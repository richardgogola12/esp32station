<?php
declare(strict_types=1);

/**
 * Spoločné jadro aplikácie – konfigurácia, DB, session, bezpečnosť, pomocné funkcie.
 */

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('ESP32 DHT Station vyžaduje PHP 8.0 alebo novšie.');
}

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.1');

$GLOBALS['__esp32dth_cfg'] = require APP_ROOT . '/config.php';

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['__esp32dth_cfg'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

date_default_timezone_set((string)cfg('timezone', 'Europe/Bratislava'));

if (cfg('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require __DIR__ . '/data.php';

/* ------------------------------------------------------------------ DB */

function t(string $name): string
{
    $prefix = (string)cfg('table_prefix', 'esp32dth_');
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
        throw new RuntimeException('Neplatný prefix tabuliek');
    }
    return $prefix . $name;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = cfg('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int)($c['port'] ?? 3306),
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('SET time_zone = ' . $pdo->quote((new DateTime())->format('P')));
    return $pdo;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/* ------------------------------------------------------------------ URL */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
}

function base_path(): string
{
    static $bp = null;
    if ($bp !== null) {
        return $bp;
    }
    $c = cfg('base_path');
    if (is_string($c)) {
        return $bp = rtrim($c, '/');
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $file   = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root   = realpath(APP_ROOT) ?: APP_ROOT;
    if ($file !== '' && str_starts_with($file, $root)) {
        $rel = str_replace('\\', '/', substr($file, strlen($root)));
        if ($rel !== '' && str_ends_with($script, $rel)) {
            return $bp = rtrim(substr($script, 0, -strlen($rel)), '/');
        }
    }
    return $bp = rtrim(str_replace('\\', '/', dirname($script)), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function host(): string
{
    $h = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    return preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $h) ? strtolower($h) : 'localhost';
}

function origin(): string
{
    return (is_https() ? 'https' : 'http') . '://' . host();
}

function absolute_url(string $path = ''): string
{
    return origin() . url($path);
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/* ------------------------------------------------------------------ Výstup */

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function send_security_headers(bool $html = true): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    if ($html) {
        header("Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
            . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; "
            . "img-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net; manifest-src 'self'; worker-src 'self'; "
            . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        header('Cache-Control: no-store');
    }
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function read_json_body(int $maxBytes = 65536): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        json_out(['ok' => false, 'error' => 'Príliš veľká požiadavka'], 413);
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* ------------------------------------------------------------------ Session */

/**
 * Vlastná, izolovaná session: iný názov cookie, cookie obmedzená na cestu aplikácie,
 * vlastný adresár pre session súbory a všetky dáta v mennom priestore $_SESSION['esp32dth'].
 * Ostatné aplikácie na serveri sa s ňou teda nepobijú.
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $life = (int)cfg('session.lifetime', 2592000);

    $dir = APP_ROOT . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)$life);

    session_name((string)cfg('session.name', 'ESP32DTH_SID'));
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => base_path() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!isset($_SESSION['esp32dth']) || !is_array($_SESSION['esp32dth'])) {
        $_SESSION['esp32dth'] = [];
    }
    $now  = time();
    $idle = (int)cfg('session.idle_timeout', 604800);
    $last = (int)($_SESSION['esp32dth']['last'] ?? $now);
    if ($now - $last > $idle) {
        $_SESSION['esp32dth'] = [];
        session_regenerate_id(true);
    }
    $_SESSION['esp32dth']['last'] = $now;
}

function sess(string $key, $default = null)
{
    return $_SESSION['esp32dth'][$key] ?? $default;
}

function sess_set(string $key, $value): void
{
    $_SESSION['esp32dth'][$key] = $value;
}

function sess_unset(string $key): void
{
    unset($_SESSION['esp32dth'][$key]);
}

function flash(string $type, string $msg): void
{
    $f = sess('flash', []);
    $f[] = ['type' => $type, 'msg' => $msg];
    sess_set('flash', $f);
}

function take_flash(): array
{
    $f = sess('flash', []);
    sess_unset('flash');
    return is_array($f) ? $f : [];
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    $tok = sess('csrf');
    if (!is_string($tok) || strlen($tok) !== 64) {
        $tok = bin2hex(random_bytes(32));
        sess_set('csrf', $tok);
    }
    return $tok;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(bool $json = false): void
{
    $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        if ($json) {
            json_out(['ok' => false, 'error' => 'Neplatný bezpečnostný token. Obnov stránku.'], 419);
        }
        http_response_code(419);
        flash('danger', 'Platnosť formulára vypršala, skús to znova.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
    }
}

/* ------------------------------------------------------------------ Autentifikácia */

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $id = (int)sess('uid', 0);
    if ($id <= 0) {
        return $cache = null;
    }
    $st = db()->prepare('SELECT id, username, email, widget_refresh_min, widget_token_hint, created_at, last_login_at FROM ' . t('users') . ' WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        sess_unset('uid');
        return $cache = null;
    }
    return $cache = $u;
}

function require_login(bool $api = false): array
{
    $u = current_user();
    if ($u) {
        return $u;
    }
    if ($api) {
        json_out(['ok' => false, 'error' => 'Neprihlásený'], 401);
    }
    $next = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'index.php');
    redirect('login.php' . ($next !== 'index.php' ? '?next=' . rawurlencode($next) : ''));
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    sess_set('uid', $id);
    sess_set('login_at', time());
    sess_unset('csrf');
    db()->prepare('UPDATE ' . t('users') . ' SET last_login_at = ? WHERE id = ?')->execute([now(), $id]);
}

function logout_user(): void
{
    $_SESSION['esp32dth'] = [];
    session_regenerate_id(true);
}

function safe_next(string $fallback = 'index.php'): string
{
    $n = (string)($_GET['next'] ?? $_POST['next'] ?? '');
    return preg_match('/^[a-z_]+\.php$/', $n) ? $n : $fallback;
}

/* ------------------------------------------------------------------ Rate limit */

function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function login_rate_limited(string $ip): bool
{
    $win = (int)cfg('login.window_minutes', 15);
    $st = db()->prepare('SELECT COUNT(*) FROM ' . t('login_attempts') . ' WHERE ip = ? AND created_at > ?');
    $st->execute([$ip, date('Y-m-d H:i:s', time() - $win * 60)]);
    return (int)$st->fetchColumn() >= (int)cfg('login.max_attempts', 5);
}

function login_record(string $ip, string $username): void
{
    db()->prepare('INSERT INTO ' . t('login_attempts') . ' (ip, username, created_at) VALUES (?, ?, ?)')
        ->execute([$ip, mb_substr($username, 0, 100), now()]);
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM ' . t('login_attempts') . ' WHERE created_at < ?')
            ->execute([date('Y-m-d H:i:s', time() - 86400)]);
    }
}

function login_clear(string $ip): void
{
    db()->prepare('DELETE FROM ' . t('login_attempts') . ' WHERE ip = ?')->execute([$ip]);
}

/* ------------------------------------------------------------------ Pomocné */

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $r = base64_decode($s, true);
    return $r === false ? '' : $r;
}

function fmt_num($v, int $dec = 1): string
{
    if ($v === null || $v === '') {
        return '–';
    }
    return number_format((float)$v, $dec, ',', ' ');
}

function time_ago(?string $dt): string
{
    if (!$dt) {
        return 'nikdy';
    }
    $d = time() - strtotime($dt);
    if ($d < 0) {
        $d = 0;
    }
    if ($d < 45) return 'práve teraz';
    if ($d < 3600) return 'pred ' . max(1, (int)round($d / 60)) . ' min';
    if ($d < 86400) return 'pred ' . (int)floor($d / 3600) . ' h';
    if ($d < 86400 * 7) return 'pred ' . (int)floor($d / 86400) . ' d';
    return date('j. n. Y H:i', strtotime($dt));
}

function format_interval(int $sec): string
{
    if ($sec % 3600 === 0) return ($sec / 3600) . ' h';
    if ($sec % 60 === 0) return ($sec / 60) . ' min';
    return $sec . ' s';
}

function device_online(array $d): bool
{
    if (empty($d['last_seen_at'])) {
        return false;
    }
    $limit = max(180, (int)$d['interval_sec'] * 3);
    return time() - strtotime($d['last_seen_at']) <= $limit;
}

/** Normalizovaný identifikátor zariadenia (ten si ESP32 posiela v hlavičke). */
function slugify(string $s): string
{
    $map = ['á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l','ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u','ý'=>'y','ž'=>'z'];
    $s = strtr(mb_strtolower(trim($s)), $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/* ------------------------------------------------------------------ Šifrovanie API kľúčov */

/** Tajný kľúč aplikácie – z config.php ('app_secret') alebo automaticky vytvorený v storage/. */
function app_secret(): string
{
    $c = cfg('app_secret');
    if (is_string($c) && strlen($c) >= 32) {
        return hash('sha256', $c, true);
    }
    $file = APP_ROOT . '/storage/secret.key';
    if (is_file($file)) {
        $s = (string)file_get_contents($file);
        if (strlen($s) === 64 && ctype_xdigit($s)) {
            return hex2bin($s);
        }
    }
    $s = bin2hex(random_bytes(32));
    if (@file_put_contents($file, $s, LOCK_EX) === false) {
        throw new RuntimeException('Nedá sa zapísať storage/secret.key – nastav app_secret v config.php');
    }
    @chmod($file, 0600);
    return hex2bin($s);
}

function encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function decrypt_secret(?string $enc): ?string
{
    if (!$enc) return null;
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

/** Doplní chýbajúce stĺpce pri aktualizácii aplikácie (bez ručného zásahu do DB). */
function ensure_schema(): void
{
    try {
        $tbl = t('devices');
        $has = db()->query("SHOW COLUMNS FROM `$tbl` LIKE 'api_key_enc'")->fetch();
        if (!$has) {
            db()->exec("ALTER TABLE `$tbl` ADD COLUMN `api_key_enc` VARCHAR(255) NULL DEFAULT NULL AFTER `api_key_hint`");
        }
    } catch (PDOException $e) {
        error_log('[esp32dth] ensure_schema: ' . $e->getMessage());
    }
}
