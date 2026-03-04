<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];

// Inclusion du fichier de configuration qui crée $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once '../config_loader.php';

// Récupérer les domaines PowerDNS pour l'utilisateur
$user_account = $_SESSION['user']['id'];
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - OpenHebergement</title>
  <link rel="stylesheet" href="assets/styles/dashboard.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <?php include("../include/header.php"); ?>

  <!-- Conteneur principal avec sidebar et contenu -->
  <div class="container">
    <!-- Sidebar (menu à gauche) -->
    <aside class="sidebar">
      <nav>
        <!-- Première section du menu -->
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
      <?php include("../include/stat-box.php"); ?>
      <?php 
      // Affichage d'une alerte en cas d'absence de domaine
      if (!$domains) {
          include("../include/alert-info.php");
      }
      ?>

      <?php if ($domains): ?>
          <?php // include("include/alert-info.php"); ?>
        <?php foreach ($domains as $domain): ?>
          <div class="chart-container" data-domain="<?php echo htmlspecialchars($domain['name']); ?>">
            <!-- Titre et sous-titre -->
            <h4 class="chart-title">Graphique des visites pour <?php echo htmlspecialchars($domain['name']); ?></h4>
            <p class="chart-subtitle"><?php echo htmlspecialchars($domain['name']); ?></p>
            <!-- Canvas unique par domaine -->
            <canvas id="visitsChart-<?php echo htmlspecialchars($domain['id']); ?>"></canvas>
            <p class="chart-footer"></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php include("../include/footer.php"); ?>
    </main>
  </div>

  <!-- Script JavaScript pour créer un graphique par domaine -->
<script>
  // Fonctions utilitaires
