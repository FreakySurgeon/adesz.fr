#!/usr/bin/env php
<?php
/**
 * Send the "nouveau site" newsletter via Brevo API.
 *
 * Usage:
 *   php scripts/send-newsletter.php test          # Send test to admin
 *   php scripts/send-newsletter.php send          # Send to Newsletter list (6)
 */

// ---------------------------------------------------------------------------
// Load API key
// ---------------------------------------------------------------------------
$api_key = getenv('BREVO_API_KEY');
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
    exit(1);
}

$mode = $argv[1] ?? 'test';

// ---------------------------------------------------------------------------
// Newsletter HTML content
// ---------------------------------------------------------------------------
$inner = ''
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">Chers amis de l\'ADESZ,</p>'

    . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Nous avons le plaisir de vous annoncer la mise &agrave; jour de notre site internet de l\'ADESZ&nbsp;: '
    . '<a href="https://adesz.fr" style="color:#2D7A3A;font-weight:600;">adesz.fr</a>'
    . '</p>'

    . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Nous avons enti&egrave;rement repens&eacute; le site pour qu\'il soit plus clair, plus rapide et plus simple &agrave; utiliser, '
    . 'que vous soyez sur ordinateur ou sur t&eacute;l&eacute;phone.'
    . '</p>'

    // Section title
    . '<p style="margin:0 0 20px 0;font-size:20px;font-weight:700;color:#2D7A3A;">Ce qui change pour vous</p>'

    // 🌍 Actions
    . '<p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#2D3436;">&#127757; D&eacute;couvrez nos actions</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Retrouvez facilement nos projets en cours, nos r&eacute;alisations pass&eacute;es et nos domaines d\'intervention '
    . '&agrave; Zafaya&nbsp;: &eacute;ducation, sant&eacute;, agriculture, acc&egrave;s &agrave; l\'eau, d&eacute;veloppement &eacute;conomique.'
    . '</p>'

    // 💳 CB
    . '<p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#2D3436;">&#128179; Faites un don par carte bancaire</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'C\'est la grande nouveaut&eacute;&nbsp;: vous pouvez d&eacute;sormais soutenir l\'ADESZ directement en ligne par carte bancaire, '
    . 'de mani&egrave;re ponctuelle ou r&eacute;currente. Le paiement est 100% s&eacute;curis&eacute; par Stripe, '
    . 'le leader mondial du paiement en ligne.'
    . '</p>'

    // 🧾 Reçu fiscal
    . '<p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#2D3436;">&#129534; Votre re&ccedil;u fiscal imm&eacute;diat</p>'
    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Apr&egrave;s chaque don, vous recevez automatiquement par email votre re&ccedil;u fiscal (Cerfa n&deg;11580*04). '
    . 'De plus, en d&eacute;but de chaque ann&eacute;e, un re&ccedil;u fiscal r&eacute;capitulatif du cumul de vos dons '
    . 'de l\'ann&eacute;e pr&eacute;c&eacute;dente vous sera envoy&eacute;. '
    . '<strong>L\'ADESZ</strong> &eacute;tant une association d\'int&eacute;r&ecirc;t g&eacute;n&eacute;ral, '
    . '<strong>vos dons sont d&eacute;ductibles de vos imp&ocirc;ts &agrave; hauteur de 66%</strong> '
    . '(dans la limite de 20% du revenu imposable).'
    . '</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;font-style:italic;">'
    . 'Exemple&nbsp;: un don de 50&nbsp;&euro; ne vous co&ucirc;te en r&eacute;alit&eacute; &laquo;&nbsp;que 17&nbsp;&euro;&nbsp;&raquo;.'
    . '</p>'

    // 📰 Actualité
    . '<p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#2D3436;">&#128240; Suivez notre actualit&eacute;</p>'
    . '<p style="margin:0 0 28px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Le site est aliment&eacute; par nos articles&nbsp;: projets en cours, retours du terrain, revue de presse. '
    . 'Restez inform&eacute;s de ce qui se passe &agrave; Zafaya.'
    . '</p>'

    // Comment nous soutenir
    . '<p style="margin:0 0 16px 0;font-size:20px;font-weight:700;color:#2D7A3A;">Comment nous soutenir&nbsp;?</p>'

    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . '&rarr; <strong>Faire un don</strong>&nbsp;: <a href="https://adesz.fr/adherer" style="color:#2D7A3A;font-weight:600;">adesz.fr/adherer</a>'
    . '</p>'
    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . '&rarr; <strong>Devenir adh&eacute;rent</strong> (paiement unique de 15&nbsp;&euro;)&nbsp;: m&ecirc;me page, choisissez &laquo;&nbsp;Adh&eacute;sion&nbsp;&raquo;'
    . '</p>'
    . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . '&rarr; <strong>Partager</strong> ce message autour de vous &mdash; chaque nouveau soutien compte&nbsp;!'
    . '</p>'

    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Merci pour votre fid&eacute;lit&eacute; et votre engagement aux c&ocirc;t&eacute;s de l\'ADESZ. '
    . 'Chaque geste, petit ou grand, contribue au d&eacute;veloppement de Zafaya.'
    . '</p>';

