<?php // Styles CSS de l'éditeur ?>
* { box-sizing: border-box; margin: 0; padding: 0; }
.pnlm-container, .pnlm-container * { box-sizing: content-box !important; }

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

/* ========== HEADER ========== */
.editor-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: var(--header-height);
    background: white;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    padding: 0 1rem;
    gap: 1rem;
    z-index: 100;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.back-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--gray-100);
    border: none;
    border-radius: 8px;
    color: var(--gray-600);
    text-decoration: none;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
}
.back-btn:hover {
    background: var(--gray-200);
    color: var(--gray-800);
}

.course-name-input {
    font-size: 1.1rem;
    font-weight: 600;
    border: none;
    background: transparent;
    padding: 0.5rem;
    border-radius: 6px;
    min-width: 200px;
}
.course-name-input:hover {
    background: var(--gray-50);
}
.course-name-input:focus {
    outline: none;
    background: var(--gray-100);
}

/* Header commun des éditeurs d'activité */
.ed-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    position: relative;
    flex: 1;
    min-width: 0;
}
.ed-back-btn {
    padding: 0.35rem 0.7rem !important;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.ed-title {
    flex: 1;
    font-size: 1.05rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin: 0;
}
.ed-header-actions {
    display: flex;
    gap: 0.25rem;
    flex-shrink: 0;
}
.ed-undo-btn, .ed-redo-btn {
    width: 28px;
    height: 28px;
    border: 1px solid var(--gray-300);
    border-radius: 5px;
    background: white;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--gray-500);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}
.ed-undo-btn:hover:not(:disabled), .ed-redo-btn:hover:not(:disabled) {
    background: var(--gray-100);
    border-color: var(--primary);
    color: var(--primary);
}
.ed-undo-btn:disabled, .ed-redo-btn:disabled {
    opacity: 0.3;
    cursor: default;
}

.header-center {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1.5rem;
}

/* Recherche dans le cours */
.ed-search-wrapper {
    position: relative;
}

.ed-search-input {
    width: 220px;
    padding: 0.35rem 0.6rem;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    font-size: 0.8rem;
    background: var(--gray-50);
    transition: all 0.15s;
}

.ed-search-input:focus {
    width: 280px;
    border-color: var(--primary);
    background: white;
    outline: none;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15);
}

.ed-search-results {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    min-width: 350px;
    max-height: 400px;
    overflow-y: auto;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    z-index: 1000;
    margin-top: 4px;
}

.ed-search-result {
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    border-bottom: 1px solid var(--gray-100);
}

.ed-search-result:last-child {
    border-bottom: none;
}

.ed-search-result:hover {
    background: var(--gray-50);
}

.ed-search-result-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 2px;
}

.ed-search-result-excerpt {
    font-size: 0.72rem;
    color: var(--gray-500);
    line-height: 1.3;
}

.ed-search-result-excerpt mark {
    background: #fef08a;
    color: inherit;
    padding: 0 1px;
    border-radius: 2px;
}

.ed-search-empty {
    padding: 0.75rem;
    text-align: center;
    font-size: 0.8rem;
    color: var(--gray-400);
}

/* Barre de progression du poids du cours */
.course-size-container {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.course-size-bar {
    width: 120px;
    height: 8px;
    background: var(--gray-200);
    border-radius: 4px;
    overflow: hidden;
}

.course-size-fill {
    height: 100%;
    background: var(--success);
    border-radius: 4px;
    transition: width 0.3s ease, background-color 0.3s ease;
    width: 0%;
}

.course-size-fill.warning {
    background: var(--warning);
}

.course-size-fill.danger {
    background: var(--danger);
}

.course-size-text {
    font-size: 0.75rem;
    color: var(--gray-500);
    white-space: nowrap;
}

.course-size-text.warning {
    color: var(--warning);
}

.course-size-text.danger {
    color: var(--danger);
    font-weight: 600;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-secondary {
    background: var(--gray-100);
    color: var(--gray-700);
}
.btn-secondary:hover {
    background: var(--gray-200);
}

.btn-primary {
    background: var(--primary);
    color: white;
}
.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-success {
    background: var(--success);
    color: white;
}
.btn-success:hover {
    background: #059669;
}

.save-status {
    font-size: 0.8rem;
    color: var(--gray-400);
}
.save-status.saving { color: var(--warning); }
.save-status.saved { color: var(--success); }

.save-now-btn {
    width: 24px;
    height: 24px;
    border: none;
    border-radius: 4px;
    background: var(--gray-100);
    color: var(--gray-500);
    font-size: 0.85rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 0.5rem;
    transition: all 0.2s;
}
.save-now-btn:hover {
    background: var(--success);
    color: white;
}

/* ========== LAYOUT ========== */
.editor-layout {
    display: flex;
    height: calc(100vh - var(--header-height));
    margin-top: var(--header-height);
}

/* ========== SIDEBAR GAUCHE (Arborescence) ========== */
.sidebar-left {
    width: var(--sidebar-width);
    background: white;
    border-right: 1px solid var(--gray-200);
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.sidebar-header {
    padding: 1rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sidebar-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.tree-container {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem;
}

.tree-section {
    margin-bottom: 0.25rem;
}

.tree-section-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 0.75rem;
    background: var(--gray-50);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}
.tree-section-header:hover {
    background: var(--gray-100);
}
.tree-section-header.selected {
    background: var(--primary);
    color: white;
}

.tree-section-icon {
    font-size: 1rem;
    transition: transform 0.2s;
}
.tree-section.collapsed .tree-section-icon {
    transform: rotate(-90deg);
}

.tree-section-name {
    flex: 1;
    font-size: 0.9rem;
    font-weight: 500;
}

.tree-section-actions {
    opacity: 0;
    display: flex;
    gap: 0.25rem;
}
.tree-section-header:hover .tree-section-actions {
    opacity: 1;
}

.tree-action-btn {
    padding: 0.2rem;
    background: none;
    border: none;
    cursor: pointer;
    opacity: 0.6;
    font-size: 0.8rem;
}
.tree-action-btn:hover {
    opacity: 1;
}

.tree-activities {
    padding-left: 1.5rem;
    overflow: hidden;
}
.tree-section.collapsed .tree-activities {
    display: none;
}

.tree-activity {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.85rem;
}
.tree-activity:hover {
    background: var(--gray-100);
}
.tree-activity-actions {
    opacity: 0;
    display: flex;
    gap: 0.15rem;
    margin-left: auto;
}
.tree-activity:hover .tree-activity-actions {
    opacity: 1;
}
.tree-activity.selected {
    background: #e0e7ff;
    color: var(--primary-dark);
}

/* Visibilité : section ou parcours masqué */
.tree-hidden > .tree-section-header .tree-section-icon,
.tree-hidden > .tree-section-header .tree-section-name {
    opacity: 0.4;
}
.tree-activity.tree-hidden .tree-activity-icon,
.tree-activity.tree-hidden .tree-activity-name {
    opacity: 0.4;
}
.tree-vis-btn {
    font-size: 0.75rem !important;
}
.tree-vis-btn.vis-off {
    opacity: 0.35 !important;
}
.tree-vis-btn.vis-inherited {
    pointer-events: none;
    opacity: 0.15 !important;
}
/* Toujours afficher l'icône œil quand élément masqué */
.tree-hidden > .tree-section-header .tree-section-actions,
.tree-activity.tree-hidden .tree-activity-actions {
    opacity: 1;
}
.tree-hidden > .tree-section-header .tree-section-actions .tree-action-btn:not(.tree-vis-btn),
.tree-activity.tree-hidden .tree-activity-actions .tree-action-btn:not(.tree-vis-btn) {
    opacity: 0;
}
.tree-section-header:hover .tree-vis-btn,
.tree-activity:hover .tree-vis-btn {
    opacity: 0.8 !important;
}
.tree-section-header:hover .tree-vis-btn.vis-off,
.tree-activity:hover .tree-vis-btn.vis-off {
    opacity: 0.5 !important;
}

.tree-activity-icon {
    font-size: 0.9rem;
}

.tree-activity-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Édition inline des noms */
.tree-activity-name.editing,
.tree-section-name.editing,
.activity-name-editable.editing {
    /* En cours de renommage. Le fond était `white` en dur : en mode sombre le texte reste
       clair (hérité de l'arbre) → texte clair sur blanc, illisible (contraste mesuré 1.23).
       --bg-secondary/--text-primary ne sont définis QUE par dark.css (style.css n'est pas
       chargé dans l'éditeur) → en clair les fallbacks donnent le rendu d'origine à l'identique,
       et dans un îlot clair ils sont re-déclarés en clair. */
    background: var(--bg-secondary, white);
    color: var(--text-primary, inherit);
    border: 1px solid var(--primary);
    border-radius: 4px;
    padding: 2px 6px;
    outline: none;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
}

.tree-activity-name:not(.editing):hover,
.tree-section-name:not(.editing):hover {
    text-decoration: underline;
    text-decoration-style: dotted;
    text-underline-offset: 2px;
}

.activity-name-editable {
    cursor: text;
    padding: 2px 6px;
    border-radius: 4px;
    border: 1px solid transparent;
    transition: all 0.2s;
}

.activity-name-editable:hover {
    background: var(--gray-100);
    border-color: var(--gray-300);
}

/* === Menu contextuel === */
.context-menu {
    position: fixed;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    min-width: 160px;
    z-index: 10000;
    overflow: hidden;
    animation: contextMenuFadeIn 0.15s ease;
}

@keyframes contextMenuFadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.context-menu-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1rem;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.15s;
}

.context-menu-item:hover {
    background: var(--gray-100);
}

.context-menu-item.danger {
    color: var(--danger);
}

.context-menu-item.danger:hover {
    background: #fef2f2;
}

.context-menu-separator {
    height: 1px;
    background: var(--gray-200);
    margin: 0.25rem 0;
}

.context-menu-item.disabled {
    color: var(--gray-400);
    cursor: not-allowed;
}

.context-menu-item.disabled:hover {
    background: transparent;
}

/* === Noms éditables dans la vue section === */
.editable-name {
    cursor: text;
    padding: 2px 6px;
    margin: -2px -6px;
    border-radius: 4px;
    border: 1px solid transparent;
    transition: all 0.2s;
}

.editable-name:hover:not(.editing) {
    background: var(--gray-100);
    border-color: var(--gray-300);
}

.editable-name.editing {
    /* même défaut que .tree-*-name.editing ci-dessus (renommage dans la vue structure) */
    background: var(--bg-secondary, white);
    color: var(--text-primary, inherit);
    border: 2px solid var(--primary);
    outline: none;
}

.section-preview-title.editable-name {
    display: inline-block;
}

.activity-card-name.editable-name:hover:not(.editing) {
    background: rgba(255,255,255,0.8);
}


.sidebar-footer {
    padding: 0.75rem;
    border-top: 1px solid var(--gray-200);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.add-section-btn, .import-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.6rem;
    border: 2px dashed var(--gray-300);
    border-radius: 8px;
    background: transparent;
    color: var(--gray-500);
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s;
}
.add-section-btn:hover, .import-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--gray-50);
}
/* « Ajouter un template » : ce dégradé était en style INLINE dans editor.php, donc impossible
   à adapter au thème sombre. Sorti ici à l'identique (mêmes couleurs) ; la variante sombre est
   dans assets/css/dark.css. Placé après la règle ci-dessus : à spécificité égale, il l'emporte. */
.import-btn--tpl {
    background: linear-gradient(135deg, #e8f5e9, #e3f2fd);
}

/* ========== ZONE CENTRALE (Édition) ========== */
.main-editor {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--gray-100);
}

