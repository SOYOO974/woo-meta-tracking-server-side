# Fichier de Contexte : Woo FB Tracking Server-Side (Architecture Hybride Native v2.2.0)

> [!IMPORTANT]
> **Consigne de mise à jour :** Ce fichier `AGENTS.md` sert de référence contextuelle absolue pour comprendre le fonctionnement global et les spécificités techniques du plugin. **À chaque fois que vous modifiez le code du projet, vous devez impérativement mettre à jour ce fichier pour refléter les changements effectués.**

---

## 1. Description Générale & Philosophie d'Ingénierie

Ce plugin transforme le suivi e-commerce WooCommerce pour Meta en une **architecture hybride native** ultra-performante et résiliente, combinant :
1. **Le Pixel Navigateur front-end (`fbq`)** : Capture instantanée du haut de tunnel (`PageView`, `ViewContent`, `AddToCart` capturé côté serveur + fragments AJAX + endpoint de repli asynchrone `?wc-ajax=wfbt_pending_atc`, `InitiateCheckout` dédupliqué par `cart_hash`) et déclenchement initial de `Purchase`. Tous les `content_ids` (Pixel + CAPI) sont résolus par `Product_Id` et alignés automatiquement sur le catalogue Meta `woo-meta-catalog-feed-soyoo`.
2. **L'API de Conversions Meta Server-Side (CAPI Graph API v21.0)** : Transmission asynchrone sécurisée de l'ensemble de l'entonnoir transactionnel (`Purchase`, `AddToCart` et `InitiateCheckout`) via WooCommerce Action Scheduler (`Background_Processor::schedule_payload`), totalement insensible aux bloqueurs de publicité (AdBlockers / uBlock), aux pannes réseau et aux restrictions de cookies (ITP Safari iOS).
3. **Une déduplication parfaite à 100% sur chaque étape** :
   - `Purchase` : partage strictement le même identifiant : `eventID: 'order_' + order_id`.
   - `AddToCart` : partage le même identifiant unique généré côté serveur : `eventID: 'atc_' + cart_item_key + '_' + ts`.
   - `InitiateCheckout` : partage le même identifiant déterministe basé sur le hash du panier : `eventID: 'ic_' + md5(cart_hash + customer_id)`.
   Meta fusionne les signaux sans doubler les conversions ni le chiffre d'affaires.
4. **Résilience Anti-Bloqueurs Avancée (`extract_request_user_data`)** : En cas de blocage du Pixel navigateur par un AdBlocker (absence de `_fbp`), le serveur génère et dépose automatiquement un cookie first-party standard `_fbp` (`fb.1.{time}.{rand}`) et résout l'IP client réelle sous Cloudflare Enterprise (`HTTP_CF_CONNECTING_IP`), assurant la validité CAPI sans rejet HTTP 400.
5. **Conformité RGPD Multi-Bannières (Woo Gads Native + Concord) & Annulation Propre** : Respect absolu du consentement marketing en cascade prioritaire (Priorité 1 : cookie first-party `woo_gads_consent` avec payload JSON `marketing: true|false` ; Priorité 2 : cookie et objet global Concord / préfixes personnalisés). File d'attente pré-consentement persistée dans `sessionStorage` (`wfbt_pending_consent_atc`) pour les ajouts au panier rapides rejouée dès l'acceptation même après changement de page. En cas de refus explicite, annulation propre et sécurisée de la transmission CAPI (`Ignored (Consent Denied)`), protégeant la boutique contre les rejets HTTP 400 de Meta et garantissant la conformité stricte CNIL.
6. **Garde Pré-Vol CAPI & Anti-Erreur 400** : Vérification stricte de la présence d'au moins un identifiant direct client (`em`, `ph`, `fbp`, `fbc`, `external_id`). Si aucun n'est présent (ex: commandes manuelles sans coordonnées), l'événement est court-circuité avec le statut `Ignored (Insufficient Customer Data)`, évitant le code d'erreur Meta 100 / sous-code 2804050 et les alertes email intempestives.
7. **Enrichissement Event Match Quality (`external_id`)** : Transmission du Customer ID WooCommerce (`$order->get_customer_id()` ou session client) pour maximiser la correspondance Meta.
8. **Compatibilité native WooCommerce HPOS (High-Performance Order Storage)** : Bannissement total de l'ancienne API post-meta au profit exclusif des méthodes CRUD de l'objet `$order` (`custom_order_tables`).
9. **Normalisation E.164 avancée (La Réunion + France)** : Nettoyage et conversion automatique des préfixes réunionnais (`0692`, `0693`, `0262` $\rightarrow$ `+262`) et métropolitains (`+33`) avant hachage SHA-256.
10. **Tableau de Bord Exécutif de Diagnostics & Barre de Débogage Front-End (Désactivée par défaut / On-Demand)** : Grille pré-vol complète avec indicateur d'événements CAPI, inspecteur de cookies de session active (`woo_gads_consent`, `concord`, `_fbp`, `_fbc`), testeur de santé Meta Graph API v21.0 (`GET /{pixel_id}`), KPIs HPOS 30 jours, histogramme d'activité 14 jours en pur SVG vectoriel natif (zéro librairie JS externe), et barre de débogage flottante admin avec étiquetage explicite de la source des AddToCart (`fragment`, `endpoint`, `page_render`, `endpoint_cache_bypass`, `dom`).
11. **Mises à jour automatiques transparentes** : Bibliothèque `plugin-update-checker` (v5.6) connectée directement aux releases GitHub de `SOYOO974/woo-meta-tracking-server-side`.

