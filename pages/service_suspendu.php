<?php

/**
 * pages/service_suspendu.php
 *
 * Vue « service suspendu » de la page /deployment.
 *
 * Incluse par pages/deployment.php quand le service résolu porte le statut
 * « suspended ». Un service suspendu reste VISIBLE dans la barre latérale — le
 * client doit voir ce qu'il a commandé — mais il n'est plus ni cliquable ni
 * pilotable : ni console, ni fichiers, ni variables, ni bouton d'alimentation.
 *
 * Le blocage ne tient pas à cette page. Elle ne fait qu'expliquer ; les vrais
 * verrous sont dans include/services_catalog.php (plus de href), dans
 * data/ptero_api.php et dans data/k8s_api.php, qui refusent tous les deux avant
 * d'agir. Une page d'explication qui serait le seul rempart n'en serait pas un.
 *
 * Variables attendues dans la portée : $service (entrée du catalogue).
 */

if (!isset($service) || !is_array($service)) {
    http_response_code(500);
    echo 'Contexte de service manquant.';
    exit;
}

$serviceName = (string)($service['name'] ?? '');
$productName = (string)($service['product_name'] ?? $serviceName);
$pageTitle   = ($serviceName !== '' ? $serviceName : 'Service') . ' — GNL Solution';

// 403 et non 200 : la ressource existe, elle est refusée. Le middleware Traefik
// « custom-errors » ne remplace que le corps des 5xx — un 4xx garde le sien.
http_response_code(403);

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main bg-surface">
      <div class="app-shell-offset-min-height w-full p-6">

        <div data-slot="card" class="mx-auto w-full max-w-xl rounded border bg-card text-card-foreground p-8 shadow-sm">

          <div class="grid h-12 w-12 place-items-center rounded-full bg-secondary text-amber-700">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="10" y1="15" x2="10" y2="9"></line>
              <line x1="14" y1="15" x2="14" y2="9"></line>
            </svg>
          </div>

          <h1 class="mt-5 text-xl font-semibold"><?= t('Service suspendu') ?></h1>

          <p class="mt-3 text-sm text-muted-foreground">
            <?= t('Le service') ?>
            <span class="font-medium text-foreground"><?= htmlspecialchars($serviceName, ENT_QUOTES, 'UTF-8') ?></span><?php
              if ($productName !== '' && $productName !== $serviceName): ?>
              <span class="text-muted-foreground">(<?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>)</span><?php
              endif; ?>
            <?= t("est actuellement suspendu. Sa page de gestion n'est donc pas accessible : ni console, ni fichiers, ni démarrage.") ?>
          </p>

          <p class="mt-3 text-sm text-muted-foreground">
            <?= t("Vos données ne sont pas supprimées. Le service redevient accessible dès la levée de la suspension. Une facture en attente en est la cause la plus fréquente.") ?>
          </p>

          <div class="mt-6 flex flex-wrap gap-2">
            <a href="./facture"
              class="inline-flex h-9 items-center justify-center rounded bg-primary px-3 text-sm font-medium text-primary-foreground transition-all hover:opacity-90"><?= t('Mes factures') ?></a>
            <a href="./abonnements"
              class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Mes abonnements') ?></a>
            <a href="./tickets"
              class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Contacter le support') ?></a>
          </div>

        </div>

      </div>
    </main>
  </div>

  <script src="../assets/js/services_menu.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/services_menu.js') ?>" defer></script>
</body>
</html>
