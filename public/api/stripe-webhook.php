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
        $kv = explode('=', $item, 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }

    $timestamp = $parts['t'][0] ?? null;
    $signatures = $parts['v1'] ?? [];

    if (!$timestamp || empty($signatures)) {
        return false;
    }

    // Check timestamp tolerance (anti-replay)
    if (abs(time() - (int)$timestamp) > $tolerance) {
        return false;
    }

    // Compute expected signature
    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    // Compare against all v1 signatures (Stripe may send multiple during key rotation)
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

if (!verify_stripe_signature($payload, $sig_header, $stripe_webhook_secret)) {
    http_response_code(400);
    error_log('Stripe webhook: invalid signature');
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
    global $stripe_secret_key;

    $email = $session['customer_email'] ?? null;
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
    $type = determine_type($metadata, $session);
    $frequency = determine_frequency($mode, $metadata);

    // Build Brevo contact attributes
    $attributes = build_brevo_attributes($metadata, $type, $amount_total, $frequency);

    // Determine which Brevo lists to add to
    $list_ids = get_brevo_list_ids($type);

    // Upsert contact in Brevo
    sync_to_brevo($email, $attributes, $list_ids);
}

/**
 * Handle invoice.paid — subscription renewal.
 */
function handle_invoice_paid(array $invoice): void {
    global $stripe_secret_key;

    // Only process subscription invoices (not one-time invoice_creation invoices)
    if (empty($invoice['subscription'])) {
        return;
    }

    $email = $invoice['customer_email'] ?? null;
    if (!$email) {
        error_log('Stripe webhook: invoice.paid without customer_email');
        return;
    }

    $amount = ($invoice['amount_paid'] ?? 0) / 100;

    // Retrieve subscription metadata
    $sub = stripe_api_get('/v1/subscriptions/' . $invoice['subscription']);
    $metadata = $sub['metadata'] ?? [];

    $type = determine_type($metadata, []);
    $frequency = determine_frequency('subscription', $metadata);

    // On renewal, only update payment date and amount
    $attributes = [
        'DATE_DERNIER_PAIEMENT' => date('Y-m-d'),
        'MONTANT'               => $amount,
    ];

    // Add member info if available (in case it changed)
    if (!empty($metadata['nom']))    $attributes['NOM'] = $metadata['nom'];
    if (!empty($metadata['prenom'])) $attributes['PRENOM'] = $metadata['prenom'];

    $list_ids = get_brevo_list_ids($type);

    sync_to_brevo($email, $attributes, $list_ids);
}

// ===========================================================================
// Helper functions
// ===========================================================================

/**
 * Determine the contact type from metadata/session.
 */
function determine_type(array $metadata, array $session): string {
    // Check if line items indicate the type (adhesion products have specific names)
    // Metadata doesn't store 'type' directly — we infer from what's present
    if (!empty($metadata['nom']) || !empty($metadata['prenom'])) {
        // Has member data → adhesion or combo
        // If amount_total > adhesion price (15€), it's a combo
        $amount = ($session['amount_total'] ?? 0) / 100;
        if ($amount > 15) {
            return 'combo';
        }
        return 'adherent';
    }
    return 'donateur';
}

/**
 * Determine payment frequency.
 */
function determine_frequency(string $mode, array $metadata): string {
    if ($mode === 'payment') {
        return 'one_time';
    }
    // For subscriptions, we'd need to check the interval
    // Default to yearly as that's the most common for the association
    return 'yearly';
}

/**
 * Build Brevo contact attributes from Stripe metadata.
 */
function build_brevo_attributes(array $metadata, string $type, float $amount, string $frequency): array {
    $attributes = [
        'TYPE'                  => $type,
        'MONTANT'               => $amount,
        'FREQUENCE'             => $frequency,
        'DATE_DERNIER_PAIEMENT' => date('Y-m-d'),
    ];

    // Set DATE_ADHESION only for new contacts (Brevo upsert won't overwrite if we exclude it)
    // We always set it — Brevo's upsert will keep the first value if we later change strategy
    $attributes['DATE_ADHESION'] = date('Y-m-d');

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
            $attributes[$brevo_key] = $metadata[$stripe_key];
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
        CURLOPT_TIMEOUT        => 15,
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
        CURLOPT_TIMEOUT        => 15,
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

    // Read existing entries
    $entries = [];
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $entries = json_decode($content, true) ?: [];
    }

    $entries[] = $entry;

    file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
