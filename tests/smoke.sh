#!/usr/bin/env bash
# drop — functional smoke test. Run on any machine with curl (e.g. the hosting
# system via SSH, or your laptop against the live URL).
#
# Usage:
#   ./smoke.sh BASE_URL [UPLOAD_PASSWORD]
#
#   ./smoke.sh https://datapot.org/drop mypassword     # full test incl. password/trust
#   ./smoke.sh http://localhost:8080                   # against `php -S localhost:8080`
#
# Optional env:
#   DROP_DIR=/path/to/drop   also test expiry (rewrites expires_at in SQLite via php CLI)
#   BIG=1                    also test the 300MB limit (creates a ~301MB temp file)
#
# Note: when testing against `php -S` (no Apache), short links /f/CODE need
# the rewrite; this script automatically falls back to f.php?c=CODE.

set -u
BASE="${1:?usage: smoke.sh BASE_URL [PASSWORD]}"
BASE="${BASE%/}"
PASSWORD="${2:-}"

PASS=0; FAIL=0
TMP="$(mktemp -d)"
JAR="$TMP/cookies.txt"
trap 'rm -rf "$TMP"' EXIT

ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1  ($2)"; }

# extract "key":"value" or "key":true from a JSON blob without jq
jget() { sed -n 's/.*"'"$2"'":"\{0,1\}\([^",}]*\)"\{0,1\}.*/\1/p' <<<"$1" | head -1; }

dl_url() { # resolve a returned short URL, falling back to f.php?c= for php -S
  local url="$1" code="$2"
  if curl -s -o /dev/null -w '%{http_code}' "$url" | grep -q '^\(200\|302\)$'; then
    echo "$url"
  else
    echo "$BASE/f.php?c=$code"
  fi
}

echo "== drop smoke test against $BASE =="

# ---------- 1. index page loads ----------
http=$(curl -s -o "$TMP/index.html" -w '%{http_code}' "$BASE/")
if [ "$http" = 200 ] && grep -q 'drop' "$TMP/index.html"; then
  ok "index page loads (200)"
else
  bad "index page loads" "HTTP $http"
fi

