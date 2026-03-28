<?php
$k8s_ingress_base_domains = isset($k8s_ingress_base_domains) && is_array($k8s_ingress_base_domains)
    ? $k8s_ingress_base_domains
    : [];
?>
<div class="bg-background app-shell-offset-min-height flex h-full min-h-full w-full max-w-xs flex-col border shadow-sm dashboard-sidebar">
<div class="px-6 pt-6"></div>
<div class="flex-1 px-6 pb-6">
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Techenique</small>
<nav class="mb-4 space-y-0.5 border-b pb-4">
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-services-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-layout-grid h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><rect height="7" rx="1" width="7" x="3" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="14"></rect><rect height="7" rx="1" width="7" x="3" y="14"></rect></svg></span><span class="font-medium">Mes services</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-services-content">
<div id="k8s-deployments" class="mt-1 space-y-1"></div>
</div>
</div>
<div data-slot="collapsible" data-state="closed">
<button aria-controls="sidebar-dns-content" aria-expanded="false" class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors" data-slot="collapsible-trigger" data-state="closed" type="button">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-layout-grid h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><rect height="7" rx="1" width="7" x="3" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="14"></rect><rect height="7" rx="1" width="7" x="3" y="14"></rect></svg></span><span class="font-medium">Zone DNS</span><span class="ml-auto grid shrink-0 place-items-center pl-2.5"><svg class="lucide lucide-chevron-right h-4 w-4" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="m9 18 6-6-6-6"></path></svg></span>
</button>
<div class="mt-1 space-y-1" data-slot="collapsible-content" data-state="closed" hidden="" id="sidebar-dns-content">
<?php if (!empty($k8s_ingress_base_domains)): ?>
<?php foreach ($k8s_ingress_base_domains as $domain): ?>
<div class="text-muted-foreground flex items-center rounded-md px-2.5 py-2 pl-10 text-sm">
<span class="font-medium truncate"><?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?></span>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun domaine détecté</div>
<?php endif; ?>
</div>
</div>
</nav>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Administration</small>
<nav class="mb-4 space-y-0.5 border-b pb-4">
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./equipes"><span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-users h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
<span class="font-medium">Equipe</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./projets">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
<span class="font-medium">Mes Projets</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./commande">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
<span class="font-medium">Mes commandes</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./facture">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-receipt h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2"></path><path d="M16 8h-8"></path><path d="M16 12h-8"></path><path d="M12 16h-4"></path></svg></span>
<span class="font-medium">Mes factures</span>
</a>
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./abonnements">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-refresh-cw h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M21 2v6h-6"></path><path d="M3 12a9 9 0 0 1 15.55-6.36L21 8"></path><path d="M3 22v-6h6"></path><path d="M21 12a9 9 0 0 1-15.55 6.36L3 16"></path></svg></span>
<span class="font-medium">Mes abonnements</span>
</a>
</nav>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Support</small>
<nav class="space-y-0.5">
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="https://incident.gnl-solution.fr/">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-headphones h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"></path></svg></span>
<span class="font-medium">Help and Support</span>
</a>
</nav>
</div>
<div class="mt-auto p-6 pt-0">
<div class="bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px my-6" data-orientation="horizontal" data-slot="separator" role="none"></div>
<small class="text-muted-foreground block text-center text-sm">GNL Solution</small>
        </div>
      </div>
