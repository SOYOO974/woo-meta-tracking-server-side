# Architecture, Composants, Cycle de Vie & Schéma HPOS — Woo FB Tracking Server-Side

> Référence détaillée rattachée à [`AGENTS.md`](../../AGENTS.md). Conserve 100 % des spécifications fonctionnelles, de l'architecture des classes, du flux de données hybride Pixel ⇄ CAPI et du dictionnaire des métadonnées HPOS / cookies.

---

## 1. Description Générale & Philosophie d'Ingénierie

Ce plugin transforme le suivi e-commerce WooCommerce pour Meta en une **architecture hybride native** ultra-performante et résiliente, combinant :
1. **Le Pixel Navigateur front-end (`fbq`)** : Capture instantanée du haut de tunnel (`PageView`, `ViewContent`, `AddToCart` capturé côté serveur + fragments AJAX + endpoint de repli asynchrone `?wc-ajax=wfbt_pending_atc`, `InitiateCheckout` dédupliqué par `cart_hash`) et déclenchement initial de `Purchase`. Tous les `content_ids` (Pixel + CAPI) sont résolus par `Product_Id` et alignés automatiquement sur le catalogue Meta `woo-meta-catalog-feed-soyoo`.
2. **L'API de Conversions Meta Server-Side (CAPI Graph API v21.0)** : Transmission asynchrone sécurisée de l'ensemble de l'entonnoir transactionnel (`Purchase`, `AddToCart` et `InitiateCheckout`) via WooCommerce Action Scheduler (`Background_Processor::schedule_payload`), totalement insensible aux bloqueurs de publicité (AdBlockers / uBlock), aux pannes réseau et aux restrictions de cookies (ITP Safari iOS).
3. **Une déduplication parfaite à 100% sur chaque étape** :
   - `Purchase` : partage strictement le même identifiant : `eventID: 'order_' + order_id`.
   - `AddToCart` : partage le même identifiant unique généré côté serveur à l'ajout : `eventID: 'atc_' + 12 caractères aléatoires` (`wp_generate_password( 12, false )`), transmis au navigateur via fragments / endpoint / rendu de page.
   - `InitiateCheckout` : partage le même identifiant déterministe basé sur le hash du panier : `eventID: 'ic_' + md5(cart_hash + customer_id)`.
   Meta fusionne les signaux sans doubler les conversions ni le chiffre d'affaires.
