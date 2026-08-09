// ==================== ÉDITEUR CARTE À EXPLORER (H5P.GameMap) ====================
// Une carte de fond + des étapes reliées entre elles. Chaque étape porte un contenu
// (texte ou QCM) qui s'ouvre quand l'élève clique dessus. Le format des données est
// celui d'Éléa : telemetry en % (coin haut-gauche), neighbors = INDICES d'étapes.

let gmSelectedStage = 0;
let _gmDrag = null;
let _gmLastActivityId = null;

const GM_STAGE_W = '4.375';
const GM_STAGE_H = '7.814060667441372';

function gmNewStage(label, x, y) {
    return {
        id: (typeof generateUUID === 'function') ? generateUUID() : String(Math.random()).slice(2),
        label: label,
        content: { params: {}, dom: { count: 0 } },
        telemetry: { x: String(x), y: String(y), width: GM_STAGE_W, height: GM_STAGE_H },
        neighbors: [],
        canBeStartStage: false,
        time: {},
        accessRestrictions: { openOnScoreSufficient: false },
        contentType: { library: 'H5P.AdvancedText 1.1', params: { text: '<p>Contenu de l’étape</p>' } },
        specialStageExtraLives: 1,
        specialStageExtraTime: 1
    };
}

function gmGetSteps(activity) {
    if (!activity.content) activity.content = getActivityDefaultContent('GameMap');
    if (!activity.content.gamemapSteps) {
        activity.content.gamemapSteps = { backgroundImageSettings: {}, gamemap: { elements: [] } };
    }
    if (!activity.content.gamemapSteps.gamemap) activity.content.gamemapSteps.gamemap = { elements: [] };
    if (!Array.isArray(activity.content.gamemapSteps.gamemap.elements)) {
        activity.content.gamemapSteps.gamemap.elements = [];
    }
    return activity.content.gamemapSteps.gamemap.elements;
}

function gmGetBackground(activity) {
    return activity.content?.gamemapSteps?.backgroundImageSettings?.backgroundImage || null;
}

// Type de contenu d'une étape, déduit de sa bibliothèque
function gmContentKind(step) {
    const lib = step.contentType?.library || '';
    if (lib.indexOf('H5P.MultiChoice') === 0) return 'multichoice';
    if (lib.indexOf('H5P.AdvancedText') === 0 || lib.indexOf('H5P.Text') === 0) return 'text';
    return 'none';
}

function renderGameMapEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';

    const steps = gmGetSteps(activity);
    const bg = gmGetBackground(activity);
    // Repartir de la première étape quand on change de carte
    if (_gmLastActivityId !== activity.id) {
        _gmLastActivityId = activity.id;
        gmSelectedStage = 0;
    }
    if (gmSelectedStage >= steps.length) gmSelectedStage = Math.max(0, steps.length - 1);

    // Pleine largeur, comme l'éditeur glisser-déposer image : la carte a besoin de place
    const canvasWrapper = document.getElementById('canvasWrapper');
    if (canvasWrapper) canvasWrapper.classList.add('cp-mode');

    content.innerHTML = `
        <div class="section-preview gm-card">
            <div class="section-preview-header">
                ${editorHeaderHtml('🧭', activity.name, sectionId)}
                <p class="section-preview-desc">Carte à explorer : l'élève avance d'étape en étape</p>
            </div>
            <div class="gm-editor">
                <div class="gm-editor-main">
                    <div class="gm-toolbar">
                        <button class="btn btn-primary" onclick="gmAddStage('${activity.id}')"
                                style="padding: 0.35rem 0.8rem; font-size: 0.8rem;" ${bg ? '' : 'disabled'}>+ Étape</button>
                        <label class="btn btn-secondary" style="cursor: pointer; padding: 0.35rem 0.8rem; font-size: 0.8rem;">
                            ${bg ? '🔄 Changer la carte' : '🗺️ Choisir la carte'}
                            <input type="file" accept="image/*" style="display: none;" onchange="gmUploadBackground(this, '${activity.id}')">
                        </label>
                        <span class="gm-toolbar-hint">Glissez une étape pour la déplacer • cliquez pour la modifier</span>
                    </div>
                    ${bg ? gmRenderMap(activity, steps, bg) : `
                        <div class="gm-empty">
                            <p style="font-size: 2rem; margin-bottom: 0.5rem;">🗺️</p>
                            <p style="color: var(--gray-600);">Choisissez d'abord l'image de fond de la carte</p>
                        </div>`}
                </div>
                <div class="gm-editor-side">
                    ${steps.length ? gmRenderStageProps(activity, steps, gmSelectedStage) : `
                        <p style="color: var(--gray-400); font-size: 0.8rem; padding: 1rem; text-align: center;">
                            Aucune étape pour l'instant.
                        </p>`}
                </div>
            </div>
        </div>`;
}

