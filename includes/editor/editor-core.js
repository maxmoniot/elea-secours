// ==================== CORE : Arborescence, Sections, Activités ====================

// ==================== UNDO / REDO ====================
let courseHistory = [];
let courseHistoryIndex = -1;
let courseHistorySaveTimeout = null;
const COURSE_HISTORY_MAX = 50;

function courseSaveToHistory() {
    if (courseHistorySaveTimeout) clearTimeout(courseHistorySaveTimeout);
    courseHistorySaveTimeout = setTimeout(() => {
        _courseCommitHistory();
    }, 500);
}

function _courseCommitHistory() {
    if (typeof courseData === 'undefined') return;
    const snapshot = JSON.stringify(courseData);
    if (courseHistoryIndex >= 0 && courseHistory[courseHistoryIndex] === snapshot) return;
    courseHistory = courseHistory.slice(0, courseHistoryIndex + 1);
    courseHistory.push(snapshot);
    if (courseHistory.length > COURSE_HISTORY_MAX) courseHistory.shift();
    courseHistoryIndex = courseHistory.length - 1;
    _courseUpdateUndoRedoBtns();
}

function courseUndo() {
    if (courseHistorySaveTimeout) { clearTimeout(courseHistorySaveTimeout); _courseCommitHistory(); }
    if (courseHistoryIndex <= 0) return;
    courseHistoryIndex--;
    _courseRestoreHistory();
}

function courseRedo() {
    if (courseHistoryIndex >= courseHistory.length - 1) return;
    courseHistoryIndex++;
    _courseRestoreHistory();
}

function _courseRestoreHistory() {
    const snapshot = courseHistory[courseHistoryIndex];
    if (!snapshot) return;
    const restored = JSON.parse(snapshot);
    Object.keys(courseData).forEach(k => delete courseData[k]);
    Object.assign(courseData, restored);
    // Mettre à jour le nom du cours dans le header
    const nameInput = document.getElementById('courseName');
    if (nameInput) nameInput.value = courseData.name || '';
    // Re-render l'arborescence
    renderTree();
    // Re-render le contenu actuel
    if (selectedActivity && selectedSection) {
        renderActivityEditor();
    } else if (structureViewActive) {
        renderStructureView();
    }
    if (typeof renderProperties === 'function') renderProperties();
    if (typeof updateSaveStatus === 'function') updateSaveStatus('modified');
    _courseUpdateUndoRedoBtns();
}

function _courseUpdateUndoRedoBtns() {
    document.querySelectorAll('.ed-undo-btn').forEach(btn => btn.disabled = courseHistoryIndex <= 0);
    document.querySelectorAll('.ed-redo-btn').forEach(btn => btn.disabled = courseHistoryIndex >= courseHistory.length - 1);
}

// Raccourcis clavier Ctrl+Z / Ctrl+Y
document.addEventListener('keydown', function(e) {
    const tag = e.target.tagName;
    const ce = e.target.contentEditable === 'true';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || ce) return;
    if (e.ctrlKey && e.key === 'z') { e.preventDefault(); courseUndo(); }
    if (e.ctrlKey && e.key === 'y') { e.preventDefault(); courseRedo(); }
});

// Helper: génère le header standard pour un éditeur d'activité
function editorHeaderHtml(icon, activityName, sectionId) {
    const sid = sectionId || selectedSection;
    const canUndo = courseHistoryIndex > 0;
    const canRedo = courseHistoryIndex < courseHistory.length - 1;
    return `<div class="ed-header">
        <button class="btn btn-secondary ed-back-btn" onclick="showStructureView()">← Retour</button>
        <h3 class="ed-title">${icon} <span class="activity-name-editable" onclick="startEditActivityNameInHeader(this)">${escapeHtml(activityName)}</span></h3>
        <div class="ed-header-actions">
            <button class="ed-undo-btn" onclick="courseUndo()" title="Annuler (Ctrl+Z)" ${canUndo ? '' : 'disabled'}>↩</button>
            <button class="ed-redo-btn" onclick="courseRedo()" title="Répéter (Ctrl+Y)" ${canRedo ? '' : 'disabled'}>↪</button>
        </div>
    </div>`
}

// ==================== ARBORESCENCE ====================
function renderTree() {
    const container = document.getElementById('treeContainer');
    
    if (courseData.sections.length === 0) {
        container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--gray-400); font-size: 0.85rem;">Aucune section</div>';
        document.getElementById('emptyCanvas').style.display = 'flex';
        document.getElementById('editorContent').style.display = 'none';
        return;
    }
    
    let html = '';
    courseData.sections.forEach((section, sIdx) => {
        const isSelected = selectedSection === section.id && !selectedActivity;
        const isCollapsed = section.collapsed ? ' collapsed' : '';
        const sectionHidden = section.visible === false;
        const sectionDimClass = sectionHidden ? ' tree-hidden' : '';
        
        html += `
            <div class="tree-section${isCollapsed}${sectionDimClass}" data-id="${section.id}" data-idx="${sIdx}">
                <div class="tree-section-header${isSelected ? ' selected' : ''}" 
                     onmousedown="treeDragStart(event, 'section', '${section.id}')"
                     onclick="selectSection('${section.id}')"
                     oncontextmenu="showContextMenu(event, 'section', '${section.id}')">
                    <span class="tree-section-icon">📁</span>
                    <span class="tree-section-name" onclick="event.stopPropagation(); startEditSectionName('${section.id}', this)">${escapeHtml(section.name)}</span>
                    <div class="tree-section-actions">
                        <button class="tree-action-btn tree-vis-btn${sectionHidden ? ' vis-off' : ''}" onclick="event.stopPropagation(); toggleSectionVisibility('${section.id}')" title="${sectionHidden ? 'Afficher la section' : 'Masquer la section'}">${sectionHidden ? '🙈' : '👁️'}</button>
                        <button class="tree-action-btn tree-collapse-btn" onclick="event.stopPropagation(); toggleSectionCollapse('${section.id}')" title="Réduire/Développer"><span class="tree-caret">▼</span></button>
                        <button class="tree-action-btn" onclick="event.stopPropagation(); deleteSection('${section.id}')" title="Supprimer">🗑️</button>
                    </div>
                </div>
                <div class="tree-activities">`;
        
        (section.activities || []).forEach((activity, aIdx) => {
            const actSelected = selectedActivity === activity.id;
            const icon = getActivityIcon(['assign','resource','mapmodules'].includes(activity.type) ? activity.type : (activity.quizType || activity.h5pType || activity.type));
            const actHidden = activity.visible === false || sectionHidden;
            const actOwnHidden = activity.visible === false;
            const actDimClass = actHidden ? ' tree-hidden' : '';
            
            html += `
                    <div class="tree-activity${actSelected ? ' selected' : ''}${actDimClass}" data-id="${activity.id}" data-idx="${aIdx}" 
                         data-section="${section.id}"
                         onmousedown="treeDragStart(event, 'activity', '${activity.id}', '${section.id}')"
                         onclick="selectActivity('${section.id}', '${activity.id}')"
                         oncontextmenu="showContextMenu(event, 'activity', '${activity.id}', '${section.id}')">
                        <span class="tree-activity-icon">${icon}</span>
                        <span class="tree-activity-name" onclick="event.stopPropagation(); startEditActivityName('${section.id}', '${activity.id}', this)">${escapeHtml(activity.name)}</span>
                        <div class="tree-activity-actions">
                            <button class="tree-action-btn tree-vis-btn${actOwnHidden ? ' vis-off' : ''}${sectionHidden ? ' vis-inherited' : ''}" onclick="event.stopPropagation(); toggleActivityVisibility('${section.id}', '${activity.id}')" title="${actOwnHidden ? 'Afficher le parcours' : 'Masquer le parcours'}">${actOwnHidden ? '🙈' : '👁️'}</button>
                            <button class="tree-action-btn" onclick="event.stopPropagation(); deleteActivity('${section.id}', '${activity.id}')" title="Supprimer">🗑️</button>
                        </div>
                    </div>`;
        });
        
        html += `
                    <div class="tree-activity add-activity" style="color: var(--gray-400);" onclick="openAddActivityModal('${section.id}')">
                        <span class="tree-activity-icon">➕</span>
                        <span class="tree-activity-name">Ajouter...</span>
                    </div>
                </div>
            </div>`;
    });
    
    container.innerHTML = html;
    
    // Mettre à jour le calcul du poids du cours
    if (typeof calculateCourseSize === 'function') {
        calculateCourseSize();
    }
}

// ==================== ÉDITION INLINE DES NOMS ====================

function startEditActivityName(sectionId, activityId, element) {
    const section = courseData.sections.find(s => s.id === sectionId);
    const activity = section?.activities.find(a => a.id === activityId);
    if (!activity) return;

    // Déjà en édition : laisser le clic placer le curseur (ne pas re-sélectionner tout)
    if (element.contentEditable === 'true') return;

    const currentName = activity.name || '';
    
    element.classList.add('editing');
    element.contentEditable = true;
    element.textContent = currentName;
    element.focus();
    
    // Sélectionner tout le texte
    const range = document.createRange();
    range.selectNodeContents(element);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    // Handlers pour terminer l'édition
    const finishEdit = () => {
        element.classList.remove('editing');
        element.contentEditable = false;
        
        const newName = element.textContent.trim() || 'Sans titre';
        activity.name = newName;
        element.textContent = newName;
        
        // Mettre à jour les autres vues
        renderProperties();
        if (typeof renderCoursePresentationEditor === 'function' && selectedActivity === activityId) {
            renderCoursePresentationEditor(activity);
        }
        if (typeof onCourseModified === 'function') onCourseModified();
    };
    
    element.onblur = finishEdit;
    element.onkeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            element.blur();
        } else if (e.key === 'Escape') {
            element.textContent = currentName;
            element.blur();
        }
    };
}

function startEditSectionName(sectionId, element) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;

    // Déjà en édition : laisser le clic placer le curseur (ne pas re-sélectionner tout)
    if (element.contentEditable === 'true') return;

    const currentName = section.name || '';
    
    element.classList.add('editing');
    element.contentEditable = true;
    element.textContent = currentName;
    element.focus();
    
    // Sélectionner tout le texte
    const range = document.createRange();
    range.selectNodeContents(element);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    const finishEdit = () => {
        element.classList.remove('editing');
        element.contentEditable = false;
        
        const newName = element.textContent.trim() || 'Section';
        section.name = newName;
        element.textContent = newName;
        
        renderProperties();
        if (typeof onCourseModified === 'function') onCourseModified();
    };
    
    element.onblur = finishEdit;
    element.onkeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            element.blur();
        } else if (e.key === 'Escape') {
            element.textContent = currentName;
            element.blur();
        }
    };
}

// ==================== ÉDITION NOM DEPUIS HEADER ====================

// Édition du nom depuis le header de l'éditeur de présentation
function startEditActivityNameInHeader(element) {
    const activity = getCurrentActivity();
    if (!activity) return;
    
    // Si déjà en mode édition, laisser le clic placer le curseur normalement
    if (element.contentEditable === 'true') return;
    
    const currentName = activity.name || '';
    
    element.classList.add('editing');
    element.contentEditable = true;
    element.textContent = currentName;
    element.focus();
    
    // Sélectionner tout le texte au premier clic
    const range = document.createRange();
    range.selectNodeContents(element);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    const finishEdit = () => {
        element.classList.remove('editing');
        element.contentEditable = false;
        
        const newName = element.textContent.trim() || 'Sans titre';
        activity.name = newName;
        element.textContent = newName;
        
        // Mettre à jour l'arborescence et les propriétés
        renderTree();
        renderProperties();
        if (typeof onCourseModified === 'function') onCourseModified();
    };
    
    element.onblur = finishEdit;
    element.onkeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            element.blur();
        } else if (e.key === 'Escape') {
            element.textContent = currentName;
            element.blur();
        }
    };
}

// Récupérer l'activité courante
function getCurrentActivity() {
    if (!selectedSection || !selectedActivity) return null;
    const section = courseData.sections.find(s => s.id === selectedSection);
    return section?.activities.find(a => a.id === selectedActivity);
}

// ==================== MENU CONTEXTUEL ====================
let contextMenuData = null;

function showContextMenu(event, type, id, sectionId = null) {
    event.preventDefault();
    event.stopPropagation();
    
    contextMenuData = { type, id, sectionId };
    
    // Supprimer l'ancien menu s'il existe
    hideContextMenu();
    
    // Créer le menu
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.id = 'contextMenu';
    
    if (type === 'section') {
        menu.innerHTML = `
            <div class="context-menu-item" onclick="contextMenuRename()">
                <span>✏️</span> Renommer
            </div>
            <div class="context-menu-item" onclick="contextMenuDuplicate()">
                <span>📋</span> Dupliquer
            </div>
            <div class="context-menu-separator"></div>
            <div class="context-menu-item danger" onclick="contextMenuDelete()">
                <span>🗑️</span> Supprimer
            </div>
        `;
    } else {
        menu.innerHTML = `
            <div class="context-menu-item" onclick="contextMenuRename()">
                <span>✏️</span> Renommer
            </div>
            <div class="context-menu-item" onclick="contextMenuEdit()">
                <span>📝</span> Éditer
            </div>
            <div class="context-menu-item" onclick="contextMenuDuplicate()">
                <span>📋</span> Dupliquer
            </div>
            <div class="context-menu-separator"></div>
            <div class="context-menu-item danger" onclick="contextMenuDelete()">
                <span>🗑️</span> Supprimer
            </div>
        `;
    }
    
    document.body.appendChild(menu);
    
    // Positionner le menu
    const x = event.clientX;
    const y = event.clientY;
    
    // Ajuster si le menu dépasse l'écran
    const menuRect = menu.getBoundingClientRect();
    const maxX = window.innerWidth - menuRect.width - 10;
    const maxY = window.innerHeight - menuRect.height - 10;
    
    menu.style.left = Math.min(x, maxX) + 'px';
    menu.style.top = Math.min(y, maxY) + 'px';
    
    // Fermer le menu au clic ailleurs
    setTimeout(() => {
        document.addEventListener('click', hideContextMenu);
        document.addEventListener('contextmenu', hideContextMenu);
    }, 10);
}

function hideContextMenu() {
    const menu = document.getElementById('contextMenu');
    if (menu) {
        menu.remove();
    }
    document.removeEventListener('click', hideContextMenu);
    document.removeEventListener('contextmenu', hideContextMenu);
}

function contextMenuRename() {
    hideContextMenu();
    if (!contextMenuData) return;
    
    const { type, id, sectionId } = contextMenuData;
    
    if (type === 'section') {
        // Try structure view first, then tree
        const structNameEl = document.querySelector(`.structure-section[data-section-id="${id}"] .structure-section-name`);
        if (structNameEl && structureViewActive) {
            structureStartRename(structNameEl, 'section', id);
        } else {
            const nameEl = document.querySelector(`.tree-section[data-id="${id}"] .tree-section-name`);
            if (nameEl) startEditSectionName(id, nameEl);
        }
    } else {
        const structNameEl = document.querySelector(`.structure-activity[data-activity-id="${id}"] .structure-activity-name`);
        if (structNameEl && structureViewActive) {
            structureStartRename(structNameEl, 'activity', id, sectionId);
        } else {
            const nameEl = document.querySelector(`.tree-activity[data-id="${id}"] .tree-activity-name`);
            if (nameEl) startEditActivityName(sectionId, id, nameEl);
        }
    }
}

function contextMenuEdit() {
    hideContextMenu();
    if (!contextMenuData || contextMenuData.type !== 'activity') return;
    
    const { id, sectionId } = contextMenuData;
    editActivity(sectionId, id);
}

