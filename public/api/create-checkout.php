<?php
/**
 * Stripe Checkout Session creation endpoint for ADESZ
 *
 * Accepts POST JSON with:
 *   - amount:    int (cents, 100..1000000)
 *   - frequency: "one_time" | "monthly" | "yearly"
 *   - type:      "don" | "adhesion" | "combo"
 *
 * Returns JSON: { "url": "..." } on success, { "error": "..." } on failure.
 */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// CORS
// ---------------------------------------------------------------------------
$allowed_origins = [
    'https://adesz.fr',
    'https://www.adesz.fr',
    'http://localhost:4321',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Only POST allowed
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// Parse & validate input
// ---------------------------------------------------------------------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$amount    = $data['amount']    ?? null;
$frequency = $data['frequency'] ?? null;
$type      = $data['type']      ?? null;

// Validate type
$valid_types = ['don', 'adhesion', 'combo'];
if (!in_array($type, $valid_types, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type. Must be one of: don, adhesion, combo']);
    exit;
}

// Validate frequency
$valid_frequencies = ['one_time', 'monthly', 'yearly'];
if (!in_array($frequency, $valid_frequencies, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid frequency. Must be one of: one_time, monthly, yearly']);
    exit;
}

// Validate amount (integer, 100..1000000 cents)
if (!is_int($amount) || $amount < 100 || $amount > 1000000) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid amount. Must be an integer between 100 and 1000000 (cents)']);
    exit;
}

// ---------------------------------------------------------------------------
// Build line items
// ---------------------------------------------------------------------------
$adhesion_amount = 1500; // 15 EUR in cents
$line_items = [];

/**
 * Build a single line-item array for the Stripe API.
 *
 * @param string      $name      Product name
 * @param int         $amount    Amount in cents
 * @param string      $frequency one_time | monthly | yearly
 * @param string|null $force_interval  If set, override the recurring interval
 * @return array
 */
function build_line_item(string $name, int $amount, string $frequency, ?string $force_interval = null): array {
    $item = [
        'price_data' => [
            'currency'     => 'eur',
            'unit_amount'  => $amount,
            'product_data' => [
                'name' => $name,
            ],
        ],
        'quantity' => 1,
    ];

    if ($frequency !== 'one_time') {
        $interval = $force_interval ?? ($frequency === 'monthly' ? 'month' : 'year');
        $item['price_data']['recurring'] = [
            'interval' => $interval,
        ];
    }

    return $item;
}

if ($type === 'don') {
    $line_items[] = build_line_item('Don ADESZ', $amount, $frequency);
} elseif ($type === 'adhesion') {
    $line_items[] = build_line_item('Adhésion ADESZ', $adhesion_amount, $frequency);
} elseif ($type === 'combo') {
    // Combo: adhesion + donation
    // For subscriptions, Stripe requires the same interval on all items,
    // so we force both to yearly when recurring.
    $combo_frequency = $frequency;
    $force_interval  = null;

    if ($frequency !== 'one_time') {
        $force_interval = 'year'; // force yearly for combo subscriptions
    }

    $line_items[] = build_line_item('Adhésion ADESZ', $adhesion_amount, $combo_frequency, $force_interval);
    $line_items[] = build_line_item('Don ADESZ', $amount, $combo_frequency, $force_interval);
}

// ---------------------------------------------------------------------------
// Build Stripe Checkout Session params
// ---------------------------------------------------------------------------
$mode = ($frequency === 'one_time') ? 'payment' : 'subscription';

$params = [
    'mode'                 => $mode,
    'locale'               => 'fr',
    'payment_method_types' => ['card'],
    'line_items'           => $line_items,
    'success_url'          => $site_url . $base_path . '/merci',
    'cancel_url'           => $site_url . $base_path . '/adherer',
];

// ---------------------------------------------------------------------------
// Call Stripe API
// ---------------------------------------------------------------------------
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $stripe_secret_key . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response    = curl_exec($ch);
$curl_error  = curl_error($ch);
$http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to contact payment provider']);
    // Log the actual cURL error server-side (not exposed to client)
    error_log('Stripe cURL error: ' . $curl_error);
    exit;
}

$result = json_decode($response, true);

if ($http_code >= 400 || !isset($result['url'])) {
    http_response_code(502);
    $stripe_error = $result['error']['message'] ?? 'Unknown payment error';
    echo json_encode(['error' => 'Payment session creation failed']);
    // Log the real Stripe error server-side
    error_log('Stripe API error (HTTP ' . $http_code . '): ' . $stripe_error);
    exit;
}

// ---------------------------------------------------------------------------
// Success — return the Checkout URL
// ---------------------------------------------------------------------------
echo json_encode(['url' => $result['url']]);
