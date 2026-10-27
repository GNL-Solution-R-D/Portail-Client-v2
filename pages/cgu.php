<?php
/* =====================================================================
   pages/cgu.php — Conditions générales d'utilisation  (/cgu)
   ---------------------------------------------------------------------
   Page publique (accessible sans connexion) : liens depuis la barre
   latérale (include/menu.php) et le pied des pages d'authentification.
   Texte identique à gnl-solution.com/cgu (Website-B2B/pages/cgu.php).
   ===================================================================== */
require_once __DIR__ . '/../include/session_bootstrap.php';
$back = (!empty($_SESSION['user']) && is_array($_SESSION['user'])) ? '/dashboard' : '/connexion';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#141a33">
<title>Conditions générales d'utilisation — GNL Solution</title>
<link rel="canonical" href="https://gnl-solution.com/cgu">
<link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32">
<style>
@font-face{font-family:Manrope;font-style:normal;font-weight:200 800;font-display:swap;src:url(/assets/gnl/fonts/Manrope-latin.woff2) format("woff2")}
body{margin:0;background:#f4f6fa;color:#141a33;font-family:Manrope,system-ui,sans-serif;font-size:15px;line-height:24px}
a{color:#1f7a4a}a:hover{color:#141a33}
section{scroll-margin-top:24px}
aside a:hover{background:#f4f6fa}
@media (max-width:860px){aside{position:static!important;flex-basis:100%!important}main{padding:24px 16px!important}article{padding:28px 22px!important}header>div{padding:20px 16px 0!important}h1{font-size:30px!important;line-height:38px!important}}
@media print{header nav,aside,.no-print,footer{display:none!important}header{background:#fff!important;color:#141a33!important}article{border:0!important;padding:0!important}}
</style>
</head>
<body>
<header style="background: #141a33; color: #ffffff;">
<div style="max-width: 1180px; margin: 0 auto; padding: 24px 40px 0; box-sizing: border-box;">
<div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
<img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution" style="width: 170px; height: auto; display: block; margin-left: -10px;">
<div style="display: flex; align-items: center; gap: 20px; font-size: 14px;">
<a href="<?= $back ?>" style="color: #c5cbd8; text-decoration: none;">← Retour à l'Espace Client</a>
</div>
</div>
<div style="padding: 48px 0 36px; display: flex; flex-direction: column; gap: 12px;">
<p style="margin: 0; display: flex; align-items: center; gap: 10px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg>INFORMATIONS LÉGALES</p>
<h1 style="margin: 0; font-size: 40px; line-height: 48px; font-weight: 800;">Conditions générales d'utilisation</h1>
<p style="margin: 0; color: #c5cbd8; font-size: 14px;">Dernière mise à jour : [DATE DE MISE EN LIGNE]</p>
</div>
<nav style="display: flex; flex-wrap: wrap; gap: 0; font-size: 14px; font-weight: 700;">
<a href="/cgu" aria-current="page" style="padding: 12px 22px; background: #f4f6fa; color: #141a33; text-decoration: none; border-radius: 3px 3px 0 0;">CGU</a>
<a href="https://gnl-solution.com/cgv" style="padding: 12px 22px; color: #c5cbd8; text-decoration: none;">CGV</a>
<a href="https://gnl-solution.com/politique-de-confidentialite" style="padding: 12px 22px; color: #c5cbd8; text-decoration: none;">Politique de confidentialité</a>
</nav>
</div>
</header>

<main style="max-width: 1180px; margin: 0 auto; padding: 40px; box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 40px; align-items: flex-start;">

<aside style="flex: 0 1 260px; position: sticky; top: 24px; display: flex; flex-direction: column; gap: 16px;">
<div style="background: #ffffff; border: 1px solid #e3e6ee; border-radius: 3px; padding: 20px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.16em; color: #5e6684;">SOMMAIRE</p>
<ol style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 2px; font-size: 14px; line-height: 20px;">
<li><a href="#art-1" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">1</span><span>Objet</span></a></li>
<li><a href="#art-2" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">2</span><span>Mentions légales</span></a></li>
<li><a href="#art-3" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">3</span><span>Acceptation</span></a></li>
<li><a href="#art-4" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">4</span><span>Accès au site</span></a></li>
<li><a href="#art-5" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">5</span><span>Compte et Espace Client</span></a></li>
<li><a href="#art-6" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">6</span><span>Propriété intellectuelle</span></a></li>
<li><a href="#art-7" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">7</span><span>Obligations de l'utilisateur</span></a></li>
<li><a href="#art-8" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">8</span><span>Signalement de contenus illicites</span></a></li>
<li><a href="#art-9" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">9</span><span>Liens hypertextes</span></a></li>
<li><a href="#art-10" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">10</span><span>Données personnelles et cookies</span></a></li>
<li><a href="#art-11" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">11</span><span>Responsabilité</span></a></li>
<li><a href="#art-12" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">12</span><span>Modification des CGU</span></a></li>
<li><a href="#art-13" style="display: flex; gap: 10px; padding: 6px 8px; color: #141a33; text-decoration: none; border-radius: 3px;"><span style="color: #1f7a4a; font-weight: 700; min-width: 18px;">13</span><span>Droit applicable</span></a></li>
</ol>
</div>
<button type="button" onclick="window.print()" class="no-print" style="width: 100%; padding: 10px; background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; color: #141a33; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer;">Imprimer / Enregistrer en PDF</button>
</aside>

<article style="flex: 1 1 560px; min-width: 0; background: #ffffff; border: 1px solid #e3e6ee; border-radius: 3px; padding: 40px 48px; display: flex; flex-direction: column; gap: 32px;">

<section id="art-1"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">1.</span> Objet</h2>
<p style="margin: 0 0 10px; color: #32385e;">Les présentes conditions générales d'utilisation (les « CGU ») définissent les conditions dans lesquelles toute personne (l'« Utilisateur ») accède au site GNL Solution, accessible à l'adresse gnl-solution.com (le « Site »), et l'utilise.</p><p style="margin: 0; color: #32385e;">Elles s'appliquent sans préjudice des <a href="https://gnl-solution.com/cgv">conditions générales de vente</a>, qui régissent les services souscrits depuis l'Espace Client.</p></section>

<section id="art-2"><h2 style="margin: 0 0 14px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">2.</span> Mentions légales</h2>
<div style="display: flex; flex-wrap: wrap; gap: 16px;">
<div style="flex: 1 1 260px; background: #f4f6fa; border-radius: 3px; padding: 18px 20px; font-size: 14px; line-height: 22px; color: #32385e;">
<p style="margin: 0 0 8px; font-size: 12px; font-weight: 700; letter-spacing: 0.16em; color: #5e6684;">ÉDITEUR</p>
<strong style="color: #141a33;">Gabin Grobost</strong>, entrepreneur individuel (EI), nom commercial GNL Solution<br>20 rue Gustave Courbet, 25000 Besançon<br>SIREN 942 358 805 · SIRET 942 358 805 00011<br>[RCS / RNE : À VÉRIFIER]<br>TVA non applicable, art. 293 B du CGI<br>contact@gnl-solution.com · 03 65 67 01 69<br>Directeur de la publication : Gabin Grobost</div>
<div style="flex: 1 1 260px; background: #f4f6fa; border-radius: 3px; padding: 18px 20px; font-size: 14px; line-height: 22px; color: #32385e;">
<p style="margin: 0 0 8px; font-size: 12px; font-weight: 700; letter-spacing: 0.16em; color: #5e6684;">HÉBERGEUR</p>
<strong style="color: #141a33;">GNL Solution</strong>, sur sa propre infrastructure<br>20 rue Gustave Courbet, 25000 Besançon, France<br>03 65 67 01 69</div>
</div></section>

<section id="art-3"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">3.</span> Acceptation des conditions</h2>
<p style="margin: 0 0 10px; color: #32385e;">L'utilisation du Site implique l'acceptation pleine et entière des présentes CGU. L'Utilisateur qui ne les accepte pas doit renoncer à utiliser le Site.</p><p style="margin: 0; color: #32385e;">GNL Solution peut refuser l'accès au Site à tout Utilisateur qui ne respecte pas les présentes CGU.</p></section>

<section id="art-4"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">4.</span> Accès au site</h2>
<p style="margin: 0 0 10px; color: #32385e;">Le Site est accessible gratuitement, 7 jours sur 7 et 24 heures sur 24, sauf interruption pour maintenance ou cas de force majeure. Les frais de matériel et de connexion restent à la charge de l'Utilisateur.</p><p style="margin: 0; color: #32385e;">L'état de disponibilité de nos services est publié en temps réel sur la page <a href="https://gnl-solution.com/stats">État des services</a>.</p></section>

<section id="art-5"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">5.</span> Compte et Espace Client</h2>
<p style="margin: 0 0 10px; color: #32385e;">La souscription et la gestion des services se font depuis l'Espace Client, qui nécessite la création d'un compte. L'Utilisateur s'engage à fournir des informations exactes et à les tenir à jour.</p><p style="margin: 0 0 10px; color: #32385e;">Il est seul responsable de la confidentialité de ses identifiants et de l'activité réalisée depuis son compte. Il prévient GNL Solution sans délai de toute utilisation non autorisée.</p><p style="margin: 0; color: #32385e;">L'Utilisateur peut demander la suppression de son compte à tout moment depuis son Espace Client ou à contact@gnl-solution.com, sous réserve des services en cours.</p></section>

<section id="art-6"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">6.</span> Propriété intellectuelle</h2>
<p style="margin: 0 0 10px; color: #32385e;">Les éléments du Site (textes, logos, marque GNL Solution, graphismes, photographies, logiciels) sont protégés par le droit de la propriété intellectuelle et restent la propriété de GNL Solution ou de ses partenaires.</p><p style="margin: 0; color: #32385e;">Toute reproduction ou exploitation sans autorisation écrite préalable est interdite et constitue une contrefaçon (articles L.335-2 et suivants du Code de la propriété intellectuelle). Les codes publiés sur notre <a href="https://github.com/GNL-Solution-R-D" rel="noopener">GitHub</a> restent soumis à leur propre licence.</p></section>

<section id="art-7"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">7.</span> Obligations de l'utilisateur</h2>
<p style="margin: 0 0 10px; color: #32385e;">L'Utilisateur s'engage à utiliser le Site conformément à la loi et aux présentes CGU. Il s'interdit notamment de :</p><ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; color: #32385e;"><li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="7" height="12" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg><span>utiliser le Site ou nos services à des fins illégales ou frauduleuses ;</span></li><li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="7" height="12" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg><span>porter atteinte au fonctionnement, à la sécurité ou à l'intégrité du Site et de notre infrastructure ;</span></li><li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="7" height="12" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg><span>extraire des données du Site par des procédés automatisés non autorisés ;</span></li><li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="7" height="12" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg><span>diffuser des contenus illicites, haineux, diffamatoires ou portant atteinte aux droits de tiers ;</span></li><li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="7" height="12" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg><span>usurper l'identité d'un tiers.</span></li></ul></section>

<section id="art-8"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">8.</span> Signalement de contenus illicites</h2>
<p style="margin: 0 0 10px; color: #32385e;">En tant qu'hébergeur, GNL Solution met à disposition un dispositif permettant à toute personne de signaler un contenu illicite hébergé sur son infrastructure : <a href="/tickets">Signaler un incident › Contenu illicite</a>.</p><p style="margin: 0; color: #32385e;">Chaque notification fait l'objet d'un accusé de réception, d'un examen diligent et d'une décision motivée communiquée à son auteur. Le fait de présenter sciemment un contenu comme illicite en sachant cette information inexacte est puni d'un an d'emprisonnement et de 15 000 € d'amende.</p></section>

<section id="art-9"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">9.</span> Liens hypertextes</h2>
<p style="margin: 0; color: #32385e;">Le Site peut contenir des liens vers des sites tiers, sur lesquels GNL Solution n'exerce aucun contrôle et dont il ne saurait être responsable.</p></section>

<section id="art-10"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">10.</span> Données personnelles et cookies</h2>
<p style="margin: 0; color: #32385e;">Les données personnelles collectées sur le Site sont traitées conformément à notre <a href="https://gnl-solution.com/politique-de-confidentialite">politique de confidentialité</a>, qui décrit aussi l'usage des cookies.</p></section>

<section id="art-11"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">11.</span> Responsabilité</h2>
<p style="margin: 0; color: #32385e;">GNL Solution s'efforce de diffuser des informations exactes et à jour, sans pouvoir en garantir l'exhaustivité. Sa responsabilité ne saurait être engagée pour les dommages résultant de l'utilisation du Site, de son indisponibilité ou de l'usage des informations qui y figurent, hors faute de sa part.</p></section>

<section id="art-12"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">12.</span> Modification des CGU</h2>
<p style="margin: 0; color: #32385e;">GNL Solution peut modifier les présentes CGU pour les adapter à l'évolution du Site ou de la réglementation. La version applicable est celle en vigueur lors de l'utilisation du Site.</p></section>

<section id="art-13"><h2 style="margin: 0 0 10px; font-size: 22px; line-height: 30px;"><span style="color: #1f7a4a;">13.</span> Droit applicable et litiges</h2>
<p style="margin: 0; color: #32385e;">Les présentes CGU sont soumises au droit français. En cas de litige, une solution amiable sera recherchée en priorité ; à défaut, les tribunaux français compétents seront saisis.</p></section>

<div class="no-print" style="background: #141a33; color: #ffffff; border-radius: 3px; padding: 24px 28px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
<div><p style="margin: 0; font-size: 18px; font-weight: 800;">Une question sur ces conditions ?</p>
<p style="margin: 4px 0 0; color: #c5cbd8; font-size: 14px;">Notre équipe à Besançon vous répond au 03 65 67 01 69.</p></div>
<a href="/tickets" style="padding: 12px 20px; background: #5cd98b; color: #141a33; border-radius: 3px; text-decoration: none; font-weight: 700; font-size: 14px;">Ouvrir un ticket</a>
</div>

</article>
</main>

<footer style="border-top: 1px solid #e3e6ee; padding: 24px 40px; display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 24px; font-size: 13px; color: #5e6684;">
<span>© <?= date('Y') ?> GNL Solution</span>
<a href="/cgu" style="color: #141a33;">CGU</a>
<a href="https://gnl-solution.com/cgv" style="color: #5e6684;">CGV</a>
<a href="https://gnl-solution.com/politique-de-confidentialite" style="color: #5e6684;">Confidentialité</a>
<a href="<?= $back ?>" style="color: #5e6684;">Espace Client</a>
</footer>
</body>
</html>
