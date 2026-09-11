# Woo FB Tracking Server-Side (Architecture Hybride Native v2.0.0)

> Documentation technique et guide de maintenance pour les développeurs et agents IA.
> Conçu et maintenu par **SOYOO** (Julien Vanwinsberghe - [https://soyoo.re](https://soyoo.re)).

---

## 1. Vue d'Ensemble & Philosophie d'Ingénierie

Ce plugin transforme le suivi e-commerce WooCommerce pour Meta en une **architecture hybride native** ultra-performante et résiliente, combinant :
1. **Le Pixel Navigateur front-end (`fbq`)** : Capture instantanée du haut de tunnel (`PageView`, `ViewContent`, `AddToCart` AJAX, `InitiateCheckout`) et déclenchement initial de `Purchase`.
2. **L'API de Conversions Meta Server-Side (CAPI Graph API v21.0)** : Transmission asynchrone sécurisée de l'événement `Purchase` via Action Scheduler, insensible aux bloqueurs de publicité (AdBlockers) et aux restrictions de cookies des navigateurs (ITP Safari iOS).
3. **Une déduplication parfaite à 100%** : Les événements `Purchase` front-end et serveur partagent strictement le même identifiant : `eventID: 'order_' + order_id`. Meta fusionne les signaux sans doubler les conversions ni le chiffre d'affaires.
4. **Conformité RGPD stricte asservie à Concord Cookie Banner** : Respect absolu du consentement marketing, écoute dynamique des événements d'acceptation en direct (activation à chaud sans rechargement de page), et blocage ou anonymisation CAPI côté serveur.
5. **Compatibilité native WooCommerce HPOS (High-Performance Order Storage)** : Bannissement total de l'ancienne API post-meta au profit exclusif des méthodes CRUD de l'objet `$order` (`custom_order_tables`).
6. **Normalisation E.164 avancée (La Réunion + France)** : Nettoyage et conversion automatique des préfixes réunionnais (`0692`, `0693`, `0262` $\rightarrow$ `+262`) et métropolitains (`+33`) avant hachage SHA-256.

---

## 2. Arborescence du Plugin

```text
woo-fb-tracking-server-side/
├── woo-fb-tracking-server-side.php      # Point d'entrée, déclaration HPOS, initialisation & PUC
├── AGENTS.md                            # Référentiel technique et guide développeur
├── readme.txt                           # Métadonnées WordPress & changelog pour PUC
├── plugin-update-checker/               # YahnisElsts/plugin-update-checker v5.6 (GitHub Releases)
├── includes/
│   ├── class-wfbt-core.php                 # Orchestration, hooks de commande, planification HPOS
│   ├── class-wfbt-meta-api.php             # Moteur CAPI Graph API v21.0, normalisation E.164, payload
│   ├── class-wfbt-background-processor.php # File d'attente asynchrone WooCommerce Action Scheduler
│   ├── class-wfbt-admin-settings.php       # Panneau de réglages & tableau de diagnostic des 20 commandes
│   └── class-wfbt-logger.php               # Wrapper WC_Logger ('wfbt-server-side')
├── public/
│   └── class-wfbt-public.php               # Injection Pixel fbq, capture fbclid/fbp/fbc, RGPD Concord
└── languages/                              # Fichiers de traduction i18n
```

---

## 2bis. Système de Mises à Jour Automatiques (GitHub Releases)

Le plugin intègre la bibliothèque officielle **`YahnisElsts/plugin-update-checker` (v5.6)** configurée directement sur le dépôt GitHub public :
- **Dépôt distant** : `https://github.com/SOYOO974/woo-meta-tracking-server-side/`
- **Branche stable** : `main`
- **Assets de release** : `$wfbt_update_checker->getVcsApi()->enableReleaseAssets();`
- **Changelog & Métadonnées** : Extraits dynamiquement de `readme.txt` et affichés dans la modale native des extensions WordPress lors de la notification de mise à jour.


## 3. Composant Front-End (`public/class-wfbt-public.php`)

### A. Cycle de vie du Pixel & RGPD (Bannière Concord)
- Le script `fbevents.js` n'est injecté que si `wfbt_pixel_id` est configuré et `wfbt_enable_pixel === 'yes'`.
- Si `wfbt_respect_consent === 'yes'` :
  - La fonction helper JavaScript `wfbtHasMarketingConsent()` inspecte les cookies de la session pour détecter le nom ou préfixe configuré (`concord` par défaut) et vérifier la présence de `marketing:true` ou d'un accord explicite.
  - Si le consentement n'est pas encore accordé, le Pixel n'est **pas initialisé**. Des écouteurs d'événements sont placés (`concord:consent`, `concord_consent_updated`, etc.) ainsi qu'un sondage cyclique léger (20 secondes).
  - Dès que l'utilisateur clique sur « Accepter » dans la bannière Concord, la fonction `initMetaPixel()` est appelée instantanément **sans nécessiter de rechargement de page**, déclenchant `PageView` et les événements de page en attente.

### B. Événements Front-End supportés
| Événement Meta | Déclencheur Front | Paramètres Clés envoyés |
| :--- | :--- | :--- |
| `PageView` | Toutes les pages (au chargement ou à l'acceptation) | — |
| `ViewContent` | Fiches produit (`is_product()`) | `content_ids`, `content_name`, `content_type: 'product'`, `value`, `currency` |
| `AddToCart` | Clic ou AJAX (`added_to_cart` jQuery) | `content_ids`, `content_type: 'product'`, `currency` (compatible Cart Drawers) |
| `InitiateCheckout` | Page de commande (`is_checkout()`) | `content_ids`, `contents`, `content_type: 'product'`, `value`, `currency`, `num_items` |
| `Purchase` | Page de confirmation (`is_order_received_page()`) | `content_type: 'product'`, `contents`, `value`, `currency`, `num_items`, `{ eventID: 'order_' + id }` |

> **Sécurité anti-doublon front** : L'événement `Purchase` vérifie la clé `wfbt_purchase_tracked_order_XXX` dans le `sessionStorage`. Si l'acheteur actualise la page de confirmation, le Pixel front ne rejoue pas l'événement.

### C. Capture des Cookies publicitaires & Click IDs
1. **`fbclid` (URL)** : Intercepté à l'atterrissage du visiteur et sauvegardé dans un cookie first-party `wfbt_fbclid` (durée de vie : 90 jours, `SameSite=Lax`) ainsi que dans `localStorage`.
2. **`_fbp` & `_fbc`** :
   - Sur la page de paiement, 3 champs masqués sont injectés dans le formulaire de checkout (`wfbt_fbp`, `wfbt_fbc`, `wfbt_consent`).
   - En JavaScript, si `_fbc` n'existe pas encore dans les cookies Meta mais que `wfbt_fbclid` est présent, la chaîne canonique Meta est automatiquement générée : `fb.1.${Date.now()}.${fbclid}`.
   - Côté serveur, lors de la création de la commande (`woocommerce_checkout_order_created`), ces valeurs sont extraites de `$_POST` (ou de `$_COOKIE` en secours) et enregistrées dans les métadonnées de la commande WooCommerce via l'API HPOS.

---

## 4. Moteur Conversions API CAPI (`includes/class-wfbt-meta-api.php`)

### A. Endpoint & Version Graph API
- **URL** : `https://graph.facebook.com/v21.0/{pixel_id}/events`
- **Méthode** : `POST` avec en-tête `Content-Type: application/json` et jeton d'accès système dans le corps de la requête.

### B. Normalisation Téléphonique E.164 (`format_phone_e164`)
Meta exige les numéros de téléphone au format international strict E.164 sans symbole `+` (uniquement des chiffres) avant hachage SHA-256. L'algorithme dédié traite spécifiquement :
- **La Réunion (indicatif 262)** :
  - Numéros mobiles `0692 XX XX XX` et `0693 XX XX XX` $\rightarrow$ `262692XXXXXX` et `262693XXXXXX` (12 chiffres).
  - Numéros fixes `0262 XX XX XX` $\rightarrow$ `262262XXXXXX`.
  - Numéros saisis avec indicatif sans `+` (`262692...`) conservés tels quels.
  - Numéros à 9 chiffres sans `0` initial avec pays `RE` $\rightarrow$ préfixés par `262`.
- **France métropolitaine (indicatif 33)** :
  - Numéros `06`, `07`, `01` à `05`, `09` $\rightarrow$ `336...`, `337...`, etc. (11 chiffres).
- **International général** : Préservation des numéros entre 8 et 15 chiffres conformes à la norme UIT-T E.164.

### C. Normalisation des données PII & Hachage SHA-256
Conformément aux directives Meta Graph API :
- `em` (Email) : Passage en minuscules, suppression des espaces extrêmes, hachage SHA-256.
- `ph` (Téléphone) : Normalisé E.164, hachage SHA-256.
- `fn` (Prénom) / `ln` (Nom) : Minuscules, sans espaces, hachage SHA-256.
- `ct` (Ville) / `st` (Département/État) / `zp` (Code postal) : Minuscules, hachage SHA-256.
- `country` (Pays) : Code ISO 3166-1 alpha-2 en minuscules (ex: `fr`, `re`) haché SHA-256.
- **Identifiants non hachés** : `fbp`, `fbc`, `client_ip_address`, `client_user_agent` (transmis en texte clair conformément à la documentation Meta).

### D. Contrôle RGPD CAPI
Avant d'émettre la requête HTTP :
- La métadonnée `_wfbt_consent` est vérifiée.
- Si le consentement marketing a été refusé (`denied`) :
  - **Mode `block` (par défaut)** : La requête est annulée, le statut de la commande devient `Ignored (Consent Denied)`, un log d'audit est créé, et aucune donnée n'est transmise à Meta.
  - **Mode `anonymize`** : La requête est transmise sans aucune PII, sans cookies publicitaires `_fbp`/`_fbc`, et sans adresse IP/User-Agent.

---

## 5. Dictionnaire des Métadonnées HPOS (`_wfbt_*`)

Toutes les métadonnées sont manipulées via les méthodes natives `$order->get_meta()` et `$order->update_meta_data()`.

| Clé de métadonnée | Type | Description |
| :--- | :--- | :--- |
| `_wfbt_fbp` | `string` | Valeur du cookie publicitaire `_fbp` (Browser ID Meta). |
| `_wfbt_fbc` | `string` | Valeur du cookie `_fbc` ou reconstruction canonique `fb.1.{ts}.{fbclid}`. |
| `_wfbt_consent` | `string` | État du consentement marketing Concord (`granted`, `denied`, `unknown`). |
| `_wfbt_capi_scheduled` | `string` | Drapeau de verrouillage (`yes`) pour empêcher l'enfilement multiple. |
| `_wfbt_capi_status` | `string` | Statut de l'envoi CAPI (`Pending`, `Success`, `Failed`, `Ignored (Consent Denied)`). |
| `_wfbt_capi_sent_at` | `string` | Horodatage MySQL de la transmission réussie vers Meta. |
| `_wfbt_capi_error` | `string` | Message d'erreur retourné par Meta Graph API ou `WP_Error` en cas d'échec. |

---

## 6. Administration, Diagnostics & Tests

Le plugin propose un écran de gestion sous **WooCommerce > Meta Tracking** :
1. **Onglet Configuration** :
   - Saisie du Pixel ID et du Jeton d'accès CAPI.
   - Saisie du Code d'événement de test (Test Event Code).
   - Activation/Désactivation du Pixel front-end.
   - Activation/Désactivation de l'asservissement RGPD Concord + nom du cookie.
   - Choix de l'action en cas de refus (Annulation CNIL ou Anonymisation).
   - Sélection personnalisée des statuts déclencheurs CAPI (cases à cocher dynamiques).
   - Configuration des alertes e-mail.
2. **Onglet Diagnostics & Commandes** :
   - Bouton AJAX **« Tester la connexion API (CAPI v21.0) »** pour vérifier immédiatement la validité du jeton et du Pixel ID.
   - Tableau d'audit en direct des **20 dernières commandes** : N° commande, date, montant, statut CAPI en temps réel, détection et aperçu tronqué de `_fbp` et `_fbc`, badge de consentement Concord (`Accordé`, `Refusé`, `Non détecté`), et bouton d'action **« Renvoyer »** (remise en file asynchrone instantanée via AJAX).

---

## 7. Guide de Test & Procédure de Validation

1. **Test de connexion direct** :
   - Renseigner le Pixel ID, le Jeton CAPI et le Code de test Meta dans l'onglet *Configuration*.
   - Aller dans l'onglet *Diagnostics* et cliquer sur *Tester la connexion API*.
   - Vérifier la réception immédiate de l'événement dans le **Gestionnaire d'événements Meta > Tester les événements**.
2. **Test du parcours complet (Hybride Dédupliqué)** :
   - Naviguer sur la boutique en ajoutant `?fbclid=TEST_SOYOO_CLICK_123` dans l'URL.
   - Consulter un produit $\rightarrow$ Vérifier `ViewContent` dans l'extension Chrome *Meta Pixel Helper*.
   - Ajouter au panier $\rightarrow$ Vérifier `AddToCart`.
   - Aller au checkout $\rightarrow$ Vérifier `InitiateCheckout`.
   - Passer une commande test.
   - Sur la page de remerciement :
     - Vérifier que `Purchase` se déclenche dans le Pixel Helper avec un `eventID` du type `order_1234`.
     - Dans Meta Events Manager, observer l'arrivée simultanée du signal Navigateur et du signal Serveur CAPI.
     - **Vérifier le statut de déduplication** : Meta affiche une icône verte indiquant que les deux événements ont été fusionnés avec succès grâce au `event_id` identique.
3. **Passage en production** :
   - Supprimer le code d'événement de test dans la configuration du plugin et enregistrer.
