# AGENTS.md — Woo FB Tracking Server-Side (Architecture Hybride Native v2.3.2)

> **Règle de maintenance du contexte** : Ce fichier est plafonné à **`<= 14 000` octets** en pyramide inversée pour éviter toute troncature Antigravity (`24 Ko`). À chaque modification du code, mettez à jour les invariants ci-dessous si impactés et consignez 100 % des détails techniques dans [`docs/architecture/`](docs/architecture/) (`<= 40 Ko` par fichier).

---

## 1. Invariants Critiques Non-Négociables & Garde-Fous

1. **Déduplication Stricte 1:1 Browser (`fbq`) ⇄ Server (CAPI Graph API v21.0)** :
   - Le 4e argument navigateur `{ eventID }` doit correspondre à l'octet près à `event_id` côté CAPI :
     - `Purchase` : `order_{order_id}` — côté Pixel, émis **uniquement** si `$order->key_is_valid($_GET['key'])` **ET** (`$order->is_paid()` OU statut dans `wfbt_trigger_statuses`), avec verrou `sessionStorage` (`wfbt_purchase_tracked_order_{id}`).
     - `AddToCart` : `atc_{12_chars}` généré côté serveur (`wp_generate_password(12, false)`), propagé au front via `fragments.wfbt_atc`, endpoint `?wc-ajax=wfbt_pending_atc` ou `wfbt_page_events`.
     - `InitiateCheckout` : `ic_{md5(cart_hash + customer_id)}`, verrouillé par panier en `sessionStorage` (`wfbt_ic_tracked_{cart_hash}`) et en session WC (`wfbt_capi_ic_sent_{cart_hash}`).
2. **Compatibilité HPOS Exclusive (`custom_order_tables`)** :
   - **Interdiction absolue** d'appeler `get_post_meta()`, `update_post_meta()` ou `delete_post_meta()` sur une commande (requêtes fantômes dans `wp_postmeta`).
   - Utiliser exclusivement le CRUD `$order` : `$order->get_meta()`, `$order->update_meta_data()`, `$order->delete_meta_data()`, `$order->save()` / `$order->save_meta_data()`.
