<?php
session_start();
require_once 'config_loader.php';

require_once 'include/csrf.php';
        // Vérifier que le fichier a bien été envoyé
$token = $_POST["csrf_token"] ?? "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !verify_csrf_token($token)) {
    http_response_code(403);
    exit("Invalid CSRF token");
}
            $allowedMime = ['application/pdf'];
            $finfo       = new finfo(FILEINFO_MIME_TYPE);
            $mimeType    = $finfo->file($tmpName);
            $extension   = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($sizeBytes > 5 * 1024 * 1024) {
                $message = "Fichier trop volumineux.";
            } elseif ($extension !== 'pdf' || !in_array($mimeType, $allowedMime)) {
                $message = "Seuls les fichiers PDF sont autorisés.";
                // Créer le répertoire d'upload s'il n'existe pas
                $uploadDir = 'uploads/'; // envisager un répertoire hors web
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                // Nom unique pour éviter les doublons
                do {
                    $uniqueName = uniqid('', true) . '.pdf';
                    $filePath   = $uploadDir . $uniqueName;
                } while (file_exists($filePath));

                $sizeMo = round($sizeBytes / (1024 * 1024), 2) . ' Mo';

                // Déplacer le fichier uploadé
                if (move_uploaded_file($tmpName, $filePath)) {
                    $stmtInsert = $pdo->prepare("INSERT INTO documents (user_id, section, name, size, path) VALUES (?, ?, ?, ?, ?)");
                    $stmtInsert->execute([$selected_user_id, $section, $docName, $sizeMo, $filePath]);
                    $message = "Document ajouté avec succes !";
                } else {
                    $message = "Erreur lors du déplacement du fichier.";
                }
    if (empty($selected_user_id)) {
        $message = "Veuillez sélectionner un utilisateur.";
    } else {
        // Vérifier que le fichier a bien été envoyé
        if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
            $tmpName      = $_FILES['file']['tmp_name'];
            $originalName = $_FILES['file']['name'];
            $sizeBytes    = $_FILES['file']['size'];
            $sizeMo       = round($sizeBytes / (1024 * 1024), 2) . ' Mo';

            // Créer le répertoire d'upload s'il n'existe pas
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Chemin final du fichier
            $filePath = $uploadDir . basename($originalName);

            // Déplacer le fichier uploadé
            if (move_uploaded_file($tmpName, $filePath)) {
                // Insérer en base de données avec le user_id sélectionné
                $stmtInsert = $pdo->prepare("INSERT INTO documents (user_id, section, name, size, path) VALUES (?, ?, ?, ?, ?)");
                $stmtInsert->execute([$selected_user_id, $section, $docName, $sizeMo, $filePath]);
                $message = "Document ajoute avec succes !";
            } else {
                $message = "Erreur lors du deplacement du fichier.";
            }
        } else {
            $message = "Erreur lors de l'upload du fichier.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Ajouter un document - OpenHebergement</title>
  <link rel="stylesheet" href="documents.css">
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    form { max-width: 400px; margin: 0 auto; }
    label { display: block; margin-top: 15px; }
    input, select { width: 100%; padding: 8px; margin-top: 5px; }
    button { margin-top: 15px; padding: 10px 15px; }
    .message { margin-top: 20px; color: green; }
  </style>
</head>
<body>
  <h1>Ajouter un document</h1>
  <?php if (!empty($message)): ?>
    <p class="message"><?php echo htmlspecialchars($message); ?></p>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
    <!-- Choix de l'utilisateur -->
    <label for="user_id">Utilisateur :</label>
    <select name="user_id" id="user_id" required>
      <option value="">-- Choisir un utilisateur --</option>
      <?php foreach ($users as $userOption): ?>
        <option value="<?php echo htmlspecialchars($userOption['id']); ?>">
          <?php echo htmlspecialchars($userOption['name'] . " (" . $userOption['siren'] . ")"); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="section">Section :</label>
    <select name="section" id="section" required>
      <option value="">-- Choisir une section --</option>
      <option value="Attestation de Cotisation">Attestation de Cotisation</option>
      <option value="Document Administratif autre">Document Administratif autre</option>
      <option value="Model">Model</option>
    </select>

    <label for="name">Nom du document :</label>
    <input type="text" name="name" id="name" required>

    <label for="file">Fichier :</label>
    <input type="file" name="file" id="file" required>

    <button type="submit">Ajouter</button>
  </form>
</body>
</html>
