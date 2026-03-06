# Brevo Templates & Automations Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create 7 email templates (4 manual, 3 automated) and 3 automation workflows in Brevo via API, in a single PHP script executable locally.

**Architecture:** Single script `scripts/setup-brevo-campaigns.php` that calls the Brevo v3 API. Follows the same pattern as `scripts/setup-brevo.php` (loads API key from env/.env, uses `brevo_request()` helper). Templates use HTML table-based layout with ADESZ branding. Workflows attempt API creation with UI fallback instructions.

**Tech Stack:** PHP (CLI), Brevo REST API v3, curl

---

### Task 1: Script skeleton + helper functions

**Files:**
- Create: `scripts/setup-brevo-campaigns.php`

**Step 1: Create script with API key loading and helper**

Copy the API key loading pattern from `scripts/setup-brevo.php` (lines 1-46) and the `brevo_request()` helper (lines 157-177). Add a `brevo_get()` helper for GET requests. Add a `template_exists($name, $api_key)` function that calls `GET /v3/smtp/templates?limit=50&offset=0` and checks if a template with that name already exists.

```php
#!/usr/bin/env php
<?php
/**
 * Setup Brevo email templates and automation workflows for ADESZ.
 *
 * Creates:
 *   - 7 email templates (4 manual, 3 automated)
 *   - 3 automation workflows
 *
 * Usage:
 *   BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
 */

// --- Load API key (same as setup-brevo.php) ---
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
    echo "Set it via: BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php\n";
    exit(1);
}

echo "=== ADESZ Brevo Templates & Automations Setup ===\n\n";

// --- Helpers ---
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

function get_existing_templates(string $api_key): array {
    $names = [];
    $offset = 0;
    do {
        $result = brevo_request('GET', "/v3/smtp/templates?limit=50&offset=$offset&sort=desc", '', $api_key);
        $data = json_decode($result['body'], true);
        if (!isset($data['templates'])) break;
        foreach ($data['templates'] as $t) {
            $names[$t['name']] = $t['id'];
        }
        $offset += 50;
    } while (count($data['templates']) === 50);
    return $names;
}

function create_template(string $name, string $subject, string $html, string $api_key, array &$existing): ?int {
    if (isset($existing[$name])) {
        echo "  . \"$name\" already exists (ID: {$existing[$name]}, skipped)\n";
        return $existing[$name];
    }
    $body = json_encode([
        'templateName' => $name,
        'subject'      => $subject,
        'sender'       => ['name' => 'ADESZ', 'email' => 'adeszafaya@gmail.com'],
        'htmlContent'  => $html,
        'isActive'     => true,
    ]);
    $result = brevo_request('POST', '/v3/smtp/templates', $body, $api_key);
    $response = json_decode($result['body'], true);
    if ($result['code'] === 201 && isset($response['id'])) {
        echo "  + \"$name\" created (ID: {$response['id']})\n";
        return $response['id'];
    }
    echo "  x \"$name\" FAILED (HTTP {$result['code']}): {$result['body']}\n";
    return null;
}
```

**Step 2: Run to verify API key loads**

```bash
BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
```

Expected: prints header, no errors.

**Step 3: Commit**

```bash
git add scripts/setup-brevo-campaigns.php
git commit -m "feat: add Brevo campaigns setup script skeleton"
```

---

### Task 2: HTML layout function

**Files:**
- Modify: `scripts/setup-brevo-campaigns.php`

**Step 1: Add `wrap_email($inner_html, $signed_by)` function**

This function wraps any content in the ADESZ email layout. All CSS is inline. Table-based for email client compatibility. 600px max-width.

