# ADESZ: Brevo Imports, Image Optimization & Tax Receipts

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Import existing contacts into Brevo, create newsletter list, optimize heavy images, and set up automatic tax receipt PDF generation via Stripe webhook.

**Architecture:** Tasks 1-2 are one-shot scripts (curl/Python) hitting Brevo API. Task 3 uses Python Pillow to resize/convert images, then updates Astro source references. Task 4 adds TCPDF-based PDF generation to the existing Stripe webhook PHP code, sending receipts via Brevo transactional email.

**Tech Stack:** Brevo REST API, Python 3 + Pillow, PHP + TCPDF, Astro 5, Stripe webhooks

---

## Task 1: Import 3 CSV files into Brevo via API

**Files:**
- Read: `brevo-import/brevo-import-tous.csv` (336 contacts)
- Read: `brevo-import/brevo-import-adherents.csv` (246 contacts)
- Read: `brevo-import/brevo-import-donateurs.csv` (152 contacts)
- Create: `scripts/import-brevo.sh`

**Context:**
- CSV separator: semicolon (`;`)
- CSV header: `EMAIL;PRENOM;NOM;ADRESSE;CODE_POSTAL;COMMUNE;TYPE;MONTANT`
- First line has BOM (`\uFEFF`) — must be stripped
- Brevo lists: Tous (5), Adherents (3), Donateurs (4)
- Brevo API key: fetch from GitHub secrets via `gh secret list` or use env var

### Step 1: Get the Brevo API key

```bash
# The key is already configured as a GitHub secret.
# Ask the user for the key, or check if there's a .env file.
# For this script, we'll use an env var: BREVO_API_KEY
```

### Step 2: Create the import script

Create `scripts/import-brevo.sh`:

```bash
#!/bin/bash
# Import CSV contacts into Brevo via /contacts/import API
# Usage: BREVO_API_KEY=xxx bash scripts/import-brevo.sh

set -euo pipefail

API_KEY="${BREVO_API_KEY:?Set BREVO_API_KEY env var}"
BASE_URL="https://api.brevo.com/v3"

import_csv() {
    local file="$1"
    local list_id="$2"
    local label="$3"

    # Read file, strip BOM, get content
    local content
    content=$(sed '1s/^\xEF\xBB\xBF//' "$file")

    echo "Importing $label ($file) into list $list_id..."

    local response
    response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/contacts/import" \
        -H "accept: application/json" \
        -H "content-type: application/json" \
        -H "api-key: $API_KEY" \
        -d "$(jq -n \
            --arg fileBody "$content" \
            --argjson listIds "[$list_id]" \
            '{
                fileBody: $fileBody,
                listIds: $listIds,
                emailBlacklist: false,
                smsBlacklist: false,
                updateExistingContacts: true,
                emptyContactsAttributes: false
            }'
        )")

    local http_code
    http_code=$(echo "$response" | tail -1)
    local body
    body=$(echo "$response" | sed '$d')

    if [[ "$http_code" -ge 200 && "$http_code" -lt 300 ]]; then
        echo "  OK ($http_code): $body"
    else
        echo "  FAILED ($http_code): $body"
        return 1
    fi
}

import_csv "brevo-import/brevo-import-tous.csv" 5 "Tous les contacts"
import_csv "brevo-import/brevo-import-adherents.csv" 3 "Adherents"
import_csv "brevo-import/brevo-import-donateurs.csv" 4 "Donateurs"

echo ""
echo "All imports completed."
```

### Step 3: Run the import

```bash
cd ~/projects/adesz
BREVO_API_KEY=<key> bash scripts/import-brevo.sh
```

Expected: 3 successful imports (HTTP 202 each), with `processId` in response body.

### Step 4: Verify imports

```bash
# Check import status (optional — Brevo processes async)
curl -s -H "api-key: $BREVO_API_KEY" "https://api.brevo.com/v3/contacts/lists/5" | jq '.totalSubscribers'
curl -s -H "api-key: $BREVO_API_KEY" "https://api.brevo.com/v3/contacts/lists/3" | jq '.totalSubscribers'
curl -s -H "api-key: $BREVO_API_KEY" "https://api.brevo.com/v3/contacts/lists/4" | jq '.totalSubscribers'
```

