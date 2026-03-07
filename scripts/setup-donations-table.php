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
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
