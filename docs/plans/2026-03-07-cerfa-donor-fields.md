# Champs donateur + Conformité Cerfa — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add donor identity fields to the donation form and make the PDF tax receipt Cerfa n° 11580 compliant.

**Architecture:** Two independent changes — (1) new HTML/JS donor fields section in the "don" tab of `adherer.astro`, wired to send data via the existing `member` payload, and (2) PHP updates to `generate-receipt.php` adding montant en lettres, nature du don, mention Cerfa complète, and "intérêt général" label.

**Tech Stack:** Astro (HTML + inline TS), PHP (tFPDF for PDF generation)

**Design doc:** `docs/plans/2026-03-07-cerfa-donor-fields-design.md`

---

### Task 1: Add donor fields HTML to the donation form

**Files:**
- Modify: `src/pages/adherer.astro:153-154` (insert new section after `membership-section` closing div)

**Step 1: Add the donor-section HTML block**

Insert the following block at line 154 (right after the closing `</div>` of `membership-section`, before the frequency section):

```html
          <!-- Donor Info for tax receipt (visible for don only, hidden for adhesion/combo) -->
          <div id="donor-section" style="display:none" class="mt-6">
            <label class="block text-sm font-semibold text-[#2D3436] mb-1">
              Pour votre re&ccedil;u fiscal
            </label>
            <p class="text-xs text-[#2D3436]/50 mb-3">
              Ces informations sont n&eacute;cessaires pour &eacute;tablir votre re&ccedil;u fiscal (Cerfa n&deg; 11580).
            </p>
            <div class="space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <input type="text" id="donor-prenom" placeholder="Pr&eacute;nom *" required
                  class="rounded-xl border-2 border-[#2D3436]/15 bg-[#F8F7F4] px-4 py-3 text-sm text-[#2D3436] placeholder-[#2D3436]/40 transition-all focus:border-[#2D7A3A] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#2D7A3A]/20" />
                <input type="text" id="donor-nom" placeholder="Nom *" required
                  class="rounded-xl border-2 border-[#2D3436]/15 bg-[#F8F7F4] px-4 py-3 text-sm text-[#2D3436] placeholder-[#2D3436]/40 transition-all focus:border-[#2D7A3A] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#2D7A3A]/20" />
              </div>
              <input type="text" id="donor-adresse" placeholder="Adresse *" required
                class="w-full rounded-xl border-2 border-[#2D3436]/15 bg-[#F8F7F4] px-4 py-3 text-sm text-[#2D3436] placeholder-[#2D3436]/40 transition-all focus:border-[#2D7A3A] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#2D7A3A]/20" />
              <div class="grid grid-cols-3 gap-3">
                <input type="text" id="donor-cp" placeholder="Code postal *" required
                  class="rounded-xl border-2 border-[#2D3436]/15 bg-[#F8F7F4] px-4 py-3 text-sm text-[#2D3436] placeholder-[#2D3436]/40 transition-all focus:border-[#2D7A3A] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#2D7A3A]/20" />
                <input type="text" id="donor-commune" placeholder="Commune *" required
                  class="col-span-2 rounded-xl border-2 border-[#2D3436]/15 bg-[#F8F7F4] px-4 py-3 text-sm text-[#2D3436] placeholder-[#2D3436]/40 transition-all focus:border-[#2D7A3A] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#2D7A3A]/20" />
              </div>
            </div>
          </div>
```

**Step 2: Commit**

```bash
git add src/pages/adherer.astro
git commit -m "feat: add donor info fields HTML to donation form"
```

---

### Task 2: Wire up donor fields in JS (visibility, validation, data collection)

**Files:**
- Modify: `src/pages/adherer.astro` (script section)

**Step 1: Add DOM ref for donor-section**

In the `// --- DOM refs ---` block (around line 336), add after the `membershipSection` ref:

```typescript
    const donorSection = document.getElementById('donor-section')!;
```

**Step 2: Add getDonorData() function**

After the `getMemberData()` function (around line 374), add:

```typescript
    function getDonorData() {
      return {
        nom: (document.getElementById('donor-nom') as HTMLInputElement).value.trim(),
        prenom: (document.getElementById('donor-prenom') as HTMLInputElement).value.trim(),
        adresse: (document.getElementById('donor-adresse') as HTMLInputElement).value.trim(),
        cp: (document.getElementById('donor-cp') as HTMLInputElement).value.trim(),
        commune: (document.getElementById('donor-commune') as HTMLInputElement).value.trim(),
      };
    }

    function isDonorFormValid(): boolean {
      const d = getDonorData();
      return !!(d.nom && d.prenom && d.adresse && d.cp && d.commune);
    }
```

**Step 3: Update isFormValid()**

Replace the existing `isFormValid()` function (around line 381-386) with:

```typescript
    function isFormValid(): boolean {
      if (activeTab === 'adhesion' || activeTab === 'combo') {
        if (!isMemberFormValid()) return false;
      }
      if (activeTab === 'don') {
        if (!isDonorFormValid()) return false;
      }
      if (activeTab === 'adhesion') return true;
      return getEffectiveAmount() >= 1;
    }
```

**Step 4: Update updateVisibility()**

In the `updateVisibility()` function (around line 404-411), add donor section visibility. Replace with:

```typescript
    function updateVisibility() {
      const showDonFields = activeTab === 'don' || activeTab === 'combo';
      const showMemberFields = activeTab === 'adhesion' || activeTab === 'combo';
      const showDonorFields = activeTab === 'don';
      amountSection.style.display = showDonFields ? '' : 'none';
      frequencySection.style.display = showDonFields ? '' : 'none';
      membershipSection.style.display = showMemberFields ? '' : 'none';
      donorSection.style.display = showDonorFields ? '' : 'none';
      comboNote.classList.toggle('hidden', activeTab !== 'combo');
    }
```

**Step 5: Add input listener for donor section revalidation**

After the existing `membershipSection.addEventListener('input', ...)` block (around line 518-521), add:

```typescript
    // Revalidate on donor form input
    donorSection.addEventListener('input', () => {
      hideError();
      updateSubmitButton();
    });
```

**Step 6: Update submit handler to send donor data for 'don' type**

In the submit handler (around line 534-536), replace the `member` logic:

```typescript
        let member = null;
        if (activeTab === 'adhesion' || activeTab === 'combo') {
          member = getMemberData();
        } else if (activeTab === 'don') {
          member = getDonorData();
        }
```

**Step 7: Commit**

```bash
git add src/pages/adherer.astro
git commit -m "feat: wire donor fields — visibility, validation, data submission"
```

---

### Task 3: Add nombre_en_lettres() PHP function

**Files:**
- Modify: `public/api/generate-receipt.php` (add function before `generate_receipt_pdf`)

**Step 1: Add the function**

Insert the following function after the `get_next_receipt_number()` function (after line 44, before the `generate_receipt_pdf` function):

```php
/**
 * Convert a numeric amount to French words.
 * Handles amounts up to 999 999,99 EUR.
 *
 * @param float $amount e.g. 25.50
 * @return string e.g. "vingt-cinq euros et cinquante centimes"
 */
function nombre_en_lettres(float $amount): string {
    $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
              'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    $tens  = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

    $convert_below_1000 = function (int $n) use ($units, $tens, &$convert_below_1000): string {
        if ($n === 0) return '';
        if ($n < 20) return $units[$n];

        if ($n < 100) {
            $t = intdiv($n, 10);
            $u = $n % 10;

            // 70-79: soixante-dix, soixante-et-onze, ...
            if ($t === 7) {
                if ($u === 0) return 'soixante-dix';
                if ($u === 1) return 'soixante-et-onze';
                return 'soixante-' . $units[10 + $u];
            }
            // 90-99: quatre-vingt-dix, quatre-vingt-onze, ...
            if ($t === 9) {
                if ($u === 0) return 'quatre-vingt-dix';
                return 'quatre-vingt-' . $units[10 + $u];
            }
            // 80-89
            if ($t === 8) {
                if ($u === 0) return 'quatre-vingts';
                return 'quatre-vingt-' . $units[$u];
            }

            if ($u === 0) return $tens[$t];
            if ($u === 1 && $t <= 6) return $tens[$t] . '-et-un';
            return $tens[$t] . '-' . $units[$u];
        }

        $h = intdiv($n, 100);
        $remainder = $n % 100;
        $prefix = ($h === 1) ? 'cent' : $units[$h] . ' cent';
        if ($remainder === 0 && $h > 1) $prefix .= 's';
        if ($remainder === 0) return $prefix;
        return $prefix . ' ' . $convert_below_1000($remainder);
    };

    $euros = (int) floor($amount);
    $cents = (int) round(($amount - $euros) * 100);

    $parts = [];

    if ($euros >= 1000) {
        $thousands = intdiv($euros, 1000);
        $remainder = $euros % 1000;
        $t = ($thousands === 1) ? 'mille' : $convert_below_1000($thousands) . ' mille';
        if ($remainder > 0) {
            $t .= ' ' . $convert_below_1000($remainder);
        }
        $parts[] = $t;
    } elseif ($euros > 0) {
        $parts[] = $convert_below_1000($euros);
    } else {
        $parts[] = 'z' . "\xc3\xa9" . 'ro';
    }

    $parts[] = ($euros <= 1) ? 'euro' : 'euros';

    if ($cents > 0) {
        $parts[] = 'et ' . $convert_below_1000($cents);
        $parts[] = ($cents <= 1) ? 'centime' : 'centimes';
    }

    return implode(' ', $parts);
}
```

**Step 2: Commit**

```bash
git add public/api/generate-receipt.php
git commit -m "feat: add nombre_en_lettres() for Cerfa amount-in-words"
```

---

### Task 4: Update PDF — Cerfa compliance elements