4. **Résilience Anti-Bloqueurs Avancée (`extract_request_user_data`)** : En cas de blocage du Pixel navigateur par un AdBlocker (absence de `_fbp`), le serveur génère et dépose automatiquement un cookie first-party standard `_fbp` (`fb.1.{time}.{rand}`) et résout l'IP client réelle sous Cloudflare Enterprise (`HTTP_CF_CONNECTING_IP`), assurant la validité CAPI sans rejet HTTP 400. Depuis v2.2.1, ce `_fbp` serveur est aussi déposé pour un visiteur n'ayant pas répondu à la bannière (consentement `unknown`), voir [`02-capi-consent-atc-and-field-autopsies.md`](02-capi-consent-atc-and-field-autopsies.md) §M.
5. **Conformité RGPD Multi-Bannières (Woo Gads Native + Concord) & Annulation Propre** : Respect absolu du consentement marketing en cascade prioritaire (Priorité 1 : cookie first-party `woo_gads_consent` avec payload JSON `marketing: true|false` ; Priorité 2 : cookie et objet global Concord / préfixes personnalisés). File d'attente pré-consentement persistée dans `sessionStorage` (`wfbt_pending_consent_atc`) pour les ajouts au panier rapides rejouée dès l'acceptation même après changement de page. **Règle CAPI (v2.2.1, décision de Julien du 12/10/2026)** : seul un refus explicite (`denied`) annule la transmission (`Ignored (Consent Denied)` pour Purchase, log pour AddToCart / InitiateCheckout). Un visiteur qui ignore ou ferme la bannière (`unknown`) est envoyé côté serveur, alors que le Pixel navigateur, lui, attend le consentement. C'est plus permissif que la lecture stricte CNIL : choix assumé, ne pas durcir sans accord de Julien.
6. **Garde Pré-Vol CAPI & Anti-Erreur 400** : Vérification stricte de la présence d'au moins un identifiant direct client (`em`, `ph`, `fbp`, `fbc`, `external_id`). Si aucun n'est présent (ex: commandes manuelles sans coordonnées), l'événement est court-circuité avec le statut `Ignored (Insufficient Customer Data)`, évitant le code d'erreur Meta 100 / sous-code 2804050 et les alertes email intempestives.
7. **Enrichissement Event Match Quality (`external_id`)** : Transmission du Customer ID WooCommerce (`$order->get_customer_id()` ou session client) pour maximiser la correspondance Meta.
8. **Compatibilité native WooCommerce HPOS (High-Performance Order Storage)** : Bannissement total de l'ancienne API post-meta au profit exclusif des méthodes CRUD de l'objet `$order` (`custom_order_tables`).
9. **Normalisation E.164 avancée ARCEP (Océan Indien + DOM + France)** : Nettoyage (espaces insécables UTF-8, marques bidi iOS/WhatsApp, parenthèses `+33(0)` / `+262(0)`) et conversion automatique des préfixes Océan Indien (`0692`, `0693`, `0262`, `0263`, `0639`, `0269` → `262`), DOM (`590`, `594`, `596`) et métropolitains (`33`), avec rejet strict (`''`) des numéros spéciaux `08` et chaînes invalides avant hachage SHA-256.
10. **Tableau de Bord Exécutif de Diagnostics & Barre de Débogage Front-End (Désactivée par défaut / On-Demand)** : Grille pré-vol complète avec indicateur d'événements CAPI, inspecteur de cookies de session active (`woo_gads_consent`, `concord`, `_fbp`, `_fbc`), testeur de santé Meta Graph API v21.0 (`GET /{pixel_id}`), KPIs HPOS 30 jours, histogramme d'activité 14 jours en pur SVG vectoriel natif (zéro librairie JS externe), et barre de débogage flottante admin avec étiquetage explicite de la source des AddToCart (`fragment`, `endpoint`, `page_render`, `endpoint_cache_bypass`, `dom`).
11. **Filtrage anti-robots & ajouts programmatiques (v2.2.1)** : aucun AddToCart (navigateur ni CAPI) pour les robots (User-Agent, filtre `wfbt_is_bot_request`), cron, WP-CLI, REST hors Store API, admin hors AJAX et restauration de panier abandonné (`?wcf_ac_token`). Le CAPI AddToCart exige en plus un signal navigateur réel (cookie technique `wfbt_js` ou `_fbp`) et un anti-doublon serveur de 10 min par produit/variation.
12. **Qualité du Purchase et du matching (v2.3.0)** : Purchase navigateur conditionné à une clé de commande valide et à une commande payée (ou en statut déclencheur), Advanced Matching Pixel haché sur checkout + remerciement, `external_id` haché identique Pixel/CAPI, horodatage `fbc` = date réelle du clic (cookie `wfbt_fbc`), segmentation `customer_type` (`new` / `returning`) sur Purchase. Voir [`02-capi-consent-atc-and-field-autopsies.md`](02-capi-consent-atc-and-field-autopsies.md) §N.
13. **Mises à jour automatiques transparentes** : Bibliothèque `plugin-update-checker` (v5.6) connectée directement aux releases GitHub de `SOYOO974/woo-meta-tracking-server-side`.

---

## 2. Structure du Projet & Rôle Détaillé des Fichiers

