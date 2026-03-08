<?php
/**
 * Temporary script to create receipt_counters table + UNIQUE indexes.
 * Run via URL with admin key, then delete.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== $admin_key) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$output = [];

try {
    $db = get_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS receipt_counters (
            year_key VARCHAR(20) PRIMARY KEY,
            counter INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $output[] = "OK: receipt_counters table created (or already exists).";

    try {
        $db->exec("ALTER TABLE donations ADD UNIQUE INDEX uq_receipt_number (receipt_number)");
        $output[] = "OK: UNIQUE index on receipt_number added.";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            $output[] = "OK: UNIQUE index on receipt_number already exists.";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("ALTER TABLE donations ADD UNIQUE INDEX uq_annual_receipt_number (annual_receipt_number)");
        $output[] = "OK: UNIQUE index on annual_receipt_number added.";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            $output[] = "OK: UNIQUE index on annual_receipt_number already exists.";
        } else {
            throw $e;
        }
    }

} catch (Exception $e) {
    $output[] = "ERROR: " . $e->getMessage();
}

echo implode("\n", $output) . "\n";
echo "\nDone. DELETE THIS FILE after running.\n";