**Files:**
- Modify: `public/api/generate-receipt.php`

**Step 1: Add "Association d'intérêt général" to beneficiary box**

In the beneficiary box section (around line 133), after the line `$pdf->Cell(0, 4, "Association loi 1901, d\xc3\xa9clar\xc3\xa9" . 'e le 04 novembre 2022', 0, 1, 'L');`, add:

```php
    $pdf->SetX(20);
    $pdf->SetFont('Poppins', 'B', 7.5);
    $pdf->Cell(0, 4, "Association d'int\xc3\xa9r\xc3\xaat g\xc3\xa9n\xc3\xa9ral", 0, 1, 'L');
    $pdf->SetFont('Poppins', '', 7.5);
```

**Step 2: Add "Nature du don" line after the payment details**

After the "Mode de paiement" line (around line 212), add:

```php
    $pdf->SetX(20);
    $pdf->SetFont('Poppins', '', 9);
    $pdf->Cell(45, 5, 'Nature du don :', 0, 0, 'L');
    $pdf->SetFont('Poppins', 'B', 9);
    $nature = ($data['type'] ?? 'don') === 'adhesion' ? 'Cotisation num\xc3\xa9raire' : 'Don num\xc3\xa9raire';
    $pdf->Cell(0, 5, $nature, 0, 1, 'L');
```

**Step 3: Add montant en lettres under the amount**

In the amount banner section, after the amount display line `$pdf->Cell(0, 8, $amount_str . ' EUR', 0, 1, 'C');` (around line 191), add:

```php
    $pdf->SetFont('Poppins', 'I', 8);
    $amount_words = nombre_en_lettres($data['amount'] ?? 0);
    $pdf->Cell(0, 5, '(' . ucfirst($amount_words) . ')', 0, 1, 'C');
```

Note: The amount banner box height may need to increase from 22 to 28 to accommodate the extra line. Update line 178: `$pdf->Rect(15, $y, 180, 28, 'F');`  and adjust subsequent Y positions (SetXY for amount text from `$y + 13` to `$y + 11`).

**Step 4: Replace partial CGI reference with full Cerfa certification mention**

Replace the existing CGI reference line (around line 111-113):
```php
    $pdf->SetFont('Poppins', '', 7.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, "Articles 200 et 238 bis du Code G\xc3\xa9n\xc3\xa9ral des Imp\xc3\xb4ts", 0, 1, 'C');
```

With:
```php
    $pdf->SetFont('Poppins', 'I', 7);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Ln(2);
    $pdf->SetX(20);
    $pdf->MultiCell(170, 4, "L'organisme certifie que les dons et versements qu'il re\xc3\xa7oit ouvrent droit \xc3\xa0 la r\xc3\xa9duction d'imp\xc3\xb4t pr\xc3\xa9vue aux articles 200, 238 bis et 978 du Code G\xc3\xa9n\xc3\xa9ral des Imp\xc3\xb4ts.", 0, 'C');
```

**Step 5: Verify layout**

Run a quick test to generate a PDF and verify elements don't overlap:

```bash
cd /home/thomas/projects/adesz && php -r "
require_once 'public/api/generate-receipt.php';
\$data = ['email'=>'test@test.fr','prenom'=>'Jean','nom'=>'Dupont','adresse'=>'12 rue de la Paix','cp'=>'75001','commune'=>'Paris','amount'=>125.50,'date'=>'07/03/2026','type'=>'don'];
\$r = generate_receipt_pdf(\$data);
echo \$r ? 'OK: '.\$r['path'] : 'FAIL';
echo PHP_EOL;
"
```

Expected: `OK: /home/thomas/projects/adesz/public/api/receipts/ADESZ-2026-XXX.pdf`

**Step 6: Commit**

```bash
git add public/api/generate-receipt.php
git commit -m "feat: Cerfa n° 11580 compliance — intérêt général, nature du don, montant en lettres, mention certifiée"
```

---

### Task 5: Manual visual check + build verification

**Step 1: Run the Astro build to verify no syntax errors**

```bash
cd /home/thomas/projects/adesz && npm run build
```

Expected: Build succeeds without errors.

**Step 2: Open the generated test PDF and visually check**

Open the PDF generated in Task 4 Step 5 and verify:
- [ ] "Association d'intérêt général" appears in the beneficiary box
- [ ] Full Cerfa certification mention appears below the title banner
- [ ] "Don numéraire" appears in the payment details
- [ ] Amount in words appears below amount in figures: "(Cent vingt-cinq euros et cinquante centimes)"
- [ ] No text overlap or layout issues

**Step 3: Clean up test PDF**

```bash
rm -f /home/thomas/projects/adesz/public/api/receipts/ADESZ-2026-*.pdf
rm -f /home/thomas/projects/adesz/public/api/receipts/counter.json
```

**Step 4: Final commit if any layout adjustments were needed**

```bash
git add -A && git commit -m "fix: adjust PDF layout for Cerfa elements"
```
