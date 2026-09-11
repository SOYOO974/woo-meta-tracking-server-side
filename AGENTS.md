# Fichier de Contexte : Woo FB Tracking Server-Side (Architecture Hybride Native v2.0.0)

> [!IMPORTANT]
> **Consigne de mise à jour :** Ce fichier `AGENTS.md` sert de référence contextuelle absolue pour comprendre le fonctionnement global et les spécificités techniques du plugin. **À chaque fois que vous modifiez le code du projet, vous devez impérativement mettre à jour ce fichier pour refléter les changements effectués.**

---

## 1. Description Générale & Philosophie d'Ingénierie

Ce plugin transforme le suivi e-commerce WooCommerce pour Meta en une **architecture hybride native** ultra-performante et résiliente, combinant :
1. **Le Pixel Navigateur front-end (`fbq`)** : Capture instantanée du haut de tunnel (`PageView`, `ViewContent`, `AddToCart` AJAX, `InitiateCheckout`) et déclenchement initial de `Purchase`.
2. **L'API de Conversions Meta Server-Side (CAPI Graph API v21.0)** : Transmission asynchrone sécurisée de l'événement `Purchase` via WooCommerce Action Scheduler, totalement insensible aux bloqueurs de publicité (AdBlockers) et aux restrictions de cookies (ITP Safari iOS).
3. **Une déduplication parfaite à 100%** : Les événements `Purchase` front-end et serveur partagent strictement le même identifiant : `eventID: 'order_' + order_id`. Meta fusionne les signaux sans doubler les conversions ni le chiffre d'affaires.
4. **Conformité RGPD stricte asservie à Concord Cookie Banner** : Respect absolu du consentement marketing, écoute dynamique des événements d'acceptation en direct (activation à chaud sans rechargement de page), et blocage CNIL ou anonymisation CAPI côté serveur.
5. **Compatibilité native WooCommerce HPOS (High-Performance Order Storage)** : Bannissement total de l'ancienne API post-meta au profit exclusif des méthodes CRUD de l'objet `$order` (`custom_order_tables`).
6. **Normalisation E.164 avancée (La Réunion + France)** : Nettoyage et conversion automatique des préfixes réunionnais (`0692`, `0693`, `0262` $\rightarrow$ `+262`) et métropolitains (`+33`) avant hachage SHA-256.
7. **Mises à jour automatiques transparentes** : Bibliothèque `plugin-update-checker` (v5.6) connectée directement aux releases GitHub de `SOYOO974/woo-meta-tracking-server-side`.

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
└── languages/                              # Fichiers de traduction i18n
```

### Détail des Composants Clés

- **[woo-fb-tracking-server-side.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/woo-fb-tracking-server-side.php)** :
  - Déclare la compatibilité HPOS (`FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`).
  - Initialise `plugin-update-checker` v5.6 relié à `SOYOO974/woo-meta-tracking-server-side` avec support des release assets.
  - Démarre le composant public `Public_Handler::init()` et le contrôleur central `Core::instance()`.
- **[readme.txt](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/readme.txt)** :
  - Fichier conforme au standard WordPress.org contenant les métadonnées de version, la description, et le changelog extrait par PUC pour la modale native des extensions.
- **[includes/class-wfbt-core.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-core.php)** :
  - Écoute les transitions de statuts de commande via `woocommerce_order_status_changed` et filtre dynamiquement selon les statuts configurés (`wfbt_trigger_statuses`, par défaut `processing` et `completed`).
  - Gère le verrouillage anti-doublon via la méta HPOS `_wfbt_capi_scheduled` et planifie l'action asynchrone.
- **[includes/class-wfbt-meta-api.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-meta-api.php)** :
  - Moteur CAPI Graph API v21.0 (`https://graph.facebook.com/v21.0/{pixel_id}/events`).
  - Implémente `format_phone_e164($phone, $country)` pour La Réunion et la France.
  - Hachage SHA-256 de tous les champs PII (`em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`).
  - Injection des identifiants non hachés (`fbp`, `fbc`, `client_ip_address`, `client_user_agent`).
  - Gouvernance RGPD : annulation (`block`) ou anonymisation (`anonymize`) si `_wfbt_consent === 'denied'`.
  - Mise à jour HPOS des statuts de commande (`Success`, `Failed`, `Ignored (Consent Denied)`).
