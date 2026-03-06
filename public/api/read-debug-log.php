<?php
// Temporary script to read webhook debug log — DELETE AFTER USE
$secret = $_GET['key'] ?? '';
if ($secret !== 'adesz-debug-2026') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
$file = __DIR__ . '/webhook-debug.log';
if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    echo 'No debug log file found.';
}
