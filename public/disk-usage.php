<?php
/**
 * Disk usage analyzer for OVH hosting.
 * Access via: https://adesz.fr/disk-usage.php?key=adesz2026
 * DELETE THIS FILE after use.
 */

if (($_GET['key'] ?? '') !== 'adesz2026') {
    http_response_code(403);
    die('Forbidden');
}

function dir_size(string $path): int {
    $size = 0;
    if (!is_dir($path)) return 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        $size += $file->getSize();
    }
    return $size;
}

function fmt(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

header('Content-Type: text/plain; charset=utf-8');

// Scan /www (parent of /test) to see WordPress files
$root = dirname(dirname(__FILE__));
echo "=== DISK USAGE REPORT ===\n";
echo "Root: $root\n\n";

// Top-level directories
$dirs = [];
foreach (scandir($root) as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $root . '/' . $item;
    if (is_dir($path)) {
        $dirs[$item] = dir_size($path);
    }
}
arsort($dirs);

echo "--- TOP-LEVEL DIRECTORIES ---\n";
$total_dirs = 0;
foreach ($dirs as $name => $size) {
    echo sprintf("%-35s %s\n", $name . '/', fmt($size));
    $total_dirs += $size;
}

// Top-level files
$files_size = 0;
foreach (scandir($root) as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $root . '/' . $item;
    if (is_file($path)) {
        $files_size += filesize($path);
    }
}
echo sprintf("%-35s %s\n", "[root files]", fmt($files_size));
echo "\nTOTAL: " . fmt($total_dirs + $files_size) . "\n";

// Drill into wp-content
$wpc = $root . '/wp-content';
if (is_dir($wpc)) {
    echo "\n--- WP-CONTENT BREAKDOWN ---\n";
    $wpc_dirs = [];
    foreach (scandir($wpc) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $wpc . '/' . $item;
        if (is_dir($path)) {
            $wpc_dirs[$item] = dir_size($path);
        }
    }
    arsort($wpc_dirs);
    foreach ($wpc_dirs as $name => $size) {
        echo sprintf("  %-33s %s\n", $name . '/', fmt($size));
    }

    // Drill into wp-content/uploads by year
    $uploads = $wpc . '/uploads';
    if (is_dir($uploads)) {
        echo "\n--- WP-CONTENT/UPLOADS BREAKDOWN ---\n";
        $up_dirs = [];
        foreach (scandir($uploads) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $uploads . '/' . $item;
            if (is_dir($path)) {
                $up_dirs[$item] = dir_size($path);
            }
        }
        arsort($up_dirs);
        foreach ($up_dirs as $name => $size) {
            echo sprintf("  %-33s %s\n", $name . '/', fmt($size));
        }
    }

    // Drill into wp-content/plugins
    $plugins = $wpc . '/plugins';
    if (is_dir($plugins)) {
        echo "\n--- WP-CONTENT/PLUGINS BREAKDOWN ---\n";
        $pl_dirs = [];
        foreach (scandir($plugins) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $plugins . '/' . $item;
            if (is_dir($path)) {
                $pl_dirs[$item] = dir_size($path);
            }
        }
        arsort($pl_dirs);
        foreach ($pl_dirs as $name => $size) {
            echo sprintf("  %-33s %s\n", $name . '/', fmt($size));
        }
    }

    // Drill into wp-content/themes
    $themes = $wpc . '/themes';
    if (is_dir($themes)) {
        echo "\n--- WP-CONTENT/THEMES BREAKDOWN ---\n";
        $th_dirs = [];
        foreach (scandir($themes) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $themes . '/' . $item;
            if (is_dir($path)) {
                $th_dirs[$item] = dir_size($path);
            }
        }
        arsort($th_dirs);
        foreach ($th_dirs as $name => $size) {
            echo sprintf("  %-33s %s\n", $name . '/', fmt($size));
        }
    }
}
