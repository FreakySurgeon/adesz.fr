<?php
/**
 * One-time CSV import of historical donations into MySQL.
 *
 * Usage: php scripts/import-donations.php donations.csv [--dry-run]
 *
 * CSV format (semicolon-separated, UTF-8):
 *   prenom;nom;email;adresse;cp;commune;montant;date;type;mode_paiement
 *
 * - date format: DD/MM/YYYY or YYYY-MM-DD
 * - type: don, adhesion, combo
 * - mode_paiement: cheque, especes, virement, helloasso, carte
 * - email can be empty
 */

require_once __DIR__ . '/../public/api/db.php';

if ($argc < 2) {
    echo "Usage: php import-donations.php <file.csv> [--dry-run]\n";
    exit(1);
}

$file = $argv[1];
$dry_run = in_array('--dry-run', $argv);

if (!file_exists($file)) {
    echo "File not found: $file\n";
    exit(1);
}

$handle = fopen($file, 'r');
if (!$handle) {
    echo "Cannot open file: $file\n";
    exit(1);
}

// Read header
$header = fgetcsv($handle, 0, ';');
if (!$header) {
    echo "Empty file or invalid CSV\n";
    exit(1);
}

$header = array_map('trim', array_map('strtolower', $header));
echo "Columns: " . implode(', ', $header) . "\n";

$expected = ['prenom', 'nom', 'email', 'adresse', 'cp', 'commune', 'montant', 'date', 'type', 'mode_paiement'];
$missing = array_diff($expected, $header);
if (!empty($missing)) {
    echo "Missing columns: " . implode(', ', $missing) . "\n";
    echo "Expected: " . implode(';', $expected) . "\n";
    exit(1);
}

$col_map = array_flip($header);
$imported = 0;
$errors = 0;
$line = 1;

while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $line++;

    if (count($row) < count($expected)) {
        echo "Line $line: not enough columns, skipping\n";
        $errors++;
        continue;
    }

    $montant = str_replace([' ', ','], ['', '.'], trim($row[$col_map['montant']]));
    $montant = (float) $montant;
    if ($montant <= 0) {
        echo "Line $line: invalid amount '{$row[$col_map['montant']]}', skipping\n";
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
        echo "Line $line: invalid date '$date_raw', skipping\n";
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
        echo "Line $line: [DRY-RUN] {$data['prenom']} {$data['nom']} - {$montant} EUR ({$date})\n";
        $imported++;
        continue;
    }

    try {
        insert_donation($data);
        $imported++;
    } catch (Throwable $e) {
        echo "Line $line: ERROR - " . $e->getMessage() . "\n";
        $errors++;
    }
}

fclose($handle);

$mode_label = $dry_run ? ' (DRY-RUN)' : '';
echo "\nDone$mode_label: $imported imported, $errors errors\n";
