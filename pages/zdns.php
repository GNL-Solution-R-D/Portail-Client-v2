<?php

declare(strict_types=1);

require_once '../include/session_bootstrap.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

// Jeton CSRF (partagé avec le proxy data/domains_api.php)
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function zdns_is_domain(string $v): bool
{
    $v = rtrim(strtolower(trim($v)), '.');
    if ($v === '' || strlen($v) > 253) {
        return false;
    }
    return (bool) preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v);
}

$domainParam = $_GET['domain'] ?? '';
$domain = is_string($domainParam) ? rtrim(strtolower(trim($domainParam)), '.') : '';
$domainValid = zdns_is_domain($domain);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo $domainValid ? h($domain) . ' — ' : ''; ?>Zone DNS — GNL Solution</title>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media(max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){.collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none !important;}}

    .zone-table{width:100%;border-collapse:separate;border-spacing:0;min-width:640px;}
    .zone-table th,.zone-table td{padding:.75rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.9rem;text-align:left;vertical-align:middle;}
    .zone-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.7rem;color:var(--muted-foreground,#64748b);}
    .zone-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">

        <?php if (!$domainValid): ?>
          <div data-slot="card" class="bg-background text-card-foreground rounded-xl border p-6 shadow-sm">
            <h1 class="text-lg font-semibold">Zone DNS</h1>
            <p class="mt-2 text-sm text-muted-foreground">Domaine manquant ou invalide. Revenez au menu et sélectionnez un domaine.</p>
            <a href="./dashboard" class="mt-4 inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">← Retour au tableau de bord</a>
          </div>
        <?php else: ?>

          <!-- En-tête du domaine -->
          <div data-slot="card" class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
            <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Zone DNS</p>
                <div class="mt-1 flex items-center gap-2">
                  <h1 class="text-xl font-bold mono truncate"><?php echo h($domain); ?></h1>
                  <span class="inline-flex items-center gap-1 rounded-md border border-transparent bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path><path d="m9 11 3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    Vérifié
                  </span>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">Gérez les enregistrements DNS de ce domaine.</p>
              </div>
              <button type="button" data-copy-domain="<?php echo h($domain); ?>"
                class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Copier le domaine</button>
            </div>
          </div>

          <!-- Enregistrements DNS -->
          <div data-slot="card" class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
            <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
              <div>
                <h2 class="text-base font-semibold">Enregistrements</h2>
                <p class="text-sm text-muted-foreground" data-zone-count></p>
              </div>
              <button type="button" data-zone-add
                class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90">Ajouter un enregistrement</button>
            </div>

            <div class="px-2 md:px-6 mt-2">
              <div class="table-wrap overflow-x-auto">
                <table class="zone-table">
                  <thead>
                    <tr>
                      <th>Type</th>
                      <th>Nom</th>
                      <th>Valeur</th>
                      <th>TTL</th>
                      <th class="text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody data-zone-body>
                    <tr><td colspan="5" class="text-sm text-muted-foreground">Chargement…</td></tr>
                  </tbody>
                </table>
              </div>
              <div data-zone-status class="mt-3 text-xs"></div>
            </div>
          </div>

        <?php endif; ?>
      </div>
    </main>
  </div>

  <?php if ($domainValid): ?>
  <!-- Modal : ajouter un enregistrement -->
  <div id="zoneAddModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
       role="dialog" aria-modal="true" aria-labelledby="zoneAddTitle">
    <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
      <div class="p-6">
        <div class="flex items-start justify-between gap-4">
          <h2 id="zoneAddTitle" class="text-lg font-semibold">Ajouter un enregistrement</h2>
          <button type="button" data-zone-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Fermer</button>
        </div>
        <div class="mt-5 space-y-4">
          <div>
            <label for="zoneType" class="mb-1.5 block text-xs font-medium text-muted-foreground">Type</label>
            <select id="zoneType" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
              <option>A</option><option>AAAA</option><option>CNAME</option>
              <option>MX</option><option>TXT</option><option>NS</option><option>SRV</option><option>CAA</option>
            </select>
          </div>
          <div>
            <label for="zoneName" class="mb-1.5 block text-xs font-medium text-muted-foreground">Nom</label>
            <input id="zoneName" type="text" autocomplete="off" spellcheck="false" placeholder="@ ou www"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
          </div>
          <div>
            <label for="zoneValue" class="mb-1.5 block text-xs font-medium text-muted-foreground">Valeur</label>
            <input id="zoneValue" type="text" autocomplete="off" spellcheck="false" placeholder="203.0.113.10"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
          </div>
          <div>
            <label for="zoneTtl" class="mb-1.5 block text-xs font-medium text-muted-foreground">TTL (secondes)</label>
            <input id="zoneTtl" type="number" min="60" step="1" value="3600"
              class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
          </div>
          <div data-zone-add-error class="hidden text-xs text-red-600"></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" data-zone-close class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary">Annuler</button>
          <button type="button" data-zone-save class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90">Enregistrer</button>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    const DOMAIN = <?php echo json_encode($domain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const CSRF   = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const API    = '../data/domains_api.php';

    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    async function apiCall(action, payload, method) {
      method = (method || 'POST').toUpperCase();
      const u = new URL(API, window.location.href);
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
      const ct = (res.headers.get('content-type') || '').toLowerCase();
      const raw = await res.text();
      let data = null; try { data = JSON.parse(raw); } catch (_) {}
      if (!ct.includes('application/json') || !data) throw new Error('Réponse non-JSON (' + res.status + ').');
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    const body   = document.querySelector('[data-zone-body]');
    const countEl = document.querySelector('[data-zone-count]');
    const statusEl = document.querySelector('[data-zone-status]');

    function setStatus(text, kind) {
      if (!statusEl) return;
      statusEl.textContent = text || '';
      statusEl.className = 'mt-3 text-xs ' + (kind === 'err' ? 'text-red-600' : kind === 'ok' ? 'text-emerald-600' : 'text-muted-foreground');
    }

    function rowHtml(r) {
      const type = esc(r.type || r.record_type || '');
      const name = esc(r.name || r.host || '@');
      const val  = esc(r.content || r.value || r.data || '');
      const ttl  = esc(r.ttl != null ? r.ttl : '');
      const id   = esc(r.id != null ? r.id : '');
      return '<tr>' +
        '<td class="mono">' + type + '</td>' +
        '<td class="mono">' + name + '</td>' +
        '<td class="mono" style="word-break:break-all;">' + val + '</td>' +
        '<td class="mono">' + ttl + '</td>' +
        '<td class="text-right">' +
          '<button type="button" data-zone-del="' + id + '" data-row-label="' + type + ' ' + name + '" ' +
          'class="inline-flex h-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-all hover:bg-secondary">Supprimer</button>' +
        '</td></tr>';
    }

    async function loadRecords() {
      if (!body) return;
      body.innerHTML = '<tr><td colspan="5" class="text-sm text-muted-foreground">Chargement…</td></tr>';
      setStatus('');
      try {
        const data = await apiCall('records', { domain: DOMAIN }, 'GET');
        const rows = Array.isArray(data.records) ? data.records
                   : Array.isArray(data.domains) ? data.domains : [];
        if (countEl) countEl.textContent = rows.length + ' enregistrement(s)';
        if (rows.length === 0) {
          body.innerHTML = '<tr><td colspan="5" class="text-sm text-muted-foreground">Aucun enregistrement.</td></tr>';
          return;
        }
        body.innerHTML = rows.map(rowHtml).join('');
      } catch (e) {
        if (countEl) countEl.textContent = '';
        body.innerHTML = '<tr><td colspan="5" class="text-sm text-muted-foreground">Impossible de charger les enregistrements.</td></tr>';
        setStatus('Erreur : ' + (e && e.message ? e.message : e), 'err');
      }
    }

    // ── Modal ajout ───────────────────────────────────────────────────────────
    const modal = document.getElementById('zoneAddModal');
    const addBtn = document.querySelector('[data-zone-add]');
    const saveBtn = modal ? modal.querySelector('[data-zone-save]') : null;
    const errEl = modal ? modal.querySelector('[data-zone-add-error]') : null;
    const fType = document.getElementById('zoneType');
    const fName = document.getElementById('zoneName');
    const fValue = document.getElementById('zoneValue');
    const fTtl = document.getElementById('zoneTtl');

    function openModal() {
      if (!modal) return;
      if (errEl) { errEl.classList.add('hidden'); errEl.textContent = ''; }
      if (fName) fName.value = ''; if (fValue) fValue.value = ''; if (fTtl) fTtl.value = '3600';
      modal.classList.remove('hidden'); modal.classList.add('flex');
    }
    function closeModal() { if (modal) { modal.classList.remove('flex'); modal.classList.add('hidden'); } }

    addBtn && addBtn.addEventListener('click', openModal);
    modal && modal.querySelectorAll('[data-zone-close]').forEach(b => b.addEventListener('click', closeModal));
    modal && modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal && modal.classList.contains('flex')) closeModal(); });

    saveBtn && saveBtn.addEventListener('click', async () => {
      const payload = {
        domain: DOMAIN,
        type: fType ? fType.value : '',
        name: fName ? fName.value.trim() : '',
        content: fValue ? fValue.value.trim() : '',
        ttl: fTtl ? fTtl.value : '3600',
      };
      if (!payload.content) {
        if (errEl) { errEl.textContent = 'La valeur est requise.'; errEl.classList.remove('hidden'); }
        return;
      }
      saveBtn.disabled = true;
      try {
        await apiCall('add_record', payload);
        closeModal();
        loadRecords();
      } catch (e) {
        if (errEl) { errEl.textContent = 'Erreur : ' + (e && e.message ? e.message : e); errEl.classList.remove('hidden'); }
      } finally {
        saveBtn.disabled = false;
      }
    });

    // ── Suppression (délégation) ────────────────────────────────────────────────
    body && body.addEventListener('click', async (e) => {
      const del = e.target.closest('[data-zone-del]');
      if (!del) return;
      const id = del.getAttribute('data-zone-del');
      if (!confirm('Supprimer l\u2019enregistrement ' + (del.getAttribute('data-row-label') || '') + ' ?')) return;
      del.disabled = true;
      try {
        await apiCall('delete_record', { domain: DOMAIN, id });
        loadRecords();
      } catch (err) {
        setStatus('Erreur suppression : ' + (err && err.message ? err.message : err), 'err');
        del.disabled = false;
      }
    });

    // ── Copier le domaine ───────────────────────────────────────────────────────
    document.querySelectorAll('[data-copy-domain]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const txt = btn.getAttribute('data-copy-domain');
        try { await navigator.clipboard.writeText(txt); } catch (_) {}
        const old = btn.textContent; btn.textContent = 'Copié'; setTimeout(() => { btn.textContent = old; }, 1200);
      });
    });

    loadRecords();
  })();
  </script>
  <?php endif; ?>
</body>
</html>
