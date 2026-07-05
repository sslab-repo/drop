<?php
// drop — API key manager. SERVER-SIDE CLI ONLY (also blocked in .htaccess).
//
//   php apikey.php list                 show all keys (id, prefix, owner, created)
//   php apikey.php add "Owner Name"     generate a key — plaintext shown ONCE
//   php apikey.php remove <id>          delete a key by its list id
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require __DIR__ . '/lib.php';

$db  = db();
$cmd = $argv[1] ?? 'list';

switch ($cmd) {

case 'list':
    $rows = $db->query('SELECT id, prefix, owner, created_at FROM api_keys ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "No API keys. Create one with:  php apikey.php add \"Owner Name\"\n";
        break;
    }
    $tz = new DateTimeZone('America/Chicago');
    printf("%-4s %-14s %-30s %s\n", 'ID', 'KEY PREFIX', 'OWNER', 'CREATED (Chicago)');
    foreach ($rows as $r) {
        $created = (new DateTime('@' . $r['created_at']))->setTimezone($tz)->format('Y-m-d H:i');
        printf("%-4d %-14s %-30s %s\n", $r['id'], $r['prefix'] . '…', $r['owner'], $created);
    }
    break;

case 'add':
    $owner = trim($argv[2] ?? '');
    if ($owner === '') {
        fwrite(STDERR, "Usage: php apikey.php add \"Owner Name\"\n");
        exit(1);
    }
    $key = 'drop_' . bin2hex(random_bytes(20));
    $db->prepare('INSERT INTO api_keys (key_hash, prefix, owner, created_at) VALUES (?, ?, ?, ?)')
       ->execute([hash('sha256', $key), substr($key, 0, 10), $owner, time()]);
    echo "API key for \"$owner\":\n\n    $key\n\n";
    echo "Store it now — only its hash is kept, it cannot be shown again.\n";
    break;

case 'remove':
    $id = (int)($argv[2] ?? 0);
    if ($id <= 0) {
        fwrite(STDERR, "Usage: php apikey.php remove <id>   (see: php apikey.php list)\n");
        exit(1);
    }
    $st = $db->prepare('DELETE FROM api_keys WHERE id = ?');
    $st->execute([$id]);
    echo $st->rowCount() ? "Key $id removed.\n" : "No key with id $id.\n";
    break;

default:
    echo "Usage: php apikey.php list | add \"Owner Name\" | remove <id>\n";
}