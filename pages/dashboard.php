<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/session_bootstrap.php';
require_once __DIR__ . '/../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once __DIR__ . '/../include/session_user.php';

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// Client API portail (stats dashboard). Inclusion BEST-EFFORT : une absence ou
// un décalage de déploiement (ancien client sans portailFetchDashboardStats())
// ne doit JAMAIS empêcher le rendu de la page → pas de require « dur » ici.
$portailClientPath = __DIR__ . '/../include/portail_api_client.php';
if (is_readable($portailClientPath)) {
    require_once $portailClientPath;
}

require_once '../data/zabbix_api.php';

// Catalogue des services achetés (mêmes libellés que la barre latérale).
// Best-effort : absent → la légende retombe sur le nom du deployment.
$servicesCatalogPath = __DIR__ . '/../include/services_catalog.php';
if (is_readable($servicesCatalogPath)) {
    require_once $servicesCatalogPath;
}

if (accountSessionsIsCurrentSessionRevoked($pdo, sessionUserId())) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}

accountSessionsTouchCurrent($pdo, sessionUserId());

$name         = sessionUserField('nom') ?: sessionUserField('name');
$siret        = sessionUserField('siret');
$perm_id      = sessionUserField('perm_id');
$user_account = sessionUserId();

$k8s_namespace = sessionUserNsK8s();

// ── Domaines PowerDNS ────────────────────────────────────────────────────────
$domains = [];
if (isset($pdo_powerdns) && $pdo_powerdns instanceof PDO) {
    try {
        $query_domains = $pdo_powerdns->prepare(
            'SELECT id, name FROM domains WHERE account = ?'
        );
        $query_domains->execute([$user_account]);
        $domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($domains)) $domains = [];
    } catch (Throwable $exception) {
        error_log('[dashboard] PowerDNS domains: ' . $exception->getMessage());
        $domains = [];
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

if (!function_exists('dashboardExtractErrorCode')) {
    function dashboardExtractErrorCode(Throwable $e): ?string
    {
        $code = $e->getCode();
        if ((is_int($code) || preg_match('/^-?\d+$/', (string)$code)) && (int)$code !== 0) {
            return (string)(int)$code;
        }
        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $e->getMessage(), $m)) return $m[1];
        if (preg_match('/\bstatus(?:\s+code)?\s*[:=]?\s*(\d{3})\b/i', $e->getMessage(), $m)) return $m[1];
        return null;
    }
}