function contextMenuDuplicate() {
    hideContextMenu();
    if (!contextMenuData) return;
    
    const { type, id, sectionId } = contextMenuData;
    
    if (type === 'section') {
        const section = courseData.sections.find(s => s.id === id);
        if (section) {
            const newSection = JSON.parse(JSON.stringify(section));
            newSection.id = generateId();
            newSection.name = section.name + ' (copie)';
            newSection.activities = newSection.activities.map(a => ({
                ...a,
                id: generateId()
            }));
            
            const idx = courseData.sections.findIndex(s => s.id === id);
            courseData.sections.splice(idx + 1, 0, newSection);
            
            renderTree();
            if (structureViewActive) renderStructureView();
            onCourseModified();
            showToast('Section dupliquée', 'success');
        }
    } else {
        const section = courseData.sections.find(s => s.id === sectionId);
        const activity = section?.activities.find(a => a.id === id);
        if (activity) {
            const newActivity = JSON.parse(JSON.stringify(activity));
            newActivity.id = generateId();
            newActivity.name = activity.name + ' (copie)';
            
            const idx = section.activities.findIndex(a => a.id === id);
            section.activities.splice(idx + 1, 0, newActivity);
            
            renderTree();
            renderStructureView();
            onCourseModified();
            showToast('Activité dupliquée', 'success');
        }
    }
}

function contextMenuDelete() {
    hideContextMenu();
    if (!contextMenuData) return;
    
    const { type, id, sectionId } = contextMenuData;
    
    if (type === 'section') {
        deleteSection(id);
    } else {
        deleteActivity(sectionId, id);
    }
}

// ==================== ÉDITION NOM DEPUIS LA VUE SECTION ====================

function startEditSectionNameInPreview(sectionId, element) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;

    // Déjà en édition : laisser le clic placer le curseur (ne pas re-sélectionner tout)
    if (element.contentEditable === 'true') return;

    const currentName = section.name || '';
    
    element.classList.add('editing');
    element.contentEditable = true;
    element.textContent = currentName;
    element.focus();
    
    // Sélectionner tout le texte
    const range = document.createRange();
    range.selectNodeContents(element);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    const finishEdit = () => {
        element.classList.remove('editing');
        element.contentEditable = false;
        
        const newName = element.textContent.trim() || 'Section';
        section.name = newName;
        element.textContent = newName;
        
        // Mettre à jour l'arborescence
        renderTree();
        renderProperties();
        if (typeof onCourseModified === 'function') onCourseModified();
    };
    
    element.onblur = finishEdit;
    element.onkeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            element.blur();
        } else if (e.key === 'Escape') {
            element.textContent = currentName;
            element.blur();
        }
    };
}

function startEditActivityNameInCard(sectionId, activityId, element) {
    const section = courseData.sections.find(s => s.id === sectionId);
    const activity = section?.activities.find(a => a.id === activityId);
    if (!activity) return;

    // Déjà en édition : laisser le clic placer le curseur (ne pas re-sélectionner tout)
    if (element.contentEditable === 'true') return;

    const currentName = activity.name || '';
    
    element.classList.add('editing');
    element.contentEditable = true;
    element.textContent = currentName;
    element.focus();
    
    // Sélectionner tout le texte
    const range = document.createRange();
    range.selectNodeContents(element);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    const finishEdit = () => {
        element.classList.remove('editing');
        element.contentEditable = false;
        
        const newName = element.textContent.trim() || 'Sans titre';
        activity.name = newName;
        element.textContent = newName;
        
        // Mettre à jour l'arborescence
        renderTree();
        renderProperties();
        if (typeof onCourseModified === 'function') onCourseModified();
    };
    
    element.onblur = finishEdit;
    element.onkeydown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            element.blur();
        } else if (e.key === 'Escape') {
            element.textContent = currentName;
            element.blur();
        }
    };
}

// ==================== DRAG & DROP (Tree sidebar) ====================
var _td = { active: false, type: null, id: null, sectionId: null, el: null, ghost: null, placeholder: null, startY: 0 };

function treeDragStart(event, type, id, sectionId) {
    var tag = event.target.tagName;
    if (tag === 'BUTTON' || tag === 'INPUT') return;
    if (event.target.closest('.tree-action-btn, .tree-section-actions, .tree-activity-actions')) return;
    if (event.target.closest('.tree-activity.add-activity')) return;
    if (event.button !== 0) return;
    
    var el;
    if (type === 'section') {
        el = event.target.closest('.tree-section');
    } else {
        el = event.target.closest('.tree-activity');
    }
    if (!el) return;
    
    _td.type = type;
    _td.id = id;
    _td.sectionId = sectionId || null;
    _td.el = el;
    _td.startY = event.clientY;
    _td.active = false;
    
    document.addEventListener('mousemove', treeDragMove);
    document.addEventListener('mouseup', treeDragEnd);
}

function treeDragMove(event) {
    event.preventDefault();
    if (!_td.active && Math.abs(event.clientY - _td.startY) < 5) return;
    
    if (!_td.active) {
        _td.active = true;
        document.body.style.userSelect = 'none';
        document.body.style.webkitUserSelect = 'none';
        window.getSelection && window.getSelection().removeAllRanges();
        
        var rect = _td.el.getBoundingClientRect();
        _td.ghost = _td.el.cloneNode(true);
        _td.ghost.className = _td.el.className + ' tree-drag-ghost';
        _td.ghost.style.cssText =
            'position:fixed; z-index:9999; pointer-events:none;' +
            'width:' + rect.width + 'px; height:' + rect.height + 'px;' +
            'opacity:0.8; transform:scale(1.02);' +
            'box-shadow:0 4px 12px rgba(0,0,0,0.2); border-radius:6px;' +
            'background:white; overflow:hidden;';
        document.body.appendChild(_td.ghost);
        
        _td.offsetY = event.clientY - rect.top;
        _td.el.style.opacity = '0.25';
        _td.el.style.transition = 'none';
        
        _td.placeholder = document.createElement('div');
        _td.placeholder.className = 'tree-drag-placeholder';
        var ph = _td.type === 'section' ? Math.min(rect.height, 36) : rect.height;
        _td.placeholderHeight = ph;
    }
    
    _td.ghost.style.left = _td.el.getBoundingClientRect().left + 'px';
    _td.ghost.style.top = (event.clientY - _td.offsetY) + 'px';
    
    // Items cibles
    if (_td.type === 'section') {
        var items = Array.from(document.querySelectorAll('#treeContainer > .tree-section'));
        var others = items.filter(function(it) { return it !== _td.el; });
        if (others.length === 0) { return; }
        
        var insertBeforeItem = null;
        for (var i = 0; i < others.length; i++) {
            var r = others[i].getBoundingClientRect();
            if (r.height === 0) continue;
            if (event.clientY < r.top + r.height / 2) { insertBeforeItem = others[i]; break; }
        }
        
        var targetParent, targetNextSibling;
        if (insertBeforeItem) {
            targetParent = insertBeforeItem.parentNode;
            targetNextSibling = insertBeforeItem;
        } else {
            var last = others[others.length - 1];
            targetParent = last.parentNode;
            var ns = last.nextSibling;
            while (ns && (ns === _td.el || ns === _td.placeholder)) ns = ns.nextSibling;
            targetNextSibling = ns;
        }
        
        var isOriginal = false;
        if (targetNextSibling === _td.el) {
            isOriginal = true;
        } else {
            var elNext = _td.el.nextElementSibling;
            while (elNext && elNext === _td.placeholder) elNext = elNext.nextElementSibling;
            if (targetNextSibling === elNext && targetParent === _td.el.parentNode) isOriginal = true;
        }
        
        var siblings = Array.from(targetParent.children);
        var targetH = isOriginal ? 0 : _td.placeholderHeight;
        flipAnimate(siblings, _td.placeholder, _td.el, function() {
            if (targetNextSibling) {
                targetParent.insertBefore(_td.placeholder, targetNextSibling);
            } else {
                targetParent.appendChild(_td.placeholder);
            }
            _td.placeholder.style.height = targetH + 'px';
        });
    } else {
        // ACTIVITÉS : approche section-aware
        // 1) Trouver quelle section (.tree-activities) le curseur survole
        var hoveredContainer = null;
        var allContainers = document.querySelectorAll('.tree-activities');
        for (var c = 0; c < allContainers.length; c++) {
            var cr = allContainers[c].getBoundingClientRect();
            if (event.clientY >= cr.top && event.clientY <= cr.bottom) {
                hoveredContainer = allContainers[c];
                break;
            }
        }
        // Si entre deux sections, prendre la plus proche
        if (!hoveredContainer) {
            var bestDist = Infinity;
            for (var c = 0; c < allContainers.length; c++) {
                var cr = allContainers[c].getBoundingClientRect();
                var dist = event.clientY < cr.top ? cr.top - event.clientY : event.clientY - cr.bottom;
                if (dist < bestDist) { bestDist = dist; hoveredContainer = allContainers[c]; }
            }
        }
        if (!hoveredContainer) return;
        
        // 2) Activités dans cette section uniquement
        var sectionActs = Array.from(hoveredContainer.querySelectorAll('.tree-activity:not(.add-activity)'));
        var others = sectionActs.filter(function(it) { return it !== _td.el; });
        var addBtn = hoveredContainer.querySelector('.tree-activity.add-activity');
        
        if (others.length === 0) {
            // Section vide (ou ne contient que l'élément draggé)
            var siblings = Array.from(hoveredContainer.children);
            flipAnimate(siblings, _td.placeholder, _td.el, function() {
                if (addBtn) {
                    hoveredContainer.insertBefore(_td.placeholder, addBtn);
                } else {
                    hoveredContainer.appendChild(_td.placeholder);
                }
                _td.placeholder.style.height = _td.placeholderHeight + 'px';
            });
        } else {
            // Trouver la position d'insertion dans cette section
            var insertBeforeItem = null;
            for (var i = 0; i < others.length; i++) {
                var r = others[i].getBoundingClientRect();
                if (r.height === 0) continue;
                if (event.clientY < r.top + r.height / 2) { insertBeforeItem = others[i]; break; }
            }
            
            var targetNextSibling;
            if (insertBeforeItem) {
                targetNextSibling = insertBeforeItem;
            } else {
                // Après la dernière activité = avant le bouton +, ou en fin de container
                targetNextSibling = addBtn || null;
            }
            
            // Vérifier si c'est la position d'origine
            var isOriginal = false;
            if (targetNextSibling === _td.el) {
                isOriginal = true;
            } else {
                var elNext = _td.el.nextElementSibling;
                while (elNext && elNext === _td.placeholder) elNext = elNext.nextElementSibling;
                if (targetNextSibling === elNext && hoveredContainer === _td.el.parentNode) {
                    isOriginal = true;
                }
            }
            
            var siblings = Array.from(hoveredContainer.children);
            var targetH = isOriginal ? 0 : _td.placeholderHeight;
            flipAnimate(siblings, _td.placeholder, _td.el, function() {
                if (targetNextSibling) {
                    hoveredContainer.insertBefore(_td.placeholder, targetNextSibling);
                } else {
                    hoveredContainer.appendChild(_td.placeholder);
                }
                _td.placeholder.style.height = targetH + 'px';
            });
        }
    }
    
    // Auto-scroll
    var sc = document.querySelector('.sidebar-left');
    if (sc) {
        var cr = sc.getBoundingClientRect();
        if (event.clientY < cr.top + 30) sc.scrollTop -= 6;
        if (event.clientY > cr.bottom - 30) sc.scrollTop += 6;
    }
}

function treeDragEnd(event) {
    document.removeEventListener('mousemove', treeDragMove);
    document.removeEventListener('mouseup', treeDragEnd);
    document.body.style.userSelect = '';
    document.body.style.webkitUserSelect = '';
    
    if (!_td.active) { _td.el = null; return; }
    
    var blocker = function(e) { e.stopPropagation(); e.preventDefault(); };
    document.addEventListener('click', blocker, true);
    setTimeout(function() { document.removeEventListener('click', blocker, true); }, 50);
    
    var placeholder = _td.placeholder;
    var parent = placeholder ? placeholder.parentNode : null;
    
    if (parent && _td.type === 'section') {
        var toIdx = 0;
        var child = parent.firstElementChild;
        while (child) {
            if (child === placeholder) break;
            if (child.classList.contains('tree-section') && child !== _td.el) toIdx++;
            child = child.nextElementSibling;
        }
        var fromIdx = courseData.sections.findIndex(function(s) { return s.id === _td.id; });
        if (fromIdx !== -1 && fromIdx !== toIdx) {
            var sec = courseData.sections.splice(fromIdx, 1)[0];
            courseData.sections.splice(toIdx, 0, sec);
            showToast('Section déplacée', 'success');
        }
    } else if (parent && _td.type === 'activity') {
        var targetSectionEl = placeholder.closest('.tree-section');
        var targetSectionId = targetSectionEl ? targetSectionEl.dataset.id : _td.sectionId;
        var toIdx = 0;
        var child = parent.firstElementChild;
        while (child) {
            if (child === placeholder) break;
            if (child.classList.contains('tree-activity') && !child.classList.contains('add-activity') && child !== _td.el) toIdx++;
            child = child.nextElementSibling;
        }
        var fromSection = courseData.sections.find(function(s) { return s.id === _td.sectionId; });
        var toSection = courseData.sections.find(function(s) { return s.id === targetSectionId; });
        if (fromSection && toSection) {
            var fromIdx = fromSection.activities.findIndex(function(a) { return a.id === _td.id; });
            if (fromIdx !== -1) {
                var act = fromSection.activities.splice(fromIdx, 1)[0];
                toSection.activities.splice(toIdx, 0, act);
                showToast('Parcours déplacé', 'success');
            }
        }
    }
    
    if (_td.ghost) _td.ghost.remove();
    if (_td.placeholder) _td.placeholder.remove();
    if (_td.el) { _td.el.style.opacity = ''; _td.el.style.transition = ''; }
    _td = { active: false, type: null, id: null, sectionId: null, el: null, ghost: null, placeholder: null, startY: 0 };
    
    renderTree();
    if (structureViewActive) renderStructureView();
    onCourseModified();
}

// Legacy — gardés pour ne pas casser d'éventuels appels restants
function handleDragStart() {}
function handleDragOver(e) { e && e.preventDefault && e.preventDefault(); }
function handleDrop(e) { e && e.preventDefault && e.preventDefault(); }
function handleDragEnd() {}

function getActivityIcon(type) {
    const icons = {
        'CoursePresentation': '🎬',
        'InteractiveVideo': '🎥',
        'QuestionSet': '📝',
        'DialogCards': '🃏',
        'MultiChoice': '☑️',
        'TrueFalse': '✅',
        'Blanks': '📝',
        'DragText': '🎯',
        'FindTheWords': '🔍',
        'mapmodules': '🗺️',
        'assign': '📤',   /* dépôt élève — distinct de l'évaluation (📋/📝) */
        'resource': '📎',
        'ThreeImage': '🌐',
        'MultiMediaChoice': '🖼️',
        'GameMap': '🧭',
        'ImageSequencing': '🔢',
        'MemoryGame': '🧠',
        'ImageMultipleHotspotQuestion': '🔎',
        'quiz': '📋',
        'ddimageortext': '🎯',
        'h5pactivity': '🎮'
    };
    return icons[type] || '📄';
}

// ==================== SECTIONS ====================
function addSection() {
    const section = {
        id: generateId(),
        name: 'Section ' + (courseData.sections.length + 1),
        summary: '',
        activities: []
    };
    courseData.sections.push(section);
    renderTree();
    selectSection(section.id);
    onCourseModified();
    showToast('Section ajoutée', 'success');
}

