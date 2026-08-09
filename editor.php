<?php
/**
 * Éléa-Secours - Éditeur de cours
 * Interface de création et modification de cours H5P/Moodle
 */
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/cleanup.php';
require_once __DIR__ . '/includes/session_check.php';

// Expiration custom de session (8h, contournement bridage OVH)
enforceSessionExpiry();

// Vérification accès (tout utilisateur connecté)
if (!isset($_SESSION['elea_access']) || $_SESSION['elea_access'] !== true) {
    header('Location: index.php');
    exit;
}

// Nettoyage automatique des brouillons de plus de 24h
cleanupOldDrafts();

// Nettoyage des sessions éditeur expirées (local + Drive)
cleanExpiredEditorSessions();

// Nettoyage des previews PDF
cleanupPdfPreviews();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Éditeur de cours - <?= SITE_NAME ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --sidebar-width: 280px;
            --properties-width: 300px;
            --header-height: 56px;
        }
        
        body {
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            overflow: hidden;
            height: 100vh;
        }
        
<?php include __DIR__ . '/includes/editor/editor-css.php'; ?>
        .activity-card.drag-over, .mapmodules-inline-preview.drag-over { outline: 2px dashed var(--primary); outline-offset: 2px; }
        .activity-card.dragging, .mapmodules-inline-preview.dragging { opacity: 0.4; }
    </style>
    <!-- Pannellum pour les panoramas 360° (embarqué en inline) -->
    <style><?php readfile(__DIR__ . '/assets/css/pannellum.css'); ?></style>
    <script><?php readfile(__DIR__ . '/assets/js/pannellum.js'); ?></script>
    <?php include __DIR__ . '/includes/theme_assets.php'; ?>
