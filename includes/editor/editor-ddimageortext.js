// ==================== ÉDITEUR GLISSER-DÉPOSER (ddimageortext) ====================
// Réplique le fonctionnement du DragQuestion de Course Presentation
// mais en tant qu'activité quiz Moodle standalone.

// État interne de l'éditeur DDI
var _ddi = {
    selectedDrag: null,   // index du drag sélectionné
    selectedDrop: null,   // index du drop sélectionné
    dragging: null,       // { type: 'drop', idx, startX, startY, origX, origY }
    resizing: null,       // { type: 'drop', idx, startX, startY, origW, origH }
    waitingForPaste: false
};

// Retourne l'activité DDI en cours d'édition (tempActivity si dans un quiz, sinon selectedActivity)
function ddiGetActivity() {
    if (typeof _qsDdiEditIdx !== 'undefined' && _qsDdiEditIdx !== null && window._qsDdiTempActivity) {
        return window._qsDdiTempActivity;
    }
    return getSelectedActivity();
}

// ==================== INITIALISATION & RENDU PRINCIPAL ====================

function ddiEnsureContent(activity) {
    if (!activity.content) activity.content = {};
    var c = activity.content;
    if (c.questiontext === undefined) c.questiontext = '<p>Compléter le schéma</p>';
    if (c.shuffleanswers === undefined) c.shuffleanswers = 1;
    if (c.attempts_number === undefined) c.attempts_number = 1;
    if (c.defaultmark === undefined) c.defaultmark = 1;
    if (!c.backgroundUrl) c.backgroundUrl = null;
    if (!c.bgImageName) c.bgImageName = null;
    if (c.canvasWidth === undefined) c.canvasWidth = 800;
    if (c.canvasHeight === undefined) c.canvasHeight = 600;
    if (!c.drags) c.drags = [];
    if (!c.drops) c.drops = [];
    // Migration legacy : ancien format auto stockait un fond étendu (extW) + sourceWidth.
    // Ramener le canvas à la taille source pour que le rendu unifié (colonne HTML) fonctionne.
    if (c.sourceWidth && c.sourceWidth < c.canvasWidth) {
        c.canvasWidth = c.sourceWidth;
    }
    if (c.sourceWidth !== undefined) delete c.sourceWidth;
    return c;
}

function renderDdimageortextEditor(activity) {
    var content = document.getElementById('editorContent');
    var c = ddiEnsureContent(activity);

    // Synchroniser canvasWidth/Height avec les dimensions naturelles de l'image de fond
    // (nécessaire pour les questions importées depuis un .mbz, où ces valeurs sont à 800x600
    // par défaut alors que l'image a son propre ratio → sinon image écrasée + drops mal placés).
    ddiSyncCanvasSizeToBg(c);

    // Mode pleine largeur
    var canvasWrapper = document.getElementById('canvasWrapper');
    if (canvasWrapper) canvasWrapper.classList.add('cp-mode');
    
    // Canvas avec background + zones
    var canvasHtml = ddiRenderCanvas(c);
    // Panneau latéral
    var panelHtml = ddiRenderPanel(c);
    
    // Header : bouton retour + titre inline (compact, pas de marge excessive)
    var isInQuiz = (typeof _qsDdiEditIdx !== 'undefined' && _qsDdiEditIdx !== null);
    var backLabel = isInQuiz ? '← Retour à l\'évaluation' : '← Retour';
    var backAction = isInQuiz ? 'qsCloseDdiEditor()' : 'showStructureView()';
    
    // Rich text questiontext
    var questionHtml = c.questiontext || '';
    
    content.innerHTML = `
        <div class="ddi-editor">
            <div class="ddi-header-compact">
                <button class="btn btn-secondary ed-back-btn" onclick="${backAction}">${backLabel}</button>
                <h3 class="ed-title">🎯 <span class="activity-name-editable" onclick="startEditActivityNameInHeader(this)">${escapeHtml(activity.name)}</span></h3>
                <div class="ed-header-actions">
                    <button class="ed-undo-btn" onclick="courseUndo()" title="Annuler (Ctrl+Z)" ${courseHistoryIndex > 0 ? '' : 'disabled'}>↩</button>
                    <button class="ed-redo-btn" onclick="courseRedo()" title="Répéter (Ctrl+Y)" ${courseHistoryIndex < courseHistory.length - 1 ? '' : 'disabled'}>↪</button>
                </div>
            </div>
            <div class="ddi-questiontext-section">
                <div class="ddi-rt-toolbar">
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('bold')" title="Gras"><b>G</b></button>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('italic')" title="Italique"><i>I</i></button>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('underline')" title="Souligné"><u>S</u></button>
                    <span class="qs-rt-sep"></span>
                    <select class="ddi-rt-heading" onchange="ddiRtFormatBlock(this.value); this.value='';" title="Titre">
                        <option value="">Titre...</option>
                        <option value="H3">Titre</option>
                        <option value="H4">Sous-titre</option>
                        <option value="P">Normal</option>
                    </select>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('justifyLeft')" title="Aligner gauche">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
                    </button>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('justifyCenter')" title="Centrer">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
                    </button>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('justifyRight')" title="Aligner à droite">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtInsertLink()" title="Lien">🔗</button>
                    <button type="button" class="qs-rt-btn" onclick="ddiRtInsertImage()" title="Insérer image">🖼️</button>
                    ${typeof cpEmojiBarHtml === 'function' ? cpEmojiBarHtml('ddiQuestionText') : ''}
                    <button type="button" class="qs-rt-btn" onclick="ddiRtCmd('removeFormat')" title="Effacer formatage">⊘</button>
                </div>
                <div class="ddi-rt-editor" contenteditable="true" id="ddiQuestionText"
                     oninput="ddiOnQuestionTextInput()"
                     onblur="ddiOnQuestionTextInput()"
                     data-placeholder="Description de la question (optionnel : texte, images...)">${questionHtml}</div>
                <input type="file" id="ddiRtImageInput" accept="image/*" style="display:none" onchange="ddiRtHandleImageUpload(this)">
            </div>
            <div class="ddi-layout">
                <div class="ddi-canvas-wrap" id="ddiCanvasWrap">
                    <div class="ddi-canvas-scroll" id="ddiCanvasScroll">
                        ${canvasHtml}
                    </div>
                    <div class="ddi-zoom-bar">
                        <button onclick="ddiZoom(-0.1)" title="Réduire">−</button>
                        <input type="range" min="30" max="200" value="100" id="ddiZoomSlider" oninput="ddiZoomTo(this.value)">
                        <button onclick="ddiZoom(0.1)" title="Agrandir">+</button>
                        <span id="ddiZoomLabel" style="min-width:32px;text-align:center">100%</span>
                        <button onclick="ddiZoomFit()" title="Adapter à l'écran" style="font-size:11px;">⊞</button>
                    </div>
                </div>
                <div class="ddi-panel" id="ddiPanel">
                    ${panelHtml}
                </div>
            </div>
        </div>`;
    
    // Init resize handles on existing images
    ddiRtInitImageResizers();
    ddiAutoFitOnce();
    ddiHealStagingImages();
}

// Réaffiche les images d'étiquettes qui n'ont pas abouti au premier rendu
// (race de chargement : plusieurs requêtes parallèles vers serve_upload peuvent
// échouer ou rester en attente ; un re-set de src force le navigateur à refaire
// la requête, qui aboutit maintenant que le cache est chaud).
function ddiHealStagingImages() {
    setTimeout(function() {
        var imgs = document.querySelectorAll('#ddiStaging img[data-src]');
        imgs.forEach(function(img) {
            var loaded = img.complete && img.naturalWidth > 0;
            if (!loaded) {
                var src = img.getAttribute('data-src');
                img.style.display = '';
                img.src = '';
                img.src = src;
            }
        });
    }, 600);
}

// Retry silencieux sur échec de chargement : relance une fois après 300ms.
// Évite qu'une image devienne définitivement invisible à cause d'un échec transitoire.
function ddiOnDragImgError(img) {
    if (img.dataset.retried) return;
    img.dataset.retried = '1';
    setTimeout(function() {
        var src = img.getAttribute('data-src') || img.src;
        img.src = '';
        img.src = src;
    }, 300);
}

// Refresh partiel : seulement le canvas ou seulement le panel
function ddiRefreshCanvas() {
    var c = ddiEnsureContent(ddiGetActivity());
    var scroll = document.getElementById('ddiCanvasScroll');
    if (scroll) {
        scroll.innerHTML = ddiRenderCanvas(c);
        _ddiApplyZoom();
        ddiHealStagingImages();
    }
}

function ddiRefreshPanel() {
    var c = ddiEnsureContent(ddiGetActivity());
    var el = document.getElementById('ddiPanel');
    if (el) el.innerHTML = ddiRenderPanel(c);
}

function ddiRefreshAll() {
    ddiRefreshCanvas();
    ddiRefreshPanel();
}

// ==================== QUESTIONTEXT RICH TEXT EDITOR ====================

function ddiRtCmd(command) {
    var editor = document.getElementById('ddiQuestionText');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    ddiOnQuestionTextInput();
}

function ddiRtFormatBlock(tag) {
    if (!tag) return;
    var editor = document.getElementById('ddiQuestionText');
    if (!editor) return;
    editor.focus();
    document.execCommand('formatBlock', false, '<' + tag + '>');
    ddiOnQuestionTextInput();
}

function ddiOnQuestionTextInput() {
    var editor = document.getElementById('ddiQuestionText');
    if (!editor) return;
    var c = ddiEnsureContent(ddiGetActivity());
    c.questiontext = editor.innerHTML;
    onCourseModified();
}

function ddiRtInsertImage() {
    var input = document.getElementById('ddiRtImageInput');
    if (input) input.click();
}

function ddiRtInsertLink() {
    var editor = document.getElementById('ddiQuestionText');
    if (!editor) return;
    editor.focus();
    
    // Détecter un lien existant dans la sélection
    var existingUrl = 'https://';
    var sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
        var el = sel.focusNode;
        if (el && el.nodeType === 3) el = el.parentElement;
        while (el && el.tagName !== 'A' && el !== editor) el = el.parentElement;
        if (el && el.tagName === 'A') existingUrl = el.getAttribute('href') || el.href;
    }
    
    var url = prompt("Entrez l'URL du lien:", existingUrl);
    if (url === null) return;
    if (url === '') {
        document.execCommand('unlink', false, null);
    } else {
        if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
        document.execCommand('createLink', false, url);
        // target="_blank"
        var sel2 = window.getSelection();
        if (sel2 && sel2.focusNode) {
            var aEl = sel2.focusNode.nodeType === 3 ? sel2.focusNode.parentElement : sel2.focusNode;
            while (aEl && aEl.tagName !== 'A') aEl = aEl.parentElement;
            if (aEl && aEl.tagName === 'A') aEl.target = '_blank';
        }
    }
    ddiOnQuestionTextInput();
}

// Paste d'image dans le richtext DDI : upload puis insérer avec resize
function ddiRtHandlePaste(e) {
    var items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
    if (!items) return;
    for (var i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            e.preventDefault();
            var file = items[i].getAsFile();
            if (file) ddiRtUploadAndInsertImage(file);
            return;
        }
    }
}