function selectSection(sectionId) {
    selectedSection = null;
    selectedActivity = null;
    structureViewActive = true;
    renderTree();
    renderStructureView();
    renderProperties();
}

function toggleSectionCollapse(sectionId) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;
    section.collapsed = !section.collapsed;

    // Basculer la classe sur l'élément existant plutôt que reconstruire l'arborescence :
    // un élément recréé apparaîtrait déjà pivoté, la transition CSS ne jouerait jamais.
    // Bonus : le volet ne clignote plus et garde sa position de défilement.
    const el = document.querySelector(`.tree-section[data-id="${sectionId}"]`);
    if (el) {
        el.classList.toggle('collapsed', !!section.collapsed);
    } else {
        renderTree();
    }
}

function toggleSectionVisibility(sectionId) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;
    section.visible = section.visible === false ? true : false;
    renderTree();
    if (structureViewActive) renderStructureView();
    onCourseModified();
}

function toggleActivityVisibility(sectionId, activityId) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;
    const activity = section.activities.find(a => a.id === activityId);
    if (!activity) return;
    activity.visible = activity.visible === false ? true : false;
    renderTree();
    if (structureViewActive) renderStructureView();
    onCourseModified();
}

function deleteSection(sectionId) {
    if (!confirm('Supprimer cette section et toutes ses activités ?')) return;
    
    // Collecter et supprimer les fichiers uploadés de toutes les activités de la section
    const section = courseData.sections.find(s => s.id === sectionId);
    if (section && section.activities) {
        const allFiles = [];
        section.activities.forEach(a => allFiles.push(...collectUploadedFiles(a)));
        if (allFiles.length > 0) {
            fetch('api/editor_api.php?action=delete_files', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filenames: allFiles })
            }).catch(() => {});
        }
    }
    
    courseData.sections = courseData.sections.filter(s => s.id !== sectionId);
    if (selectedSection === sectionId) {
        selectedSection = null;
        selectedActivity = null;
    }
    renderTree();
    
    // Réafficher la vue Structure si des sections restent
    if (courseData.sections.length > 0 && structureViewActive) {
        renderStructureView();
    } else if (courseData.sections.length > 0) {
        showStructureView();
    } else {
        showStructureView();
    }
    
    renderProperties();
    onCourseModified();
    showToast('Section supprimée', 'success');
}

// ==================== ACTIVITÉS ====================
function openAddActivityModal(sectionId) {
    pendingSectionId = sectionId;
    selectedActivityType = null;
    document.querySelectorAll('.activity-type-card').forEach(el => el.classList.remove('selected'));
    openModal('addActivityModal');
}

function selectActivityType(el) {
    document.querySelectorAll('.activity-type-card').forEach(item => item.classList.remove('selected'));
    el.classList.add('selected');
    selectedActivityType = el.dataset.type;
    
    // Valider directement sans attendre le clic sur "Ajouter"
    confirmAddActivity();
}

function confirmAddActivity() {
    if (!selectedActivityType || !pendingSectionId) {
        showToast('Sélectionnez un type d\'activité', 'error');
        return;
    }
    
    const section = courseData.sections.find(s => s.id === pendingSectionId);
    if (!section) return;
    
    // Mapper les types HTML vers les types H5P
    const typeMapping = {
        'coursepresentation': 'CoursePresentation',
        'interactivevideo': 'InteractiveVideo',
        'questionset': 'QuestionSet',
        'multichoice': 'MultiChoice',
        'truefalse': 'TrueFalse',
        'blanks': 'Blanks',
        'dialogcards': 'DialogCards',
        'dragtext': 'DragText',
        'findthewords': 'FindTheWords',
        'threeimage': 'ThreeImage',
        'multimediachoice': 'MultiMediaChoice',
        'gamemap': 'GameMap',
        'imagesequencing': 'ImageSequencing',
        'memorygame': 'MemoryGame',
        'multihotspot': 'ImageMultipleHotspotQuestion'
    };
    
    let activity;
    if (selectedActivityType === 'mapmodules') {
        activity = {
            id: generateId(),
            type: 'mapmodules',
            name: getActivityDefaultName('mapmodules'),
            mapPath: 'M 22 120 C 38 95 68 37 99 34 C 131 31 198 79 206 105 C 214 131 208 162 184 180 C 159 197 119 202 104 236 C 89 270 99 304 112 318 C 125 332 160 351 234 342 C 307 334 342 306 353 288 C 363 271 370 216 359 189 C 349 162 323 107 323 97 C 323 88 323 60 351 49 C 378 39 450 20 493 47 C 536 73 532 116 521 150 C 511 183 477 264 477 272 C 477 280 482 314 510 329 C 537 344 591 361 633 348 C 675 335 697 320 703 307 C 709 294 720 265 704 236 C 689 208 667 170 670 146 C 673 122 680 83 715 68 C 750 53 782 45 796 65 C 810 84 835 126 840 170 C 844 213 866 259 881 264 C 896 268 910 272 930 265 C 949 258 971 245 971 245',
            mapImage: null,
            descriptionHeader: '<h5>👇 Voici la carte de progression de l\u2019activité 👇</h5>',
            descriptionFooter: '<h4>Bonjour, faites les activités ci-dessous les unes après les autres<br>👇👇👇</h4><br><em>prenez le temps de bien comprendre les instructions, chaque question est notée</em>',
            iconset: 4,
            buttonWidth: 50
        };
    } else if (selectedActivityType === 'assign') {
        activity = {
            id: generateId(),
            type: 'assign',
            name: getActivityDefaultName('assign'),
            files: [],
            intro: ''
        };
    } else if (selectedActivityType === 'resource') {
        activity = {
            id: generateId(),
            type: 'resource',
            name: getActivityDefaultName('resource'),
            files: [],
            intro: ''
        };
    } else if (selectedActivityType === 'ddimageortext') {
        activity = {
            id: generateId(),
            type: 'quiz',
            quizType: 'ddimageortext',
            name: getActivityDefaultName('ddimageortext'),
            content: {
                questiontext: '<p>Compléter le schéma</p>',
                shuffleanswers: 1,
                attempts_number: 1,
                defaultmark: 1,
                backgroundUrl: null,
                bgImageName: null,
                canvasWidth: 800,
                canvasHeight: 600,
                drags: [],
                drops: []
            }
        };
    } else {
        const h5pType = typeMapping[selectedActivityType] || selectedActivityType;
        activity = {
            id: generateId(),
            type: 'h5pactivity',
            h5pType: h5pType,
            name: getActivityDefaultName(h5pType),
            content: getActivityDefaultContent(h5pType)
        };
    }
    
    section.activities = section.activities || [];
    section.activities.push(activity);
    
    closeModal('addActivityModal');
    renderTree();
    if (selectedActivityType === 'mapmodules') {
        // Rester dans la vue section, ne pas ouvrir l'éditeur
        showStructureView();
    } else {
        selectActivity(pendingSectionId, activity.id);
    }
    onCourseModified();
    showToast('Activité ajoutée', 'success');
}

function getActivityDefaultName(type) {
    const names = {
        'CoursePresentation': 'Nouvelle présentation',
        'InteractiveVideo': 'Nouvelle vidéo interactive',
        'QuestionSet': 'Nouvelle évaluation',
        'DialogCards': 'Nouvelles cartes',
        'MultiChoice': 'Nouveau QCM',
        'TrueFalse': 'Nouveau Vrai/Faux',
        'Blanks': 'Nouveau texte à trous',
        'DragText': 'Nouveau glisser-déposer',
        'FindTheWords': 'Nouveaux mots mêlés',
        'ThreeImage': 'Nouvelle visite 360',
        'MultiMediaChoice': 'Nouveau choix multimédia',
        'GameMap': 'Nouvelle carte à explorer',
        'ImageSequencing': 'Nouvelle remise en ordre',
        'MemoryGame': 'Nouveau memory',
        'ImageMultipleHotspotQuestion': 'Nouvelles zones à trouver',
        'mapmodules': 'Carte de progression',
        'assign': 'Nouveau travail à déposer',
        'resource': 'Nouveaux fichiers à distribuer',
        'ddimageortext': 'Nouveau glisser-déposer image'
    };
    return names[type] || 'Nouvelle activité';
}

function getActivityDefaultContent(type) {
    switch(type) {
        case 'CoursePresentation':
            return {
                presentation: {
                    slides: [{
                        elements: []
                    }]
                }
            };
        case 'InteractiveVideo':
            return {
                interactiveVideo: {
                    video: { files: [] },
                    assets: { interactions: [] }
                }
            };
        case 'QuestionSet':
            return {
                questions: [],
                settings: {
                    attempts_number: 1,
                    preferredbehaviour: 'deferredfeedback',
                    questionsperpage: 1,
                    shuffleanswers: 1,
                    grade: 10
                }
            };
        case 'DialogCards':
            return {
                dialogs: [{ text: '', answer: '', tips: {}, image: null }],
                behaviour: {
                    randomCards: false
                }
            };
        case 'MultiChoice':
            return {
                question: '',
                answers: [
                    { text: 'Bonne r\u00e9ponse', correct: true },
                    { text: 'Mauvaise r\u00e9ponse', correct: false }
                ],
                behaviour: {
                    enableRetry: true,
                    enableSolutionsButton: false
                }
            };
        case 'TrueFalse':
            return {
                question: '',
                correct: 'true',
                behaviour: {
                    enableRetry: true,
                    enableSolutionsButton: false
                }
            };
        case 'Blanks':
            return {
                text: '',
                questions: [],
                behaviour: {
                    enableRetry: true,
                    enableSolutionsButton: false,
                    caseSensitive: false,
                    showSolutionsRequiresInput: false
                }
            };
        case 'DragText':
            return {
                textField: '',
                distractors: ''
            };
        case 'FindTheWords':
            return {
                wordList: '',
                taskDescription: 'Retrouvez les mots dans la grille'
            };
        case 'ImageSequencing':
            return {
                taskDescription: '',
                sequenceImages: [],
                behaviour: { enableSolution: true, enableRetry: true, enableResume: true }
            };
        case 'ImageMultipleHotspotQuestion':
            // Zones à trouver : x/y/width/height en POURCENTAGES de l'image de fond,
            // x/y = coin haut-gauche (format relevé sur un export Éléa réel).
            return {
                imageMultipleHotspotQuestion: {
                    backgroundImageSettings: { questionTitle: 'Image hotspot question' },
                    hotspotSettings: { hotspot: [] }
                }
            };
        case 'MemoryGame':
            // Une entrée de `cards` = UNE paire. l10n en français, comme les cours Éléa de Max.
            return {
                cards: [],
                behaviour: { useGrid: true, allowRetry: true },
                lookNFeel: { themeColor: '#707070' },
                l10n: {
                    cardTurns: 'Cartes retournées :',
                    timeSpent: 'Temps écoulé :',
                    feedback: 'Bien joué !',
                    tryAgain: 'Réessayer',
                    closeLabel: 'Fermer',
                    label: 'Jeu de mémoire. Trouver les cartes qui se correspondent.',
                    done: 'Toutes les cartes ont été trouvées.',
                    cardPrefix: 'Carte %num sur %total:',
                    cardUnturned: 'Non retournées. Click to turn.',
                    cardTurned: 'Turned.',
                    cardMatched: 'Correspondance trouvée.',
                    cardMatchedA11y: 'Your cards match!',
                    cardNotMatchedA11y: 'Your chosen cards do not match. Turn other cards to try again.'
                }
            };
        case 'GameMap':
            return {
                gamemapSteps: {
                    backgroundImageSettings: {},
                    gamemap: { elements: [] }
                },
                behaviour: {
                    enableRetry: true,
                    enableSolutionsButton: true,
                    map: { showLabels: true, roaming: 'complete', fog: 'all' }
                }
            };
        case 'ThreeImage':
            return {
                threeImage: {
                    scenes: [{
                        sceneId: 0,
                        sceneType: '360',
                        showBackButton: true,
                        iconType: 'arrow',
                        scenesrc: null,
                        scenedescription: '',
                        scenename: 'Scène 1',
                        cameraStartPosition: '0,0',
                        interactions: []
                    }],
                    startSceneId: 0
                },
                behaviour: {
                    sceneRenderingQuality: 'high',
                    label: { labelPosition: 'right', showLabel: true }
                }
            };
        case 'MultiMediaChoice':
            return {
                question: '<p><strong>Sélectionnez les bonnes réponses</strong></p>',
                options: [
                    { media: { params: { file: null }, library: 'H5P.Image 1.1' }, correct: true },
                    { media: { params: { file: null }, library: 'H5P.Image 1.1' }, correct: false }
                ],
                behaviour: {
                    enableRetry: true,
                    enableSolutionsButton: false,
                    questionType: 'auto',
                    maxAlternativesPerRow: 4,
                    passPercentage: 100
                }
            };
        default:
            return {};
    }
}

function selectActivity(sectionId, activityId) {
    selectedSection = sectionId;
    selectedActivity = activityId;
    structureViewActive = false; // Désactiver la vue Structure
    renderTree();
    renderActivityEditor();
    renderProperties();
}

function deleteActivity(sectionId, activityId) {
    if (!confirm('Supprimer cette activité ?')) return;
    
    const section = courseData.sections.find(s => s.id === sectionId);
    if (section) {
        // Collecter les fichiers uploadés de cette activité avant suppression
        const activity = section.activities.find(a => a.id === activityId);
        if (activity) {
            const filenames = collectUploadedFiles(activity);
            if (filenames.length > 0) {
                fetch('api/editor_api.php?action=delete_files', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filenames })
                }).catch(() => {}); // fire & forget
            }
        }
        
        section.activities = section.activities.filter(a => a.id !== activityId);
        if (selectedActivity === activityId) {
            selectedActivity = null;
        }
        renderTree();
        
        // Réafficher la vue Structure si elle était active
        if (structureViewActive) {
            renderStructureView();
        } else {
            showStructureView();
        }
        
        onCourseModified();
        showToast('Activité supprimée', 'success');
    }
}

/**
 * Parcourt récursivement un objet pour extraire tous les noms de fichiers uploadés
 * (URLs contenant serve_upload&file=xxx)
 */
