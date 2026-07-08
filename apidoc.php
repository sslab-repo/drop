<?php
// drop — API reference page (linked from the main page).
require __DIR__ . '/lib.php';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
$b = htmlspecialchars($base);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>drop API Reference | SSLab, Lewis University</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root { --red:#c22033; --darkRed:#940731; --black:#000; --white:#fff;
          --gray:#dcdcd7; --bg:#f1f1ef; --text:#1a1a1a; --muted:#7e7f74; --border:#dcdcd7; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:'Montserrat', system-ui, sans-serif;
         background:var(--bg); color:var(--text); min-height:100vh;
         display:flex; flex-direction:column; }
  .topstrip { background:var(--black); color:var(--white); font-size:.72rem;
              letter-spacing:.08em; text-transform:uppercase; padding:.45rem 1.2rem; }
  .topstrip a { color:var(--white); text-decoration:none; }
  .masthead { background:var(--white); border-bottom:4px solid var(--red);
              padding:1rem 1.2rem; display:flex; align-items:baseline; gap:.75rem; flex-wrap:wrap; }
  .wordmark { color:var(--red); font-weight:800; letter-spacing:.06em;
              font-size:1.15rem; text-transform:uppercase; }
  .appname { color:var(--black); font-weight:300; font-size:1.15rem; }
  .appname b { font-weight:700; }
  main { flex:1; max-width:760px; width:100%; margin:0 auto; padding:2rem 1rem; }
  h1 { font-size:1.4rem; margin:0 0 .3rem; }
  .sub { color:var(--muted); margin:0 0 1.6rem; font-size:.9rem; }
  h2 { font-size:1.02rem; margin:1.8rem 0 .5rem; text-transform:uppercase;
       letter-spacing:.05em; border-bottom:2px solid var(--red);
       padding-bottom:.3rem; }
  p, li { font-size:.9rem; line-height:1.6; }
  code { font-family:ui-monospace, monospace; background:var(--white);
         border:1px solid var(--border); padding:.05rem .3rem; font-size:.85em; }
  pre { background:var(--black); color:#e7eaf0; padding:.9rem 1rem;
        overflow-x:auto; font-size:.8rem; line-height:1.5; }
  pre code { background:none; border:0; color:inherit; padding:0; }
  table { border-collapse:collapse; width:100%; font-size:.85rem; background:var(--white); }
  th, td { border:1px solid var(--border); padding:.45rem .6rem; text-align:left; }
  th { background:var(--gray); text-transform:uppercase; font-size:.72rem; letter-spacing:.05em; }
  a { color:var(--darkRed); }
  footer { background:var(--black); color:var(--white); padding:1.4rem 1.2rem;
           font-size:.78rem; line-height:1.6; }
  footer a { color:var(--white); }
</style>
</head>
<body>
<div class="topstrip"><a href="https://www.lewisu.edu">Lewis University</a> &nbsp;·&nbsp; Security Science Lab</div>
<div class="masthead">
  <span class="appname">SSLab <b>drop</b> — API Reference</span>
</div>

<main>
  <h1>drop API</h1>
  <p class="sub">HTTP + JSON. Made for scripts and AI agents.
     Base URL: <code><?= $b ?></code> · <a href="./">back to upload page</a></p>

  <h2>Authentication</h2>
  <p><strong>Always send the <code>X-Api-Key</code> header on uploads</strong> —
     the hosting firewall rejects file POSTs without it (HTML 403 instead of a
     JSON reply). Expirations of <strong>30 days or less need no real key</strong>:
     use the literal value <code>none</code>. Longer expirations
     (<code>6m</code>, <code>1y</code>, <code>forever</code>) require a valid
     key (<code>Authorization: Bearer …</code> also accepted). Keys are issued
     by the lab — ask <a href="mailto:sslab@lewisu.edu">sslab@lewisu.edu</a>.
     Invalid-key attempts are throttled (10 per IP / 15 min).</p>

  <h2>1 · Upload</h2>
  <pre><code>curl -X POST "<?= $b ?>/api.php?action=upload" \
     -H "X-Api-Key: none" \
     -F "file=@report.pdf" \
     -F "expiration=30d"

# long expiration — key required:
curl -X POST "<?= $b ?>/api.php?action=upload" \
     -H "X-Api-Key: drop_YOUR_KEY" \
     -F "file=@dataset.zip" \
     -F "expiration=1y"</code></pre>
  <p>Response:</p>
  <pre><code>{"ok":true,"code":"Ab3xK9","url":"<?= $b ?>/Ab3xK9","expiration":"30d","size":1048576}</code></pre>
  <table>
    <tr><th>field</th><th>values</th></tr>
    <tr><td><code>file</code></td><td>multipart file, max 300 MB</td></tr>
    <tr><td><code>expiration</code></td><td><code>1d</code> · <code>30d</code> · <code>6m</code>* · <code>1y</code>* · <code>forever</code>* &nbsp;(* = key required)</td></tr>
  </table>

  <h2>2 · Download</h2>
  <p>No API needed — the short link serves the raw bytes as an attachment:</p>
  <pre><code>curl -OJ "<?= $b ?>/Ab3xK9"</code></pre>
  <p>Expired or unknown codes return <code>410</code> / <code>404</code>.</p>

  <h2>3 · File info &amp; service stats</h2>
  <pre><code>curl "<?= $b ?>/api.php?action=info&amp;code=Ab3xK9"
curl "<?= $b ?>/api.php?action=stats"</code></pre>
  <pre><code>{"ok":true,"code":"Ab3xK9","name":"report.pdf","size":1048576,
 "sha256":"9f86d0…","uploaded_at":1751600000,"expires_at":1754192000,
 "downloads":3,"url":"<?= $b ?>/Ab3xK9"}</code></pre>

  <h2>Errors</h2>
  <p>Every error is JSON: <code>{"ok":false,"error":"…"}</code> with a
     matching HTTP status (<code>400</code> bad request, <code>401</code>
     missing/invalid key, <code>404</code> not found, <code>410</code> expired,
     <code>413</code> too large, <code>429</code> throttled or over quota).</p>

  <h2>Limits &amp; rules</h2>
  <p>Max <strong>300 MB per file</strong> and <strong>1 GB per address per
     day</strong> (rolling 24 h — HTTP 429 when exceeded). Expired files stop
     being downloadable immediately; their data is permanently deleted about a
     week after expiry. Education, research, and study files only. Provided
     as-is with no legal liability. Operated by SSLab, a non-profit lab at
     Lewis University.</p>
</main>

<footer>
  <div><strong>Copyright - Dr. Jake Cho, SSLab@Lewis University</strong></div>
  <div><a href="mailto:sslab@lewisu.edu">sslab@lewisu.edu</a></div>
</footer>
</body>
</html>