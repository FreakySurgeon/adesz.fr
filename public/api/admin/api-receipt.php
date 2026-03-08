<?php
/**
 * Receipt generation and sending for manual donations.
 *
 * GET  ?donation_id=X&action=download  — Download receipt PDF
 * POST {donation_id, action: "send"}   — Send receipt via Brevo email
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../generate-receipt.php';
require_once __DIR__ . '/../config.php';

/**
 * Get or generate a receipt for a donation.
 * Reuses existing receipt_number if present, otherwise generates a new one
 * and saves it to the database.
 *
 * @param array $donation Donation row from DB
 * @return array{path: string, filename: string, number: string, content: string}
 */
function get_or_generate_receipt(array $donation): array {
    // Convert date from Y-m-d to d/m/Y for the PDF
    $date_formatted = $donation['date_don']
        ? date('d/m/Y', strtotime($donation['date_don']))
        : date('d/m/Y');

    $data = [
        'email'   => $donation['email'] ?? '',
        'prenom'  => $donation['prenom'] ?? '',
        'nom'     => $donation['nom'] ?? '',
        'adresse' => $donation['adresse'] ?? '',
        'cp'      => $donation['cp'] ?? '',
        'commune' => $donation['commune'] ?? '',
        'amount'  => (float) ($donation['amount'] ?? 0),
        'date'    => $date_formatted,
        'type'    => $donation['type'] ?? 'don',
    ];

    // Reuse existing receipt number if donation already has one
    if (!empty($donation['receipt_number'])) {
        $data['receipt_number_override'] = $donation['receipt_number'];
    }

    $result = generate_receipt_pdf($data);

    if (!$result) {
        throw new RuntimeException('PDF generation failed');
    }

    // If first time generating (no existing receipt_number), save the new number
    if (empty($donation['receipt_number'])) {
        update_donation((int) $donation['id'], ['receipt_number' => $result['number']]);
    }

    return $result;
}

// --- Routing ---

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'download') {
    // GET: Download receipt PDF
    $donation_id = (int) ($_GET['donation_id'] ?? 0);
    if ($donation_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'donation_id requis']);
        exit;
    }

    $donation = get_donation_by_id($donation_id);
    if (!$donation) {
        http_response_code(404);
        echo json_encode(['error' => 'Donation introuvable']);
        exit;
    }

    try {
        $result = get_or_generate_receipt($donation);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur génération PDF: ' . $e->getMessage()]);
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
    header('Content-Length: ' . strlen($result['content']));
    echo $result['content'];
    exit;
}

if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $donation_id = (int) ($input['donation_id'] ?? 0);

    if ($action !== 'send') {
        http_response_code(400);
        echo json_encode(['error' => 'Action invalide']);
        exit;
    }

    if ($donation_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'donation_id requis']);
        exit;
    }

    $donation = get_donation_by_id($donation_id);
    if (!$donation) {
        http_response_code(404);
        echo json_encode(['error' => 'Donation introuvable']);
        exit;
    }

    $email = trim($donation['email'] ?? '');
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Pas d\'adresse email pour ce donateur']);
        exit;
    }

    try {
        $result = get_or_generate_receipt($donation);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur génération PDF: ' . $e->getMessage()]);
        exit;
    }

    // --- Send via Brevo transactional email ---
    global $brevo_api_key;

    $donor_name = trim(($donation['prenom'] ?? '') . ' ' . ($donation['nom'] ?? ''));
    $amount_fmt = number_format((float) $donation['amount'], 2, ',', ' ');
    $deduction_66 = number_format((float) $donation['amount'] * 0.66, 2, ',', ' ');
    $deduction_60 = number_format((float) $donation['amount'] * 0.60, 2, ',', ' ');

    $email_body = [
        'sender'  => ['name' => 'ADESZ', 'email' => 'adeszafaya@gmail.com'],
        'to'      => [['email' => $email, 'name' => $donor_name ?: $email]],
        'subject' => 'Votre reçu fiscal ADESZ - ' . $result['number'],
        'htmlContent' => '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#2D3436;margin:0;padding:0;">'
            . '<div style="background:#2D7A3A;padding:30px 20px;text-align:center;">'
            . '<h1 style="color:white;margin:0;font-size:28px;">ADESZ</h1>'
            . '<p style="color:#F5C518;margin:8px 0 0;font-size:16px;">Merci pour votre générosité !</p>'
            . '</div>'
            . '<div style="padding:30px 40px;max-width:600px;margin:0 auto;">'
            . '<p style="font-size:16px;">Bonjour ' . htmlspecialchars($donor_name ?: 'cher donateur', ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p style="font-size:15px;line-height:1.6;">Nous vous remercions chaleureusement pour votre don de <strong>'
            . $amount_fmt . ' EUR</strong> en faveur de l\'ADESZ.</p>'
            . '<p style="font-size:15px;line-height:1.6;">Vous trouverez en pièce jointe votre reçu fiscal <strong>'
            . $result['number'] . '</strong>.</p>'
            . '<div style="background:#FFFBEB;border-left:4px solid #F5C518;padding:15px 20px;margin:20px 0;border-radius:4px;">'
            . '<p style="margin:0 0 8px;font-size:14px;"><strong>Avantage fiscal :</strong></p>'
            . '<p style="margin:0 0 4px;font-size:14px;">Particuliers : réduction d\'impôt de <strong>' . $deduction_66 . ' EUR</strong> (66% du montant, art. 200 du CGI)</p>'
            . '<p style="margin:0;font-size:14px;">Entreprises : réduction d\'impôt de <strong>' . $deduction_60 . ' EUR</strong> (60% du montant, art. 238 bis du CGI)</p>'
            . '</div>'
            . '<p style="font-size:15px;line-height:1.6;">Votre soutien nous aide à poursuivre nos actions pour le '
            . 'développement de Zafaya au Tchad.</p>'
            . '<p style="font-size:15px;margin-top:25px;">Cordialement,<br>'
            . '<strong>Abakar Mahamat</strong><br>'
            . '<span style="color:#666;">Président de l\'ADESZ</span></p>'
            . '</div>'
            . '<div style="background:#F8F7F4;padding:20px;text-align:center;font-size:12px;color:#888;">'
            . 'ADESZ | 491 Bd Pierre Delmas, 06600 Antibes | <a href="https://adesz.fr" style="color:#2D7A3A;">www.adesz.fr</a>'
            . '</div>'
            . '</body></html>',
        'attachment' => [
            [
                'content' => base64_encode($result['content']),
                'name'    => $result['filename'],
            ],
        ],
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $brevo_api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($email_body),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur réseau: ' . $curl_error]);
        exit;
    }

    if ($http_code >= 200 && $http_code < 300) {
        error_log('Admin receipt sent: ' . $result['number'] . ' to ' . $email);
        echo json_encode([
            'success'        => true,
            'receipt_number' => $result['number'],
            'email'          => $email,
        ]);
    } else {
        error_log('Admin receipt email failed (HTTP ' . $http_code . '): ' . $response);
        http_response_code(502);
        echo json_encode([
            'error'     => 'Échec envoi email (HTTP ' . $http_code . ')',
            'details'   => json_decode($response, true),
        ]);
    }
    exit;
}

// Unsupported method
http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Méthode non supportée']);