function collectUploadedFiles(obj) {
    const filenames = [];
    const seen = new Set();
    
    function walk(val) {
        if (!val) return;
        if (typeof val === 'string') {
            // Pattern: api/editor_api.php?action=serve_upload&file=FILENAME
            const m = val.match(/action=serve_upload&file=([^&"'\s]+)/);
            if (m) {
                const fn = decodeURIComponent(m[1]);
                if (!seen.has(fn)) { seen.add(fn); filenames.push(fn); }
            }
        } else if (Array.isArray(val)) {
            val.forEach(walk);
        } else if (typeof val === 'object') {
            Object.values(val).forEach(walk);
        }
    }
    
    walk(obj);
    return filenames;
}

function duplicateSection(sectionId) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;
    const idx = courseData.sections.indexOf(section);
    const clone = JSON.parse(JSON.stringify(section));
    clone.id = generateId();
    clone.name = section.name + ' (copie)';
    (clone.activities || []).forEach(a => { a.id = generateId(); });
    courseData.sections.splice(idx + 1, 0, clone);
    renderTree();
    if (structureViewActive) renderStructureView();
    onCourseModified();
    showToast('Section dupliqu\u00e9e', 'success');
}

function duplicateActivity(sectionId, activityId) {
    const section = courseData.sections.find(s => s.id === sectionId);
    if (!section) return;
    const activity = (section.activities || []).find(a => a.id === activityId);
    if (!activity) return;
    const idx = section.activities.indexOf(activity);
    const clone = JSON.parse(JSON.stringify(activity));
    clone.id = generateId();
    clone.name = activity.name + ' (copie)';
    section.activities.splice(idx + 1, 0, clone);
    renderTree();
    if (structureViewActive) renderStructureView();
    onCourseModified();
    showToast('Parcours dupliqu\u00e9', 'success');
}

function structureStartRename(span, type, id, sectionId) {
    const currentName = span.textContent;
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentName;
    input.className = 'structure-rename-input';
    input.style.cssText = 'font-size: inherit; font-weight: inherit; padding: 0.1rem 0.3rem; border: 1px solid var(--primary); border-radius: 3px; outline: none; width: 100%; min-width: 120px;';
    span.replaceWith(input);
    input.focus();
    input.select();
    // Empêcher le clic / mousedown sur le champ de remonter à la ligne parente
    // (qui ouvrirait le parcours via selectActivity, ou démarrerait un glisser).
    input.addEventListener('mousedown', e => e.stopPropagation());
    input.addEventListener('click', e => e.stopPropagation());

    const commit = () => {
        const val = input.value.trim();
        if (type === 'section') {
            const sec = courseData.sections.find(s => s.id === id);
            if (sec && val) { sec.name = val; }
        } else {
            const sec = courseData.sections.find(s => s.id === sectionId);
            if (sec) {
                const act = (sec.activities || []).find(a => a.id === id);
                if (act && val) { act.name = val; }
            }
        }
        renderTree();
        renderStructureView();
        onCourseModified();
    };
    input.addEventListener('blur', commit);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { input.value = currentName; input.blur(); }
    });
}

// ==================== RENDU ÉDITEUR ====================
function renderSectionEditor() {
    // Redirige vers la vue structure (la vue section n'existe plus)
    showStructureView();
}

function renderActivityEditor() {
    // Nettoyer les event listeners du bézier éditeur si actifs
    const oldSvg = document.getElementById('mapmodulesSvg');
    if (oldSvg && oldSvg._mapCleanup) oldSvg._mapCleanup();
    _mapDragging = null;
    
    const content = document.getElementById('editorContent');
    const empty = document.getElementById('emptyCanvas');
    
    if (!selectedActivity) {
        showStructureView();
        return;
    }
    
    const section = courseData.sections.find(s => s.id === selectedSection);
    if (!section) return;
    
    const activity = section.activities.find(a => a.id === selectedActivity);
    if (!activity) return;
    
    empty.style.display = 'none';
    content.style.display = 'flex';
    
    // Router vers l'éditeur approprié selon le type
    if (activity.type === 'mapmodules') {
        renderMapmodulesEditor(activity);
        return;
    }
    if (activity.type === 'assign') {
        renderAssignEditor(activity);
        return;
    }
    if (activity.type === 'resource') {
        renderResourceEditor(activity);
        return;
    }
    if (activity.type === 'quiz' && activity.quizType === 'ddimageortext') {
        renderDdimageortextEditor(activity);
        return;
    }
    switch (activity.h5pType) {
        case 'CoursePresentation':
            renderCoursePresentationEditor(activity);
            break;
        case 'QuestionSet':
            renderQuestionSetEditor(activity);
            break;
        case 'MultiChoice':
        case 'TrueFalse':
        case 'Blanks':
        case 'DragText':
        case 'FindTheWords':
        case 'MultiMediaChoice':
            renderSimpleQuizEditor(activity);
            break;
        case 'InteractiveVideo':
            renderInteractiveVideoEditor(activity);
            break;
        case 'DialogCards':
            renderDialogCardsEditor(activity);
            break;
        case 'ThreeImage':
            renderThreeImageEditor(activity);
            break;
        case 'GameMap':
            renderGameMapEditor(activity);
            break;
        case 'ImageSequencing':
            renderImageSequencingEditor(activity);
            break;
        case 'MemoryGame':
            renderMemoryGameEditor(activity);
            break;
        case 'ImageMultipleHotspotQuestion':
            renderMultiHotspotEditor(activity);
            break;
        default:
            renderGenericEditor(activity, section);
    }
}

// ==================== ÉDITEUR ASSIGN (TRAVAIL À DÉPOSER) ====================

function renderAssignEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';
    
    // Migration ancien format mono-fichier vers multi-fichier
    if ((activity.fileUrl || activity.fileName) && !activity.files) {
        activity.files = [];
        if (activity.fileUrl && activity.fileName) {
            activity.files.push({ fileUrl: activity.fileUrl, fileName: activity.fileName });
        }
        delete activity.fileUrl;
        delete activity.fileName;
    }
    if (!activity.files) activity.files = [];
    const intro = activity.intro || '';
    
    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml('📤', activity.name, sectionId)}
                <p class="section-preview-desc">Travail à déposer par les élèves</p>
            </div>
            <div style="padding: 1.5rem;">
                <div style="background: var(--gray-50); border-radius: 12px; padding: 1.5rem; border: 2px dashed var(--gray-300);">
                    <div id="assignFileList" style="margin-bottom: 1rem;"></div>
                    <div style="text-align: center;">
                        <label class="btn" style="cursor: pointer; background: var(--primary); color: white; padding: 0.5rem 1.25rem; border-radius: 8px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                            📎 Ajouter des fichiers
                            <input type="file" multiple style="display: none;" onchange="assignUploadFiles(this, '${activity.id}')">
                        </label>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <label class="cp-prop-label" style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.85rem; color: var(--gray-600);">Description (optionnelle)</label>
                    <div class="rich-text-toolbar" style="display: flex; gap: 0.25rem; margin-bottom: 0.25rem; flex-wrap: wrap;">
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('assignIntroEditor','bold')" title="Gras"><b>G</b></button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('assignIntroEditor','italic')" title="Italique"><i>I</i></button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('assignIntroEditor','underline')" title="Souligné"><u>S</u></button>
                        <span style="border-left: 1px solid var(--gray-300); margin: 0 0.25rem;"></span>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('assignIntroEditor','insertUnorderedList')" title="Liste à puces">☰</button>
                        <span style="border-left: 1px solid var(--gray-300); margin: 0 0.25rem;"></span>
                        <label class="rich-text-btn" style="cursor: pointer; margin: 0;" title="Insérer une image">
                            🖼️
                            <input type="file" accept="image/*" style="display: none;" onchange="assignInsertImage(this)">
                        </label>
                    </div>
                    <div id="assignIntroEditor" class="rich-text-editor" contenteditable="true"
                         style="min-height: 80px; font-size: 0.9rem; padding: 0.75rem; border: 1px solid var(--gray-300); border-radius: 8px; background: var(--bg-secondary,white); color: var(--text-primary,inherit); outline: none;"
                         oninput="assignUpdateIntro()">${intro}</div>
                </div>
                
                <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #fff3cd; border-radius: 8px; font-size: 0.8rem; color: #856404;">
                    ⚠️ Sur Éléa-Secours, les élèves pourront <strong>télécharger</strong> ces fichiers mais ne pourront pas déposer de fichier en retour (pas de remise de devoir). Cette fonctionnalité est disponible uniquement sur Éléa.
                </div>
            </div>
        </div>`;
    
    assignRenderFileList(activity);
    requestAnimationFrame(() => assignInitImgResize());
}

function assignUpdateIntro() {
    const editor = document.getElementById('assignIntroEditor');
    if (!editor) return;
    const activity = getSelectedActivity();
    if (activity) {
        // Nettoyer les wrappers de redimensionnement avant sauvegarde
        activity.intro = assignCleanIntroForSave(editor.innerHTML);
        onCourseModified();
    }
}

function assignInsertImage(input) {
    const file = input.files[0];
    if (!file) return;
    
    if (!file.type.startsWith('image/')) {
        showToast('Seules les images sont acceptées', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('session_id', getEditorSessionId());
    formData.append('file', file);
    
    showToast('Upload de l\'image...', 'info');
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.url) {
            if (data.filename && typeof EditorDriveSync !== 'undefined') EditorDriveSync.onFileUploaded(data.filename, data.url, data.type || '');
            const editor = document.getElementById('assignIntroEditor');
            if (editor) {
                editor.focus();
                const img = document.createElement('img');
                img.src = data.url;
                img.className = 'img-fluid';
                img.setAttribute('role', 'presentation');
                // Redimensionner à 500px max une fois chargée
                img.onload = function() {
                    const naturalW = this.naturalWidth;
                    const w = Math.min(naturalW, 500);
                    this.style.width = w + 'px';
                    this.style.maxWidth = '100%';
                    this.style.height = 'auto';
                    this.setAttribute('width', w);
                    assignUpdateIntro();
                    assignSelectImg(this);
                };
                // Style temporaire avant chargement
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                
                const sel = window.getSelection();
                if (sel.rangeCount > 0) {
                    const range = sel.getRangeAt(0);
                    range.deleteContents();
                    range.insertNode(img);
                    range.collapse(false);
                } else {
                    editor.appendChild(img);
                }
                
                assignUpdateIntro();
                showToast('Image insérée', 'success');
            }
        } else {
            showToast('Erreur: ' + (data.error || 'Upload échoué'), 'error');
        }
    })
    .catch(err => showToast('Erreur d\'upload', 'error'));
    
    input.value = '';
}

// ==================== POIGNÉE DE REDIMENSIONNEMENT D'IMAGE ====================
let _assignDragState = null;
let _assignSelectedImg = null;

function assignInitImgResize() {
    const editor = document.getElementById('assignIntroEditor');
    if (!editor) return;
    
    // Empêcher le menu contextuel natif sur les images
    editor.addEventListener('contextmenu', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
        }
    });
    
    // Clic sur une image : sélectionner et montrer la poignée
    editor.addEventListener('click', function(e) {
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            assignSelectImg(e.target);
        } else if (!e.target.closest('.assign-img-handle')) {
            assignDeselectImg();
        }
    });
    
    // Delete/Backspace sur image sélectionnée
    editor.addEventListener('keydown', function(e) {
        if (_assignSelectedImg && (e.key === 'Delete' || e.key === 'Backspace')) {
            // Vérifier que le curseur n'est pas dans du texte
            const sel = window.getSelection();
            const isTyping = sel && sel.rangeCount > 0 && sel.toString().length === 0 
                && sel.anchorNode && sel.anchorNode.nodeType === 3;
            if (!isTyping) {
                e.preventDefault();
                _assignSelectedImg.remove();
                assignDeselectImg();
                assignUpdateIntro();
            }
        }
    });
}

function assignSelectImg(img) {
    const editor = document.getElementById('assignIntroEditor');
    if (!editor) return;
    
    // Déselectionner l'ancien
    assignDeselectImg();
    
    _assignSelectedImg = img;
    img.style.outline = '2px solid var(--primary, #4f46e5)';
    img.style.outlineOffset = '2px';
    
    // Créer la poignée en overlay
    const handle = document.createElement('div');
    handle.className = 'assign-img-handle';
    handle.style.cssText = 'position:absolute;width:14px;height:14px;background:var(--primary, #4f46e5);border:2px solid white;border-radius:3px;cursor:nwse-resize;z-index:10;box-shadow:0 1px 4px rgba(0,0,0,0.3);';
    
    editor.style.position = 'relative';
    editor.appendChild(handle);
    
    // Positionner la poignée
    assignPositionHandle(img, handle);
    
    // Drag mouse
    handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        _assignDragState = {
            img: img,
            handle: handle,
            startX: e.clientX,
            startW: img.getBoundingClientRect().width
        };
        document.addEventListener('mousemove', assignDragMove);
        document.addEventListener('mouseup', assignDragEnd);
    });
    
    // Drag touch
    handle.addEventListener('touchstart', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const t = e.touches[0];
        _assignDragState = {
            img: img,
            handle: handle,
            startX: t.clientX,
            startW: img.getBoundingClientRect().width
        };
        document.addEventListener('touchmove', assignDragMoveTouch, { passive: false });
        document.addEventListener('touchend', assignDragEndTouch);
    });
}

function assignDeselectImg() {
    if (_assignSelectedImg) {
        _assignSelectedImg.style.outline = '';
        _assignSelectedImg.style.outlineOffset = '';
        _assignSelectedImg = null;
    }
    document.querySelectorAll('.assign-img-handle').forEach(h => h.remove());
}

function assignPositionHandle(img, handle) {
    const editor = document.getElementById('assignIntroEditor');
    if (!editor || !img || !handle) return;
    const editorRect = editor.getBoundingClientRect();
    const imgRect = img.getBoundingClientRect();
    handle.style.left = (imgRect.right - editorRect.left - 7 + editor.scrollLeft) + 'px';
    handle.style.top = (imgRect.bottom - editorRect.top - 7 + editor.scrollTop) + 'px';
}

function assignDragMove(e) {
    if (!_assignDragState) return;
    const dx = e.clientX - _assignDragState.startX;
    const newW = Math.max(30, Math.round(_assignDragState.startW + dx));
    const img = _assignDragState.img;
    img.style.width = newW + 'px';
    img.style.maxWidth = '100%';
    img.style.height = 'auto';
    img.setAttribute('width', newW);
    img.removeAttribute('height');
    assignPositionHandle(img, _assignDragState.handle);
}

function assignDragEnd() {
    document.removeEventListener('mousemove', assignDragMove);
    document.removeEventListener('mouseup', assignDragEnd);
    _assignDragState = null;
    assignUpdateIntro();
}

function assignDragMoveTouch(e) {
    if (!_assignDragState) return;
    e.preventDefault();
    const t = e.touches[0];
    const dx = t.clientX - _assignDragState.startX;
    const newW = Math.max(30, Math.round(_assignDragState.startW + dx));
    const img = _assignDragState.img;
    img.style.width = newW + 'px';
    img.style.maxWidth = '100%';
    img.style.height = 'auto';
    img.setAttribute('width', newW);
    img.removeAttribute('height');
    assignPositionHandle(img, _assignDragState.handle);
}

function assignDragEndTouch() {
    document.removeEventListener('touchmove', assignDragMoveTouch);
    document.removeEventListener('touchend', assignDragEndTouch);
    _assignDragState = null;
    assignUpdateIntro();
}

// Nettoyer le HTML avant sauvegarde (retirer les poignées orphelines)
function assignCleanIntroForSave(html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    div.querySelectorAll('.assign-img-handle').forEach(h => h.remove());
    return div.innerHTML;
}

function assignRenderFileList(activity) {
    const container = document.getElementById('assignFileList');
    if (!container) return;
    
    if (!activity.files || activity.files.length === 0) {
        container.innerHTML = '<div style="text-align: center;"><p style="font-size: 2rem; margin-bottom: 0.5rem;">📁</p><p style="color: var(--gray-600); font-size: 0.9rem;">Aucun fichier sélectionné</p></div>';
        return;
    }
    
    let html = '';
    if (activity.files.length > 1) {
        html += `<div style="display:flex; justify-content:flex-end; margin-bottom:0.5rem;">
            <button onclick="assignDownloadAll('${activity.id}')" style="background: var(--info, #0d6efd); color: white; border: none; padding: 0.35rem 0.9rem; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight:500;">⬇️ Tout télécharger (${activity.files.length})</button>
        </div>`;
    }
    activity.files.forEach((f, idx) => {
        html += `<div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.75rem; background: var(--bg-secondary,white); color: var(--text-primary,inherit); border-radius: 8px; margin-bottom: 0.5rem; border: 1px solid var(--gray-200);">
            <span style="font-size: 1.2rem;">📄</span>
            <span style="flex: 1; font-size: 0.85rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(f.fileName)}</span>
            <button onclick="assignDownloadFile('${activity.id}', ${idx})" title="Télécharger" style="background: var(--info, #0d6efd); color: white; border: none; padding: 0.3rem 0.7rem; border-radius: 6px; cursor: pointer; font-size: 0.8rem;">⬇️</button>
            <button onclick="assignRemoveFile('${activity.id}', ${idx})" title="Supprimer" style="background: var(--danger, #dc3545); color: white; border: none; padding: 0.3rem 0.7rem; border-radius: 6px; cursor: pointer; font-size: 0.8rem;">🗑️</button>
        </div>`;
    });
    container.innerHTML = html;
}

function assignUploadFiles(input, activityId) {
    if (!input.files || input.files.length === 0) return;
    const activity = findActivityById(activityId);
    if (!activity) return;
    if (!activity.files) activity.files = [];
    
    let pending = input.files.length;
    
    Array.from(input.files).forEach(file => {
        if (file.size > 50 * 1024 * 1024) {
            showToast('Fichier trop volumineux (max 50 Mo) : ' + file.name, 'error');
            pending--;
            if (pending <= 0) assignRenderFileList(activity);
            return;
        }
        
        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload_assign_file');
        formData.append('session_id', getEditorSessionId());
        formData.append('activity_id', activityId);
        
        fetch('api/editor_api.php?action=upload_assign_file', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                showToast('Erreur: ' + data.error, 'error');
            } else {
                activity.files.push({
                    fileUrl: data.url,
                    fileName: data.originalName || data.fileName || file.name
                });
                if (data.filename && typeof EditorDriveSync !== 'undefined') EditorDriveSync.onFileUploaded(data.filename, data.url, '');
                onCourseModified();
            }
            pending--;
            if (pending <= 0) {
                assignRenderFileList(activity);
                if (activity.files.length > 0) showToast(activity.files.length + ' fichier(s) joint(s)', 'success');
            }
        })
        .catch(err => {
            showToast('Erreur d\'upload', 'error');
            console.error(err);
            pending--;
            if (pending <= 0) assignRenderFileList(activity);
        });
    });
    
    input.value = '';
}

function assignRemoveFile(activityId, fileIndex) {
    const activity = findActivityById(activityId);
    if (!activity || !activity.files) return;
    activity.files.splice(fileIndex, 1);
    assignRenderFileList(activity);
    onCourseModified();
    showToast('Fichier retiré', 'info');
}

async function assignDownloadFile(activityId, fileIndex) {
    const activity = findActivityById(activityId);
    if (!activity || !activity.files || !activity.files[fileIndex]) return;
    const f = activity.files[fileIndex];
    await assignFetchAndSave(f.fileUrl, f.fileName);
}

// Télécharge en FORÇANT le nom d'origine côté client (blob). Immunisé contre le renommage
// que provoquaient les redirections Drive (a.download est ignoré sur une redirection
// cross-origin → le fichier prenait son nom interne import_XXXX). Repli : lien direct.
async function assignFetchAndSave(fileUrl, fileName) {
    const sep = fileUrl.includes('?') ? '&' : '?';
    const url = fileUrl + sep + 'download=1&download_name=' + encodeURIComponent(fileName);
    try {
        const resp = await fetch(url, { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const blob = await resp.blob();
        const obj = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = obj;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(obj), 5000);
    } catch (e) {
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
}

async function assignDownloadAll(activityId) {
    const activity = findActivityById(activityId);
    if (!activity || !activity.files || !activity.files.length) return;
    for (const f of activity.files) {
        await assignFetchAndSave(f.fileUrl, f.fileName);
        await new Promise(r => setTimeout(r, 400)); // évite que le navigateur bloque le lot
    }
}

// ==================== ÉDITEUR RESOURCE (FICHIERS À DISTRIBUER) ====================

function renderResourceEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';
    
    if (!activity.files) activity.files = [];
    const intro = activity.intro || '';
    
    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml('📎', activity.name, sectionId)}
                <p class="section-preview-desc">Fichiers téléchargeables par les élèves</p>
            </div>
            <div style="padding: 1.5rem;">
                <div style="background: var(--gray-50); border-radius: 12px; padding: 1.5rem; border: 2px dashed var(--gray-300);">
                    <div id="resourceFileList" style="margin-bottom: 1rem;"></div>
                    <div style="text-align: center;">
                        <label class="btn" style="cursor: pointer; background: var(--primary); color: white; padding: 0.5rem 1.25rem; border-radius: 8px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                            📎 Ajouter des fichiers
                            <input type="file" multiple style="display: none;" onchange="resourceUploadFiles(this, '${activity.id}')">
                        </label>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <label class="cp-prop-label" style="display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.85rem; color: var(--gray-600);">Description (optionnelle)</label>
                    <div class="rich-text-toolbar" style="display: flex; gap: 0.25rem; margin-bottom: 0.25rem; flex-wrap: wrap;">
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('resourceIntroEditor','bold')" title="Gras"><b>G</b></button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('resourceIntroEditor','italic')" title="Italique"><i>I</i></button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('resourceIntroEditor','underline')" title="Souligné"><u>S</u></button>
                        <span style="border-left: 1px solid var(--gray-300); margin: 0 0.25rem;"></span>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('resourceIntroEditor','insertUnorderedList')" title="Liste à puces">☰</button>
                        <span style="border-left: 1px solid var(--gray-300); margin: 0 0.25rem;"></span>
                        <label class="rich-text-btn" style="cursor: pointer; margin: 0;" title="Insérer une image">
                            🖼️
                            <input type="file" accept="image/*" style="display: none;" onchange="resourceInsertImage(this)">
                        </label>
                    </div>
                    <div id="resourceIntroEditor" class="rich-text-editor" contenteditable="true"
                         style="min-height: 80px; font-size: 0.9rem; padding: 0.75rem; border: 1px solid var(--gray-300); border-radius: 8px; background: var(--bg-secondary,white); color: var(--text-primary,inherit); outline: none;"
                         oninput="resourceUpdateIntro()">${intro}</div>
                </div>
            </div>
        </div>`;
    
    resourceRenderFileList(activity);
    requestAnimationFrame(() => resourceInitImgResize());
}

