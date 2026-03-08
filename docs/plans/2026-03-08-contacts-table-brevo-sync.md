# Contacts Table + Brevo Sync Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a `contacts` table synced from Brevo for autocomplétion, and sync manual donations back to Brevo.

**Architecture:** New `contacts` table (email as primary key) populated via Brevo GET API (paginated). Autocomplétion queries `contacts` first, falls back to `donations` DISTINCT. Manual donation save upserts contact to Brevo + updates local `contacts` table. Sync script runs as admin button or cron.

**Tech Stack:** PHP 8.x, MySQL (OVH), Brevo REST API v3, existing admin UI

---

### Task 1: Create `contacts` table in MySQL

**Files:**
- Modify: `scripts/setup-donations-table.php` (add contacts table)
- Modify: `public/api/db.php` (add contacts CRUD functions)

**Step 1: Add contacts table DDL to setup script**

Add after receipt_counters creation in `scripts/setup-donations-table.php`:

```php
$db->exec("
    CREATE TABLE IF NOT EXISTS contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NULL,
        prenom VARCHAR(255) NOT NULL DEFAULT '',
        nom VARCHAR(255) NOT NULL DEFAULT '',
        adresse VARCHAR(500) NOT NULL DEFAULT '',
        cp VARCHAR(10) NOT NULL DEFAULT '',
        commune VARCHAR(255) NOT NULL DEFAULT '',
        telephone VARCHAR(50) NOT NULL DEFAULT '',
        type VARCHAR(50) NOT NULL DEFAULT '',
        source VARCHAR(50) NOT NULL DEFAULT 'brevo',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE INDEX uq_email (email),
        INDEX idx_nom (nom, prenom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
// Note: email is UNIQUE but NULLable — contacts sans email are allowed
// MySQL allows multiple NULLs in a UNIQUE index
echo "OK: contacts table created (or already exists).\n";
```

**Step 2: Add contacts helper functions to `public/api/db.php`**

```php
function upsert_contact(array $data): void {
    $sql = "INSERT INTO contacts (email, prenom, nom, adresse, cp, commune, telephone, type, source)
            VALUES (:email, :prenom, :nom, :adresse, :cp, :commune, :telephone, :type, :source)
            ON DUPLICATE KEY UPDATE
                prenom = IF(:prenom2 != '', :prenom3, prenom),
                nom = IF(:nom2 != '', :nom3, nom),
                adresse = IF(:adresse2 != '', :adresse3, adresse),
                cp = IF(:cp2 != '', :cp3, cp),
                commune = IF(:commune2 != '', :commune3, commune),
                telephone = IF(:telephone2 != '', :telephone3, telephone),
                type = IF(:type2 != '', :type3, type),
                source = :source2";
    // Note: only overwrite non-empty fields to avoid data loss
    $stmt = get_db()->prepare($sql);
    $stmt->execute([
        ':email' => $data['email'],
        ':prenom' => $data['prenom'] ?? '', ':prenom2' => $data['prenom'] ?? '', ':prenom3' => $data['prenom'] ?? '',
        ':nom' => $data['nom'] ?? '', ':nom2' => $data['nom'] ?? '', ':nom3' => $data['nom'] ?? '',
        ':adresse' => $data['adresse'] ?? '', ':adresse2' => $data['adresse'] ?? '', ':adresse3' => $data['adresse'] ?? '',
        ':cp' => $data['cp'] ?? '', ':cp2' => $data['cp'] ?? '', ':cp3' => $data['cp'] ?? '',
        ':commune' => $data['commune'] ?? '', ':commune2' => $data['commune'] ?? '', ':commune3' => $data['commune'] ?? '',
        ':telephone' => $data['telephone'] ?? '', ':telephone2' => $data['telephone'] ?? '', ':telephone3' => $data['telephone'] ?? '',
        ':type' => $data['type'] ?? '', ':type2' => $data['type'] ?? '', ':type3' => $data['type'] ?? '',
        ':source' => $data['source'] ?? 'manual', ':source2' => $data['source'] ?? 'manual',
    ]);
}

function search_contacts(string $query, int $limit = 10): array {
    $like = '%' . $query . '%';
    $sql = "SELECT email, prenom, nom, adresse, cp, commune, telephone
            FROM contacts
            WHERE nom LIKE ? COLLATE utf8mb4_unicode_ci
               OR prenom LIKE ? COLLATE utf8mb4_unicode_ci
               OR email LIKE ? COLLATE utf8mb4_unicode_ci
               OR CONCAT(nom, ' ', prenom) LIKE ? COLLATE utf8mb4_unicode_ci
            ORDER BY nom, prenom
            LIMIT ?";
    $stmt = get_db()->prepare($sql);
    $stmt->bindValue(1, $like);
    $stmt->bindValue(2, $like);
    $stmt->bindValue(3, $like);
    $stmt->bindValue(4, $like);
    $stmt->bindValue(5, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
```

**Step 3: Deploy contacts table creation**

Create a temporary `public/api/admin/setup-contacts.php` (ADMIN_KEY auth, like the import script) that creates the table. Execute via URL, then delete.

**Step 4: Commit**

```bash
git add scripts/setup-donations-table.php public/api/db.php
git commit -m "feat: contacts table + upsert/search helpers"
```

---

### Task 2: Brevo → contacts import script

**Files:**
- Create: `public/api/admin/api-sync-brevo.php`

This endpoint fetches all contacts from Brevo (paginated, GET /v3/contacts with limit/offset) and upserts them into the `contacts` table. Protected by WordPress auth.

**Step 1: Create the sync endpoint**

```php
<?php
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
```

**Step 2: Commit**

```bash
git add public/api/admin/api-sync-brevo.php
git commit -m "feat: Brevo → contacts sync endpoint"
```

