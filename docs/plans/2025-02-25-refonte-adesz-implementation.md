# Refonte adesz.fr — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a static Astro website to replace the current WordPress/Elementor site for ADESZ (adesz.fr), using WordPress as a headless CMS via its REST API.

**Architecture:** Astro 5 SSG site with Tailwind CSS for styling. Content is fetched from the existing WordPress REST API at build time and rendered as static HTML. The site is deployed on OVH shared hosting via FTP, replacing the current public-facing WordPress frontend. WordPress remains accessible as a backend CMS for volunteers.

**Tech Stack:** Astro 5, Tailwind CSS 4, TypeScript, WordPress REST API, Formspree (contact form), GitHub Actions + lftp (CI/CD)

---

## WordPress API Reference

| Endpoint | Key IDs |
|----------|---------|
| Categories | Projets en cours=311, Projets a venir=312, Projets=313, Nos realisations=314, Les partenaires=315, Articles de Presse=317, FAQ=323 |
| Pages | Pays=19047, Village=19058, Association=19063, Sante=19073, Education=19076, Agriculture=19079, Developpement=19082, Urgences=19085, Adherer=19094, Partenaires=19183 |
| Posts | 9 posts total across categories |

API base: `https://adesz.fr/wp-json/wp/v2`

---

### Task 1: Initialize Astro project with Tailwind

**Files:**
- Create: `package.json`, `astro.config.mjs`, `tsconfig.json`, `src/styles/global.css`

**Step 1: Create Astro project**

Run from `/home/thomas/projects/adesz`:
```bash
npm create astro@latest . -- --template minimal --no-git --no-install --typescript strict
```

**Step 2: Install dependencies**

```bash
npm install
npm install @astrojs/tailwind tailwindcss @fontsource/poppins
```

**Step 3: Configure Astro with Tailwind**

`astro.config.mjs`:
```javascript
import { defineConfig } from 'astro/config';
import tailwindcss from '@astrojs/tailwind';

export default defineConfig({
  integrations: [tailwindcss()],
  output: 'static',
  site: 'https://adesz.fr',
});
```

**Step 4: Create global CSS with Tailwind + Poppins**

`src/styles/global.css`:
```css
@import '@fontsource/poppins/400.css';
@import '@fontsource/poppins/500.css';
@import '@fontsource/poppins/600.css';
@import '@fontsource/poppins/700.css';

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  html {
    font-family: 'Poppins', sans-serif;
    color: #2D3436;
  }
}
```

**Step 5: Build to verify**

```bash
npm run build
```
Expected: successful build with no errors

**Step 6: Commit**

```bash
git add -A && git commit -m "init: astro project with tailwind and poppins"
```

---

### Task 2: Copy static assets (logo, images, favicon)

**Files:**
- Create: `public/images/logo.png`, `public/favicon.ico`, etc.

**Step 1: Copy logo and key images from gdrive**

```bash
mkdir -p public/images
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/logo.png" public/images/
cp -r "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/favicon_io/"* public/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/Classes.jpg" public/images/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/Centresante.jpg" public/images/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/Ardoises.jpg" public/images/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/Danse_folklorique_Batha_(Tchad).jpg" public/images/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/Place_de_la_nation4_(Tchad).jpg" public/images/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/free-photo-of-femme-afrique-sourire-exterieur.jpeg" public/images/
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/youth-africa-student-child-education-classroom-74935-pxhere.com.jpg" public/images/education.jpg
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/pexels-anthonybbeck-4493205.jpg" public/images/agriculture.jpg
cp "/home/thomas/gdrive/1 Projets/ADESZ Site internet/images/Image4.jpg" public/images/
```

**Step 2: Verify files are in place**

```bash
ls public/images/ && ls public/favicon.ico
```

**Step 3: Commit**

```bash
git add public/ && git commit -m "assets: add logo, images, and favicon"
```

---

### Task 3: WordPress API client

**Files:**
- Create: `src/lib/wordpress.ts`

**Step 1: Create the WP API client**

