<?php
declare(strict_types=1);
/**
 * AUTH SERVICE - Autenticación por roles (JWT HS256).
 * Patrones: Front Controller, Repository, Service. Seguridad: bcrypt, sentencias preparadas,
 * bloqueo por intentos, tokens con expiración, secretos por entorno.
 */
header('Access-Control-Allow-Origin: ' . (getenv('CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function out(array $d, int $c = 200): never { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }

final class Jwt {
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function encode(array $p, string $k): string {
        $h = self::b64('{"alg":"HS256","typ":"JWT"}'); $b = self::b64(json_encode($p));
        return "$h.$b." . self::b64(hash_hmac('sha256', "$h.$b", $k, true));
    }
    public static function decode(string $t, string $k): ?array {
        $x = explode('.', $t); if (count($x) !== 3) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', "$x[0].$x[1]", $k, true)), $x[2])) return null;
        $p = json_decode(base64_decode(strtr($x[1], '-_', '+/')), true);
        return ($p && ($p['exp'] ?? 0) > time()) ? $p : null;
    }
}

final class UserRepository {
    public function __construct(private PDO $db) {
        $db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT, email TEXT UNIQUE, password_hash TEXT, role TEXT, attempts INTEGER DEFAULT 0, locked_until INTEGER DEFAULT 0)');
        if (!(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
            foreach ([['Administrador UNILLANOS','admin@unillanos.edu.co','Admin123*','admin'],
                      ['Docente Demo','docente@unillanos.edu.co','Docente123*','docente'],
                      ['Estudiante Demo','estudiante@unillanos.edu.co','Estudiante123*','estudiante']] as [$n,$e,$p,$r])
                $this->create($n, $e, $p, $r);
        }
    }
    public function create(string $n, string $e, string $p, string $r): void {
        $this->db->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')->execute([$n, $e, password_hash($p, PASSWORD_BCRYPT), $r]);
    }
    public function byEmail(string $e): ?array { $s = $this->db->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$e]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function fail(int $id, int $attempts): void {
        $this->db->prepare('UPDATE users SET attempts=?, locked_until=? WHERE id=?')->execute([$attempts, $attempts >= 5 ? time() + 300 : 0, $id]);
    }
    public function reset(int $id): void { $this->db->prepare('UPDATE users SET attempts=0, locked_until=0 WHERE id=?')->execute([$id]); }
}

final class AuthService {
    public function __construct(private UserRepository $users, private string $secret) {}
    public function login(string $email, string $pass): array {
        $u = $this->users->byEmail(strtolower(trim($email)));
        if ($u && $u['locked_until'] > time()) out(['error' => 'Cuenta bloqueada 5 min por intentos fallidos'], 429);
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            if ($u) $this->users->fail((int)$u['id'], (int)$u['attempts'] + 1);
            out(['error' => 'Credenciales inválidas'], 401);
        }
        $this->users->reset((int)$u['id']);
        $claims = ['sub' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'exp' => time() + 7200];
        return ['token' => Jwt::encode($claims, $this->secret), 'user' => array_diff_key($claims, ['exp' => 1])];
    }
}

$secret = getenv('JWT_SECRET') ?: 'dev-secret-cambiar-en-produccion';
$db = new PDO('sqlite:' . __DIR__ . '/auth.db'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$repo = new UserRepository($db); $svc = new AuthService($repo, $secret);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $m = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    if ($m === 'POST' && $path === '/login') out($svc->login((string)($body['email'] ?? ''), (string)($body['password'] ?? '')));
    if ($m === 'POST' && $path === '/register') { // autoregistro: SIEMPRE rol estudiante
        $e = strtolower(trim((string)($body['email'] ?? ''))); $p = (string)($body['password'] ?? ''); $n = trim((string)($body['name'] ?? ''));
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($p) < 8 || $n === '') out(['error' => 'Datos inválidos (contraseña mínima 8)'], 422);
        if ($repo->byEmail($e)) out(['error' => 'Correo ya registrado'], 409);
        $repo->create($n, $e, $p, 'estudiante'); out(['ok' => true], 201);
    }
    if ($m === 'GET' && $path === '/me') {
        $t = preg_match('/Bearer (.+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $mm) ? Jwt::decode($mm[1], $secret) : null;
        $t ? out(['user' => $t]) : out(['error' => 'Token inválido'], 401);
    }
    if ($path === '/health') out(['service' => 'auth', 'status' => 'ok']);
    out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) { out(['error' => 'Error interno'], 500); }