function resourceRenderFileList(activity) {
    const container = document.getElementById('resourceFileList');
    if (!container) return;
    
    if (!activity.files || activity.files.length === 0) {
        container.innerHTML = '<div style="text-align: center;"><p style="font-size: 2rem; margin-bottom: 0.5rem;">📁</p><p style="color: var(--gray-600); font-size: 0.9rem;">Aucun fichier sélectionné</p></div>';
        return;
    }
    
    let html = '';
    activity.files.forEach((f, idx) => {
        html += `<div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.75rem; background: var(--bg-secondary,white); color: var(--text-primary,inherit); border-radius: 8px; margin-bottom: 0.5rem; border: 1px solid var(--gray-200);">
            <span style="font-size: 1.2rem;">📄</span>
            <span style="flex: 1; font-size: 0.85rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(f.fileName)}</span>
            <button onclick="resourceDownloadFile('${activity.id}', ${idx})" style="background: var(--info, #0d6efd); color: white; border: none; padding: 0.3rem 0.7rem; border-radius: 6px; cursor: pointer; font-size: 0.8rem;">⬇️</button>
            <button onclick="resourceRemoveFile('${activity.id}', ${idx})" style="background: var(--danger, #dc3545); color: white; border: none; padding: 0.3rem 0.7rem; border-radius: 6px; cursor: pointer; font-size: 0.8rem;">🗑️</button>
        </div>`;
    });
    container.innerHTML = html;
}

function resourceUploadFiles(input, activityId) {
    if (!input.files || input.files.length === 0) return;
    const activity = findActivityById(activityId);
    if (!activity) return;
    if (!activity.files) activity.files = [];
    
    let pending = input.files.length;
    
    Array.from(input.files).forEach(file => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload_assign_file');
        formData.append('session_id', getEditorSessionId());
        formData.append('activity_id', activityId);
        
        fetch('api/editor_api.php?action=upload_assign_file', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                activity.files.push({
                    fileUrl: data.url,
                    fileName: data.originalName || data.fileName || file.name
                });
                if (data.filename && typeof EditorDriveSync !== 'undefined') EditorDriveSync.onFileUploaded(data.filename, data.url, '');
            } else {
                alert('Erreur upload: ' + (data.error || 'inconnue'));
            }
            pending--;
            if (pending <= 0) {
                resourceRenderFileList(activity);
                onCourseModified();
            }
        })
        .catch(() => {
            pending--;
            if (pending <= 0) resourceRenderFileList(activity);
        });
    });
    
    input.value = '';
}

function resourceRemoveFile(activityId, fileIndex) {
    const activity = findActivityById(activityId);
    if (!activity || !activity.files) return;
    activity.files.splice(fileIndex, 1);
    resourceRenderFileList(activity);
    onCourseModified();
}

function resourceDownloadFile(activityId, fileIndex) {
    const activity = findActivityById(activityId);
    if (!activity || !activity.files || !activity.files[fileIndex]) return;
    const f = activity.files[fileIndex];
    const sep = f.fileUrl.includes('?') ? '&' : '?';
    const a = document.createElement('a');
    a.href = f.fileUrl + sep + 'download=1&download_name=' + encodeURIComponent(f.fileName);
    a.download = f.fileName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function resourceUpdateIntro() {
    const editor = document.getElementById('resourceIntroEditor');
    if (!editor) return;
    const activity = selectedActivity;
    if (activity) {
        activity.intro = resourceCleanIntroForSave(editor.innerHTML);
        onCourseModified();
    }
}

function resourceInsertImage(input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    const activityId = selectedActivity ? selectedActivity.id : '';
    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'upload_assign_file');
        formData.append('session_id', getEditorSessionId());
    formData.append('activity_id', activityId);
    fetch('api/editor_api.php?action=upload_assign_file', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (data.filename && typeof EditorDriveSync !== 'undefined') EditorDriveSync.onFileUploaded(data.filename, data.url, '');
            const editor = document.getElementById('resourceIntroEditor');
            if (editor) {
                const img = document.createElement('img');
                img.src = data.url;
                img.style.maxWidth = '100%';
                img.className = 'img-fluid';
                img.setAttribute('role', 'presentation');
                editor.focus();
                const sel = window.getSelection();
                if (sel.rangeCount > 0) {
                    sel.getRangeAt(0).insertNode(img);
                    sel.collapseToEnd();
                } else {
                    editor.appendChild(img);
                }
                resourceUpdateIntro();
                resourceSelectImg(img);
            }
        }
    });
    input.value = '';
}

let _resourceSelectedImg = null;
let _resourceDragState = null;

function resourceInitImgResize() {
    const editor = document.getElementById('resourceIntroEditor');
    if (!editor) return;
    editor.querySelectorAll('img').forEach(img => {
        img.addEventListener('click', function(e) { e.stopPropagation(); resourceSelectImg(this); });
    });
    editor.addEventListener('click', function(e) {
        if (e.target.tagName !== 'IMG') resourceDeselectImg();
    });
}

function resourceSelectImg(img) {
    resourceDeselectImg();
    _resourceSelectedImg = img;
    img.style.outline = '2px solid var(--primary)';
    img.style.outlineOffset = '2px';
    
    const handle = document.createElement('div');
    handle.className = 'assign-img-handle';
    handle.style.cssText = 'position:absolute;width:12px;height:12px;background:var(--primary);border-radius:50%;cursor:ew-resize;z-index:10;';
    const editor = document.getElementById('resourceIntroEditor');
    editor.style.position = 'relative';
    editor.appendChild(handle);
    resourcePositionHandle(img, handle);
    
    handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        _resourceDragState = { img, handle, startX: e.clientX, startW: img.offsetWidth };
        document.addEventListener('mousemove', resourceDragMove);
        document.addEventListener('mouseup', resourceDragEnd);
    });
    handle.addEventListener('touchstart', function(e) {
        const t = e.touches[0];
        _resourceDragState = { img, handle, startX: t.clientX, startW: img.offsetWidth };
        document.addEventListener('touchmove', resourceDragMoveTouch, { passive: false });
        document.addEventListener('touchend', resourceDragEndTouch);
    });
}

function resourceDeselectImg() {
    if (_resourceSelectedImg) {
        _resourceSelectedImg.style.outline = '';
        _resourceSelectedImg.style.outlineOffset = '';
        _resourceSelectedImg = null;
    }
    document.querySelectorAll('.assign-img-handle').forEach(h => h.remove());
}

function resourcePositionHandle(img, handle) {
    const editor = document.getElementById('resourceIntroEditor');
    if (!editor) return;
    const er = editor.getBoundingClientRect();
    const ir = img.getBoundingClientRect();
    handle.style.left = (ir.right - er.left - 6) + 'px';
    handle.style.top = (ir.bottom - er.top - 6) + 'px';
}

function resourceDragMove(e) {
    if (!_resourceDragState) return;
    const dx = e.clientX - _resourceDragState.startX;
    const newW = Math.max(30, Math.round(_resourceDragState.startW + dx));
    _resourceDragState.img.style.width = newW + 'px';
    _resourceDragState.img.style.height = 'auto';
    resourcePositionHandle(_resourceDragState.img, _resourceDragState.handle);
}

function resourceDragEnd() {
    document.removeEventListener('mousemove', resourceDragMove);
    document.removeEventListener('mouseup', resourceDragEnd);
    _resourceDragState = null;
    resourceUpdateIntro();
}

function resourceDragMoveTouch(e) {
    if (!_resourceDragState) return;
    e.preventDefault();
    const t = e.touches[0];
    const dx = t.clientX - _resourceDragState.startX;
    const newW = Math.max(30, Math.round(_resourceDragState.startW + dx));
    _resourceDragState.img.style.width = newW + 'px';
    _resourceDragState.img.style.height = 'auto';
    resourcePositionHandle(_resourceDragState.img, _resourceDragState.handle);
}

function resourceDragEndTouch() {
    document.removeEventListener('touchmove', resourceDragMoveTouch);
    document.removeEventListener('touchend', resourceDragEndTouch);
    _resourceDragState = null;
    resourceUpdateIntro();
}

function resourceCleanIntroForSave(html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    div.querySelectorAll('.assign-img-handle').forEach(h => h.remove());
    return div.innerHTML;
}

function findActivityById(activityId) {
    for (const section of courseData.sections) {
        for (const activity of (section.activities || [])) {
            if (activity.id === activityId) return activity;
        }
    }
    return null;
}

// ==================== VUE STRUCTURE DU COURS ====================
let structureViewActive = false;

function showStructureView() {
    // Désélectionner tout
    selectedSection = null;
    selectedActivity = null;
    structureViewActive = true;
    
    // Mettre à jour l'arborescence (désélection visuelle)
    renderTree();
    
    // Afficher la vue Structure dans le panneau principal
    renderStructureView();
}

