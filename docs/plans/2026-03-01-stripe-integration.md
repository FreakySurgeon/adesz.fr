# Stripe Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add Stripe Checkout payments (one-time & recurring donations + memberships) to the ADESZ static site hosted on OVH shared hosting.

**Architecture:** Static Astro site sends JS fetch() to a PHP endpoint (`/api/create-checkout.php`) on the same OVH server. The PHP script uses cURL to create a Stripe Checkout Session and returns the redirect URL. No SDK, no Composer — pure cURL. Stripe public key is injected at build time via Astro env vars.

**Tech Stack:** Astro 5, Tailwind CSS v4, Stripe Checkout API, PHP cURL, Stripe.js (optional, only for redirect)

**Design doc:** `docs/plans/2026-03-01-stripe-integration-design.md`

---

### Task 1: Create `.env` file with Stripe keys

**Files:**
- Create: `.env`
- Modify: `.gitignore` (verify `.env` is listed — already done)

**Step 1: Create `.env` with all Stripe key placeholders**

```env
# Stripe Test Keys
PUBLIC_STRIPE_KEY_TEST=pk_test_51T6Bxt0CblrKGjqRaEMQflY1bDWy7lPHOS9gplfGwVt4r5b8RFKmwHUnqthPiuBQqmBDBX4mxqHHMULABiq5xQmt0091tqobx9
STRIPE_SECRET_KEY_TEST=sk_test_REPLACE_ME

# Stripe Live Keys (à remplir)
PUBLIC_STRIPE_KEY_LIVE=pk_live_REPLACE_ME
STRIPE_SECRET_KEY_LIVE=sk_live_REPLACE_ME

# Mode: "test" or "live"
STRIPE_MODE=test

# Derived (used by Astro — PUBLIC_ prefix required for client-side access)
PUBLIC_STRIPE_KEY=pk_test_51T6Bxt0CblrKGjqRaEMQflY1bDWy7lPHOS9gplfGwVt4r5b8RFKmwHUnqthPiuBQqmBDBX4mxqHHMULABiq5xQmt0091tqobx9
```

Note: `PUBLIC_` prefix is required by Astro for client-side env vars (`import.meta.env.PUBLIC_STRIPE_KEY`).

**Step 2: Verify `.gitignore` includes `.env`**

Check that `.gitignore` has `.env` and `.env.production` lines. It already does — no change needed.

**Step 3: Commit**

```bash
# Nothing to commit — .env is gitignored.
# Just verify it's not tracked:
git status
```

---

### Task 2: Create PHP backend — `public/api/create-checkout.php`

**Files:**
- Create: `public/api/create-checkout.php`
- Create: `public/api/config.php`

**Step 1: Create `public/api/config.php`**

This file holds the Stripe secret key. It will be deployed to OVH but should NOT contain real keys in the repo — only test keys for development. The live keys will be set manually on OVH.

```php
<?php
// Stripe configuration
// IMPORTANT: On production (OVH), replace with live keys manually
$stripe_secret_key = 'sk_test_REPLACE_ME';
$stripe_mode = 'test'; // 'test' or 'live'

// Site URL for redirects
$site_url = 'https://adesz.fr';
```

**Step 2: Create `public/api/create-checkout.php`**

PHP endpoint that:
1. Reads POST JSON body (`amount`, `frequency`, `type`)
2. Validates inputs
3. Builds Stripe Checkout Session params
4. cURL POST to `https://api.stripe.com/v1/checkout/sessions`
5. Returns `{ "url": "..." }` or `{ "error": "..." }`

Logic:
- `type=don`: single line_item with chosen amount
- `type=adhesion`: single line_item fixed at 1500 (15€)
- `type=combo`: two line_items (adhesion + donation)
- `frequency=one_time` → `mode=payment`
- `frequency=monthly|yearly` → `mode=subscription` with `recurring[interval]`
- For combo: if frequency is not one_time, both items use the same subscription mode. Adhesion uses yearly interval, donation uses chosen interval. Since Stripe subscriptions require all items to share the same billing interval, combo with recurring defaults to yearly for both.

CORS headers: allow `https://adesz.fr` and `http://localhost:4321` (dev).

