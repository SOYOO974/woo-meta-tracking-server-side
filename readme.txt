=== Woo FB Tracking Server-Side ===
Contributors: SOYOO
Tags: woocommerce, meta, facebook, pixel, capi, server-side, tracking, conversions api, hpos, rgpd
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tracking hybride WooCommerce pour Meta (Pixel Navigateur fbq + Conversions API CAPI v21.0), 100% conforme RGPD Concord et compatible HPOS.

== Description ==

Ce plugin transforme le suivi e-commerce WooCommerce pour Meta en une architecture hybride native :
* **Pixel Navigateur (`fbq`)** : PageView, ViewContent, AddToCart (AJAX), InitiateCheckout et Purchase.
* **Conversions API CAPI v21.0 (Server-Side)** : Envoi asynchrone sécurisé du Purchase via WooCommerce Action Scheduler, insensible aux bloqueurs de publicité (AdBlockers) et aux restrictions de cookies (ITP iOS Safari).
* **Déduplication parfaite à 100%** : Strict partage du même identifiant `eventID: 'order_' + order_id` entre le front-end et le serveur.
* **Conformité RGPD Concord** : Respect du consentement marketing avec activation à chaud sans rechargement de page et mode anonymisé par défaut en cas de refus.
* **WooCommerce HPOS (High-Performance Order Storage)** : Déclaration de compatibilité et utilisation exclusive des méthodes CRUD de l'objet `$order`.
* **Normalisation E.164 Réunion** : Conversion automatique des numéros réunionnais (`0692`, `0693`, `0262` -> `+262`) et français (`+33`) avant hachage SHA-256.
* **Mises à jour automatiques** : Détection et installation transparente des nouvelles versions depuis les releases GitHub.

== Installation ==

1. Téléversez le dossier du plugin dans le répertoire `/wp-content/plugins/woo-meta-tracking-server-side`.
2. Activez l'extension via le menu 'Extensions' de WordPress.
3. Rendez-vous dans **WooCommerce > Meta Tracking** pour renseigner votre Pixel ID et votre Jeton d'accès CAPI.

== Changelog ==

= 2.0.4 =
* **Tableau de Bord Exécutif de Diagnostics** : Refonte totale du 2ème onglet « Diagnostics » avec grille d'état pré-vol (identifiants Meta, architecture HPOS/Action Scheduler, RGPD Concord, débogueur).
* **Barre de Débogage Flottante Front-End** : Inspecteur interactif repliable en bas à droite pour les administrateurs et gestionnaires de boutique (`wfbt_enable_debug_bar` ou `?wfbt_debug=1`). Capture et affiche en direct le flux des événements `fbq` (`PageView`, `ViewContent`, `AddToCart`, `InitiateCheckout`, `Purchase`), le consentement Concord et les cookies publicitaires. Inclut la simulation de clics Meta en 1 clic (`?fbclid=`).
* **Inspecteur de Cookies Navigateur en Direct** : Détection instantanée côté admin des cookies de session active `concord`, `_fbp` et `_fbc` avec lien de test direct.
* **Vérification de Santé Meta Graph API (v21.0)** : Interrogation directe de l'API Graph Meta pour contrôler le nom du Dataset, la disponibilité et la date du dernier événement reçu.
* **KPIs HPOS sur 30 Jours & Histogramme SVG Natif 14 Jours** : Mesure de performance sans impact front-end (taux de succès CAPI, taux de capture `_fbp`, taux d'attribution clics Meta `_fbc`, volume de commandes) et graphique dynamique pur SVG (zéro librairie JS externe).
* **Internationalisation (i18n)** : Fichiers .pot, .po et binaire .mo français 100% synchronisés.

= 2.0.3 =
* **Guide Déroulant en Accordéon & Clarté Optimale** : Refonte de l'onglet Tutoriel en accordéon avec volets fermés par défaut et boutons « Tout déplier / Tout replier ».
* **Détail Exhaustif du Tunnel Meta CAPI** : Explication pas-à-pas de l'assistant Meta avec choix exclusif de l'événement « Acheter » (Purchase) et tableau complet des cases à cocher (Event ID dédupliqué, cookies fbp/fbc, IP/UA, E.164 Réunion/France).
* **Génération Token CAPI** : Instructions détaillées pour le token Dataset Quality API et alternatives Utilisateur système.
* **Internationalisation (i18n)** : Fichiers .pot, .po et binaire .mo français 100% à jour.

= 2.0.2 =
* **Correctif Autorisations Admin (Settings)** : Résolution de l'erreur « Désolé, vous n’avez pas l’autorisation d’accéder à cette page ». Prise en charge dynamique des rôles administrateurs (`manage_options`) et gestionnaires de boutique (`manage_woocommerce`), avec repli automatique sous Réglages si `manage_woocommerce` est indisponible.
* **Priorité du Menu Admin** : Enregistrement différé à la priorité 50 sur le hook `admin_menu` pour garantir l'initialisation complète de l'arborescence WooCommerce.

= 2.0.1 =
* **Tutoriel & Guide Pas à Pas** : Refonte intégrale du 3ème onglet avec 5 étapes numérotées, liens directs cliquables vers le Gestionnaire d'événements Meta, Paramètres d'entreprise et Pixel Helper.
* **Consentement RGPD Anonymisé par Défaut** : L'option « Envoyer une requête anonymisée » est désormais sélectionnée par défaut lors de l'installation et en cas de refus des cookies marketing (transmission du montant, devise, IDs produits et eventID sans aucune PII, sans cookies publicitaires et sans IP/UA).
* **Internationalisation (i18n)** : Traduction française intégrale synchronisée (.pot, .po, .mo compilé).

= 2.0.0 =
* **Architecture Hybride Native** : Intégration du Pixel Navigateur (`fbq`) combiné à la Meta Conversions API (Graph API v21.0).
* **Déduplication 100%** : Partage de l'identifiant unique `eventID: 'order_' + orderId` entre le navigateur et le serveur.
* **Conformité RGPD Concord Cookie Banner** : Asservissement du Pixel et de CAPI au consentement marketing, avec détection dynamique en direct sans rechargement de page.
* **Normalisation Téléphonique E.164 Réunion** : Nettoyage et formatage spécifique des préfixes réunionnais (`0692`, `0693`, `0262` -> `+262`) et métropolitains (`+33`).
* **Compatibilité HPOS Intégrale** : Bannissement des fonctions `_post_meta` au profit de l'API CRUD native de `WC_Order`.
* **Diagnostics & Audit** : Tableau des 20 dernières commandes avec détection en temps réel des cookies `_fbp`, `_fbc`, badge de consentement Concord et bouton de renvoi instantané.
* **Mises à jour GitHub** : Intégration de Plugin Update Checker v5.6 pour les mises à jour automatiques via les GitHub Releases.

= 1.0.0 =
* Version initiale : Envoi asynchrone du Purchase vers Meta Conversions API via Action Scheduler.
