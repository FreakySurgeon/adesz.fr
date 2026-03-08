<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Search contacts table first (Brevo-synced), then donations as fallback
$results = search_contacts($q, 10);

// Also search donations for donors not in contacts table
$donation_donors = search_donors($q, 10);
$existing_emails = array_filter(array_column($results, 'email'));
foreach ($donation_donors as $d) {
    if (empty($d['email']) || !in_array($d['email'], $existing_emails, true)) {
        $results[] = $d;
    }
}

echo json_encode(array_slice($results, 0, 10));
