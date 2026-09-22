<?php
// ══════════════════════════════════════════════════════════════════════════════
//  include/menu.php
//  Sidebar de navigation + assistant « Ajouter un domaine » (modal multi-étapes)
// ══════════════════════════════════════════════════════════════════════════════

// La liste des domaines de la barre latérale (section « Zone DNS ») n'est plus
// alimentée par les Ingress Kubernetes ni par PowerDNS : elle provient
// UNIQUEMENT de la table n8n (domain_buy_name), chargée côté client via le
// proxy data/portail_api.php (action=domain.list, GET).

// ── Domaines achetés chez nous ───────────────────────────────────────────────
//  Le dépliant de l'assistant n'est PLUS alimenté par $domains (PowerDNS).
//  Il est peuplé côté client depuis la table n8n (domain_buy_name où
//  gnl_domain = true) via le proxy data/portail_api.php (action=domain.list, GET).
//  $domains n'est donc plus une source de vérité pour le menu.

// ── Déploiements disponibles (pour rattacher un domaine acheté) ───────────────
// ── Déploiements disponibles (« Mes services » + rattacher un domaine) ────────
// Normalement pré-calculé par la page (cf. dashboard.php). Si la page courante
// ne l'a pas fourni, on le construit ici pour que « Mes services » marche
// sur TOUTES les pages, pas seulement le dashboard.
// ── Droits de l'utilisateur (fonction Keycloak) ─────────────────────────────
// Chaque entrée du menu n'est affichée que si l'utilisateur détient le droit
// correspondant (include/org_permissions.php). Masquer n'est PAS protéger :
// les pages et les API refont le contrôle de leur côté.
require_once __DIR__ . '/org_permissions.php';
$menuCan = [
    'services' => orgCan('services.manage'),
    'dns'      => orgCan('dns.manage'),
    'orders'   => orgCan('orders.view'),
    'invoices' => orgCan('invoices.view'),
    'tickets'  => orgCan('tickets.manage'),
];

if (!isset($k8s_deployments_names) || !is_array($k8s_deployments_names)) {
    $k8s_deployments_names = [];

    // Namespace utilisateur : « ns-k8s », dérivé de l'UID d'organisation
    // Keycloak normalisé RFC1123 (même source que projects_menu_api.php).
    require_once __DIR__ . '/session_user.php';
    // Pas d'appel Kubernetes si ni « Mes services » ni l'assistant DNS ne s'affichent.
    $menu_k8s_namespace = ($menuCan['services'] || $menuCan['dns']) ? sessionUserNsK8s() : '';

    if ($menu_k8s_namespace !== '') {
        $k8sClientPath = dirname(__DIR__) . '/data/KubernetesClient.php';
        if (is_readable($k8sClientPath)) {
            require_once $k8sClientPath;
            try {
                $k8s   = new KubernetesClient(null, null, null, 3);
                $list  = $k8s->listDeployments($menu_k8s_namespace);
                $items = is_array($list['items'] ?? null) ? $list['items'] : [];
                foreach ($items as $item) {
                    $depName = (string)($item['metadata']['name'] ?? '');
                    if ($depName !== '') $k8s_deployments_names[] = $depName;
                }
                sort($k8s_deployments_names, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_deployments_names = array_values(array_unique($k8s_deployments_names));
            } catch (Throwable $e) {
                error_log('[menu] K8s deployments: ' . $e->getMessage());
                $k8s_deployments_names = [];
            }
        }
    }
}

$menu_deployments = array_values(array_filter(
    array_map('strval', $k8s_deployments_names),
    static fn ($n) => $n !== ''
));

// ── Jeton CSRF (réutilise celui de la session si présent) ─────────────────────
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $_SESSION['csrf'] = '';
    }
}
$menu_csrf_token = (string)$_SESSION['csrf'];

// ── Valeurs de configuration DNS GNL ─────────────────────────────────────────
//  Les serveurs DNS affichés au client (étape « registrar ») DOIVENT être ceux
//  que le proxy inscrit dans la zone qu'il crée : sinon le client pointe son
//  domaine vers des serveurs absents de la zone — panne silencieuse. La source
//  unique de vérité est donc PowerDnsClient::configuredNameservers()
//  (variable PDNS_ZONE_NAMESERVERS, repli codé en dur dans la classe).
//  Ce fichier ne contient que la classe et des helpers statiques : l'inclure
//  n'a aucun effet de bord.
$gnl_nameservers = ['ns1.gnl-solution.fr', 'ns2.gnl-solution.fr', 'ns3.gnl-solution.fr'];
$menu_pdnsClientPath = dirname(__DIR__) . '/data/PowerDnsClient.php';
if (is_readable($menu_pdnsClientPath)) {
    require_once $menu_pdnsClientPath;
    if (method_exists('PowerDnsClient', 'configuredNameservers')) {
        $menu_ns = PowerDnsClient::configuredNameservers();
        if (is_array($menu_ns) && $menu_ns !== []) {
            $gnl_nameservers = $menu_ns;
        }
    }
}
$gnl_dns_target  = '203.0.113.10'; // IP/cible de l'Ingress public — placeholder
?>
<div class="bg-background app-shell-offset-min-height flex h-full min-h-full w-full max-w-xs flex-col border shadow-sm dashboard-sidebar">
<div class="px-6 pt-6"></div>
<div class="flex-1 px-6 pb-6">
<?php if ($menuCan['services'] || $menuCan['dns']): ?>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Mes services</small>
<nav class="mb-4 space-y-0.5 border-b pb-4">
<?php if ($menuCan['services']): ?>
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-services-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center">
  <svg class="lucide lucide-layout-grid h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg">
    <rect height="7" rx="1" width="7" x="3" y="3"></rect>
    <rect height="7" rx="1" width="7" x="14" y="3"></rect>
    <rect height="7" rx="1" width="7" x="14" y="14"></rect>
    <rect height="7" rx="1" width="7" x="3" y="14"></rect>
  </svg>
</span>
<span class="font-medium">Services WEB</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-services-content">
<div id="web-services-list" class="mt-1 space-y-1">
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10" data-services-loading>Chargement…</div>
</div>
</div>
</div>
<!-- ══════════════════════════════════════════════════════════════════
     Catégories de services supplémentaires (même structure que
     « Services WEB » : le JS générique [data-slot="collapsible-trigger"]
     des pages les prend en charge automatiquement).
     Chaque conteneur (id=*-list) est prêt à être peuplé côté client.
══════════════════════════════════════════════════════════════════ -->
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-cloud-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-cloud h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path></svg></span><span class="font-medium">Services Cloud</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-cloud-content">
<div id="cloud-services-list" class="mt-1 space-y-1">
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun service</div>
</div>
</div>
</div>
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-specifiques-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-settings-2 h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M20 7h-9"></path><path d="M14 17H5"></path><circle cx="17" cy="17" r="3"></circle><circle cx="7" cy="7" r="3"></circle></svg></span><span class="font-medium">Services Spécifiques</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-specifiques-content">
<div id="specific-services-list" class="mt-1 space-y-1">
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun service</div>
</div>
</div>
</div>
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-vps-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-layers h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"></path><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"></path><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"></path></svg></span><span class="font-medium">Serveurs Virtualisés</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-vps-content">
<div id="virtual-servers-list" class="mt-1 space-y-1">
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun service</div>
</div>
</div>
</div>
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-dedicated-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-server h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><rect height="8" rx="2" ry="2" width="20" x="2" y="2"></rect><rect height="8" rx="2" ry="2" width="20" x="2" y="14"></rect><line x1="6" x2="6.01" y1="6" y2="6"></line><line x1="6" x2="6.01" y1="18" y2="18"></line></svg></span><span class="font-medium">Serveurs Dédiés</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-dedicated-content">
<div id="dedicated-servers-list" class="mt-1 space-y-1">
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun service</div>
</div>
</div>
</div>
<?php endif; ?>
<?php if ($menuCan['dns']): ?>
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-dns-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-layout-grid h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><rect height="7" rx="1" width="7" x="3" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="14"></rect><rect height="7" rx="1" width="7" x="3" y="14"></rect></svg></span><span class="font-medium">Zone DNS</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-dns-content">
<!-- Liste des domaines (domain_buy_name) — peuplée UNIQUEMENT depuis la table n8n
     via le proxy (action=domain.list, GET). Pas de repli Ingress/PowerDNS. -->
<div id="dns-domains-list" class="space-y-0.5">
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10" data-dns-loading>Chargement…</div>
</div>
<!-- ══════════════════════════════════════════════════════════════════════
     « Ajouter un Domaine » — ouvre l'assistant (modal). Toujours visible.
