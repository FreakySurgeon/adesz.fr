# Receipt Counter MySQL + Manual Receipt Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Migrate receipt numbering from JSON file to MySQL for robustness, and add unit receipt generation (preview + email + PDF download) for manually entered donations.

**Architecture:** Receipt counters move to a `receipt_counters` MySQL table with atomic INSERT ON DUPLICATE KEY UPDATE. The admin donation form gets a post-save confirmation panel with HTML receipt preview, send/download/edit actions. Reuses existing `generate_receipt_pdf()` and Brevo email sending from `stripe-webhook.php`.

**Tech Stack:** PHP 8 + PDO MySQL, tFPDF, Brevo API, vanilla JS

---

### Task 1: MySQL receipt_counters table + get_next_counter()

**Files:**
- Modify: `public/api/db.php` (add `get_next_counter()`, `get_donation_by_id()`, `update_donation()`)
- Modify: `scripts/setup-donations-table.php` (add receipt_counters table + UNIQUE indexes)

**Step 1: Add `receipt_counters` table creation to setup script**

In `scripts/setup-donations-table.php`, after the donations table creation, add:

```php
$db->exec("
    CREATE TABLE IF NOT EXISTS receipt_counters (
        year_key VARCHAR(20) PRIMARY KEY,
        counter INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK: receipt_counters table created (or already exists).\n";
```

Also add UNIQUE indexes on donations:

```php
// Add UNIQUE indexes if they don't exist (safe to re-run)
try {
    $db->exec("ALTER TABLE donations ADD UNIQUE INDEX uq_receipt_number (receipt_number)");
    echo "OK: UNIQUE index on receipt_number added.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "OK: UNIQUE index on receipt_number already exists.\n";
    } else {
        throw $e;
    }
}
try {
    $db->exec("ALTER TABLE donations ADD UNIQUE INDEX uq_annual_receipt_number (annual_receipt_number)");
    echo "OK: UNIQUE index on annual_receipt_number added.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "OK: UNIQUE index on annual_receipt_number already exists.\n";
    } else {
        throw $e;
    }
}
```

**Step 2: Add helper functions to `db.php`**

Append to `public/api/db.php`:

```php
function get_next_counter(string $year_key): int {
    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO receipt_counters (year_key, counter) VALUES (?, 1)
                              ON DUPLICATE KEY UPDATE counter = counter + 1");
        $stmt->execute([$year_key]);
        $stmt = $db->prepare("SELECT counter FROM receipt_counters WHERE year_key = ?");
        $stmt->execute([$year_key]);
        $counter = (int) $stmt->fetchColumn();
        $db->commit();
        return $counter;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function get_donation_by_id(int $id): ?array {
    $stmt = get_db()->prepare("SELECT * FROM donations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function update_donation(int $id, array $data): void {
    $allowed = ['email', 'prenom', 'nom', 'adresse', 'cp', 'commune',
                'amount', 'date_don', 'type', 'mode_paiement', 'receipt_number', 'annual_receipt_number'];
    $sets = [];
    $values = [];
    foreach ($data as $col => $val) {
        if (in_array($col, $allowed, true)) {
            $sets[] = "$col = ?";
            $values[] = $val;
        }
    }
    if (empty($sets)) return;
    $values[] = $id;
    $sql = 'UPDATE donations SET ' . implode(', ', $sets) . ' WHERE id = ?';
    get_db()->prepare($sql)->execute($values);
}
```

**Step 3: Commit**

```bash
git add public/api/db.php scripts/setup-donations-table.php
git commit -m "feat: add MySQL receipt counter + donation helpers"
```

---

### Task 2: Migrate receipt numbering to MySQL

**Files:**
- Modify: `public/api/generate-receipt.php:15-44` (replace `get_next_receipt_number()`)
- Modify: `public/api/generate-annual-receipt.php:18-47` (replace `get_next_annual_receipt_number()`)

**Step 1: Replace `get_next_receipt_number()` in `generate-receipt.php`**

Replace the entire function (lines 15-44) with:

```php
function get_next_receipt_number(): string {
    require_once __DIR__ . '/db.php';
    $year = date('Y');
    try {
        $counter = get_next_counter($year);
        return sprintf('ADESZ-%s-%03d', $year, $counter);
    } catch (Throwable $e) {
        error_log('Receipt counter error: ' . $e->getMessage());
        return 'ADESZ-' . $year . '-ERR';
    }
}
```

