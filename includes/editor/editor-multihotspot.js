// ==================== ÉDITEUR TROUVER LES ZONES (H5P.ImageMultipleHotspotQuestion) ====================
// Une image de fond et des zones à repérer au clic. x/y/width/height sont des
// POURCENTAGES de l'image, x/y désignant le COIN HAUT-GAUCHE (format Éléa).
// On clique sur l'image pour poser une zone, on la déplace au glisser, on la
// redimensionne par la poignée en bas à droite.

function fmhGetContent(activity) {
    if (!activity.content) activity.content = getActivityDefaultContent('ImageMultipleHotspotQuestion');
    const c = activity.content;
    if (!c.imageMultipleHotspotQuestion) {
        c.imageMultipleHotspotQuestion = { backgroundImageSettings: {}, hotspotSettings: { hotspot: [] } };
    }
    const q = c.imageMultipleHotspotQuestion;
    if (!q.backgroundImageSettings) q.backgroundImageSettings = {};
    if (!q.backgroundImageSettings.questionTitle) q.backgroundImageSettings.questionTitle = 'Image hotspot question';
    if (!q.hotspotSettings) q.hotspotSettings = { hotspot: [] };
    if (!Array.isArray(q.hotspotSettings.hotspot)) q.hotspotSettings.hotspot = [];
    return q;
}

function fmhZones(activity) { return fmhGetContent(activity).hotspotSettings.hotspot; }

function renderMultiHotspotEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';

    const q = fmhGetContent(activity);
    const fond = q.backgroundImageSettings.backgroundImage;
    const zones = q.hotspotSettings.hotspot;
    const aid = activity.id;
    const nbJustes = zones.filter(z => (z.userSettings || {}).correct !== false).length;

    const zonesHtml = zones.map((z, i) => {
        const cs = z.computedSettings || {};
        const juste = (z.userSettings || {}).correct !== false;
        return `
        <span class="fmh-zone${juste ? '' : ' fausse'}${cs.figure === 'rectangle' ? ' rect' : ''}"
              data-idx="${i}"
              style="left:${cs.x}%;top:${cs.y}%;width:${cs.width}%;height:${cs.height}%;"
              onmousedown="fmhStartDrag(event, '${aid}', ${i})"
              title="${juste ? 'Zone à trouver' : 'Zone piège'} — glisser pour déplacer">
            <span class="fmh-zone-num">${i + 1}</span>
            <span class="fmh-zone-poignee" onmousedown="fmhStartResize(event, '${aid}', ${i})"></span>
        </span>`;
    }).join('');

    const listeHtml = zones.map((z, i) => {
        const us = z.userSettings || {};
        const cs = z.computedSettings || {};
        const juste = us.correct !== false;
        return `
        <div class="fmh-item${juste ? '' : ' fausse'}">
            <span class="fmh-item-num">${i + 1}</span>
            <div class="fmh-item-champs">
                <label class="cp-checkbox-label" style="margin:0;">
                    <input type="checkbox" ${juste ? 'checked' : ''}
                           onchange="fmhSetCorrect('${aid}', ${i}, this.checked)"> Bonne zone
                </label>
                <input type="text" class="cp-prop-input" value="${escapeHtml(us.feedbackText || '')}"
                       placeholder="Message affiché au clic (facultatif)"
                       onchange="fmhSetFeedback('${aid}', ${i}, this.value)">
            </div>
            <select class="cp-prop-input fmh-item-forme" onchange="fmhSetFigure('${aid}', ${i}, this.value)">
                <option value="circle" ${cs.figure !== 'rectangle' ? 'selected' : ''}>Cercle</option>
                <option value="rectangle" ${cs.figure === 'rectangle' ? 'selected' : ''}>Rectangle</option>
            </select>
            <button class="tree-action-btn" onclick="fmhDeleteZone('${aid}', ${i})" title="Supprimer la zone">🗑️</button>
        </div>`;
    }).join('');

    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml('🔎', activity.name, sectionId)}
                <p class="section-preview-desc">Cliquez sur l'image pour poser une zone à trouver</p>
            </div>
            <div style="padding: 1.5rem;">
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Image de fond</label>
                    ${fond && fond.path ? `
                    <div class="fmh-canvas-wrap">
                        <div class="fmh-canvas" id="fmhCanvas_${aid}" onclick="fmhAddZone(event, '${aid}')">
                            <img src="${fond.path}" alt="" draggable="false" id="fmhImg_${aid}">
                            ${zonesHtml}
                        </div>
                    </div>
                    <div class="fmh-actions">
                        <label class="btn btn-secondary" style="cursor:pointer; padding:0.35rem 0.8rem; font-size:0.8rem;">
                            🖼️ Remplacer l'image
                            <input type="file" accept="image/*" style="display:none;" onchange="fmhUploadFond(this, '${aid}')">
                        </label>
                        <span class="fmh-hint">${zones.length} zone${zones.length > 1 ? 's' : ''} · ${nbJustes} à trouver</span>
                    </div>` : `
                    <label class="fmh-vide">
                        <span>🖼️ Choisir l'image sur laquelle les élèves cliqueront</span>
                        <input type="file" accept="image/*" style="display:none;" onchange="fmhUploadFond(this, '${aid}')">
                    </label>`}
                </div>

                ${zones.length ? `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Zones</label>
                    <div class="fmh-liste">${listeHtml}</div>
                </div>` : ''}
            </div>
        </div>`;
}

// ==================== IMAGE DE FOND ====================
function fmhUploadFond(input, activityId) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (typeof canAddImage === 'function' && !canAddImage(file)) { input.value = ''; return; }
    const activity = findActivityById(activityId);
    if (!activity) return;
    showToast('Upload en cours...', 'info');

    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    formData.append('session_id', typeof getEditorSessionId === 'function' ? getEditorSessionId() : '');

    fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Erreur');
            const img = new Image();
            img.onload = () => {
                fmhGetContent(activity).backgroundImageSettings.backgroundImage = {
                    path: data.url,
                    mime: file.type || 'image/png',
                    copyright: { license: 'U' },
                    width: img.naturalWidth,
                    height: img.naturalHeight
                };
                onCourseModified();
                renderMultiHotspotEditor(activity);
                showToast('Image de fond ajoutée', 'success');
            };
            img.onerror = () => showToast('Image illisible', 'error');
            img.src = data.url;
        })
        .catch(err => { console.error(err); showToast('Erreur : ' + err.message, 'error'); });
    input.value = '';
}

// ==================== ZONES ====================
// Taille par défaut d'une zone, en % — calée sur les exports Éléa (≈7 % de large)
const FMH_TAILLE_DEFAUT = 7;

function fmhAddZone(event, activityId) {
    // Un clic sur une zone existante (ou sa poignée) ne doit pas en créer une nouvelle
    if (event.target.closest('.fmh-zone')) return;
    const activity = findActivityById(activityId);
    if (!activity) return;
    const img = document.getElementById('fmhImg_' + activityId);
    if (!img) return;

    const r = img.getBoundingClientRect();
    const px = ((event.clientX - r.left) / r.width) * 100;
    const py = ((event.clientY - r.top) / r.height) * 100;
    // L'image est plus large que haute : à surface visuelle égale, la hauteur en %
    // doit être plus grande que la largeur en %.
    const w = FMH_TAILLE_DEFAUT;
    const h = FMH_TAILLE_DEFAUT * (r.width / r.height);

    fmhZones(activity).push({
        userSettings: { correct: true, feedbackText: '' },
        computedSettings: {
            x: Math.max(0, Math.min(100 - w, px - w / 2)),
            y: Math.max(0, Math.min(100 - h, py - h / 2)),
            width: w, height: h, figure: 'circle'
        }
    });
    onCourseModified();
    renderMultiHotspotEditor(activity);
}

function fmhDeleteZone(activityId, idx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    fmhZones(activity).splice(idx, 1);
    onCourseModified();
    renderMultiHotspotEditor(activity);
}

function fmhSetCorrect(activityId, idx, correct) {
    const activity = findActivityById(activityId);
    const z = activity && fmhZones(activity)[idx];
    if (!z) return;
    if (!z.userSettings) z.userSettings = {};
    z.userSettings.correct = !!correct;
    onCourseModified();
    renderMultiHotspotEditor(activity);
}

function fmhSetFeedback(activityId, idx, texte) {
    const activity = findActivityById(activityId);
    const z = activity && fmhZones(activity)[idx];
    if (!z) return;
    if (!z.userSettings) z.userSettings = {};
    z.userSettings.feedbackText = texte;
    onCourseModified();
}

function fmhSetFigure(activityId, idx, figure) {
    const activity = findActivityById(activityId);
    const z = activity && fmhZones(activity)[idx];
    if (!z) return;
    if (!z.computedSettings) z.computedSettings = {};
    z.computedSettings.figure = figure === 'rectangle' ? 'rectangle' : 'circle';
    onCourseModified();
    renderMultiHotspotEditor(activity);
}

// ==================== DÉPLACEMENT / REDIMENSIONNEMENT ====================
let _fmhDrag = null;

function fmhStartDrag(event, activityId, idx) {
    if (event.target.classList.contains('fmh-zone-poignee')) return;
    event.preventDefault();
    event.stopPropagation();
    const activity = findActivityById(activityId);
    const z = activity && fmhZones(activity)[idx];
    const img = document.getElementById('fmhImg_' + activityId);
    if (!z || !img) return;
    const r = img.getBoundingClientRect();
    _fmhDrag = {
        mode: 'move', activity, idx, r,
        dx: ((event.clientX - r.left) / r.width) * 100 - z.computedSettings.x,
        dy: ((event.clientY - r.top) / r.height) * 100 - z.computedSettings.y,
        el: event.currentTarget
    };
    document.addEventListener('mousemove', fmhOnDrag);
    document.addEventListener('mouseup', fmhEndDrag);
}

function fmhStartResize(event, activityId, idx) {
    event.preventDefault();
    event.stopPropagation();
    const activity = findActivityById(activityId);
    const z = activity && fmhZones(activity)[idx];
    const img = document.getElementById('fmhImg_' + activityId);
    if (!z || !img) return;
    _fmhDrag = {
        mode: 'resize', activity, idx, r: img.getBoundingClientRect(),
        el: event.currentTarget.parentElement
    };
    document.addEventListener('mousemove', fmhOnDrag);
    document.addEventListener('mouseup', fmhEndDrag);
}

function fmhOnDrag(event) {
    if (!_fmhDrag) return;
    const { mode, activity, idx, r, el } = _fmhDrag;
    const z = fmhZones(activity)[idx];
    if (!z) return;
    const cs = z.computedSettings;
    const px = ((event.clientX - r.left) / r.width) * 100;
    const py = ((event.clientY - r.top) / r.height) * 100;

    if (mode === 'move') {
        cs.x = Math.max(0, Math.min(100 - cs.width, px - _fmhDrag.dx));
        cs.y = Math.max(0, Math.min(100 - cs.height, py - _fmhDrag.dy));
        el.style.left = cs.x + '%';
        el.style.top = cs.y + '%';
    } else {
        cs.width = Math.max(2, Math.min(100 - cs.x, px - cs.x));
        cs.height = Math.max(2, Math.min(100 - cs.y, py - cs.y));
        el.style.width = cs.width + '%';
        el.style.height = cs.height + '%';
    }
}

function fmhEndDrag() {
    document.removeEventListener('mousemove', fmhOnDrag);
    document.removeEventListener('mouseup', fmhEndDrag);
    if (_fmhDrag) {
        onCourseModified();
        _fmhDrag = null;
    }
}