function ddiRtUploadAndInsertImage(file) {
    showToast('Upload en cours...', 'info');
    var formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    if (typeof getEditorSessionId === 'function') {
        var sid = getEditorSessionId();
        if (sid) formData.append('session_id', sid);
    }
    fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.url) {
                var editor = document.getElementById('ddiQuestionText');
                if (!editor) return;
                editor.focus();
                var img = new Image();
                img.onload = function() {
                    var natW = img.naturalWidth, natH = img.naturalHeight;
                    var displayH = Math.min(300, natH);
                    var displayW = Math.round(natW * (displayH / natH));
                    var imgHtml = '<img src="' + escapeHtml(data.url) + '" ' +
                        'width="' + displayW + '" height="' + displayH + '" ' +
                        'class="ddi-rt-img" style="max-width:100%; cursor:pointer;" ' +
                        'data-orig-w="' + natW + '" data-orig-h="' + natH + '">';
                    document.execCommand('insertHTML', false, imgHtml);
                    ddiOnQuestionTextInput();
                    ddiRtInitImageResizers();
                    showToast('Image insérée (' + natW + '×' + natH + 'px)', 'success');
                };
                img.onerror = function() {
                    document.execCommand('insertHTML', false, '<img src="' + escapeHtml(data.url) + '" style="max-width:100%;">');
                    ddiOnQuestionTextInput();
                    showToast('Image insérée', 'success');
                };
                img.src = data.url;
            } else {
                showToast('Erreur upload: ' + (data.error || 'inconnue'), 'error');
            }
        })
        .catch(function(err) { showToast('Erreur: ' + err.message, 'error'); });
}

function ddiRtHandleImageUpload(input) {
    var file = input.files[0];
    if (!file) return;
    showToast('Upload en cours...', 'info');
    var formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    if (typeof getEditorSessionId === 'function') {
        var sid = getEditorSessionId();
        if (sid) formData.append('session_id', sid);
    }
    fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.url) {
                var editor = document.getElementById('ddiQuestionText');
                if (!editor) return;
                editor.focus();
                // Insérer image avec taille par défaut redimensionnable
                var img = new Image();
                img.onload = function() {
                    var natW = img.naturalWidth, natH = img.naturalHeight;
                    var displayH = Math.min(300, natH);
                    var displayW = Math.round(natW * (displayH / natH));
                    var imgHtml = '<img src="' + escapeHtml(data.url) + '" ' +
                        'width="' + displayW + '" height="' + displayH + '" ' +
                        'class="ddi-rt-img" style="max-width:100%; cursor:pointer;" ' +
                        'data-orig-w="' + natW + '" data-orig-h="' + natH + '">';
                    document.execCommand('insertHTML', false, imgHtml);
                    ddiOnQuestionTextInput();
                    ddiRtInitImageResizers();
                    showToast('Image insérée (' + natW + '×' + natH + 'px)', 'success');
                };
                img.onerror = function() {
                    document.execCommand('insertHTML', false, '<img src="' + escapeHtml(data.url) + '" style="max-width:100%;">');
                    ddiOnQuestionTextInput();
                    showToast('Image insérée', 'success');
                };
                img.src = data.url;
            } else {
                showToast('Erreur upload: ' + (data.error || 'inconnue'), 'error');
            }
        })
        .catch(function(err) { showToast('Erreur: ' + err.message, 'error'); });
    input.value = '';
}

// Rend les images dans le questiontext resizables au clic
function ddiRtInitImageResizers() {
    var editor = document.getElementById('ddiQuestionText');
    if (!editor) return;
    
    // Attacher le handler paste une seule fois
    if (!editor._ddiPasteInited) {
        editor._ddiPasteInited = true;
        editor.addEventListener('paste', ddiRtHandlePaste);
    }
    
    editor.querySelectorAll('img').forEach(function(img) {
        if (img._ddiResizeInited) return;
        img._ddiResizeInited = true;
        img.style.cursor = 'pointer';
        img.draggable = false;
        // Clic = sélection avec poignée
        img.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            ddiRtShowImageControls(img);
        });
    });
}

var _ddiRtActiveImg = null;
var _ddiRtWrap = null;

function ddiRtShowImageControls(img) {
    ddiRtHideImageControls();
    _ddiRtActiveImg = img;
    
    // Envelopper l'image dans un wrapper inline-block pour le resize
    var wrap = document.createElement('span');
    wrap.className = 'ddi-rt-img-wrap';
    wrap.contentEditable = 'false';
    wrap.style.cssText = 'display:inline-block; position:relative; line-height:0;';
    img.parentNode.insertBefore(wrap, img);
    wrap.appendChild(img);
    _ddiRtWrap = wrap;
    
    img.style.outline = '2px solid #1976d2';
    img.style.display = 'block';
    
    var w = img.width || img.naturalWidth;
    var h = img.height || img.naturalHeight;
    
    // Barre d'infos en bas
    var infoBar = document.createElement('div');
    infoBar.className = 'ddi-rt-img-info';
    infoBar.contentEditable = 'false';
    infoBar.style.cssText = 'display:flex; gap:6px; align-items:center; padding:2px 6px; font-size:0.7rem; color:#666; background:rgba(0,0,0,0.05); border-radius:0 0 4px 4px;';
    infoBar.innerHTML = '<span class="ddi-rt-img-size">' + w + ' × ' + h + '</span>' +
        '<button onclick="ddiRtResetImg()" style="background:none;border:1px solid #ccc;border-radius:3px;padding:0 4px;cursor:pointer;font-size:0.65rem;color:#666;" title="Taille originale">↻</button>' +
        '<button onclick="ddiRtRemoveImg()" style="background:none;border:1px solid #e57373;border-radius:3px;padding:0 4px;cursor:pointer;font-size:0.65rem;color:#d32f2f;" title="Supprimer">✕</button>';
    wrap.appendChild(infoBar);
    
    // Poignée de redimensionnement en bas à droite
    var handle = document.createElement('div');
    handle.className = 'ddi-rt-img-handle';
    handle.contentEditable = 'false';
    handle.style.cssText = 'position:absolute; bottom:20px; right:0; width:16px; height:16px; background:#1976d2; color:white; ' +
        'border-radius:3px 0 3px 0; cursor:se-resize; display:flex; align-items:center; justify-content:center; font-size:10px; z-index:10; user-select:none;';
    handle.textContent = '⤡';
    handle.title = 'Redimensionner';
    handle.addEventListener('mousedown', function(e) { ddiRtStartResize(e); });
    wrap.appendChild(handle);
    
    setTimeout(function() {
        document.addEventListener('mousedown', _ddiRtDocClick, true);
    }, 10);
}

function _ddiRtDocClick(e) {
    if (_ddiRtActiveImg && !e.target.closest('.ddi-rt-img-wrap') && !e.target.closest('.ddi-rt-img-handle')) {
        ddiRtHideImageControls();
    }
}

function ddiRtHideImageControls() {
    document.removeEventListener('mousedown', _ddiRtDocClick, true);
    if (_ddiRtActiveImg && _ddiRtWrap) {
        _ddiRtActiveImg.style.outline = '';
        _ddiRtActiveImg.style.display = '';
        // Dés-envelopper l'image
        _ddiRtWrap.parentNode.insertBefore(_ddiRtActiveImg, _ddiRtWrap);
        _ddiRtWrap.remove();
    }
    _ddiRtActiveImg = null;
    _ddiRtWrap = null;
}

function ddiRtStartResize(e) {
    e.preventDefault();
    e.stopPropagation();
    var img = _ddiRtActiveImg;
    if (!img) return;
    
    var startX = e.clientX;
    var startW = img.width || img.offsetWidth;
    var startH = img.height || img.offsetHeight;
    var ratio = startW / startH;
    
    function onMove(ev) {
        var dx = ev.clientX - startX;
        var newW = Math.max(32, Math.round(startW + dx));
        var newH = Math.round(newW / ratio);
        img.width = newW;
        img.height = newH;
        img.style.width = newW + 'px';
        img.style.height = newH + 'px';
        var sizeEl = _ddiRtWrap ? _ddiRtWrap.querySelector('.ddi-rt-img-size') : null;
        if (sizeEl) sizeEl.textContent = newW + ' × ' + newH;
    }
    
    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        ddiOnQuestionTextInput();
    }
    
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

function ddiRtResetImg() {
    if (!_ddiRtActiveImg) return;
    var img = _ddiRtActiveImg;
    var origW = parseInt(img.dataset.origW) || img.naturalWidth;
    var origH = parseInt(img.dataset.origH) || img.naturalHeight;
    img.width = origW;
    img.height = origH;
    img.style.width = origW + 'px';
    img.style.height = origH + 'px';
    var sizeEl = _ddiRtWrap ? _ddiRtWrap.querySelector('.ddi-rt-img-size') : null;
    if (sizeEl) sizeEl.textContent = origW + ' × ' + origH;
    ddiOnQuestionTextInput();
}

function ddiRtRemoveImg() {
    if (!_ddiRtActiveImg) return;
    var img = _ddiRtActiveImg;
    ddiRtHideImageControls();
    img.remove();
    _ddiRtActiveImg = null;
    ddiOnQuestionTextInput();
}

// ==================== RENDU CANVAS ====================

// Précharge l'image de fond pour récupérer ses dimensions naturelles et les
// stocker dans c.canvasWidth/c.canvasHeight. Indispensable pour les mbz importés
// (MbzParser ne remonte pas les dimensions de l'image bgimage). Sans cela, le canvas
// reste au ratio par défaut 800x600 et l'image est déformée.
function ddiSyncCanvasSizeToBg(c) {
    if (!c || !c.backgroundUrl) return;
    var probe = new Image();
    probe.onload = function() {
        var natW = probe.naturalWidth;
        var natH = probe.naturalHeight;
        if (!natW || !natH) return;
        var currentRatio = (c.canvasWidth || 1) / (c.canvasHeight || 1);
        var naturalRatio = natW / natH;
        if (Math.abs(currentRatio - naturalRatio) > 0.01) {
            c.canvasWidth = natW;
            c.canvasHeight = natH;
            if (typeof ddiRefreshCanvas === 'function') ddiRefreshCanvas();
        }
    };
    probe.src = c.backgroundUrl;
}