.editor-toolbar {
    padding: 0.75rem 1rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.toolbar-group {
    display: flex;
    gap: 0.25rem;
    padding-right: 0.75rem;
    border-right: 1px solid var(--gray-200);
}
.toolbar-group:last-child {
    border-right: none;
}

.toolbar-btn {
    padding: 0.5rem 0.75rem;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.toolbar-btn:hover {
    background: var(--gray-100);
    border-color: var(--gray-300);
}
.toolbar-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.editor-canvas {
    flex: 1;
    overflow: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 0;
}

.canvas-wrapper {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    width: 100%;
    max-width: 850px;
    min-height: 400px;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}

/* Mode Course Presentation : le wrapper prend toute la hauteur disponible */
.canvas-wrapper.cp-mode {
    flex: 1;
    min-height: 0;
    background: transparent;
    box-shadow: none;
    border-radius: 0;
    max-width: none;
}

#editorContent {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

/* Quand l'éditeur de quiz est actif, le contenu prend sa taille naturelle */
#editorContent:has(.quiz-editor) {
    flex: 0 0 auto;
    min-height: auto;
}

.empty-canvas {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    min-height: 400px;
    color: var(--gray-400);
    text-align: center;
    padding: 2rem;
}
.empty-canvas-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}
.empty-canvas h3 {
    font-size: 1.2rem;
    margin-bottom: 0.5rem;
    color: var(--gray-600);
}
.empty-canvas p {
    font-size: 0.9rem;
    max-width: 300px;
}

/* Preview d'une section */
.section-preview {
    padding: 2rem;
}
.section-preview-header {
    margin-bottom: 1.5rem;
}
.section-preview-header .ed-header {
    margin-bottom: 0;
}
.section-preview-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--gray-800);
}
.section-preview-desc {
    color: var(--gray-500);
    margin-top: 0.5rem;
}

.activities-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.activity-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.activity-card:hover {
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
}
.activity-card.selected {
    border-color: var(--primary);
    background: #f0f0ff;
}

.activity-card-icon {
    width: 48px;
    height: 48px;
    background: white;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.activity-card-info {
    flex: 1;
}
.activity-card-name {
    font-weight: 600;
    color: var(--gray-800);
}
.activity-card-type {
    font-size: 0.8rem;
    color: var(--gray-500);
}

.activity-card-actions {
    display: flex;
    gap: 0.5rem;
    opacity: 0;
}
.activity-card:hover .activity-card-actions {
    opacity: 1;
}

.add-activity-card {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1.5rem;
    border: 2px dashed var(--gray-300);
    border-radius: 8px;
    color: var(--gray-500);
    cursor: pointer;
    transition: all 0.2s;
}
.add-activity-card:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--gray-50);
}

/* ========== SIDEBAR DROITE (Propriétés) - SUPPRIMÉ ========== */
/* Volet supprimé - les propriétés sont dans la popup déplaçable */

.property-group {
    margin-bottom: 1.5rem;
}

.property-label {
    display: block;
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--gray-600);
    margin-bottom: 0.4rem;
}

.property-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}
.property-input:focus {
    outline: none;
    border-color: var(--primary);
}

.property-textarea {
    min-height: 80px;
    resize: vertical;
}

.property-select {
    background: white;
    cursor: pointer;
}

.empty-properties {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 200px;
    color: var(--gray-400);
    text-align: center;
}
.empty-properties-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

/* ========== MODALS ========== */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active {
    display: flex;
}

.modal {
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-size: 1.1rem;
    font-weight: 600;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--gray-400);
    padding: 0.25rem;
}
.modal-close:hover {
    color: var(--gray-600);
}

.modal-body {
    padding: 1.5rem;
    overflow-y: auto;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

/* Liste d'activités à ajouter */
.activity-types-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}

.activity-type-card {
    padding: 1rem;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}
.activity-type-card:hover {
    border-color: var(--primary);
    background: var(--gray-50);
}
.activity-type-card.selected {
    border-color: var(--primary);
    background: #e0e7ff;
}

.activity-type-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}
.activity-type-name {
    font-weight: 500;
    font-size: 0.9rem;
}
.activity-type-desc {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-top: 0.25rem;
}

/* Import Selector */
.import-course-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--gray-100);
    border-radius: 8px;
    margin-bottom: 1rem;
}
.import-course-header input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
.import-course-name {
    font-weight: 600;
    font-size: 1rem;
}
.import-section {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    margin-bottom: 0.75rem;
    overflow: hidden;
}
.import-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--gray-50);
    cursor: pointer;
    transition: background 0.2s;
}
.import-section-header:hover {
    background: var(--gray-100);
}
.import-section-header input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}
.import-section-icon {
    font-size: 1.1rem;
}
.import-section-name {
    flex: 1;
    font-weight: 500;
}
.import-section-count {
    font-size: 0.75rem;
    color: var(--gray-500);
    background: var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
}
.import-section-toggle {
    color: var(--gray-400);
    transition: transform 0.2s;
}
.import-section.collapsed .import-section-toggle {
    transform: rotate(-90deg);
}
.import-activities {
    padding: 0.5rem 1rem 0.75rem 2.5rem;
    border-top: 1px solid var(--gray-200);
}
.import-section.collapsed .import-activities {
    display: none;
}
.import-activity {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0;
}
.import-activity input[type="checkbox"] {
    width: 14px;
    height: 14px;
    cursor: pointer;
}
.import-activity-icon {
    font-size: 0.9rem;
}
.import-activity-name {
    font-size: 0.85rem;
    color: var(--gray-700);
}
.import-activity-type {
    font-size: 0.7rem;
    color: var(--gray-400);
    margin-left: auto;
}

/* Toast notifications */
.toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 2000;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.toast {
    padding: 0.75rem 1.25rem;
    background: var(--gray-800);
    color: white;
    border-radius: 8px;
    font-size: 0.9rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    animation: toastIn 0.3s ease;
}
.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }

@keyframes toastIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive */
@media (max-width: 1024px) {
    .sidebar-right { display: none; }
}
@media (max-width: 768px) {
    .sidebar-left { width: 60px; }
    .sidebar-left .sidebar-title,
    .sidebar-left .tree-section-name,
    .sidebar-left .tree-activity-name,
    .sidebar-left .add-section-btn span,
    .sidebar-left .import-btn span { display: none; }
    .tree-section-header, .tree-activity { justify-content: center; }
}

/* Drag & Drop - Tree sidebar */
.tree-section-header,
.tree-activity:not(.add-activity) {
    cursor: grab;
}
.tree-section-header:active,
.tree-activity:not(.add-activity):active {
    cursor: grabbing;
}
.tree-drag-placeholder {
    height: 0;
    overflow: hidden;
    border: 2px dashed var(--primary);
    border-radius: 6px;
    margin: 2px 4px;
    background: rgba(99, 102, 241, 0.06);
}

/* Drag & Drop - Structure view */

/* Modal liste brouillons */
.drafts-list {
    max-height: 300px;
    overflow-y: auto;
}
.draft-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    margin-bottom: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
}
.draft-item:hover {
    border-color: var(--primary);
    background: var(--gray-50);
}
.draft-item-icon {
    font-size: 1.5rem;
}
.draft-item-info {
    flex: 1;
}
.draft-item-name {
    font-weight: 500;
    color: var(--gray-800);
}
.draft-item-meta {
    font-size: 0.75rem;
    color: var(--gray-500);
}
.draft-item-actions {
    display: flex;
    gap: 0.5rem;
}
.draft-item-delete {
    padding: 0.25rem 0.5rem;
    background: none;
    border: none;
    color: var(--gray-400);
    cursor: pointer;
    border-radius: 4px;
}
.draft-item-delete:hover {
    background: #fee2e2;
    color: var(--danger);
}
.no-drafts {
    text-align: center;
    padding: 2rem;
    color: var(--gray-400);
}
.modal-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--gray-200);
    padding-bottom: 0.5rem;
}
.modal-tab {
    padding: 0.5rem 1rem;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--gray-500);
    border-radius: 6px;
}
.modal-tab:hover {
    background: var(--gray-100);
}
.modal-tab.active {
    background: var(--primary);
    color: white;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* ========== ÉDITEUR COURSE PRESENTATION ========== */
.cp-editor {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    background: var(--gray-100);
}

.cp-editor-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    flex-shrink: 0;
}

.cp-editor-header h3 {
    color: var(--gray-800);
    font-size: 1rem;
    margin: 0;
    flex: 1;
}

.cp-editor-toolbar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    flex-shrink: 0;
    flex-wrap: wrap;
}

.cp-toolbar-label {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-right: 0.5rem;
}

.cp-toolbar-btn {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.7rem;
    background: var(--gray-50);
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    color: var(--gray-700);
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}
.cp-toolbar-btn:hover {
    background: var(--gray-100);
    border-color: var(--primary);
    color: var(--primary);
}
/* Boutons accentués « Accès rapide » et « Templates ». Les dégradés étaient posés en style
   inline : passés en classes pour pouvoir les décliner en mode sombre (sinon texte clair
   sur pastel clair = illisible). */
.cp-toolbar-btn--quick {
    background: linear-gradient(135deg, #e3f2fd, #e8f5e9);
}
.cp-toolbar-btn--tpl {
    background: linear-gradient(135deg, #fff3e0, #fce4ec);
}
.cp-toolbar-btn-icon {
    font-size: 1rem;
}

.cp-canvas-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 2.5rem;
    overflow: visible;
    min-height: 0;
    background: var(--gray-200);
    position: relative;
    container-type: size;
}

.cp-canvas-add-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 96px;
    height: 54px;
    border: 2px dashed var(--gray-300);
    background: rgba(255,255,255,0.7);
    cursor: pointer;
    color: var(--gray-400);
    font-size: 1.3rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    z-index: 10;
    border-radius: 6px;
}
.cp-canvas-add-left {
    right: calc(100% + 10px);
}
.cp-canvas-add-right {
    left: calc(100% + 10px);
}
.cp-canvas-add-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: rgba(99, 102, 241, 0.12);
}

.cp-canvas-wrapper {
    width: min(100%, 1400px, 200cqh); /* 200cqh = 2 × container height → keeps 2:1 ratio within available height */
    max-height: 100%;
    position: relative;
    aspect-ratio: 2 / 1; /* Ratio réel du CoursePresentation H5P */
}

.cp-canvas {
    width: 100%;
    height: 100%;
    background: #F5F5F5;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    position: relative;
    overflow: visible;
    transform-origin: center center;
    transition: transform 0.2s ease;
}

.cp-canvas-inner {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    border-radius: 8px;
}

/* Contrôle de zoom dans la barre des slides */
.cp-zoom-control {
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--gray-100);
    padding: 4px 10px;
    border-radius: 6px;
    margin-left: auto;
    font-size: 0.75rem;
}

.cp-zoom-icon {
    font-size: 0.85rem;
    opacity: 0.7;
}

.cp-zoom-control input[type="range"] {
    width: 70px;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
    background: var(--gray-300);
    border-radius: 2px;
    cursor: pointer;
}

.cp-zoom-control input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 12px;
    height: 12px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
    transition: transform 0.1s;
}

.cp-zoom-control input[type="range"]::-webkit-slider-thumb:hover {
    transform: scale(1.2);
}

.cp-zoom-control input[type="range"]::-moz-range-thumb {
    width: 12px;
    height: 12px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
    border: none;
}

.cp-zoom-value {
    min-width: 32px;
    text-align: right;
    font-weight: 600;
    color: var(--gray-600);
    font-size: 0.7rem;
}

.cp-element {
    position: absolute;
    border: 2px solid transparent;
    cursor: move;
    transition: border-color 0.2s;
    min-width: 30px;
    min-height: 20px;
    isolation: isolate; /* Empêche les z-index internes de fuiter */
}
.cp-element:hover {
    border-color: var(--primary);
}
.cp-element.selected {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.3);
}

.cp-element-content {
    width: 100%;
    height: 100%;
    overflow: hidden;
}

.cp-element-resize {
    position: absolute;
    width: 12px;
    height: 12px;
    background: var(--primary);
    border-radius: 2px;
    cursor: se-resize;
    right: -6px;
    bottom: -6px;
    display: none;
}
.cp-element.selected .cp-element-resize {
    display: block;
}

.cp-element-rotate {
    position: absolute;
    width: 20px;
    height: 20px;
    background: #8b5cf6;
    color: white;
    border-radius: 50%;
    cursor: grab;
    top: -24px;
    left: 50%;
    transform: translateX(-50%);
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
    user-select: none;
    z-index: 5;
}
.cp-element.selected .cp-element-rotate {
    display: flex;
}
.cp-element-rotate:active {
    cursor: grabbing;
}

/* Shape dropdown */
.cp-shape-dropdown-menu.open {
    display: block !important;
}
.cp-toolbar-dropdown {
    position: relative;
}

