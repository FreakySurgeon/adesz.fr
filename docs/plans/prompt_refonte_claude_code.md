# Prompt Claude Code — Refonte Site ADESZ

> Copier-coller ce prompt dans une session Claude Code pour lancer la refonte.

---

Tu es un developpeur fullstack. Tu vas creer le nouveau site de l'association ADESZ (adesz.fr) en remplacement du site WordPress/Elementor actuel.

## Contexte

ADESZ est une association loi 1901 pour le developpement du village de Zafaya au Tchad (education, sante, agriculture). Site actuel : adesz.fr (WordPress + Elementor sur OVH mutualise). Objectif : migrer vers un site statique moderne, maintenable, versionne sous Git.

## Stack technique

- **Frontend** : Astro 5+ avec Tailwind CSS
- **CMS** : WordPress headless (REST API) — le WP actuel sur OVH reste comme backend d'edition
- **Paiements** : Stripe Payment Links (dans un premier temps)
- **Deploiement** : GitHub Actions → Cloudflare Pages
- **Domaine** : adesz.fr (DNS OVH, a pointer vers Cloudflare Pages)

## Structure du site

Reproduis la structure du site actuel :

1. **Accueil** — Hero avec mission + CTA don, chiffres d'impact (salles construites, eleves, etc.), apercu des projets, derniers articles, CTA adhesion
2. **Presentation** — 3 sous-pages : Le Pays (Tchad), Le Village (Zafaya), L'Association (ADESZ)
3. **Domaines d'action** — Cards : Sante, Education, Agriculture, Developpement, Urgences
4. **Projets** — Liste dynamique depuis WP (projets en cours)
5. **Nos realisations** — Liste dynamique depuis WP (projets termines)
6. **Nos partenaires** — Page avec logos/descriptions partenaires
7. **Articles de presse** — Blog dynamique depuis WP
8. **Adherer / Faire un don** — 3 options : don seul, adhesion seule (15 EUR), don + adhesion. Integration Stripe Payment Links. Mention deductibilite fiscale 66%/60%.
9. **Nous contacter** — Formulaire de contact
10. **FAQ** — Section questions frequentes
11. **Zafaya en Video** — Embeds YouTube

## Charte graphique

- **Couleurs** : Bleu primaire (#0073a8), jaune accent (#FFEE00), fond blanc/gris clair
- **Typo** : Poppins (Google Fonts)
- **Style** : ONG/charity professionnel, moderne, epure. S'inspirer du theme "Alone" actuel mais en plus clean.
- **Logo** : Utiliser le logo ADESZ existant (dans `/images/`). Le logo sera refait plus tard (detailler l'acronyme).

## Architecture technique

```
adesz-frontend/
├── src/
│   ├── layouts/
│   │   └── BaseLayout.astro          # Header + Footer + meta
│   ├── components/
│   │   ├── Hero.astro
│   │   ├── ImpactStats.astro
│   │   ├── ProjectCard.astro
│   │   ├── ArticleCard.astro
│   │   ├── DonationCTA.astro
│   │   ├── ContactForm.astro
│   │   └── ...
│   ├── pages/
│   │   ├── index.astro               # Accueil
│   │   ├── presentation/
│   │   │   ├── pays.astro
│   │   │   ├── village.astro
│   │   │   └── association.astro
│   │   ├── domaines.astro
│   │   ├── projets/
│   │   │   ├── index.astro           # Liste (depuis WP)
│   │   │   └── [slug].astro          # Detail projet
│   │   ├── realisations.astro
│   │   ├── partenaires.astro
│   │   ├── presse/
│   │   │   ├── index.astro           # Blog (depuis WP)
│   │   │   └── [slug].astro          # Article detail
│   │   ├── adherer.astro
│   │   ├── contact.astro
│   │   ├── faq.astro
│   │   └── videos.astro
│   ├── lib/
│   │   └── wordpress.ts              # Fonctions fetch WP REST API
│   └── styles/
│       └── global.css
├── public/
│   └── images/                        # Logo, images statiques
├── astro.config.mjs
├── tailwind.config.mjs
├── package.json
└── .github/
    └── workflows/
        └── deploy.yml                 # Build + deploy Cloudflare Pages
```

## Integration WordPress headless

Le WordPress existant (adesz.fr/wp-json/wp/v2/) sera utilise comme source de donnees :

```typescript
// src/lib/wordpress.ts
const WP_API = "https://adesz.fr/wp-json/wp/v2";

export async function getPosts(params?: Record<string, string>) {
  const url = new URL(`${WP_API}/posts`);
  if (params) Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  const res = await fetch(url);
  return res.json();
}

export async function getPages() { ... }
export async function getMedia(id: number) { ... }
```

Au build, Astro fetch les articles/pages via l'API REST et genere les pages statiques.

## Stripe Payment Links

Pour la page "Adherer / Faire un don" :
- Creer 3 boutons/cards :
  1. **Don libre** → Stripe Payment Link (montant au choix)
  2. **Adhesion** → Stripe Payment Link (15 EUR fixe)
  3. **Don + Adhesion** → Stripe Payment Link (15 EUR + montant libre)
- Mentionner la deductibilite fiscale (66% particuliers, 60% entreprises)
- Garder le lien HelloAsso en alternative
- Mentionner les autres moyens : virement (IBAN FR76 3000 3015 0900 0372 6041 709), cheque

## CI/CD

```yaml
# .github/workflows/deploy.yml
name: Deploy to Cloudflare Pages
on:
  push:
    branches: [main]
  repository_dispatch:
    types: [wordpress_publish]  # Webhook WP

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
      - run: npm ci
      - run: npm run build
      - uses: cloudflare/wrangler-action@v3
        with:
          command: pages deploy dist --project-name=adesz-site
        env:
          CLOUDFLARE_API_TOKEN: ${{ secrets.CF_API_TOKEN }}
```

## Consignes

1. Commence par initialiser le projet Astro avec Tailwind
2. Cree le layout de base (header avec navigation responsive, footer)
3. Developpe la page d'accueil en premier (hero, stats, projets, CTA)
4. Ajoute les pages statiques (presentation, domaines, FAQ, contact, videos)
5. Integre l'API WordPress pour les pages dynamiques (articles, projets)
6. Ajoute la page dons/adhesion avec les liens Stripe
7. Configure le deploiement GitHub Actions → Cloudflare Pages
8. Optimise : SEO, images, accessibilite, responsive

Pour le contenu textuel des pages statiques, scrape le site actuel adesz.fr pour recuperer les textes existants.
