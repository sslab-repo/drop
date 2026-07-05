<?php
// drop — service size stats as JSON (polled by the main page).
require __DIR__ . '/lib.php';

$db = db();
[$n, $bytes] = $db->query('SELECT COUNT(*), COALESCE(SUM(size),0) FROM files')->fetch(PDO::FETCH_NUM);

$archBytes = 0;
$archFiles = 0;
foreach (glob(__DIR__ . '/data/archive/*') as $p) {
    if (is_file($p) && substr($p, -3) !== '.md') {
        $archBytes += filesize($p);
        $archFiles++;
    }
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
