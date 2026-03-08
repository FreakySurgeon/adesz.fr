<?php
/**
 * Shared Brevo contact sync helper.
 * Used by api-save.php and stripe-webhook.php for upserting contacts.
 */

function sync_contact_to_brevo(array $donation): void {
    global $brevo_api_key, $brevo_list_donateurs, $brevo_list_adherents, $brevo_list_tous;

    $email = trim($donation['email'] ?? '');
    if (empty($email) || empty($brevo_api_key) || $brevo_api_key === 'xkeysib-REPLACE_ME') return;

    $attributes = [];
    if (!empty($donation['prenom']))    $attributes['PRENOM'] = $donation['prenom'];
    if (!empty($donation['nom']))       $attributes['NOM'] = $donation['nom'];
    if (!empty($donation['adresse']))   $attributes['ADRESSE'] = $donation['adresse'];
    if (!empty($donation['cp']))        $attributes['CODE_POSTAL'] = $donation['cp'];
    if (!empty($donation['commune']))   $attributes['COMMUNE'] = $donation['commune'];
    if (!empty($donation['telephone'])) $attributes['TELEPHONE'] = $donation['telephone'];
    $attributes['TYPE'] = $donation['type'] ?? 'don';
    $attributes['MONTANT'] = (float) ($donation['amount'] ?? 0);
    $attributes['DATE_DERNIER_PAIEMENT'] = date('Y-m-d');

    $list_ids = [(int) $brevo_list_tous];
    $type = $donation['type'] ?? 'don';
    if ($type === 'adhesion' || $type === 'combo') $list_ids[] = (int) $brevo_list_adherents;
    if ($type === 'don' || $type === 'combo')      $list_ids[] = (int) $brevo_list_donateurs;

    $body = json_encode([
        'email'         => $email,
        'attributes'    => $attributes,
        'listIds'       => $list_ids,
        'updateEnabled' => true,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/contacts');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $brevo_api_key,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT    => 10,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code < 200 || $http_code >= 300) {
        error_log("Brevo upsert failed for $email (HTTP $http_code): $response");
    }
}
