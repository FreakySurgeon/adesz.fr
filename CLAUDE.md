# CLAUDE.md — adesz.fr

## Project

Site statique de l'association ADESZ (adesz.fr) — refonte depuis WordPress/Elementor.

## Stack

- **Frontend**: Astro 5 + Tailwind CSS v4 (via `@tailwindcss/vite`)
- **CMS**: WordPress headless (REST API sur adesz.fr)
- **Deploiement**: GitHub Actions → FTP sur OVH mutualise
- **Formulaire**: Formspree (placeholder)
- **Paiements**: Stripe Payment Links (placeholder) + HelloAsso

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

## Fonctionnalites futures

### Gestion des adherents & Newsletter
- **Contact**: Abakar MAHAMAT (president ADESZ)
- **Outil choisi**: Brevo (ex-Sendinblue) — base adherents + newsletter dans un seul outil
- **Sync**: Stripe webhook → PHP sur OVH → Brevo API (auto-ajout nouveaux adherents)
- **Import initial**: Excel d'Abakar → CSV → import Brevo
- **Recus fiscaux**: PDFs Stripe (invoice_creation deja en place, mention 66%)
- **Autonomie**: Abakar donne carte blanche, n'utilise que Brevo au quotidien

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
