<?php
// ══════════════════════════════════════════════════════════════════════════════
//  include/menu.php
//  Sidebar de navigation + assistant « Ajouter un domaine » (modal multi-étapes)
// ══════════════════════════════════════════════════════════════════════════════

// Domaines détectés via les Ingress Kubernetes (passés par la page hôte)
$k8s_ingress_base_domains = isset($k8s_ingress_base_domains) && is_array($k8s_ingress_base_domains)
    ? $k8s_ingress_base_domains
    : [];

// ── Domaines achetés chez nous (PowerDNS) ────────────────────────────────────
//  La page hôte (dashboard.php) expose $domains : [['id' => …, 'name' => …], …]
//  On dérive ici une liste sûre, en restant tolérant si la variable n'existe pas
//  (le menu est inclus dans plusieurs pages qui ne définissent pas $domains).
$menu_purchased_domains = [];
if (isset($domains) && is_array($domains)) {
    foreach ($domains as $d) {
        $nm = is_array($d) ? (string)($d['name'] ?? '') : (string)$d;
        if ($nm === '') {
            continue;
        }
        $menu_purchased_domains[] = [
            'id'   => is_array($d) ? ($d['id'] ?? null) : null,
            'name' => $nm,
        ];
    }
}

// ── Déploiements disponibles (pour rattacher un domaine acheté) ───────────────
$menu_deployments = (isset($k8s_deployments_names) && is_array($k8s_deployments_names))
    ? array_values(array_filter(array_map('strval', $k8s_deployments_names), static fn ($n) => $n !== ''))
    : [];

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
//  ⚠️ À ajuster selon votre infrastructure réelle (point de vérité unique).
$gnl_nameservers = ['ns1.gnl-solution.fr', 'ns2.gnl-solution.fr', 'ns3.gnl-solution.fr'];
$gnl_dns_target  = '203.0.113.10'; // IP/cible de l'Ingress public — placeholder
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
<!-- ══════════════════════════════════════════════════════════════════════
     « Ajouter un Domaine » — ouvre désormais l'assistant (modal), au lieu
     de rediriger vers ./commande. Toujours visible (avec ou sans domaine).
══════════════════════════════════════════════════════════════════════ -->
<button type="button" data-add-domain-open class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center rounded-md px-2.5 py-2 transition-colors text-left">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-package h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg></span>
<span class="font-medium truncate">Ajouter un Domaine</span>
</button>
</div>
</div>
</nav>
<small class="text-muted-foreground mb-3 block text-xs font-bold tracking-wide uppercase">Administration</small>
<nav class="mb-4 space-y-0.5 border-b pb-4">
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./equipes"><span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-users h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
<span class="font-medium">Equipe</span>
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
<a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors" href="./documentation">
<span class="mr-2.5 grid shrink-0 place-items-center"><svg class="lucide lucide-headphones h-5 w-5" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewbox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"></path></svg></span>
<span class="font-medium">FAQ</span>
</a>
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

                <!-- Dépliant de sélection (détails de population à finaliser) -->
                <div data-add-domain-purchased-picker class="mt-3 hidden">
                  <?php if (!empty($menu_purchased_domains)): ?>
                    <label for="addDomainPurchasedSelect" class="mb-1.5 block text-xs font-medium text-muted-foreground">Sélectionnez un domaine</label>
                    <select id="addDomainPurchasedSelect"
                      class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                      <option value="">— Choisir un domaine —</option>
                      <?php foreach ($menu_purchased_domains as $pd): ?>
                        <option value="<?php echo htmlspecialchars((string)$pd['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-domain-id="<?php echo htmlspecialchars((string)($pd['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                          <?php echo htmlspecialchars((string)$pd['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <div class="rounded-md border border-dashed px-3 py-3 text-xs text-muted-foreground">
                      Aucun domaine enregistré sur votre compte.
                      <a href="./commande" class="font-medium text-foreground underline underline-offset-2">En commander un</a>.
                    </div>
                  <?php endif; ?>
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

<script>
(function () {
  if (window.__addDomainWizardInit) return;           // évite la double initialisation
  window.__addDomainWizardInit = true;

  const CSRF = <?php echo json_encode($menu_csrf_token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  // Proxy PHP côté serveur qui relaie vers le webhook n8n (data-domain).
  // On ne contacte jamais n8n directement depuis le navigateur : le client_id
  // est injecté côté serveur depuis la session (non falsifiable) + protection CSRF.
  const DOMAINS_API = new URL('../data/domains_api.php', window.location.href).toString();

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

    function open()  { reset(); modal.classList.remove('hidden'); modal.classList.add('flex'); loadDomains(); }
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
      el.className = 'text-xs ' + (kind === 'err' ? 'text-red-600' : kind === 'ok' ? 'text-emerald-600' : 'text-muted-foreground');
    }

    // Appel JSON normalisé vers le proxy → webhook n8n (data-domain).
    // Le serveur renvoie toujours { ok: true/false, error?, domains?, row? }.
    async function apiCall(action, payload) {
      const u = new URL(DOMAINS_API);
      u.searchParams.set('action', action);
      const res = await fetch(u.toString(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
        body: new URLSearchParams(payload || {}),
      });
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

    async function submitStep(step) {
      nextBtn.disabled = true;
      setStatus(step, 'Traitement…', 'muted');
      try {
        // 1) On crée/enregistre la ligne dans la table (idempotent côté n8n via domain_buy_name).
        await apiCall('upsert', rowPayload());

        // 2) Selon l'étape, on déclenche la vérification ou le déploiement.
        if (step === 'purchased') {
          await apiCall('deploy', rowPayload());
          setStatus(step, 'Domaine rattaché. Déploiement lancé.', 'ok');
        } else {
          // registrar = vérif des serveurs DNS ; zone = vérif des enregistrements
          const data = await apiCall('verify', rowPayload());
          if (data.verified) {
            setStatus(step, 'Domaine vérifié ✓', 'ok');
          } else if (step === 'registrar') {
            setStatus(step, 'En attente de la propagation des serveurs DNS…', 'ok');
          } else {
            setStatus(step, 'En attente de la détection des enregistrements…', 'ok');
          }
        }
        loadDomains(); // rafraîchit la liste (dropdown) depuis la table
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
    async function loadDomains() {
      if (!purchasedSel) return;
      try {
        const data = await apiCall('list', {});
        domainsCache = Array.isArray(data.domains) ? data.domains : [];
        const previous = purchasedSel.value;
        // On ne garde dans le dépliant que les domaines achetés chez GNL.
        const owned = domainsCache.filter(d => String(d.gnl_domain) === '1' || d.gnl_domain === true);
        if (owned.length === 0) return; // garde le rendu PHP existant
        purchasedSel.innerHTML = '<option value="">— Choisir un domaine —</option>' +
          owned.map(d => {
            const name = String(d.domain_buy_name || '');
            const id   = String(d.id || '');
            const esc  = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            return '<option value="' + esc(name) + '" data-domain-id="' + esc(id) + '">' + esc(name) + '</option>';
          }).join('');
        if (previous) purchasedSel.value = previous;
        updateNext();
      } catch (_) {
        // silencieux : on conserve les options PHP
      }
    }

    // état initial
    reset();
  });
})();
</script>