<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';

if (empty($_SESSION['team_csrf_token'])) {
    $_SESSION['team_csrf_token'] = bin2hex(random_bytes(32));
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalize_role(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    return strtolower($value);
}

function permission_labels(): array
{
    return [
        0  => 'Accès complet',
        1  => 'Supervision',
        2  => 'Financier',
        3  => 'Contrats',
        4  => 'Technique',
        5  => 'RH',
        6  => 'Gestionnaire',
        7  => 'Direction',
        8  => 'Facturation',
        9  => 'Support',
        10 => 'Validation',
        11 => 'Lecture globale',
        12 => 'Membres',
        13 => 'Documents',
        14 => 'Projets',
        15 => 'Planning',
        16 => 'Événements',
        17 => 'Comptabilité',
        18 => 'Trésorerie',
        19 => 'Achats',
        20 => 'Ventes',
        21 => 'Partenaires',
        22 => 'Bénévoles',
        23 => 'Adhérents',
        24 => 'Juridique',
        25 => 'Paie',
        26 => 'Recrutement',
        27 => 'Infrastructure',
        28 => 'Sécurité',
        29 => 'Audit',
        30 => 'Lecture seule',
    ];
}

function permission_badge_class(int $permId): string
{
    if ($permId <= 5) {
        return 'border-transparent bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400';
    }

    if ($permId <= 10) {
        return 'border-transparent bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400';
    }

    if ($permId <= 20) {
        return 'border-transparent bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400';
    }

    return 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-900/20 dark:text-slate-300';
}

$permissionLabels = permission_labels();
$currentUser = $_SESSION['user'];
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentSiret = trim((string) ($currentUser['siret'] ?? ''));
$currentPermId = (int) ($currentUser['perm_id'] ?? 999);
$currentFunction = (string) ($currentUser['fonction'] ?? '');
$currentName = trim(((string) ($currentUser['prenom'] ?? '')) . ' ' . ((string) ($currentUser['nom'] ?? '')));
$currentName = $currentName !== '' ? $currentName : ((string) ($currentUser['username'] ?? 'Utilisateur'));

$normalizedFunction = normalize_role($currentFunction);
$rhKeywords = [
    'rh',
    'ressources humaines',
    'gestionnaire',
    'manager',
    'administrateur',
    'administration',
    'admin',
    'direction',
    'responsable',
];

$isRhRole = false;
foreach ($rhKeywords as $keyword) {
    if ($normalizedFunction !== '' && str_contains($normalizedFunction, $keyword)) {
        $isRhRole = true;
        break;
    }
}

$canManageMembers = $currentSiret !== '' && $currentPermId >= 0 && $currentPermId <= 10 && $isRhRole;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['team_csrf_token'], $postedToken)) {
        http_response_code(403);
        exit('Jeton CSRF invalide');
    }

    if (!$canManageMembers) {
        http_response_code(403);
        exit('Action non autorisée');
    }

    $memberId = (int) ($_POST['member_id'] ?? 0);
    $newFunction = trim((string) ($_POST['fonction'] ?? ''));
    $newPermId = filter_input(INPUT_POST, 'perm_id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 30],
    ]);

    if ($memberId <= 0 || $newPermId === false || $newPermId === null) {
        $_SESSION['team_flash'] = [
            'type' => 'error',
            'message' => 'Mise à jour refusée : données invalides.',
        ];
        header('Location: /equipes');
        exit();
    }

    if (mb_strlen($newFunction) > 120) {
        $_SESSION['team_flash'] = [
            'type' => 'error',
            'message' => 'La fonction est trop longue.',
        ];
        header('Location: /equipes');
        exit();
    }

    $targetStmt = $pdo->prepare('SELECT id, siret FROM users WHERE id = ? AND siret = ? LIMIT 1');
    $targetStmt->execute([$memberId, $currentSiret]);
    $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        $_SESSION['team_flash'] = [
            'type' => 'error',
            'message' => 'Membre introuvable pour votre structure.',
        ];
        header('Location: /equipes');
        exit();
    }

    $updateStmt = $pdo->prepare('UPDATE users SET fonction = ?, perm_id = ? WHERE id = ? AND siret = ?');
    $updateStmt->execute([$newFunction, $newPermId, $memberId, $currentSiret]);

    if ($memberId === $currentUserId) {
        $_SESSION['user']['fonction'] = $newFunction;
        $_SESSION['user']['perm_id'] = $newPermId;
    }

    $_SESSION['team_flash'] = [
        'type' => 'success',
        'message' => 'Le membre a été mis à jour.',
    ];

    header('Location: /equipes');
    exit();
}