```text
woo-fb-tracking-server-side/
├── woo-fb-tracking-server-side.php      # Point d'entrée, déclaration HPOS, initialisation & PUC
├── AGENTS.md                            # Pyramide inversée (invariants critiques & table de routage <= 14 Ko)
├── docs/
│   └── architecture/
│       ├── 01-components-lifecycle-and-schema.md      # Composants, flux de données & dictionnaire HPOS/cookies
│       ├── 02-capi-consent-atc-and-field-autopsies.md # Décisions architecturales §A–§O & autopsies terrain
│       └── 03-releases-and-backlog.md                 # Backlog projet & protocole de release PUC/GitHub
├── readme.txt                           # Métadonnées WordPress & changelog pour PUC
├── .gitignore                           # Exclusions Git propres (archives, logs, IDE)
├── .agent/workflows/
│   └── agent-releaser.md                # Workflow automatisé de publication des releases GitHub
├── plugin-update-checker/               # YahnisElsts/plugin-update-checker v5.6 (GitHub Releases)
├── includes/
│   ├── class-wfbt-core.php                 # Orchestration, hooks de commande, planification HPOS
│   ├── class-wfbt-meta-api.php             # Moteur CAPI Graph API v21.0, normalisation E.164, payload
│   ├── class-wfbt-product-id.php           # Résolveur unique des content_ids alignés sur le catalogue Meta
│   ├── class-wfbt-background-processor.php # File d'attente asynchrone WooCommerce Action Scheduler
│   ├── class-wfbt-admin-settings.php       # Panneau de réglages & tableau de diagnostic des 20 commandes
│   └── class-wfbt-logger.php               # Wrapper WC_Logger ('wfbt-server-side')
├── public/
│   └── class-wfbt-public.php               # Injection Pixel fbq, capture fbclid/fbp/fbc, RGPD Concord
└── languages/                              # Fichiers de traduction i18n (Loco Translate & WP standard)
    ├── wfbt-server-side.pot                # Modèle maître de traduction officiel (.pot généré par WP-CLI)
    ├── wfbt-server-side-fr_FR.po           # Fichier source de traduction française (fr_FR)
    └── wfbt-server-side-fr_FR.mo           # Binaire compilé chargé nativement par WordPress
```

### Détail des Composants Clés

- **[woo-fb-tracking-server-side.php](../../woo-fb-tracking-server-side.php)** :
  - Déclare la compatibilité HPOS (`FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`).
  - Initialise `plugin-update-checker` v5.6 relié à `SOYOO974/woo-meta-tracking-server-side` avec support des release assets.
  - Démarre le composant public `Public_Handler::init()` et le contrôleur central `Core::instance()`.
  - Résolution universelle des autorisations : filtre `user_has_cap` accordant dynamiquement en mémoire `manage_woocommerce` et `view_woocommerce_reports` à tout utilisateur possédant `manage_options`, combiné à l'auto-réparation proactive en base (`wfbt_ensure_admin_capabilities`).
  - Redirection automatique de rétrocompatibilité sur `admin_init` (`options-general.php?page=wfbt-settings` → `admin.php?page=wfbt-settings`).
  - Filtre `option_page_capability_wfbt_settings_group` dynamique garantissant la sauvegarde sans 403 via `options.php`.
- **[readme.txt](../../readme.txt)** :
  - Fichier conforme au standard WordPress.org contenant les métadonnées de version, la description, et le changelog extrait par PUC pour la modale native des extensions.
- **[includes/class-wfbt-core.php](../../includes/class-wfbt-core.php)** :
  - Écoute les transitions de statuts de commande via `woocommerce_order_status_changed` et filtre dynamiquement selon les statuts configurés (`wfbt_trigger_statuses`, par défaut `processing` et `completed`).
  - Gère le verrouillage anti-doublon via la méta HPOS `_wfbt_capi_scheduled` et planifie l'action asynchrone.
- **[includes/class-wfbt-meta-api.php](../../includes/class-wfbt-meta-api.php)** :
  - Moteur CAPI Graph API v21.0 (`https://graph.facebook.com/v21.0/{pixel_id}/events`).
  - Implémente `format_phone_e164($phone, $country)` pour La Réunion, Mayotte, les DOM et la France.
  - Hachage SHA-256 de tous les champs PII (`em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`).
  - Injection de l'identifiant client WooCommerce (`external_id`, haché SHA-256 depuis v2.3.0) pour renforcer l'Event Match Quality (EMQ).
  - Injection des identifiants non hachés (`fbp`, `fbc`, `client_ip_address`, `client_user_agent`).
  - Garde Pré-Vol anti-rejet : Vérifie la présence d'au moins un identifiant direct avant transmission. Si absent, marque proprement la commande `Ignored (Insufficient Customer Data)` sans erreur ni alerte mail.
  - Gouvernance RGPD : annulation automatique et sécurisée (`Ignored (Consent Denied)`) si `_wfbt_consent === 'denied'`.
  - Mise à jour HPOS des statuts de commande (`Success`, `Failed`, `Ignored (Consent Denied)`, `Ignored (Insufficient Customer Data)`).