function ddiRenderCanvas(c) {
    var bg = c.backgroundUrl;
    var w = c.canvasWidth || 800;
    var h = c.canvasHeight || 600;
    
    // Zones de dépôt
    var zonesHtml = (c.drops || []).map(function(drop, idx) {
        var dw = drop.width || 100;
        var dh = drop.height || 30;
        var xPct = (drop.x / w) * 100;
        var yPct = (drop.y / h) * 100;
        var wPct = (dw / w) * 100;
        var hPct = (dh / h) * 100;
        var isSelected = _ddi.selectedDrop === idx;
        
        return '<div class="ddi-canvas-drop ' + (isSelected ? 'selected' : '') + '" ' +
            'data-idx="' + idx + '" ' +
            'style="position:absolute; left:' + xPct + '%; top:' + yPct + '%; width:' + wPct + '%; height:' + hPct + '%; ' +
            'border: 2px dashed rgba(156,39,176,0.7); border-radius: 4px; box-sizing: border-box; ' +
            'display: flex; align-items: center; justify-content: center; ' +
            'background: rgba(156,39,176,' + (isSelected ? '0.15' : '0.05') + '); ' +
            'z-index: ' + (isSelected ? 15 : 1) + '; cursor: move;' +
            (isSelected ? ' box-shadow: 0 0 8px rgba(156,39,176,0.5);' : '') + '" ' +
            'onmousedown="ddiStartDragDrop(event, ' + idx + ')" ' +
            'onclick="event.stopPropagation(); ddiSelectDrop(' + idx + ')">' +
            '<span style="font-size: 1.2em; font-weight: bold; color: rgba(156,39,176,0.9); pointer-events: none;">' + (idx + 1) + '</span>' +
            '<button onclick="event.stopPropagation(); ddiDeleteDrop(' + idx + ')" ' +
                'style="position: absolute; top: -8px; right: -8px; width: 18px; height: 18px; border-radius: 50%; ' +
                'background: #e53935; color: white; border: 2px solid white; font-size: 10px; font-weight: bold; ' +
                'cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 20;" ' +
                'title="Supprimer la zone">×</button>' +
            '<div class="ddi-resize-handle" onmousedown="ddiStartResizeDrop(event, ' + idx + ')"></div>' +
        '</div>';
    }).join('');
    
    var hasDrags = (c.drags || []).length > 0;

    var placeholder = '';
    if (!bg && !hasDrags && (c.drops || []).length === 0) {
        placeholder = '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); ' +
            'color: #999; font-size: 1em; text-align: center; pointer-events: none;">' +
            '🎯 Glisser-Déposer<br><small>Ajoutez une image de fond et des étiquettes</small></div>';
    }

    // Avec fond : le canvas prend la largeur naturelle de l'image (w px), sans cap,
    // et l'image (display: block, width: 100%, height: auto) détermine elle-même la
    // hauteur du canvas — garantit le ratio naturel de l'image, pas d'écrasement.
    // Le zoom (transform: scale) est appliqué au wrapper #ddiCanvasScale, pas au canvas.
    // Sans fond : fallback aspect-ratio w/h pour que le canvas ait une hauteur visible.
    var canvasStyle = bg
        ? 'width: ' + w + 'px;'
        : 'width: ' + w + 'px; aspect-ratio: ' + w + ' / ' + h + ';';
    var canvasHtml = '<div class="ddi-canvas" id="ddiCanvas" ' +
        'style="position: relative; ' + canvasStyle + ' ' +
            'background: #f5f5f5; border-radius: 8px; overflow: hidden; flex: 0 0 auto;" ' +
        'onclick="ddiDeselectAll()" ' +
        'onmousemove="ddiHandleMouseMove(event)" onmouseup="ddiHandleMouseUp(event)" onmouseleave="ddiHandleMouseUp(event)">' +
        (bg ? '<img src="' + escapeHtml(bg) + '" style="display: block; width: 100%; height: auto; pointer-events: none;" onload="_ddiApplyZoom()" onerror="this.style.display=\'none\'">' : '') +
        zonesHtml + placeholder +
    '</div>';

    // Colonne d'étiquettes à droite du canvas
    var stagingItems = '';
    (c.drags || []).forEach(function(drag, idx) {
        var isSelected = _ddi.selectedDrag === idx;
        var selStyle = isSelected
            ? 'border: 2px solid #1976d2; background: #e3f2fd; box-shadow: 0 0 6px rgba(25,118,210,0.4);'
            : 'border: 1px dashed #aaa; background: #fff;';
        if (drag.imageUrl) {
            stagingItems +=
                '<div class="ddi-canvas-drag ' + (isSelected ? 'selected' : '') + '" data-idx="' + idx + '" ' +
                'style="position: relative; display: flex; flex-direction: column; align-items: center; ' +
                'border-radius: 6px; padding: 6px 8px; cursor: pointer; ' + selStyle + '" ' +
                'onclick="event.stopPropagation(); ddiSelectDrag(' + idx + ')">' +
                '<img src="' + escapeHtml(drag.imageUrl) + '" data-src="' + escapeHtml(drag.imageUrl) + '" ' +
                    'style="display: block; height: auto; object-fit: contain; pointer-events: none;" ' +
                    'onload="_ddiApplyZoom()" onerror="ddiOnDragImgError(this)">' +
                '<span style="font-size: 0.8em; color: #1976d2; font-weight: 600; margin-top: 4px;">' + (idx + 1) + '</span>' +
                '<button onclick="event.stopPropagation(); ddiDeleteDrag(' + idx + ')" ' +
                    'style="position: absolute; top: 2px; right: 2px; width: 16px; height: 16px; border-radius: 50%; ' +
                    'background: #e53935; color: white; border: none; font-size: 10px; font-weight: bold; ' +
                    'cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 5; opacity: 0.7;" ' +
                    'onmouseover="this.style.opacity=\'1\'" onmouseout="this.style.opacity=\'0.7\'" title="Supprimer">×</button>' +
                '</div>';
        } else {
            stagingItems +=
                '<div class="ddi-canvas-drag ' + (isSelected ? 'selected' : '') + '" data-idx="' + idx + '" ' +
                'style="padding: 6px 10px; border-radius: 6px; font-size: 0.95em; cursor: pointer; ' + selStyle + '" ' +
                'onclick="event.stopPropagation(); ddiSelectDrag(' + idx + ')">' +
                '<span style="color: #1976d2; font-weight: 600; margin-right: 4px;">' + (idx + 1) + '.</span>' +
                escapeHtml(drag.label || 'Étiquette ' + (idx + 1)) +
                '</div>';
        }
    });

    // Enveloppe commune canvas + colonne étiquettes :
    // - #ddiCanvasScale reçoit transform: scale (zoom unifié des deux côté à côté)
    // - #ddiCanvasScaleOuter est dimensionné en pixels par _ddiApplyZoom pour que
    //   le scroll du wrap reflète la taille effectivement zoomée.
    var stagingHtml = hasDrags
        ? '<div class="ddi-staging-col" id="ddiStaging">' +
            '<span class="ddi-staging-label">🏷️ Étiquettes :</span>' +
            stagingItems +
          '</div>'
        : '';
    return '<div id="ddiCanvasScaleOuter" style="position: relative;">' +
        '<div class="ddi-canvas-row" id="ddiCanvasScale">' +
            canvasHtml +
            stagingHtml +
        '</div>' +
    '</div>';
}

// ==================== RENDU PANNEAU ====================