### Step 5: Commit

```bash
git add scripts/import-brevo.sh
git commit -m "feat: add Brevo CSV import script for existing contacts"
```

---

## Task 2: Create newsletter list + import 391 mailing contacts

**Files:**
- Read: `brevo-import/brevo-import-mailing-adesz.csv` (391 contacts, format: `EMAIL;TYPE`)
- Modify: `scripts/import-brevo.sh` (add newsletter import)

### Step 1: Create the "Newsletter ADESZ" list in Brevo

```bash
BREVO_API_KEY=<key>
RESPONSE=$(curl -s -X POST "https://api.brevo.com/v3/contacts/lists" \
    -H "accept: application/json" \
    -H "content-type: application/json" \
    -H "api-key: $BREVO_API_KEY" \
    -d '{"name": "Newsletter ADESZ", "folderId": 1}')
echo "$RESPONSE"
# Note the returned list ID
```

Expected: `{"id": <N>}` — save this ID.

### Step 2: Import the mailing CSV

```bash
NEWSLETTER_LIST_ID=<N>  # from step 1

# This CSV has no BOM, simpler format (EMAIL;TYPE)
CONTENT=$(cat brevo-import/brevo-import-mailing-adesz.csv)

curl -s -X POST "https://api.brevo.com/v3/contacts/import" \
    -H "accept: application/json" \
    -H "content-type: application/json" \
    -H "api-key: $BREVO_API_KEY" \
    -d "$(jq -n \
        --arg fileBody "$CONTENT" \
        --argjson listIds "[$NEWSLETTER_LIST_ID]" \
        '{
            fileBody: $fileBody,
            listIds: $listIds,
            emailBlacklist: false,
            updateExistingContacts: true,
            emptyContactsAttributes: false
        }'
    )"
```

Expected: HTTP 202, `processId` returned.

### Step 3: Verify

```bash
curl -s -H "api-key: $BREVO_API_KEY" \
    "https://api.brevo.com/v3/contacts/lists/$NEWSLETTER_LIST_ID" | jq '.totalSubscribers'
```

Expected: 391 (or close — may take a moment to process).

### Step 4: Store the newsletter list ID as GitHub secret

```bash
echo "$NEWSLETTER_LIST_ID" | gh secret set BREVO_LIST_NEWSLETTER -R FreakySurgeon/adesz.fr
```

### Step 5: Update CLAUDE.md and memory

Add `BREVO_LIST_NEWSLETTER` to the secrets section of `CLAUDE.md`.

### Step 6: Commit

```bash
git add CLAUDE.md
git commit -m "docs: add Newsletter ADESZ Brevo list ID to secrets"
```

---

## Task 3: Optimize 5 large images

**Files:**
- Optimize: `public/images/community.jpg` (2.9 MB, 5760x3840)
- Optimize: `public/images/education.jpg` (2.4 MB, 5760x3240)
- Optimize: `public/images/hero-children.jpg` (2.1 MB, 1484x1475)
- Optimize: `public/images/hero-africa.jpg` (2.0 MB, 1365x1706)
- Optimize: `public/images/agriculture.jpg` (1.9 MB, 6000x4000)
- Create: `scripts/optimize-images.py`
- Modify: `src/components/Hero.astro` — update src reference
- Modify: `src/pages/domaines.astro` — update image references
- Modify: `src/pages/index.astro` — update image references
- Modify: `src/pages/presentation/association.astro` — update image reference

**Context:**
- Images are referenced via `url('/images/xxx.jpg')` in Astro components
- Site uses `public/images/` for static assets (no Astro `Image` component currently)
- Pillow is available, cwebp/ImageMagick are NOT
- Target: max 1920px wide, WebP format, quality ~80

### Step 1: Create the optimization script

Create `scripts/optimize-images.py`:

