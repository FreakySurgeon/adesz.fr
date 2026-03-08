<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib/SimpleXLSXWriter.php';

$table = $_GET['table'] ?? '';
$year  = $_GET['year'] ?? '';

if (!in_array($table, ['donations', 'contacts'], true)) {
    http_response_code(400);
    echo 'Table invalide.';
    exit;
}

$db = get_db();

if ($table === 'donations') {
    $sql = "SELECT id, date_don, nom, prenom, email, adresse, cp, commune,
                   amount, type, mode_paiement, source, receipt_number, annual_receipt_number, created_at
            FROM donations";
    $params = [];
    if ($year && preg_match('/^\d{4}$/', $year)) {
        $sql .= " WHERE YEAR(date_don) = ?";
        $params[] = (int) $year;
    }
    $sql .= " ORDER BY date_don DESC, nom, prenom";

    $headers = ['ID', 'Date', 'Nom', 'Prénom', 'Email', 'Adresse', 'Code postal', 'Commune',
                'Montant', 'Type', 'Mode paiement', 'Source', 'N° reçu', 'N° reçu annuel', 'Créé le'];

    $sheetName = 'Dons' . ($year ? " {$year}" : '');
    $filename = 'adesz-dons' . ($year ? "-{$year}" : '') . '-' . date('Y-m-d') . '.xlsx';
} else {
    $sql = "SELECT id, email, prenom, nom, adresse, cp, commune, telephone, type, source, updated_at
            FROM contacts
            ORDER BY nom, prenom";
    $params = [];

    $headers = ['ID', 'Email', 'Prénom', 'Nom', 'Adresse', 'Code postal', 'Commune',
                'Téléphone', 'Type', 'Source', 'Mis à jour le'];

    $sheetName = 'Contacts';
    $filename = 'adesz-contacts-' . date('Y-m-d') . '.xlsx';
}

$stmt = $db->prepare($sql);
$stmt->execute($params);

$xlsx = new SimpleXLSXWriter($sheetName);
$xlsx->addRow($headers);

while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $xlsx->addRow($row);
}

$xlsx->download($filename);
