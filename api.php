<?php
// drop — agent/API endpoint. JSON only. See apidoc.php for the reference.
//
//   POST api.php?action=upload   multipart: file, expiration=1d|30d|6m|1y|forever
//                                header X-Api-Key required for 6m/1y/forever
//   GET  api.php?action=info&code=CODE
//   GET  api.php?action=stats
//   (downloads need no API: GET /drop/CODE)
require __DIR__ . '/lib.php';

set_exception_handler(function (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
});

$db = db();
$cfg = cfg();

// Key from X-Api-Key or Authorization: Bearer (FPM/CGI setups deliver the
// rewritten header as REDIRECT_HTTP_AUTHORIZATION — check both)
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($key === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) $key = $m[1];
}

switch ($_GET['action'] ?? '') {

case 'upload':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'POST only.'], 405);
    }
    if (empty($_FILES['file'])) {
        json_out(['ok' => false, 'error' => 'No file received — it may exceed the server limit.'], 400);
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => upload_error_message($f['error'])], 400);
    }
    if ($f['size'] > $cfg['max_bytes']) {
        json_out(['ok' => false, 'error' => 'File is larger than 300 MB.'], 413);
    }
    $expKey = $_POST['expiration'] ?? '';
    if (!isset($cfg['expirations'][$expKey])) {
        json_out(['ok' => false, 'error' => 'Invalid expiration. Use: ' . implode(', ', array_keys($cfg['expirations'])) . '.'], 400);
    }

    // Expirations of 30 days or less need no key; longer ones require a valid one.
    $owner = null;
    if (in_array($expKey, $cfg['protected'], true)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (pw_blocked($db, $ip)) {
            json_out(['ok' => false, 'error' => 'Too many invalid attempts — try again in 15 minutes.'], 429);
        }
        if ($key === '') {
            json_out(['ok' => false, 'error' => 'This expiration requires an API key (X-Api-Key header).', 'need_key' => true], 401);
        }
        $owner = api_key_owner($db, $key);
        if ($owner === null) {
            pw_record_failure($db, $ip);
            json_out(['ok' => false, 'error' => 'Invalid API key.'], 401);
        }
    }

    if (ip_quota_exceeded($db, $_SERVER['REMOTE_ADDR'] ?? 'unknown', (int)$f['size'])) {
        json_out(['ok' => false, 'error' => 'Daily upload limit reached (1 GB per day per address).'], 429);
    }

    [$code, $err] = store_file($db, $f, $expKey, $owner);
    if ($err !== null) {
        json_out(['ok' => false, 'error' => $err], 500);
    }
    json_out([
        'ok'         => true,
        'code'       => $code,
        'url'        => short_url($code),
        'expiration' => $expKey,
        'size'       => (int)$f['size'],
    ]);

case 'info':
    $code = $_GET['code'] ?? '';
    if (!preg_match('/^[A-Za-z0-9]{4,16}$/', $code)) {
        json_out(['ok' => false, 'error' => 'Invalid code.'], 400);
    }
    $st = $db->prepare('SELECT * FROM files WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_out(['ok' => false, 'error' => 'Not found.'], 404);
    }
    if ($row['expires_at'] !== null && (int)$row['expires_at'] < time()) {
        archive_row($db, $row);
        json_out(['ok' => false, 'error' => 'Expired.'], 410);
    }
    json_out([
        'ok'          => true,
        'code'        => $row['code'],
        'name'        => $row['original_name'],
        'size'        => (int)$row['size'],
        'sha256'      => $row['sha256'],
        'uploaded_at' => (int)$row['uploaded_at'],
        'expires_at'  => $row['expires_at'] === null ? null : (int)$row['expires_at'],
        'downloads'   => (int)$row['downloads'],
        'url'         => short_url($row['code']),
    ]);

case 'stats':
    [$n, $bytes] = $db->query('SELECT COUNT(*), COALESCE(SUM(size),0) FROM files')->fetch(PDO::FETCH_NUM);
    $archBytes = 0; $archFiles = 0;
    foreach (glob(__DIR__ . '/data/archive/*') as $p) {
        if (is_file($p) && substr($p, -3) !== '.md') { $archBytes += filesize($p); $archFiles++; }
    }
    $lt = $db->query('SELECT k, v FROM stats')->fetchAll(PDO::FETCH_KEY_PAIR);
    json_out([
        'ok'             => true,
        'active_files'   => (int)$n,
        'active_bytes'   => (int)$bytes,
        'archived_files' => $archFiles,
        'archived_bytes' => $archBytes,
        'lifetime_files' => (int)($lt['total_files'] ?? 0),
        'lifetime_bytes' => (int)($lt['total_bytes'] ?? 0),
    ]);

default:
    json_out(['ok' => false, 'error' => 'Unknown action. Use: upload, info, stats.'], 404);
}