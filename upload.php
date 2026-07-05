<?php
// drop — upload handler. Returns JSON.
require __DIR__ . '/lib.php';

// Any uncaught server error must come back as JSON, not a blank page —
// otherwise the uploader only sees "Unexpected server response".
set_exception_handler(function (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
});

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
    json_out(['ok' => false, 'error' => upload_error_message($f['error'])], 400);
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

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (ip_quota_exceeded(db(), $ip, (int)$f['size'])) {
    json_out(['ok' => false, 'error' => 'Daily upload limit reached (1 GB per day per address). Try again tomorrow.'], 429);
}

[$code, $err] = store_file(db(), $f, $expKey);
if ($err !== null) {
    json_out(['ok' => false, 'error' => $err], 500);
}

json_out(['ok' => true, 'code' => $code, 'url' => short_url($code)]);