function ddiRenderPanel(c) {
    var html = '';
    
    // Vue détail d'une étiquette sélectionnée
    if (_ddi.selectedDrag !== null && _ddi.selectedDrag < c.drags.length) {
        html += ddiRenderDragDetail(c, _ddi.selectedDrag);
        return html;
    }
    
    // Vue détail d'une zone sélectionnée
    if (_ddi.selectedDrop !== null && _ddi.selectedDrop < c.drops.length) {
        html += ddiRenderDropDetail(c, _ddi.selectedDrop);
        return html;
    }
    
    // === Section: Image de fond ===
    html += '<div class="cp-dq-editor-section cp-dq-collapsible">' +
        '<div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">' +
            '<span>🖼️ Image de fond</span>' +
            '<span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>' +
        '</div>' +
        '<div class="cp-dq-collapse-content">' +
            '<div class="cp-dq-bg-actions" style="margin-bottom: 10px;">' +
                '<input type="file" id="ddiBgFile" class="cp-prop-input" accept="image/*" onchange="ddiUploadBackground(this)" style="display: none;">' +
                '<button class="cp-dq-bg-btn" onclick="document.getElementById(\'ddiBgFile\').click()">📁 Parcourir</button>' +
                '<button class="cp-dq-bg-btn" onclick="ddiPromptBackgroundUrl()">🔗 URL</button>' +
                (c.backgroundUrl ? '<button class="cp-dq-bg-btn danger" onclick="ddiClearBackground()">🗑️</button>' : '') +
            '</div>' +
        '</div>' +
    '</div>';
    
    // === Section: Automatisation ===
    html += '<div class="cp-dq-editor-section cp-dq-collapsible">' +
        '<div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">' +
            '<span>🤖 Automatisation</span>' +
            '<span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>' +
        '</div>' +
        '<div class="cp-dq-collapse-content">';
    
    // Dropdown des images proposées (presets)
    html += '<div class="cp-dq-preset-section" style="margin-bottom: 12px;">' +
        '<label style="font-size: 0.75rem; color: var(--text-secondary,#666); margin-bottom: 6px; display: block;">Images proposées:</label>' +
        '<div class="cp-dq-dropdown" style="position: relative;">' +
            '<div onclick="this.nextElementSibling.classList.toggle(\'open\')" style="width: 100%; padding: 8px; border: 1px solid var(--gray-300,#ddd); border-radius: 4px; font-size: 0.8rem; cursor: pointer; background: var(--bg-secondary,white); color: var(--text-primary,inherit); display: flex; align-items: center; gap: 8px;">' +
                (c.backgroundUrl ? '<img src="' + escapeHtml(c.backgroundUrl) + '" style="height: 30px; width: auto; border-radius: 2px;"><span style="flex:1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + escapeHtml((c.bgImageName || '').split('/').pop()) + '</span>' : '<span style="color: var(--text-muted,#999);">-- Sélectionner une image --</span>') +
                '<span style="color: var(--text-secondary,#666);">▼</span>' +
            '</div>' +
            '<div class="cp-dq-dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-secondary,white); border: 1px solid var(--gray-300,#ddd); border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; max-height: 200px; overflow-y: auto;">' +
                ddiPresetOptions() +
            '</div>' +
        '</div>' +
    '</div>';
    
    // Extraction de blocs
    html += '<div class="cp-dq-blocks-section">' +
        '<label style="font-size: 0.75rem; color: var(--text-secondary,#666); margin-bottom: 6px; display: block;">Programme Blocks ou Algorigramme:</label>' +
        '<div style="display: flex; gap: 6px; margin-bottom: 8px;">' +
            '<input type="file" id="ddiBlocksFile" accept="image/*" onchange="ddiExtractBlocksFromFile(this)" style="display: none;">' +
            '<button class="cp-dq-blocks-btn" onclick="document.getElementById(\'ddiBlocksFile\').click()">' +
                '🧩 Charger image' +
            '</button>' +
        '</div>' +
        '<div id="ddiBlocksStatus"></div>' +
    '</div>';
    
    html += '</div></div>';
    
    // === Section: Étiquettes (drags) ===
    html += '<div class="cp-dq-editor-section cp-dq-collapsible">' +
        '<div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">' +
            '<span>🏷️ Étiquettes <span class="cp-dq-count">' + c.drags.length + '</span></span>' +
            '<span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>' +
        '</div>' +
        '<div class="cp-dq-collapse-content">' +
            '<div style="display: flex; gap: 6px; margin-bottom: 10px;">' +
                '<button onclick="ddiAddDrag()" style="flex:1; padding: 8px; background: #1976d2; color: white; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer;">+ Texte</button>' +
                '<button onclick="ddiAddImageDrag()" style="flex:1; padding: 8px; background: #0d47a1; color: white; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer;">+ Image</button>' +
            '</div>';
    
    c.drags.forEach(function(drag, idx) {
        var isImage = !!drag.imageUrl;
        var displayText = isImage ? (drag.label || 'Image ' + (idx + 1)) : (drag.label || '');
        var groupColor = ['#1976d2', '#e65100', '#2e7d32', '#6a1b9a', '#c62828', '#00838f'][((drag.group || 1) - 1) % 6];
        
        html += '<div class="cp-dq-item" style="' + (_ddi.selectedDrag === idx ? 'border-color: #1976d2; background: rgba(25,118,210,0.05);' : '') + '" ' +
                     'onclick="ddiSelectDrag(' + idx + ')">' +
            '<div class="cp-dq-item-header">' +
                '<span class="cp-dq-item-title"><span class="cp-dq-item-num" style="background: ' + groupColor + ';">' + (idx + 1) + '</span> ' +
                    (isImage ? '🖼️' : '') + ' ' + escapeHtml(displayText.substring(0, 30)) + '</span>' +
                '<div class="cp-dq-item-actions">' +
                    '<button class="cp-dq-item-btn delete" onclick="event.stopPropagation(); ddiDeleteDrag(' + idx + ')" title="Supprimer">×</button>' +
                '</div>' +
            '</div>';
        
        if (!isImage) {
            html += '<input type="text" class="cp-prop-input" value="' + escapeHtml(drag.label || '') + '" ' +
                'placeholder="Texte de l\'étiquette" ' +
                'onclick="event.stopPropagation()" ' +
                'onchange="ddiUpdateDrag(' + idx + ', \'label\', this.value)" ' +
                'style="font-size: 0.85rem; margin-top: 6px;">';
        }
        
        // Dropdown bonne réponse
        if (c.drops.length > 0) {
            var correctDrops = c.drops.filter(function(d) { return d.choice === idx + 1; });
            var correctText = correctDrops.length === 0 ? 'Aucune zone' :
                correctDrops.map(function(d) { return d.no; }).join(', ');
            html += '<div style="font-size: 0.7rem; color: #9c27b0; margin-top: 4px;">Bonne réponse: Zone ' + correctText + '</div>';
        }
        
        html += '</div>';
    });
    
    html += '</div></div>';
    
    // === Section: Zones de dépôt (drops) ===
    html += '<div class="cp-dq-editor-section cp-dq-collapsible">' +
        '<div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">' +
            '<span>🎯 Zones de dépôt <span class="cp-dq-count">' + c.drops.length + '</span></span>' +
            '<span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>' +
        '</div>' +
        '<div class="cp-dq-collapse-content">' +
            '<button onclick="ddiAddDrop()" style="width: 100%; padding: 8px; background: #9c27b0; color: white; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer; margin-bottom: 10px;">+ Ajouter une zone de dépôt</button>';
    
    c.drops.forEach(function(drop, idx) {
        var choiceDrag = drop.choice > 0 ? c.drags[drop.choice - 1] : null;
        var choiceLabel = choiceDrag ? (choiceDrag.label || 'Étiquette ' + drop.choice) : 'Non assignée';
        var isSelected = _ddi.selectedDrop === idx;
        
        html += '<div class="cp-dq-item" style="border-left: 3px solid #9c27b0;' + (isSelected ? ' border-color: #9c27b0; background: rgba(156,39,176,0.05);' : '') + '" ' +
                     'onclick="ddiSelectDrop(' + idx + ')">' +
            '<div class="cp-dq-item-header">' +
                '<span class="cp-dq-item-title"><span class="cp-dq-item-num" style="background: #9c27b0; color: white;">' + (idx + 1) + '</span> Zone ' + (idx + 1) + '</span>' +
                '<div class="cp-dq-item-actions">' +
                    '<button class="cp-dq-item-btn delete" onclick="event.stopPropagation(); ddiDeleteDrop(' + idx + ')" title="Supprimer">×</button>' +
                '</div>' +
            '</div>' +
            '<div style="font-size: 0.75rem; color: #666; margin-top: 4px;">→ ' + escapeHtml(choiceLabel) + '</div>' +
            '<div style="font-size: 0.7rem; color: #999; margin-top: 2px;">x=' + Math.round(drop.x) + ' y=' + Math.round(drop.y) + '</div>' +
        '</div>';
    });
    
    html += '</div></div>';
    
    // === Section: Options ===
    html += '<div class="cp-dq-editor-section cp-dq-collapsible">' +
        '<div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">' +
            '<span>⚙️ Options</span>' +
            '<span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>' +
        '</div>' +
        '<div class="cp-dq-collapse-content">' +
            '<div class="cp-prop-group">' +
                '<label class="cp-prop-label">Tentatives</label>' +
                '<input type="number" class="cp-prop-input" value="' + (c.attempts_number || 1) + '" min="0" max="10" ' +
                    'onchange="ddiUpdateOption(\'attempts_number\', parseInt(this.value))" style="font-size: 0.85rem;">' +
            '</div>' +
            '<label class="cp-checkbox-label" style="margin-top: 8px;"><input type="checkbox" ' + (c.shuffleanswers ? 'checked' : '') + ' ' +
                'onchange="ddiUpdateOption(\'shuffleanswers\', this.checked ? 1 : 0)"> Mélanger les étiquettes</label>' +
        '</div>' +
    '</div>';
    
    return html;
}

// ==================== DÉTAIL ÉTIQUETTE / ZONE ====================

function ddiRenderDragDetail(c, idx) {
    var drag = c.drags[idx];
    var isImage = !!drag.imageUrl;
    var groupColor = ['#1976d2', '#e65100', '#2e7d32', '#6a1b9a', '#c62828', '#00838f'][((drag.group || 1) - 1) % 6];
    
    var html = '<div style="margin-bottom: 12px;">' +
        '<button onclick="_ddi.selectedDrag = null; ddiRefreshAll();" style="display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: var(--gray-100,#f5f5f5); border: 1px solid var(--gray-300,#ddd); border-radius: 4px; cursor: pointer; font-size: 0.8rem; color: var(--text-secondary,#666);">' +
            '← Retour' +
        '</button></div>';
    
    html += '<div class="cp-dq-editor-section">' +
        '<div class="cp-dq-editor-section-title" style="display: flex; align-items: center; gap: 8px;">' +
            '<span class="cp-dq-item-num" style="background: ' + groupColor + '; color: white;">' + (idx + 1) + '</span>' +
            '<span>' + (isImage ? '🖼️ Étiquette image' : '🏷️ Étiquette texte') + '</span>' +
        '</div>';
    
    if (isImage) {
        html += '<div style="margin: 10px 0; text-align: center; background: #f5f5f5; padding: 8px; border-radius: 6px; border: 1px solid #ddd;">' +
            '<img src="' + escapeHtml(drag.imageUrl) + '" style="max-width: 100%; max-height: 120px; object-fit: contain;" ' +
                'onerror="this.outerHTML=\'<span style=color:#999>Image introuvable</span>\'">' +
        '</div>' +
        '<div class="cp-prop-group"><label class="cp-prop-label">Texte alternatif</label>' +
            '<input type="text" class="cp-prop-input" value="' + escapeHtml(drag.label || '') + '" ' +
                'onchange="ddiUpdateDrag(' + idx + ', \'label\', this.value)" style="font-size: 0.85rem;">' +
        '</div>';
    } else {
        html += '<div class="cp-prop-group" style="margin-top: 10px;"><label class="cp-prop-label">Texte</label>' +
            '<input type="text" class="cp-prop-input" id="ddiDragText_' + idx + '" value="' + escapeHtml(drag.label || '') + '" ' +
                'onchange="ddiUpdateDrag(' + idx + ', \'label\', this.value)" style="font-size: 0.9rem; padding: 10px;">' +
        '</div>';
    }
    
    html += '<div class="cp-prop-group"><label class="cp-prop-label">Groupe</label>' +
        '<select class="cp-prop-input" onchange="ddiUpdateDrag(' + idx + ', \'group\', parseInt(this.value))" style="font-size: 0.85rem;">';
    for (var g = 1; g <= 6; g++) {
        html += '<option value="' + g + '" ' + (drag.group === g ? 'selected' : '') + '>Groupe ' + g + '</option>';
    }
    html += '</select></div>';
    
    html += '<label class="cp-checkbox-label" style="margin-top: 8px;"><input type="checkbox" ' + (drag.infinite ? 'checked' : '') + ' ' +
        'onchange="ddiUpdateDrag(' + idx + ', \'infinite\', this.checked)"> Réutilisable (infini)</label>';
    
    // Bonne réponse : quelles zones acceptent cette étiquette
    if (c.drops.length > 0) {
        html += '<div style="margin-top: 15px;"><label class="cp-prop-label">Bonne réponse (zone correcte)</label>' +
            '<div style="display: flex; flex-direction: column; gap: 4px; margin-top: 8px;">';
        // Option "aucune zone"
        var hasNoZone = !c.drops.some(function(d) { return d.choice === idx + 1; });
        html += '<label style="display: flex; align-items: center; gap: 10px; padding: 10px; cursor: pointer; border: 1px solid #ddd; border-radius: 4px; background: ' + (hasNoZone ? 'rgba(158,158,158,0.1)' : 'white') + ';">' +
            '<input type="radio" name="ddiDragCorrect_' + idx + '" ' + (hasNoZone ? 'checked' : '') + ' ' +
                'onchange="ddiUnassignDrag(' + idx + ')" ' +
                'style="width: 18px; height: 18px; accent-color: #999;">' +
            '<span style="color: #999;">Aucune zone</span>' +
        '</label>';
        c.drops.forEach(function(drop, dIdx) {
            var isCorrect = drop.choice === idx + 1;
            html += '<label style="display: flex; align-items: center; gap: 10px; padding: 10px; cursor: pointer; border: 1px solid #ddd; border-radius: 4px; background: ' + (isCorrect ? 'rgba(156,39,176,0.08)' : 'white') + ';">' +
                '<input type="radio" name="ddiDragCorrect_' + idx + '" ' + (isCorrect ? 'checked' : '') + ' ' +
                    'onchange="ddiAssignDragToZone(' + idx + ', ' + dIdx + ')" ' +
                    'style="width: 18px; height: 18px; accent-color: #9c27b0;">' +
                '<span style="font-weight: 600; color: #9c27b0;">Zone ' + (dIdx + 1) + '</span>' +
            '</label>';
        });
        html += '</div></div>';
    }
    
    html += '<div style="margin-top: 15px;">' +
        '<button onclick="ddiDeleteDrag(' + idx + '); _ddi.selectedDrag = null; ddiRefreshAll();" ' +
            'style="width: 100%; padding: 10px; background: #f44336; color: white; border: none; border-radius: 4px; font-size: 0.85rem; cursor: pointer;">' +
            '🗑️ Supprimer cette étiquette</button></div>';
    
    html += '</div>';
    return html;
}

function ddiRenderDropDetail(c, idx) {
    var drop = c.drops[idx];
    
    var html = '<div style="margin-bottom: 12px;">' +
        '<button onclick="_ddi.selectedDrop = null; ddiRefreshAll();" style="display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; font-size: 0.8rem; color: #666;">' +
            '← Retour' +
        '</button></div>';
    
    html += '<div class="cp-dq-editor-section">' +
        '<div class="cp-dq-editor-section-title" style="display: flex; align-items: center; gap: 8px;">' +
            '<span class="cp-dq-item-num" style="background: #9c27b0; color: white;">' + (idx + 1) + '</span>' +
            '<span>🎯 Zone de dépôt</span>' +
        '</div>';
    
    html += '<div style="display: flex; gap: 8px; margin-top: 10px;">' +
        '<div class="cp-prop-group" style="flex:1;"><label class="cp-prop-label">X (px)</label>' +
            '<input type="number" class="cp-prop-input" value="' + Math.round(drop.x) + '" min="0" ' +
                'onchange="ddiUpdateDrop(' + idx + ', \'x\', parseFloat(this.value))" style="font-size: 0.85rem;"></div>' +
        '<div class="cp-prop-group" style="flex:1;"><label class="cp-prop-label">Y (px)</label>' +
            '<input type="number" class="cp-prop-input" value="' + Math.round(drop.y) + '" min="0" ' +
                'onchange="ddiUpdateDrop(' + idx + ', \'y\', parseFloat(this.value))" style="font-size: 0.85rem;"></div>' +
    '</div>';
    
    html += '<div style="display: flex; gap: 8px; margin-top: 6px;">' +
        '<div class="cp-prop-group" style="flex:1;"><label class="cp-prop-label">Largeur</label>' +
            '<input type="number" class="cp-prop-input" value="' + Math.round(drop.width || 100) + '" min="20" ' +
                'onchange="ddiUpdateDrop(' + idx + ', \'width\', parseFloat(this.value))" style="font-size: 0.85rem;"></div>' +
        '<div class="cp-prop-group" style="flex:1;"><label class="cp-prop-label">Hauteur</label>' +
            '<input type="number" class="cp-prop-input" value="' + Math.round(drop.height || 30) + '" min="15" ' +
                'onchange="ddiUpdateDrop(' + idx + ', \'height\', parseFloat(this.value))" style="font-size: 0.85rem;"></div>' +
    '</div>';
    
    // Bonne réponse : quelle étiquette va dans cette zone
    html += '<div class="cp-prop-group" style="margin-top: 10px;"><label class="cp-prop-label">Bonne réponse (étiquette)</label>' +
        '<select class="cp-prop-input" onchange="ddiSetDropChoice(' + idx + ', parseInt(this.value))" style="font-size: 0.85rem;">' +
            '<option value="0" ' + (!drop.choice ? 'selected' : '') + '>-- Non assignée --</option>';
    c.drags.forEach(function(drag, dIdx) {
        html += '<option value="' + (dIdx + 1) + '" ' + (drop.choice === dIdx + 1 ? 'selected' : '') + '>' +
            (dIdx + 1) + '. ' + escapeHtml(drag.label || 'Étiquette') + '</option>';
    });
    html += '</select></div>';
    
    html += '<div style="margin-top: 15px;">' +
        '<button onclick="ddiDeleteDrop(' + idx + '); _ddi.selectedDrop = null; ddiRefreshAll();" ' +
            'style="width: 100%; padding: 10px; background: #f44336; color: white; border: none; border-radius: 4px; font-size: 0.85rem; cursor: pointer;">' +
            '🗑️ Supprimer cette zone</button></div>';
    
    html += '</div>';
    return html;
}

// ==================== ACTIONS DRAGS ====================

function ddiAddDrag() {
    var c = ddiEnsureContent(ddiGetActivity());
    var no = c.drags.length + 1;
    c.drags.push({ no: no, label: 'Étiquette ' + no, group: 1, infinite: false, imageUrl: null, imageName: null });
    onCourseModified(); ddiRefreshAll();
}

function ddiAddImageDrag() {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function() {
        if (!input.files || !input.files[0]) return;
        ddiUploadDragImage(input.files[0], function(url, name) {
            var c = ddiEnsureContent(ddiGetActivity());
            var no = c.drags.length + 1;
            c.drags.push({ no: no, label: name || 'Image ' + no, group: 1, infinite: false, imageUrl: url, imageName: name });
            onCourseModified(); ddiRefreshAll();
        });
    };
    input.click();
}

function ddiDeleteDrag(idx) {
    var c = ddiEnsureContent(ddiGetActivity());
    var removedNo = idx + 1;
    c.drags.splice(idx, 1);
    // Renuméroter
    c.drags.forEach(function(d, i) { d.no = i + 1; });
    // Mettre à jour les choices des drops
    c.drops.forEach(function(d) {
        if (d.choice === removedNo) d.choice = 0;
        else if (d.choice > removedNo) d.choice--;
    });
    if (_ddi.selectedDrag === idx) _ddi.selectedDrag = null;
    onCourseModified(); ddiRefreshAll();
}

function ddiUpdateDrag(idx, prop, value) {
    var c = ddiEnsureContent(ddiGetActivity());
    if (c.drags[idx]) {
        c.drags[idx][prop] = value;
        onCourseModified();
        // Ne pas refresh all pour les inputs texte
        if (prop !== 'label') ddiRefreshAll();
    }
}

function ddiSelectDrag(idx) {
    _ddi.selectedDrag = idx;
    _ddi.selectedDrop = null;
    ddiRefreshAll();
}

// ==================== ACTIONS DROPS ====================

function ddiAddDrop() {
    var c = ddiEnsureContent(ddiGetActivity());
    var no = c.drops.length + 1;
    c.drops.push({ no: no, x: 100 + no * 20, y: 100 + no * 20, choice: 0, label: String(no), width: 120, height: 35 });
    onCourseModified(); ddiRefreshAll();
}

function ddiDeleteDrop(idx) {
    var c = ddiEnsureContent(ddiGetActivity());
    c.drops.splice(idx, 1);
    c.drops.forEach(function(d, i) { d.no = i + 1; d.label = String(i + 1); });
    if (_ddi.selectedDrop === idx) _ddi.selectedDrop = null;
    onCourseModified(); ddiRefreshAll();
}

function ddiUpdateDrop(idx, prop, value) {
    var c = ddiEnsureContent(ddiGetActivity());
    if (c.drops[idx]) {
        c.drops[idx][prop] = value;
        onCourseModified(); ddiRefreshCanvas();
    }
}

function ddiSetDropChoice(dropIdx, choice) {
    var c = ddiEnsureContent(ddiGetActivity());
    if (!c.drops[dropIdx]) return;
    // Si on assigne un drag à cette zone, désassigner ce drag des autres zones
    if (choice > 0) {
        c.drops.forEach(function(d, dIdx) {
            if (dIdx !== dropIdx && d.choice === choice) {
                d.choice = 0;
            }
        });
    }
    c.drops[dropIdx].choice = choice;
    onCourseModified(); ddiRefreshAll();
}

// Assigner un drag à une zone, en désassignant automatiquement le drag précédent sur cette zone
function ddiAssignDragToZone(dragIdx, dropIdx) {
    var c = ddiEnsureContent(ddiGetActivity());
    if (!c.drops[dropIdx]) return;
    // Désassigner cette zone de tout autre drag qui l'utilisait
    c.drops[dropIdx].choice = dragIdx + 1;
    // Désassigner ce drag de toute autre zone où il était
    c.drops.forEach(function(d, dIdx) {
        if (dIdx !== dropIdx && d.choice === dragIdx + 1) {
            d.choice = 0;
        }
    });
    onCourseModified(); ddiRefreshAll();
}

// Désassigner un drag de toutes les zones
function ddiUnassignDrag(dragIdx) {
    var c = ddiEnsureContent(ddiGetActivity());
    c.drops.forEach(function(d) {
        if (d.choice === dragIdx + 1) d.choice = 0;
    });
    onCourseModified(); ddiRefreshAll();
}

function ddiSelectDrop(idx) {
    _ddi.selectedDrop = idx;
    _ddi.selectedDrag = null;
    ddiRefreshAll();
}

function ddiDeselectAll() {
    if (_ddi.selectedDrag !== null || _ddi.selectedDrop !== null) {
        _ddi.selectedDrag = null;
        _ddi.selectedDrop = null;
        ddiRefreshAll();
    }
}

function ddiUpdateOption(prop, value) {
    var c = ddiEnsureContent(ddiGetActivity());
    c[prop] = value;
    onCourseModified();
}

// ==================== IMAGE DE FOND ====================

function ddiUploadBackground(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    ddiUploadBlob(file, file.name, function(url) {
        var c = ddiEnsureContent(ddiGetActivity());
        c.backgroundUrl = url;
        c.bgImageName = file.name;
        // Lire les dimensions de l'image
        var img = new Image();
        img.onload = function() {
            c.canvasWidth = img.width;
            c.canvasHeight = img.height;
            onCourseModified(); ddiRefreshAll();
        };
        img.onerror = function() { onCourseModified(); ddiRefreshAll(); };
        img.src = url;
    });
}

function ddiPromptBackgroundUrl() {
    var url = prompt('URL de l\'image de fond:');
    if (!url) return;
    var c = ddiEnsureContent(ddiGetActivity());
    c.backgroundUrl = url;
    c.bgImageName = url.split('/').pop();
    var img = new Image();
    img.onload = function() {
        c.canvasWidth = img.width;
        c.canvasHeight = img.height;
        onCourseModified(); ddiRefreshAll();
    };
    img.onerror = function() { onCourseModified(); ddiRefreshAll(); };
    img.src = url;
}

function ddiClearBackground() {
    var c = ddiEnsureContent(ddiGetActivity());
    c.backgroundUrl = null;
    c.bgImageName = null;
    onCourseModified(); ddiRefreshAll();
}

function ddiSetBackgroundUrl(url) {
    var c = ddiEnsureContent(ddiGetActivity());
    c.backgroundUrl = url;
    c.bgImageName = url.split('/').pop();
    var img = new Image();
    img.onload = function() { c.canvasWidth = img.width; c.canvasHeight = img.height; onCourseModified(); ddiRefreshAll(); };
    img.onerror = function() { onCourseModified(); ddiRefreshAll(); };
    img.src = url;
}

// ==================== UPLOAD HELPER ====================

function ddiUploadBlob(blob, filename, onSuccess, onError) {
    ddiUploadBlobWithRetry(blob, filename, onSuccess, onError, 3);
}

function ddiUploadBlobWithRetry(blob, filename, onSuccess, onError, retriesLeft) {
    var formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', blob, filename || 'image.png');
    // Envoyer session_id pour que DriveSync puisse flush vers le Drive
    if (typeof getEditorSessionId === 'function') {
        var sid = getEditorSessionId();
        if (sid) formData.append('session_id', sid);
    }
    fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.url) { if (onSuccess) onSuccess(data.url); }
            else {
                if (retriesLeft > 0) {
                    console.warn('[DDI] Upload failed for ' + filename + ', retrying... (' + retriesLeft + ' left)');
                    setTimeout(function() { ddiUploadBlobWithRetry(blob, filename, onSuccess, onError, retriesLeft - 1); }, 500);
                } else {
                    console.error('[DDI] Upload failed after retries for ' + filename + ':', data.error);
                    showToast('Erreur upload: ' + (data.error || 'inconnue'), 'error');
                    if (onError) onError();
                }
            }
        })
        .catch(function(err) {
            if (retriesLeft > 0) {
                console.warn('[DDI] Upload network error for ' + filename + ', retrying...', err.message);
                setTimeout(function() { ddiUploadBlobWithRetry(blob, filename, onSuccess, onError, retriesLeft - 1); }, 500);
            } else {
                console.error('[DDI] Upload network error after retries for ' + filename);
                showToast('Erreur réseau: ' + err.message, 'error');
                if (onError) onError();
            }
        });
}