```php
<?php
require_once __DIR__ . '/config.php';

// CORS
$allowed_origins = ['https://adesz.fr', 'https://www.adesz.fr', 'http://localhost:4321'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$amount = intval($input['amount'] ?? 0); // in cents
$frequency = $input['frequency'] ?? 'one_time';
$type = $input['type'] ?? 'don';

// Validate
if ($type !== 'adhesion' && ($amount < 100 || $amount > 1000000)) {
    http_response_code(400);
    echo json_encode(['error' => 'Montant invalide (min 1€, max 10 000€)']);
    exit;
}
if (!in_array($frequency, ['one_time', 'monthly', 'yearly'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Fréquence invalide']);
    exit;
}
if (!in_array($type, ['don', 'adhesion', 'combo'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Type invalide']);
    exit;
}

// Build line items
$line_items = [];
$is_recurring = $frequency !== 'one_time';

// For adhesion: always yearly if recurring, otherwise one-time
if ($type === 'adhesion' || $type === 'combo') {
    $adhesion_item = [
        'price_data' => [
            'currency' => 'eur',
            'unit_amount' => 1500,
            'product_data' => [
                'name' => 'Adhésion ADESZ',
                'description' => 'Cotisation annuelle'
            ]
        ],
        'quantity' => 1
    ];
    if ($is_recurring) {
        $adhesion_item['price_data']['recurring'] = ['interval' => 'year'];
    }
    $line_items[] = $adhesion_item;
}

if ($type === 'don' || $type === 'combo') {
    $don_item = [
        'price_data' => [
            'currency' => 'eur',
            'unit_amount' => $amount,
            'product_data' => [
                'name' => 'Don ADESZ',
                'description' => $is_recurring
                    ? 'Don ' . ($frequency === 'monthly' ? 'mensuel' : 'annuel')
                    : 'Don ponctuel'
            ]
        ],
        'quantity' => 1
    ];
    if ($is_recurring) {
        $don_item['price_data']['recurring'] = ['interval' => ($frequency === 'monthly' ? 'month' : 'year')];
    }
    $line_items[] = $don_item;
}

// For combo with mixed intervals (donation monthly + adhesion yearly):
// Stripe requires all items in a subscription to have the same interval.
// If combo + monthly: we set adhesion to yearly and donation to monthly — this won't work.
// Solution: for combo, force the donation interval to yearly.
if ($type === 'combo' && $is_recurring) {
    // Override: all items use yearly interval
    foreach ($line_items as &$item) {
        $item['price_data']['recurring'] = ['interval' => 'year'];
    }
    unset($item);
}

// Stripe session mode
$mode = $is_recurring ? 'subscription' : 'payment';

// Build params
$base_url = rtrim($site_url, '/');
$params = [
    'mode' => $mode,
    'success_url' => $base_url . '/merci',
    'cancel_url' => $base_url . '/adherer',
    'locale' => 'fr',
    'payment_method_types[]' => 'card'
];

// Add line items as nested params
foreach ($line_items as $i => $item) {
    $params["line_items[$i][price_data][currency]"] = $item['price_data']['currency'];
    $params["line_items[$i][price_data][unit_amount]"] = $item['price_data']['unit_amount'];
    $params["line_items[$i][price_data][product_data][name]"] = $item['price_data']['product_data']['name'];
    $params["line_items[$i][price_data][product_data][description]"] = $item['price_data']['product_data']['description'];
    if (isset($item['price_data']['recurring'])) {
        $params["line_items[$i][price_data][recurring][interval]"] = $item['price_data']['recurring']['interval'];
    }
    $params["line_items[$i][quantity]"] = $item['quantity'];
}

// cURL to Stripe
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_USERPWD => $stripe_secret_key . ':',
    CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($http_code >= 200 && $http_code < 300 && isset($data['url'])) {
    echo json_encode(['url' => $data['url']]);
} else {
    http_response_code(500);
    $error_msg = $data['error']['message'] ?? 'Erreur lors de la création du paiement';
    echo json_encode(['error' => $error_msg]);
}
```

**Step 3: Test PHP locally (optional)**

```bash
php -S localhost:8080 -t public/
# Then POST to http://localhost:8080/api/create-checkout.php
```

**Step 4: Commit**

```bash
git add public/api/create-checkout.php public/api/config.php
git commit -m "feat: add Stripe Checkout PHP backend (cURL, no SDK)"
```

---

### Task 3: Refactor `src/pages/adherer.astro` — payment form

**Files:**
- Modify: `src/pages/adherer.astro` (full refactor)

This is the main task. The page needs:
1. **3 tabs** at the top: Don | Adhésion (15€) | Don + Adhésion
2. **Amount selector** (for "don" and "combo"): preset buttons 10€/25€/50€/100€ + custom input
3. **Frequency selector**: Ponctuel | Mensuel | Annuel (hidden for "adhesion" tab, forced yearly for combo)
4. **Pay button**: calls `/api/create-checkout.php` via fetch, redirects to Stripe
5. **Loading/error states**: spinner on button, error message display
6. **Keep**: Virement bancaire + Chèque section
7. **Keep**: Déductibilité fiscale section
8. **Remove**: HelloAsso section entirely

**Step 1: Rewrite the page**

Replace the three cards + HelloAsso section with an interactive form. Keep the page header, "autres moyens de paiement" section, and fiscal deductibility section.

The form is client-side JS only (no framework needed — vanilla JS with event listeners). Use `<script>` block at the bottom.

Key UX details:
- Tab switching highlights the active tab
- Amount buttons use radio-button behavior (click to select, visual highlight)
- Custom amount field appears when "Autre" is selected
- Frequency hidden when "adhesion" tab is active
- Button shows "Adhérer — 15€" for adhesion, "Donner XX€" for don, "Adhérer + Donner XX€" for combo
- Button disabled until valid amount selected
- Loading spinner replaces button text during fetch

