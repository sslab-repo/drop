<?php
// drop — cron target: archives expired files (data/archive/) and kills their links.
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
    if (is_dir($path)) continue; // e.g. data/archive/ — never touched by the sweep
    $base = basename($path);
    if (str_starts_with($base, '.') || str_starts_with($base, 'app.sqlite')) continue;
    // Age guard: a fresh blob may not have its DB row yet (mid-upload) — never
    // treat anything younger than 1 hour as an orphan.
    if (!isset($known[$base]) && filemtime($path) < time() - 3600) {
        @unlink($path);
        $orphans++;
    }
}

// Archive retention: expired blobs are kept 7 days then deleted permanently;
// their .md metadata sidecars survive 30 days. (Days configurable in config.php.)
$cfg = cfg();
$keepBlob = time() - 86400 * (int)($cfg['archive_keep_days'] ?? 7);
$keepMeta = time() - 86400 * (int)($cfg['archive_meta_keep_days'] ?? 30);
$blobsPurged = $metaPurged = 0;
foreach (glob(__DIR__ . '/data/archive/*') as $path) {
    if (!is_file($path)) continue;
    $isMeta = substr($path, -3) === '.md';
    $mtime  = filemtime($path);
    if ($isMeta ? $mtime < $keepMeta : $mtime < $keepBlob) {
        @unlink($path);
        $isMeta ? $metaPurged++ : $blobsPurged++;
    }
}

echo "expired archived: $removed, orphans removed: $orphans, "
   . "archive blobs purged: $blobsPurged, metadata purged: $metaPurged\n";
