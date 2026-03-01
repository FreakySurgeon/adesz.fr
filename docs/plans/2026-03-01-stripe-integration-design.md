# Intégration Stripe — ADESZ

## Contexte

Ajout de paiements en ligne (dons ponctuels/récurrents + adhésions) via Stripe Checkout sur le site statique adesz.fr, hébergé sur OVH mutualisé.

## Décisions

| Question | Choix |
|----------|-------|
| Méthode de paiement | Stripe Checkout (redirection) |
| Backend | Script PHP cURL sur OVH (pas de SDK, pas de Composer) |
| Récurrence | Ponctuel + Mensuel + Annuel |
| Montants | Suggérés (10€, 25€, 50€, 100€) + libre |
| HelloAsso | Retiré, remplacé par Stripe |
| Virement/Chèque | Conservés comme alternatives |

## Architecture

```
Page /adherer (JS client)
    │
    ├─ Formulaire : montant + fréquence + type
    │
    ├─ fetch() POST → /api/create-checkout.php
    │                    │
    │                    ├─ Valide données (montant >= 1€)
    │                    ├─ cURL POST → Stripe API
    │                    │   (crée Checkout Session)
    │                    └─ Retourne { url: "https://checkout.stripe.com/..." }
    │
    ├─ window.location = url (redirection Stripe)
    │
    └─ Retour : /merci (succès) ou /adherer (annulation)
```

## Fichiers à créer/modifier

### Nouveaux fichiers

| Fichier | Description |
|---------|-------------|
| `.env` | Clés Stripe (test + production) — non versionné |
| `public/api/create-checkout.php` | Endpoint PHP (cURL vers Stripe API) |
| `src/pages/merci.astro` | Page de confirmation post-paiement |

### Fichiers modifiés

| Fichier | Modification |
|---------|-------------|
| `src/pages/adherer.astro` | Refonte : formulaire interactif avec montants + fréquence + JS client |
| `src/components/DonationCTA.astro` | Mise à jour du lien CTA |
| `astro.config.mjs` | Injection variable d'env `STRIPE_PUBLIC_KEY` |

## Page /adherer — UX

3 modes de paiement :

### 1. Don libre
- Montants suggérés : 10€, 25€, 50€, 100€
- Champ libre pour montant personnalisé
- Fréquence : Ponctuel | Mensuel | Annuel

### 2. Adhésion
- Montant fixe : 15€/an
- Pas de choix de fréquence (annuel uniquement)

### 3. Don + Adhésion (recommandé)
- Adhésion 15€ + don libre
- Fréquence applicable au don uniquement

## Backend PHP — create-checkout.php

### Entrée (POST JSON)
```json
{
  "amount": 2500,
  "frequency": "one_time|monthly|yearly",
  "type": "don|adhesion|combo"
}
```

- `amount` : montant en centimes (minimum 100 = 1€)
- `frequency` : `one_time`, `monthly`, `yearly`
- `type` : détermine les line_items

### Logique

1. Valider les entrées (montant >= 100 centimes, fréquence valide)
2. Construire les `line_items` selon le type :
   - **don** : 1 item avec le montant choisi
   - **adhesion** : 1 item fixe à 1500 centimes
   - **combo** : 2 items (adhésion + don)
3. Pour les paiements récurrents : utiliser `mode: 'subscription'` avec `recurring.interval`
4. Pour les ponctuels : utiliser `mode: 'payment'`
5. cURL POST vers `https://api.stripe.com/v1/checkout/sessions`
6. Retourner `{ "url": session.url }`

### Sortie
```json
{ "url": "https://checkout.stripe.com/c/pay/cs_test_..." }
```

### Gestion combo don récurrent + adhésion annuelle

Si le type est "combo" et la fréquence du don est mensuelle :
- L'adhésion reste annuelle (15€/an, récurrence yearly)
- Le don est mensuel (récurrence monthly)
- Cela nécessite 2 sessions séparées ou un seul mode subscription avec 2 prix différents

Simplification : pour le combo, le don adopte la même fréquence que l'adhésion (annuel). Si don mensuel souhaité, faire un don séparé.

## Variables d'environnement (.env)

```
STRIPE_PUBLIC_KEY_TEST=pk_test_...
STRIPE_SECRET_KEY_TEST=sk_test_...
STRIPE_PUBLIC_KEY_LIVE=pk_live_...
STRIPE_SECRET_KEY_LIVE=sk_live_...
STRIPE_MODE=test
```

- Clé publique : injectée dans le HTML via `import.meta.env.PUBLIC_STRIPE_KEY`
- Clé secrète : lue par PHP uniquement (fichier config séparé ou .env PHP)

## Sécurité

- Clé secrète Stripe uniquement côté PHP
- Validation montant côté serveur (min 1€, max 10 000€)
- CORS : `Access-Control-Allow-Origin: https://adesz.fr`
- Headers JSON requis
- Pas de stockage de données de carte (géré par Stripe)

## Page /merci

- Message de remerciement
- Rappel déductibilité fiscale (66% pour particuliers, 60% pour entreprises)
- Bouton retour accueil

## Déploiement

Le fichier PHP est dans `public/api/` → copié tel quel dans `dist/api/` au build → déployé via FTP sur OVH.

La configuration Stripe (clé secrète) sera dans un fichier PHP séparé (`api/config.php`) uploadé manuellement sur OVH (pas dans le repo git).
