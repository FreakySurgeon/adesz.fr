# CLAUDE.md — adesz.fr

## Project

Site statique de l'association ADESZ (adesz.fr) — refonte depuis WordPress/Elementor.

## Stack

- **Frontend**: Astro 5 + Tailwind CSS v4 (via `@tailwindcss/vite`)
- **CMS**: WordPress headless (REST API sur adesz.fr)
- **Deploiement**: GitHub Actions → FTP sur OVH mutualise
- **Formulaire**: Formspree (placeholder)
- **Paiements**: Stripe Checkout (dons + adhésions)
- **Sync adhérents**: Stripe webhook → Brevo API (PHP)
- **Newsletter**: Brevo (en attente setup)

## Commands

| Command | Description |
|---------|-------------|
| `npm run dev` | Dev server (localhost:4321) |
| `npm run build` | Build statique dans `dist/` |
| `npm run preview` | Preview du build |
| `npx astro check` | Type-check TypeScript |

## Architecture

- `src/lib/wordpress.ts` — Client API WordPress (types, constantes CATEGORIES/PAGES, fonctions fetch)
- `src/layouts/BaseLayout.astro` — Layout principal (Header + Footer + SEO meta)
- `src/components/` — Composants reutilisables (Hero, Cards, CTA, etc.)
- `src/pages/` — Pages Astro (statiques et dynamiques via [slug])
- `public/images/` — Assets statiques (logo, photos, favicon)
- `public/api/create-checkout.php` — Création sessions Stripe Checkout (dons/adhésions/combo)
- `public/api/stripe-webhook.php` — Webhook Stripe → Brevo (sync contacts)
- `public/api/config.php` — Config Stripe + Brevo (placeholders remplacés par CI)
- `public/api/.htaccess` — Protection fichiers sensibles (JSON)
- `scripts/setup-brevo.php` — Script one-time setup Brevo (attributs + listes)

## Charte graphique

| Role | Hex |
|------|-----|
| Vert primaire | `#2D7A3A` |
| Vert fonce | `#1B5E27` |
| Jaune accent | `#F5C518` |
| Fond sections | `#F8F7F4` |
| Texte | `#2D3436` |

Police : Poppins (via @fontsource)

## WordPress API

Base: `https://adesz.fr/wp-json/wp/v2`

Categories cles: Projets en cours (311), Realisations (314), Presse (317)

## Webhook Stripe → Brevo

Le webhook `stripe-webhook.php` écoute `checkout.session.completed` et `invoice.paid`, puis upsert le contact dans Brevo avec les attributs : PRENOM, NOM, ADRESSE, CODE_POSTAL, COMMUNE, TELEPHONE, TYPE, DATE_ADHESION, MONTANT, FREQUENCE, DATE_DERNIER_PAIEMENT. Trois listes Brevo : Adhérents, Donateurs, Tous.

En cas d'échec Brevo : 2 retries → fallback `failed_contacts.json` → email notification admin.

### En attente (étapes manuelles)
1. **Compte Brevo** — email à valider par Abakar → récupérer clé API → `.env` → `php scripts/setup-brevo.php`
2. **Clé Stripe live** — à récupérer avec Abakar → `gh secret set STRIPE_SECRET_KEY_LIVE` → créer webhook live
3. **Import Excel adhérents** — Abakar envoie le fichier → import CSV dans Brevo
4. **Former Abakar** à Brevo

### Docs design
- `docs/plans/2026-03-02-stripe-brevo-webhook-design.md`
- `docs/plans/2026-03-02-stripe-brevo-webhook.md`

## Secrets GitHub Actions

- `FTP_HOST` — Serveur FTP OVH
- `FTP_USER` — Utilisateur FTP OVH
- `FTP_PASSWORD` — Mot de passe FTP OVH
- `STRIPE_SECRET_KEY_LIVE` — Clé secrète Stripe (production)
- `STRIPE_SECRET_KEY_TEST` — Clé secrète Stripe (test)
- `STRIPE_WEBHOOK_SECRET_LIVE` — Secret webhook Stripe (production)
- `STRIPE_WEBHOOK_SECRET_TEST` — Secret webhook Stripe (test)
- `BREVO_API_KEY` — Clé API Brevo
- `BREVO_LIST_ADHERENTS` — ID liste Brevo "Adhérents"
- `BREVO_LIST_DONATEURS` — ID liste Brevo "Donateurs"
- `BREVO_LIST_TOUS` — ID liste Brevo "Tous"
- `ADMIN_EMAIL` — Email de notification (échecs sync Brevo)
- `BREVO_LIST_NEWSLETTER` — ID liste Brevo "Newsletter ADESZ"