```python
#!/usr/bin/env python3
"""Resize and convert large JPG images to WebP."""
from pathlib import Path
from PIL import Image

MAX_WIDTH = 1920
QUALITY = 80

images = [
    "community.jpg",
    "education.jpg",
    "hero-children.jpg",
    "hero-africa.jpg",
    "agriculture.jpg",
]

img_dir = Path(__file__).resolve().parent.parent / "public" / "images"

for name in images:
    src = img_dir / name
    dst = img_dir / name.replace(".jpg", ".webp")

    img = Image.open(src)
    w, h = img.size

    if w > MAX_WIDTH:
        ratio = MAX_WIDTH / w
        new_size = (MAX_WIDTH, int(h * ratio))
        img = img.resize(new_size, Image.LANCZOS)
        print(f"{name}: {w}x{h} -> {new_size[0]}x{new_size[1]}")
    else:
        print(f"{name}: {w}x{h} (no resize needed)")

    img.save(dst, "WEBP", quality=QUALITY)

    src_size = src.stat().st_size / 1024
    dst_size = dst.stat().st_size / 1024
    print(f"  {src_size:.0f} KB -> {dst_size:.0f} KB ({dst_size/src_size*100:.0f}%)")

print("\nDone. Now delete the original JPGs manually after updating references.")
```

### Step 2: Run the script

```bash
cd ~/projects/adesz
python3 scripts/optimize-images.py
```

Expected: 5 WebP files created, significant size reduction (should be ~100-200 KB each).

### Step 3: Update image references in Astro source files

Replace `.jpg` with `.webp` in these files for the 5 images:

**`src/components/Hero.astro:8`**: `hero-children.jpg` → `hero-children.webp`

**`src/pages/domaines.astro:21-24,32`**: `education.jpg`, `agriculture.jpg`, `community.jpg`, `hero-africa.jpg` → `.webp`

**`src/pages/index.astro:23,37`**: `education.jpg`, `agriculture.jpg` → `.webp`

**`src/pages/presentation/association.astro:21`**: `community.jpg` → `.webp`

### Step 4: Delete original JPGs

```bash
cd ~/projects/adesz/public/images
rm community.jpg education.jpg hero-children.jpg hero-africa.jpg agriculture.jpg
```

### Step 5: Build and verify

```bash
cd ~/projects/adesz
npm run build
```

Expected: Build succeeds without errors.

### Step 6: Commit

```bash
git add public/images/*.webp scripts/optimize-images.py \
    src/components/Hero.astro src/pages/domaines.astro \
    src/pages/index.astro src/pages/presentation/association.astro
git rm public/images/community.jpg public/images/education.jpg \
    public/images/hero-children.jpg public/images/hero-africa.jpg \
    public/images/agriculture.jpg
git commit -m "perf: optimize 5 large images - resize to 1920px max + convert to WebP

Reduces total image size from ~11.3 MB to ~500 KB.
Closes #2"
```

---

## Task 4: Automatic tax receipt PDF via Stripe webhook + Brevo

**Files:**
- Create: `public/api/lib/TCPDF/` — TCPDF library (download)
- Create: `public/api/generate-receipt.php` — PDF generation function
- Modify: `public/api/stripe-webhook.php` — call receipt generation + Brevo send after sync
- Modify: `public/api/config.php` — add receipt-related config
- Modify: `public/api/.htaccess` — block PDF files from direct access
- Create: `public/api/receipts/` — directory for generated PDFs (gitignored)
- Modify: `.gitignore` — add receipts directory

**Context:**
- Tax receipt template from `RECU FISCAL.docx`:
  - Title: "DON ANNEE {YEAR}"
  - Subtitle: full asso name
  - Receipt number: `ADESZ-{YEAR}-{NNN}`
  - Donor info: name
  - Beneficiary: ADESZ, loi 1901, W061016106
  - Amount + date
  - President signature: Abakar Mahamat
  - Footer: address, phone, email, website, Facebook
- Deductibility: 66% particuliers / 60% entreprises (mention CGI art. 200 & 238 bis)
- Existing webhook extracts: email, prenom, nom, adresse, cp, commune, amount, type, frequency
- OVH mutualize has PHP 8.x with most standard extensions
- No Composer on OVH — use standalone TCPDF or pure-PHP PDF lib
- Brevo transactional email API: POST /v3/smtp/email with attachment (base64)

### Step 1: Choose PDF library — FPDF (lightweight, no dependencies)