---

## 2. 📂 Structure du Projet & Rôle des Fichiers

```text
woo-fb-tracking-server-side/
├── woo-fb-tracking-server-side.php      # Point d'entrée, déclaration HPOS, initialisation & PUC
├── AGENTS.md                            # Référentiel technique et guide développeur
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

- **[woo-fb-tracking-server-side.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/woo-fb-tracking-server-side.php)** :
  - Déclare la compatibilité HPOS (`FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`).
  - Initialise `plugin-update-checker` v5.6 relié à `SOYOO974/woo-meta-tracking-server-side` avec support des release assets.
  - Démarre le composant public `Public_Handler::init()` et le contrôleur central `Core::instance()`.
  - Résolution universelle des autorisations : filtre `user_has_cap` accordant dynamiquement en mémoire `manage_woocommerce` et `view_woocommerce_reports` à tout utilisateur possédant `manage_options`, combiné à l'auto-réparation proactive en base (`wfbt_ensure_admin_capabilities`).
  - Redirection automatique de rétrocompatibilité sur `admin_init` (`options-general.php?page=wfbt-settings` $\rightarrow$ `admin.php?page=wfbt-settings`).
  - Filtre `option_page_capability_wfbt_settings_group` dynamique garantissant la sauvegarde sans 403 via `options.php`.
- **[readme.txt](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/readme.txt)** :
  - Fichier conforme au standard WordPress.org contenant les métadonnées de version, la description, et le changelog extrait par PUC pour la modale native des extensions.
- **[includes/class-wfbt-core.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-core.php)** :
  - Écoute les transitions de statuts de commande via `woocommerce_order_status_changed` et filtre dynamiquement selon les statuts configurés (`wfbt_trigger_statuses`, par défaut `processing` et `completed`).
  - Gère le verrouillage anti-doublon via la méta HPOS `_wfbt_capi_scheduled` et planifie l'action asynchrone.
- **[includes/class-wfbt-meta-api.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-meta-api.php)** :
  - Moteur CAPI Graph API v21.0 (`https://graph.facebook.com/v21.0/{pixel_id}/events`).
  - Implémente `format_phone_e164($phone, $country)` pour La Réunion et la France.
  - Hachage SHA-256 de tous les champs PII (`em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`).
  - Injection de l'identifiant client WooCommerce (`external_id`) pour renforcer l'Event Match Quality (EMQ).
  - Injection des identifiants non hachés (`fbp`, `fbc`, `client_ip_address`, `client_user_agent`).
  - Garde Pré-Vol anti-rejet : Vérifie la présence d'au moins un identifiant direct avant transmission. Si absent, marque proprement la commande `Ignored (Insufficient Customer Data)` sans erreur ni alerte mail.
  - Gouvernance RGPD : annulation automatique et sécurisée (`Ignored (Consent Denied)`) si `_wfbt_consent === 'denied'`.
  - Mise à jour HPOS des statuts de commande (`Success`, `Failed`, `Ignored (Consent Denied)`, `Ignored (Insufficient Customer Data)`).
- **[includes/class-wfbt-background-processor.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-background-processor.php)** :
  - File d'attente asynchrone Action Scheduler (hook `wfbt_send_capi_event`, groupe `wfbt_capi`).
- **[includes/class-wfbt-admin-settings.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-admin-settings.php)** :
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
- **[public/class-wfbt-public.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/public/class-wfbt-public.php)** :
  - Injection front du script `fbevents.js` et déclenchement des événements `PageView`, `ViewContent`, `AddToCart` (capture serveur `woocommerce_add_to_cart` + fragments AJAX, voir §3), `InitiateCheckout` et `Purchase`.
  - `ViewContent` sur produit variable : envoi des content_ids des variations visibles (max 50), car le catalogue Meta référence les variations et non le parent.
  - Instrumentation non intrusive du Pixel `window.fbq` pour journaliser tous les appels dans `window.wfbtEventsLog`.
  - Barre de débogage flottante en direct (`maybe_render_debug_bar`) pour administrateurs et gestionnaires de boutique (`manage_woocommerce`, `manage_options`, ou `?wfbt_debug=1`) : pastille repliable en bas à droite inspectant l'état du Pixel, le consentement marketing avec sa source (`GRANTED (woo_gads)`, `GRANTED (concord)`), `_fbp`, `_fbc`, `?fbclid=`, le flux temps réel de tous les événements `fbq` avec paramètres et boutons d'actions rapides.
  - Détection du consentement en cascade : Priorité 1 au cookie `woo_gads_consent` (avec parsing JSON), Priorité 2 au cookie Concord ou préfixe personnalisé, et objet global `window.ConcordConsent`.
  - Activation à chaud sans rechargement de page : écoute du bouton `#woo-gads-btn-accept`, de l'événement `woo_gads_consent_updated`, des événements Concord et polling léger fallback.
  - Capture PHP native `detect_consent_php()` (avec alias rétrocompatible `detect_concord_consent_php()`).
  - Capture de `?fbclid=` en cookie first-party `wfbt_fbclid` (90 jours) + `localStorage`.
  - Injection de champs masqués au checkout pour sauvegarder `_wfbt_fbp`, `_wfbt_fbc` et `_wfbt_consent`.
