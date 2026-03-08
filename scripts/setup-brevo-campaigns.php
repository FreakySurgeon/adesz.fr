#!/usr/bin/env php
<?php
/**
 * Setup script for Brevo email templates and automation workflows.
 *
 * Creates:
 *   - 4 manual templates (Newsletter, Annonce Projet, Appel aux dons, Invitation)
 *   - 3 automated templates (Anniversaire, Relance, Reactivation)
 *   - Prints workflow configuration instructions
 *
 * Usage:
 *   BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
 *
 * Or add BREVO_API_KEY to .env and run:
 *   php scripts/setup-brevo-campaigns.php
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
    echo "Set it via: BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php\n";
    echo "Or add BREVO_API_KEY=xkeysib-... to .env\n";
    exit(1);
}

echo "Brevo campaigns setup starting...\n\n";

// ---------------------------------------------------------------------------
// Helper: Brevo API request
// ---------------------------------------------------------------------------
function brevo_request(string $method, string $endpoint, ?string $body, string $api_key): array {
    $ch = curl_init('https://api.brevo.com' . $endpoint);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $response ?: ''];
}

// ---------------------------------------------------------------------------
// Helper: Get existing templates (paginated)
// ---------------------------------------------------------------------------
function get_existing_templates(string $api_key): array {
    $existing = [];
    $limit = 50;
    $offset = 0;

    while (true) {
        $result = brevo_request(
            'GET',
            '/v3/smtp/templates?limit=' . $limit . '&offset=' . $offset . '&sort=desc',
            null,
            $api_key
        );

        if ($result['code'] !== 200) {
            echo "  Warning: could not fetch templates (HTTP {$result['code']})\n";
            break;
        }

        $data = json_decode($result['body'], true);
        $templates = $data['templates'] ?? [];

        if (empty($templates)) break;

        foreach ($templates as $tpl) {
            $existing[$tpl['name']] = $tpl['id'];
        }

        $offset += $limit;
        if ($offset >= ($data['count'] ?? 0)) break;
    }

    return $existing;
}

// ---------------------------------------------------------------------------
// Helper: Create template (idempotent)
// ---------------------------------------------------------------------------
function create_template(string $name, string $subject, string $html, string $api_key, array &$existing): ?int {
    $fields = [
        'subject'     => $subject,
        'htmlContent' => $html,
        'sender'      => ['name' => 'ADESZ', 'email' => 'adeszafaya@gmail.com'],
        'replyTo'     => 'adeszafaya@gmail.com',
        'isActive'    => true,
    ];

    if (isset($existing[$name])) {
        // Update existing template
        $id = $existing[$name];
        $result = brevo_request('PUT', '/v3/smtp/templates/' . $id, json_encode($fields), $api_key);
        if ($result['code'] === 204) {
            echo "  ✓ \"$name\" updated (ID: $id)\n";
            return $id;
        }
        echo "  ✗ \"$name\" update failed (HTTP {$result['code']}): {$result['body']}\n";
        return $id;
    }

    $fields['templateName'] = $name;
    $result = brevo_request('POST', '/v3/smtp/templates', json_encode($fields), $api_key);
    $response = json_decode($result['body'], true);

    if ($result['code'] === 201 && isset($response['id'])) {
        $existing[$name] = $response['id'];
        echo "  ✓ \"$name\" created (ID: {$response['id']})\n";
        return $response['id'];
    }

    echo "  ✗ \"$name\" failed (HTTP {$result['code']}): {$result['body']}\n";
    return null;
}

// ---------------------------------------------------------------------------
// Helper: Wrap email content in ADESZ branded layout
// ---------------------------------------------------------------------------
function wrap_email(string $inner, string $signed_by = 'L\'equipe ADESZ'): string {
    return '<!DOCTYPE html>'
        . '<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background-color:#F8F7F4;font-family:Arial,Helvetica,sans-serif;color:#2D3436;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8F7F4;">'
        . '<tr><td align="center" style="padding:20px 10px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">'

        // Header
        . '<tr><td style="background-color:#2D7A3A;padding:24px;text-align:center;">'
        . '<img src="https://adesz.fr/images/logo-adesz-2026.jpg" alt="ADESZ" width="120" style="display:block;margin:0 auto 12px auto;max-width:120px;" />'
        . '<p style="margin:0;font-size:14px;color:#F5C518;font-weight:600;">Association pour le D&eacute;veloppement &Eacute;conomique et Social du pays Zaghawa</p>'
        . '</td></tr>'

        // Content
        . '<tr><td style="padding:32px 28px 16px 28px;">'
        . $inner
        . '</td></tr>'

        // Signature
        . '<tr><td style="padding:0 28px 24px 28px;">'
        . '<p style="margin:24px 0 4px 0;font-size:15px;color:#2D3436;">Chaleureusement,</p>'
        . '<p style="margin:0;font-size:15px;font-weight:600;color:#2D7A3A;">' . $signed_by . '</p>'
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
}

// ---------------------------------------------------------------------------
// Fetch existing templates
// ---------------------------------------------------------------------------
echo "--- Fetching existing templates ---\n";
$existing = get_existing_templates($api_key);
echo "  Found " . count($existing) . " existing template(s)\n\n";

// ---------------------------------------------------------------------------
// T1 — Newsletter
// ---------------------------------------------------------------------------
echo "--- Creating manual templates ---\n";

$t1_inner = ''
    . '<p style="margin:0 0 20px 0;font-size:22px;font-weight:700;color:#2D7A3A;">{{ params.TITLE }}</p>'
    . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#2D3436;">{{ params.INTRO }}</p>'

    // Article 1
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">'
    . '<tr><td>'
    . '<img src="{{ params.IMAGE_1 }}" alt="" width="544" style="display:block;width:100%;max-width:544px;border-radius:6px;margin-bottom:12px;" />'
    . '<p style="margin:0 0 6px 0;font-size:18px;font-weight:700;color:#2D7A3A;">{{ params.ARTICLE_1_TITRE }}</p>'
    . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#2D3436;">{{ params.ARTICLE_1_TEXTE }}</p>'
    . '<a href="{{ params.ARTICLE_1_LIEN }}" style="font-size:14px;font-weight:600;color:#2D7A3A;text-decoration:underline;">Lire la suite</a>'
    . '</td></tr></table>'

    // Article 2
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">'
    . '<tr><td>'
    . '<img src="{{ params.IMAGE_2 }}" alt="" width="544" style="display:block;width:100%;max-width:544px;border-radius:6px;margin-bottom:12px;" />'
    . '<p style="margin:0 0 6px 0;font-size:18px;font-weight:700;color:#2D7A3A;">{{ params.ARTICLE_2_TITRE }}</p>'
    . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#2D3436;">{{ params.ARTICLE_2_TEXTE }}</p>'
    . '<a href="{{ params.ARTICLE_2_LIEN }}" style="font-size:14px;font-weight:600;color:#2D7A3A;text-decoration:underline;">Lire la suite</a>'
    . '</td></tr></table>';

$t1_id = create_template(
    'ADESZ - Newsletter',
    '{{ params.TITLE }}',
    wrap_email($t1_inner),
    $api_key,
    $existing
);

// ---------------------------------------------------------------------------
// T2 — Annonce Projet
// ---------------------------------------------------------------------------
$t2_inner = ''
    . '<img src="{{ params.IMAGE }}" alt="" width="544" style="display:block;width:100%;max-width:544px;border-radius:6px;margin-bottom:16px;" />'
    . '<p style="margin:0 0 12px 0;font-size:22px;font-weight:700;color:#2D7A3A;">{{ params.TITRE_PROJET }}</p>'
    . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#2D3436;">{{ params.DESCRIPTION }}</p>'

    // 2-column impact stats
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">'
    . '<tr>'
    . '<td width="50%" style="text-align:center;padding:16px;background-color:#F8F7F4;border-radius:6px 0 0 6px;">'
    . '<p style="margin:0 0 4px 0;font-size:28px;font-weight:700;color:#2D7A3A;">{{ params.CHIFFRE_1 }}</p>'
    . '<p style="margin:0;font-size:13px;color:#636e72;">{{ params.LABEL_1 }}</p>'
    . '</td>'
    . '<td width="50%" style="text-align:center;padding:16px;background-color:#F8F7F4;border-radius:0 6px 6px 0;">'
    . '<p style="margin:0 0 4px 0;font-size:28px;font-weight:700;color:#2D7A3A;">{{ params.CHIFFRE_2 }}</p>'
    . '<p style="margin:0;font-size:13px;color:#636e72;">{{ params.LABEL_2 }}</p>'
    . '</td>'
    . '</tr></table>';

$t2_id = create_template(
    'ADESZ - Annonce Projet',
    '{{ params.TITRE_PROJET }}',
    wrap_email($t2_inner),
    $api_key,
    $existing
);

// ---------------------------------------------------------------------------
// T3 — Appel aux dons
// ---------------------------------------------------------------------------
$t3_inner = ''
    . '<p style="margin:0 0 8px 0;font-size:22px;font-weight:700;color:#2D7A3A;">{{ params.TITRE }}</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">Cher(e) {{ contact.PRENOM }},</p>'
    . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#2D3436;">{{ params.MESSAGE }}</p>'

    // Progress bar
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">'
    . '<tr><td style="padding:16px;background-color:#F8F7F4;border-radius:6px;">'
    . '<p style="margin:0 0 8px 0;font-size:14px;color:#636e72;">{{ params.COLLECTE }} / {{ params.OBJECTIF }}</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#dfe6e9;border-radius:4px;"><tr>'
    . '<td width="{{ params.POURCENTAGE }}%" style="background-color:#2D7A3A;border-radius:4px;height:12px;font-size:1px;line-height:1px;">&nbsp;</td>'
    . '<td style="height:12px;font-size:1px;line-height:1px;">&nbsp;</td>'
    . '</tr></table>'
    . '<p style="margin:8px 0 0 0;font-size:14px;font-weight:600;color:#2D7A3A;text-align:right;">{{ params.POURCENTAGE }}%</p>'
    . '</td></tr></table>';

$t3_id = create_template(
    'ADESZ - Appel aux dons',
    '{{ params.TITRE }}',
    wrap_email($t3_inner, 'Abakar Mahamat, President de l\'ADESZ'),
    $api_key,
    $existing
);

// ---------------------------------------------------------------------------
// T4 — Invitation Evenement
// ---------------------------------------------------------------------------
$t4_inner = ''
    . '<p style="margin:0 0 12px 0;font-size:22px;font-weight:700;color:#2D7A3A;">{{ params.TITRE_EVENT }}</p>'
    . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#2D3436;">{{ params.DESCRIPTION }}</p>'

    // Info card
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">'
    . '<tr><td style="padding:20px;background-color:#F8F7F4;border-radius:6px;border-left:4px solid #2D7A3A;">'
    . '<p style="margin:0 0 10px 0;font-size:15px;color:#2D3436;">&#128197; <strong>Date :</strong> {{ params.DATE }}</p>'
    . '<p style="margin:0 0 10px 0;font-size:15px;color:#2D3436;">&#128205; <strong>Lieu :</strong> {{ params.LIEU }}</p>'
    . '<p style="margin:0;font-size:15px;color:#2D3436;">&#128336; <strong>Heure :</strong> {{ params.HEURE }}</p>'
    . '</td></tr></table>';

$t4_id = create_template(
    'ADESZ - Invitation Evenement',
    '{{ params.TITRE_EVENT }}',
    wrap_email($t4_inner),
    $api_key,
    $existing
);

echo "\n";

// ---------------------------------------------------------------------------
// A1 — Anniversaire Adhesion
// ---------------------------------------------------------------------------
echo "--- Creating automated templates ---\n";

$a1_inner = ''
    . '<p style="margin:0 0 8px 0;font-size:22px;font-weight:700;color:#2D7A3A;">Merci pour votre fid&eacute;lit&eacute; !</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">Cher(e) {{ contact.PRENOM }},</p>'
    . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Aujourd\'hui marque l\'anniversaire de votre adh&eacute;sion &agrave; l\'ADESZ. '
    . 'Nous tenions &agrave; vous remercier pour votre engagement &agrave; nos c&ocirc;t&eacute;s. '
    . 'Votre soutien est pr&eacute;cieux et contribue directement &agrave; am&eacute;liorer la vie des communaut&eacute;s au Tchad.'
    . '</p>'
    . '<p style="margin:0 0 12px 0;font-size:15px;font-weight:600;color:#2D7A3A;">Gr&acirc;ce &agrave; vous, cette ann&eacute;e nous avons pu :</p>'
    . '<ul style="margin:0 0 16px 0;padding-left:20px;font-size:15px;line-height:1.8;color:#2D3436;">'
    . '<li>Accompagner des familles dans leurs projets &eacute;ducatifs</li>'
    . '<li>Soutenir des initiatives agricoles et &eacute;conomiques locales</li>'
    . '<li>Renforcer l\'acc&egrave;s aux soins de sant&eacute; dans les zones rurales</li>'
    . '</ul>'
    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Si vous souhaitez aller encore plus loin, un don compl&eacute;mentaire nous aiderait &agrave; &eacute;tendre nos actions. '
    . 'Chaque contribution, quelle que soit sa taille, fait une diff&eacute;rence.'
    . '</p>';

$a1_id = create_template(
    'ADESZ - Anniversaire Adhesion',
    'Merci pour votre fidelite, {{ contact.PRENOM }} !',
    wrap_email($a1_inner, 'Abakar Mahamat, President de l\'ADESZ'),
    $api_key,
    $existing
);

// ---------------------------------------------------------------------------
// A2 — Relance Donateur
// ---------------------------------------------------------------------------
$a2_inner = ''
    . '<p style="margin:0 0 8px 0;font-size:22px;font-weight:700;color:#2D7A3A;">Des nouvelles de l\'ADESZ</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">Cher(e) {{ contact.PRENOM }},</p>'
    . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Il y a quelques mois, vous avez fait un don &agrave; l\'ADESZ. '
    . 'Nous voulions vous donner des nouvelles et vous montrer l\'impact concret de votre g&eacute;n&eacute;rosit&eacute;.'
    . '</p>'
    . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Vos contributions nous ont permis de poursuivre nos projets d\'&eacute;ducation, de sant&eacute; et de d&eacute;veloppement agricole '
    . 'aupr&egrave;s des communaut&eacute;s du Tchad. Chaque don compte et transforme des vies.'
    . '</p>'
    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Nous serions honor&eacute;s de pouvoir compter &agrave; nouveau sur votre soutien. '
    . 'Un nouveau don, m&ecirc;me modeste, nous aiderait &agrave; maintenir la dynamique de nos actions sur le terrain.'
    . '</p>';

$a2_id = create_template(
    'ADESZ - Relance Donateur',
    'Des nouvelles de l\'ADESZ, {{ contact.PRENOM }}',
    wrap_email($a2_inner, 'Abakar Mahamat, President de l\'ADESZ'),
    $api_key,
    $existing
);

// ---------------------------------------------------------------------------
// A4 — Reactivation (A3 was merged into A1 during design — numbering kept for traceability)
// ---------------------------------------------------------------------------
$a4_inner = ''
    . '<p style="margin:0 0 8px 0;font-size:22px;font-weight:700;color:#2D7A3A;">Vous nous manquez</p>'
    . '<p style="margin:0 0 20px 0;font-size:15px;line-height:1.6;color:#2D3436;">Cher(e) {{ contact.PRENOM }},</p>'
    . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Cela fait un moment que nous n\'avons pas eu de vos nouvelles, et vous nous manquez. '
    . 'L\'ADESZ continue son travail aupr&egrave;s des communaut&eacute;s au Tchad, et votre soutien a toujours &eacute;t&eacute; pr&eacute;cieux.'
    . '</p>'
    . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Beaucoup de choses se sont pass&eacute;es depuis votre derni&egrave;re visite : '
    . 'de nouveaux projets &eacute;ducatifs, des avanc&eacute;es dans l\'acc&egrave;s aux soins, '
    . 'et des initiatives agricoles prometteuses.'
    . '</p>'
    . '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;color:#2D3436;">'
    . 'Nous serions ravis de vous retrouver parmi nos soutiens. '
    . 'Visitez notre site pour d&eacute;couvrir nos derni&egrave;res actions, ou faites un don pour nous aider &agrave; continuer.'
    . '</p>';

$a4_id = create_template(
    'ADESZ - Reactivation',
    '{{ contact.PRENOM }}, vous nous manquez',
    wrap_email($a4_inner, 'Abakar Mahamat, President de l\'ADESZ'),
    $api_key,
    $existing
);

echo "\n";

// ---------------------------------------------------------------------------
// Automation workflows — check API availability then print instructions
// ---------------------------------------------------------------------------
echo "--- Automation workflows ---\n";

$automation_result = brevo_request('GET', '/v3/automation/workflows?limit=1', null, $api_key);
if ($automation_result['code'] === 200) {
    echo "  Automation API is accessible (HTTP 200).\n";
} else {
    echo "  Automation API returned HTTP {$automation_result['code']} — workflows must be configured manually.\n";
}

echo "\n";
echo "  Configure the following workflows in Brevo > Automation > Workflows:\n\n";

echo "  W1 — Anniversaire Adhesion\n";
echo "    Trigger:   DATE_ADHESION anniversary (every year)\n";
echo "    List:      Adherents (ID: 3)\n";
echo "    Condition: TYPE = 'adherent' OR TYPE = 'combo'\n";
echo "    Action:    Send template " . ($a1_id !== null ? "\"ADESZ - Anniversaire Adhesion\" (ID: $a1_id)" : '(creation failed)') . "\n";
echo "    Recurrence: Annual\n\n";

echo "  W2 — Relance Donateur (6 mois)\n";
echo "    Trigger:   DATE_DERNIER_PAIEMENT + 180 days\n";
echo "    List:      Donateurs (ID: 4)\n";
echo "    Condition: FREQUENCE = 'one_time'\n";
echo "    Action:    Send template " . ($a2_id !== null ? "\"ADESZ - Relance Donateur\" (ID: $a2_id)" : '(creation failed)') . "\n";
echo "    Recurrence: One-shot (send once per contact)\n\n";

echo "  W3 — Reactivation (18 mois)\n";
echo "    Trigger:   DATE_DERNIER_PAIEMENT + 540 days\n";
echo "    List:      Tous (ID: 5)\n";
echo "    Condition: FREQUENCE = 'one_time' (exclude 'monthly' and 'yearly')\n";
echo "    Action:    Send template " . ($a4_id !== null ? "\"ADESZ - Reactivation\" (ID: $a4_id)" : '(creation failed)') . "\n";
echo "    Recurrence: One-shot (send once per contact)\n\n";

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "=== SETUP COMPLETE ===\n\n";

echo "Template IDs:\n";
$templates_summary = [
    'T1 Newsletter'           => $t1_id,
    'T2 Annonce Projet'       => $t2_id,
    'T3 Appel aux dons'       => $t3_id,
    'T4 Invitation Evenement' => $t4_id,
    'A1 Anniversaire'         => $a1_id,
    'A2 Relance Donateur'     => $a2_id,
    'A4 Reactivation'         => $a4_id,
];

foreach ($templates_summary as $label => $id) {
    if ($id !== null) {
        echo "  $label : ID $id\n";
    } else {
        echo "  $label : FAILED\n";
    }
}

echo "\nUsage:\n";
echo "  - Manual templates (T1-T4): use via Brevo > Campaigns > Create campaign > Select template\n";
echo "  - Fill in the params (TITLE, INTRO, etc.) when creating each campaign\n";
echo "  - Automated templates (A1, A2, A4): configure workflows in Brevo > Automation\n";
echo "  - See workflow instructions above for trigger/condition/action details\n";
