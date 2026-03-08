<?php
require_once __DIR__ . '/../public/api/db.php';

try {
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS donations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NULL,
            prenom VARCHAR(255) NOT NULL DEFAULT '',
            nom VARCHAR(255) NOT NULL DEFAULT '',
            adresse VARCHAR(500) NOT NULL DEFAULT '',
            cp VARCHAR(10) NOT NULL DEFAULT '',
            commune VARCHAR(255) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL,
            date_don DATE NOT NULL,
            type ENUM('don','adhesion','combo') NOT NULL DEFAULT 'don',
            mode_paiement VARCHAR(50) NOT NULL DEFAULT 'carte',
            source ENUM('stripe','manual','csv') NOT NULL DEFAULT 'stripe',
            stripe_payment_id VARCHAR(255) NULL,
            receipt_number VARCHAR(50) NULL,
            annual_receipt_number VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_year_donor (date_don, email, nom, prenom),
            INDEX idx_email (email)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
    echo "OK: donations table created (or already exists).\n";

    $db->exec("
        CREATE TABLE IF NOT EXISTS receipt_counters (
            year_key VARCHAR(20) PRIMARY KEY,
            counter INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK: receipt_counters table created (or already exists).\n";

    // Add UNIQUE indexes if they don't exist
    try {
        $db->exec("ALTER TABLE donations ADD UNIQUE INDEX uq_receipt_number (receipt_number)");
        echo "OK: UNIQUE index on receipt_number added.\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "OK: UNIQUE index on receipt_number already exists.\n";
        } else {
            throw $e;
        }
    }
    try {
        $db->exec("ALTER TABLE donations ADD UNIQUE INDEX uq_annual_receipt_number (annual_receipt_number)");
        echo "OK: UNIQUE index on annual_receipt_number added.\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "OK: UNIQUE index on annual_receipt_number already exists.\n";
        } else {
            throw $e;
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
