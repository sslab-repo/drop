<?php
// drop — configuration. Keep this file NOT web-readable (.htaccess denies it).
return [
    // Upload password required for long expirations (6m / 1y / forever).
    // Generate a new hash with:  php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT), PHP_EOL;"
    'password_hash' => '$2y$10$CHANGE_ME_RUN_THE_COMMAND_ABOVE_AND_PASTE_HASH_HERE.....',

    // Secret for signing the 30-day "trusted computer" cookie.
    // Change to any long random string. Changing it revokes all trusted computers.
    'secret'        => 'CHANGE_ME_to_a_long_random_string',

    // Secret token so cron can call cleanup.php over HTTP (if your host's cron
    // uses wget/curl instead of the php CLI). Change it.
    'cron_token'    => 'CHANGE_ME_cron_token',

    // Max upload size in bytes (300 MB).
    'max_bytes'     => 300 * 1024 * 1024,

    // Per-IP upload quota: max bytes per rolling 24h (1 GB).
    'daily_ip_bytes' => 1024 * 1024 * 1024,

    // Archive retention (days): expired blobs are deleted permanently after
    // 'archive_keep_days'; their .md metadata survives until 'archive_meta_keep_days'.
    'archive_keep_days'      => 7,
    'archive_meta_keep_days' => 30,

    // How long a correct password trusts this computer (seconds) — 30 days.
    'trust_seconds' => 30 * 86400,

    // Expiration choices: key => [label, seconds] (null = forever).
    'expirations'   => [
        '1d'      => ['1 day',    86400],
        '30d'     => ['30 days',  30 * 86400],
        '6m'      => ['6 months', 182 * 86400],
        '1y'      => ['1 year',   365 * 86400],
        'forever' => ['Forever',  null],
    ],

    // Expiration keys that require the password.
    'protected'     => ['6m', '1y', 'forever'],
];