---

### Task 3: Update autocomplétion to use contacts table

**Files:**
- Modify: `public/api/admin/api-search.php`

**Step 1: Update search to query contacts first, then donations**

Replace the current `api-search.php` with:

```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Search contacts table first (Brevo-synced), then donations as fallback
$results = search_contacts($q, 10);

// Also search donations for donors not in contacts table
$donation_donors = search_donors($q, 10);
$existing_emails = array_column($results, 'email');
foreach ($donation_donors as $d) {
    if (!in_array($d['email'], $existing_emails, true)) {
        $results[] = $d;
    }
}

// Limit total results
echo json_encode(array_slice($results, 0, 10));
```

**Step 2: Commit**

```bash
git add public/api/admin/api-search.php
git commit -m "feat: autocomplete searches contacts table + donations fallback"
```

---

### Task 4: Manual donation save → Brevo upsert + contacts update

**Files:**
- Modify: `public/api/admin/api-save.php`

**Step 1: After saving donation, upsert contact locally and sync to Brevo**

Add at end of `api-save.php`, after the successful insert/update block, before `echo json_encode(...)`:

```php
// Upsert local contact
$contact_email = trim($donation['email'] ?? '');
if ($contact_email) {
    upsert_contact([
        'email'     => $contact_email,
        'prenom'    => $donation['prenom'] ?? '',
        'nom'       => $donation['nom'] ?? '',
        'adresse'   => $donation['adresse'] ?? '',
        'cp'        => $donation['cp'] ?? '',
        'commune'   => $donation['commune'] ?? '',
        'telephone' => $donation['telephone'] ?? '',
        'type'      => $donation['type'] ?? '',
        'source'    => 'manual',
    ]);

    // Sync to Brevo (best-effort, don't fail the save)
    try {
        sync_manual_donation_to_brevo($donation);
    } catch (Throwable $e) {
        error_log('Brevo sync failed for manual donation: ' . $e->getMessage());
    }
}
```

**Step 2: Add `sync_manual_donation_to_brevo` function to a shared file**

Add to `public/api/db.php` (or better: create a small `public/api/brevo-sync.php` helper):

```php
// public/api/brevo-sync.php
<?php

function sync_manual_donation_to_brevo(array $donation): void {
    global $brevo_api_key, $brevo_list_donateurs, $brevo_list_adherents, $brevo_list_tous;

    $email = trim($donation['email'] ?? '');
    if (empty($email) || empty($brevo_api_key) || $brevo_api_key === 'xkeysib-REPLACE_ME') return;

    $attributes = [];
    if (!empty($donation['prenom']))  $attributes['PRENOM'] = $donation['prenom'];
    if (!empty($donation['nom']))     $attributes['NOM'] = $donation['nom'];
    if (!empty($donation['adresse'])) $attributes['ADRESSE'] = $donation['adresse'];
    if (!empty($donation['cp']))      $attributes['CODE_POSTAL'] = $donation['cp'];
    if (!empty($donation['commune'])) $attributes['COMMUNE'] = $donation['commune'];
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
```

**Step 3: Commit**

```bash
git add public/api/brevo-sync.php public/api/admin/api-save.php
git commit -m "feat: manual donations sync to Brevo + upsert local contacts"
```

---

### Task 5: Admin UI — "Sync Brevo" button

**Files:**
- Modify: `public/api/admin/index.php` (add sync button in header or settings area)

**Step 1: Add a sync button at the top of the admin page**

In the tab bar area or as a small utility button, add:

```html
<button class="btn btn-outline" id="btn-sync-brevo" style="padding:6px 14px; font-size:13px;"
    title="Importer les contacts depuis Brevo">⟳ Sync Brevo</button>
```

**Step 2: Add JS handler**

```javascript
document.getElementById('btn-sync-brevo').addEventListener('click', function() {
    var btn = this;
    if (!confirm('Importer/mettre à jour les contacts depuis Brevo ?')) return;
    var origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sync en cours…';

    fetch('api-sync-brevo.php', { method: 'POST' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                btn.textContent = data.imported + ' contacts ✓';
                setTimeout(function() { btn.textContent = origText; }, 3000);
            } else {
                btn.textContent = origText;
                alert(data.error || 'Erreur sync');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Erreur de connexion.');
        });
});
```

**Step 3: Commit**

```bash
git add public/api/admin/index.php
git commit -m "feat: admin UI — Sync Brevo button"
```

---

### Task 6: Deploy — create contacts table in production

**Files:**
- Create: `public/api/admin/setup-contacts.php` (temporary)

**Step 1: Create temp setup script (ADMIN_KEY auth)**

Same pattern as the import script — requires `?key=ADMIN_KEY`, creates the contacts table, shows result.

**Step 2: Deploy, execute, delete**

Push to main+staging, execute via URL, then remove and push again.

**Step 3: Run initial Brevo sync**

Click the "Sync Brevo" button in admin to populate contacts from Brevo (~400 contacts).

**Step 4: Commit cleanup**

```bash
git rm public/api/admin/setup-contacts.php
git commit -m "chore: remove temporary setup-contacts.php after table creation"
```

---

## Summary of data flow

```
Brevo (400 contacts)
    │
    ▼ [Sync Brevo button / periodic]
contacts table (email PK)
    │
    ▼ [autocomplete search]
Admin form → "Saisir un don"
    │
    ▼ [save]
donations table + upsert contacts + upsert Brevo
```

## Simplification note

The `upsert_contact` function uses `IF(:val != '', :val, existing)` pattern — non-empty values from any source overwrite, empty values preserve existing data. This prevents Brevo imports (which may lack address data) from wiping out manually entered info.
