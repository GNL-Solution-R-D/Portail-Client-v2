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

function documentationExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'records', 'knowledgerecords', 'knowledgebase'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

function documentationFirstValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];
        if (is_string($value) || is_numeric($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return '';
}

function documentationDateDisplay(array $row): string
{
    foreach (['date_modification', 'date_update', 'tms', 'date_creation', 'datec', 'date'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $timestamp = dolbarApiDateToTimestamp($row[$key]);
        if ($timestamp !== null) {
            return date('d/m/Y', $timestamp);
        }
    }

    return '—';
}

function documentationPlainText(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped = strip_tags($decoded);
    $normalized = preg_replace('/\s+/u', ' ', $stripped);

    return trim((string) $normalized);
}

function documentationHtmlToDisplay(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $allowed = '<p><br><ul><ol><li><strong><b><em><i><u><a><code><pre><blockquote>';
    $safe = strip_tags($decoded, $allowed);

    return trim($safe);
}

$articles = [];
$articlesError = null;
$articlesErrorCode = null;

try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $_SESSION['user']);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $_SESSION['user']);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $_SESSION['user']);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $_SESSION['user']);
    $sessionToken = trim((string) ($_SESSION['dolibarr_token'] ?? ''));

    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolibarr incomplète (URL manquante).', 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);

    $query = [
        'sortfield' => 't.rowid',
        'sortorder' => 'DESC',
        'limit' => 200,
    ];

    $endpoints = [
        '/knowledgemanagement',
        '/knowledgemanagement/knowledgerecords',
        '/knowledgemanagement/records',
        '/knowledgebase/records',
        '/knowledgerecords',
    ];

    $doRequest = static function (string $endpoint) use ($apiUrl, $sessionToken, $login, $password, $apiKey, $query): array {
        if ($sessionToken !== '') {
            return dolbarApiCallWithToken($apiUrl, $endpoint, $sessionToken, 'GET', $query, [], 12);
        }

        if ($login !== null && $password !== null) {
            $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
            return dolbarApiCallWithToken($apiUrl, $endpoint, $token, 'GET', $query, [], 12);
        }

        if ($apiKey !== null) {
            return dolbarApiCall($apiUrl, $endpoint, $apiKey, 'GET', $query, [], 12);
        }

        throw new RuntimeException(
            'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
            0
        );
    };

    $lastError = null;
    $rows = [];

    foreach ($endpoints as $endpoint) {
        try {
            $payload = $doRequest($endpoint);
            $rows = documentationExtractRows($payload);
            if ($rows !== []) {
                break;
            }
        } catch (Throwable $endpointError) {
            $lastError = $endpointError;
        }
    }

    if ($rows === [] && $lastError !== null) {
        throw $lastError;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int) documentationFirstValue($row, ['id', 'rowid']);
        $title = documentationFirstValue($row, ['question', 'title', 'label', 'name', 'ref']);
        $category = documentationFirstValue($row, ['category', 'category_label', 'type_label', 'type']);

        $summaryRaw = documentationFirstValue($row, ['question', 'description', 'summary', 'note_public', 'note', 'content']);
        $contentRaw = documentationFirstValue($row, ['answer', 'content', 'description', 'body', 'note_public', 'note']);

        $summary = documentationPlainText($summaryRaw);
        $content = documentationPlainText($contentRaw);
        $contentHtml = documentationHtmlToDisplay($contentRaw);

        if ($summary === '' && $content !== '') {
            $summary = mb_substr($content, 0, 180);
            if (mb_strlen($content) > 180) {
                $summary .= '…';
            }
        }

        $articles[] = [
            'id' => $id,
            'title' => $title !== '' ? $title : 'Article sans titre',
            'category' => $category !== '' ? $category : 'Général',
            'summary' => $summary,
            'content' => $content,
            'content_html' => $contentHtml,
            'updated_at' => documentationDateDisplay($row),
        ];
    }
} catch (Throwable $e) {
    $articlesError = $e->getMessage();
    $articlesErrorCode = dolbarApiExtractErrorCode($e) ?? 'DLB';
}