if (!function_exists('dashboardRenderWidgetErrorBadge')) {
    function dashboardRenderWidgetErrorBadge(?string $errorCode): string
    {
        if ($errorCode === null || $errorCode === '') return '';
        $safeCode = htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8');
        return '<span data-slot="badge" class="inline-flex items-center justify-center rounded border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 transition-[color,box-shadow] overflow-hidden border-transparent gap-1 bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-400">Erreur ' . $safeCode . '</span>';
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Amélioration #5 — deploymentBaseDomainFromHost() définie ici (idem deployment.php)
//   Avant : closure $k8sBaseDomain locale, logique dupliquée
//   → nommée en fonction globale pour être réutilisable via include futur
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dashboardBaseDomainFromHost')) {
    function dashboardBaseDomainFromHost(string $host): string
    {
        $host = strtolower(trim(rtrim($host, '.')));
        if (str_starts_with($host, '*.')) $host = substr($host, 2);
        $host = (string)preg_replace('/:\d+$/', '', $host);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return $host;
        $parts = array_values(array_filter(explode('.', $host), static fn ($p): bool => $p !== ''));
        $n = count($parts);
        if ($n <= 2) return $host;
        $last2     = $parts[$n - 2] . '.' . $parts[$n - 1];
        $twoLevel  = ['co.uk','org.uk','gov.uk','ac.uk','net.uk','com.au','net.au','org.au','co.nz','org.nz','com.br','com.mx','co.jp'];
        return (in_array($last2, $twoLevel, true) && $n >= 3) ? $parts[$n - 3] . '.' . $last2 : $last2;
    }
}

// ── Kubernetes : deployments + ingress ───────────────────────────────────────
$k8s_deployments_count      = 0;
$k8s_deployments_error_code = null;
$k8s_ingress_domains_count  = 0;
$k8s_ingress_error_code     = null;
$k8s_ingress_base_domains   = [];
$k8s_deployments_names      = [];

// ── Zabbix SLA ───────────────────────────────────────────────────────────────
$zabbixAvailability = zabbixApiGetAnnualAvailabilityDisplay(sessionUserArray());
$annual_availability_display = (string)($zabbixAvailability['display'] ?? '---');
$availability_error_code     = (isset($zabbixAvailability['error_code']) && $zabbixAvailability['error_code'] !== '')
    ? (string)$zabbixAvailability['error_code'] : null;

if ($k8s_namespace !== '') {
    $k8sClientPath = dirname(__DIR__) . '/data/KubernetesClient.php';
    if (!is_readable($k8sClientPath)) {
        $k8sClientPath = dirname(__DIR__) . '/KubernetesClient.php';
    }

    if (is_readable($k8sClientPath)) {
        require_once $k8sClientPath;

        try {
            $k8s = new KubernetesClient(null, null, null, 3);

            // Deployments
            try {
                $list  = $k8s->listDeployments($k8s_namespace);
                $items = is_array($list['items'] ?? null) ? $list['items'] : [];
                foreach ($items as $item) {
                    $depName = (string)($item['metadata']['name'] ?? '');
                    if ($depName !== '') $k8s_deployments_names[] = $depName;
                }
                sort($k8s_deployments_names, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_deployments_names = array_values(array_unique($k8s_deployments_names));
                $k8s_deployments_count = count($k8s_deployments_names);
            } catch (Throwable $e) {
                $k8s_deployments_count      = 0;
                $k8s_deployments_names      = [];
                $k8s_deployments_error_code = dashboardExtractErrorCode($e);
                error_log('[dashboard] K8s deployments: ' . $e->getMessage());
            }

            // Ingress
            try {
                $ns       = rawurlencode($k8s_namespace);
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
                foreach (($ingresses['items'] ?? []) as $ing) {
                    if (!is_array($ing)) continue;
                    foreach (($ing['spec']['rules'] ?? []) as $r) {
                        $h = (string)($r['host'] ?? '');
                        if ($h !== '') $hosts[] = $h;
                    }
                    foreach (($ing['spec']['tls'] ?? []) as $t) {
                        foreach (($t['hosts'] ?? []) as $h) {
                            $h = (string)$h;
                            if ($h !== '') $hosts[] = $h;
                        }
                    }
                }
                $baseDomains = [];
                foreach ($hosts as $h) {
                    $bd = dashboardBaseDomainFromHost($h);
                    if ($bd !== '') $baseDomains[$bd] = true;
                }
                $k8s_ingress_base_domains  = array_keys($baseDomains);
                sort($k8s_ingress_base_domains, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_ingress_domains_count = count($k8s_ingress_base_domains);
            } catch (Throwable $e) {
                $k8s_ingress_domains_count = 0;
                $k8s_ingress_base_domains  = [];
                $k8s_ingress_error_code    = dashboardExtractErrorCode($e);
                error_log('[dashboard] K8s ingress: ' . $e->getMessage());
            }

        } catch (Throwable $e) {
            // Échec de construction du client (token/CA manquants)
            $shared = dashboardExtractErrorCode($e);
            if ($k8s_deployments_error_code === null) $k8s_deployments_error_code = $shared;
            if ($k8s_ingress_error_code === null)     $k8s_ingress_error_code     = $shared;
            error_log('[dashboard] KubernetesClient init: ' . $e->getMessage());
        }
    }
}

// ── Stats de visites par deployment ──────────────────────────────────────────
// Source PRIMAIRE : l'API portail (pipeline n8n « data-portail », action
// "stats.dashboard"). Cohérent avec le reste du portail (UN SEUL webhook ;
// client_id injecté serveur, non falsifiable).
//
// Repli (best-effort) : anciens sidecars
//   <deployment>-stats.<namespace>.svc.cluster.local:9090/stats
// uniquement si l'API ne renvoie rien ET que STATS_SECRET est configuré.
//
// Quelle que soit la source, les données sont normalisées vers la même forme
// ({current_month_hits, previous_month_hits, by_month}) puis agrégées plus bas,
// de sorte que les cartes et le graphique restent inchangés.
$visit_stats_by_deployment = [];
$visitors_error_code       = null;
$visitors_load_failed      = false;
$current_month_hits        = 0;
$previous_month_hits       = 0;
$by_month_raw              = [];

if ($k8s_namespace !== '' && $k8s_deployments_names !== []) {
    // 1) Source primaire : API portail (n8n) — uniquement si le client est dispo.
    //
    // Fiabilisation (n8n parfois lent au premier appel → « Aucune donnée »
    // jusqu'à plusieurs rafraîchissements) :
    //   - délai porté de 4 s à 8 s, et UNE nouvelle tentative si la 1re échoue
    //     (exception / délai dépassé) ou revient vide ;
    //   - dernier résultat non vide mis en cache session : servi directement
    //     pendant DASHBOARD_STATS_TTL, et en secours (même périmé) si n8n
    //     ne répond pas du tout.
    if (function_exists('portailFetchDashboardStats')) {
        $statsCacheKey = 'dashboard_stats_cache';
        $statsCacheId  = $k8s_namespace . '|' . implode(',', $k8s_deployments_names);
        $statsCache    = $_SESSION[$statsCacheKey] ?? null;
        $statsCacheOk  = is_array($statsCache)
            && ($statsCache['id'] ?? null) === $statsCacheId
            && is_array($statsCache['data'] ?? null)
            && $statsCache['data'] !== [];
        $statsTtl = 300; // 5 min

        if ($statsCacheOk && (time() - (int)($statsCache['at'] ?? 0)) < $statsTtl) {
            $visit_stats_by_deployment = $statsCache['data'];
        } else {
            $lastError  = null;
            $lastStatus = 0;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $apiStats = portailFetchDashboardStats(sessionUserArray(), $k8s_deployments_names, 8, 3);
                    $visit_stats_by_deployment = is_array($apiStats['by_deployment'] ?? null)
                        ? $apiStats['by_deployment']
                        : [];
                    $lastStatus = (int)($apiStats['status'] ?? 0);
                    $lastError  = null;
                    if ($visit_stats_by_deployment !== []) break;
                    error_log('[dashboard] stats API: réponse vide (HTTP ' . $lastStatus . '), tentative ' . $attempt);
                } catch (Throwable $e) {
                    $visit_stats_by_deployment = [];
                    $lastError = $e;
                    error_log('[dashboard] stats API (tentative ' . $attempt . '): ' . $e->getMessage());
                }
            }

            if ($visit_stats_by_deployment !== []) {
                $_SESSION[$statsCacheKey] = ['id' => $statsCacheId, 'at' => time(), 'data' => $visit_stats_by_deployment];
            } elseif ($statsCacheOk) {
                // n8n muet : on ressert les dernières stats connues plutôt qu'un graphique vide.
                $visit_stats_by_deployment = $statsCache['data'];
            } elseif ($lastError !== null) {
                $visitors_error_code = dashboardExtractErrorCode($lastError);
                $visitors_load_failed = true;
            } elseif ($lastStatus !== 0 && ($lastStatus < 200 || $lastStatus >= 300)) {
                // Badge d'erreur sur la carte si n8n répond hors 2xx sans donnée.
                $visitors_error_code  = (string)$lastStatus;
                $visitors_load_failed = true;
            }
        }
    }

    // 2) Repli sidecar (best-effort) si l'API n'a rien renvoyé.
    if ($visit_stats_by_deployment === []) {
        $stats_secret = (string)(getenv('STATS_SECRET') ?: '');
        foreach ($k8s_deployments_names as $depName) {
            $stats_url = "http://{$depName}-stats.{$k8s_namespace}.svc.cluster.local:9090/stats";
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'header'  => $stats_secret !== '' ? "X-Stats-Secret: {$stats_secret}\r\n" : '',
                ],
            ]);
            $raw = @file_get_contents($stats_url, false, $ctx);
            if ($raw !== false) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    $visit_stats_by_deployment[$depName] = $decoded;
                }
            }
            // Sidecar absent → ignoré silencieusement (comportement voulu)
        }
    }

    // 3) Agrégats (cartes + tendance), source unifiée (API ou sidecar).
    foreach ($visit_stats_by_deployment as $decoded) {
        if (!is_array($decoded)) {
            continue;
        }
        $current_month_hits  += (int)($decoded['current_month_hits']  ?? 0);
        $previous_month_hits += (int)($decoded['previous_month_hits'] ?? 0);
        foreach (($decoded['by_month'] ?? []) as $month => $count) {
            $by_month_raw[$month] = ($by_month_raw[$month] ?? 0) + (int)$count;
        }
    }
}

