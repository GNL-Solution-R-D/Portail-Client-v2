<?php
session_start();

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../data/dolbar_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function projetStatusLabel($status): string
{
    $normalized = strtolower(trim((string) $status));

    $map = [
        '0' => 'Brouillon',
        '1' => 'Ouvert',
        '2' => 'Clos',
        '-1' => 'Abandonné',
        'draft' => 'Brouillon',
        'open' => 'Ouvert',
        'opened' => 'Ouvert',
        'closed' => 'Clos',
        'abandoned' => 'Abandonné',
        'canceled' => 'Abandonné',
        'cancelled' => 'Abandonné',
    ];

    return $map[$normalized] ?? ($normalized !== '' ? ucfirst($normalized) : 'Inconnu');
}

function projetStatusClass($status): string
{
    $normalized = strtolower(trim((string) $status));

    if (in_array($normalized, ['1', 'open', 'opened'], true)) {
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
    }

    if (in_array($normalized, ['2', 'closed'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }

    if (in_array($normalized, ['-1', 'abandoned', 'canceled', 'cancelled'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }

    if (in_array($normalized, ['0', 'draft'], true)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function projetDateDisplay($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (is_numeric($value)) {
        $timestamp = (int) $value;
        if ($timestamp > 0) {
            return date('d/m/Y', $timestamp);
        }
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp !== false) {
        return date('d/m/Y', $timestamp);
    }

    return '—';
}

function projetAmountDisplay($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, 2, ',', ' ') . ' €';
}

function projetExtractClientName(array $project): string
{
    $tiers = $project['tiers'] ?? null;
    $thirdparty = $project['thirdparty'] ?? null;
    $customer = $project['customer'] ?? null;

    $candidates = [
        is_array($tiers) ? ($tiers['name'] ?? null) : null,
        is_array($tiers) ? ($tiers['nom'] ?? null) : null,
        is_array($tiers) ? ($tiers['socname'] ?? null) : null,
        $project['tiers_name'] ?? null,
        $project['tiers_nom'] ?? null,
        is_string($project['tiers'] ?? null) ? $project['tiers'] : null,
        is_array($thirdparty) ? ($thirdparty['name'] ?? null) : null,
        is_array($thirdparty) ? ($thirdparty['nom'] ?? null) : null,
        $project['thirdparty_name'] ?? null,
        $project['socname'] ?? null,
        $project['societe'] ?? null,
        $project['company'] ?? null,
        is_array($customer) ? ($customer['name'] ?? null) : null,
        is_array($customer) ? ($customer['nom'] ?? null) : null,
        $project['customer_name'] ?? null,
        $project['client'] ?? null,
        $project['client_name'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if ((is_string($candidate) || is_numeric($candidate)) && trim((string) $candidate) !== '') {
            return trim((string) $candidate);
        }
    }

    return '—';
}

function projetExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'projects', 'projets'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}


$projects = [];
$projectsError = null;
$projectsErrorCode = null;

try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $_SESSION['user']);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $_SESSION['user']);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $_SESSION['user']);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $_SESSION['user']);
    $sessionToken = trim((string)($_SESSION['dolibarr_token'] ?? ''));

    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolbar incomplète (URL manquante).', 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $query = ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 100];

    if ($sessionToken !== '') {
        $rawProjects = dolbarApiCallWithToken($apiUrl, '/projects', $sessionToken, 'GET', $query, [], 12);
    } elseif ($login !== null && $password !== null) {
        $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
        $rawProjects = dolbarApiCallWithToken($apiUrl, '/projects', $token, 'GET', $query, [], 12);
    } elseif ($apiKey !== null) {
        $rawProjects = dolbarApiCall($apiUrl, '/projects', $apiKey, 'GET', $query, [], 12);
    } else {
        throw new RuntimeException(
            'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
            0
        );
    }

    $projects = array_values(array_filter(
        projetExtractRows($rawProjects),
        static fn($row): bool => is_array($row)
    ));
} catch (Throwable $e) {
    $projectsError = $e->getMessage();
    $projectsErrorCode = dolbarApiExtractErrorCode($e) ?? 'DLB';
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Mes projets - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>

  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}

    .projects-table-wrap{overflow:auto;}
    .projects-table{width:100%;border-collapse:separate;border-spacing:0;min-width:860px;}
    .projects-table th,.projects-table td{padding:0.9rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.92rem;white-space:nowrap;}
    .projects-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;color:var(--muted-foreground, #64748b);text-align:left;}
    .projects-table tbody tr:hover{background:rgba(148,163,184,.08);}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.2rem .6rem;font-size:.75rem;font-weight:600;}

    .collapsible-content {overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open {opacity:1;}
    .collapsible-trigger .collapsible-chevron {transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {transform:rotate(90deg);}

    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">Mes projets</h1>
              <p class="text-sm text-muted-foreground mt-1">Suivi des projets synchronisés depuis Dolbar.</p>
            </div>
            <span class="text-sm text-muted-foreground"><?php echo count($projects); ?> projet(s)</span>
          </div>

          <?php if ($projectsError !== null): ?>
            <div class="mx-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 ml-8 mr-8">
              Impossible de charger les projets (code: <?php echo h($projectsErrorCode); ?>). <?php echo h($projectsError); ?>
            </div>
          <?php elseif (empty($projects)): ?>
            <div class="mx-6 rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
              Aucun projet trouvé pour le moment.
            </div>
          <?php else: ?>
            <div class="projects-table-wrap px-2 md:px-6">
              <table class="projects-table">
                <thead>
                  <tr>
                    <th>Référence</th>
                    <th>Projet</th>
                    <th>Client</th>
                    <th>Statut</th>
                    <th>Date début</th>
                    <th>Date fin</th>
                    <th>Budget</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($projects as $project): ?>
                  <?php
                    $reference = $project['ref'] ?? ('PRJ-' . (int)($project['id'] ?? 0));
                    $label = $project['title'] ?? $project['label'] ?? $project['name'] ?? '—';
                    $thirdparty = projetExtractClientName($project);
                    $statusRaw = $project['statut'] ?? $project['status'] ?? $project['fk_statut'] ?? '';
                    $statusLabel = projetStatusLabel($statusRaw);
                    $statusClass = projetStatusClass($statusRaw);
                    $dateStart = $project['dateo'] ?? $project['date_start'] ?? $project['date_debut'] ?? null;
                    $dateEnd = $project['datee'] ?? $project['date_end'] ?? $project['date_fin'] ?? null;
                    $budget = $project['budget_amount'] ?? $project['budget'] ?? $project['budget_ht'] ?? null;
                  ?>
                  <tr>
                    <td class="font-medium"><?php echo h($reference); ?></td>
                    <td><?php echo h($label); ?></td>
                    <td><?php echo h($thirdparty); ?></td>
                    <td>
                      <span class="badge <?php echo h($statusClass); ?>"><?php echo h($statusLabel); ?></span>
                    </td>
                    <td><?php echo h(projetDateDisplay($dateStart)); ?></td>
                    <td><?php echo h(projetDateDisplay($dateEnd)); ?></td>
                    <td><?php echo h(projetAmountDisplay($budget)); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <script>
  (function () {
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
      var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
      triggers.forEach(function (btn) {
        btn.classList.add('collapsible-trigger');
        var targetId = btn.getAttribute('aria-controls');
        var content = targetId ? document.getElementById(targetId) : null;
        if (!content) {
          var parent = btn.closest('[data-slot="collapsible"]');
          if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
        }
        if (!content) return;

        content.classList.add('collapsible-content');
        var chev = btn.querySelector('.lucide-chevron-right');
        if (chev) chev.classList.add('collapsible-chevron');

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

        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var isOpen = btn.getAttribute('aria-expanded') === 'true';

          if (!isOpen) {
            btn.setAttribute('aria-expanded', 'true');
            content.hidden = false;
            content.classList.add('is-open');
            content.style.height = '0px';
            var h = content.scrollHeight;
            requestAnimationFrame(function () { content.style.height = h + 'px'; });
            content.addEventListener('transitionend', function onEnd(ev) {
              if (ev.propertyName !== 'height') return;
              content.style.height = 'auto';
              content.removeEventListener('transitionend', onEnd);
            });
          } else {
            btn.setAttribute('aria-expanded', 'false');
            content.classList.remove('is-open');
            var current = content.scrollHeight;
            content.style.height = current + 'px';
            requestAnimationFrame(function () { content.style.height = '0px'; });
            content.addEventListener('transitionend', function onEndClose(ev) {
              if (ev.propertyName !== 'height') return;
              content.hidden = true;
              content.removeEventListener('transitionend', onEndClose);
            });
          }
        }, { passive: false });
      });
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>
