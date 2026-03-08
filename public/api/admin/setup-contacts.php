<?php
/**
 * Temporary: create contacts table.
 * Access: https://adesz.fr/api/admin/setup-contacts.php?key=ADMIN_KEY
 * DELETE AFTER USE.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

global $admin_key;
if (($_GET['key'] ?? '') !== $admin_key) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NULL,
            prenom VARCHAR(255) NOT NULL DEFAULT '',
            nom VARCHAR(255) NOT NULL DEFAULT '',
            adresse VARCHAR(500) NOT NULL DEFAULT '',
            cp VARCHAR(10) NOT NULL DEFAULT '',
            commune VARCHAR(255) NOT NULL DEFAULT '',
            telephone VARCHAR(50) NOT NULL DEFAULT '',
            type VARCHAR(50) NOT NULL DEFAULT '',
            source VARCHAR(50) NOT NULL DEFAULT 'brevo',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE INDEX uq_email (email),
            INDEX idx_nom (nom, prenom)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK: contacts table created (or already exists).\n";

    // Show table structure
    $stmt = $db->query("DESCRIBE contacts");
    echo "\nTable structure:\n";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "  {$col['Field']} — {$col['Type']} {$col['Key']}\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