- **[includes/class-wfbt-background-processor.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-background-processor.php)** :
  - File d'attente asynchrone Action Scheduler (hook `wfbt_send_capi_event`, groupe `wfbt_capi`).
- **[includes/class-wfbt-admin-settings.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/includes/class-wfbt-admin-settings.php)** :
  - Formulaire de configuration avec bascules Pixel front, RGPD Concord, statuts déclencheurs personnalisés et alertes e-mail.
  - Onglet Diagnostics : test de connexion CAPI direct en AJAX (v21.0) et tableau d'audit des 20 dernières commandes avec détection en temps réel de `_fbp`, `_fbc`, badge de consentement Concord et bouton « Renvoyer ».
- **[public/class-wfbt-public.php](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/public/class-wfbt-public.php)** :
  - Injection front du script `fbevents.js` et déclenchement des événements `PageView`, `ViewContent`, `AddToCart` (AJAX WooCommerce `added_to_cart`), `InitiateCheckout` et `Purchase`.
  - Intégration Concord Cookie Banner (fonction JS helper `wfbtHasMarketingConsent()`, écouteurs d'événements et polling léger).
  - Capture de `?fbclid=` en cookie first-party `wfbt_fbclid` (90 jours) + `localStorage`.
  - Injection de champs masqués au checkout pour sauvegarder `_wfbt_fbp`, `_wfbt_fbc` et `_wfbt_consent`.

---

## 3. ⚙️ Cycle de Vie Détaillé des Données

### 1. Capture Navigateur & Atterrissage
1. Le visiteur clique sur une annonce Facebook/Instagram et atterrit avec `?fbclid=...`.
2. Le script JS (`public/class-wfbt-public.php`) enregistre ce `fbclid` dans le cookie `wfbt_fbclid` (durée 90 jours, `SameSite=Lax`) et dans le `localStorage`.
3. Dès que le consentement marketing Concord est accordé :
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
   - **Contrôle RGPD** : Si `_wfbt_consent === 'denied'`, le plugin annule l'envoi (`Ignored (Consent Denied)`) en mode strict CNIL ou anonymise le payload.
   - **Normalisation E.164 Réunion/France** : Le numéro de téléphone est converti au format strict (ex: `0692 12 34 56` $\rightarrow$ `262692123456`) puis haché en SHA-256.
   - **Enrichissement PII** : Email, nom, prénom, ville, code postal, pays (`re`, `fr`) hachés en SHA-256 minuscules.
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
| `_wfbt_consent` | `string` | État du consentement Concord (`granted`, `denied`, `unknown`). |
| `_wfbt_capi_scheduled` | `string` | Verrou (`yes`) empêchant la planification multiple. |
| `_wfbt_capi_status` | `string` | Statut CAPI (`Pending`, `Success`, `Failed`, `Ignored (Consent Denied)`). |
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

---

## 6. 🚀 Procédure de Release & Déploiement

À chaque fois que vous apportez des modifications fonctionnelles au code du projet et souhaitez publier une nouvelle version stable :
- **N'effectuez PAS la release manuellement.**
- Suivez les étapes automatisées du workflow : [`.agent/workflows/agent-releaser.md`](file:///c:/Antigravity/woo-plugins/woo-fb-tracking-server-side/.agent/workflows/agent-releaser.md).
- Ce workflow s'assure d'incrémenter les versions dans `woo-fb-tracking-server-side.php` et `readme.txt`, de générer l'archive `.zip` propre, de committer, de tagguer et de publier la release officielle via GitHub CLI (`gh`).