`src/lib/wordpress.ts`:
```typescript
const WP_API = "https://adesz.fr/wp-json/wp/v2";

export interface WPPost {
  id: number;
  title: { rendered: string };
  content: { rendered: string };
  excerpt: { rendered: string };
  slug: string;
  date: string;
  categories: number[];
  featured_media: number;
  _embedded?: {
    'wp:featuredmedia'?: Array<{ source_url: string; alt_text: string }>;
  };
}

export interface WPPage {
  id: number;
  title: { rendered: string };
  content: { rendered: string };
  slug: string;
}

export interface WPCategory {
  id: number;
  name: string;
  slug: string;
  count: number;
}

export interface WPMedia {
  id: number;
  source_url: string;
  alt_text: string;
}

// Category IDs
export const CATEGORIES = {
  PROJETS_EN_COURS: 311,
  PROJETS_A_VENIR: 312,
  PROJETS: 313,
  REALISATIONS: 314,
  PARTENAIRES: 315,
  PRESSE: 317,
  FAQ: 323,
} as const;

// Page IDs
export const PAGES = {
  PAYS: 19047,
  VILLAGE: 19058,
  ASSOCIATION: 19063,
  SANTE: 19073,
  EDUCATION: 19076,
  AGRICULTURE: 19079,
  DEVELOPPEMENT: 19082,
  URGENCES: 19085,
  ADHERER: 19094,
  PARTENAIRES: 19183,
} as const;

async function fetchAPI<T>(endpoint: string, params?: Record<string, string>): Promise<T> {
  const url = new URL(`${WP_API}/${endpoint}`);
  url.searchParams.set('_embed', 'true');
  if (params) {
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  }
  const res = await fetch(url.toString());
  if (!res.ok) {
    throw new Error(`WP API error: ${res.status} ${res.statusText}`);
  }
  return res.json();
}

export async function getPosts(params?: Record<string, string>): Promise<WPPost[]> {
  return fetchAPI<WPPost[]>('posts', { per_page: '100', ...params });
}

export async function getPostsByCategory(categoryId: number): Promise<WPPost[]> {
  return getPosts({ categories: categoryId.toString() });
}

export async function getPostBySlug(slug: string): Promise<WPPost | undefined> {
  const posts = await getPosts({ slug });
  return posts[0];
}

export async function getPage(id: number): Promise<WPPage> {
  return fetchAPI<WPPage>(`pages/${id}`);
}

export async function getMedia(id: number): Promise<WPMedia> {
  return fetchAPI<WPMedia>(`media/${id}`);
}
```

**Step 2: Verify TypeScript compiles**

```bash
npx astro check
```

**Step 3: Commit**

```bash
git add src/lib/wordpress.ts && git commit -m "feat: wordpress REST API client with types"
```

---

### Task 4: Base layout (header + footer + responsive nav)

**Files:**
- Create: `src/layouts/BaseLayout.astro`
- Create: `src/components/Header.astro`
- Create: `src/components/Footer.astro`
- Create: `src/components/MobileMenu.astro`

**Step 1: Create Header component with responsive navigation**

The header must include:
- Logo (left)
- Navigation menu with dropdowns for Presentation, Domaines d'action, Projets
- CTA button "Faire un don" (right)
- Mobile hamburger menu

Navigation structure (from current site):
```
Presentation → Pays, Village, Association
Domaines d'Action → Sante, Education, Agriculture, Developpement, Urgences
Projets → Projets en cours, Projets a venir
Nos realisations
Nos Partenaires
Articles de Presse
Adherer / Faire un don
Nous contacter
```

Use Tailwind for all styling. Green/yellow palette:
- Primary green: `#2D7A3A`
- Dark green: `#1B5E27`
- Yellow accent: `#F5C518`
- Text: `#2D3436`
- Light bg: `#F8F7F4`

**Step 2: Create Footer component**

Footer includes:
- Logo + short description
- Navigation links
- Contact info: adeszafaya@gmail.com
- Social links: Facebook, YouTube
- Copyright: "Tous droits reserves ADESZ — Realisation T. Chauvet"

**Step 3: Create BaseLayout that wraps Header + Footer**

`src/layouts/BaseLayout.astro`:
- Accept `title`, `description` props
- Include meta tags, favicon, global CSS
- Wrap slot between Header and Footer
- Add Open Graph meta tags

**Step 4: Create a minimal index.astro to test the layout**

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout title="ADESZ - Association pour le Developpement de Zafaya">
  <main>
    <h1>ADESZ</h1>
    <p>Site en construction</p>
  </main>