.cp-element-delete {
    position: absolute;
    top: -10px;
    right: -10px;
    width: 22px;
    height: 22px;
    background: var(--danger);
    color: white;
    border: none;
    border-radius: 50%;
    font-size: 14px;
    cursor: pointer;
    display: none;
    align-items: center;
    justify-content: center;
}
.cp-element.selected .cp-element-delete {
    display: flex;
}

.cp-slides-nav {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.75rem;
    background: white;
    border-top: 1px solid var(--gray-200);
    flex-shrink: 0;
}

.cp-slide-counter {
    font-size: 0.75rem;
    color: var(--gray-500);
    font-weight: 600;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
}

.cp-slides-scroll {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    overflow-x: auto;
    overflow-y: visible;
    flex: 1;
    min-width: 0;
    padding: 10px 0 2px 0;
    /* safe center : centré quand les vignettes tiennent, mais bascule en
       alignement au début dès que ça déborde — sinon les premières slides
       passent avant scrollLeft=0 et deviennent inatteignables (glissière à
       fond à gauche = 1re slide cliquable trop loin). */
    justify-content: safe center;
}

.cp-slide-thumb {
    width: 90px;
    height: 45px;
    background: #F5F5F5;
    border: 2px solid var(--gray-300);
    border-radius: 4px;
    cursor: grab;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease, transform 0.15s ease, margin 0.2s ease;
    position: relative;
    flex-shrink: 0;
}

.cp-slide-thumb-preview {
    width: 100%;
    height: 100%;
    position: relative;
    pointer-events: none;
    overflow: hidden;
}
.cp-slide-thumb-preview .cp-thumb-canvas {
    position: absolute;
    top: 0;
    left: 0;
    width: 500px;
    height: 250px;
    transform: scale(0.18);
    transform-origin: top left;
    overflow: hidden;
    font-size: 16px;
    line-height: 1.5;
    color: #333;
}

.cp-slide-thumb:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.cp-slide-thumb.active {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.3);
}
.cp-slide-thumb.dragging {
    opacity: 0.4;
    cursor: grabbing;
    transform: scale(0.95);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.cp-slide-thumb.drag-over-before {
    margin-left: 50px;
    position: relative;
}
.cp-slide-thumb.drag-over-before::before {
    content: '';
    position: absolute;
    left: -28px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    border: 2px dashed var(--primary);
    border-radius: 4px;
    background: rgba(99, 102, 241, 0.1);
    animation: dropIndicatorPulse 0.5s ease infinite alternate;
}
.cp-slide-thumb.drag-over-after {
    margin-right: 50px;
    position: relative;
}
.cp-slide-thumb.drag-over-after::after {
    content: '';
    position: absolute;
    right: -28px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    border: 2px dashed var(--primary);
    border-radius: 4px;
    background: rgba(99, 102, 241, 0.1);
    animation: dropIndicatorPulse 0.5s ease infinite alternate;
}

@keyframes dropIndicatorPulse {
    from { opacity: 0.5; transform: translateY(-50%) scale(0.9); }
    to { opacity: 1; transform: translateY(-50%) scale(1); }
}

.cp-slide-thumb-actions {
    position: absolute;
    top: -6px;
    right: -3px;
    display: none;
    gap: 2px;
}
.cp-slide-thumb:hover .cp-slide-thumb-actions {
    display: flex;
}
.cp-slide-thumb-btn {
    width: 18px;
    height: 18px;
    border: none;
    border-radius: 50%;
    font-size: 9px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    background: var(--gray-600);
    color: white;
    transition: all 0.15s;
}
.cp-slide-thumb-btn:hover {
    transform: scale(1.1);
}
.cp-slide-thumb-btn.cp-slide-thumb-delete {
    background: var(--danger);
}

.cp-add-slide {
    min-width: 70px;
    height: 40px;
    border: 2px dashed var(--gray-300);
    border-radius: 5px;
    background: transparent;
    cursor: pointer;
    color: var(--gray-400);
    font-size: 1.1rem;
    transition: all 0.2s;
}
.cp-add-slide:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--gray-50);
}

/* Panneau de propriétés flottant et déplaçable */
.cp-props-panel {
    position: fixed;
    top: 190px;
    right: 20px;
    width: 300px;
    max-height: 70vh;
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
    overflow: hidden;
    display: none;
    z-index: 1000;
    resize: both;
    min-width: 250px;
    min-height: 200px;
}
.cp-props-panel.visible {
    display: flex;
    flex-direction: column;
}

.cp-props-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.6rem 1rem;
    background: var(--primary);
    color: white;
    cursor: move;
    user-select: none;
}
.cp-props-header h4 {
    margin: 0;
    font-size: 0.85rem;
    font-weight: 600;
}
.cp-props-header-icon {
    margin-right: 0.5rem;
}
.cp-props-close {
    background: none;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    color: rgba(255,255,255,0.8);
    padding: 0;
    line-height: 1;
}
.cp-props-close:hover {
    color: white;
}

.cp-props-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.cp-prop-group {
    margin-bottom: 1rem;
}

.cp-prop-label {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--gray-600);
    margin-bottom: 0.3rem;
    display: block;
}

.cp-prop-input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 0.85rem;
}
.cp-prop-input:focus {
    outline: none;
    border-color: var(--primary);
}

.cp-prop-textarea {
    min-height: 80px;
    resize: vertical;
    font-family: inherit;
}

.cp-prop-row {
    display: flex;
    gap: 0.5rem;
}
.cp-prop-row .cp-prop-group {
    flex: 1;
}

.cp-prop-row {
    display: flex;
    gap: 0.5rem;
}
.cp-prop-row .cp-prop-group {
    flex: 1;
}

/* Éléments spécifiques */
.cp-text-element {
    padding: 8px;
    font-size: inherit;
    /* SANS unité : la hauteur de ligne suit la taille de police de chaque portion.
       En "1.5em", elle se figeait sur la taille de base (~39px) → une grosse police ou un
       emoji sur la 1re ligne débordait vers le haut et était coupé. Le rendu du texte normal
       est identique (1.5 × taille). Doit rester synchro avec .h5p-cp-text du viewer (view.php). */
    line-height: 1.5;
    /* Boîte calquée sur .h5p-cp-text du lecteur (view.php) : elle GRANDIT avec le texte
       (height:auto) et c'est .cp-element-content qui rogne — exactement comme
       .h5p-cp-element{overflow:hidden} côté lecteur.
       AVANT : height:100% + overflow:auto → une barre de défilement apparaissait dans
       l'éditeur là où le lecteur affiche le texte en entier ; elle volait en plus ~15px de
       largeur, ce qui reprovoquait des retours à la ligne et coupait des phrases en bas. */
    height: auto;
    min-height: 100%;
    overflow: visible;
}
/* Interligne Entrée (p) vs Shift+Entrée (br) — aligné sur Éléa/H5P */
.cp-text-element p,
.cp-editable-text p,
.cp-quiz-element p {
    margin: 0 0 1em 0;
}
.cp-text-element p:last-child,
.cp-editable-text p:last-child,
.cp-quiz-element p:last-child {
    margin-bottom: 0;
}

.cp-element-text .cp-text-element {
    padding-top: 8px;
}

.cp-editable-text {
    cursor: grab;
    outline: none;
    min-height: 100%;
    position: relative;
    z-index: 1;
    user-select: none;
    -webkit-user-select: none;
}
.cp-editable-text[contenteditable="true"] {
    cursor: text;
    user-select: auto;
    -webkit-user-select: auto;
    background: rgba(99, 102, 241, 0.03);
}
.cp-editable-text[contenteditable="true"]:focus {
    background: rgba(99, 102, 241, 0.06);
}
.cp-editable-text[contenteditable="true"]::selection {
    background: rgba(99, 102, 241, 0.3);
}

/* S'assurer que le content peut recevoir les clics pour le texte */
.cp-element-text .cp-element-content {
    position: relative;
    z-index: 2;
    /* .cp-element porte une bordure de 2px (transparente au repos) que le lecteur n'a pas :
       en border-box elle rentre dans la boîte, donc le rognage tombait 2px trop haut et
       l'éditeur coupait le texte AVANT le lecteur. On rend ces 2px du bas pour que le point
       de coupe soit celui de .h5p-cp-element (mesuré : 1,99px d'écart → 0,01px). */
    height: calc(100% + 2px);
}

/* En cours de frappe, on ne cache rien : le texte qui dépasse reste visible (au lieu d'être
   rogné comme dans le lecteur, ou de scroller comme avant). L'élément passe au-dessus des
   voisins le temps de l'édition. La bordure de l'élément montre où Éléa coupera. */
.cp-element-text:has(> .cp-element-content > .cp-editable-text[contenteditable="true"]) {
    z-index: 30;
}
.cp-element-text:has(> .cp-element-content > .cp-editable-text[contenteditable="true"]) .cp-element-content {
    overflow: visible;
}

/* Éléments texte : pas de poignée séparée, tout l'élément est déplaçable */
.cp-element-text {
    cursor: grab;
}
.cp-element-text:active {
    cursor: grabbing;
}
.cp-element-drag-handle {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 18px;
    cursor: move;
    background: transparent;
    z-index: 10;
    opacity: 0;
    transition: opacity 0.2s;
}
.cp-element-text:hover .cp-element-drag-handle,
.cp-element-text.selected .cp-element-drag-handle {
    opacity: 1;
    background: linear-gradient(to bottom, rgba(99, 102, 241, 0.2), transparent);
}
/* Handle plus visible et toujours affiché en mode édition texte */
.cp-element-text:has([contenteditable="true"]) .cp-element-drag-handle {
    opacity: 1;
    background: linear-gradient(to bottom, rgba(99, 102, 241, 0.35), transparent);
    cursor: grab;
}
.cp-element-text:has([contenteditable="true"]) .cp-element-drag-handle:active {
    cursor: grabbing;
    background: linear-gradient(to bottom, rgba(99, 102, 241, 0.5), transparent);
}
/* Décaler le texte vers le bas quand le handle est actif en mode édition */
.cp-element-text:has([contenteditable="true"]) .cp-text-element {
    padding-top: 20px;
}
.cp-element-drag-handle::before {
    content: '⋮⋮';
    position: absolute;
    top: 1px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 10px;
    color: var(--primary);
    letter-spacing: -2px;
}

/* ============ Floating Text Toolbar ============ */
.cp-float-toolbar {
    position: absolute;
    display: none;
    align-items: center;
    gap: 1px;
    padding: 4px 6px;
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.20);
    z-index: 100;
    white-space: nowrap;
    transform: translateX(-50%);
    pointer-events: auto;
}
.cp-float-toolbar.visible {
    display: flex;
}
.cp-float-toolbar .ft-btn {
    border: none;
    background: none;
    cursor: pointer;
    padding: 8px 11px;
    font-size: 18px;
    border-radius: 6px;
    line-height: 1;
    color: var(--gray-700);
    transition: background 0.12s;
}
.cp-float-toolbar .ft-btn:hover {
    background: var(--gray-100);
}
.cp-float-toolbar .ft-btn.active {
    background: var(--primary);
    color: white;
}
.cp-float-toolbar .ft-sep {
    width: 1px;
    height: 26px;
    background: var(--gray-200);
    margin: 0 2px;
}
.cp-float-toolbar .ft-btn svg {
    display: block;
}
/* Emoji hold-to-pick popup */
.cp-emoji-popup {
    position: absolute;
    bottom: calc(100% + 4px);
    left: 50%;
    transform: translateX(-50%);
    display: none;
    flex-wrap: wrap;
    gap: 1px;
    padding: 4px;
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    width: 240px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 200;
}
.cp-emoji-popup.visible {
    display: flex;
}
.cp-emoji-popup .ep-item {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.1s, transform 0.1s;
    user-select: none;
}
.cp-emoji-popup .ep-item:hover,
.cp-emoji-popup .ep-item.hovered {
    background: var(--primary-light, #e0e7ff);
    transform: scale(1.2);
}

/* Bouton emoji compact dans le panneau propriétés */
.cp-emoji-toggle-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 24px;
    border: 1px solid var(--gray-200);
    background: var(--gray-50);
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    padding: 0;
    margin: 0;
    vertical-align: middle;
    transition: background 0.15s, border-color 0.15s;
}
.cp-emoji-toggle-btn:hover {
    background: var(--gray-100);
    border-color: var(--gray-300);
}
/* Dans les toolbars rich-text, adopter le style des autres boutons */
.rich-text-toolbar .cp-emoji-toggle-btn,
.cp-blanks-richtext-toolbar .cp-emoji-toggle-btn {
    height: 28px;
    width: 28px;
    border: none;
    background: transparent;
    border-radius: 4px;
    margin-left: 2px;
}
.rich-text-toolbar .cp-emoji-toggle-btn:hover,
.cp-blanks-richtext-toolbar .cp-emoji-toggle-btn:hover {
    background: var(--gray-200);
}

