/**
 * Inject Kubernetes deployments into the sidebar submenu.
 *
 * Defaults:
 * - API endpoint: /k8s/k8s_api.php
 * - Deployment route: /deployment
 *
 * Optional overrides (set on <html>):
 *   data-k8s-api-url="/k8s/k8s_api.php"
 *   data-k8s-deployment-url="/deployment"
 */
(async function () {
  const host = document.getElementById('k8s-deployments');
  if (!host) return;

  const root = document.documentElement;
  const apiBase = (root.dataset.k8sApiUrl || '/k8s_api.php').replace(/\/+$/, '');
  const deploymentRoute = (root.dataset.k8sDeploymentUrl || '../deployment').replace(/\/+$/, '');

  host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Chargement…</div>';

  try {
    const url = apiBase + '?action=list_deployments';
    const res = await fetch(url, { credentials: 'same-origin' });

    const ct = (res.headers.get('content-type') || '').toLowerCase();
    const text = await res.text();
    const data = ct.includes('application/json') ? safeJson(text) : null;

    if (!res.ok) {
      throw new Error(`HTTP ${res.status} — ${short(text)} (URL: ${url})`);
    }
    if (!data || !data.ok) {
      throw new Error((data && data.error)
        ? data.error
        : `Réponse non-JSON (HTTP ${res.status}). URL: ${url}. ${short(text)}`);
    }

    const deps = Array.isArray(data.deployments) ? data.deployments : [];
    if (deps.length === 0) {
      host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Aucun deployment</div>';
      return;
    }

    host.innerHTML = deps.map(d => {
      const name = encodeURIComponent(d.name || '');
      const href = `${deploymentRoute}?name=${name}`;
      return `
        <a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors pl-10"
           href="${href}">
          <span class="font-medium truncate">${escapeHtml(d.name)}</span>
          <span class="ml-auto text-xs text-muted-foreground">${d.ready ?? 0}/${d.replicas ?? 0}</span>
        </a>
      `;
    }).join('');
  } catch (e) {
    host.innerHTML = `<div class="text-red-600 text-xs px-2.5 py-1">K8S: ${escapeHtml(e.message || String(e))}</div>`;
  }

  function safeJson(s) {
    try { return JSON.parse(s); } catch { return null; }
  }

  function short(s) {
    const t = String(s || '').replace(/\s+/g, ' ').trim();
    return t.length > 180 ? (t.slice(0, 180) + '…') : t;
  }

  function escapeHtml(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
})();
