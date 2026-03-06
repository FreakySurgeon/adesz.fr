<?php
/**
 * Generate a tax receipt (recu fiscal) PDF for ADESZ donations.
 *
 * Uses tFPDF (public/api/lib/tfpdf.php) — lightweight, UTF-8 + TTF support.
 * Returns array with PDF path/content, or false on failure.
 */

require_once __DIR__ . '/lib/tfpdf.php';

/**
 * Get the next receipt number for the current year.
 * Uses a simple JSON counter file with file locking.
 */
function get_next_receipt_number(): string {
    $year = date('Y');
    $counter_file = __DIR__ . '/receipts/counter.json';

    $dir = dirname($counter_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fp = fopen($counter_file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        error_log('Cannot lock receipt counter file');
        return 'ADESZ-' . $year . '-ERR';
    }

    $content = stream_get_contents($fp);
    $data = $content ? (json_decode($content, true) ?: []) : [];

    $current = ($data[$year] ?? 0) + 1;
    $data[$year] = $current;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return sprintf('ADESZ-%s-%03d', $year, $current);
}

/**
 * Generate a tax receipt PDF.
 *
 * @param array $data Keys: email, prenom, nom, adresse, cp, commune, amount, date, type
 * @return array{path: string, filename: string, number: string, content: string}|false
 */
function generate_receipt_pdf(array $data) {
    $receipt_number = get_next_receipt_number();
    $year = date('Y');

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
    $pdf->Cell(0, 4, 'Date : ' . ($data['date'] ?? date('d/m/Y')), 0, 1, 'R');

    // --- Title banner ---
    $pdf->Ln(15);
    $pdf->SetFillColor(45, 122, 58);
    $pdf->Rect(15, $pdf->GetY(), 180, 14, 'F');
    $pdf->SetFont('Poppins', 'B', 15);
    $pdf->SetTextColor(255, 255, 255);
    $type_upper = ($data['type'] ?? 'don') === 'adhesion' ? 'COTISATION' : 'DON';
    $pdf->Cell(0, 14, "RECU FISCAL - " . $type_upper . " " . $year, 0, 1, 'C');

    // --- CGI reference ---
    $pdf->SetFont('Poppins', '', 7.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, "Articles 200 et 238 bis du Code G\xc3\xa9n\xc3\xa9ral des Imp\xc3\xb4ts", 0, 1, 'C');

    // --- Beneficiary box ---
    $pdf->Ln(3);
    $y = $pdf->GetY();
    $pdf->SetFillColor(248, 247, 244);
    $pdf->SetDrawColor(45, 122, 58);
    $pdf->Rect(15, $y, 180, 42, 'DF');

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

    $donor_name = trim(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
    $donor_address = trim($data['adresse'] ?? '');
    $donor_city = trim(($data['cp'] ?? '') . ' ' . ($data['commune'] ?? ''));

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

    // --- Amount banner (green) ---
    $pdf->Ln(8);
    $y = $pdf->GetY();
    $pdf->SetFillColor(45, 122, 58);
    $pdf->Rect(15, $y, 180, 22, 'F');

    $pdf->SetXY(20, $y + 3);
    $pdf->SetFont('Poppins', '', 8.5);
    $pdf->SetTextColor(255, 255, 255);
    $type_label = ($data['type'] ?? 'don') === 'adhesion' ? 'cotisation' : 'don';
    $pdf->Cell(0, 5, "Le b\xc3\xa9n\xc3\xa9ficiaire reconna\xc3\xaet avoir re\xc3\xa7u \xc3\xa0 titre de " . $type_label . ",", 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, "consenti \xc3\xa0 titre gratuit et sans contrepartie directe ou indirecte", 0, 1, 'L');

    $pdf->SetXY(20, $y + 13);
    $pdf->SetFont('Poppins', 'B', 16);
    $amount_str = number_format($data['amount'] ?? 0, 2, ',', ' ');
    $pdf->Cell(0, 8, $amount_str . ' EUR', 0, 1, 'C');

    // --- BOI + Payment details ---
    $pdf->Ln(3);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetFont('Poppins', 'I', 7);
    $pdf->SetX(20);
    $pdf->Cell(0, 4, "(Bulletin officiel des imp\xc3\xb4ts n\xc2\xb0186 du 8 octobre 1999)", 0, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetTextColor(45, 52, 54);
    $pdf->SetFont('Poppins', '', 9);
    $pdf->SetX(20);
    $pdf->Cell(45, 5, 'Date du paiement :', 0, 0, 'L');
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->Cell(0, 5, $data['date'] ?? date('d/m/Y'), 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Poppins', '', 9);
    $pdf->Cell(45, 5, 'Mode de paiement :', 0, 0, 'L');
    $pdf->SetFont('Poppins', 'B', 9);
    $pdf->Cell(0, 5, 'Carte bancaire (Stripe)', 0, 1, 'L');

    // --- Tax deduction box (yellow) ---
    $pdf->Ln(5);
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
    $deduction_66 = number_format(($data['amount'] ?? 0) * 0.66, 2, ',', ' ');
    $pdf->Cell(0, 4, "Particuliers : r\xc3\xa9duction d'imp\xc3\xb4t de 66% (art. 200 du CGI), soit " . $deduction_66 . " EUR", 0, 1, 'L');
    $pdf->SetX(20);
    $deduction_60 = number_format(($data['amount'] ?? 0) * 0.60, 2, ',', ' ');
    $pdf->Cell(0, 4, "Entreprises : r\xc3\xa9duction d'imp\xc3\xb4t de 60% (art. 238 bis du CGI), soit " . $deduction_60 . " EUR", 0, 1, 'L');

    // --- Signature ---
    $pdf->Ln(10);
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