// Upload séquentiel : prend une liste de {blob, filename} et les upload un par un
function ddiUploadBlobsSequential(items, onAllDone) {
    var results = new Array(items.length);
    var idx = 0;
    function next() {
        if (idx >= items.length) { onAllDone(results); return; }
        var item = items[idx];
        var currentIdx = idx;
        idx++;
        if (!item || !item.blob) { results[currentIdx] = null; next(); return; }
        ddiUploadBlob(item.blob, item.filename, function(url) {
            results[currentIdx] = url;
            next();
        }, function() {
            results[currentIdx] = null;
            next();
        });
    }
    next();
}

function ddiUploadDragImage(file, callback) {
    ddiUploadBlob(file, file.name, function(url) {
        callback(url, file.name);
    });
}

// ==================== DRAG & RESIZE DES ZONES SUR LE CANVAS ====================

function ddiStartDragDrop(event, idx) {
    event.preventDefault();
    event.stopPropagation();
    var canvas = document.getElementById('ddiCanvas');
    if (!canvas) return;
    var rect = canvas.getBoundingClientRect();
    var c = ddiEnsureContent(ddiGetActivity());
    var drop = c.drops[idx];
    _ddi.dragging = {
        type: 'drop', idx: idx,
        startX: event.clientX, startY: event.clientY,
        origX: drop.x, origY: drop.y,
        canvasW: rect.width, canvasH: rect.height,
        dataW: c.canvasWidth, dataH: c.canvasHeight
    };
    _ddi.selectedDrop = idx;
    _ddi.selectedDrag = null;
    ddiRefreshPanel();
}

