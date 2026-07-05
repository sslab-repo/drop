<?php
// drop — kept for the main page's poller; same payload as api.php?action=stats.
$_GET['action'] = 'stats';
require __DIR__ . '/api.php';