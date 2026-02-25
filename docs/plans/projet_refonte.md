# Refonte Site ADESZ 2026

## Contexte

**ADESZ** (Association pour le Developpement Economique et Social de Zafaya) est une association loi 1901 basee a Antibes (491 Bd Pierre Delmas, 06600 Antibes), oeuvrant pour le developpement du village de Zafaya au Tchad. President : **Abakar Mahamat** (abakarmahamat@hotmail.com). Email asso : adeszafaya@gmail.com.

### Realisations
- 3 salles de classe primaire construites (2023-2024)
- College en construction, ouverture prevue fin janvier 2026
- Don de fournitures scolaires par ONG ASMA GASSIM (oct 2025)
- Tables-bancs offerts par organisation locale
- Reconnaissance d'interet general par l'administration fiscale (2025) → dons deductibles 66% particuliers / 60% entreprises

### Projets 2026
- Relance cooperative agricole de Riz (bassin ~10 000 personnes)
- Refonte du site internet

---

## Site actuel (adesz.fr)

### Architecture technique
- **Hebergement** : OVH mutualise
- **CMS** : WordPress
- **Theme** : "Alone" (BearsThemes) — theme charity/nonprofit avec Elementor
- **Page builder** : Elementor
- **Plugins** : WooCommerce (dons?), extensions multiples (echecs de MAJ recurrents)
- **DNS/Domaine** : adesz.fr (OVH)

### Structure du site
| Page | Contenu |
|------|---------|
| Accueil | Hero, chiffres impact, causes/projets, CTA don |
| Presentation | Pays (Tchad), Village (Zafaya), Association |
| Domaines d'action | Sante, Education, Agriculture, Developpement, Urgences |
| Projets | Projets en cours et a venir |
| Nos realisations | Projets termines |
| Nos partenaires | Page partenaires |
| Articles de presse | Revue de presse |
| Adherer / Faire un don | Adhesion 15EUR + dons via HelloAsso, cheque, virement |
| Nous contacter | Formulaire de contact |
| Zafaya en Video | Section video |

### Charte graphique actuelle
- **Couleurs** : Bleu primaire (#0073a8), jaune accent (#FFEE00)
- **Typo** : Poppins
- **Style** : ONG/charity classique, cards, hero prominent
- **Logo** : ADESZ (acronyme a detailler dans la refonte du logo)

### Problemes identifies
1. Elementor = "enfer a maintenir" (verbatim Thomas)
2. Extensions WordPress en echec de MAJ (WooCommerce etc.)
3. Vestiges du theme de demo (liens vers beplusthemes.com)
4. Vulnerabilites connues du theme Alone
5. Maintenance lourde (plugins, mises a jour, securite)

---

## Decision de refonte (23/02/2026)

### Choix architectural
Apres analyse (voir `conversation_refonte_chatgpt_site_adesz.txt`), Thomas a decide :

**Astro (frontend statique) + WordPress headless (CMS backend)**

### Pourquoi ce choix
- Code versionne sous Git, deploiement CI/CD (GitHub Actions)
- Site statique ultra rapide, quasi inviolable cote securite
- Zero plugins WordPress cote frontend
- Maintenance minimale long terme
- Les benevoles continuent de publier via le back-office WP existant
- Build automatique a chaque publication (~30s)

### Stack cible
| Composant | Technologie | Hebergement |
|-----------|-------------|-------------|
| Frontend | **Astro** + Tailwind CSS | Cloudflare Pages (gratuit) |
| CMS | **WordPress** (headless, REST API) | OVH mutualise (existant) |
| Paiements | **Stripe** (Payment Links ou Checkout + Worker) | Stripe / Cloudflare Workers |
| CI/CD | GitHub Actions | GitHub |
| DNS | adesz.fr | OVH (pointer vers Cloudflare Pages) |

### Theme / Design
- Reproduire le look actuel "ONG/charity" (hero, causes, impact, CTA don)
- Options envisagees :
  - **Kindora** (theme Astro natif, charity/fundraising) — zero conversion
  - **Theme HTML Bootstrap 5** (Huruma, Trust, etc.) integre dans Astro
  - **Theme HTML Tailwind** adapte
- Conserver la charte : couleurs bleu/jaune, typo Poppins, structure ONG

### Fonctionnalites demandees (reunion 11/06/2025)
D'apres les notes manuscrites scannees (`modifs_apres_reunion_11062025.pdf`) :
1. **Refonte logo** : detailler l'acronyme ADESZ (Association D... E...)
2. **Section dons/adhesion** : permettre don seul, adhesion seule, ou les deux
3. **Nos realisations** : page dediee (existante, a conserver)
4. **Nos partenaires** : page dediee
5. **Formulaire** : formulaire complet d'adhesion
6. **FAQ** : section FAQ
7. **Contact** : Herbo (?) tagota tel : 06 63 04 66 12
8. **Video** : integration video (YouTube/embed)

### Paiements : migration HelloAsso → Stripe
- Actuellement : HelloAsso pour adhesions (15EUR) et dons
- Objectif : Stripe Payment Links (simple) ou Stripe Checkout + serverless (avance)
- Avantage : integration directe dans le site, plus de redirection externe
- HelloAsso reste en backup pendant la transition

---

## Plan de migration

### Phase 1 — Preparation
- [ ] Creer le repo GitHub `adesz-frontend` (ou `adesz-site`)
- [ ] Choisir le theme/template Astro (Kindora ou HTML Bootstrap a integrer)
- [ ] Cartographier le contenu WP existant (pages, articles, medias)
- [ ] Configurer WordPress en mode headless (API REST activee, acces public restreint)

### Phase 2 — Developpement frontend Astro
- [ ] Setup Astro + Tailwind
- [ ] Integrer le theme choisi (layout, composants, navigation)
- [ ] Pages statiques : Accueil, Presentation, Domaines d'action, Contact, FAQ
- [ ] Pages dynamiques (depuis WP API) : Articles, Projets, Realisations
- [ ] Section dons/adhesion avec Stripe
- [ ] Integration video
- [ ] SEO / meta tags / sitemap

### Phase 3 — Paiements Stripe
- [ ] Creer compte Stripe (ou configurer existant)
- [ ] Payment Links : don libre, adhesion 15EUR, don + adhesion
- [ ] Optionnel : Cloudflare Worker pour Stripe Checkout (montant custom, mensuel)

### Phase 4 — CI/CD & Deploiement
- [ ] GitHub Actions : build + deploy sur Cloudflare Pages
- [ ] Webhook WP → GitHub Actions (rebuild auto a chaque publication)
- [ ] Pointer adesz.fr vers Cloudflare Pages
- [ ] WordPress : restreindre acces public (admin only)

### Phase 5 — Validation & Transition
- [ ] Montrer le nouveau site a Abakar et aux membres du bureau
- [ ] Former les benevoles (publication inchangee cote WP)
- [ ] Go live : basculer le DNS
- [ ] Garder l'ancien site en backup temporaire

---

## Contacts
| Nom | Role | Email | Tel |
|-----|------|-------|-----|
| Abakar Mahamat | President ADESZ | abakarmahamat@hotmail.com | A retrouver |
| Julie | Dev precedente (site WP initial) | ? | ? |
| Thomas Chauvet | Membre ADESZ, dev refonte | chauvet.t@gmail.com | |
| Herbo (?) | Contact asso | ? | 06 63 04 66 12 |
