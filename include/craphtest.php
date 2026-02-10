
  <div class="chart-container">
    <!-- Titre du graphique -->
    <h4 class="chart-title">Graphique des visites le mois dernier</h4>
    <!-- Sous-titre (ex : nom de domaine) -->
    <p class="chart-subtitle">asso.openhebergement.cloud</p>
    <!-- Canvas pour Chart.js -->
    <canvas id="visitsChart"></canvas>
    <!-- Note de bas de graphique -->
    <p class="chart-footer"></p>
  </div>

  <script>
    /**
     * Formate une date/heure reçue en chaîne (ex : "YYYY-MM-DD" ou "YYYY-MM-DD HH:00")
     * en affichage UTC pour l'utilisateur.
     */
    function formatUTCLabel(dateString, type) {
      let isoString;
      if (type === 'hourly') {
        // Exemple attendu : "YYYY-MM-DD HH:00"
        // Transformation en format ISO ("YYYY-MM-DDTHH:00:00Z")
        isoString = dateString.replace(' ', 'T') + ':00Z';
        const dateObj = new Date(isoString);
        // Affichage en heure UTC (exemple : "14:00")
        return dateObj.toLocaleTimeString('fr-FR', { timeZone: 'UTC', hour: '2-digit', minute: '2-digit' });
      } else if (type === 'daily') {
        // Pour "YYYY-MM-DD", ajoute l'heure minimale pour obtenir une date ISO UTC
        isoString = dateString + 'T00:00:00Z';
        const dateObj = new Date(isoString);
        // Affichage de la date en format localisé en UTC (exemple : "24/02/2025")
        return dateObj.toLocaleDateString('fr-FR', { timeZone: 'UTC' });
      }
      // Pour les données mensuelles ou autres, on retourne la chaîne directement
      return dateString;
    }

    /**
     * Convertit une chaîne "YYYY-MM-DD" en objet Date (en temps local) pour le calcul des écarts.
     */
    function parseDate(dateString) {
      const parts = dateString.split('-');
      return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    /**
     * Calcule la différence en jours entre deux dates.
     */
    function diffDays(date1, date2) {
      const diffTime = Math.abs(date2 - date1);
      return diffTime / (1000 * 60 * 60 * 24);
    }

    /**
     * Agrège les données journalières en relevés hebdomadaires.
     * Regroupe les relevés par blocs de 7 jours.
     */
    function aggregateWeekly(dailyData) {
      let weeklyData = [];
      let weekSum = 0;
      let weekCount = 0;
      let weekStart = dailyData[0].day; // Date de début de la semaine

      for (let i = 0; i < dailyData.length; i++) {
        weekSum += parseInt(dailyData[i].total_views, 10);
        weekCount++;
        // Dès qu'on atteint 7 jours ou la fin des données, créer le relevé hebdomadaire.
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

    /**
     * Crée un graphique en barres avec Chart.js en utilisant le canvas #visitsChart
     * et les options spécifiées. La graduation de l'axe s'adapte automatiquement.
     */
function createBarChart(labels, data, datasetLabel) {
  const ctx = document.getElementById('visitsChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: datasetLabel,
        data: data,
        backgroundColor: '#00aaff', // Couleur des barres
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            display: false // Désactive le quadrillage Y
          }
        },
        x: {
          ticks: {
            maxRotation: 0, // Chiffres à l'horizontale
            minRotation: 0
          },
          grid: {
            display: false // Désactive le quadrillage X
          }
        }
      },
      plugins: {
        legend: {
          display: false // Masque la légende
        }
      }
    }
  });
}

    /**
     * Logique adaptative pour déterminer l'endpoint à utiliser en fonction du nombre de jours couverts :
     * - Moins de 5 jours ? relevés horaires (endpoint /hourly-views)
     * - Entre 5 jours et 5 mois ? relevés journaliers (endpoint /daily-views)
     * - Entre 5 mois et 1 an ? agrégation hebdomadaire des relevés journaliers
     * - 1 an ou plus ? relevés mensuels sur l'intervalle du même mois de l'année précédente au mois actuel
     */
    fetch('https://openhebergement.fr/wp-json/my_site_stats/v1/daily-views')
      .then(response => response.json())
      .then(dailyData => {
        if (dailyData.length === 0) {
          console.error("Aucune donnée disponible.");
          return;
        }

        // On suppose que dailyData est trié par ordre croissant et contient la propriété "day" (format "YYYY-MM-DD")
        const firstDate = parseDate(dailyData[0].day);
        const lastDate  = parseDate(dailyData[dailyData.length - 1].day);
        const totalDays = diffDays(firstDate, lastDate);

        console.log("Nombre total de jours de données :", totalDays.toFixed(0));

        if (totalDays < 5) {
          // Moins de 5 jours ? utiliser les relevés horaires
          fetch('https://openhebergement.fr/index.php/wp-json/my_site_stats/v1/hourly-views')
            .then(response => response.json())
            .then(hourlyData => {
              const labels = hourlyData.map(item => formatUTCLabel(item.hour, 'hourly'));
              const views  = hourlyData.map(item => parseInt(item.total_views, 10));
              createBarChart(labels, views, 'Relevé horaire (UTC)');
            })
            .catch(error => console.error("Erreur avec les données horaires :", error));

        } else if (totalDays < 150) {
          // Entre 5 jours et 5 mois ? utiliser les relevés journaliers
          const labels = dailyData.map(item => formatUTCLabel(item.day, 'daily'));
          const views  = dailyData.map(item => parseInt(item.total_views, 10));
          createBarChart(labels, views, 'Relevé journalier (UTC)');

        } else if (totalDays < 365) {
          // Entre 5 mois et 1 an ? agrégation hebdomadaire
          const weeklyData = aggregateWeekly(dailyData);
          const labels = weeklyData.map(item => item.week);
          const views  = weeklyData.map(item => parseInt(item.total_views, 10));
          createBarChart(labels, views, 'Relevé hebdomadaire');

        } else {
          // 1 an ou plus ? utiliser les relevés mensuels sur l'intervalle allant
          // du même mois de l'année précédente au mois actuel
          fetch('https://openhebergement.fr/index.php/wp-json/my_site_stats/v1/monthly-views')
            .then(response => response.json())
            .then(monthlyData => {
              const now = new Date();
              const currentYear = now.getFullYear();
              const currentMonth = (now.getMonth() + 1).toString().padStart(2, '0');
              const currentMonthString = `${currentYear}-${currentMonth}`;
              const startMonthString = `${currentYear - 1}-${currentMonth}`; // Même mois, année précédente

              // Filtrer les données pour l'intervalle souhaité
              const filteredMonthlyData = monthlyData.filter(item => {
                return item.month >= startMonthString && item.month <= currentMonthString;
              });
              const labels = filteredMonthlyData.map(item => item.month);
              const views  = filteredMonthlyData.map(item => parseInt(item.total_views, 10));
              createBarChart(labels, views, 'Relevé mensuel');
            })
            .catch(error => console.error("Erreur avec les données mensuelles :", error));
        }
      })
      .catch(error => console.error("Erreur lors de la récupération des données journalières :", error));
  </script>
