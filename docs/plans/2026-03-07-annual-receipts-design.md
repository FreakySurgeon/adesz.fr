# Design : Reçu fiscal annuel cumulé + saisie manuelle des dons

**Date** : 2026-03-07
**Statut** : Validé

## Contexte

Les donateurs récurrents (mensuels/annuels) reçoivent actuellement un reçu fiscal à chaque paiement. Il faut un reçu annuel cumulé envoyé début janvier, couvrant tous les dons de l'année (Stripe + hors Stripe). Cela nécessite aussi de pouvoir saisir les dons hors Stripe (chèques, espèces, virements, HelloAsso).

## 1. Base de données — table `donations`

Utilise la BDD MySQL existante sur OVH (celle de WordPress), mais sans préfixe WP — table indépendante.

| Colonne | Type | Description |
|---------|------|-------------|
| id | INT AUTO_INCREMENT | PK |
| email | VARCHAR(255) NULL | Email du donateur (optionnel pour dons hors Stripe) |
| prenom | VARCHAR(255) | |
| nom | VARCHAR(255) | |
| adresse | VARCHAR(500) | |
| cp | VARCHAR(10) | |
| commune | VARCHAR(255) | |
| amount | DECIMAL(10,2) | Montant en euros |
| date_don | DATE | Date du don |
| type | ENUM('don','adhesion','combo') | |
| mode_paiement | VARCHAR(50) | 'carte', 'cheque', 'especes', 'virement', 'helloasso' |
| source | ENUM('stripe','manual','csv') | Origine de l'enregistrement |
| stripe_payment_id | VARCHAR(255) NULL | ID Stripe (si applicable) |
| receipt_number | VARCHAR(50) NULL | N° du reçu unitaire (si déjà envoyé) |
| annual_receipt_number | VARCHAR(50) NULL | N° du reçu annuel cumulé |
| created_at | DATETIME | |

## 2. Flux de données

### 3 sources alimentent la table `donations`

1. **Webhook Stripe** — `stripe-webhook.php` insère automatiquement chaque don dans `donations` (en plus de la sync Brevo existante)
2. **Formulaire admin** — Page PHP protégée par auth WordPress, saisie unitaire (chèque, espèces, virement, HelloAsso)
3. **Import CSV one-time** — Script `scripts/import-donations.php` pour l'import initial historique (fait une seule fois ensemble)

### Regroupement des donateurs pour le reçu annuel

- Par `email` en priorité
- Fallback sur `nom + prenom + cp` quand l'email est vide
- MySQL `COLLATE utf8mb4_unicode_ci` pour gérer accents/casse

## 3. Interface admin

Page `public/api/admin/index.php`, protégée par auth WordPress (charge `wp-load.php`, vérifie que l'utilisateur est admin WP).

### Onglet 1 — Saisir un don

- Champ "Rechercher un donateur" avec **autocomplete** sur nom/prénom (recherche dans `donations`)
- Recherche insensible à la casse et aux accents (`COLLATE utf8mb4_unicode_ci`)
- Si match → pré-remplit tous les champs (prénom, nom, email, adresse, CP, commune)
- Si pas de match → saisie libre
- Champs : prénom, nom, email (optionnel), adresse, CP, commune, montant, date, type (don/adhésion), mode de paiement (chèque/espèces/virement/helloasso)
- Bouton "Enregistrer" + confirmation visuelle

### Onglet 2 — Reçus annuels

1. Sélecteur d'année → bouton "Prévisualiser"
2. Affiche le récap : nombre de donateurs, total des dons, liste des donateurs avec cumul
3. **Bouton "Aperçu PDF"** → génère un exemple de reçu annuel et l'affiche dans le navigateur
4. **Bouton "Envoi test"** → envoie tous les reçus sur une seule adresse (adeszafaya@gmail.com ou celle de Thomas) pour vérification
5. **Bouton "Envoyer les reçus"** → envoi réel à chaque donateur via Brevo transactionnel
6. **Email de confirmation** envoyé à Abakar avec : nombre de reçus envoyés, liste des donateurs sans email (à traiter par courrier), total des montants

## 4. Reçu fiscal annuel cumulé (PDF)

Même charte graphique que le reçu unitaire actuel, avec les différences suivantes :

- Titre : "REÇU FISCAL ANNUEL — DONS 2025"
- **Tableau détaillé** listant chaque don : date, montant, type, mode de paiement
- Ligne de total en bas du tableau
- Montant total en chiffres et en lettres
- Mêmes mentions Cerfa que le reçu unitaire (art. 200, 238 bis, 978 du CGI)

## 5. Cron OVH + déclenchement manuel

- **Cron OVH** : le 2 janvier à 8h, exécute un script qui envoie un email à Abakar : "Les reçus fiscaux annuels 2025 sont prêts. Connectez-vous à l'interface admin pour vérifier et envoyer."
- **Déclenchement manuel** : Abakar se connecte à l'interface admin, preview → envoi test → envoi réel
- L'envoi réel n'est jamais automatique, toujours déclenché manuellement après vérification

## 6. Auth WordPress

Les scripts admin chargent `wp-load.php` pour vérifier la session WordPress. Si l'utilisateur n'est pas connecté ou n'est pas admin, redirection vers le login WP.

## 7. Architecture fichiers

```
public/api/
  config.php                     ← modifié (ajouter credentials MySQL)
  db.php                         ← NOUVEAU (connexion PDO MySQL + helpers)
  generate-receipt.php           ← existant (reçu unitaire)
  generate-annual-receipt.php    ← NOUVEAU (reçu annuel avec tableau)
  stripe-webhook.php             ← modifié (insère dans donations MySQL)
  admin/
    index.php                    ← NOUVEAU (interface admin, 2 onglets)
    api-search.php               ← NOUVEAU (endpoint autocomplete donateurs)
    api-save.php                 ← NOUVEAU (endpoint sauvegarde don)
    api-annual.php               ← NOUVEAU (endpoint preview/envoi reçus annuels)
scripts/
  import-donations.php           ← NOUVEAU (import one-time CSV → MySQL)
  setup-donations-table.php      ← NOUVEAU (création table MySQL)
  cron-annual-receipts.php       ← NOUVEAU (cron OVH → email "reçus prêts")
```

## 8. Secrets GitHub à ajouter

| Secret | Description |
|--------|-------------|
| DB_HOST | Hôte MySQL OVH |
| DB_NAME | Nom de la BDD |
| DB_USER | Utilisateur MySQL |
| DB_PASS | Mot de passe MySQL |
| ADMIN_KEY | Clé pour le cron (pas d'auth WP en CLI) |
