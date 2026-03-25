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

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function entrepriseDisplayValue($value): string
{
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : '—';
}

function entrepriseBoolLabel($value, string $yes = 'Oui', string $no = 'Non'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return ((int) $value) > 0 ? $yes : $no;
}

function entrepriseDateDisplay($value): string
{
    $timestamp = dolbarApiDateToTimestamp($value);
    if ($timestamp !== null) {
        return date('d/m/Y', $timestamp);
    }

    return '—';
}

function entrepriseMoneyDisplay($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, 2, ',', ' ') . ' €';
}

function entrepriseExtractRows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'thirdparties', 'societes', 'companies'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

$company = null;
$companyError = null;
$companyErrorCode = null;

try {
    $userContext = $_SESSION['user'];
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $fullUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($fullUser)) {
            $userContext = array_merge($fullUser, $userContext);
        }
    }

    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $userContext);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $userContext);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $userContext);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $userContext);
    $sessionToken = trim((string)($_SESSION['dolibarr_token'] ?? ''));

    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolibarr incomplète (URL manquante).', 0);
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $query = ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 200];

    if ($sessionToken !== '') {
        $rawCompanies = dolbarApiCallWithToken($apiUrl, '/thirdparties', $sessionToken, 'GET', $query, [], 12);
    } elseif ($login !== null && $password !== null) {
        $token = dolbarApiLoginToken($apiUrl, $login, $password, 8);
        $rawCompanies = dolbarApiCallWithToken($apiUrl, '/thirdparties', $token, 'GET', $query, [], 12);
    } elseif ($apiKey !== null) {
        $rawCompanies = dolbarApiCall($apiUrl, '/thirdparties', $apiKey, 'GET', $query, [], 12);
    } else {
        throw new RuntimeException(
            'Configuration Dolibarr incomplète (renseigner login/mot de passe ou clé API).',
            0
        );
    }

    $rows = entrepriseExtractRows($rawCompanies);
    $rows = array_values(array_filter($rows, static fn($row): bool => is_array($row)));

    if (!empty($rows)) {
        $company = $rows[0];
    }
} catch (Throwable $e) {
    $companyError = $e->getMessage();
    $companyErrorCode = dolbarApiExtractErrorCode($e) ?? 'DLB';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Mon entreprise - GNL Solution</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>

  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}

    .company-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;}
    .summary-card{border:1px solid rgba(148,163,184,.2);border-radius:.85rem;padding:.85rem 1rem;background:rgba(148,163,184,.08);}
    .summary-label{display:block;font-size:.73rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted-foreground, #64748b);}
    .summary-value{display:block;margin-top:.32rem;font-size:1rem;font-weight:700;color:var(--foreground, #0f172a);}

    .company-sections{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;}
    .section-card{border:1px solid rgba(148,163,184,.2);border-radius:.85rem;padding:1rem;background:rgba(255,255,255,.45);}
    .section-title{font-size:.86rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted-foreground, #64748b);margin-bottom:.8rem;}

    .company-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem 1rem;}
    .company-field{border:1px solid rgba(148,163,184,.2);border-radius:.75rem;padding:.8rem .9rem;background:rgba(255,255,255,.65);}
    .company-label{display:block;font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted-foreground, #64748b);margin-bottom:.3rem;}
    .company-value{font-size:.95rem;font-weight:500;color:var(--foreground, #0f172a);word-break:break-word;}

    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
      .company-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
      .company-sections{grid-template-columns:1fr;}
      .company-grid{grid-template-columns:1fr;}
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <?php include('../include/menu.php'); ?>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">Mon entreprise</h1>
              <p class="text-sm text-muted-foreground mt-1">Informations synchronisées depuis Dolibarr.</p>
            </div>
          </div>

          <?php if ($companyError !== null): ?>
            <div class="mx-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 ml-8 mr-8">
              Impossible de charger les informations entreprise (code: <?php echo h($companyErrorCode); ?>). <?php echo h($companyError); ?>
            </div>
          <?php elseif ($company === null): ?>
            <div class="mx-6 rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
              Aucune fiche entreprise trouvée pour votre compte.
            </div>
          <?php else: ?>
            <?php
              $name = $company['name'] ?? $company['nom'] ?? $company['socname'] ?? null;
              $codeClient = $company['code_client'] ?? $company['codeclient'] ?? null;
              $siret = $company['siret'] ?? $company['idprof2'] ?? ($company['idprof']['2'] ?? null);
              $siren = $company['siren'] ?? $company['idprof1'] ?? ($company['idprof']['1'] ?? null);
              $tva = $company['tva_intra'] ?? null;
              $email = $company['email'] ?? null;
              $phone = $company['phone'] ?? null;
              $mobile = $company['phone_mobile'] ?? null;
              $fax = $company['fax'] ?? null;
              $website = $company['url'] ?? null;
              $effectif = $company['effectif'] ?? null;
              $typent = $company['typent_code'] ?? $company['typent_label'] ?? null;
              $commercial = $company['commercial_id'] ?? null;
              $isClient = $company['client'] ?? null;
              $isSupplier = $company['fournisseur'] ?? null;
              $status = $company['status'] ?? null;
              $encours = $company['encours_client'] ?? null;
              $createdAt = $company['date_creation'] ?? $company['datec'] ?? null;
              $updatedAt = $company['date_modification'] ?? $company['tms'] ?? null;
              $address = trim(implode(' ', array_filter([
                $company['address'] ?? null,
                $company['zip'] ?? null,
                $company['town'] ?? null,
                $company['country'] ?? null,
              ], static fn($value): bool => trim((string)$value) !== '')));
            ?>

            <div class="px-6 pb-2 flex flex-col gap-4">
              <div class="company-summary-grid">
                <div class="summary-card"><span class="summary-label">Type compte</span><span class="summary-value"><?php echo h(entrepriseBoolLabel($isClient, 'Client', 'Prospect')); ?></span></div>
                <div class="summary-card"><span class="summary-label">Statut actif</span><span class="summary-value"><?php echo h(entrepriseBoolLabel($status, 'Actif', 'Inactif')); ?></span></div>
                <div class="summary-card"><span class="summary-label">Encours autorisé</span><span class="summary-value"><?php echo h(entrepriseMoneyDisplay($encours)); ?></span></div>
                <div class="summary-card"><span class="summary-label">Dernière mise à jour</span><span class="summary-value"><?php echo h(entrepriseDateDisplay($updatedAt)); ?></span></div>
              </div>

              <div class="company-sections">
                <section class="section-card">
                  <h2 class="section-title">Informations légales</h2>
                  <div class="company-grid">
                    <div class="company-field"><span class="company-label">Raison sociale</span><span class="company-value"><?php echo h(entrepriseDisplayValue($name)); ?></span></div>
                    <div class="company-field"><span class="company-label">Code client</span><span class="company-value"><?php echo h(entrepriseDisplayValue($codeClient)); ?></span></div>
                    <div class="company-field"><span class="company-label">SIRET</span><span class="company-value"><?php echo h(entrepriseDisplayValue($siret)); ?></span></div>
                    <div class="company-field"><span class="company-label">SIREN</span><span class="company-value"><?php echo h(entrepriseDisplayValue($siren)); ?></span></div>
                    <div class="company-field"><span class="company-label">TVA intracom</span><span class="company-value"><?php echo h(entrepriseDisplayValue($tva)); ?></span></div>
                    <div class="company-field"><span class="company-label">Type d'entreprise</span><span class="company-value"><?php echo h(entrepriseDisplayValue($typent)); ?></span></div>
                    <div class="company-field" style="grid-column:1/-1;"><span class="company-label">Adresse</span><span class="company-value"><?php echo h(entrepriseDisplayValue($address)); ?></span></div>
                  </div>
                </section>

                <section class="section-card">
                  <h2 class="section-title">Contact & suivi</h2>
                  <div class="company-grid">
                    <div class="company-field"><span class="company-label">Téléphone</span><span class="company-value"><?php echo h(entrepriseDisplayValue($phone)); ?></span></div>
                    <div class="company-field"><span class="company-label">Mobile</span><span class="company-value"><?php echo h(entrepriseDisplayValue($mobile)); ?></span></div>
                    <div class="company-field"><span class="company-label">Fax</span><span class="company-value"><?php echo h(entrepriseDisplayValue($fax)); ?></span></div>
                    <div class="company-field"><span class="company-label">Email</span><span class="company-value"><?php echo h(entrepriseDisplayValue($email)); ?></span></div>
                    <div class="company-field"><span class="company-label">Site web</span><span class="company-value"><?php echo h(entrepriseDisplayValue($website)); ?></span></div>
                    <div class="company-field"><span class="company-label">Commercial</span><span class="company-value"><?php echo h(entrepriseDisplayValue($commercial)); ?></span></div>
                    <div class="company-field"><span class="company-label">Effectif</span><span class="company-value"><?php echo h(entrepriseDisplayValue($effectif)); ?></span></div>
                    <div class="company-field"><span class="company-label">Fournisseur</span><span class="company-value"><?php echo h(entrepriseBoolLabel($isSupplier)); ?></span></div>
                    <div class="company-field"><span class="company-label">Créée le</span><span class="company-value"><?php echo h(entrepriseDateDisplay($createdAt)); ?></span></div>
                  </div>
                </section>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <script src="../assets/js/menu.js"></script>
</body>
</html>