// ---------------------------------------------------------------------------
// Wrap in ADESZ branded layout (same as setup-brevo-campaigns.php)
// ---------------------------------------------------------------------------
$html = '<!DOCTYPE html>'
    . '<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
    . '<body style="margin:0;padding:0;background-color:#F8F7F4;font-family:Arial,Helvetica,sans-serif;color:#2D3436;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8F7F4;">'
    . '<tr><td align="center" style="padding:20px 10px;">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">'

    // Header
    . '<tr><td style="background-color:#2D7A3A;padding:24px;text-align:center;">'
    . '<img src="https://adesz.fr/images/logo-adesz-2026.jpg" alt="ADESZ" width="120" style="display:block;margin:0 auto 12px auto;max-width:120px;" />'
    . '<p style="margin:0;font-size:14px;color:#F5C518;font-weight:600;">Association pour le D&eacute;veloppement, l\'Entraide et la Solidarit&eacute; du Village de Zafaya</p>'
    . '</td></tr>'

    // Content
    . '<tr><td style="padding:32px 28px 16px 28px;">'
    . $inner
    . '</td></tr>'

    // Signature
    . '<tr><td style="padding:0 28px 24px 28px;">'
    . '<p style="margin:24px 0 4px 0;font-size:15px;color:#2D3436;">Solidairement,</p>'
    . '<p style="margin:0;font-size:15px;font-weight:600;color:#2D7A3A;">Le bureau de l\'ADESZ</p>'
    . '</td></tr>'

    // CTA button
    . '<tr><td style="padding:0 28px 32px 28px;text-align:center;">'
    . '<a href="https://adesz.fr/adherer" style="display:inline-block;background-color:#F5C518;color:#2D3436;font-size:16px;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:6px;">Soutenir l\'association</a>'
    . '</td></tr>'

    // Footer
    . '<tr><td style="background-color:#f0efeb;padding:24px 28px;text-align:center;font-size:13px;color:#636e72;line-height:1.6;">'
    . '<p style="margin:0 0 8px 0;font-weight:600;">ADESZ</p>'
    . '<p style="margin:0 0 4px 0;">491 Bd Pierre Delmas, 06600 Antibes</p>'
    . '<p style="margin:0 0 4px 0;">Email : adeszafaya@gmail.com | Tel : 06 63 04 66 12</p>'
    . '<p style="margin:12px 0 0 0;font-size:12px;"><a href="{{ unsubscribe }}" style="color:#636e72;text-decoration:underline;">Se d&eacute;sinscrire</a></p>'
    . '</td></tr>'

    . '</table>'
    . '</td></tr></table>'
    . '</body></html>';

$subject = 'Le nouveau site adesz.fr est en ligne — soutenez-nous en quelques clics !';

// ---------------------------------------------------------------------------
// Brevo API helper
// ---------------------------------------------------------------------------
function brevo_request(string $method, string $endpoint, ?string $body, string $api_key): array {
    $ch = curl_init('https://api.brevo.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $response ?: ''];
}

// ---------------------------------------------------------------------------
// Send
// ---------------------------------------------------------------------------
if ($mode === 'test') {
    // Send transactional email to test address
    $payload = json_encode([
        'sender'  => ['name' => 'ADESZ', 'email' => 'contact@adesz.fr'],
        'to'      => [['email' => 'chauvet.t@gmail.com', 'name' => 'Thomas (test)']],
        'subject' => '[TEST] ' . $subject,
        'htmlContent' => $html,
    ]);

    $result = brevo_request('POST', '/v3/smtp/email', $payload, $api_key);
    if ($result['code'] === 201) {
        echo "✓ Test email sent to chauvet.t@gmail.com\n";
    } else {
        echo "✗ Failed (HTTP {$result['code']}): {$result['body']}\n";
    }

} elseif ($mode === 'send') {
    // Create and send a campaign to Newsletter list (6)
    $campaign_payload = json_encode([
        'name'       => 'Nouveau site adesz.fr - ' . date('Y-m-d'),
        'subject'    => $subject,
        'sender'     => ['name' => 'ADESZ', 'email' => 'contact@adesz.fr'],
        'htmlContent' => $html,
        'recipients' => ['listIds' => [6]],
        'replyTo'    => 'contact@adesz.fr',
    ]);

    // Step 1: Create campaign
    $result = brevo_request('POST', '/v3/emailCampaigns', $campaign_payload, $api_key);
    $response = json_decode($result['body'], true);

    if ($result['code'] !== 201 || !isset($response['id'])) {
        echo "✗ Campaign creation failed (HTTP {$result['code']}): {$result['body']}\n";
        exit(1);
    }

    $campaign_id = $response['id'];
    echo "✓ Campaign created (ID: $campaign_id)\n";

    // Step 2: Send campaign immediately
    $send_result = brevo_request('POST', "/v3/emailCampaigns/$campaign_id/sendNow", '{}', $api_key);
    if ($send_result['code'] === 204) {
        echo "✓ Campaign sent to Newsletter list (ID: 6)!\n";
    } else {
        echo "✗ Send failed (HTTP {$send_result['code']}): {$send_result['body']}\n";
        echo "  Campaign ID $campaign_id was created but not sent. You can send it from Brevo UI.\n";
    }

} else {
    echo "Usage: php scripts/send-newsletter.php [test|send]\n";
}
