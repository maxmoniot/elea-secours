<?php
/**
 * Mode sombre — à inclure dans le <head> de chaque page.
 *
 * 1. Le script inline pose data-theme AVANT le rendu (évite le flash blanc) :
 *    choix mémorisé s'il existe, sinon réglage clair/sombre de l'ordinateur.
 * 2. dark.css applique le thème (et protège le rendu des cours).
 * 3. theme.js ajoute le bouton de bascule discret.
 *
 * NB : chemins d'assets en relatif, comme le reste de l'app (assets/css/style.css).
 */
?>
<script>(function(){try{var t=localStorage.getItem('elea-theme');var d=t?t==='dark':(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');}catch(e){}})();</script>
<?php
// Empreinte de version : sans elle, le navigateur d'un élève garde la feuille de style
// mise en cache après un envoi FTP et continue d'afficher l'ancien rendu.
$__themeV = @filemtime(__DIR__ . '/../assets/css/dark.css') ?: 0;
$__themeJsV = @filemtime(__DIR__ . '/../assets/js/theme.js') ?: 0;
?>
<link rel="stylesheet" href="assets/css/dark.css?v=<?= $__themeV ?>">
<script src="assets/js/theme.js?v=<?= $__themeJsV ?>" defer></script>