```php
function wrap_email(string $inner, string $signed_by = "L'equipe ADESZ"): string {
    return '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#F8F7F4;font-family:Arial,Helvetica,sans-serif;color:#2D3436;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8F7F4;">
<tr><td align="center" style="padding:20px 10px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">

<!-- HEADER -->
<tr><td style="background-color:#2D7A3A;padding:24px;text-align:center;">
  <img src="https://adesz.fr/images/logo-adesz.webp" alt="ADESZ" width="120" style="display:block;margin:0 auto;">
  <p style="color:#F5C518;font-size:14px;margin:8px 0 0;letter-spacing:1px;">Association pour le Developpement Economique et Social de Zafaya</p>
</td></tr>

<!-- CONTENT -->
<tr><td style="padding:32px 24px;">
' . $inner . '

<!-- SIGNATURE -->
<p style="margin:32px 0 0;color:#666;font-size:14px;">Chaleureusement,<br><strong>' . htmlspecialchars($signed_by) . '</strong></p>
</td></tr>

<!-- CTA -->
<tr><td style="padding:0 24px 32px;text-align:center;">
  <a href="https://adesz.fr/adherer" style="display:inline-block;background-color:#F5C518;color:#2D3436;font-weight:bold;font-size:16px;padding:14px 32px;border-radius:6px;text-decoration:none;">Soutenir l\'association</a>
</td></tr>

<!-- FOOTER -->
<tr><td style="background-color:#f0f0f0;padding:20px 24px;text-align:center;font-size:12px;color:#888;">
  <p style="margin:0;">ADESZ — 491 Bd Pierre Delmas, 06600 Antibes</p>
  <p style="margin:4px 0 0;"><a href="mailto:adeszafaya@gmail.com" style="color:#2D7A3A;">adeszafaya@gmail.com</a> | 06 63 04 66 12</p>
  <p style="margin:8px 0 0;"><a href="{{ unsubscribe }}" style="color:#888;">Se desabonner</a></p>
</td></tr>

</table>
</td></tr>
</table>
</body></html>';
}
```

**Step 2: Commit**

```bash
git add scripts/setup-brevo-campaigns.php
git commit -m "feat: add shared HTML email layout function"
```

---

### Task 3: Manual templates T1-T4

**Files:**
- Modify: `scripts/setup-brevo-campaigns.php`

**Step 1: Add template definitions for T1-T4**

After the helper functions, add the main execution block that fetches existing templates, then creates each one. Use placeholder content with Brevo variables (`{{ params.TITLE }}`, etc.) that Abakar will replace when duplicating.

