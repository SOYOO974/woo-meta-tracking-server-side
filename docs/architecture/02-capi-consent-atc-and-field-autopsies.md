# Décisions Architecturales, Pièges Résolus & Autopsies Terrain (§A à §O)

> Référence détaillée rattachée à [`AGENTS.md`](../../AGENTS.md). Conserve sans aucune perte l'intégralité des décisions d'ingénierie, des garde-fous CAPI/RGPD/HPOS/ARCEP et des retours d'expérience clients (`comptoirdecambaie.re`, `kidshow.fr`, `jardin-naturel.re`, `conforama.re`).

---

## A. Déduplication Stricte Meta (Browser ⇄ Server)
- **Règle absolue** : La clé d'événement doit impérativement être nommée `eventID` côté navigateur dans le 4e paramètre de `fbq` (`{ eventID: 'order_' + id }`), et `event_id` côté CAPI (`'event_id' => 'order_' . $id`).
- Meta Events Manager utilise cette clé pour fusionner les deux signaux sous 48 heures. Aucune double facturation ni doublon de chiffre d'affaires n'est possible.

## B. Bannissement Total de `get_post_meta()` / `update_post_meta()`
- Avec l'activation de WooCommerce HPOS (`custom_order_tables`), les métadonnées de commande sont stockées dans les tables `wp_wc_orders_meta`.
- L'utilisation des anciennes fonctions WordPress `get_post_meta()` provoque des requêtes fantômes dans `wp_postmeta` qui ne contiennent pas les données à jour.
- **Règle** : Toujours manipuler `$order->get_meta()`, `$order->update_meta_data()`, `$order->delete_meta_data()` et `$order->save()` (ou `$order->save_meta_data()`).

## C. Normalisation E.164 ARCEP pour l'Océan Indien (974 / 976), les DOM et la Métropole (v2.3.2)
- La Réunion et Mayotte partagent l'indicatif international `+262` (format Meta E.164 sans `+` : `262` + 9 chiffres).
- `Meta_Api::format_phone_e164( $phone, $country_code )` couvre l'ensemble du plan de numérotation ARCEP :
  - **Océan Indien (`262`)** : `0(692|693|262|263|639|269)XXXXXX` (ainsi que `2620?...`, `330?...` erronés ou format local 9 chiffres) → `'262'` + 9 chiffres, quel que soit le pays (`FR`, `RE`, `YT`).
  - **DOM (`590`, `596`, `594`)** : `0(690|691|590)` → `590...`, `0(696|697|596)` → `596...`, `0(694|594)` → `594...`.
  - **France métropolitaine (`33`)** : `0[1-79]XXXXXXXX` avec pays `FR` (ou vide) → `33...`, et mobiles métropolitains `0[67]XXXXXXXX` (hors DOM) utilisés avec pays `RE`/`YT`/DOM → `33...`. Les numéros spéciaux `08` retournent `''`.
  - **Internationaux & Rejet propre** : les numéros internationaux déjà préfixés valides (`^[1-9][0-9]{6,14}$`) sont conservés sans `+` ; toute autre chaîne invalide retourne `''` afin de ne jamais hacher un faux numéro.

## D. Internationalisation (i18n) & Compatibilité Loco Translate
- **Textes sources en anglais par défaut** : Tous les textes utilisateur du code PHP et JS sont rédigés en anglais et enveloppés dans les fonctions standards WordPress (`__( '...', 'wfbt-server-side' )`, `esc_html__()`, etc.).
- **Modèle maître officiel (`languages/wfbt-server-side.pot`)** : Généré via la commande officielle `wp i18n make-pot . languages/wfbt-server-side.pot --slug=wfbt-server-side --domain=wfbt-server-side`.
- **Traductions françaises intégrées** : Les fichiers `languages/wfbt-server-side-fr_FR.po` et le binaire compilé `languages/wfbt-server-side-fr_FR.mo` (généré via `wp i18n make-mo languages/`) assurent l'affichage direct en français sur les sites configurés en langue française.
- **Loco Translate natif** : L'extension Loco Translate reconnaît immédiatement le text domain `wfbt-server-side` et le modèle `.pot` pour synchroniser ou ajouter d'autres langues en un clic.

