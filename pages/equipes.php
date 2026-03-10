<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

$name = $_SESSION['user']['nom'];
$siret = $_SESSION['user']['siret'];
$perm_id = $_SESSION['user']['perm_id'];

// Inclusion du fichier de configuration qui crée $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once '../config_loader.php';

// Récupérer les domaines PowerDNS pour l'utilisateur
$user_account = $_SESSION['user']['id'];
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);


// --- Kubernetes: stats rapides (déploiements + domaines depuis les Ingress)
$k8s_deployments_count = 0;
$k8s_ingress_domains_count = 0;
$k8s_ingress_base_domains = [];
$k8s_deployments_names = [];

$k8s_namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? null;

if (is_string($k8s_namespace) && $k8s_namespace !== '') {
    // Base domain (hors sous-domaine) à partir d'un host d'Ingress.
    $k8sBaseDomain = function (string $host): string {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }

        // Retire un éventuel port (rare mais possible)
        $host = preg_replace('/:\\d+$/', '', $host);

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $parts = explode('.', $host);
        $n = count($parts);

        if ($n <= 2) {
            return $host;
        }

        $last2 = $parts[$n - 2] . '.' . $parts[$n - 1];

        // Heuristique pour suffixes à 2 niveaux courants (sans embarquer une Public Suffix List)
        $twoLevelSuffixes = [
            'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'net.uk',
            'com.au', 'net.au', 'org.au',
            'co.nz', 'org.nz',
            'com.br', 'com.mx', 'co.jp',
        ];

        if (in_array($last2, $twoLevelSuffixes, true) && $n >= 3) {
            return $parts[$n - 3] . '.' . $last2;
        }

        return $last2;
    };

    $k8sClientPath = dirname(__DIR__) . '/k8s/KubernetesClient.php';
    if (!is_readable($k8sClientPath)) {
        $k8sClientPath = dirname(__DIR__) . '/KubernetesClient.php';
    }

    if (is_readable($k8sClientPath)) {
        require_once $k8sClientPath;

        try {
            $k8s = new KubernetesClient(null, null, null, 3); // timeout court

            // 1) Déploiements
            $list = $k8s->listDeployments($k8s_namespace);
            $items = $list['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    $deploymentName = (string)($item['metadata']['name'] ?? '');
                    if ($deploymentName !== '') {
                        $k8s_deployments_names[] = $deploymentName;
                    }
                }

                sort($k8s_deployments_names, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_deployments_names = array_values(array_unique($k8s_deployments_names));
                $k8s_deployments_count = count($k8s_deployments_names);
            }

            // 2) Ingress -> domaines (hors sous-domaines)
            $ns = rawurlencode($k8s_namespace);
            $ingresses = null;

            try {
                $ingresses = $k8s->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses?limit=200");
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'HTTP 404')) {
                    $ingresses = $k8s->get("/apis/extensions/v1beta1/namespaces/{$ns}/ingresses?limit=200");
                } else {
                    throw $e;
                }
            }

            $hosts = [];
            $ingItems = $ingresses['items'] ?? [];
            if (is_array($ingItems)) {
                foreach ($ingItems as $ing) {
                    $spec = $ing['spec'] ?? [];

                    $rules = $spec['rules'] ?? [];
                    if (is_array($rules)) {
                        foreach ($rules as $r) {
                            $h = (string)($r['host'] ?? '');
                            if ($h !== '') {
                                $hosts[] = $h;
                            }
                        }
                    }

                    $tls = $spec['tls'] ?? [];
                    if (is_array($tls)) {
                        foreach ($tls as $t) {
                            $ths = $t['hosts'] ?? [];
                            if (is_array($ths)) {
                                foreach ($ths as $h) {
                                    $h = (string)$h;
                                    if ($h !== '') {
                                        $hosts[] = $h;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $baseDomains = [];
            foreach ($hosts as $h) {
                $bd = $k8sBaseDomain($h);
                if ($bd !== '') {
                    $baseDomains[$bd] = true;
                }
            }

            $k8s_ingress_base_domains = array_keys($baseDomains);
            sort($k8s_ingress_base_domains, SORT_NATURAL | SORT_FLAG_CASE);
            $k8s_ingress_domains_count = count($k8s_ingress_base_domains);

        } catch (Throwable $e) {
            // On garde 0 si Kubernetes / RBAC / API est indisponible.
        }
    }
}


?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
<meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  
  <style>
    .dashboard-layout{
      display:flex;
      flex-direction:row;
      align-items:stretch;
      width:100%;
      min-height:100vh;
    }
    .dashboard-sidebar{
      flex:0 0 20rem;
      width:20rem;
      max-width:20rem;
    }
    .dashboard-main{
      flex:1 1 auto;
      min-width:0;
    }
    @media (max-width: 1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{
        width:100%;
        max-width:none;
        flex:0 0 auto;
        height:auto !important;
      }
      .dashboard-main{padding:1rem;}
    }

  </style>

<style>
  /* Sidebar collapsible (vanilla JS) */
  .collapsible-content {
    overflow: hidden;
    height: 0;
    opacity: 0;
    transition: height 220ms ease, opacity 220ms ease;
    will-change: height, opacity;
  }
  .collapsible-content.is-open {
    opacity: 1;
  }
  .collapsible-trigger .collapsible-chevron {
    transition: transform 220ms ease;
    will-change: transform;
  }
  .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {
    transform: rotate(90deg);
  }
  @media (prefers-reduced-motion: reduce) {
    .collapsible-content,
    .collapsible-trigger .collapsible-chevron {
      transition: none !important;
    }
  }
</style>
</head>
<body class="bg-background text-foreground">
  <?php include("../include/header.php"); ?>
  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>
            <main class="dashboard-main">
        <div class="w-full min-h-screen bg-surface p-6">
          <div class="bg-background text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-sm" data-slot="card">
            <div class="@container/card-header auto-rows-min grid-rows-[auto_auto] has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-6 m-0 flex w-full flex-wrap items-start justify-between gap-4 rounded-none p-4" data-slot="card-header">
              <div>
                <p class="text-default mb-1 text-lg leading-relaxed font-medium font-semibold">Members List</p>
                <p class="text-foreground block text-sm">See information about all members</p>
              </div>
              <div class="flex w-full shrink-0 flex-col items-center gap-3 sm:flex-row md:w-max"><div class="relative w-full sm:w-72"><input class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive pl-9" data-slot="input" placeholder="Search here..."/><svg class="lucide lucide-search text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg></div><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2 has-[&gt;svg]:px-3 w-full sm:w-auto" data-slot="button">Add Member</button></div></div><div class="mt-4 overflow-scroll rounded-none p-0" data-slot="card-content"><table class="w-full min-w-max table-auto text-left"><thead><tr><th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Name</p></th><th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Function</p></th><th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Status</p></th><th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Employed</p></th><th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"> </p></th></tr></thead><tbody><tr><td class="border-surface border-b p-4"><div class="flex items-center gap-3"><span class="relative flex size-8 shrink-0 overflow-hidden rounded-full" data-slot="avatar"></span><div><p class="text-default block text-sm font-semibold">Emma Roberts</p><p class="text-foreground block text-sm"><span class="text-foreground block text-sm">[email protected]</span></p></div></div></td><td class="border-surface border-b p-4"><div><p class="text-default block text-sm font-semibold">Manager</p><p class="text-foreground block text-sm">Organization</p></div></td><td class="border-surface border-b p-4"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 gap-1 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="success" data-slot="badge">Online</span></td><td class="border-surface border-b p-4"><p class="text-foreground block text-sm">23/04/18</p></td><td class="border-surface border-b p-4"><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" color="secondary" data-slot="tooltip-trigger" data-state="closed"><svg class="lucide lucide-pencil h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path></svg></button></td></tr><tr><td class="border-surface border-b p-4"><div class="flex items-center gap-3"><span class="relative flex size-8 shrink-0 overflow-hidden rounded-full" data-slot="avatar"></span><div><p class="text-default block text-sm font-semibold">Marcel Glock</p><p class="text-foreground block text-sm"><span class="text-foreground block text-sm">[email protected]</span></p></div></div></td><td class="border-surface border-b p-4"><div><p class="text-default block text-sm font-semibold">Executive</p><p class="text-foreground block text-sm">Projects</p></div></td><td class="border-surface border-b p-4"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 gap-1 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="error" data-slot="badge">Offline</span></td><td class="border-surface border-b p-4"><p class="text-foreground block text-sm">23/04/18</p></td><td class="border-surface border-b p-4"><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" color="secondary" data-slot="tooltip-trigger" data-state="closed"><svg class="lucide lucide-pencil h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path></svg></button></td></tr><tr><td class="border-surface border-b p-4"><div class="flex items-center gap-3"><span class="relative flex size-8 shrink-0 overflow-hidden rounded-full" data-slot="avatar"></span><div><p class="text-default block text-sm font-semibold">Misha Stam</p><p class="text-foreground block text-sm"><span class="text-foreground block text-sm">[email protected]</span></p></div></div></td><td class="border-surface border-b p-4"><div><p class="text-default block text-sm font-semibold">Social Media</p><p class="text-foreground block text-sm">Projects</p></div></td><td class="border-surface border-b p-4"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 gap-1 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="success" data-slot="badge">Online</span></td><td class="border-surface border-b p-4"><p class="text-foreground block text-sm">23/04/18</p></td><td class="border-surface border-b p-4"><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" color="secondary" data-slot="tooltip-trigger" data-state="closed"><svg class="lucide lucide-pencil h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path></svg></button></td></tr><tr><td class="border-surface border-b p-4"><div class="flex items-center gap-3"><span class="relative flex size-8 shrink-0 overflow-hidden rounded-full" data-slot="avatar"></span><div><p class="text-default block text-sm font-semibold">Lucian Eurel</p><p class="text-foreground block text-sm"><span class="text-foreground block text-sm">[email protected]</span></p></div></div></td><td class="border-surface border-b p-4"><div><p class="text-default block text-sm font-semibold">Programator</p><p class="text-foreground block text-sm">Developer</p></div></td><td class="border-surface border-b p-4"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 gap-1 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="error" data-slot="badge">Offline</span></td><td class="border-surface border-b p-4"><p class="text-foreground block text-sm">23/04/18</p></td><td class="border-surface border-b p-4"><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" color="secondary" data-slot="tooltip-trigger" data-state="closed"><svg class="lucide lucide-pencil h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path></svg></button></td></tr><tr><td class="border-surface border-b p-4"><div class="flex items-center gap-3"><span class="relative flex size-8 shrink-0 overflow-hidden rounded-full" data-slot="avatar"></span><div><p class="text-default block text-sm font-semibold">Linde Michele</p><p class="text-foreground block text-sm"><span class="text-foreground block text-sm">[email protected]</span></p></div></div></td><td class="border-surface border-b p-4"><div><p class="text-default block text-sm font-semibold">Manager</p><p class="text-foreground block text-sm">Organization</p></div></td><td class="border-surface border-b p-4"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 gap-1 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="error" data-slot="badge">Offline</span></td><td class="border-surface border-b p-4"><p class="text-foreground block text-sm">23/04/18</p></td><td class="border-surface border-b p-4"><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" color="secondary" data-slot="tooltip-trigger" data-state="closed"><svg class="lucide lucide-pencil h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path></svg></button></td></tr><tr><td class="border-surface border-b p-4"><div class="flex items-center gap-3"><span class="relative flex size-8 shrink-0 overflow-hidden rounded-full" data-slot="avatar"></span><div><p class="text-default block text-sm font-semibold">Georg Joshiash</p><p class="text-foreground block text-sm"><span class="text-foreground block text-sm">[email protected]</span></p></div></div></td><td class="border-surface border-b p-4"><div><p class="text-default block text-sm font-semibold">Designer</p><p class="text-foreground block text-sm">Projects</p></div></td><td class="border-surface border-b p-4"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&amp;&gt;svg]:size-3 gap-1 [&amp;&gt;svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden w-max" color="success" data-slot="badge">Online</span></td><td class="border-surface border-b p-4"><p class="text-foreground block text-sm">23/04/18</p></td><td class="border-surface border-b p-4"><button class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9" color="secondary" data-slot="tooltip-trigger" data-state="closed"><svg class="lucide lucide-pencil h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"></path><path d="m15 5 4 4"></path></svg></button></td></tr></tbody></table></div><div class="[.border-t]:pt-6 flex flex-wrap items-center justify-between gap-4 p-4" data-slot="card-footer"><p class="text-default block text-sm">Page 2 <span class="text-foreground font-normal">of 10</span></p><div class="flex items-center gap-2"><button class="justify-center whitespace-nowrap text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 h-8 rounded-md px-3 has-[&gt;svg]:px-2.5 flex items-center gap-1.5" color="secondary" data-slot="button"><svg class="lucide lucide-chevron-left h-4 w-4 stroke-2" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m15 18-6-6 6-6"></path></svg>prev</button><button class="justify-center whitespace-nowrap text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*='size-'])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 h-8 rounded-md px-3 has-[&gt;svg]:px-2.5 flex items-center gap-1.5" color="secondary" data-slot="button">Next<svg class="lucide lucide-chevron-right h-4 w-4 stroke-2" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></button></div></div></div>
        </div>
      </main>
  </div>

<script>
(function () {
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
    triggers.forEach(function (btn, idx) {
      btn.classList.add('collapsible-trigger');

      // Find target content safely (Radix-style aria-controls)
      var targetId = btn.getAttribute('aria-controls');
      var content = targetId ? document.getElementById(targetId) : null;

      // Fallback: next sibling with data-slot="collapsible-content"
      if (!content) {
        var parent = btn.closest('[data-slot="collapsible"]');
        if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
      }
      if (!content) return;

      content.classList.add('collapsible-content');

      // Mark chevron for rotation
      var chev = btn.querySelector('.lucide-chevron-right');
      if (chev) chev.classList.add('collapsible-chevron');

      // Initial state
      var expanded = btn.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        content.hidden = false;
        content.classList.add('is-open');
        content.style.height = 'auto';
      } else {
        content.hidden = true;
        content.classList.remove('is-open');
        content.style.height = '0px';
      }

      // Toggle handler
      btn.addEventListener('click', function (e) {
        e.preventDefault();

        var isOpen = btn.getAttribute('aria-expanded') === 'true';

        if (!isOpen) {
          // OPEN
          btn.setAttribute('aria-expanded', 'true');
          btn.setAttribute('data-state', 'open');
          content.hidden = false;
          content.classList.add('is-open');
          content.setAttribute('data-state', 'open');

          // animate height: 0 -> scrollHeight
          content.style.height = '0px';
          var h = content.scrollHeight;
          requestAnimationFrame(function () {
            content.style.height = h + 'px';
          });

          var onEnd = function (ev) {
            if (ev.propertyName !== 'height') return;
            content.style.height = 'auto';
            content.removeEventListener('transitionend', onEnd);
          };
          content.addEventListener('transitionend', onEnd);

        } else {
          // CLOSE
          btn.setAttribute('aria-expanded', 'false');
          btn.setAttribute('data-state', 'closed');
          content.classList.remove('is-open');
          content.setAttribute('data-state', 'closed');

          // animate height: current -> 0
          var current = content.scrollHeight;
          content.style.height = current + 'px';
          requestAnimationFrame(function () {
            content.style.height = '0px';
          });

          var onEndClose = function (ev) {
            if (ev.propertyName !== 'height') return;
            content.hidden = true;
            content.removeEventListener('transitionend', onEndClose);
          };
          content.addEventListener('transitionend', onEndClose);
        }
      }, { passive: false });
    });
  });
})();
</script>


<script>
(function () {
  function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    var input = document.querySelector('[data-slot="input"]');
    var table = document.querySelector('table');
    if (!input || !table) return;

    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));

    input.addEventListener('input', function () {
      var term = (input.value || '').trim().toLowerCase();

      rows.forEach(function (row) {
        var text = (row.textContent || '').toLowerCase();
        row.style.display = !term || text.indexOf(term) !== -1 ? '' : 'none';
      });
    });
  });
})();
</script>

<script>
  // K8S endpoints (paths absolus pour éviter les surprises depuis /pages/*)
  window.K8S_API_URL = "../k8s/k8s_api.php";
  window.K8S_UI_BASE = "./pages/";
</script>
<script src="../k8s/k8s-menu.js" defer></script>

</body>
</html>