- **[includes/class-wfbt-product-id.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-product-id.php)** (v2.1.0) :
  - Source unique des `content_ids` / `contents[].id` (Pixel ET CAPI). Option `wfbt_content_id_format` : `auto` (défaut — délègue à Woo Meta Catalog Feed SOYOO via le filtre `soyoo_meta_catalog_content_id`, repli SKU sinon ID = logique historique du flux), `sku`, `id`, `gla` (`gla_{id}`, Google for WooCommerce / import GMC), `fb_wc` (`{sku}_{id}` ou `wc_post_id_{id}`). Filtre `wfbt_content_id` pour les formats sur-mesure.
  - **Contrat inter-extensions** : le flux catalogue (`woo-meta-catalog-feed-soyoo`) est la source de vérité des ID. Il expose `add_filter( 'soyoo_meta_catalog_content_id', fn( $id, $product ) => Feed_Item::get_content_id( $product ), 10, 2 )`. Le tracking détecte le flux via `WOO_META_CATALOG_FEED_VERSION` et affiche dans ses réglages un contrôle d'alignement en direct (5 produits, tracking vs `g:id`). Le flux peut réciproquement lire `\WFBT\Product_Id::get()` / `get_effective_format()` pour afficher le statut côté catalogue.
  - **Règle absolue** : ces ID doivent être identiques à la colonne « ID de contenu » du catalogue Meta, sinon taux de correspondance catalogue = 0 % (ViewContent/AddToCart/Purchase « Manquant » dans le Gestionnaire des ventes).

---

## 3. ⚙️ Cycle de Vie Détaillé des Données

### 1. Capture Navigateur & Atterrissage
1. Le visiteur clique sur une annonce Facebook/Instagram et atterrit avec `?fbclid=...`.
2. Le script JS (`public/class-wfbt-public.php`) enregistre ce `fbclid` dans le cookie `wfbt_fbclid` (durée 90 jours, `SameSite=Lax`) et dans le `localStorage`.
3. Dès que le consentement marketing est accordé (via la bannière native Woo Gads ou Concord) :
   - Le Pixel s'initialise (`fbq('init', pixel_id)`).
   - Meta dépose ses propres cookies first-party `_fbp` (Browser ID) et `_fbc` (Click ID).
   - L'événement `PageView` est envoyé, ainsi que l'événement spécifique de la page consultée (`ViewContent` ou `InitiateCheckout`).
4. Lors de l'ajout au panier (v2.1.0), le hook serveur `woocommerce_add_to_cart` (enregistré AVANT le garde `is_admin()` pour couvrir `admin-ajax.php`) construit le payload `AddToCart` (variation exacte, quantité, valeur, content_id catalogue) et le place dans la session WC (`wfbt_pending_atc`, flag `ajax`) :
   - **Ajout AJAX** (wc-ajax, Woodmart, Cart Drawers) : le filtre `woocommerce_add_to_cart_fragments` expose les événements sous la clé `fragments.wfbt_atc`, lus par l'écouteur jQuery `added_to_cart`. Repli JS sur les attributs `data-product_id` / `data-product_sku` si le thème ne renvoie pas de fragments.
   - **Formulaire classique** (fiche produit POST, sans AJAX) : l'événement est injecté dans `wfbt_page_events` au rendu de la page suivante. Les événements flagués `ajax` ne sont jamais rejoués au rechargement (anti-doublon).

### 2. Validation de Commande & Métadonnées HPOS
Lors du paiement (`woocommerce_checkout_order_created` / `woocommerce_checkout_update_order_meta`) :
- Le serveur extrait `_fbp`, `_fbc` et l'état du consentement (`granted` / `denied`).
- Si `_fbc` n'est pas encore présent dans les cookies mais que `wfbt_fbclid` existe, le format canonique officiel Meta est reconstruit :
  $$\text{fbc} = \text{fb.1.} + \text{timestamp} + \text{.} + \text{fbclid}$$
- Ces valeurs sont enregistrées directement dans l'objet `$order` via les métadonnées :
  - `_wfbt_fbp`
  - `_wfbt_fbc`
  - `_wfbt_consent`

### 3. Page de Confirmation (Thank You Page) & Déduplication Front
Sur la page `is_order_received_page()` :
1. Le Pixel navigateur déclenche :
   ```javascript
   fbq('track', 'Purchase', {
       content_type: 'product',
       contents: [...],
       value: order_total,
       currency: order_currency,
       num_items: total_items
   }, { eventID: 'order_' + order_id });
   ```
2. Un verrou `sessionStorage.setItem('wfbt_purchase_tracked_order_' + order_id, '1')` empêche tout réenvoi intempestif si l'acheteur actualise sa page de confirmation.

### 4. Traitement Asynchrone CAPI Server-Side
1. Dès que la commande atteint l'un des statuts configurés (ex: `processing` ou `completed`) :
   - `Core::maybe_send_capi_event` vérifie que `_wfbt_capi_scheduled !== 'yes'` et que `_wfbt_capi_status !== 'Success'`.
   - L'événement est enfilé dans WooCommerce Action Scheduler (`Background_Processor::schedule_event`).
