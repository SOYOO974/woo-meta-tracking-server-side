# Fichier de Contexte : Woo FB Tracking Server-Side (Architecture Hybride Native v2.0.8)

> [!IMPORTANT]
> **Consigne de mise à jour :** Ce fichier `AGENTS.md` sert de référence contextuelle absolue pour comprendre le fonctionnement global et les spécificités techniques du plugin. **À chaque fois que vous modifiez le code du projet, vous devez impérativement mettre à jour ce fichier pour refléter les changements effectués.**

---

## 1. Description Générale & Philosophie d'Ingénierie

Ce plugin transforme le suivi e-commerce WooCommerce pour Meta en une **architecture hybride native** ultra-performante et résiliente, combinant :
1. **Le Pixel Navigateur front-end (`fbq`)** : Capture instantanée du haut de tunnel (`PageView`, `ViewContent`, `AddToCart` AJAX, `InitiateCheckout`) et déclenchement initial de `Purchase`.
2. **L'API de Conversions Meta Server-Side (CAPI Graph API v21.0)** : Transmission asynchrone sécurisée de l'événement `Purchase` via WooCommerce Action Scheduler, totalement insensible aux bloqueurs de publicité (AdBlockers) et aux restrictions de cookies (ITP Safari iOS).
3. **Une déduplication parfaite à 100%** : Les événements `Purchase` front-end et serveur partagent strictement le même identifiant : `eventID: 'order_' + order_id`. Meta fusionne les signaux sans doubler les conversions ni le chiffre d'affaires.
4. **Conformité RGPD Multi-Bannières (Woo Gads Native + Concord) & Annulation Propre** : Respect absolu du consentement marketing en cascade prioritaire (Priorité 1 : cookie first-party `woo_gads_consent` avec payload JSON `marketing: true|false` ; Priorité 2 : cookie et objet global Concord / préfixes personnalisés). Écoute dynamique des événements d'acceptation en direct (bouton `#woo-gads-btn-accept`, événement `woo_gads_consent_updated`, `concord:consent`). En cas de refus explicite, annulation propre et sécurisée de la transmission CAPI (`Ignored (Consent Denied)`), protégeant la boutique contre les rejets HTTP 400 de Meta et garantissant la conformité stricte CNIL.
5. **Garde Pré-Vol CAPI & Anti-Erreur 400** : Vérification stricte de la présence d'au moins un identifiant direct client (`em`, `ph`, `fbp`, `fbc`, `external_id`). Si aucun n'est présent (ex: commandes manuelles sans coordonnées), l'événement est court-circuité avec le statut `Ignored (Insufficient Customer Data)`, évitant le code d'erreur Meta 100 / sous-code 2804050 et les alertes email intempestives.
6. **Enrichissement Event Match Quality (`external_id`)** : Transmission du Customer ID WooCommerce (`$order->get_customer_id()`) pour maximiser la correspondance Meta.
7. **Compatibilité native WooCommerce HPOS (High-Performance Order Storage)** : Bannissement total de l'ancienne API post-meta au profit exclusif des méthodes CRUD de l'objet `$order` (`custom_order_tables`).
8. **Normalisation E.164 avancée (La Réunion + France)** : Nettoyage et conversion automatique des préfixes réunionnais (`0692`, `0693`, `0262` $\rightarrow$ `+262`) et métropolitains (`+33`) avant hachage SHA-256.
9. **Tableau de Bord Exécutif de Diagnostics & Barre de Débogage Front-End** : Grille pré-vol complète, inspecteur de cookies de session active (`woo_gads_consent`, `concord`, `_fbp`, `_fbc`), testeur de santé Meta Graph API v21.0 (`GET /{pixel_id}`), KPIs HPOS 30 jours, histogramme d'activité 14 jours en pur SVG vectoriel natif (zéro librairie JS externe), et barre de débogage flottante admin en direct sur la boutique (avec affichage de la source du consentement).
10. **Mises à jour automatiques transparentes** : Bibliothèque `plugin-update-checker` (v5.6) connectée directement aux releases GitHub de `SOYOO974/woo-meta-tracking-server-side`.

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
  - Onglet Configuration : détection automatique de la bannière native Woo Gads (`woo_gads_settings['enable_builtin_banner']`), formulaire avec bascules Pixel front, barre de débogage flottante admin (`wfbt_enable_debug_bar`), gestion RGPD simplifiée (Woo Gads + Concord) avec annulation automatique en cas de refus, statuts déclencheurs personnalisés et alertes e-mail.
  - Onglet Diagnostics Exécutif :
    - Grille pré-vol d'état (Identifiants Meta, Architecture HPOS & Action Scheduler, RGPD Consent Management, Débogueur front).
    - Inspecteur de cookies de session active en temps réel (`woo_gads_consent`, `concord`, `_fbp`, `_fbc`) avec bouton d'actualisation et simulation de clic pub Meta (`?fbclid=`).
    - Outils d'interrogation Meta Graph API v21.0 : test de santé du Dataset (`GET /{pixel_id}`) et test de connexion CAPI (`POST /{pixel_id}/events`).
    - KPIs de performance HPOS sur 30 jours (taux de succès CAPI, taux de capture `_fbp`, taux de clics Meta Ads `_fbc`, volume de commandes, ratio de consentement marketing).
    - Histogramme d'activité quotidien sur 14 jours généré en pur SVG vectoriel natif (zéro librairie JS externe).
    - Tableau d'audit HPOS des 20 dernières commandes avec détection en temps réel de `_fbp`, `_fbc`, badge de consentement et bouton « Renvoyer ».
  - Onglet Tutoriel & Guide de configuration : 8 étapes structurées sous forme d'accordéon repliable (fermé par défaut) avec boutons « Tout déplier / Tout replier ». Détaille exhaustivement le paramétrage Meta (choix exclusif de l'événement Acheter, matrice exacte des cases à cocher client/événement, génération du token Dataset Quality API, test en direct et Pixel Helper).