function ddiStartResizeDrop(event, idx) {
    event.preventDefault();
    event.stopPropagation();
    var canvas = document.getElementById('ddiCanvas');
    if (!canvas) return;
    var rect = canvas.getBoundingClientRect();
    var c = ddiEnsureContent(ddiGetActivity());
    var drop = c.drops[idx];
    _ddi.resizing = {
        type: 'drop', idx: idx,
        startX: event.clientX, startY: event.clientY,
        origW: drop.width || 100, origH: drop.height || 30,
        canvasW: rect.width, canvasH: rect.height,
        dataW: c.canvasWidth, dataH: c.canvasHeight
    };
}

function ddiHandleMouseMove(event) {
    if (_ddi.dragging) {
        var d = _ddi.dragging;
        var dx = (event.clientX - d.startX) / d.canvasW * d.dataW;
        var dy = (event.clientY - d.startY) / d.canvasH * d.dataH;
        var c = ddiEnsureContent(ddiGetActivity());
        var drop = c.drops[d.idx];
        if (drop) {
            drop.x = Math.max(0, Math.min(d.dataW - (drop.width || 100), d.origX + dx));
            drop.y = Math.max(0, Math.min(d.dataH - (drop.height || 30), d.origY + dy));
            ddiRefreshCanvas();
        }
    }
    if (_ddi.resizing) {
        var r = _ddi.resizing;
        var dw = (event.clientX - r.startX) / r.canvasW * r.dataW;
        var dh = (event.clientY - r.startY) / r.canvasH * r.dataH;
        var c = ddiEnsureContent(ddiGetActivity());
        var drop = c.drops[r.idx];
        if (drop) {
            drop.width = Math.max(30, r.origW + dw);
            drop.height = Math.max(15, r.origH + dh);
            ddiRefreshCanvas();
        }
    }
}

function ddiHandleMouseUp(event) {
    if (_ddi.dragging || _ddi.resizing) {
        _ddi.dragging = null;
        _ddi.resizing = null;
        onCourseModified();
        ddiRefreshPanel(); // Refresh panel pour les coordonnées mises à jour
    }
}

// ==================== PRESETS (IMAGES PROPOSÉES) ====================

var DDI_PRESETS = null; // Lazy load from CP_DQ_PRESETS

function ddiPresetOptions() {
    var items = [
        { name: 'capteurs-actionneurs', label: 'Capteurs-Actionneurs', img: 'assets/images/dragdrop/_Capteurs-Actionneurs.png' },
        { name: 'actionneurs', label: 'Actionneurs', img: 'assets/images/dragdrop/_Actionneurs.png' },
        { name: 'capteurs', label: 'Capteurs', img: 'assets/images/dragdrop/_Capteurs.png' },
        { name: 'chaine-information', label: 'Chaîne d\'information', img: 'assets/images/dragdrop/chaine-information.png' },
        { name: 'chaine-energie', label: 'Chaîne d\'énergie', img: 'assets/images/dragdrop/chaine-energie.png' },
        { name: 'chaine-complete', label: 'Chaîne complète', img: 'assets/images/dragdrop/chaine-complete.png' }
    ];
    return items.map(function(item) {
        return '<div onclick="ddiApplyPreset(\'' + item.name + '\'); this.parentElement.classList.remove(\'open\');" ' +
            'style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #eee;" ' +
            'onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'white\'">' +
            '<img src="' + item.img + '" style="height: 40px; width: auto; border-radius: 2px;">' +
            '<span>' + item.label + '</span></div>';
    }).join('');
}

function ddiApplyPreset(presetName) {
    // Utiliser les presets de CP_DQ_PRESETS s'ils existent
    if (typeof CP_DQ_PRESETS !== 'undefined' && CP_DQ_PRESETS[presetName]) {
        var preset = CP_DQ_PRESETS[presetName];
        var bgUrl = preset.image || preset.background;
        
        if (!bgUrl) {
            showToast('Preset sans image de fond', 'error');
            return;
        }
        
        // Copier l'image template vers les uploads pour qu'elle soit utilisable à l'export
        fetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=copy_image&source_type=template&source=' + encodeURIComponent(bgUrl.split('/').pop())
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var finalUrl = data.url || bgUrl;
            
            // Charger l'image pour obtenir les dimensions
            var img = new Image();
            img.onload = function() {
                var activity = (typeof _qsDdiEditIdx !== 'undefined' && _qsDdiEditIdx !== null && window._qsDdiTempActivity) 
                    ? window._qsDdiTempActivity : ddiGetActivity();
                var c = ddiEnsureContent(activity);
                c.backgroundUrl = finalUrl;
                c.bgImageName = finalUrl.split('/').pop();
                c.canvasWidth = img.width;
                c.canvasHeight = img.height;
                
                // Convertir les zones H5P en drops DDI (positions en pixels)
                c.drops = [];
                c.drags = [];
                
                if (preset.dropZones) {
                    preset.dropZones.forEach(function(dz, idx) {
                        var xPx = (dz.x / 100) * img.width;
                        var yPx = (dz.y / 100) * img.height;
                        var wPx = (dz.w || dz.width || 6.5) * 16;
                        var hPx = (dz.h || dz.height || 3.5) * 16;
                        
                        c.drops.push({ no: idx + 1, x: Math.round(xPx), y: Math.round(yPx), choice: idx + 1, label: String(idx + 1), width: Math.round(wPx), height: Math.round(hPx) });
                    });
                }
                
                // Créer les drags depuis les elements, ou un drag par dropZone si elements est vide
                var dragSource = (preset.elements && preset.elements.length > 0) ? preset.elements : (preset.dropZones || []);
                dragSource.forEach(function(el, idx) {
                    var label = '';
                    if (el.type && el.type.params) {
                        label = (el.type.params.text || el.type.params.alt || '').replace(/<[^>]*>/g, '');
                    }
                    c.drags.push({ no: idx + 1, label: label || 'Étiquette ' + (idx + 1), group: 1, infinite: false, imageUrl: null, imageName: null });
                });
                
                onCourseModified(); ddiRefreshAll();
                showToast('Preset "' + presetName + '" appliqué', 'success');
            };
            img.onerror = function() {
                showToast('Impossible de charger l\'image du preset', 'error');
            };
            img.src = finalUrl;
        })
        .catch(function() {
            // Fallback: utiliser l'URL directement
            var img = new Image();
            img.onload = function() {
                var activity = (typeof _qsDdiEditIdx !== 'undefined' && _qsDdiEditIdx !== null && window._qsDdiTempActivity) 
                    ? window._qsDdiTempActivity : ddiGetActivity();
                var c = ddiEnsureContent(activity);
                c.backgroundUrl = bgUrl;
                c.bgImageName = bgUrl.split('/').pop();
                c.canvasWidth = img.width;
                c.canvasHeight = img.height;
                c.drops = [];
                c.drags = [];
                onCourseModified(); ddiRefreshAll();
                showToast('Preset "' + presetName + '" appliqué', 'success');
            };
            img.onerror = function() {
                showToast('Impossible de charger l\'image du preset', 'error');
            };
            img.src = bgUrl;
        });
    }
}

// ==================== EXTRACTION DE BLOCS (makecode_extract.js) ====================

function ddiExtractBlocksFromFile(input) {
    if (!input.files || !input.files[0]) return;
    ddiLoadImageAndExtract(input.files[0]);
    input.value = '';
}

function ddiExtractBlocksFromClipboard() {
    // Appelé quand on colle une image dans l'éditeur DDI
    if (navigator.clipboard && navigator.clipboard.read) {
        navigator.clipboard.read().then(function(items) {
            for (var i = 0; i < items.length; i++) {
                var imageType = items[i].types.find(function(t) { return t.indexOf('image/') === 0; });
                if (imageType) {
                    items[i].getType(imageType).then(function(blob) {
                        ddiLoadImageAndExtract(blob);
                    });
                    return;
                }
            }
        }).catch(function() {});
    }
}

