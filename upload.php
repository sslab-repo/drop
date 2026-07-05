<?php
// drop — upload handler. Returns JSON.
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST only'], 405);
}

$cfg = cfg();

// If PHP dropped the upload because post_max_size was exceeded, $_FILES is empty.
if (empty($_FILES['file'])) {
    json_out(['ok' => false, 'error' => 'No file received — it may exceed the server limit.'], 400);
}

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    $msg = match ($f['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the size limit.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted — please retry.',
        UPLOAD_ERR_NO_FILE => 'No file selected.',
        default => 'Upload failed (code ' . $f['error'] . ').',
    };
    json_out(['ok' => false, 'error' => $msg], 400);
}
if ($f['size'] > $cfg['max_bytes']) {
    json_out(['ok' => false, 'error' => 'File is larger than 300 MB.'], 413);
}

$expKey = $_POST['expiration'] ?? '';
if (!isset($cfg['expirations'][$expKey])) {
    json_out(['ok' => false, 'error' => 'Invalid expiration choice.'], 400);
}

// Long expirations require the password unless this computer is trusted.
if (in_array($expKey, $cfg['protected'], true) && !is_trusted()) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (pw_blocked(db(), $ip)) {
        json_out(['ok' => false, 'error' => 'Too many wrong password attempts — try again in 15 minutes.'], 429);
    }
    $pw = (string)($_POST['password'] ?? '');
    if ($pw === '' || !password_verify($pw, $cfg['password_hash'])) {
        if ($pw !== '') pw_record_failure(db(), $ip);
        json_out(['ok' => false, 'error' => 'Password required for this expiration.', 'need_password' => true], 403);
    }
    grant_trust(); // correct password → trust this computer for 30 days
}

$db = db();
$code = gen_code($db);
$storedName = bin2hex(random_bytes(16));
$dest = __DIR__ . '/data/' . $storedName;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    json_out(['ok' => false, 'error' => 'Could not store the file on the server.'], 500);
}

$seconds = $cfg['expirations'][$expKey][1];
$db->prepare('INSERT INTO files (code, original_name, stored_name, size, mime, uploaded_at, expires_at, ip, sha256)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
   ->execute([
        $code,
        $f['name'],
        $storedName,
        $f['size'],
        $f['type'] ?: null,
        time(),
        $seconds === null ? null : time() + $seconds,
        $_SERVER['REMOTE_ADDR'] ?? null,
        hash_file('sha256', $dest),   // recorded now for the expiry archive metadata
    ]);

// Build the short link from the current location: /drop/upload.php → /drop/CODE
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir . '/' . $code;

json_out(['ok' => true, 'code' => $code, 'url' => $url]);