══════════════════════════════════════════════════════════════════════ -->
<button type="button" data-add-domain-open class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors text-left">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
<span class="text-sm truncate">Ajouter un Domaine</span>
</button>
</div>
</div>
<?php endif; ?>
</nav>
<?php endif; ?>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Administration</small>
<nav class="mb-4 space-y-0.5 border-b pb-4">
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./equipes"><span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-users h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
<span class="font-medium">Equipe</span>
</a>
<?php if ($menuCan['orders']): ?>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./commande">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
<span class="font-medium">Mes commandes</span>
</a>
<?php endif; ?>
<?php if ($menuCan['invoices']): ?>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./facture">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-receipt h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2"></path><path d="M16 8h-8"></path><path d="M16 12h-8"></path><path d="M12 16h-4"></path></svg></span>
<span class="font-medium">Mes factures</span>
</a>
<?php endif; ?>
<?php if ($menuCan['orders']): ?>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./abonnements">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-refresh-cw h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15.55-6.36L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15.55 6.36L3 16"></path></svg></span>
<span class="font-medium">Mes abonnements</span>
</a>
<?php endif; ?>
</nav>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Support</small>
<nav class="space-y-0.5">
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./documentation">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-headphones h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"></path></svg></span>
<span class="font-medium">Documentation</span>
</a>
<?php if ($menuCan['tickets']): ?>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./tickets">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-headphones h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"></path></svg></span>
<span class="font-medium">Help and Support</span>
</a>
<?php endif; ?>
</nav>
</div>
<div class="mt-auto p-6 pt-0">
<div class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px my-6" data-orientation="horizontal" data-slot="separator" role="none"></div>
<small class="text-muted-foreground block text-center text-sm">GNL Solution</small>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ASSISTANT « AJOUTER UN DOMAINE »
     Une seule fenêtre, plusieurs écrans (data-step) basculés en JS :
       1) source   → domaine externe OU domaine acheté chez GNL (+ choix DNS)
       2) registrar→ externe + DNS GNL : pointer les serveurs DNS chez le registrar
       3) zone     → externe + DNS perso : créer les enregistrements
       4) purchased→ acheté chez GNL : rattacher le domaine + déploiement
