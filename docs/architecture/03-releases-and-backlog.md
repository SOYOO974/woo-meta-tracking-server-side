# Suivi, Backlog & Procédure de Release — Woo FB Tracking Server-Side

> Référence détaillée rattachée à [`AGENTS.md`](../../AGENTS.md). Conserve l'historique opérationnel des releases, le backlog de suivi multi-sites SOYOO et les garde-fous techniques de publication GitHub / PUC v5.6.

---

## 1. Suivi & Backlog (au 25/11/2026)

| Sujet | État | Action |
| :--- | :--- | :--- |
| Modularisation de `AGENTS.md` (`<= 14 Ko` en pyramide inversée) et déport sans perte dans `docs/architecture/*.md` (`<= 40 Ko/fichier`) | Publiée le 25/11/2026 | Maintenir `AGENTS.md <= 14 000` octets et consigner tout nouveau retour d'expérience détaillé dans `docs/architecture/` |
| Release v2.3.2 (Protection stricte contre le préfixe `wfbt_concord_cookie_name` vide en JS/PHP 8 + exclusion cookies internes + normalisation téléphonique E.164 ARCEP Océan Indien / DOM / Métropole et rejet `''` des numéros invalides/`08`) | Publiée le 24/11/2026 | Mise à jour en 1 clic sur `jardin-naturel.re` + déploiement progressif via PUC sur l'ensemble du parc SOYOO |
| Release v2.3.1 (`Meta_Api::get_customer_type()` aligné sur `wc_get_is_paid_statuses()` pour les statuts logistiques post-paiement type `conforama.re` : `ready-for-pickup`, `in-transit`, `shipped`) | Publiée le 17/11/2026 | Mettre à jour via PUC sur `conforama.re` et les sites à statuts logistiques personnalisés + purger les caches |
| Release v2.3.0 (Purchase sécurisé clé + statut payé, Advanced Matching Pixel, `external_id` haché, horodatage `fbc`, `customer_type`, traductions `fr_FR` complètes) | Publiée le 28/10/2026 | Sur chaque site : mise à jour PUC + purge cache, « Tester les événements » (Advanced Matching sur Purchase, `customer_type`, déduplication). Suivre l'EMQ Purchase et le ratio Purchase Meta / commandes payées sur 7 à 14 jours |
| Meta : conversion personnalisée « Purchase où `customer_type = new` » + définitions clients existants Advantage+ | À faire dans le Gestionnaire d'événements / Ads Manager | Aucun code requis |
| Release v2.2.1 (AddToCart CAPI gonflé par les robots, consentement `denied` seul bloquant, signal `wfbt_js`, anti-doublon 10 min) | Publiée le 12/10/2026 (commit `79c82da`, tag `v2.2.1`) | `comptoirdecambaie.re` : mise à jour PUC + purge cache Rocket.net, test « Tester les événements », contrôle sur 48 h : ATC serveur ≤ ATC navigateur × 1,2 |
| RGPD : `_fbp` serveur déposé avant consentement pour les visiteurs `unknown` | Risque assumé par Julien | À réévaluer en cas de contrôle CNIL ou de plainte |
| `comptoirdecambaie.re` : `Disallow: /*add-to-cart=*` dans `robots.txt` | Recommandé | Réduit les sessions et paniers fantômes créés par les robots (indépendant du tracking) |
| Release v2.2.0 (Architecture Hybride Complète CAPI AddToCart + InitiateCheckout, déduplication 1:1, anti-adblocker `_fbp` auto, pré-consentement `sessionStorage`, regex `href` carrousels, `Product_Id::get_view_content_type`) | Publiée le 09/10/2026 | Supplantée par v2.2.1 (AddToCart gonflé par les robots) |
| KidShow (`kidshow.fr`) : Configuration du Format Content ID sur "WooCommerce Product ID" | À faire en admin | Sélectionner "WooCommerce Product ID (e.g. 1234)" dans réglages |
| Release v2.1.1 (Résilience AddToCart multi-chemins, endpoint `?wc-ajax=wfbt_pending_atc`) | Déployée | Supplantée par v2.2.0 |
| `woo-meta-catalog-feed-soyoo` v1.3.0 (filtre `soyoo_meta_catalog_content_id`, `Feed_Item::get_content_id()`, encadré statut tracking, fix SKU variations) | À faire dans la session dédiée du flux | Prompt fourni à Julien |
| `comptoirdecambaie.re` : contrôle du ratio AddToCart / InitiateCheckout | À contrôler après v2.2.1 | Le ratio doit repasser > 1 sans gonflement (comparer avec ThoughtMetric) |
| Page de remerciement : `Purchase` rendu pour tout `order-received` sans vérifier `?key=` | Corrigé en v2.3.0 | — |
| Traductions `fr_FR` des chaînes v2.2.0 à v2.3.0 | Complétées en v2.3.0 | WP-CLI absent du Bash Git : lancer `wp i18n make-pot` depuis PowerShell, fusion `.po` + `.mo` via `polib` |
| Libellé admin « Annuler l'événement (CNIL stricte) » alors que `unknown` est envoyé | Wording à revoir | Ne bloque que `denied` |

---

## 2. Procédure de Release & Déploiement (GitHub Releases + PUC v5.6)

À chaque fois que vous apportez des modifications fonctionnelles au code du projet et souhaitez publier une nouvelle version stable :
- **N'effectuez PAS de déploiement FTP sauvage ni de release manuelle incomplète.**
- Suivez les étapes automatisées du workflow : [`.agent/workflows/agent-releaser.md`](../../.agent/workflows/agent-releaser.md).
- Ce workflow s'assure d'incrémenter les versions dans `woo-fb-tracking-server-side.php` (`Version:` + `WFBT_VERSION`) et `readme.txt` (`Stable tag:` + `== Changelog ==`), de vérifier `php -l` et l'absence totale de secrets, de générer l'archive `.zip` propre en POSIX (`/`), de committer, de tagguer et de publier la release officielle via GitHub CLI (`gh`).
- **Notes de release multilignes** : passer par `gh release create … --notes-file <fichier.md>` (rédigé dans le dossier scratch de l'agent) plutôt que `--notes` : les guillemets et backticks du changelog cassent l'échappement PowerShell. Rédiger ces notes directement en Markdown `-` (une conversion naïve `* ` → `- ` casse le gras `**…**`).
- **Archive ZIP POSIX sous Windows / Git Bash** : interdiction formelle de PowerShell `Compress-Archive` (encode des antislashs `\` qui aplatissent les fichiers sous Linux et désactivent l'extension). L'ancienne commande `python -c` cassait aussi sur l'antislash ; le workflow utilise désormais `as_posix()`. En cas d'échec d'échappement shell, écrire le script Python dans le dossier scratch avec l'outil d'écriture de fichier (les heredocs cassent aussi), utiliser `f.relative_to(p).as_posix()`, puis vérifier qu'aucune entrée ne contient d'antislash `\` et que `woo-fb-tracking-server-side/woo-fb-tracking-server-side.php` est présent.
- **Fichiers à committer** : l'étape 7 du workflow ne liste que les fichiers de version par défaut ; toujours ajouter explicitement tous les fichiers modifiés (`public/`, `includes/`, `languages/`, `AGENTS.md`, `docs/architecture/`) et contrôler avec `git status --short`.
- **Après publication** : les sites clients récupèrent la mise à jour via PUC (`SOYOO974/woo-meta-tracking-server-side`, cycle ~12 h ou « Vérifier les mises à jour »), suivi d'une purge des caches (`POST /wp-json/agent-bridge/v1/performance/cache/purge` via WP Agent Bridge si installé).