- **[public/class-wfbt-public.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/public/class-wfbt-public.php)** :
  - Injection front du script `fbevents.js` et déclenchement des événements `PageView`, `ViewContent`, `AddToCart` (AJAX WooCommerce `added_to_cart`), `InitiateCheckout` et `Purchase`.
  - Instrumentation non intrusive du Pixel `window.fbq` pour journaliser tous les appels dans `window.wfbtEventsLog`.
  - Barre de débogage flottante en direct (`maybe_render_debug_bar`) pour administrateurs et gestionnaires de boutique (`manage_woocommerce`, `manage_options`, ou `?wfbt_debug=1`) : pastille repliable en bas à droite inspectant l'état du Pixel, le consentement marketing avec sa source (`GRANTED (woo_gads)`, `GRANTED (concord)`), `_fbp`, `_fbc`, `?fbclid=`, le flux temps réel de tous les événements `fbq` avec paramètres et boutons d'actions rapides.
  - Détection du consentement en cascade : Priorité 1 au cookie `woo_gads_consent` (avec parsing JSON), Priorité 2 au cookie Concord ou préfixe personnalisé, et objet global `window.ConcordConsent`.
  - Activation à chaud sans rechargement de page : écoute du bouton `#woo-gads-btn-accept`, de l'événement `woo_gads_consent_updated`, des événements Concord et polling léger fallback.
  - Capture PHP native `detect_consent_php()` (avec alias rétrocompatible `detect_concord_consent_php()`).
  - Capture de `?fbclid=` en cookie first-party `wfbt_fbclid` (90 jours) + `localStorage`.
  - Injection de champs masqués au checkout pour sauvegarder `_wfbt_fbp`, `_wfbt_fbc` et `_wfbt_consent`.

---

## 3. ⚙️ Cycle de Vie Détaillé des Données

### 1. Capture Navigateur & Atterrissage
1. Le visiteur clique sur une annonce Facebook/Instagram et atterrit avec `?fbclid=...`.
2. Le script JS (`public/class-wfbt-public.php`) enregistre ce `fbclid` dans le cookie `wfbt_fbclid` (durée 90 jours, `SameSite=Lax`) et dans le `localStorage`.
3. Dès que le consentement marketing est accordé (via la bannière native Woo Gads ou Concord) :
   - Le Pixel s'initialise (`fbq('init', pixel_id)`).
   - Meta dépose ses propres cookies first-party `_fbp` (Browser ID) et `_fbc` (Click ID).
   - L'événement `PageView` est envoyé, ainsi que l'événement spécifique de la page consultée (`ViewContent` ou `InitiateCheckout`).
4. Lors de l'ajout au panier, l'écouteur jQuery `added_to_cart` intercepte les ajouts AJAX (compatible tiroirs paniers / Cart Drawers) et déclenche `fbq('track', 'AddToCart', ...)`.

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

---

## 6. 🚀 Procédure de Release & Déploiement

À chaque fois que vous apportez des modifications fonctionnelles au code du projet et souhaitez publier une nouvelle version stable :
- **N'effectuez PAS la release manuellement.**
- Suivez les étapes automatisées du workflow : [`.agent/workflows/agent-releaser.md`](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/.agent/workflows/agent-releaser.md).
- Ce workflow s'assure d'incrémenter les versions dans `woo-fb-tracking-server-side.php` et `readme.txt`, de générer l'archive `.zip` propre, de committer, de tagguer et de publier la release officielle via GitHub CLI (`gh`).