- **[includes/class-wfbt-background-processor.php](../../includes/class-wfbt-background-processor.php)** :
  - File d'attente asynchrone Action Scheduler (hook `wfbt_send_capi_event` pour les commandes et `ACTION_PAYLOAD_HOOK` via `schedule_payload` pour `AddToCart` / `InitiateCheckout`, groupe `wfbt_capi`).
- **[includes/class-wfbt-admin-settings.php](../../includes/class-wfbt-admin-settings.php)** :
  - Enregistrement standard du sous-menu sous `woocommerce` à la priorité 20 sur `admin_menu` avec la capacité requise `manage_woocommerce`.
  - Onglet Configuration : détection automatique de la bannière native Woo Gads (`woo_gads_settings['enable_builtin_banner']`), formulaire avec bascules Pixel front, barre de débogage flottante admin (`wfbt_enable_debug_bar`, désactivée par défaut pour ne pas polluer la navigation admin), gestion RGPD simplifiée (Woo Gads + Concord) avec annulation automatique en cas de refus, statuts déclencheurs personnalisés et alertes e-mail.
  - Onglet Diagnostics Exécutif :
    - Grille pré-vol d'état (Identifiants Meta, Architecture HPOS & Action Scheduler, RGPD Consent Management, Débogueur front).
    - Inspecteur de cookies de session active en temps réel (`woo_gads_consent`, `concord`, `_fbp`, `_fbc`) avec bouton d'actualisation et simulation de clic pub Meta (`?fbclid=`).
    - Outils d'interrogation Meta Graph API v21.0 : test de santé du Dataset (`GET /{pixel_id}`) et test de connexion CAPI (`POST /{pixel_id}/events`).
    - KPIs de performance HPOS sur 30 jours (taux de succès CAPI, taux de capture `_fbp`, taux de clics Meta Ads `_fbc`, volume de commandes, ratio de consentement marketing).
    - Histogramme d'activité quotidien sur 14 jours généré en pur SVG vectoriel natif (zéro librairie JS externe).
    - Tableau d'audit HPOS des 20 dernières commandes avec détection en temps réel de `_fbp`, `_fbc`, badge de consentement et bouton « Renvoyer ».
  - Onglet Tutoriel & Guide de configuration : 8 étapes structurées sous forme d'accordéon repliable (fermé par défaut) avec boutons « Tout déplier / Tout replier ». Détaille exhaustivement le paramétrage Meta (choix exclusif de l'événement Acheter, matrice exacte des cases à cocher client/événement, génération du token Dataset Quality API, test en direct et Pixel Helper).
- **[public/class-wfbt-public.php](../../public/class-wfbt-public.php)** :
  - Injection front du script `fbevents.js` et déclenchement des événements `PageView`, `ViewContent`, `AddToCart` (capture serveur `woocommerce_add_to_cart` + fragments AJAX, voir §3), `InitiateCheckout` et `Purchase`.
  - `ViewContent` sur produit variable : envoi des `content_ids` des variations visibles (max 50), car le catalogue Meta référence les variations et non le parent.
  - Instrumentation non intrusive du Pixel `window.fbq` pour journaliser tous les appels dans `window.wfbtEventsLog`.
  - Barre de débogage flottante en direct (`maybe_render_debug_bar`) pour administrateurs et gestionnaires de boutique (`manage_woocommerce`, `manage_options`, ou `?wfbt_debug=1`) : pastille repliable en bas à droite inspectant l'état du Pixel, le consentement marketing avec sa source (`GRANTED (woo_gads)`, `GRANTED (concord)`), `_fbp`, `_fbc`, `?fbclid=`, le flux temps réel de tous les événements `fbq` avec paramètres et boutons d'actions rapides.
  - Détection du consentement en cascade : Priorité 1 au cookie `woo_gads_consent` (avec parsing JSON), Priorité 2 au cookie Concord ou préfixe personnalisé (`get_concord_cookie_prefix()` avec repli `'concord'` si vide), et objet global `window.ConcordConsent`.
  - Activation à chaud sans rechargement de page : écoute du bouton `#woo-gads-btn-accept`, de l'événement `woo_gads_consent_updated`, des événements Concord et polling léger fallback.
  - Capture PHP native `detect_consent_php()` (avec alias rétrocompatible `detect_concord_consent_php()`).
  - Capture de `?fbclid=` en cookie first-party `wfbt_fbclid` + `wfbt_fbc` (90 jours) + `localStorage`.
  - Injection de champs masqués au checkout pour sauvegarder `_wfbt_fbp`, `_wfbt_fbc` et `_wfbt_consent`.
