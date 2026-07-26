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
<link rel="stylesheet" href="assets/css/dark.css">
<script src="assets/js/theme.js" defer></script>
