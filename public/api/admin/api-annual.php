<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

// Parse JSON body for POST requests (JS sends Content-Type: application/json)
$_JSON = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_JSON = json_decode(file_get_contents('php://input'), true) ?: [];
}

$action = $_GET['action'] ?? $_JSON['action'] ?? $_POST['action'] ?? '';
$year = (int) ($_GET['year'] ?? $_JSON['year'] ?? $_POST['year'] ?? date('Y') - 1);

if ($year < 2020 || $year > (int) date('Y')) {
    http_response_code(400);
    echo json_encode(['error' => "Ann\xc3\xa9e invalide"]);
    exit;
}

switch ($action) {
    case 'preview':
        handle_preview($year);
        break;
    case 'preview_pdf':
        handle_preview_pdf($year);
        break;
    case 'test_send':
        handle_send($year, true);
        break;
    case 'send':
        handle_send($year, false);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action invalide']);
}

function handle_preview(int $year): void {
    header('Content-Type: application/json');
    $donors = get_annual_donations($year);

    $total = 0;
    $sans_email = 0;
    $summary = [];

    foreach ($donors as $d) {
        $total += $d['total'];
        if (empty($d['email'])) $sans_email++;
        $summary[] = [
            'prenom'    => $d['prenom'],
            'nom'       => $d['nom'],
            'email'     => $d['email'] ?? '',
            'nb_dons'   => count($d['donations']),
            'total'     => round($d['total'], 2),
        ];
    }

    echo json_encode([
        'year'        => $year,
        'nb_donors'   => count($donors),
        'nb_dons'     => array_sum(array_map(fn($d) => count($d['donations']), $donors)),
        'total'       => round($total, 2),
        'sans_email'  => $sans_email,
        'donors'      => $summary,
    ]);
}

function handle_preview_pdf(int $year): void {
    require_once __DIR__ . '/../generate-annual-receipt.php';

    $donors = get_annual_donations($year);
    if (empty($donors)) {
        http_response_code(404);
        echo 'Aucun don pour cette année';
        exit;
    }

    // Generate PDF for first donor as preview
    $result = generate_annual_receipt_pdf($donors[0], $year);
    if (!$result) {
        http_response_code(500);
        echo "Erreur de g\xc3\xa9n\xc3\xa9ration du PDF";
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $result['filename'] . '"');
    echo $result['content'];
}

