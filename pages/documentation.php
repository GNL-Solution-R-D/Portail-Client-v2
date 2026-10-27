<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── Barre de recherche du header ──────────────────────────────────────────────
// On active la barre du header.php et on personnalise son libellé. Le JS de cette
// page se branche dessus via son id (globalSearchInput) pour filtrer la liste.
$showSearch        = false; // la recherche est dans le bandeau de la page
$searchPlaceholder = t('Rechercher dans la documentation…');

// Chaînes traduites passées au JS (le rendu des cartes se fait côté client).
$docI18n = [
    'updated'    => t('Mis à jour le'),
    'articles'   => t('article(s)'),
    'all'        => t('Tous les articles'),
    'load_error' => t('Impossible de charger la documentation'),
    'thanks'     => t('Merci pour votre retour.'),
    'sorry'      => t('Merci. Notre équipe peut vous aider directement :'),
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Documentation - GNL Solution') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="theme-color" content="#141a33"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <link rel="stylesheet" href="../assets/styles/gnl-portail.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/styles/gnl-portail.css') ?>"/>
  <link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin/>
  <link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32"/>
  <style>
    /* ── Documentation : bandeau marine + recherche, onglets de catégorie collés,
          liste d'articles à gauche, lecture à droite (maquette « Documentation »). */
    .doc-hero{padding:28px;border-radius:3px 3px 0 0;background:#141a33;color:#fff;display:flex;flex-direction:column;gap:14px}
    .doc-kicker{margin:0;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;letter-spacing:.2em;color:#5cd98b;text-transform:uppercase}
    .doc-hero h1{margin:0;font-size:30px;line-height:36px;font-weight:800;color:#fff}
    .doc-search{display:flex;align-items:center;gap:10px;max-width:640px;min-height:50px;padding:0 14px;border-radius:3px;background:#fff;color:#5e6684}
    .doc-search:focus-within{box-shadow:0 0 0 3px #5cd98b}
    .doc-search input{flex:1;min-width:0;border:0;outline:0;font:400 16px Manrope,system-ui,sans-serif;color:#141a33;background:transparent}
    .doc-cats{display:flex;flex-wrap:wrap;border-radius:0 0 3px 3px;overflow:hidden;background:#fff;border:1px solid #e3e6ee;border-top:0}
    .doc-cats button{flex:1 1 140px;min-height:52px;padding:0 14px;border:0;border-right:1px solid #e3e6ee;border-radius:0;background:#fff;color:#141a33;font:700 14px Manrope,system-ui,sans-serif;white-space:nowrap;cursor:pointer}
    .doc-cats button:last-child{border-right:0}
    .doc-cats button:hover{background:#f4f6fa}
    .doc-cats button[aria-selected="true"]{background:#1f7a4a;color:#fff}
    .doc-cats button span{font-weight:500;opacity:.75;margin-left:4px}
    .doc-body{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin-top:24px}
    .doc-list{flex:1 1 300px;min-width:0;border:1px solid #e3e6ee;border-radius:3px;background:#fff}
    .doc-list h2{margin:0;padding:16px 20px;font-size:16px;font-weight:800;border-bottom:1px solid #e3e6ee;display:flex;justify-content:space-between;gap:12px}
    .doc-list h2 small{font-size:13px;font-weight:500;color:#5e6684}
    .doc-list ul{margin:0;padding:8px;list-style:none;display:flex;flex-direction:column;gap:4px;max-height:640px;overflow:auto}
    .doc-list li button{width:100%;display:flex;flex-direction:column;gap:2px;padding:12px;border:1px solid #e3e6ee;border-radius:3px;background:#fff;color:#141a33;font:400 13px/19px Manrope,system-ui,sans-serif;text-align:left;cursor:pointer}
    .doc-list li button:hover{border-color:#141a33}
    .doc-list li button[aria-pressed="true"]{border-color:#1f7a4a;background:#e9f6ef}
    .doc-list li strong{font-size:15px;line-height:21px;font-weight:700}
    .doc-list li span{color:#5e6684}
    .doc-read{flex:999 1 520px;min-width:0;padding:28px 32px;border:1px solid #e3e6ee;border-radius:3px;background:#fff}
    .doc-read .meta{margin:0 0 6px;font-size:13px;color:#5e6684}
    .doc-read h2{margin:0 0 14px;font-size:26px;line-height:32px;font-weight:800}
    .doc-read .intro{margin:0 0 18px;font-size:16px;line-height:26px;color:#5e6684}
    .doc-content{font-size:15px;line-height:24px;color:#141a33;overflow-wrap:anywhere}
    .doc-content p{margin:0 0 14px}
    .doc-content h1,.doc-content h2,.doc-content h3,.doc-content h4{margin:22px 0 10px;font-size:18px;line-height:24px;font-weight:800}
    .doc-content ul{margin:0 0 14px;padding-left:20px}
    .doc-content ol{margin:0 0 18px;padding:0;list-style:none;counter-reset:st;display:flex;flex-direction:column;gap:14px}
    .doc-content ol>li{counter-increment:st;display:flex;gap:14px}
    .doc-content ol>li::before{content:counter(st);display:grid;place-items:center;width:28px;height:28px;flex:none;border-radius:50%;background:#141a33;color:#fff;font-size:13px;font-weight:800}
    .doc-content pre,.doc-content code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
    .doc-content pre{margin:0 0 20px;padding:14px 16px;border-radius:3px;background:#141a33;color:#c5cbd8;overflow-x:auto;line-height:21px}
    .doc-content :not(pre)>code{padding:1px 5px;border-radius:3px;background:#f4f6fa}
    .doc-content a{color:#1f7a4a;font-weight:700}
    .doc-content img{max-width:100%;height:auto;border-radius:3px}
    .doc-content table{width:100%;border-collapse:collapse;margin:0 0 16px;font-size:14px}
    .doc-content th,.doc-content td{padding:8px 10px;border:1px solid #e3e6ee;text-align:left}
    .doc-feedback{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;margin-top:22px;padding-top:18px;border-top:1px solid #e3e6ee}
    .doc-feedback p{margin:0;font-size:14px;font-weight:700}
    .doc-feedback button{min-height:40px;padding:0 16px;border:1px solid #c5cbd8;border-radius:3px;background:#fff;color:#141a33;font:700 14px Manrope,system-ui,sans-serif;cursor:pointer}
    .doc-feedback button:hover{border-color:#141a33}
    .doc-feedback a{margin-left:auto;font-size:14px;font-weight:700;color:#1f7a4a}
    .doc-state{margin:0;padding:24px 20px;color:#5e6684;font-size:14px}
    .doc-error{margin:24px 0 0;padding:12px 16px;border-radius:3px;border:1px solid #fecdca;background:#fef3f2;color:#b42318;font-size:14px}
    @media (max-width:640px){
      .doc-hero{padding:22px 18px}.doc-hero h1{font-size:24px;line-height:30px}
      .doc-cats button{flex-basis:50%;border-bottom:1px solid #e3e6ee}
      .doc-read{padding:22px 18px}.doc-read h2{font-size:22px;line-height:28px}
      .doc-feedback a{margin-left:0}
    }
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

        <div>
          <div class="doc-hero">
            <p class="doc-kicker"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><?= t('Documentation') ?></p>
            <h1><?= t('Comment pouvons-nous vous aider ?') ?></h1>
            <label class="doc-search">
              <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
              <span class="sr-only"><?= t('Rechercher dans la documentation') ?></span>
              <input id="docs-search" type="search" autocomplete="off"
                     placeholder="<?= h(t('Rechercher un article : DNS, sauvegarde, facture, SSH…')) ?>"/>
            </label>
          </div>
          <div class="doc-cats" role="tablist" aria-label="<?= h(t('Catégories')) ?>" id="docs-cats"></div>
        </div>

        <div class="doc-error" id="docs-error" hidden></div>

        <div class="doc-body" id="docs-body">
          <section class="doc-list" aria-labelledby="docs-list-title">
            <h2 id="docs-list-title"><span id="docs-cat-label"><?= t('Tous les articles') ?></span><small id="doc-count"></small></h2>
            <p class="doc-state" id="docs-loading"><?= t('Chargement…') ?></p>
            <p class="doc-state" id="docs-empty" hidden><?= t('Aucun article dans votre base de connaissance pour le moment.') ?></p>
            <p class="doc-state" id="docs-empty-search" hidden><?= t('Aucun résultat pour votre recherche.') ?></p>
            <ul id="docs-list"></ul>
          </section>

          <article class="doc-read" id="docs-read" aria-labelledby="docs-read-title" hidden>
            <p class="meta" id="docs-read-meta"></p>
            <h2 id="docs-read-title"></h2>
            <p class="intro" id="docs-read-intro"></p>
            <div class="doc-content" id="docs-read-content"></div>
            <div class="doc-feedback" id="docs-feedback">
              <p id="docs-feedback-q"><?= t('Cet article vous a-t-il aidé ?') ?></p>
              <button type="button" data-fb="yes"><?= t('Oui') ?></button>
              <button type="button" data-fb="no"><?= t('Non') ?></button>
              <a id="docs-ticket" href="/tickets?nouveau=1"><?= t('Toujours bloqué ? Ouvrir un ticket') ?></a>
            </div>
          </article>
        </div>

      </div>
    </main>
  </div>

  <script>
    window.DOC_API  = "../data/portail_api.php";
    window.DOC_I18N = <?php echo json_encode($docI18n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  </script>

  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
      var API  = window.DOC_API || '../data/portail_api.php';
      var I18N = window.DOC_I18N || {};
      function tr(k, d){ return I18N[k] || d; }
      function $(id){ return document.getElementById(id); }
      function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
      }
      function nl2br(s){ return esc(s).replace(/\n/g, '<br>'); }

      var catsEl = $('docs-cats'), listEl = $('docs-list'), countEl = $('doc-count'),
          readEl = $('docs-read'), searchEl = $('docs-search');

      var all = [], cats = [], cat = '', query = '', currentId = null;

      function keyOf(a, i){ return a.id ? String(a.id) : 'i' + i; }

      function filtered() {
        var q = query.toLowerCase().trim();
        return all.filter(function (a) {
          if (cat && a.category !== cat) return false;
          if (!q) return true;
          return (a.title + ' ' + a.category + ' ' + a.summary + ' ' + a.content).toLowerCase().indexOf(q) !== -1;
        });
      }

      function renderCats() {
        var counts = {};
        all.forEach(function (a) { counts[a.category] = (counts[a.category] || 0) + 1; });
        var items = [{ id: '', label: tr('all', 'Tous les articles'), n: all.length }]
          .concat(cats.map(function (c) { return { id: c, label: c, n: counts[c] }; }));
        catsEl.innerHTML = items.map(function (c) {
          return '<button type="button" role="tab" aria-selected="' + (c.id === cat) + '" data-cat="' + esc(c.id) + '">'
            + esc(c.label) + '<span>' + c.n + '</span></button>';
        }).join('');
        catsEl.hidden = cats.length < 1;
      }

      function show(a) {
        if (!a) { readEl.hidden = true; return; }
        currentId = a._key;
        $('docs-read-meta').textContent = a.category + (a.updated_at ? ' · ' + tr('updated', 'Mis à jour le') + ' ' + a.updated_at : '');
        $('docs-read-title').textContent = a.title;
        var intro = a.summary && a.content && a.content.indexOf(a.summary.replace(/…$/, '')) === 0 ? '' : a.summary;
        $('docs-read-intro').textContent = intro || '';
        $('docs-read-intro').hidden = !intro;
        // content_html est filtré côté serveur (liste blanche de balises).
        $('docs-read-content').innerHTML = a.content_html ? a.content_html : nl2br(a.content);
        $('docs-ticket').href = '/tickets?nouveau=1&service=' + encodeURIComponent(a.title);
        $('docs-feedback-q').textContent = <?php echo json_encode(t('Cet article vous a-t-il aidé ?'), JSON_UNESCAPED_UNICODE); ?>;
        Array.prototype.forEach.call(readEl.querySelectorAll('[data-fb]'), function (b) { b.hidden = false; });
        readEl.hidden = false;
        Array.prototype.forEach.call(listEl.querySelectorAll('button'), function (b) {
          b.setAttribute('aria-pressed', String(b.getAttribute('data-key') === currentId));
        });
        if (history.replaceState) history.replaceState(null, '', '#article-' + a._key);
      }

      function render() {
        var list = filtered();
        $('docs-cat-label').textContent = cat || tr('all', 'Tous les articles');
        countEl.textContent = list.length + ' ' + tr('articles', 'article(s)');
        $('docs-empty').hidden = all.length !== 0;
        $('docs-empty-search').hidden = !(all.length && !list.length);
        listEl.innerHTML = list.map(function (a) {
          return '<li><button type="button" data-key="' + esc(a._key) + '" aria-pressed="' + (a._key === currentId) + '">'
            + '<strong>' + esc(a.title) + '</strong>'
            + '<span>' + esc(cat ? (a.updated_at || '') : a.category + (a.updated_at ? ' · ' + a.updated_at : '')) + '</span>'
            + '</button></li>';
        }).join('');
        var cur = list.filter(function (a) { return a._key === currentId; })[0];
        show(cur || list[0]);
      }

      catsEl.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-cat]'); if (!b) return;
        cat = b.getAttribute('data-cat');
        Array.prototype.forEach.call(catsEl.children, function (x) { x.setAttribute('aria-selected', String(x === b)); });
        render();
      });
      listEl.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-key]'); if (!b) return;
        var k = b.getAttribute('data-key');
        show(all.filter(function (a) { return a._key === k; })[0]);
        if (window.matchMedia('(max-width: 900px)').matches) readEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      searchEl.addEventListener('input', function () { query = searchEl.value || ''; render(); });
      $('docs-feedback').addEventListener('click', function (e) {
        var b = e.target.closest('[data-fb]'); if (!b) return;
        $('docs-feedback-q').textContent = b.getAttribute('data-fb') === 'yes' ? tr('thanks', 'Merci pour votre retour.') : tr('sorry', 'Merci. Notre équipe peut vous aider directement :');
        Array.prototype.forEach.call(readEl.querySelectorAll('[data-fb]'), function (x) { x.hidden = true; });
      });

      function showError(msg, code) {
        $('docs-loading').hidden = true;
        var txt = tr('load_error', 'Impossible de charger la documentation') + (code ? ' (code: ' + code + ')' : '') + '.' + (msg ? ' ' + msg : '');
        $('docs-error').textContent = txt;
        $('docs-error').hidden = false;
        $('docs-body').hidden = true;
      }

      fetch(API + '?action=documentation.list', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (res) {
          return res.json().then(function (d) { return { res: res, data: d }; }, function () { return { res: res, data: null }; });
        })
        .then(function (r) {
          $('docs-loading').hidden = true;
          var data = r.data;
          if (!r.res.ok || !data || !data.ok) { showError((data && data.error) || ('HTTP ' + r.res.status), data && data.code); return; }
          all = (Array.isArray(data.articles) ? data.articles : []).map(function (a, i) {
            a.title = String(a.title || ''); a.category = String(a.category || '');
            a.summary = String(a.summary || ''); a.content = String(a.content || '');
            a._key = keyOf(a, i); return a;
          });
          cats = [];
          all.forEach(function (a) { if (cats.indexOf(a.category) === -1) cats.push(a.category); });
          var m = /^#article-(.+)$/.exec(location.hash || '');
          if (m) currentId = decodeURIComponent(m[1]);
          renderCats();
          render();
        })
        .catch(function (e) { showError(String(e && e.message ? e.message : e)); });
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/services_menu.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/services_menu.js') ?>" defer></script>
</body>
</html>
