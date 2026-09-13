<?php
/* =====================================================================
   Racine du portail -> page de connexion.
   ---------------------------------------------------------------------
   URL canonique : /connexion (sans extension, cf. .htaccess qui réécrit
   vers pages/connexion.php).

   NE JAMAIS pointer ici vers /connexion.php : cette URL n'existe pas à la
   racine du docroot, et si quelque chose la sert en 302 on obtient une
   redirection vers elle-même. Les sondes Kubernetes suivent au maximum
   10 redirections, échouent, et la liveness redémarre le conteneur en
   boucle (CrashLoopBackOff).
   ===================================================================== */

header('Location: /connexion', true, 302);
exit;