function handle_send(int $year, bool $test_mode): void {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../generate-annual-receipt.php';
    global $brevo_api_key, $admin_email;

    $donors = get_annual_donations($year);
    if (empty($donors)) {
        echo json_encode(['error' => "Aucun don pour $year"]);
        return;
    }

    $sent = 0;
    $errors = [];
    $sans_email = [];

    // Test mode: only send one receipt (first donor) to admin email
    $donors_to_process = $test_mode ? [reset($donors)] : $donors;

    foreach ($donors_to_process as $donor) {
        // Skip donors without email (in real mode only)
        if (!$test_mode && empty($donor['email'])) {
            $sans_email[] = trim($donor['prenom'] . ' ' . $donor['nom']);
            continue;
        }

        $result = generate_annual_receipt_pdf($donor, $year);
        if (!$result) {
            $errors[] = trim($donor['prenom'] . ' ' . $donor['nom']) . ' : erreur PDF';
            continue;
        }

        // Mark donations with annual receipt number (skip in test mode)
        if (!$test_mode) {
            $donation_ids = array_map(fn($d) => $d['id'], $donor['donations']);
            set_annual_receipt_number($donation_ids, $result['number']);
        }

        // Send email
        $recipient_email = $test_mode ? $admin_email : $donor['email'];
        $donor_name = trim(($donor['prenom'] ?? '') . ' ' . ($donor['nom'] ?? ''));
        $amount_fmt = number_format($donor['total'], 2, ',', ' ');
        $deduction = number_format($donor['total'] * 0.66, 2, ',', ' ');

        $subject = $test_mode
            ? "[TEST] Re\xc3\xa7u fiscal annuel $year - " . $donor_name
            : "Votre re\xc3\xa7u fiscal annuel ADESZ $year - " . $result['number'];

        $html = '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#2D3436;margin:0;padding:0;">'
            . '<div style="background:#2D7A3A;padding:30px 20px;text-align:center;">'
            . '<h1 style="color:white;margin:0;font-size:28px;">ADESZ</h1>'
            . '<p style="color:#F5C518;margin:8px 0 0;font-size:16px;">Votre re' . "\xc3\xa7" . 'u fiscal annuel ' . $year . '</p>'
            . '</div>'
            . '<div style="padding:30px 40px;max-width:600px;margin:0 auto;">'
            . '<p style="font-size:16px;">Bonjour ' . htmlspecialchars($donor_name ?: 'cher donateur', ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p style="font-size:15px;line-height:1.6;">Veuillez trouver ci-joint votre re' . "\xc3\xa7" . 'u fiscal annuel <strong>'
            . $result['number'] . '</strong> pour l\'ann' . "\xc3\xa9" . 'e ' . $year . '.</p>'
            . '<p style="font-size:15px;line-height:1.6;">Total de vos dons : <strong>' . $amount_fmt . ' EUR</strong></p>'
            . '<div style="background:#FFFBEB;border-left:4px solid #F5C518;padding:15px 20px;margin:20px 0;border-radius:4px;">'
            . '<p style="margin:0;font-size:14px;"><strong>Avantage fiscal :</strong> vos dons vous ouvrent droit ' . "\xc3\xa0"
            . ' une r' . "\xc3\xa9" . 'duction d\'imp' . "\xc3\xb4" . 't de <strong>' . $deduction . ' EUR</strong> (66%, art. 200 du CGI).</p>'
            . '</div>'
            . '<p style="font-size:15px;line-height:1.6;">Merci pour votre g' . "\xc3\xa9" . 'n' . "\xc3\xa9" . 'rosit' . "\xc3\xa9"
            . ' et votre soutien continu.</p>'
            . '<p style="font-size:15px;margin-top:25px;">Cordialement,<br>'
            . '<strong>Abakar Mahamat</strong><br>'
            . '<span style="color:#666;">Pr' . "\xc3\xa9" . 'sident de l\'ADESZ</span></p>'
            . '</div>'
            . '<div style="background:#F8F7F4;padding:20px;text-align:center;font-size:12px;color:#888;">'
            . 'ADESZ | 491 Bd Pierre Delmas, 06600 Antibes | <a href="https://adesz.fr" style="color:#2D7A3A;">www.adesz.fr</a>'
            . '</div>'
            . '</body></html>';

        $email_body = [
            'sender'  => ['name' => 'ADESZ', 'email' => 'adeszafaya@gmail.com'],
            'to'      => [['email' => $recipient_email, 'name' => $donor_name ?: $recipient_email]],
            'subject' => $subject,
            'htmlContent' => $html,
            'attachment' => [[
                'content' => base64_encode($result['content']),
                'name'    => $result['filename'],
            ]],
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
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            $sent++;
        } else {
            $errors[] = ($donor_name ?: $recipient_email) . " : HTTP $http_code";
            error_log("Annual receipt email failed for $recipient_email (HTTP $http_code): $response");
        }
    }

    // Send confirmation email to admin
    if (!$test_mode) {
        send_admin_confirmation($year, $sent, $sans_email, $errors);
    }

    echo json_encode([
        'success'    => true,
        'sent'       => $sent,
        'errors'     => $errors,
        'sans_email' => $sans_email,
        'test_mode'  => $test_mode,
    ]);
}

function send_admin_confirmation(int $year, int $sent, array $sans_email, array $errors): void {
    global $admin_email;

    if (empty($admin_email) || $admin_email === 'admin@REPLACE_ME') return;

    $msg = "Re\xc3\xa7us fiscaux annuels $year envoy\xc3\xa9s.\n\n"
        . "Re\xc3\xa7us envoy\xc3\xa9s : $sent\n";

    if (!empty($sans_email)) {
        $msg .= "\nDonateurs sans email (" . count($sans_email) . ") — \xc3\xa0 traiter par courrier :\n";
        foreach ($sans_email as $name) {
            $msg .= "  - $name\n";
        }
    }

    if (!empty($errors)) {
        $msg .= "\nErreurs (" . count($errors) . ") :\n";
        foreach ($errors as $err) {
            $msg .= "  - $err\n";
        }
    }

    @mail($admin_email, "[ADESZ] Re\xc3\xa7us annuels $year - R\xc3\xa9sum\xc3\xa9", $msg,
        "From: noreply@adesz.fr\r\nContent-Type: text/plain; charset=UTF-8");
}