function renderStructureView() {
    const content = document.getElementById('editorContent');
    const empty = document.getElementById('emptyCanvas');
    const canvasWrapper = document.getElementById('canvasWrapper');
    
    // Retirer la classe cp-mode si présente
    if (canvasWrapper) {
        canvasWrapper.classList.remove('cp-mode');
    }
    
    // Si aucune section, afficher la page d'accueil
    if (courseData.sections.length === 0) {
        empty.style.display = 'flex';
        content.style.display = 'none';
        structureViewActive = false;
        return;
    }
    
    empty.style.display = 'none';
    content.style.display = 'flex';
    
    let html = `
        <div class="structure-view">
            <div class="structure-header">
                <h2 class="structure-title">📚 Structure du cours</h2>
                <p class="structure-subtitle">${courseData.sections.length} section(s) • Glissez-déposez pour réorganiser</p>
            </div>
            <div class="structure-sections" id="structureSections">`;
    
    courseData.sections.forEach((section, sIdx) => {
        const activities = section.activities || [];
        const secHidden = section.visible === false;
        const secDimStyle = secHidden ? ' style="opacity: 0.45;"' : '';
        const secEyeIcon = secHidden ? '🙈' : '👁️';
        const secEyeTitle = secHidden ? 'Afficher la section' : 'Masquer la section';
        
        html += `
            <div class="structure-section" data-section-id="${section.id}" data-section-idx="${sIdx}">
                <div class="structure-section-header" onmousedown="structDragStart(event, 'section', '${section.id}')" oncontextmenu="showContextMenu(event, 'section', '${section.id}')">
                    <div class="structure-section-drag-handle" title="Glisser pour réorganiser">⠿</div>
                    <span class="structure-section-icon"${secDimStyle}>📁</span>
                    <span class="structure-section-name"${secDimStyle} onclick="event.stopPropagation(); structureStartRename(this, 'section', '${section.id}')">${escapeHtml(section.name)}</span>
                    <span class="structure-section-count">${activities.length} parcours</span>
                    <div class="structure-section-actions">
                        <button class="structure-btn" onclick="event.stopPropagation(); toggleSectionVisibility('${section.id}')" title="${secEyeTitle}">${secEyeIcon}</button>
                        <button class="structure-btn" onclick="event.stopPropagation(); duplicateSection('${section.id}')" title="Dupliquer">📋</button>
                        <button class="structure-btn danger" onclick="event.stopPropagation(); deleteSection('${section.id}')" title="Supprimer">🗑️</button>
                    </div>
                </div>
                <div class="structure-activities" id="structureActivities-${section.id}">`;
        
        activities.forEach((activity, aIdx) => {
            const icon = getActivityIcon(['assign','resource','mapmodules'].includes(activity.type) ? activity.type : (activity.quizType || activity.h5pType || activity.type));
            const typeLabel = activity.type === 'mapmodules' ? 'Carte de Progression' : (activity.type === 'assign' ? 'Travail à déposer' : (activity.type === 'resource' ? 'Fichiers à distribuer' : (activity.quizType === 'ddimageortext' ? 'Glisser Image' : (activity.h5pType === 'CoursePresentation' ? 'Parcours' : (activity.h5pType === 'ThreeImage' ? 'Visite 360' : (activity.h5pType === 'GameMap' ? 'Carte à explorer' : (activity.h5pType === 'ImageSequencing' ? 'Remettre dans l\'ordre' : (activity.h5pType === 'MemoryGame' ? 'Memory' : (activity.h5pType === 'ImageMultipleHotspotQuestion' ? 'Trouver les zones' : (activity.h5pType || activity.type))))))))));
            const actHidden = activity.visible === false || secHidden;
            const actOwnHidden = activity.visible === false;
            const actDimStyle = actHidden ? ' style="opacity: 0.45;"' : '';
            const actEyeIcon = actOwnHidden ? '🙈' : '👁️';
            const actEyeTitle = actOwnHidden ? 'Afficher le parcours' : 'Masquer le parcours';
            const actEyeDisabled = secHidden ? ' style="opacity:0.3; pointer-events:none;"' : '';
            
            // Carte de progression : affichage inline dans la vue structure
            if (activity.type === 'mapmodules') {
                const mapPath = activity.mapPath || MAPMODULES_DEFAULT_PATH;
                const header = activity.descriptionHeader || '';
                const footer = activity.descriptionFooter || '';
                const customImg = activity.mapImage || '';
                
                html += `
                    <div class="mapmodules-inline-preview" data-activity-id="${activity.id}" data-section-id="${section.id}" data-activity-idx="${aIdx}"
                         style="border: 1px solid var(--gray-200); border-radius: 12px; overflow: hidden; margin: 0.25rem 0.5rem 0.5rem; background: white;${actHidden ? ' opacity: 0.45;' : ''}">
                        ${header ? '<div style="padding: 0.5rem 0.75rem 0.15rem; font-size: 0.85rem;">' + header + '</div>' : ''}
                        <div style="position: relative; cursor: pointer;" onclick="selectActivity('${section.id}', '${activity.id}')">
                            ${customImg 
                                ? '<img src="' + escapeHtml(customImg) + '" style="width: 100%; display: block;" alt="carte">'
                                : ''}
                            <svg id="mapStruct_${activity.id}" viewBox="0 0 1000 400" style="${customImg ? 'position: absolute; top: 0; left: 0;' : ''} width: 100%; height: 100%; display: block;">
                                ${customImg ? '' : '<rect width="1000" height="400" fill="#FF9800" rx="0"/>'}
                                ${customImg ? '' : '<path d="' + escapeHtml(MAPMODULES_DEFAULT_PATH) + '" fill="none" stroke="white" stroke-width="4" stroke-dasharray="8 12" stroke-linecap="round"/>'}
                                <path class="mapStructPath" d="${escapeHtml(mapPath)}" fill="none" stroke="none"/>
                            </svg>
                            <div style="position: absolute; top: 6px; right: 6px; display: flex; gap: 4px;">
                                <button class="btn btn-secondary" onclick="event.stopPropagation(); toggleActivityVisibility('${section.id}', '${activity.id}')" style="padding: 0.2rem 0.4rem; font-size: 0.65rem; background: rgba(255,255,255,0.9);"${actEyeDisabled} title="${actEyeTitle}">${actEyeIcon}</button>
                                <button class="btn btn-secondary" onclick="event.stopPropagation(); selectActivity('${section.id}', '${activity.id}')" style="padding: 0.2rem 0.4rem; font-size: 0.65rem; background: rgba(255,255,255,0.9);">✏️ Éditer</button>
                                <button class="btn btn-secondary" onclick="event.stopPropagation(); deleteActivity('${section.id}', '${activity.id}')" style="padding: 0.2rem 0.4rem; font-size: 0.65rem; background: rgba(255,255,255,0.9);">🗑️</button>
                            </div>
                        </div>
                        ${footer ? '<div style="padding: 0.15rem 0.75rem 0.5rem; font-size: 0.85rem;">' + footer + '</div>' : ''}
                    </div>`;
                return; // skip normal card rendering
            }
            
            html += `
                    <div class="structure-activity" data-activity-id="${activity.id}" data-section-id="${section.id}" data-activity-idx="${aIdx}"
                         onmousedown="structDragStart(event, 'activity', '${activity.id}', '${section.id}')"
                         onclick="selectActivity('${section.id}', '${activity.id}')"
                         oncontextmenu="showContextMenu(event, 'activity', '${activity.id}', '${section.id}')"${actDimStyle}>
                        <div class="structure-activity-drag-handle" title="Glisser pour réorganiser">⠿</div>
                        <span class="structure-activity-icon">${icon}</span>
                        <div class="structure-activity-info">
                            <span class="structure-activity-name" onclick="event.stopPropagation(); structureStartRename(this, 'activity', '${activity.id}', '${section.id}')">${escapeHtml(activity.name)}</span>
                            <span class="structure-activity-type">${typeLabel}</span>
                        </div>
                        <div class="structure-activity-actions">
                            <button class="structure-btn" onclick="event.stopPropagation(); toggleActivityVisibility('${section.id}', '${activity.id}')" title="${actEyeTitle}"${actEyeDisabled}>${actEyeIcon}</button>
                            <button class="structure-btn" onclick="event.stopPropagation(); duplicateActivity('${section.id}', '${activity.id}')" title="Dupliquer">📋</button>
                            <button class="structure-btn" onclick="event.stopPropagation(); selectActivity('${section.id}', '${activity.id}')" title="Éditer">✏️</button>
                            <button class="structure-btn danger" onclick="event.stopPropagation(); deleteActivity('${section.id}', '${activity.id}')" title="Supprimer">🗑️</button>
                        </div>
                    </div>`;
        });
        
        html += `
                    <div class="structure-add-activity" onclick="openAddActivityModal('${section.id}')">
                        <span>➕</span>
                        <span>Ajouter un parcours</span>
                    </div>
                </div>
            </div>`;
    });
    
    html += `
            </div>
            <div class="structure-add-section">
                <button class="btn btn-primary btn-lg" onclick="addSection()">
                    ➕ Ajouter une section
                </button>
            </div>
        </div>`;
    
    content.innerHTML = html;
    
    // Placer les boutons "?" sur les cartes de progression dans la vue structure
    requestAnimationFrame(() => {
        document.querySelectorAll('svg[id^="mapStruct_"]').forEach(svgEl => {
            const pathEl = svgEl.querySelector('.mapStructPath');
            if (!pathEl) return;
            const actList = mapmodulesGetActivityList();
            if (actList.length <= 0) return;
            const totalLen = pathEl.getTotalLength();
            for (let i = 0; i < actList.length; i++) {
                const t = mapmodulesCalcT(i, actList.length);
                const pt = pathEl.getPointAtLength(t * totalLen);
                const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                g.style.cursor = 'pointer';
                g.innerHTML = '<circle cx="' + pt.x + '" cy="' + pt.y + '" r="18" fill="#2E7D32" stroke="white" stroke-width="2"/>' +
                    '<text x="' + pt.x + '" y="' + (pt.y + 1) + '" text-anchor="middle" dominant-baseline="central" fill="white" font-size="18" font-weight="bold">?</text>';
                const act = actList[i];
                g.onclick = (e) => { e.stopPropagation(); selectActivity(act.sectionId, act.activityId); };
                const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                title.textContent = act.name;
                g.appendChild(title);
                svgEl.appendChild(g);
            }
        });
    });
}

// ==================== FLIP ANIMATION HELPER ====================
// Technique FLIP : First (snapshot), Last (après DOM), Invert (transform), Play (animer)
function flipAnimate(items, placeholder, draggedEl, domChangeFn) {
    // 1. FIRST — enregistrer les positions actuelles
    var firstRects = new Map();
    for (var i = 0; i < items.length; i++) {
        var el = items[i];
        if (el === draggedEl || el === placeholder) continue;
        firstRects.set(el, el.getBoundingClientRect().top);
    }
    
    // 2. Exécuter le changement DOM
    domChangeFn();
    
    // 3. LAST + INVERT — calculer les deltas et appliquer les transforms inverses
    firstRects.forEach(function(oldTop, el) {
        var newTop = el.getBoundingClientRect().top;
        var delta = oldTop - newTop;
        if (Math.abs(delta) < 1) return; // pas bougé
        el.style.transition = 'none';
        el.style.transform = 'translateY(' + delta + 'px)';
    });
    
    // 4. PLAY — forcer reflow puis animer vers position finale
    // Le void force le navigateur à recalculer les styles avant de changer
    document.body.offsetHeight; // force reflow
    
    firstRects.forEach(function(oldTop, el) {
        el.style.transition = 'transform 0.15s ease';
        el.style.transform = '';
    });
    
    // Nettoyage après la transition
    setTimeout(function() {
        firstRects.forEach(function(oldTop, el) {
            el.style.transition = '';
            el.style.transform = '';
        });
    }, 170);
}

// ==================== DRAG & DROP CUSTOM (vue Structure) ====================
var _sd = { active: false, type: null, id: null, sectionId: null, el: null, ghost: null, placeholder: null, startY: 0 };

