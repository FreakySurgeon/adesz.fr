<?php
/**
 * Stripe Webhook → Brevo sync for ADESZ
 *
 * Listens for:
 *   - checkout.session.completed (initial payments)
 *   - invoice.paid (subscription renewals)
 *
 * Extracts member/donor data from Stripe and upserts contacts in Brevo.
 */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Only POST allowed
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

// ---------------------------------------------------------------------------
// Read and verify Stripe signature
// ---------------------------------------------------------------------------
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!$payload || !$sig_header) {
    http_response_code(400);
    echo 'Missing payload or signature';
    exit;
}

/**
 * Verify Stripe webhook signature (HMAC SHA256).
 *
 * @param string $payload   Raw request body
 * @param string $sig_header Value of Stripe-Signature header
 * @param string $secret    Webhook signing secret (whsec_...)
 * @param int    $tolerance Max age in seconds (default 300 = 5 min)
 * @return bool
 */
function verify_stripe_signature(string $payload, string $sig_header, string $secret, int $tolerance = 300): bool {
    // Parse the header: t=timestamp,v1=signature[,v1=signature...]
    $parts = [];
    foreach (explode(',', $sig_header) as $item) {
        $kv = explode('=', trim($item), 2);
        if (count($kv) === 2) {
            $parts[trim($kv[0])][] = trim($kv[1]);
        }
    }

    $timestamp = $parts['t'][0] ?? null;
    $signatures = $parts['v1'] ?? [];

    if (!$timestamp || empty($signatures)) {
        error_log("Stripe Signature DEBUG: Missing timestamp or signatures in header");
        return false;
    }

    // Check timestamp tolerance (anti-replay)
    $diff = time() - (int)$timestamp;
    if (abs($diff) > $tolerance) {
        error_log("Stripe Signature DEBUG: Timestamp too old or too far in future (diff=$diff)");
        return false;
    }

    // Compute expected signature
    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, trim($secret));

    // Compare against all v1 signatures
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    // DEBUG: Log the values for debugging
    error_log("Stripe Signature DEBUG: time_diff=$diff, expected=$expected");
    foreach ($signatures as $s) {
        error_log("Stripe Signature DEBUG: got=$s");
    }

    return false;
}

if (!verify_stripe_signature($payload, $sig_header, $stripe_webhook_secret)) {
    http_response_code(400);
    error_log('Stripe webhook: invalid signature for secret starting with ' . substr($stripe_webhook_secret, 0, 8));
    echo 'Invalid signature';
    exit;
}

// ---------------------------------------------------------------------------
// Parse event
// ---------------------------------------------------------------------------
$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
    http_response_code(400);
    echo 'Invalid event payload';
    exit;
}

$event_type = $event['type'];

// ---------------------------------------------------------------------------
// Route event
// ---------------------------------------------------------------------------
switch ($event_type) {
    case 'checkout.session.completed':
        handle_checkout_completed($event['data']['object']);
        break;

    case 'invoice.paid':
        handle_invoice_paid($event['data']['object']);
        break;

    default:
        // Ignore unhandled events — return 200 so Stripe doesn't retry
        break;
}

// Always return 200 to Stripe
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['status' => 'ok']);
exit;

// ===========================================================================
// Event handlers
// ===========================================================================

/**
 * Handle checkout.session.completed — initial payment (one-time or first subscription).
 */
function handle_checkout_completed(array $session): void {
    $email = $session['customer_email']
        ?? $session['customer_details']['email']
        ?? null;
    if (!$email) {
        error_log('Stripe webhook: checkout.session.completed without customer_email');
        return;
    }

    $mode = $session['mode'] ?? 'payment'; // 'payment' or 'subscription'
    $amount_total = ($session['amount_total'] ?? 0) / 100; // cents → euros

    // Retrieve metadata from payment_intent or subscription
    $metadata = [];
    if ($mode === 'payment' && !empty($session['payment_intent'])) {
        $pi = stripe_api_get('/v1/payment_intents/' . $session['payment_intent']);
        $metadata = $pi['metadata'] ?? [];
    } elseif ($mode === 'subscription' && !empty($session['subscription'])) {
        $sub = stripe_api_get('/v1/subscriptions/' . $session['subscription']);
        $metadata = $sub['metadata'] ?? [];
    }

    // Determine type and frequency
    $type = determine_type($metadata);
    $frequency = determine_frequency($mode, $metadata);

    // Build Brevo contact attributes
    $attributes = build_brevo_attributes($metadata, $type, $amount_total, $frequency);

    // Determine which Brevo lists to add to
    $list_ids = get_brevo_list_ids($type);

    // Upsert contact in Brevo
    sync_to_brevo($email, $attributes, $list_ids);

    // Send tax receipt
    send_tax_receipt($email, $metadata, $amount_total, $type);
}

