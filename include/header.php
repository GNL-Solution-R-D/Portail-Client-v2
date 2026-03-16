<!-- Bandeau supérieur plein largeur -->
<?php
$menuUser = $_SESSION['user'] ?? [];

$prenom = trim((string)($_SESSION['user']['prenom'] ?? ''));
$name   = trim((string)($_SESSION['user']['nom'] ?? ''));

$menuUsername = trim($prenom . ' ' . $name);

if ($menuUsername === '') {
    $menuUsername = trim((string)(
        $menuUser['username']
        ?? $menuUser['email']
        ?? 'Utilisateur'
    ));
}

$initialPrenom = $prenom !== ''
    ? (function_exists('mb_substr') ? mb_substr($prenom, 0, 1, 'UTF-8') : substr($prenom, 0, 1))
    : '';

$initialNom = $name !== ''
    ? (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1))
    : '';

$menuInitial = $initialPrenom . $initialNom;

if ($menuInitial === '') {
    $menuInitial = function_exists('mb_substr')
        ? mb_substr($menuUsername, 0, 1, 'UTF-8')
        : substr($menuUsername, 0, 1);
}

$menuInitial = function_exists('mb_strtoupper')
    ? mb_strtoupper($menuInitial, 'UTF-8')
    : strtoupper($menuInitial);
?>
<div hidden="">
  <!--$--><!--/$-->
</div>
<script>((e,t,r,o,a,n,s,i)=>{let l=document.documentElement,d=["light","dark"];function c(t){var r;(Array.isArray(e)?e:[e]).forEach(e=>{let r="class"===e,o=r&&n?a.map(e=>n[e]||e):a;r?(l.classList.remove(...o),l.classList.add(n&&n[t]?n[t]:t)):l.setAttribute(e,t)}),r=t,i&&d.includes(r)&&(l.style.colorScheme=r)}if(o)c(o);else try{let e=localStorage.getItem(t)||r,o=s&&"system"===e?window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light":e;c(o)}catch(e){}})("class","theme","system",null,["light","dark"],null,true,true)</script>

<div class="bg-background w-full border shadow-sm">
  <nav class="w-full overflow-visible rounded-lg border border-transparent p-2 shadow-transparent">
    <div class="relative flex items-center gap-8">
      <button href="/dashboard">
        <p class="mt-1 ml-1 text-base font-semibold">GNL Solution</p>
        <p class="mt-1 ml-1 text-base font-semibold">Portail Association &amp; Entreprise</p>
      </button>

      <div class="ml-auto flex items-center gap-2">
        <div class="relative hidden w-full max-w-sm min-w-[200px] items-center md:block">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search absolute top-2.5 left-2.5 h-5 w-5 text-slate-600">
            <circle cx="11" cy="11" r="8"></circle>
            <path d="m21 21-4.3-4.3"></path>
          </svg>
          <input data-slot="input" class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive pl-10" placeholder="Search"/>
        </div>

        <button data-slot="button" class="items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9 grid md:hidden">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search h-5 w-5">
            <circle cx="11" cy="11" r="8"></circle>
            <path d="m21 21-4.3-4.3"></path>
          </svg>
        </button>

        <button data-slot="button" class="items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9 hidden lg:grid">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bell h-5 w-5">
            <path d="M10.268 21a2 2 0 0 0 3.464 0"></path>
            <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path>
          </svg>
        </button>

        <button data-slot="button" class="items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9 mr-1 hidden lg:grid">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package h-5 w-5">
            <path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path>
            <path d="M12 22V12"></path>
            <polyline points="3.29 7 12 12 20.71 7"></polyline>
            <path d="m7.5 4.27 9 5.15"></path>
          </svg>
        </button>

        <div class="user-menu">
          <button
            type="button"
            id="userMenuButton"
            class="user-menu__trigger"
            aria-expanded="false"
            aria-haspopup="true"
            aria-controls="userMenuDropdown"
          >
            <span data-slot="avatar" class="relative flex size-8 shrink-0 overflow-hidden rounded-full h-8 w-8">
              <span data-slot="avatar-fallback" class="user-menu__avatar bg-muted flex size-full items-center justify-center rounded-full">
                <?php echo htmlspecialchars($menuInitial, ENT_QUOTES, 'UTF-8'); ?>
              </span>
            </span>
          </button>

          <div
            id="userMenuDropdown"
            class="user-menu__dropdown"
            role="menu"
            aria-labelledby="userMenuButton"
          >
            <span
              class="user-menu__username"
              title="<?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?>"
            >
              <?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?>
            </span>

            <div class="user-menu__actions">
              <a href="/parametres" class="user-menu__item" role="menuitem">Paramètres</a>
              <a href="/deconnexion" class="user-menu__item user-menu__item--danger" role="menuitem">Déconnexion</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </nav>
</div>

<!--$--><!--/$-->
<section aria-label="Notifications alt+T" tabindex="-1" aria-live="polite" aria-relevant="additions text" aria-atomic="false"></section>

<script>
(function () {
  const menu = document.querySelector('.user-menu');
  const button = document.getElementById('userMenuButton');
  const dropdown = document.getElementById('userMenuDropdown');

  if (!menu || !button || !dropdown) return;

  function openMenu() {
    dropdown.classList.add('is-open');
    button.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    dropdown.classList.remove('is-open');
    button.setAttribute('aria-expanded', 'false');
  }

  function toggleMenu() {
    dropdown.classList.contains('is-open') ? closeMenu() : openMenu();
  }

  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', function (event) {
    if (!menu.contains(event.target)) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
      button.focus();
    }
  });
})();
</script>