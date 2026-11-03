<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    public function require(): array {
        $u = preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) ? Jwt::decode($m[1], $this->secret) : null;
        if (!$u) out(['error' => 'No autenticado'], 401);
        return $u;
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
$catalogUrl = getenv('CATALOG_URL') ?: 'http://localhost:8003';
$reservationUrl = getenv('RESERVATION_URL') ?: 'http://localhost:8005';
$guard = new AuthGuard($secret);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$token = preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) ? $m[1] : null;

try {
    if ($path === '/health') out(['service' => 'room-availability', 'status' => 'ok']);
    if ($method === 'GET' && $path === '/availability') {
        $guard->require();
        $catalog = http_json($catalogUrl . '/rooms', 'GET', $token);
        $rooms = $catalog['body']['rooms'] ?? [];
        $date = (string)($_GET['date'] ?? '');
        $start = (string)($_GET['start'] ?? '');
        $end = (string)($_GET['end'] ?? '');
        $capacity = (int)($_GET['capacity'] ?? 1);
        $type = (string)($_GET['type'] ?? '');
        $filters = ['touch_screen' => !empty($_GET['touch_screen']), 'air_conditioning' => !empty($_GET['air_conditioning']), 'projector' => !empty($_GET['projector'])];

        if ($date && $start && $end) {
            $reservations = http_json($reservationUrl . '/reservations', 'GET', $token);
            $busy = [];
            foreach (($reservations['body']['reservations'] ?? []) as $r) {
                if (($r['status'] ?? '') === 'cancelada') continue;
                if (($r['date'] ?? '') !== $date) continue;
                $busy[(int)($r['room_id'] ?? 0)][] = $r;
            }
            $rooms = array_values(array_filter($rooms, function ($room) use ($capacity, $type, $filters, $date, $start, $end, $busy) {
                if ((int)($room['capacity'] ?? 0) < $capacity) return false;
                if ($type && ($room['type'] ?? '') !== $type) return false;
                foreach ($filters as $k => $enabled) {
                    if ($enabled && (int)($room[$k] ?? 0) !== 1) return false;
                }
                foreach ($busy[(int)($room['id'] ?? 0)] ?? [] as $res) {
                    if ($res['start'] < $end && $res['end'] > $start) return false;
                }
                return true;
            }));
        }
        out(['rooms' => $rooms]);
    }
    if (preg_match('#^/rooms/(\d+)/schedule$#', $path, $x)) {
        $guard->require();
        $id = (int)$x[1];
        $room = http_json($catalogUrl . '/rooms/' . $id, 'GET', $token);
        if ($room['status'] !== 200) out(['error' => 'Salón no existe'], 404);
        $reservations = http_json($reservationUrl . '/reservations', 'GET', $token);
        $schedule = [];
        foreach (($reservations['body']['reservations'] ?? []) as $r) {
            if ((int)($r['room_id'] ?? 0) === $id && (($r['status'] ?? '') !== 'cancelada')) {
                $schedule[] = $r;
            }
        }
        out(['room' => $room['body']['room'], 'schedule' => $schedule]);
    }
    out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) {
    out(['error' => 'Error interno'], 500);
}