function gmRenderMap(activity, steps, bg) {
    // Repère gradué en % de la LARGEUR sur les deux axes, comme dans le lecteur :
    // sinon les pointillés ronds seraient étirés en ellipses.
    const ratio = (bg.width > 0 && bg.height > 0) ? (bg.height / bg.width) : (9 / 16);

    // Chemins : reliés au CENTRE des pastilles, une seule ligne par paire
    let lines = '';
    steps.forEach((step, i) => {
        (step.neighbors || []).forEach(n => {
            const ni = parseInt(n, 10);
            if (!(ni > i) || !steps[ni]) return;
            const a = gmCenter(step), b = gmCenter(steps[ni]);
            lines += `<line x1="${a.x}" y1="${a.y * ratio}" x2="${b.x}" y2="${b.y * ratio}"
                            stroke="rgba(255,255,255,0.9)" stroke-width="0.6"
                            stroke-dasharray="0.01,1.32" stroke-linecap="round"/>`;
        });
    });

    const markers = steps.map((step, i) => {
        const t = step.telemetry || {};
        const isStart = !!step.canBeStartStage;
        const isFinish = step.specialStageType === 'finish';
        return `<button type="button"
                        class="gm-stage${i === gmSelectedStage ? ' selected' : ''}${isStart ? ' start' : ''}${isFinish ? ' finish' : ''}"
                        style="left:${parseFloat(t.x || 50)}%; top:${parseFloat(t.y || 50)}%; width:${parseFloat(t.width || GM_STAGE_W)}%; height:${parseFloat(t.height || GM_STAGE_H)}%;"
                        data-idx="${i}"
                        title="${escapeHtml(step.label || ('Étape ' + (i + 1)))}"
                        onmousedown="gmStartDragStage(event, '${activity.id}', ${i})">
                    <span class="gm-stage-num">${i + 1}</span>
                    <span class="gm-stage-label">${escapeHtml(step.label || ('Étape ' + (i + 1)))}</span>
                </button>`;
    }).join('');

    return `
        <div class="gm-map" id="gmMap">
            <img src="${bg.path}" class="gm-map-bg" alt="Carte">
            <svg class="gm-map-paths" viewBox="0 0 100 ${100 * ratio}" preserveAspectRatio="none">${lines}</svg>
            <div class="gm-map-stages">${markers}</div>
        </div>`;
}

function gmCenter(step) {
    const t = step.telemetry || {};
    return {
        x: parseFloat(t.x || 50) + parseFloat(t.width || GM_STAGE_W) / 2,
        y: parseFloat(t.y || 50) + parseFloat(t.height || GM_STAGE_H) / 2
    };
}