2. Action Scheduler exécute en arrière-plan `Meta_Api::send_purchase_event` :
   - **Contrôle RGPD** : Si `_wfbt_consent === 'denied'`, le plugin annule l'envoi (`Ignored (Consent Denied)`) en conformité stricte CNIL.
   - **Garde Pré-Vol Données Client** : Vérifie la présence d'au moins un identifiant direct (`em`, `ph`, `fbp`, `fbc`, `external_id`). Si aucun n'est présent (ex: commande manuelle ou incomplète), l'événement est court-circuité avec le statut `Ignored (Insufficient Customer Data)` sans lever d'erreur ni générer d'alerte mail.
   - **Normalisation E.164 Réunion/France** : Le numéro de téléphone est converti au format strict (ex: `0692 12 34 56` $\rightarrow$ `262692123456`) puis haché en SHA-256.
   - **Enrichissement PII & EMQ** : Email, prénom, nom, ville, code postal, pays (`re`, `fr`) hachés en SHA-256 minuscules, et transmission de l'ID client WooCommerce (`external_id`).
   - **Identifiants Meta** : `fbp`, `fbc`, `client_ip_address`, `client_user_agent` transmis non hachés.
   - **Identifiant de déduplication** : `'event_id' => 'order_' . $order->get_id()`.
   - **Transmission HTTP POST** vers `https://graph.facebook.com/v21.0/{pixel_id}/events`.
3. En cas de succès (HTTP 200) : La commande est marquée `_wfbt_capi_status = 'Success'` avec l'horodatage `_wfbt_capi_sent_at`.
4. En cas d'erreur : La commande est marquée `_wfbt_capi_status = 'Failed'` avec le message d'erreur, et une alerte email est envoyée si configurée.

---

## 4. 🗄️ Dictionnaire des Métadonnées HPOS (`_wfbt_*`)

| Clé de métadonnée | Type | Description |
| :--- | :--- | :--- |
| `_wfbt_fbp` | `string` | Valeur du cookie publicitaire `_fbp` (Browser ID Meta). |
| `_wfbt_fbc` | `string` | Valeur du cookie `_fbc` ou reconstruction canonique `fb.1.{ts}.{fbclid}`. |
| `_wfbt_consent` | `string` | État du consentement marketing (`granted`, `denied`, `unknown`) issu de Woo Gads ou Concord. |
| `_wfbt_capi_scheduled` | `string` | Verrou (`yes`) empêchant la planification multiple. |
| `_wfbt_capi_status` | `string` | Statut CAPI (`Pending`, `Success`, `Failed`, `Ignored (Consent Denied)`, `Ignored (Insufficient Customer Data)`). |
| `_wfbt_capi_sent_at` | `string` | Horodatage MySQL de la transmission réussie vers Meta. |
| `_wfbt_capi_error` | `string` | Détail de l'erreur API ou réseau en cas d'échec. |

---

## 5. ⚠️ Décisions Architecturales & Points de Vigilance

### A. Déduplication Stricte Meta (Browser ⇄ Server)
- **Règle absolue** : La clé d'événement doit impérativement être nommée `eventID` côté navigateur dans le 4e paramètre de `fbq` (`{ eventID: 'order_' + id }`), et `event_id` côté CAPI (`'event_id' => 'order_' . $id`).
- Meta Events Manager utilise cette clé pour fusionner les deux signaux sous 48 heures. Aucune double facturation ni doublon de chiffre d'affaires n'est possible.

### B. Bannissement Total de `get_post_meta()` / `update_post_meta()`
- Avec l'activation de WooCommerce HPOS (`custom_order_tables`), les métadonnées de commande sont stockées dans les tables `wp_wc_orders_meta`.
- L'utilisation des anciennes fonctions WordPress `get_post_meta()` provoque des requêtes fantômes dans `wp_postmeta` qui ne contiennent pas les données à jour.
- **Règle** : Toujours manipuler `$order->get_meta()`, `$order->update_meta_data()`, `$order->delete_meta_data()` et `$order->save()`.

### C. Normalisation E.164 pour La Réunion (974)
- La Réunion utilise l'indicatif international `+262`.
- Les numéros locaux saisis par les clients débutent fréquemment par `0692`, `0693` (mobiles) ou `0262` (fixes). Si ces numéros étaient préfixés par l'indicatif métropolitain `+33` (erreur courante de plugins anglophones), Meta serait incapable de les faire correspondre aux profils des acheteurs.
- L'algorithme dédié dans `Meta_Api::format_phone_e164` garantit un formatage propre `262692...` avant hachage SHA-256.

### D. Internationalisation (i18n) & Compatibilité Loco Translate
- **Textes sources en anglais par défaut** : Tous les textes utilisateur du code PHP et JS sont rédigés en anglais et enveloppés dans les fonctions standards WordPress (`__( '...', 'wfbt-server-side' )`, `esc_html__()`, etc.).
- **Modèle maître officiel (`languages/wfbt-server-side.pot`)** : Généré via la commande officielle `wp i18n make-pot . languages/wfbt-server-side.pot --slug=wfbt-server-side --domain=wfbt-server-side`.
- **Traductions françaises intégrées** : Les fichiers `languages/wfbt-server-side-fr_FR.po` et le binaire compilé `languages/wfbt-server-side-fr_FR.mo` (généré via `wp i18n make-mo languages/`) assurent l'affichage direct en français sur les sites configurés en langue française.
- **Loco Translate natif** : L'extension Loco Translate reconnaît immédiatement le text domain `wfbt-server-side` et le modèle `.pot` pour synchroniser ou ajouter d'autres langues en un clic.

