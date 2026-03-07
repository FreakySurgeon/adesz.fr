<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$required = ['prenom', 'nom', 'amount', 'date_don', 'type', 'mode_paiement'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Champ requis : $field"]);
        exit;
    }
}

$input['amount'] = (float) $input['amount'];
if ($input['amount'] <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Montant invalide']);
    exit;
}

$input['source'] = 'manual';

try {
    $id = insert_donation($input);
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('Admin save donation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
