<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: /connexion");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];

// Inclusion du fichier de configuration qui crée $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once '../config_loader.php';

// Récupérer les domaines PowerDNS pour l'utilisateur
$user_account = $_SESSION['user']['id'];
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);

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
  <link rel="preload" as="script" fetchPriority="low" href="../assets/js/chunks/webpack-9a5725d2191b0ffe74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN"/>
  <script src="../assets/js/chunks/7f6febab-8890b596c86c36c274a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/93579-544a9d9715058faa74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/main-app-1f00358174e7fff574a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/24255-f680ac166d10741374a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/21355-2d2b4b16dbf7c8c174a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/app/layout-60a23d0e1798237574a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/a5407e4f-0755c7458d01ce2574a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/62475-6e88cfe6c4ed090674a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/71302-be2009c96efbee7074a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/35327-95c38693b82dded274a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/62668-b69a37d46787d24c74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/4878-d9fe174aedb5da2574a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/59059-73570667f6b828b974a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/88022-b9c8919a0a54e91a74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/56781-7a3627bb371be0e474a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/57341-56558a29d488924d74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/68559-6ddf365dcfa2ce8774a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/7114-179ad431eba2e72674a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/31887-6bd60806ed07bced74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/25445-58276efeed633dfb74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/6237-010afa36fc51426374a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/50683-410ffebcf268ad2d74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/98193-443ff38d4c49c58674a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/61202-675a2d4d7b574ffe74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/38941-6661427224b8386e74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/3236-6899fd1c3509075074a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/75347-e58d6492db2f2d1474a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/60678-1d6a80c6e6167c7b74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/96586-40fb6ea0706974e674a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/28906-0084639ed8306e3b74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/78272-7f0038dbddad1bf574a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/75823-a2f86a1a92a71dfe74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/52237-949055dc61988b3974a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/34338-c6e436fa7d2f6dab74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/26323-82232bf44c28aacf74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <script src="../assets/js/chunks/app/(view)/view/%5bname%5d/page-ff9cb4a45c66c81e74a1.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
<body>
  <?php include("../include/header.php"); ?>
  <div class="dashboard-layout" style="display:flex;flex-direction:row;align-items:flex-start;width:100%;">
    <div class="bg-background flex h-screen w-full max-w-xs flex-col overflow-y-scroll border shadow-sm" style="flex:0 0 20rem;width:20rem;max-width:20rem;">
      <div class="px-6 pt-6"></div>
      <div class="flex-1 px-6 pb-6">
        <small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Navigation</small>
        <nav class="mb-4 space-y-0.5 border-b pb-4">
          <div data-state="closed" data-slot="collapsible">
            <button type="button" aria-controls="radix-«Rl7neplb»" aria-expanded="false" data-state="closed" data-slot="collapsible-trigger" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors">
              <span class="mr-2.5 grid shrink-0 place-items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-grid h-5 w-5"><rect width="7" height="7" x="3" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="14" rx="1"></rect><rect width="7" height="7" x="3" y="14" rx="1"></rect></svg></span><span class="font-medium">Projet A</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-right h-4 w-4"><path d="m9 18 6-6-6-6"></path></svg></span>
            </button>
            <div data-state="closed" id="radix-«Rl7neplb»" hidden="" data-slot="collapsible-content" class="mt-1 space-y-1">
<?php if (!empty($domains)): ?>
  <?php foreach ($domains as $d): ?>
    <a href="#" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 pl-10 transition-colors">
      <span class="truncate"><?= htmlspecialchars($d['name']) ?></span>
    </a>
  <?php endforeach; ?>
<?php else: ?>
  <div class="text-muted-foreground px-2.5 py-2 pl-10 text-sm">Aucun domaine</div>