function gmRenderStageProps(activity, steps, idx) {
    const step = steps[idx];
    if (!step) return '';
    const kind = gmContentKind(step);
    const aid = activity.id;

    const neighborsHtml = steps.map((other, oi) => {
        if (oi === idx) return '';
        const linked = (step.neighbors || []).map(n => parseInt(n, 10)).indexOf(oi) !== -1;
        return `<label class="gm-neighbor">
                    <input type="checkbox" ${linked ? 'checked' : ''}
                           onchange="gmToggleNeighbor('${aid}', ${idx}, ${oi}, this.checked)">
                    <span>${oi + 1}. ${escapeHtml(other.label || ('Étape ' + (oi + 1)))}</span>
                </label>`;
    }).join('');

    let contentHtml = '';
    if (kind === 'text') {
        contentHtml = `
            <div class="rich-text-toolbar" style="margin-bottom: 0.25rem;">
                <button class="rich-text-btn" onclick="gmTextExecCmd('bold')" title="Gras"><b>G</b></button>
                <button class="rich-text-btn" onclick="gmTextExecCmd('italic')" title="Italique"><i>I</i></button>
                <button class="rich-text-btn" onclick="gmTextExecCmd('underline')" title="Souligné"><u>S</u></button>
                <span class="rich-text-separator"></span>
                <button class="rich-text-btn" onclick="gmTextExecCmd('justifyLeft')" title="Aligner à gauche">⬅</button>
                <button class="rich-text-btn" onclick="gmTextExecCmd('justifyCenter')" title="Centrer">↔</button>
                <button class="rich-text-btn" onclick="gmTextExecCmd('justifyRight')" title="Aligner à droite">➡</button>
            </div>
            <div class="rich-text-editor" contenteditable="true" id="gmTextEditor"
                 style="min-height: 90px; font-size: 0.85rem;"
                 onblur="gmUpdateText('${aid}', ${idx})">${step.contentType?.params?.text || ''}</div>`;
    } else if (kind === 'multichoice') {
        const p = step.contentType?.params || {};
        const answers = p.answers || [];
        contentHtml = `
            <label class="cp-prop-label">Question</label>
            <input type="text" class="cp-prop-input" value="${escapeHtml((p.question || '').replace(/<[^>]*>/g, ''))}"
                   onchange="gmUpdateMcQuestion('${aid}', ${idx}, this.value)" style="margin-bottom: 0.5rem;">
            <label class="cp-prop-label">Réponses (cochez les bonnes)</label>
            ${answers.map((a, ai) => `
                <div class="gm-answer">
                    <input type="checkbox" ${a.correct ? 'checked' : ''}
                           onchange="gmToggleMcCorrect('${aid}', ${idx}, ${ai}, this.checked)" title="Bonne réponse">
                    <input type="text" class="cp-prop-input" value="${escapeHtml((a.text || '').replace(/<[^>]*>/g, ''))}"
                           onchange="gmUpdateMcAnswer('${aid}', ${idx}, ${ai}, this.value)">
                    <button class="tree-action-btn" onclick="gmDeleteMcAnswer('${aid}', ${idx}, ${ai})"
                            title="Supprimer" ${answers.length <= 2 ? 'disabled' : ''}>🗑️</button>
                </div>`).join('')}
            <button class="btn btn-secondary" onclick="gmAddMcAnswer('${aid}', ${idx})"
                    style="padding: 0.25rem 0.6rem; font-size: 0.75rem; margin-top: 0.35rem;">+ Réponse</button>`;
    } else {
        contentHtml = `<p style="font-size: 0.75rem; color: var(--gray-500); margin: 0;">
            Étape sans contenu : elle sert de case d'arrivée.
        </p>`;
    }

    return `
        <div class="gm-props">
            <div class="gm-props-head">
                <span>Étape ${idx + 1}</span>
                <button class="tree-action-btn" onclick="gmDeleteStage('${aid}', ${idx})" title="Supprimer l'étape">🗑️</button>
            </div>
            <div class="cp-prop-group">
                <label class="cp-prop-label">Libellé</label>
                <input type="text" class="cp-prop-input" value="${escapeHtml(step.label || '')}"
                       onchange="gmUpdateStageLabel('${aid}', ${idx}, this.value)">
            </div>
            <div class="cp-prop-group">
                <label class="cp-checkbox-label">
                    <input type="radio" name="gmStartStage" ${step.canBeStartStage ? 'checked' : ''}
                           onchange="gmSetStartStage('${aid}', ${idx})">
                    Point de départ
                </label>
                <label class="cp-checkbox-label">
                    <input type="checkbox" ${step.specialStageType === 'finish' ? 'checked' : ''}
                           onchange="gmToggleFinish('${aid}', ${idx}, this.checked)">
                    Case d'arrivée
                </label>
            </div>
            <div class="cp-prop-group">
                <label class="cp-prop-label">Contenu</label>
                <select class="cp-prop-input" onchange="gmSetContentKind('${aid}', ${idx}, this.value)"
                        style="margin-bottom: 0.5rem;">
                    <option value="text" ${kind === 'text' ? 'selected' : ''}>Texte</option>
                    <option value="multichoice" ${kind === 'multichoice' ? 'selected' : ''}>QCM</option>
                    <option value="none" ${kind === 'none' ? 'selected' : ''}>Aucun</option>
                </select>
                ${contentHtml}
            </div>
            <div class="cp-prop-group">
                <label class="cp-prop-label">Reliée à</label>
                ${neighborsHtml || '<p style="font-size:0.75rem;color:var(--gray-400);margin:0;">Ajoutez une deuxième étape.</p>'}
            </div>
        </div>`;
}

