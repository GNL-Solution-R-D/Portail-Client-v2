<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$name = $_SESSION["user"]["name"];
$structure_type = $_SESSION["user"]["structure_type"];
// Lecture des filtres
$filterCollege = $_GET['college'] ?? '';
$filterCotisation = $_GET['cotisation_type'] ?? '';
$filterStatut = $_GET['statut'] ?? '';

// Récupération des données utilisateurs
$sql = "SELECT * FROM users";
$conditions = [];
$params = [];
if ($filterCollege !== '') {
    $conditions[] = "college = ?";
    $params[] = $filterCollege;
}
if ($filterCotisation !== '') {
    $conditions[] = "cotisation_type = ?";
    $params[] = $filterCotisation;
}
if ($filterStatut !== '') {
    if ($filterStatut === 'paye') {
        $conditions[] = "cotisation_ok = 1 AND suspendu = 0 AND cotisation_type <> 'Exonere'";
    } elseif ($filterStatut === 'impaye') {
        $conditions[] = "cotisation_ok = 0 AND suspendu = 0 AND cotisation_type <> 'Exonere'";
    } elseif ($filterStatut === 'exempt') {
        $conditions[] = "cotisation_type = 'Exonere'";
    }
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY college, name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// Groupes par collège
    if ($u['suspendu']) return ['SUSPENDU', 'status-suspendu'];
    if ($u['cotisation_type'] == 'Exonere') return ['EXEMPT', 'status-exempt'];
    return $u['cotisation_ok'] ? ['PAYE', 'status-paye'] : ['IMPAYE', 'status-impaye'];
// Calculs statistiques
$total = count($users);
$payes = count(array_filter($users, fn($u) => $u['cotisation_ok'] == 1));
$impayes = count(array_filter($users, fn($u) => $u['cotisation_ok'] == 0 && $u['suspendu'] == 0));
$exempts = count(array_filter($users, fn($u) => $u['cotisation_type'] == 'Exonere'));

// Groupes par college
$groupes = ['WEB' => [], 'CLOUD' => [], 'WEB-CLOUD' => []];
foreach ($users as $user) {
    $groupes[$user['college']][] = $user;
}

function getStatutLabel($u) {
    if ($u['suspendu']) return ['SUSPENDU', '#ffa70080'];
    if ($u['cotisation_type'] == 'Exonere') return ['EXEMPT', '#8080806b'];
    return $u['cotisation_ok'] ? ['PAYE', 'lightgreen'] : ['IMPAYE', '#ee9090ba'];
}

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Cotisation Gestion</title>
    <link rel="stylesheet" href="/administration/css/style.css">
  <link rel="stylesheet" href="/administration/css/dashboard.css">
  <link rel="stylesheet" href="/administration/css/dns.css">
  <link rel="stylesheet" href="/assets/styles/formcss.css">

</head>
<body>
  <?php include("./include/header.php"); ?>
  <!-- Conteneur principal avec sidebar et contenu -->
  <div class="container">
    <!-- Sidebar (menu Ã  gauche) -->
    <aside class="sidebar">
      <nav>
        <!-- PremiÃ¨re section du menu -->
        <ul class="menu-items">
          <li class="active"><a class="active" href="dashboard.php">Tableau de Bord</a></li>
          <li class="categorie">Mes Services</li>
          <li><a href="siteweb.php">Site Web</a></li>
          <!-- <li><a href="domaines.php">Domaines</a></li> -->
          <!-- <li><a href="suite_doc.php">Suite Doc, Tableur</a></li> -->
          <li><a href="stockage.php">Stockage Cloud</a></li>
        </ul>
        <!-- Bloc danger -->
        <div class="danger-zone">
          <div class="danger-block">
            <ul class="menu-items">
              <li class="categorie">Technique</li>
              <li><a href="console.php">Console</a></li>
              <li><a href="dns.php">Zone DNS</a></li>
              <li><a href="fichiers.php">Fichier</a></li>
              <!-- <li><a href="sftp.php">SFTP</a></li> -->
            </ul>
          </div>
          <p class="danger-text">Zone de danger</p>
        </div>
        <!-- Seconde section du menu -->
        <ul class="menu-items">
          <li class="categorie">Administratif</li>
          <li><a href="documents.php">Documents</a></li>
          <!-- <li><a href="cotisation.php">Cotisation</a></li> -->
        </ul>
      </nav>
    </aside>

    <!-- Contenu principal -->
    <main class="main-content">





      <div class="stats">
  <div class="stat-box" id="visitors-stat">
    <div class="stat-line">
      <span class="stat-number" id="visitors-count"><?= $total ?></span>
      <div class="stat-text">
        <span class="stat-label">Utilisateurs</span>
        <span class="stat-subtext">Actifs</span>
      </div>
    </div>
  </div>


  <div class="stat-box" id="paye-stat">
    <div class="stat-line">
      <span class="stat-number" id="paye-count"><?= $payes ?></span>
      <div class="stat-text">
        <span class="stat-label" id="paye-text">Cotisation</span>
        <span class="stat-subtext" id="paye-subtext">Paye</span>
      </div>
    </div>
  </div>


  <div class="stat-box" id="impaye-stat">
    <div class="stat-line">
      <span class="stat-number" id="impaye-count"><?= $impayes ?></span>
      <div class="stat-text">
        <span class="stat-label" id="impaye-text">Cotisation</span>
        <span class="stat-subtext" id="impaye-subtext">Impaye</span>
      </div>
    </div>
  </div>


  <div class="stat-box">
    <div class="stat-line">
      <span class="stat-number"><?= $exempts ?></span>
      <div class="stat-text">
        <span class="stat-label">Exempt</span>
        <span class="stat-subtext">de cotisation</span>
      </div>
    </div>
  </div>
</div>




  <form class="filters" method="get">
        <label>Filtre par :</label>
        <select name="college">
                <option value="">Collège choisi :</option>
                <option value="WEB" <?= $filterCollege === 'WEB' ? 'selected' : '' ?>>Web</option>
                <option value="CLOUD" <?= $filterCollege === 'CLOUD' ? 'selected' : '' ?>>Cloud</option>
                <option value="WEB-CLOUD" <?= $filterCollege === 'WEB-CLOUD' ? 'selected' : '' ?>>Web-Cloud</option>
        </select>
        <input type="text" name="cotisation_type" placeholder="Type de cotisation :" value="<?= htmlspecialchars($filterCotisation) ?>">
        <select name="statut">
                <option value="">Cotisation payée :</option>
                <option value="paye" <?= $filterStatut === 'paye' ? 'selected' : '' ?>>Payée</option>
                <option value="impaye" <?= $filterStatut === 'impaye' ? 'selected' : '' ?>>Impayée</option>
                <option value="exempt" <?= $filterStatut === 'exempt' ? 'selected' : '' ?>>Exempt</option>
        </select>
        <button type="submit">Filtrer</button>
        <a href="cotisation.php" class="reset-btn">Réinitialiser</a>
    </form>

    <?php foreach ($groupes as $college => $membres): ?>
        <?php if (count($membres) === 0) continue; ?>
        <h3>College <?= htmlspecialchars($college) ?><br><small>Cotisation <?= $college ?> Europe</small></h3>
        <table>
            <thead>
                <tr><th>Nom</th><th>SIREN</th><th>Type</th><th>Type Cotisation</th><th>Montant</th><th>Statut</th></tr>
            </thead>
            <tbody>
            <?php foreach ($membres as $u): 
                $statut = getStatutLabel($u);
                $montant = match ($u['cotisation_type']) {
                    'Mensuel' => '5/mois',
                    'Trimestriel' => '15/trimestre',
                    'Annuel' => '60/an',
                    default => '-'
                };
            ?>
                <tr>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= htmlspecialchars($u['siren']) ?></td>
                    <td><?= htmlspecialchars($u['structure_type']) ?></td>
                    <td><?= htmlspecialchars($u['college']) ?></td>
                    <td><?= $montant ?></td>
                    <td class="statut <?= $statut[1] ?>">
                        <?= $statut[0] ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

      <?php include("include/footer.php"); ?>
    </main>
  </div>


</body>
</html>
