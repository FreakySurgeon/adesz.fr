<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../brevo-sync.php';

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

try {
    $edit_id = isset($input['id']) ? (int) $input['id'] : 0;

    if ($edit_id > 0) {
        $existing = get_donation_by_id($edit_id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Don introuvable']);
            exit;
        }
        if ($existing['receipt_number']) {
            http_response_code(400);
            echo json_encode(['error' => "Impossible de modifier un don avec reçu déjà généré"]);
            exit;
        }
        $update_fields = [];
        foreach (['prenom', 'nom', 'email', 'adresse', 'amount', 'date_don', 'type', 'mode_paiement'] as $f) {
            if (array_key_exists($f, $input)) {
                $update_fields[$f] = $input[$f];
            }
        }
        if (isset($input['code_postal'])) {
            $update_fields['cp'] = $input['code_postal'];
        }
        if (isset($input['commune'])) {
            $update_fields['commune'] = $input['commune'];
        }
        if (isset($input['telephone'])) {
            $update_fields['telephone'] = $input['telephone'];
        }
        update_donation($edit_id, $update_fields);
        $donation = get_donation_by_id($edit_id);
    } else {
        $input['source'] = 'manual';
        if (isset($input['code_postal'])) {
            $input['cp'] = $input['code_postal'];
            unset($input['code_postal']);
        }
        $id = insert_donation($input);
        $donation = get_donation_by_id($id);
    }

    // Upsert local contact + sync to Brevo (best-effort)
    $contact_email = trim($donation['email'] ?? '');
    try {
        upsert_contact([
            'email'     => $contact_email ?: null,
            'prenom'    => $donation['prenom'] ?? '',
            'nom'       => $donation['nom'] ?? '',
            'adresse'   => $donation['adresse'] ?? '',
            'cp'        => $donation['cp'] ?? '',
            'commune'   => $donation['commune'] ?? '',
            'telephone' => $donation['telephone'] ?? '',
            'type'      => $donation['type'] ?? '',
            'source'    => 'manual',
        ]);
    } catch (Throwable $e) {
        error_log('Contact upsert failed: ' . $e->getMessage());
    }

    if ($contact_email) {
        try {
            sync_contact_to_brevo($donation);
        } catch (Throwable $e) {
            error_log('Brevo sync failed for manual donation: ' . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'donation' => $donation]);
} catch (Throwable $e) {
    error_log('Admin save donation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