TCPDF is heavy (~40 MB). Use FPDF instead — single file, ~600 KB, sufficient for this use case.

Download FPDF:
```bash
mkdir -p public/api/lib
curl -L "http://www.fpdf.org/en/dl.php?v=185&f=zip" -o /tmp/fpdf.zip
unzip /tmp/fpdf.zip -d public/api/lib/
# This creates public/api/lib/fpdf185/ with fpdf.php
```

### Step 2: Add receipt counter logic

Create `public/api/generate-receipt.php`:

```php
<?php
/**
 * Generate a tax receipt (recu fiscal) PDF for ADESZ donations.
 *
 * Returns the PDF content as a string, or false on failure.
 */

require_once __DIR__ . '/lib/fpdf185/fpdf.php';

/**
 * Get the next receipt number for the current year.
 * Uses a simple JSON counter file with file locking.
 */
function get_next_receipt_number(): string {
    $year = date('Y');
    $counter_file = __DIR__ . '/receipts/counter.json';

    // Ensure directory exists
    $dir = dirname($counter_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fp = fopen($counter_file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        error_log('Cannot lock receipt counter file');
        return 'ADESZ-' . $year . '-ERR';
    }

    $content = stream_get_contents($fp);
    $data = $content ? (json_decode($content, true) ?: []) : [];

    $current = ($data[$year] ?? 0) + 1;
    $data[$year] = $current;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return sprintf('ADESZ-%s-%03d', $year, $current);
}

/**
 * Generate a tax receipt PDF.
 *
 * @param array $data {
 *   email: string, prenom: string, nom: string,
 *   adresse: string, cp: string, commune: string,
 *   amount: float, date: string, type: string
 * }
 * @return string|false PDF content as string, or false on failure
 */
function generate_receipt_pdf(array $data) {
    $receipt_number = get_next_receipt_number();
    $year = date('Y');

    // Colors from ADESZ brand
    $green_r = 45; $green_g = 122; $green_b = 58;   // #2D7A3A
    $dark_r = 27;  $dark_g = 94;   $dark_b = 39;    // #1B5E27
    $yellow_r = 245; $yellow_g = 197; $yellow_b = 24; // #F5C518
    $text_r = 45;  $text_g = 52;   $text_b = 54;    // #2D3436

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // --- Top decorative bar ---
    $pdf->SetFillColor($green_r, $green_g, $green_b);
    $pdf->Rect(0, 0, 210, 8, 'F');
    $pdf->SetFillColor($yellow_r, $yellow_g, $yellow_b);
    $pdf->Rect(0, 8, 210, 2, 'F');

    // --- Logo (if available) ---
    $logo_path = __DIR__ . '/../images/logo-adesz-2026.jpg';
    if (file_exists($logo_path)) {
        $pdf->Image($logo_path, 15, 15, 30);
    }

    // --- Header ---
    $pdf->SetY(18);
    $pdf->SetX(50);
    $pdf->SetFont('Helvetica', 'B', 22);
    $pdf->SetTextColor($dark_r, $dark_g, $dark_b);
    $pdf->Cell(0, 10, 'ADESZ', 0, 1, 'L');
    $pdf->SetX(50);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor($text_r, $text_g, $text_b);
    $pdf->Cell(0, 4, utf8_decode('Association pour le Developpement, l\'Entraide'), 0, 1, 'L');
    $pdf->SetX(50);
    $pdf->Cell(0, 4, utf8_decode('et la Solidarite du Village de Zafaya au Tchad'), 0, 1, 'L');

    // --- Receipt number badge ---
    $pdf->SetY(18);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor($green_r, $green_g, $green_b);
    $pdf->Cell(0, 5, $receipt_number, 0, 1, 'R');
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, 'Date : ' . ($data['date'] ?? date('d/m/Y')), 0, 1, 'R');

    // --- Title ---
    $pdf->Ln(15);
    $pdf->SetFillColor($green_r, $green_g, $green_b);
    $pdf->Rect(15, $pdf->GetY(), 180, 14, 'F');
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 14, utf8_decode('RECU FISCAL - DON ' . $year), 0, 1, 'C');

    // --- Subtitle: article du CGI ---
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, utf8_decode('Articles 200 et 238 bis du Code General des Impots'), 0, 1, 'C');

    // --- Beneficiary section ---
    $pdf->Ln(3);
    $section_y = $pdf->GetY();
    $pdf->SetFillColor(248, 247, 244); // #F8F7F4
    $pdf->Rect(15, $section_y, 180, 32, 'F');
    $pdf->SetDrawColor($green_r, $green_g, $green_b);
    $pdf->Rect(15, $section_y, 180, 32, 'D');

    $pdf->SetXY(20, $section_y + 3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor($green_r, $green_g, $green_b);
    $pdf->Cell(0, 6, utf8_decode('ORGANISME BENEFICIAIRE'), 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor($text_r, $text_g, $text_b);
    $pdf->Cell(0, 5, 'ADESZ', 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(0, 4, utf8_decode('Association loi 1901 - N° W061016106'), 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, utf8_decode('491 Boulevard Pierre Delmas, 06600 Antibes'), 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, utf8_decode('Objet : Developpement, entraide et solidarite - Zafaya, Tchad'), 0, 1, 'L');

    // --- Donor section ---
    $pdf->Ln(5);
    $section_y = $pdf->GetY();
    $pdf->SetFillColor(248, 247, 244);
    $pdf->Rect(15, $section_y, 180, 28, 'F');
    $pdf->SetDrawColor($green_r, $green_g, $green_b);
    $pdf->Rect(15, $section_y, 180, 28, 'D');

    $pdf->SetXY(20, $section_y + 3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor($green_r, $green_g, $green_b);
    $pdf->Cell(0, 6, 'DONATEUR', 0, 1, 'L');

    $donor_name = trim(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
    $donor_address = trim($data['adresse'] ?? '');
    $donor_city = trim(($data['cp'] ?? '') . ' ' . ($data['commune'] ?? ''));

    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor($text_r, $text_g, $text_b);
    $pdf->Cell(0, 5, utf8_decode($donor_name ?: 'Non renseigne'), 0, 1, 'L');
    if ($donor_address) {
        $pdf->SetX(20);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(0, 4, utf8_decode($donor_address), 0, 1, 'L');
    }
    if (trim($donor_city)) {
        $pdf->SetX(20);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell(0, 4, utf8_decode($donor_city), 0, 1, 'L');
    }

    // --- Amount section (prominent) ---
    $pdf->Ln(8);
    $amount_y = $pdf->GetY();
    $pdf->SetFillColor($green_r, $green_g, $green_b);
    $pdf->Rect(15, $amount_y, 180, 22, 'F');

    $pdf->SetXY(20, $amount_y + 3);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(255, 255, 255);
    $type_label = ($data['type'] ?? 'don') === 'adhesion' ? 'cotisation' : 'don';
    $pdf->Cell(0, 5, utf8_decode('Le beneficiaire reconnait avoir recu a titre de ' . $type_label . ','), 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->Cell(0, 4, utf8_decode('consenti a titre gratuit et sans contrepartie directe ou indirecte :'), 0, 1, 'L');

    $pdf->SetXY(20, $amount_y + 13);
    $pdf->SetFont('Helvetica', 'B', 16);
    $amount_str = number_format($data['amount'] ?? 0, 2, ',', ' ');
    $pdf->Cell(0, 8, $amount_str . ' EUR', 0, 1, 'C');

    // --- Payment info ---
    $pdf->Ln(5);
    $pdf->SetTextColor($text_r, $text_g, $text_b);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetX(20);
    $pdf->Cell(45, 5, 'Date du paiement :', 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 5, $data['date'] ?? date('d/m/Y'), 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(45, 5, 'Mode de paiement :', 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(0, 5, 'Carte bancaire (Stripe)', 0, 1, 'L');

    // --- Tax deduction box ---
    $pdf->Ln(5);
    $box_y = $pdf->GetY();
    $pdf->SetFillColor(255, 251, 235); // light yellow
    $pdf->SetDrawColor($yellow_r, $yellow_g, $yellow_b);
    $pdf->Rect(15, $box_y, 180, 22, 'DF');

    $pdf->SetXY(20, $box_y + 3);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor($text_r, $text_g, $text_b);
    $pdf->Cell(0, 5, utf8_decode('AVANTAGE FISCAL'), 0, 1, 'L');

    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', '', 8);
    $deduction_66 = number_format(($data['amount'] ?? 0) * 0.66, 2, ',', ' ');
    $pdf->Cell(0, 4, utf8_decode('Particuliers : reduction d\'impot de 66% (art. 200 du CGI), soit ' . $deduction_66 . ' EUR'), 0, 1, 'L');
    $pdf->SetX(20);
    $deduction_60 = number_format(($data['amount'] ?? 0) * 0.60, 2, ',', ' ');
    $pdf->Cell(0, 4, utf8_decode('Entreprises : reduction d\'impot de 60% (art. 238 bis du CGI), soit ' . $deduction_60 . ' EUR'), 0, 1, 'L');

    // --- Signature ---
    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor($text_r, $text_g, $text_b);
    $pdf->Cell(0, 5, utf8_decode('Fait a Antibes, le ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 5, 'Le President,', 0, 1, 'R');
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->Cell(0, 5, 'Abakar Mahamat', 0, 1, 'R');

    // --- Bottom decorative bar ---
    $pdf->SetFillColor($green_r, $green_g, $green_b);
    $pdf->Rect(0, 277, 210, 2, 'F');
    $pdf->SetFillColor($yellow_r, $yellow_g, $yellow_b);
    $pdf->Rect(0, 279, 210, 1, 'F');

    // --- Footer ---
    $pdf->SetY(282);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell(0, 3, 'ADESZ | 491 Bd Pierre Delmas, 06600 Antibes | 06 63 07 66 12 | adeszafaya@gmail.com | www.adesz.fr', 0, 1, 'C');

    // Save to file
    $receipts_dir = __DIR__ . '/receipts';
    if (!is_dir($receipts_dir)) {
        mkdir($receipts_dir, 0755, true);
    }
    $filename = $receipt_number . '.pdf';
    $filepath = $receipts_dir . '/' . $filename;

    $pdf->Output('F', $filepath);

    return [
        'path'     => $filepath,
        'filename' => $filename,
        'number'   => $receipt_number,
        'content'  => file_get_contents($filepath),
    ];
}
```