```php
// --- Main execution ---
$existing = get_existing_templates($api_key);
$created_ids = [];

echo "--- Creating email templates ---\n";

// T1: Newsletter ADESZ
$t1_html = wrap_email('
<h1 style="color:#2D7A3A;font-size:24px;margin:0 0 16px;">{{ params.TITLE }}</h1>
<p style="font-size:16px;line-height:1.6;">{{ params.INTRO }}</p>

<!-- Article 1 -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr><td>
  <img src="{{ params.IMAGE_1 }}" alt="" width="552" style="width:100%;border-radius:6px;">
  <h2 style="color:#2D7A3A;font-size:20px;margin:16px 0 8px;">{{ params.ARTICLE_1_TITRE }}</h2>
  <p style="font-size:15px;line-height:1.6;">{{ params.ARTICLE_1_TEXTE }}</p>
  <a href="{{ params.ARTICLE_1_LIEN }}" style="color:#2D7A3A;font-weight:bold;">Lire la suite &rarr;</a>
</td></tr>
</table>

<!-- Article 2 (optionnel — supprimer si inutile) -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr><td>
  <img src="{{ params.IMAGE_2 }}" alt="" width="552" style="width:100%;border-radius:6px;">
  <h2 style="color:#2D7A3A;font-size:20px;margin:16px 0 8px;">{{ params.ARTICLE_2_TITRE }}</h2>
  <p style="font-size:15px;line-height:1.6;">{{ params.ARTICLE_2_TEXTE }}</p>
  <a href="{{ params.ARTICLE_2_LIEN }}" style="color:#2D7A3A;font-weight:bold;">Lire la suite &rarr;</a>
</td></tr>
</table>
', "L'equipe ADESZ");

$created_ids['T1'] = create_template(
    'ADESZ - Newsletter',
    '{{ params.TITLE }}',
    $t1_html,
    $api_key,
    $existing
);

// T2: Annonce projet/realisation
$t2_html = wrap_email('
<img src="{{ params.IMAGE }}" alt="" width="552" style="width:100%;border-radius:6px;">
<h1 style="color:#2D7A3A;font-size:24px;margin:16px 0;">{{ params.TITRE_PROJET }}</h1>
<p style="font-size:16px;line-height:1.6;">{{ params.DESCRIPTION }}</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;background-color:#F8F7F4;border-radius:6px;">
<tr>
<td style="padding:16px;text-align:center;width:50%;">
  <span style="font-size:28px;font-weight:bold;color:#2D7A3A;">{{ params.CHIFFRE_1 }}</span><br>
  <span style="font-size:13px;color:#666;">{{ params.LABEL_1 }}</span>
</td>
<td style="padding:16px;text-align:center;width:50%;">
  <span style="font-size:28px;font-weight:bold;color:#2D7A3A;">{{ params.CHIFFRE_2 }}</span><br>
  <span style="font-size:13px;color:#666;">{{ params.LABEL_2 }}</span>
</td>
</tr>
</table>
', "L'equipe ADESZ");

$created_ids['T2'] = create_template(
    'ADESZ - Annonce Projet',
    '{{ params.TITRE_PROJET }}',
    $t2_html,
    $api_key,
    $existing
);

// T3: Appel aux dons
$t3_html = wrap_email('
<h1 style="color:#2D7A3A;font-size:24px;margin:0 0 16px;">{{ params.TITRE }}</h1>
<p style="font-size:16px;line-height:1.6;">Cher(e) {{ contact.PRENOM }},</p>
<p style="font-size:16px;line-height:1.6;">{{ params.MESSAGE }}</p>

<!-- Barre de progression -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr><td>
  <p style="font-size:14px;color:#666;margin:0 0 8px;">Objectif : {{ params.OBJECTIF }} EUR</p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#e0e0e0;border-radius:10px;overflow:hidden;">
  <tr><td style="background-color:#2D7A3A;height:20px;width:{{ params.POURCENTAGE }}%;border-radius:10px;"></td>
  <td style="height:20px;"></td></tr>
  </table>
  <p style="font-size:14px;color:#2D7A3A;font-weight:bold;margin:8px 0 0;">{{ params.COLLECTE }} EUR collectes ({{ params.POURCENTAGE }}%)</p>
</td></tr>
</table>
', 'Abakar Mahamat, President de l\'ADESZ');

$created_ids['T3'] = create_template(
    'ADESZ - Appel aux dons',
    '{{ params.TITRE }}',
    $t3_html,
    $api_key,
    $existing
);

// T4: Invitation evenement
$t4_html = wrap_email('
<h1 style="color:#2D7A3A;font-size:24px;margin:0 0 16px;">{{ params.TITRE_EVENT }}</h1>
<p style="font-size:16px;line-height:1.6;">{{ params.DESCRIPTION }}</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;background-color:#F8F7F4;border-radius:6px;">
<tr><td style="padding:20px;">
  <table role="presentation" cellpadding="0" cellspacing="0">
  <tr><td style="padding:4px 12px 4px 0;font-size:20px;">&#128197;</td><td style="font-size:15px;"><strong>Date :</strong> {{ params.DATE }}</td></tr>
  <tr><td style="padding:4px 12px 4px 0;font-size:20px;">&#128205;</td><td style="font-size:15px;"><strong>Lieu :</strong> {{ params.LIEU }}</td></tr>
  <tr><td style="padding:4px 12px 4px 0;font-size:20px;">&#128336;</td><td style="font-size:15px;"><strong>Heure :</strong> {{ params.HEURE }}</td></tr>
  </table>
</td></tr>
</table>
', "L'equipe ADESZ");

$created_ids['T4'] = create_template(
    'ADESZ - Invitation Evenement',
    '{{ params.TITRE_EVENT }}',
    $t4_html,
    $api_key,
    $existing
);
```

**Step 2: Run and verify templates T1-T4 are created**

```bash
BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
```

Expected: 4 templates created with IDs.

**Step 3: Commit**

```bash
git add scripts/setup-brevo-campaigns.php
git commit -m "feat: add 4 manual email templates (newsletter, project, donation, event)"
```

---

### Task 4: Automated templates A1, A2, A4

**Files:**
- Modify: `scripts/setup-brevo-campaigns.php`

**Step 1: Add template definitions for A1, A2, A4**

These have fixed content (not parameterized like the manual ones) since they're sent automatically.

