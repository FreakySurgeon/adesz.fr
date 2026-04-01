<?php
/**
 * HTTP endpoint for bulk CSV import of historical donations.
 *
 * POST /api/import-donations-csv.php
 * Header: X-Admin-Key: <ADMIN_KEY>
 * Body: multipart/form-data with field "csv" (file upload)
 *   OR: application/x-www-form-urlencoded with field "csv_data" (raw CSV string)
 *
 * Optional query params:
 *   ?dry_run=1   — Simulate import, no DB writes
 *
 * CSV format (semicolon-separated, UTF-8):
 *   prenom;nom;email;adresse;cp;commune;montant;date;type;mode_paiement
 *
 * Returns JSON:
 *   {"imported": N, "errors": N, "details": [...]}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// Auth: X-Admin-Key header
$provided_key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
if (!$admin_key || !hash_equals($admin_key, $provided_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$dry_run = !empty($_GET['dry_run']);

// Get CSV content
$csv_content = null;
if (!empty($_FILES['csv']['tmp_name'])) {
    $csv_content = file_get_contents($_FILES['csv']['tmp_name']);
} elseif (!empty($_POST['csv_data'])) {
    $csv_content = $_POST['csv_data'];
} else {
    $body = file_get_contents('php://input');
    if ($body) {
        $csv_content = $body;
    }
}

if (!$csv_content) {
    http_response_code(400);
    echo json_encode(['error' => 'No CSV data provided. Use multipart file "csv" or POST field "csv_data".']);
    exit;
}

// Parse CSV
$lines = explode("\n", str_replace("\r\n", "\n", $csv_content));
$imported = 0;
$errors = 0;
$details = [];

$header_line = array_shift($lines);
$header = array_map('trim', array_map('strtolower', str_getcsv($header_line, ';')));

$expected = ['prenom', 'nom', 'email', 'adresse', 'cp', 'commune', 'montant', 'date', 'type', 'mode_paiement'];
$missing = array_diff($expected, $header);
if (!empty($missing)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing columns: ' . implode(', ', $missing)]);
    exit;
}

$col_map = array_flip($header);
$line_num = 1;

foreach ($lines as $line_str) {
    $line_num++;
    $line_str = trim($line_str);
    if ($line_str === '') {
        continue;
    }

    $row = str_getcsv($line_str, ';');

    if (count($row) < count($expected)) {
        $details[] = "Line $line_num: not enough columns, skipping";
        $errors++;
        continue;
    }

    $montant = str_replace([' ', ','], ['', '.'], trim($row[$col_map['montant']]));
    $montant = (float) $montant;
    if ($montant <= 0) {
        $details[] = "Line $line_num: invalid amount, skipping";
        $errors++;
        continue;
    }

    // Parse date
    $date_raw = trim($row[$col_map['date']]);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date_raw, $m)) {
        $date = "$m[3]-$m[2]-$m[1]";
    } elseif (preg_match('#^\d{4}-\d{2}-\d{2}$#', $date_raw)) {
        $date = $date_raw;
    } else {
        $details[] = "Line $line_num: invalid date '$date_raw', skipping";
        $errors++;
        continue;
    }

    $type = strtolower(trim($row[$col_map['type']]));
    if (!in_array($type, ['don', 'adhesion', 'combo'])) {
        $type = 'don';
    }

    $mode = strtolower(trim($row[$col_map['mode_paiement']]));
    $email = trim($row[$col_map['email']]);

    $data = [
        'prenom'        => trim($row[$col_map['prenom']]),
        'nom'           => trim($row[$col_map['nom']]),
        'email'         => $email ?: null,
        'adresse'       => trim($row[$col_map['adresse']]),
        'cp'            => trim($row[$col_map['cp']]),
        'commune'       => trim($row[$col_map['commune']]),
        'amount'        => $montant,
        'date_don'      => $date,
        'type'          => $type,
        'mode_paiement' => $mode ?: 'cheque',
        'source'        => 'csv',
    ];

    if ($dry_run) {
        $details[] = "Line $line_num: [DRY-RUN] {$data['prenom']} {$data['nom']} - {$montant} EUR ({$date})";
        $imported++;
        continue;
    }

    try {
        insert_donation($data);
        $imported++;
    } catch (Throwable $e) {
        $details[] = "Line $line_num: ERROR - " . $e->getMessage();
        $errors++;
    }
}

$mode_label = $dry_run ? ' (DRY-RUN)' : '';
echo json_encode([
    'status'   => 'ok',
    'dry_run'  => $dry_run,
    'imported' => $imported,
    'errors'   => $errors,
    'message'  => "Done{$mode_label}: {$imported} imported, {$errors} errors",
    'details'  => array_slice($details, 0, 50), // cap to avoid huge responses
]);