- **[includes/class-wfbt-product-id.php](../../includes/class-wfbt-product-id.php)** (v2.1.0+) :
  - Source unique des `content_ids` / `contents[].id` (Pixel ET CAPI). Option `wfbt_content_id_format` : `auto` (défaut — délègue à Woo Meta Catalog Feed SOYOO via le filtre `soyoo_meta_catalog_content_id`, repli SKU sinon ID = logique historique du flux), `sku`, `id`, `gla` (`gla_{id}`, Google for WooCommerce / import GMC), `fb_wc` (`{sku}_{id}` ou `wc_post_id_{id}`). Filtre `wfbt_content_id` pour les formats sur-mesure.
  - **Contrat inter-extensions** : le flux catalogue (`woo-meta-catalog-feed-soyoo`) est la source de vérité des ID. Il expose `add_filter( 'soyoo_meta_catalog_content_id', fn( $id, $product ) => Feed_Item::get_content_id( $product ), 10, 2 )`. Le tracking détecte le flux via `WOO_META_CATALOG_FEED_VERSION` et affiche dans ses réglages un contrôle d'alignement en direct (5 produits, tracking vs `g:id`). Le flux peut réciproquement lire `\WFBT\Product_Id::get()` / `get_effective_format()` pour afficher le statut côté catalogue.
  - **Règle absolue** : ces ID doivent être identiques à la colonne « ID de contenu » du catalogue Meta, sinon taux de correspondance catalogue = 0 % (ViewContent/AddToCart/Purchase « Manquant » dans le Gestionnaire des ventes).

---

## 3. Cycle de Vie Détaillé des Données

### 1. Capture Navigateur & Atterrissage
1. Le visiteur clique sur une annonce Facebook/Instagram et atterrit avec `?fbclid=...`.
2. Le script JS (`public/class-wfbt-public.php`) enregistre ce `fbclid` dans le cookie `wfbt_fbclid` et le format canonique horodaté au clic dans `wfbt_fbc` (durée 90 jours, `SameSite=Lax`) ainsi que dans le `localStorage` (`wfbt_fbclid`, `wfbt_fbclid_ts`).
3. Dès que le consentement marketing est accordé (via la bannière native Woo Gads ou Concord) :
   - Le Pixel s'initialise (`fbq('init', pixel_id, advancedMatching)`).
   - Meta dépose ses propres cookies first-party `_fbp` (Browser ID) et `_fbc` (Click ID).
   - L'événement `PageView` est envoyé, ainsi que l'événement spécifique de la page consultée (`ViewContent` ou `InitiateCheckout`).
