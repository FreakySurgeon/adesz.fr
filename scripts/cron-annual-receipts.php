<?php
/**
 * Cron script — sends email notification to admin when annual receipts are ready.
 * Scheduled on OVH cron: January 2 at 8:00 AM.
 *
 * Usage: php scripts/cron-annual-receipts.php [admin_key]
 * Or via HTTP: /api/cron-annual-receipts.php?key=ADMIN_KEY
 */

require_once __DIR__ . '/../public/api/config.php';
require_once __DIR__ . '/../public/api/db.php';

// Auth: check admin key (CLI arg or GET param)
$key = $argv[1] ?? $_GET['key'] ?? '';
if ($key !== $admin_key || empty($admin_key) || $admin_key === 'ADMIN_KEY_REPLACE_ME') {
    http_response_code(403);
    echo "Unauthorized\n";
    exit(1);
}

$year = (int) date('Y') - 1; // Previous year
$donors = get_annual_donations($year);

if (empty($donors)) {
    echo "No donations found for $year\n";
    exit(0);
}

$total = array_sum(array_map(fn($d) => $d['total'], $donors));
$nb_dons = array_sum(array_map(fn($d) => count($d['donations']), $donors));
$sans_email = count(array_filter($donors, fn($d) => empty($d['email'])));

$subject = "[ADESZ] Re\xc3\xa7us fiscaux annuels $year pr\xc3\xaats";
$message = "Bonjour Abakar,\n\n"
    . "Les re\xc3\xa7us fiscaux annuels $year sont pr\xc3\xaats \xc3\xa0 \xc3\xaatre envoy\xc3\xa9s.\n\n"
    . "R\xc3\xa9sum\xc3\xa9 :\n"
    . "  - " . count($donors) . " donateurs\n"
    . "  - $nb_dons dons\n"
    . "  - " . number_format($total, 2, ',', ' ') . " EUR au total\n"
    . "  - $sans_email donateurs sans email\n\n"
    . "Connectez-vous \xc3\xa0 l'interface admin pour v\xc3\xa9rifier et envoyer :\n"
    . "https://adesz.fr/api/admin/\n\n"
    . "\xc3\x89tapes :\n"
    . "1. Cliquez sur l'onglet \"Re\xc3\xa7us annuels\"\n"
    . "2. S\xc3\xa9lectionnez l'ann\xc3\xa9e $year et cliquez \"Pr\xc3\xa9visualiser\"\n"
    . "3. V\xc3\xa9rifiez la liste des donateurs\n"
    . "4. Cliquez \"Envoi test\" pour recevoir un exemple\n"
    . "5. Si tout est correct, cliquez \"Envoyer les re\xc3\xa7us\"\n\n"
    . "Cordialement,\n"
    . "Le site adesz.fr";

$headers = "From: noreply@adesz.fr\r\n"
    . "Reply-To: noreply@adesz.fr\r\n"
    . "Content-Type: text/plain; charset=UTF-8";

if (@mail($admin_email, $subject, $message, $headers)) {
    echo "Notification sent to $admin_email for year $year\n";
} else {
    echo "Failed to send notification\n";
    exit(1);
}
