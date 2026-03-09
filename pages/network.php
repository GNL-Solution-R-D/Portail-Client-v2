<?php

declare(strict_types=1);

// Cookie de session valable sur /pages/* ET /k8s/*
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    @session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

$namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? '';

// CSRF token
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$deploymentFilter = $_GET['deployment'] ?? '';
if (!is_string($deploymentFilter)) $deploymentFilter = '';

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Network - URLs publiques</title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .wrap{max-width:1200px;margin:0 auto;padding:24px;}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}

    .row-grid{display:grid;gap:10px;align-items:center;}
    @media(min-width: 1024px){
      .row-grid{grid-template-columns: 2.2fr 1.2fr 1.6fr .8fr 1.4fr .9fr 1.6fr;}
    }

    .field{width:100%;height:36px;border-radius:8px;border:1px solid hsl(var(--border));background: hsl(var(--background));padding:0 10px;font-size:14px;}
    .field:disabled{opacity:.6;}

    .btn{height:36px;border-radius:8px;border:1px solid hsl(var(--border));padding:0 12px;font-size:13px;transition:background .15s ease;}
    .btn:hover{background: hsl(var(--secondary));}
    .btn:disabled{opacity:.6;cursor:not-allowed;}

    .badge{display:inline-flex;align-items:center;border-radius:999px;padding:2px 10px;font-size:12px;border:1px solid transparent;white-space:nowrap;}
    .b-ok{background: rgba(34,197,94,.12);color: rgb(22,163,74);}     /* green */
    .b-warn{background: rgba(245,158,11,.12);color: rgb(217,119,6);}   /* amber */
    .b-err{background: rgba(239,68,68,.12);color: rgb(220,38,38);}     /* red */
    .b-muted{background: hsl(var(--muted));color: hsl(var(--muted-foreground));}

    .actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;}

    @media (prefers-reduced-motion: reduce) {
      .btn{transition:none;}
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php if (file_exists('../include/header.php')) include('../include/header.php'); ?>

  <div class="wrap">
    <div class="mb-6">
      <a class="text-muted-foreground hover:text-foreground" href="/dashboard">← Retour dashboard</a>
      <h1 class="text-2xl font-bold mt-3">Network <span class="text-muted-foreground">/ URLs publiques</span></h1>
      <p class="text-muted-foreground">Namespace: <span class="mono"><?= htmlspecialchars((string)$namespace) ?></span>
        <?php if ($deploymentFilter !== ''): ?>
          • Filtre deployment: <span class="mono"><?= htmlspecialchars($deploymentFilter) ?></span>
        <?php endif; ?>
      </p>
    </div>

    <div class="bg-background rounded-xl border p-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold">Ingress</h2>
          <p class="text-sm text-muted-foreground mt-1">Liste des URLs publiques (host + path) et leur backend Service.</p>
        </div>
        <div class="flex items-center gap-2">
          <button id="refreshBtn" class="btn">Rafraîchir</button>
          <button id="addBtn" class="btn">Ajouter une URL</button>
        </div>
      </div>

      <div id="netMsg" class="text-sm text-muted-foreground mt-4"></div>

      <div class="mt-4 space-y-3" id="rows"></div>

      <div class="mt-5 text-xs text-muted-foreground">
        Notes:
        <ul class="list-disc pl-5 mt-2 space-y-1">
          <li>Seuls les Ingress marqués <span class="mono">gnl-solution.fr/managed-by=dashboard</span> sont modifiables ici.</li>
          <li>Le statut du certificat TLS est basé sur <span class="mono">cert-manager</span> si dispo, sinon sur le Secret TLS (<span class="mono">tls.crt</span>). Si le ServiceAccount n’a pas le droit de lire les Secrets, ça reste « ? ».</li>
        </ul>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const rowsEl = document.getElementById('rows');
      const msgEl = document.getElementById('netMsg');
      const addBtn = document.getElementById('addBtn');
      const refreshBtn = document.getElementById('refreshBtn');

      const csrf = <?= json_encode($_SESSION['csrf']) ?>;
      const deploymentFilter = <?= json_encode($deploymentFilter) ?>;

      const apiBase = new URL('../k8s/k8s_api.php', window.location.origin);

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      const badge = (text, kind='muted') => {
        const cls = kind==='ok' ? 'badge b-ok' : kind==='warn' ? 'badge b-warn' : kind==='err' ? 'badge b-err' : 'badge b-muted';
        return `<span class="${cls}">${escapeHtml(text)}</span>`;
      };

      const setMsg = (text, kind='muted') => {
        msgEl.className = 'text-sm ' + (kind==='err' ? 'text-red-600' : kind==='ok' ? 'text-emerald-600' : 'text-muted-foreground');
        msgEl.textContent = text;
      };

      let services = [];
      let entries = [];

      function urlFrom(e){
        const scheme = e.tlsSecret ? 'https' : (e.scheme || 'http');
        let path = (e.path || '/');
        if(typeof path !== 'string') path = '/';
        path = path.trim();
        if(path === '') path = '/';
        if(!path.startsWith('/')) path = '/' + path;
        return `${scheme}://${e.host}${path}`;
      }

      function certBadge(e){
        const c = e.cert || null;
        if(!c || !c.status) return badge('TLS ?', 'warn');
        if(c.status === 'none') return badge('Sans TLS', 'muted');
        if(c.status === 'valid'){
          const d = (c.daysRemaining != null) ? ` (${c.daysRemaining}j)` : '';
          return badge('TLS OK' + d, 'ok');
        }
        if(c.status === 'expired') return badge('TLS expiré', 'err');
        if(c.status === 'error') return badge('TLS KO', 'err');
        return badge('TLS ?', 'warn');
      }

      function lbBadge(e){
        const lb = Array.isArray(e.loadBalancer) ? e.loadBalancer : [];
        if(lb.length === 0) return badge('LB: -', 'muted');
        const first = lb[0];
        const t = first.hostname || first.ip || 'OK';
        return badge('LB ' + t, 'ok');
      }

      function serviceOptions(selected){
        const opts = services.map(s => {
          const sel = (s.name === selected) ? 'selected' : '';
          return `<option value="${escapeHtml(s.name)}" ${sel}>${escapeHtml(s.name)}</option>`;
        }).join('');
        return `<option value="">(choisir)</option>` + opts;
      }

      function serviceDefaultPort(serviceName){
        const s = services.find(x => x.name === serviceName);
        if(!s || !Array.isArray(s.ports) || s.ports.length === 0) return 80;
        const p = s.ports[0];
        return Number(p.port) || 80;
      }

      function render(){
        rowsEl.innerHTML = '';

        if(entries.length === 0){
          rowsEl.innerHTML = `<div class="text-muted-foreground text-sm">Aucune URL publique trouvée.</div>`;
          return;
        }

        for(const e of entries){
          const url = urlFrom(e);
          const managed = !!e.managed;
          const ro = managed ? '' : 'disabled';

          const row = document.createElement('div');
          row.className = 'rounded-xl border p-4';
          row.dataset.ingress = e.ingressName || '';
          row.dataset.id = e.id || '';

          const portVal = (e.port != null && e.port !== '') ? e.port : '';
          const tlsOn = !!e.tlsSecret;

          row.innerHTML = `
            <div class="row-grid">
              <div>
                <div class="text-xs text-muted-foreground mb-1">Host</div>
                <input class="field" name="host" value="${escapeHtml(e.host || '')}" ${ro} placeholder="ex: app.example.com" />
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Path</div>
                <input class="field" name="path" value="${escapeHtml(e.path || '/') }" ${ro} placeholder="/" />
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Service</div>
                <select class="field" name="service" ${ro}>${serviceOptions(e.service || '')}</select>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Port</div>
                <input class="field" name="port" value="${escapeHtml(String(portVal))}" ${ro} placeholder="80" />
              </div>
              <div>
                <div class="flex items-center justify-between">
                  <div class="text-xs text-muted-foreground mb-1">TLS secret</div>
                  <label class="text-xs text-muted-foreground flex items-center gap-1" title="Active/désactive TLS">
                    <input type="checkbox" name="tls" ${tlsOn ? 'checked' : ''} ${ro} /> TLS
                  </label>
                </div>
                <input class="field" name="tlsSecret" value="${escapeHtml(e.tlsSecret || '')}" ${ro} placeholder="ex: app-tls" />
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Certificat</div>
                <div class="flex flex-wrap items-center gap-2">
                  ${certBadge(e)}
                  ${lbBadge(e)}
                </div>
              </div>
              <div>
                <div class="text-xs text-muted-foreground mb-1">Actions</div>
                <div class="actions">
                  <a class="btn" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;">Ouvrir</a>
                  <button class="btn" data-copy>Copier</button>
                  ${managed ? '<button class="btn" data-save>Enregistrer</button>' : ''}
                  ${managed ? '<button class="btn" data-del>Supprimer</button>' : ''}
                </div>
              </div>
            </div>

            <div class="mt-3 text-xs text-muted-foreground">
              <span>Ingress: <span class="mono">${escapeHtml(e.ingressName || '(nouveau)')}</span></span>
              <span class="mx-2">•</span>
              <span>URL: <span class="mono">${escapeHtml(url)}</span></span>
              ${managed ? '' : '<span class="mx-2">•</span><span class="text-amber-600">Lecture seule</span>'}
            </div>

            <div class="mt-2 text-xs" data-row-msg></div>
          `;

          const rowMsg = row.querySelector('[data-row-msg]');
          const setRowMsg = (t, kind='muted') => {
            rowMsg.className = 'mt-2 text-xs ' + (kind==='err' ? 'text-red-600' : kind==='ok' ? 'text-emerald-600' : 'text-muted-foreground');
            rowMsg.textContent = t;
          };

          // copy
          row.querySelector('[data-copy]').addEventListener('click', async () => {
            try{ await navigator.clipboard.writeText(url); setRowMsg('Copié.', 'ok'); }
            catch(_){
              const ta = document.createElement('textarea');
              ta.value = url; document.body.appendChild(ta); ta.select();
              document.execCommand('copy'); ta.remove();
              setRowMsg('Copié.', 'ok');
            }
          });

          // auto port when service changes
          const svcSel = row.querySelector('select[name="service"]');
          const portInp = row.querySelector('input[name="port"]');
          if(svcSel && portInp && managed){
            svcSel.addEventListener('change', () => {
              if(!portInp.value){
                portInp.value = String(serviceDefaultPort(svcSel.value));
              }
            });
          }

          // tls toggles secret field
          const tlsCb = row.querySelector('input[name="tls"]');
          const tlsSecretInp = row.querySelector('input[name="tlsSecret"]');
          if(tlsCb && tlsSecretInp && managed){
            const syncTls = () => {
              if(tlsCb.checked){
                tlsSecretInp.disabled = false;
                tlsSecretInp.placeholder = 'ex: app-tls';
              }else{
                tlsSecretInp.value = '';
                tlsSecretInp.disabled = true;
              }
            };
            syncTls();
            tlsCb.addEventListener('change', syncTls);
          }

          // save
          const saveBtn = row.querySelector('[data-save]');
          if(saveBtn){
            saveBtn.addEventListener('click', async () => {
              saveBtn.disabled = true;
              setRowMsg('Enregistrement…');
              try{
                const hostV = row.querySelector('input[name="host"]').value.trim();
                const pathV = row.querySelector('input[name="path"]').value.trim() || '/';
                const svcV  = row.querySelector('select[name="service"]').value.trim();
                const portV = row.querySelector('input[name="port"]').value.trim();
                const tlsV  = row.querySelector('input[name="tls"]').checked;
                const tlsSecretV = row.querySelector('input[name="tlsSecret"]').value.trim();

                const body = new URLSearchParams({
                  id: row.dataset.id || '',
                  ingressName: row.dataset.ingress || '',
                  host: hostV,
                  path: pathV,
                  service: svcV,
                  port: portV,
                  tls: tlsV ? '1' : '0',
                  tlsSecret: tlsSecretV,
                });

                const u = new URL(apiBase.toString());
                u.searchParams.set('action','upsert_public_url');

                const res = await fetch(u.toString(), {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf,
                  },
                  body,
                });

                const ct = (res.headers.get('content-type') || '').toLowerCase();
                const raw = await res.text();
                let data = null;
                try{ data = JSON.parse(raw); }catch(_){ }

                if(!ct.includes('application/json') || !data){
                  throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
                }
                if(!res.ok || !data.ok){
                  throw new Error(data.error || ('HTTP ' + res.status));
                }

                setRowMsg('Ok. Ingress mis à jour. Rafraîchissement…', 'ok');
                await load();

              }catch(e){
                setRowMsg('Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
              }finally{
                saveBtn.disabled = false;
              }
            });
          }

          // delete
          const delBtn = row.querySelector('[data-del]');
          if(delBtn){
            delBtn.addEventListener('click', async () => {
              if(!row.dataset.ingress){
                setRowMsg('Rien à supprimer (pas encore créé).', 'warn');
                return;
              }
              delBtn.disabled = true;
              setRowMsg('Suppression…');
              try{
                const body = new URLSearchParams({ ingressName: row.dataset.ingress });
                const u = new URL(apiBase.toString());
                u.searchParams.set('action','delete_public_url');
                const res = await fetch(u.toString(), {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrf,
                  },
                  body,
                });

                const ct = (res.headers.get('content-type') || '').toLowerCase();
                const raw = await res.text();
                let data = null;
                try{ data = JSON.parse(raw); }catch(_){ }

                if(!ct.includes('application/json') || !data){
                  throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
                }
                if(!res.ok || !data.ok){
                  throw new Error(data.error || ('HTTP ' + res.status));
                }

                setRowMsg('Supprimé. Rafraîchissement…', 'ok');
                await load();

              }catch(e){
                setRowMsg('Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
              }finally{
                delBtn.disabled = false;
              }
            });
          }

          rowsEl.appendChild(row);
        }
      }

      function newRow(){
        const id = 'new-' + Math.random().toString(16).slice(2, 10);
        return {
          id,
          ingressName: '',
          managed: true,
          host: '',
          path: '/',
          service: '',
          port: '',
          tlsSecret: '',
          cert: { status: 'none' },
          loadBalancer: [],
        };
      }

      async function load(){
        setMsg('Chargement…');
        try{
          const u = new URL(apiBase.toString());
          u.searchParams.set('action','list_public_urls');
          if(deploymentFilter) u.searchParams.set('deployment', deploymentFilter);

          const res = await fetch(u.toString(), { credentials: 'same-origin' });
          const ct = (res.headers.get('content-type') || '').toLowerCase();
          const raw = await res.text();
          let data = null;
          try{ data = JSON.parse(raw); }catch(_){ }

          if(!ct.includes('application/json') || !data){
            throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
          }
          if(!res.ok || !data.ok){
            throw new Error(data.error || ('HTTP ' + res.status));
          }

          services = Array.isArray(data.services) ? data.services : [];
          entries = Array.isArray(data.entries) ? data.entries : [];

          render();
          setMsg(`${entries.length} URL(s) chargée(s).`, 'ok');

        }catch(e){
          setMsg('Erreur: ' + (e && e.message ? e.message : String(e)), 'err');
          rowsEl.innerHTML = '';
        }
      }

      addBtn.addEventListener('click', () => {
        entries = [newRow(), ...entries];
        render();
        setMsg('Nouvelle ligne ajoutée (pense à enregistrer).', 'muted');
      });

      refreshBtn.addEventListener('click', () => load());

      load();
    })();
  </script>

</body>
</html>
