<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

final class AvailabilityRepository {
    public function __construct(private PDO $db) {
        $db->exec('CREATE TABLE IF NOT EXISTS rooms(id INTEGER PRIMARY KEY, name TEXT, building TEXT, type TEXT, capacity INTEGER, touch_screen INTEGER, air_conditioning INTEGER, projector INTEGER, computers INTEGER)');
        $db->exec("CREATE TABLE IF NOT EXISTS reservations_cache(id INTEGER PRIMARY KEY, room_id INTEGER NOT NULL, date TEXT NOT NULL, start TEXT NOT NULL, end TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'activa')");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reservations_cache_room_date ON reservations_cache(room_id, date)');
    }

    public function replaceRooms(array $rooms): void {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM rooms');
            $stmt = $this->db->prepare('INSERT INTO rooms(id,name,building,type,capacity,touch_screen,air_conditioning,projector,computers) VALUES(?,?,?,?,?,?,?,?,?)');
            foreach ($rooms as $room) {
                $stmt->execute([
                    (int)($room['id'] ?? 0),
                    (string)($room['name'] ?? ''),
                    (string)($room['building'] ?? ''),
                    (string)($room['type'] ?? ''),
                    (int)($room['capacity'] ?? 0),
                    (int)!empty($room['touch_screen']),
                    (int)!empty($room['air_conditioning']),
                    (int)!empty($room['projector']),
                    max(0, (int)($room['computers'] ?? 0)),
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function replaceReservations(array $reservations): void {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM reservations_cache');
            $stmt = $this->db->prepare('INSERT INTO reservations_cache(id,room_id,date,start,end,status) VALUES(?,?,?,?,?,?)');
            foreach ($reservations as $reservation) {
                $stmt->execute([
                    (int)($reservation['id'] ?? 0),
                    (int)($reservation['room_id'] ?? 0),
                    (string)($reservation['date'] ?? ''),
                    (string)($reservation['start'] ?? ''),
                    (string)($reservation['end'] ?? ''),
                    (string)($reservation['status'] ?? 'activa'),
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function search(array $q): array {
        $capacity = max(1, (int)($q['capacity'] ?? 1));
        $type = (string)($q['type'] ?? '');
        $date = (string)($q['date'] ?? '');
        $start = (string)($q['start'] ?? '');
        $end = (string)($q['end'] ?? '');

        $sql = 'SELECT * FROM rooms WHERE capacity >= ?';
        $params = [$capacity];
        if ($type !== '') { $sql .= ' AND type = ?'; $params[] = $type; }
        foreach (['touch_screen', 'air_conditioning', 'projector'] as $f) {
            if (!empty($q[$f])) { $sql .= " AND $f = 1"; }
        }
        $sql .= ' ORDER BY name';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($date === '' || $start === '' || $end === '') return $rooms;

        $busyStmt = $this->db->prepare("SELECT room_id, start, end FROM reservations_cache WHERE date = ? AND status != 'cancelada'");
        $busyStmt->execute([$date]);
        $busyByRoom = [];
        foreach ($busyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $busyByRoom[(int)$row['room_id']][] = $row;
        }

        return array_values(array_filter($rooms, function (array $room) use ($busyByRoom, $start, $end): bool {
            $roomId = (int)($room['id'] ?? 0);
            foreach ($busyByRoom[$roomId] ?? [] as $slot) {
                if (($slot['start'] ?? '') < $end && ($slot['end'] ?? '') > $start) return false;
            }
            return true;
        }));
    }

    public function roomWithSchedule(int $id): ?array {
        $roomStmt = $this->db->prepare('SELECT * FROM rooms WHERE id = ?');
        $roomStmt->execute([$id]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$room) return null;

        $schedStmt = $this->db->prepare("SELECT * FROM reservations_cache WHERE room_id = ? AND status != 'cancelada' ORDER BY date DESC, start");
        $schedStmt->execute([$id]);
        return ['room' => $room, 'schedule' => $schedStmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}

$secret = getenv('JWT_SECRET') ?: 'dev-secret-cambiar-en-produccion';
$db = new PDO('sqlite:' . __DIR__ . '/availability.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new AvailabilityRepository($db);
$guard = new AuthGuard($secret);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($path === '/health') out(['service' => 'room-availability', 'status' => 'ok']);

    if ($method === 'POST' && $path === '/sync/rooms') {
        $guard->require();
        $rooms = $body['rooms'] ?? null;
        if (!is_array($rooms)) out(['error' => 'Payload inválido'], 422);
        $repo->replaceRooms($rooms);
        out(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/sync/reservations') {
        $guard->require();
        $reservations = $body['reservations'] ?? null;
        if (!is_array($reservations)) out(['error' => 'Payload inválido'], 422);
        $repo->replaceReservations($reservations);
        out(['ok' => true]);
    }

    if ($method === 'GET' && $path === '/availability') {
        $guard->require();
        out(['rooms' => $repo->search($_GET)]);
    }

    if ($method === 'GET' && preg_match('#^/rooms/(\d+)/schedule$#', $path, $x)) {
        $guard->require();
        $data = $repo->roomWithSchedule((int)$x[1]);
        if (!$data) out(['error' => 'Salón no existe'], 404);
        out($data);
    }

    out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) {
    out(['error' => 'Error interno'], 500);
}