### Step 3: Add receipt sending function to webhook

Add to `public/api/stripe-webhook.php` — a function to send the receipt via Brevo transactional email:

```php
/**
 * Generate and send a tax receipt via Brevo transactional email.
 */
function send_tax_receipt(string $email, array $metadata, float $amount, string $type): void {
    global $brevo_api_key, $admin_email;

    require_once __DIR__ . '/generate-receipt.php';

    $data = [
        'email'   => $email,
        'prenom'  => $metadata['prenom'] ?? '',
        'nom'     => $metadata['nom'] ?? '',
        'adresse' => $metadata['adresse'] ?? '',
        'cp'      => $metadata['cp'] ?? '',
        'commune' => $metadata['commune'] ?? '',
        'amount'  => $amount,
        'date'    => date('d/m/Y'),
        'type'    => $type,
    ];

    $result = generate_receipt_pdf($data);
    if (!$result) {
        error_log('Tax receipt: PDF generation failed for ' . $email);
        return;
    }

    $donor_name = trim(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
    $deduction = number_format($amount * 0.66, 2, ',', ' ');

    // Send via Brevo transactional email
    $email_body = [
        'sender'  => ['name' => 'ADESZ', 'email' => 'adeszafaya@gmail.com'],
        'to'      => [['email' => $email, 'name' => $donor_name]],
        'subject' => 'Votre recu fiscal ADESZ - ' . $result['number'],
        'htmlContent' => '<html><body style="font-family:Arial,sans-serif;color:#2D3436;">'
            . '<div style="background:#2D7A3A;padding:20px;text-align:center;">'
            . '<h1 style="color:white;margin:0;">ADESZ</h1>'
            . '<p style="color:#F5C518;margin:5px 0 0;">Merci pour votre generosite !</p>'
            . '</div>'
            . '<div style="padding:30px;max-width:600px;margin:0 auto;">'
            . '<p>Bonjour ' . htmlspecialchars($donor_name ?: 'cher donateur') . ',</p>'
            . '<p>Nous vous remercions chaleureusement pour votre don de <strong>'
            . number_format($amount, 2, ',', ' ') . ' EUR</strong> en faveur de l\'ADESZ.</p>'
            . '<p>Vous trouverez en piece jointe votre recu fiscal <strong>' . $result['number'] . '</strong>.</p>'
            . '<p>Ce don vous ouvre droit a une reduction d\'impot de <strong>'
            . $deduction . ' EUR</strong> (66% du montant, art. 200 du CGI).</p>'
            . '<p>Votre soutien nous aide a poursuivre nos actions pour le developpement de Zafaya au Tchad.</p>'
            . '<p>Cordialement,<br><strong>Abakar Mahamat</strong><br>President de l\'ADESZ</p>'
            . '</div>'
            . '<div style="background:#F8F7F4;padding:15px;text-align:center;font-size:12px;color:#888;">'
            . 'ADESZ | 491 Bd Pierre Delmas, 06600 Antibes | www.adesz.fr'
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
            'content-type: application/json',
            'api-key: ' . $brevo_api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($email_body),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        error_log('Tax receipt sent: ' . $result['number'] . ' to ' . $email);
    } else {
        error_log('Tax receipt email failed (HTTP ' . $http_code . '): ' . $response);
        // Don't retry — the PDF is saved locally as fallback
    }
}
```

