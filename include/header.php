<!-- Bandeau supérieur plein largeur -->
<header class="top-banner">
  <div class="header-content">
    <!-- Branding à gauche -->
    <div class="header-left">
      <div class="branding">
	<a href="dashboard.php">
        	<d class="brand-name">OpenHebergement</d>
        	<p>Portail Association &amp; Micro Entreprise</p>
	</a>
      </div>
    </div>
    <!-- Centre : Centre d'Assistance -->
    <div class="header-center">
      <a class="support" href="https://pgsi.openhebergement.fr" target="_blank">Centre d'Assistance</a>
    </div>
    <!-- Informations utilisateur à droite -->
    <div class="header-right">
      <div class="user-info" id="user-info">
        <span class="user-role"><strong><?php echo htmlspecialchars($structure_type); ?></strong></span>
        <span class="user-name"><?php echo htmlspecialchars($name); ?></span>
      </div>
    </div>
  </div>
</header>

<!-- Menu déroulant en dehors du header -->
<div class="user-dropdown" id="user-dropdown">
  <ul>
    <li><a href="account.php">Mon Compte</a></li>
    <li><a href="logout.php">Deconnexion</a></li>
  </ul>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var userInfo = document.getElementById("user-info");
    var dropdown = document.getElementById("user-dropdown");

    // Afficher le menu quand la souris entre sur user-info
    userInfo.addEventListener("mouseenter", function() {
        dropdown.style.display = "block"; // S'assurer qu'il est visible pour l'animation
        setTimeout(() => {
            dropdown.style.opacity = "1";
            dropdown.style.visibility = "visible";
        }, 10); // Petit délai pour activer l'animation
    });

    // Masquer le menu si la souris quitte user-info et user-dropdown
    function hideDropdown() {
        dropdown.style.opacity = "0";
        dropdown.style.visibility = "hidden";
        setTimeout(() => {
            dropdown.style.display = "none"; // Masquer après l'animation
        }, 200); // Temps correspondant à la transition CSS
    }

    userInfo.addEventListener("mouseleave", function() {
        setTimeout(() => {
            if (!dropdown.matches(':hover')) {
                hideDropdown();
            }
        }, 200);
    });

    dropdown.addEventListener("mouseleave", function() {
        hideDropdown();
    });

    // Ajouter un gestionnaire pour le clic sur un élément du menu
    var menuItems = dropdown.querySelectorAll("a");
    menuItems.forEach(function(item) {
        item.addEventListener("click", function() {
            // Supprimer la classe 'selected' de tous les éléments
            menuItems.forEach(function(menuItem) {
                menuItem.classList.remove("selected");
            });
            // Ajouter la classe 'selected' à l'élément cliqué
            item.classList.add("selected");
        });
    });
});
</script>