$listStmt = $pdo->prepare('SELECT id, username, civilite, prenom, nom, fonction, perm_id, siret FROM users WHERE siret = ? ORDER BY nom ASC, prenom ASC, username ASC');
$listStmt->execute([$currentSiret]);
$members = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$teamCount = count($members);
$editableCount = 0;
foreach ($members as $member) {
    $memberPermId = (int) ($member['perm_id'] ?? 30);
    if ($memberPermId >= 0 && $memberPermId <= 10) {
        $editableCount++;
    }
}

$flash = $_SESSION['team_flash'] ?? null;
unset($_SESSION['team_flash']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Équipe client - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <style>
    .dashboard-layout{
      display:flex;
      flex-direction:row;
      align-items:stretch;
      width:100%;
      min-height:100vh;
    }
    .dashboard-sidebar{
      flex:0 0 20rem;
      width:20rem;
      max-width:20rem;
    }
    .dashboard-main{
      flex:1 1 auto;
      min-width:0;
    }
    @media (max-width: 1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{
        width:100%;
        max-width:none;
        flex:0 0 auto;
        height:auto !important;
      }
      .dashboard-main{padding:1rem;}
    }

    .team-page-shell {
      min-height: 100vh;
      background: var(--surface);
      padding: 1.5rem;
    }

    .team-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.25rem;
    }

    .team-toolbar__search {
      width: min(100%, 22rem);
    }

    .team-table-wrap {
      overflow-x: auto;
    }

    .team-row-self {
      background: color-mix(in oklab, var(--accent) 10%, transparent);
    }

    .team-actions {
      min-width: 320px;
    }

    .team-edit-form {
      display: grid;
      grid-template-columns: 1fr;
      gap: .75rem;
      min-width: 280px;
    }

    .team-edit-grid {
      display: grid;
      gap: .75rem;
    }

    .team-empty {
      border: 1px dashed var(--border);
      border-radius: 1rem;
      padding: 2rem;
      text-align: center;
      color: var(--muted-foreground);
      background: color-mix(in oklab, var(--card) 75%, transparent);
    }

    .team-readonly {
      color: var(--muted-foreground);
      font-size: .875rem;
      white-space: nowrap;
    }

    @media (min-width: 1280px) {
      .team-edit-grid {
        grid-template-columns: minmax(140px, 1fr) minmax(170px, 1.1fr) auto;
        align-items: end;
      }
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>
  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>
    <main class="dashboard-main">
      <div class="team-page-shell">
        <div class="team-toolbar">
          <div>
            <p class="text-default mb-1 text-lg leading-relaxed font-semibold">Membres de votre structure</p>
            <p class="text-foreground block text-sm">
              Portail client association / entreprise · SIRET : <span class="font-semibold"><?php echo e($currentSiret); ?></span>
            </p>
          </div>

          <div class="team-toolbar__search relative">
            <input
              id="pageTeamSearch"
              type="search"
              data-slot="input"
              class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-10 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive pl-9"
              placeholder="Rechercher un membre, une fonction ou une permission..."
              aria-label="Rechercher dans les membres"
            />
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
          </div>
        </div>

        <?php if ($flash): ?>
          <div class="mb-4 rounded-xl border px-4 py-3 text-sm <?php echo $flash['type'] === 'success' ? 'border-transparent bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'border-transparent bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-400'; ?>">
            <?php echo e($flash['message'] ?? ''); ?>
          </div>
        <?php endif; ?>

        <div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-3">
          <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-2 rounded-xl border py-6 shadow-sm">
            <div class="px-6">
              <p class="text-sm text-muted-foreground">Membres visibles</p>
              <p class="text-2xl font-semibold"><?php echo (int) $teamCount; ?></p>
            </div>
          </div>
          <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-2 rounded-xl border py-6 shadow-sm">
            <div class="px-6">
              <p class="text-sm text-muted-foreground">Profils client 0-10</p>
              <p class="text-2xl font-semibold"><?php echo (int) $editableCount; ?></p>
            </div>
          </div>
          <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-2 rounded-xl border py-6 shadow-sm">
            <div class="px-6">
              <p class="text-sm text-muted-foreground">Votre accès</p>
              <p class="text-base font-semibold"><?php echo e($permissionLabels[$currentPermId] ?? ('Permission #' . $currentPermId)); ?></p>
              <p class="text-sm text-muted-foreground mt-1"><?php echo $canManageMembers ? 'Édition RH autorisée' : 'Consultation uniquement'; ?></p>
            </div>
          </div>
        </div>

        <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-sm">
          <div data-slot="card-header" class="@container/card-header auto-rows-min grid-rows-[auto_auto] has-data-[slot=card-action]:grid-cols-[1fr_auto] m-0 flex w-full flex-wrap items-start justify-between gap-4 rounded-none px-4 pb-0">
            <div>
              <p class="text-default mb-1 text-lg leading-relaxed font-semibold">Liste des membres</p>
              <p class="text-foreground block text-sm">
                Seuls les utilisateurs rattachés au même SIRET sont affichés. Le namespace n'est ni visible ni modifiable ici.
              </p>
            </div>
            <div class="text-sm text-muted-foreground">
              <?php echo $canManageMembers ? 'Vous pouvez modifier la fonction et la permission des membres de votre structure.' : 'Vous voyez les droits de votre structure en lecture seule.'; ?>
            </div>
          </div>

          <div data-slot="card-content" class="px-0 pt-0">
            <?php if ($teamCount === 0): ?>
              <div class="px-4">
                <div class="team-empty">Aucun membre trouvé pour ce SIRET. Une belle performance administrative, dans le mauvais sens.</div>
              </div>
            <?php else: ?>
              <div class="team-table-wrap">
                <table class="w-full min-w-max table-auto text-left">
                  <thead>
                    <tr>
                      <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Nom</p></th>
                      <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Identifiant</p></th>
                      <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Fonction</p></th>
                      <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Permission</p></th>
                      <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Actions</p></th>
                    </tr>
                  </thead>
                  <tbody id="teamTableBody">
                    <?php foreach ($members as $member): ?>
                      <?php
                        $memberId = (int) ($member['id'] ?? 0);
                        $memberPermId = (int) ($member['perm_id'] ?? 30);
                        $fullName = trim(((string) ($member['prenom'] ?? '')) . ' ' . ((string) ($member['nom'] ?? '')));
                        $displayName = $fullName !== '' ? $fullName : ((string) ($member['username'] ?? 'Sans nom'));
                        $memberFunction = trim((string) ($member['fonction'] ?? ''));
                        $memberPermissionLabel = $permissionLabels[$memberPermId] ?? ('Permission #' . $memberPermId);
                        $isSelf = $memberId === $currentUserId;
                        $searchBlob = mb_strtolower($displayName . ' ' . ($member['username'] ?? '') . ' ' . $memberFunction . ' ' . $memberPermissionLabel);
                      ?>
                      <tr class="<?php echo $isSelf ? 'team-row-self' : ''; ?>" data-team-row data-search="<?php echo e($searchBlob); ?>">
                        <td class="border-surface border-b p-4 align-top">
                          <div class="flex items-center gap-3">
                            <span data-slot="avatar" class="relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full border bg-muted text-xs font-semibold">
                              <?php echo e(mb_strtoupper(mb_substr($displayName, 0, 1))); ?>
                            </span>
                            <div>
                              <p class="text-default block text-sm font-semibold"><?php echo e($displayName); ?></p>
                              <p class="text-foreground block text-sm">
                                <?php echo $isSelf ? 'Compte connecté' : 'Membre de votre structure'; ?>
                              </p>
                            </div>
                          </div>
                        </td>
                        <td class="border-surface border-b p-4 align-top">
                          <p class="text-default block text-sm font-semibold"><?php echo e((string) ($member['username'] ?? '')); ?></p>
                          <p class="text-foreground block text-sm">SIRET partagé</p>
                        </td>
                        <td class="border-surface border-b p-4 align-top">
                          <p class="text-default block text-sm font-semibold"><?php echo e($memberFunction !== '' ? $memberFunction : 'Non renseignée'); ?></p>
                          <p class="text-foreground block text-sm">Fonction interne client</p>
                        </td>
                        <td class="border-surface border-b p-4 align-top">
                          <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] transition-[color,box-shadow] overflow-hidden w-max <?php echo permission_badge_class($memberPermId); ?>">
                            #<?php echo (int) $memberPermId; ?> · <?php echo e($memberPermissionLabel); ?>
                          </span>
                        </td>
                        <td class="border-surface border-b p-4 align-top team-actions">
                          <?php if ($canManageMembers): ?>
                            <form method="post" class="team-edit-form">
                              <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['team_csrf_token']); ?>">
                              <input type="hidden" name="member_id" value="<?php echo (int) $memberId; ?>">
                              <div class="team-edit-grid">
                                <div>
                                  <label class="mb-1 block text-xs font-medium text-muted-foreground" for="perm_id_<?php echo (int) $memberId; ?>">Permission</label>
                                  <select
                                    id="perm_id_<?php echo (int) $memberId; ?>"
                                    name="perm_id"
                                    class="border-input data-[placeholder]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 flex h-9 w-full items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                                  >
                                    <?php foreach ($permissionLabels as $permissionId => $permissionLabel): ?>
                                      <option value="<?php echo (int) $permissionId; ?>" <?php echo $permissionId === $memberPermId ? 'selected' : ''; ?>>
                                        <?php echo '#' . (int) $permissionId . ' · ' . e($permissionLabel); ?>
                                      </option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>
                                <div>
                                  <label class="mb-1 block text-xs font-medium text-muted-foreground" for="fonction_<?php echo (int) $memberId; ?>">Fonction</label>
                                  <input
                                    id="fonction_<?php echo (int) $memberId; ?>"
                                    type="text"
                                    name="fonction"
                                    maxlength="120"
                                    value="<?php echo e($memberFunction); ?>"
                                    class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                    placeholder="Ex. Gestionnaire RH"
                                  />
                                </div>
                                <div>
                                  <button
                                    type="submit"
                                    data-slot="button"
                                    class="inline-flex h-9 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-all hover:bg-primary/90"
                                  >
                                    Enregistrer
                                  </button>
                                </div>
                              </div>
                            </form>
                          <?php else: ?>
                            <span class="team-readonly">Lecture seule</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    (function () {
      const input = document.getElementById('pageTeamSearch');
      const rows = Array.from(document.querySelectorAll('[data-team-row]'));

      if (!input || rows.length === 0) {
        return;
      }

      input.addEventListener('input', function () {
        const query = (input.value || '').toLowerCase().trim();

        rows.forEach(function (row) {
          const haystack = (row.getAttribute('data-search') || '').toLowerCase();
          row.style.display = query === '' || haystack.includes(query) ? '' : 'none';
        });
      });
    })();
  </script>
</body>
</html>