</head>
<body>
    <div id="editorLoadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:#fff;z-index:99999;display:none;flex-direction:column;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
        <div id="editorLoadingContent" style="text-align:center;max-width:320px;">
            <div style="font-size:2rem;margin-bottom:1rem;">✏️</div>
            <div id="editorLoadingTitle" style="font-size:1.1rem;color:#334155;font-weight:600;margin-bottom:0.75rem;">Restauration du brouillon...</div>
            <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:0.5rem;">
                <div id="editorLoadingBar" style="width:0%;height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:4px;transition:width 0.3s ease;"></div>
            </div>
            <div id="editorLoadingText" style="font-size:0.8rem;color:#94a3b8;">Recherche d'un brouillon...</div>
            <div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1.25rem;">
                <button id="editorLoadingAbortBtn" onclick="editorLoadingAbort()" style="padding:0.5rem 1.1rem;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;color:#475569;font-size:0.85rem;cursor:pointer;">✕ Annuler</button>
                <button id="editorLoadingNewBtn" onclick="editorLoadingCancel()" style="padding:0.5rem 1.1rem;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;color:#475569;font-size:0.85rem;cursor:pointer;transition:background 0.2s;">📄 Nouveau cours</button>
            </div>
        </div>
    </div>
    <script>
    // Si le prefetch de la homepage indique un brouillon, afficher l'overlay immédiatement
    (function() {
        var prefetch = sessionStorage.getItem('editor_has_draft');
        // Ne pas consommer le prefetch, il sera mis à jour par la homepage
        if (prefetch === '1') {
            var ov = document.getElementById('editorLoadingOverlay');
            if (ov) ov.style.display = 'flex';
        }
    })();
    /**
     * Abandonner la restauration du brouillon SANS rien supprimer : l'éditeur reste vide et
     * le brouillon pourra être repris plus tard. À ne pas confondre avec « Nouveau cours »,
     * qui lui efface le brouillon et ses fichiers.
     */
    function editorLoadingAbort() {
        window._editorLoadCancelled = true;
        var ov = document.getElementById('editorLoadingOverlay');
        if (ov) ov.style.display = 'none';

        // Vider l'éditeur explicitement : la réponse du serveur a pu arriver juste avant le
        // clic et déjà remplir le cours. On ne touche NI au brouillon NI aux fichiers — ils
        // reviendront au prochain chargement de la page.
        courseData = { id: generateId(), name: 'Nouveau cours', shortname: 'cours1', sections: [], vignette: null };
        if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();
        var champNom = document.getElementById('courseName');
        if (champNom) champNom.value = courseData.name;
        selectedSection = null;
        selectedActivity = null;
        if (typeof renderTree === 'function') renderTree();
        if (typeof renderProperties === 'function') renderProperties();
        var vide = document.getElementById('emptyCanvas');
        var contenu = document.getElementById('editorContent');
        if (vide) vide.style.display = 'flex';
        if (contenu) contenu.style.display = 'none';
        if (typeof calculateCourseSize === 'function') calculateCourseSize();
        if (typeof showToast === 'function') showToast('Restauration annulée — le brouillon est conservé, rechargez la page pour le reprendre', 'info');
    }

    function editorLoadingCancel() {
        if (!confirm('Créer un nouveau cours ?\n\nLe brouillon en cours de chargement et tous ses fichiers (images, vidéos) seront supprimés du serveur et du Drive.\n\nCette action est irréversible.')) {
            return;
        }
        window._editorLoadCancelled = true;
        var ov = document.getElementById('editorLoadingOverlay');
        if (ov) ov.remove();
        // Cleanup ancienne session (local + Drive + metadata + draft)
        var oldId = (typeof getEditorSessionId === 'function') ? getEditorSessionId() : '';
        if (oldId) {
            fetch('api/editor_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cleanup_editor_session', sessionId: oldId })
            })
            .then(function(r) { return r.json(); })
            .then(function() {
                if (typeof fetchServerUsage === 'function') fetchServerUsage();
            })
            .catch(function(){});
        }
        // Nouveau session
        if (typeof regenerateEditorSessionId === 'function') {
            var newId = regenerateEditorSessionId();
            if (typeof EditorDriveSync !== 'undefined') {
                EditorDriveSync.reset();
                EditorDriveSync.init(newId);
            }
        }
        courseData = { id: generateId(), name: 'Nouveau cours', shortname: 'cours1', sections: [], vignette: null };
        if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();
        document.getElementById('courseName').value = 'Nouveau cours';
        selectedSection = null;
        selectedActivity = null;
        renderTree();
        showStructureView();
        renderProperties();
        if (typeof showToast === 'function') showToast('Nouveau cours créé', 'success');
        if (typeof refreshFilesSize === 'function') { _sessionFilesTotal = 0; }
        if (typeof calculateCourseSize === 'function') calculateCourseSize();
    }
    </script>
    <!-- Header -->
    <header class="editor-header">
        <div class="header-left">
            <a href="index.php" class="back-btn">← Accueil</a>
            <input type="text" class="course-name-input" id="courseName" value="Nouveau cours" placeholder="Nom du cours">
            <!-- Vignette du cours (image du parcours dans Éléa, 300 × 215) -->
            <div class="cvg-control" id="courseVignetteBtn" onclick="openCourseVignetteModal()"
                 title="Aucune vignette — cliquer pour en ajouter une (300 × 215)">
                <span class="cvg-label">🖼️ Vignette</span>
                <img class="cvg-mini" id="courseVignetteThumb" alt="" style="display:none;">
                <span class="cvg-mini cvg-mini--empty" id="courseVignetteEmpty">+</span>
            </div>
        </div>
        
        <div class="header-center">
            <div class="ed-search-wrapper">
                <input type="text" class="ed-search-input" id="edSearchInput" placeholder="🔍 Rechercher dans le cours..." oninput="edSearchCourse(this.value)" onfocus="edSearchCourse(this.value)" autocomplete="off">
                <div class="ed-search-results" id="edSearchResults"></div>
            </div>
            <div class="course-size-container" style="flex-direction:column;align-items:stretch;gap:0.25rem;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <div class="course-size-bar">
                        <div class="course-size-fill" id="courseSizeFill"></div>
                    </div>
                    <span class="course-size-text" id="courseSizeText">Cours en création: 0 Mo / 200 Mo</span>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <div class="course-size-bar" style="background:var(--gray-200);">
                        <div class="course-size-fill" id="serverSizeFill" style="background:#6366f1;"></div>
                    </div>
                    <span class="course-size-text" id="serverSizeText" title="Espace disque cache serveur">Serveur: ... / <?= SERVER_MAX_MB ?> Mo</span>
                </div>
            </div>
            <span class="save-status" id="saveStatus">Non sauvegardé</span>
            <button class="save-now-btn" onclick="autoSaveDraft()" title="Sauvegarder maintenant">✓</button>
        </div>
        
        <div class="header-right">
            <button class="btn btn-secondary" onclick="newCourse()">📄 Nouveau</button>
            <button class="btn btn-secondary" onclick="openMbzDialog()">📂 Ouvrir</button>
            <button class="btn btn-secondary" onclick="editorGeneratePDF()" title="Générer un PDF du cours">Générer PDF</button>
            <button class="btn btn-primary" onclick="exportElea()" title="Export compatible Éléa/Moodle">💾 Exporter mon cours</button>
        </div>
    </header>
    
    <!-- Layout principal -->
    <div class="editor-layout">
        <!-- Sidebar gauche - Arborescence -->
        <aside class="sidebar-left">
            <div class="sidebar-header">
                <span class="sidebar-title structure-link" onclick="showStructureView()" title="Voir la structure complète">📋 Structure</span>
            </div>
            
            <div class="tree-container" id="treeContainer">
                <!-- Généré par JavaScript -->
            </div>
            
            <div class="sidebar-footer">
                <button class="add-section-btn" onclick="addSection()">
                    <span>➕</span>
                    <span>Ajouter une section</span>
                </button>
                <button class="import-btn" onclick="openImportModal()">
                    <span>📥</span>
                    <span>Importer un parcours</span>
                </button>
                <button class="import-btn import-btn--tpl" onclick="openTemplateMenu(this)">
                    <span>📋</span>
                    <span>Ajouter un template</span>
                </button>
            </div>
        </aside>
        
        <!-- Zone centrale - Édition -->
        <main class="main-editor">
            <div class="editor-toolbar" id="editorToolbar" style="display: none;">
                <!-- Toolbar contextuel selon la sélection -->
            </div>
            
            <div class="editor-canvas">
                <div class="canvas-wrapper" id="canvasWrapper">
                    <div class="empty-canvas" id="emptyCanvas">
                        <div class="empty-canvas-icon">📚</div>
                        <h3>Commencez votre cours</h3>
                        <p>Créez votre première section pour organiser vos activités</p>
                        <button class="btn btn-primary" onclick="addSection()" style="margin-top: 1.5rem; padding: 0.75rem 1.5rem; font-size: 1rem;">
                            ➕ Ajouter une section
                        </button>
                    </div>
                    <div id="editorContent" style="display: none;">
                        <!-- Contenu édité -->
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Modal édition interaction vidéo -->
    <div class="modal-overlay" id="ivEditInteractionModal" style="display:none;">
        <div class="modal" style="max-width: 550px;">
            <div class="modal-header">
                <h3 id="ivModalTitle">Éditer l'interaction</h3>
                <button class="modal-close" onclick="ivCloseInteractionEditor()">×</button>
            </div>
            <div class="modal-body" id="ivInteractionEditorContent" style="max-height: 70vh; overflow-y: auto;">
            </div>
            <div class="modal-footer" style="padding: 0.75rem 1.5rem; border-top: 1px solid var(--gray-200); display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button class="btn btn-secondary" onclick="ivCloseInteractionEditor()">Fermer</button>
            </div>
        </div>
    </div>

    <!-- Modal Ajout Activité -->
    <div class="modal-overlay" id="addActivityModal">
        <div class="modal">
            <div class="modal-header">
                <span class="modal-title">Ajouter une activité</span>
                <button class="modal-close" onclick="closeModal('addActivityModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="activity-types-grid">
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="coursepresentation">
                        <div class="activity-type-icon">📊</div>
                        <div class="activity-type-name">Course Presentation</div>
                        <div class="activity-type-desc">Présentation interactive avec slides</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="questionset">
                        <div class="activity-type-icon">📝</div>
                        <div class="activity-type-name">Évaluation</div>
                        <div class="activity-type-desc">Série de questions variées</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="blanks">
                        <div class="activity-type-icon">✏️</div>
                        <div class="activity-type-name">Texte à trous</div>
                        <div class="activity-type-desc">Compléter les espaces vides</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="assign">
                        <div class="activity-type-icon">📝</div>
                        <div class="activity-type-name">Travail à déposer</div>
                        <div class="activity-type-desc">Travail à déposer par les élèves</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="mapmodules">
                        <div class="activity-type-icon">🗺️</div>
                        <div class="activity-type-name">Carte de Progression</div>
                        <div class="activity-type-desc">Carte visuelle du parcours élève</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="findthewords">
                        <div class="activity-type-icon">🔍</div>
                        <div class="activity-type-name">Mots Mêlés</div>
                        <div class="activity-type-desc">Retrouver des mots dans une grille</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="multichoice">
                        <div class="activity-type-icon">✅</div>
                        <div class="activity-type-name">QCM</div>
                        <div class="activity-type-desc">Question à choix multiples</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="multimediachoice">
                        <div class="activity-type-icon">🖼️</div>
                        <div class="activity-type-name">Choix Multimédia</div>
                        <div class="activity-type-desc">Images à sélectionner</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="truefalse" style="display:none;">
                        <div class="activity-type-icon">⚖️</div>
                        <div class="activity-type-name">Vrai/Faux</div>
                        <div class="activity-type-desc">Question vrai ou faux</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="interactivevideo">
                        <div class="activity-type-icon">🎬</div>
                        <div class="activity-type-name">Vidéo Interactive</div>
                        <div class="activity-type-desc">Vidéo avec questions intégrées</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="dragtext">
                        <div class="activity-type-icon">🔤</div>
                        <div class="activity-type-name">Glisser Texte</div>
                        <div class="activity-type-desc">Glisser-déposer des mots</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="dialogcards">
                        <div class="activity-type-icon">🃏</div>
                        <div class="activity-type-name">Cartes Dialogue</div>
                        <div class="activity-type-desc">Cartes retournables Q/R</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="imagesequencing">
                        <div class="activity-type-icon">🔢</div>
                        <div class="activity-type-name">Remettre dans l'ordre</div>
                        <div class="activity-type-desc">Images à replacer dans le bon ordre</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="memorygame">
                        <div class="activity-type-icon">🧠</div>
                        <div class="activity-type-name">Memory</div>
                        <div class="activity-type-desc">Retrouver les paires de cartes</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="multihotspot">
                        <div class="activity-type-icon">🔎</div>
                        <div class="activity-type-name">Trouver les zones</div>
                        <div class="activity-type-desc">Cliquer les endroits à repérer sur une image</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="gamemap">
                        <div class="activity-type-icon">🧭</div>
                        <div class="activity-type-name">Carte à explorer</div>
                        <div class="activity-type-desc">Étapes reliées sur une carte</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="threeimage">
                        <div class="activity-type-icon">🌐</div>
                        <div class="activity-type-name">Visite virtuelle 360</div>
                        <div class="activity-type-desc">Image 360° avec hotspots interactifs</div>
                    </div>
                    <div class="activity-type-card" onclick="selectActivityType(this)" data-type="resource">
                        <div class="activity-type-icon">📎</div>
                        <div class="activity-type-name">Fichiers à distribuer</div>
                        <div class="activity-type-desc">Fichiers téléchargeables par les élèves</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Vignette du cours -->
    <div class="modal-overlay" id="courseVignetteModal">
        <div class="modal" style="max-width: 460px;">
            <div class="modal-header">
                <span class="modal-title">Vignette du cours</span>
                <button class="modal-close" onclick="closeCourseVignetteModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="cvg-preview" id="courseVignettePreview"
                     ondragover="courseVignetteDragOver(event)"
                     ondragleave="courseVignetteDragLeave(event)"
                     ondrop="courseVignetteDrop(event)"></div>
                <div class="cvg-info" id="courseVignetteInfo"></div>
                <p class="cvg-help">
                    Image affichée sur la carte du parcours dans Éléa. Format attendu :
                    <strong>300 × 215</strong> — une image d'un autre format est recadrée automatiquement.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="courseVignetteRemoveBtn" onclick="courseVignetteRemove()" style="display:none;">Retirer</button>
                <button class="btn btn-secondary" onclick="closeCourseVignetteModal()">Fermer</button>
                <button class="btn btn-primary" onclick="document.getElementById('courseVignetteInput').click()">Choisir une image…</button>
            </div>
        </div>
    </div>
    <input type="file" id="courseVignetteInput" accept="image/*" onchange="courseVignetteChoose(this)" style="display: none;">

    <!-- Input caché pour ouvrir un fichier MBZ -->
    <input type="file" id="openFileInput" accept=".mbz" onchange="loadMbzFile()" style="display: none;">
    
    <!-- Input caché pour import fichier local -->
    <input type="file" id="importFileInput" accept=".mbz" onchange="loadImportFile()" style="display: none;">
    
    <!-- Modal Import (ajouter à un cours existant) -->
    <div class="modal-overlay" id="importModal">
        <div class="modal" style="max-width: 900px; max-height: 85vh;">
            <div class="modal-header">
                <span class="modal-title">Importer un parcours</span>
                <button class="modal-close" onclick="closeModal('importModal')">×</button>
            </div>
            <div class="modal-body" style="overflow-y: auto; max-height: 65vh; padding: 0;">
                <!-- Liste des cours permanents + bouton upload -->
                <div id="importMainView" style="padding: 1.5rem;">
                    <!-- Bouton upload local en haut -->
                    <div class="import-local-btn" onclick="document.getElementById('importFileInput').click()">
                        <span class="import-local-icon">💻</span>
                        <span class="import-local-text">Charger depuis mon ordinateur</span>
                        <span class="import-local-hint">.mbz</span>
                    </div>
                    
                    <div class="import-separator">
                        <span>ou sélectionnez un cours permanent</span>
                    </div>
                    
                    <!-- Liste des cours permanents -->
                    <div id="importDriveContent">
                        <div style="text-align: center; padding: 2rem;">
                            <div class="spinner"></div>
                            <p style="margin-top: 1rem; color: var(--gray-500);">Chargement des cours...</p>
                        </div>
                    </div>
                </div>
                
                <!-- Sélection des sections/activités -->
                <div id="importSelectorZone" style="display: none; padding: 1.5rem;">
                    <!-- Sélecteur d'import généré par JS -->
                </div>
            </div>
            <div class="modal-footer" id="importFooter" style="display: none;">
                <button class="btn btn-secondary" onclick="backToImportList()">← Retour</button>
                <button class="btn btn-primary" onclick="confirmImportSelection()">Importer la sélection</button>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
    // ==================== FICHIERS JAVASCRIPT MODULAIRES ====================
    