### E. Visibilité Publique du Dépôt & Contrôle Strict Zéro Donnée Sensible
- **Dépôt public pour les mises à jour sans friction** : Le dépôt GitHub `SOYOO974/woo-meta-tracking-server-side` est **public** (identique à `woo-google-ads-tracking-server-side`). Cette visibilité est indispensable pour permettre à `plugin-update-checker` (PUC) sur les sites WordPress d'interroger l'API GitHub (`/releases/latest`) et de télécharger les archives de mise à jour sans nécessiter de Personal Access Token (PAT) ni d'authentification.
- **Règle absolue de sécurité (Zéro Info Sensible)** :
  - **Interdiction formelle de committer des secrets** : Ne jamais inclure de tokens d'accès Meta réels (`EAAB...`), de tokens GitHub (`ghp_...`, `gho_...`), de mots de passe, de clés API privées, d'adresses d'environnements confidentiels ou de données clients dans le code ou l'historique Git.
  - **Vérification systématique avant chaque commit et release** : Vérifier impérativement via `git diff` ou recherche textuelle qu'aucune donnée sensible n'a été insérée par inadvertance (ex: lors de tests locaux de l'API Meta).

### F. Résolution Universelle des Droits Administrateur (user_has_cap) & Enregistrement Menu
- **Problématique 403 récurrente sur WordPress** : Sur certains sites WordPress, le compte administrateur (`manage_options`) peut perdre ou ne jamais avoir reçu les capacités spécifiques créées par WooCommerce (`manage_woocommerce`, `view_woocommerce_reports`) à la suite de migrations de base de données, plugins de rôles ou configurations personnalisées. Lorsque WordPress vérifie l'accès à `admin.php?page=wfbt-settings`, il valide que l'utilisateur possède le droit du menu parent WooCommerce (`manage_woocommerce`). Sans ce droit, WordPress renvoie immédiatement `403 Permission Denied`.
- **Interception dynamique en mémoire (`user_has_cap`)** : Le hook `user_has_cap` intercepte tous les contrôles de capacité de WordPress. Si l'utilisateur possède `manage_options`, le filtre lui injecte dynamiquement `manage_woocommerce = true` et `view_woocommerce_reports = true`. Cette approche est 100% infaillible car elle ne dépend ni du nom du rôle, ni des mutations en base de données, ni du cache d'objets.
- **Auto-réparation proactive (`wfbt_ensure_admin_capabilities`)** : Branchée sur `init` à priorité 5, cette fonction vérifie et persiste les capacités manquantes directement dans `wp_user_roles` et l'objet `$current_user`.
- **Repli Menu Résilient** : `Admin_Settings::get_capability()` évalue dynamiquement la capacité disponible (`manage_woocommerce` puis repli sur `manage_options`), et `Admin_Settings::add_settings_page()` bascule automatiquement sous `options-general.php` si le menu parent WooCommerce n'est pas présent dans l'arborescence admin.

### G. Garde Pré-Vol CAPI & Anti-Erreur 400 (Subcode 2804050)
- L'API Meta Graph v21.0 exige impérativement au moins un paramètre d'identification client (`user_data`). Contrairement à Google Ads qui dispose d'une modélisation sans cookies (Consent Mode v2), Meta rejette catégoriquement tout événement avec `user_data: {}` avec l'erreur `HTTP 400: Vous n’avez pas ajouté suffisamment de données de paramètres d’informations client pour cet évènement` (subcode 2804050).
- Le plugin implémente un garde-fou pré-vol : si `em`, `ph`, `fbp`, `fbc` et `external_id` sont absents, ou si le client a refusé le consentement marketing, la requête HTTP vers Meta est court-circuitée, évitant les erreurs 400, les logs d'erreurs et les faux e-mails d'alerte critique.

### H. Format d'Archive ZIP & Normalisation des Séparateurs (Slashs `/` vs Antislashs `\`)
- Sur Windows, la commande native PowerShell `Compress-Archive` enregistre les chemins relatifs avec des antislashs (`\`). Lorsque WordPress décompresse cette archive sur un serveur Linux de production, le système de fichiers n'interprète pas `\` comme un séparateur mais comme un caractère littéral de nom de fichier. Cela crée des fichiers uniques à plat au lieu de dossiers (ex: `woo-fb-tracking-server-side\includes\class-wfbt-core.php`), provoquant la désactivation immédiate de l'extension par WordPress suite à l'absence perçue du fichier d'en-tête racine et la perte d'autorisation 403.
- Les releases sont désormais obligatoirement assemblées via le module Python standard `zipfile`, garantissant des slashs POSIX (`/`) universels et un dossier racine `woo-fb-tracking-server-side/` strictement conforme au slug déclaré dans PUC.

### I. Alignement des Content ID avec le Catalogue Meta (v2.1.0)
- **Le catalogue est la source de vérité.** Sur les sites SOYOO, le catalogue Meta est alimenté par `woo-meta-catalog-feed-soyoo` (`/feed/meta-catalog.xml`, `<g:id>` = SKU sinon ID produit, variations exportées individuellement avec `item_group_id` = ID parent).
- **Sens unique, jamais de lecture croisée des options** : le tracking consomme l'ID du flux (filtre `soyoo_meta_catalog_content_id`), le flux ne fait qu'afficher un statut en lisant `\WFBT\Product_Id`. Une double lecture d'options crée une dépendance circulaire qui dérive silencieusement.
- **Rétrocompatibilité** : flux ≤ v1.2.0 sans filtre → le mode `auto` applique la logique historique du flux (SKU sinon ID). Aucun changement d'ID pour les sites existants.
- **Interdit** : modifier la logique d'ID dans `Public_Handler` ou `Meta_Api` en dur. Toute évolution passe par `Product_Id::get()`.
- **Piège variations** : `get_sku()` (contexte `view`) d'une variation sans SKU propre renvoie le SKU du parent → plusieurs variations partagent le même ID (doublons rejetés par Meta). Correctif prévu côté flux (v1.3.0, `get_sku( 'edit' )` + repli ID variation) ; le tracking suivra automatiquement via la délégation.

### J. Capture AddToCart : Autopsie des Pièges & Résolution Multi-Niveaux (v2.1.1)

Lors du déploiement de la v2.1.0 sur `comptoirdecambaie.re` (thème FSE / UnderscoreTW sur-mesure `_cpd`), les statistiques Meta Events Manager montraient une anomalie majeure : **3 AddToCart pour 20 InitiateCheckout et 6 Purchase sur 4 jours**, et **3 AddToCart pour 70 InitiateCheckout sur 28 jours**. L'AddToCart restait massivement sous-déclaré et le ratio était aberrant.

L'autopsie du code thème (`javascript/script.js` et `theme/inc/woocommerce.php`) a révélé 5 causes réelles conjuguées :

1. **Bouton Mobile Sticky (`.mobile-bar [data-mobile-add]`) dépourvu de métadonnées** :
   - Sur mobile (~95 % du trafic Meta Ads), l'ajout est déclenché par un bouton fixe en bas d'écran : `<button type="button" class="btn btn--accent" data-mobile-add>`. Ce bouton est placé hors du formulaire et ne possède **aucun** attribut `data-product_id`, `data-product_sku`, ni `value`.
   - Le script du thème intercepte le clic, fait son propre `fetch(cfg.ajaxUrl, ...)` vers `?wc-ajax=add_to_cart`, puis déclenche manuellement jQuery : `$(document.body).trigger('added_to_cart', [ res.fragments, res.cart_hash, $(trigger) ])`.
   - Si les fragments ne contenaient pas `wfbt_atc` (voir cause 2), le repli DOM lisait `button.data('product_id') || button.val()` qui valait chaîne vide `''`. Le tracking abandonnait silencieusement (`if (!cid) return;`).
2. **Siphonnage intempestif de la file de session par concurrence de fragments** :
   - En v2.1.0, `inject_add_to_cart_fragment()` appelait `pop_pending_add_to_cart()` à chaque exécution du filtre `woocommerce_add_to_cart_fragments`.
   - Or, ce filtre est appelé en permanence par WooCommerce :
     a) Lors du rafraîchissement au chargement de chaque page via `cart-fragments.js` (`?wc-ajax=get_refreshed_fragments`) ;
     b) Lors des modifications de quantité ou suppressions dans le panier latéral via `_cpd_wc_cart_fragment_payload()` (`?wc-ajax=cpd_update_cart`).
   - Si une requête de fragment passive s'exécutait, elle vidait `wfbt_pending_atc` de la session WooCommerce et l'injectait dans une réponse HTTP que le navigateur n'écoutait pas sous `added_to_cart`. L'événement était détruit avant que l'acheteur ne puisse le consommer.
3. **Produits variables en POST classique et Edge Cache Cloudflare Enterprise (Rocket.net)** :
   - Sur les produits variables, le thème désactive l'AJAX et repasse en POST classique avec rechargement.
   - Le serveur enregistrait l'événement dans la session et redirigeait en GET (Post-Redirect-Get).
   - Mais sur Rocket.net, si la page suivante était servie par le cache Cloudflare Edge (Edge Cache HIT), le HTML statique ne contenait pas l'événement dynamique (`get_page_event_data()` non exécuté). L'événement restait bloqué en session jusqu'à ce qu'un `get_refreshed_fragments` passif vienne le détruire.
4. **Faux ajouts lors des rafraîchissements programmatiques du tiroir panier** :
   - Lors d'une modification de quantité (+ / −) ou d'une suppression d'article dans le tiroir (`initCartDrawer`), le thème déclenchait `added_to_cart` avec `programmaticRefresh = true` pour rafraîchir les badges WooCommerce, risquant de créer de faux événements ou de consommer des événements légitimes.
5. **Sur-comptage d'InitiateCheckout faussant le ratio** :
   - `InitiateCheckout` était émis à chaque chargement de `/commande/` sans aucun verrou.
   - Les rechargements de page, les retours depuis Alma ou les passerelles 3DS, et les erreurs de validation de formulaire renvoyaient chacun un `InitiateCheckout` pour le même panier, gonflant le volume à 70 pour seulement ~10 acheteurs réels.

#### 🛡️ Architecture de Résilience Multi-Niveaux (v2.1.1) :
- **Endpoint de repli serveur dédié `/?wc-ajax=wfbt_pending_atc` (avec repli `admin-ajax.php?action=wfbt_pending_atc`)** :
  Dès que l'écouteur `added_to_cart` est déclenché côté navigateur et que `fragments.wfbt_atc` est manquant, le script JS interroge immédiatement cet endpoint asynchrone sans cache (`nocache_headers()`). L'endpoint dépile les événements exacts de la session WooCommerce (`Product_Id`, variation, prix TTC, quantité, devise) et les renvoie en JSON.
- **Protection anti-concurrence `$added_in_current_request`** :
  `inject_add_to_cart_fragment()` ne touche à la session que si un ajout réel (`woocommerce_add_to_cart`) s'est produit dans la même exécution PHP (`$added_in_current_request === true`). Les rafraîchissements passifs (`get_refreshed_fragments`) et les updates de panier (`cpd_update_cart`) ne peuvent plus jamais vider la file.
- **Bypass du cache Edge via cookie court `wfbt_has_pending_atc`** :
  Lors d'un ajout POST, PHP dépose un cookie 60 secondes. Au chargement de la page suivante, même servie à 100 % depuis Cloudflare Edge Cache, le JS détecte le cookie au `DOMContentLoaded`, appelle l'endpoint de repli et déclenche le pixel (`source: 'endpoint_cache_bypass'`).
- **Filtrage des faux ajouts programmatiques** :
  L'écouteur `added_to_cart` ignore formellement tout trigger provenant du tiroir (`.cart-drawer`, `.cd-row`, `is-updating`, `.cart-form`).
- **File d'attente pré-consentement (`window.wfbtPendingConsentAtc`)** :
  Si l'internaute clique sur "Ajouter" avant d'avoir validé la bannière RGPD, l'événement est conservé en mémoire et rejoué automatiquement à l'initialisation du pixel (`initMetaPixel`).
- **Déduplication d'`InitiateCheckout` par panier (`cart_hash`)** :
  L'événement est verrouillé dans `sessionStorage` (`wfbt_ic_tracked_{cart_hash}`). Il ne part qu'une fois par panier, même en cas de rechargement ou de retour Alma/Stripe.
- **Traçabilité totale des sources d'AddToCart** :
  Chaque AddToCart est étiqueté (`fragment`, `endpoint`, `page_render`, `endpoint_cache_bypass`, `dom`) dans `window.wfbtEventsLog` et mis en évidence par un badge coloré dans la barre de débogage flottante admin.

### K. Méthode de Diagnostic « Taux de correspondance catalogue 0 % »
1. **Distinguer les deux écrans Meta** : *Gestionnaire d'événements* (l'événement arrive-t-il ?) vs *Gestionnaire des ventes > Catalogue > Événements* (l'ID envoyé existe-t-il dans le catalogue ?). Un `ViewContent` « Actif » avec 0 % de correspondance = problème d'ID ou de délai, pas d'envoi.
2. **Comparer sur preuve, pas sur hypothèse** : relever `wfbt_page_events` dans le HTML d'une fiche produit en ligne et chercher le même ID dans `/feed/meta-catalog.xml` (`<g:id>…</g:id>`). Vérifier aussi que `wfbt_pixel_id` = ID du pixel associé au catalogue.
3. **Délai Meta** : un catalogue créé récemment reste à 0 % / « Manquant » plusieurs jours (fenêtre de calcul de 28 jours). Ne pas conclure avant 3 à 7 jours après création ou correction.
4. **Leçon comptoirdecambaie.re & kidshow.fr (oct. 2026)** : l'alignement des content_ids doit être vérifié dès la configuration du catalogue. Si le catalogue Meta a été importé avec les IDs produits WooCommerce (ex: `10261`), le mode `id` doit être explicitement choisi dans les réglages du plugin (`wfbt_content_id_format = 'id'`).

### L. Architecture Hybride Complète Server-Side & Déduplication 1:1 (v2.2.0)

Dans la version 2.1.1, seul l'événement `Purchase` bénéficiait de la passerelle CAPI Server-Side. `AddToCart`, `InitiateCheckout` et `ViewContent` dépendaient exclusivement du navigateur (`fbevents.js`).
Sur un site comme `kidshow.fr`, cette asymétrie produisait des anomalies critiques :
- **Perte massive d'AddToCart** (seulement 2 AddToCart reçus pour 118 InitiateCheckout et 94 Purchase sur 28 jours), causée par :
  1. Les bloqueurs de publicité (uBlock Origin, AdGuard, Brave Shields) bloquant `fbevents.js` en totalité.
  2. Les carrousels thèmes personnalisés (ex: `/home-2/`) dont les balises d'ajout utilisent `<a href="?add-to-cart=123">` sans attribut `data-product_id`.
  3. Les utilisateurs ajoutant au panier avant d'avoir validé le bandeau de consentement, dont la file en mémoire RAM était perdue lors d'une navigation.
- **Correspondance Catalogue à 0%** : le catalogue Meta 278475902616040 étant indexé sur les Product IDs WooCommerce (`10261`), les événements du plugin envoyaient des SKUs (`couverture-starwars-1-1`) en mode `auto`.

#### 🛡️ Innovations Architecturales Introduites en v2.2.0 :
1. **Double Passerelle CAPI Asynchrone Action Scheduler (`Background_Processor::schedule_payload`)** :
   - `capture_add_to_cart()` (hook `woocommerce_add_to_cart`) génère l'événement `AddToCart` et planifie immédiatement l'envoi CAPI via Action Scheduler (`ACTION_PAYLOAD_HOOK`), tout en conservant le payload en session pour les fragments AJAX.
   - `maybe_send_initiate_checkout_capi()` planifie l'envoi CAPI de `InitiateCheckout` au chargement de `/commande/`, dédupliqué strictement par `cart_hash` pour neutraliser les actualisations ou retours de passerelle.
   - Les appels réseau vers Meta Graph API v21.0 s'exécutent en tâche de fond asynchrone non-bloquante, préservant 100% du TTFB et de la vitesse perçue de la boutique.
2. **Déduplication Parfaite 1:1 Browser ⇄ Server** :
   - Le serveur forge un `eventID` déterministe partagé (`atc_{cart_item_key}_{ts}` pour AddToCart, `ic_{hash}` pour InitiateCheckout, `order_{id}` pour Purchase).
   - Les deux événements sont envoyés avec ce même identifiant. Meta Events Manager effectue la réconciliation sans sur-comptage.
3. **Résilience Anti-Bloqueurs Avancée (`extract_request_user_data`)** :
   - Lorsqu'un AdBlocker bloque le Pixel client, le cookie `_fbp` n'est jamais déposé par Meta.
   - La méthode `extract_request_user_data()` détecte cette absence et génère automatiquement un identifiant first-party `_fbp` canonique (`fb.1.{time}.{rand}`) déposé en cookie HTTP et injecté dans le payload CAPI.
   - L'adresse IP réelle est résolue sous Cloudflare Enterprise (`HTTP_CF_CONNECTING_IP`), garantissant un niveau élevé d'Event Match Quality (EMQ) et zéro rejet HTTP 400.
4. **File Pré-Consentement Persistée (`sessionStorage`)** :
   - `wfbtDispatchAtc()` persiste la file d'attente pré-consentement dans `sessionStorage.setItem('wfbt_pending_consent_atc')`.
   - Si le visiteur navigue vers une autre page avant d'accepter les cookies, les ajouts au panier ne sont plus perdus et sont immédiatement dépilés et envoyés dès que le consentement est accordé (`initMetaPixel`).
5. **Extraction Universelle Regex Thèmes & Carrousels** :
   - `wfbtFallbackAtcFromDom()` intègre une détection regex des URLs d'ajout au panier `/[?&]add-to-cart=(\d+)/` et `/[?&]quantity=(\d+)/` sur l'attribut `href` des boutons AJAX personnalisés sans `data-product_id`.
6. **Harmonisation Fine des Catalogues (`Product_Id`)** :
   - Ajout de `Product_Id::get_view_content_type()` pour attribuer dynamiquement `product` ou `product_group` aux fiches produits variables selon la présence des déclinaisons exportées.

---

## 6. 📌 Suivi & Backlog (au 09/10/2026)

| Sujet | État | Action |
| :--- | :--- | :--- |
| Release v2.2.0 (Architecture Hybride Complète CAPI AddToCart + InitiateCheckout, déduplication 1:1, anti-adblocker _fbp auto, pré-consentement sessionStorage, regex href carrousels, Product_Id::get_view_content_type) | ✅ Validée & Prête pour Release | Tag GitHub `v2.2.0` + déploiement production KidShow |
| KidShow (kidshow.fr) : Configuration du Format Content ID sur "WooCommerce Product ID" | ⏳ À faire en admin | Sélectionner "WooCommerce Product ID (e.g. 1234)" dans réglages |
| Release v2.1.1 (Résilience AddToCart multi-chemins, endpoint `?wc-ajax=wfbt_pending_atc`) | ✅ Déployée | Supplantée par v2.2.0 |
| `woo-meta-catalog-feed-soyoo` v1.3.0 (filtre `soyoo_meta_catalog_content_id`, `Feed_Item::get_content_id()`, encadré statut tracking, fix SKU variations) | ⏳ À faire dans la session dédiée du flux | Prompt fourni à Julien |
| comptoirdecambaie.re : contrôle du ratio AddToCart / InitiateCheckout | ⏳ À contrôler | Le ratio doit repasser > 1 |
| Page de remerciement : `Purchase` rendu pour tout `order-received` sans vérifier `?key=` (fuite du montant d'une commande tierce) | ⚠️ Non corrigé | Ajouter `$order->key_is_valid( $_GET['key'] )` dans `get_page_event_data()` |
| Traductions fr_FR des nouvelles chaînes v2.2.0 | ⚠️ Manquantes | Régénérer `.pot`, compléter `.po`, recompiler `.mo` |

---

## 7. 🚀 Procédure de Release & Déploiement

À chaque fois que vous apportez des modifications fonctionnelles au code du projet et souhaitez publier une nouvelle version stable :
- **N'effectuez PAS la release manuellement.**
- Suivez les étapes automatisées du workflow : [`.agent/workflows/agent-releaser.md`](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/.agent/workflows/agent-releaser.md).
- Ce workflow s'assure d'incrémenter les versions dans `woo-fb-tracking-server-side.php` et `readme.txt`, de générer l'archive `.zip` propre, de committer, de tagguer et de publier la release officielle via GitHub CLI (`gh`).
- **Notes de release multilignes** : passer par `gh release create … --notes-file <fichier.md>` (rédigé dans le dossier scratch de l'agent) plutôt que `--notes` : les guillemets et backticks du changelog cassent l'échappement PowerShell.
- **Après publication** : les sites récupèrent la mise à jour via PUC (cycle ~12 h ou « Vérifier les mises à jour »), puis purge des caches (WP Agent Bridge si installé).