/**
 * Handle invoice.paid — subscription renewal.
 */
function handle_invoice_paid(array $invoice): void {
    // Only process subscription invoices (not one-time invoice_creation invoices)
    if (empty($invoice['subscription'])) {
        return;
    }

    $email = $invoice['customer_email']
        ?? $invoice['customer_details']['email']
        ?? null;
    if (!$email) {
        error_log('Stripe webhook: invoice.paid without customer_email');
        return;
    }

    $amount = ($invoice['amount_paid'] ?? 0) / 100;

    // Retrieve subscription metadata
    $sub = stripe_api_get('/v1/subscriptions/' . $invoice['subscription']);
    $metadata = $sub['metadata'] ?? [];

    $type = determine_type($metadata);
    $frequency = determine_frequency('subscription', $metadata);

    // On renewal, update payment date, amount, and ensure type/frequency are set
    $attributes = build_brevo_attributes($metadata, $type, $amount, $frequency, false);

    $list_ids = get_brevo_list_ids($type);

    sync_to_brevo($email, $attributes, $list_ids);

    // Send tax receipt for renewal
    send_tax_receipt($email, $metadata, $amount, $type);
}

// ===========================================================================
// Helper functions
// ===========================================================================

/**
 * Determine the contact type from metadata.
 */
function determine_type(array $metadata): string {
    $type = $metadata['type'] ?? '';

    // Map Stripe checkout types to Brevo contact types
    $type_map = [
        'adhesion' => 'adherent',
        'combo'    => 'combo',
        'don'      => 'donateur',
    ];

    return $type_map[$type] ?? 'donateur';
}

/**
 * Determine payment frequency from metadata.
 */
function determine_frequency(string $mode, array $metadata): string {
    // Use explicit frequency from metadata (set by create-checkout.php)
    $freq = $metadata['frequency'] ?? '';
    if (in_array($freq, ['one_time', 'monthly', 'yearly'], true)) {
        return $freq;
    }

    // Fallback: infer from Stripe session mode
    return ($mode === 'payment') ? 'one_time' : 'yearly';
}

/**
 * Build Brevo contact attributes from Stripe metadata.
 *
 * @param bool $is_initial Whether this is the initial payment (sets DATE_ADHESION)
 */
function build_brevo_attributes(array $metadata, string $type, float $amount, string $frequency, bool $is_initial = true): array {
    $attributes = [
        'TYPE'                  => $type,
        'MONTANT'               => $amount,
        'FREQUENCE'             => $frequency,
        'DATE_DERNIER_PAIEMENT' => date('Y-m-d'),
    ];

    // Only set DATE_ADHESION on initial payment, not renewals
    if ($is_initial) {
        $attributes['DATE_ADHESION'] = date('Y-m-d');
    }

    // Map Stripe metadata fields to Brevo attributes
    $field_map = [
        'prenom'  => 'PRENOM',
        'nom'     => 'NOM',
        'adresse' => 'ADRESSE',
        'cp'      => 'CODE_POSTAL',
        'commune' => 'COMMUNE',
        'tel'     => 'TELEPHONE',
    ];

    foreach ($field_map as $stripe_key => $brevo_key) {
        if (!empty($metadata[$stripe_key])) {
            $attributes[$brevo_key] = substr(trim($metadata[$stripe_key]), 0, 500);
        }
    }

    return $attributes;
}

/**
 * Get the Brevo list IDs for a given contact type.
 */
function get_brevo_list_ids(string $type): array {
    global $brevo_list_adherents, $brevo_list_donateurs, $brevo_list_tous;

    $lists = [(int)$brevo_list_tous];

    if ($type === 'adherent' || $type === 'combo') {
        $lists[] = (int)$brevo_list_adherents;
    } else {
        $lists[] = (int)$brevo_list_donateurs;
    }

    // Filter out 0 (unconfigured)
    return array_filter($lists, fn($id) => $id > 0);
}

// ===========================================================================
// API calls
// ===========================================================================

/**
 * GET request to Stripe API.
 */
function stripe_api_get(string $endpoint): array {
    global $stripe_secret_key;

    $ch = curl_init('https://api.stripe.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripe_secret_key . ':',
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Stripe API cURL error: ' . $error);
        return [];
    }

    if ($http_code >= 400) {
        error_log('Stripe API error (HTTP ' . $http_code . '): ' . $response);
        return [];
    }

    return json_decode($response, true) ?: [];
}

/**
 * Sync a contact to Brevo with retry, fallback file, and email notification.
 *
 * @param string $email      Contact email
 * @param array  $attributes Brevo contact attributes
 * @param array  $list_ids   Brevo list IDs to add the contact to
 */