<?php include __DIR__ . '/includes/editor/editor-utils.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-size.js'; ?>

    // ==================== ÉTAT DU COURS ====================
    let courseData = {
        id: generateId(),
        name: 'Nouveau cours',
        shortname: 'cours1',
        vignette: null,     // image du parcours dans Éléa : { url, name }
        sections: []
    };
    
    // État de sélection
    let selectedSection = null;
    let selectedActivity = null;
    let selectedActivityType = null;

    // Template images scanned from server directory
    const cpTemplateImages = <?php
        $templateDir = __DIR__ . '/assets/templatesImages';
        $images = [];
        if (is_dir($templateDir)) {
            $files = scandir($templateDir);
            foreach ($files as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $images[] = $f;
                }
            }
            sort($images, SORT_NATURAL | SORT_FLAG_CASE);
        }
        echo json_encode($images);
    ?>;

    // Emoji images scanned from server directory
    const cpEmojiImages = <?php
        $emojiDir = __DIR__ . '/assets/emojis_png';
        $emojis = [];
        if (is_dir($emojiDir)) {
            $files = scandir($emojiDir);
            foreach ($files as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                    $emojis[] = $f;
                }
            }
            sort($emojis, SORT_NATURAL | SORT_FLAG_CASE);
        }
        echo json_encode($emojis);
    ?>;
    let pendingSectionId = null;
    let draggedItem = null;

