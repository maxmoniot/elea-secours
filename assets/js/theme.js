/* =====================================================
   Éléa-Secours — Mode sombre : bouton de bascule
   -----------------------------------------------------
   Le thème est posé très tôt par un petit script inline
   dans le <head> de chaque page (évite le flash blanc).
   Ce fichier ne fait qu'ajouter le bouton et gérer le clic.

   Comportement : par défaut l'app suit le réglage
   clair/sombre de l'ordinateur. Dès que l'utilisateur
   clique, son choix est mémorisé (localStorage) et prime.
   ===================================================== */
(function () {
    'use strict';

    var KEY = 'elea-theme';

    function osPrefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function saved() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }

    function current() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
        refreshBtn();
    }

    var btn = null;
    function refreshBtn() {
        if (!btn) return;
        var dark = current() === 'dark';
        // Icône = action proposée (on affiche la lune quand on est en clair)
        btn.textContent = dark ? '☀️' : '🌙';
        btn.title = dark ? 'Passer en mode clair' : 'Passer en mode sombre';
        btn.setAttribute('aria-label', btn.title);
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }

    /* Conteneurs de boutons d'en-tête, par page. Le bouton s'y range à la suite des
       autres (pas en superposition). Si aucun n'existe, il flotte en haut à droite. */
    var HOSTS = [
        '.top-buttons',           // index.php (accueil)
        '.header-right',          // editor.php
        '.course-header-content'  // view.php / assets/css/view.php (viewers)
    ];

    function findHost() {
        for (var i = 0; i < HOSTS.length; i++) {
            var el = document.querySelector(HOSTS[i]);
            if (el) return el;
        }
        return null;
    }

    function createBtn() {
        if (document.querySelector('.theme-toggle')) return; // déjà là
        btn = document.createElement('button');
        btn.type = 'button';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var next = current() === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(KEY, next); } catch (err) {}
            apply(next);
        });
        var host = findHost();
        if (host) {
            btn.className = 'theme-toggle theme-toggle--inline';
            host.appendChild(btn);
        } else {
            btn.className = 'theme-toggle theme-toggle--floating';
            document.body.appendChild(btn);
        }
        refreshBtn();
    }

    // Suivre l'OS tant qu'aucun choix manuel n'a été fait
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onChange = function () { if (!saved()) apply(osPrefersDark() ? 'dark' : 'light'); };
        if (mq.addEventListener) mq.addEventListener('change', onChange);
        else if (mq.addListener) mq.addListener(onChange);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createBtn);
    } else {
        createBtn();
    }
})();