4. Lors de l'ajout au panier (v2.1.0+), le hook serveur `woocommerce_add_to_cart` (enregistré AVANT le garde `is_admin()` pour couvrir `admin-ajax.php`) construit le payload `AddToCart` (variation exacte, quantité, valeur, `content_id` catalogue, `eventID` partagé) et le place dans la session WC (`wfbt_pending_atc`, flag `ajax`), tout en planifiant l'envoi CAPI serveur si la requête est humaine et munie d'un signal navigateur (`wfbt_js` ou `_fbp`) :
   - **Ajout AJAX** (`wc-ajax`, Woodmart, Cart Drawers) : le filtre `woocommerce_add_to_cart_fragments` expose les événements sous la clé `fragments.wfbt_atc` (protégé par `$added_in_current_request`), lus par l'écouteur jQuery `added_to_cart`. Repli vers `/?wc-ajax=wfbt_pending_atc` puis attributs DOM / regex `href` si le thème ne renvoie pas de fragments.
   - **Formulaire classique** (fiche produit POST, sans AJAX) : l'événement est injecté dans `wfbt_page_events` au rendu de la page suivante (ou dépilé via le cookie court `wfbt_has_pending_atc` en cas d'Edge Cache HIT). Les événements flagués `ajax` ne sont jamais rejoués au rechargement (anti-doublon).

### 2. Validation de Commande & Métadonnées HPOS
Lors du paiement (`woocommerce_checkout_order_created` / `woocommerce_checkout_update_order_meta`) :
- Le serveur extrait `_fbp`, `_fbc` et l'état du consentement (`granted` / `denied` / `unknown`).
- Si `_fbc` n'est pas encore présent dans les cookies, `Meta_Api::resolve_request_fbc()` lit en priorité `wfbt_fbc` (`fb.1.{date_du_clic_ms}.{fbclid}`) puis reconstruit en repli depuis `wfbt_fbclid` :
  `fbc = fb.1.{timestamp}.{fbclid}`
- Ces valeurs sont enregistrées directement dans l'objet `$order` via les métadonnées HPOS :
  - `_wfbt_fbp`
  - `_wfbt_fbc`
  - `_wfbt_consent`

### 3. Page de Confirmation (Thank You Page) & Déduplication Front
Sur la page `is_order_received_page()` (conditionnée depuis v2.3.0 à `$order->key_is_valid( $_GET['key'] )` ET commande `is_paid()` ou en statut déclencheur `wfbt_trigger_statuses`) :
1. Le Pixel navigateur déclenche :
   ```javascript
   fbq('track', 'Purchase', {
       content_type: 'product',
       contents: [...],
       value: order_total,
       currency: order_currency,
       num_items: total_items,
       customer_type: 'new' // ou 'returning'
   }, { eventID: 'order_' + order_id });
   ```
2. Un verrou `sessionStorage.setItem('wfbt_purchase_tracked_order_' + order_id, '1')` empêche tout réenvoi intempestif si l'acheteur actualise sa page de confirmation.

### 4. Traitement Asynchrone CAPI Server-Side
1. Dès que la commande atteint l'un des statuts configurés (ex: `processing` ou `completed`) :
   - `Core::maybe_send_capi_event` vérifie que `_wfbt_capi_scheduled !== 'yes'` et que `_wfbt_capi_status !== 'Success'`.
   - L'événement est enfilé dans WooCommerce Action Scheduler (`Background_Processor::schedule_event`).
2. Action Scheduler exécute en arrière-plan `Meta_Api::send_purchase_event` :
   - **Contrôle RGPD** : Si `_wfbt_consent === 'denied'`, le plugin annule l'envoi (`Ignored (Consent Denied)`). `unknown` est envoyé (voir §1.5 et [`02-capi-consent-atc-and-field-autopsies.md`](02-capi-consent-atc-and-field-autopsies.md) §M).
   - **Garde Pré-Vol Données Client** : Vérifie la présence d'au moins un identifiant direct (`em`, `ph`, `fbp`, `fbc`, `external_id`). Si aucun n'est présent (ex: commande manuelle ou incomplète), l'événement est court-circuité avec le statut `Ignored (Insufficient Customer Data)` sans lever d'erreur ni générer d'alerte mail.
   - **Normalisation E.164 ARCEP Réunion/DOM/France** : Le numéro de téléphone est converti au format strict (ex: `0692 12 34 56` → `262692123456`) puis haché en SHA-256.
   - **Enrichissement PII & EMQ** : Email, prénom, nom, ville, état/région, code postal, pays (`re`, `fr`) et `external_id` (ID client WooCommerce) hachés en SHA-256 minuscules.
   - **Identifiants Meta** : `fbp`, `fbc`, `client_ip_address`, `client_user_agent` transmis non hachés.
   - **Identifiant de déduplication** : `'event_id' => 'order_' . $order->get_id()`.
   - **Transmission HTTP POST** vers `https://graph.facebook.com/v21.0/{pixel_id}/events`.
