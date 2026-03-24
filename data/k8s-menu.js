/**
 * Inject Kubernetes deployments into the sidebar submenu.
 *
 * Requirements:
 * - A container element with id="k8s-deployments" (inside your collapsible content)
 * - Backend endpoint: /data/k8s_api.php?action=list_deployments
 *
 * Notes:
 * - No hardcoded domain. Works on staging/prod/dev without edits.
 * - Uses window.K8S_API_URL and window.K8S_DEPLOYMENT_URL if present.
 */

(async function(){
  const host = document.getElementById('k8s-deployments');
  if(!host) return;

  host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Chargement…</div>';

  const apiBase = (() => {
    const hint = (typeof window !== 'undefined' && window.K8S_API_URL) ? String(window.K8S_API_URL) : '/data/k8s_api.php';
    return new URL(hint, window.location.href);
  })();

  const deploymentRoute = (typeof window !== 'undefined' && window.K8S_DEPLOYMENT_URL)
    ? String(window.K8S_DEPLOYMENT_URL)
    : '/deployment';

  try{
    const url = new URL(apiBase.toString());
    url.searchParams.set('action', 'list_deployments');

    const res = await fetch(url.toString(), { credentials: 'same-origin' });
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    const raw = await res.text();
    let data = null;
    try { data = JSON.parse(raw); } catch(_) { /* ignore */ }

    if(!ct.includes('application/json') || !data){
      throw new Error(`Réponse non-JSON (${res.status}). URL: ${url.pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
    }
    if(!res.ok || !data.ok){
      throw new Error(data.error || ('HTTP ' + res.status));
    }

    const deps = Array.isArray(data.deployments) ? data.deployments : [];
    if(deps.length === 0){
      host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Aucun deployment</div>';
      return;
    }

    host.innerHTML = deps.map(d => {
      const name = encodeURIComponent(d.name);
      const href = `${deploymentRoute}?deployment=${name}`;
      return `
        <a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors pl-10" href="${escapeHtml(href)}">
          <span class="font-medium truncate">${escapeHtml(d.name)}</span>
          <span class="ml-auto text-xs text-muted-foreground">${Number(d.ready ?? 0)}/${Number(d.replicas ?? 0)}</span>
        </a>
      `;
    }).join('');
  }catch(e){
    host.innerHTML = `<div class="text-red-600 text-xs px-2.5 py-1">K8S: ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
  }

  function escapeHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }
})();
