<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];

require_once 'config_loader.php';

$user_id = $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = ? ORDER BY section, id");
$stmt->execute([$user_id]);
$allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sections = [];
foreach ($allDocs as $doc) {
    $sections[$doc['section']][] = $doc;
}

if (empty($sections)) {
    $alert_type = 'info';
    $alert_title = 'Information';
    $info = "Aucun document pour l'instant.";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Documents - OpenHebergement</title>
  <link rel="stylesheet" href="assets/styles/dashboard.css">
  <link rel="stylesheet" href="assets/styles/documents.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
        <?php include("include/header.php") ?>
        <?php if (isset($info)) { $alert_message = $info; include("include/alert-info.php"); } ?>

  <!-- Conteneur principal avec sidebar et contenu -->
  <div class="container">
<!-- Sidebar (menu  gauche) -->
    <aside class="sidebar">
      <nav>
        <!-- Premire section du menu -->
        <ul class="menu-items">
          <li><a href="dashboard.php">Tableau de Bord</a></li>
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
          <li class="active"><a class="active" href="documents.php">Documents</a></li>
          <!-- <li><a href="cotisation.php">Cotisation</a></li> -->
        </ul>
      </nav>
    </aside>

    <!-- Contenu principal -->
    <main class="main-content">

        <?php if (!empty($sections)): ?>
            <?php include("include/documents-list.php") ?>
        <?php endif; ?>
        <?php include("include/footer.php") ?>

    </main>
  </div>
</body>
</html>