### Step 4: Wire receipt generation into webhook handlers

In `handle_checkout_completed()`, after `sync_to_brevo()` call, add:

```php
    // Send tax receipt
    send_tax_receipt($email, $metadata, $amount_total, $type);
```

In `handle_invoice_paid()`, after `sync_to_brevo()` call, add:

```php
    // Send tax receipt for renewal
    send_tax_receipt($email, $metadata, $amount, $type);
```

### Step 5: Update .htaccess to block PDF access

Add to `public/api/.htaccess`:

```apache
# Block direct HTTP access to receipt PDFs and counter
<FilesMatch "\.(pdf|json)$">
    Require all denied
</FilesMatch>
```

### Step 6: Update .gitignore

Add to project `.gitignore`:

```
public/api/receipts/
```

### Step 7: Download FPDF library

```bash
cd ~/projects/adesz
mkdir -p public/api/lib
curl -L "http://www.fpdf.org/en/dl.php?v=185&f=zip" -o /tmp/fpdf.zip
unzip /tmp/fpdf.zip -d public/api/lib/
```

### Step 8: Test locally (dry run)

Since we don't have PHP locally, test after deployment by triggering a Stripe test webhook:

```bash
# After deploying to test environment, trigger a test event
stripe trigger checkout.session.completed --override checkout_session:customer_email=chauvet.t@gmail.com
```