/* Popup emoji pour le panneau propriétés */
.cp-props-emoji-popup {
    display: flex;
    flex-wrap: wrap;
    gap: 1px;
    padding: 5px;
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    width: 244px;
    z-index: 1100;
}
.cp-props-emoji-item {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    border: none;
    background: none;
    border-radius: 4px;
    cursor: pointer;
    padding: 0;
    transition: background 0.1s, transform 0.1s;
}
.cp-props-emoji-item:hover {
    background: var(--primary-light, #e0e7ff);
    transform: scale(1.2);
}

/* ===== Sélecteur de couleur texte / surlignage (CoursePresentation) ===== */
.cp-color-popup {
    position: fixed;
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.18);
    padding: 8px;
    z-index: 1300;
    width: max-content;
}
.cp-color-grid {
    display: grid;
    grid-template-columns: repeat(5, 26px);
    gap: 6px;
}
.cp-color-swatch {
    width: 26px;
    height: 26px;
    border: 1px solid rgba(0,0,0,0.18);
    border-radius: 5px;
    cursor: pointer;
    padding: 0;
    transition: transform 0.1s, box-shadow 0.1s;
}
.cp-color-swatch:hover {
    transform: scale(1.12);
    box-shadow: 0 0 0 2px var(--primary-light, #e0e7ff);
}
.cp-color-custom {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--gray-200);
    font-size: 0.78rem;
    color: var(--gray-700);
    cursor: pointer;
}
.cp-color-custom-input {
    width: 30px;
    height: 24px;
    border: 1px solid var(--gray-300);
    border-radius: 5px;
    padding: 0;
    background: none;
    cursor: pointer;
    flex-shrink: 0;
}
.cp-color-clear {
    display: block;
    width: 100%;
    margin-top: 8px;
    padding: 6px 8px;
    border: 1px solid var(--gray-300);
    border-radius: 5px;
    background: var(--gray-50, #f8f9fa);
    color: var(--gray-700);
    font-size: 0.78rem;
    cursor: pointer;
    text-align: center;
}
.cp-color-clear:hover {
    background: var(--gray-100, #f1f3f5);
    border-color: var(--gray-400);
}
/* Icônes "A" (couleur du texte) et "A" surligné dans les barres d'outils */
.cp-ci {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    line-height: 1;
    font-weight: 700;
}
.cp-ci-bar {
    display: block;
    width: 0.85em;
    height: 0.18em;
    min-height: 3px;
    margin-top: 2px;
    border-radius: 1px;
    background: currentColor;
}
.cp-ci-hl {
    padding: 1px 3px;
    border-radius: 3px;
    color: #2c3e50;
}
/* Surlignage : léger débordement façon marqueur (rendu identique au lecteur) */
#cpCanvasInner span[style*="background-color"],
#cpTextEditor span[style*="background-color"] {
    /* Padding vertical 0 : sur le gros texte (1.5em) le fond fait déjà la hauteur de ligne,
       donc toute marge verticale le ferait déborder sur les lignes voisines. */
    padding: 0 0.4em;
    border-radius: 0.2em;
    -webkit-box-decoration-break: clone;
    box-decoration-break: clone;
}
/* Sans ça, la box de l'élément texte (overflow:hidden/auto) rogne le débordement du
   surlignage sur les côtés et le haut. On ne lève le rognage que pour les éléments
   texte qui contiennent effectivement un surlignage. */
.cp-element-text:has(span[style*="background-color"]) .cp-element-content,
.cp-element-text:has(span[style*="background-color"]) .cp-text-element {
    overflow: visible;
}

.cp-image-element {
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    width: 100%;
    height: 100%;
}
.cp-image-element:has(.cp-image-placeholder) {
    background: var(--gray-100);
}
.cp-image-element img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.cp-image-placeholder {
    color: var(--gray-400);
    text-align: center;
}
.cp-image-placeholder-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.cp-quiz-element {
    padding: 12px;
    height: 100%;
    overflow: auto;
    display: flex;
    flex-direction: column;
    background: transparent;
}
.cp-quiz-element.cp-quiz-transparent {
    background: transparent;
    border: none;
}
.cp-quiz-question {
    font-size: 1.82rem;
    color: var(--gray-800);
    margin-bottom: 12px;
    line-height: 1.4;
    font-weight: 500;
}
.cp-quiz-answers {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
}
.cp-quiz-answer {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 6px;
    font-size: 1.56rem;
    border: 2px solid var(--gray-300);
}
.cp-quiz-answer.correct {
    background: rgba(220, 252, 231, 0.9);
    border-color: #22c55e;
}
.cp-quiz-marker {
    width: 22px;
    height: 22px;
    border: 2px solid var(--gray-400);
    border-radius: 4px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.cp-quiz-answer.correct .cp-quiz-marker {
    background: #22c55e;
    border-color: #22c55e;
}
.cp-quiz-answer.correct .cp-quiz-marker::after {
    content: '✓';
    color: white;
    font-size: 16px;
    font-weight: bold;
}

/* Bouton Vérifier */
.cp-quiz-btn-container {
    margin-top: 12px;
    padding-top: 8px;
}
.cp-quiz-verify-btn {
    background: #2563eb;
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: 6px;
    font-size: 1.2rem;
    font-weight: 600;
    cursor: default;
    pointer-events: none;
}

/* Spacer pour éviter le scroll dans Éléa */
.cp-quiz-spacer {
    height: 30px;
    flex-shrink: 0;
}

/* Indicateur de navigation pour Vrai/Faux avec plusieurs questions */
.cp-quiz-nav-indicator {
    margin-top: 16px;
    padding: 10px 16px;
    background: rgba(37, 99, 235, 0.1);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}
.cp-quiz-nav-info {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2563eb;
}

/* Vrai/Faux - réponses verticales */
.cp-quiz-tf-question {
    margin-bottom: 16px;
}
.cp-quiz-tf-question:last-child {
    margin-bottom: 0;
}
.cp-quiz-tf-answers {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}
.cp-quiz-tf-answer {
    padding: 12px 18px;
    border-radius: 6px;
    font-size: 1.56rem;
    text-align: left;
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid var(--gray-300);
    color: var(--gray-700);
}
.cp-quiz-tf-answer.correct {
    background: rgba(220, 252, 231, 0.9);
    border-color: #22c55e;
    color: #166534;
    font-weight: 600;
}

/* Texte à trous */
.cp-quiz-blanks-title {
    font-size: 1.82rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--gray-200);
}
.cp-quiz-blanks-text {
    font-size: 1.82rem;
    line-height: 1.8;
    color: var(--gray-800);
    flex: 1;
}
.cp-quiz-blanks-line {
    margin-bottom: 10px;
}
.cp-quiz-blank {
    display: inline-block;
    background: #fef3c7;
    border: 2px dashed #f59e0b;
    border-radius: 4px;
    padding: 4px 14px;
    min-width: 80px;
    text-align: center;
    font-weight: 600;
    color: #d97706;
    font-size: 1.56rem;
}

/* Options dans le panneau propriétés */
/* Blanks rich text editor */
.cp-blanks-richtext-wrap {
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    overflow: hidden;
}

.cp-blanks-richtext-toolbar {
    display: flex;
    gap: 2px;
    padding: 4px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    flex-wrap: wrap;
}

.cp-blanks-richtext-editor {
    min-height: 120px;
    max-height: 300px;
    overflow-y: auto;
    padding: 0.5rem;
    font-size: 0.85rem;
    line-height: 1.6;
    outline: none;
}

.cp-blanks-richtext-editor:focus {
    background: #fafbff;
}

.cp-blanks-richtext-editor hr.cp-blanks-sep {
    border: none;
    border-top: 2px dashed var(--gray-300);
    margin: 0.5rem 0;
}

.cp-blanks-richtext-editor p {
    margin: 0 0 0.25rem 0;
}

.cp-quiz-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.cp-checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: var(--gray-700);
    cursor: pointer;
}
.cp-checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

/* Questions Vrai/Faux multiples dans le panneau */
.tf-questions-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 8px;
}
.tf-question-block {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    padding: 10px;
}
.tf-question-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.tf-question-num {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--primary);
    background: var(--primary-light);
    padding: 2px 8px;
    border-radius: 10px;
}
.tf-question-delete {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    opacity: 0.6;
    transition: opacity 0.2s;
}
.tf-question-delete:hover {
    opacity: 1;
}
.cp-prop-subgroup {
    margin-bottom: 8px;
}
.cp-prop-sublabel {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-bottom: 4px;
    display: block;
}
.tf-answers-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.tf-answer-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px;
    border-radius: 4px;
    background: white;
    border: 1px solid var(--gray-200);
}
.tf-answer-item.correct {
    background: #dcfce7;
    border-color: #86efac;
}
.tf-answer-marker {
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    color: #22c55e;
    font-weight: bold;
}
.tf-answer-text {
    flex: 1;
    padding: 4px 8px;
    border: 1px solid var(--gray-200);
    border-radius: 4px;
    font-size: 0.8rem;
}

.cp-video-element {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border-radius: 4px;
    color: white;
    text-align: center;
    padding: 10px;
}
.cp-video-preview-element {
    position: relative;
    padding: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.cp-video-wrapper {
    display: flex;
    flex-direction: column;
    max-width: 100%;
    max-height: 100%;
    width: 100%;
    height: 100%;
}
.cp-video-preview-element video {
    cursor: pointer;
    flex: 1;
    min-height: 0;
    width: 100%;
    object-fit: contain;
    background: #000;
}
.cp-video-controls {
    position: relative;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.7), rgba(0,0,0,0.9));
    flex-shrink: 0;
    border-radius: 0 0 4px 4px;
}
.cp-video-ctrl-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}
.cp-video-ctrl-btn:hover {
    background: rgba(255,255,255,0.3);
}
.cp-video-time, .cp-video-duration {
    font-size: 11px;
    font-family: monospace;
    color: white;
    min-width: 35px;
}

/* Container de la barre de progression avec marqueurs */
.cp-video-progress-container {
    flex: 1;
    position: relative;
    height: 20px;
    display: flex;
    align-items: center;
}
.cp-video-progress {
    width: 100%;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
    cursor: pointer;
    position: relative;
    z-index: 1;
}
.cp-video-progress::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
}
.cp-video-progress::-moz-range-thumb {
    width: 12px;
    height: 12px;
    background: var(--primary);
    border-radius: 50%;
    cursor: pointer;
    border: none;
}

/* Points sur la timeline */
.cp-timeline-markers {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    z-index: 2;
}
.cp-timeline-marker {
    position: absolute;
    width: 10px;
    height: 10px;
    background: #fbbf24;
    border: 2px solid white;
    border-radius: 50%;
    transform: translate(-50%, -50%);
    top: 50%;
    cursor: pointer;
    pointer-events: auto;
    transition: transform 0.15s, background 0.15s;
    box-shadow: 0 1px 4px rgba(0,0,0,0.4);
}
.cp-timeline-marker:hover {
    transform: translate(-50%, -50%) scale(1.4);
    background: #f59e0b;
}
.cp-timeline-marker.active {
    background: #22c55e;
    transform: translate(-50%, -50%) scale(1.3);
}

.cp-video-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: var(--primary);
    color: white;
    font-size: 0.6rem;
    padding: 2px 6px;
    border-radius: 10px;
    pointer-events: none;
    z-index: 10;
}

/* Layer des interactions vidéo */
.cp-video-interactions-layer {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 45px; /* Espace pour les contrôles */
    pointer-events: none;
    z-index: 5;
}
.cp-video-wrapper {
    position: relative;
}

