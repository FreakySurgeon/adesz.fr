<?php
/**
 * Sync contacts from Brevo → local contacts table.
 * POST only, requires WordPress auth.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

global $brevo_api_key;

if (empty($brevo_api_key) || $brevo_api_key === 'xkeysib-REPLACE_ME') {
    http_response_code(500);
    echo json_encode(['error' => 'Brevo API key not configured']);
    exit;
}

$limit = 50;
$offset = 0;
$imported = 0;
$errors = [];

do {
    $url = "https://api.brevo.com/v3/contacts?limit=$limit&offset=$offset&sort=desc";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $brevo_api_key,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo json_encode(['error' => "Brevo API error HTTP $http_code", 'imported' => $imported]);
        exit;
    }

    $data = json_decode($response, true);
    $contacts = $data['contacts'] ?? [];

    foreach ($contacts as $c) {
        $email = $c['email'] ?? '';
        if (empty($email)) continue;
        $attrs = $c['attributes'] ?? [];

        try {
            upsert_contact([
                'email'     => $email,
                'prenom'    => $attrs['PRENOM'] ?? '',
                'nom'       => $attrs['NOM'] ?? '',
                'adresse'   => $attrs['ADRESSE'] ?? '',
                'cp'        => $attrs['CODE_POSTAL'] ?? '',
                'commune'   => $attrs['COMMUNE'] ?? '',
                'telephone' => $attrs['TELEPHONE'] ?? '',
                'type'      => $attrs['TYPE'] ?? '',
                'source'    => 'brevo',
            ]);
            $imported++;
        } catch (Throwable $e) {
            $errors[] = "$email: " . $e->getMessage();
        }
    }

    $offset += $limit;
    $total = $data['count'] ?? 0;
} while ($offset < $total);

echo json_encode([
    'success'  => true,
    'imported' => $imported,
    'total'    => $total,
    'errors'   => $errors,
]);