function ddiLoadImageAndExtract(blob) {
    var statusEl = document.getElementById('ddiBlocksStatus');
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Chargement de l\'image...</div>';
    
    var url = URL.createObjectURL(blob);
    var img = new Image();
    img.onload = function() {
        URL.revokeObjectURL(url);
        var canvas = document.createElement('canvas');
        canvas.width = img.width;
        canvas.height = img.height;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        var imageData = ctx.getImageData(0, 0, img.width, img.height);
        
        if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Extraction des blocs...</div>';
        showToast('Extraction en cours...', 'info');
        
        setTimeout(function() {
            try {
                var result = MKExtract.extract(imageData);
                ddiProcessExtractionResult(result, imageData, canvas);
            } catch(e) {
                if (statusEl) statusEl.innerHTML = '<div style="color:#d32f2f; font-size: 0.7rem;">Erreur: ' + e.message + '</div>';
                showToast('Erreur extraction: ' + e.message, 'error');
                console.error(e);
            }
        }, 50);
    };
    img.onerror = function() {
        URL.revokeObjectURL(url);
        if (statusEl) statusEl.innerHTML = '<div style="color:#d32f2f; font-size: 0.7rem;">Impossible de charger l\'image</div>';
        showToast('Image invalide', 'error');
    };
    img.src = url;
}

function ddiProcessExtractionResult(result, imageData, srcCanvas) {
    var manifest = result.manifest;
    if (manifest.imageType === 'flowchart') {
        ddiProcessFlowchartResult(result, imageData, srcCanvas);
    } else {
        ddiProcessBlocksResult(result, imageData, srcCanvas);
    }
}

// ==================== TRAITEMENT FLOWCHART ====================
// Identique au CP : masques pixel par pixel, transparence, blanchiment des zones

function ddiProcessFlowchartResult(result, imageData, srcCanvas) {
    var manifest = result.manifest;
    var labelMasks = result.labelMasks;
    var w = manifest.size.w, h = manifest.size.h;
    var rgba = imageData.data;
    var statusEl = document.getElementById('ddiBlocksStatus');
    var labels = manifest.labels || [];
    var labelSize = manifest.labelSize;
    
    if (labels.length === 0) {
        if (statusEl) statusEl.innerHTML = '<div style="color:#f57c00; font-size: 0.7rem;">Aucune étiquette détectée</div>';
        showToast('Aucune étiquette détectée', 'warning');
        return;
    }
    
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Génération (' + labels.length + ' étiquettes)...</div>';
    
    // 1. Image de fond : original avec les zones de texte blanchies, taille source uniquement
    var bgCanvas = document.createElement('canvas');
    bgCanvas.width = w; bgCanvas.height = h;
    var bgCtx = bgCanvas.getContext('2d');
    bgCtx.fillStyle = '#ffffff'; bgCtx.fillRect(0, 0, w, h);
    bgCtx.drawImage(srcCanvas, 0, 0);

    // Blanchir les zones de texte via les masques
    var bgImageData = bgCtx.getImageData(0, 0, w, h);
    var bgData = bgImageData.data;
    for (var li = 0; li < labels.length; li++) {
        var mask = labelMasks[li];
        for (var idx = 0; idx < w * h; idx++) {
            if (mask[idx]) {
                var o = idx * 4;
                bgData[o] = 255; bgData[o + 1] = 255; bgData[o + 2] = 255; bgData[o + 3] = 255;
            }
        }
    }
    bgCtx.putImageData(bgImageData, 0, 0);


    // 2. Générer les PNG de chaque étiquette (texte sur fond transparent via masques)
    var labelCanvases = [];
    for (var li = 0; li < labels.length; li++) {
        var label = labels[li];
        var lx = Math.max(0, label.pos.x), ly = Math.max(0, label.pos.y);
        var lw = label.size.w, lh = label.size.h;
        var mask = labelMasks[li];
        
        var labelCanvas = document.createElement('canvas');
        labelCanvas.width = lw; labelCanvas.height = lh;
        var labelCtx = labelCanvas.getContext('2d');
        var labelImgData = labelCtx.createImageData(lw, lh);
        var ld = labelImgData.data;
        
        for (var py = 0; py < lh; py++) {
            for (var px = 0; px < lw; px++) {
                var srcX = lx + px, srcY = ly + py;
                if (srcX < 0 || srcX >= w || srcY < 0 || srcY >= h) continue;
                var srcIdx = srcY * w + srcX;
                var dstOff = (py * lw + px) * 4;
                var srcOff = srcIdx * 4;
                if (mask[srcIdx]) {
                    ld[dstOff] = rgba[srcOff]; ld[dstOff + 1] = rgba[srcOff + 1];
                    ld[dstOff + 2] = rgba[srcOff + 2]; ld[dstOff + 3] = 255;
                }
            }
        }
        labelCtx.putImageData(labelImgData, 0, 0);
        labelCanvases.push(labelCanvas);
    }

    // Détection + fusion des étiquettes dupliquées : repeintes sur le fond, retirées des drags
    var sigs = labelCanvases.map(sigFromCanvas);
    var paramsFlags = labelCanvases.map(paramFromCanvas);
    var dupGroups = thresholdClustering(labels, sigs, undefined, undefined, paramsFlags);
    var mergeRes = mergeDuplicatesIntoBgCanvas(bgCanvas, labelCanvases, labels, dupGroups);
    labels = mergeRes.keptIndices.map(function(i) { return labels[i]; });
    labelCanvases = mergeRes.keptIndices.map(function(i) { return labelCanvases[i]; });

    // 3. Uploader tout SÉQUENTIELLEMENT (fond d'abord, puis étiquettes une par une)
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Upload fond...</div>';
    
    bgCanvas.toBlob(function(bgBlob) {
        ddiUploadBlob(bgBlob, 'background.png', function(bgUrl) {
            // Convertir les canvases en blobs puis uploader séquentiellement
            var blobItems = [];
            var converted = 0;
            labelCanvases.forEach(function(lc, i) {
                lc.toBlob(function(blob) {
                    blobItems[i] = { blob: blob, filename: 'label_' + i + '.png' };
                    converted++;
                    if (converted >= labelCanvases.length) {
                        if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Upload étiquettes...</div>';
                        ddiUploadBlobsSequential(blobItems, function(labelUrls) {
                            ddiApplyExtraction({
                                source_size: { w: w, h: h },
                                background_url: bgUrl,
                                maxBlockSize: { w: labelSize.w, h: labelSize.h },
                                pad: 0,
                                blocks: labels.map(function(l, i) {
                                    return { id: l.id, url: labelUrls[i], pos: l.pos, size: l.size, type: 'block', name: 'label_' + i + '.png' };
                                })
                            });
                        });
                    }
                }, 'image/png');
            });
        }, function() {
            if (statusEl) statusEl.innerHTML = '<div style="color:#d32f2f; font-size: 0.7rem;">Erreur upload fond</div>';
        });
    }, 'image/png');
}

// ==================== TRAITEMENT BLOCS (MakeCode) ====================
// Identique au CP : séparation conteneurs/blocs d'action, masques pixel par pixel, transparence

function ddiProcessBlocksResult(result, imageData, srcCanvas) {
    console.log('[BlockExtract] MKExtract version:', result._version, '| blocks:', result.manifest.blocks.length,
        result.manifest.blocks.map(function(b) { return b.type + ' (' + b.pos.x + ',' + b.pos.y + ') ' + b.size.w + 'x' + b.size.h; }).join(' | '));
    var manifest = result.manifest;
    var blockMasks = result.blockMasks;
    var bgColor = result.bgColor;
    var w = manifest.size.w, h = manifest.size.h;
    var rgba = imageData.data;
    var statusEl = document.getElementById('ddiBlocksStatus');
    
    // Séparer conteneurs et blocs d'action
    var containerBlocks = manifest.blocks.filter(function(b) { return b.type === 'container'; });
    var actionBlocks = manifest.blocks.filter(function(b) { return b.type === 'block' || b.type === 'diamond'; });
    
    if (actionBlocks.length === 0) {
        if (statusEl) statusEl.innerHTML = '<div style="color:#f57c00; font-size: 0.7rem;">Aucun bloc d\'action détecté</div>';
        showToast('Aucun bloc d\'action détecté', 'warning');
        return;
    }
    
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Génération des images (' + actionBlocks.length + ' blocs)...</div>';
    
    var PAD = 3;
    var maxBlockW = 0, maxBlockH = 0;
    actionBlocks.forEach(function(b) {
        var realW = b.size.w - 2 * PAD;
        var realH = b.size.h - 2 * PAD;
        if (realW > maxBlockW) maxBlockW = realW;
        if (realH > maxBlockH) maxBlockH = realH;
    });
    
    // 1. Image de fond : FOND BLANC + conteneurs dessinés depuis l'original (SANS blocs d'action), taille source uniquement
    var bgCanvas = document.createElement('canvas');
    bgCanvas.width = w; bgCanvas.height = h;
    var bgCtx = bgCanvas.getContext('2d');
    bgCtx.fillStyle = '#ffffff'; bgCtx.fillRect(0, 0, w, h);
    
    if (containerBlocks.length > 0) {
        var actionMask = new Uint8Array(w * h);
        for (var ai = 0; ai < actionBlocks.length; ai++) {
            var aMask = blockMasks[actionBlocks[ai].id];
            for (var idx = 0; idx < w * h; idx++) {
                if (aMask[idx]) actionMask[idx] = 1;
            }
        }
        
        var containerImageData = bgCtx.getImageData(0, 0, w, h);
        var cData = containerImageData.data;
        for (var ci = 0; ci < containerBlocks.length; ci++) {
            var cMask = blockMasks[containerBlocks[ci].id];
            for (var idx = 0; idx < w * h; idx++) {
                if (cMask[idx] && !actionMask[idx]) {
                    var o = idx * 4;
                    cData[o] = rgba[o]; cData[o + 1] = rgba[o + 1];
                    cData[o + 2] = rgba[o + 2]; cData[o + 3] = 255;
                }
            }
        }
        bgCtx.putImageData(containerImageData, 0, 0);
    }

    // 2. Générer les PNG de chaque bloc (avec transparence via masques)
    var blockCanvases = [];
    for (var bi = 0; bi < actionBlocks.length; bi++) {
        var blockInfo = actionBlocks[bi];
        var mask = blockMasks[blockInfo.id];
        var bx = blockInfo.pos.x, by = blockInfo.pos.y;
        var bw = blockInfo.size.w, bh = blockInfo.size.h;
        
        var blockCanvas = document.createElement('canvas');
        blockCanvas.width = bw; blockCanvas.height = bh;
        var blockCtx = blockCanvas.getContext('2d');
        var blockImgData = blockCtx.createImageData(bw, bh);
        var bd = blockImgData.data;
        
        for (var py = 0; py < bh; py++) {
            for (var px = 0; px < bw; px++) {
                var srcX = bx + px, srcY = by + py;
                if (srcX < 0 || srcX >= w || srcY < 0 || srcY >= h) continue;
                var srcIdx = srcY * w + srcX;
                var dstOff = (py * bw + px) * 4;
                var srcOff = srcIdx * 4;
                bd[dstOff] = rgba[srcOff]; bd[dstOff + 1] = rgba[srcOff + 1];
                bd[dstOff + 2] = rgba[srcOff + 2]; bd[dstOff + 3] = mask[srcIdx] ? 255 : 0;
            }
        }
        blockCtx.putImageData(blockImgData, 0, 0);
        blockCanvases.push(blockCanvas);
    }

    // Détection + fusion des blocs dupliqués : repeints sur le fond, retirés des drags
    var sigs = blockCanvases.map(sigFromCanvas);
    var paramsFlags = blockCanvases.map(paramFromCanvas);
    var dupGroups = thresholdClustering(actionBlocks, sigs, undefined, undefined, paramsFlags);
    var mergeRes = mergeDuplicatesIntoBgCanvas(bgCanvas, blockCanvases, actionBlocks, dupGroups);
    actionBlocks = mergeRes.keptIndices.map(function(i) { return actionBlocks[i]; });
    blockCanvases = mergeRes.keptIndices.map(function(i) { return blockCanvases[i]; });
    maxBlockW = 0; maxBlockH = 0;
    actionBlocks.forEach(function(b) {
        var rw = b.size.w - 2 * PAD, rh = b.size.h - 2 * PAD;
        if (rw > maxBlockW) maxBlockW = rw;
        if (rh > maxBlockH) maxBlockH = rh;
    });

    // 3. Uploader tout (fond + blocs)
    var totalUploads = 1 + blockCanvases.length;
    
    // Upload SÉQUENTIEL : fond d'abord, puis blocs un par un
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Upload fond...</div>';
    
    bgCanvas.toBlob(function(bgBlob) {
        ddiUploadBlob(bgBlob, 'background.png', function(bgUrl) {
            // Convertir les canvases en blobs puis uploader séquentiellement
            var blobItems = [];
            var converted = 0;
            blockCanvases.forEach(function(bc, i) {
                bc.toBlob(function(blob) {
                    blobItems[i] = { blob: blob, filename: 'block_' + i + '.png' };
                    converted++;
                    if (converted >= blockCanvases.length) {
                        if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Upload blocs...</div>';
                        ddiUploadBlobsSequential(blobItems, function(blockUrls) {
                            var failCount = blockUrls.filter(function(u) { return !u; }).length;
                            if (failCount > 0) console.warn('[DDI] ' + failCount + '/' + blockUrls.length + ' block uploads failed');
                            ddiApplyExtraction({
                                source_size: { w: w, h: h },
                                background_url: bgUrl,
                                maxBlockSize: { w: maxBlockW, h: maxBlockH },
                                pad: PAD,
                                blocks: actionBlocks.map(function(b, i) {
                                    return { id: b.id, url: blockUrls[i], pos: b.pos, size: b.size, type: b.type, name: 'block_' + i + '.png' };
                                })
                            });
                        });
                    }
                }, 'image/png');
            });
        }, function() {
            if (statusEl) statusEl.innerHTML = '<div style="color:#d32f2f; font-size: 0.7rem;">Erreur upload fond</div>';
        });
    }, 'image/png');
}

