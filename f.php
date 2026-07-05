<?php
// drop — download handler: /drop/f/CODE  (rewritten to f.php?c=CODE)
require __DIR__ . '/lib.php';

$code = $_GET['c'] ?? '';
if (!preg_match('/^[A-Za-z0-9]{4,16}$/', $code)) {
    http_response_code(404);
    exit('Not found.');
}

$db = db();
$st = $db->prepare('SELECT * FROM files WHERE code = ?');
$st->execute([$code]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Not found.');
}

// Lazy expiry check — never serve an expired file even if cron hasn't run yet.
if ($row['expires_at'] !== null && (int)$row['expires_at'] < time()) {
    archive_row($db, $row);
    http_response_code(410);
    exit('This link has expired.');
}

$path = __DIR__ . '/data/' . $row['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

$db->prepare('UPDATE files SET downloads = downloads + 1 WHERE id = ?')->execute([$row['id']]);

// Always force download — never render user content in the browser.
$name = $row['original_name'];
$fallback = preg_replace('/[^\x20-\x7E]/', '_', $name);       // ASCII fallback
$fallback = str_replace(['\\', '"'], '_', $fallback);         // can't break out of the quoted header value
@set_time_limit(0);                                           // large files on slow connections
@ini_set('zlib.output_compression', 'Off');                   // keep Content-Length accurate
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename=\"$fallback\"; filename*=UTF-8''" . rawurlencode($name));

// Stream in chunks to stay within shared-hosting memory limits.
$fp = fopen($path, 'rb');
while (!feof($fp)) {
    echo fread($fp, 1024 * 1024);
    flush();
}
fclose($fp);
