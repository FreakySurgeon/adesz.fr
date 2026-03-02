# Design : Webhook Stripe → Brevo (sync adhérents)

Date : 2026-03-02

## Contexte

L'ADESZ collecte dons et adhésions via Stripe Checkout. Les données membres (nom, prénom, adresse, etc.) sont stockées en metadata Stripe. Brevo (ex-Sendinblue) est l'outil choisi pour centraliser la base adhérents et envoyer les newsletters. Le président Abakar n'utilisera que Brevo au quotidien.

## Objectif

Synchroniser automatiquement les contacts vers Brevo après chaque paiement Stripe réussi, avec des listes séparées pour adhérents et donateurs.

## Architecture

Script PHP monolithique (`public/api/stripe-webhook.php`), cohérent avec le pattern existant (`create-checkout.php`). Pas de dépendances externes (pas de Composer sur OVH mutualisé).

## Flux de données

```
Stripe (POST webhook)
  → stripe-webhook.php
    → Vérifie signature HMAC SHA256 (header Stripe-Signature)
    → Parse l'événement JSON
    → Selon le type d'événement :
      - checkout.session.completed : paiement initial
      - invoice.paid : renouvellements de subscriptions
    → Récupère customer_email depuis la session
    → Appel API Stripe pour récupérer les metadata depuis payment_intent ou subscription
    → POST https://api.brevo.com/v3/contacts (upsert contact)
    → Ajoute aux listes Brevo appropriées
    → Retourne 200 à Stripe
```

## Événements Stripe écoutés

| Événement | Cas d'usage |
|-----------|-------------|
| `checkout.session.completed` | Paiement initial (one-time ou première subscription) |
| `invoice.paid` | Renouvellements de subscriptions |

## Extraction des données

- **Email** : `session.customer_email` (toujours présent)
- **Metadata membre** : depuis le payment_intent (one-time) ou la subscription (recurring) — nécessite un appel API Stripe supplémentaire
- **Type** : depuis les metadata (`don`, `adhesion`, `combo`)
- **Montant** : `session.amount_total`
- **Fréquence** : `session.mode` (payment → one_time) + metadata si subscription

### Cas par type

| Type | Données disponibles |
|------|---------------------|
| `don` | Email Stripe uniquement (pas de formulaire membre) |
| `adhesion` | Email + metadata complètes (nom, prénom, adresse, etc.) |
| `combo` | Email + metadata complètes |

## Attributs Brevo

| Attribut | Type | Description |
|----------|------|-------------|
| PRENOM | text | Prénom |
| NOM | text | Nom |
| ADRESSE | text | Adresse postale |
| CODE_POSTAL | text | Code postal |
| COMMUNE | text | Commune |
| TELEPHONE | text | Téléphone |
| TYPE | text | "adherent", "donateur", "combo" |
| DATE_ADHESION | date | Date du premier paiement |
| MONTANT | number | Montant en euros |
| FREQUENCE | text | "one_time", "monthly", "yearly" |
| DATE_DERNIER_PAIEMENT | date | Mis à jour à chaque paiement |

Ces attributs doivent être créés manuellement dans Brevo avant le premier test.

## Listes Brevo

| Liste | Contenu | ID configuré dans |
|-------|---------|-------------------|
| Adhérents | type = adhesion ou combo | `$brevo_list_adherents` |
| Donateurs | type = don | `$brevo_list_donateurs` |
| Tous | tous les contacts | `$brevo_list_tous` |

Les listes doivent être créées manuellement dans Brevo. Leurs IDs numériques sont configurés dans `config.php`.

**Upsert** : `POST /v3/contacts` avec `updateEnabled: true` — crée le contact s'il n'existe pas, met à jour s'il existe (basé sur l'email).

## Gestion des erreurs Brevo

Mécanisme en 3 niveaux pour ne jamais perdre de données :

1. **Retry** : 2 tentatives avec pause de 1 seconde entre les deux
2. **Fichier fallback** : en cas d'échec persistant, sauvegarde du contact dans `api/failed_contacts.json` (append)
3. **Email de notification** : envoi d'un email d'alerte via `mail()` PHP à l'adresse admin configurée

Le webhook retourne toujours 200 à Stripe (les données sont sauvegardées localement en cas d'échec Brevo).

## Sécurité

- **Signature Stripe** : HMAC SHA256 avec `hash_hmac()`, comparaison du header `Stripe-Signature`
- **Tolérance temporelle** : 5 minutes (anti-replay)
- **Pas de CORS** : appel serveur-à-serveur depuis Stripe
- **Clés API** : uniquement côté serveur (PHP), injectées par CI

## Configuration

Nouvelles variables dans `config.php` :

```php
$stripe_webhook_secret = 'whsec_REPLACE_ME';
$brevo_api_key = 'xkeysib-REPLACE_ME';
$brevo_list_adherents = 0;
$brevo_list_donateurs = 0;
$brevo_list_tous = 0;
$admin_email = 'admin@REPLACE_ME';
```

## Secrets GitHub Actions

| Secret | Workflow | Description |
|--------|----------|-------------|
| `STRIPE_WEBHOOK_SECRET_LIVE` | deploy.yml | Secret webhook Stripe (production) |
| `STRIPE_WEBHOOK_SECRET_TEST` | deploy-test.yml | Secret webhook Stripe (test) |
| `BREVO_API_KEY` | les deux | Clé API Brevo |
| `BREVO_LIST_ADHERENTS` | les deux | ID liste Brevo "Adhérents" |
| `BREVO_LIST_DONATEURS` | les deux | ID liste Brevo "Donateurs" |
| `BREVO_LIST_TOUS` | les deux | ID liste Brevo "Tous" |
| `ADMIN_EMAIL` | les deux | Email de notification en cas d'erreur |

## Fichiers impactés

| Fichier | Action |
|---------|--------|
| `public/api/stripe-webhook.php` | Créer |
| `public/api/config.php` | Modifier (nouvelles variables) |
| `.github/workflows/deploy.yml` | Modifier (injection secrets) |
| `.github/workflows/deploy-test.yml` | Modifier (injection secrets) |
| `CLAUDE.md` | Modifier (documenter nouveaux secrets) |
