# Stripe Invoicing — Factures automatisées avec mentions fiscales

**Date** : 2026-03-01
**Statut** : Approuvé

## Objectif

Activer la génération automatique de factures/reçus Stripe pour tous les paiements (dons, adhésions, combos) avec les mentions légales permettant de servir de reçu fiscal.

## Décisions

| Question | Choix |
|----------|-------|
| Reçu Stripe auto | Oui — via `invoice_creation[enabled]` pour paiements ponctuels |
| Abonnements | Stripe génère déjà des invoices — on ajoute les mentions fiscales |
| Cerfa officiel | Non — on utilise les custom fields/footer de l'invoice Stripe comme reçu fiscal simplifié |
| Envoi email | Natif Stripe — à activer dans Dashboard > Settings > Customer emails |

## Architecture

### Modifications PHP (create-checkout.php)

**Mode `payment` (ponctuels)** — ajouter `invoice_creation` :

```php
$params['invoice_creation'] = [
    'enabled' => true,
    'invoice_data' => [
        'description' => 'Reçu — ADESZ',
        'footer' => 'ADESZ — Association loi 1901 d\'intérêt général — '
                   . 'Article 200 du CGI : ce don ouvre droit à une réduction '
                   . 'd\'impôt de 66% dans la limite de 20% du revenu imposable.',
        'custom_fields' => [
            ['name' => 'Type', 'value' => '<Don|Adhésion|Don + Adhésion>'],
            ['name' => 'Objet', 'value' => 'Soutien aux actions de l\'ADESZ au Tchad'],
        ],
    ],
];
```

**Mode `subscription` (récurrents)** — ajouter dans `subscription_data` :

```php
$params['subscription_data']['invoice_settings'] = [
    'issuer' => ['type' => 'self'],
];
```

Les mentions footer/custom fields pour les subscriptions sont configurées via le template dans le dashboard Stripe (car `invoice_data` n'est pas supporté dans `subscription_data`).

### Configuration Dashboard Stripe (manuelle)

1. **Branding** (Settings > Branding) : logo ADESZ, couleur `#2D7A3A`
2. **Customer emails** (Settings > Customer emails) : activer "Paiements réussis" + "Factures finalisées"
3. **Invoice template** : créer un template par défaut avec le footer mentions légales
4. **Infos publiques** (Settings > Public) : nom "ADESZ", adresse, site web

### Ce qui ne change pas

- Frontend (adherer.astro) — aucune modification
- Métadonnées adhérent — restent dans payment_intent/subscription metadata
- Page merci (merci.astro) — aucune modification
- Workflows CI — aucune modification

## Mentions légales

- **Footer** : "ADESZ — Association loi 1901 d'intérêt général — Article 200 du CGI : ce don ouvre droit à une réduction d'impôt de 66% dans la limite de 20% du revenu imposable."
- **Custom field "Type"** : Don / Adhésion / Don + Adhésion (dynamique selon le type)
- **Custom field "Objet"** : "Soutien aux actions de l'ADESZ au Tchad"