// ==================== APPLICATION DES RÉSULTATS D'EXTRACTION ====================
// Fonction commune flowchart/blocks : configure fond, zones, étiquettes dans le modèle DDI

function ddiApplyExtraction(data) {
    var c = ddiEnsureContent(ddiGetActivity());
    var statusEl = document.getElementById('ddiBlocksStatus');
    
    var srcW = data.source_size.w;
    var srcH = data.source_size.h;
    var actionBlocks = data.blocks;
    var PAD = data.pad !== undefined ? data.pad : 3;

    if (actionBlocks.length === 0) {
        showToast('Aucun bloc d\'action détecté', 'warning');
        return;
    }

    // 1. Configurer le fond (taille source uniquement — staging rendu en colonne HTML)
    c.backgroundUrl = data.background_url;
    c.bgImageName = 'background.png';
    c.canvasWidth = srcW;
    c.canvasHeight = srcH;
    delete c.sourceWidth;
    c.drags = [];
    c.drops = [];
    
    // 2. Largeur uniforme pour les zones de dépôt
    var maxRealW = data.maxBlockSize.w;
    
    // 3. Zones de dépôt (positions des blocs sur le fond)
    actionBlocks.forEach(function(block, idx) {
        var realX = block.pos.x + PAD;
        var realY = block.pos.y + PAD;
        var realH = block.size.h - 2 * PAD;
        c.drops.push({
            no: idx + 1, x: realX, y: realY,
            choice: 0, label: String(idx + 1),
            width: maxRealW, height: realH
        });
    });
    
    // 4. Mélanger les étiquettes (Fisher-Yates)
    var shuffledIndices = actionBlocks.map(function(_, i) { return i; });
    for (var si = shuffledIndices.length - 1; si > 0; si--) {
        var sj = Math.floor(Math.random() * (si + 1));
        var tmp = shuffledIndices[si]; shuffledIndices[si] = shuffledIndices[sj]; shuffledIndices[sj] = tmp;
    }
    
    // Assigner les choices
    for (var ei = 0; ei < shuffledIndices.length; ei++) {
        var bi = shuffledIndices[ei];
        c.drops[bi].choice = ei + 1;
    }
    
    // 5. Créer les étiquettes dans l'ordre mélangé
    var labelPadding = 8;
    var labelY_px = 10;
    var blocksToLoad = actionBlocks.length;
    var blocksLoaded = 0;
    
    var labelPositions = [];
    for (var li = 0; li < shuffledIndices.length; li++) {
        var blockIdx = shuffledIndices[li];
        var bh = actionBlocks[blockIdx].size.h;
        labelPositions.push({ blockIdx: blockIdx, y: labelY_px });
        labelY_px += bh + labelPadding;
    }
    
    labelPositions.forEach(function(lp, elemIdx) {
        var block = actionBlocks[lp.blockIdx];
        
        var img = new Image();
        img.onload = function() {
            c.drags[elemIdx] = {
                no: elemIdx + 1, label: 'Bloc ' + (elemIdx + 1), group: 1,
                infinite: false, imageUrl: block.url, imageName: block.name || ('block_' + elemIdx + '.png')
            };
            blocksLoaded++;
            if (blocksLoaded >= blocksToLoad) {
                ddiRefreshAll(); onCourseModified();
                if (statusEl) statusEl.innerHTML = '<div style="color:#2e7d32; font-size: 0.7rem;">✓ ' + actionBlocks.length + ' blocs extraits</div>';
                showToast(actionBlocks.length + ' blocs extraits et configurés', 'success');
            }
        };
        img.onerror = function() {
            c.drags[elemIdx] = {
                no: elemIdx + 1, label: 'Bloc ' + (elemIdx + 1), group: 1,
                infinite: false, imageUrl: block.url, imageName: block.name || ('block_' + elemIdx + '.png')
            };
            blocksLoaded++;
            if (blocksLoaded >= blocksToLoad) {
                ddiRefreshAll(); onCourseModified();
                if (statusEl) statusEl.innerHTML = '<div style="color:#2e7d32; font-size: 0.7rem;">✓ ' + actionBlocks.length + ' blocs extraits</div>';
                showToast(actionBlocks.length + ' blocs extraits', 'success');
            }
        };
        img.src = block.url;
    });
}


// ==================== PASTE EVENT : DÉTECTION AUTOMATIQUE ====================
// Intercepte Ctrl+V quand l'éditeur DDI est affiché

function ddiIsActive() {
    return !!document.querySelector('.ddi-editor');
}

// Listener global paste pour DDI (ajouté une seule fois)
document.addEventListener('paste', function(e) {
    if (!ddiIsActive()) return;
    // Ne pas interférer si un input/textarea a le focus
    var ae = document.activeElement;
    if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable)) return;
    
    var clipData = e.clipboardData || window.clipboardData;
    if (!clipData) return;
    
    // Chercher une image dans le presse-papier
    var imageFound = false;
    if (clipData.files && clipData.files.length > 0) {
        for (var i = 0; i < clipData.files.length; i++) {
            if (clipData.files[i].type.indexOf('image/') === 0) {
                e.preventDefault();
                ddiLoadImageAndExtract(clipData.files[i]);
                imageFound = true;
                break;
            }
        }
    }
    if (!imageFound && clipData.items) {
        for (var i = 0; i < clipData.items.length; i++) {
            if (clipData.items[i].type.indexOf('image/') === 0) {
                var blob = clipData.items[i].getAsFile();
                if (blob) {
                    e.preventDefault();
                    ddiLoadImageAndExtract(blob);
                    break;
                }
            }
        }
    }
});

// ==================== ZOOM CANVAS ====================

var _ddiZoomLevel = 100; // pourcentage

function ddiZoom(delta) {
    _ddiZoomLevel = Math.max(30, Math.min(200, _ddiZoomLevel + delta * 100));
    _ddiApplyZoom();
}

function ddiZoomTo(val) {
    _ddiZoomLevel = Math.max(30, Math.min(200, parseInt(val)));
    _ddiApplyZoom();
}

function ddiZoomFit() {
    var wrap = document.getElementById('ddiCanvasWrap');
    var inner = document.getElementById('ddiCanvasScale');
    if (!wrap || !inner) return;
    // offsetWidth n'est pas affecté par transform: scale — il donne la largeur
    // naturelle (100%) de la rangée canvas + étiquettes.
    var wrapW = wrap.clientWidth - 32; // padding ddi-canvas-wrap
    var naturalW = inner.offsetWidth;
    if (naturalW <= 0) return;
    _ddiZoomLevel = Math.round(Math.min(100, (wrapW / naturalW) * 100));
    _ddiZoomLevel = Math.max(30, _ddiZoomLevel);
    _ddiApplyZoom();
}

function _ddiApplyZoom() {
    var inner = document.getElementById('ddiCanvasScale');
    var outer = document.getElementById('ddiCanvasScaleOuter');
    var scroll = document.getElementById('ddiCanvasScroll');
    var slider = document.getElementById('ddiZoomSlider');
    var label = document.getElementById('ddiZoomLabel');
    var scale = _ddiZoomLevel / 100;
    if (inner) {
        inner.style.transform = 'scale(' + scale + ')';
        inner.style.transformOrigin = 'top left';
    }
    // Dimensionner l'outer aux dimensions zoomées pour que le scroll du scroll-container
    // reflète le contenu effectivement affiché (transform ne modifie pas offset*).
    if (inner && outer) {
        outer.style.width = (inner.offsetWidth * scale) + 'px';
        outer.style.height = (inner.offsetHeight * scale) + 'px';
    }
    if (slider) slider.value = _ddiZoomLevel;
    if (label) label.textContent = _ddiZoomLevel + '%';
    // Activer le scroll uniquement sur ddiCanvasScroll (le wrap externe reste hidden
    // pour que la zoom-bar en absolute reste fixée en bas).
    if (scroll && outer) {
        setTimeout(function() {
            var scaledH = outer.offsetHeight;
            var scaledW = outer.offsetWidth;
            scroll.style.overflow = (scaledH > scroll.clientHeight || scaledW > scroll.clientWidth) ? 'auto' : 'hidden';
        }, 20);
    }
}

// Auto-fit au premier rendu
function ddiAutoFitOnce() {
    setTimeout(function() {
        var inner = document.getElementById('ddiCanvasScale');
        if (inner && inner.offsetWidth > 0) {
            ddiZoomFit();
        }
    }, 100);
}