usort(
    $articles,
    static fn(array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title'])
);

$articlesCount = count($articles);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Documentation - GNL Solution</title>
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

    .docs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;}
    .doc-item{display:flex;flex-direction:column;gap:.65rem;border:1px solid rgba(148,163,184,.25);border-radius:.85rem;padding:1rem;background:rgba(255,255,255,.03);}
    .doc-item h3{font-size:1rem;line-height:1.35;margin:0;}
    .doc-item p{margin:0;font-size:.9rem;color:var(--muted-foreground,#64748b);line-height:1.5;}
    .doc-meta{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;font-size:.78rem;color:var(--muted-foreground,#64748b);}
    .doc-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:999px;font-size:.72rem;font-weight:700;background:rgba(59,130,246,.12);color:rgb(96,165,250);}
    .doc-content{white-space:pre-line;font-size:.9rem;color:var(--foreground,#e2e8f0);line-height:1.6;}
    .doc-item.is-hidden{display:none;}

    .search-wrap{position:relative;max-width:520px;}
    .search-input{width:100%;border:1px solid rgba(148,163,184,.28);border-radius:.7rem;background:transparent;padding:.6rem .85rem;font-size:.92rem;outline:none;}
    .search-input:focus{border-color:rgba(96,165,250,.65);box-shadow:0 0 0 3px rgba(59,130,246,.22);}

    .empty-state{border:1px dashed rgba(148,163,184,.36);border-radius:.8rem;padding:1.4rem;text-align:center;color:var(--muted-foreground,#64748b);font-size:.92rem;}

    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}

    @media (max-width:1200px){
      .docs-grid{grid-template-columns:1fr;}
    }

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
    <aside class="dashboard-sidebar">
      <?php includeIsolated('../include/menu.php', ['k8s_ingress_base_domains' => $k8s_ingress_base_domains ?? []]); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">Documentation</h1>
              <p class="text-sm text-muted-foreground mt-1">Base de connaissance synchronisée depuis votre API Dolibarr.</p>
            </div>
            <span class="text-sm text-muted-foreground" id="doc-count"><?php echo h((string) $articlesCount); ?> article(s)</span>
          </div>

          <?php if ($articlesError !== null): ?>
            <div class="mx-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 ml-8 mr-8">
              Impossible de charger la documentation (code: <?php echo h($articlesErrorCode); ?>). <?php echo h($articlesError); ?>
            </div>
          <?php else: ?>
            <div class="px-6 pb-1">
              <label class="sr-only" for="docs-search">Rechercher un article</label>
              <div class="search-wrap">
                <input id="docs-search" class="search-input" type="search" placeholder="Rechercher dans la documentation (titre, catégorie, contenu)…" autocomplete="off"/>
              </div>
            </div>

            <?php if (empty($articles)): ?>
              <div class="mx-6 mb-2 empty-state">
                Aucun article trouvé dans votre base de connaissance Dolibarr.
              </div>
            <?php else: ?>
              <div class="docs-grid px-6 pb-1" id="docs-list">
                <?php foreach ($articles as $article): ?>
                  <?php $searchIndex = mb_strtolower($article['title'] . ' ' . $article['category'] . ' ' . $article['summary'] . ' ' . $article['content']); ?>
                  <article class="doc-item" data-doc-search="<?php echo h($searchIndex); ?>">
                    <div class="doc-meta">
                      <span class="doc-badge"><?php echo h($article['category']); ?></span>
                      <span>Mise à jour : <?php echo h($article['updated_at']); ?></span>
                    </div>
                    <h3>❓ <?php echo h($article['title']); ?></h3>
                    <?php if ($article['summary'] !== ''): ?>
                      <p><?php echo h($article['summary']); ?></p>
                    <?php endif; ?>
                    <?php if ($article['content'] !== '' || $article['content_html'] !== ''): ?>
                      <details>
                        <summary class="cursor-pointer text-sm font-semibold text-blue-400">Voir la solution</summary>
                        <?php if ($article['content_html'] !== ''): ?>
                          <div class="doc-content mt-3"><?php echo $article['content_html']; ?></div>
                        <?php else: ?>
                          <div class="doc-content mt-3"><?php echo nl2br(h($article['content'])); ?></div>
                        <?php endif; ?>
                      </details>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>

              <div class="mx-6 mb-2 empty-state" id="docs-empty-search" hidden>
                Aucun résultat pour votre recherche.
              </div>
            <?php endif; ?>
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

      var searchInput = document.getElementById('docs-search');
      var items = Array.prototype.slice.call(document.querySelectorAll('.doc-item'));
      var count = document.getElementById('doc-count');
      var emptySearch = document.getElementById('docs-empty-search');

      if (!searchInput || items.length === 0) {
        return;
      }

      function applyFilter() {
        var raw = (searchInput.value || '').toLowerCase().trim();
        var visible = 0;

        items.forEach(function (item) {
          var index = (item.getAttribute('data-doc-search') || '').toLowerCase();
          var match = raw === '' || index.indexOf(raw) !== -1;
          item.classList.toggle('is-hidden', !match);
          if (match) {
            visible += 1;
          }
        });

        if (count) {
          count.textContent = visible + ' article(s)';
        }

        if (emptySearch) {
          emptySearch.hidden = visible !== 0;
        }
      }

      searchInput.addEventListener('input', applyFilter, { passive: true });
      applyFilter();
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
