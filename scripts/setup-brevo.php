#!/usr/bin/env php
<?php
/**
 * One-time setup script for Brevo (ex-Sendinblue).
 *
 * Creates:
 *   - Contact attributes (PRENOM, NOM, ADRESSE, etc.)
 *   - Contact lists (Adhérents, Donateurs, Tous)
 *
 * Usage:
 *   BREVO_API_KEY=xkeysib-... php scripts/setup-brevo.php
 *
 * Or add BREVO_API_KEY to .env and run:
 *   php scripts/setup-brevo.php
 */

// ---------------------------------------------------------------------------
// Load API key
// ---------------------------------------------------------------------------
$api_key = getenv('BREVO_API_KEY');

// Try .env file if not set in environment
if (!$api_key) {
    $env_file = __DIR__ . '/../.env';
    if (file_exists($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                if (trim($key) === 'BREVO_API_KEY') {
                    $api_key = trim($value);
                }
            }
        }
    }
}

if (!$api_key) {
    echo "ERROR: BREVO_API_KEY not found.\n";
    echo "Set it via: BREVO_API_KEY=xkeysib-... php scripts/setup-brevo.php\n";
    echo "Or add BREVO_API_KEY=xkeysib-... to .env\n";
    exit(1);
}

echo "Brevo setup starting...\n\n";

// ---------------------------------------------------------------------------
// Create contact attributes
// ---------------------------------------------------------------------------
$attributes = [
    ['category' => 'normal', 'type' => 'text',   'name' => 'PRENOM'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'NOM'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'ADRESSE'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'CODE_POSTAL'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'COMMUNE'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'TELEPHONE'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'TYPE'],
    ['category' => 'normal', 'type' => 'date',   'name' => 'DATE_ADHESION'],
    ['category' => 'normal', 'type' => 'float',  'name' => 'MONTANT'],
    ['category' => 'normal', 'type' => 'text',   'name' => 'FREQUENCE'],
    ['category' => 'normal', 'type' => 'date',   'name' => 'DATE_DERNIER_PAIEMENT'],
];

echo "--- Creating contact attributes ---\n";

foreach ($attributes as $attr) {
    $name = $attr['name'];
    $body = json_encode(['type' => $attr['type']]);

    $result = brevo_request(
        'POST',
        '/v3/contacts/attributes/' . $attr['category'] . '/' . $name,
        $body,
        $api_key
    );

    if ($result['code'] === 201 || $result['code'] === 204) {
        echo "  ✓ $name created\n";
    } elseif ($result['code'] === 400 && str_contains($result['body'], 'already exists')) {
        echo "  · $name already exists (skipped)\n";
    } else {
        echo "  ✗ $name failed (HTTP {$result['code']}): {$result['body']}\n";
    }
}

echo "\n";

// ---------------------------------------------------------------------------
// Create contact lists
// ---------------------------------------------------------------------------
$lists = [
    'Adhérents ADESZ',
    'Donateurs ADESZ',
    'Tous les contacts ADESZ',
];

echo "--- Creating contact lists ---\n";

$list_ids = [];
foreach ($lists as $list_name) {
    $body = json_encode([
        'name'     => $list_name,
        'folderId' => 1, // Default folder
    ]);

    $result = brevo_request('POST', '/v3/contacts/lists', $body, $api_key);
    $response = json_decode($result['body'], true);

    if ($result['code'] === 201 && isset($response['id'])) {
        $list_ids[$list_name] = $response['id'];
        echo "  ✓ \"$list_name\" created (ID: {$response['id']})\n";
    } elseif ($result['code'] === 400 && str_contains($result['body'], 'already exists')) {
        echo "  · \"$list_name\" already exists — check Brevo dashboard for the ID\n";
    } else {
        echo "  ✗ \"$list_name\" failed (HTTP {$result['code']}): {$result['body']}\n";
    }
}

echo "\n";

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "=== SETUP COMPLETE ===\n\n";

if (!empty($list_ids)) {
    echo "Add these list IDs to your GitHub Actions secrets:\n\n";

    foreach ($list_ids as $name => $id) {
        if (str_contains($name, 'Adhérents')) {
            echo "  BREVO_LIST_ADHERENTS = $id\n";
        } elseif (str_contains($name, 'Donateurs')) {
            echo "  BREVO_LIST_DONATEURS = $id\n";
        } elseif (str_contains($name, 'Tous')) {
            echo "  BREVO_LIST_TOUS = $id\n";
        }
    }

    echo "\nCommands to set them:\n\n";
    foreach ($list_ids as $name => $id) {
        if (str_contains($name, 'Adhérents')) {
            echo "  echo \"$id\" | gh secret set BREVO_LIST_ADHERENTS -R FreakySurgeon/adesz.fr\n";
        } elseif (str_contains($name, 'Donateurs')) {
            echo "  echo \"$id\" | gh secret set BREVO_LIST_DONATEURS -R FreakySurgeon/adesz.fr\n";
        } elseif (str_contains($name, 'Tous')) {
            echo "  echo \"$id\" | gh secret set BREVO_LIST_TOUS -R FreakySurgeon/adesz.fr\n";
        }
    }
}

echo "\nAlso add the Brevo API key:\n";
echo "  echo \"YOUR_BREVO_API_KEY\" | gh secret set BREVO_API_KEY -R FreakySurgeon/adesz.fr\n";

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function brevo_request(string $method, string $endpoint, string $body, string $api_key): array {
    $ch = curl_init('https://api.brevo.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $response ?: ''];
}
