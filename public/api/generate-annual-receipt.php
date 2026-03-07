<?php
/**
 * Generate an ANNUAL cumulative tax receipt (recu fiscal annuel cumule) PDF.
 *
 * A donor who made multiple donations during the year gets ONE receipt
 * listing all donations in a table.
 *
 * Uses tFPDF via generate-receipt.php (reuses nombre_en_lettres() + tFPDF).
 */

require_once __DIR__ . '/generate-receipt.php';

/**
 * Get the next annual receipt number for a given year.
 * Uses key "annual_{year}" in receipts/counter.json.
 * Returns format: ADESZ-ANN-{YEAR}-{NNN}
 */
function get_next_annual_receipt_number(int $year): string {
    $counter_file = __DIR__ . '/receipts/counter.json';

    $dir = dirname($counter_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fp = fopen($counter_file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        error_log('Cannot lock receipt counter file');
        return 'ADESZ-ANN-' . $year . '-ERR';
    }

    $content = stream_get_contents($fp);
    $data = $content ? (json_decode($content, true) ?: []) : [];

    $key = 'annual_' . $year;
    $current = ($data[$key] ?? 0) + 1;
    $data[$key] = $current;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return sprintf('ADESZ-ANN-%d-%03d', $year, $current);
}

/**
 * Generate an annual cumulative tax receipt PDF.
 *
 * @param array $donor Keys: email, prenom, nom, adresse, cp, commune, total (float), donations (array)
 *                     Each donation: id, amount, date_don, type, mode_paiement
 * @param int   $year  Fiscal year
 * @return array{path: string, filename: string, number: string, content: string}|false
 */
function generate_annual_receipt_pdf(array $donor, int $year) {
    $receipt_number = get_next_annual_receipt_number($year);

    // Determine donation types for smart title / nature
    $types = array_unique(array_column($donor['donations'], 'type'));
    $has_don = in_array('don', $types);
    $has_adhesion = in_array('adhesion', $types);
    $has_combo = in_array('combo', $types);

    if (($has_don || $has_combo) && $has_adhesion) {
        $title_suffix = "DONS ET COTISATIONS $year";
        $nature = "Don et cotisation num\xc3\xa9raires";
    } elseif ($has_adhesion && !$has_don && !$has_combo) {
        $title_suffix = "COTISATIONS $year";
        $nature = "Cotisation num\xc3\xa9raire";
    } else {
        $title_suffix = "DONS $year";
        $nature = "Don num\xc3\xa9raire";
    }

    $total = (float) ($donor['total'] ?? 0);

    $pdf = new tFPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);

    // Add Poppins font (TTF, UTF-8)
    $pdf->AddFont('Poppins', '', 'Poppins-Regular.ttf', true);
    $pdf->AddFont('Poppins', 'B', 'Poppins-Bold.ttf', true);
    $pdf->AddFont('Poppins', 'I', 'Poppins-Italic.ttf', true);
    $pdf->AddFont('Poppins', 'BI', 'Poppins-BoldItalic.ttf', true);

    $pdf->AddPage();

    // --- Top decorative bar (green + yellow accent) ---
    $pdf->SetFillColor(45, 122, 58);  // #2D7A3A
    $pdf->Rect(0, 0, 210, 8, 'F');
    $pdf->SetFillColor(245, 197, 24); // #F5C518
    $pdf->Rect(0, 8, 210, 2, 'F');

    // --- Logo ---
    $logo_path = __DIR__ . '/../images/logo-adesz-2026.jpg';
    if (file_exists($logo_path)) {
        $pdf->Image($logo_path, 15, 15, 30);
    }

    // --- Header: ADESZ name ---
    $pdf->SetY(18);
    $pdf->SetX(50);
    $pdf->SetFont('Poppins', 'B', 22);
    $pdf->SetTextColor(27, 94, 39); // #1B5E27
    $pdf->Cell(0, 10, 'ADESZ', 0, 1, 'L');
    $pdf->SetX(50);
    $pdf->SetFont('Poppins', '', 7.5);
    $pdf->SetTextColor(45, 52, 54);  // #2D3436
    $pdf->Cell(0, 4, "Association pour le D\xc3\xa9veloppement, l'Entraide et la Solidarit\xc3\xa9", 0, 1, 'L');
    $pdf->SetX(50);
    $pdf->Cell(0, 4, "du Village de Zafaya au Tchad et de la R\xc3\xa9gion", 0, 1, 'L');

    // --- Receipt number (top-right) ---
    $pdf->SetY(18);
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->SetTextColor(45, 122, 58);
    $pdf->Cell(0, 5, $receipt_number, 0, 1, 'R');
    $pdf->SetFont('Poppins', '', 7);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, "Ann\xc3\xa9e fiscale : " . $year, 0, 1, 'R');

    // --- Title banner ---
    $pdf->Ln(15);
    $pdf->SetFillColor(45, 122, 58);
    $pdf->Rect(15, $pdf->GetY(), 180, 14, 'F');
    $pdf->SetFont('Poppins', 'B', 15);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 14, "RECU FISCAL ANNUEL \xe2\x80\x94 " . $title_suffix, 0, 1, 'C');

    // --- Cerfa certification mention ---
    $pdf->SetFont('Poppins', 'I', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Ln(2);
    $pdf->SetX(20);
    $pdf->MultiCell(170, 4, "L'organisme certifie que les dons et versements qu'il re\xc3\xa7oit ouvrent droit \xc3\xa0 la r\xc3\xa9duction d'imp\xc3\xb4t pr\xc3\xa9vue aux articles 200, 238 bis et 978 du Code G\xc3\xa9n\xc3\xa9ral des Imp\xc3\xb4ts.", 0, 'C');

    // --- Beneficiary box ---
    $pdf->Ln(3);
    $y = $pdf->GetY();
    $pdf->SetFillColor(248, 247, 244);
    $pdf->SetDrawColor(45, 122, 58);
    $pdf->Rect(15, $y, 180, 46, 'DF');

    $pdf->SetXY(20, $y + 3);
    $pdf->SetFont('Poppins', 'B', 10);
    $pdf->SetTextColor(45, 122, 58);
    $pdf->Cell(0, 6, "ORGANISME B\xc3\x89N\xc3\x89FICIAIRE", 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->SetTextColor(45, 52, 54);
    $pdf->Cell(0, 5, 'ADESZ', 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('Poppins', '', 7.5);
    $pdf->Cell(0, 4, "Association loi 1901, d\xc3\xa9clar\xc3\xa9" . 'e le 04 novembre 2022', 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('Poppins', 'B', 7.5);
    $pdf->Cell(0, 4, "Association d'int\xc3\xa9r\xc3\xaat g\xc3\xa9n\xc3\xa9ral", 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('Poppins', '', 7.5);
    $pdf->Cell(0, 4, "Enregistr\xc3\xa9" . "e \xc3\xa0 la Pr\xc3\xa9fecture des Alpes-Maritimes sous le n\xc2\xb0 W061016106", 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, "Sans but lucratif, \xc3\xa0 caract\xc3\xa8re social", 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, "491 Boulevard Pierre Delmas, 06600 Antibes", 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, "Objet : D\xc3\xa9veloppement, entraide et solidarit\xc3\xa9 - Zafaya, Tchad et r\xc3\xa9gion", 0, 1, 'L');

    // --- Donor box ---
    $pdf->Ln(5);
    $y = $pdf->GetY();
    $pdf->SetFillColor(248, 247, 244);
    $pdf->SetDrawColor(45, 122, 58);
    $pdf->Rect(15, $y, 180, 28, 'DF');

    $pdf->SetXY(20, $y + 3);
    $pdf->SetFont('Poppins', 'B', 10);
    $pdf->SetTextColor(45, 122, 58);
    $pdf->Cell(0, 6, 'DONATEUR', 0, 1, 'L');

    $donor_name = trim(($donor['prenom'] ?? '') . ' ' . ($donor['nom'] ?? ''));
    $donor_address = trim($donor['adresse'] ?? '');
    $donor_city = trim(($donor['cp'] ?? '') . ' ' . ($donor['commune'] ?? ''));

    $pdf->SetX(20);
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->SetTextColor(45, 52, 54);
    $pdf->Cell(0, 5, $donor_name ?: "Non renseign\xc3\xa9", 0, 1, 'L');
    if ($donor_address) {
        $pdf->SetX(20);
        $pdf->SetFont('Poppins', '', 8);
        $pdf->Cell(0, 4, $donor_address, 0, 1, 'L');
    }
    if (trim($donor_city)) {
        $pdf->SetX(20);
        $pdf->SetFont('Poppins', '', 8);
        $pdf->Cell(0, 4, $donor_city, 0, 1, 'L');
    }

    // --- Donation table ---
    $pdf->Ln(6);
    $table_x = 20; // centered: (210 - 170) / 2 = 20
    $col_date = 35;
    $col_type = 40;
    $col_mode = 55;
    $col_amount = 40;
    $row_height = 6;

    // Table header
    $y = $pdf->GetY();
    $pdf->SetFillColor(45, 122, 58);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Poppins', 'B', 8);

    $pdf->SetXY($table_x, $y);
    $pdf->Cell($col_date, $row_height, 'Date', 0, 0, 'C', true);
    $pdf->Cell($col_type, $row_height, 'Type', 0, 0, 'C', true);
    $pdf->Cell($col_mode, $row_height, 'Mode', 0, 0, 'C', true);
    $pdf->Cell($col_amount, $row_height, 'Montant (EUR)', 0, 1, 'C', true);

    // Table data rows
    $pdf->SetFont('Poppins', '', 8);
    $pdf->SetTextColor(45, 52, 54);

    foreach ($donor['donations'] as $i => $donation) {
        $y = $pdf->GetY();

        // Check page overflow — if less than 40mm left, add new page
        if ($y + $row_height > 260) {
            // Bottom bars before new page
            $pdf->SetFillColor(45, 122, 58);
            $pdf->Rect(0, 278, 210, 2, 'F');
            $pdf->SetFillColor(245, 197, 24);
            $pdf->Rect(0, 280, 210, 1, 'F');

            $pdf->AddPage();

            // Re-draw top bars on new page
            $pdf->SetFillColor(45, 122, 58);
            $pdf->Rect(0, 0, 210, 8, 'F');
            $pdf->SetFillColor(245, 197, 24);
            $pdf->Rect(0, 8, 210, 2, 'F');

            $pdf->SetY(15);

            // Re-draw table header
            $pdf->SetFillColor(45, 122, 58);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Poppins', 'B', 8);
            $pdf->SetX($table_x);
            $pdf->Cell($col_date, $row_height, 'Date', 0, 0, 'C', true);
            $pdf->Cell($col_type, $row_height, 'Type', 0, 0, 'C', true);
            $pdf->Cell($col_mode, $row_height, 'Mode', 0, 0, 'C', true);
            $pdf->Cell($col_amount, $row_height, 'Montant (EUR)', 0, 1, 'C', true);

            $pdf->SetFont('Poppins', '', 8);
            $pdf->SetTextColor(45, 52, 54);
        }

        // Alternating row background
        if ($i % 2 === 1) {
            $pdf->SetFillColor(248, 247, 244); // #F8F7F4
            $fill = true;
        } else {
            $pdf->SetFillColor(255, 255, 255);
            $fill = true;
        }

        // Format date dd/mm/yyyy
        $date_str = $donation['date_don'] ?? '';
        if (strlen($date_str) === 10 && strpos($date_str, '-') !== false) {
            // Convert YYYY-MM-DD to dd/mm/yyyy
            $date_str = date('d/m/Y', strtotime($date_str));
        }

        // Format type
        $type_str = $donation['type'] ?? 'don';
        if ($type_str === 'adhesion') {
            $type_display = "Adh\xc3\xa9sion";
        } elseif ($type_str === 'combo') {
            $type_display = 'Combo';
        } else {
            $type_display = 'Don';
        }

        // Mode de paiement
        $mode_str = $donation['mode_paiement'] ?? 'Carte bancaire (Stripe)';

        // Amount
        $amount_str = number_format((float) ($donation['amount'] ?? 0), 2, ',', ' ');

        $pdf->SetX($table_x);
        $pdf->Cell($col_date, $row_height, $date_str, 0, 0, 'C', $fill);
        $pdf->Cell($col_type, $row_height, $type_display, 0, 0, 'C', $fill);
        $pdf->Cell($col_mode, $row_height, $mode_str, 0, 0, 'C', $fill);
        $pdf->Cell($col_amount, $row_height, $amount_str, 0, 1, 'R', $fill);
    }

    // Total row
    $pdf->SetFillColor(45, 122, 58);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Poppins', 'B', 9);
    $total_str = number_format($total, 2, ',', ' ');

    $pdf->SetX($table_x);
    $pdf->Cell($col_date + $col_type + $col_mode, $row_height + 1, 'TOTAL', 0, 0, 'L', true);
    $pdf->Cell($col_amount, $row_height + 1, $total_str . ' EUR', 0, 1, 'R', true);

    // --- Amount in words ---
    $pdf->Ln(2);
    $pdf->SetFont('Poppins', 'I', 8);
    $pdf->SetTextColor(45, 52, 54);
    $amount_words = nombre_en_lettres($total);
    $pdf->Cell(0, 5, '(' . ucfirst($amount_words) . ')', 0, 1, 'C');

    // --- BOI mention + payment details ---
    $pdf->Ln(2);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetFont('Poppins', 'I', 7);
    $pdf->SetX(20);
    $pdf->Cell(0, 4, "(Bulletin officiel des imp\xc3\xb4ts n\xc2\xb0186 du 8 octobre 1999)", 0, 1, 'L');

    $pdf->Ln(2);
    $pdf->SetTextColor(45, 52, 54);
    $pdf->SetFont('Poppins', '', 9);
    $pdf->SetX(20);
    $pdf->Cell(45, 5, 'Nature du don :', 0, 0, 'L');
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->Cell(0, 5, $nature, 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Poppins', '', 9);
    $pdf->Cell(45, 5, "P\xc3\xa9riode :", 0, 0, 'L');
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->Cell(0, 5, "01/01/$year au 31/12/$year", 0, 1, 'L');

    // --- Tax deduction box (yellow) ---
    $pdf->Ln(4);
    $y = $pdf->GetY();
    $pdf->SetFillColor(255, 251, 235);
    $pdf->SetDrawColor(245, 197, 24);
    $pdf->Rect(15, $y, 180, 22, 'DF');

    $pdf->SetXY(20, $y + 3);
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->SetTextColor(45, 52, 54);
    $pdf->Cell(0, 5, 'AVANTAGE FISCAL', 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Poppins', '', 7.5);
    $deduction_66 = number_format($total * 0.66, 2, ',', ' ');
    $pdf->Cell(0, 4, "Particuliers : r\xc3\xa9duction d'imp\xc3\xb4t de 66% (art. 200 du CGI), soit " . $deduction_66 . " EUR", 0, 1, 'L');
    $pdf->SetX(20);
    $deduction_60 = number_format($total * 0.60, 2, ',', ' ');
    $pdf->Cell(0, 4, "Entreprises : r\xc3\xa9duction d'imp\xc3\xb4t de 60% (art. 238 bis du CGI), soit " . $deduction_60 . " EUR", 0, 1, 'L');

    // --- Signature ---
    $pdf->Ln(8);
    $pdf->SetFont('Poppins', '', 9);
    $pdf->SetTextColor(45, 52, 54);
    $pdf->Cell(0, 5, "Fait \xc3\xa0 Antibes, le " . date('d/m/Y'), 0, 1, 'R');
    $pdf->Ln(2);
    $pdf->SetFont('Poppins', 'B', 10);
    $pdf->Cell(0, 5, "Le Pr\xc3\xa9sident,", 0, 1, 'R');
    $pdf->SetFont('Poppins', 'I', 10);
    $pdf->Cell(0, 5, 'Abakar Mahamat', 0, 1, 'R');

    // --- Bottom decorative bars ---
    $pdf->SetFillColor(45, 122, 58);
    $pdf->Rect(0, 278, 210, 2, 'F');
    $pdf->SetFillColor(245, 197, 24);
    $pdf->Rect(0, 280, 210, 1, 'F');

    // --- Footer ---
    $pdf->SetY(282);
    $pdf->SetFont('Poppins', '', 6.5);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell(0, 3, "ADESZ | 491 Bd Pierre Delmas, 06600 Antibes | 06 63 07 66 12 | adeszafaya@gmail.com | www.adesz.fr", 0, 1, 'C');
    $pdf->Cell(0, 3, "Facebook : Association ADESZ Tchad", 0, 1, 'C');

    // --- Save PDF ---
    $receipts_dir = __DIR__ . '/receipts';
    if (!is_dir($receipts_dir)) {
        mkdir($receipts_dir, 0755, true);
    }
    $filename = $receipt_number . '.pdf';
    $filepath = $receipts_dir . '/' . $filename;
    $pdf->Output('F', $filepath);

    return [
        'path'     => $filepath,
        'filename' => $filename,
        'number'   => $receipt_number,
        'content'  => file_get_contents($filepath),
    ];
}