function formatUTCLabel(dateString, type) {
  let isoString;
  if (type === 'hourly') {
    // Transformation en format ISO (ex: "YYYY-MM-DDTHH:00:00Z")
    isoString = dateString.replace(' ', 'T') + ':00Z';
    const dateObj = new Date(isoString);
    // Affichage en heure locale (client) sans forcer l'UTC
    return dateObj.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  } else if (type === 'daily') {
    // Pour "YYYY-MM-DD", on ajoute l'heure minimale pour obtenir une date ISO
    isoString = dateString + 'T00:00:00Z';
    const dateObj = new Date(isoString);
    // Affichage de la date en format local (client)
    return dateObj.toLocaleDateString('fr-FR');
  }
  // Pour d'autres cas, retourner la chaîne directement
  return dateString;
}

  function parseDate(dateString) {
    const parts = dateString.split('-');
    return new Date(parts[0], parts[1] - 1, parts[2]);
  }

  function diffDays(date1, date2) {
    const diffTime = Math.abs(date2 - date1);
    return diffTime / (1000 * 60 * 60 * 24);
  }

  function aggregateWeekly(dailyData) {
    let weeklyData = [];
    let weekSum = 0;
    let weekCount = 0;
    let weekStart = dailyData[0].day;
    for (let i = 0; i < dailyData.length; i++) {
      weekSum += parseInt(dailyData[i].total_views, 10);
      weekCount++;
      if (weekCount === 7 || i === dailyData.length - 1) {
        weeklyData.push({
          week: weekStart + ' au ' + dailyData[i].day,
          total_views: weekSum
        });
        weekSum = 0;
        weekCount = 0;
        if (i < dailyData.length - 1) {
          weekStart = dailyData[i + 1].day;
        }
      }
    }
    return weeklyData;
  }

  function createBarChartForDomain(canvasId, labels, data, datasetLabel) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: datasetLabel,
          data: data,
          backgroundColor: '#00aaff',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true, grid: { display: false } },
          x: { ticks: { maxRotation: 0, minRotation: 0 }, grid: { display: false } }
        },
        plugins: { legend: { display: false } }
      }
    });
  }

  // Liste des domaines injectée depuis PHP
  const domains = <?php echo json_encode($domains); ?>;
  domains.forEach(domain => {
    const domainName = domain.name;
    const canvasId = 'visitsChart-' + domain.id;
    // Construire l'URL de base de l'API pour ce domaine
    const baseEndpoint = 'https://' + domainName + '/index.php/wp-json/my_site_stats/v1/';
    
    // Récupérer les données journalières pour ce domaine
    const dailyEndpoint = baseEndpoint + 'daily-views?domain=' + encodeURIComponent(domainName);
    fetch(dailyEndpoint)
      .then(response => response.json())
      .then(dailyData => {
         if (dailyData.length === 0) {
           console.error("Aucune donnée disponible pour le domaine " + domainName);
           // On peut retirer le conteneur s'il n'y a aucune donnée
           const container = document.querySelector('.chart-container[data-domain="' + domainName + '"]');
           if (container) container.remove();
           return;
         }
         const firstDate = parseDate(dailyData[0].day);
         const lastDate  = parseDate(dailyData[dailyData.length - 1].day);
         const totalDays = diffDays(firstDate, lastDate);
         console.log("Domaine " + domainName + " - Nombre total de jours de données :", totalDays.toFixed(0));

if (totalDays < 5) {
  // Moins de 5 jours ? utiliser les données horaires, mais seulement les 24 dernières heures.
  const hourlyEndpoint = baseEndpoint + 'hourly-views?domain=' + encodeURIComponent(domainName);
  fetch(hourlyEndpoint)
    .then(response => response.json())
    .then(hourlyData => {
      // On suppose que hourlyData est trié par ordre croissant.
      const recentHourlyData = hourlyData.slice(-24);
      const labels = recentHourlyData.map(item => formatUTCLabel(item.hour, 'hourly'));
      const views  = recentHourlyData.map(item => parseInt(item.total_views, 10));
      createBarChartForDomain(canvasId, labels, views, 'Relevé horaire (UTC) pour ' + domainName);
    })
    .catch(error => console.error("Erreur avec les données horaires pour " + domainName, error));
} else if (totalDays < 150) {
           // Entre 5 jours et 5 mois ? utiliser les données journalières
           const labels = dailyData.map(item => formatUTCLabel(item.day, 'daily'));
           const views  = dailyData.map(item => parseInt(item.total_views, 10));
           createBarChartForDomain(canvasId, labels, views, 'Relevé journalier (UTC) pour ' + domainName);
         } else if (totalDays < 365) {
           // Entre 5 mois et 1 an ? agrégation hebdomadaire
           const weeklyData = aggregateWeekly(dailyData);
           const labels = weeklyData.map(item => item.week);
           const views  = weeklyData.map(item => parseInt(item.total_views, 10));
           createBarChartForDomain(canvasId, labels, views, 'Relevé hebdomadaire pour ' + domainName);
         } else {
           // 1 an ou plus ? utiliser les données mensuelles
           const monthlyEndpoint = baseEndpoint + 'monthly-views?domain=' + encodeURIComponent(domainName);
           fetch(monthlyEndpoint)
             .then(response => response.json())
             .then(monthlyData => {
               const now = new Date();
               const currentYear = now.getFullYear();
               const currentMonth = (now.getMonth() + 1).toString().padStart(2, '0');
               const currentMonthString = `${currentYear}-${currentMonth}`;
               const startMonthString = `${currentYear - 1}-${currentMonth}`;
               const filteredMonthlyData = monthlyData.filter(item => item.month >= startMonthString && item.month <= currentMonthString);
               const labels = filteredMonthlyData.map(item => item.month);
               const views  = filteredMonthlyData.map(item => parseInt(item.total_views, 10));
               createBarChartForDomain(canvasId, labels, views, 'Relevé mensuel pour ' + domainName);
             })
             .catch(error => console.error("Erreur avec les données mensuelles pour " + domainName, error));
         }
      })
      .catch(error => {
         console.error("Erreur lors de la récupération des données journalières pour " + domainName, error);
         // Retirer le conteneur de graphique en cas d'erreur
         const container = document.querySelector('.chart-container[data-domain="' + domainName + '"]');
         if (container) container.remove();
      });
  });
</script>
</body>
</html>