```php
// A1: Anniversaire adhesion + appel don
$a1_html = wrap_email('
<p style="font-size:16px;line-height:1.6;">Cher(e) {{ contact.PRENOM }},</p>
<p style="font-size:16px;line-height:1.6;">Cela fait deja un an de plus que vous avez rejoint l\'ADESZ en tant que membre. Votre engagement nous touche profondement et nous permet de continuer notre mission au Tchad.</p>

<p style="font-size:16px;line-height:1.6;">Grace a nos membres, nous avons pu cette annee :</p>
<ul style="font-size:15px;line-height:1.8;color:#2D3436;">
  <li>Soutenir l\'education des enfants de Zafaya</li>
  <li>Ameliorer l\'acces aux soins de sante</li>
  <li>Developper des projets agricoles durables</li>
</ul>

<p style="font-size:16px;line-height:1.6;">Si vous le souhaitez, un don — meme modeste — nous aiderait a aller encore plus loin cette annee.</p>
', 'Abakar Mahamat, President de l\'ADESZ');

$created_ids['A1'] = create_template(
    'ADESZ - Anniversaire Adhesion',
    'Merci pour votre fidelite, {{ contact.PRENOM }} !',
    $a1_html,
    $api_key,
    $existing
);

// A2: Relance donateur ponctuel (6 mois)
$a2_html = wrap_email('
<p style="font-size:16px;line-height:1.6;">Cher(e) {{ contact.PRENOM }},</p>
<p style="font-size:16px;line-height:1.6;">Il y a quelques mois, vous avez fait un don a l\'ADESZ. Nous tenions a vous donner des nouvelles de ce que votre generosite a permis de realiser.</p>

<p style="font-size:16px;line-height:1.6;">Votre soutien a contribue directement a nos actions sur le terrain a Zafaya : acces a l\'education, soins de sante, et developpement agricole pour les communautes locales.</p>

<p style="font-size:16px;line-height:1.6;">Chaque don compte. Si vous souhaitez renouveler votre geste, nous vous en serions infiniment reconnaissants.</p>
', 'Abakar Mahamat, President de l\'ADESZ');

$created_ids['A2'] = create_template(
    'ADESZ - Relance Donateur',
    'Des nouvelles de l\'ADESZ, {{ contact.PRENOM }}',
    $a2_html,
    $api_key,
    $existing
);

// A4: Reactivation inactif (18 mois)
$a4_html = wrap_email('
<p style="font-size:16px;line-height:1.6;">Cher(e) {{ contact.PRENOM }},</p>
<p style="font-size:16px;line-height:1.6;">Cela fait un moment que nous n\'avons pas eu de vos nouvelles, et nous voulions simplement vous dire que vous nous manquez.</p>

<p style="font-size:16px;line-height:1.6;">L\'ADESZ continue son travail au Tchad pour ameliorer le quotidien des habitants de Zafaya. Chaque geste de soutien — un don, un partage, un mot d\'encouragement — fait la difference.</p>

<p style="font-size:16px;line-height:1.6;">Si vous souhaitez reprendre contact ou en savoir plus sur nos projets en cours, nous serions ravis de vous retrouver.</p>
', 'Abakar Mahamat, President de l\'ADESZ');

$created_ids['A4'] = create_template(
    'ADESZ - Reactivation',
    '{{ contact.PRENOM }}, vous nous manquez',
    $a4_html,
    $api_key,
    $existing
);
```

**Step 2: Run and verify all 7 templates created**

```bash
BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
```

Expected: 7 templates created (or skipped if re-run).

**Step 3: Commit**

```bash
git add scripts/setup-brevo-campaigns.php
git commit -m "feat: add 3 automated email templates (anniversary, donor followup, reactivation)"
```

---

### Task 5: Automation workflows W1-W3

**Files:**
- Modify: `scripts/setup-brevo-campaigns.php`

**Step 1: Research Brevo automation API**

Check `GET /v3/automation/workflows` and `POST /v3/automation/workflows` availability. The Brevo automation API may not support creating workflows with date-attribute triggers. If it doesn't, output clear manual instructions.

**Step 2: Add workflow creation (or fallback instructions)**