Or test the PDF generation separately by creating a quick test script.

### Step 9: Build and verify

```bash
npm run build
```

### Step 10: Commit

```bash
git add public/api/generate-receipt.php public/api/lib/fpdf185/ \
    public/api/stripe-webhook.php public/api/.htaccess .gitignore
git commit -m "feat: automatic tax receipt PDF generation and email via Brevo

- Generate branded PDF receipts (FPDF) on checkout.session.completed and invoice.paid
- Send via Brevo transactional email with PDF attachment
- Receipt numbering: ADESZ-{YEAR}-{NNN} with file-locked counter
- Includes tax deduction info (66% / 60% CGI art. 200 & 238 bis)
- Branded with ADESZ colors and logo
Closes #3"
```

---

## Execution Order

1. **Task 1** (Import CSV) — independent, run first
2. **Task 2** (Newsletter list) — depends on Task 1 being done (to avoid duplicates)
3. **Task 3** (Images) — independent, can run in parallel with Task 1-2
4. **Task 4** (Tax receipts) — independent but most complex, do last

## Pre-requisites

- **Brevo API key** — need it as env var for Tasks 1-2
- **jq** — needed for import script (`sudo apt install jq` if missing)
- **Pillow** — already installed (confirmed: pillow 10.2.0)
- **FPDF download** — Task 4 Step 7
