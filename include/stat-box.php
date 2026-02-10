<div class="stats">
  <!-- 1ère stat : Total des visiteurs ce mois-ci -->
  <div class="stat-box" id="visitors-stat">
    <div class="stat-line">
      <span class="stat-number" id="visitors-count">--</span>
      <div class="stat-text">
        <span class="stat-label">Visiteurs</span>
        <span class="stat-subtext">Ce mois-ci sur l'ensemble de vos domaines</span>
      </div>
    </div>
  </div>

  <!-- 2ème stat : Nextcloud -->
  <div class="stat-box" id="nextcloud-stat">
    <div class="stat-line">
      <span class="stat-number" id="nextcloud-storage">--</span>
      <div class="stat-text">
        <span class="stat-label" id="nextcloud-text">Nextcloud</span>
        <span class="stat-subtext" id="nextcloud-subtext">Chargement...</span>
      </div>
    </div>
  </div>

  <!-- 3ème stat : Uptime pour chaque domaine -->
  <div id="uptime-stats-container"></div>

  <!-- 4ème stat : Nombre total de domaines liés -->
  <div class="stat-box">
    <div class="stat-line">
      <span class="stat-number"><?php echo isset($domains) ? count($domains) : 0; ?></span>
      <div class="stat-text">
        <span class="stat-label">Domaines</span>
        <span class="stat-subtext">lies a votre compte</span>
      </div>
    </div>
  </div>
</div>

<!-- Script pour récupérer et afficher les statistiques -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  const domains = <?php echo json_encode($domains); ?>;
  const uptimeContainer = document.getElementById('uptime-stats-container');
  // ---------------------------------------
  // Stat 1 : Total des visiteurs ce mois-ci
  // ---------------------------------------
  let totalVisitors = 0;
  let requestsCompleted = 0;
  const currentDate = new Date();
  const currentMonth = currentDate.getFullYear() + '-' + (currentDate.getMonth() + 1).toString().padStart(2, '0');

  if (domains.length === 0) {
    document.getElementById('visitors-count').textContent = '0';
  }

  domains.forEach(function(domain) {
    const monthlyEndpoint = `https://${domain.name}/index.php/wp-json/my_site_stats/v1/monthly-views?domain=${encodeURIComponent(domain.name)}`;

    fetch(monthlyEndpoint)
      .then(response => response.json())
      .then(monthlyData => {
        const record = monthlyData.find(item => item.month === currentMonth);
        if (record) {
          totalVisitors += parseInt(record.total_views, 10);
        }
      })
      .catch(error => {
        console.error("Erreur pour le domaine " + domain.name, error);
      })
      .finally(() => {
        requestsCompleted++;
        if (requestsCompleted === domains.length) {
          document.getElementById('visitors-count').textContent = totalVisitors;
        }
      });
  });
  // ---------------------------------------
  // Stat 2 : Nextcloud usage
  // ---------------------------------------
  fetch('https://nextcloud.openhebergement.cloud/index.php/apps/usage/api/v1/usage')
    .then(response => response.json())
    .then(data => {
      let used = data?.ocs?.data?.usage?.used ?? data.usage.used;
      let total = data?.ocs?.data?.usage?.total ?? data.usage.total;
      let percent = Math.round((used / total) * 100);
      document.getElementById('nextcloud-storage').textContent = percent + '%';
      document.getElementById('nextcloud-subtext').textContent = formatBytes(used) + " utilisé sur " + formatBytes(total);
    })
    .catch(error => {
      console.error("Erreur lors de la récupération des données Nextcloud", error);
      document.getElementById('nextcloud-storage').textContent = '--';
      document.getElementById('nextcloud-text').textContent = 'Erreur';
      document.getElementById('nextcloud-subtext').textContent = 'Impossible de charger les donnees';
    });
  // ---------------------------------------
  // Stat 3 : Uptime pour chaque domaine
  // ---------------------------------------
  domains.forEach((domain) => {
    const uptimeEndpoint = `https://${domain.name}/index.php/wp-json/my_site_stats/v1/uptime`;

    fetch(uptimeEndpoint)
      .then(response => {
        if (!response.ok) throw new Error('Réponse non valide');
        return response.json();
      })
      .then(data => {
        if (!data || typeof data.uptime_hours === 'undefined') throw new Error('Données invalides');

        // Création de la stat-box
        const statBox = document.createElement('div');
        statBox.className = 'stat-box uptime-stat';
        statBox.innerHTML = `
          <div class="stat-line">
            <span class="stat-number">${data.uptime_hours}</span>
            <div class="stat-text">
              <span class="stat-label">H</span>
              <span class="stat-subtext">de fonctionnement (${domain.name})</span>
            </div>
          </div>
        `;
        uptimeContainer.appendChild(statBox);
      })
      .catch(error => {
        console.warn(`Erreur pour l'API uptime de ${domain.name}:`, error);
      });
  });
});
</script>