function structDragStart(event, type, id, sectionId) {
    // Ne pas drag si clic sur texte éditable, bouton, input
    var tag = event.target.tagName;
    if (tag === 'BUTTON' || tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (event.target.closest('.structure-activity-name, .structure-section-name, .structure-activity-actions, .structure-section-actions, .structure-btn')) return;
    // Seulement bouton gauche
    if (event.button !== 0) return;
    
    // Trouver l'élément draggable
    var el;
    if (type === 'section') {
        el = event.target.closest('.structure-section');
    } else {
        el = event.target.closest('.structure-activity, .mapmodules-inline-preview');
    }
    if (!el) return;
    
    // Stocker mais ne pas encore bloquer — le clic pourra passer si pas de drag
    _sd.type = type;
    _sd.id = id;
    _sd.sectionId = sectionId || null;
    _sd.el = el;
    _sd.startY = event.clientY;
    _sd.startX = event.clientX;
    _sd.active = false;
    _sd.didPrevent = false;
    
    document.addEventListener('mousemove', structDragMove);
    document.addEventListener('mouseup', structDragEnd);
}

function structDragMove(event) {
    event.preventDefault(); // Empêcher la sélection de texte
    
    // Seuil de 5px avant d'activer le drag
    if (!_sd.active && Math.abs(event.clientY - _sd.startY) < 5) return;
    
    if (!_sd.active) {
        _sd.active = true;
        // Bloquer la sélection de texte sur toute la page
        document.body.style.userSelect = 'none';
        document.body.style.webkitUserSelect = 'none';
        window.getSelection && window.getSelection().removeAllRanges();
        
        // Créer le ghost — taille exacte de l'original
        var rect = _sd.el.getBoundingClientRect();
        _sd.ghost = _sd.el.cloneNode(true);
        _sd.ghost.className = _sd.el.className + ' struct-drag-ghost';
        _sd.ghost.style.cssText = 
            'position:fixed; z-index:9999; pointer-events:none;' +
            'width:' + rect.width + 'px; height:' + rect.height + 'px;' +
            'max-width:' + rect.width + 'px;' +
            'opacity:0.85; transform:rotate(1deg);' +
            'box-shadow:0 8px 24px rgba(0,0,0,0.18); border-radius:8px;' +
            'background:white; overflow:hidden;';
        document.body.appendChild(_sd.ghost);
        
        // Offset du clic dans l'élément
        _sd.offsetY = event.clientY - rect.top;
        _sd.offsetX = event.clientX - rect.left;
        
        // Garder l'original visible en transparence
        _sd.el.style.opacity = '0.25';
        _sd.el.style.transition = 'none';
        
        // Créer le placeholder
        _sd.placeholder = document.createElement('div');
        _sd.placeholder.className = 'struct-drag-placeholder';
        _sd.placeholder.style.cssText = 'height:0; overflow:hidden; border:2px dashed var(--primary); border-radius:8px; margin:4px 0; background:rgba(99,102,241,0.06);';
        _sd.placeholderHeight = rect.height;
    }
    
    // Positionner le ghost là où est la souris
    _sd.ghost.style.left = (event.clientX - _sd.offsetX) + 'px';
    _sd.ghost.style.top = (event.clientY - _sd.offsetY) + 'px';
    
    // Trouver les items cibles
    if (_sd.type === 'section') {
        var items = Array.from(document.querySelectorAll('#structureSections > .structure-section'));
        var others = items.filter(function(it) { return it !== _sd.el; });
        if (others.length === 0) return;
        
        var insertBeforeItem = null;
        for (var i = 0; i < others.length; i++) {
            var r = others[i].getBoundingClientRect();
            if (r.height === 0) continue;
            if (event.clientY < r.top + r.height / 2) { insertBeforeItem = others[i]; break; }
        }
        
        var targetParent, targetNextSibling;
        if (insertBeforeItem) {
            targetParent = insertBeforeItem.parentNode;
            targetNextSibling = insertBeforeItem;
        } else {
            var last = others[others.length - 1];
            targetParent = last.parentNode;
            var ns = last.nextSibling;
            while (ns && (ns === _sd.el || ns === _sd.placeholder)) ns = ns.nextSibling;
            targetNextSibling = ns;
        }
        
        var isOriginal = false;
        if (targetNextSibling === _sd.el) {
            isOriginal = true;
        } else {
            var elNext = _sd.el.nextElementSibling;
            while (elNext && elNext === _sd.placeholder) elNext = elNext.nextElementSibling;
            if (targetNextSibling === elNext && targetParent === _sd.el.parentNode) isOriginal = true;
        }
        
        var siblings = Array.from(targetParent.children);
        var targetH = isOriginal ? 0 : _sd.placeholderHeight;
        flipAnimate(siblings, _sd.placeholder, _sd.el, function() {
            if (targetNextSibling) {
                targetParent.insertBefore(_sd.placeholder, targetNextSibling);
            } else {
                targetParent.appendChild(_sd.placeholder);
            }
            _sd.placeholder.style.height = targetH + 'px';
        });
    } else {
        // ACTIVITÉS : approche section-aware
        // 1) Trouver quelle section (.structure-activities) le curseur survole
        var hoveredContainer = null;
        var allContainers = document.querySelectorAll('.structure-activities');
        for (var c = 0; c < allContainers.length; c++) {
            var cr = allContainers[c].getBoundingClientRect();
            if (event.clientY >= cr.top && event.clientY <= cr.bottom) {
                hoveredContainer = allContainers[c];
                break;
            }
        }
        if (!hoveredContainer) {
            var bestDist = Infinity;
            for (var c = 0; c < allContainers.length; c++) {
                var cr = allContainers[c].getBoundingClientRect();
                var dist = event.clientY < cr.top ? cr.top - event.clientY : event.clientY - cr.bottom;
                if (dist < bestDist) { bestDist = dist; hoveredContainer = allContainers[c]; }
            }
        }
        if (!hoveredContainer) return;
        
        // 2) Activités dans cette section uniquement
        var actSelector = '.structure-activity, .mapmodules-inline-preview[data-activity-id]';
        var sectionActs = Array.from(hoveredContainer.querySelectorAll(actSelector));
        var others = sectionActs.filter(function(it) { return it !== _sd.el; });
        
        var addBtn = hoveredContainer.querySelector('.structure-add-activity');
        
        if (others.length === 0) {
            // Section vide : insérer avant le bouton "Ajouter un parcours"
            var siblings = Array.from(hoveredContainer.children);
            flipAnimate(siblings, _sd.placeholder, _sd.el, function() {
                if (addBtn) {
                    hoveredContainer.insertBefore(_sd.placeholder, addBtn);
                } else {
                    hoveredContainer.appendChild(_sd.placeholder);
                }
                _sd.placeholder.style.height = _sd.placeholderHeight + 'px';
            });
        } else {
            var insertBeforeItem = null;
            for (var i = 0; i < others.length; i++) {
                var r = others[i].getBoundingClientRect();
                if (r.height === 0) continue;
                if (event.clientY < r.top + r.height / 2) { insertBeforeItem = others[i]; break; }
            }
            
            // Si pas d'item trouvé = dernière position → avant le bouton +
            var targetNextSibling = insertBeforeItem || addBtn || null;
            
            var isOriginal = false;
            if (targetNextSibling === _sd.el) {
                isOriginal = true;
            } else {
                var elNext = _sd.el.nextElementSibling;
                while (elNext && elNext === _sd.placeholder) elNext = elNext.nextElementSibling;
                if (targetNextSibling === elNext && hoveredContainer === _sd.el.parentNode) isOriginal = true;
            }
            
            var siblings = Array.from(hoveredContainer.children);
            var targetH = isOriginal ? 0 : _sd.placeholderHeight;
            flipAnimate(siblings, _sd.placeholder, _sd.el, function() {
                if (targetNextSibling) {
                    hoveredContainer.insertBefore(_sd.placeholder, targetNextSibling);
                } else {
                    hoveredContainer.appendChild(_sd.placeholder);
                }
                _sd.placeholder.style.height = targetH + 'px';
            });
        }
    }
    
    // Auto-scroll
    var scrollContainer = document.querySelector('.structure-view');
    if (scrollContainer) {
        var cr = scrollContainer.getBoundingClientRect();
        if (event.clientY < cr.top + 40) scrollContainer.scrollTop -= 8;
        if (event.clientY > cr.bottom - 40) scrollContainer.scrollTop += 8;
    }
}

function structDragEnd(event) {
    document.removeEventListener('mousemove', structDragMove);
    document.removeEventListener('mouseup', structDragEnd);
    
    // Restaurer la sélection de texte
    document.body.style.userSelect = '';
    document.body.style.webkitUserSelect = '';
    
    if (!_sd.active) { _sd.el = null; return; }
    
    // Bloquer le clic qui va suivre le mouseup (sinon ça ouvre l'activité)
    var blocker = function(e) { e.stopPropagation(); e.preventDefault(); };
    document.addEventListener('click', blocker, true);
    setTimeout(function() { document.removeEventListener('click', blocker, true); }, 50);
    
    // Calculer la nouvelle position à partir du placeholder
    var placeholder = _sd.placeholder;
    var parent = placeholder ? placeholder.parentNode : null;
    
    if (parent && _sd.type === 'section') {
        var toIdx = 0;
        var child = parent.firstElementChild;
        while (child) {
            if (child === placeholder) break;
            if (child.classList.contains('structure-section') && child !== _sd.el) toIdx++;
            child = child.nextElementSibling;
        }
        
        var fromIdx = courseData.sections.findIndex(function(s) { return s.id === _sd.id; });
        if (fromIdx !== -1 && fromIdx !== toIdx) {
            var sec = courseData.sections.splice(fromIdx, 1)[0];
            courseData.sections.splice(toIdx, 0, sec);
            showToast('Section déplacée', 'success');
        }
        
    } else if (parent && _sd.type === 'activity') {
        var targetSectionEl = placeholder.closest('.structure-section');
        var targetSectionId = targetSectionEl ? targetSectionEl.dataset.sectionId : _sd.sectionId;
        
        var activitiesContainer = placeholder.parentNode;
        var toIdx = 0;
        var child = activitiesContainer.firstElementChild;
        while (child) {
            if (child === placeholder) break;
            if ((child.classList.contains('structure-activity') || child.classList.contains('mapmodules-inline-preview')) && child !== _sd.el) toIdx++;
            child = child.nextElementSibling;
        }
        
        var fromSection = courseData.sections.find(function(s) { return s.id === _sd.sectionId; });
        var toSection = courseData.sections.find(function(s) { return s.id === targetSectionId; });
        
        if (fromSection && toSection) {
            var fromIdx = fromSection.activities.findIndex(function(a) { return a.id === _sd.id; });
            if (fromIdx !== -1) {
                var act = fromSection.activities.splice(fromIdx, 1)[0];
                toSection.activities.splice(toIdx, 0, act);
                showToast('Parcours déplacé', 'success');
            }
        }
    }
    
    // Nettoyer
    if (_sd.ghost) _sd.ghost.remove();
    if (_sd.placeholder) _sd.placeholder.remove();
    if (_sd.el) { _sd.el.style.opacity = ''; _sd.el.style.transition = ''; }
    _sd = { active: false, type: null, id: null, sectionId: null, el: null, ghost: null, placeholder: null, startY: 0 };
    
    renderTree();
    renderStructureView();
    onCourseModified();
}

// Legacy handlers kept for tree sidebar (left panel)
let structureDragData = null;
function handleStructureDragStart() {}
function handleStructureDragOver() {}
function handleStructureDrop() {}
function handleStructureDragEnd() {}

// ==================== ÉDITEUR COURSE PRESENTATION ====================
let cpCurrentSlide = 0;
// ==================== ÉDITEUR CARTE DE PROGRESSION ====================

const MAPMODULES_DEFAULT_PATH = 'M 22 120 C 38 95 68 37 99 34 C 131 31 198 79 206 105 C 214 131 208 162 184 180 C 159 197 119 202 104 236 C 89 270 99 304 112 318 C 125 332 160 351 234 342 C 307 334 342 306 353 288 C 363 271 370 216 359 189 C 349 162 323 107 323 97 C 323 88 323 60 351 49 C 378 39 450 20 493 47 C 536 73 532 116 521 150 C 511 183 477 264 477 272 C 477 280 482 314 510 329 C 537 344 591 361 633 348 C 675 335 697 320 703 307 C 709 294 720 265 704 236 C 689 208 667 170 670 146 C 673 122 680 83 715 68 C 750 53 782 45 796 65 C 810 84 835 126 840 170 C 844 213 866 259 881 264 C 896 268 910 272 930 265 C 949 258 971 245 971 245';

function renderMapmodulesEditor(activity) {
    const content = document.getElementById('editorContent');
    const empty = document.getElementById('emptyCanvas');
    empty.style.display = 'none';
    content.style.display = 'flex';

    const mapPath = activity.mapPath || MAPMODULES_DEFAULT_PATH;
    const header = activity.descriptionHeader || '';
    const footer = activity.descriptionFooter || '';
    const customImage = activity.mapImage || '';

    // Compter les activités visibles (toutes sections, hors cartes de progression)
    let totalActivities = 0;
    (courseData.sections || []).forEach(s => {
        (s.activities || []).forEach(a => {
            if (a.type !== 'mapmodules') totalActivities++;
        });
    });

    content.innerHTML = `
        <div style="max-width: 900px; margin: 0 auto; padding: 1rem; width: 100%;">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <button class="btn btn-secondary" onclick="showStructureView()" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;" title="Retour">← Retour</button>
                <span style="font-size: 1.5rem;">🗺️</span>
                <div style="flex: 1;">
                    <input type="text" class="cp-prop-input" value="${escapeHtml(activity.name)}" 
                           onchange="mapmodulesUpdateProp('name', this.value)"
                           style="font-size: 1.1rem; font-weight: 600;">
                </div>
            </div>

            <div class="cp-prop-group" style="margin-bottom: 0.75rem;">
                <label class="cp-prop-label">Texte au-dessus de la carte</label>
                <div class="rich-text-toolbar">
                    <button class="rich-text-btn" type="button" onclick="cpExecCmd('mapHeaderEditor','bold')" title="Gras"><b>G</b></button>
                    <button class="rich-text-btn" type="button" onclick="cpExecCmd('mapHeaderEditor','italic')" title="Italique"><i>I</i></button>
                    <button class="rich-text-btn" type="button" onclick="cpExecCmd('mapHeaderEditor','underline')" title="Souligné"><u>S</u></button>
                </div>
                <div id="mapHeaderEditor" class="rich-text-editor" contenteditable="true"
                     style="min-height: 40px; font-size: 0.85rem;"
                     oninput="mapmodulesUpdateProp('descriptionHeader', this.innerHTML)">${header}</div>
            </div>

            <div style="position: relative; width: 100%; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.12); margin-bottom: 0.5rem; cursor: crosshair;"
                 id="mapEditorContainer">
                ${customImage 
                    ? '<img src="' + escapeHtml(customImage) + '" style="width: 100%; display: block;" alt="carte" draggable="false">'
                    : ''}
                <svg id="mapmodulesSvg" viewBox="0 0 1000 400" style="${customImage ? 'position: absolute; top: 0; left: 0;' : ''} width: 100%; height: 100%; display: block;">
                    ${customImage ? '' : '<rect width="1000" height="400" fill="#FF9800" rx="12"/>'}
                    ${customImage ? '' : '<path d="' + escapeHtml(MAPMODULES_DEFAULT_PATH) + '" fill="none" stroke="white" stroke-width="4" stroke-dasharray="8 12" stroke-linecap="round" pointer-events="none"/>'}
                    <g id="mapControlLines"></g>
                    <path id="mapPath" d="${escapeHtml(mapPath)}" fill="none" stroke="rgba(255,100,100,0.5)" stroke-width="2" pointer-events="none"/>
                    <g id="mapControlPoints"></g>
                    <g id="mapButtons"></g>
                </svg>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; gap: 0.5rem; flex-wrap: wrap;">
                <div style="font-size: 0.8rem; color: var(--gray-500);">
                    ${totalActivities} activité${totalActivities > 1 ? 's' : ''} → ${totalActivities} bouton${totalActivities > 1 ? 's' : ''}
                </div>
                <div style="display: flex; gap: 0.4rem; align-items: center;">
                    <label style="font-size: 0.75rem; display: flex; align-items: center; gap: 0.3rem; cursor: pointer; user-select: none;">
                        <input type="checkbox" id="mapEditMode" onchange="mapmodulesToggleEditMode(this.checked)" checked>
                        Éditer le tracé
                    </label>
                    <button class="btn btn-secondary" style="font-size: 0.7rem; padding: 0.2rem 0.5rem;"
                            onclick="mapmodulesUpdateProp('mapPath', MAPMODULES_DEFAULT_PATH); renderMapmodulesEditor(getSelectedActivity())">↩️ Tracé par défaut</button>
                </div>
            </div>

            <div class="cp-prop-group" style="margin-bottom: 0.75rem;">
                <label class="cp-prop-label">Image de fond personnalisée</label>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <input type="file" id="mapCustomImage" accept="image/*" onchange="mapmodulesUploadImage()" style="font-size: 0.75rem; flex: 1;">
                    ${customImage ? '<button class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;" onclick="mapmodulesUpdateProp(\'mapImage\', null); renderMapmodulesEditor(getSelectedActivity())">🗑️ Supprimer</button>' : ''}
                </div>
            </div>

            <div class="cp-prop-group" style="margin-bottom: 0.75rem;">
                <label class="cp-prop-label">Texte sous la carte</label>
                <div class="rich-text-toolbar">
                    <button class="rich-text-btn" type="button" onclick="cpExecCmd('mapFooterEditor','bold')" title="Gras"><b>G</b></button>
                    <button class="rich-text-btn" type="button" onclick="cpExecCmd('mapFooterEditor','italic')" title="Italique"><i>I</i></button>
                    <button class="rich-text-btn" type="button" onclick="cpExecCmd('mapFooterEditor','underline')" title="Souligné"><u>S</u></button>
                </div>
                <div id="mapFooterEditor" class="rich-text-editor" contenteditable="true"
                     style="min-height: 40px; font-size: 0.85rem;"
                     oninput="mapmodulesUpdateProp('descriptionFooter', this.innerHTML)">${footer}</div>
            </div>
        </div>`;

    // Initialiser l'éditeur de bézier
    requestAnimationFrame(() => {
        mapmodulesPlaceButtons();
        mapmodulesInitBezierEditor(mapPath);
    });
}

// ========== ÉDITEUR DE BÉZIER INTERACTIF ==========

let _mapBezierPoints = []; // [{x, y, type: 'anchor'|'cp1'|'cp2'}]
let _mapDragging = null;
let _mapEditMode = true;

function mapmodulesParsePathToPoints(d) {
    const points = [];
    // Nettoyer et tokeniser
    const tokens = d.replace(/,/g, ' ').replace(/([MCmc])/g, ' $1 ').trim().split(/\s+/);
    let i = 0;
    while (i < tokens.length) {
        const cmd = tokens[i];
        if (cmd === 'M' || cmd === 'm') {
            i++;
            points.push({ x: parseFloat(tokens[i++]), y: parseFloat(tokens[i++]), type: 'anchor' });
        } else if (cmd === 'C' || cmd === 'c') {
            i++;
            while (i + 5 < tokens.length && isFinite(parseFloat(tokens[i]))) {
                points.push({ x: parseFloat(tokens[i++]), y: parseFloat(tokens[i++]), type: 'cp1' });
                points.push({ x: parseFloat(tokens[i++]), y: parseFloat(tokens[i++]), type: 'cp2' });
                points.push({ x: parseFloat(tokens[i++]), y: parseFloat(tokens[i++]), type: 'anchor' });
            }
        } else {
            i++; // skip unknown
        }
    }
    return points;
}

function mapmodulesPointsToPath(points) {
    if (points.length < 1) return '';
    let d = `M ${Math.round(points[0].x)} ${Math.round(points[0].y)}`;
    for (let i = 1; i + 2 < points.length; i += 3) {
        d += ` C ${Math.round(points[i].x)} ${Math.round(points[i].y)} ${Math.round(points[i+1].x)} ${Math.round(points[i+1].y)} ${Math.round(points[i+2].x)} ${Math.round(points[i+2].y)}`;
    }
    return d;
}

function mapmodulesInitBezierEditor(pathD) {
    _mapBezierPoints = mapmodulesParsePathToPoints(pathD);
    _mapEditMode = document.getElementById('mapEditMode')?.checked ?? true;
    mapmodulesRenderControlPoints();
    mapmodulesSetupDrag();
}

function mapmodulesRenderControlPoints() {
    const linesG = document.getElementById('mapControlLines');
    const pointsG = document.getElementById('mapControlPoints');
    if (!linesG || !pointsG) return;
    
    linesG.innerHTML = '';
    pointsG.innerHTML = '';
    
    if (!_mapEditMode) return;
    
    const pts = _mapBezierPoints;
    
    // Dessiner les lignes de contrôle (anchor → cp)
    for (let i = 0; i + 3 < pts.length; i += 3) {
        // Anchor[i] → CP1[i+1]
        const line1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line1.setAttribute('x1', pts[i].x); line1.setAttribute('y1', pts[i].y);
        line1.setAttribute('x2', pts[i+1].x); line1.setAttribute('y2', pts[i+1].y);
        line1.setAttribute('stroke', 'rgba(255,255,255,0.4)'); line1.setAttribute('stroke-width', '1.5');
        linesG.appendChild(line1);
        
        // CP2[i+2] → Anchor[i+3]
        const line2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line2.setAttribute('x1', pts[i+2].x); line2.setAttribute('y1', pts[i+2].y);
        line2.setAttribute('x2', pts[i+3].x); line2.setAttribute('y2', pts[i+3].y);
        line2.setAttribute('stroke', 'rgba(255,255,255,0.4)'); line2.setAttribute('stroke-width', '1.5');
        linesG.appendChild(line2);
    }
    
    // Dessiner les points (contrôle en bleu petit, ancres en blanc grand)
    pts.forEach((pt, idx) => {
        const isAnchor = pt.type === 'anchor';
        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', pt.x);
        circle.setAttribute('cy', pt.y);
        circle.setAttribute('r', isAnchor ? '8' : '5');
        circle.setAttribute('fill', isAnchor ? 'white' : '#42A5F5');
        circle.setAttribute('stroke', isAnchor ? '#333' : '#1565C0');
        circle.setAttribute('stroke-width', isAnchor ? '2' : '1.5');
        circle.setAttribute('data-idx', idx);
        circle.style.cursor = 'grab';
        pointsG.appendChild(circle);
    });
}

function mapmodulesSetupDrag() {
    const svg = document.getElementById('mapmodulesSvg');
    if (!svg) return;
    
    // Supprimer les anciens handlers
    svg.onmousedown = null; svg.onmousemove = null; svg.onmouseup = null;
    svg.ontouchstart = null; svg.ontouchmove = null; svg.ontouchend = null;
    
    function getSvgPoint(e) {
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        const rect = svg.getBoundingClientRect();
        const viewBox = svg.viewBox.baseVal;
        return {
            x: (clientX - rect.left) / rect.width * viewBox.width,
            y: (clientY - rect.top) / rect.height * viewBox.height
        };
    }
    
    function onDown(e) {
        if (!_mapEditMode) return;
        const target = e.target.closest('circle[data-idx]');
        if (!target) return;
        e.preventDefault();
        _mapDragging = parseInt(target.getAttribute('data-idx'));
        target.style.cursor = 'grabbing';
    }
    
    function onMove(e) {
        if (_mapDragging === null) return;
        e.preventDefault();
        const pt = getSvgPoint(e);
        // Clamp dans le viewBox
        _mapBezierPoints[_mapDragging].x = Math.max(0, Math.min(1000, pt.x));
        _mapBezierPoints[_mapDragging].y = Math.max(0, Math.min(400, pt.y));
        
        // Mettre à jour le path SVG en temps réel
        const newD = mapmodulesPointsToPath(_mapBezierPoints);
        const pathEl = document.getElementById('mapPath');
        if (pathEl) pathEl.setAttribute('d', newD);
        
        // Mettre à jour les poignées
        mapmodulesRenderControlPoints();
        
        // Mettre à jour les boutons "?"
        const buttonsG = document.getElementById('mapButtons');
        if (buttonsG) {
            buttonsG.innerHTML = '';
            const actList = mapmodulesGetActivityList();
            const actCount = actList.length;
            if (actCount > 0 && pathEl) {
                const totalLen = pathEl.getTotalLength();
                for (let i = 0; i < actCount; i++) {
                    const t = mapmodulesCalcT(i, actCount);
                    const p = pathEl.getPointAtLength(t * totalLen);
                    const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                    g.style.cursor = 'pointer';
                    g.innerHTML = '<circle cx="'+p.x+'" cy="'+p.y+'" r="18" fill="#2E7D32" stroke="white" stroke-width="2"/>' +
                        '<text x="'+p.x+'" y="'+(p.y+1)+'" text-anchor="middle" dominant-baseline="central" fill="white" font-size="18" font-weight="bold">?</text>';
                    const act = actList[i];
                    if (act) {
                        g.onclick = (e) => { e.stopPropagation(); selectActivity(act.sectionId, act.activityId); };
                    }
                    buttonsG.appendChild(g);
                }
            }
        }
    }
    
    function onUp() {
        if (_mapDragging !== null) {
            _mapDragging = null;
            // Sauvegarder le chemin
            const newD = mapmodulesPointsToPath(_mapBezierPoints);
            const activity = getSelectedActivity();
            if (activity) {
                activity.mapPath = newD;
                onCourseModified();
            }
        }
    }
    
    svg.addEventListener('mousedown', onDown);
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    svg.addEventListener('touchstart', onDown, { passive: false });
    window.addEventListener('touchmove', onMove, { passive: false });
    window.addEventListener('touchend', onUp);
    
    // Cleanup au prochain render
    svg._mapCleanup = () => {
        window.removeEventListener('mousemove', onMove);
        window.removeEventListener('mouseup', onUp);
        window.removeEventListener('touchmove', onMove);
        window.removeEventListener('touchend', onUp);
    };
}

function mapmodulesToggleEditMode(on) {
    _mapEditMode = on;
    mapmodulesRenderControlPoints();
}

// Récupère la liste ordonnée des activités non-mapmodules avec leurs IDs section/activité
function mapmodulesGetActivityList() {
    const list = [];
    (courseData.sections || []).forEach(s => {
        (s.activities || []).forEach(a => {
            if (a.type !== 'mapmodules') list.push({ sectionId: s.id, activityId: a.id, name: a.name });
        });
    });
    return list;
}

// Calcule la position t sur le chemin pour le bouton i parmi count
function mapmodulesCalcT(i, count) {
    if (count <= 1) return 0;
    return i / (count - 1);
}

function mapmodulesPlaceButtons() {
    const buttonsG = document.getElementById('mapButtons');
    const pathEl = document.getElementById('mapPath');
    if (!buttonsG || !pathEl) return;

    buttonsG.innerHTML = '';
    const actList = mapmodulesGetActivityList();
    const count = actList.length;
    if (count <= 0) return;
    const totalLen = pathEl.getTotalLength();

    for (let i = 0; i < count; i++) {
        const t = mapmodulesCalcT(i, count);
        const pt = pathEl.getPointAtLength(t * totalLen);
        const act = actList[i];
        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        g.style.cursor = 'pointer';
        g.innerHTML = `
            <circle cx="${pt.x}" cy="${pt.y}" r="18" fill="#2E7D32" stroke="white" stroke-width="2"/>
            <text x="${pt.x}" y="${pt.y + 1}" text-anchor="middle" dominant-baseline="central" 
                  fill="white" font-size="18" font-weight="bold">?</text>`;
        if (act) {
            g.onclick = (e) => { e.stopPropagation(); selectActivity(act.sectionId, act.activityId); };
            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = act.name;
            g.appendChild(title);
        }
        buttonsG.appendChild(g);
    }
}

function mapmodulesUpdateProp(prop, value) {
    const activity = getSelectedActivity();
    if (!activity) return;
    activity[prop] = value;
    onCourseModified();
}

function mapmodulesUploadImage() {
    const input = document.getElementById('mapCustomImage');
    if (!input.files[0]) return;

    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('session_id', getEditorSessionId());
    formData.append('file', input.files[0]);

    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.url) {
            if (data.filename && typeof EditorDriveSync !== 'undefined') EditorDriveSync.onFileUploaded(data.filename, data.url, data.type || '');
            mapmodulesUpdateProp('mapImage', data.url);
            renderMapmodulesEditor(getSelectedActivity());
        } else {
            showToast('Erreur upload: ' + (data.error || ''), 'error');
        }
    })
    .catch(err => showToast('Erreur: ' + err.message, 'error'));
}