The JS `<script>` at the bottom:
- Listens for tab clicks, amount clicks, frequency clicks, custom amount input
- Updates UI state (active classes, button text, visibility)
- On form submit: POST to `/api/create-checkout.php`, handle response, redirect or show error

API URL for the fetch: use relative path `/api/create-checkout.php` (works on OVH since PHP is at same domain). For local dev, the Astro dev server won't serve PHP — developer needs to run `php -S localhost:8080 -t public/` alongside.

**Step 2: Verify the page renders**

```bash
npm run dev
# Open http://localhost:4321/adherer
# Verify: tabs work, amounts selectable, frequency toggles, button text updates
```

**Step 3: Commit**

```bash
git add src/pages/adherer.astro
git commit -m "feat: interactive Stripe payment form on /adherer"
```

---

### Task 4: Create `src/pages/merci.astro` — thank you page

**Files:**
- Create: `src/pages/merci.astro`

**Step 1: Create the page**

Simple static page:
- Uses `BaseLayout` with title "Merci ! - ADESZ"
- Green checkmark icon
- "Merci pour votre soutien !" heading
- Paragraph about the contribution
- Fiscal deductibility reminder (66%/60%)
- "Un reçu fiscal vous sera envoyé par email."
- Button "Retour à l'accueil" linking to `/`

Match the existing design language: sand backgrounds, green accents, `reveal` animation classes, DM Serif Display headings, Nunito Sans body.

**Step 2: Verify the page**

```bash
npm run dev
# Open http://localhost:4321/merci
```

**Step 3: Commit**

```bash
git add src/pages/merci.astro
git commit -m "feat: add /merci thank you page for post-payment"
```

---

### Task 5: Update `src/components/DonationCTA.astro`

**Files:**
- Modify: `src/components/DonationCTA.astro`

**Step 1: Update the CTA**

Currently has two buttons: "Faire un don" and "Adhérer pour 15€". Both link to `/adherer` which is correct. No functional change needed — the buttons already point to the right page.

Review and leave as-is unless there's a reason to change the wording. The CTA drives traffic to `/adherer` where the new form lives.

**Step 2: Commit (if changes)**

```bash
# Only commit if something was actually changed
```

---

### Task 6: Build & test full flow

**Files:**
- None (testing only)

**Step 1: Run build**

```bash
npm run build
```

Verify:
- `dist/api/create-checkout.php` exists (copied from `public/api/`)
- `dist/api/config.php` exists
- `dist/adherer/index.html` contains the form
- `dist/merci/index.html` exists

**Step 2: Test the PHP endpoint locally**

```bash
# Terminal 1: serve built site
php -S localhost:8080 -t dist/

# Terminal 2: test the endpoint
curl -X POST http://localhost:8080/api/create-checkout.php \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:4321" \
  -d '{"amount": 2500, "frequency": "one_time", "type": "don"}'
```

Expected: `{"url": "https://checkout.stripe.com/c/pay/cs_test_..."}` (a real Stripe URL if test keys are valid)

**Step 3: Test full flow in browser**

1. Open `http://localhost:8080/adherer/`
2. Select "Faire un don" tab
3. Click "25€"
4. Select "Ponctuel"
5. Click "Donner 25€"
6. Should redirect to Stripe Checkout page
7. Use test card `4242 4242 4242 4242`, any future expiry, any CVC
8. Complete payment
9. Should redirect to `/merci`

**Step 4: Commit any fixes**

```bash
git add -u
git commit -m "fix: adjustments after Stripe integration testing"
```

---

### Task 7: Add `config.php` to `.gitignore` and create deployment note

**Files:**
- Modify: `.gitignore`
- Create: `docs/stripe-deployment.md` (short note)

**Step 1: Update `.gitignore`**

Add `public/api/config.php` to `.gitignore` so production keys are never committed. The file with test keys was committed in Task 2 for convenience, but going forward the production version will be maintained manually on OVH.

Actually, reconsider: since test keys are already in the .env (which is gitignored), we should keep `config.php` in the repo with test keys for development convenience. The deployment workflow copies it as-is. On OVH, the admin manually replaces `config.php` with live keys.

Decision: keep `config.php` in the repo with test keys. Document the deployment process.

**Step 2: Create deployment note**

Short doc explaining:
- After FTP deploy, manually edit `/www/api/config.php` on OVH
- Replace test key with live key
- Set `$stripe_mode = 'live'`
- Update `$site_url` if needed

**Step 3: Commit**

```bash
git add docs/stripe-deployment.md
git commit -m "docs: add Stripe deployment instructions"
```

---

### Task 8: Final verification & push

**Step 1: Run full build one more time**

```bash
npm run build
```

**Step 2: Verify all files**

- `.env` exists locally (not tracked)
- `public/api/create-checkout.php` — PHP endpoint
- `public/api/config.php` — test keys
- `src/pages/adherer.astro` — interactive form
- `src/pages/merci.astro` — thank you page
- `docs/stripe-deployment.md` — deployment notes

**Step 3: Push to staging**

```bash
git push origin staging
```

This triggers `deploy-test.yml` → deploys to `adesz.fr/test/`. Test the full flow on staging with Stripe test keys before merging to main.
