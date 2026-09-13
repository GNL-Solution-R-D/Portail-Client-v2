<?php
/* =====================================================================
   Sonde de santé (liveness / readiness Kubernetes).
   ---------------------------------------------------------------------
   Répond 200 immédiatement : pas de session, pas de redirection, aucune
   dépendance externe (Keycloak, API portail, base). Elle vérifie qu'Apache
   et PHP répondent, et rien d'autre — c'est exactement ce qu'une sonde
   doit tester.

   À utiliser comme cible des probes :
     httpGet: { path: /healthz.php, port: 80 }
   ===================================================================== */

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "ok\n";
