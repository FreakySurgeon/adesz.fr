<?php
/**
 * Disk cleanup analyzer for OVH hosting.
 * Access via: https://adesz.fr/test/disk-cleanup.php?key=adesz2026
 * Add &action=delete to actually delete.
 * DELETE THIS FILE after use.
 */

if (($_GET['key'] ?? '') !== 'adesz2026') {
    http_response_code(403);
    die('Forbidden');
}

$action = $_GET['action'] ?? 'analyze';
$root = dirname(dirname(__FILE__)); // /www

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

function rmdir_recursive(string $dir): bool {
    if (!is_dir($dir)) return false;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    return rmdir($dir);
}

header('Content-Type: text/plain; charset=utf-8');

// ============================================================
// PART 1: Check if ACF is used in posts/pages
// ============================================================
echo "=== ACF USAGE CHECK ===\n";
$wp_config = $root . '/wp-config.php';
if (file_exists($wp_config)) {
    // Bootstrap WordPress minimally to query DB
    define('ABSPATH', $root . '/');
    // Extract DB credentials from wp-config
    $config_content = file_get_contents($wp_config);
    preg_match("/define\s*\(\s*'DB_NAME'\s*,\s*'([^']+)'/", $config_content, $m);
    $db_name = $m[1] ?? '';
    preg_match("/define\s*\(\s*'DB_USER'\s*,\s*'([^']+)'/", $config_content, $m);
    $db_user = $m[1] ?? '';
    preg_match("/define\s*\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'/", $config_content, $m);
    $db_pass = $m[1] ?? '';
    preg_match("/define\s*\(\s*'DB_HOST'\s*,\s*'([^']+)'/", $config_content, $m);
    $db_host = $m[1] ?? 'localhost';
    preg_match("/table_prefix\s*=\s*'([^']+)'/", $config_content, $m);
    $prefix = $m[1] ?? 'wp_';

    if ($db_name && $db_user) {
        $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($db->connect_error) {
            echo "DB connection failed: " . $db->connect_error . "\n";
        } else {
            $db->set_charset('utf8mb4');

            // Check ACF fields
            $res = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}postmeta WHERE meta_key LIKE '_%' AND meta_key NOT LIKE '\_%' LIMIT 1");
            $acf_count = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}postmeta WHERE meta_key LIKE 'field_%'")->fetch_assoc()['cnt'];
            echo "ACF field definitions in DB: $acf_count\n";

            $acf_usage = $db->query("SELECT COUNT(DISTINCT post_id) as cnt FROM {$prefix}postmeta WHERE meta_key NOT LIKE '\_%' AND meta_key NOT IN ('_edit_lock','_edit_last','_wp_page_template','_thumbnail_id','_wp_attached_file','_wp_attachment_metadata','_elementor_data','_elementor_edit_mode','_elementor_template_type','_elementor_version','_elementor_pro_version','_elementor_page_settings','_elementor_controls_usage') AND meta_value != ''")->fetch_assoc()['cnt'];
            echo "Posts with custom meta (non-WP/Elementor): $acf_usage\n";

            // ============================================================
            // PART 2: Find all images referenced by posts/pages
            // ============================================================
            echo "\n=== REFERENCED IMAGES ===\n";

            // Get all attachment URLs (featured images + content images)
            $referenced_files = [];

            // 1. Featured images (thumbnails)
            $res = $db->query("
                SELECT pm.meta_value as attachment_id, p.post_title
                FROM {$prefix}postmeta pm
                JOIN {$prefix}posts p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_thumbnail_id'
                AND p.post_status = 'publish'
            ");
            $featured_ids = [];
            while ($row = $res->fetch_assoc()) {
                $featured_ids[] = $row['attachment_id'];
            }
            echo "Featured images used: " . count($featured_ids) . "\n";

            // Get file paths for featured images
            if ($featured_ids) {
                $ids = implode(',', array_map('intval', $featured_ids));
                $res = $db->query("
                    SELECT pm.meta_value as file_path
                    FROM {$prefix}postmeta pm
                    WHERE pm.meta_key = '_wp_attached_file'
                    AND pm.post_id IN ($ids)
                ");
                while ($row = $res->fetch_assoc()) {
                    $referenced_files[] = $row['file_path'];
                }
            }

            // 2. Images in post/page content
            $res = $db->query("
                SELECT post_content FROM {$prefix}posts
                WHERE post_status = 'publish'
                AND post_type IN ('post', 'page')
                AND post_content LIKE '%wp-content/uploads/%'
            ");
            while ($row = $res->fetch_assoc()) {
                preg_match_all('#wp-content/uploads/([^\s"\'\)]+)#', $row['post_content'], $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $path) {
                        $referenced_files[] = $path;
                    }
                }
            }

            // Deduplicate and extract base files (without size suffixes like -300x200)
            $base_files = [];
            foreach (array_unique($referenced_files) as $file) {
                $base_files[] = $file;
                // Also keep the original (without -WxH suffix)
                $base = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $file);
                if ($base !== $file) {
                    $base_files[] = $base;
                }
            }
            $base_files = array_unique($base_files);

            echo "Unique referenced upload paths: " . count($base_files) . "\n";
            echo "\nReferenced files:\n";
            sort($base_files);
            foreach ($base_files as $f) {
                echo "  $f\n";
            }

            // ============================================================
            // PART 3: Find orphaned uploads
            // ============================================================
            echo "\n=== ORPHANED UPLOADS ===\n";
            $uploads_dir = $root . '/wp-content/uploads';
            $orphaned_size = 0;
            $orphaned_count = 0;
            $kept_size = 0;
            $kept_count = 0;

            // Scan year/month dirs only (skip bears-backup, elementor, etc.)
            $year_dirs = [];
            foreach (scandir($uploads_dir) as $item) {
                if (preg_match('/^20\d{2}$/', $item) && is_dir("$uploads_dir/$item")) {
                    $year_dirs[] = $item;
                }
            }

            foreach ($year_dirs as $year) {
                $year_path = "$uploads_dir/$year";
                foreach (scandir($year_path) as $month) {
                    if ($month === '.' || $month === '..') continue;
                    $month_path = "$year_path/$month";
                    if (!is_dir($month_path)) continue;

                    foreach (scandir($month_path) as $file) {
                        if ($file === '.' || $file === '..') continue;
                        $file_path = "$month_path/$file";
                        if (!is_file($file_path)) continue;

                        $rel_path = "$year/$month/$file";
                        // Check if this file or its base version is referenced
                        $is_referenced = false;
                        $base_rel = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $rel_path);
                        foreach ($base_files as $ref) {
                            if ($rel_path === $ref || $base_rel === $ref || strpos($ref, $base_rel) !== false || strpos($base_rel, $ref) !== false) {
                                $is_referenced = true;
                                break;
                            }
                        }

                        $fsize = filesize($file_path);
                        if ($is_referenced) {
                            $kept_count++;
                            $kept_size += $fsize;
                        } else {
                            $orphaned_count++;
                            $orphaned_size += $fsize;
                        }
                    }
                }
            }

            echo "Referenced files kept: $kept_count (" . fmt($kept_size) . ")\n";
            echo "Orphaned files: $orphaned_count (" . fmt($orphaned_size) . ")\n";

            $db->close();
        }
    }
} else {
    echo "wp-config.php not found\n";
}

