<?php
session_start();

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$currentUser = $_SESSION['user'];
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentSiret = trim((string)($currentUser['siret'] ?? ''));
$currentPermId = (int)($currentUser['perm_id'] ?? 9999);
$canEditMembers = $currentSiret !== '' && $currentPermId >= 0 && $currentPermId <= 10;

if ($currentUserId <= 0 || $currentSiret === '') {
    http_response_code(403);
    exit('Session utilisateur invalide.');
}

if (empty($_SESSION['csrf_equipes'])) {
    $_SESSION['csrf_equipes'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_equipes'];

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_member') {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrfToken, (string)$postedToken)) {
            $errors[] = 'Le jeton de sécurité est invalide.';
        }

        if (!$canEditMembers) {
            $errors[] = 'Vous n\'avez pas l\'autorisation de modifier les membres de cette équipe.';
        }

        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId <= 0) {
            $errors[] = 'Membre invalide.';
        }

        $civilite = trim((string)($_POST['civilite'] ?? ''));
        $prenom = trim((string)($_POST['prenom'] ?? ''));
        $nom = trim((string)($_POST['nom'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $langueCode = trim((string)($_POST['langue_code'] ?? ''));
        $timezone = trim((string)($_POST['timezone'] ?? ''));
        $permIdTarget = filter_input(INPUT_POST, 'perm_id', FILTER_VALIDATE_INT);

        $civilite = mb_substr($civilite, 0, 50);
        $prenom = mb_substr($prenom, 0, 100);
        $nom = mb_substr($nom, 0, 100);
        $username = mb_substr($username, 0, 100);
        $langueCode = mb_substr($langueCode, 0, 20);
        $timezone = mb_substr($timezone, 0, 100);

        if ($prenom === '' || $nom === '' || $username === '') {
            $errors[] = 'Les champs prénom, nom et identifiant sont obligatoires.';
        }

        if ($permIdTarget === false || $permIdTarget === null) {
            $errors[] = 'Le niveau de permission est invalide.';
        }

        if ($username !== '') {
            $stmtUsername = $pdo->prepare('SELECT id FROM users WHERE username = ? AND siret = ? AND id <> ? LIMIT 1');
            $stmtUsername->execute([$username, $currentSiret, $memberId]);
            if ($stmtUsername->fetch()) {
                $errors[] = 'Cet identifiant est déjà utilisé pour ce SIRET.';
            }
        }

        if (!$errors) {
            $stmtTarget = $pdo->prepare('SELECT id FROM users WHERE id = ? AND siret = ? LIMIT 1');
            $stmtTarget->execute([$memberId, $currentSiret]);
            $targetExists = $stmtTarget->fetchColumn();

            if (!$targetExists) {
                $errors[] = 'Le membre demandé n\'appartient pas à votre structure.';
            } else {
                $stmtUpdate = $pdo->prepare(
                    'UPDATE users
                     SET civilite = ?, prenom = ?, nom = ?, username = ?, perm_id = ?, langue_code = ?, timezone = ?
                     WHERE id = ? AND siret = ?'
                );

                $stmtUpdate->execute([
                    $civilite,
                    $prenom,
                    $nom,
                    $username,
                    (int)$permIdTarget,
                    $langueCode,
                    $timezone,
                    $memberId,
                    $currentSiret,
                ]);

                if ($memberId === $currentUserId) {
                    $_SESSION['user']['civilite'] = $civilite;
                    $_SESSION['user']['prenom'] = $prenom;
                    $_SESSION['user']['nom'] = $nom;
                    $_SESSION['user']['username'] = $username;
                    $_SESSION['user']['perm_id'] = (int)$permIdTarget;
                    $_SESSION['user']['langue_code'] = $langueCode;
                    $_SESSION['user']['timezone'] = $timezone;
                }

                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?updated=1');
                exit();
            }
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $successMessage = 'Le membre a bien été mis à jour.';
}

$stmtMembers = $pdo->prepare(
    'SELECT id, siret, civilite, prenom, nom, username, perm_id, langue_code, timezone
     FROM users
     WHERE siret = ?
     ORDER BY nom ASC, prenom ASC, username ASC'
);
$stmtMembers->execute([$currentSiret]);
$members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);
$memberCount = count($members);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Équipe - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2">
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2">
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2">
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2">
  <meta name="next-size-adjust" content="">
  <meta name="theme-color" content="#ffffff">
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next">
  <style>
    .dashboard-layout {
      display: flex;
      flex-direction: row;
      align-items: stretch;
      width: 100%;
      min-height: 100vh;
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
    .members-card {
      background: var(--background, #fff);
    }
    .members-table-wrap {
      overflow-x: auto;
    }
    .members-table {
      width: 100%;
      min-width: 980px;
      border-collapse: collapse;
    }
    .members-table th,
    .members-table td {
      vertical-align: top;
    }
    .edit-row {
      background: rgba(0, 0, 0, 0.015);
    }
    .edit-row[hidden] {
      display: none;
    }
    .field-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
    }
    .field-grid .field-full {
      grid-column: 1 / -1;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      border-radius: 999px;
      padding: .2rem .65rem;
      font-size: .75rem;
      font-weight: 600;
      border: 1px solid transparent;
      white-space: nowrap;
    }
    .status-pill.is-ok {
      background: rgba(34, 197, 94, .12);
      color: rgb(22, 101, 52);
    }
    .status-pill.is-lock {
      background: rgba(239, 68, 68, .12);
      color: rgb(153, 27, 27);
    }
    .alert {
      border-radius: .75rem;
      border: 1px solid transparent;
      padding: .9rem 1rem;
      margin-bottom: 1rem;
      font-size: .95rem;
    }
    .alert-success {
      background: rgba(34, 197, 94, .10);
      color: rgb(21, 128, 61);
      border-color: rgba(34, 197, 94, .20);
    }
    .alert-error {
      background: rgba(239, 68, 68, .10);
      color: rgb(185, 28, 28);
      border-color: rgba(239, 68, 68, .20);
    }
    @media (max-width: 1024px) {
      .dashboard-layout {
        flex-direction: column;
      }
      .dashboard-sidebar {
        width: 100%;
        max-width: none;
        flex: 0 0 auto;
        height: auto !important;
      }
      .dashboard-main {
        padding: 1rem;
      }
      .field-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>
  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>
    <main class="dashboard-main">
      <div class="w-full min-h-screen bg-surface p-6">
        <div data-slot="card" class="members-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-sm">
          <div data-slot="card-header" class="m-0 flex w-full flex-wrap items-start justify-between gap-4 rounded-none p-4">
            <div>
              <p class="text-default mb-1 text-lg leading-relaxed font-semibold">Membres de l'équipe</p>
              <p class="text-foreground block text-sm">
                SIRET filtré: <strong><?= h($currentSiret) ?></strong> · <?= (int)$memberCount ?> membre<?= $memberCount > 1 ? 's' : '' ?> visible<?= $memberCount > 1 ? 's' : '' ?>
              </p>
            </div>
            <div class="flex w-full shrink-0 flex-col items-center gap-3 sm:flex-row md:w-max">
              <div class="relative w-full sm:w-72">
                <input id="memberSearch" data-slot="input" class="file:text-foreground placeholder:text-muted-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 pl-9 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm" placeholder="Rechercher un membre...">
                <svg class="lucide lucide-search text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" fill="none" height="24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
              </div>
              <?php if ($canEditMembers): ?>
                <span class="status-pill is-ok">Édition autorisée</span>
              <?php else: ?>
                <span class="status-pill is-lock">Lecture seule</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="px-4">
            <?php if ($successMessage): ?>
              <div class="alert alert-success"><?= h($successMessage) ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
              <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                  <div><?= h($error) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div data-slot="card-content" class="mt-0 rounded-none p-0">
            <div class="members-table-wrap">
              <table class="members-table text-left">
                <thead>
                  <tr>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Nom</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Identifiant</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Civilité</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Perm</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Langue</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Timezone</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Action</p></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$members): ?>
                    <tr>
                      <td colspan="7" class="border-surface border-b p-6">
                        <p class="text-foreground block text-sm">Aucun membre trouvé pour ce SIRET. Le silence des bases vides, quel grand classique.</p>
                      </td>
                    </tr>
                  <?php endif; ?>

                  <?php foreach ($members as $member): ?>
                    <?php
                      $memberId = (int)$member['id'];
                      $fullName = trim(((string)($member['prenom'] ?? '')) . ' ' . ((string)($member['nom'] ?? '')));
                      $fullName = $fullName !== '' ? $fullName : 'Sans nom';
                    ?>
                    <tr class="member-row" data-search="<?= h(mb_strtolower($fullName . ' ' . ($member['username'] ?? '') . ' ' . ($member['civilite'] ?? '') . ' ' . ($member['langue_code'] ?? '') . ' ' . ($member['timezone'] ?? '') . ' ' . ($member['perm_id'] ?? ''))) ?>">
                      <td class="border-surface border-b p-4">
                        <div>
                          <p class="text-default block text-sm font-semibold"><?= h($fullName) ?><?= $memberId === $currentUserId ? ' (vous)' : '' ?></p>
                          <p class="text-foreground block text-sm">SIRET: <?= h((string)$member['siret']) ?></p>
                        </div>
                      </td>
                      <td class="border-surface border-b p-4"><p class="text-foreground block text-sm"><?= h((string)$member['username']) ?></p></td>
                      <td class="border-surface border-b p-4"><p class="text-foreground block text-sm"><?= h((string)$member['civilite']) ?></p></td>
                      <td class="border-surface border-b p-4"><p class="text-foreground block text-sm"><?= (int)$member['perm_id'] ?></p></td>
                      <td class="border-surface border-b p-4"><p class="text-foreground block text-sm"><?= h((string)$member['langue_code']) ?></p></td>
                      <td class="border-surface border-b p-4"><p class="text-foreground block text-sm"><?= h((string)$member['timezone']) ?></p></td>
                      <td class="border-surface border-b p-4">
                        <?php if ($canEditMembers): ?>
                          <button type="button" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground h-9 px-3" data-edit-toggle="member-edit-<?= $memberId ?>">
                            Éditer
                          </button>
                        <?php else: ?>
                          <span class="status-pill is-lock">Verrouillé</span>
                        <?php endif; ?>
                      </td>
                    </tr>

                    <?php if ($canEditMembers): ?>
                      <tr id="member-edit-<?= $memberId ?>" class="edit-row" hidden>
                        <td colspan="7" class="border-surface border-b p-4">
                          <form method="post" action="" class="space-y-4">
                            <input type="hidden" name="action" value="update_member">
                            <input type="hidden" name="member_id" value="<?= $memberId ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                            <div class="field-grid">
                              <div>
                                <label class="text-default mb-1 block text-sm font-medium" for="civilite-<?= $memberId ?>">Civilité</label>
                                <input id="civilite-<?= $memberId ?>" name="civilite" value="<?= h((string)$member['civilite']) ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none">
                              </div>
                              <div>
                                <label class="text-default mb-1 block text-sm font-medium" for="username-<?= $memberId ?>">Identifiant</label>
                                <input id="username-<?= $memberId ?>" name="username" value="<?= h((string)$member['username']) ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none" required>
                              </div>
                              <div>
                                <label class="text-default mb-1 block text-sm font-medium" for="prenom-<?= $memberId ?>">Prénom</label>
                                <input id="prenom-<?= $memberId ?>" name="prenom" value="<?= h((string)$member['prenom']) ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none" required>
                              </div>
                              <div>
                                <label class="text-default mb-1 block text-sm font-medium" for="nom-<?= $memberId ?>">Nom</label>
                                <input id="nom-<?= $memberId ?>" name="nom" value="<?= h((string)$member['nom']) ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none" required>
                              </div>
                              <div>
                                <label class="text-default mb-1 block text-sm font-medium" for="perm-<?= $memberId ?>">perm_id</label>
                                <input id="perm-<?= $memberId ?>" name="perm_id" type="number" value="<?= (int)$member['perm_id'] ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none" required>
                              </div>
                              <div>
                                <label class="text-default mb-1 block text-sm font-medium" for="langue-<?= $memberId ?>">langue_code</label>
                                <input id="langue-<?= $memberId ?>" name="langue_code" value="<?= h((string)$member['langue_code']) ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none">
                              </div>
                              <div class="field-full">
                                <label class="text-default mb-1 block text-sm font-medium" for="timezone-<?= $memberId ?>">Timezone</label>
                                <input id="timezone-<?= $memberId ?>" name="timezone" value="<?= h((string)$member['timezone']) ?>" class="border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm outline-none">
                              </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                              <button type="submit" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2">Enregistrer</button>
                              <button type="button" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2" data-edit-toggle="member-edit-<?= $memberId ?>">Annuler</button>
                            </div>
                          </form>
                        </td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
  (function () {
    var searchInput = document.getElementById('memberSearch');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.member-row'));

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        var term = (searchInput.value || '').toLowerCase().trim();

        rows.forEach(function (row) {
          var haystack = (row.getAttribute('data-search') || '').toLowerCase();
          var visible = term === '' || haystack.indexOf(term) !== -1;
          row.style.display = visible ? '' : 'none';

          var editRow = document.getElementById(row.querySelector('[data-edit-toggle]') ? row.querySelector('[data-edit-toggle]').getAttribute('data-edit-toggle') : '');
          if (editRow && !visible) {
            editRow.hidden = true;
          }
        });
      });
    }

    Array.prototype.slice.call(document.querySelectorAll('[data-edit-toggle]')).forEach(function (button) {
      button.addEventListener('click', function () {
        var targetId = button.getAttribute('data-edit-toggle');
        var target = document.getElementById(targetId);
        if (!target) return;
        target.hidden = !target.hidden;
      });
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = '../k8s/k8s_api.php';
    window.K8S_UI_BASE = './pages/';
  </script>
  <script src="../k8s/k8s-menu.js" defer></script>
</body>
</html>