/* Carte d'interaction (affichage riche) */
.cp-video-interaction {
    position: absolute;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    pointer-events: auto;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s, transform 0.2s;
    min-width: 180px;
    max-width: 280px;
    overflow: hidden;
    border: 2px solid var(--primary);
}
.cp-video-interaction.visible {
    opacity: 1;
    visibility: visible;
}
.cp-video-interaction.dragging {
    opacity: 0.9;
    cursor: grabbing;
    z-index: 100;
    box-shadow: 0 8px 30px rgba(0,0,0,0.4);
}
/* Interaction en cours d'édition : mise en évidence + toujours saisissable */
.cp-video-interaction.editing {
    z-index: 50;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.55), 0 8px 30px rgba(0,0,0,0.35);
}

/* Header de l'interaction */
.cp-interaction-header {
    background: var(--primary);
    color: white;
    padding: 6px 10px;
    font-size: 0.7rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: grab;
}
.cp-interaction-header:active {
    cursor: grabbing;
}
.cp-interaction-type {
    display: flex;
    align-items: center;
    gap: 4px;
}
.cp-interaction-time {
    font-size: 0.65rem;
    opacity: 0.8;
}

/* Corps de l'interaction */
.cp-interaction-body {
    padding: 10px;
    font-size: 0.75rem;
    color: #333;
    max-height: 150px;
    overflow-y: auto;
}
.cp-interaction-body p {
    margin: 0 0 0.5rem 0;
}
.cp-interaction-body p:last-child {
    margin-bottom: 0;
}

/* Question */
.cp-interaction-question {
    font-weight: 600;
    margin-bottom: 8px;
    color: #1f2937;
}

/* Réponses QCM */
.cp-interaction-answers {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.cp-interaction-answer {
    padding: 4px 8px;
    background: #f3f4f6;
    border-radius: 4px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    gap: 6px;
}
.cp-interaction-answer.correct {
    background: #dcfce7;
    border-left: 3px solid #22c55e;
}
.cp-interaction-answer-marker {
    width: 14px;
    height: 14px;
    border: 2px solid #9ca3af;
    border-radius: 50%;
    flex-shrink: 0;
}
.cp-interaction-answer.correct .cp-interaction-answer-marker {
    background: #22c55e;
    border-color: #22c55e;
}

/* Texte simple */
.cp-interaction-text {
    line-height: 1.4;
}

/* Vrai/Faux */
.cp-interaction-tf {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}
.cp-interaction-tf-btn {
    flex: 1;
    padding: 6px;
    border: none;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}
.cp-interaction-tf-btn.true-btn {
    background: #dcfce7;
    color: #166534;
}
.cp-interaction-tf-btn.false-btn {
    background: #fee2e2;
    color: #991b1b;
}
.cp-interaction-tf-btn.correct {
    box-shadow: 0 0 0 2px #22c55e;
}

/* Texte à trous */
.cp-interaction-blanks {
    line-height: 1.6;
}
.cp-interaction-blank {
    display: inline-block;
    min-width: 60px;
    border-bottom: 2px solid var(--primary);
    margin: 0 2px;
    text-align: center;
    color: var(--primary);
    font-weight: 600;
}

/* Bouton d'édition sur l'interaction */
.cp-interaction-edit-btn {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 20px;
    height: 20px;
    background: rgba(255,255,255,0.9);
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.15s;
}
.cp-video-interaction:hover .cp-interaction-edit-btn {
    opacity: 1;
}

/* Les interactions sont totalement cachées par défaut */
/* Elles n'apparaissent QUE quand on survole le point sur la timeline */
.cp-element.selected .cp-video-interaction {
    /* Ne pas afficher en semi-transparent, garder caché */
}
.cp-element.selected .cp-video-interaction.visible {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, -50%) scale(1);
}
.cp-element.selected .cp-video-interaction:hover {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1.02);
}

.cp-video-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}
.cp-video-label {
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
}
.cp-video-element small {
    font-size: 0.7rem;
    opacity: 0.8;
}

.cp-dialogcard-preview {
    position: relative;
    width: 100%;
    height: 100%;
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    border: 1px solid #e0e0e0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.cp-dialogcard-inner {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 8px;
    text-align: center;
}
.cp-dialogcard-img {
    max-width: 90%;
    max-height: 45%;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 4px;
    margin-bottom: 6px;
}
.cp-dialogcard-text {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    line-height: 1.3;
    color: #333;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 4px;
}
.cp-dialogcard-hint {
    margin-top: auto;
    padding: 4px 12px;
    background: #1a73e8;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 0.7rem;
    font-family: inherit;
    cursor: pointer;
    flex-shrink: 0;
}
.cp-dialogcard-hint:hover {
    background: #1557b0;
}
/* Face affichée (chrome d'éditeur : n'existe pas dans le lecteur) */
.cp-dialogcard-preview::after {
    content: 'recto';
    position: absolute;
    top: 3px;
    right: 5px;
    font-size: 0.6rem;
    letter-spacing: 0.02em;
    color: #9aa0a6;
    pointer-events: none;
}
.cp-dialogcard-preview.flipped::after {
    content: 'verso';
}
/* Navigation entre cartes sur le canvas (miroir de .h5p-dc-nav du lecteur) */
.cp-dialogcard-nav {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 4px 0 6px;
}
.cp-dialogcard-nav-btn {
    border: 1px solid #d0d5dd;
    background: #ffffff;
    color: #1a73e8;
    border-radius: 4px;
    padding: 0 5px;
    font-size: 0.65rem;
    line-height: 1.5;
    cursor: pointer;
}
.cp-dialogcard-nav-btn:hover:not(:disabled) {
    background: #e8f0fe;
}
.cp-dialogcard-nav-btn:disabled {
    opacity: 0.35;
    cursor: default;
}
.cp-dialogcard-progress {
    font-size: 0.6rem;
    font-weight: 600;
    color: #666666;
    white-space: nowrap;
}

/* Liste des cartes dans le panneau propriétés */
.cp-dc-card-list {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.cp-dc-card-item {
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    overflow: hidden;
}
.cp-dc-card-item.active {
    border-color: var(--primary);
}
.cp-dc-card-head {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.3rem 0.4rem;
    background: var(--gray-50);
    cursor: pointer;
}
.cp-dc-card-item.active .cp-dc-card-head {
    background: var(--gray-100);
}
.cp-dc-card-num {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--primary);
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
}
.cp-dc-card-title {
    flex: 1;
    min-width: 0;
    font-size: 0.7rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cp-dc-card-head .tree-action-btn {
    font-size: 0.65rem;
}
.cp-dc-card-head .tree-action-btn:disabled {
    opacity: 0.3;
    cursor: default;
}
.cp-dc-card-body {
    padding: 0.4rem;
}
.cp-dc-add-card {
    width: 100%;
    margin-top: 0.5rem;
    padding: 0.4rem;
    background: none;
    border: 1px dashed var(--gray-300);
    border-radius: 6px;
    color: var(--gray-500);
    font-size: 0.72rem;
    font-family: inherit;
    cursor: pointer;
}
.cp-dc-add-card:hover {
    border-color: var(--primary);
    color: var(--primary);
}

/* Éditeur de texte riche */
.rich-text-toolbar {
    display: flex;
    gap: 0.25rem;
    padding: 0.4rem;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    flex-wrap: wrap;
}
.rich-text-btn {
    width: 26px;
    height: 26px;
    border: none;
    background: white;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.rich-text-btn:hover {
    background: var(--gray-200);
}
.rich-text-btn.active {
    background: var(--primary);
    color: white;
}
.rich-text-separator {
    width: 1px;
    height: 20px;
    background: var(--gray-300);
    margin: 3px 4px;
}
.rich-text-select {
    height: 26px;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 0.75rem;
    padding: 0 0.25rem;
    background: white;
    cursor: pointer;
}
.rich-text-select:focus {
    outline: none;
    border-color: var(--primary);
}
.rich-text-editor {
    border: 1px solid var(--gray-200);
    border-radius: 0 0 4px 4px;
    min-height: 80px;
    padding: 0.5rem;
    font-size: 0.9rem;
}
.rich-text-editor:focus {
    outline: none;
    border-color: var(--primary);
}
.rich-text-editor table {
    border-collapse: collapse;
    width: 100%;
    margin: 0.5rem 0;
}
.rich-text-editor table td,
.rich-text-editor table th {
    border: 1px solid var(--gray-300);
    padding: 0.5rem;
    min-width: 50px;
}

/* ========== ÉDITEUR QUIZ ========== */
.quiz-editor {
    padding: 1.5rem;
    flex: 1 0 auto;
    position: relative;
}

.quiz-question-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.quiz-question-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--gray-50);
    cursor: pointer;
}

.quiz-question-num {
    width: 28px;
    height: 28px;
    background: var(--primary);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 600;
}

.quiz-question-title {
    flex: 1;
    font-weight: 500;
    cursor: text;
    border-radius: 4px;
    padding: 2px 4px;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.quiz-question-title:hover {
    background: rgba(25, 118, 210, 0.06);
}

.quiz-question-type {
    font-size: 0.75rem;
    color: var(--gray-500);
    background: var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
}

.quiz-question-body {
    padding: 1rem;
}

.quiz-answers-list {
    margin-top: 1rem;
}

.quiz-answer-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    margin-bottom: 0.5rem;
    background: var(--gray-50);
    border-radius: 6px;
    overflow: hidden;
}

.quiz-answer-correct {
    width: 24px;
    height: 24px;
    cursor: pointer;
    flex-shrink: 0;
}

.quiz-answer-text {
    flex: 1;
    min-width: 0;
    padding: 0.5rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 0.9rem;
}

.quiz-answer-delete {
    padding: 0.25rem 0.5rem;
    background: none;
    border: none;
    color: var(--gray-400);
    cursor: pointer;
    flex-shrink: 0;
}
.quiz-answer-delete:hover {
    color: var(--danger);
}

.quiz-feedback-toggle {
    padding: 0.15rem 0.35rem;
    background: none;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.75rem;
    opacity: 0.4;
    transition: all 0.15s;
    flex-shrink: 0;
}
.quiz-feedback-toggle:hover {
    opacity: 0.8;
    border-color: var(--primary);
}
.quiz-feedback-toggle.active {
    opacity: 1;
    background: #eff6ff;
    border-color: #3b82f6;
}

.quiz-feedback-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0 0.5rem 0.5rem 2.5rem;
    margin-top: -0.3rem;
    margin-bottom: 0.3rem;
}
.quiz-feedback-input {
    flex: 1;
    padding: 0.35rem 0.5rem;
    border: 1px solid #93c5fd;
    border-radius: 4px;
    font-size: 0.8rem;
    background: #f0f7ff;
    color: var(--gray-700);
}
.quiz-feedback-input::placeholder {
    color: #93c5fd;
    font-style: italic;
}

.quiz-add-answer {
    padding: 0.5rem 1rem;
    background: none;
    border: 1px dashed var(--gray-300);
    border-radius: 6px;
    color: var(--gray-500);
    cursor: pointer;
    width: 100%;
    margin-top: 0.5rem;
}
.quiz-add-answer:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.quiz-add-question {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    border: 2px dashed var(--gray-300);
    border-radius: 8px;
    color: var(--gray-500);
    cursor: pointer;
    margin-top: 1rem;
}
.quiz-add-question:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--gray-50);
}

/* ========== ÉVALUATION (QuestionSet) ========== */
/* Barre collante du total de points : toujours visible, même après avoir déplié/scrollé/replié
   des questions (avant, le total était en position:absolute tout en haut → coupé une fois
   scrollé). pointer-events:none pour ne pas gêner les clics à côté du badge. */
.qs-total-sticky {
    position: sticky;
    top: 0.5rem;
    z-index: 6;
    display: flex;
    justify-content: flex-end;
    pointer-events: none;   /* purement visuel : ne bloque jamais un clic, même en surimpression */
    margin-bottom: 0.25rem;
}
.qs-total-sticky .qs-total-points {
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
}
.qs-total-points {
    background: var(--primary);
    color: white;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.qs-points-badge {
    font-size: 0.7rem;
    color: var(--primary);
    background: rgba(79, 70, 229, 0.1);
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-weight: 600;
}

.qs-add-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.qs-add-btn {
    flex: 1;
    min-width: 140px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 0.75rem 1rem;
    border: 2px dashed var(--gray-300);
    border-radius: 8px;
    background: white;
    color: var(--gray-600);
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.15s;
}

.qs-add-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--gray-50);
}

.qs-topbar {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
    margin-bottom: 1rem;
}

/* Rich text */
.qs-richtext-wrap {
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    overflow: hidden;
}

