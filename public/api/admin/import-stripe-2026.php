<?php
/**
 * Temporary web-accessible import script.
 * Access via: https://adesz.fr/api/admin/import-stripe-2026.php?key=ADMIN_KEY
 * DELETE AFTER USE.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: text/plain; charset=utf-8');

$output = [];
$output[] = "=== Import Stripe 2026 + Clean test data ===\n";

// Step 1: Clean test donations
$pdo = get_pdo();
$stmt = $pdo->query("SELECT id, prenom, nom, amount, date_don, receipt_number FROM donations WHERE nom = 'Dupont Testeur'");
$test_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($test_rows)) {
    $output[] = "No test donations found.";
} else {
    foreach ($test_rows as $row) {
        $output[] = "  Deleting: #{$row['id']} {$row['prenom']} {$row['nom']} - {$row['amount']}€ ({$row['date_don']})" . ($row['receipt_number'] ? " [receipt: {$row['receipt_number']}]" : "");
    }
    $ids = array_column($test_rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM donations WHERE id IN ($placeholders)")->execute($ids);
    $output[] = "  Deleted " . count($ids) . " test donation(s).";
}

// Also clean receipt_counters for test receipts
$output[] = "";

// Step 2: Import Stripe 2026
$output[] = "=== Importing Stripe 2026 ===";

$donations = [
    [
        'email' => 'a.mahamat@tzanck.org', 'prenom' => 'Abakar', 'nom' => 'Abakar Mahamat',
        'adresse' => '491 Bd Pierre Delmas', 'cp' => '06600', 'commune' => 'Antibes',
        'amount' => 25.00, 'date_don' => '2026-03-06', 'type' => 'don',
        'mode_paiement' => 'carte', 'source' => 'stripe',
        'stripe_payment_id' => 'ch_3T860v17sF96z2O41pZTa38e',
    ],
    [
        'email' => 'chauvet.t@gmail.com', 'prenom' => 'Thomas', 'nom' => 'Chauvet',
        'adresse' => 'Chemin du Vallon Monari, Villa Merciari', 'cp' => '06200', 'commune' => 'Nice',
        'amount' => 25.00, 'date_don' => '2026-03-06', 'type' => 'don',
        'mode_paiement' => 'carte', 'source' => 'stripe',
        'stripe_payment_id' => 'ch_3T81mb17sF96z2O41oEv5BCl',
    ],
];

$insert_sql = "INSERT INTO donations (email, prenom, nom, adresse, cp, commune, amount, date_don, type, mode_paiement, source, stripe_payment_id)
               VALUES (:email, :prenom, :nom, :adresse, :cp, :commune, :amount, :date_don, :type, :mode_paiement, :source, :stripe_payment_id)";
$check_sql = "SELECT id FROM donations WHERE stripe_payment_id = :spid";

$insert_stmt = $pdo->prepare($insert_sql);
$check_stmt = $pdo->prepare($check_sql);

$imported = 0;
$skipped = 0;

foreach ($donations as $d) {
    $check_stmt->execute([':spid' => $d['stripe_payment_id']]);
    if ($check_stmt->fetch()) {
        $output[] = "  SKIP (exists): {$d['email']} - {$d['amount']}€";
        $skipped++;
        continue;
    }
    $insert_stmt->execute([
        ':email' => $d['email'], ':prenom' => $d['prenom'], ':nom' => $d['nom'],
        ':adresse' => $d['adresse'], ':cp' => $d['cp'], ':commune' => $d['commune'],
        ':amount' => $d['amount'], ':date_don' => $d['date_don'], ':type' => $d['type'],
        ':mode_paiement' => $d['mode_paiement'], ':source' => $d['source'],
        ':stripe_payment_id' => $d['stripe_payment_id'],
    ]);
    $id = $pdo->lastInsertId();
    $output[] = "  Imported #{$id}: {$d['prenom']} {$d['nom']} - {$d['amount']}€";
    $imported++;
}

$output[] = "\nResult: $imported imported, $skipped skipped.";

// Step 3: Show current state
$output[] = "\n=== Current donations ===";
$stmt = $pdo->query("SELECT id, prenom, nom, email, amount, date_don, type, source, receipt_number FROM donations ORDER BY date_don DESC, id DESC");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $output[] = sprintf("#%-3s %-12s %-15s %-30s %7.2f€  %-12s %-6s %-8s %s",
        $r['id'], $r['prenom'] ?: '-', $r['nom'] ?: '-', $r['email'] ?: '-',
        $r['amount'], $r['date_don'], $r['type'], $r['source'], $r['receipt_number'] ?: '-');
}

echo implode("\n", $output);