<?php endif; ?>
</div>
          </div>
          <a href="#" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors">
            <span class="mr-2.5 grid shrink-0 place-items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package h-5 w-5"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
            <span class="font-medium">Products</span>
          </a>
          <a href="#" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors">
            <span class="mr-2.5 grid shrink-0 place-items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shopping-cart h-5 w-5"><circle cx="8" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path></svg></span>
            <span class="font-medium">Orders</span>
          </a>
          <a href="#" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors"><span class="mr-2.5 grid shrink-0 place-items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users h-5 w-5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            <span class="font-medium">Customers</span>
          </a>
        </nav>
        <small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Support</small>
        <nav class="space-y-0.5">
          <a href="#" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors">
            <span class="mr-2.5 grid shrink-0 place-items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-headphones h-5 w-5"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"></path></svg></span>
            <span class="font-medium">Help and Support</span>
          </a>
          <a href="#" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors">
            <span class="mr-2.5 grid shrink-0 place-items-center"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-log-out h-5 w-5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg></span>
            <span class="font-medium">Sign Out</span>
          </a>
        </nav>
      </div>
      <div class="mt-auto p-6 pt-0">
        <div data-orientation="horizontal" role="none" data-slot="separator" class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px mb-6"></div>
        <div data-slot="alert" role="alert" class="w-full rounded-lg border px-4 py-3 text-sm grid has-[&gt;svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[&gt;svg]:gap-x-3 gap-y-0.5 items-start [&amp;&gt;svg]:size-4 [&amp;&gt;svg]:translate-y-0.5 [&amp;&gt;svg]:text-current relative border-transparent bg-green-500/10 text-green-500">
          <button data-slot="button" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:text-accent-foreground dark:hover:bg-accent/50 size-9 absolute top-2 right-2 h-6 w-6 hover:bg-green-500/20"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x h-3.5 w-3.5"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg></button>
          <div class="pr-6">
            <div class="mb-3 flex items-center gap-2">
              <div class="rounded-lg bg-green-500/20 p-1.5"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bell h-3.5 w-3.5"><path d="M10.268 21a2 2 0 0 0 3.464 0"></path><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path></svg></div>
              <div data-slot="alert-description" class="text-muted-foreground col-start-2 grid justify-items-start gap-1 text-sm [&amp;_p]:leading-relaxed m-0 font-semibold">New Version Available</div>
            </div>
            <div data-slot="alert-description" class="text-muted-foreground col-start-2 grid justify-items-start gap-1 [&amp;_p]:leading-relaxed mb-4 text-sm">Update your app and enjoy the new features and improvements.</div>
            <div class="flex items-center gap-4">
              <button data-slot="button" class="inline-flex items-center justify-center whitespace-nowrap transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:hover:bg-accent/50 rounded-md gap-1.5 has-[&gt;svg]:px-2.5 h-auto p-0 text-sm font-semibold text-red-500 hover:bg-red-500/10 hover:text-red-600">Dismiss</button>
              <button data-slot="button" class="inline-flex items-center justify-center whitespace-nowrap transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:hover:bg-accent/50 rounded-md gap-1.5 has-[&gt;svg]:px-2.5 h-auto p-0 text-sm font-semibold text-green-500 hover:bg-green-500/10 hover:text-green-600">Upgrade Now</button>
            </div>
          </div>
        </div>
        <div data-orientation="horizontal" role="none" data-slot="separator" class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px my-6"></div>
        <small class="text-muted-foreground block text-center text-sm">Creative Tim UI v3.0.0</small>
      </div>
    </div>
  </div></div>
  <!--$--><!--/$-->
  <section aria-label="Notifications alt+T" tabindex="-1" aria-live="polite" aria-relevant="additions text" aria-atomic="false"></section><script src="../_next/static/chunks/webpack-bb24677fc12e5c39992b.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" async=""></script><script>(self.__next_f=self.__next_f||[]).push([0])</script><script>self.__next_f.push([1,"1:\"$Sreact.fragment\"\n2:I[26604,[\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7177\",\"static/chunks/app/layout-60a23d0e17982375.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"\"]\n3:I[40062,[\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7177\",\"static/chunks/app/layout-60a23d0e17982375.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"ThemeProvider\"]\n4:I[3801,[\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7177\",\"static/chunks/app/layout-60a23d0e17982375.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"LayoutProvider\"]\n5:I[17035,[\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7177\",\"static/chunks/app/layout-60a23d0e17982375.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"ActiveThemeProvider\"]\n6:I[84544,[],\"\"]\n7:I[97062,[],\"\"]\n8:I[37445,[\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7177\",\"static/chunks/app/layout-60a23d0e17982375.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"Toaster\"]\n9:I[62808,[\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7177\",\"static/chunks/app/layout-60a23d0e17982375.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"Analytics\"]\nb:I[59600,[],\"OutletBoundary\"]\ne:I[97848,[],\"AsyncMetadataOutlet\"]\n10:I[59600,[],\"ViewportBoundary\"]\n12:I[59600,[],\"MetadataBoundary\"]\n14:I[53599,[],\"\"]\n:HL[\"/ui/_next/static/media/4cf2300e9c8272f7-s.p.woff2\",\"font\",{\"crossOrigin\":\"\",\"type\":\"font"])</script><script>self.__next_f.push([1,"/woff2\"}]\n:HL[\"/ui/_next/static/media/81f255edf7f746ee-s.p.woff2\",\"font\",{\"crossOrigin\":\"\",\"type\":\"font/woff2\"}]\n:HL[\"/ui/_next/static/media/96b9d03623b8cae2-s.p.woff2\",\"font\",{\"crossOrigin\":\"\",\"type\":\"font/woff2\"}]\n:HL[\"/ui/_next/static/media/e4af272ccee01ff0-s.p.woff2\",\"font\",{\"crossOrigin\":\"\",\"type\":\"font/woff2\"}]\n:HL[\"../_next/static/css/f01f021cce21c55f992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n:HL[\"../_next/static/css/94db31bbefae3b73992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n:HL[\"../_next/static/css/f50ae58b45097a9e992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n:HL[\"../_next/static/css/e2c436fd740d88d7992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n:HL[\"../_next/static/css/a86c08583c63d2b3992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n:HL[\"../_next/static/css/0cd54441d5cc9677992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n:HL[\"../_next/static/css/7c25ef1d5cdc2eda992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"style\"]\n"])</script><script>self.__next_f.push([1,"0:{\"P\":null,\"b\":\"QNl7mz3ZzlcFaaNguj2hg\",\"p\":\"/ui\",\"c\":[\"\",\"view\",\"sidebar-with-notification\"],\"i\":false,\"f\":[[[\"\",{\"children\":[\"(view)\",{\"children\":[\"view\",{\"children\":[[\"name\",\"sidebar-with-notification\",\"d\"],{\"children\":[\"__PAGE__\",{}]}]}]}]},\"$undefined\",\"$undefined\",true],[\"\",[\"$\",\"$1\",\"c\",{\"children\":[[[\"$\",\"link\",\"0\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/f01f021cce21c55f992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}],[\"$\",\"link\",\"1\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/94db31bbefae3b73992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}]],[\"$\",\"html\",null,{\"lang\":\"en\",\"suppressHydrationWarning\":true,\"children\":[[\"$\",\"head\",null,{\"children\":[[\"$\",\"$L2\",null,{\"src\":\"https://accounts.google.com/gsi/client\",\"strategy\":\"afterInteractive\"}],[\"$\",\"script\",null,{\"dangerouslySetInnerHTML\":{\"__html\":\"\\n              try {\\n                if (localStorage.theme === 'dark' || ((!('theme' in localStorage) || localStorage.theme === 'system') \u0026\u0026 window.matchMedia('(prefers-color-scheme: dark)').matches)) {\\n                  document.querySelector('meta[name=\\\"theme-color\\\"]').setAttribute('content', '#09090b')\\n                }\\n                if (localStorage.layout) {\\n                  document.documentElement.classList.add('layout-' + localStorage.layout)\\n                }\\n              } catch (_) {}\\n            \"}}],[\"$\",\"meta\",null,{\"name\":\"theme-color\",\"content\":\"#ffffff\"}]]}],[\"$\",\"body\",null,{\"className\":\"text-foreground group/body overscroll-none font-sans antialiased [--footer-height:calc(var(--spacing)*14)] [--header-height:calc(var(--spacing)*14)] xl:[--footer-height:calc(var(--spacing)*24)] __variable_ce64a8 __variable_0c0f58 __variable_17caaa __variable_ce64a8 __variable_993ba7\",\"children\":[\"$\",\"$L3\",null,{\"children\":[\"$\",\"$L4\",null,{\"children\":[\"$\",\"$L5\",null,{\"children\":[[\"$\",\"$L6\",null,{\"parallelRouterKey\":\"children\",\"error\":\"$undefined\",\"errorStyles\":\"$undefined\",\"errorScripts\":\"$undefined\",\"template\":[\"$\",\"$L7\",null,{}],\"templateStyles\":\"$undefined\",\"templateScripts\":\"$undefined\",\"notFound\":[[[\"$\",\"title\",null,{\"children\":\"404: This page could not be found.\"}],[\"$\",\"div\",null,{\"style\":{\"fontFamily\":\"system-ui,\\\"Segoe UI\\\",Roboto,Helvetica,Arial,sans-serif,\\\"Apple Color Emoji\\\",\\\"Segoe UI Emoji\\\"\",\"height\":\"100vh\",\"textAlign\":\"center\",\"display\":\"flex\",\"flexDirection\":\"column\",\"alignItems\":\"center\",\"justifyContent\":\"center\"},\"children\":[\"$\",\"div\",null,{\"children\":[[\"$\",\"style\",null,{\"dangerouslySetInnerHTML\":{\"__html\":\"body{color:#000;background:#fff;margin:0}.next-error-h1{border-right:1px solid rgba(0,0,0,.3)}@media (prefers-color-scheme:dark){body{color:#fff;background:#000}.next-error-h1{border-right:1px solid rgba(255,255,255,.3)}}\"}}],[\"$\",\"h1\",null,{\"className\":\"next-error-h1\",\"style\":{\"display\":\"inline-block\",\"margin\":\"0 20px 0 0\",\"padding\":\"0 23px 0 0\",\"fontSize\":24,\"fontWeight\":500,\"verticalAlign\":\"top\",\"lineHeight\":\"49px\"},\"children\":404}],[\"$\",\"div\",null,{\"style\":{\"display\":\"inline-block\"},\"children\":[\"$\",\"h2\",null,{\"style\":{\"fontSize\":14,\"fontWeight\":400,\"lineHeight\":\"49px\",\"margin\":0},\"children\":\"This page could not be found.\"}]}]]}]}]],[]],\"forbidden\":\"$undefined\",\"unauthorized\":\"$undefined\"}],null,[\"$\",\"$L8\",null,{\"position\":\"top-center\"}],[\"$\",\"$L9\",null,{}]]}]}]}]}]]}]]}],{\"children\":[\"(view)\",[\"$\",\"$1\",\"c\",{\"children\":[null,[\"$\",\"$L6\",null,{\"parallelRouterKey\":\"children\",\"error\":\"$undefined\",\"errorStyles\":\"$undefined\",\"errorScripts\":\"$undefined\",\"template\":[\"$\",\"$L7\",null,{}],\"templateStyles\":\"$undefined\",\"templateScripts\":\"$undefined\",\"notFound\":[[[\"$\",\"title\",null,{\"children\":\"404: This page could not be found.\"}],[\"$\",\"div\",null,{\"style\":\"$0:f:0:1:1:props:children:1:props:children:1:props:children:props:children:props:children:props:children:0:props:notFound:0:1:props:style\",\"children\":[\"$\",\"div\",null,{\"children\":[[\"$\",\"style\",null,{\"dangerouslySetInnerHTML\":{\"__html\":\"body{color:#000;background:#fff;margin:0}.next-error-h1{border-right:1px solid rgba(0,0,0,.3)}@media (prefers-color-scheme:dark){body{color:#fff;background:#000}.next-error-h1{border-right:1px solid rgba(255,255,255,.3)}}\"}}],[\"$\",\"h1\",null,{\"className\":\"next-error-h1\",\"style\":\"$0:f:0:1:1:props:children:1:props:children:1:props:children:props:children:props:children:props:children:0:props:notFound:0:1:props:children:props:children:1:props:style\",\"children\":404}],[\"$\",\"div\",null,{\"style\":\"$0:f:0:1:1:props:children:1:props:children:1:props:children:props:children:props:children:props:children:0:props:notFound:0:1:props:children:props:children:2:props:style\",\"children\":[\"$\",\"h2\",null,{\"style\":\"$0:f:0:1:1:props:children:1:props:children:1:props:children:props:children:props:children:props:children:0:props:notFound:0:1:props:children:props:children:2:props:children:props:style\",\"children\":\"This page could not be found.\"}]}]]}]}]],[]],\"forbidden\":\"$undefined\",\"unauthorized\":\"$undefined\"}]]}],{\"children\":[\"view\",[\"$\",\"$1\",\"c\",{\"children\":[null,[\"$\",\"$L6\",null,{\"parallelRouterKey\":\"children\",\"error\":\"$undefined\",\"errorStyles\":\"$undefined\",\"errorScripts\":\"$undefined\",\"template\":[\"$\",\"$L7\",null,{}],\"templateStyles\":\"$undefined\",\"templateScripts\":\"$undefined\",\"notFound\":\"$undefined\",\"forbidden\":\"$undefined\",\"unauthorized\":\"$undefined\"}]]}],{\"children\":[[\"name\",\"sidebar-with-notification\",\"d\"],[\"$\",\"$1\",\"c\",{\"children\":[null,[\"$\",\"$L6\",null,{\"parallelRouterKey\":\"children\",\"error\":\"$undefined\",\"errorStyles\":\"$undefined\",\"errorScripts\":\"$undefined\",\"template\":[\"$\",\"$L7\",null,{}],\"templateStyles\":\"$undefined\",\"templateScripts\":\"$undefined\",\"notFound\":\"$undefined\",\"forbidden\":\"$undefined\",\"unauthorized\":\"$undefined\"}]]}],{\"children\":[\"__PAGE__\",[\"$\",\"$1\",\"c\",{\"children\":[\"$La\",[[\"$\",\"link\",\"0\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/f50ae58b45097a9e992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}],[\"$\",\"link\",\"1\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/e2c436fd740d88d7992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}],[\"$\",\"link\",\"2\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/a86c08583c63d2b3992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}],[\"$\",\"link\",\"3\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/0cd54441d5cc9677992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}],[\"$\",\"link\",\"4\",{\"rel\":\"stylesheet\",\"href\":\"../_next/static/css/7c25ef1d5cdc2eda992b.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"precedence\":\"next\",\"crossOrigin\":\"$undefined\",\"nonce\":\"$undefined\"}]],[\"$\",\"$Lb\",null,{\"children\":[\"$Lc\",\"$Ld\",[\"$\",\"$Le\",null,{\"promise\":\"$@f\"}]]}]]}],{},null,false]},null,false]},null,false]},null,false]},null,false],[\"$\",\"$1\",\"h\",{\"children\":[null,[\"$\",\"$1\",\"j4TC-mG6agiwXabwz2XNXv\",{\"children\":[[\"$\",\"$L10\",null,{\"children\":\"$L11\"}],[\"$\",\"meta\",null,{\"name\":\"next-size-adjust\",\"content\":\"\"}]]}],[\"$\",\"$L12\",null,{\"children\":\"$L13\"}]]}],false]],\"m\":\"$undefined\",\"G\":[\"$14\",\"$undefined\"],\"s\":false,\"S\":true}\n"])</script><script>self.__next_f.push([1,"15:\"$Sreact.suspense\"\n16:I[97848,[],\"AsyncMetadata\"]\n13:[\"$\",\"div\",null,{\"hidden\":true,\"children\":[\"$\",\"$15\",null,{\"fallback\":null,\"children\":[\"$\",\"$L16\",null,{\"promise\":\"$@17\"}]}]}]\nd:null\n"])</script><script>self.__next_f.push([1,"11:[[\"$\",\"meta\",\"0\",{\"charSet\":\"utf-8\"}],[\"$\",\"meta\",\"1\",{\"name\":\"viewport\",\"content\":\"width=device-width, initial-scale=1\"}]]\nc:null\n"])</script><script>self.__next_f.push([1,"a:[\"$\",\"div\",null,{\"className\":\"w-full bg-surface\",\"children\":\"$L18\"}]\n"])</script><script>self.__next_f.push([1,"f:{\"metadata\":[[\"$\",\"title\",\"0\",{\"children\":\"Sidebar with notification alert for new version updates\"}],[\"$\",\"meta\",\"1\",{\"name\":\"description\",\"content\":\"Sidebar with notification alert for new version updates\"}],[\"$\",\"link\",\"2\",{\"rel\":\"author\",\"href\":\"https://www.creative-tim.com\"}],[\"$\",\"meta\",\"3\",{\"name\":\"author\",\"content\":\"Creative Tim\"}],[\"$\",\"link\",\"4\",{\"rel\":\"manifest\",\"href\":\"https://www.creative-tim.com/ui/site.webmanifest\",\"crossOrigin\":\"$undefined\"}],[\"$\",\"meta\",\"5\",{\"name\":\"keywords\",\"content\":\"Creative Tim,UI,shadcn,Components,shadcn/ui,Blocks,AI Agents,v0,Lovable,Claude\"}],[\"$\",\"meta\",\"6\",{\"name\":\"creator\",\"content\":\"@creativetim\"}],[\"$\",\"meta\",\"7\",{\"property\":\"og:title\",\"content\":\"sidebar-with-notification\"}],[\"$\",\"meta\",\"8\",{\"property\":\"og:description\",\"content\":\"Sidebar with notification alert for new version updates\"}],[\"$\",\"meta\",\"9\",{\"property\":\"og:url\",\"content\":\"https://www.creative-tim.com/view/sidebar-with-notification\"}],[\"$\",\"meta\",\"10\",{\"property\":\"og:image\",\"content\":\"https://raw.githubusercontent.com/creativetimofficial/ui/refs/heads/main/apps/www/public/opengraph-image.png\"}],[\"$\",\"meta\",\"11\",{\"property\":\"og:image:width\",\"content\":\"1200\"}],[\"$\",\"meta\",\"12\",{\"property\":\"og:image:height\",\"content\":\"630\"}],[\"$\",\"meta\",\"13\",{\"property\":\"og:image:alt\",\"content\":\"Creative Tim UI\"}],[\"$\",\"meta\",\"14\",{\"property\":\"og:type\",\"content\":\"article\"}],[\"$\",\"meta\",\"15\",{\"name\":\"twitter:card\",\"content\":\"summary_large_image\"}],[\"$\",\"meta\",\"16\",{\"name\":\"twitter:creator\",\"content\":\"@creativetim\"}],[\"$\",\"meta\",\"17\",{\"name\":\"twitter:title\",\"content\":\"sidebar-with-notification\"}],[\"$\",\"meta\",\"18\",{\"name\":\"twitter:description\",\"content\":\"Sidebar with notification alert for new version updates\"}],[\"$\",\"meta\",\"19\",{\"name\":\"twitter:image\",\"content\":\"https://raw.githubusercontent.com/creativetimofficial/ui/refs/heads/main/apps/www/public/opengraph-image.png\"}],[\"$\",\"link\",\"20\",{\"rel\":\"shortcut icon\",\"href\":\"https://www.creative-tim.com/ui/favicon-32x32.png\"}],[\"$\",\"link\",\"21\",{\"rel\":\"icon\",\"href\":\"https://www.creative-tim.com/ui/favicon.ico\"}],[\"$\",\"link\",\"22\",{\"rel\":\"apple-touch-icon\",\"href\":\"https://www.creative-tim.com/ui/apple-touch-icon.png\"}]],\"error\":null,\"digest\":\"$undefined\"}\n"])</script><script>self.__next_f.push([1,"17:{\"metadata\":\"$f:metadata\",\"error\":null,\"digest\":\"$undefined\"}\n"])</script><script>self.__next_f.push([1,"19:I[74363,[\"13983\",\"static/chunks/404992b-3?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"62475\",\"static/chunks/404992b-4?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"71302\",\"static/chunks/404992b-5?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"24255\",\"static/chunks/404992b?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"35327\",\"static/chunks/404992b-6?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"62668\",\"static/chunks/404992b-7?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"4878\",\"static/chunks/404992b-8?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"59059\",\"static/chunks/404992b-9?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"83998\",\"static/chunks/404992b-10?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"56781\",\"static/chunks/404992b-11?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"57341\",\"static/chunks/404992b-12?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"68559\",\"static/chunks/404992b-13?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"7114\",\"static/chunks/404992b-14?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"31887\",\"static/chunks/404992b-15?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"25445\",\"static/chunks/404992b-16?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"6237\",\"static/chunks/404992b-17?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"50683\",\"static/chunks/404992b-18?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"21355\",\"static/chunks/404992b-2?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"98193\",\"static/chunks/404992b-19?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"61202\",\"static/chunks/404992b-20?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"38941\",\"static/chunks/404992b-21?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"3236\",\"static/chunks/404992b-22?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"11515\",\"static/chunks/404992b-23?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"60678\",\"static/chunks/404992b-24?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"96586\",\"static/chunks/404992b-25?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"28906\",\"static/chunks/404992b-26?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"78272\",\"static/chunks/404992b-27?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"75823\",\"static/chunks/404992b-28?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"52237\",\"static/chunks/404992b-29?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"34338\",\"static/chunks/404992b-30?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"26323\",\"static/chunks/404992b-31?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\",\"79031\",\"static/chunks/app/(view)/view/%5Bname%5D/page-7efd1dddaa7dfcd3.js?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN\"],\"default\"]\n"])</script><script>self.__next_f.push([1,"18:[\"$\",\"div\",null,{\"className\":\"flex min-h-dvh items-center justify-center\",\"children\":[\"$\",\"$L19\",null,{}]}]\n"])</script><script defer src="https://static.cloudflareinsights.com/beacon.min.js/v8c78df7c7c0f484497ecbca7046644da1771523124516" integrity="sha512-8DS7rgIrAmghBFwoOTujcf6D9rXvH8xm8JQ1Ja01h9QX8EzXldiszufYa4IFfKdLUKTTrnSFXLDkUEOTrZQ8Qg==" data-cf-beacon='{"version":"2024.11.0","token":"1b7cbb72744b40c580f8633c6b62637e","server_timing":{"name":{"cfCacheStatus":true,"cfEdge":true,"cfExtPri":true,"cfL4":true,"cfOrigin":true,"cfSpeedBrain":true},"location_startswith":null}}' crossorigin="anonymous"></script>

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