.qs-richtext-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 4px 6px;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.qs-rt-btn {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--gray-600);
}

.qs-rt-btn:hover {
    background: var(--gray-200);
}

.qs-rt-sep {
    width: 1px;
    height: 20px;
    background: var(--gray-300);
    margin: 0 4px;
}

.qs-richtext-editor {
    min-height: 80px;
    max-height: 300px;
    overflow-y: auto;
    padding: 0.6rem;
    font-size: 0.9rem;
    line-height: 1.5;
    outline: none;
}

.qs-richtext-editor:focus {
    background: #fafbff;
}

.qs-richtext-editor img {
    max-width: 100%;
    border-radius: 4px;
    margin: 0.5rem 0;
}

/* Question image */
.qs-question-image-preview {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
    padding: 0.5rem;
    background: var(--gray-50);
    border-radius: 6px;
    overflow: auto;
}

.qs-image-resize-wrapper {
    position: relative;
    display: inline-block;
    border: 2px solid transparent;
    border-radius: 6px;
    transition: border-color 0.2s;
}

.qs-image-resize-wrapper:hover {
    border-color: var(--primary, #4f46e5);
}

.qs-resize-handle {
    position: absolute;
    bottom: 4px;
    right: 4px;
    width: 22px;
    height: 22px;
    background: var(--primary, #4f46e5);
    color: white;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    cursor: nwse-resize;
    opacity: 0;
    transition: opacity 0.2s;
    user-select: none;
    z-index: 2;
}

.qs-image-resize-wrapper:hover .qs-resize-handle,
.qs-image-resize-wrapper.qs-resizing .qs-resize-handle {
    opacity: 1;
}

.qs-image-resize-wrapper.qs-resizing {
    border-color: var(--primary, #4f46e5);
}

.qs-image-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.qs-image-size-label {
    font-size: 0.75rem;
    color: var(--gray-500);
    font-family: monospace;
}

.qs-btn-small {
    padding: 0.2rem 0.5rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    background: white;
    color: var(--gray-700);
    cursor: pointer;
    font-size: 0.75rem;
    white-space: nowrap;
}

.qs-btn-small:hover {
    background: var(--gray-100);
}

.qs-remove-image-btn {
    padding: 0.2rem 0.5rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    background: white;
    color: var(--danger);
    cursor: pointer;
    font-size: 0.75rem;
    white-space: nowrap;
}

.qs-remove-image-btn:hover {
    background: var(--danger);
    color: white;
}

/* Vrai/Faux */
.qs-tf-choices {
    display: flex;
    gap: 0.5rem;
}

.qs-tf-option {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem;
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.15s;
}

.qs-tf-option.selected {
    border-color: var(--primary);
    background: rgba(79, 70, 229, 0.05);
    color: var(--primary);
}

.qs-tf-option input[type="radio"] {
    display: none;
}

/* Fraction select (réponse courte) */
.qs-fraction-select {
    width: 80px;
    padding: 0.4rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 0.8rem;
}

/* Group select (sélection de mots) */
.qs-group-select {
    width: 55px;
    padding: 0.4rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 0.8rem;
}

.qs-choice-num {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--primary);
    background: rgba(79, 70, 229, 0.1);
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    white-space: nowrap;
}

/* Gap preview */
.qs-gaptext-preview {
    padding: 0.75rem;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    font-size: 0.9rem;
    line-height: 1.8;
}

.qs-gap-slot {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    background: rgba(79, 70, 229, 0.1);
    border: 1px dashed var(--primary);
    border-radius: 4px;
    color: var(--primary);
    font-size: 0.8rem;
    font-weight: 500;
}

/* Gap groups */
.qs-gap-group {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    background: var(--gray-50);
}

.qs-gap-group-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.qs-gap-group-label {
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--primary);
}

.qs-gap-group-label code {
    background: rgba(79, 70, 229, 0.1);
    padding: 0.1rem 0.3rem;
    border-radius: 3px;
    font-size: 0.8rem;
}

.qs-gap-warning {
    font-size: 0.75rem;
    color: var(--danger, #e53e3e);
    font-weight: 500;
}

.qs-correct-radio {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.qs-correct-radio input[type="radio"] {
    display: none;
}

.qs-correct-indicator {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid var(--gray-400);
    display: inline-block;
    transition: all 0.15s;
    position: relative;
}

.qs-correct-radio input[type="radio"]:checked + .qs-correct-indicator {
    border-color: var(--success, #22c55e);
    background: var(--success, #22c55e);
    box-shadow: inset 0 0 0 3px white;
}

.qs-correct-choice {
    background: rgba(34, 197, 94, 0.06);
    border-left: 3px solid var(--success, #22c55e);
}

/* Gap select choice items (new design) */
.qs-gap-choices-list {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    margin-bottom: 0.75rem;
}

.qs-gap-choice-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.5rem;
    background: var(--gray-50);
    border-radius: 6px;
}

.qs-gap-choice-num {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    white-space: nowrap;
    flex-shrink: 0;
    font-family: monospace;
}

.qs-gap-choice-item .quiz-answer-text {
    flex: 1;
    min-width: 0;
}

.qs-gap-group-select {
    width: 65px;
    padding: 0.3rem 0.2rem;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 0.75rem;
    flex-shrink: 0;
    cursor: pointer;
}

.qs-gap-choice-item .quiz-answer-delete {
    flex-shrink: 0;
}

/* Gap groups summary */
.qs-gap-groups-summary {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.qs-gap-group-summary {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.qs-gap-group-badge {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    white-space: nowrap;
    flex-shrink: 0;
}

.qs-gap-summary-choices {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
}

.qs-gap-summary-choice {
    font-size: 0.75rem;
    padding: 0.15rem 0.4rem;
    border: 1px solid;
    border-radius: 4px;
    opacity: 0.5;
}

.qs-gap-summary-choice.used {
    opacity: 1;
    font-weight: 500;
}

.qs-gap-summary-choice code {
    font-size: 0.65rem;
    opacity: 0.7;
}

.qs-gap-slot-error {
    background: #fef2f2 !important;
    border-color: #ef4444 !important;
    color: #ef4444 !important;
}

.qs-rt-btn-gap {
    font-size: 0.65rem !important;
    font-weight: 700 !important;
    background: #4f46e5 !important;
    color: white !important;
    border-radius: 4px !important;
    padding: 0.2rem 0.6rem !important;
    letter-spacing: 0.02em;
    white-space: nowrap !important;
    width: auto !important;
    height: auto !important;
}

.qs-rt-btn-gap:hover {
    background: #4338ca !important;
    color: white !important;
}

/* Help text */
.qs-help-text {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-bottom: 0.5rem;
    line-height: 1.4;
}

.qs-help-text code {
    background: var(--gray-100);
    padding: 0.1rem 0.3rem;
    border-radius: 3px;
    font-size: 0.8em;
}

/* ========== ÉDITEUR VIDÉO INTERACTIVE ========== */
.iv-editor {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.iv-editor-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}
.iv-editor-header .ed-header {
    margin-bottom: 0;
}

.iv-main {
    flex: 1;
    display: flex;
    overflow: hidden;
}

.iv-video-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #1a1a1a;
}

.iv-video-container {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    min-height: 300px;
}

.iv-video-placeholder {
    text-align: center;
    color: #888;
}
.iv-video-placeholder-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}
.iv-video-placeholder p {
    margin-bottom: 1rem;
}

.iv-video-player {
    max-width: 100%;
    max-height: 100%;
}

/* Overlays d'interactions sur la vidéo */
.iv-overlay-interaction {
    position: absolute;
    transform: translate(-50%, -50%);
    background: rgba(255,255,255,0.92);
    border: 2px solid var(--primary);
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 0.7rem;
    display: none;
    align-items: center;
    gap: 4px;
    cursor: pointer;
    z-index: 5;
    max-width: 180px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    transition: transform 0.1s;
}
.iv-overlay-interaction.visible {
    display: flex;
}
.iv-overlay-interaction.selected {
    border-color: var(--danger);
    box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.3);
}
.iv-overlay-interaction:hover {
    transform: translate(-50%, -50%) scale(1.05);
}
.iv-overlay-icon {
    font-size: 0.8rem;
    flex-shrink: 0;
}
.iv-overlay-label {
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--gray-700);
}

.iv-timeline {
    background: #2a2a2a;
    padding: 1rem;
}

.iv-timeline-track {
    height: 60px;
    background: #3a3a3a;
    border-radius: 6px;
    position: relative;
    cursor: pointer;
}

.iv-timeline-progress {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 0%;
    background: rgba(99, 102, 241, 0.3);
    border-radius: 6px 0 0 6px;
}

.iv-timeline-cursor {
    position: absolute;
    top: 0;
    left: 0;
    width: 2px;
    height: 100%;
    background: var(--primary);
    z-index: 10;
}

.iv-timeline-marker {
    position: absolute;
    top: 5px;
    width: 24px;
    height: 24px;
    background: var(--warning);
    border-radius: 50%;
    transform: translateX(-50%);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: white;
    z-index: 5;
    transition: transform 0.2s;
}
.iv-timeline-marker:hover {
    transform: translateX(-50%) scale(1.2);
}
.iv-timeline-marker.selected {
    background: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
}

.iv-timeline-time {
    display: flex;
    justify-content: space-between;
    margin-top: 0.5rem;
    font-size: 0.75rem;
    color: #888;
}

.iv-controls {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: #222;
}

.iv-control-btn {
    width: 36px;
    height: 36px;
    background: #444;
    border: none;
    border-radius: 50%;
    color: white;
    cursor: pointer;
    font-size: 1rem;
    transition: background 0.2s;
}
.iv-control-btn:hover {
    background: #555;
}
.iv-control-btn.primary {
    background: var(--primary);
}
.iv-control-btn.primary:hover {
    background: var(--primary-dark);
}

.iv-time-display {
    font-family: monospace;
    color: #ccc;
    font-size: 0.9rem;
}

.iv-sidebar {
    width: 320px;
    background: white;
    border-left: 1px solid var(--gray-200);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.iv-sidebar-tabs {
    display: flex;
    border-bottom: 1px solid var(--gray-200);
}

.iv-sidebar-tab {
    flex: 1;
    padding: 0.75rem;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--gray-500);
    border-bottom: 2px solid transparent;
}
.iv-sidebar-tab:hover {
    background: var(--gray-50);
}
.iv-sidebar-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}

.iv-sidebar-content {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.iv-interaction-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}
.iv-interaction-card:hover {
    border-color: var(--primary);
}
.iv-interaction-card.selected {
    border-color: var(--primary);
    background: #e0e7ff;
}

.iv-interaction-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.iv-interaction-icon {
    font-size: 1.2rem;
}

.iv-interaction-type {
    font-size: 0.7rem;
    color: var(--primary);
    font-weight: 600;
    text-transform: uppercase;
}

.iv-interaction-time {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--gray-500);
    font-family: monospace;
}

.iv-interaction-preview {
    font-size: 0.85rem;
    color: var(--gray-600);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.iv-interaction-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.iv-add-interaction {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.iv-add-interaction-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 0.75rem;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.iv-add-interaction-btn:hover {
    border-color: var(--primary);
    background: #f0f0ff;
}

/* ========== IMPORT SÉLECTIF ========== */
.import-selector {
    max-height: 400px;
    overflow-y: auto;
}

.import-course-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
    color: white;
    border-radius: 8px;
    margin-bottom: 1rem;
}
.import-course-info-icon {
    font-size: 2rem;
}
.import-course-info h4 {
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
}
.import-course-info p {
    font-size: 0.8rem;
    opacity: 0.9;
}

.import-section-item {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    margin-bottom: 0.75rem;
    overflow: hidden;
}

.import-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--gray-50);
    cursor: pointer;
}
.import-section-header:hover {
    background: var(--gray-100);
}

.import-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--primary);
}

.import-section-icon {
    font-size: 1.1rem;
}

.import-section-name {
    flex: 1;
    font-weight: 500;
}

.import-section-count {
    font-size: 0.75rem;
    color: var(--gray-500);
    background: var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
}

.import-section-toggle {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    color: var(--gray-400);
    padding: 0.25rem;
}

.import-activities {
    padding: 0.5rem 1rem 0.75rem 2.5rem;
    display: none;
}
.import-section-item.expanded .import-activities {
    display: block;
}

