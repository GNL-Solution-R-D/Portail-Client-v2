<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';

$currentUser = $_SESSION['user'];
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentSiret = trim((string)($currentUser['siret'] ?? ''));
$currentPermId = (int)($currentUser['perm_id'] ?? 255);
$canEdit = $currentSiret !== '' && $currentPermId >= 0 && $currentPermId <= 10;

if ($currentSiret === '') {
    http_response_code(403);
    exit('SIRET utilisateur introuvable en session.');
}

if (empty($_SESSION['team_csrf_token'])) {
    $_SESSION['team_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['team_csrf_token'];

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function set_team_flash(string $type, string $message): void
{
    $_SESSION['team_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function redirect_to_self(array $params = []): void
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
    $query = http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''));
    header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
    exit();
}

function permission_label(int $permId): string
{
    if ($permId < 0 || $permId > 255) {
        return 'Permission inconnue';
    }

    $coreLabels = [
        0  => 'Super administrateur portail',
        1  => 'Administrateur portail',
        2  => 'Responsable conformité',
        3  => 'Responsable sécurité',
        4  => 'Responsable facturation',
        5  => 'Responsable association',
        6  => 'Responsable entreprise',
        7  => 'Responsable RH',
        8  => 'Responsable finance',
        9  => 'Responsable technique',
        10 => 'Éditeur SIRET',
        255 => 'Compte désactivé',
    ];

    if (isset($coreLabels[$permId])) {
        return $coreLabels[$permId];
    }

    return match (true) {
        $permId >= 11 && $permId <= 31 => 'Association - gouvernance niveau ' . $permId,
        $permId >= 32 && $permId <= 63 => 'Association - gestion niveau ' . $permId,
        $permId >= 64 && $permId <= 95 => 'Association - opérationnel niveau ' . $permId,
        $permId >= 96 && $permId <= 127 => 'Entreprise - direction niveau ' . $permId,
        $permId >= 128 && $permId <= 159 => 'Entreprise - RH / finance niveau ' . $permId,
        $permId >= 160 && $permId <= 191 => 'Entreprise - métier / projets niveau ' . $permId,
        $permId >= 192 && $permId <= 223 => 'Lecture seule / audit niveau ' . $permId,
        $permId >= 224 && $permId <= 239 => 'API / intégration niveau ' . $permId,
        $permId >= 240 && $permId <= 254 => 'Système / réservé niveau ' . $permId,
        default => 'Permission ' . $permId,
    };
}

function permission_badge_class(int $permId): string
{
    return match (true) {
        $permId >= 0 && $permId <= 10 => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
        $permId >= 192 && $permId <= 223 => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
        $permId === 255 => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    };
}

function get_permission_options(): array
{
    $options = [];
    for ($i = 0; $i <= 255; $i++) {
        $options[$i] = permission_label($i);
    }
    return $options;
}

$search = trim((string)($_GET['q'] ?? ''));
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editId = $editId !== false ? $editId : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_member') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $returnSearch = trim((string)($_POST['return_q'] ?? ''));
    $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);

    if (!hash_equals($csrfToken, $token)) {
        set_team_flash('error', 'Le jeton de sécurité est invalide.');
        redirect_to_self(['q' => $returnSearch]);
    }

    if (!$canEdit) {
        set_team_flash('error', 'Votre permission ne permet pas de modifier les fiches de ce SIRET.');
        redirect_to_self(['q' => $returnSearch]);
    }

    if ($memberId === false || $memberId === null || $memberId <= 0) {
        set_team_flash('error', 'Identifiant utilisateur invalide.');
        redirect_to_self(['q' => $returnSearch]);
    }

    $civilite = trim((string)($_POST['civilite'] ?? ''));
    $prenom = trim((string)($_POST['prenom'] ?? ''));
    $nom = trim((string)($_POST['nom'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $langueCode = trim((string)($_POST['langue_code'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? ''));
    $k8sNamespace = trim((string)($_POST['k8s_namespace'] ?? ''));
    $permIdInput = filter_input(INPUT_POST, 'perm_id', FILTER_VALIDATE_INT);

    if ($prenom === '' || $nom === '' || $username === '') {
        set_team_flash('error', 'Les champs prénom, nom et identifiant sont obligatoires.');
        redirect_to_self(['q' => $returnSearch, 'edit' => $memberId]);
    }

    if ($permIdInput === false || $permIdInput === null || $permIdInput < 0 || $permIdInput > 255) {
        set_team_flash('error', 'Le perm_id doit être compris entre 0 et 255.');
        redirect_to_self(['q' => $returnSearch, 'edit' => $memberId]);
    }

    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND siret = :siret LIMIT 1');
    $checkStmt->execute([
        ':id' => $memberId,
        ':siret' => $currentSiret,
    ]);

    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        set_team_flash('error', 'Utilisateur introuvable pour ce SIRET.');
        redirect_to_self(['q' => $returnSearch]);
    }

    try {
        $updateStmt = $pdo->prepare(
            'UPDATE users
             SET civilite = :civilite,
                 prenom = :prenom,
                 nom = :nom,
                 username = :username,
                 perm_id = :perm_id,
                 langue_code = :langue_code,
                 timezone = :timezone,
                 k8s_namespace = :k8s_namespace
             WHERE id = :id AND siret = :siret'
        );

        $updateStmt->execute([
            ':civilite' => $civilite,
            ':prenom' => $prenom,
            ':nom' => $nom,
            ':username' => $username,
            ':perm_id' => $permIdInput,
            ':langue_code' => $langueCode,
            ':timezone' => $timezone,
            ':k8s_namespace' => $k8sNamespace,
            ':id' => $memberId,
            ':siret' => $currentSiret,
        ]);

        if ($memberId === $currentUserId) {
            $_SESSION['user']['civilite'] = $civilite;
            $_SESSION['user']['prenom'] = $prenom;
            $_SESSION['user']['nom'] = $nom;
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['perm_id'] = $permIdInput;
            $_SESSION['user']['langue_code'] = $langueCode;
            $_SESSION['user']['timezone'] = $timezone;
            $_SESSION['user']['k8s_namespace'] = $k8sNamespace;
        }

        set_team_flash('success', 'La fiche membre a été mise à jour.');
        redirect_to_self(['q' => $returnSearch]);
    } catch (Throwable $e) {
        set_team_flash('error', 'Mise à jour impossible. Vérifiez les contraintes SQL de la table users.');
        redirect_to_self(['q' => $returnSearch, 'edit' => $memberId]);
    }
}

$flash = $_SESSION['team_flash'] ?? null;
unset($_SESSION['team_flash']);

$sql = 'SELECT id, siret, username, civilite, prenom, nom, perm_id, langue_code, timezone, k8s_namespace
        FROM users
        WHERE siret = :siret';
$params = [':siret' => $currentSiret];

if ($search !== '') {
    $sql .= ' AND (
        username LIKE :term
        OR civilite LIKE :term
        OR prenom LIKE :term
        OR nom LIKE :term
        OR langue_code LIKE :term
        OR timezone LIKE :term
        OR k8s_namespace LIKE :term
        OR CAST(perm_id AS CHAR) LIKE :term
        OR CONCAT_WS(" ", civilite, prenom, nom, username, langue_code, timezone, k8s_namespace) LIKE :term
    )';
    $params[':term'] = '%' . $search . '%';
}

$sql .= ' ORDER BY nom ASC, prenom ASC, username ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$permissionOptions = get_permission_options();
$editingMember = null;
if ($canEdit && $editId !== null) {
    foreach ($members as $member) {
        if ((int)$member['id'] === (int)$editId) {
            $editingMember = $member;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Équipes - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <meta name="next-size-adjust" content=""/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
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
    .table-shell {
      overflow-x: auto;
    }
    .member-row[hidden] {
      display: none !important;
    }
    .flash-success {
      background: rgba(34, 197, 94, 0.12);
      border: 1px solid rgba(34, 197, 94, 0.32);
      color: rgb(21, 128, 61);
    }
    .flash-error {
      background: rgba(239, 68, 68, 0.10);
      border: 1px solid rgba(239, 68, 68, 0.28);
      color: rgb(185, 28, 28);
    }
    .cell-muted {
      color: rgb(100 116 139);
      font-size: .875rem;
    }
    .edit-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 1rem;
    }
    .edit-grid .field-full {
      grid-column: 1 / -1;
    }
    @media (max-width: 1280px) {
      .edit-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
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
    }
    @media (max-width: 768px) {
      .edit-grid {
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
        <?php if ($flash): ?>
          <div class="mb-4 rounded-xl px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'flash-success' : 'flash-error' ?>">
            <?= h($flash['message']) ?>
          </div>
        <?php endif; ?>

        <section data-slot="card" class="bg-card text-card-foreground flex flex-col gap-0 rounded-xl border py-0 shadow-sm">
          <div data-slot="card-header" class="border-b p-4">
            <form method="get" class="flex w-full items-center justify-end gap-3">
              <div class="relative w-full max-w-md">
                <input
                  id="teamSearch"
                  name="q"
                  value="<?= h($search) ?>"
                  data-slot="input"
                  class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-10 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 pl-10 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive"
                  placeholder="Rechercher un membre, un identifiant, une permission..."
                  autocomplete="off"
                />
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2">
                  <circle cx="11" cy="11" r="8"></circle>
                  <path d="m21 21-4.3-4.3"></path>
                </svg>
              </div>
              <button type="submit" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2">
                Filtrer
              </button>
            </form>
          </div>

          <div data-slot="card-content" class="p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 text-sm">
              <div>
                <span class="font-semibold"><?= count($members) ?></span>
                membre(s) rattaché(s) au SIRET
                <span class="font-semibold"><?= h($currentSiret) ?></span>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center justify-center rounded-md border px-2.5 py-1 text-xs font-medium <?= $canEdit ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' ?>">
                  <?= $canEdit ? 'Édition autorisée' : 'Lecture seule' ?>
                </span>
                <span class="cell-muted">Édition réservée aux comptes du même SIRET avec perm_id 0 à 10.</span>
              </div>
            </div>

            <div class="table-shell">
              <table class="w-full min-w-[980px] table-auto text-left">
                <thead>
                  <tr>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Nom</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Identifiant</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Permission</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Langue / fuseau</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Namespace</p></th>
                    <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium">Actions</p></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$members): ?>
                    <tr>
                      <td colspan="6" class="p-6 text-center text-sm text-muted-foreground">
                        Aucun membre trouvé pour ce SIRET.
                      </td>
                    </tr>
                  <?php endif; ?>

                  <?php foreach ($members as $member): ?>
                    <?php
                      $memberId = (int)$member['id'];
                      $memberPermId = (int)$member['perm_id'];
                      $fullName = trim((string)($member['civilite'] ?? '') . ' ' . (string)($member['prenom'] ?? '') . ' ' . (string)($member['nom'] ?? ''));
                      $rowSearch = strtolower(implode(' ', [
                          $member['civilite'] ?? '',
                          $member['prenom'] ?? '',
                          $member['nom'] ?? '',
                          $member['username'] ?? '',
                          permission_label($memberPermId),
                          (string)$memberPermId,
                          $member['langue_code'] ?? '',
                          $member['timezone'] ?? '',
                          $member['k8s_namespace'] ?? '',
                      ]));
                      $isEditing = $editingMember && (int)$editingMember['id'] === $memberId;
                    ?>
                    <tr class="member-row" data-member-row data-search="<?= h($rowSearch) ?>">
                      <td class="border-surface border-b p-4 align-top">
                        <p class="text-default block text-sm font-semibold"><?= h($fullName !== '' ? $fullName : 'Sans nom renseigné') ?></p>
                        <p class="cell-muted">ID interne #<?= $memberId ?></p>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <p class="text-default block text-sm font-semibold"><?= h((string)$member['username']) ?></p>
                        <p class="cell-muted">SIRET <?= h((string)$member['siret']) ?></p>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <span class="inline-flex items-center justify-center rounded-md border px-2.5 py-1 text-xs font-medium <?= permission_badge_class($memberPermId) ?>">
                          #<?= $memberPermId ?>
                        </span>
                        <p class="mt-2 text-sm font-medium"><?= h(permission_label($memberPermId)) ?></p>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <p class="text-default block text-sm font-semibold"><?= h((string)($member['langue_code'] ?: 'n/d')) ?></p>
                        <p class="cell-muted"><?= h((string)($member['timezone'] ?: 'Fuseau non renseigné')) ?></p>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <p class="text-default block text-sm font-semibold"><?= h((string)($member['k8s_namespace'] ?: '—')) ?></p>
                      </td>
                      <td class="border-surface border-b p-4 align-top">
                        <?php if ($canEdit): ?>
                          <a
                            href="?<?= h(http_build_query(array_filter(['q' => $search, 'edit' => $memberId], static fn($value) => $value !== null && $value !== ''))) ?>"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground h-9 px-4 py-2"
                          >
                            Éditer
                          </a>
                        <?php else: ?>
                          <span class="cell-muted">Aucune action</span>
                        <?php endif; ?>
                      </td>
                    </tr>

                    <?php if ($isEditing): ?>
                      <tr data-member-row data-search="<?= h($rowSearch) ?>">
                        <td colspan="6" class="border-surface border-b bg-background/60 p-4">
                          <form method="post" class="space-y-4">
                            <input type="hidden" name="action" value="save_member">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="member_id" value="<?= $memberId ?>">
                            <input type="hidden" name="return_q" value="<?= h($search) ?>">

                            <div class="edit-grid">
                              <div>
                                <label class="mb-2 block text-sm font-medium">Civilité</label>
                                <input type="text" name="civilite" value="<?= h((string)$member['civilite']) ?>" class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                              <div>
                                <label class="mb-2 block text-sm font-medium">Prénom</label>
                                <input type="text" name="prenom" value="<?= h((string)$member['prenom']) ?>" required class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                              <div>
                                <label class="mb-2 block text-sm font-medium">Nom</label>
                                <input type="text" name="nom" value="<?= h((string)$member['nom']) ?>" required class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                              <div>
                                <label class="mb-2 block text-sm font-medium">Identifiant</label>
                                <input type="text" name="username" value="<?= h((string)$member['username']) ?>" required class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                              <div class="field-full">
                                <label class="mb-2 block text-sm font-medium">Permission (perm_id 0-255)</label>
                                <select name="perm_id" class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                                  <?php foreach ($permissionOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $value === $memberPermId ? 'selected' : '' ?>><?= h(sprintf('%03d - %s', $value, $label)) ?></option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                              <div>
                                <label class="mb-2 block text-sm font-medium">Langue</label>
                                <input type="text" name="langue_code" value="<?= h((string)$member['langue_code']) ?>" class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                              <div>
                                <label class="mb-2 block text-sm font-medium">Fuseau horaire</label>
                                <input type="text" name="timezone" value="<?= h((string)$member['timezone']) ?>" class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                              <div class="field-full">
                                <label class="mb-2 block text-sm font-medium">Namespace Kubernetes</label>
                                <input type="text" name="k8s_namespace" value="<?= h((string)$member['k8s_namespace']) ?>" class="border-input h-10 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                              </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3">
                              <a href="?<?= h(http_build_query(array_filter(['q' => $search], static fn($value) => $value !== null && $value !== ''))) ?>" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2">
                                Annuler
                              </a>
                              <button type="submit" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2">
                                Enregistrer
                              </button>
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
        </section>
      </div>
    </main>
  </div>

  <script>
    (function () {
      var input = document.getElementById('teamSearch');
      var rows = Array.prototype.slice.call(document.querySelectorAll('[data-member-row]'));
      if (!input || rows.length === 0) {
        return;
      }

      function normalize(value) {
        return (value || '').toString().toLowerCase().trim();
      }

      function filterRows() {
        var term = normalize(input.value);
        rows.forEach(function (row) {
          var haystack = normalize(row.getAttribute('data-search'));
          row.hidden = term !== '' && haystack.indexOf(term) === -1;
        });
      }

      input.addEventListener('input', filterRows);
      filterRows();
    })();
  </script>

  <script>
    window.K8S_API_URL = '../k8s/k8s_api.php';
    window.K8S_UI_BASE = './pages/';
  </script>
  <script src="../k8s/k8s-menu.js" defer></script>
</body>
</html>
