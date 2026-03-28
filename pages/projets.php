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
    $timestamp = dolbarApiDateToTimestamp($value);
    if ($timestamp !== null) {
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
    $thirdparty = $project['thirdparty'] ?? null;

    $candidates = [
        is_array($thirdparty) ? ($thirdparty['name'] ?? null) : null,
        $project['socname'] ?? null,
        $project['thirdparty_name'] ?? null,
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

function projetNormalizeTagValue($value): string
{
    if (!(is_string($value) || is_numeric($value))) {
        return '';
    }

    return trim((string) $value);
}

function projetIsDeploymentParentLabel(string $label): bool
{
    $normalized = strtolower(trim($label));
    return in_array($normalized, ['deploiment', 'deployment'], true);
}

function projetExtractObjectIdsFromCategoryObjectsPayload($payload): array
{
    $rows = is_array($payload) ? projetExtractRows($payload) : [];
    if ($rows === [] && is_array($payload)) {
        $rows = $payload;
    }

    $ids = [];
    foreach ($rows as $row) {
        if (is_numeric($row)) {
            $id = (int) $row;
            if ($id > 0) {
                $ids[] = $id;
            }
            continue;
        }
        if (!is_array($row)) {
            continue;
        }
        foreach (['id', 'rowid', 'fk_project', 'project_id'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                $id = (int) $row[$key];
                if ($id > 0) {
                    $ids[] = $id;
                    break;
                }
            }
        }
    }

    return array_values(array_unique($ids));
}

function projetBuildProjectTagsById(array $projects, callable $dolibarrRequest): array
{
    $projectSubtags = [];
    $projectAllTags = [];
    $projectIds = [];

    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }
        $projectIds[$projectId] = true;
    }

    if ($projectIds === []) {
        return ['deploymentSubtags' => [], 'allTags' => []];
    }

    try {
        $rawCategories = $dolibarrRequest('/categories', ['type' => 'project', 'limit' => 1000, 'sortfield' => 't.rowid']);
    } catch (Throwable $e) {
        return ['deploymentSubtags' => [], 'allTags' => []];
    }

    $categoryRows = is_array($rawCategories) ? projetExtractRows($rawCategories) : [];
    if ($categoryRows === [] && is_array($rawCategories)) {
        $categoryRows = $rawCategories;
    }

    $categoriesById = [];
    foreach ($categoryRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $categoryId = (int)($row['id'] ?? $row['rowid'] ?? 0);
        if ($categoryId <= 0) {
            continue;
        }
        $categoriesById[$categoryId] = [
            'label' => projetNormalizeTagValue($row['label'] ?? $row['name'] ?? null),
            'parent_id' => (int)($row['fk_parent'] ?? $row['parent'] ?? 0),
        ];
    }

    $projectTags = [];
    foreach ($categoriesById as $categoryId => $categoryMeta) {
        if ($categoryMeta['label'] === '') {
            continue;
        }

        $objectIds = [];
        // Source principale (documentée dans API projets): filtrer les projets par catégorie.
        try {
            $rawProjectsForCategory = $dolibarrRequest('/projects', [
                'category' => $categoryId,
                'limit' => 1000,
                'sortfield' => 't.rowid',
                'sortorder' => 'ASC',
            ]);
            $rowsForCategory = is_array($rawProjectsForCategory) ? projetExtractRows($rawProjectsForCategory) : [];
            if ($rowsForCategory === [] && is_array($rawProjectsForCategory)) {
                $rowsForCategory = $rawProjectsForCategory;
            }
            foreach ($rowsForCategory as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $projectIdFromCategory = (int)($row['id'] ?? $row['rowid'] ?? 0);
                if ($projectIdFromCategory > 0) {
                    $objectIds[] = $projectIdFromCategory;
                }
            }
        } catch (Throwable $e) {
            // fallback ci-dessous
        }

        // Fallback pour compatibilité (selon versions/permissions API).
        if ($objectIds === []) {
            $candidateRoutes = [
                ['/categories/' . $categoryId . '/objects', ['type' => 'project']],
                ['/categories/' . $categoryId . '/objects/project', []],
            ];
            foreach ($candidateRoutes as [$route, $params]) {
                try {
                    $rawObjects = $dolibarrRequest($route, $params);
                    $objectIds = projetExtractObjectIdsFromCategoryObjectsPayload($rawObjects);
                    if ($objectIds !== []) {
                        break;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        foreach (array_values(array_unique($objectIds)) as $objectId) {
            if (!isset($projectIds[$objectId])) {
                continue;
            }
            $projectTags[$objectId][] = $categoryId;
        }
    }

    foreach ($projectTags as $projectId => $categoryIds) {
        $allTags = [];
        $subtags = [];
        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $meta = $categoriesById[$categoryId] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            $label = $meta['label'];
            if ($label !== '') {
                $allTags[] = $label;
            }

            $parentId = (int)$meta['parent_id'];
            if ($parentId <= 0) {
                continue;
            }
            $parentMeta = $categoriesById[$parentId] ?? null;
            if (!is_array($parentMeta)) {
                continue;
            }
            if (projetIsDeploymentParentLabel((string)$parentMeta['label']) && !projetIsDeploymentParentLabel($label)) {
                $subtags[] = $label;
            }
        }

        $allTags = array_values(array_unique(array_filter($allTags, static fn($v): bool => $v !== '')));
        if ($allTags !== []) {
            $projectAllTags[(int)$projectId] = implode(', ', $allTags);
        }

        $subtags = array_values(array_unique(array_filter($subtags, static fn($v): bool => $v !== '')));
        if ($subtags !== []) {
            $projectSubtags[(int)$projectId] = implode(', ', $subtags);
        }
    }

    return [
        'deploymentSubtags' => $projectSubtags,
        'allTags' => $projectAllTags,
    ];
}


$projects = [];
$projectsError = null;
$projectsErrorCode = null;
$projectDeploymentSubtags = [];
$projectAllTags = [];

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

    $loginToken = null;
    $requestDolibarr = static function (string $path, array $params = []) use (
        $apiUrl,
        $sessionToken,
        $login,
        $password,
        $apiKey,
        &$loginToken
    ) {
        if ($sessionToken !== '') {
            return dolbarApiCallWithToken($apiUrl, $path, $sessionToken, 'GET', $params, [], 12);
        }
        if ($login !== null && $password !== null) {
            if ($loginToken === null) {
                $loginToken = dolbarApiLoginToken($apiUrl, $login, $password, 8);
            }
            return dolbarApiCallWithToken($apiUrl, $path, $loginToken, 'GET', $params, [], 12);
        }
        if ($apiKey !== null) {
            return dolbarApiCall($apiUrl, $path, $apiKey, 'GET', $params, [], 12);
        }

        throw new RuntimeException(
            'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
            0
        );
    };

    $rawProjects = $requestDolibarr('/projects', $query);

    $projects = array_values(array_filter(
        projetExtractRows($rawProjects),
        static fn($row): bool => is_array($row)
    ));

    $projectTagsById = projetBuildProjectTagsById($projects, $requestDolibarr);
    $projectDeploymentSubtags = is_array($projectTagsById['deploymentSubtags'] ?? null) ? $projectTagsById['deploymentSubtags'] : [];
    $projectAllTags = is_array($projectTagsById['allTags'] ?? null) ? $projectTagsById['allTags'] : [];
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
                    <th>Déploiement</th>
                    <th>Tous les tags</th>
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
                    $statusRaw = $project['statut'] ?? $project['status'] ?? $project['fk_statut'] ?? '';
                    $statusLabel = projetStatusLabel($statusRaw);
                    $statusClass = projetStatusClass($statusRaw);
                    $dateStart = $project['dateo'] ?? $project['date_start'] ?? $project['date_debut'] ?? null;
                    $dateEnd = $project['datee'] ?? $project['date_end'] ?? $project['date_fin'] ?? null;
                    $budget = $project['budget_amount'] ?? $project['budget'] ?? $project['budget_ht'] ?? null;
                    $projectId = (int) ($project['id'] ?? 0);
                    $deploymentSubtag = $projectDeploymentSubtags[$projectId] ?? '—';
                    $allTags = $projectAllTags[$projectId] ?? '—';
                  ?>
                  <tr>
                    <td class="font-medium"><?php echo h($reference); ?></td>
                    <td><?php echo h($label); ?></td>
                    <td><?php echo h($deploymentSubtag); ?></td>
                    <td><?php echo h($allTags); ?></td>
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
