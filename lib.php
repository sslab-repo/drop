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
        // Migrations for databases created before these columns existed.
        $cols = $db->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ip', $cols, true))        $db->exec('ALTER TABLE files ADD COLUMN ip TEXT');
        if (!in_array('sha256', $cols, true))    $db->exec('ALTER TABLE files ADD COLUMN sha256 TEXT');
        if (!in_array('api_owner', $cols, true)) $db->exec('ALTER TABLE files ADD COLUMN api_owner TEXT');
        // API keys — managed only by the server-side CLI (apikey.php).
        $db->exec('CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key_hash TEXT UNIQUE NOT NULL,   -- sha256 of the key; plaintext never stored
            prefix TEXT NOT NULL,            -- first chars, for identification in listings
            owner TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS pw_failures (
            ip TEXT NOT NULL,
            ts INTEGER NOT NULL
        )');
        // Lifetime counters (all-time bytes/files uploaded, survives expiry).
        $db->exec('CREATE TABLE IF NOT EXISTS stats (
            k TEXT PRIMARY KEY,
            v INTEGER NOT NULL
        )');
        if ((int)$db->query('SELECT COUNT(*) FROM stats')->fetchColumn() === 0) {
            // First run after this feature: seed from what is already stored.
            [$n, $b] = $db->query('SELECT COUNT(*), COALESCE(SUM(size),0) FROM files')->fetch(PDO::FETCH_NUM);
            $seed = $db->prepare('INSERT INTO stats (k, v) VALUES (?, ?)');
            $seed->execute(['total_files', (int)$n]);
            $seed->execute(['total_bytes', (int)$b]);
        }
    }
    return $db;
}

function stat_add(PDO $db, string $k, int $v): void {
    $st = $db->prepare('UPDATE stats SET v = v + ? WHERE k = ?');
    $st->execute([$v, $k]);
    if ($st->rowCount() === 0) {
        $db->prepare('INSERT INTO stats (k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
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

// ---- API keys ----

function api_key_owner(PDO $db, string $key): ?string {
    if ($key === '') return null;
    $st = $db->prepare('SELECT owner FROM api_keys WHERE key_hash = ?');
    $st->execute([hash('sha256', $key)]);
    $owner = $st->fetchColumn();
    return $owner === false ? null : (string)$owner;
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
        if (!@rename($src, "$dir/$final")) {
            // Archive not writable: keep the DB row so the next cron/access
            // retries — NEVER let the blob fall through to the orphan sweep.
            // (The link is already dead: callers 410 before serving.)
            error_log("drop: cannot archive {$row['code']} to data/archive/ — check permissions");
            return;
        }

        $fmt = 'Y-m-d H:i:s T';
        $up  = (new DateTime('@' . $row['uploaded_at']))->setTimezone($tz)->format($fmt);
        $ex  = $row['expires_at']
             ? (new DateTime('@' . $row['expires_at']))->setTimezone($tz)->format($fmt)
             : 'never';
        $md  = "# Expired file: {$row['original_name']}\n\n"
             . "- Short code: {$row['code']}\n"
             . "- Archived as: $final\n"
             . "- Source IP: " . (!empty($row['ip']) ? $row['ip'] : 'unknown') . "\n"
             . (!empty($row['api_owner']) ? "- Uploaded via API key: {$row['api_owner']}\n" : '')
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

// ---- upload storage core (shared by upload.php and api.php) ----

function upload_error_message(int $err): string {
    switch ($err) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'File exceeds the size limit.';
        case UPLOAD_ERR_PARTIAL:   return 'Upload was interrupted — please retry.';
        case UPLOAD_ERR_NO_FILE:   return 'No file selected.';
        default:                   return 'Upload failed (code ' . $err . ').';
    }
}

// Stores a validated $_FILES entry; returns [code, error] — one is null.
function store_file(PDO $db, array $f, string $expKey, ?string $apiOwner = null): array {
    $cfg = cfg();
    $code = gen_code($db);
    $storedName = bin2hex(random_bytes(16));
    $dest = __DIR__ . '/data/' . $storedName;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return [null, 'Could not store the file on the server.'];
    }
    $seconds = $cfg['expirations'][$expKey][1];
    $db->prepare('INSERT INTO files (code, original_name, stored_name, size, mime, uploaded_at, expires_at, ip, sha256, api_owner)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([
            $code,
            $f['name'],
            $storedName,
            $f['size'],
            $f['type'] ?: null,
            time(),
            $seconds === null ? null : time() + $seconds,
            $_SERVER['REMOTE_ADDR'] ?? null,
            hash_file('sha256', $dest),
            $apiOwner,
        ]);
    stat_add($db, 'total_files', 1);
    stat_add($db, 'total_bytes', (int)$f['size']);
    return [$code, null];
}

function short_url(string $code): string {
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/' . $code;
}

function json_out(array $data, int $status = 200) { // no return type: PHP 8.0 compat
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
