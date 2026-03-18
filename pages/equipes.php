<?php
session_start();

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
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

$schemaColumns = [];
$schemaDetails = [];
try {
    $columnStmt = $pdo->query('SHOW COLUMNS FROM users');
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $field = strtolower((string) ($column['Field'] ?? ''));
        if ($field !== '') {
            $schemaColumns[$field] = $field;
            $schemaDetails[$field] = $column;
        }
    }
} catch (Throwable $e) {
    $schemaColumns = [
        'id' => 'id',
        'siret' => 'siret',
        'username' => 'username',
        'civilite' => 'civilite',
        'prenom' => 'prenom',
        'nom' => 'nom',
        'perm_id' => 'perm_id',
        'fonction' => 'fonction',
        'email' => 'email',
        'statut' => 'statut',
        'status' => 'status',
        'active' => 'active',
    ];
}

$selectCandidates = ['id', 'siret', 'username', 'civilite', 'prenom', 'nom', 'perm_id', 'fonction', 'email', 'statut', 'status', 'active'];
$selectColumns = [];
foreach ($selectCandidates as $candidate) {
    if (isset($schemaColumns[$candidate])) {
        $selectColumns[] = '`' . $candidate . '`';
    }
}
if (!in_array('`id`', $selectColumns, true)) {
    $selectColumns[] = '`id`';
}
if (!in_array('`siret`', $selectColumns, true)) {
    $selectColumns[] = '`siret`';
}
if (!in_array('`perm_id`', $selectColumns, true)) {
    $selectColumns[] = '`perm_id`';
}

$errors = [];
$success = [];
$structureName = resolve_structure_name($pdo, $currentSiret);