**Step 2: Replace `get_next_annual_receipt_number()` in `generate-annual-receipt.php`**

Replace the entire function (lines 18-47) with:

```php
function get_next_annual_receipt_number(int $year): string {
    require_once __DIR__ . '/db.php';
    try {
        $counter = get_next_counter('annual_' . $year);
        return sprintf('ADESZ-ANN-%d-%03d', $year, $counter);
    } catch (Throwable $e) {
        error_log('Annual receipt counter error: ' . $e->getMessage());
        return 'ADESZ-ANN-' . $year . '-ERR';
    }
}
```

**Step 3: Commit**

```bash
git add public/api/generate-receipt.php public/api/generate-annual-receipt.php
git commit -m "feat: migrate receipt numbering from JSON to MySQL"
```

---

### Task 3: Update api-save.php for update mode + return full data

**Files:**
- Modify: `public/api/admin/api-save.php`

**Step 1: Replace api-save.php content**

The endpoint should:
- Accept optional `id` field — if present, UPDATE instead of INSERT
- Return the full donation row in the response (needed for confirmation panel)

```php
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

try {
    $edit_id = isset($input['id']) ? (int) $input['id'] : 0;

    if ($edit_id > 0) {
        // UPDATE mode — only allow editing manual donations without receipt
        $existing = get_donation_by_id($edit_id);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Don introuvable']);
            exit;
        }
        if ($existing['receipt_number']) {
            http_response_code(400);
            echo json_encode(['error' => 'Impossible de modifier un don avec reçu déjà généré']);
            exit;
        }
        $update_fields = [];
        foreach (['prenom', 'nom', 'email', 'adresse', 'cp', 'commune', 'amount', 'date_don', 'type', 'mode_paiement'] as $f) {
            if (array_key_exists($f, $input)) {
                $update_fields[$f] = $input[$f];
            }
        }
        // Map code_postal → cp
        if (isset($input['code_postal'])) {
            $update_fields['cp'] = $input['code_postal'];
        }
        update_donation($edit_id, $update_fields);
        $donation = get_donation_by_id($edit_id);
    } else {
        // INSERT mode
        $input['source'] = 'manual';
        // Map code_postal → cp for insert
        if (isset($input['code_postal'])) {
            $input['cp'] = $input['code_postal'];
            unset($input['code_postal']);
        }
        $id = insert_donation($input);
        $donation = get_donation_by_id($id);
    }

    echo json_encode(['success' => true, 'donation' => $donation]);
} catch (Throwable $e) {
    error_log('Admin save donation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
```

**Step 2: Commit**

```bash
git add public/api/admin/api-save.php
git commit -m "feat: api-save supports update mode + returns full donation"
```

---

### Task 4: Create api-receipt.php endpoint

**Files:**
- Create: `public/api/admin/api-receipt.php`

**Step 1: Create the endpoint**

This endpoint handles two actions:
- `GET ?donation_id=X&action=download` → generate PDF, return as download
- `POST {donation_id, action: "send"}` → generate PDF + send via Brevo email