let cpSelectedElement = null;
let cpSelectedElements = new Set();
let cpDragging = null;
let cpResizing = null;


// ==================== RECHERCHE DANS LE COURS ====================

function edSearchCourse(query) {
    var resultsDiv = document.getElementById('edSearchResults');
    if (!resultsDiv) return;
    
    query = (query || '').trim().toLowerCase();
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        resultsDiv.innerHTML = '';
        return;
    }
    
    var results = [];
    
    if (typeof courseData === 'undefined' || !courseData.sections) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    courseData.sections.forEach(function(section) {
        (section.activities || []).forEach(function(activity) {
            var sectionId = section.id;
            var activityId = activity.id;
            var activityName = activity.name || '';
            var type = activity.h5pType || activity.type || '';
            
            // Course Presentation : chercher dans chaque slide
            if (type === 'CoursePresentation' && activity.content && activity.content.presentation && activity.content.presentation.slides) {
                activity.content.presentation.slides.forEach(function(slide, slideIdx) {
                    (slide.elements || []).forEach(function(el) {
                        var text = _edExtractElementText(el);
                        if (text.toLowerCase().includes(query)) {
                            var excerpt = _edExcerpt(text, query);
                            results.push({
                                sectionId: sectionId,
                                activityId: activityId,
                                activityName: activityName,
                                slideIdx: slideIdx,
                                type: 'cp',
                                label: activityName + ' — Slide ' + (slideIdx + 1),
                                excerpt: excerpt
                            });
                        }
                    });
                });
            }
            // Quiz / QuestionSet
            else if (activity.content && activity.content.questions) {
                activity.content.questions.forEach(function(q, qIdx) {
                    var text = _edStripHtml(q.questiontext || '');
                    // Also search in answers
                    if (q.answers) q.answers.forEach(function(a) { text += ' ' + _edStripHtml(a.text || ''); });
                    if (q.choices) q.choices.forEach(function(c) { text += ' ' + (c.text || ''); });
                    if (text.toLowerCase().includes(query)) {
                        var excerpt = _edExcerpt(text, query);
                        results.push({
                            sectionId: sectionId,
                            activityId: activityId,
                            activityName: activityName,
                            type: 'quiz',
                            label: activityName + ' — Q' + (qIdx + 1),
                            excerpt: excerpt
                        });
                    }
                });
            }
            // Other activities with content (text, description, etc.)
            else if (activity.content) {
                var text = _edExtractContentText(activity.content);
                if (text.toLowerCase().includes(query)) {
                    var excerpt = _edExcerpt(text, query);
                    results.push({
                        sectionId: sectionId,
                        activityId: activityId,
                        activityName: activityName,
                        type: 'activity',
                        label: activityName,
                        excerpt: excerpt
                    });
                }
            }
        });
    });
    
    if (results.length === 0) {
        resultsDiv.innerHTML = '<div class="ed-search-empty">Aucun résultat</div>';
        resultsDiv.style.display = 'block';
        return;
    }
    
    // Limiter à 20 résultats
    var html = '';
    results.slice(0, 20).forEach(function(r, i) {
        html += '<div class="ed-search-result" onclick="edSearchGoTo(' + i + ')" data-idx="' + i + '">';
        html += '<div class="ed-search-result-label">' + escapeHtml(r.label) + '</div>';
        html += '<div class="ed-search-result-excerpt">' + r.excerpt + '</div>';
        html += '</div>';
    });
    if (results.length > 20) {
        html += '<div class="ed-search-empty">' + (results.length - 20) + ' autres résultats...</div>';
    }
    
    resultsDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
    window._edSearchResults = results;
}

function edSearchGoTo(idx) {
    var r = window._edSearchResults && window._edSearchResults[idx];
    if (!r) return;
    
    // Fermer la recherche
    var resultsDiv = document.getElementById('edSearchResults');
    if (resultsDiv) resultsDiv.style.display = 'none';
    
    // Naviguer vers l'activité
    selectActivity(r.sectionId, r.activityId);
    
    // Si Course Presentation, aller à la bonne slide
    if (r.type === 'cp' && r.slideIdx !== undefined) {
        setTimeout(function() {
            if (typeof cpGoToSlide === 'function') {
                cpGoToSlide(r.slideIdx);
            }
        }, 100);
    }
}

function _edExtractElementText(el) {
    var parts = [];
    // Text content
    if (el.action && el.action.params) {
        var p = el.action.params;
        if (p.text) parts.push(_edStripHtml(p.text));
        if (p.question) parts.push(_edStripHtml(p.question));
        if (p.alt) parts.push(p.alt);
        if (p.answers) p.answers.forEach(function(a) { parts.push(_edStripHtml(a.text || '')); });
        if (p.questions) p.questions.forEach(function(q) { parts.push(_edStripHtml(q)); });
        // Blanks
        if (p.textField) parts.push(_edStripHtml(p.textField));
    }
    return parts.join(' ');
}

function _edExtractContentText(content) {
    if (!content) return '';
    var parts = [];
    // Recursive text extraction from any content object
    function walk(obj) {
        if (typeof obj === 'string') { parts.push(_edStripHtml(obj)); return; }
        if (Array.isArray(obj)) { obj.forEach(walk); return; }
        if (obj && typeof obj === 'object') {
            Object.keys(obj).forEach(function(k) {
                if (k === 'path' || k === 'mime' || k === 'id' || k === 'subContentId') return; // skip non-text
                walk(obj[k]);
            });
        }
    }
    walk(content);
    return parts.join(' ');
}

function _edStripHtml(html) {
    var div = document.createElement('div');
    div.innerHTML = html || '';
    return div.textContent || '';
}

function _edExcerpt(text, query) {
    var lower = text.toLowerCase();
    var pos = lower.indexOf(query);
    if (pos === -1) return escapeHtml(text.substring(0, 60)) + '...';
    var start = Math.max(0, pos - 25);
    var end = Math.min(text.length, pos + query.length + 25);
    var excerpt = (start > 0 ? '...' : '') + text.substring(start, end) + (end < text.length ? '...' : '');
    // Highlight the match
    var matchStart = pos - start;
    var before = escapeHtml(excerpt.substring(0, matchStart + (start > 0 ? 3 : 0)));
    var match = escapeHtml(excerpt.substring(matchStart + (start > 0 ? 3 : 0), matchStart + (start > 0 ? 3 : 0) + query.length));
    var after = escapeHtml(excerpt.substring(matchStart + (start > 0 ? 3 : 0) + query.length));
    return before + '<mark>' + match + '</mark>' + after;
}

// Fermer les résultats quand on clique ailleurs
document.addEventListener('click', function(e) {
    var wrapper = document.querySelector('.ed-search-wrapper');
    var resultsDiv = document.getElementById('edSearchResults');
    if (wrapper && resultsDiv && !wrapper.contains(e.target)) {
        resultsDiv.style.display = 'none';
    }
});
