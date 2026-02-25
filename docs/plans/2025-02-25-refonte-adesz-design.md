# Design — Refonte adesz.fr

> Date : 2025-02-25
> Statut : Validee

## 1. Contexte

ADESZ est une association loi 1901 pour le developpement du village de Zafaya au Tchad (education, sante, agriculture). Le site actuel (adesz.fr) tourne sur WordPress + Elementor sur OVH mutualise. Objectif : migrer vers un site statique moderne, maintenable, versionne sous Git, tout en gardant WordPress comme backend d'edition pour les benevoles.

## 2. Architecture technique

| Composant | Choix | Details |
|-----------|-------|---------|
| **Frontend** | Astro 5 + Tailwind CSS | Site statique, SSG au build |
| **CMS** | WordPress headless (REST API) | WP existant sur OVH, source de donnees |
| **Hebergement** | OVH mutualise (existant) | Build Astro → upload fichiers statiques via FTP |
| **CI/CD** | GitHub Actions → deploy FTP sur OVH | Build auto a chaque push sur `main` |
| **Paiements** | Stripe Payment Links (placeholder) + HelloAsso | Stripe a configurer plus tard |
| **Formulaire contact** | Formspree (gratuit) ou mailto: fallback | Service tiers, zero backend |
| **Repo** | `adesz.fr` sur GitHub | Package manager : npm |

### Pourquoi OVH plutot que Cloudflare Pages

Le plan initial prevoyait CF Pages, mais l'hebergement OVH est deja en place et paye. Rester sur OVH evite la migration DNS et simplifie le setup. Le site statique Astro sera deploye via FTP dans le dossier web OVH, cohabitant avec le WordPress backend.

### WordPress headless

L'API REST WordPress (`adesz.fr/wp-json/wp/v2/`) est publiquement accessible :
- 10 posts (articles, projets, realisations)
- 10 pages (presentation, domaines d'action, etc.)
- 10 categories (Articles de Presse, Projets en cours, Nos realisations, FAQ, etc.)
- Medias accessibles

Au build Astro, le contenu est fetche via l'API et genere en HTML statique.

## 3. Structure des pages

### Pages statiques (contenu WP API → HTML au build)

| Page | Route | Contenu |
|------|-------|---------|
| Accueil | `/` | Hero, stats d'impact, apercu projets, derniers articles, CTA don |
| Le Pays | `/presentation/pays` | Presentation du Tchad |
| Le Village | `/presentation/village` | Presentation de Zafaya |
| L'Association | `/presentation/association` | Presentation d'ADESZ |
| Domaines d'action | `/domaines` | 5 cards : Sante, Education, Agriculture, Developpement, Urgences |
| Nos partenaires | `/partenaires` | Logos et descriptions partenaires |
| Adherer / Faire un don | `/adherer` | 3 options (don, adhesion 15EUR, don+adhesion), Stripe placeholder + HelloAsso |
| Contact | `/contact` | Infos + formulaire (Formspree) |
| FAQ | `/faq` | Questions frequentes |
| Zafaya en Video | `/videos` | Embeds YouTube |

### Pages dynamiques (generees depuis les posts WP par categorie)

| Page | Route | Source WP |
|------|-------|-----------|
| Projets | `/projets` + `/projets/[slug]` | Posts categorie "Projets en cours" |
| Nos realisations | `/realisations` + `/realisations/[slug]` | Posts categorie "Nos realisations" |
| Articles de presse | `/presse` + `/presse/[slug]` | Posts categorie "Articles de Presse" |

## 4. Charte graphique

### Couleurs (extraites du logo ADESZ)

| Role | Couleur | Hex |
|------|---------|-----|
| Primaire | Vert foret | `#2D7A3A` |
| Profondeur | Vert fonce | `#1B5E27` |
| Accent | Jaune soleil | `#F5C518` |
| Fond principal | Blanc | `#FFFFFF` |
| Fond sections | Gris chaud | `#F8F7F4` |
| Texte | Gris fonce | `#2D3436` |

### Typographie

- **Police** : Poppins (Google Fonts)
- **Titres** : Poppins Bold/SemiBold
- **Corps** : Poppins Regular 16px

### Style

- Chaleureux et humain, pas corporate
- Arrondis genereux sur les cards et boutons
- Photos mises en avant
- Beaucoup d'espace blanc
- Inspire des sites ONG (Action contre la Faim, Solidarites International) mais en plus leger/accessible

### Logo

Logo existant : mains vertes portant un soleil/graine jaune avec des feuilles. Symbolise la croissance, l'agriculture, l'entraide. Le logo sera refait plus tard pour detailler l'acronyme ADESZ.

## 5. Contenu

- **Pages statiques** : contenu recupere via l'API WP (`/wp-json/wp/v2/pages`) au build
- **Articles/projets** : recuperes via l'API WP (`/wp-json/wp/v2/posts?categories=X`)
- **Images** : copiees depuis le dossier gdrive + medias WP via l'API
- **Fallback** : si l'API WP manque de contenu, scraping HTML du site actuel

## 6. Deploiement

- GitHub Actions build Astro a chaque push sur `main`
- Upload des fichiers statiques via `lftp` (FTP) sur OVH
- WordPress reste accessible en back-office pour les benevoles
- Le front statique remplace les fichiers publics

## 7. Fonctionnalites dons/adhesion

- **Don libre** → Stripe Payment Link (placeholder pour l'instant)
- **Adhesion** → Stripe Payment Link 15 EUR (placeholder)
- **Don + Adhesion** → Stripe Payment Link (placeholder)
- **HelloAsso** en alternative (lien existant conserve)
- **Autres moyens** : virement (IBAN FR76 3000 3015 0900 0372 6041 709), cheque
- Mention deductibilite fiscale : 66% particuliers, 60% entreprises

## 8. Architecture fichiers

```
adesz.fr/
├── src/
│   ├── layouts/
│   │   └── BaseLayout.astro
│   ├── components/
│   │   ├── Hero.astro
│   │   ├── ImpactStats.astro
│   │   ├── ProjectCard.astro
│   │   ├── ArticleCard.astro
│   │   ├── DonationCTA.astro
│   │   ├── ContactForm.astro
│   │   └── ...
│   ├── pages/
│   │   ├── index.astro
│   │   ├── presentation/
│   │   │   ├── pays.astro
│   │   │   ├── village.astro
│   │   │   └── association.astro
│   │   ├── domaines.astro
│   │   ├── projets/
│   │   │   ├── index.astro
│   │   │   └── [slug].astro
│   │   ├── realisations/
│   │   │   ├── index.astro
│   │   │   └── [slug].astro
│   │   ├── partenaires.astro
│   │   ├── presse/
│   │   │   ├── index.astro
│   │   │   └── [slug].astro
│   │   ├── adherer.astro
│   │   ├── contact.astro
│   │   ├── faq.astro
│   │   └── videos.astro
│   ├── lib/
│   │   └── wordpress.ts
│   └── styles/
│       └── global.css
├── public/
│   └── images/
├── docs/
│   └── plans/
├── astro.config.mjs
├── tailwind.config.mjs
├── package.json
└── .github/
    └── workflows/
        └── deploy.yml
```