// ==================== ACTIONS ====================

function gmUploadBackground(input, activityId) {
    const file = input.files[0];
    if (!file) return;
    if (typeof canAddImage === 'function' && !canAddImage(file)) { input.value = ''; return; }

    const formData = new FormData();
    formData.append('file', file);
    if (typeof getEditorSessionId === 'function') formData.append('session_id', getEditorSessionId());
    showToast('Upload en cours...', 'info');

    fetch('api/editor_api.php?action=upload_file', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.error) { showToast('Erreur: ' + data.error, 'error'); return; }
        const activity = findActivityById(activityId);
        if (!activity) return;
        const img = new Image();
        img.onload = function() {
            gmGetSteps(activity); // garantit la structure
            activity.content.gamemapSteps.backgroundImageSettings = {
                backgroundImage: {
                    path: data.url,
                    mime: file.type || 'image/jpeg',
                    copyright: { license: 'U' },
                    width: img.naturalWidth,
                    height: img.naturalHeight
                }
            };
            onCourseModified();
            renderGameMapEditor(activity);
            showToast('Carte chargée', 'success');
        };
        img.onerror = function() { showToast('Image illisible', 'error'); };
        img.src = data.url;
    })
    .catch(err => { showToast("Erreur d'upload", 'error'); console.error(err); });
}

function gmAddStage(activityId) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const steps = gmGetSteps(activity);

    // Placer la nouvelle étape à droite de la précédente, en restant sur la carte
    const prev = steps[steps.length - 1];
    let x = 20, y = 40;
    if (prev) {
        x = Math.min(90, parseFloat(prev.telemetry.x) + 15);
        y = Math.min(85, Math.max(5, parseFloat(prev.telemetry.y) + (steps.length % 2 ? 12 : -12)));
    }
    const step = gmNewStage('Étape ' + (steps.length + 1), x.toFixed(2), y.toFixed(2));

    // Première étape = point de départ ; les suivantes se relient à la précédente
    if (steps.length === 0) {
        step.canBeStartStage = true;
    } else {
        const prevIdx = steps.length - 1;
        step.neighbors = [String(prevIdx)];
        steps[prevIdx].neighbors = (steps[prevIdx].neighbors || []).concat([String(steps.length)]);
    }
    steps.push(step);
    gmSelectedStage = steps.length - 1;
    onCourseModified();
    renderGameMapEditor(activity);
}

function gmDeleteStage(activityId, idx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const steps = gmGetSteps(activity);
    if (!steps[idx]) return;
    const wasStart = !!steps[idx].canBeStartStage;

    steps.splice(idx, 1);
    // Les voisins sont des indices : retirer l'étape supprimée et décaler les suivants
    steps.forEach(s => {
        s.neighbors = (s.neighbors || [])
            .map(n => parseInt(n, 10))
            .filter(n => n !== idx)
            .map(n => String(n > idx ? n - 1 : n));
    });
    if (wasStart && steps.length) steps[0].canBeStartStage = true;

    gmSelectedStage = Math.max(0, Math.min(gmSelectedStage, steps.length - 1));
    onCourseModified();
    renderGameMapEditor(activity);
}

