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
            downloads INTEGER NOT NULL DEFAULT 0,
            ip TEXT,                     -- uploader source IP (for archive metadata)
            sha256 TEXT                  -- computed at upload (for archive metadata)
        )');
        // Migration for databases created before ip/sha256 existed.
        $cols = $db->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ip', $cols, true))     $db->exec('ALTER TABLE files ADD COLUMN ip TEXT');
        if (!in_array('sha256', $cols, true)) $db->exec('ALTER TABLE files ADD COLUMN sha256 TEXT');
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

// ---- expired-file archiving (shared by cleanup.php and lazy checks) ----
// Expired files are NOT deleted: the blob moves to data/archive/ under its
// original name + "_expired_MMDDYYYY" (Chicago date), next to a .md metadata
// file. The DB row is removed, so the short link stops working (410).

function safe_archive_name(string $name): string {
    $name = preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/', '_', $name);
    $name = ltrim($name, '.');
    return $name === '' ? 'file' : $name;
}

function archive_row(PDO $db, array $row): void {
    $src = __DIR__ . '/data/' . $row['stored_name'];
    $dir = __DIR__ . '/data/archive';
    if (is_file($src)) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tz  = new DateTimeZone('America/Chicago');
        $now = new DateTime('now', $tz);
        $stamp = $now->format('mdY'); // MMDDYYYY

        $orig = safe_archive_name($row['original_name']);
        $dot  = strrpos($orig, '.');
        $base = $dot > 0 ? substr($orig, 0, $dot) : $orig;
        $ext  = $dot > 0 ? substr($orig, $dot) : '';

        $final = $base . '_expired_' . $stamp . $ext;
        for ($i = 2; file_exists("$dir/$final"); $i++) {   // same name + same day
            $final = $base . '_expired_' . $stamp . '_' . $i . $ext;
        }

        $hash = !empty($row['sha256']) ? $row['sha256'] : hash_file('sha256', $src);
        rename($src, "$dir/$final");

        $fmt = 'Y-m-d H:i:s T';
        $up  = (new DateTime('@' . $row['uploaded_at']))->setTimezone($tz)->format($fmt);
        $ex  = $row['expires_at']
             ? (new DateTime('@' . $row['expires_at']))->setTimezone($tz)->format($fmt)
             : 'never';
        $md  = "# Expired file: {$row['original_name']}\n\n"
             . "- Short code: {$row['code']}\n"
             . "- Archived as: $final\n"
             . "- Source IP: " . (!empty($row['ip']) ? $row['ip'] : 'unknown') . "\n"
             . "- Uploaded (Chicago): $up\n"
             . "- Expired (Chicago): $ex\n"
             . "- Archived (Chicago): " . $now->format($fmt) . "\n"
             . "- File size: {$row['size']} bytes\n"
             . "- SHA-256: $hash\n"
             . "- Downloads: {$row['downloads']}\n";
        file_put_contents("$dir/$final.md", $md);
    }
    $db->prepare('DELETE FROM files WHERE id = ?')->execute([$row['id']]);
}

function purge_expired(PDO $db): int {
    $st = $db->prepare('SELECT * FROM files WHERE expires_at IS NOT NULL AND expires_at < ?');
    $st->execute([time()]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        archive_row($db, $row);
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