.import-activity-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    border-radius: 6px;
    margin-bottom: 0.25rem;
}
.import-activity-item:hover {
    background: var(--gray-50);
}

.import-activity-icon {
    font-size: 0.9rem;
}

.import-activity-name {
    flex: 1;
    font-size: 0.85rem;
}

.import-activity-type {
    font-size: 0.7rem;
    color: var(--gray-500);
}

.import-summary {
    padding: 1rem;
    background: var(--gray-50);
    border-radius: 8px;
    margin-top: 1rem;
    text-align: center;
}
.import-summary-count {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
}
.import-summary-text {
    font-size: 0.85rem;
    color: var(--gray-600);
}

/* === Import Local Button === */
.import-local-btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    border: 2px dashed var(--gray-300);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--gray-50);
}

.import-local-btn:hover {
    border-color: var(--primary);
    background: white;
}

.import-local-icon {
    font-size: 1.5rem;
}

.import-local-text {
    flex: 1;
    font-weight: 500;
    color: var(--gray-700);
}

.import-local-hint {
    font-size: 0.8rem;
    color: var(--gray-400);
    background: var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
}

/* === Import Separator === */
.import-separator {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin: 1.5rem 0;
    color: var(--gray-400);
    font-size: 0.85rem;
}

.import-separator::before,
.import-separator::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--gray-200);
}

/* === Import Drive List === */
.import-drive-folders {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.import-drive-folder {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    overflow: hidden;
}

.import-drive-folder-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--gray-50);
    cursor: pointer;
    transition: background 0.2s;
}

.import-drive-folder-header:hover {
    background: var(--gray-100);
}

.import-drive-folder-icon {
    font-size: 1.1rem;
}

.import-drive-folder-name {
    flex: 1;
    font-weight: 500;
}

.import-drive-folder-count {
    font-size: 0.8rem;
    color: var(--gray-500);
    background: var(--gray-200);
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
}

.import-drive-folder-toggle {
    font-size: 0.8rem;
    color: var(--gray-400);
    transition: transform 0.2s;
}

.import-drive-folder.expanded .import-drive-folder-toggle {
    transform: rotate(90deg);
}

.import-drive-courses {
    display: none;
    padding: 0.5rem;
    background: white;
}

.import-drive-folder.expanded .import-drive-courses {
    display: block;
}

.import-drive-course {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.import-drive-course:hover {
    background: var(--gray-100);
}

.import-drive-course-icon {
    font-size: 1rem;
}

.import-drive-course-name {
    flex: 1;
    font-size: 0.9rem;
}

.import-drive-course-action {
    font-size: 0.8rem;
    color: var(--primary);
    opacity: 0;
    transition: opacity 0.2s;
}

.import-drive-course:hover .import-drive-course-action {
    opacity: 1;
}

/* Spinner */
.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--gray-200);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ==================== VUE STRUCTURE DU COURS ==================== */

.sidebar-title.structure-link {
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
}
.sidebar-title.structure-link:hover {
    background: var(--primary);
    color: white;
}

.structure-view {
    padding: 24px;
    max-width: 1000px;
    margin: 0 auto;
    width: 100%;
}

.structure-header {
    margin-bottom: 24px;
    text-align: center;
}

.structure-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--gray-800);
    margin: 0 0 8px 0;
}

.structure-subtitle {
    font-size: 0.95rem;
    color: var(--gray-500);
    margin: 0;
}

.structure-sections {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.structure-section {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
    transition: all 0.2s;
    border: 2px solid transparent;
}

.structure-section:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

.structure-section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    cursor: grab;
    border-bottom: 1px solid var(--gray-200);
    cursor: pointer;
    transition: background 0.2s;
}

.structure-section-header:hover {
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
}

.structure-section-drag-handle {
    font-size: 1.2rem;
    color: var(--gray-400);
    cursor: grab;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s;
}

.structure-section-drag-handle:hover {
    background: var(--gray-200);
    color: var(--gray-600);
}

.structure-section-drag-handle:active {
    cursor: grabbing;
}

.structure-section-icon {
    font-size: 1.4rem;
}

.structure-section-name {
    flex: 1;
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--gray-800);
    cursor: text;
    border-radius: 3px;
    padding: 0.05rem 0.2rem;
}
.structure-section-name:hover {
    background: rgba(99, 102, 241, 0.06);
    text-decoration: underline;
    text-decoration-style: dashed;
    text-underline-offset: 3px;
    text-decoration-color: var(--gray-400);
}

.structure-section-count {
    font-size: 0.85rem;
    color: var(--gray-500);
    background: var(--gray-100);
    padding: 4px 10px;
    border-radius: 20px;
}

.structure-section-actions {
    display: flex;
    gap: 6px;
    opacity: 0;
    transition: opacity 0.2s;
}

.structure-section-header:hover .structure-section-actions {
    opacity: 1;
}

.structure-btn {
    background: white;
    border: 1px solid var(--gray-300);
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.structure-btn:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
}

.structure-btn.danger:hover {
    background: #fee2e2;
    border-color: #ef4444;
}

.structure-activities {
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.structure-activity {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--gray-50);
    border-radius: 8px;
    cursor: grab;
    transition: all 0.2s;
    border: 2px solid transparent;
}

.structure-activity:hover {
    background: var(--gray-100);
}

.structure-section.dragging,
.structure-activity.dragging {
    opacity: 0.3;
}

.structure-section-drag-handle,
.structure-activity-drag-handle {
    cursor: grab;
}
.structure-section-drag-handle:active,
.structure-activity-drag-handle:active {
    cursor: grabbing;
}

.structure-activity-drag-handle {
    font-size: 1rem;
    color: var(--gray-400);
    cursor: grab;
    padding: 2px 4px;
    border-radius: 4px;
    transition: all 0.2s;
}

.structure-activity-drag-handle:hover {
    background: var(--gray-200);
    color: var(--gray-600);
}

.structure-activity-drag-handle:active {
    cursor: grabbing;
}

.structure-activity-icon {
    font-size: 1.3rem;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.structure-activity-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.structure-activity-name {
    font-size: 1rem;
    font-weight: 500;
    color: var(--gray-800);
    cursor: text;
    border-radius: 3px;
    padding: 0.05rem 0.2rem;
}
.structure-activity-name:hover {
    background: rgba(99, 102, 241, 0.06);
    text-decoration: underline;
    text-decoration-style: dashed;
    text-underline-offset: 3px;
    text-decoration-color: var(--gray-400);
}

.structure-activity-type {
    font-size: 0.8rem;
    color: var(--gray-500);
}

.structure-activity-actions {
    display: flex;
    gap: 6px;
    opacity: 0;
    transition: opacity 0.2s;
}

.structure-activity:hover .structure-activity-actions {
    opacity: 1;
}

.structure-add-activity {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    background: transparent;
    border: 2px dashed var(--gray-300);
    border-radius: 8px;
    color: var(--gray-500);
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
}

.structure-add-activity:hover {
    background: var(--gray-50);
    border-color: var(--primary);
    color: var(--primary);
}

.structure-add-section {
    margin-top: 24px;
    text-align: center;
}

.structure-add-section .btn-lg {
    padding: 14px 28px;
    font-size: 1.05rem;
}

/* ========== Drag & Drop Preview ========== */
.cp-dragdrop-preview {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 8px;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 0.8rem;
    box-sizing: border-box;
}

.cp-dragdrop-header {
    font-weight: 600;
    color: #6366f1;
    font-size: 0.95rem;
    text-align: center;
    padding-bottom: 6px;
    border-bottom: 1px solid #dee2e6;
}

.cp-dragdrop-bg-indicator {
    text-align: center;
    font-size: 0.75rem;
    color: #6c757d;
    padding: 4px;
    background: white;
    border-radius: 4px;
}

.cp-dragdrop-section {
    background: white;
    border-radius: 6px;
    padding: 8px;
}

.cp-dragdrop-label {
    font-size: 0.7rem;
    color: #6c757d;
    margin-bottom: 4px;
    font-weight: 500;
}

.cp-dragdrop-items {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.cp-dq-element-preview {
    background: #e0e0e0;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.65rem;
    color: #495057;
    border: 1px solid #bbb;
}

.cp-dq-zone-preview {
    background: #fff3cd;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.65rem;
    color: #856404;
    border: 1px dashed #ffc107;
}

/* ========== Drag & Drop Editor Panel ========== */
.cp-dq-editor-section {
    background: var(--gray-50);
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 10px;
}

.cp-dq-editor-section-title {
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--gray-700);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.cp-dq-item-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    padding: 8px;
    margin-bottom: 6px;
}

.cp-dq-item-card:hover {
    border-color: var(--primary);
}

.cp-dq-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.cp-dq-item-title {
    font-weight: 500;
    font-size: 0.8rem;
    color: var(--gray-700);
}

.cp-dq-item-actions {
    display: flex;
    gap: 4px;
}

.cp-dq-item-btn {
    width: 22px;
    height: 22px;
    border: none;
    background: var(--gray-100);
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cp-dq-item-btn:hover {
    background: var(--gray-200);
}

.cp-dq-item-btn.delete:hover {
    background: #fee2e2;
    color: #dc2626;
}

.cp-dq-add-btn {
    width: 100%;
    padding: 8px;
    border: 2px dashed var(--gray-300);
    background: transparent;
    border-radius: 6px;
    color: var(--gray-500);
    cursor: pointer;
    font-size: 0.8rem;
    transition: all 0.2s;
}

.cp-dq-add-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--gray-50);
}

.cp-dq-position-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 6px;
}

.cp-dq-position-input {
    display: flex;
    align-items: center;
    gap: 4px;
}

.cp-dq-position-input label {
    font-size: 0.7rem;
    color: var(--gray-500);
    min-width: 20px;
}

.cp-dq-position-input input {
    flex: 1;
    padding: 4px 6px;
    font-size: 0.75rem;
    border: 1px solid var(--gray-200);
    border-radius: 4px;
    width: 50px;
}

.cp-dq-bg-preview {
    width: 100%;
    height: 80px;
    background: var(--gray-100);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    margin-bottom: 8px;
}

.cp-dq-bg-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.cp-dq-preset-images {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-top: 8px;
}

.cp-dq-preset-img {
    width: 100%;
    aspect-ratio: 16/9;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all 0.2s;
}

.cp-dq-preset-img:hover {
    border-color: var(--primary);
    transform: scale(1.05);
}

.cp-dq-preset-img.selected {
    border-color: var(--primary);
}

/* ========== Drag & Drop - Aperçu Canvas Amélioré ========== */
.cp-dq-canvas-preview {
    display: flex;
    align-items: center;
    justify-content: center;
}

.cp-dq-canvas-element {
    transition: transform 0.1s, box-shadow 0.1s;
}

.cp-dq-canvas-element:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.cp-dq-canvas-dropzone {
    transition: border-color 0.2s;
}

.cp-dq-canvas-badge {
    pointer-events: none;
}

/* ========== Drag & Drop - Panneau Propriétés Amélioré ========== */
.cp-dq-mini-preview-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.cp-dq-mini-preview {
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
}

.cp-dq-mini-element {
    transition: all 0.15s;
    font-weight: 600;
}

.cp-dq-mini-element:hover,
.cp-dq-mini-element.selected {
    background: #6366f1 !important;
    color: white !important;
    border-color: #4f46e5 !important;
    transform: scale(1.2);
    z-index: 10 !important;
}

.cp-dq-mini-zone {
    transition: all 0.15s;
}

.cp-dq-mini-zone:hover,
.cp-dq-mini-zone.selected {
    background: rgba(156,39,176,0.3) !important;
    border-width: 2px !important;
    border-color: #9c27b0 !important;
}

.cp-dq-bg-actions {
    display: flex;
    gap: 6px;
    margin-top: 8px;
}

