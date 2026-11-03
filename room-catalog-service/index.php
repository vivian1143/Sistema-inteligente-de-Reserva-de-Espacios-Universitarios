<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out(array $d, int $c = 200): never {
    http_response_code($c);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

final class Jwt {
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function decode(string $t, string $k): ?array {
        $x = explode('.', $t); if (count($x) !== 3) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', "$x[0].$x[1]", $k, true)), $x[2])) return null;
        $p = json_decode(base64_decode(strtr($x[1], '-_', '+/')), true);
        return ($p && ($p['exp'] ?? 0) > time()) ? $p : null;
    }
}

final class AuthGuard {
    public function __construct(private string $secret) {}
    public function require(array $roles = []): array {
        $u = preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) ? Jwt::decode($m[1], $this->secret) : null;
        if (!$u) out(['error' => 'No autenticado'], 401);
        if ($roles && !in_array($u['role'], $roles, true)) out(['error' => 'Sin permisos para esta acción'], 403);
        return $u;
    }
}

final class CatalogRepository {
    public function __construct(private PDO $db) {
        $db->exec('CREATE TABLE IF NOT EXISTS rooms(id INTEGER PRIMARY KEY, name TEXT, building TEXT, type TEXT, capacity INTEGER, touch_screen INTEGER, air_conditioning INTEGER, projector INTEGER, computers INTEGER)');
        if (!(int)$db->query('SELECT COUNT(*) FROM rooms')->fetchColumn()) {
            foreach ([
                ['Sala Orinoquia','Bloque A','sala_estudio',6,0,1,0,0],
                ['Aula Magna 101','Bloque B','aula',40,1,1,1,0],
                ['Lab. Cómputo 1','Bloque C','laboratorio_computo',30,1,1,1,30],
                ['Sala Tutorías 2','Bloque A','sala_estudio',4,0,0,0,0],
            ] as $r) $this->create($r);
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
    }

    public function listAll(): array {
        $s = $this->db->query('SELECT * FROM rooms ORDER BY name');
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array {
        $s = $this->db->prepare('SELECT * FROM rooms WHERE id=?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function search(array $q): array {
        $sql = 'SELECT r.* FROM rooms r WHERE capacity>=?';
        $p = [max(1, (int)($q['capacity'] ?? 1))];
        if (!empty($q['type'])) { $sql .= ' AND type=?'; $p[] = $q['type']; }
        foreach (['touch_screen', 'air_conditioning', 'projector'] as $f) {
            if (!empty($q[$f])) { $sql .= " AND $f=1"; }
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY name');
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class Validator {
    public static function room(array $b): array {
        $types = ['aula', 'laboratorio_computo', 'sala_estudio', 'auditorio'];
        $name = trim(strip_tags((string)($b['name'] ?? '')));
        $building = trim(strip_tags((string)($b['building'] ?? '')));
        $capacity = (int)($b['capacity'] ?? 0);
        $type = (string)($b['type'] ?? '');
        if ($name === '' || $building === '' || $capacity < 1 || $capacity > 500 || !in_array($type, $types, true)) out(['error' => 'Datos de salón inválidos'], 422);
        return [$name, $building, $type, $capacity, (int)!empty($b['touch_screen']), (int)!empty($b['air_conditioning']), (int)!empty($b['projector']), max(0, (int)($b['computers'] ?? 0))];
    }
}

$secret = getenv('JWT_SECRET') ?: 'dev-secret-cambiar-en-produccion';
$db = new PDO('sqlite:' . __DIR__ . '/catalog.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new CatalogRepository($db);
$guard = new AuthGuard($secret);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($path === '/health') out(['service' => 'room-catalog', 'status' => 'ok']);
    if ($method === 'GET' && $path === '/rooms') {
        $guard->require();
        out(['rooms' => $repo->search($_GET)]);
    }
    if (preg_match('#^/rooms/(\d+)$#', $path, $x)) {
        $id = (int)$x[1];
        if ($method === 'GET') {
            $guard->require();
            $room = $repo->find($id) ?? out(['error' => 'Salón no existe'], 404);
            out(['room' => $room]);
        }
        if ($method === 'PUT') {
            $guard->require(['admin']);
            $repo->update($id, Validator::room($body));
            out(['ok' => true]);
        }
        if ($method === 'DELETE') {
            $guard->require(['admin']);
            $repo->delete($id);
            out(['ok' => true]);
        }
    }
    if ($method === 'POST' && $path === '/rooms') {
        $guard->require(['admin']);
        out(['id' => $repo->create(Validator::room($body))], 201);
    }
    out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) {
    out(['error' => 'Error interno'], 500);
}
