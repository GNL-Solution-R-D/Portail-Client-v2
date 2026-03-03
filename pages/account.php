<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$name = $_SESSION['user']['name'];
$siren = $_SESSION['user']['siren'];
$structure_type = $_SESSION['user']['structure_type'];


require_once 'include/csrf.php';

// Inclusion du fichier de configuration qui crée $pdo (base principale) et $pdo_powerdns (base PowerDNS)
require_once 'config_loader.php';

// Récupérer les domaines PowerDNS pour l'utilisateur (pour d'éventuelles utilisations ultérieures)
$user_account = $_SESSION['user']['id'];
$query_domains = $pdo_powerdns->prepare("SELECT id, name FROM domains WHERE account = ?");
$query_domains->execute([$user_account]);
$domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);

// Message d'information (exemple)
if (!$domains) {
    $info = "Aucun domaine configur� pour votre compte.";
} else {
    $info = "Maintenance en cours.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - OpenHebergement</title>
  <link rel="stylesheet" href="assets/styles/dashboard.css">
  <link rel="stylesheet" href="assets/styles/compte.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <?php include("include/header.php"); ?>

  <!-- Conteneur principal -->
  <div class="container">
    <main class="main-content">
      <div class="stats">
        <!-- Box 1: Parametres du Compte -->
        <div class="parametre-box box200" id="account-settings-box">
          <h3>Parametres du Compte</h3>
          <form action="update_account.php" method="post">
            <?php if ($structure_type == 'Association'): ?>
              <label for="nra">NRA (Numero de Reference Associatif):</label>
              <input type="text" id="nra" name="nra" value="<?php echo htmlspecialchars($siren); ?>" disabled>
            <?php elseif ($structure_type == 'micro-entreprise'): ?>
              <label for="siren">SIREN:</label>
              <input type="text" id="siren" name="siren" value="<?php echo htmlspecialchars($siren); ?>" disabled>
            <?php endif; ?>
            <label for="password">Mot de passe:</label>
            <input type="password" id="password" name="password" placeholder="Nouveau mot de passe">
            <button type="submit">Mettre a jour</button>
          </form>
        </div>

        <!-- Box 2: U2F -->
        <div class="parametre-box box200" id="u2f-settings-box">
          <h3>U2F</h3>
          <form action="update_u2f.php" method="post">
            <label for="u2f-status">Etat:</label>
            <input type="text" id="u2f-status" name="u2f_status" value="Desactive" disabled>
            <label for="u2f-type">Type:</label>
            <select name="u2f_type" id="u2f-type" style="display: block; font-size: 14px; color: #777; margin-top: 5px; width: 240px; height: 23px; margin-bottom: 10px;">
              <option value="code-sms">Code SMS</option>
              <option value="code-application">Application</option>
              <option value="physique">Cle physique</option>
            </select>
            <button type="submit">Activer</button>
          </form>
        </div>

        <!-- Box 3: Pr�f�rence d'affichage -->
        <div class="parametre-box box200" id="display-preferences-box">
          <h3>Preference d'affichage</h3>
          <form action="update_preferences.php" method="post">
            <label for="theme">Theme:</label>
            <select name="theme" id="theme" style="display: block; font-size: 14px; color: #777; margin-top: 5px; width: 240px; height: 23px; margin-bottom: 10px;">
              <option value="default">Claire</option>
              <option value="dark">Sombre</option>
            </select>
            <label for="background">Fond:</label>
            <select name="background" id="background" style="display: block; font-size: 14px; color: #777; margin-top: 5px; width: 240px; height: 23px; margin-bottom: 10px;">
              <option value="color">Couleur</option>
              <option value="image">Image</option>
            </select>
            <button type="submit">Appliquer</button>
          </form>
        </div>

        <!-- Box 4: Contact Administratif -->
        <div class="parametre-box box200" id="admin-contact-box">
          <h3>Contact Administratif</h3>
          <form action="update_admin_contact.php" method="post">
            <label for="legal_representative">Nom du Representant Legal:</label>
            <input type="text" id="legal_representative" name="legal_representative" value="JF MORGAN">
            <label for="admin-email">Email Administratif:</label>
            <input type="email" id="admin-email" name="admin_email" value="johndoe@example.com">
            <button type="submit">Mettre a jour</button>
          </form>
        </div>

        <!-- Box 5: Contact Technique -->
        <div class="parametre-box box200" id="tech-contact-box">
          <h3>Contact Technique</h3>
          <form action="update_tech_contact.php" method="post">
            <label for="tech-name">Nom du R.S.I (si concerne):</label>
            <input type="text" id="tech-name" name="tech_name" value="JF MORGAN">
            <label for="tech-email">Email Technique:</label>
            <input type="email" id="tech-email" name="tech_email" value="johndoe@example.com">
            <button type="submit">Mettre a jour</button>
          </form>
        </div>

        <!-- Box 6: Domiciliation -->
        <div class="parametre-box box200" id="domiciliation-box">
          <h3>Domiciliation</h3>
          <form action="update_domiciliation.php" method="post">
            <label for="street">Rue:</label>
            <input type="text" id="street" name="street" value="12 avenue Gerard">
            <label for="city">Code Postal, Ville:</label>
            <input type="text" id="postal_code" style="width: 70px;" name="postal_code" value="25000" required>
	    <input type="text" id="city" style="width: 165px;" name="city" value="BESANCON">
            <button type="submit">Mettre a jour</button>
          </form>
        </div>
      </div>
      <?php include("include/footer.php"); ?>
    </main>
  </div>
</body>
</html>