if ($currentSiret === '') {
    $errors[] = 'Aucun SIRET n\'est associé au compte connecté. L\'affichage a été bloqué pour éviter les mélanges foireux.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_member') {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $errors[] = 'Jeton de sécurité invalide.';
    } elseif (!$canEditMembers) {
        $errors[] = 'Vous n\'avez pas les droits pour modifier les membres de cette structure.';
    } else {
        $memberId = (int) ($_POST['member_id'] ?? 0);

        $targetStmt = $pdo->prepare('SELECT `id`, `siret`, `perm_id` FROM `users` WHERE `id` = ? AND `siret` = ? LIMIT 1');
        $targetStmt->execute([$memberId, $currentSiret]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $errors[] = 'Membre introuvable pour ce SIRET.';
        } else {
            $updateData = [];

            // Champs volontairement non éditables : civilité, prénom, nom, identifiant.
            // Ils restent visibles dans le tableau mais ne sont plus pris en compte en modification.
            if (isset($schemaColumns['email'])) {
                $email = clamp_text($_POST['email'] ?? '', 190);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Adresse e-mail invalide.';
                } else {
                    $updateData['email'] = $email;
                }
            }
            if (isset($schemaColumns['fonction'])) {
                $updateData['fonction'] = clamp_text($_POST['fonction'] ?? '', 150);
            }
            if (isset($schemaColumns['statut'])) {
                $updateData['statut'] = clamp_text($_POST['statut'] ?? '', 50);
            } elseif (isset($schemaColumns['status'])) {
                $updateData['status'] = clamp_text($_POST['status'] ?? '', 50);
            }
            if (isset($schemaColumns['perm_id'])) {
                $newPermId = (int) ($_POST['perm_id'] ?? $target['perm_id']);
                if ($newPermId < 0 || $newPermId > 255) {
                    $errors[] = 'Le perm_id doit être compris entre 0 et 255.';
                } elseif ($memberId === $currentUserId && $newPermId !== (int) $target['perm_id']) {
                    $errors[] = 'Vous ne pouvez pas modifier votre propre niveau de permission.';
                } else {
                    $updateData['perm_id'] = $newPermId;
                }
            }

            if (empty($errors) && isset($updateData['email']) && $updateData['email'] !== '') {
                $duplicateEmailStmt = $pdo->prepare('SELECT `id` FROM `users` WHERE `email` = ? AND `id` <> ? LIMIT 1');
                $duplicateEmailStmt->execute([$updateData['email'], $memberId]);
                if ($duplicateEmailStmt->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
                }
            }

            if (empty($errors) && !empty($updateData)) {
                $assignments = [];
                $params = [];
                foreach ($updateData as $field => $value) {
                    if (in_array($field, ['k8s_namespace', 'k8sNamespace', 'namespace', 'namespace_k8s', 'k8s_ns'], true)) {
                        continue;
                    }
                    $assignments[] = '`' . $field . '` = ?';
                    $params[] = $value;
                }

                if (!empty($assignments)) {
                    $params[] = $memberId;
                    $params[] = $currentSiret;

                    $updateSql = 'UPDATE `users` SET ' . implode(', ', $assignments) . ' WHERE `id` = ? AND `siret` = ? LIMIT 1';
                    try {
                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->execute($params);
                        redirect_self(['updated' => 1]);
                    } catch (Throwable $e) {
                        $errors[] = "La mise à jour a échoué. Vérifiez l'unicité des données et réessayez.";
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_member') {
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        $errors[] = 'Jeton de sécurité invalide.';
    } elseif (!$canEditMembers) {
        $errors[] = 'Vous n\'avez pas les droits pour ajouter des membres à cette structure.';
    } else {
        $insertData = [];

        if (isset($schemaColumns['siret'])) {
            $insertData['siret'] = $currentSiret;
        }

        if (isset($schemaColumns['username'])) {
            $username = clamp_text($_POST['new_username'] ?? '', 190);
            if ($username === '') {
                $errors[] = 'L\'identifiant est obligatoire.';
            } else {
                $insertData['username'] = $username;
            }
        }

        if (isset($schemaColumns['civilite'])) {
            $insertData['civilite'] = clamp_text($_POST['new_civilite'] ?? '', 20);
        }
        if (isset($schemaColumns['prenom'])) {
            $insertData['prenom'] = clamp_text($_POST['new_prenom'] ?? '', 120);
        }
        if (isset($schemaColumns['nom'])) {
            $insertData['nom'] = clamp_text($_POST['new_nom'] ?? '', 120);
        }
        if (isset($schemaColumns['email'])) {
            $email = clamp_text($_POST['new_email'] ?? '', 190);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adresse e-mail invalide.';
            } else {
                $insertData['email'] = $email;
            }
        }
        if (isset($schemaColumns['fonction'])) {
            $insertData['fonction'] = clamp_text($_POST['new_fonction'] ?? '', 150);
        }
        if (isset($schemaColumns['statut'])) {
            $insertData['statut'] = clamp_text($_POST['new_statut'] ?? 'Actif', 50);
        } elseif (isset($schemaColumns['status'])) {
            $insertData['status'] = clamp_text($_POST['new_statut'] ?? 'Actif', 50);
        }
        if (isset($schemaColumns['active'])) {
            $insertData['active'] = 1;
        }
        if (isset($schemaColumns['perm_id'])) {
            $newPermId = (int) ($_POST['new_perm_id'] ?? 6);
            if ($newPermId < 0 || $newPermId > 255) {
                $errors[] = 'Le perm_id doit être compris entre 0 et 255.';
            } else {
                $insertData['perm_id'] = $newPermId;
            }
        }
        if (isset($schemaColumns['password'])) {
            $password = (string) ($_POST['new_password'] ?? '');
            if ($password === '') {
                $errors[] = 'Le mot de passe est obligatoire.';
            } else {
                $insertData['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
        }

        if (empty($errors) && isset($insertData['email']) && $insertData['email'] !== '') {
            $duplicateEmailStmt = $pdo->prepare('SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1');
            $duplicateEmailStmt->execute([$insertData['email']]);
            if ($duplicateEmailStmt->fetch(PDO::FETCH_ASSOC)) {
                $errors[] = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
            }
        }

        if (empty($errors) && isset($insertData['username'])) {
            $duplicateUsernameStmt = $pdo->prepare('SELECT `id` FROM `users` WHERE `siret` = ? AND `username` = ? LIMIT 1');
            $duplicateUsernameStmt->execute([$currentSiret, $insertData['username']]);
            if ($duplicateUsernameStmt->fetch(PDO::FETCH_ASSOC)) {
                $errors[] = 'Cet identifiant existe déjà pour votre structure.';
            }
        }

        if (empty($errors)) {
            foreach ($schemaDetails as $field => $details) {
                if (isset($insertData[$field])) {
                    continue;
                }
                $extra = safe_lower((string) ($details['Extra'] ?? ''));
                $nullable = safe_lower((string) ($details['Null'] ?? '')) === 'yes';
                $hasDefault = array_key_exists('Default', $details) && $details['Default'] !== null;
                if (!$nullable && !$hasDefault && strpos($extra, 'auto_increment') === false) {
                    $errors[] = 'Impossible d\'ajouter un membre : le champ obligatoire "' . $field . '" n\'est pas géré par ce formulaire.';
                }
            }
        }

        if (empty($errors) && !empty($insertData)) {
            $columns = [];
            $placeholders = [];
            $params = [];
            foreach ($insertData as $field => $value) {
                if (in_array($field, ['k8s_namespace', 'k8sNamespace', 'namespace', 'namespace_k8s', 'k8s_ns'], true)) {
                    continue;
                }
                $columns[] = '`' . $field . '`';
                $placeholders[] = '?';
                $params[] = $value;
            }

            if (!empty($columns)) {
                $insertSql = 'INSERT INTO `users` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                try {
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute($params);
                    redirect_self(['created' => '1']);
                } catch (Throwable $e) {
                    $errors[] = 'Impossible d\'ajouter ce membre avec la structure actuelle de la table users.';
                }
            }
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success[] = 'Le membre a été mis à jour. Sans namespace, sans fuite, sans tragédie.';
}
if (isset($_GET['created']) && $_GET['created'] === '1') {
    $success[] = 'Le membre a été ajouté.';
}

$members = [];
if ($currentSiret !== '') {
    $orderParts = [];
    foreach (['nom', 'prenom', 'username'] as $sortableColumn) {
        if (isset($schemaColumns[$sortableColumn])) {
            $orderParts[] = '`' . $sortableColumn . '` ASC';
        }
    }
    $orderParts[] = '`id` ASC';

    $sql = 'SELECT ' . implode(', ', $selectColumns) . ' FROM `users` WHERE `siret` = ? ORDER BY ' . implode(', ', $orderParts);

    try {
        $listStmt = $pdo->prepare($sql);
        $listStmt->execute([$currentSiret]);
        $members = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $errors[] = 'Impossible de charger la liste des membres avec la structure actuelle de la table users.';
    }
}

$editMember = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
if ($editId > 0 && !empty($members)) {
    foreach ($members as $member) {
        if ((int) ($member['id'] ?? 0) === $editId) {
            $editMember = $member;
            break;
        }
    }
}

$permissionLabels = build_permission_labels();
$isEditingSelf = $editMember && (int) ($editMember['id'] ?? 0) === $currentUserId;
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
    <?php include('../include/menu.php'); ?>
    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">
        <div class="bg-background text-card-foreground flex flex-col gap-3 rounded-xl border py-6 shadow-sm">
          <div class="px-6">
            <h1 class="text-lg font-semibold">Membres de la structure</h1>
            <p class="text-sm text-muted-foreground">
              Affichage limité au SIRET <strong><?php echo h($currentSiret !== '' ? $currentSiret : 'non défini'); ?></strong>.
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

              <?php if (isset($schemaColumns['email'])): ?>
                <label class="block">
                  <span class="mb-1 block text-sm font-medium">E-mail</span>
                  <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="email" name="email" value="<?php echo h($editMember['email'] ?? ''); ?>">
                </label>
              <?php endif; ?>

              <?php if (isset($schemaColumns['fonction'])): ?>
                <label class="block">
                  <span class="mb-1 block text-sm font-medium">Fonction</span>
                  <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="fonction" value="<?php echo h($editMember['fonction'] ?? ''); ?>">
                </label>
              <?php endif; ?>

              <?php if (isset($schemaColumns['statut']) || isset($schemaColumns['status'])): ?>
                <label class="block">
                  <span class="mb-1 block text-sm font-medium">Statut</span>
                  <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="<?php echo isset($schemaColumns['statut']) ? 'statut' : 'status'; ?>" value="<?php echo h(isset($schemaColumns['statut']) ? ($editMember['statut'] ?? '') : ($editMember['status'] ?? '')); ?>">
                </label>
              <?php endif; ?>

              <?php if (isset($schemaColumns['perm_id'])): ?>
                <label class="block md:col-span-2 xl:col-span-1">
                  <span class="mb-1 block text-sm font-medium">Permission</span>
                  <?php if ($isEditingSelf): ?>
                    <input type="hidden" name="perm_id" value="<?php echo (int) ($editMember['perm_id'] ?? 255); ?>">
                    <input class="border-input h-10 w-full rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground" type="text" value="<?php echo h(permission_label((int) ($editMember['perm_id'] ?? 255))); ?>" readonly>
                    <p class="mt-1 text-xs text-muted-foreground">Vous ne pouvez pas modifier votre propre niveau de permission.</p>
                  <?php else: ?>
                    <select class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" name="perm_id">
                      <?php foreach ($permissionLabels as $permValue => $permLabel): ?>
                        <option value="<?php echo (int) $permValue; ?>" <?php echo (int) ($editMember['perm_id'] ?? 255) === (int) $permValue ? 'selected' : ''; ?>>
                          <?php echo (int) $permValue; ?> - <?php echo h($permLabel); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                </label>
              <?php endif; ?>

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
              <a href="?add=1" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-3 py-2">
                Ajouter un membre
              </a>
            <?php endif; ?>
          </div>

          <?php if ($canEditMembers && isset($_GET['add']) && $_GET['add'] === '1'): ?>
            <form method="post" class="px-6 pt-6 pb-2 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 border-b">
              <input type="hidden" name="action" value="create_member">
              <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">

              <label class="block">
                <span class="mb-1 block text-sm font-medium">Identifiant</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="new_username" required>
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium">E-mail</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="email" name="new_email">
              </label>
              <?php if (isset($schemaColumns['password'])): ?>
                <label class="block">
                  <span class="mb-1 block text-sm font-medium">Mot de passe</span>
                  <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="password" name="new_password" required>
                </label>
              <?php endif; ?>

              <label class="block">
                <span class="mb-1 block text-sm font-medium">Prénom</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="new_prenom">
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium">Nom</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="new_nom">
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium">Fonction</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="new_fonction">
              </label>

              <label class="block">
                <span class="mb-1 block text-sm font-medium">Statut</span>
                <input class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" type="text" name="new_statut" value="Actif">
              </label>

              <?php if (isset($schemaColumns['perm_id'])): ?>
                <label class="block">
                  <span class="mb-1 block text-sm font-medium">Permission</span>
                  <select class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm" name="new_perm_id">
                    <?php foreach ($permissionLabels as $permValue => $permLabel): ?>
                      <option value="<?php echo (int) $permValue; ?>" <?php echo (int) $permValue === 6 ? 'selected' : ''; ?>>
                        <?php echo (int) $permValue; ?> - <?php echo h($permLabel); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              <?php endif; ?>

              <div class="md:col-span-2 xl:col-span-3 flex flex-wrap items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2">
                  Ajouter
                </button>
                <a href="<?php echo h(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-background hover:bg-accent h-10 px-4 py-2">
                  Annuler
                </a>
              </div>
            </form>
          <?php endif; ?>

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
