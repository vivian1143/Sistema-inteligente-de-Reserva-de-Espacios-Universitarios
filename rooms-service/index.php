<?php
declare(strict_types=1);
/**
 * ROOMS SERVICE - Salones, horarios y reservas.
 * Patrones: Front Controller, Repository, Middleware (AuthGuard JWT/roles), Validator.
 * La disponibilidad SIEMPRE sale de la base de datos (la IA futura solo la consultará vía este API).
 */
header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out(array $d, int $c = 200): never { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }

final class Jwt { // mismo contrato que auth-service (servicios independientes, secreto compartido)
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function decode(string $t, string $k): ?array {
        $x = explode('.', $t); if (count($x) !== 3) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', "$x[0].$x[1]", $k, true)), $x[2])) return null;
        $p = json_decode(base64_decode(strtr($x[1], '-_', '+/')), true);
        return ($p && ($p['exp'] ?? 0) > time()) ? $p : null;
    }
}

final class AuthGuard { // Middleware RBAC
    public function __construct(private string $secret) {}
    public function require(array $roles = []): array {
        $u = preg_match('/Bearer (.+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) ? Jwt::decode($m[1], $this->secret) : null;
        if (!$u) out(['error' => 'No autenticado'], 401);
        if ($roles && !in_array($u['role'], $roles, true)) out(['error' => 'Sin permisos para esta acción'], 403);
        return $u;
    }
}

final class RoomRepository {
    public function __construct(private PDO $db) {
        $db->exec('CREATE TABLE IF NOT EXISTS rooms(id INTEGER PRIMARY KEY, name TEXT, building TEXT, type TEXT, capacity INTEGER, touch_screen INTEGER, air_conditioning INTEGER, projector INTEGER, computers INTEGER)');
        $db->exec("CREATE TABLE IF NOT EXISTS reservations(id INTEGER PRIMARY KEY, room_id INTEGER, user_id INTEGER, user_name TEXT, date TEXT, start TEXT, end TEXT, purpose TEXT, status TEXT DEFAULT 'activa')");
        if (!(int)$db->query('SELECT COUNT(*) FROM rooms')->fetchColumn()) {
            foreach ([['Sala Orinoquia','Bloque A','sala_estudio',6,0,1,0,0],['Aula Magna 101','Bloque B','aula',40,1,1,1,0],
                      ['Lab. Cómputo 1','Bloque C','laboratorio_computo',30,1,1,1,30],['Sala Tutorías 2','Bloque A','sala_estudio',4,0,0,0,0]] as $r)
                $this->create($r);
        }
    }
    public function create(array $r): int {
        $this->db->prepare('INSERT INTO rooms(name,building,type,capacity,touch_screen,air_conditioning,projector,computers) VALUES(?,?,?,?,?,?,?,?)')->execute(array_values($r));
        return (int)$this->db->lastInsertId();
    }
    public function update(int $id, array $r): void {
        $this->db->prepare('UPDATE rooms SET name=?,building=?,type=?,capacity=?,touch_screen=?,air_conditioning=?,projector=?,computers=? WHERE id=?')->execute([...array_values($r), $id]);
    }
    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM rooms WHERE id=?')->execute([$id]);
        $this->db->prepare('DELETE FROM reservations WHERE room_id=?')->execute([$id]);
    }
    public function find(int $id): ?array { $s = $this->db->prepare('SELECT * FROM rooms WHERE id=?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function search(array $q): array {
        $sql = 'SELECT r.* FROM rooms r WHERE capacity>=?'; $p = [max(1, (int)($q['capacity'] ?? 1))];
        if (!empty($q['type'])) { $sql .= ' AND type=?'; $p[] = $q['type']; }
        foreach (['touch_screen','air_conditioning','projector'] as $f) if (!empty($q[$f])) $sql .= " AND $f=1";
        if (!empty($q['date']) && !empty($q['start']) && !empty($q['end'])) {
            $sql .= " AND NOT EXISTS(SELECT 1 FROM reservations v WHERE v.room_id=r.id AND v.date=? AND v.status!='cancelada' AND v.start<? AND v.end>?)";
            array_push($p, $q['date'], $q['end'], $q['start']);
        }
        $s = $this->db->prepare($sql . ' ORDER BY name'); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function scheduleOf(int $id): array {
        $s = $this->db->prepare("SELECT id,date,start,end,purpose,status FROM reservations WHERE room_id=? AND status!='cancelada' AND date>=? ORDER BY date,start");
        $s->execute([$id, date('Y-m-d')]); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function conflict(int $room, string $d, string $a, string $b): bool {
        $s = $this->db->prepare("SELECT COUNT(*) FROM reservations WHERE room_id=? AND date=? AND status!='cancelada' AND start<? AND end>?");
        $s->execute([$room, $d, $b, $a]); return (bool)$s->fetchColumn();
    }
    public function reserve(int $room, array $u, string $d, string $a, string $b, string $purpose, string $status): int {
        $this->db->prepare('INSERT INTO reservations(room_id,user_id,user_name,date,start,end,purpose,status) VALUES(?,?,?,?,?,?,?,?)')->execute([$room, $u['sub'], $u['name'], $d, $a, $b, $purpose, $status]);
        return (int)$this->db->lastInsertId();
    }
    public function mine(array $u): array {
        $s = $this->db->prepare("SELECT v.*, r.name room_name FROM reservations v JOIN rooms r ON r.id=v.room_id WHERE (?=1 OR v.user_id=?) AND v.status!='cancelada' ORDER BY date DESC,start");
        $s->bindValue(1, $u['role'] === 'admin' ? 1 : 0, PDO::PARAM_INT); $s->bindValue(2, $u['sub'], PDO::PARAM_INT); $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function cancel(int $id, array $u): bool {
        $s = $this->db->prepare("UPDATE reservations SET status='cancelada' WHERE id=? AND (?=1 OR user_id=?)");
        $s->bindValue(1, $id, PDO::PARAM_INT); $s->bindValue(2, $u['role'] === 'admin' ? 1 : 0, PDO::PARAM_INT); $s->bindValue(3, $u['sub'], PDO::PARAM_INT);
        $s->execute(); return $s->rowCount() > 0;
    }
}

final class Validator {
    public static function room(array $b): array {
        $types = ['aula','laboratorio_computo','sala_estudio','auditorio'];
        $name = trim(strip_tags((string)($b['name'] ?? ''))); $bld = trim(strip_tags((string)($b['building'] ?? '')));
        $cap = (int)($b['capacity'] ?? 0); $type = (string)($b['type'] ?? '');
        if ($name === '' || $bld === '' || $cap < 1 || $cap > 500 || !in_array($type, $types, true)) out(['error' => 'Datos de salón inválidos'], 422);
        return [$name, $bld, $type, $cap, (int)!empty($b['touch_screen']), (int)!empty($b['air_conditioning']), (int)!empty($b['projector']), max(0, (int)($b['computers'] ?? 0))];
    }
    public static function slot(array $b): array {
        $t = '/^([01]\d|2[0-3]):[0-5]\d$/'; $d = (string)($b['date'] ?? ''); $a = (string)($b['start'] ?? ''); $e = (string)($b['end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match($t, $a) || !preg_match($t, $e) || $e <= $a) out(['error' => 'Fecha u horario inválido'], 422);
        if ($d < date('Y-m-d')) out(['error' => 'No se puede reservar en el pasado'], 422);
        return [$d, $a, $e];
    }
}

$guard = new AuthGuard(getenv('JWT_SECRET') ?: 'dev-secret-cambiar-en-produccion');
$db = new PDO('sqlite:' . __DIR__ . '/rooms.db'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new RoomRepository($db);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $m = $_SERVER['REQUEST_METHOD'];
$b = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($path === '/health') out(['service' => 'rooms', 'status' => 'ok']);
    if ($m === 'GET' && $path === '/rooms') { $guard->require(); out(['rooms' => $repo->search($_GET)]); }
    if ($m === 'POST' && $path === '/rooms') { $guard->require(['admin']); out(['id' => $repo->create(Validator::room($b))], 201); }
    if (preg_match('#^/rooms/(\d+)$#', $path, $x)) {
        $id = (int)$x[1];
        if ($m === 'GET') { $guard->require(); $r = $repo->find($id) ?? out(['error' => 'Salón no existe'], 404); out(['room' => $r, 'schedule' => $repo->scheduleOf($id)]); }
        if ($m === 'PUT') { $guard->require(['admin']); $repo->update($id, Validator::room($b)); out(['ok' => true]); }
        if ($m === 'DELETE') { $guard->require(['admin']); $repo->delete($id); out(['ok' => true]); }
    }
    if ($m === 'POST' && preg_match('#^/rooms/(\d+)/(reserve|block)$#', $path, $x)) { // reservar (todos) / asignar horario de clase (admin)
        $isBlock = $x[2] === 'block';
        $u = $guard->require($isBlock ? ['admin'] : ['admin', 'docente', 'estudiante']);
        $id = (int)$x[1]; $repo->find($id) ?? out(['error' => 'Salón no existe'], 404);
        [$d, $a, $e] = Validator::slot($b);
        if ($repo->conflict($id, $d, $a, $e)) out(['error' => 'El salón ya está ocupado en ese horario'], 409);
        $purpose = mb_substr(trim(strip_tags((string)($b['purpose'] ?? ''))), 0, 120);
        out(['id' => $repo->reserve($id, $u, $d, $a, $e, $purpose, $isBlock ? 'clase' : 'activa')], 201);
    }
    if ($m === 'GET' && $path === '/reservations') out(['reservations' => $repo->mine($guard->require())]);
    if ($m === 'DELETE' && preg_match('#^/reservations/(\d+)$#', $path, $x))
        $repo->cancel((int)$x[1], $guard->require()) ? out(['ok' => true]) : out(['error' => 'No permitido o no existe'], 404);
    out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) { out(['error' => 'Error interno'], 500); }
