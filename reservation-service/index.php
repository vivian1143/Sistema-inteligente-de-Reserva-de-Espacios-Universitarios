<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

final class ReservationRepository {
    public function __construct(private PDO $db) {
        $db->exec("CREATE TABLE IF NOT EXISTS reservations(id INTEGER PRIMARY KEY, room_id INTEGER, user_id INTEGER, user_name TEXT, date TEXT, start TEXT, end TEXT, purpose TEXT, status TEXT DEFAULT 'activa')");
    }

    public function conflict(int $roomId, string $date, string $start, string $end): bool {
        $s = $this->db->prepare("SELECT COUNT(*) FROM reservations WHERE room_id=? AND date=? AND status!='cancelada' AND start<? AND end>?");
        $s->execute([$roomId, $date, $end, $start]);
        return (bool)$s->fetchColumn();
    }

    public function reserve(int $roomId, array $user, string $date, string $start, string $end, string $purpose, string $status): int {
        $this->db->prepare('INSERT INTO reservations(room_id,user_id,user_name,date,start,end,purpose,status) VALUES(?,?,?,?,?,?,?,?)')->execute([$roomId, $user['sub'], $user['name'], $date, $start, $end, $purpose, $status]);
        return (int)$this->db->lastInsertId();
    }

    public function mine(array $user): array {
        $s = $this->db->prepare("SELECT * FROM reservations WHERE (?=1 OR user_id=?) AND status!='cancelada' ORDER BY date DESC,start");
        $s->bindValue(1, $user['role'] === 'admin' ? 1 : 0, PDO::PARAM_INT);
        $s->bindValue(2, $user['sub'], PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancel(int $id, array $user): bool {
        $s = $this->db->prepare("UPDATE reservations SET status='cancelada' WHERE id=? AND (?=1 OR user_id=?)");
        $s->bindValue(1, $id, PDO::PARAM_INT);
        $s->bindValue(2, $user['role'] === 'admin' ? 1 : 0, PDO::PARAM_INT);
        $s->bindValue(3, $user['sub'], PDO::PARAM_INT);
        $s->execute();
        return $s->rowCount() > 0;
    }
}

final class Validator {
    public static function slot(array $b): array {
        $t = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $date = (string)($b['date'] ?? '');
        $start = (string)($b['start'] ?? '');
        $end = (string)($b['end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match($t, $start) || !preg_match($t, $end) || $end <= $start) out(['error' => 'Fecha u horario inválido'], 422);
        if ($date < date('Y-m-d')) out(['error' => 'No se puede reservar en el pasado'], 422);
        return [$date, $start, $end];
    }
}

function http_json(string $url, string $method = 'GET', ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($response === false ? '[]' : $response, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

$secret = getenv('JWT_SECRET') ?: 'dev-secret-cambiar-en-produccion';
$db = new PDO('sqlite:' . __DIR__ . '/reservations.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new ReservationRepository($db);
$guard = new AuthGuard($secret);
$catalogUrl = getenv('CATALOG_URL') ?: 'http://localhost:8003';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($path === '/health') out(['service' => 'reservation', 'status' => 'ok']);
    if ($method === 'GET' && $path === '/reservations') {
        $user = $guard->require();
        $reservations = $repo->mine($user);
        out(['reservations' => $reservations]);
    }
    if ($method === 'DELETE' && preg_match('#^/reservations/(\d+)$#', $path, $x)) {
        $user = $guard->require();
        $repo->cancel((int)$x[1], $user) ? out(['ok' => true]) : out(['error' => 'No permitido o no existe'], 404);
    }
    if ($method === 'POST' && preg_match('#^/rooms/(\d+)/(reserve|block)$#', $path, $x)) {
        $isBlock = $x[2] === 'block';
        $user = $guard->require($isBlock ? ['admin'] : ['admin', 'docente', 'estudiante']);
        $roomId = (int)$x[1];
        $token = preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) ? $m[1] : null;
        $room = http_json($catalogUrl . '/rooms/' . $roomId, 'GET', $token);
        if ($room['status'] !== 200) out(['error' => 'Salón no existe'], 404);
        [$date, $start, $end] = Validator::slot($body);
        if ($repo->conflict($roomId, $date, $start, $end)) out(['error' => 'El salón ya está ocupado en ese horario'], 409);
        $purpose = mb_substr(trim(strip_tags((string)($body['purpose'] ?? ''))), 0, 120);
        $status = $isBlock ? 'clase' : 'activa';
        $id = $repo->reserve($roomId, $user, $date, $start, $end, $purpose, $status);
        out(['id' => $id], 201);
    }
    out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) {
    out(['error' => 'Error interno'], 500);
}
