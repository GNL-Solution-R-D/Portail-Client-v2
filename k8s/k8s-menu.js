/**
 * Inject Kubernetes deployments into the sidebar submenu.
 *
 * Requirements:
 * - A container element with id="k8s-deployments" (inside your collapsible content)
 * - Backend endpoint: ./k8s/k8s_api.php?action=list_deployments
 */

(async function(){
  const host = document.getElementById('k8s-deployments');
  if(!host) return;

  host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Chargement…</div>';

  try{
    const res = await fetch('k8s_api.php?action=list_deployments', { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if(!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));

    const deps = Array.isArray(data.deployments) ? data.deployments : [];
    if(deps.length === 0){
      host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Aucun deployment</div>';
      return;
    }

    host.innerHTML = deps.map(d => {
      const name = encodeURIComponent(d.name);
      return `
        <a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors pl-10" href="./deployment?name=${name}">
          <span class="font-medium truncate">${escapeHtml(d.name)}</span>
          <span class="ml-auto text-xs text-muted-foreground">${d.ready ?? 0}/${d.replicas ?? 0}</span>
        </a>
      `;
    }).join('');
  }catch(e){
    host.innerHTML = `<div class="text-red-600 text-xs px-2.5 py-1">K8S: ${escapeHtml(e.message || String(e))}</div>`;
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
