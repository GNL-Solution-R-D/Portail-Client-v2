/**
 * assets/js/services_menu.js
 *
 * Remplit les dépliants « Mes services » de la barre latérale avec les
 * PRODUITS ACHETÉS par le client (statut active ou suspended).
 *
 * Un seul appel : data/services_menu_api.php, qui enchaîne côté serveur
 * order.list → order.product → product.list et renvoie les entrées déjà
 * groupées par dépliant (colonne product.esp_cli_menu_name).
 *
 *   web    → #web-services-list        (Services WEB)
 *   cloud  → #cloud-services-list      (Services Cloud)
 *   other  → #specific-services-list   (Services Spécifiques)
 *   vm     → #virtual-servers-list     (Serveurs Virtualisés)
 *   bm     → #dedicated-servers-list   (Serveurs Dédiés)
 *
 * Remplace assets/js/k8s_menu.js (déploiements Kubernetes) pour « Services WEB ».
 */

(async function () {
  var TARGETS = {
    web:   'web-services-list',
    cloud: 'cloud-services-list',
    other: 'specific-services-list',
    vm:    'virtual-servers-list',
    bm:    'dedicated-servers-list'
  };

  // Icône affichée devant chaque produit, par dépliant.
  var ICON_PATHS = {
    web:   '<path d="M6 7.95h.01M9 7.95h.01M12 7.95h.01M6.2 19h11.6c1.12 0 1.68 0 2.108-.218a2 2 0 0 0 .874-.874C21 17.48 21 16.92 21 15.8V8.2c0-1.12 0-1.68-.218-2.108a2 2 0 0 0-.874-.874C19.48 5 18.92 5 17.8 5H6.2c-1.12 0-1.68 0-2.108.218a2 2 0 0 0-.874.874C3 6.52 3 7.08 3 8.2v7.6c0 1.12 0 1.68.218 2.108a2 2 0 0 0 .874.874C4.52 19 5.08 19 6.2 19Z"></path>',
    cloud: '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>',
    other: '<path d="M20 7h-9"></path><path d="M14 17H5"></path><circle cx="17" cy="17" r="3"></circle><circle cx="7" cy="7" r="3"></circle>',
    vm:    '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"></path><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"></path><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"></path>',
    bm:    '<rect height="8" rx="2" ry="2" width="20" x="2" y="2"></rect><rect height="8" rx="2" ry="2" width="20" x="2" y="14"></rect><line x1="6" x2="6.01" y1="6" y2="6"></line><line x1="6" x2="6.01" y1="18" y2="18"></line>'
  };

  var hosts = {};
  var found = false;
  Object.keys(TARGETS).forEach(function (key) {
    var el = document.getElementById(TARGETS[key]);
    if (el) { hosts[key] = el; found = true; }
  });
  if (!found) return;

  setAll('<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Chargement…</div>');

  var apiUrl = (function () {
    if (typeof window !== 'undefined' && window.SERVICES_MENU_API_URL) {
      return new URL(String(window.SERVICES_MENU_API_URL), window.location.href);
    }
    var inPagesDir = window.location.pathname.indexOf('/pages/') !== -1;
    return new URL(inPagesDir ? '../data/services_menu_api.php' : './data/services_menu_api.php', window.location.href);
  })();

  try {
    var res = await fetch(apiUrl.toString(), { credentials: 'same-origin' });
    var ct  = (res.headers.get('content-type') || '').toLowerCase();
    var raw = await res.text();

    var data = null;
    try { data = JSON.parse(raw); } catch (_) { /* ignore */ }

    if (ct.indexOf('application/json') === -1 || !data) {
      throw new Error(buildNonJsonError(res.status, apiUrl.pathname, raw));
    }
    if (!res.ok || !data.ok) {
      throw new Error(data.error || ('HTTP ' + res.status));
    }

    var menus = (data && typeof data.menus === 'object' && data.menus) ? data.menus : {};

    Object.keys(hosts).forEach(function (key) {
      var entries = Array.isArray(menus[key]) ? menus[key] : [];
      hosts[key].innerHTML = entries.length
        ? entries.map(function (e) { return renderEntry(e, key); }).join('')
        : '<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Aucun service</div>';
    });

    if (Array.isArray(data.warnings) && data.warnings.length) {
      console.warn('[services] ' + data.warnings.join(' | '));
    }
    if (Array.isArray(data.unmapped) && data.unmapped.length) {
      console.warn('[services] produits sans esp_cli_menu_name exploitable : ' + data.unmapped.join(', '));
    }
  } catch (e) {
    var msg = escapeHtml(e && e.message ? e.message : String(e));
    setAll('<div class="text-red-600 text-xs px-2.5 py-1 pl-10">Services : ' + msg + '</div>');
  }

  // ── Rendu ───────────────────────────────────────────────────────────────────

  function renderEntry(entry, menuKey) {
    var name = String((entry && entry.name) || (entry && entry.slug) || '').trim();
    if (!name) return '';

    var statuses = Array.isArray(entry && entry.statuses) ? entry.statuses : [];
    var suspended = statuses.length > 0 && statuses.indexOf('active') === -1;
    var count = Number(entry && entry.count) || 1;

    var icon =
      '<span class="mr-0.5 grid shrink-0 place-items-center">' +
        '<svg class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" ' +
        'aria-hidden="true">' + (ICON_PATHS[menuKey] || ICON_PATHS.other) + '</svg>' +
      '</span>';

    var badge = count > 1
      ? '<span class="ml-auto shrink-0 rounded bg-secondary px-1.5 py-0.5 text-[10px] font-medium">×' + count + '</span>'
      : '';

    var suspendedDot = suspended
      ? '<span class="ml-auto shrink-0 text-[10px] font-medium text-amber-600">suspendu</span>'
      : '';

    var title = name + (suspended ? ' — suspendu' : '') + (count > 1 ? ' (' + count + ' exemplaires)' : '');

    return '<div data-service-slug="' + escapeHtml(String(entry.slug || '')) + '" title="' + escapeHtml(title) + '" ' +
      'class="text-muted-foreground flex w-full items-center gap-2 rounded-md px-2.5 py-2 pl-10 text-sm">' +
      icon +
      '<span class="font-medium truncate min-w-0' + (suspended ? ' opacity-70' : '') + '">' + escapeHtml(name) + '</span>' +
      (suspendedDot || badge) +
      '</div>';
  }

  function setAll(html) {
    Object.keys(hosts).forEach(function (key) { hosts[key].innerHTML = html; });
  }

  function buildNonJsonError(status, path, raw) {
    var compact = String(raw || '').replace(/\s+/g, ' ').trim();

    if (/failed opening required/i.test(compact) || /failed to open stream/i.test(compact)) {
      return 'API services indisponible (' + status + '). Vérifie la configuration serveur de ' + path + '.';
    }
    if (/<\/?(html|body|br|b)\b/i.test(compact)) {
      return 'API services indisponible (' + status + '). Le serveur a renvoyé une page HTML au lieu de JSON.';
    }
    return 'Réponse API invalide (' + status + ') sur ' + path + '.';
  }

  function escapeHtml(s) {
    return String(s)
      .split('&').join('&amp;')
      .split('<').join('&lt;')
      .split('>').join('&gt;')
      .split('"').join('&quot;')
      .split("'").join('&#039;');
  }
})();