# ---------- 2. upload (1 day, no password) ----------
head -c 65536 /dev/urandom > "$TMP/small.bin"
resp=$(curl -s -F "file=@$TMP/small.bin;filename=test file.bin" -F expiration=1d "$BASE/upload.php")
if [ "$(jget "$resp" ok)" = true ]; then
  ok "upload 1d without password"
  url=$(jget "$resp" url); code=$(jget "$resp" code)
  url=${url//\\\//\/}   # unescape JSON slashes

  # ---------- 3. download matches ----------
  real=$(dl_url "$url" "$code")
  curl -s -D "$TMP/headers.txt" -o "$TMP/down.bin" "$real"
  if cmp -s "$TMP/small.bin" "$TMP/down.bin"; then
    ok "downloaded bytes identical to upload"
  else
    bad "downloaded bytes identical" "cmp mismatch on $real"
  fi

  # ---------- 4. forced download + filename ----------
  if grep -qi 'content-disposition: *attachment' "$TMP/headers.txt"; then
    ok "download is forced attachment"
  else
    bad "download is forced attachment" "no attachment header"
  fi
  if grep -qi 'test%20file.bin\|test file.bin' "$TMP/headers.txt"; then
    ok "original filename preserved"
  else
    bad "original filename preserved" "filename not in headers"
  fi
else
  bad "upload 1d without password" "$resp"
fi

# ---------- 5. bogus code -> 404 ----------
http=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/f.php?c=NoSuch9")
[ "$http" = 404 ] && ok "unknown code returns 404" || bad "unknown code returns 404" "HTTP $http"

# ---------- 6. protected expiration refused without password ----------
resp=$(curl -s -F "file=@$TMP/small.bin" -F expiration=6m "$BASE/upload.php")
if [ "$(jget "$resp" ok)" = false ] && [ "$(jget "$resp" need_password)" = true ]; then
  ok "6m without password refused (need_password)"
else
  bad "6m without password refused" "$resp"
fi

# ---------- 7. wrong password refused ----------
resp=$(curl -s -F "file=@$TMP/small.bin" -F expiration=1y -F password=definitely-wrong-pw "$BASE/upload.php")
if [ "$(jget "$resp" ok)" = false ]; then
  ok "wrong password refused"
else
  bad "wrong password refused" "$resp"
fi

# ---------- 8. invalid expiration key refused ----------
resp=$(curl -s -F "file=@$TMP/small.bin" -F expiration=99years "$BASE/upload.php")
if [ "$(jget "$resp" ok)" = false ]; then
  ok "invalid expiration key refused"
else
  bad "invalid expiration key refused" "$resp"
fi

# ---------- 9-11. correct password + 30-day trust cookie ----------
if [ -n "$PASSWORD" ]; then
  resp=$(curl -s -c "$JAR" -F "file=@$TMP/small.bin" -F expiration=6m -F "password=$PASSWORD" "$BASE/upload.php")
  if [ "$(jget "$resp" ok)" = true ]; then
    ok "6m with correct password accepted"
  else
    bad "6m with correct password accepted" "$resp"
  fi

  if grep -q drop_trust "$JAR" 2>/dev/null; then
    ok "trust cookie set after correct password"
  else
    bad "trust cookie set" "no drop_trust in cookie jar"
  fi

  resp=$(curl -s -b "$JAR" -F "file=@$TMP/small.bin" -F expiration=forever "$BASE/upload.php")
  if [ "$(jget "$resp" ok)" = true ]; then
    ok "trusted computer uploads 'forever' without password"
  else
    bad "trusted upload without password" "$resp"
  fi

  # tampered cookie must NOT be trusted
  sed 's/drop_trust\t\([0-9]*\)\./drop_trust\t9999999999./' "$JAR" > "$JAR.tampered"
  resp=$(curl -s -b "$JAR.tampered" -F "file=@$TMP/small.bin" -F expiration=forever "$BASE/upload.php")
  if [ "$(jget "$resp" ok)" = false ]; then
    ok "tampered trust cookie rejected"
  else
    bad "tampered trust cookie rejected" "$resp"
  fi
else
  echo "  SKIP  password/trust tests (no password given — pass it as 2nd arg)"
fi

# ---------- 12. expiry (needs DROP_DIR + php CLI on this machine) ----------
if [ -n "${DROP_DIR:-}" ] && command -v php >/dev/null; then
  resp=$(curl -s -F "file=@$TMP/small.bin" -F expiration=1d "$BASE/upload.php")
  code=$(jget "$resp" code)
  if [ -n "$code" ]; then
    php -r '$d=new PDO("sqlite:".$argv[1]."/data/app.sqlite");
            $s=$d->prepare("UPDATE files SET expires_at=1 WHERE code=?");$s->execute([$argv[2]]);' "$DROP_DIR" "$code"
    http=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/f.php?c=$code")
    [ "$http" = 410 ] && ok "expired file returns 410" || bad "expired file returns 410" "HTTP $http"
    php "$DROP_DIR/cleanup.php" >/dev/null 2>&1 && ok "cleanup.php runs via CLI" || bad "cleanup.php via CLI" "nonzero exit"
  fi
else
  echo "  SKIP  expiry + cleanup tests (set DROP_DIR=/path/to/drop and need php CLI)"
fi

# ---------- 13. oversize upload (opt-in: BIG=1) ----------
if [ "${BIG:-0}" = 1 ]; then
  head -c $((301*1024*1024)) /dev/zero > "$TMP/big.bin"
  resp=$(curl -s -F "file=@$TMP/big.bin" -F expiration=1d "$BASE/upload.php")
  if [ "$(jget "$resp" ok)" = false ]; then
    ok "301MB upload rejected"
  else
    bad "301MB upload rejected" "$resp"
  fi
else
  echo "  SKIP  300MB limit test (set BIG=1 to enable)"
fi

echo "== done: $PASS passed, $FAIL failed =="
exit $((FAIL > 0))