## E. Visibilité Publique du Dépôt & Contrôle Strict Zéro Donnée Sensible
- **Dépôt public pour les mises à jour sans friction** : Le dépôt GitHub `SOYOO974/woo-meta-tracking-server-side` est **public** (identique à `woo-google-ads-tracking-server-side`). Cette visibilité est indispensable pour permettre à `plugin-update-checker` (PUC) sur les sites WordPress d'interroger l'API GitHub (`/releases/latest`) et de télécharger les archives de mise à jour sans nécessiter de Personal Access Token (PAT) ni d'authentification.
- **Règle absolue de sécurité (Zéro Info Sensible)** :
  - **Interdiction formelle de committer des secrets** : Ne jamais inclure de tokens d'accès Meta réels (`EAAB...`), de tokens GitHub (`ghp_...`, `gho_...`), de mots de passe, de clés API privées, d'adresses d'environnements confidentiels ou de données clients dans le code ou l'historique Git.
  - **Vérification systématique avant chaque commit et release** : Vérifier impérativement via `git diff` ou recherche textuelle qu'aucune donnée sensible n'a été insérée par inadvertance (ex: lors de tests locaux de l'API Meta).

## F. Résolution Universelle des Droits Administrateur (`user_has_cap`) & Enregistrement Menu
- **Problématique 403 récurrente sur WordPress** : Sur certains sites WordPress, le compte administrateur (`manage_options`) peut perdre ou ne jamais avoir reçu les capacités spécifiques créées par WooCommerce (`manage_woocommerce`, `view_woocommerce_reports`) à la suite de migrations de base de données, plugins de rôles ou configurations personnalisées. Lorsque WordPress vérifie l'accès à `admin.php?page=wfbt-settings`, il valide que l'utilisateur possède le droit du menu parent WooCommerce (`manage_woocommerce`). Sans ce droit, WordPress renvoie immédiatement `403 Permission Denied`.
- **Interception dynamique en mémoire (`user_has_cap`)** : Le hook `user_has_cap` intercepte tous les contrôles de capacité de WordPress. Si l'utilisateur possède `manage_options`, le filtre lui injecte dynamiquement `manage_woocommerce = true` et `view_woocommerce_reports = true`. Cette approche est 100% infaillible car elle ne dépend ni du nom du rôle, ni des mutations en base de données, ni du cache d'objets.
- **Auto-réparation proactive (`wfbt_ensure_admin_capabilities`)** : Branchée sur `init` à priorité 5, cette fonction vérifie et persiste les capacités manquantes directement dans `wp_user_roles` et l'objet `$current_user`.
- **Repli Menu Résilient** : `Admin_Settings::get_capability()` évalue dynamiquement la capacité disponible (`manage_woocommerce` puis repli sur `manage_options`), et `Admin_Settings::add_settings_page()` bascule automatiquement sous `options-general.php` si le menu parent WooCommerce n'est pas présent dans l'arborescence admin.

## G. Garde Pré-Vol CAPI & Anti-Erreur 400 (Subcode 2804050)
- L'API Meta Graph v21.0 exige impérativement au moins un paramètre d'identification client (`user_data`). Contrairement à Google Ads qui dispose d'une modélisation sans cookies (Consent Mode v2), Meta rejette catégoriquement tout événement avec `user_data: {}` avec l'erreur `HTTP 400: Vous n’avez pas ajouté suffisamment de données de paramètres d’informations client pour cet évènement` (subcode 2804050).
- Le plugin implémente un garde-fou pré-vol : si `em`, `ph`, `fbp`, `fbc` et `external_id` sont absents, ou si le client a refusé le consentement marketing, la requête HTTP vers Meta est court-circuitée, évitant les erreurs 400, les logs d'erreurs et les faux e-mails d'alerte critique.

## H. Format d'Archive ZIP & Normalisation des Séparateurs (Slashs `/` vs Antislashs `\`)
- Sur Windows, la commande native PowerShell `Compress-Archive` enregistre les chemins relatifs avec des antislashs (`\`). Lorsque WordPress décompresse cette archive sur un serveur Linux de production, le système de fichiers n'interprète pas `\` comme un séparateur mais comme un caractère littéral de nom de fichier. Cela crée des fichiers uniques à plat au lieu de dossiers (ex: `woo-fb-tracking-server-side\includes\class-wfbt-core.php`), provoquant la désactivation immédiate de l'extension par WordPress suite à l'absence perçue du fichier d'en-tête racine et la perte d'autorisation 403.
- Les releases sont désormais obligatoirement assemblées via le module Python standard `zipfile`, garantissant des slashs POSIX (`/`) universels et un dossier racine `woo-fb-tracking-server-side/` strictement conforme au slug déclaré dans PUC.

## I. Alignement des Content ID avec le Catalogue Meta (v2.1.0)
- **Le catalogue est la source de vérité.** Sur les sites SOYOO, le catalogue Meta est alimenté par `woo-meta-catalog-feed-soyoo` (`/feed/meta-catalog.xml`, `<g:id>` = SKU sinon ID produit, variations exportées individuellement avec `item_group_id` = ID parent).
- **Sens unique, jamais de lecture croisée des options** : le tracking consomme l'ID du flux (filtre `soyoo_meta_catalog_content_id`), le flux ne fait qu'afficher un statut en lisant `\WFBT\Product_Id`. Une double lecture d'options crée une dépendance circulaire qui dérive silencieusement.
- **Rétrocompatibilité** : flux ≤ v1.2.0 sans filtre → le mode `auto` applique la logique historique du flux (SKU sinon ID). Aucun changement d'ID pour les sites existants.
- **Interdit** : modifier la logique d'ID dans `Public_Handler` ou `Meta_Api` en dur. Toute évolution passe par `Product_Id::get()`.
- **Piège variations** : `get_sku()` (contexte `view`) d'une variation sans SKU propre renvoie le SKU du parent → plusieurs variations partagent le même ID (doublons rejetés par Meta). Correctif prévu côté flux (v1.3.0, `get_sku( 'edit' )` + repli ID variation) ; le tracking suivra automatiquement via la délégation.

## J. Capture AddToCart : Autopsie des Pièges & Résolution Multi-Niveaux (v2.1.1)

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

### Architecture de Résilience Multi-Niveaux (v2.1.1) :
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

## K. Méthode de Diagnostic « Taux de correspondance catalogue 0 % »
1. **Distinguer les deux écrans Meta** : *Gestionnaire d'événements* (l'événement arrive-t-il ?) vs *Gestionnaire des ventes > Catalogue > Événements* (l'ID envoyé existe-t-il dans le catalogue ?). Un `ViewContent` « Actif » avec 0 % de correspondance = problème d'ID ou de délai, pas d'envoi.
2. **Comparer sur preuve, pas sur hypothèse** : relever `wfbt_page_events` dans le HTML d'une fiche produit en ligne et chercher le même ID dans `/feed/meta-catalog.xml` (`<g:id>…</g:id>`). Vérifier aussi que `wfbt_pixel_id` = ID du pixel associé au catalogue.
3. **Délai Meta** : un catalogue créé récemment reste à 0 % / « Manquant » plusieurs jours (fenêtre de calcul de 28 jours). Ne pas conclure avant 3 à 7 jours après création ou correction.
4. **Leçon comptoirdecambaie.re & kidshow.fr (oct. 2026)** : l'alignement des `content_ids` doit être vérifié dès la configuration du catalogue. Si le catalogue Meta a été importé avec les IDs produits WooCommerce (ex: `10261`), le mode `id` doit être explicitement choisi dans les réglages du plugin (`wfbt_content_id_format = 'id'`).

## L. Architecture Hybride Complète Server-Side & Déduplication 1:1 (v2.2.0)

Dans la version 2.1.1, seul l'événement `Purchase` bénéficiait de la passerelle CAPI Server-Side. `AddToCart`, `InitiateCheckout` et `ViewContent` dépendaient exclusivement du navigateur (`fbevents.js`).
Sur un site comme `kidshow.fr`, cette asymétrie produisait des anomalies critiques :
- **Perte massive d'AddToCart** (seulement 2 AddToCart reçus pour 118 InitiateCheckout et 94 Purchase sur 28 jours), causée par :
  1. Les bloqueurs de publicité (uBlock Origin, AdGuard, Brave Shields) bloquant `fbevents.js` en totalité.
  2. Les carrousels thèmes personnalisés (ex: `/home-2/`) dont les balises d'ajout utilisent `<a href="?add-to-cart=123">` sans attribut `data-product_id`.
  3. Les utilisateurs ajoutant au panier avant d'avoir validé le bandeau de consentement, dont la file en mémoire RAM était perdue lors d'une navigation.
- **Correspondance Catalogue à 0%** : le catalogue Meta 278475902616040 étant indexé sur les Product IDs WooCommerce (`10261`), les événements du plugin envoyaient des SKUs (`couverture-starwars-1-1`) en mode `auto`.

### Innovations Architecturales Introduites en v2.2.0 :
1. **Double Passerelle CAPI Asynchrone Action Scheduler (`Background_Processor::schedule_payload`)** :
   - `capture_add_to_cart()` (hook `woocommerce_add_to_cart`) génère l'événement `AddToCart` et planifie immédiatement l'envoi CAPI via Action Scheduler (`ACTION_PAYLOAD_HOOK`), tout en conservant le payload en session pour les fragments AJAX.
   - `maybe_send_initiate_checkout_capi()` planifie l'envoi CAPI de `InitiateCheckout` au chargement de `/commande/`, dédupliqué strictement par `cart_hash` pour neutraliser les actualisations ou retours de passerelle.
   - Les appels réseau vers Meta Graph API v21.0 s'exécutent en tâche de fond asynchrone non-bloquante, préservant 100% du TTFB et de la vitesse perçue de la boutique.
2. **Déduplication Parfaite 1:1 Browser ⇄ Server** :
   - Le serveur forge un `eventID` déterministe partagé (`atc_{12_chars}` / `atc_{cart_item_key}_{ts}` pour AddToCart, `ic_{hash}` pour InitiateCheckout, `order_{id}` pour Purchase).
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

## M. AddToCart CAPI gonflé par les robots & consentement strict (v2.2.1)

Constat `comptoirdecambaie.re` (10–12/10/2026, v2.2.0) : ~810 AddToCart reçus dont ~700 « serveur seul », contre ~355 ajouts réels mesurés par ThoughtMetric. Des heures avec 20 à 35 AddToCart pour 0 à 2 PageView, et `event_source_url` toujours égal à la page d'accueil.

### Causes :
1. La page d'accueil expose 24 liens `href="/?add-to-cart=ID"` (`rel="nofollow"` ne bloque ni AdsBot ni Storebot) et `robots.txt` ne les interdit pas. Un robot qui suit le lien déclenche `woocommerce_add_to_cart` côté serveur.
2. `extract_request_user_data()` génère un `_fbp` serveur pour toute requête, robots compris : le garde pré-vol (`fbp` présent) passe toujours.
3. Le consentement `unknown` (aucun cookie de bannière, cas de tous les robots) n'annulait pas l'envoi : seul `denied` bloquait.
4. Le « filtrage programmatique » de la v2.1.1 ne concernait que l'écouteur JS `added_to_cart` (tiroir), jamais le hook serveur.

### Correctif (`public/class-wfbt-public.php`) :
- `get_request_context()` / `format_request_context()` : instantané de la requête (méthode, type `page|ajax|store_api|rest`, `?add-to-cart`, consentement, `_fbp`, `wfbt_js`, `woo_gads_consent`, referer, User-Agent) journalisé à chaque capture.
- `get_add_to_cart_skip_reason()` : aucun suivi (ni navigateur ni CAPI) pour `cron`, `wp_cli`, `rest_api` (hors Store API), `admin` (hors AJAX), `cart_restore` (`?wcf_ac_token`, `woo-cart-abandonment-recovery-soyoo`) et `bot`. Filtre `wfbt_add_to_cart_skip_reason`.
- `is_bot_request()` : regex User-Agent (`bot`, `crawl`, `spider`, `AdsBot`, `Storebot`, `facebookexternalhit`, `HeadlessChrome`, `curl`, `python`…) + UA vide. Filtre `wfbt_is_bot_request`. Les navigateurs intégrés FB/Instagram ne sont pas filtrés.
- CAPI AddToCart : annulé uniquement sur consentement `denied` (`unknown` = bannière ignorée ou fermée → envoyé, décision de Julien du 12/10/2026, plus permissive que le Pixel navigateur et que la lecture stricte CNIL), signal navigateur réel obligatoire (cookie technique `wfbt_js` posé par le script inline du `<head>`, ou `_fbp`), anti-doublon serveur 10 min par produit/variation et par session (`wfbt_capi_atc_sent`), `event_source_url` = permalien produit si referer absent ou page d'accueil.
- CAPI InitiateCheckout : annulé uniquement sur `denied` + filtre robots.
- Purchase : inchangé (annulé seulement sur `denied`).
- **Risque RGPD assumé** : pour un visiteur `unknown`, le pixel ne s'est pas chargé, donc `extract_request_user_data()` dépose un `_fbp` serveur avant consentement. Ne pas « corriger » sans accord de Julien.
- Le gonflement par les robots reste neutralisé par le filtre User-Agent, le signal `wfbt_js` / `_fbp` et l'anti-doublon, indépendamment du consentement.

**Diagnostic en production** : WooCommerce > État > Journaux > source `wfbt-server-side`. Chaque capture logue `method= type= get_atc= consent= fbp= wfbt_js= woo_gads_consent= referer= ua=` et la raison d'un non-envoi (`skipped — reason: bot|cron|wp_cli|rest_api|admin|cart_restore`, `not sent — consent denied`, `no browser signal`, `duplicate`). Critère de succès : AddToCart serveur ≤ AddToCart navigateur × 1,2 et volume cohérent avec ThoughtMetric (`add_to_carts`).

**Leçons** :
- Toute génération serveur d'identifiant (ici `_fbp`) neutralise le garde pré-vol « au moins un identifiant client » : ce garde ne protège plus contre les requêtes non humaines. Le filtrage doit se faire en amont (UA, signal navigateur).
- Un hook serveur (`woocommerce_add_to_cart`) est déclenché par n'importe quel GET `?add-to-cart=ID`, y compris par des robots : tout envoi CAPI depuis un hook WooCommerce doit qualifier la requête.
- Signature d'un trafic robot dans Meta : beaucoup d'événements « serveur seul », très peu de PageView sur les mêmes heures, `event_source_url` = page d'accueil.
- Les prompts de modification peuvent se tromper de version : vérifier `git tag` et la date du dernier déploiement avant de numéroter (le prompt visait « v2.1.1 → v2.1.2 » alors que le site tournait en v2.2.0).

## N. Fiabilité du Purchase & Event Match Quality (v2.3.0 / v2.3.1)

Objectif : augmenter le ROAS en fiabilisant les conversions déclarées et leur correspondance, sans stockage supplémentaire ni réglage qui fausse la valeur.

- **Page de remerciement** (`Public_Handler::get_received_order()`) : Purchase navigateur seulement si `$order->key_is_valid( $_GET['key'] )` ET (`is_paid()` OU statut dans `wfbt_trigger_statuses`). Logs `invalid_key` / `status_<slug>`. Une commande `pending` au retour de passerelle (IPN lent) n'a pas de Pixel : le CAPI l'envoie seul au passage en statut déclencheur, même `event_id`.
- **Advanced Matching** (`Public_Handler::get_advanced_matching_data()` → `Meta_Api::get_advanced_matching_data()`) : 3e argument de `fbq('init')` avec `em`, `ph`, `fn`, `ln`, `ct`, `st`, `zp`, `country`, `external_id` déjà hachés SHA-256 (même code que le CAPI). Limité à `is_checkout()` (inclut la page de remerciement) car ces pages ne sont jamais servies par le cache Edge : aucun hash client ne peut être mis en cache pour un autre visiteur. Appel de `extract_request_user_data( false )` : aucun `_fbp` serveur déposé par ce chemin.
- **`external_id` haché** sur tous les événements CAPI et le Pixel (avant v2.3.0 : ID brut côté CAPI). Le Pixel ne re-hache pas une valeur SHA-256 de 64 caractères hexadécimaux, les deux côtés sont donc identiques.
- **Horodatage `fbc`** : avant v2.3.0, `fb.1.{time()}.{fbclid}` utilisait l'heure de la commande. Désormais la date du clic est conservée (cookie `wfbt_fbc`, `localStorage wfbt_fbclid_ts`).
- **Segmentation client** (`Meta_Api::get_customer_type()`) : `wc_get_orders` (`limit 1`, `return ids`, `exclude` commande courante, statuts issus de `wc_get_is_paid_statuses()` avec repli défensif `['processing', 'completed']` depuis v2.3.1 — couvrant `ready-for-pickup`, `in-transit`, `shipped`, etc., `customer => [ID, email]`, `date_created < date de la commande`), stocké via `save_meta_data()`. Paramètre **personnalisé** `customer_type` : `customer_segmentation` / `new_customer_to_business` n'apparaît pas dans la documentation officielle Meta des `custom_data` (vérifié le 28/10/2026), ne pas l'utiliser sans preuve.
- **Écarté volontairement (ne pas réintroduire sans accord de Julien)** :
  - Cookies 400 jours + restauration `localStorage` → cookie : sans effet sous ITP (cookies JS plafonnés à 7 jours, `localStorage` purgé après 7 jours sans visite), fenêtre d'attribution Meta par défaut de 7 jours, et 400 j dépasse les 13 mois CNIL.
  - Cross-device stitching (transients par email, `user_meta`) : Meta rapproche déjà via `em` / `ph` hachés, cas couvert étroit, écritures en base à chaque `update_order_review`, attribution erronée sur email partagé.
  - Seuil minimum, pondération ou blocage des clients existants, événement `NewCustomerPurchase` : le Pixel (souvent retenu par Meta à la déduplication) garde la valeur réelle, et sous-déclarer le CA dégrade le ROAS affiché et l'apprentissage. Le pilotage nouveaux clients se fait dans Meta : définitions « clients existants » + plafond de budget Advantage+, conversion personnalisée sur `customer_type = new`.

## O. Blindage du Préfixe de Cookie Vide & Normalisation ARCEP E.164 (v2.3.2)

Constat `jardin-naturel.re` (nov. 2026) :
1. **Piège `get_option(..., 'concord')` après sauvegarde vide via `options.php`** :
   - Sur les boutiques utilisant la bannière native `woo_gads_consent`, si le champ `wfbt_concord_cookie_name` était vidé et enregistré, WordPress stockait `""` dans `wp_options` (`get_option()` ne renvoie son 2e argument que si l'option est absente en base).
   - Tant que le visiteur n'avait pas interagi avec la bannière (`woo_gads_consent` absent), la boucle Priorité 2 exécutait `cName.indexOf("") !== -1` en JS et `stripos($cookie_name, "") !== false` en PHP 8 (qui retourne `0`, donc vrai !), matchant 100 % des cookies du domaine : tout cookie tiers valant `"true"` déclenchait le Pixel sans consentement, et tout cookie tiers valant `"false"` forçait `_wfbt_consent = 'denied'` au lieu de `'unknown'`, annulant l'envoi CAPI.
   - **Correctif** : `Public_Handler::get_concord_cookie_prefix()` et `Admin_Settings::sanitize_concord_cookie_name()` imposent un repli sur `'concord'` dès que la valeur nettoyée est vide, protègent `decodeURIComponent` contre les séquences URI malformées, coupent uniquement sur le premier `=` et excluent explicitement les cookies techniques internes (`woo_gads_consent`, `wfbt_js`, `_fbp`, `_fbc`, `wfbt_fbc`, `wfbt_fbclid`) en Priorité 2.
2. **Couverture ARCEP complète dans `Meta_Api::format_phone_e164()`** :
   - Ajout de `0263` (fixe Réunion), `0639`/`0269` (Mayotte), des DOM (`590`, `594`, `596`), gestion de `+33(0)`/`+262(0)` et des espaces insécables/bidi UTF-8, conservation des mobiles métropolitains `0[67]` sur adresse `RE`/`YT`, exclusion des `08` et retour strict `''` sur tout numéro invalide.