function sync_to_brevo(string $email, array $attributes, array $list_ids): void {
    global $brevo_api_key, $admin_email;

    $body = [
        'email'         => $email,
        'attributes'    => $attributes,
        'listIds'       => array_values($list_ids),
        'updateEnabled' => true,
    ];

    $json_body = json_encode($body);

    // Attempt 1
    $result = brevo_api_post('/v3/contacts', $json_body);
    if ($result['success']) {
        return;
    }

    // Attempt 2 (after 1 second pause)
    sleep(1);
    $result = brevo_api_post('/v3/contacts', $json_body);
    if ($result['success']) {
        return;
    }

    // Both attempts failed — save to fallback file and notify
    $error_msg = $result['error'] ?? 'Unknown error';
    error_log('Brevo sync failed after 2 attempts for ' . $email . ': ' . $error_msg);

    save_failed_contact($email, $attributes, $list_ids, $error_msg);
    notify_admin_failure($email, $error_msg);
}

/**
 * POST request to Brevo API.
 *
 * @return array{success: bool, error?: string}
 */
function brevo_api_post(string $endpoint, string $json_body): array {
    global $brevo_api_key;

    $ch = curl_init('https://api.brevo.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $brevo_api_key,
        ],
        CURLOPT_POSTFIELDS     => $json_body,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $error];
    }

    // Brevo returns 201 for create, 204 for update
    if ($http_code >= 200 && $http_code < 300) {
        return ['success' => true];
    }

    return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . $response];
}

/**
 * Save a failed contact to a local JSON file for later retry.
 * Uses file locking to prevent race conditions on concurrent webhook calls.
 */
function save_failed_contact(string $email, array $attributes, array $list_ids, string $error): void {
    $file = __DIR__ . '/failed_contacts.json';

    $entry = [
        'timestamp'  => date('c'),
        'email'      => $email,
        'attributes' => $attributes,
        'list_ids'   => $list_ids,
        'error'      => $error,
    ];

    $fp = fopen($file, 'c+');
    if (!$fp) {
        error_log('Cannot open fallback file: ' . $file);
        return;
    }

    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $entries = $content ? (json_decode($content, true) ?: []) : [];
        $entries[] = $entry;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
}

/**
 * Send an email notification to the admin about a Brevo sync failure.
 */
function notify_admin_failure(string $contact_email, string $error): void {
    global $admin_email;

    if (empty($admin_email) || $admin_email === 'admin@REPLACE_ME') {
        return;
    }

    $subject = '[ADESZ] Échec synchronisation Brevo';
    $message = "La synchronisation d'un contact vers Brevo a échoué après 2 tentatives.\n\n"
        . "Contact : " . $contact_email . "\n"
        . "Erreur : " . $error . "\n"
        . "Date : " . date('d/m/Y H:i:s') . "\n\n"
        . "Les données ont été sauvegardées dans failed_contacts.json sur le serveur.\n"
        . "Connectez-vous en FTP pour vérifier et relancer manuellement si nécessaire.";

    $headers = 'From: noreply@adesz.fr' . "\r\n"
        . 'Reply-To: noreply@adesz.fr' . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8';

    @mail($admin_email, $subject, $message, $headers);
}

// ===========================================================================
// Tax receipt
// ===========================================================================

/**
 * Generate and send a tax receipt via Brevo transactional email.
 */
function send_tax_receipt(string $email, array $metadata, float $amount, string $type): void {
    global $brevo_api_key;

    if ($amount <= 0) {
        return;
    }

    require_once __DIR__ . '/generate-receipt.php';

    $data = [
        'email'   => $email,
        'prenom'  => $metadata['prenom'] ?? '',
        'nom'     => $metadata['nom'] ?? '',
        'adresse' => $metadata['adresse'] ?? '',
        'cp'      => $metadata['cp'] ?? '',
        'commune' => $metadata['commune'] ?? '',
        'amount'  => $amount,
        'date'    => date('d/m/Y'),
        'type'    => $type,
    ];

    try {
        $result = generate_receipt_pdf($data);
    } catch (\Throwable $e) {
        error_log('Tax receipt: PDF exception for ' . $email . ': ' . $e->getMessage());
        return;
    }
    if (!$result) {
        error_log('Tax receipt: PDF generation failed for ' . $email);
        return;
    }

    $donor_name = trim(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
    $deduction = number_format($amount * 0.66, 2, ',', ' ');
    $amount_fmt = number_format($amount, 2, ',', ' ');

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
            . '<p style="margin:0;font-size:14px;"><strong>Avantage fiscal :</strong> ce don vous ouvre droit à une réduction '
            . 'd\'impôt de <strong>' . $deduction . ' EUR</strong> (66% du montant, art. 200 du CGI).</p>'
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
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        error_log('Tax receipt sent: ' . $result['number'] . ' to ' . $email);
    } else {
        error_log('Tax receipt email failed (HTTP ' . $http_code . '): ' . $response);
    }
}