3. **Source Unique des `content_ids` (`\WFBT\Product_Id::get()`)** :
   - Interdiction de calculer un ID produit en dur dans `Public_Handler` ou `Meta_Api`. Tout passe par `Product_Id::get()` (mode `auto` déléguant en sens unique au filtre `soyoo_meta_catalog_content_id` de `woo-meta-catalog-feed-soyoo`, sans lecture croisée d'options). Sur produit variable, `ViewContent` envoie les variations visibles (max 50) et `Product_Id::get_view_content_type()` résout `product` vs `product_group`.
4. **Gouvernance RGPD Multi-Bannières & Règle CAPI `unknown` (Décision Julien 12/10/2026)** :
   - Cascade de détection : **Priorité 1** cookie first-party `woo_gads_consent` (`marketing: true|false`) → **Priorité 2** cookie/objet Concord (`wfbt_concord_cookie_name`).
   - **Verrou anti-préfixe vide (v2.3.2)** : `get_concord_cookie_prefix()` et `sanitize_concord_cookie_name()` forcent le repli `'concord'` si l'option vaut `""` (évite `stripos($name, "") === 0` en PHP 8 / `indexOf("")` en JS qui matchait tous les cookies du domaine) et excluent les cookies internes (`woo_gads_consent`, `wfbt_js`, `_fbp`, `_fbc`, `wfbt_fbc`, `wfbt_fbclid`).
   - **Pixel navigateur** : attend le consentement explicite (`granted`), avec file persistée `sessionStorage` (`wfbt_pending_consent_atc`) rejouée dès acceptation.
   - **CAPI serveur** : **seul `denied` bloque l'envoi** (`Ignored (Consent Denied)`). Un visiteur `unknown` (bannière ignorée/fermée) est envoyé côté serveur avec dépôt d'un `_fbp` first-party serveur (`extract_request_user_data()`). **Risque assumé : ne jamais durcir sans accord explicite de Julien.**
5. **Qualification Anti-Robots, Garde Pré-Vol Anti-400 (`2804050`) & Qualité EMQ / ARCEP** :
   - **Zéro AddToCart robot/programmatique** : `get_add_to_cart_skip_reason()` bloque `bot` (`is_bot_request`), `cron`, `wp_cli`, `rest_api` (hors Store API), `admin` (hors AJAX) et `cart_restore` (`?wcf_ac_token`). Le CAPI `AddToCart` exige en plus un signal navigateur réel (cookie `wfbt_js` ou `_fbp`) et un anti-doublon 10 min (`wfbt_capi_atc_sent`). En front, `inject_add_to_cart_fragment()` ne dépile la session que si `$added_in_current_request === true` et ignore les refreshs du tiroir panier.
   - **Garde pré-vol CAPI** : exige au moins 1 identifiant direct (`em`, `ph`, `fbp`, `fbc`, `external_id`), sinon court-circuite en `Ignored (Insufficient Customer Data)` sans erreur HTTP 400 ni alerte mail.
   - **EMQ & Matching** : `external_id` haché SHA-256 identique Pixel/CAPI ; Advanced Matching `fbq('init', id, data)` limité à `is_checkout()` (jamais sur pages cachées Edge, appel `extract_request_user_data(false)`) ; `fbc` horodaté au clic réel via cookie `wfbt_fbc` (`_fbc` > `wfbt_fbc` > `wfbt_fbclid`) ; segmentation `customer_type` (`new`/`returning`) calculée via `wc_get_is_paid_statuses()` ; téléphones normalisés ARCEP (`Meta_Api::format_phone_e164` : `262` Réunion/Mayotte `0692/0693/0262/0263/0639/0269`, `590/594/596` DOM, `33` métropole, rejet `''` des `08` et numéros invalides).
   - **Pistes écartées volontairement (ne pas réintroduire)** : cookies 400 j (inutile sous ITP + hors plafond 13 mois CNIL), cross-device stitching SQL par email, minoration/blocage du CA des clients existants ou événement `NewCustomerPurchase`.
6. **Sécurité Dépôt Public, i18n (`wfbt-server-side`), Anti-403 & Release PUC v5.6** :
   - Dépôt public `SOYOO974/woo-meta-tracking-server-side` : **zéro secret** (`EAAB...`, `ghp_...`, clés, données clients), **zéro déploiement FTP par défaut**.
   - **Interdiction formelle de `Compress-Archive` PowerShell** (antislashs `\` cassant Linux) : packager uniquement en ZIP POSIX (`/`) avec dossier racine `woo-fb-tracking-server-side/` via [`.agent/workflows/agent-releaser.md`](.agent/workflows/agent-releaser.md).
   - Code source 100 % anglais (`wfbt-server-side`) + synchronisation `languages/wfbt-server-side.{pot,po,mo}` (`fr_FR`) + validation `php -l` sur tout fichier PHP modifié + bouclier anti-403 `user_has_cap` (`manage_options` → `manage_woocommerce`).

---

## 2. Checklist Pré-Release en 6 Points

- [ ] `php -l` valide sur 100 % des fichiers PHP modifiés.
- [ ] `git diff` contrôlé : zéro token Meta/GitHub, URL privée ou donnée client.
- [ ] Chaînes UI en anglais (`wfbt-server-side`) et fichiers `languages/wfbt-server-side.{pot,-fr_FR.po,-fr_FR.mo}` à jour si texte ajouté/modifié.
- [ ] Versions alignées sur les 3 emplacements : en-tête `Version:` + `WFBT_VERSION` dans `woo-fb-tracking-server-side.php` + `Stable tag:` et `== Changelog ==` dans `readme.txt`.
- [ ] Archive `woo-meta-tracking-server-side.zip` générée en Python (`as_posix()`, zéro `\`, racine `woo-fb-tracking-server-side/woo-fb-tracking-server-side.php`).
- [ ] Commit conventionnel (`git add` incluant `public/`, `includes/`, `languages/`, `AGENTS.md`, `docs/`), push `main` + tag `vX.Y.Z` + `gh release create vX.Y.Z ... --notes-file`.

---

## 3. Cartographie Rapide du Code & des Clés

| Fichier / Clé | Rôle / Portée |
| :--- | :--- |
| [`woo-fb-tracking-server-side.php`](woo-fb-tracking-server-side.php) | Bootstrap, déclaration HPOS, PUC v5.6 (`enableReleaseAssets`), bouclier anti-403 `user_has_cap` & redirection admin. |
| [`includes/class-wfbt-core.php`](includes/class-wfbt-core.php) | Hooks `woocommerce_order_status_changed` (`wfbt_trigger_statuses`), verrou `_wfbt_capi_scheduled` & enfilement Action Scheduler. |
| [`includes/class-wfbt-meta-api.php`](includes/class-wfbt-meta-api.php) | Client CAPI Graph API v21.0, garde pré-vol 2804050, SHA-256 PII/`external_id`, ARCEP E.164, IP Cloudflare, `_fbp` serveur, `customer_type`. |
| [`includes/class-wfbt-product-id.php`](includes/class-wfbt-product-id.php) | Résolveur unique `content_ids` (`auto` via `soyoo_meta_catalog_content_id`, `sku`, `id`, `gla`, `fb_wc`) & `get_view_content_type()`. |
| [`includes/class-wfbt-background-processor.php`](includes/class-wfbt-background-processor.php) | Files asynchrones Action Scheduler (`wfbt_send_capi_event` commandes & `schedule_payload` ATC/IC, groupe `wfbt_capi`). |
| [`public/class-wfbt-public.php`](public/class-wfbt-public.php) | Pixel `fbq`, cascade consentement (`woo_gads_consent` > Concord), capture ATC multi-niveaux + endpoint `?wc-ajax=wfbt_pending_atc`, filtre bots, Advanced Matching checkout, debug bar admin. |
| [`includes/class-wfbt-admin-settings.php`](includes/class-wfbt-admin-settings.php) | Réglages WooCommerce, diagnostics exécutifs HPOS 30 j, inspecteur cookies temps réel, tests Graph API v21.0, histogramme SVG 14 j, guide 8 étapes. |
| [`includes/class-wfbt-logger.php`](includes/class-wfbt-logger.php) | Journalisation `WC_Logger` (source `wfbt-server-side`). |
| **Métas HPOS commande** | `_wfbt_fbp`, `_wfbt_fbc`, `_wfbt_consent` (`granted`\|`denied`\|`unknown`), `_wfbt_capi_scheduled`, `_wfbt_capi_status`, `_wfbt_capi_sent_at`, `_wfbt_capi_error`, `_wfbt_customer_type` (`new`\|`returning`). |
| **Cookies & Session Front** | Cookies : `wfbt_js` (24 h signal navigateur), `wfbt_has_pending_atc` (60 s bypass Edge Cache), `wfbt_fbc` (90 j clic ms), `wfbt_fbclid` (90 j). Session WC : `wfbt_pending_atc`, `wfbt_capi_atc_sent` (10 min), `wfbt_capi_ic_sent_{hash}`. `sessionStorage` : `wfbt_pending_consent_atc`, `wfbt_ic_tracked_{hash}`, `wfbt_purchase_tracked_order_{id}`. |

---

## 4. Table de Routage Obligatoire (`docs/architecture/`)

> **Instruction impérative** : Avant de modifier un contrôleur, un hook front/AJAX, la logique de consentement, le formatage CAPI ou de publier une release, lisez **obligatoirement** le module documentaire correspondant ci-dessous.

| Document d'architecture (`<= 40 Ko`) | Contenu technique intégral (100 % sans perte) | Quand le lire obligatoirement |
| :--- | :--- | :--- |
| [`docs/architecture/01-components-lifecycle-and-schema.md`](docs/architecture/01-components-lifecycle-and-schema.md) | Philosophie d'ingénierie en 13 points, arborescence complète, responsabilités détaillées de chaque classe PHP, cycle de vie de bout en bout (atterrissage `?fbclid=`, ATC AJAX/POST, checkout HPOS, Thank You Page, Action Scheduler CAPI) et dictionnaire exhaustif des métadonnées `_wfbt_*` et cookies/sessions. | **Avant toute intervention sur l'architecture générale, le flux d'une commande, l'admin/diagnostics ou les métadonnées HPOS**. |
| [`docs/architecture/02-capi-consent-atc-and-field-autopsies.md`](docs/architecture/02-capi-consent-atc-and-field-autopsies.md) | Décisions architecturales §A à §O et autopsies terrain complètes : déduplication 1:1, HPOS, normalisation ARCEP E.164 (`262`/`590`/`594`/`596`/`33`), i18n Loco, garde 2804050, contrat `Product_Id` & diagnostic catalogue 0 %, résilience ATC 5 pièges (`comptoirdecambaie.re`, `kidshow.fr`), filtre anti-robots & règle RGPD `unknown` (v2.2.1), Purchase sécurisé / Advanced Matching / `customer_type` / pistes écartées (v2.3.0–2.3.1), et blindage du préfixe Concord vide PHP 8 / JS (`jardin-naturel.re`, v2.3.2). | **Avant toute modification de `class-wfbt-public.php`, `class-wfbt-meta-api.php`, `class-wfbt-product-id.php`, du consentement RGPD ou d'un diagnostic d'écart d'événements Meta**. |
| [`docs/architecture/03-releases-and-backlog.md`](docs/architecture/03-releases-and-backlog.md) | Journal de suivi & backlog multi-sites SOYOO (actions en attente Meta Ads Manager, `comptoirdecambaie.re`, `kidshow.fr`, `woo-meta-catalog-feed-soyoo`) et guide opérationnel complet de release GitHub / PUC v5.6 (pièges d'échappement PowerShell `--notes-file`, packaging Python `as_posix()` sans antislash). | **Avant toute publication de nouvelle version GitHub ou revue du backlog projet**. |
| [`.agent/workflows/agent-releaser.md`](.agent/workflows/agent-releaser.md) | Séquence pas-à-pas exécutable de release (tags Git, changelog `readme.txt`, build `.zip` POSIX et publication `gh release create`). | **Lors de l'exécution d'une release officielle du plugin**. |