.cp-dq-bg-btn {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid var(--gray-300);
    background: white;
    border-radius: 4px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.cp-dq-bg-btn:hover {
    background: var(--gray-50);
    border-color: var(--primary);
}

.cp-dq-bg-btn.danger {
    flex: 0;
    color: #dc2626;
}

.cp-dq-bg-btn.danger:hover {
    background: #fee2e2;
    border-color: #dc2626;
}

/* Boutons extraction blocs */
.cp-dq-blocks-btn {
    flex: 1;
    padding: 8px 10px;
    border: 1px solid #7c3aed;
    background: #f5f3ff;
    color: #5b21b6;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.cp-dq-blocks-btn:hover {
    background: #ede9fe;
    border-color: #6d28d9;
}
.cp-dq-blocks-btn.paste {
    border-color: #0891b2;
    background: #ecfeff;
    color: #155e75;
}
.cp-dq-blocks-btn.paste:hover {
    background: #cffafe;
    border-color: #0e7490;
}

/* Loading spinner */
.cp-dq-blocks-loading {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    color: #7c3aed;
    padding: 6px 0;
}
.cp-dq-blocks-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid #e9d5ff;
    border-top-color: #7c3aed;
    border-radius: 50%;
    animation: cpDqSpin 0.6s linear infinite;
}
@keyframes cpDqSpin {
    to { transform: rotate(360deg); }
}

.cp-dq-preset-section {
    margin-top: 10px;
}

.cp-dq-preset-section > label {
    font-size: 0.7rem;
    color: var(--gray-500);
    display: block;
    margin-bottom: 6px;
}

.cp-dq-dropdown-menu.open {
    display: block !important;
}

.cp-dq-editor-section-title {
    display: flex;
    align-items: center;
    gap: 8px;
}

.cp-dq-count {
    background: var(--gray-200);
    color: var(--gray-600);
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: normal;
}

.cp-dq-add-inline-btn {
    margin-left: auto;
    width: 22px;
    height: 22px;
    border: none;
    background: var(--primary);
    color: white;
    border-radius: 4px;
    font-size: 1rem;
    font-weight: bold;
    cursor: pointer;
    line-height: 1;
    transition: all 0.2s;
}

.cp-dq-add-inline-btn:hover {
    background: var(--primary-dark, #4f46e5);
    transform: scale(1.1);
}

.cp-dq-items-list {
    max-height: 300px;
    overflow-y: auto;
}

.cp-dq-item-card {
    transition: all 0.2s;
}

.cp-dq-item-card:hover {
    border-color: var(--primary);
}

.cp-dq-item-card.highlight {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2);
}

.cp-dq-zone-card {
    border-left: 3px solid #9c27b0;
}

.cp-dq-item-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    background: #e0e0e0;
    border-radius: 3px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-right: 4px;
}

.cp-dq-item-num.zone {
    background: rgba(156,39,176,0.15);
    color: #9c27b0;
}

.cp-dq-correct-select {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--gray-200);
}

.cp-dq-correct-select > label {
    font-size: 0.7rem;
    color: var(--gray-500);
    display: block;
    margin-bottom: 4px;
}

.cp-dq-correct-select select {
    font-size: 0.75rem;
}

.cp-dq-empty {
    color: #999;
    font-size: 0.8rem;
    text-align: center;
    padding: 15px;
    margin: 0;
}

.cp-dq-item-btn {
    opacity: 0.6;
}

.cp-dq-item-card:hover .cp-dq-item-btn {
    opacity: 1;
}

/* Amélioration prévisualisation fond */
.cp-dq-bg-preview {
    height: 100px;
    background: linear-gradient(45deg, #f0f0f0 25%, transparent 25%), 
                linear-gradient(-45deg, #f0f0f0 25%, transparent 25%), 
                linear-gradient(45deg, transparent 75%, #f0f0f0 75%), 
                linear-gradient(-45deg, transparent 75%, #f0f0f0 75%);
    background-size: 10px 10px;
    background-position: 0 0, 0 5px, 5px -5px, -5px 0px;
    background-color: white;
}

.cp-dq-bg-preview img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

/* ========== Drag & Drop - Poignées de redimensionnement ========== */
.cp-dq-resize-handle {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 12px;
    height: 12px;
    background: linear-gradient(135deg, transparent 50%, #6366f1 50%);
    cursor: se-resize;
    border-radius: 0 0 3px 0;
    opacity: 0;
    transition: opacity 0.2s;
}

.cp-dq-resize-handle.zone {
    background: linear-gradient(135deg, transparent 50%, #9c27b0 50%);
}

.cp-dq-interactive:hover .cp-dq-resize-handle {
    opacity: 1;
}

.cp-dq-interactive {
    transition: outline 0.1s, box-shadow 0.1s;
}

.cp-dq-interactive:hover {
    outline: 1px solid rgba(99, 102, 241, 0.5);
}

.cp-dq-canvas-dropzone.cp-dq-interactive:hover {
    outline: 1px solid rgba(156, 39, 176, 0.5);
    background: rgba(156, 39, 176, 0.1) !important;
}

/* Curseurs pour indiquer les interactions */
.cp-dq-canvas-element.cp-dq-interactive {
    cursor: move;
}

.cp-dq-canvas-dropzone.cp-dq-interactive {
    cursor: move;
}

/* Style pendant le drag/resize */
.cp-dq-interactive[style*="outline: 2px"] {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* ==================== DDI (Glisser Image) EDITOR ==================== */
.ddi-editor {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding: 0.75rem 1rem;
}

.ddi-header-compact {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
    flex-shrink: 0;
}
.ddi-header-compact .ed-title {
    flex: 1;
    font-size: 1.05rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin: 0;
}
.ddi-header-compact .ed-header-actions {
    display: flex;
    gap: 0.25rem;
    flex-shrink: 0;
}

.ddi-questiontext-section {
    margin-bottom: 0.5rem;
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    background: white;
    flex-shrink: 0;
}

.ddi-rt-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 4px 8px;
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
    border-radius: 6px 6px 0 0;
    flex-wrap: wrap;
}
.ddi-rt-heading {
    padding: 2px 4px;
    border: 1px solid var(--gray-200);
    border-radius: 4px;
    font-size: 0.75rem;
    background: white;
    cursor: pointer;
}

.ddi-rt-editor {
    min-height: 2.5em;
    max-height: 150px;
    overflow-y: auto;
    padding: 8px 12px;
    font-size: 0.9rem;
    line-height: 1.5;
    outline: none;
}
.ddi-rt-editor:empty:before {
    content: attr(data-placeholder);
    color: var(--gray-400);
    pointer-events: none;
}
.ddi-rt-editor img {
    max-width: 100%;
    border-radius: 4px;
    vertical-align: middle;
    cursor: pointer;
}
.ddi-rt-editor img:hover {
    outline: 2px solid rgba(25, 118, 210, 0.3);
}

.ddi-layout {
    display: flex;
    gap: 1rem;
    flex: 1;
    min-height: 0;
    overflow: hidden;
}

.ddi-canvas-wrap {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    background: #e8e8e8;
    border-radius: 8px;
    padding: 1rem;
    padding-bottom: 2.5rem;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    max-height: calc(100vh - 200px);
}
/* Wrapper interne scrollable : isole le scroll du canvas pour que la
   .ddi-zoom-bar (en position: absolute sur .ddi-canvas-wrap) reste fixée
   en bas, même quand le contenu déborde. */
.ddi-canvas-scroll {
    flex: 1;
    min-width: 0;
    min-height: 0;
    width: 100%;
    overflow: hidden;
}

.ddi-canvas {
    box-shadow: 0 2px 12px rgba(0,0,0,0.15);
}

.ddi-canvas-row {
    display: flex;
    flex-direction: row;
    gap: 12px;
    align-items: flex-start;
    width: max-content;
    transform-origin: top left;
    transition: transform 0.15s ease;
}

.ddi-staging-col {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 8px 10px;
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 8px;
    align-self: flex-start;
}
.ddi-staging-col .ddi-staging-label {
    font-size: 0.8em;
    color: #666;
    font-weight: 600;
    margin-bottom: 4px;
}

.ddi-zoom-bar {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    padding: 4px 8px;
    background: rgba(232,232,232,0.92);
    border-top: 1px solid #ddd;
    border-radius: 0 0 8px 8px;
    z-index: 30;
    font-size: 0.7rem;
    color: #666;
}
.ddi-zoom-bar button {
    width: 24px; height: 24px;
    border: 1px solid #ccc; border-radius: 4px;
    background: #fff; cursor: pointer;
    font-size: 14px; font-weight: bold;
    display: flex; align-items: center; justify-content: center;
    color: #555;
}
.ddi-zoom-bar button:hover { background: #eee; }
.ddi-zoom-bar input[type="range"] {
    width: 90px; height: 4px;
    accent-color: #7b1fa2;
}

.ddi-panel {
    width: 320px;
    min-width: 280px;
    overflow-y: auto;
    padding: 0 4px 1rem 0;
}

.ddi-canvas-drop.selected {
    border-color: #7b1fa2 !important;
    border-style: solid !important;
}

.ddi-canvas-drag.selected {
    border-color: #1976d2 !important;
    border-style: solid !important;
}

.ddi-resize-handle {
    position: absolute;
    bottom: -4px;
    right: -4px;
    width: 12px;
    height: 12px;
    background: #9c27b0;
    border: 2px solid white;
    border-radius: 2px;
    cursor: se-resize;
    z-index: 25;
}

@media (max-width: 900px) {
    .ddi-layout {
        flex-direction: column;
    }
    .ddi-panel {
        width: 100%;
        min-width: 0;
        max-height: 300px;
    }
}

/* ========== ÉLÉMENTS TABLEAU (H5P.Table) dans le CP ========== */
.cp-element-table .cp-element-content {
    overflow: auto;
}
.cp-table-element {
    width: 100%;
    height: 100%;
    overflow: auto;
    box-sizing: border-box;
    font-size: 0.8em;
}
/* Canvas : HTML normalisé par JS (inline styles sur cellules) */
.cp-table-element { font-size: 0.75em; }
.cp-table-element figure { margin: 0; width: 100%; }
.cp-table-element table { width: 100%; }
.cp-table-element td, .cp-table-element th { word-wrap: break-word; }
.cp-table-element .table-overflow-protection { display: none; }

/* Panneau propriétés tableau */
.cp-table-props-wrapper {
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    padding: 4px;
    overflow: auto;
    max-height: 280px;
    background: white;
    position: relative;
}
/* Panel propriétés : HTML normalisé par JS (inline styles sur cellules) */
.cp-table-props-wrapper figure { margin: 0; width: 100%; }
.cp-table-props-wrapper table { width: 100%; }
.cp-table-props-wrapper td, .cp-table-props-wrapper th {
    word-wrap: break-word;
    cursor: default;
    min-width: 30px;
    position: relative;
}
.cp-table-props-wrapper td[contenteditable="true"],
.cp-table-props-wrapper th[contenteditable="true"] {
    outline: 2px solid var(--primary);
    background: #f0f4ff;
    cursor: text;
}
.cp-table-props-wrapper .table-overflow-protection { display: none; }

/* Poignées de redimensionnement de colonnes */
.cp-col-resize-handle {
    position: absolute;
    top: 0;
    width: 7px;
    cursor: col-resize;
    background: transparent;
    z-index: 10;
    transition: background 0.15s;
}
.cp-col-resize-handle:hover,
.cp-col-resize-handle.dragging {
    background: rgba(99, 102, 241, 0.35);
}

/* Dialogue de création de tableau */
.cp-table-dialog-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 9000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.cp-table-dialog {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    min-width: 280px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
}
.cp-table-dialog h3 {
    margin-bottom: 1rem;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
}
.cp-table-dialog-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
}
.cp-table-dialog-row label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 0.85rem;
    color: var(--gray-700);
    flex: 1;
}
.cp-table-dialog-row label input[type="number"] {
    padding: 6px 8px;
    border: 1px solid var(--gray-300);
    border-radius: 6px;
    font-size: 0.9rem;
    width: 100%;
}
.cp-table-dialog-row label.checkbox-row {
    flex-direction: row;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.cp-table-dialog-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    margin-top: 1rem;
}
.cp-table-dialog-actions button {
    padding: 6px 16px;
    border-radius: 6px;
    border: 1px solid var(--gray-300);
    cursor: pointer;
    font-size: 0.85rem;
    background: white;
    color: var(--gray-700);
}
.cp-table-dialog-actions button.btn-primary {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
/* Tableaux dans rich-text-editor (texte) */
.rich-text-editor figure { margin: 0.25rem 0; max-width: 100%; }
.rich-text-editor table { width: 100%; border-collapse: collapse; }
.rich-text-editor td, .rich-text-editor th { border: 1px solid #ccc; padding: 4px 6px; }
.rich-text-editor .table-overflow-protection { display: none; }
