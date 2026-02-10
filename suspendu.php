<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];
$alert_type = "danger";
$alert_title = "Compte Suspendu";
	<?php include("include/header.php") ?>
        <?php if (isset($info)) { $alert_message = $info; include("include/alert-info.php"); } ?>


<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - OpenHebergement</title>
  <link rel="stylesheet" href="dashboard.css">
  <!-- Ajoutez Chart.js si nécessaire -->
</head>
<body>
	<?php include("header.php") ?>


    <!-- Contenu principal -->
    <main class="main-content">
	<?php include("alert-info.php") ?>
    </main>
  </div>
</body>
</html>
