# drop — simple file sharing

Minimal file-sharing web app for shared hosting: upload a file (≤300 MB), pick
an expiration, get a short download link. No daemon, no database server —
PHP + SQLite + one cron line.

Lives at `https://datapot.org/drop/`.

## Features

- Upload up to **300 MB** with a live **progress bar**
- Expirations: **1 day / 30 days / 6 months / 1 year / forever**
- Long expirations (6 m / 1 y / forever) require the **upload password**;
  a correct password trusts that computer for **30 days** (signed cookie)
- Short download links: `https://datapot.org/drop/f/Ab3xK9`
- Downloads are always forced as attachments (nothing renders in the browser)
- Expired files removed by daily cron + checked lazily on every download

## Requirements

- **PHP ≥ 8.0** with the SQLite PDO driver (standard on shared hosts)
- **Apache or LiteSpeed** (`.htaccess` support). ⚠️ On nginx `.htaccess` is
  ignored — the `data/` protection and `/f/CODE` rewrites must be replicated
  in the nginx config, otherwise uploaded blobs and the database are
  web-readable.

## Setup (one time)

1. **Upload** this `drop/` folder to the web root of the hosting account.

2. **Set the password.** Generate a hash:

   ```
   php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Paste it into `password_hash` in `config.php`. Also change `secret` and
   `cron_token` to long random strings.

   To **change the password later**: run the command again, paste the new
   hash. To **revoke all trusted computers**: change `secret`.

3. **Permissions.** Make sure PHP can write to `data/`:

   ```
   chmod 775 data
   ```

4. **Cron.** Add one daily line in the hosting control panel:

   ```
   10 3 * * * php /home/USER/public_html/drop/cleanup.php
   ```

   If the host's cron only fetches URLs:

   ```
   10 3 * * * wget -qO- "https://datapot.org/drop/cleanup.php?token=YOUR_CRON_TOKEN"
   ```

5. **Check upload limits.** `.htaccess` (mod_php) and `.user.ini` (PHP-FPM/CGI)
   both raise the limits to 310 MB. Some hosts enforce a lower hard cap —
   if uploads near 300 MB fail, ask the host or lower `max_bytes` in
   `config.php` to match.

## Files

| File | Purpose |
|---|---|
| `index.php` | Upload page (progress bar, expiration picker, result link) |
| `upload.php` | Receives the upload, enforces size/password, stores file |
| `f.php` | Download handler for `/f/CODE` short links |
| `cleanup.php` | Cron target — deletes expired files and orphan blobs |
| `config.php` | Password hash, secrets, size limit, expiration table |
| `lib.php` | Shared helpers (DB, trust cookie, code generator) |
| `data/` | SQLite DB + uploaded blobs (web access denied) |

## Testing

`tests/smoke.sh` is a curl-based functional test — run it from SSH on the
hosting system (or anywhere with curl) against the deployed URL:

```
./tests/smoke.sh https://datapot.org/drop YOUR_UPLOAD_PASSWORD

# extras:
DROP_DIR=~/public_html/drop ./tests/smoke.sh ...   # also tests expiry + cleanup
BIG=1 ./tests/smoke.sh ...                         # also tests the 300MB limit
```

It covers: page load, upload/download round-trip (byte-identical), forced
attachment + filename, 404/410 handling, password enforcement, wrong password,
invalid expiration, trust cookie issue/use, and tampered-cookie rejection.

## Security notes

- `data/`, `config.php`, `lib.php`, `.user.ini` are blocked from web access
  via `.htaccess` (data/ has its own deny-all). **Apache/LiteSpeed only** —
  see Requirements.
- Stored files get random names; short codes are unguessable (58^6).
- The trust cookie is HMAC-signed with `secret` and `SameSite=Lax` (blocks
  cross-site POST reuse); it cannot be forged without the secret.
- Wrong password attempts are throttled: 10 per IP per 15 minutes.
- Downloads send `Content-Disposition: attachment` + `nosniff`, so uploaded
  HTML/JS can never execute on the site's origin; `"`/`\` are stripped from
  the filename so it cannot break the header.
- **Known accepted risks:** short-expiry uploads (1d/30d) need no password, so
  anyone who finds the URL can consume disk quota until expiry — monitor
  usage, or add the short keys to `protected` in `config.php` to require the
  password for everything. The whole file uploads before the password is
  checked (single-request design). No virus scanning — links are only as safe
  as the people you share them with.