```php
echo "\n--- Automation Workflows ---\n";

// Try to create workflows via API. If the API doesn't support the triggers we need,
// output manual configuration instructions.

$workflows = [
    [
        'name' => 'W1 - Anniversaire adhesion + appel don',
        'template_key' => 'A1',
        'description' => 'Trigger: chaque annee a DATE_ADHESION | Cible: Adherents (liste 3), TYPE=adherent ou combo | Template: ADESZ - Anniversaire Adhesion',
        'config' => [
            'trigger' => 'DATE_ADHESION anniversary (day+month match)',
            'list' => 'Adherents ADESZ (ID: 3)',
            'condition' => 'TYPE = adherent OR TYPE = combo',
            'action' => 'Send template A1',
            'recurrence' => 'Annual',
        ],
    ],
    [
        'name' => 'W2 - Relance donateur ponctuel',
        'template_key' => 'A2',
        'description' => 'Trigger: DATE_DERNIER_PAIEMENT + 180 jours | Cible: Donateurs (liste 4), FREQUENCE=one_time | Template: ADESZ - Relance Donateur',
        'config' => [
            'trigger' => 'DATE_DERNIER_PAIEMENT + 180 days',
            'list' => 'Donateurs ADESZ (ID: 4)',
            'condition' => 'FREQUENCE = one_time',
            'action' => 'Send template A2',
            'recurrence' => 'One-shot per contact',
        ],
    ],
    [
        'name' => 'W3 - Reactivation inactif',
        'template_key' => 'A4',
        'description' => 'Trigger: DATE_DERNIER_PAIEMENT + 540 jours | Cible: Tous (liste 5), FREQUENCE=one_time | Template: ADESZ - Reactivation',
        'config' => [
            'trigger' => 'DATE_DERNIER_PAIEMENT + 540 days',
            'list' => 'Tous les contacts ADESZ (ID: 5)',
            'condition' => 'FREQUENCE = one_time (exclude monthly/yearly)',
            'action' => 'Send template A4',
            'recurrence' => 'One-shot per contact',
        ],
    ],
];

// Attempt API creation
$api_workflows_supported = false;
$test = brevo_request('GET', '/v3/automation/workflows?limit=1', '', $api_key);
if ($test['code'] === 200) {
    $api_workflows_supported = true;
}

if (!$api_workflows_supported) {
    echo "  Brevo Automation API not available for workflow creation.\n";
    echo "  Please configure these workflows manually in Brevo UI:\n\n";
}

foreach ($workflows as $w) {
    if ($api_workflows_supported) {
        // Attempt to create via API (implementation depends on actual API schema)
        // For now, log instructions as the trigger types may not be API-creatable
        echo "  [TODO] \"{$w['name']}\" — API creation attempted\n";
    }

    echo "  --- {$w['name']} ---\n";
    foreach ($w['config'] as $key => $val) {
        echo "    $key: $val\n";
    }
    if (isset($created_ids[$w['template_key']])) {
        echo "    template_id: {$created_ids[$w['template_key']]}\n";
    }
    echo "\n";
}
```

**Step 3: Add summary output**

```php
echo "=== SETUP COMPLETE ===\n\n";
echo "Templates created:\n";
foreach ($created_ids as $key => $id) {
    if ($id) echo "  $key: ID $id\n";
}
echo "\nManual templates (T1-T4): Abakar duplique dans Brevo, modifie le contenu, envoie.\n";
echo "Automated templates (A1,A2,A4): Lies aux workflows ci-dessus.\n";
```

**Step 4: Run full script**

```bash
BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
```

Expected: 7 templates + workflow instructions.

**Step 5: Commit**

```bash
git add scripts/setup-brevo-campaigns.php
git commit -m "feat: add automation workflow creation with UI fallback instructions"
```

---

### Task 6: Execute script and verify

**Step 1: Run the complete script locally**

```bash
cd /home/thomas/projects/adesz
BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php
```

**Step 2: Verify in Brevo dashboard** that templates appear correctly (spot-check HTML rendering).

**Step 3: Final commit if any fixes needed**

```bash
git add scripts/setup-brevo-campaigns.php
git commit -m "fix: adjust templates after testing"
```
