<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];

// Inclusion du fichier de configuration qui cr�e $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once 'config.php';

// Utilisation de l'identifiant de l'utilisateur pour filtrer les domaines
// Le champ 'account' dans la table 'domains' de PowerDNS doit correspondre � $_SESSION['user']['id'] ou � un autre identifiant pertinent
$user_account = $_SESSION['user']['id'];

// R�cup�rer les domaines depuis la base PowerDNS via le champ 'account'
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);

// Message d'information si aucun domaine n'est configur�
if (!$domains) {
    $alert_type = "error";
    $alert_title = "Erreur";
    $info = "Aucun domaine configure pour votre compte.";
}

// R�cup�rer les records pour chaque domaine (seulement si des domaines existent)
$dns_zones = [];
if ($domains) {
    foreach ($domains as $domain) {
        $domain_id = $domain['id'];
        $domain_name = $domain['name'];
        $query_dns = $pdo_powerdns->prepare("SELECT name, ttl, type, content FROM records WHERE domain_id = ?");
        $query_dns->execute([$domain_id]);
        $dns_zones[$domain_name] = $query_dns->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>DNS Manager - OpenHebergement</title>
  <link rel="stylesheet" href="assets/styles/dashboard.css">
  <link rel="stylesheet" href="assets/styles/dns.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include("include/header.php") ?>

    <!-- Conteneur principal avec sidebar et contenu -->
    <div class="container">
        <!-- Sidebar (menu � gauche) -->
    <aside class="sidebar">
      <nav>
        <!-- Premi�re section du menu -->
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
              <li class="active"><a class="active" href="dns.php">Zone DNS</a></li>
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
            <?php
            // Si aucun domaine n'est li�, afficher le message d'information ou inclure alert-info.php
            if (!$domains) {
                include("include/alert-info.php");
            }
            ?>

            <?php if ($domains): ?>
		<?php include("include/zdns.php") ?>
            <?php endif; ?>

            <?php include("include/footer.php") ?>
        </main>
    </div>
</body>
</html>
