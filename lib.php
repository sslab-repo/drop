<?php
// drop — shared helpers.

function cfg(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    return $cfg;
}

function db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . __DIR__ . '/data/app.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000'); // don't fail on concurrent uploads
        $db->exec('CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            size INTEGER NOT NULL,
            mime TEXT,
            uploaded_at INTEGER NOT NULL,
            expires_at INTEGER,          -- NULL = forever
            downloads INTEGER NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS pw_failures (
            ip TEXT NOT NULL,
            ts INTEGER NOT NULL
        )');
    }
    return $db;
}

// ---- password brute-force throttle: max 10 wrong attempts per IP per 15 min ----

function pw_blocked(PDO $db, string $ip): bool {
    $db->prepare('DELETE FROM pw_failures WHERE ts < ?')->execute([time() - 900]);
    $st = $db->prepare('SELECT COUNT(*) FROM pw_failures WHERE ip = ?');
    $st->execute([$ip]);
    return (int)$st->fetchColumn() >= 10;
}

function pw_record_failure(PDO $db, string $ip): void {
    $db->prepare('INSERT INTO pw_failures (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
}

// ---- trusted-computer cookie (signed, no server-side session needed) ----

const TRUST_COOKIE = 'drop_trust';

function trust_sig(string $expiry): string {
    return hash_hmac('sha256', $expiry, cfg()['secret']);
}

function is_trusted(): bool {
    $raw = $_COOKIE[TRUST_COOKIE] ?? '';
    if (!str_contains($raw, '.')) return false;
    [$expiry, $sig] = explode('.', $raw, 2);
    if (!ctype_digit($expiry) || (int)$expiry < time()) return false;
    return hash_equals(trust_sig($expiry), $sig);
}

function grant_trust(): void {
    $expiry = (string)(time() + cfg()['trust_seconds']);
    setcookie(TRUST_COOKIE, $expiry . '.' . trust_sig($expiry), [
        'expires'  => (int)$expiry,
        'path'     => dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

// ---- short codes ----

function gen_code(PDO $db, int $len = 6): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    for ($try = 0; $try < 10; $try++) {
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $st = $db->prepare('SELECT 1 FROM files WHERE code = ?');
        $st->execute([$code]);
        if (!$st->fetch()) return $code;
    }
    throw new RuntimeException('could not generate a unique code');
}

// ---- expired-file removal (shared by cleanup.php and lazy checks) ----

function delete_row(PDO $db, array $row): void {
    @unlink(__DIR__ . '/data/' . $row['stored_name']);
    $db->prepare('DELETE FROM files WHERE id = ?')->execute([$row['id']]);
}

function purge_expired(PDO $db): int {
    $st = $db->prepare('SELECT * FROM files WHERE expires_at IS NOT NULL AND expires_at < ?');
    $st->execute([time()]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        delete_row($db, $row);
        $n++;
    }
    return $n;
}

function json_out(array $data, int $status = 200) { // no return type: PHP 8.0 compat
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
