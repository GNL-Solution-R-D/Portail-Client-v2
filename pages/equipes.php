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
$csrfPath = '../include/csrf.php';
if (is_readable($csrfPath)) {
    require_once $csrfPath;
}

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token)
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
    }
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


function safe_lower($value)
{
    $value = (string) $value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function safe_substr($value, $start, $length = null)
{
    $value = (string) $value;
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

function safe_upper($value)
{
    $value = (string) $value;
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function build_permission_labels()
{
    static $labels = null;

    if ($labels !== null) {
        return $labels;
    }

    $labels = [
        0 => 'Acces Complet',
        1 => 'Signataire/Representant',
        2 => 'Acces financier',
        3 => 'Acces Trésorie',
        4 => 'Acces Technique',
        5 => 'Lecture seule',
        6 => 'Invité',
    ];




    return $labels;
}

function permission_label($permId)
{
    $permId = max(0, min(255, (int) $permId));
    $labels = build_permission_labels();
    return isset($labels[$permId]) ? $labels[$permId] : 'Profil non défini';
}

function status_label(array $member)
{
    if (isset($member['statut']) && trim((string) $member['statut']) !== '') {
        return (string) $member['statut'];
    }
    if (isset($member['status']) && trim((string) $member['status']) !== '') {
        return (string) $member['status'];
    }
    if (isset($member['active'])) {
        return (int) $member['active'] === 1 ? 'Actif' : 'Inactif';
    }
    return 'Actif';
}

function status_badge_class($status)
{
    $normalized = safe_lower(trim((string) $status));
    $positive = ['actif', 'active', 'online', 'enabled', 'ok', 'en poste', 'disponible'];
    $negative = ['inactif', 'inactive', 'offline', 'disabled', 'bloqué', 'suspendu'];

    if (in_array($normalized, $positive, true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }

    if (in_array($normalized, $negative, true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function member_display_name(array $member)
{
    $parts = [];
    foreach (['civilite', 'prenom', 'nom'] as $key) {
        if (isset($member[$key]) && trim((string) $member[$key]) !== '') {
            $parts[] = trim((string) $member[$key]);
        }
    }

    if (!empty($parts)) {
        return implode(' ', $parts);
    }

    if (isset($member['username']) && trim((string) $member['username']) !== '') {
        return trim((string) $member['username']);
    }

    return 'Utilisateur #' . (int) ($member['id'] ?? 0);
}

function member_secondary_text(array $member)
{
    if (isset($member['email']) && trim((string) $member['email']) !== '') {
        return trim((string) $member['email']);
    }

    if (isset($member['username']) && trim((string) $member['username']) !== '') {
        return trim((string) $member['username']);
    }

    return 'Compte interne';
}

function member_initials(array $member)
{
    $firstName = trim((string) ($member['prenom'] ?? ''));
    $lastName = trim((string) ($member['nom'] ?? ''));

    $firstInitial = $firstName !== '' ? safe_substr($firstName, 0, 1) : '';
    $lastInitial = $lastName !== '' ? safe_substr($lastName, 0, 1) : '';

    $initials = safe_upper($firstInitial . $lastInitial);
    if ($initials !== '') {
        return $initials;
    }

    $username = trim((string) ($member['username'] ?? ''));
    if ($username !== '') {
        return safe_upper(safe_substr($username, 0, 2));
    }

    return '#';
}

function clamp_text($value, $maxLength)
{
    $value = trim((string) $value);
    return safe_substr($value, 0, (int) $maxLength);
}

function normalize_siret($value)
{
    return preg_replace('/\D+/', '', (string) $value);
}

function find_establishments_csv_paths()
{
    $paths = [];
    $candidates = [
        __DIR__ . '/etablissements.csv',
        dirname(__DIR__) . '/etablissements.csv',
        '/mnt/data/etablissements.csv',
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
            $paths[] = $candidate;
        }
    }

    return array_values(array_unique($paths));
}

function resolve_structure_name(PDO $pdo, $siret)
{
    $normalizedSiret = normalize_siret($siret);
    if ($normalizedSiret === '') {
        return '';
    }

    try {
        $stmt = $pdo->prepare('SELECT `nom` FROM `etablissements` WHERE REPLACE(REPLACE(REPLACE(`siret`, " ", ""), ".", ""), "-", "") = ? LIMIT 1');
        $stmt->execute([$normalizedSiret]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['nom']) && trim((string) $row['nom']) !== '') {
            return trim((string) $row['nom']);
        }
    } catch (Throwable $e) {
        // Repli CSV si la table n'existe pas ou n'est pas accessible.
    }

    foreach (find_establishments_csv_paths() as $csvPath) {
        $handle = @fopen($csvPath, 'r');
        if ($handle === false) {
            continue;
        }

        $headers = fgetcsv($handle, 0, ',');
        if ($headers === false) {
            fclose($handle);
            continue;
        }

        if (!empty($headers)) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }
        $headers = array_map(static function ($header) {
            return safe_lower(trim((string) $header));
        }, $headers);

        $siretIndex = array_search('siret', $headers, true);
        $nomIndex = array_search('nom', $headers, true);

        if ($siretIndex === false || $nomIndex === false) {
            fclose($handle);
            continue;
        }

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $rowSiret = normalize_siret($row[$siretIndex] ?? '');
            if ($rowSiret !== '' && $rowSiret === $normalizedSiret) {
                $name = trim((string) ($row[$nomIndex] ?? ''));
                fclose($handle);
                if ($name !== '') {
                    return $name;
                }
                break;
            }
        }

        fclose($handle);
    }

    return '';
}

function redirect_self(array $query = [])
{
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    if (!empty($query)) {
        $path .= '?' . http_build_query($query);
    }
    header('Location: ' . $path);
    exit();
}

$currentUser = $_SESSION['user'];
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentSiret = trim((string) ($currentUser['siret'] ?? ''));
$currentPermId = (int) ($currentUser['perm_id'] ?? 255);
$editablePermissionIds = [0, 1, 2, 3, 4];
$canEditMembers = $currentSiret !== '' && in_array($currentPermId, $editablePermissionIds, true);

$errors = [];
$success = [];
$structureName = '';
$isDolibarrMode = true;
$members = [];
$editMember = null;
$permissionLabels = build_permission_labels();
$isEditingSelf = false;

function dolbarExtractRows($payload)
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'contacts', 'users'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

function dolbarApiRequestWithBestAuth($apiUrl, $endpoint, $method, $query, $body, $userContext)
{
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $userContext);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $userContext);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $userContext);
    $sessionToken = dolbarApiResolveSessionToken($_SESSION);

    if ($sessionToken !== '') {
        return dolbarApiCallWithToken($apiUrl, $endpoint, $sessionToken, $method, $query, $body, 12);
    }

    if ($login !== null && $password !== null) {
        $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
        $_SESSION['dolibarr_token'] = $token;
        return dolbarApiCallWithToken($apiUrl, $endpoint, $token, $method, $query, $body, 12);
    }

    if ($apiKey !== null) {
        return dolbarApiCall($apiUrl, $endpoint, $apiKey, $method, $query, $body, 12);
    }

    throw new RuntimeException('Configuration Dolibarr incomplète (login/mot de passe ou clé API).', 0);
}