// ── Graphique : 12 derniers mois ──────────────────────────────────────────────
$chart_month_labels = [];
$chart_month_keys   = [];
$monthNames = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
for ($i = 11; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
    $key = date('Y-m', $ts);
    $chart_month_keys[]   = $key;
    $chart_month_labels[] = $monthNames[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

// Une série par deployment du namespace, MÊME sans stats renvoyées par l'API
// (série à 0) : sinon une application sans trafic disparaît du graphique.
// Les clés de l'API sont rapprochées sans tenir compte de la casse.
$statsByLowerName = [];
foreach ($visit_stats_by_deployment as $depName => $stats) {
    if (is_array($stats)) $statsByLowerName[strtolower((string)$depName)] = $stats;
}
// Aucune stat du tout → pas de séries à 0 : on garde l'état « Aucune donnée ».
$chart_series_names = $visit_stats_by_deployment !== [] ? $k8s_deployments_names : [];
foreach (array_keys($visit_stats_by_deployment) as $depName) {
    $depName = (string)$depName;
    $known = false;
    foreach ($chart_series_names as $n) {
        if (strcasecmp($n, $depName) === 0) { $known = true; break; }
    }
    if (!$known) $chart_series_names[] = $depName;
}

$chart_datasets = [];
foreach ($chart_series_names as $depName) {
    $stats  = $statsByLowerName[strtolower((string)$depName)] ?? [];
    $series = [];
    foreach ($chart_month_keys as $key) {
        $series[] = (int)($stats['by_month'][$key] ?? 0);
    }
    $chart_datasets[(string)$depName] = $series;
}

// ── Libellés du graphique : noms affichés dans le menu « Mes services » ───────
// Le deployment K8s porte l'UID produit (order_product.uid) ou son
// provider_service_slug : on retrouve l'entrée du catalogue correspondante et
// on reprend son « name » (renommage client, sinon nom catalogue). Même cache
// session que services_menu_api.php → pas d'appel n8n supplémentaire en général.
$chart_dataset_labels = [];
foreach (array_keys($chart_datasets) as $depName) {
    $chart_dataset_labels[(string)$depName] = (string)$depName;
}
if ($chart_datasets !== [] && function_exists('servicesCatalogFetch')) {
    try {
        $catalogClientUid = trim((string)($_SESSION['user']['id'] ?? ''));
        $catalogClientId  = (int)($_SESSION['user']['account_id'] ?? 0);
        if ($catalogClientId <= 0 && ctype_digit($catalogClientUid)) {
            $catalogClientId = (int)$catalogClientUid;
        }
        $catalog = servicesCatalogFetch($catalogClientId);
        $nameByKey = [];
        foreach (($catalog['entries'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $label = trim((string)($entry['name'] ?? ''));
            if ($label === '') continue;
            foreach (['uid', 'provider_service_slug'] as $k) {
                $key = strtolower(trim((string)($entry[$k] ?? '')));
                if ($key !== '' && !isset($nameByKey[$key])) $nameByKey[$key] = $label;
            }
        }
        foreach ($chart_dataset_labels as $depName => $_) {
            $key = strtolower($depName);
            if (isset($nameByKey[$key])) $chart_dataset_labels[$depName] = $nameByKey[$key];
        }
    } catch (Throwable $e) {
        error_log('[dashboard] services catalog (libellés graphique) : ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Amélioration : évolution mensuelle en % — calcul centralisé côté PHP
//   Avant : calcul inline dans le HTML avec risque de division par zéro non capturé
// ══════════════════════════════════════════════════════════════════════════════
$hits_pct_vs_prev = null;
if ($previous_month_hits > 0 && $current_month_hits > 0) {
    $hits_pct_vs_prev = (int)round(
        (($current_month_hits - $previous_month_hits) / $previous_month_hits) * 100
    );
}

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="theme-color" content="#141a33"/>

  <!-- ══════════════════════════════════════════════════════════════════════
       Amélioration : titre contextualisé avec le nom de l'utilisateur
       Avant : <title><?= t('Dashboard - GNL Solution') ?></title>
  ══════════════════════════════════════════════════════════════════════ -->
  <title><?= t('Dashboard') ?><?= $name !== '' ? ' · ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '' ?> — GNL Solution</title>


  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <link rel="stylesheet" href="../assets/styles/gnl-portail.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/styles/gnl-portail.css') ?>"/>
  <link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin/>
  <link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32"/>

  <?php if ($chart_datasets !== []): /* Chart.js chargé seulement s'il y a des données à tracer */ ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
  <?php endif; ?>

  <style>
    /* Animations */
    @keyframes fadeUp{from{opacity:0;transform:translate3d(0,10px,0);}to{opacity:1;transform:translate3d(0,0,0);}}
    .chart-reveal{opacity:0;transform:translate3d(0,10px,0);}
    .chart-reveal.is-visible{animation:fadeUp .6s ease-out both;}
    .metric-card{transition:transform .2s ease,box-shadow .2s ease;}
    .metric-card:hover{transform:translate3d(0,-2px,0);}
    @media(prefers-reduced-motion:reduce){
      .chart-reveal,.chart-reveal.is-visible{opacity:1;transform:none;animation:none;}
      .metric-card{transition:none;}
    }

    /* Collapsibles */
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){
      .collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none !important;}
    }

    /* ══════════════════════════════════════════════════════════════════════
       Amélioration : cards métriques — indicateur de tendance visuel
    ══════════════════════════════════════════════════════════════════════ */
    .metric-trend-up   { color: #16a34a; }
    .metric-trend-down { color: #dc2626; }
    .metric-trend-neutral { color: #64748b; }

  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height ec-page">
<?php
  $dashFirstName = trim((string) sessionUserField('prenom'));
  $dashOrg       = $menuSideOrg ?? '';
  $dashCanInv    = !empty($menuCan['invoices']);
  $dashCanOrders = !empty($menuCan['orders']);
  $dashCanTicket = !empty($menuCan['tickets']);
  $dashCanDns    = !empty($menuCan['dns']);
  $dashH = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
        <!-- En-tête -->
        <div class="ec-head">
          <div>
            <p class="ec-kicker"><?= t('Tableau de bord') ?></p>
            <h1 class="ec-h1"><?= $dashFirstName !== '' ? t('Bonjour') . ' ' . $dashH($dashFirstName) : t('Bonjour') ?></h1>
            <p class="ec-sub">
              <?= $dashH($dashOrg) ?><?php if ($dashOrg !== ''): ?> · <?php endif; ?>
              <span id="dashSubServices"><?= (int) $k8s_deployments_count ?> <?= t('service(s)') ?></span>
              · <span id="dashSubDomains"><?= (int) $k8s_ingress_domains_count ?> <?= t('domaine(s)') ?></span>
            </p>
          </div>
          <a class="ec-btn ec-btn--primary" href="https://gnl-solution.com/solutions" target="_blank" rel="noopener">
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            <?= t('Commander un service') ?>
          </a>
        </div>

        <!-- Indicateurs -->
        <div class="ec-kpis">
          <a class="ec-kpi" href="/services">
            <span class="ec-kpi__label"><?= t('Services actifs') ?></span>
            <span class="ec-kpi__value" id="kpiServices"><?= (int) $k8s_deployments_count ?></span>
            <span class="ec-kpi__note" id="kpiServicesNote"><?= $k8s_namespace !== '' ? t('en service') : t('namespace non configuré') ?></span>
            <?= dashboardRenderWidgetErrorBadge($k8s_deployments_error_code) ?>
          </a>
          <a class="ec-kpi" href="/domaines">
            <span class="ec-kpi__label"><?= t('Domaines') ?></span>
            <span class="ec-kpi__value" id="kpiDomains"><?= (int) $k8s_ingress_domains_count ?></span>
            <span class="ec-kpi__note" id="kpiDomainsNote" title="<?= $dashH(implode(', ', $k8s_ingress_base_domains)) ?>">
              <?php if ($k8s_ingress_base_domains !== []): ?>
                <?= $dashH(implode(', ', array_slice($k8s_ingress_base_domains, 0, 2))) ?><?= count($k8s_ingress_base_domains) > 2 ? ' +' . (count($k8s_ingress_base_domains) - 2) : '' ?>
              <?php else: ?>
                <?= t('Aucun domaine relié') ?>
              <?php endif; ?>
            </span>
            <?= dashboardRenderWidgetErrorBadge($k8s_ingress_error_code) ?>
          </a>
          <a class="ec-kpi" href="<?= $dashCanOrders ? '/abonnements' : '/facture' ?>">
            <span class="ec-kpi__label"><?= t('Prochaine échéance') ?></span>
            <span class="ec-kpi__value" id="kpiNextDue">—</span>
            <span class="ec-kpi__note" id="kpiNextDueNote"><?= $dashCanOrders ? t('Chargement…') : t('Accès réservé') ?></span>
          </a>
          <div class="ec-kpi">
            <span class="ec-kpi__label"><?= t('Disponibilité annuelle') ?></span>
            <span class="ec-kpi__value"><?= $dashH($annual_availability_display) ?></span>
            <span class="ec-kpi__note"><?= t('tous services confondus') ?></span>
            <?= dashboardRenderWidgetErrorBadge($availability_error_code) ?>
          </div>
        </div>

        <div class="ec-row">
          <!-- Mes services -->
          <section class="ec-card ec-grow" aria-labelledby="t-services">
            <div class="ec-card__head">
              <h2 class="ec-h2" id="t-services"><?= t('Mes services') ?></h2>
              <a href="/services"><?= t('Tout voir') ?></a>
            </div>
            <div class="ec-table-wrap">
              <table class="ec-table" style="min-width:520px">
                <thead><tr><th scope="col"><?= t('Service') ?></th><th scope="col"><?= t('Catégorie') ?></th><th scope="col"><?= t('État') ?></th><th scope="col"><span class="ec-sr"><?= t('Action') ?></span></th></tr></thead>
                <tbody id="dashServices">
                  <tr class="ec-table__state"><td colspan="4"><?= t('Chargement…') ?></td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <div class="ec-side-col">
            <!-- Sobriété -->
            <section class="ec-impact" aria-labelledby="t-impact">
              <p class="ec-kicker"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="currentColor"></path></svg><?= t('Sobriété') ?></p>
              <h2 class="ec-h2" id="t-impact" style="margin-bottom:14px"><?= t("L'empreinte de vos services") ?></h2>
              <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
                <div><p style="margin:0;font-size:26px;line-height:32px;font-weight:800">- W</p><p style="margin:0;font-size:13px;color:#c5cbd8"><?= t('consommés en moyenne') ?></p></div>
                <div><p style="margin:0;font-size:26px;line-height:32px;font-weight:800">- g</p><p style="margin:0;font-size:13px;color:#c5cbd8"><?= t('de CO₂e ce mois-ci') ?></p></div>
              </div>
              <p style="margin:14px 0 0;font-size:13px"><a href="https://gnl-solution.com/impact" target="_blank" rel="noopener"><?= t("Comment c'est mesuré") ?></a></p>
            </section>

<?php if ($dashCanInv): ?>
            <!-- Dernières factures -->
            <section class="ec-card" aria-labelledby="t-factures">
              <div class="ec-card__head">
                <h2 class="ec-h2" id="t-factures"><?= t('Dernières factures') ?></h2>
                <a href="/facture"><?= t('Toutes') ?></a>
              </div>
              <ul class="ec-list" id="dashInvoices">
                <li class="ec-muted ec-small"><?= t('Chargement…') ?></li>
              </ul>
            </section>
<?php endif; ?>
          </div>
        </div>

        <!-- Visiteurs -->
        <section class="ec-card" aria-labelledby="t-visitors">
          <div class="ec-card__head">
            <h2 class="ec-h2" id="t-visitors"><?= t('Visiteurs par application') ?></h2>
            <span class="ec-muted ec-small">
              <?php if ($current_month_hits > 0): ?>
                <strong style="color:var(--foreground)"><?= number_format($current_month_hits, 0, ',', ' ') ?></strong> <?= t('ce mois-ci') ?>
                <?php if ($hits_pct_vs_prev !== null): ?>
                  <span class="<?= $hits_pct_vs_prev >= 0 ? 'metric-trend-up' : 'metric-trend-down' ?>">(<?= ($hits_pct_vs_prev >= 0 ? '+' : '') . $hits_pct_vs_prev ?> %)</span>
                <?php endif; ?>
                ·
              <?php endif; ?>
              <?= t('12 derniers mois') ?>
            </span>
            <?= dashboardRenderWidgetErrorBadge($visitors_error_code) ?>
          </div>
          <div class="ec-card__body" data-chart="visitors">
<?php if ($chart_datasets !== []): ?>
            <div style="height:300px">
              <canvas id="visitorsChart" aria-label="<?= t('Graphique des visiteurs par application') ?>" role="img"></canvas>
            </div>
<?php endif; ?>
            <div id="visitorsChartEmpty" class="ec-empty<?= $chart_datasets !== [] ? ' hidden' : '' ?>">
              <?= $visitors_load_failed
                  ? t('Les statistiques n\'ont pas pu être chargées (service indisponible ou trop lent). Rafraîchissez la page dans un instant.')
                  : t('Aucune donnée de visite disponible pour le moment.') ?>
            </div>
            <div id="visitorsChartLegend" class="mt-4 flex flex-wrap items-center gap-4 text-sm text-muted-foreground"></div>
          </div>
        </section>

        <div class="ec-row">
          <!-- Raccourcis -->
          <section style="flex:1 1 320px;min-width:0" aria-labelledby="t-raccourcis">
            <h2 class="ec-h2" id="t-raccourcis" style="margin-bottom:12px"><?= t('Raccourcis') ?></h2>
            <div class="ec-shortcuts">
<?php if ($dashCanDns): ?>
              <button type="button" class="ec-shortcut" data-add-domain-open><strong><?= t('Ajouter un domaine') ?></strong><span><?= t('Acheté chez nous ou ailleurs') ?></span></button>
<?php endif; ?>
<?php if ($dashCanTicket): ?>
              <a class="ec-shortcut" href="/tickets?nouveau=1"><strong><?= t('Ouvrir un ticket') ?></strong><span><?= t('Réponse sous 48 h ouvrées') ?></span></a>
<?php endif; ?>
              <a class="ec-shortcut" href="/equipes"><strong><?= t('Inviter un membre') ?></strong><span><?= t('Avec des droits choisis') ?></span></a>
<?php if ($dashCanOrders): ?>
              <a class="ec-shortcut" href="/abonnements"><strong><?= t('Moyen de paiement') ?></strong><span><?= t('Carte ou prélèvement SEPA') ?></span></a>
<?php endif; ?>
              <a class="ec-shortcut" href="/account"><strong><?= t('Sécuriser mon compte') ?></strong><span><?= t('Double authentification, clés') ?></span></a>
              <a class="ec-shortcut" href="/documentation"><strong><?= t('Documentation') ?></strong><span><?= t('Guides pas à pas') ?></span></a>
            </div>
          </section>

          <!-- Activité récente (notifications) -->
          <section class="ec-card" style="flex:1 1 320px;min-width:0" aria-labelledby="t-activite">
            <div class="ec-card__head"><h2 class="ec-h2" id="t-activite"><?= t('Activité récente') ?></h2></div>
            <ol class="ec-list" id="dashActivity">
              <li class="ec-muted ec-small"><?= t('Chargement…') ?></li>
            </ol>
          </section>
        </div>

      </div>
    </main>
  </div>

  <!-- Données du tableau de bord : services, factures, échéance, activité -->
  <script>
  (function () {
    var API   = '../data/portail_api.php';
    var I18N  = {
      none:      <?= json_encode(t('Aucun service pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      err:       <?= json_encode(t('Impossible de charger les services.'), JSON_UNESCAPED_UNICODE) ?>,
      manage:    <?= json_encode(t('Gérer'), JSON_UNESCAPED_UNICODE) ?>,
      noInv:     <?= json_encode(t('Aucune facture pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      noAct:     <?= json_encode(t('Rien de nouveau pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      noDue:     <?= json_encode(t('Aucun abonnement en cours'), JSON_UNESCAPED_UNICODE) ?>,
      pdf:       <?= json_encode(t('Télécharger la facture'), JSON_UNESCAPED_UNICODE) ?>,
      services:  <?= json_encode(t('service(s)'), JSON_UNESCAPED_UNICODE) ?>,
      online:    <?= json_encode(t('en ligne'), JSON_UNESCAPED_UNICODE) ?>,
      domains:   <?= json_encode(t('domaine(s)'), JSON_UNESCAPED_UNICODE) ?>
    };
    var CAT = { web: 'Service web', cloud: 'Service cloud', other: 'Service spécifique', vm: 'Serveur virtualisé', bm: 'Serveur dédié' };
    var canInv = <?= $dashCanInv ? 'true' : 'false' ?>, canOrders = <?= $dashCanOrders ? 'true' : 'false' ?>;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
    function getJson(url) {
      return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return null; }).then(function (d) { return { ok: r.ok && d && d.ok, data: d }; }); })
        .catch(function () { return { ok: false, data: null }; });
    }
    // Statut brut → pastille GNL
    function badge(status) {
      var s = String(status || '').toLowerCase();
      var cls = 'ec-badge', label = status || '—';
      if (s === 'active' || s === 'running')        { cls += ' ec-badge--ok';   label = 'En ligne'; }
      else if (s === 'deployment' || s === 'starting') { cls += ' ec-badge--warn'; label = 'Déploiement'; }
      else if (s === 'suspended')                   { cls += ' ec-badge--warn'; label = 'Suspendu'; }
      else if (s === 'stopped' || s === 'offline')  { label = 'Arrêté'; }
      else if (s === 'error' || s === 'crash')      { cls += ' ec-badge--danger'; label = 'Incident'; }
      return '<span class="' + cls + '">' + esc(label) + '</span>';
    }

    // Services
    var tbody = document.getElementById('dashServices');
    Promise.all([getJson('../data/services_menu_api.php'), getJson('../data/services_state_api.php')]).then(function (res) {
      var menus = res[0].ok && res[0].data.menus ? res[0].data.menus : null;
      var states = res[1].ok && res[1].data.states ? res[1].data.states : {};
      if (!menus) { tbody.innerHTML = '<tr class="ec-table__state ec-table__state--error"><td colspan="4">' + esc(I18N.err) + '</td></tr>'; return; }
      var rows = [];
      Object.keys(menus).forEach(function (k) { (menus[k] || []).forEach(function (e) { if (e && (e.name || e.slug)) rows.push({ e: e, k: k }); }); });
      var n = rows.length;
      var kpi = document.getElementById('kpiServices'); if (kpi) kpi.textContent = String(n);
      var online = rows.filter(function (r) { var st = states[r.e.uid]; return st ? (st.level === 'state' && /running/i.test(st.label || '')) : /active/i.test(r.e.status || ''); }).length;
      var kn = document.getElementById('kpiServicesNote'); if (kn) kn.textContent = online + ' ' + I18N.online;
      var sub = document.getElementById('dashSubServices'); if (sub) sub.textContent = n + ' ' + I18N.services;
      if (!n) { tbody.innerHTML = '<tr class="ec-table__state"><td colspan="4">' + esc(I18N.none) + '</td></tr>'; return; }
      tbody.innerHTML = rows.slice(0, 6).map(function (r) {
        var e = r.e, st = states[e.uid] || null;
        var status = st && st.level === 'state' ? String(st.label || '').toLowerCase() : (st && st.level ? st.level : e.status);
        var name = esc(e.name || e.slug);
        var sub = e.product_name && e.product_name !== e.name ? '<span class="ec-muted ec-small" style="display:block">' + esc(e.product_name) + '</span>' : '';
        return '<tr><td><strong style="display:block">' + name + '</strong>' + sub + '</td>' +
          '<td class="ec-muted">' + esc(CAT[r.k] || '') + '</td>' +
          '<td>' + badge(status) + '</td>' +
          '<td style="text-align:right">' + (e.href ? '<a class="ec-link" href="' + esc(e.href) + '">' + esc(I18N.manage) + '</a>' : '') + '</td></tr>';
      }).join('');
    });

    // Dernières factures
    if (canInv) {
      getJson(API + '?action=invoice.list').then(function (r) {
        var ul = document.getElementById('dashInvoices'); if (!ul) return;
        var list = r.ok && Array.isArray(r.data.invoices) ? r.data.invoices.slice() : [];
        if (!r.ok) { ul.innerHTML = '<li class="ec-muted ec-small">—</li>'; return; }
        list.sort(function (a, b) { return (b.date_ts || 0) - (a.date_ts || 0); });
        if (!list.length) { ul.innerHTML = '<li class="ec-muted ec-small">' + esc(I18N.noInv) + '</li>'; return; }
        ul.innerHTML = list.slice(0, 3).map(function (f) {
          var paid = /pay|class/i.test(f.status_label || '');
          var dl = f.has_pdf && f.download_url
            ? '<a class="ec-icon-btn" style="height:36px;min-width:36px" href="' + esc(f.download_url) + '" aria-label="' + esc(I18N.pdf + ' ' + f.ref) + '"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg></a>' : '';
          return '<li><div style="flex:1;min-width:0"><strong style="display:block;font-size:14px">' + esc(f.ref) + '</strong>' +
            '<span class="ec-muted ec-small">' + esc(f.date) + ' · ' + esc(f.total_ttc) + ' TTC</span></div>' +
            '<span class="ec-small" style="font-weight:700;color:' + (paid ? 'var(--gnl-green-ink)' : 'var(--gnl-warn)') + '">' + esc(f.status_label) + '</span>' + dl + '</li>';
        }).join('');
      });
    }

    // Prochaine échéance (abonnements en cours)
    if (canOrders) {
      getJson(API + '?action=subscription.list').then(function (r) {
        var v = document.getElementById('kpiNextDue'), note = document.getElementById('kpiNextDueNote');
        var list = r.ok && Array.isArray(r.data.subscriptions) ? r.data.subscriptions : [];
        var now = Date.now() / 1000, next = null, count = 0;
        list.forEach(function (s) {
          if (!/cours|active|open|running/i.test(String(s.status_label || s.status || ''))) return;
          count++;
          if (s.end_ts && s.end_ts >= now - 86400 && (!next || s.end_ts < next.end_ts)) next = s;
        });
        if (!r.ok) { note.textContent = '—'; return; }
        if (!next) { note.textContent = I18N.noDue; return; }
        v.textContent = new Date(next.end_ts * 1000).toLocaleDateString(document.documentElement.lang || 'fr', { day: 'numeric', month: 'short' });
        note.textContent = (next.amount ? next.amount + ' · ' : '') + count + ' abonnement(s)';
      });
    }

    // Domaines actifs (table n8n) : complète le décompte des Ingress
    <?php if ($dashCanDns): ?>
    getJson(API + '?action=domain.list').then(function (r) {
      if (!r.ok || !Array.isArray(r.data.domains)) return;
      var seen = {};
      r.data.domains.forEach(function (d) { var n = String(d.domain_buy_name || '').toLowerCase(); if (n && (d.domain_active === true || d.domain_active === 1 || d.domain_active === '1' || d.domain_active === 'true')) seen[n] = 1; });
      var n = Object.keys(seen).length, el = document.getElementById('kpiDomains');
      if (el && n > parseInt(el.textContent, 10)) {
        el.textContent = String(n);
        var sub = document.getElementById('dashSubDomains'); if (sub) sub.textContent = n + ' ' + I18N.domains;
        var note = document.getElementById('kpiDomainsNote');
        if (note) note.textContent = Object.keys(seen).slice(0, 2).join(', ') + (n > 2 ? ' +' + (n - 2) : '');
      }
    });
    <?php endif; ?>

    // Activité récente = dernières notifications
    getJson(API + '?action=notification.list').then(function (r) {
      var ol = document.getElementById('dashActivity'); if (!ol) return;
      var list = r.ok && Array.isArray(r.data.notifications) ? r.data.notifications : [];
      if (!list.length) { ol.innerHTML = '<li class="ec-muted ec-small">' + esc(I18N.noAct) + '</li>'; return; }
      ol.innerHTML = list.slice(0, 5).map(function (n) {
        var when = n.created_at ? new Date(n.created_at) : null;
        var whenTxt = when && !isNaN(when) ? when.toLocaleString(document.documentElement.lang || 'fr', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
        var txt = esc(n.title || '') + (n.title && n.message ? ' — ' : '') + esc(n.message || '');
        var body = n.link ? '<a href="' + esc(n.link) + '" style="color:inherit">' + txt + '</a>' : txt;
        return '<li style="align-items:flex-start"><span class="ec-dot" style="margin-top:7px;background:var(--gnl-green-ink)"></span>' +
          '<div><p style="margin:0;font-size:14px">' + body + '</p><p class="ec-muted ec-small" style="margin:0">' + esc(whenTxt) + '</p></div></li>';
      }).join('');
    });
  })();
  </script>

  <!-- ════════════════════════════════════════════════════════════════════════
       GRAPHIQUE — données injectées proprement par PHP
  ════════════════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const chartLabels   = <?= json_encode($chart_month_labels, JSON_UNESCAPED_UNICODE) ?>;
    const chartDatasets = <?= json_encode($chart_datasets,     JSON_UNESCAPED_UNICODE) ?>;
    const chartNames    = <?= json_encode((object)$chart_dataset_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function displayName(key) {
      return (chartNames && chartNames[key]) ? String(chartNames[key]) : String(key);
    }

    function prefersReducedMotion() {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function palette(index) {
      // Palette GNL : vert, marine, puis teintes intermédiaires lisibles.
      const colors = [
        [31,122,74],[20,26,51],[92,217,139],[94,102,132],[181,71,8],
        [50,56,94],[23,96,58],[197,203,216],[180,35,24],[35,43,82],
      ];
      return colors[index % colors.length];
    }

    function rgba(rgb, alpha) {
      return `rgba(${rgb[0]},${rgb[1]},${rgb[2]},${alpha})`;
    }

    function toggleEmptyState(hasData) {
      const empty  = document.getElementById('visitorsChartEmpty');
      const legend = document.getElementById('visitorsChartLegend');
      const canvas = document.getElementById('visitorsChart');
      if (empty)  empty.classList.toggle('hidden', hasData);
      if (legend) legend.classList.toggle('hidden', !hasData);
      if (canvas?.parentElement) canvas.parentElement.classList.toggle('hidden', !hasData);
    }

    function renderLegend(names) {
      const legend = document.getElementById('visitorsChartLegend');
      if (!legend) return;
      legend.innerHTML = '';
      names.forEach((name, i) => {
        const rgb  = palette(i);
        const item = document.createElement('div');
        item.className = 'flex items-center gap-2';
        const dot  = document.createElement('span');
        dot.className = 'h-2.5 w-2.5 rounded-full shrink-0';
        dot.style.backgroundColor = rgba(rgb, 1);
        const label = document.createElement('span');
        label.textContent = displayName(name);
        label.title = name;
        item.appendChild(dot);
        item.appendChild(label);
        legend.appendChild(item);
      });
    }

    function buildVisitorsChart() {
      const canvas = document.getElementById('visitorsChart');
      if (!canvas || !window.Chart) return null;

      const names = Object.keys(chartDatasets);

      if (names.length === 0) { toggleEmptyState(false); return null; }

      toggleEmptyState(true);
      renderLegend(names);

      const ctx         = canvas.getContext('2d');
      const h           = 320;
      const shouldFill  = names.length <= 4;
      const fillOpacity = names.length <= 4 ? 0.18 : 0.08;
      const labels      = chartLabels;

      const datasets = names.map((name, index) => {
        const rgb      = palette(index);
        const gradient = ctx.createLinearGradient(0, 0, 0, h);
        gradient.addColorStop(0, rgba(rgb, fillOpacity));
        gradient.addColorStop(1, rgba(rgb, 0));
        const data = chartDatasets[name];
        return {
          label: displayName(name),
          data,
          borderColor:               rgba(rgb, 1),
          backgroundColor:           gradient,
          pointBackgroundColor:      rgba(rgb, 1),
          pointBorderColor:          rgba(rgb, 1),
          pointHoverBackgroundColor: rgba(rgb, 1),
          pointHoverBorderColor:     rgba(rgb, 1),
          fill: shouldFill,
        };
      });

      return new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              padding: 10,
              displayColors: true,
              callbacks: {
                label: (c) => ' ' + c.dataset.label + ': ' + c.parsed.y.toLocaleString('fr-FR'),
              },
            },
          },
          elements: {
            point: { radius: 0, hoverRadius: 4, hitRadius: 12 },
            line:  { tension: 0.35, borderWidth: 2 },
          },
          scales: {
            x: {
              grid:  { display: false },
              ticks: { color: 'rgba(148,163,184,0.9)', maxRotation: 45 },
            },
            y: {
              grid:  { color: 'rgba(148,163,184,0.15)' },
              beginAtZero: true,
              ticks: {
                color: 'rgba(148,163,184,0.9)',
                callback: (v) => v.toLocaleString('fr-FR'),
              },
            },
          },
          animation: prefersReducedMotion() ? false : { duration: 900, easing: 'easeOutQuart' },
        },
      });
    }

    function init() {
      const section = document.querySelector('[data-chart="visitors"]');
      if (!section) return;
      let chartInstance = null;
      const run = () => {
        section.classList.add('is-visible');
        if (!chartInstance) chartInstance = buildVisitorsChart();
      };
      if ('IntersectionObserver' in window && !prefersReducedMotion()) {
        const io = new IntersectionObserver(
          (entries) => { if (entries.some(e => e.isIntersecting)) { run(); io.disconnect(); } },
          { threshold: 0.2 }
        );
        io.observe(section);
      } else {
        run();
      }
    }

    if (document.readyState === 'complete') init(); else window.addEventListener('load', init);
  })();
  </script>

  <!-- ════════════════════════════════════════════════════════════════════════
       Amélioration #6 — collapsible JS (même logique que les autres pages)
       À terme : extraire dans assets/js/collapsible.js et charger avec defer
  ════════════════════════════════════════════════════════════════════════ -->
  <script src="../assets/js/collapsible.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/collapsible.js') ?>"></script>

  <script>
    window.K8S_API_URL = '../data/k8s_api.php';
    window.K8S_UI_BASE = './pages/';
  </script>
  <script src="../assets/js/services_menu.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/services_menu.js') ?>" defer></script>
</body>
</html>