</BaseLayout>
```

**Step 5: Build and verify**

```bash
npm run dev
```
Open http://localhost:4321 — verify header, nav, footer render correctly. Test mobile responsive.

**Step 6: Commit**

```bash
git add src/ && git commit -m "feat: base layout with responsive header and footer"
```

---

### Task 5: Homepage — Hero section

**Files:**
- Create: `src/components/Hero.astro`
- Modify: `src/pages/index.astro`

**Step 1: Create Hero component**

Content from current site:
- Title: "ASSOCIATION ADESZ"
- Subtitle: "Pour aider le village de Zafaya"
- Description: "Nous oeuvrons au Tchad pour ameliorer le quotidien des habitants de Zafaya et des villages environnants..."
- Two CTA buttons: "Faire un don" (yellow bg), "Decouvrir nos projets" (outlined)
- Background: semi-transparent overlay on a hero image

Style: Full-width hero, min-height 80vh, centered text, green overlay on background image.

**Step 2: Update index.astro with Hero**

**Step 3: Build and verify**

**Step 4: Commit**

```bash
git add src/ && git commit -m "feat: homepage hero section"
```

---

### Task 6: Homepage — Impact stats + Domaines d'action + CTA

**Files:**
- Create: `src/components/ImpactStats.astro`
- Create: `src/components/DomaineCard.astro`
- Create: `src/components/DonationCTA.astro`
- Modify: `src/pages/index.astro`

**Step 1: Create ImpactStats component**

Stats from current site / docs:
- "3 salles de classe construites"
- "1 college en construction"
- "1 centre de sante renove"
- "~10 000 beneficiaires"

Display as a row of 4 stat cards with big numbers and labels. Green background, white text.

**Step 2: Create DomaineCard component**

Reusable card: icon/image, title, short description, link. Used for the 3 featured domains on the homepage.

**Step 3: Create DonationCTA component**

A section with:
- Heading "Soutenez nos actions"
- Text about tax deduction (66% / 60%)
- Two buttons: "Faire un don", "Adherer pour 15 EUR"
- Warm yellow background

**Step 4: Assemble on index.astro**

Add sections in order: Hero → ImpactStats → Domaines → Recent Articles (placeholder) → DonationCTA

**Step 5: Build and verify**

**Step 6: Commit**

```bash
git add src/ && git commit -m "feat: homepage stats, domaines cards, and donation CTA"
```

---

### Task 7: Homepage — Latest articles section

**Files:**
- Create: `src/components/ArticleCard.astro`
- Modify: `src/pages/index.astro`

**Step 1: Create ArticleCard component**

Card showing: featured image (or placeholder), title, date, excerpt, "Lire la suite" link.

**Step 2: Fetch latest 3 posts from WP API in index.astro**

```astro
---
import { getPosts } from '../lib/wordpress';
const latestPosts = await getPosts({ per_page: '3', orderby: 'date', order: 'desc' });
---
```

Display them in a 3-column grid with ArticleCard.

**Step 3: Build and verify**

Verify the 3 latest articles appear with real content from WP.

**Step 4: Commit**

```bash
git add src/ && git commit -m "feat: homepage latest articles from WP API"
```

---

### Task 8: Presentation pages (Pays, Village, Association)

**Files:**
- Create: `src/pages/presentation/pays.astro`
- Create: `src/pages/presentation/village.astro`
- Create: `src/pages/presentation/association.astro`

**Step 1: Create the 3 presentation pages**

Each page:
1. Fetch the corresponding WP page via `getPage(PAGES.PAYS)` etc.
2. Render the WP content (`content.rendered`) inside the BaseLayout
3. Add a page header with title and breadcrumb
4. Style the WP HTML content with Tailwind prose (`@tailwindcss/typography`)

Install typography plugin:
```bash
npm install @tailwindcss/typography
```

**Step 2: Build and verify**

Check each page renders the WP content correctly.

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: presentation pages (pays, village, association) from WP"
```

---

### Task 9: Domaines d'action page

**Files:**
- Create: `src/pages/domaines.astro`

**Step 1: Create domaines page**

Fetch 5 WP pages: Sante (19073), Education (19076), Agriculture (19079), Developpement (19082), Urgences (19085).