function gmUpdateStageLabel(activityId, idx, value) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    if (!steps[idx]) return;
    steps[idx].label = value;
    onCourseModified();
    renderGameMapEditor(activity);
}

function gmSetStartStage(activityId, idx) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    steps.forEach((s, i) => { s.canBeStartStage = (i === idx); });
    onCourseModified();
    renderGameMapEditor(activity);
}

function gmToggleFinish(activityId, idx, checked) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    if (!steps[idx]) return;
    if (checked) {
        steps[idx].specialStageType = 'finish';
        steps[idx].contentType = { params: {} };
    } else {
        delete steps[idx].specialStageType;
        steps[idx].contentType = { library: 'H5P.AdvancedText 1.1', params: { text: '<p>Contenu de l’étape</p>' } };
    }
    onCourseModified();
    renderGameMapEditor(activity);
}

// Les liaisons sont réciproques dans Éléa : les deux étapes se citent mutuellement
function gmToggleNeighbor(activityId, idx, otherIdx, checked) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    if (!steps[idx] || !steps[otherIdx]) return;

    function setLink(from, to, on) {
        const list = (steps[from].neighbors || []).map(n => parseInt(n, 10)).filter(n => n !== to);
        if (on) list.push(to);
        list.sort((a, b) => a - b);
        steps[from].neighbors = list.map(String);
    }
    setLink(idx, otherIdx, checked);
    setLink(otherIdx, idx, checked);

    onCourseModified();
    renderGameMapEditor(activity);
}

function gmSetContentKind(activityId, idx, kind) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    if (!steps[idx]) return;

    if (kind === 'text') {
        steps[idx].contentType = { library: 'H5P.AdvancedText 1.1', params: { text: '<p>Contenu de l’étape</p>' } };
    } else if (kind === 'multichoice') {
        steps[idx].contentType = { library: 'H5P.MultiChoice 1.16', params: gmDefaultMcParams() };
    } else {
        steps[idx].contentType = { params: {} };
    }
    onCourseModified();
    renderGameMapEditor(activity);
}

function gmDefaultMcParams() {
    return {
        media: { disableImageZooming: false, type: { params: {} } },
        question: '<p>Nouvelle question ?</p>',
        answers: [
            { correct: true, text: '<div>Réponse A</div>', tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' } },
            { correct: false, text: '<div>Réponse B</div>', tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' } }
        ],
        overallFeedback: [{ from: 0, to: 100 }],
        behaviour: {
            enableRetry: true, enableSolutionsButton: true, enableCheckButton: true,
            type: 'auto', singlePoint: false, randomAnswers: true,
            showSolutionsRequiresInput: true, confirmCheckDialog: false,
            confirmRetryDialog: false, autoCheck: false, passPercentage: 100, showScorePoints: true
        },
        UI: {
            checkAnswerButton: 'Vérifier', submitAnswerButton: 'Envoyer',
            showSolutionButton: 'Afficher la solution', tryAgainButton: 'Recommencer',
            tipsLabel: 'Afficher les indices', scoreBarLabel: 'You got :num out of :total points',
            tipAvailable: 'Indice disponible', feedbackAvailable: 'Retour disponible',
            readFeedback: 'Lire le commentaire', wrongAnswer: 'Mauvaise réponse',
            correctAnswer: 'Bonne réponse', shouldCheck: 'Il fallait cocher ici',
            shouldNotCheck: 'Il ne fallait pas cocher ici !',
            noInput: 'Veuillez répondre avant de consulter la solution'
        }
    };
}

function gmTextExecCmd(command) {
    const editor = document.getElementById('gmTextEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
}

function gmUpdateText(activityId, idx) {
    const editor = document.getElementById('gmTextEditor');
    if (!editor) return;
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    if (!steps[idx]) return;
    steps[idx].contentType.params.text = editor.innerHTML;
    onCourseModified();
}

function gmUpdateMcQuestion(activityId, idx, value) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    if (!steps[idx]) return;
    steps[idx].contentType.params.question = '<p>' + escapeHtml(value) + '</p>';
    onCourseModified();
}

function gmUpdateMcAnswer(activityId, idx, ai, value) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    const answers = steps[idx]?.contentType?.params?.answers;
    if (!answers || !answers[ai]) return;
    answers[ai].text = '<div>' + escapeHtml(value) + '</div>';
    onCourseModified();
}

function gmToggleMcCorrect(activityId, idx, ai, checked) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    const answers = steps[idx]?.contentType?.params?.answers;
    if (!answers || !answers[ai]) return;
    answers[ai].correct = !!checked;
    onCourseModified();
}

