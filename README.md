# Portail-Client-v2

Espace Client GNL Solution — version au design de la maquette « Espace Client » (charte GNL : marine #141a33, vert #5cd98b, Manrope).

## Installation

Inchangée : déposer le dossier à la racine du vhost (Apache + mod_rewrite, PHP 8.1+), copier `config.example.php` en `config.php` et le renseigner (Keycloak, n8n, Kubernetes, Pterodactyl, PowerDNS, Mollie). Aucune nouvelle variable de configuration.

## Ce qui change

Toute la logique métier (Keycloak, API n8n, Kubernetes, Pterodactyl, DNS, droits d'organisation, CSRF) est conservée à l'identique ; seules la présentation et la navigation ont été refaites.

- **Charte** : `assets/styles/gnl-portail.css` (chargée par toutes les pages), police et logos locaux dans `assets/gnl/` — plus aucune police ni image externe (Google Fonts, Unsplash, Creative Tim retirés).
- **En-tête et barre latérale** (`include/header.php`, `include/menu.php`) : sélecteur d'organisation, menu Piloter / Gérer / Aide, langue, notifications, état des services.
- **En-têtes de page et onglets** : `include/page_head.php` (`gnl_page_head()`, `gnl_section_tabs()`).
- **Nouvelles pages** :
  - `/services` — liste de tous les services ;
  - `/domaines` — liste des domaines ;
  - `/cgu` — conditions générales d'utilisation, publique.
- **Pages refaites** : tableau de bord, facturation (factures, abonnements, commandes), support, équipe et compte, documentation, DNS, journaux, service (onglets d'ancrage Aperçu / Domaines / Configuration / Variables / Fichiers / Journaux ; Console / Fichiers pour les serveurs Pterodactyl), service suspendu.
- **Connexion** : nouveau gabarit `gnl_auth_head()` / `gnl_auth_foot()` dans `include/keycloak_rest.php` (connexion, choix d'organisation).
- **Pages d'état** : `include/status_page.php`, utilisé par `pages/404.php` et `pages/maintenance.php` (renvoie un 503 avec `Retry-After`).
- **Liens** : le lien de déploiement de `k8s_menu.js` est désormais relatif (fonctionne sur toutes les régions).

## À compléter

- `pages/cgu.php` : `[DATE DE MISE EN LIGNE]` et `[RCS / RNE : À VÉRIFIER]` (comme sur gnl-solution.com/cgu).
