# Design : Templates & Automatisations Brevo ADESZ

Date : 2026-03-06

## Contexte

L'asso ADESZ a besoin d'envoyer des emails ponctuels (newsletters, annonces) et automatiques (relances, anniversaires). Abakar (non technique) doit pouvoir envoyer des newsletters depuis l'interface Brevo en dupliquant un template pre-configure.

## Decisions

- **Envois ponctuels** (pas de rythme fixe) — Abakar envoie quand il a quelque chose a dire
- **Ton** : automatisations personnelles (signe Abakar), newsletters/annonces institutionnelles (signe L'equipe ADESZ)
- **Pas de bienvenue separee** — le recu fiscal (deja en place) suffit comme premier contact
- **Adhesion = a vie** (paiement unique de 15EUR), pas de renouvellement
- **Tout via API Brevo** (templates + workflows), avec fallback UI si l'API ne supporte pas certains triggers
- **Execution locale** du script (`php scripts/setup-brevo-campaigns.php`), pas uploade sur le serveur

## Donnees Brevo existantes

### Attributs contacts
PRENOM, NOM, ADRESSE, CODE_POSTAL, COMMUNE, TELEPHONE, TYPE (adherent/donateur/combo), DATE_ADHESION, MONTANT, FREQUENCE (one_time/monthly/yearly), DATE_DERNIER_PAIEMENT

### Listes
| ID | Nom | Contacts |
|----|-----|----------|
| 3 | Adherents ADESZ | 246 |
| 4 | Donateurs ADESZ | 152 |
| 5 | Tous les contacts ADESZ | 336 |
| 6 | Newsletter ADESZ | 391 |

## Templates manuels (Abakar duplique et envoie)

### T1 — Newsletter ADESZ
- **Usage** : Nouvelles du terrain, actus de l'asso
- **Structure** : Intro + 1-3 blocs article (image + texte + lien "Lire la suite") + CTA don
- **Liste cible** : Newsletter (6)
- **Signe** : L'equipe ADESZ

### T2 — Annonce projet/realisation
- **Usage** : Presenter un nouveau projet ou une realisation terminee
- **Structure** : Grande image hero, description, impact chiffre, CTA don
- **Liste cible** : Newsletter (6)
- **Signe** : L'equipe ADESZ

### T3 — Appel aux dons
- **Usage** : Appel cible pour un besoin specifique (urgence, campagne)
- **Structure** : Ton direct, montant cible, barre de progression visuelle, CTA don
- **Liste cible** : Tous (5) ou segment specifique
- **Signe** : Abakar

### T4 — Invitation evenement
- **Usage** : Evenement (AG, soiree solidaire, etc.)
- **Structure** : Date/lieu/heure, description, CTA inscription/participation
- **Liste cible** : Newsletter (6)
- **Signe** : L'equipe ADESZ

## Templates automatises

### A1 — Anniversaire adhesion + appel don
- **Usage** : Chaque annee a DATE_ADHESION, "Ca fait X an(s) que vous etes membre, soutenez-nous avec un don"
- **Signe** : Abakar

### A2 — Relance donateur ponctuel
- **Usage** : 6 mois apres un don one_time, nouvelles de l'impact + invitation a redonner
- **Signe** : Abakar

### A4 — Reactivation inactif
- **Usage** : 18 mois sans activite, ton doux, "vous nous manquez"
- **Signe** : Abakar

## Workflows (automatisations)

### W1 — Anniversaire adhesion + appel don
- **Trigger** : DATE_ADHESION anniversary (chaque annee, jour/mois)
- **Cible** : Liste Adherents (3), TYPE = "adherent" ou "combo"
- **Action** : Envoi template A1
- **Recurrence** : Annuelle

### W2 — Relance donateur ponctuel
- **Trigger** : DATE_DERNIER_PAIEMENT + 180 jours
- **Cible** : Liste Donateurs (4), FREQUENCE = "one_time"
- **Action** : Envoi template A2
- **Garde-fou** : Skip si nouveau don ou adhesion entre-temps

### W3 — Reactivation inactif
- **Trigger** : DATE_DERNIER_PAIEMENT + 540 jours (18 mois)
- **Cible** : Liste Tous (5), FREQUENCE = "one_time"
- **Action** : Envoi template A4
- **Garde-fou** : Exclure FREQUENCE "monthly" ou "yearly"

## Implementation technique

### Script : `scripts/setup-brevo-campaigns.php`

Execution locale : `BREVO_API_KEY=xkeysib-... php scripts/setup-brevo-campaigns.php`

1. Verifier templates existants (`GET /v3/smtp/templates`) — skip si deja crees
2. Creer les 7 templates (`POST /v3/smtp/templates`) avec HTML inline charte ADESZ
3. Tenter de creer les 3 workflows (`POST /v3/workflows`)
4. Si workflow echoue → log les parametres pour configuration UI manuelle

### Layout HTML commun

- Table-based (compatibilite email clients)
- Inline CSS uniquement
- Max-width 600px, images fluides
- Header vert #2D7A3A avec logo ADESZ
- CTA jaune #F5C518 → adesz.fr/adherer
- Footer : adresse asso + lien desabonnement
- Font : Arial (Poppins non fiable en email)

### Charte couleurs
| Role | Hex |
|------|-----|
| Header/accent | #2D7A3A |
| Header fonce | #1B5E27 |
| CTA bouton | #F5C518 |
| Fond body | #F8F7F4 |
| Texte | #2D3436 |