══════════════════════════════════════════════════════════════════════════ -->
<div id="addDomainModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
     role="dialog" aria-modal="true" aria-labelledby="addDomainTitle" aria-describedby="addDomainSubtitle">
  <div class="w-full max-w-lg rounded-xl border bg-card text-card-foreground shadow-lg max-h-[90vh] overflow-y-auto">
    <div class="p-6">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
          <h2 id="addDomainTitle" class="text-lg font-semibold">Ajouter un domaine</h2>
          <p id="addDomainSubtitle" class="mt-1 text-sm text-muted-foreground">Liez un domaine externe ou rattachez un domaine acheté chez GNL Solution.</p>
        </div>
        <button type="button" data-add-domain-close
          class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"
          aria-label="Fermer">Fermer</button>
      </div>

      <!-- ───────────── ÉTAPE 1 : source + DNS ───────────── -->
      <section data-step="source" class="mt-6 space-y-5">
        <div class="space-y-3">
          <p class="text-sm font-semibold">Origine du domaine</p>

          <label data-add-domain-source="external"
            class="block cursor-pointer rounded-lg border p-4 transition-colors hover:bg-secondary/50">
            <div class="flex items-start gap-3">
              <input type="radio" name="addDomainSource" value="external" class="mt-1 size-4 shrink-0">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">Domaine externe</p>
                <p class="text-sm text-muted-foreground">Vous possédez déjà un domaine (chez un autre registrar) et souhaitez le lier.</p>

                <!-- Nom du domaine à lier — requis pour la vérification -->
                <div data-add-domain-external-picker class="mt-3 hidden">
                  <label for="addDomainExternalInput" class="mb-1.5 block text-xs font-medium text-muted-foreground">Nom du domaine</label>
                  <input id="addDomainExternalInput" type="text" inputmode="url" autocomplete="off"
                    spellcheck="false" placeholder="exemple.com"
                    class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
                  <p data-add-domain-external-error class="mt-1.5 hidden text-xs text-red-600">Saisissez un nom de domaine valide (ex. exemple.com).</p>
                </div>
              </div>
            </div>
          </label>

          <label data-add-domain-source="purchased"
            class="block cursor-pointer rounded-lg border p-4 transition-colors hover:bg-secondary/50">
            <div class="flex items-start gap-3">
              <input type="radio" name="addDomainSource" value="purchased" class="mt-1 size-4 shrink-0">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">Domaine acheté chez GNL Solution</p>
                <p class="text-sm text-muted-foreground">Rattachez un domaine déjà enregistré sur votre compte.</p>

                <!-- Dépliant de sélection — peuplé depuis la table n8n (action=list). -->
                <div data-add-domain-purchased-picker class="mt-3 hidden">
                  <label for="addDomainPurchasedSelect" class="mb-1.5 block text-xs font-medium text-muted-foreground">Sélectionnez un domaine</label>
                  <select id="addDomainPurchasedSelect" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="">— Chargement… —</option>
                  </select>
                  <p data-add-domain-purchased-empty class="mt-2 hidden rounded-md border border-dashed px-3 py-3 text-xs text-muted-foreground">
                    Aucun domaine enregistré sur votre compte.
                    <a href="./commande" class="font-medium text-foreground underline underline-offset-2">En commander un</a>.
                  </p>
                </div>
              </div>
            </div>
          </label>
        </div>

        <!-- Choix des serveurs DNS — pertinent pour un domaine externe -->
        <div data-add-domain-dns-block class="space-y-3 hidden">
          <p class="text-sm font-semibold">Serveurs DNS</p>
          <p class="text-sm text-muted-foreground">Souhaitez-vous utiliser nos serveurs DNS sécurisés ?</p>

          <label data-add-domain-dns="yes"
            class="block cursor-pointer rounded-lg border p-4 transition-colors hover:bg-secondary/50">
            <div class="flex items-start gap-3">
              <input type="radio" name="addDomainDns" value="yes" class="mt-1 size-4 shrink-0">
              <div class="min-w-0">
                <p class="text-sm font-medium">Oui
                  <span class="ml-1 inline-flex items-center rounded-md border border-transparent bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">Recommandé</span>
                </p>
                <p class="text-sm text-muted-foreground">GNL héberge votre zone DNS. Vous pointez simplement les serveurs DNS chez votre registrar.</p>
              </div>
            </div>
          </label>

          <label data-add-domain-dns="no"
            class="block cursor-pointer rounded-lg border p-4 transition-colors hover:bg-secondary/50">
            <div class="flex items-start gap-3">
              <input type="radio" name="addDomainDns" value="no" class="mt-1 size-4 shrink-0">
              <div class="min-w-0">
                <p class="text-sm font-medium">Non</p>
                <p class="text-sm text-muted-foreground">Vous gardez votre zone DNS actuelle. Vous ajoutez vous-même les enregistrements.</p>
              </div>
            </div>
          </label>
        </div>

        <!-- Note pour les domaines achetés chez nous -->
        <div data-add-domain-purchased-note class="hidden rounded-lg border bg-secondary/40 px-4 py-3 text-sm text-muted-foreground">
          Les domaines achetés chez GNL Solution utilisent automatiquement nos serveurs DNS sécurisés. L'étape suivante consiste à les rattacher à un déploiement.
        </div>
      </section>

      <!-- ───────────── ÉTAPE 2 : registrar (externe + DNS GNL) ───────────── -->
      <section data-step="registrar" class="mt-6 space-y-4" hidden>
        <div class="rounded-lg border bg-secondary/40 px-4 py-3 text-sm">
          <p class="font-medium">Pointez votre domaine vers nos serveurs DNS</p>
          <p class="mt-1 text-muted-foreground">Connectez-vous à l'espace de gestion de votre registrar (OVH, Gandi, IONOS…) et remplacez les serveurs DNS (NS) de
            <span class="font-mono text-foreground" data-add-domain-target-name>votre domaine</span> par ceux ci-dessous.</p>
        </div>

        <div class="space-y-2">
          <?php foreach ($gnl_nameservers as $i => $ns): ?>
          <div class="flex items-center gap-2 rounded-md border bg-background px-3 py-2">
            <span class="text-xs text-muted-foreground w-10 shrink-0">NS<?php echo (int)$i + 1; ?></span>
            <code class="flex-1 truncate text-sm"><?php echo htmlspecialchars($ns, ENT_QUOTES, 'UTF-8'); ?></code>
            <button type="button" data-copy="<?php echo htmlspecialchars($ns, ENT_QUOTES, 'UTF-8'); ?>"
              class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary">Copier</button>
          </div>
          <?php endforeach; ?>
        </div>

        <p class="text-xs text-muted-foreground">La propagation DNS peut prendre jusqu'à 24–48 h. La vérification se lancera automatiquement une fois les serveurs détectés.</p>
        <div data-add-domain-status="registrar" class="text-xs"></div>
      </section>

      <!-- ───────────── ÉTAPE 3 : zone DNS (externe + DNS perso) ───────────── -->
      <section data-step="zone" class="mt-6 space-y-4" hidden>
        <div class="rounded-lg border bg-secondary/40 px-4 py-3 text-sm">
          <p class="font-medium">Ajoutez ces enregistrements dans votre zone DNS</p>
          <p class="mt-1 text-muted-foreground">Dans l'interface DNS de votre hébergeur, créez les enregistrements suivants pour
            <span class="font-mono text-foreground" data-add-domain-target-name>votre domaine</span>.</p>
        </div>

        <div class="overflow-hidden rounded-md border">
          <table class="w-full text-sm">
            <thead class="bg-secondary/60 text-muted-foreground">
              <tr>
                <th class="px-3 py-2 text-left font-medium">Type</th>
                <th class="px-3 py-2 text-left font-medium">Nom</th>
                <th class="px-3 py-2 text-left font-medium">Valeur</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-t">
                <td class="px-3 py-2 font-mono">A</td>
                <td class="px-3 py-2 font-mono">@</td>
                <td class="px-3 py-2 font-mono truncate"><?php echo htmlspecialchars($gnl_dns_target, ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="px-3 py-2 text-right">
                  <button type="button" data-copy="<?php echo htmlspecialchars($gnl_dns_target, ENT_QUOTES, 'UTF-8'); ?>"
                    class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary">Copier</button>
                </td>
              </tr>
              <tr class="border-t">
                <td class="px-3 py-2 font-mono">CNAME</td>
                <td class="px-3 py-2 font-mono">www</td>
                <td class="px-3 py-2 font-mono truncate" data-add-domain-cname>@</td>
                <td class="px-3 py-2 text-right">
                  <button type="button" data-copy-ref="add-domain-cname"
                    class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary">Copier</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- TODO : valeurs définitives fournies par le back (cible Ingress, TXT de validation, TTL…) -->
        <p class="text-xs text-muted-foreground">Les valeurs définitives (cible exacte, enregistrement de validation TLS) sont fournies par GNL Solution.</p>
        <div data-add-domain-status="zone" class="text-xs"></div>
      </section>

      <!-- ───────────── ÉTAPE 4 : domaine acheté + déploiement ───────────── -->
      <section data-step="purchased" class="mt-6 space-y-4" hidden>
        <div class="rounded-lg border bg-secondary/40 px-4 py-3 text-sm">
          <p class="font-medium">Rattacher le domaine à un déploiement</p>
          <p class="mt-1 text-muted-foreground">Domaine sélectionné :
            <span class="font-mono text-foreground" data-add-domain-target-name>votre domaine</span>.
            Choisissez le déploiement vers lequel le faire pointer.</p>
        </div>

        <div>
          <label for="addDomainDeploymentSelect" class="mb-1.5 block text-xs font-medium text-muted-foreground">Déploiement cible</label>
          <?php if (!empty($menu_deployments)): ?>
          <select id="addDomainDeploymentSelect" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
            <option value="">— Choisir un déploiement —</option>
            <?php foreach ($menu_deployments as $dep): ?>
              <option value="<?php echo htmlspecialchars($dep, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($dep, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <div class="rounded-md border border-dashed px-3 py-3 text-xs text-muted-foreground">
            Aucun déploiement disponible. Créez d'abord une application.
          </div>
          <?php endif; ?>
        </div>

        <!-- TODO : process de déploiement complet (création Ingress + certificat TLS + zone PowerDNS) à détailler -->
        <p class="text-xs text-muted-foreground">Nous créerons l'Ingress et le certificat TLS automatiquement après validation.</p>
        <div data-add-domain-status="purchased" class="text-xs"></div>
      </section>

      <!-- ───────────── Pied : navigation ───────────── -->
      <div class="mt-6 flex items-center justify-between gap-2 border-t pt-4">
        <button type="button" data-add-domain-back
          class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary invisible">← Retour</button>
        <div class="flex items-center gap-2">
          <button type="button" data-add-domain-cancel
            class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Annuler</button>
          <button type="button" data-add-domain-next disabled
            class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90 disabled:opacity-50">Continuer</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     Modal d'information — clic sur un domaine NON vérifié (en attente).
══════════════════════════════════════════════════════════════════════════ -->
<div id="pendingInfoModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
     role="dialog" aria-modal="true" aria-labelledby="pendingInfoTitle">
  <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
    <div class="p-6">
      <div class="flex items-start gap-3">
        <span class="shrink-0 grid place-items-center" style="color:#d67d0b;">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M15 18H9M14 6H10M20 3H19M19 3H5M19 3C19 5.51022 17.7877 7.86592 15.7451 9.32495L12 12M5 3H4M5 3C5 5.51022 6.21228 7.86592 8.25493 9.32495L12 12M20 21H19M19 21H5M19 21C19 18.4898 17.7877 16.1341 15.7451 14.675L12 12M5 21H4M5 21C5 18.4898 6.21228 16.1341 8.25493 14.675L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <h2 id="pendingInfoTitle" class="text-lg font-semibold">Vérification en attente</h2>
          <p class="mt-0.5 text-sm font-medium text-foreground truncate" data-pending-domain-name></p>
        </div>
      </div>
      <p class="mt-4 text-sm text-muted-foreground">La propagation DNS peut prendre jusqu'à 24–48 h. La vérification se lancera automatiquement une fois les serveurs détectés.</p>
      <div class="mt-6 flex justify-end">
        <button type="button" data-pending-close
          class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90">J'ai compris</button>
      </div>
    </div>
  </div>
</div>

<!-- Menu contextuel (clic droit) sur un domaine du sous-menu -->
<div id="domainContextMenu" class="hidden fixed z-[60] min-w-[11rem] overflow-hidden rounded-md border bg-card text-card-foreground shadow-lg py-1" role="menu" style="top:0;left:0;">
  <button type="button" data-domain-delete role="menuitem"
    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-red-600 hover:bg-secondary">
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6h14M10 11v6M14 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
    </svg>
    Supprimer
  </button>
</div>

<!-- Menu contextuel (clic droit) sur un service du sous-menu « Mes services ».
     Piloté par assets/js/services_menu.js (clé : order_product.uid). -->
<div id="deploymentContextMenu" class="hidden fixed z-[60] min-w-[11rem] overflow-hidden rounded-md border bg-card text-card-foreground shadow-lg py-1" role="menu" style="top:0;left:0;">
  <button type="button" data-deployment-rename role="menuitem"
    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-secondary">
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
    </svg>
    Renommer
  </button>
</div>

<!-- Renommer un déploiement (le nom personnalisé est enregistré dans n8n) -->
<div id="renameDeploymentModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
     role="dialog" aria-modal="true" aria-labelledby="renameDeploymentTitle">
  <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
    <div class="p-6">
      <h2 id="renameDeploymentTitle" class="text-lg font-semibold">Renommer le service</h2>
      <p class="mt-2 text-sm text-muted-foreground">Donnez un nom d'affichage à
        <span class="font-medium text-foreground" data-rename-deployment-name></span>. Le produit commandé reste inchangé.</p>
      <div class="mt-4">
        <label for="renameDeploymentInput" class="mb-1.5 block text-xs font-medium text-muted-foreground">Nom d'affichage</label>
        <input id="renameDeploymentInput" data-rename-input type="text" autocomplete="off" spellcheck="false"
          class="h-10 w-full rounded-md border bg-background px-3 text-sm" placeholder="Mon application" />
        <p class="mt-1.5 text-xs text-muted-foreground">Laissez vide pour réafficher le nom d'origine du produit.</p>
      </div>
      <div data-rename-status class="mt-3 text-xs"></div>
      <div class="mt-6 flex justify-end gap-2">
        <button type="button" data-rename-cancel
          class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Annuler</button>
        <button type="button" data-rename-confirm
          class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90 disabled:opacity-50">Enregistrer</button>
      </div>
    </div>
  </div>
</div>

<!-- Confirmation de suppression d'un domaine -->
<div id="deleteDomainModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
     role="dialog" aria-modal="true" aria-labelledby="deleteDomainTitle">
  <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
    <div class="p-6">
      <h2 id="deleteDomainTitle" class="text-lg font-semibold">Supprimer le domaine</h2>
      <p class="mt-2 text-sm text-muted-foreground">Voulez-vous vraiment supprimer
        <span class="font-medium text-foreground" data-delete-domain-name></span> ? Cette action est irréversible.</p>

      <!-- Ce qui part avec le domaine. Le bloc « routes » est toujours affiché
           (on débranche systématiquement les Ingress) ; le bloc « zone DNS »
           seulement si la zone est hébergée chez nous (ns_gnl). -->
      <div data-delete-scope-warning
        class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-300">
        <p class="font-medium">Ce qui sera supprimé avec
          <span class="font-mono" data-delete-domain-name></span></p>
        <ul class="mt-1 list-disc space-y-1 pl-5">
          <li>Les <strong>routes Kubernetes</strong> qui exposent ce domaine :
            les règles et entrées TLS correspondantes sont retirées des Ingress,
            et ceux qui ne servaient que lui sont supprimés. Vos déploiements ne
            répondront plus sur cette adresse.</li>
          <li data-delete-zone-warning hidden>La <strong>zone DNS</strong> hébergée
            chez GNL et <strong>tous ses enregistrements</strong> (A, CNAME, MX,
            TXT…), définitivement.</li>
        </ul>
      </div>

      <div data-delete-status class="mt-3 text-xs"></div>
      <div class="mt-6 flex justify-end gap-2">
        <button type="button" data-delete-cancel
          class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Annuler</button>
        <button type="button" data-delete-confirm
          class="inline-flex h-9 items-center justify-center rounded-md bg-red-600 px-3 text-sm font-medium text-white transition-all hover:bg-red-700 disabled:opacity-50">Supprimer</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  if (window.__addDomainWizardInit) return;           // évite la double initialisation
  window.__addDomainWizardInit = true;

  const CSRF = <?php echo json_encode($menu_csrf_token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  // Partagé avec assets/js/services_menu.js, qui POSTe « deployment.rename ».
  window.PORTAIL_CSRF = CSRF;
  window.PORTAIL_API  = '../data/portail_api.php';
  // Droits de l'utilisateur (include/org_permissions.php) : évite d'appeler des
  // API qu'il ne peut pas utiliser (elles répondraient 403 de toute façon).
  window.PORTAIL_PERMS = <?php echo json_encode($menuCan, JSON_UNESCAPED_SLASHES); ?>;
  // Chemin (relatif) du proxy PHP qui relaie vers le webhook n8n (data-domain).
  // On NE construit PAS d'URL absolue ici : un new URL() au niveau module
  // planterait tout le script si la base était inhabituelle. La résolution se
  // fait dans apiCall(), à l'intérieur d'un try/catch.
  const DOMAINS_API = '../data/portail_api.php';
  // Proxy PHP qui relaie vers le webhook n8n pour les renommages de déploiements.
  // Même contrat que domains_api.php : renvoie toujours { ok, error?, deployments?, row? }.
  const DEPLOYMENTS_API = '../data/portail_api.php';
  // Proxy PHP qui parle en direct à l'API REST PowerDNS (jamais le navigateur :
  // la clé X-API-Key reste côté serveur). Utilisé par l'assistant pour créer la
  // zone quand le client confie ses serveurs DNS à GNL (action « zone.create »).
  const PDNS_API = '../data/pdns_api.php';
  // Proxy PHP qui parle à l'API Kubernetes (namespace pris dans la session,
  // jamais dans la requête). Utilisé ici pour débrancher un domaine supprimé
  // des Ingress du namespace (action « purge_domain_ingress »).
  const K8S_API = '../data/k8s_api.php';
  // Noms techniques des déploiements (source : Kubernetes, fournis côté PHP).
  const DEPLOYMENTS = <?php echo json_encode(array_values($menu_deployments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  // Cible des liens « domaine » dans la barre latérale (section Zone DNS).
  // Page Zone DNS : /zdns?domain=<domain_buy_name>.
  const DNS_ZONE_HREF = (name) => './zdns?domain=' + encodeURIComponent(name);
  // Cible du clic gauche sur un déploiement (« Mes services »).
  // ⚠️ Ajustez cette route si la page de votre service est différente.
  const DEPLOYMENT_HREF = (name) => './deployment?deployment=' + encodeURIComponent(name);

  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    const modal = document.getElementById('addDomainModal');
    if (!modal) return;

    const openers   = document.querySelectorAll('[data-add-domain-open]');
    const closeBtn  = modal.querySelector('[data-add-domain-close]');
    const cancelBtn = modal.querySelector('[data-add-domain-cancel]');
    const backBtn   = modal.querySelector('[data-add-domain-back]');
    const nextBtn   = modal.querySelector('[data-add-domain-next]');

    const sections   = modal.querySelectorAll('section[data-step]');
    const dnsBlock   = modal.querySelector('[data-add-domain-dns-block]');
    const picker     = modal.querySelector('[data-add-domain-purchased-picker]');
    const purchasedNote = modal.querySelector('[data-add-domain-purchased-note]');
    const purchasedSel  = document.getElementById('addDomainPurchasedSelect');
    const deploymentSel = document.getElementById('addDomainDeploymentSelect');
    const targetNames   = modal.querySelectorAll('[data-add-domain-target-name]');
    const cnameCell     = modal.querySelector('[data-add-domain-cname]');
    const externalPicker = modal.querySelector('[data-add-domain-external-picker]');
    const externalInput  = document.getElementById('addDomainExternalInput');
    const externalError  = modal.querySelector('[data-add-domain-external-error]');

    const state = { source: null, dns: null, step: 'source' };

    // Validation basique d'un nom de domaine (FQDN, 1+ label + TLD ≥ 2)
    const DOMAIN_RE = /^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;
    function externalDomainName() { return externalInput ? externalInput.value.trim().replace(/\.$/, '') : ''; }
    function externalDomainValid() { return DOMAIN_RE.test(externalDomainName()); }

    // ── Helpers d'affichage ─────────────────────────────────────────────────
    function showStep(step) {
      state.step = step;
      sections.forEach(s => { s.hidden = (s.getAttribute('data-step') !== step); });
      backBtn.classList.toggle('invisible', step === 'source');
      updateNext();
    }

    function selectedDomainName() {
      if (state.source === 'purchased') return purchasedSel ? purchasedSel.value.trim() : '';
      if (state.source === 'external')  return externalDomainName();
      return '';
    }

    function refreshTargetNames() {
      const name = selectedDomainName() || 'votre domaine';
      targetNames.forEach(el => { el.textContent = name; });
      if (cnameCell) cnameCell.textContent = (selectedDomainName() || '@');
    }

    function highlightCards(attr, value) {
      modal.querySelectorAll('[' + attr + ']').forEach(card => {
        const on = card.getAttribute(attr) === value;
        card.classList.toggle('border-primary', on);
        card.classList.toggle('ring-2', on);
        card.classList.toggle('ring-primary/30', on);
        card.classList.toggle('bg-secondary/60', on);
      });
    }

    // ── Validation du bouton « Continuer / Valider » ─────────────────────────
    function updateNext() {
      let ok = false, label = 'Continuer';

      if (state.step === 'source') {
        if (state.source === 'external') {
          const hasDns = state.dns === 'yes' || state.dns === 'no';
          const valid  = externalDomainValid();
          // erreur affichée seulement si l'utilisateur a saisi quelque chose d'invalide
          if (externalError) externalError.classList.toggle('hidden', valid || externalDomainName() === '');
          ok = valid && hasDns;
        } else if (state.source === 'purchased') {
          ok = !!(purchasedSel && purchasedSel.value);
        }
      } else if (state.step === 'registrar' || state.step === 'zone') {
        ok = true; label = 'J\u2019ai terminé, vérifier';
      } else if (state.step === 'purchased') {
        ok = !!(deploymentSel && deploymentSel.value);
        label = 'Déployer';
      }

      nextBtn.disabled = !ok;
      nextBtn.textContent = label;
    }

    // ── Ouverture / fermeture ────────────────────────────────────────────────
    function reset() {
      state.source = null; state.dns = null;
      modal.querySelectorAll('input[type="radio"]').forEach(r => { r.checked = false; });
      highlightCards('data-add-domain-source', '');
      highlightCards('data-add-domain-dns', '');
      if (picker) picker.classList.add('hidden');
      if (externalPicker) externalPicker.classList.add('hidden');
      if (externalInput) externalInput.value = '';
      if (externalError) externalError.classList.add('hidden');
      if (dnsBlock) dnsBlock.classList.add('hidden');
      if (purchasedNote) purchasedNote.classList.add('hidden');
      if (purchasedSel) purchasedSel.value = '';
      if (deploymentSel) deploymentSel.value = '';
      modal.querySelectorAll('[data-add-domain-status]').forEach(el => { el.textContent = ''; el.className = 'text-xs'; });
      refreshTargetNames();
      showStep('source');
    }

    function open()  { reset(); modal.classList.remove('hidden'); modal.classList.add('flex'); refreshDomains(); }
    function close() { modal.classList.remove('flex'); modal.classList.add('hidden'); }

    openers.forEach(btn => btn.addEventListener('click', open));
    closeBtn  && closeBtn.addEventListener('click', close);
    cancelBtn && cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('flex')) close(); });

    // ── Sélection de la source ─────────────────────────────────────────────
    modal.querySelectorAll('[data-add-domain-source]').forEach(card => {
      card.addEventListener('click', () => {
        const val = card.getAttribute('data-add-domain-source');
        const radio = card.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
        state.source = val;
        highlightCards('data-add-domain-source', val);

        const isPurchased = val === 'purchased';
        const isExternal  = val === 'external';
        if (picker)         picker.classList.toggle('hidden', !isPurchased);
        if (externalPicker) externalPicker.classList.toggle('hidden', !isExternal);
        if (dnsBlock)       dnsBlock.classList.toggle('hidden', isPurchased);
        if (purchasedNote)  purchasedNote.classList.toggle('hidden', !isPurchased);
        if (isPurchased)    state.dns = null;          // DNS GNL implicite
        if (isExternal && externalInput) requestAnimationFrame(() => externalInput.focus());
        refreshTargetNames();
        updateNext();
      });
    });

    // ── Sélection du DNS ─────────────────────────────────────────────────────
    modal.querySelectorAll('[data-add-domain-dns]').forEach(card => {
      card.addEventListener('click', () => {
        const val = card.getAttribute('data-add-domain-dns');
        const radio = card.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
        state.dns = val;
        highlightCards('data-add-domain-dns', val);
        updateNext();
      });
    });

    purchasedSel  && purchasedSel.addEventListener('change', () => { refreshTargetNames(); updateNext(); });
    deploymentSel && deploymentSel.addEventListener('change', updateNext);
    externalInput && externalInput.addEventListener('input', () => { refreshTargetNames(); updateNext(); });

    // ── Boutons copier ────────────────────────────────────────────────────────
    async function copyText(txt, btn) {
      try { await navigator.clipboard.writeText(txt); }
      catch (_) {
        const ta = document.createElement('textarea'); ta.value = txt;
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        ta.remove();
      }
      if (btn) { const old = btn.textContent; btn.textContent = 'Copié'; setTimeout(() => { btn.textContent = old; }, 1200); }
    }
    modal.addEventListener('click', e => {
      const direct = e.target.closest('[data-copy]');
      if (direct) { copyText(direct.getAttribute('data-copy'), direct); return; }
      const ref = e.target.closest('[data-copy-ref]');
      if (ref) {
        const el = modal.querySelector('[data-' + ref.getAttribute('data-copy-ref') + ']');
        if (el) copyText(el.textContent.trim(), ref);
      }
    });

    // ── Navigation : Retour ────────────────────────────────────────────────────
    backBtn.addEventListener('click', () => { if (state.step !== 'source') showStep('source'); });

    // ── Navigation : Continuer / Valider ────────────────────────────────────────
    nextBtn.addEventListener('click', () => {
      if (state.step === 'source') {
        refreshTargetNames();
        if (state.source === 'purchased')      showStep('purchased');
        else if (state.dns === 'yes')          showStep('registrar');
        else if (state.dns === 'no')           showStep('zone');
        return;
      }
      // Étapes de process : déclenche la vérification / le déploiement (backend à brancher)
      submitStep(state.step);
    });

    // ── Soumission d'une étape de process (relais n8n via le proxy PHP) ──────────
    function setStatus(step, text, kind) {
      const el = modal.querySelector('[data-add-domain-status="' + step + '"]');
      if (!el) return;
      el.textContent = text;
      el.className = 'text-xs ' + (kind === 'err'  ? 'text-red-600'
                                 : kind === 'warn' ? 'text-amber-600'
                                 : kind === 'ok'   ? 'text-emerald-600'
                                 :                   'text-muted-foreground');
    }

    // Appel JSON normalisé vers le proxy → webhook n8n (data-domain).
    // Le serveur renvoie toujours { ok: true/false, error?, domains?, row? }.
    // method 'GET' pour les lectures (list), 'POST' pour les écritures.
    async function apiCall(action, payload, method, base) {
      method = (method || 'POST').toUpperCase();
      const u = new URL(base || DOMAINS_API, window.location.href);
      u.searchParams.set('action', action);
      const opts = { method, credentials: 'same-origin', headers: {} };
      if (method === 'GET') {
        Object.entries(payload || {}).forEach(([k, v]) => u.searchParams.set(k, v));
      } else {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.headers['X-CSRF-Token'] = CSRF;
        opts.body = new URLSearchParams(payload || {});
      }
      const res = await fetch(u.toString(), opts);
      const ct  = (res.headers.get('content-type') || '').toLowerCase();
      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) {}
      if (!ct.includes('application/json') || !data) {
        throw new Error('Réponse non-JSON (' + res.status + '). ' + raw.slice(0, 160).replace(/\s+/g, ' '));
      }
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    // Mappe l'état de l'assistant sur les colonnes de la table domain_portail.
    function rowPayload() {
      return {
        domain_buy_name: selectedDomainName(),                 // nom du domaine
        gnl_domain: state.source === 'purchased' ? '1' : '0',  // acheté chez GNL ?
        ns_gnl:     state.source === 'purchased' ? '1' : (state.dns === 'yes' ? '1' : '0'), // DNS GNL ?
        linked_to:  deploymentSel ? deploymentSel.value : '',  // déploiement rattaché
      };
    }

    // Domaine externe + serveurs DNS GNL : c'est NOUS qui hébergeons la zone,
    // il faut donc qu'elle existe dans PowerDNS. L'étape « registrar » est
    // atteinte exactement dans ce cas (source=external ET dns=yes).
    //
    // Appelée APRÈS domain.upsert : le contrôle d'accès de pdns_api.php relit
    // le domain.list du client, qui doit déjà contenir le domaine.
    //
    // Un échec ne bloque pas l'étape : la ligne est enregistrée et la
    // vérification lancée. On le signale simplement en ambre — la zone se
    // crée à la main côté GNL, le client n'a rien à refaire.
    async function ensureDnsZone() {
      const domain = selectedDomainName();
      if (!domain) return { text: '', failed: false };
      try {
        const z = await apiCall('zone.create', { domain }, 'POST', PDNS_API);
        return {
          text: z && z.created === false
            ? ' Zone DNS déjà en place sur nos serveurs.'
            : ' Zone DNS créée sur nos serveurs.',
          failed: false,
        };
      } catch (e) {
        const msg = (e && e.message ? e.message : String(e));
        return {
          text: ' La zone DNS n’a pas pu être créée automatiquement (' + msg
              + '). Nos équipes s’en chargent, vous n’avez rien à refaire.',
          failed: true,
        };
      }
    }

    async function submitStep(step) {
      nextBtn.disabled = true;
      setStatus(step, 'Traitement…', 'muted');
      try {
        // 1) On crée/enregistre la ligne dans la table (idempotent côté n8n via domain_buy_name).
        await apiCall('domain.upsert', rowPayload());

        // 2) DNS confiés à GNL : la zone doit exister chez nous.
        const zoneRes = (step === 'registrar')
          ? await ensureDnsZone()
          : { text: '', failed: false };

        // 3) Selon l'étape, on déclenche la vérification ou le déploiement.
        if (step === 'purchased') {
          await apiCall('domain.deploy', rowPayload());
          setStatus(step, 'Domaine rattaché. Déploiement lancé.', 'ok');
        } else {
          // registrar = vérif des serveurs DNS ; zone = vérif des enregistrements
          const data = await apiCall('domain.verify', rowPayload());
          let base;
          if (data.verified) {
            base = 'Domaine vérifié ✓';
          } else if (step === 'registrar') {
            base = 'En attente de la propagation des serveurs DNS…';
          } else {
            base = 'En attente de la détection des enregistrements…';
          }
          setStatus(step, base + zoneRes.text, zoneRes.failed ? 'warn' : 'ok');
        }
        refreshDomains(); // rafraîchit la barre latérale + le dépliant depuis la table
      } catch (e) {
        setStatus(step, 'Erreur : ' + (e && e.message ? e.message : String(e)), 'err');
      } finally {
        nextBtn.disabled = false;
        updateNext();
      }
    }

    // ── Lecture de la table (action "list") → alimente le dépliant des domaines ──
    //  Repli silencieux sur les options rendues par PHP si l'appel échoue.
    let domainsCache = [];
    const escHtml = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const isTruthy = v => v === true || v === 1 || (typeof v === 'string' && ['1','true','yes','oui','on'].includes(v.toLowerCase()));

    // Sablier : domaine en attente de vérification (verified = false).
    // Hérite de la couleur du bouton (currentColor) → #d67d0b en attente.
    const PENDING_ICON =
      '<svg class="shrink-0 h-4 w-4" viewBox="0 0 24 24" fill="none" ' +
      'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<path d="M15 18H9M14 6H10M20 3H19M19 3H5M19 3C19 5.51022 17.7877 7.86592 15.7451 9.32495L12 12M5 3H4M5 3C5 5.51022 6.21228 7.86592 8.25493 9.32495L12 12M20 21H19M19 21H5M19 21C19 18.4898 17.7877 16.1341 15.7451 14.675L12 12M5 21H4M5 21C5 18.4898 6.21228 16.1341 8.25493 14.675L12 12" ' +
      'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';

    // Style appliqué au bouton d'un domaine en attente de vérification.
    const PENDING_STYLE = 'background-color:#97410d4f;color:#d67d0b;';

    // Coche verte : domaine vérifié (verified = true).
    const VERIFIED_ICON =
      '<svg class="shrink-0 h-4 w-4 text-emerald-600" viewBox="0 0 24 24" fill="none" ' +
      'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
      '<path d="m9 11 3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';

    // Badge bleu : domaine désactivé (domain_off = true). Hérite de currentColor (#a4c7f4).
    const OFF_ICON =
      '<svg class="shrink-0 h-4 w-4" viewBox="0 0 24 24" fill="none" ' +
      'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<path d="M9 12L11 14L15 10M12 3L13.9101 4.87147L16.5 4.20577L17.2184 6.78155L19.7942 7.5L19.1285 10.0899L21 12L19.1285 13.9101L19.7942 16.5L17.2184 17.2184L16.5 19.7942L13.9101 19.1285L12 21L10.0899 19.1285L7.5 19.7942L6.78155 17.2184L4.20577 16.5L4.87147 13.9101L3 12L4.87147 10.0899L4.20577 7.5L6.78155 6.78155L7.5 4.20577L10.0899 4.87147L12 3Z" ' +
      'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';

    // Style appliqué au bouton d'un domaine désactivé (domain_off = true).
    const OFF_STYLE = 'background-color:#0087ff42;color:#a4c7f4;';

    // Liste de la barre latérale (au-dessus de « Ajouter un Domaine ») :
    // tous les domain_buy_name de la table n8n.
    function renderSidebarDomains(rows) {
      const list = document.getElementById('dns-domains-list');
      if (!list) return;
      const order = [], stateByName = new Map();
      (rows || []).forEach(d => {
        const n = String((d && d.domain_buy_name) || '').trim();
        if (!n) return;
        if (!isTruthy(d && d.domain_active)) return; // Zone DNS : domaines actifs uniquement
        const k = n.toLowerCase();
        const v = isTruthy(d && d.verified);
        const off = isTruthy(d && d.domain_off);
        if (!stateByName.has(k)) { order.push(n); stateByName.set(k, { verified: v, off }); }
        else { const s = stateByName.get(k); s.verified = s.verified || v; s.off = s.off || off; }
      });
      if (order.length === 0) {
        list.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun domaine</div>';
        return;
      }
      const baseCls = 'flex items-center gap-2 rounded-md px-2.5 py-2 pl-10 text-sm transition-colors';
      const cell = (icon) => '<span class="ml-auto shrink-0 grid place-items-center">' + icon + '</span>';
      // Ordre du sous-menu : désactivés, puis vérifiés, puis non vérifiés.
      // Tri stable → l'ordre d'origine est conservé à l'intérieur de chaque groupe.
      const rank = (n) => { const s = stateByName.get(n.toLowerCase()); return s.off ? 0 : (s.verified ? 1 : 2); };
      order.sort((a, b) => rank(a) - rank(b));
      list.innerHTML = order.map(n => {
        const st = stateByName.get(n.toLowerCase());
        const dom = ' data-domain="' + escHtml(n) + '"';
        const name = '<span class="font-medium truncate min-w-0">' + escHtml(n) + '</span>';
        const href = escHtml(DNS_ZONE_HREF(n));
        // Priorité d'affichage : désactivé > vérifié > en attente.
        if (st.off) {
          return '<a' + dom + ' href="' + href + '" title="' + escHtml(n) + ' — désactivé" ' +
            'class="' + baseCls + '" style="' + OFF_STYLE + '">' + name + cell(OFF_ICON) + '</a>';
        }
        if (st.verified) {
          return '<a' + dom + ' href="' + href + '" title="' + escHtml(n) + '" ' +
            'class="text-muted-foreground hover:text-foreground hover:bg-secondary ' + baseCls + '">' +
            name + cell(VERIFIED_ICON) + '</a>';
        }
        // Non vérifié : pas de navigation → bouton qui ouvre la modal d'info.
        return '<button type="button"' + dom + ' data-domain-pending="' + escHtml(n) + '" ' +
          'title="' + escHtml(n) + ' — en attente de vérification" ' +
          'class="w-full text-left ' + baseCls + '" style="' + PENDING_STYLE + '">' + name + cell(PENDING_ICON) + '</button>';
      }).join('');
    }

    // Dépliant de l'assistant : domaines achetés chez GNL (gnl_domain = true)
    // PAS ENCORE actifs (domain_active = false) → disponibles à rattacher.
    // Les actifs sont déjà listés dans « Zone DNS ».
    function renderPurchasedOptions(rows) {
      if (!purchasedSel) return;
      const emptyHint = modal.querySelector('[data-add-domain-purchased-empty]');
      const previous = purchasedSel.value;
      const owned = (rows || []).filter(d => isTruthy(d && d.gnl_domain) && !isTruthy(d && d.domain_active));
      if (owned.length === 0) {
        purchasedSel.innerHTML = '<option value="">— Aucun domaine —</option>';
        if (emptyHint) emptyHint.classList.remove('hidden');
        updateNext();
        return;
      }
      if (emptyHint) emptyHint.classList.add('hidden');
      purchasedSel.innerHTML = '<option value="">— Choisir un domaine —</option>' +
        owned.map(d => {
          const name = String(d.domain_buy_name || ''), id = String(d.id || '');
          return '<option value="' + escHtml(name) + '" data-domain-id="' + escHtml(id) + '">' + escHtml(name) + '</option>';
        }).join('');
      if (previous) purchasedSel.value = previous;
      updateNext();
    }

    // Une seule lecture de la table → alimente la barre latérale ET le dépliant.
    async function refreshDomains() {
      try {
        const data = await apiCall('domain.list', {}, 'GET');
        domainsCache = Array.isArray(data.domains) ? data.domains : [];
        renderSidebarDomains(domainsCache);
        renderPurchasedOptions(domainsCache);
      } catch (e) {
        // La table n'a pas pu être lue (webhook de test non à l'écoute, format, etc.).
        // On reflète la table : aucune donnée de repli (ni Ingress, ni PowerDNS).
        console.warn('[domaines] lecture n8n impossible :', e && e.message ? e.message : e);
        renderSidebarDomains([]);    // « Aucun domaine » dans la barre latérale
        renderPurchasedOptions([]);  // « Aucun domaine » dans le dépliant
      }
    }

    // ── Modal d'info « en attente de vérification » ─────────────────────────────
    const pendingModal = document.getElementById('pendingInfoModal');
    function openPendingInfo(name) {
      if (!pendingModal) return;
      const nameEl = pendingModal.querySelector('[data-pending-domain-name]');
      if (nameEl) nameEl.textContent = name || '';
      pendingModal.classList.remove('hidden'); pendingModal.classList.add('flex');
    }
    function closePendingInfo() {
      if (!pendingModal) return;
      pendingModal.classList.remove('flex'); pendingModal.classList.add('hidden');
    }
    if (pendingModal) {
      // Délégation : le contenu de #dns-domains-list est régénéré dynamiquement.
      const dnsList = document.getElementById('dns-domains-list');
      dnsList && dnsList.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-domain-pending]');
        if (!btn) return;
        e.preventDefault();
        openPendingInfo(btn.getAttribute('data-domain-pending'));
      });
      pendingModal.querySelectorAll('[data-pending-close]').forEach(b => b.addEventListener('click', closePendingInfo));
      pendingModal.addEventListener('click', (e) => { if (e.target === pendingModal) closePendingInfo(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && pendingModal.classList.contains('flex')) closePendingInfo(); });
    }

    // ── Menu contextuel (clic droit) + suppression d'un domaine ─────────────────
    const ctxMenu = document.getElementById('domainContextMenu');
    const deleteModal = document.getElementById('deleteDomainModal');

    function hideCtxMenu() { if (ctxMenu) ctxMenu.classList.add('hidden'); }
    function showCtxMenu(x, y, domain) {
      if (!ctxMenu) return;
      ctxMenu.dataset.domain = domain || '';
      ctxMenu.classList.remove('hidden');
      const r = ctxMenu.getBoundingClientRect();
      const left = Math.max(8, Math.min(x, window.innerWidth  - r.width  - 8));
      const top  = Math.max(8, Math.min(y, window.innerHeight - r.height - 8));
      ctxMenu.style.left = left + 'px';
      ctxMenu.style.top  = top  + 'px';
    }

    // La zone DNS n'est chez nous que si le domaine utilise nos serveurs DNS
    // (colonne ns_gnl de la table n8n — vraie aussi pour les domaines achetés
    // chez GNL). C'est ce drapeau qui décide de l'avertissement ET de l'appel
    // à zone.delete : un domaine dont le client a gardé sa propre zone n'a
    // rien à supprimer chez nous.
    function domainRow(domain) {
      return domainsCache.find(d =>
        String((d && d.domain_buy_name) || '').toLowerCase() === String(domain || '').toLowerCase());
    }
    function zoneHostedHere(domain) {
      const row = domainRow(domain);
      return !!(row && isTruthy(row.ns_gnl));
    }

    function openDeleteModal(domain) {
      if (!deleteModal || !domain) return;
      deleteModal.dataset.domain = domain;
      // Deux emplacements portent le nom : le paragraphe et l'encadré d'alerte.
      deleteModal.querySelectorAll('[data-delete-domain-name]').forEach(el => { el.textContent = domain; });
      // La puce « zone DNS » ne concerne que les domaines hébergés chez nous ;
      // la puce « routes Kubernetes », elle, vaut pour tous.
      const warn = deleteModal.querySelector('[data-delete-zone-warning]');
      if (warn) warn.hidden = !zoneHostedHere(domain);
      const st = deleteModal.querySelector('[data-delete-status]');
      if (st) { st.textContent = ''; st.className = 'mt-3 text-xs'; }
      deleteModal.classList.remove('hidden'); deleteModal.classList.add('flex');
    }
    function closeDeleteModal() {
      if (!deleteModal) return;
      deleteModal.classList.remove('flex'); deleteModal.classList.add('hidden');
      deleteModal.dataset.domain = '';
    }

    const dnsListCtx = document.getElementById('dns-domains-list');
    if (dnsListCtx && ctxMenu) {
      // Clic droit sur un domaine → menu custom. Sur du vide → menu navigateur normal.
      dnsListCtx.addEventListener('contextmenu', (e) => {
        const el = e.target.closest('[data-domain]');
        if (!el) return;                 // clic droit sur du vide → menu navigateur normal
        e.preventDefault();
        e.stopPropagation();             // empêche le handler global de refermer aussitôt
        showCtxMenu(e.clientX, e.clientY, el.getAttribute('data-domain'));
      });
      // Fermeture : tout clic gauche, tout clic droit ailleurs, scroll, resize, Échap.
      document.addEventListener('click', hideCtxMenu);
      document.addEventListener('contextmenu', hideCtxMenu);
      window.addEventListener('scroll', hideCtxMenu, true);
      window.addEventListener('resize', hideCtxMenu);
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideCtxMenu(); });

      const delItem = ctxMenu.querySelector('[data-domain-delete]');
      delItem && delItem.addEventListener('click', () => {
        const d = ctxMenu.dataset.domain || '';
        hideCtxMenu();
        openDeleteModal(d);
      });
    }

    if (deleteModal) {
      deleteModal.querySelectorAll('[data-delete-cancel]').forEach(b => b.addEventListener('click', closeDeleteModal));
      deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && deleteModal.classList.contains('flex')) closeDeleteModal(); });

      const confirmBtn = deleteModal.querySelector('[data-delete-confirm]');
      confirmBtn && confirmBtn.addEventListener('click', async () => {
        const domain = deleteModal.dataset.domain || '';
        if (!domain) return;
        const st = deleteModal.querySelector('[data-delete-status]');
        const row = domainRow(domain);
        confirmBtn.disabled = true;
        const setSt = (txt, cls) => { if (st) { st.textContent = txt; st.className = 'mt-3 text-xs ' + cls; } };
        setSt('Suppression…', 'text-muted-foreground');
        try {
          // 1) Débrancher le domaine des déploiements : règles et entrées TLS
          //    retirées des Ingress du namespace. Toujours tenté — un domaine
          //    dont le DNS est ailleurs peut très bien être routé chez nous.
          setSt('Retrait des routes Kubernetes…', 'text-muted-foreground');
          try {
            const k = await apiCall('purge_domain_ingress', { domain }, 'POST', K8S_API);
            const n = (k && k.rules_removed) || 0;
            if (n > 0) setSt('Routes Kubernetes retirées (' + n + ').', 'text-muted-foreground');
          } catch (ingErr) {
            // Bloquant : laisser une règle Ingress derrière soi, c'est un
            // domaine encore branché sur un déploiement sans plus rien pour
            // le suivre. Le message du proxy nomme les Ingress à traiter.
            setSt('Les routes Kubernetes n’ont pas pu être retirées : '
                  + (ingErr && ingErr.message ? ingErr.message : String(ingErr))
                  + ' Le domaine n’a pas été supprimé.', 'text-red-600');
            return;
          }

          // 2) La zone PowerDNS, tant que le domaine figure encore dans le
          //    domain.list du client : c'est ce qui autorise l'appel. Une fois
          //    la ligne n8n supprimée, zone.delete répondrait 403 et la zone
          //    resterait orpheline, plus supprimable depuis le portail.
          if (zoneHostedHere(domain)) {
            setSt('Suppression de la zone DNS…', 'text-muted-foreground');
            try {
              await apiCall('zone.delete', { domain }, 'POST', PDNS_API);
            } catch (zoneErr) {
              // Échec bloquant, volontairement : on ne supprime PAS la ligne,
              // sinon la zone devient inatteignable. Le client peut réessayer.
              setSt('La zone DNS n’a pas pu être supprimée : '
                    + (zoneErr && zoneErr.message ? zoneErr.message : String(zoneErr))
                    + ' Le domaine n’a pas été supprimé — réessayez.', 'text-red-600');
              return;
            }
          }

          // 3) Puis la ligne dans la table n8n.
          setSt('Suppression du domaine…', 'text-muted-foreground');
          await apiCall('domain.delete', { domain_buy_name: domain, id: (row && row.id != null) ? String(row.id) : '' }, 'POST');
          closeDeleteModal();
          refreshDomains(); // recharge la liste depuis la table
        } catch (err) {
          setSt('Erreur : ' + (err && err.message ? err.message : String(err)), 'text-red-600');
        } finally {
          confirmBtn.disabled = false;
        }
      });
    }

    // ════════════════════════════════════════════════════════════════════════
    //  DÉPLOIEMENTS (« Mes services ») — rendu + clic droit → Renommer
    //  Le nom d'affichage est stocké dans n8n via le proxy DEPLOYMENTS_API,
    //  exactement comme les domaines passent par DOMAINS_API.
    // ════════════════════════════════════════════════════════════════════════

    // Petite icône « boîte » (identique à celle des autres entrées de la barre).
    const DEP_ICON =
      '<svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" ' +
      'stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="24" ' +
      'xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path>' +
      '<path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg>';

    // Icône « navigateur » pour les déploiements dont le nom technique finit par « -web ».
    const DEP_ICON_WEB =
      '<svg class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" ' +
      'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' +
      'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<path d="M6 7.9502H6.01M9 7.9502H9.01M12 7.9502H12.01M6.2 19H17.8C18.9201 19 19.4802 19 19.908 18.782C20.2843 18.5903 20.5903 18.2843 20.782 17.908C21 17.4802 21 16.9201 21 15.8V8.2C21 7.0799 21 6.51984 20.782 6.09202C20.5903 5.71569 20.2843 5.40973 19.908 5.21799C19.4802 5 18.9201 5 17.8 5H6.2C5.0799 5 4.51984 5 4.09202 5.21799C3.71569 5.40973 3.40973 5.71569 3.21799 6.09202C3 6.51984 3 7.07989 3 8.2V15.8C3 16.9201 3 17.4802 3.21799 17.908C3.40973 18.2843 3.71569 18.5903 4.09202 18.782C4.51984 19 5.07989 19 6.2 19Z"></path>' +
      '</svg>';

    // Table des renommages chargée depuis n8n : nom technique → nom d'affichage.
    let deploymentRenames = {};

    // Rendu de la liste « Mes services » : noms techniques (DEPLOYMENTS) + renommages n8n.
    function renderSidebarDeployments(names, renames) {
      const list = document.getElementById('k8s-deployments');
      if (!list) return;
      const arr = (names || []).map(String).filter(n => n.trim() !== '');
      if (arr.length === 0) {
        list.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun déploiement</div>';
        return;
      }
      const baseCls = 'flex w-full items-center gap-2 rounded-md px-2.5 py-2 pl-10 text-sm text-left transition-colors text-muted-foreground hover:text-foreground hover:bg-secondary';
      list.innerHTML = arr.map(raw => {
        const disp = (renames && renames[raw]) ? renames[raw] : raw;
        const renamed = disp !== raw;
        const title = renamed ? (disp + ' (' + raw + ')') : raw;
        const icon = /-web$/i.test(raw) ? DEP_ICON_WEB : DEP_ICON;
        return '<a data-deployment="' + escHtml(raw) + '" href="' + escHtml(DEPLOYMENT_HREF(raw)) + '" title="' + escHtml(title) + '" class="' + baseCls + '">' +
          '<span class="mr-0.5 grid shrink-0 place-items-center">' + icon + '</span>' +
          '<span class="font-medium truncate min-w-0" data-deployment-label>' + escHtml(disp) + '</span>' +
          '</a>';
      }).join('');
    }

    // Une lecture du proxy → carte des renommages, puis rendu.
    async function refreshDeployments() {
      // « Services WEB » est désormais alimenté par assets/js/services_menu.js
      // (produits achetés). Le conteneur #k8s-deployments n'existe plus : inutile
      // d'appeler deployment.list. Pour revenir aux déploiements Kubernetes,
      // rendre son id à la liste et supprimer ce garde-fou.
      if (!document.getElementById('k8s-deployments')) return;

      const renames = {};
      try {
        const data = await apiCall('deployment.list', {}, 'GET', DEPLOYMENTS_API);
        (Array.isArray(data.deployments) ? data.deployments : []).forEach(d => {
          const raw  = String((d && d.deployment_name) || '').trim();
          const disp = String((d && d.display_name) || '').trim();
          if (raw && disp) renames[raw] = disp;
        });
      } catch (e) {
        // Lecture impossible (webhook absent, format…) : on affiche les noms techniques.
        console.warn('[déploiements] lecture n8n impossible :', e && e.message ? e.message : e);
      }
      deploymentRenames = renames;
      renderSidebarDeployments(DEPLOYMENTS, renames);
    }

    // ── Menu contextuel + modal de renommage ───────────────────────────────────
    const depCtxMenu  = document.getElementById('deploymentContextMenu');
    const renameModal = document.getElementById('renameDeploymentModal');

    function hideDepCtxMenu() { if (depCtxMenu) depCtxMenu.classList.add('hidden'); }
    function showDepCtxMenu(x, y, dep) {
      if (!depCtxMenu) return;
      depCtxMenu.dataset.deployment = dep || '';
      depCtxMenu.classList.remove('hidden');
      const r = depCtxMenu.getBoundingClientRect();
      const left = Math.max(8, Math.min(x, window.innerWidth  - r.width  - 8));
      const top  = Math.max(8, Math.min(y, window.innerHeight - r.height - 8));
      depCtxMenu.style.left = left + 'px';
      depCtxMenu.style.top  = top  + 'px';
    }

    function openRenameModal(dep) {
      if (!renameModal || !dep) return;
      renameModal.dataset.deployment = dep;
      const nameEl = renameModal.querySelector('[data-rename-deployment-name]');
      if (nameEl) nameEl.textContent = dep;
      const input = renameModal.querySelector('[data-rename-input]');
      if (input) input.value = deploymentRenames[dep] || '';
      const st = renameModal.querySelector('[data-rename-status]');
      if (st) { st.textContent = ''; st.className = 'mt-3 text-xs'; }
      renameModal.classList.remove('hidden'); renameModal.classList.add('flex');
      if (input) requestAnimationFrame(() => { input.focus(); input.select(); });
    }
    function closeRenameModal() {
      if (!renameModal) return;
      renameModal.classList.remove('flex'); renameModal.classList.add('hidden');
      renameModal.dataset.deployment = '';
    }

    const depListCtx = document.getElementById('k8s-deployments');
    if (depListCtx && depCtxMenu) {
      // Clic droit sur un déploiement → menu custom. Sur du vide → menu navigateur.
      depListCtx.addEventListener('contextmenu', (e) => {
        const el = e.target.closest('[data-deployment]');
        if (!el) return;
        e.preventDefault();
        e.stopPropagation();
        showDepCtxMenu(e.clientX, e.clientY, el.getAttribute('data-deployment'));
      });
      // Fermeture sur clic, clic droit ailleurs, scroll, resize, Échap.
      document.addEventListener('click', hideDepCtxMenu);
      document.addEventListener('contextmenu', hideDepCtxMenu);
      window.addEventListener('scroll', hideDepCtxMenu, true);
      window.addEventListener('resize', hideDepCtxMenu);
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideDepCtxMenu(); });

      const renameItem = depCtxMenu.querySelector('[data-deployment-rename]');
      renameItem && renameItem.addEventListener('click', () => {
        const d = depCtxMenu.dataset.deployment || '';
        hideDepCtxMenu();
        openRenameModal(d);
      });
    }

    // Câblage historique (déploiements K8s) : actif uniquement si la liste
    // #k8s-deployments existe. Sinon c'est assets/js/services_menu.js qui pilote
    // ce même modal, avec order_product.uid comme clé.
    if (renameModal && depListCtx) {
      renameModal.querySelectorAll('[data-rename-cancel]').forEach(b => b.addEventListener('click', closeRenameModal));
      renameModal.addEventListener('click', (e) => { if (e.target === renameModal) closeRenameModal(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && renameModal.classList.contains('flex')) closeRenameModal(); });

      const input      = renameModal.querySelector('[data-rename-input]');
      const confirmBtn = renameModal.querySelector('[data-rename-confirm]');

      input && input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); confirmBtn && confirmBtn.click(); }
      });

      confirmBtn && confirmBtn.addEventListener('click', async () => {
        const dep = renameModal.dataset.deployment || '';
        if (!dep) return;
        const displayName = input ? input.value.trim() : '';
        const st = renameModal.querySelector('[data-rename-status]');
        confirmBtn.disabled = true;
        if (st) { st.textContent = 'Enregistrement…'; st.className = 'mt-3 text-xs text-muted-foreground'; }
        try {
          // display_name vide ⇒ le backend réinitialise (réaffiche le nom technique).
          await apiCall('deployment.rename', { deployment_name: dep, display_name: displayName }, 'POST', DEPLOYMENTS_API);
          closeRenameModal();
          refreshDeployments(); // recharge la liste depuis n8n
        } catch (err) {
          if (st) { st.textContent = 'Erreur : ' + (err && err.message ? err.message : String(err)); st.className = 'mt-3 text-xs text-red-600'; }
        } finally {
          confirmBtn.disabled = false;
        }
      });
    }

    // état initial
    reset();
    if (window.PORTAIL_PERMS && window.PORTAIL_PERMS.dns) refreshDomains(); // peuple la barre latérale au chargement de la page
    refreshDeployments();  // peuple « Mes services » + applique les renommages n8n
  });
})();
</script>