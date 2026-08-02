// ==================== IMPORT ====================
// Version: 2026-02-25-v3 (avec support assign fileUrl/fileName/intro + loading overlay)
// Variable pour stocker les données importées temporairement
let importedCourseData = null;

// ==================== OUVERTURE MODAL ====================
function openImportModal() {
    document.getElementById('importFileInput').value = '';
    document.getElementById('importMainView').style.display = 'block';
    document.getElementById('importSelectorZone').style.display = 'none';
    document.getElementById('importFooter').style.display = 'none';
    openModal('importModal');
    
    // Charger la liste des cours permanents
    loadPermanentCoursesList();
}

// ==================== RETOUR À LA LISTE ====================
function backToImportList() {
    document.getElementById('importMainView').style.display = 'block';
    document.getElementById('importSelectorZone').style.display = 'none';
    document.getElementById('importFooter').style.display = 'none';
}

// ==================== IMPORT FICHIER LOCAL ====================
function loadImportFile() {
    const file = document.getElementById('importFileInput').files[0];
    if (!file) {
        return;
    }
    
    // Limite 200 Mo
    if (file.size > 200 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux (' + (file.size / (1024*1024)).toFixed(1) + ' Mo). Limite : 200 Mo.', 'error');
        document.getElementById('importFileInput').value = '';
        return;
    }
    
    const fileName = file.name.replace(/\.mbz$/i, '');
    showLoadingOverlay('Import du parcours...', fileName);
    
    const formData = new FormData();
    formData.append('action', 'parse_mbz');
    formData.append('file', file);
    formData.append('session_id', getEditorSessionId());
    
    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        hideLoadingOverlay();
        
        if (data.success) {
            showImportSelector(data.course);
        } else {
            throw new Error(data.error || 'Erreur de parsing');
        }
    })
    .catch(err => {
        hideLoadingOverlay();
        showToast('Erreur: ' + err.message, 'error');
    });
}

// ==================== LISTE DES COURS PERMANENTS ====================
function loadPermanentCoursesList() {
    const container = document.getElementById('importDriveContent');
    container.innerHTML = `
        <div style="text-align: center; padding: 2rem;">
            <div class="spinner"></div>
            <p style="margin-top: 1rem; color: var(--gray-500);">Chargement des cours...</p>
        </div>`;
    
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_drive_courses' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.folders) {
            renderPermanentCoursesList(data.folders);
        } else {
            container.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                    <p>⚠️ ${data.error || 'Aucun cours permanent disponible'}</p>
                </div>`;
        }
    })
    .catch(err => {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: var(--danger);">
                <p>❌ Erreur de chargement</p>
                <p style="font-size: 0.85rem;">${err.message}</p>
            </div>`;
    });
}