// ============================================================
// PART 4: Safe deletion targets
// ============================================================
echo "\n=== SAFE TO DELETE ===\n";
$targets = [
    'wp-content/uploads/bears-backup'      => 'Old site backups',
    'wp-content/uploads/revslider'          => 'RevSlider plugin data',
    'wp-content/uploads/elementor'          => 'Elementor CSS cache',
    'wp-content/uploads/wc-logs'            => 'WooCommerce logs',
    'wp-content/uploads/woocommerce_uploads'=> 'WooCommerce uploads',
    'wp-content/uploads/sb-instagram-feed-images' => 'Instagram feed cache',
    'wp-content/plugins/bears-backup'       => 'Backup plugin (unused)',
    '.tmb'                                  => 'File manager thumbnails',
];

$total_deletable = 0;
foreach ($targets as $path => $reason) {
    $full = "$root/$path";
    if (is_dir($full)) {
        $size = dir_size($full);
        echo sprintf("  %-45s %10s  (%s)\n", $path . '/', fmt($size), $reason);
        $total_deletable += $size;
    } elseif (is_file($full)) {
        $size = filesize($full);
        echo sprintf("  %-45s %10s  (%s)\n", $path, fmt($size), $reason);
        $total_deletable += $size;
    }
}

// Languages - keep only fr_FR
$lang_dir = "$root/wp-content/languages";
if (is_dir($lang_dir)) {
    $lang_deletable = 0;
    foreach (scandir($lang_dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = "$lang_dir/$item";
        if (is_dir($full) && $item !== 'plugins' && $item !== 'themes') {
            $lang_deletable += dir_size($full);
        } elseif (is_file($full) && strpos($item, 'fr_FR') === false && strpos($item, 'fr-FR') === false) {
            $lang_deletable += filesize($full);
        }
    }
    echo sprintf("  %-45s %10s  (%s)\n", 'wp-content/languages/ (non-fr)', fmt($lang_deletable), 'Keep only French');
    $total_deletable += $lang_deletable;
}

echo "\nTotal safely deletable: " . fmt($total_deletable) . "\n";
echo "Orphaned uploads (additional): " . fmt($orphaned_size) . "\n";
echo "GRAND TOTAL recoverable: " . fmt($total_deletable + $orphaned_size) . "\n";

// ============================================================
// PART 5: Delete if requested
// ============================================================
if ($action === 'delete') {
    echo "\n=== DELETING ===\n";
    $freed = 0;

    foreach ($targets as $path => $reason) {
        $full = "$root/$path";
        if (is_dir($full)) {
            $size = dir_size($full);
            if (rmdir_recursive($full)) {
                echo "  DELETED: $path/ (" . fmt($size) . ")\n";
                $freed += $size;
            } else {
                echo "  FAILED: $path/\n";
            }
        }
    }

    // Delete non-French language files
    if (is_dir($lang_dir)) {
        foreach (scandir($lang_dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = "$lang_dir/$item";
            if (is_dir($full) && $item !== 'plugins' && $item !== 'themes') {
                $size = dir_size($full);
                if (rmdir_recursive($full)) {
                    echo "  DELETED: wp-content/languages/$item/ (" . fmt($size) . ")\n";
                    $freed += $size;
                }
            } elseif (is_file($full) && strpos($item, 'fr_FR') === false && strpos($item, 'fr-FR') === false) {
                $size = filesize($full);
                if (unlink($full)) {
                    echo "  DELETED: wp-content/languages/$item (" . fmt($size) . ")\n";
                    $freed += $size;
                }
            }
        }
    }

    // Delete orphaned upload files
    echo "\n  --- Orphaned uploads ---\n";
    // Re-connect DB to get referenced files list
    $config_content = file_get_contents($wp_config);
    preg_match("/define\s*\(\s*'DB_NAME'\s*,\s*'([^']+)'/", $config_content, $m); $db_name = $m[1] ?? '';
    preg_match("/define\s*\(\s*'DB_USER'\s*,\s*'([^']+)'/", $config_content, $m); $db_user = $m[1] ?? '';
    preg_match("/define\s*\(\s*'DB_PASSWORD'\s*,\s*'([^']+)'/", $config_content, $m); $db_pass = $m[1] ?? '';
    preg_match("/define\s*\(\s*'DB_HOST'\s*,\s*'([^']+)'/", $config_content, $m); $db_host = $m[1] ?? 'localhost';
    preg_match("/table_prefix\s*=\s*'([^']+)'/", $config_content, $m); $prefix = $m[1] ?? 'wp_';

    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if (!$db->connect_error) {
        $db->set_charset('utf8mb4');

        // Rebuild referenced files list
        $referenced_files = [];
        $res = $db->query("SELECT pm.meta_value as attachment_id FROM {$prefix}postmeta pm JOIN {$prefix}posts p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND p.post_status = 'publish'");
        $featured_ids = [];
        while ($row = $res->fetch_assoc()) { $featured_ids[] = $row['attachment_id']; }
        if ($featured_ids) {
            $ids = implode(',', array_map('intval', $featured_ids));
            $res = $db->query("SELECT pm.meta_value as file_path FROM {$prefix}postmeta pm WHERE pm.meta_key = '_wp_attached_file' AND pm.post_id IN ($ids)");
            while ($row = $res->fetch_assoc()) { $referenced_files[] = $row['file_path']; }
        }
        $res = $db->query("SELECT post_content FROM {$prefix}posts WHERE post_status = 'publish' AND post_type IN ('post', 'page') AND post_content LIKE '%wp-content/uploads/%'");
        while ($row = $res->fetch_assoc()) {
            preg_match_all('#wp-content/uploads/([^\s"\'\)]+)#', $row['post_content'], $matches);
            if (!empty($matches[1])) { foreach ($matches[1] as $path) { $referenced_files[] = $path; } }
        }
        $base_files = [];
        foreach (array_unique($referenced_files) as $file) {
            $base_files[] = $file;
            $base = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $file);
            if ($base !== $file) $base_files[] = $base;
        }
        $base_files = array_unique($base_files);
        $db->close();

        foreach ($year_dirs as $year) {
            $year_path = "$uploads_dir/$year";
            foreach (scandir($year_path) as $month) {
                if ($month === '.' || $month === '..') continue;
                $month_path = "$year_path/$month";
                if (!is_dir($month_path)) continue;
                foreach (scandir($month_path) as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $file_path = "$month_path/$file";
                    if (!is_file($file_path)) continue;
                    $rel_path = "$year/$month/$file";
                    $is_referenced = false;
                    $base_rel = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $rel_path);
                    foreach ($base_files as $ref) {
                        if ($rel_path === $ref || $base_rel === $ref || strpos($ref, $base_rel) !== false || strpos($base_rel, $ref) !== false) {
                            $is_referenced = true;
                            break;
                        }
                    }
                    if (!$is_referenced) {
                        $fsize = filesize($file_path);
                        if (unlink($file_path)) {
                            $freed += $fsize;
                        }
                    }
                }
            }
        }
    }

    echo "\nTotal freed: " . fmt($freed) . "\n";
}

echo "\n--- To delete, add &action=delete to the URL ---\n";