$dolibarrApiUrl = null;
$dolibarrThirdpartyId = 0;
$userContext = $currentUser;
if ($currentUserId > 0) {
    try {
        $fullUserStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $fullUserStmt->execute([$currentUserId]);
        $fullUser = $fullUserStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($fullUser)) {
            $userContext = array_merge($fullUser, $currentUser);
        }
    } catch (Throwable $e) {
        // Non bloquant : on continue avec la session courante.
    }
}

try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $userContext);
    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolibarr incomplète (URL API manquante).', 0);
    }

    $dolibarrApiUrl = dolbarApiNormalizeBaseUrl($apiUrl);

    $thirdpartiesPayload = dolbarApiRequestWithBestAuth(
        $dolibarrApiUrl,
        '/thirdparties',
        'GET',
        ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 500],
        [],
        $userContext
    );

    $thirdparties = array_values(array_filter(dolbarExtractRows($thirdpartiesPayload), static function ($row) {
        return is_array($row);
    }));

    $selectedThirdparty = $thirdparties[0] ?? null;
    if (!is_array($selectedThirdparty)) {
        throw new RuntimeException('Aucun tiers Dolibarr disponible pour ce compte.', 404);
    }

    $dolibarrThirdpartyId = (int) ($selectedThirdparty['id'] ?? $selectedThirdparty['rowid'] ?? 0);
    if ($dolibarrThirdpartyId <= 0) {
        throw new RuntimeException("Le tiers Dolibarr sélectionné ne contient pas d'identifiant exploitable.", 500);
    }

    $structureName = trim((string) ($selectedThirdparty['name'] ?? $selectedThirdparty['nom'] ?? $selectedThirdparty['socname'] ?? ''));
    if ($currentSiret === '') {
        $currentSiret = trim((string) ($selectedThirdparty['siret'] ?? ''));
    }
} catch (Throwable $e) {
    $errors[] = 'Impossible de connecter Dolibarr pour récupérer le tiers affiché dans Entreprise (code: ' . h(dolbarApiExtractErrorCode($e) ?? 'DLB') . '). ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_member') {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $errors[] = 'Jeton de sécurité invalide.';
    } elseif (!$canEditMembers) {
        $errors[] = "Vous n'avez pas les droits pour modifier les contacts de ce tiers.";
    } elseif ($dolibarrApiUrl === null || $dolibarrThirdpartyId <= 0) {
        $errors[] = 'Connexion Dolibarr indisponible : mise à jour impossible.';
    } else {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId <= 0) {
            $errors[] = 'Contact invalide.';
        } else {
            $email = clamp_text($_POST['email'] ?? '', 190);
            $fonction = clamp_text($_POST['fonction'] ?? '', 150);
            $statusRaw = safe_lower(clamp_text($_POST['statut'] ?? '', 50));
            $status = in_array($statusRaw, ['1', 'actif', 'active', 'on', 'enabled'], true) ? 1 : 0;

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adresse e-mail invalide.';
            }

            if (empty($errors)) {
                try {
                    dolbarApiRequestWithBestAuth(
                        $dolibarrApiUrl,
                        '/contacts/' . $memberId,
                        'PUT',
                        [],
                        [
                            'email' => $email,
                            'poste' => $fonction,
                            'socid' => $dolibarrThirdpartyId,
                            'fk_soc' => $dolibarrThirdpartyId,
                            'statut' => $status,
                        ],
                        $userContext
                    );
                    redirect_self(['updated' => 1]);
                } catch (Throwable $e) {
                    $errors[] = 'La mise à jour du contact Dolibarr a échoué (code: ' . h(dolbarApiExtractErrorCode($e) ?? 'DLB') . ').';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_member') {
    $errors[] = 'La création de membres locaux est désactivée ici : cette page modifie uniquement les contacts du tiers Dolibarr.';
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success[] = 'Le contact Dolibarr a été mis à jour.';
}

if ($dolibarrApiUrl !== null && $dolibarrThirdpartyId > 0) {
    try {
        $contactsPayload = [];
        try {
            $contactsPayload = dolbarApiRequestWithBestAuth(
                $dolibarrApiUrl,
                '/thirdparties/' . $dolibarrThirdpartyId . '/contacts',
                'GET',
                ['sortfield' => 't.lastname', 'sortorder' => 'ASC', 'limit' => 500],
                [],
                $userContext
            );
        } catch (Throwable $endpointError) {
            $errorCode = dolbarApiExtractErrorCode($endpointError);
            if ($errorCode !== '404') {
                throw $endpointError;
            }

            // Fallback Dolibarr: certaines instances n'exposent pas /thirdparties/{id}/contacts.
            $contactsPayload = dolbarApiRequestWithBestAuth(
                $dolibarrApiUrl,
                '/contacts',
                'GET',
                ['sortfield' => 't.lastname', 'sortorder' => 'ASC', 'limit' => 1000],
                [],
                $userContext
            );
        }

        $rawContacts = array_values(array_filter(dolbarExtractRows($contactsPayload), static function ($row) {
            return is_array($row);
        }));

        foreach ($rawContacts as $contact) {
            $contactSocid = (int) ($contact['fk_soc'] ?? $contact['socid'] ?? $contact['socid_id'] ?? 0);
            if ($contactSocid > 0 && $contactSocid !== $dolibarrThirdpartyId) {
                continue;
            }

            $contactId = (int) ($contact['id'] ?? $contact['rowid'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $members[] = [
                'id' => $contactId,
                'siret' => $currentSiret,
                'username' => trim((string) ($contact['email'] ?? $contact['login'] ?? ('contact-' . $contactId))),
                'civilite' => trim((string) ($contact['civility'] ?? $contact['civility_code'] ?? '')),
                'prenom' => trim((string) ($contact['firstname'] ?? '')),
                'nom' => trim((string) ($contact['lastname'] ?? '')),
                'perm_id' => 6,
                'fonction' => trim((string) ($contact['poste'] ?? $contact['job'] ?? '')),
                'email' => trim((string) ($contact['email'] ?? '')),
                'statut' => ((int) ($contact['statut'] ?? $contact['status'] ?? 1) === 1 ? 'Actif' : 'Inactif'),
                'active' => ((int) ($contact['statut'] ?? $contact['status'] ?? 1) === 1 ? 1 : 0),
            ];
        }
    } catch (Throwable $e) {
        $errors[] = 'Impossible de charger les contacts du tiers Dolibarr (code: ' . h(dolbarApiExtractErrorCode($e) ?? 'DLB') . '). ' . $e->getMessage();
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
if ($editId > 0 && !empty($members)) {
    foreach ($members as $member) {
        if ((int) ($member['id'] ?? 0) === $editId) {
            $editMember = $member;
            break;
        }
    }
}

$isEditingSelf = false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Équipes - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <style>
    .dashboard-layout {
      display: flex;
      flex-direction: row;
      align-items: stretch;
      width: 100%;
      min-height: calc(100vh - var(--app-header-height, 0px));
      min-height: calc(100dvh - var(--app-header-height, 0px));
    }
    .dashboard-sidebar {
      flex: 0 0 20rem;
      width: 20rem;
      max-width: 20rem;
    }
    .dashboard-main {
      flex: 1 1 auto;
      min-width: 0;
    }
    .table-wrap {
      overflow-x: auto;
    }
    .hidden-header-search,
    header input[type="search"],
    header input[placeholder*="Search"],
    header input[placeholder*="Rechercher"],
    header .search,
    header .search-bar,
    header .search-container,
    header [data-slot="input"] {
      display: none !important;
    }
    header .lucide-search,
    header [data-lucide="search"] {
      display: none !important;
    }
    @media (max-width: 1024px) {
      .dashboard-layout { flex-direction: column; }
      .dashboard-sidebar {
        width: 100%;
        max-width: none;
        flex: 0 0 auto;
        height: auto !important;
      }
      .dashboard-main { padding: 1rem; }
    }
    .collapsible-content {
      overflow: hidden;
      height: 0;
      opacity: 0;
      transition: height 220ms ease, opacity 220ms ease;
      will-change: height, opacity;
    }
    .collapsible-content.is-open {
      opacity: 1;
    }
    .collapsible-trigger .collapsible-chevron {
      transition: transform 220ms ease;
      will-change: transform;
    }
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {
      transform: rotate(90deg);
    }
    @media (prefers-reduced-motion: reduce) {
      .collapsible-content,
      .collapsible-trigger .collapsible-chevron {
        transition: none !important;
      }
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
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">
        <div class="bg-background text-card-foreground flex flex-col gap-3 rounded-xl border py-6 shadow-sm">
          <div class="px-6">
            <h1 class="text-lg font-semibold">Membres de la structure</h1>
            <p class="text-sm text-muted-foreground">
              Affichage basé sur le tiers sélectionné dans <strong>Entreprise</strong><?php echo $structureName !== '' ? ' : <strong>' . h($structureName) . '</strong>' : ''; ?>.
            </p>
          </div>
          <div class="px-6 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
            <span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
              <?php echo count($members); ?> membre(s)
            </span>
            <span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium <?php echo $canEditMembers ? 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300'; ?>">
              <?php echo $canEditMembers ? 'Édition autorisée' : 'Lecture seule'; ?>
            </span>
          </div>
        </div>

        <?php foreach ($errors as $message): ?>
          <div class="rounded-xl border border-red-200 bg-red-50 px-6 py-4 text-sm text-red-700 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-300">
            <?php echo h($message); ?>
          </div>
        <?php endforeach; ?>

        <?php foreach ($success as $message): ?>
          <div class="rounded-xl border border-green-200 bg-green-50 px-6 py-4 text-sm text-green-700 dark:border-green-900/30 dark:bg-green-950/30 dark:text-green-300">
            <?php echo h($message); ?>
          </div>
        <?php endforeach; ?>

        <?php if ($editMember && $canEditMembers): ?>
          <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
            <div class="px-6 pb-4 border-b">
              <h2 class="text-base font-semibold">Modifier le membre</h2>
              <p class="text-sm text-muted-foreground">
                <?php echo h(member_display_name($editMember)); ?> · <?php echo h(member_secondary_text($editMember)); ?>
              </p>
            </div>
            <form method="post" class="px-6 pt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              <input type="hidden" name="action" value="update_member">
              <input type="hidden" name="member_id" value="<?php echo (int) $editMember['id']; ?>">
              <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">

              <div class="md:col-span-2 xl:col-span-3 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                Les champs <strong>Civilité</strong>, <strong>Prénom</strong>, <strong>Nom</strong> et <strong>Identifiant</strong> restent visibles dans le tableau ci-dessous, mais ne sont plus modifiables depuis ce formulaire.
              </div>

              <label class="block">
                <span class="mb-1 block text-sm font-medium">E-mail</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="email" name="email" value="<?php echo h($editMember['email'] ?? ''); ?>">
              </label>

              <label class="block">
                <span class="mb-1 block text-sm font-medium">Fonction</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="fonction" value="<?php echo h($editMember['fonction'] ?? ''); ?>">
              </label>

              <label class="block">
                <span class="mb-1 block text-sm font-medium">Statut</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="statut" value="<?php echo h($editMember['statut'] ?? ''); ?>">
              </label>

              <div class="md:col-span-2 xl:col-span-3 flex flex-wrap items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2">
                  Enregistrer
                </button>
                <a href="<?php echo h(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-background hover:bg-accent h-10 px-4 py-2">
                  Annuler
                </a>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold">Liste des membres</h2>
              <p class="text-sm text-muted-foreground">Gestion des Acces</p>
            </div>
            <?php if ($canEditMembers): ?>
              <span class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-background h-9 px-3 py-2 text-muted-foreground">
                Gestion via Dolibarr
              </span>
            <?php endif; ?>
          </div>

          <div class="table-wrap" data-slot="card-content">
            <table class="w-full min-w-max table-auto text-left">
              <thead>
                <tr>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Membre</p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Fonction</p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Statut</p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Permission</p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Action</p></th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($members)): ?>
                  <tr>
                    <td colspan="5" class="border-surface border-b p-6 text-sm text-muted-foreground">Aucun membre trouvé pour ce SIRET.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($members as $member): ?>
                    <?php $status = status_label($member); ?>
                    <tr>
                      <td class="border-surface border-b p-4 align-top">
                        <div class="flex items-center gap-3">
                          <span class="relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-semibold">
                            <?php echo h(member_initials($member)); ?>
                          </span>
                          <div>
                            <p class="text-default block text-sm font-semibold"><?php echo h(member_display_name($member)); ?></p>
                            <p class="text-foreground block text-sm"><?php echo h(member_secondary_text($member)); ?></p>
                          </div>
                        </div>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <div>
                          <p class="text-default block text-sm font-semibold"><?php echo h($structureName !== '' ? $structureName : ((string) ($member['siret'] ?? 'SIRET indisponible'))); ?></p>
                          <p class="text-foreground block text-sm"><?php echo h(trim((string) ($member['fonction'] ?? '')) !== '' ? (string) $member['fonction'] : 'Aucune fonction définie'); ?></p>
                        </div>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 <?php echo h(status_badge_class($status)); ?>">
                          <?php echo h($status); ?>
                        </span>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <div>
                          <p class="text-foreground block text-sm"><?php echo h(permission_label((int) ($member['perm_id'] ?? 255))); ?></p>
                        </div>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <?php if ($canEditMembers): ?>
                          <a class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-background shadow-xs hover:bg-accent h-9 px-3 py-2" href="?edit=<?php echo (int) ($member['id'] ?? 0); ?>">
                            Modifier
                          </a>
                        <?php else: ?>
                          <span class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-slate-50 text-slate-500 h-9 px-3 py-2 cursor-not-allowed">
                            Non autorisé
                          </span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
    (function () {
      function ready(fn) {
        if (document.readyState !== 'loading') {
          fn();
        } else {
          document.addEventListener('DOMContentLoaded', fn);
        }
      }

      ready(function () {
        var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
        triggers.forEach(function (btn) {
          btn.classList.add('collapsible-trigger');

          var targetId = btn.getAttribute('aria-controls');
          var content = targetId ? document.getElementById(targetId) : null;
          if (!content) {
            var parent = btn.closest('[data-slot="collapsible"]');
            if (parent) {
              content = parent.querySelector('[data-slot="collapsible-content"]');
            }
          }
          if (!content) {
            return;
          }

          content.classList.add('collapsible-content');
          var chev = btn.querySelector('.lucide-chevron-right');
          if (chev) {
            chev.classList.add('collapsible-chevron');
          }

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
              btn.setAttribute('data-state', 'open');
              content.hidden = false;
              content.classList.add('is-open');
              content.setAttribute('data-state', 'open');
              content.style.height = '0px';
              var h = content.scrollHeight;
              requestAnimationFrame(function () {
                content.style.height = h + 'px';
              });
              var onEnd = function (ev) {
                if (ev.propertyName !== 'height') {
                  return;
                }
                content.style.height = 'auto';
                content.removeEventListener('transitionend', onEnd);
              };
              content.addEventListener('transitionend', onEnd);
            } else {
              btn.setAttribute('aria-expanded', 'false');
              btn.setAttribute('data-state', 'closed');
              content.classList.remove('is-open');
              content.setAttribute('data-state', 'closed');
              var current = content.scrollHeight;
              content.style.height = current + 'px';
              requestAnimationFrame(function () {
                content.style.height = '0px';
              });
              var onEndClose = function (ev) {
                if (ev.propertyName !== 'height') {
                  return;
                }
                content.hidden = true;
                content.removeEventListener('transitionend', onEndClose);
              };
              content.addEventListener('transitionend', onEndClose);
            }
          }, { passive: false });
        });

        var headerSelectors = [
          'header input[type="search"]',
          'header input[placeholder*="Search"]',
          'header input[placeholder*="Rechercher"]',
          'header .search',
          'header .search-bar',
          'header .search-container',
          'header [data-slot="input"]',
          'header .lucide-search',
          'header [data-lucide="search"]'
        ];
        document.querySelectorAll(headerSelectors.join(',')).forEach(function (node) {
          node.style.display = 'none';
        });
      });
    })();
  </script>
</body>
</html>