Display as 5 cards in a grid, each with:
- Icon or image (use local images: Centresante.jpg for sante, education.jpg for education, agriculture.jpg for agriculture)
- Title
- Excerpt from WP page content (first paragraph)
- "En savoir plus" button that opens a modal or expands to show full content

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: domaines d'action page with WP content"
```

---

### Task 10: Dynamic pages — Projets

**Files:**
- Create: `src/pages/projets/index.astro`
- Create: `src/pages/projets/[slug].astro`

**Step 1: Create projects list page**

Fetch posts from categories PROJETS_EN_COURS (311) and PROJETS_A_VENIR (312).
Display in two sections: "Projets en cours" and "Projets a venir".
Each project as a card (ProjectCard) with image, title, excerpt, date, link to detail page.

**Step 2: Create project detail page**

Use `getStaticPaths()` to generate pages for all project posts:
```astro
---
import { getPostsByCategory, CATEGORIES } from '../../lib/wordpress';

export async function getStaticPaths() {
  const projetsEnCours = await getPostsByCategory(CATEGORIES.PROJETS_EN_COURS);
  const projetsAVenir = await getPostsByCategory(CATEGORIES.PROJETS_A_VENIR);
  const allProjets = [...projetsEnCours, ...projetsAVenir];
  // Deduplicate by slug
  const unique = [...new Map(allProjets.map(p => [p.slug, p])).values()];
  return unique.map(post => ({ params: { slug: post.slug }, props: { post } }));
}
---
```

Render full WP content with prose styling.

**Step 3: Build and verify**

```bash
npm run build
```
Check that project pages are generated in `dist/projets/`.

**Step 4: Commit**

```bash
git add src/ && git commit -m "feat: projets pages (list + detail) from WP API"
```

---

### Task 11: Dynamic pages — Realisations

**Files:**
- Create: `src/pages/realisations/index.astro`
- Create: `src/pages/realisations/[slug].astro`

**Step 1: Create realisations pages**

Same pattern as projets but fetching category REALISATIONS (314).
List page shows all completed projects.
Detail page renders full WP content.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: realisations pages from WP API"
```

---

### Task 12: Dynamic pages — Articles de presse

**Files:**
- Create: `src/pages/presse/index.astro`
- Create: `src/pages/presse/[slug].astro`

**Step 1: Create press articles pages**

Fetch posts from category PRESSE (317).
List page: blog-style layout with ArticleCard components.
Detail page: full article with featured image, date, prose content.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: articles de presse pages from WP API"
```

---

### Task 13: Partenaires page

**Files:**
- Create: `src/pages/partenaires.astro`

**Step 1: Create partenaires page**

Two sources:
1. Fetch WP page PARTENAIRES (19183) for structured content
2. Fetch post from category PARTENAIRES (315) for the "Nos partenaires" post

Display partner logos/info. If WP content has logos, render them. Otherwise use a clean grid layout with the content from WP.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: partenaires page from WP"
```

---

### Task 14: Adherer / Faire un don page

**Files:**
- Create: `src/pages/adherer.astro`

**Step 1: Create donation/membership page**

