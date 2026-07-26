// ==================== ÉDITEUR VISITE VIRTUELLE 360 (H5P.ThreeImage) ====================
// Calibration identique au viewer : yawOffset=95°, miroir (pas de signe négatif)

// Injecter le CSS pour les hotspots de l'éditeur 360
(function() {
    const style = document.createElement('style');
    style.textContent = `
        /* Pannellum hotspot reset */
        #vtEdPannellumViewer .pnlm-hotspot-base { cursor: pointer; }
        #vtEdPannellumViewer .pnlm-hotspot { background: none !important; border: none !important; 
            width: auto !important; height: auto !important; }
        #vtEdPannellumViewer .pnlm-tooltip { display: none !important; }

        /* Custom hotspot buttons */
        .vted-hs-btn {
            display: flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 600; white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0,0,0,0.4);
            cursor: grab; user-select: none;
            transition: transform 0.15s, box-shadow 0.15s;
            border: 2px solid rgba(255,255,255,0.8);
        }
        .vted-hs-btn:hover { transform: scale(1.08); box-shadow: 0 3px 12px rgba(0,0,0,0.5); }
        .vted-hs-btn.dragging { cursor: grabbing; opacity: 0.7; transform: scale(0.95); }
        .vted-hs-text { background: rgba(30, 144, 255, 0.9); color: white; }
        .vted-hs-quiz { background: rgba(255, 140, 0, 0.9); color: white; }
        .vted-hs-goto { background: rgba(76, 175, 80, 0.9); color: white; }
    `;
    document.head.appendChild(style);
})();

const VT_YAW_OFFSET = 95;

function h5pToPannellum(yawRad, pitchRad) {
    let yawDeg = (yawRad * 180 / Math.PI);
    while (yawDeg > 180) yawDeg -= 360;
    while (yawDeg < -180) yawDeg += 360;
    return { yaw: yawDeg + VT_YAW_OFFSET, pitch: pitchRad * 180 / Math.PI };
}

function pannellumToH5p(yawDeg, pitchDeg) {
    let yawRad = (yawDeg - VT_YAW_OFFSET) * Math.PI / 180;
    while (yawRad > Math.PI) yawRad -= 2 * Math.PI;
    while (yawRad < -Math.PI) yawRad += 2 * Math.PI;
    return { yaw: yawRad, pitch: pitchDeg * Math.PI / 180 };
}

function vtGetInteractionType(inter) {
    const lib = inter.action?.library || '';
    if (lib.includes('GoToScene')) return 'goto';
    if (lib.includes('AdvancedText')) return 'text';
    return 'quiz';
}
function vtGetInteractionIcon(type) {
    return type === 'text' ? '📝' : type === 'goto' ? '🔗' : '❓';
}
function vtGetInteractionLabel(inter, scenes) {
    const type = vtGetInteractionType(inter);
    if (type === 'text') return inter.action?.params?.text?.replace(/<[^>]*>/g, '').substring(0, 35) || 'Texte';
    if (type === 'goto') {
        const targetId = inter.action?.params?.nextSceneId ?? 0;
        const targetName = scenes && scenes[targetId] ? scenes[targetId].scenename : null;
        return '→ ' + (targetName || ('Scène ' + (targetId + 1)));
    }
    return 'Quiz';
}

let vtEditorViewer = null;
let vtEditorCurrentScene = 0;
let vtEditorPlacingHotspot = false;
let _vtEdActivityId = null;
let _vtEdSceneIdx = null;

// ==================== RENDU PRINCIPAL ====================

function renderThreeImageEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';

    if (!activity.content) activity.content = getActivityDefaultContent('ThreeImage');
    const scenes = activity.content.threeImage?.scenes || [];
    vtEditorCurrentScene = Math.min(vtEditorCurrentScene, Math.max(0, scenes.length - 1));
    const scene = scenes[vtEditorCurrentScene];
    _vtEdActivityId = activity.id;
    _vtEdSceneIdx = vtEditorCurrentScene;

    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml('🌐', activity.name, sectionId)}
                <p class="section-preview-desc">Visite virtuelle 360° avec hotspots interactifs</p>
            </div>
            <div style="padding: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
                    ${scenes.map((s, i) => `
                        <button class="btn ${i === vtEditorCurrentScene ? 'btn-primary' : 'btn-secondary'}" 
                                onclick="vtEdSelectScene('${activity.id}', ${i})"
                                style="padding: 0.3rem 0.8rem; font-size: 0.8rem; border-radius: 16px;">
                            ${escapeHtml(s.scenename || ('Scène ' + (i + 1)))}
                        </button>
                    `).join('')}
                    <button class="btn btn-secondary" onclick="vtEdAddScene('${activity.id}')" 
                            style="padding: 0.3rem 0.6rem; font-size: 0.8rem; border-radius: 16px;">+ Scène</button>
                </div>

                ${scene ? renderVtSceneEditor(activity, scene, vtEditorCurrentScene) : `
                    <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                        <p style="font-size: 2rem; margin-bottom: 0.5rem;">🌐</p>
                        <p>Ajoutez une scène pour commencer</p>
                    </div>
                `}
            </div>
        </div>`;
    
    if (scene && scene.scenesrc?.path) {
        setTimeout(() => vtEdInitPannellum(activity, vtEditorCurrentScene), 100);
    }
}

function renderVtSceneEditor(activity, scene, sceneIdx) {
    const hasImage = scene.scenesrc && scene.scenesrc.path;
    const interactions = scene.interactions || [];
    const scenes = activity.content.threeImage.scenes;
    const sceneCount = scenes.length;
    
    return `
        <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem;">
            <input type="text" class="cp-prop-input" value="${escapeHtml(scene.scenename || '')}" 
                   placeholder="Nom de la scène" 
                   onchange="vtEdUpdateScene('${activity.id}', ${sceneIdx}, 'scenename', this.value)"
                   style="flex: 1; font-weight: 600;">
            ${sceneCount > 1 ? `
                <button class="btn" onclick="vtEdDeleteScene('${activity.id}', ${sceneIdx})" 
                        style="background: var(--danger, #dc3545); color: white; padding: 0.3rem 0.6rem; font-size: 0.8rem; border-radius: 6px; border: none; cursor: pointer;">🗑️</button>
            ` : ''}
        </div>

        ${hasImage ? `
            <div id="vtEdPannellum" style="width: 100%; height: 380px; border-radius: 10px; overflow: hidden; background: #111; position: relative;">
                <div id="vtEdPannellumViewer" style="width: 100%; height: 100%;"></div>
                <div id="vtEdPlaceOverlay" style="display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 10; cursor: crosshair; background: rgba(30,144,255,0.08);">
                    <div style="position: absolute; top: 10px; left: 50%; transform: translateX(-50%); background: rgba(30,144,255,0.9); color: white; padding: 6px 16px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; pointer-events: none;">
                        Cliquez pour placer le hotspot • Échap pour annuler
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 0.6rem; flex-wrap: wrap; gap: 0.3rem;">
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--gray-600);">Ajouter des hotspots</span>
                <div style="display: flex; gap: 0.4rem; align-items: center;">
                    <label class="btn btn-secondary" style="cursor: pointer; padding: 0.3rem 0.8rem; font-size: 0.8rem; border-radius: 6px;">
                        🔄 Changer l'image
                        <input type="file" accept="image/*" style="display: none;" onchange="vtEdUploadImage(this, '${activity.id}', ${sceneIdx})">
                    </label>
                    <button class="btn btn-secondary" onclick="vtEdSetCameraStart('${activity.id}', ${sceneIdx})" 
                            style="padding: 0.3rem 0.8rem; font-size: 0.8rem; border-radius: 6px;">📷 Définir vue initiale</button>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.4rem; flex-wrap: wrap;">
                <button class="btn" onclick="vtEdStartPlaceHotspot('${activity.id}', ${sceneIdx}, 'text')"
                        style="padding: 0.4rem 0.9rem; font-size: 0.85rem; border-radius: 8px; background: #1e90ff; color: white; border: none; cursor: pointer; font-weight: 600;">📝 Texte</button>
                <button class="btn" onclick="vtEdStartPlaceHotspot('${activity.id}', ${sceneIdx}, 'quiz')"
                        style="padding: 0.4rem 0.9rem; font-size: 0.85rem; border-radius: 8px; background: #ff8c00; color: white; border: none; cursor: pointer; font-weight: 600;">❓ Quiz</button>
                ${sceneCount > 1 ? `
                <button class="btn" onclick="vtEdStartPlaceHotspot('${activity.id}', ${sceneIdx}, 'goto')"
                        style="padding: 0.4rem 0.9rem; font-size: 0.85rem; border-radius: 8px; background: #4caf50; color: white; border: none; cursor: pointer; font-weight: 600;">🔗 Changer de scène</button>
                ` : ''}
            </div>
        ` : `
            <div style="background: var(--gray-50); border: 2px dashed var(--gray-300); border-radius: 12px; padding: 3rem; text-align: center;">
                <p style="font-size: 2rem; margin-bottom: 0.5rem;">🖼️</p>
                <p style="color: var(--gray-600); margin-bottom: 1rem;">Chargez une image équirectangulaire 360°</p>
                <label class="btn btn-primary" style="cursor: pointer; padding: 0.5rem 1.25rem; border-radius: 8px;">
                    📷 Choisir une image 360°
                    <input type="file" accept="image/*" style="display: none;" onchange="vtEdUploadImage(this, '${activity.id}', ${sceneIdx})">
                </label>
                <p style="font-size: 0.75rem; color: var(--gray-400); margin-top: 0.75rem;">Format recommandé : JPEG équirectangulaire (ratio 2:1)</p>
            </div>
        `}

        <div style="margin-top: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <h4 style="margin: 0; font-size: 0.9rem;">Hotspots (${interactions.length})</h4>
            </div>
            ${interactions.length === 0 ? `
                <p style="color: var(--gray-400); font-size: 0.8rem; text-align: center; padding: 0.5rem;">
                    ${hasImage ? 'Ajoutez des hotspots via les boutons ci-dessus. Double-cliquez sur un hotspot pour l\'éditer, glissez-le pour le déplacer.' : "Chargez d'abord une image 360°"}
                </p>
            ` : `
                <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                    ${interactions.map((inter, iIdx) => {
                        const type = vtGetInteractionType(inter);
                        const icon = vtGetInteractionIcon(type);
                        const label = vtGetInteractionLabel(inter, scenes);
                        const colorMap = { text: '#1e90ff', quiz: '#ff8c00', goto: '#4caf50' };
                        return `
                            <div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.6rem; background: var(--gray-50); border-radius: 8px; font-size: 0.8rem; border-left: 3px solid ${colorMap[type]};">
                                <span>${icon}</span>
                                <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(label)}</span>
                                <button onclick="vtEdEditInteraction('${activity.id}', ${sceneIdx}, ${iIdx})" 
                                        style="background: none; border: none; cursor: pointer; font-size: 0.8rem;" title="Éditer">✏️</button>
                                <button onclick="vtEdFocusInteraction('${activity.id}', ${sceneIdx}, ${iIdx})" 
                                        style="background: none; border: none; cursor: pointer; font-size: 0.8rem;" title="Voir sur la carte">👁️</button>
                                <button onclick="vtEdDeleteInteraction('${activity.id}', ${sceneIdx}, ${iIdx})" 
                                        style="background: none; border: none; cursor: pointer; font-size: 0.8rem;" title="Supprimer">🗑️</button>
                            </div>
                        `;
                    }).join('')}
                </div>
                <p style="font-size: 0.7rem; color: var(--gray-400); margin-top: 0.4rem; text-align: center;">
                    Glissez les hotspots sur l'image pour les déplacer • Double-cliquez pour éditer
                </p>
            `}
        </div>
    `;
}

// ==================== PANNELLUM VIEWER ====================

function vtEdInitPannellum(activity, sceneIdx) {
    const scene = activity.content?.threeImage?.scenes?.[sceneIdx];
    if (!scene || !scene.scenesrc?.path) return;
    const container = document.getElementById('vtEdPannellumViewer');
    if (!container) return;
    
    if (vtEditorViewer) { try { vtEditorViewer.destroy(); } catch(e) {} vtEditorViewer = null; }
    
    const imageUrl = scene.scenesrc.path;
    const camParts = (scene.cameraStartPosition || '0,0').split(',');
    const pannPos = h5pToPannellum(parseFloat(camParts[0] || 0), parseFloat(camParts[1] || 0));
    const scenes = activity.content.threeImage.scenes;
    
    const hotSpots = (scene.interactions || []).map((inter, idx) => {
        const posParts = (inter.interactionpos || '0,0').split(',');
        const pos = h5pToPannellum(parseFloat(posParts[0] || 0), parseFloat(posParts[1] || 0));
        const type = vtGetInteractionType(inter);
        const icon = vtGetInteractionIcon(type);
        const label = vtGetInteractionLabel(inter, scenes);
        return {
            pitch: pos.pitch, yaw: pos.yaw, type: 'info', id: 'hs-' + idx,
            createTooltipFunc: vtEdCreateHotspotTooltip,
            createTooltipArgs: { icon, label, type, idx, activityId: activity.id, sceneIdx },
            clickHandlerFunc: function() {}
        };
    });
    
    if (typeof pannellum === 'undefined') {
        if (!document.querySelector('link[href*="pannellum"]')) {
            const css = document.createElement('link'); css.rel = 'stylesheet';
            css.href = 'https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css';
            document.head.appendChild(css);
        }
        if (!document.querySelector('script[src*="pannellum"]')) {
            const js = document.createElement('script');
            js.src = 'https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js';
            js.onload = () => vtEdCreateViewer(container, imageUrl, pannPos, hotSpots);
            document.head.appendChild(js); return;
        }
        setTimeout(() => vtEdInitPannellum(activity, sceneIdx), 200); return;
    }
    vtEdCreateViewer(container, imageUrl, pannPos, hotSpots);
}

function vtEdCreateHotspotTooltip(hotSpotDiv, args) {
    const btn = document.createElement('div');
    btn.classList.add('vted-hs-btn', 'vted-hs-' + args.type);
    const shortLabel = args.label.length > 22 ? args.label.substring(0, 22) + '…' : args.label;
    btn.innerHTML = '<span>' + args.icon + '</span><span>' + shortLabel + '</span>';
    
    btn.addEventListener('dblclick', function(e) {
        e.stopPropagation();
        vtEdEditInteraction(args.activityId, args.sceneIdx, args.idx);
    });
    
    // Drag pour déplacer
    let dragStarted = false;
    let startX, startY;
    
    btn.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        e.stopPropagation();
        dragStarted = false;
        startX = e.clientX; startY = e.clientY;
        btn.classList.add('dragging');
        
        function onMouseMove(ev) {
            const dx = ev.clientX - startX, dy = ev.clientY - startY;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) dragStarted = true;
        }
        function onMouseUp(ev) {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            btn.classList.remove('dragging');
            if (dragStarted && vtEditorViewer) {
                const coords = vtEditorViewer.mouseEventToCoords(ev);
                if (coords) {
                    const h5pPos = pannellumToH5p(coords[1], coords[0]);
                    vtEdMoveInteraction(args.activityId, args.sceneIdx, args.idx, h5pPos.yaw, h5pPos.pitch);
                }
            }
        }
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    });
    
    hotSpotDiv.appendChild(btn);
}

function vtEdCreateViewer(container, imageUrl, pannPos, hotSpots) {
    if (typeof pannellum === 'undefined') {
        setTimeout(() => vtEdCreateViewer(container, imageUrl, pannPos, hotSpots), 200); return;
    }
    vtEditorViewer = pannellum.viewer(container.id, {
        type: 'equirectangular', panorama: imageUrl, autoLoad: true,
        showControls: true, compass: false,
        yaw: pannPos.yaw, pitch: pannPos.pitch, hfov: 100, hotSpots: hotSpots
    });
}

function vtEdMoveInteraction(activityId, sceneIdx, intIdx, yawRad, pitchRad) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const inter = activity.content.threeImage.scenes[sceneIdx]?.interactions?.[intIdx];
    if (!inter) return;
    inter.interactionpos = yawRad + ',' + pitchRad;
    onCourseModified();
    renderThreeImageEditor(activity);
    showToast('Hotspot déplacé', 'success');
}

// ==================== GESTION DES SCÈNES ====================

function vtEdSelectScene(activityId, sceneIdx) {
    vtEditorCurrentScene = sceneIdx;
    const activity = findActivityById(activityId);
    if (activity) renderThreeImageEditor(activity);
}

function vtEdAddScene(activityId) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const scenes = activity.content.threeImage.scenes;
    const newIdx = scenes.length;
    scenes.push({
        sceneId: newIdx, sceneType: '360', showBackButton: true, iconType: 'arrow',
        scenesrc: null, scenedescription: '', scenename: 'Scène ' + (newIdx + 1),
        cameraStartPosition: '0,0', interactions: []
    });
    vtEditorCurrentScene = newIdx;
    onCourseModified();
    renderThreeImageEditor(activity);
    showToast('Scène ajoutée', 'success');
}

function vtEdDeleteScene(activityId, sceneIdx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    activity.content.threeImage.scenes.splice(sceneIdx, 1);
    activity.content.threeImage.scenes.forEach((s, i) => s.sceneId = i);
    if (activity.content.threeImage.startSceneId >= activity.content.threeImage.scenes.length)
        activity.content.threeImage.startSceneId = 0;
    // Mettre à jour les GoToScene
    activity.content.threeImage.scenes.forEach(s => {
        (s.interactions || []).forEach(inter => {
            if (vtGetInteractionType(inter) === 'goto') {
                const t = inter.action.params.nextSceneId;
                if (t === sceneIdx) inter.action.params.nextSceneId = 0;
                else if (t > sceneIdx) inter.action.params.nextSceneId = t - 1;
            }
        });
    });
    vtEditorCurrentScene = Math.min(vtEditorCurrentScene, activity.content.threeImage.scenes.length - 1);
    onCourseModified();
    renderThreeImageEditor(activity);
    showToast('Scène supprimée', 'info');
}

function vtEdUpdateScene(activityId, sceneIdx, prop, value) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    activity.content.threeImage.scenes[sceneIdx][prop] = value;
    onCourseModified();
    renderTree();
}

// ==================== UPLOAD IMAGE ====================

function vtEdUploadImage(input, activityId, sceneIdx) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 50 * 1024 * 1024) { showToast('Image trop volumineuse (max 50 Mo)', 'error'); return; }
    const formData = new FormData();
    formData.append('file', file);
    showToast('Upload en cours...', 'info');
    fetch('api/editor_api.php?action=upload_file', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.error) { showToast('Erreur: ' + data.error, 'error'); return; }
        const activity = findActivityById(activityId);
        if (!activity) return;
        activity.content.threeImage.scenes[sceneIdx].scenesrc = {
            path: data.url, mime: file.type || 'image/jpeg',
            copyright: { license: 'U' }, width: 1000, height: 500
        };
        onCourseModified();
        renderThreeImageEditor(activity);
        showToast('Image 360° chargée', 'success');
    })
    .catch(err => { showToast("Erreur d'upload", 'error'); console.error(err); });
}

function vtEdSetCameraStart(activityId, sceneIdx) {
    if (!vtEditorViewer) { showToast('Viewer non initialisé', 'error'); return; }
    const h5pPos = pannellumToH5p(vtEditorViewer.getYaw(), vtEditorViewer.getPitch());
    const activity = findActivityById(activityId);
    if (!activity) return;
    activity.content.threeImage.scenes[sceneIdx].cameraStartPosition = h5pPos.yaw + ',' + h5pPos.pitch;
    onCourseModified();
    showToast('Vue initiale enregistrée', 'success');
}

// ==================== PLACEMENT HOTSPOTS ====================

let vtEdPendingHotspotType = null;
let vtEdPendingActivityId = null;
let vtEdPendingSceneIdx = null;

function vtEdStartPlaceHotspot(activityId, sceneIdx, type) {
    vtEdPendingHotspotType = type;
    vtEdPendingActivityId = activityId;
    vtEdPendingSceneIdx = sceneIdx;
    vtEditorPlacingHotspot = true;
    const overlay = document.getElementById('vtEdPlaceOverlay');
    if (overlay) {
        overlay.style.display = 'block';
        overlay.onclick = function(evt) {
            evt.stopPropagation();
            if (!vtEditorViewer) return;
            const coords = vtEditorViewer.mouseEventToCoords(evt);
            if (!coords) { showToast('Position invalide', 'error'); return; }
            const h5pPos = pannellumToH5p(coords[1], coords[0]);
            vtEdPlaceHotspot(h5pPos.yaw, h5pPos.pitch);
        };
    }
    document.addEventListener('keydown', vtEdCancelPlacement);
}
function vtEdCancelPlacement(evt) { if (evt.key === 'Escape') vtEdStopPlacement(); }
function vtEdStopPlacement() {
    vtEditorPlacingHotspot = false; vtEdPendingHotspotType = null;
    const overlay = document.getElementById('vtEdPlaceOverlay');
    if (overlay) overlay.style.display = 'none';
    document.removeEventListener('keydown', vtEdCancelPlacement);
}

function vtEdPlaceHotspot(yawRad, pitchRad) {
    const activityId = vtEdPendingActivityId;
    const sceneIdx = vtEdPendingSceneIdx;
    const type = vtEdPendingHotspotType;
    vtEdStopPlacement();
    
    const activity = findActivityById(activityId);
    if (!activity) return;
    const scene = activity.content.threeImage.scenes[sceneIdx];
    if (!scene.interactions) scene.interactions = [];
    
    const interactionPos = yawRad + ',' + pitchRad;
    const uuid = crypto.randomUUID ? crypto.randomUUID() : ('id-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8));
    
    let action;
    if (type === 'text') {
        action = {
            library: 'H5P.AdvancedText 1.1',
            params: { text: '<p>Nouveau texte</p>' },
            subContentId: uuid,
            metadata: { contentType: 'Text', license: 'U', title: 'Sans titre Text', authors: [], changes: [] }
        };
    } else if (type === 'goto') {
        const scenes = activity.content.threeImage.scenes;
        let targetScene = 0;
        for (let i = 0; i < scenes.length; i++) { if (i !== sceneIdx) { targetScene = i; break; } }
        action = {
            library: 'H5P.GoToScene 0.1',
            params: { nextSceneId: targetScene },
            subContentId: uuid,
            metadata: { contentType: 'Go To Scene', license: 'U', title: 'Sans titre Go To Scene', authors: [], changes: [], extraTitle: 'Sans titre Go To Scene' }
        };
    } else {
        action = {
            library: 'H5P.SingleChoiceSet 1.11',
            params: {
                choices: [{ subContentId: uuid + '-q', question: '<p>Question ?</p>', answers: ['<p>Bonne réponse</p>', '<p>Mauvaise réponse</p>'] }],
                overallFeedback: [{ from: 0, to: 100 }],
                behaviour: { autoContinue: true, timeoutCorrect: 2000, timeoutWrong: 3000, soundEffectsEnabled: true, enableRetry: true, enableSolutionsButton: true, passPercentage: 100 },
                l10n: { nextButtonLabel: 'Question suivante', showSolutionButtonLabel: 'Voir la solution', retryButtonLabel: 'Correction', solutionViewTitle: 'Recommencer', correctText: 'Correct !', incorrectText: 'Incorrect !', muteButtonLabel: 'Couper les retours sons', closeButtonLabel: 'Fermer', slideOfTotal: 'Diapositive :num sur :total', scoreBarLabel: 'Vous avez :num points sur un total de :total', solutionListQuestionNumber: 'Question :num', a11yShowSolution: 'Show the solution.', a11yRetry: 'Retry the task.' }
            },
            subContentId: uuid,
            metadata: { contentType: 'Single Choice Set', license: 'U', title: 'Sans titre', authors: [], changes: [] }
        };
    }
    
    scene.interactions.push({ interactionpos: interactionPos, action: action, label: { labelPosition: 'inherit', showLabel: 'inherit' } });
    onCourseModified();
    renderThreeImageEditor(activity);
    const newIdx = scene.interactions.length - 1;
    setTimeout(() => vtEdEditInteraction(activityId, sceneIdx, newIdx), 300);
}

// ==================== ÉDITION DES INTERACTIONS ====================

function vtEdEditInteraction(activityId, sceneIdx, intIdx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const interaction = activity.content.threeImage.scenes[sceneIdx]?.interactions?.[intIdx];
    if (!interaction) return;
    const type = vtGetInteractionType(interaction);
    
    const modalId = 'vtEdInteractionModal';
    let modal = document.getElementById(modalId);
    if (modal) modal.remove();
    modal = document.createElement('div');
    modal.id = modalId;
    modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';
    
    if (type === 'text') {
        const currentText = interaction.action.params.text || '';
        const decoded = currentText.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"');
        const plainText = decoded.replace(/<[^>]*>/g, '');
        modal.innerHTML = '<div style="background: white; border-radius: 12px; padding: 1.5rem; width: 90%; max-width: 500px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);"><h3 style="margin: 0 0 1rem 0; font-size: 1rem;">📝 Éditer le texte</h3><textarea id="vtEdTextArea" style="width: 100%; height: 120px; padding: 0.75rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem; resize: vertical;">' + escapeHtml(plainText) + '</textarea><div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;"><button class="btn btn-secondary" onclick="document.getElementById(\'' + modalId + '\').remove()" style="padding: 0.4rem 1rem; border-radius: 6px;">Annuler</button><button class="btn btn-primary" onclick="vtEdSaveText(\'' + activityId + '\', ' + sceneIdx + ', ' + intIdx + ')" style="padding: 0.4rem 1rem; border-radius: 6px;">Enregistrer</button></div></div>';
    } else if (type === 'goto') {
        const scenes = activity.content.threeImage.scenes;
        const currentTarget = interaction.action.params.nextSceneId ?? 0;
        let options = '';
        scenes.forEach((s, i) => {
            if (i === sceneIdx) return;
            options += '<option value="' + i + '"' + (i === currentTarget ? ' selected' : '') + '>' + escapeHtml(s.scenename || ('Scène ' + (i + 1))) + '</option>';
        });
        modal.innerHTML = '<div style="background: white; border-radius: 12px; padding: 1.5rem; width: 90%; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);"><h3 style="margin: 0 0 1rem 0; font-size: 1rem;">🔗 Changer de scène</h3><p style="font-size: 0.85rem; color: #666; margin-bottom: 0.75rem;">Scène cible :</p><select id="vtEdGoToSceneSelect" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 8px; font-size: 0.9rem;">' + options + '</select><div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;"><button class="btn btn-secondary" onclick="document.getElementById(\'' + modalId + '\').remove()" style="padding: 0.4rem 1rem; border-radius: 6px;">Annuler</button><button class="btn btn-primary" onclick="vtEdSaveGoToScene(\'' + activityId + '\', ' + sceneIdx + ', ' + intIdx + ')" style="padding: 0.4rem 1rem; border-radius: 6px;">Enregistrer</button></div></div>';
    } else {
        const choices = interaction.action?.params?.choices || [];
        let choicesHtml = '';
        choices.forEach((c, ci) => {
            const q = (c.question || '').replace(/<[^>]*>/g, '');
            const answers = c.answers || [];
            let answersHtml = '';
            answers.forEach((a, ai) => {
                const aText = a.replace(/<[^>]*>/g, '');
                answersHtml += '<div style="display: flex; align-items: center; gap: 0.3rem; margin-left: 1rem; margin-bottom: 0.25rem;"><span style="font-size: 0.75rem; color: ' + (ai === 0 ? '#28a745' : '#dc3545') + ';">' + (ai === 0 ? '✅' : '❌') + '</span><input type="text" class="vted-a" data-ci="' + ci + '" data-ai="' + ai + '" value="' + escapeHtml(aText) + '" style="flex: 1; padding: 0.2rem 0.4rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.8rem;"></div>';
            });
            choicesHtml += '<div style="background: #f8f9fa; border-radius: 8px; padding: 0.75rem; margin-bottom: 0.75rem;"><div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;"><strong style="font-size: 0.8rem;">Q' + (ci+1) + '</strong><input type="text" class="vted-q" data-ci="' + ci + '" value="' + escapeHtml(q) + '" style="flex: 1; padding: 0.3rem 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.85rem;"></div>' + answersHtml + '</div>';
        });
        modal.innerHTML = '<div style="background: white; border-radius: 12px; padding: 1.5rem; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);"><h3 style="margin: 0 0 1rem 0; font-size: 1rem;">❓ Éditer le quiz</h3><div id="vtEdQuizChoices">' + choicesHtml + '</div><p style="font-size: 0.75rem; color: var(--gray-400); margin-bottom: 1rem;">La première réponse est toujours la bonne</p><div style="display: flex; justify-content: flex-end; gap: 0.5rem;"><button class="btn btn-secondary" onclick="document.getElementById(\'' + modalId + '\').remove()" style="padding: 0.4rem 1rem; border-radius: 6px;">Annuler</button><button class="btn btn-primary" onclick="vtEdSaveQuiz(\'' + activityId + '\', ' + sceneIdx + ', ' + intIdx + ')" style="padding: 0.4rem 1rem; border-radius: 6px;">Enregistrer</button></div></div>';
    }
    
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ==================== SAUVEGARDE ====================

function vtEdSaveText(activityId, sceneIdx, intIdx) {
    const ta = document.getElementById('vtEdTextArea');
    if (!ta) return;
    const activity = findActivityById(activityId);
    if (!activity) return;
    activity.content.threeImage.scenes[sceneIdx].interactions[intIdx].action.params.text = '<p>' + escapeHtml(ta.value.trim()) + '</p>';
    onCourseModified();
    document.getElementById('vtEdInteractionModal')?.remove();
    renderThreeImageEditor(activity);
    showToast('Texte mis à jour', 'success');
}

function vtEdSaveGoToScene(activityId, sceneIdx, intIdx) {
    const sel = document.getElementById('vtEdGoToSceneSelect');
    if (!sel) return;
    const activity = findActivityById(activityId);
    if (!activity) return;
    activity.content.threeImage.scenes[sceneIdx].interactions[intIdx].action.params.nextSceneId = parseInt(sel.value);
    onCourseModified();
    document.getElementById('vtEdInteractionModal')?.remove();
    renderThreeImageEditor(activity);
    showToast('Scène cible mise à jour', 'success');
}

function vtEdSaveQuiz(activityId, sceneIdx, intIdx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const interaction = activity.content.threeImage.scenes[sceneIdx].interactions[intIdx];
    const questions = document.querySelectorAll('.vted-q');
    const choices = [];
    const ciSet = new Set();
    questions.forEach(q => ciSet.add(q.dataset.ci));
    ciSet.forEach(ci => {
        const qInput = document.querySelector('.vted-q[data-ci="' + ci + '"]');
        const aInputs = document.querySelectorAll('.vted-a[data-ci="' + ci + '"]');
        if (qInput) {
            choices.push({
                subContentId: crypto.randomUUID ? crypto.randomUUID() : ('q-' + Date.now() + '-' + ci),
                question: '<p>' + escapeHtml(qInput.value) + '</p>',
                answers: Array.from(aInputs).map(a => '<p>' + escapeHtml(a.value) + '</p>')
            });
        }
    });
    if (choices.length > 0) interaction.action.params.choices = choices;
    onCourseModified();
    document.getElementById('vtEdInteractionModal')?.remove();
    renderThreeImageEditor(activity);
    showToast('Quiz mis à jour', 'success');
}

function vtEdDeleteInteraction(activityId, sceneIdx, intIdx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    activity.content.threeImage.scenes[sceneIdx].interactions.splice(intIdx, 1);
    onCourseModified();
    renderThreeImageEditor(activity);
    showToast('Hotspot supprimé', 'info');
}

function vtEdFocusInteraction(activityId, sceneIdx, intIdx) {
    const activity = findActivityById(activityId);
    if (!activity || !vtEditorViewer) return;
    const inter = activity.content.threeImage.scenes[sceneIdx]?.interactions?.[intIdx];
    if (!inter) return;
    const posParts = (inter.interactionpos || '0,0').split(',');
    const pos = h5pToPannellum(parseFloat(posParts[0] || 0), parseFloat(posParts[1] || 0));
    vtEditorViewer.lookAt(pos.pitch, pos.yaw, 90, 1000);
}