3. En cas de succès (HTTP 200) : La commande est marquée `_wfbt_capi_status = 'Success'` avec l'horodatage `_wfbt_capi_sent_at`.
4. En cas d'erreur : La commande est marquée `_wfbt_capi_status = 'Failed'` avec le message d'erreur `_wfbt_capi_error`, et une alerte email est envoyée si configurée.

---

## 4. Dictionnaire des Métadonnées HPOS (`_wfbt_*`) & Cookies / Session

### Métadonnées de commande HPOS (`wp_wc_orders_meta`)

| Clé de métadonnée | Type | Description |
| :--- | :--- | :--- |
| `_wfbt_fbp` | `string` | Valeur du cookie publicitaire `_fbp` (Browser ID Meta). |
| `_wfbt_fbc` | `string` | Valeur du cookie `_fbc` ou reconstruction canonique `fb.1.{ts}.{fbclid}`. |
| `_wfbt_consent` | `string` | État du consentement marketing (`granted`, `denied`, `unknown`) issu de Woo Gads ou Concord. |
| `_wfbt_capi_scheduled` | `string` | Verrou (`yes`) empêchant la planification multiple. |
| `_wfbt_capi_status` | `string` | Statut CAPI (`Pending`, `Success`, `Failed`, `Ignored (Consent Denied)`, `Ignored (Insufficient Customer Data)`). |
| `_wfbt_capi_sent_at` | `string` | Horodatage MySQL de la transmission réussie vers Meta. |
| `_wfbt_capi_error` | `string` | Détail de l'erreur API ou réseau en cas d'échec. |
| `_wfbt_customer_type` | `string` | `new` ou `returning` (v2.3.0+, élargi en v2.3.1), calculé une fois au premier Purchase construit (Pixel ou CAPI) d'après les commandes antérieures en statut payé `wc_get_is_paid_statuses()` (ID client OU email, incluant les statuts logistiques post-paiement). Envoyé en paramètre personnalisé `customer_type`. |

### Cookies et clés de session utilisés côté front

| Clé | Portée | Description |
| :--- | :--- | :--- |
| `wfbt_js` | Cookie 24 h, posé par le script inline du `<head>` | Flag technique « vrai navigateur » (sans identifiant), exigé avant tout CAPI AddToCart (v2.2.1). Nom volontairement hors préfixes `wordpress_` / `woocommerce_` pour ne pas casser le cache Edge. |
| `wfbt_has_pending_atc` | Cookie 60 s | Signale un AddToCart en attente en session, pour contourner le cache Edge (v2.1.1). |
| `wfbt_fbclid` | Cookie 90 j + `localStorage` (`wfbt_fbclid`, `wfbt_fbclid_ts`) | `fbclid` capturé à l'atterrissage (repli historique pour reconstruire `_fbc`). |
| `wfbt_fbc` | Cookie 90 j (v2.3.0) | `fbc` canonique `fb.1.{date du clic ms}.{fbclid}` écrit à l'atterrissage. Ordre de lecture : `_fbc` > `wfbt_fbc` > `wfbt_fbclid` (`Meta_Api::resolve_request_fbc()`). |
| `wfbt_pending_atc` | Session WC | File des AddToCart à restituer au navigateur (fragments, endpoint, rendu de page). |
| `wfbt_capi_atc_sent` | Session WC | Horodatages des CAPI AddToCart envoyés par `product_id:variation_id` (anti-doublon 10 min, v2.2.1). |
| `wfbt_capi_ic_sent_{cart_hash}` | Session WC | Verrou InitiateCheckout CAPI par panier. |
| `wfbt_pending_consent_atc` | `sessionStorage` | AddToCart en attente de consentement, rejoués à l'initialisation du Pixel. |