function renderPermanentCoursesList(folders) {
    const container = document.getElementById('importDriveContent');
    
    const folderNames = Object.keys(folders);
    if (folderNames.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                <p>Aucun cours permanent disponible</p>
            </div>`;
        return;
    }
    
    let html = '<div class="import-drive-folders">';
    
    folderNames.forEach((folderName, idx) => {
        const courses = folders[folderName];
        const isFirst = idx === 0;
        
        html += `
            <div class="import-drive-folder ${isFirst ? 'expanded' : ''}" data-folder="${idx}">
                <div class="import-drive-folder-header" onclick="toggleDriveFolder(${idx})">
                    <span class="import-drive-folder-icon">📁</span>
                    <span class="import-drive-folder-name">${escapeHtml(folderName)}</span>
                    <span class="import-drive-folder-count">${courses.length}</span>
                    <span class="import-drive-folder-toggle">▶</span>
                </div>
                <div class="import-drive-courses">`;
        
        courses.forEach(course => {
            html += `
                    <div class="import-drive-course" onclick="loadPermanentCourse('${escapeHtml(course.gdrive_id)}', '${escapeHtml(course.name.replace(/'/g, "\\'"))}')">
                        <span class="import-drive-course-icon">📚</span>
                        <span class="import-drive-course-name">${escapeHtml(course.name)}</span>
                        <span class="import-drive-course-action">Sélectionner →</span>
                    </div>`;
        });
        
        html += `
                </div>
            </div>`;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

function toggleDriveFolder(idx) {
    const folder = document.querySelector(`.import-drive-folder[data-folder="${idx}"]`);
    if (folder) {
        folder.classList.toggle('expanded');
    }
}

function loadPermanentCourse(gdriveId, name) {
    showLoadingOverlay('Chargement du parcours...', name);
    
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'parse_drive_mbz', gdrive_id: gdriveId })
    })
    .then(r => r.json())
    .then(data => {
        hideLoadingOverlay();

        if (data.success) {
            if (typeof DriveUploadWidget !== 'undefined') {
                DriveUploadWidget.enqueue(gdriveId, name || (data.course && data.course.name) || gdriveId);
            }
            showImportSelector(data.course);
        } else {
            throw new Error(data.error || 'Erreur de chargement');
        }
    })
    .catch(err => {
        hideLoadingOverlay();
        showToast('Erreur: ' + err.message, 'error');
    });
}

// ==================== SÉLECTEUR D'IMPORT ====================
function showImportSelector(importedCourse) {
    importedCourseData = importedCourse;
    
    if (!importedCourse.sections || importedCourse.sections.length === 0) {
        showToast('Aucune section trouvée dans ce parcours', 'error');
        return;
    }
    
    // Compter le total
    let totalActivities = 0;
    importedCourse.sections.forEach(s => totalActivities += (s.activities || []).length);
    
    // Construire le HTML du sélecteur
    let html = `
        <div class="import-course-info">
            <span class="import-course-info-icon">📚</span>
            <div>
                <h4>${escapeHtml(importedCourse.name || 'Cours importé')}</h4>
                <p>${importedCourse.sections.length} section(s) • ${totalActivities} activité(s)</p>
            </div>
        </div>
        
        <div style="margin-bottom: 1rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" class="import-checkbox" id="importSelectAll" checked onchange="toggleImportAll(this.checked)">
                <strong>Tout sélectionner</strong>
            </label>
        </div>
        
        <div class="import-selector">`;
    
    importedCourse.sections.forEach((section, sIdx) => {
        const actCount = (section.activities || []).length;
        html += `
        <div class="import-section-item expanded" data-section-idx="${sIdx}">
            <div class="import-section-header" onclick="toggleImportSectionExpand(${sIdx})">
                <input type="checkbox" class="import-checkbox import-section-checkbox" data-section="${sIdx}" checked 
                       onclick="event.stopPropagation(); updateImportSectionCheck(${sIdx}, this.checked)">
                <span class="import-section-icon">📁</span>
                <span class="import-section-name">${escapeHtml(section.name || 'Section')}</span>
                <span class="import-section-count">${actCount}</span>
                <button class="import-section-toggle">▼</button>
            </div>
            <div class="import-activities">`;
        
        (section.activities || []).forEach((activity, aIdx) => {
            const icon = getActivityIcon(activity.quizType || activity.h5pType || activity.type);
            html += `
                <div class="import-activity-item">
                    <input type="checkbox" class="import-checkbox import-activity-checkbox" data-section="${sIdx}" data-activity="${aIdx}" checked
                           onchange="updateImportActivityCheck(${sIdx})">
                    <span class="import-activity-icon">${icon}</span>
                    <span class="import-activity-name">${escapeHtml(activity.name || 'Activité')}</span>
                    <span class="import-activity-type">${activity.type === 'mapmodules' ? 'Carte de Progression' : (activity.type === 'quiz' ? (activity.quizType === 'ddimageortext' ? 'Glisser-Déposer Image' : 'Quiz') : (activity.type === 'assign' ? 'Travail à déposer' : (activity.type === 'resource' ? 'Fichiers à distribuer' : (activity.h5pType || activity.type))))}</span>
                </div>`;
        });
        
        html += `
            </div>
        </div>`;
    });
    
    html += `
        </div>
        <div class="import-summary">
            <div class="import-summary-count" id="importSummaryCount">${totalActivities}</div>
            <div class="import-summary-text">activité(s) sélectionnée(s)</div>
        </div>`;
    
    // Afficher le sélecteur
    document.getElementById('importMainView').style.display = 'none';
    document.getElementById('importSelectorZone').innerHTML = html;
    document.getElementById('importSelectorZone').style.display = 'block';
    document.getElementById('importFooter').style.display = 'flex';
}

// ==================== GESTION DES CHECKBOXES ====================
function toggleImportSectionExpand(sectionIdx) {
    const sectionEl = document.querySelector(`.import-section-item[data-section-idx="${sectionIdx}"]`);
    if (sectionEl) {
        sectionEl.classList.toggle('expanded');
        const toggle = sectionEl.querySelector('.import-section-toggle');
        if (toggle) toggle.textContent = sectionEl.classList.contains('expanded') ? '▼' : '▶';
    }
}

function toggleImportAll(checked) {
    document.querySelectorAll('.import-section-checkbox, .import-activity-checkbox').forEach(cb => {
        cb.checked = checked;
    });
    updateImportSummary();
}

function updateImportSectionCheck(sectionIdx, checked) {
    document.querySelectorAll(`.import-activity-checkbox[data-section="${sectionIdx}"]`).forEach(cb => {
        cb.checked = checked;
    });
    updateImportSelectAll();
    updateImportSummary();
}

function updateImportActivityCheck(sectionIdx) {
    const activities = document.querySelectorAll(`.import-activity-checkbox[data-section="${sectionIdx}"]`);
    const sectionCb = document.querySelector(`.import-section-checkbox[data-section="${sectionIdx}"]`);
    if (sectionCb && activities.length > 0) {
        const allChecked = Array.from(activities).every(cb => cb.checked);
        const someChecked = Array.from(activities).some(cb => cb.checked);
        sectionCb.checked = allChecked;
        sectionCb.indeterminate = someChecked && !allChecked;
    }
    updateImportSelectAll();
    updateImportSummary();
}

function updateImportSelectAll() {
    const allSections = document.querySelectorAll('.import-section-checkbox');
    const selectAllCb = document.getElementById('importSelectAll');
    if (selectAllCb && allSections.length > 0) {
        const allChecked = Array.from(allSections).every(cb => cb.checked);
        const someChecked = Array.from(allSections).some(cb => cb.checked || cb.indeterminate);
        selectAllCb.checked = allChecked;
        selectAllCb.indeterminate = someChecked && !allChecked;
    }
}

function updateImportSummary() {
    const checkedActivities = document.querySelectorAll('.import-activity-checkbox:checked').length;
    const summaryEl = document.getElementById('importSummaryCount');
    if (summaryEl) {
        summaryEl.textContent = checkedActivities;
    }
}

// ==================== CONFIRMATION IMPORT ====================
function confirmImportSelection() {
    if (!importedCourseData) return;
    
    let importedCount = 0;
    
    importedCourseData.sections.forEach((section, sIdx) => {
        const sectionCb = document.querySelector(`.import-section-checkbox[data-section="${sIdx}"]`);
        if (!sectionCb || (!sectionCb.checked && !sectionCb.indeterminate)) return;
        
        // Créer une copie de la section
        const newSection = {
            id: generateId(),
            name: section.name || 'Section importée',
            summary: section.summary || '',
            visible: section.visible !== undefined ? section.visible : true,
            activities: []
        };
        
        // Ajouter les activités sélectionnées
        (section.activities || []).forEach((activity, aIdx) => {
            const actCb = document.querySelector(`.import-activity-checkbox[data-section="${sIdx}"][data-activity="${aIdx}"]`);
            if (actCb && actCb.checked) {
                const newAct = {
                    id: generateId(),
                    type: activity.type || 'h5pactivity',
                    h5pType: activity.h5pType || 'unknown',
                    name: activity.name || 'Activité importée',
                    visible: activity.visible !== undefined ? activity.visible : true,
                    content: JSON.parse(JSON.stringify(activity.content || {})) // Deep copy
                };
                // Copier les champs spécifiques mapmodules
                const isMapAct = activity.type === 'mapmodules' || activity.mapPath !== undefined || activity.iconset !== undefined;
                if (isMapAct) {
                    newAct.type = 'mapmodules';
                    newAct.mapPath = activity.mapPath || '';
                    newAct.mapImage = activity.mapImage || null;
                    newAct.descriptionHeader = activity.descriptionHeader || '';
                    newAct.descriptionFooter = activity.descriptionFooter || '';
                    newAct.iconset = activity.iconset || 4;
                    newAct.buttonWidth = activity.buttonWidth || 50;
                }
                // Copier les champs spécifiques assign (travail à déposer)
                if (activity.type === 'assign') {
                    newAct.type = 'assign';
                    newAct.files = (activity.files || []).map(f => ({
                        fileUrl: f.fileUrl || null,
                        fileName: f.fileName || null
                    }));
                    // Rétrocompatibilité mono-fichier (anciens parcours sans tableau files)
                    if (newAct.files.length === 0 && activity.fileUrl && activity.fileName) {
                        newAct.files = [{ fileUrl: activity.fileUrl, fileName: activity.fileName }];
                    }
                    newAct.intro = activity.intro || '';
                }
                // Copier les champs spécifiques resource (fichiers à distribuer)
                if (activity.type === 'resource') {
                    newAct.type = 'resource';
                    newAct.files = (activity.files || []).map(f => ({
                        fileUrl: f.fileUrl || null,
                        fileName: f.fileName || null
                    }));
                    newAct.intro = activity.intro || '';
                }
                // Copier les champs spécifiques quiz (Glisser-Déposer)
                if (activity.type === 'quiz') {
                    newAct.type = 'quiz';
                    newAct.quizType = activity.quizType || '';
                    newAct.content = JSON.parse(JSON.stringify(activity.content || {}));
                }
                newSection.activities.push(newAct);
                importedCount++;
            }
        });
        
        // Ajouter la section seulement si elle a des activités ou si la section entière est cochée
        if (newSection.activities.length > 0 || sectionCb.checked) {
            courseData.sections.push(newSection);
        }
    });
    
    closeModal('importModal');
    importedCourseData = null;
    
    renderTree();
    showStructureView();
    onCourseModified();
    
    // Invalider le cache des miniatures pour forcer la regénération
    if (typeof cpInvalidateAllThumbs === 'function') cpInvalidateAllThumbs();
    
    if (typeof calculateCourseSize === 'function') {
        calculateCourseSize();
    }
    
    showToast(`${importedCount} activité${importedCount > 1 ? 's' : ''} importée${importedCount > 1 ? 's' : ''}`, 'success');
}

// ==================== TEMPLATES ====================

var _templateMenuEl = null;

function openTemplateMenu(btn) {
    // Fermer le menu existant
    if (_templateMenuEl) { _templateMenuEl.remove(); _templateMenuEl = null; return; }
    
    var menu = document.createElement('div');
    menu.className = 'template-menu';
    menu.innerHTML = '<div style="padding: 10px 14px; color: var(--gray-400); font-size: 0.8rem;">Chargement...</div>';
    
    // Positionner AU-DESSUS du bouton
    var rect = btn.getBoundingClientRect();
    // Fond/texte via var(--x, fallback) : dark.css ne peut pas surcharger un style inline.
    // Avec `background:white` en dur, le texte (clair, hérité de body en mode sombre) devenait
    // illisible sur fond blanc (contraste mesuré 1.23).
    menu.style.cssText = 'position:fixed; left:' + rect.left + 'px; bottom:' + (window.innerHeight - rect.top) + 'px; ' +
        'background:var(--bg-secondary, white); color:var(--text-primary, inherit); ' +
        'border:1px solid var(--gray-200); border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.15); ' +
        'z-index:1000; min-width:220px; max-width:260px; max-height:300px; overflow-y:auto;';
    document.body.appendChild(menu);
    _templateMenuEl = menu;
    
    // Fermer au clic extérieur
    setTimeout(function() {
        document.addEventListener('click', _closeTemplateMenu, { once: true });
    }, 10);
    
    // Charger la liste
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_templates' })
    })
    .then(r => r.json())
    .then(data => {
        if (!_templateMenuEl) return;
        if (!data.success || !data.templates || data.templates.length === 0) {
            menu.innerHTML = '<div style="padding: 10px 14px; color: var(--gray-400); font-size: 0.85rem;">Aucun template disponible</div>';
            return;
        }
        menu.innerHTML = '';
        data.templates.forEach(function(tpl) {
            var item = document.createElement('div');
            // --dk-border / --bg-tertiary ne sont définis QUE par dark.css : en thème clair les
            // fallbacks redonnent EXACTEMENT les couleurs d'origine (#f0f0f0 / #f5f5f5).
            // (Ne pas utiliser --gray-* ici : elles existent aussi en clair et décaleraient la teinte.)
            item.style.cssText = 'padding:8px 14px; cursor:pointer; font-size:0.85rem; display:flex; align-items:center; gap:8px; border-bottom:1px solid var(--dk-border, #f0f0f0);';
            item.innerHTML = '<span>📋</span><span>' + escapeHtml(tpl.name) + '</span>';
            item.onmouseover = function() { this.style.background = 'var(--bg-tertiary, #f5f5f5)'; };
            item.onmouseout = function() { this.style.background = ''; };
            item.onclick = function(e) {
                e.stopPropagation();
                _closeTemplateMenu();
                loadTemplate(tpl.file);
            };
            menu.appendChild(item);
        });
    })
    .catch(function() {
        if (_templateMenuEl) menu.innerHTML = '<div style="padding: 10px 14px; color: var(--danger-text, #e53935); font-size: 0.85rem;">Erreur de chargement</div>';
    });
}

function _closeTemplateMenu() {
    if (_templateMenuEl) { _templateMenuEl.remove(); _templateMenuEl = null; }
    document.removeEventListener('click', _closeTemplateMenu);
}

function loadTemplate(filename) {
    showToast('Chargement du template...', 'info');
    
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'load_template',
            file: filename,
            sessionId: (typeof getEditorSessionId === 'function') ? getEditorSessionId() : ''
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { showToast('Erreur : ' + data.error, 'error'); return; }
        
        // Vérifier si le cours est complètement vide
        var courseIsEmpty = !courseData.sections || courseData.sections.length === 0 ||
            (courseData.sections.length === 1 && (!courseData.sections[0].activities || courseData.sections[0].activities.length === 0));

        var count = 0;

        if (courseIsEmpty && data.sections && data.sections.length > 0) {
            // ===== COURS VIDE : CHARGER LE TEMPLATE COMPLET =====
            var newSections = data.sections.map(function(section) {
                var newActivities = (section.activities || []).map(function(act) {
                    var actCopy = {};
                    for (var key in act) {
                        if (act.hasOwnProperty(key)) actCopy[key] = act[key];
                    }
                    actCopy.id = generateId();
                    if (actCopy.visible === undefined) actCopy.visible = true;
                    count++;
                    return actCopy;
                });

                return {
                    id: generateId(),
                    name: section.name || 'Section',
                    summary: section.summary || '',
                    visible: section.visible !== undefined ? section.visible : true,
                    activities: newActivities
                };
            });

            if (courseData.sections.length === 0) {
                courseData.sections = newSections;
            } else {
                courseData.sections.push(...newSections);
            }
        } else {
            // ===== COURS NON-VIDE OU TEMPLATE SANS SECTIONS =====
            var templateSections = [];
            if (data.sections && data.sections.length > 0) {
                if (data.sections.length === 1) {
                    // Un seul section : prendre ses activités
                    templateSections = [data.sections[0]];
                } else {
                    // Plusieurs sections : inclure toutes (section[0] aura ses activités ajoutées au lieu cible)
                    templateSections = data.sections;
                }
            } else if (data.activities && data.activities.length > 0) {
                templateSections = [{ activities: data.activities }];
            }

            var totalActivities = 0;
            templateSections.forEach(function(s) { totalActivities += (s.activities || []).length; });
            if (totalActivities === 0) {
                showToast('Template vide', 'error');
                return;
            }

            // Préparer les activités (nouveaux IDs)
            templateSections.forEach(function(section) {
                (section.activities || []).forEach(function(act) {
                    act.id = generateId();
                    if (act.visible === undefined) act.visible = true;
                });
            });

            count = totalActivities;

            // Trouver la section et la position d'insertion
            var targetSection = null;
            var targetSectionIdx = -1;
            var insertAfterIdx = -1; // -1 = ajouter à la fin

            if (typeof selectedActivity !== 'undefined' && selectedActivity &&
                typeof selectedSection !== 'undefined' && selectedSection) {
                // Un parcours est sélectionné/ouvert : insérer juste après
                targetSection = courseData.sections.find(s => s.id === selectedSection) || null;
                if (targetSection) {
                    targetSectionIdx = courseData.sections.indexOf(targetSection);
                    insertAfterIdx = (targetSection.activities || []).findIndex(a => a.id === selectedActivity);
                }
            }

            if (!targetSection) {
                // Aucune sélection : ajouter à la fin de la dernière section
                if (courseData.sections.length > 0) {
                    targetSectionIdx = courseData.sections.length - 1;
                    targetSection = courseData.sections[targetSectionIdx];
                } else {
                    targetSection = { id: generateId(), name: 'Section 1', summary: '', visible: true, activities: [] };
                    courseData.sections.push(targetSection);
                    targetSectionIdx = 0;
                }
            }

            if (!targetSection.activities) targetSection.activities = [];

            // Insérer les activités de la première section template dans la section cible
            var firstActivities = templateSections.length > 0 ? (templateSections[0].activities || []) : [];
            if (insertAfterIdx >= 0) {
                targetSection.activities.splice(insertAfterIdx + 1, 0, ...firstActivities);
            } else {
                targetSection.activities.push(...firstActivities);
            }

            // Créer de nouvelles sections pour les sections suivantes du template
            if (templateSections.length > 1) {
                var newSections = templateSections.slice(1).map(function(tplSection) {
                    return {
                        id: generateId(),
                        name: tplSection.name || 'Section',
                        summary: tplSection.summary || '',
                        visible: tplSection.visible !== undefined ? tplSection.visible : true,
                        activities: tplSection.activities || []
                    };
                });
                courseData.sections.splice(targetSectionIdx + 1, 0, ...newSections);
            }
        }
        
        renderTree();
        renderStructureView();
        onCourseModified();
        if (typeof cpInvalidateAllThumbs === 'function') cpInvalidateAllThumbs();
        showToast(count + ' activité' + (count > 1 ? 's' : '') + ' ajoutée' + (count > 1 ? 's' : ''), 'success');
    })
    .catch(function(err) {
        console.error(err);
        showToast('Erreur réseau', 'error');
    });
}