Three cards:
1. **Don libre** — Description + Stripe placeholder button (#) + mention deductibilite 66%/60%
2. **Adhesion** — 15 EUR a vie, Stripe placeholder button
3. **Don + Adhesion** — Combine les deux, Stripe placeholder button

Below the cards:
- **HelloAsso** link (existing): "Vous pouvez aussi adherer/donner via HelloAsso"
- **Virement** : IBAN FR76 3000 3015 0900 0372 6041 709 (BIC: SOGEFRPP)
- **Cheque** : a l'ordre d'ADESZ, 491 Bd Pierre Delmas, 06600 Antibes
- Tax deduction info: "Votre don est deductible a 66% de votre impot sur le revenu (particuliers) ou 60% de l'impot sur les societes (entreprises). ADESZ est reconnue d'interet general."

Also fetch WP page ADHERER (19094) for any additional content.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: adherer/don page with stripe placeholders"
```

---

### Task 15: Contact page

**Files:**
- Create: `src/pages/contact.astro`

**Step 1: Create contact page**

Two columns:
- Left: Contact form (Formspree or simple mailto: for now)
  - Fields: Nom, Email, Sujet, Message
  - Action: `https://formspree.io/f/{FORM_ID}` (placeholder, to be configured)
- Right: Contact info
  - Email: adeszafaya@gmail.com
  - Address: 491 Bd Pierre Delmas, 06600 Antibes
  - Tel: 06 63 04 66 12

Style: clean form with green submit button.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: contact page with form"
```

---

### Task 16: FAQ page

**Files:**
- Create: `src/pages/faq.astro`

**Step 1: Create FAQ page**

Accordion-style FAQ with common questions. Content to include:
- "Comment faire un don ?" → Explication des 3 methodes (Stripe, virement, cheque)
- "Comment adherer ?" → Adhesion 15 EUR a vie
- "Les dons sont-ils deductibles ?" → Oui, 66% particuliers, 60% entreprises
- "Qui est ADESZ ?" → Presentation courte
- "Ou se trouve Zafaya ?" → Tchad, region du Batha
- "Comment suivre les projets ?" → Articles de presse, reseaux sociaux

Use CSS-only accordion (details/summary HTML elements) or simple JS toggle. No framework needed.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: FAQ page with accordion"
```

---

### Task 17: Videos page

**Files:**
- Create: `src/pages/videos.astro`

**Step 1: Create videos page**

Embed YouTube videos about Zafaya. Check if there are YouTube links in the WP content, otherwise use placeholder embeds. Use responsive iframe wrappers (aspect-ratio: 16/9).

Check the existing WP site for video URLs by fetching content from the API.

**Step 2: Build and verify**

**Step 3: Commit**

```bash
git add src/ && git commit -m "feat: videos page with youtube embeds"
```

---

### Task 18: SEO, sitemap, meta tags

**Files:**
- Modify: `src/layouts/BaseLayout.astro`
- Create: `src/components/SEO.astro` (optional, can be inline in BaseLayout)

**Step 1: Install sitemap integration**

```bash
npx astro add sitemap
```

**Step 2: Add comprehensive meta tags to BaseLayout**

- Title tag with site name suffix
- Meta description
- Open Graph (og:title, og:description, og:image, og:url)
- Twitter card meta
- Canonical URL
- Robots meta
- Structured data (JSON-LD) for Organization

**Step 3: Add robots.txt**

`public/robots.txt`:
```
User-agent: *
Allow: /
Sitemap: https://adesz.fr/sitemap-index.xml
```

**Step 4: Build and verify**

```bash
npm run build
```
Check that sitemap is generated, meta tags are present in HTML output.

**Step 5: Commit**

```bash
git add -A && git commit -m "feat: SEO meta tags, sitemap, robots.txt"
```

---

### Task 19: GitHub repo + CI/CD deploy to OVH via FTP

**Files:**
- Create: `.github/workflows/deploy.yml`
- Create: `.gitignore`

**Step 1: Create .gitignore**

```
node_modules/
dist/
.astro/
.env
```

**Step 2: Create GitHub Actions workflow**

`.github/workflows/deploy.yml`:
```yaml
name: Build and Deploy to OVH

on:
  push:
    branches: [main]
  workflow_dispatch:

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
      - run: npm ci
      - run: npm run build
      - name: Deploy to OVH via FTP
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.FTP_HOST }}
          username: ${{ secrets.FTP_USER }}
          password: ${{ secrets.FTP_PASSWORD }}
          local-dir: ./dist/
          server-dir: ./www/
```

Secrets to configure on GitHub:
- `FTP_HOST`: ftp.cluster0XX.hosting.ovh.net (from OVH panel)
- `FTP_USER`: OVH FTP username
- `FTP_PASSWORD`: OVH FTP password

**Step 3: Create GitHub repo and push**

```bash
gh repo create adesz.fr --public --source=. --push
```

**Step 4: Commit**

```bash
git add -A && git commit -m "ci: github actions deploy to OVH via FTP"
```

---

### Task 20: Final review and polish

**Step 1: Full build test**

```bash
npm run build
```

**Step 2: Visual review**

```bash
npm run preview
```

Check all pages at http://localhost:4321:
- [ ] Homepage: hero, stats, domaines, articles, CTA
- [ ] Presentation pages (3)
- [ ] Domaines d'action
- [ ] Projets list + detail
- [ ] Realisations list + detail
- [ ] Presse list + detail
- [ ] Partenaires
- [ ] Adherer/don
- [ ] Contact
- [ ] FAQ
- [ ] Videos
- [ ] Mobile responsive on all pages
- [ ] All links work
- [ ] Images load

**Step 3: Fix any issues found**

**Step 4: Final commit**

```bash
git add -A && git commit -m "polish: final review and fixes"
```