```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../generate-receipt.php';

/**
 * Get or generate receipt for a donation.
 * If receipt_number already exists, regenerate PDF with same number.
 * If not, generate new number and save to DB.
 */
function get_or_generate_receipt(array $donation): array {
    $date_obj = DateTime::createFromFormat('Y-m-d', $donation['date_don']);
    $date_fmt = $date_obj ? $date_obj->format('d/m/Y') : $donation['date_don'];

    $data = [
        'email'   => $donation['email'] ?? '',
        'prenom'  => $donation['prenom'] ?? '',
        'nom'     => $donation['nom'] ?? '',
        'adresse' => $donation['adresse'] ?? '',
        'cp'      => $donation['cp'] ?? '',
        'commune' => $donation['commune'] ?? '',
        'amount'  => (float) $donation['amount'],
        'date'    => $date_fmt,
        'type'    => $donation['type'] ?? 'don',
    ];

    // If receipt already generated, we still regenerate the PDF but keep same number
    if (!empty($donation['receipt_number'])) {
        $data['receipt_number_override'] = $donation['receipt_number'];
    }

    $result = generate_receipt_pdf($data);
    if (!$result) {
        throw new RuntimeException('PDF generation failed');
    }

    // Save receipt_number to DB if first time
    if (empty($donation['receipt_number'])) {
        update_donation((int) $donation['id'], ['receipt_number' => $result['number']]);
    }

    return $result;
}

// --- Handle GET (download) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download') {
    $donation_id = (int) ($_GET['donation_id'] ?? 0);
    if ($donation_id <= 0) {
        http_response_code(400);
        echo 'Missing donation_id';
        exit;
    }

    $donation = get_donation_by_id($donation_id);
    if (!$donation) {
        http_response_code(404);
        echo 'Donation not found';
        exit;
    }

    try {
        $result = get_or_generate_receipt($donation);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Content-Length: ' . strlen($result['content']));
        echo $result['content'];
    } catch (Throwable $e) {
        error_log('Receipt download error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Erreur generation PDF';
    }
    exit;
}

// --- Handle POST (send email) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    $donation_id = (int) ($input['donation_id'] ?? 0);
    $action = $input['action'] ?? '';

    if ($donation_id <= 0 || $action !== 'send') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $donation = get_donation_by_id($donation_id);
    if (!$donation) {
        http_response_code(404);
        echo json_encode(['error' => 'Donation not found']);
        exit;
    }

    if (empty($donation['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Pas d\'email pour ce donateur']);
        exit;
    }

    try {
        global $brevo_api_key;
        $result = get_or_generate_receipt($donation);

        $donor_name = trim(($donation['prenom'] ?? '') . ' ' . ($donation['nom'] ?? ''));
        $amount = (float) $donation['amount'];
        $deduction = number_format($amount * 0.66, 2, ',', ' ');
        $amount_fmt = number_format($amount, 2, ',', ' ');

        $email_body = [
            'sender'  => ['name' => 'ADESZ', 'email' => 'adeszafaya@gmail.com'],
            'to'      => [['email' => $donation['email'], 'name' => $donor_name ?: $donation['email']]],
            'subject' => 'Votre reçu fiscal ADESZ - ' . $result['number'],
            'htmlContent' => '<html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;color:#2D3436;margin:0;padding:0;">'
                . '<div style="background:#2D7A3A;padding:30px 20px;text-align:center;">'
                . '<h1 style="color:white;margin:0;font-size:28px;">ADESZ</h1>'
                . '<p style="color:#F5C518;margin:8px 0 0;font-size:16px;">Merci pour votre g&eacute;n&eacute;rosit&eacute; !</p>'
                . '</div>'
                . '<div style="padding:30px 40px;max-width:600px;margin:0 auto;">'
                . '<p style="font-size:16px;">Bonjour ' . htmlspecialchars($donor_name ?: 'cher donateur', ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p style="font-size:15px;line-height:1.6;">Nous vous remercions chaleureusement pour votre don de <strong>'
                . $amount_fmt . ' EUR</strong> en faveur de l\'ADESZ.</p>'
                . '<p style="font-size:15px;line-height:1.6;">Vous trouverez en pi&egrave;ce jointe votre re&ccedil;u fiscal <strong>'
                . $result['number'] . '</strong>.</p>'
                . '<div style="background:#FFFBEB;border-left:4px solid #F5C518;padding:15px 20px;margin:20px 0;border-radius:4px;">'
                . '<p style="margin:0;font-size:14px;"><strong>Avantage fiscal :</strong> ce don vous ouvre droit &agrave; une r&eacute;duction '
                . 'd\'imp&ocirc;t de <strong>' . $deduction . ' EUR</strong> (66% du montant, art. 200 du CGI).</p>'
                . '</div>'
                . '<p style="font-size:15px;line-height:1.6;">Votre soutien nous aide &agrave; poursuivre nos actions pour le '
                . 'd&eacute;veloppement de Zafaya au Tchad.</p>'
                . '<p style="font-size:15px;margin-top:25px;">Cordialement,<br>'
                . '<strong>Abakar Mahamat</strong><br>'
                . '<span style="color:#666;">Pr&eacute;sident de l\'ADESZ</span></p>'
                . '</div>'
                . '<div style="background:#F8F7F4;padding:20px;text-align:center;font-size:12px;color:#888;">'
                . 'ADESZ | 491 Bd Pierre Delmas, 06600 Antibes | <a href="https://adesz.fr" style="color:#2D7A3A;">www.adesz.fr</a>'
                . '</div>'
                . '</body></html>',
            'attachment' => [
                [
                    'content' => base64_encode($result['content']),
                    'name'    => $result['filename'],
                ],
            ],
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . $brevo_api_key,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($email_body),
            CURLOPT_TIMEOUT    => 30,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            echo json_encode(['success' => true, 'receipt_number' => $result['number'],
                              'message' => 'Reçu ' . $result['number'] . ' envoyé à ' . $donation['email']]);
        } else {
            error_log('Brevo email error: HTTP ' . $http_code . ' — ' . $response);
            echo json_encode(['success' => false, 'error' => 'Erreur envoi email (code ' . $http_code . '). PDF généré: ' . $result['number']]);
        }
    } catch (Throwable $e) {
        error_log('Receipt send error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
```

