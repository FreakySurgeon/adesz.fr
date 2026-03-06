<?php
/**
 * Disk usage analyzer for OVH hosting.
 * Access via: https://adesz.fr/test/disk-usage.php?key=adesz2026
 * DELETE THIS FILE after use.
 */

if (($_GET['key'] ?? '') !== 'adesz2026') {
    http_response_code(403);
    die('Forbidden');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(120);

function dir_size(string $path): int {
    $size = 0;
    if (!is_dir($path)) return 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $it->setMaxDepth(20);
        foreach ($it as $file) {
            try { $size += $file->getSize(); } catch (Exception $e) {}
        }
    } catch (Exception $e) {}
    return $size;
}

function fmt(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function scan_dir_sizes(string $root): array {
    $dirs = [];
    $files_size = 0;
    $entries = @scandir($root);
    if (!$entries) return ['dirs' => [], 'files' => 0, 'error' => 'Cannot read: ' . $root];
    foreach ($entries as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $root . '/' . $item;
        if (is_dir($path)) {
            $dirs[$item] = dir_size($path);
        } elseif (is_file($path)) {
            $files_size += @filesize($path) ?: 0;
        }
    }
    arsort($dirs);
    return ['dirs' => $dirs, 'files' => $files_size];
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== ENVIRONMENT ===\n";
echo "Script: " . __FILE__ . "\n";
echo "open_basedir: " . (ini_get('open_basedir') ?: '(none)') . "\n";
echo "document_root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";

// Try known OVH paths
$paths_to_try = [
    '/home/adeszfz',
    dirname(dirname(dirname(__FILE__))),  // up from /www/test/
    dirname(dirname(__FILE__)),           // /www/
];

foreach ($paths_to_try as $home) {
    if (!@is_dir($home)) continue;
    echo "\n=== SCANNING: $home ===\n";
    $result = scan_dir_sizes($home);
    if (isset($result['error'])) {
        echo "ERROR: " . $result['error'] . "\n";
        continue;
    }
    $total = 0;
    foreach ($result['dirs'] as $name => $size) {
        echo sprintf("%-35s %s\n", $name . '/', fmt($size));
        $total += $size;
    }
    if ($result['files']) {
        echo sprintf("%-35s %s\n", "[files]", fmt($result['files']));
        $total += $result['files'];
    }
    echo "TOTAL: " . fmt($total) . "\n";
    break; // stop at first successful scan
}

// Scan /www specifically
$www = dirname(dirname(__FILE__));
echo "\n=== /www BREAKDOWN ===\n";
$result = scan_dir_sizes($www);
$total = 0;
foreach ($result['dirs'] as $name => $size) {
    echo sprintf("%-35s %s\n", $name . '/', fmt($size));
    $total += $size;
}
echo sprintf("%-35s %s\n", "[files]", fmt($result['files']));
echo "TOTAL: " . fmt($total + $result['files']) . "\n";

// Check OVH-specific paths for logs
echo "\n=== OVH KNOWN PATHS ===\n";
$ovh_paths = [
    '/home/adeszfz/logs',
    '/home/adeszfz/tmp',
    '/home/adeszfz/.composer',
    '/home/adeszfz/.npm',
    '/home/adeszfz/mail',
    '/home/adeszfz/ovhconfig',
];
foreach ($ovh_paths as $p) {
    if (@is_dir($p)) {
        echo sprintf("%-35s %s\n", $p . '/', fmt(dir_size($p)));
    } elseif (@is_file($p)) {
        echo sprintf("%-35s %s\n", $p, fmt(@filesize($p)));
    } else {
        echo sprintf("%-35s %s\n", $p, "(not accessible)");
    }
}

// Also try disk_free_space
echo "\n=== DISK SPACE ===\n";
$free = @disk_free_space('/home/adeszfz/www/');
$total_disk = @disk_total_space('/home/adeszfz/www/');
if ($free !== false && $total_disk !== false) {
    echo "Total quota: " . fmt((int)$total_disk) . "\n";
    echo "Free: " . fmt((int)$free) . "\n";
    echo "Used: " . fmt((int)($total_disk - $free)) . "\n";
} else {
    echo "disk_free_space not available\n";
}

// WP-content breakdown (post-cleanup)
$wpc = $www . '/wp-content';
if (is_dir($wpc)) {
    echo "\n=== WP-CONTENT (post-cleanup) ===\n";
    $result = scan_dir_sizes($wpc);
    $total = 0;
    foreach ($result['dirs'] as $name => $size) {
        echo sprintf("  %-33s %s\n", $name . '/', fmt($size));
        $total += $size;
    }
    echo "  WP-CONTENT TOTAL: " . fmt($total) . "\n";
}
