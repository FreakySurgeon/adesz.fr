# Design : Champs donateur + Conformité Cerfa n° 11580

**Date** : 2026-03-07
**Statut** : Validé

## Contexte

Le reçu fiscal doit être conforme au Cerfa n° 11580 pour permettre la déduction d'impôt. Actuellement, l'onglet "Faire un don" ne collecte aucune info personnelle, et le PDF manque certains éléments obligatoires.

## 1. Formulaire de don — Champs donateur allégés

Ajouter 5 champs dans l'onglet "Faire un don", entre la fréquence et le récapitulatif, avec le label **"Pour votre reçu fiscal"**.

| Champ | Type | Obligatoire | Placeholder |
|-------|------|-------------|-------------|
| Prénom | text | oui | Prénom |
| Nom | text | oui | Nom |
| Adresse | text | oui | Adresse |
| Code postal | text | oui | Code postal |
| Commune | text | oui | Commune |

- Même style que le `membership-section` existant
- Layout : prénom + nom sur une ligne, adresse pleine largeur, CP + commune sur une ligne
- Bouton "Payer" désactivé tant que les 5 champs ne sont pas remplis
- Pas de tél, date de naissance ni email (Stripe collecte l'email)

## 2. Reçu fiscal PDF — Mise en conformité Cerfa

Éléments à ajouter au PDF existant (`generate-receipt.php`) :

| Élément | Action |
|---------|--------|
| Montant en lettres | Ajouter sous le montant en chiffres (fonction PHP `nombre_en_lettres()`) |
| Nature du don | Ajouter "Don numéraire" / "Cotisation numéraire" |
| Mention Cerfa complète | Remplacer la mention partielle par : "L'organisme certifie que les dons et versements qu'il reçoit ouvrent droit à la réduction d'impôt prévue aux articles 200, 238 bis et 978 du Code Général des Impôts" |
| Qualité d'intérêt général | Ajouter "Association d'intérêt général" dans le bloc bénéficiaire |

Ce qui est déjà conforme (pas de changement) :
- Numéro d'ordre unique (ADESZ-YYYY-NNN)
- Infos organisme (nom, adresse, objet, n° préfecture)
- Infos donateur (nom, prénom, adresse)
- Date et mode de versement
- Référence aux articles du CGI

## 3. Backend — Transmission des données donateur

- **JS (`adherer.astro`)** : collecter les champs donateur pour le type `don` et les envoyer dans le champ `member` (même format que adhésion/combo, sans birthdate/tel/email)
- **`create-checkout.php`** : aucun changement nécessaire (gère déjà `member` quel que soit le type)
- **`stripe-webhook.php`** : aucun changement nécessaire (lit déjà les metadata pour tous les types)

## Fichiers impactés

1. `src/pages/adherer.astro` — HTML (nouveau bloc champs) + JS (collecte + validation)
2. `public/api/generate-receipt.php` — Mentions Cerfa + montant en lettres