**Step 2: Modify `generate-receipt.php` to support `receipt_number_override`**

In `generate_receipt_pdf()`, change line 132 from:
```php
$receipt_number = get_next_receipt_number();
```
to:
```php
$receipt_number = $data['receipt_number_override'] ?? get_next_receipt_number();
```

**Step 3: Commit**

```bash
git add public/api/admin/api-receipt.php public/api/generate-receipt.php
git commit -m "feat: add receipt generation/send endpoint for manual donations"
```

---

### Task 5: Admin UI — confirmation panel + receipt preview + edit mode

**Files:**
- Modify: `public/api/admin/index.php`

**Step 1: Add confirmation panel HTML**

After the `</form>` closing tag inside tab-donation (after line ~367), add:

```html
<!-- Confirmation panel (hidden by default) -->
<div id="confirm-panel" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h2 style="color:#1B5E27; font-size:17px; margin:0;">Don enregistr&eacute;</h2>
        <button class="btn btn-outline" id="btn-new-donation" style="padding:6px 16px;">Nouveau don</button>
    </div>

    <!-- Summary -->
    <div id="confirm-summary" style="background:#e8f5e9; padding:14px 18px; border-radius:5px; margin-bottom:16px; font-size:14px;"></div>

    <!-- Receipt preview (HTML mockup) -->
    <div id="receipt-preview" style="border:1px solid #ddd; border-radius:5px; padding:0; margin-bottom:16px; overflow:hidden;"></div>

    <!-- Action buttons -->
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn btn-primary" id="btn-send-receipt" style="display:none;">Envoyer le re&ccedil;u par email</button>
        <button class="btn btn-outline" id="btn-download-receipt">T&eacute;l&eacute;charger PDF</button>
        <button class="btn btn-yellow" id="btn-edit-donation">Modifier</button>
    </div>

    <div class="msg msg-success" id="receipt-success"></div>
    <div class="msg msg-error" id="receipt-error"></div>
    <div class="msg msg-info" id="receipt-loading" style="display:none;">Envoi en cours&hellip;</div>
</div>
```

**Step 2: Add JS for confirmation panel, receipt preview, edit mode**

In the JS section, replace the form submit handler and add new functions. The key behaviors:

- After successful save: hide form, show confirm panel with summary + HTML receipt preview
- "Envoyer" → POST api-receipt.php with send action
- "Télécharger" → window.open to api-receipt.php with download action
- "Modifier" → hide confirm panel, show form pre-filled, set editId so next submit does UPDATE
- "Nouveau don" → hide confirm panel, show form reset

The HTML receipt preview renders inline using the same visual style as the PDF (green header, donor info, amount box, yellow tax deduction box).

**Step 3: Commit**

```bash
git add public/api/admin/index.php
git commit -m "feat: admin confirmation panel with receipt preview/send/download/edit"
```

---

### Task 6: Deploy — setup receipt_counters table in production

**Files:**
- Create (temporary): `public/api/setup-db.php` (admin-key-protected, creates receipt_counters + UNIQUE indexes)

**Step 1: Create temporary setup script**

Same pattern as before — admin key protection, accessible via URL, creates the table and indexes.

**Step 2: Deploy via GitHub Actions, run setup URL**

**Step 3: Remove temporary setup script, commit**

```bash
git rm public/api/setup-db.php
git commit -m "chore: remove temporary setup script"
```

---

Plan complete and saved to `docs/plans/2026-03-08-receipt-counter-mysql-manual-receipt.md`. Two execution options:

**1. Subagent-Driven (this session)** — I dispatch fresh subagent per task, review between tasks, fast iteration

**2. Parallel Session (separate)** — Open new session with executing-plans, batch execution with checkpoints

Which approach?