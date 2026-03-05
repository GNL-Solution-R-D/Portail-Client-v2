/**
 * Inject Kubernetes deployments into the sidebar submenu.
 *
 * Requirements:
 * - A container element with id="k8s-deployments" (inside your collapsible content)
 * - Backend endpoint: k8s_api.php?action=list_deployments (same folder as this JS)
 *
 * This script resolves URLs based on *its own location* so it keeps working even if
 * the dashboard page lives in a different subfolder.
 */

(async function(){
  const host = document.getElementById('k8s-deployments');
  if(!host) return;

  host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Chargement…</div>';

  try{
    const scriptUrl = document.currentScript?.src
      ? new URL(document.currentScript.src)
      : new URL(window.location.href);

    // Allow overriding from pages if needed.
    const apiBase = (window.K8S_API_URL)
      ? new URL(window.K8S_API_URL, window.location.href)
      : new URL('k8s_api.php', scriptUrl);

    const uiBase = (window.K8S_UI_BASE)
      ? new URL(window.K8S_UI_BASE, window.location.href)
      : new URL('./', scriptUrl); // folder containing this script

    const apiUrl = new URL(apiBase.toString());
    apiUrl.searchParams.set('action', 'list_deployments');

    const res = await fetch(apiUrl.toString(), { credentials: 'same-origin' });

    // If we didn't get JSON, it's almost always: wrong URL (HTML 200) or a redirect/login page.
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    if(!ct.includes('application/json')){
      const txt = await res.text();
      throw new Error(
        `Réponse non-JSON (${res.status}). URL: ${apiUrl.pathname}. ` +
        txt.slice(0, 140).replace(/\s+/g,' ')
      );
    }

    const data = await res.json().catch(() => ({}));
    if(!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));

    const deps = Array.isArray(data.deployments) ? data.deployments : [];
    if(deps.length === 0){
      host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Aucun deployment</div>';
      return;
    }

    host.innerHTML = deps.map(d => {
      const nameEnc = encodeURIComponent(d.name);
      const link = new URL('deployment.php', uiBase).toString() + `?name=${nameEnc}`;
      return `
        <a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors pl-10" href="${link}">
          <span class="font-medium truncate">${escapeHtml(d.name)}</span>
          <span class="ml-auto text-xs text-muted-foreground">${Number(d.ready ?? 0)}/${Number(d.replicas ?? 0)}</span>
        </a>
      `;
    }).join('');

  }catch(e){
    host.innerHTML = `<div class="text-red-600 text-xs px-2.5 py-1">K8S: ${escapeHtml(e?.message || String(e))}</div>`;
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
