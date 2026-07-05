<?php
// drop — cron target: deletes expired files.
//
// Preferred crontab (daily at 03:10):
//   10 3 * * * php /path/to/drop/cleanup.php
//
// If your host's cron can only fetch URLs, use the token from config.php:
//   10 3 * * * wget -qO- "https://datapot.org/drop/cleanup.php?token=YOUR_CRON_TOKEN"
require __DIR__ . '/lib.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli && !hash_equals(cfg()['cron_token'], (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden.');
}

$db = db();
$removed = purge_expired($db);

// Also remove orphan blobs in data/ that have no DB row (safety net).
$known = $db->query('SELECT stored_name FROM files')->fetchAll(PDO::FETCH_COLUMN);
$known = array_flip($known);
$orphans = 0;
foreach (glob(__DIR__ . '/data/*') as $path) {
    $base = basename($path);
    if (str_starts_with($base, '.') || str_starts_with($base, 'app.sqlite')) continue;
    // Age guard: a fresh blob may not have its DB row yet (mid-upload) — never
    // treat anything younger than 1 hour as an orphan.
    if (!isset($known[$base]) && filemtime($path) < time() - 3600) {
        @unlink($path);
        $orphans++;
    }
}

echo "expired removed: $removed, orphans removed: $orphans\n";
