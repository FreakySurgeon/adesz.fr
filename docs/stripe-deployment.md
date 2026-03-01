# Déploiement Stripe — ADESZ

## Fonctionnement

Le paiement Stripe utilise un fichier PHP (`api/create-checkout.php`) déployé avec le site sur OVH. Ce fichier crée des sessions Stripe Checkout via l'API REST (cURL).

## Configuration sur OVH

Après chaque déploiement FTP, le fichier `www/api/config.php` contient les clés **test**. Pour passer en production :

1. Se connecter en FTP à OVH
2. Éditer `www/api/config.php`
3. Remplacer la clé secrète test par la clé live :

```php
$stripe_secret_key = 'sk_live_VOTRE_CLE_LIVE';
$stripe_mode = 'live';
```

4. Vérifier que `$site_url` est bien `'https://adesz.fr'`

## Clés Stripe

| Environnement | Clé publique | Clé secrète |
|---------------|-------------|-------------|
| Test | `pk_test_...` (dans `.env`) | `sk_test_...` (dans `config.php` du repo) |
| Production | `pk_live_...` (dans `.env`) | `sk_live_...` (à mettre manuellement sur OVH) |

La clé publique est injectée dans le HTML au build via la variable d'environnement `PUBLIC_STRIPE_KEY` dans le `.env`.

Pour un build de production avec la clé live, mettre à jour `PUBLIC_STRIPE_KEY` dans `.env` avec la clé `pk_live_...` avant de builder.

## Test du paiement

- Carte test : `4242 4242 4242 4242`, expiration future quelconque, CVC quelconque
- Dashboard test : https://dashboard.stripe.com/test/payments
- Dashboard live : https://dashboard.stripe.com/payments