function gmAddMcAnswer(activityId, idx) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    const answers = steps[idx]?.contentType?.params?.answers;
    if (!answers) return;
    answers.push({ correct: false, text: '<div>Nouvelle réponse</div>',
                   tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' } });
    onCourseModified();
    renderGameMapEditor(activity);
}

function gmDeleteMcAnswer(activityId, idx, ai) {
    const activity = findActivityById(activityId);
    const steps = gmGetSteps(activity);
    const answers = steps[idx]?.contentType?.params?.answers;
    if (!answers || answers.length <= 2) return;
    answers.splice(ai, 1);
    onCourseModified();
    renderGameMapEditor(activity);
}

// ==================== DÉPLACEMENT DES ÉTAPES ====================
// Un simple clic (sans déplacement) sélectionne l'étape ; un glissement la repositionne.

function gmStartDragStage(event, activityId, idx) {
    event.preventDefault();
    event.stopPropagation();
    const map = document.getElementById('gmMap');
    const marker = event.currentTarget;
    if (!map) return;

    const mapRect = map.getBoundingClientRect();
    const markerRect = marker.getBoundingClientRect();
    _gmDrag = {
        activityId: activityId,
        idx: idx,
        marker: marker,
        mapRect: mapRect,
        // décalage curseur → coin haut-gauche de la pastille, pour éviter le saut au clic
        offsetX: event.clientX - markerRect.left,
        offsetY: event.clientY - markerRect.top,
        wPct: markerRect.width / mapRect.width * 100,
        hPct: markerRect.height / mapRect.height * 100,
        moved: false
    };
    document.addEventListener('mousemove', gmOnDragStage);
    document.addEventListener('mouseup', gmStopDragStage);
}

function gmOnDragStage(event) {
    if (!_gmDrag) return;
    const d = _gmDrag;
    let x = (event.clientX - d.offsetX - d.mapRect.left) / d.mapRect.width * 100;
    let y = (event.clientY - d.offsetY - d.mapRect.top) / d.mapRect.height * 100;
    x = Math.max(0, Math.min(100 - d.wPct, x));
    y = Math.max(0, Math.min(100 - d.hPct, y));
    d.lastX = x;
    d.lastY = y;
    d.moved = true;
    d.marker.style.left = x + '%';
    d.marker.style.top = y + '%';
}

function gmStopDragStage() {
    document.removeEventListener('mousemove', gmOnDragStage);
    document.removeEventListener('mouseup', gmStopDragStage);
    if (!_gmDrag) return;
    const d = _gmDrag;
    _gmDrag = null;

    const activity = findActivityById(d.activityId);
    if (!activity) return;
    const steps = gmGetSteps(activity);
    if (d.moved && steps[d.idx]) {
        steps[d.idx].telemetry.x = d.lastX.toFixed(6);
        steps[d.idx].telemetry.y = d.lastY.toFixed(6);
        onCourseModified();
    }
    gmSelectedStage = d.idx;
    renderGameMapEditor(activity);
}