<?php include __DIR__ . '/includes/editor/editor-core.js'; ?>

<?php include __DIR__ . '/includes/makecode_extract.js'; ?>

<?php include __DIR__ . '/includes/duplicate-detection.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-course-presentation.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-quiz.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-ddimageortext.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-video.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-properties.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-drive-sync.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-save-export.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-three-image.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-gamemap.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-imagesequencing.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-memorygame.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-multihotspot.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-import.js'; ?>

<?php include __DIR__ . '/includes/editor/editor-vignette.js'; ?>

    // ==================== INITIALISATION ====================
    document.addEventListener('DOMContentLoaded', function() {
        // Synchroniser le nom du cours avec auto-save
        document.getElementById('courseName').addEventListener('input', function() {
            courseData.name = this.value;
            onCourseModified();
        });

        courseVignetteRefreshUI();
        
        // Vérifier si on doit charger un cours depuis le visualiseur
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('load') === 'course') {
            try {
                const courseInfo = sessionStorage.getItem('courseToLoad');
                if (courseInfo) {
                    const info = JSON.parse(courseInfo);
                    sessionStorage.removeItem('courseToLoad');
                    
                    // Retirer l'overlay s'il est affiché
                    var ov = document.getElementById('editorLoadingOverlay');
                    if (ov) ov.remove();
                    
                    // Nettoyer l'URL
                    window.history.replaceState({}, document.title, 'editor.php');
                    
                    // Cleanup de l'ancienne session AVANT de charger le nouveau cours
                    var needsCleanup = sessionStorage.getItem('editor_needs_cleanup');
                    if (needsCleanup) {
                        sessionStorage.removeItem('editor_needs_cleanup');
                        var oldSessionId = getEditorSessionId();
                        EditorDriveSync.reset();
                        fetch('api/editor_api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'cleanup_editor_session', sessionId: oldSessionId })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function() {
                            var newId = regenerateEditorSessionId();
                            EditorDriveSync.init(newId);
                            loadCourseForEditing(info);
                        })
                        .catch(function() {
                            var newId = regenerateEditorSessionId();
                            EditorDriveSync.init(newId);
                            loadCourseForEditing(info);
                        });
                    } else {
                        EditorDriveSync.init(getEditorSessionId());
                        loadCourseForEditing(info);
                    }
                    return;
                }
            } catch (e) {
                console.error('Erreur lors du chargement:', e);
            }
        }
        
        // Charger le brouillon au démarrage
        loadDraftOnStartup().then((loaded) => {
            renderTree();
            renderProperties();
            
            // Sauvegarder l'état initial dans l'historique undo/redo
            if (typeof _courseCommitHistory === 'function') _courseCommitHistory();
            
            // Afficher la vue Structure par défaut si des sections existent
            if (courseData.sections && courseData.sections.length > 0) {
                showStructureView();
            }
            
            // Gérer l'overlay de chargement
            var overlay = document.getElementById('editorLoadingOverlay');
            if (!overlay) return;
            
            if (!loaded || !courseData.sections || courseData.sections.length === 0) {
                overlay.remove();
                return;
            }
            
            // Extraire TOUTES les URLs d'images du JSON courseData
            var bar = document.getElementById('editorLoadingBar');
            var text = document.getElementById('editorLoadingText');
            var dismissed = false;
            
            function dismissOverlay() {
                if (dismissed) return;
                dismissed = true;
                if (bar) bar.style.width = '100%';
                if (text) text.textContent = 'Prêt !';
                setTimeout(function() {
                    overlay.style.transition = 'opacity 0.3s';
                    overlay.style.opacity = '0';
                    setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
                }, 250);
            }
            
            // Scanner récursivement le JSON pour trouver toutes les URLs serve_upload
            var imageUrls = [];
            function findImageUrls(obj) {
                if (!obj || typeof obj !== 'object') return;
                if (Array.isArray(obj)) {
                    obj.forEach(function(item) { findImageUrls(item); });
                    return;
                }
                for (var key in obj) {
                    var val = obj[key];
                    if (typeof val === 'string' && val.indexOf('serve_upload') !== -1) {
                        if (imageUrls.indexOf(val) === -1) imageUrls.push(val);
                    } else if (typeof val === 'object') {
                        findImageUrls(val);
                    }
                }
            }
            findImageUrls(courseData);
            
            if (imageUrls.length === 0) {
                dismissOverlay();
                return;
            }
            
            // Précharger toutes les images via des objets Image()
            var total = imageUrls.length;
            var loaded2 = 0;
            text.textContent = '0 / ' + total + ' images';
            
            function onImgPreloaded() {
                loaded2++;
                var pct = 70 + Math.round((loaded2 / total) * 30);
                if (bar) bar.style.width = Math.min(pct, 99) + '%';
                if (text) text.textContent = loaded2 + ' / ' + total + ' images';
                if (loaded2 >= total) dismissOverlay();
            }
            
            imageUrls.forEach(function(url) {
                var img = new Image();
                img.onload = onImgPreloaded;
                img.onerror = onImgPreloaded;
                img.src = url;
            });
            
            // Sécurité max 30s
            setTimeout(function() { dismissOverlay(); }, 30000);
        });
    });
    
    // Charger un cours pour édition (depuis le visualiseur)
    function loadCourseForEditing(info) {
        showToast('Chargement du cours "' + info.name + '"...', 'info');

        let apiData;
        var progressId = (typeof newProgressId === 'function') ? newProgressId() : '';

        if (info.type === 'gdrive' && info.gdriveId) {
            apiData = { action: 'parse_drive_mbz', gdrive_id: info.gdriveId, sessionId: getEditorSessionId(), progressId: progressId };
        } else if (info.type === 'editor_session' && info.sessionId) {
            apiData = { action: 'load_editor_session_draft', sessionId: info.sessionId };
        } else if (info.localId) {
            apiData = { action: 'parse_local_course', course_id: info.localId, sessionId: getEditorSessionId(), progressId: progressId };
        } else {
            showToast('Impossible de charger ce cours', 'error');
            renderTree();
            renderProperties();
            return;
        }

        // Voile avec barre et bouton d'annulation : ce chargement peut durer longtemps.
        var etatPrecedent = snapshotEditorState();
        var annule = false;
        var controleur = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        // Barre seulement si le serveur publie son avancement pour cette action ;
        // sinon rond qui tourne, mais avec le bouton d'annulation dans les deux cas.
        var avecBarre = !!apiData.progressId;
        showLoadingOverlay('Chargement du cours...', info.name, avecBarre, function() {
            annule = true;
            stopServerProgress();
            if (controleur) controleur.abort();
            restoreEditorState(etatPrecedent);
        });
        if (avecBarre) {
            setLoadingProgress(0, 'Lecture du cours…');
            watchServerProgress(progressId, 0, 100);
        }

        fetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(apiData),
            signal: controleur ? controleur.signal : undefined
        })
        .then(r => r.json())
        .then(data => {
            if (annule) return;
            stopServerProgress();
            hideLoadingOverlay();
            if (data.success && data.course) {
                // Utiliser le nom connu si le cours parsé n'en a pas
                if (!data.course.name && info.name) {
                    data.course.name = info.name;
                }
                if (info.type === 'gdrive' && info.gdriveId && typeof DriveUploadWidget !== 'undefined') {
                    DriveUploadWidget.enqueue(info.gdriveId, data.course.name || info.name || info.gdriveId);
                }
                importEntireCourse(data.course);
            } else {
                throw new Error(data.error || 'Erreur de chargement');
            }
        })
        .catch(err => {
            if (annule || err.name === 'AbortError') return;
            stopServerProgress();
            hideLoadingOverlay();
            console.error('Erreur:', err);
            restoreEditorState(etatPrecedent, null);
            showToast('Erreur: ' + err.message, 'error');
        });
    }
    
    // Importer un cours complet directement (sans passer par le sélecteur)
    function importEntireCourse(importedCourse) {
        // Réinitialiser les données
        courseData.name = importedCourse.name || 'Cours importé';
        courseData.sections = [];
        // Vignette du cours (image du parcours dans Éléa) : sans cette reprise, elle
        // était perdue dès l'ouverture du cours, donc absente du .mbz réexporté.
        courseData.vignette = importedCourse.vignette || null;
        if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();

        document.getElementById('courseName').value = courseData.name;
        
        let importedCount = 0;
        
        (importedCourse.sections || []).forEach((section, sIdx) => {
            // Créer une copie de la section
            const newSection = {
                id: generateId(),
                name: section.name || 'Section ' + (sIdx + 1),
                summary: section.summary || '',
                visible: section.visible !== undefined ? section.visible : true,
                activities: []
            };
            
            // Ajouter toutes les activités
            (section.activities || []).forEach((activity) => {
                // Deep copy complète de l'activité, puis écraser l'id
                const act = JSON.parse(JSON.stringify(activity));
                act.id = generateId();
                // Normaliser les champs obligatoires
                if (!act.type) act.type = 'h5pactivity';
                if (!act.name) act.name = 'Activité importée';
                newSection.activities.push(act);
                importedCount++;
            });
            
            courseData.sections.push(newSection);
        });
        
        // Convertir les éléments H5P.Video en InteractiveVideo (anciens cours)
        if (typeof convertH5pVideoToInteractiveVideo === 'function') {
            convertH5pVideoToInteractiveVideo();
        }
        
        renderTree();
        renderProperties();
        onCourseModified();
        
        // Invalider le cache des miniatures pour forcer la regénération
        if (typeof cpInvalidateAllThumbs === 'function') cpInvalidateAllThumbs();
        
        if (typeof calculateCourseSize === 'function') {
            calculateCourseSize();
        }
        
        if (courseData.sections.length > 0) {
            showStructureView();
        }
        
        showToast(`Cours chargé : ${courseData.sections.length} section(s), ${importedCount} activité(s)`, 'success');
        
        // Synchroniser les fichiers avec Drive immédiatement
        if (typeof EditorDriveSync !== 'undefined') {
            EditorDriveSync.syncAndFlush();
        }
    }
    </script>
<?php include __DIR__ . '/includes/drive_upload_widget.php'; ?>
</body>
</html>
