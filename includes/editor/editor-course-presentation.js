// ==================== ÉDITEUR COURSE PRESENTATION ====================
// Variables pour le drag des slides
let cpDraggingSlideIdx = null;
// Variable pour le niveau de zoom (70% par défaut)
let cpZoomLevel = 70;
// Variable pour l'élément DragQuestion sélectionné (étiquette ou zone)
// Format: { type: 'element'|'zone', idx: number } ou null
let cpDqSelectedItem = null;

// État d'aperçu des Dialog Cards sur le canvas (jamais exporté : purement visuel)
// Format: { "slideIdx:elementIdx": { card: 0, flipped: false } }
var cpDcPreview = {};

// Fermer les menus déroulants quand on clique ailleurs
document.addEventListener('click', function(e) {
    // Sauvegarder les modifications d'étiquettes en attente
    if (typeof cpDqFlushPendingChanges === 'function') {
        cpDqFlushPendingChanges();
    }

    // Fermer le dropdown des images
    if (!e.target.closest('.cp-dq-dropdown')) {
        document.querySelectorAll('.cp-dq-dropdown-menu.open').forEach(m => m.classList.remove('open'));
    }
    // Fermer les dropdowns des zones acceptées
    if (!e.target.closest('.cp-dq-zones-dropdown')) {
        document.querySelectorAll('.cp-dq-zones-menu').forEach(m => m.style.display = 'none');
    }
    // Fermer le dropdown des formes
    if (!e.target.closest('.cp-toolbar-dropdown')) {
        document.querySelectorAll('.cp-shape-dropdown-menu.open').forEach(m => m.classList.remove('open'));
    }
});

// Gestionnaire de touches clavier global pour suppression
// Clipboard interne pour copier/coller des éléments (tableau pour multi-sélection)
var cpClipboardElements = null;

// Buffer pour les modifications d'étiquettes en attente de sauvegarde
var cpDqPendingChanges = {}; // { "elemIdx:etqIdx": text, ... }

// === Multi-sélection helpers ===
// Sélection unique : remet le Set à un seul élément
function cpSelectSingle(idx) {
    cpSelectedElement = idx;
    cpSelectedElements.clear();
    if (idx !== null) cpSelectedElements.add(idx);
}
// Synchronise le Set depuis cpSelectedElement si celui-ci a été modifié directement
function cpSyncSelection() {
    if (cpSelectedElement === null && cpSelectedElements.size > 0) {
        cpSelectedElements.clear();
    } else if (cpSelectedElement !== null && !cpSelectedElements.has(cpSelectedElement)) {
        cpSelectedElements.clear();
        cpSelectedElements.add(cpSelectedElement);
    }
}

document.addEventListener('keydown', function(e) {
    // Vérifier qu'on est dans l'éditeur Course Presentation
    if (!document.getElementById('cpCanvasInner')) return;
    
    const activeEl = document.activeElement;
    
    // Escape : sortir du mode édition texte (blur le contenteditable)
    if (e.key === 'Escape') {
        if (activeEl && activeEl.isContentEditable && activeEl.closest('.cp-element')) {
            activeEl.blur();
            cpHideFloatToolbar();
            // Garder l'élément sélectionné (ne pas désélectionner)
            e.preventDefault();
            return;
        }
        // Sinon, désélectionner l'élément
        if (cpSelectedElement !== null || cpSelectedElements.size > 0) {
            cpSelectedElement = null; cpSelectedElements.clear();
            cpSelectedElements.clear();
            cpHideFloatToolbar();
            var canvas = document.getElementById('cpCanvasInner');
            if (canvas) canvas.querySelectorAll('.cp-element').forEach(function(el) { el.classList.remove('selected'); });
            cpRenderElementProps();
            canvas?.focus({ preventScroll: true });
            e.preventDefault();
            return;
        }
    }
    
    // Ne pas interférer si on est dans un champ de saisie
    if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) {
        return;
    }
    // Si on est dans un contenteditable du canvas en mode édition, laisser le comportement natif
    if (activeEl && activeEl.isContentEditable) {
        if (activeEl.closest('.cp-element')) {
            // Laisser CTRL+C/V/B/I/U natif pour le texte
            return;
        }
        if (activeEl.closest('.cp-prop-group') || activeEl.closest('.rich-text-editor')) {
            return;
        }
    }
    
    // CTRL+C : copier les éléments sélectionnés
    if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
        cpSyncSelection();
        if (cpSelectedElements.size > 0) {
            e.preventDefault();
            const activity = getSelectedActivity();
            if (!activity) return;
            const slide = activity.content.presentation.slides[cpCurrentSlide];
            if (!slide || !slide.elements) return;
            cpClipboardElements = [];
            cpSelectedElements.forEach(function(idx) {
                if (slide.elements[idx]) {
                    cpClipboardElements.push(JSON.parse(JSON.stringify(slide.elements[idx])));
                }
            });
            if (cpClipboardElements.length === 0) { cpClipboardElements = null; return; }
            // Écrire un marqueur dans le presse-papier système pour écraser l'ancien contenu
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText('__elea_cp_element__').catch(function(){});
            }
            showToast(cpClipboardElements.length > 1 ? cpClipboardElements.length + ' éléments copiés' : 'Élément copié', 'info');
        }
        return;
    }
    
    // CTRL+V : on laisse l'événement se propager pour déclencher le 'paste' event
    // (ne PAS faire e.preventDefault() ici, sinon le paste event ne se déclenche pas)
    if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
        // Si l'élément sélectionné est un DragQuestion, on redirige vers l'extraction de blocs
        // Le paste event sera géré par le handler global qui détectera le DQ
        cpWaitingForPaste = true;
        // Fallback : si le paste event ne se déclenche pas (pas de focus), utiliser readText
        setTimeout(function() {
            if (!cpWaitingForPaste) return; // déjà traité par le paste event
            cpWaitingForPaste = false;
            // Si DragQuestion sélectionné, pas de fallback normal (le paste event gère)
            if (cpIsSelectedElementDragQuestion()) return;
            // Essayer clipboard.readText (moins restrictif que .read())
            if (navigator.clipboard && navigator.clipboard.readText) {
                navigator.clipboard.readText().then(function(text) {
                    text = (text || '').trim();
                    if (text === '__elea_cp_element__') {
                        cpPasteElement();
                    } else if (text.match(/^https?:\/\/.+\.(jpg|jpeg|png|gif|webp|svg|bmp)/i)) {
                        cpPasteImageUrl(text);
                    } else {
                        cpPasteElement();
                    }
                }).catch(function() {
                    cpPasteElement();
                });
            } else {
                cpPasteElement();
            }
        }, 150);
        return;
    }
    
    // Flèches : naviguer entre slides si rien n'est sélectionné dans la slide
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        if (cpSelectedElement !== null || cpSelectedElements.size > 0) return;
        var activity = getSelectedActivity();
        if (!activity || !activity.content || !activity.content.presentation) return;
        var total = (activity.content.presentation.slides || []).length;
        if (total <= 1) return;
        var goPrev = (e.key === 'ArrowLeft' || e.key === 'ArrowUp');
        if (goPrev && cpCurrentSlide > 0) {
            e.preventDefault();
            cpGoToSlide(cpCurrentSlide - 1);
        } else if (!goPrev && cpCurrentSlide < total - 1) {
            e.preventDefault();
            cpGoToSlide(cpCurrentSlide + 1);
        }
        return;
    }

    // Supprimer avec Suppr ou Backspace (fonctionne quand le contenteditable N'A PAS le focus)
    if ((e.key === 'Delete' || e.key === 'Backspace')) {
        cpSyncSelection();
        if (cpSelectedElements.size > 0) {
            e.preventDefault();
            cpDeleteSelected();
        }
    }
});

// Flag pour savoir si le paste vient du canvas CP
var cpWaitingForPaste = false;

// Vérifie si l'élément sélectionné sur le slide est un DragQuestion
function cpIsSelectedElementDragQuestion() {
    var activity = typeof getSelectedActivity === 'function' ? getSelectedActivity() : null;
    if (!activity || !activity.content || !activity.content.presentation || !activity.content.presentation.slides) return false;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) return false;
    var idx = cpSelectedElement;
    if (idx === null || idx === undefined) {
        if (cpSelectedElements && cpSelectedElements.size === 1) {
            idx = cpSelectedElements.values().next().value;
        } else {
            return false;
        }
    }
    if (!slide.elements[idx]) return false;
    var lib = (slide.elements[idx].action && slide.elements[idx].action.library || '').split(' ')[0].replace('H5P.', '').toLowerCase();
    return lib === 'dragquestion';
}

// ============================================================
// Sélection de texte unifiée dans tout l'éditeur
// Partout où l'on peut modifier du texte (<input> texte, <textarea>,
// zones contenteditable) :
//   • clic qui donne le focus à un champ  -> sélectionne TOUT le texte
//   • double-clic                          -> sélectionne le MOT (natif)
//   • triple-clic                          -> sélectionne la PHRASE sous le curseur
// Les cadres texte de Course Presentation et les cellules de tableau passent
// en édition au double-clic (qui sélectionne tout) ; une fois en édition ils
// suivent les mêmes règles (mot / phrase).
// ============================================================

// Cible éditable (input texte / textarea / hôte contenteditable) à partir du
// noeud réellement cliqué (qui peut être un enfant : p, span, strong...).
function _txtEditableTarget(node) {
    if (!node) return null;
    if (node.nodeType === 3) node = node.parentElement;
    if (!node || !node.closest) return null;
    var field = node.closest('input, textarea');
    if (field && _txtIsField(field)) return field;
    var ce = node.closest('[contenteditable]');
    while (ce && ce.getAttribute('contenteditable') === 'false') {
        ce = ce.parentElement ? ce.parentElement.closest('[contenteditable]') : null;
    }
    if (ce && ce.isContentEditable) return ce;
    return null;
}

// Champ de saisie texte (input/textarea) modifiable ?
function _txtIsField(el) {
    if (!el || el.disabled || el.readOnly) return false;
    if (el.tagName === 'TEXTAREA') return true;
    if (el.tagName === 'INPUT') {
        var t = (el.getAttribute('type') || 'text').toLowerCase();
        return ['text', 'search', 'url', 'tel', 'password', 'email', 'number'].indexOf(t) !== -1;
    }
    return false;
}

// Sélection courante réduite (= pas de cliqué-glissé) ?
function _txtIsCollapsed(el) {
    if (_txtIsField(el)) {
        return el.selectionStart == null || el.selectionStart === el.selectionEnd;
    }
    var sel = window.getSelection();
    return !sel || sel.isCollapsed;
}

// Sélectionne tout le contenu
function _txtSelectAll(el) {
    if (_txtIsField(el)) {
        try { el.select(); } catch (e) {}
        return;
    }
    try {
        var sel = window.getSelection();
        var range = document.createRange();
        range.selectNodeContents(el);
        sel.removeAllRanges();
        sel.addRange(range);
    } catch (e) {}
}

// Frontières [début, fin] de la phrase contenant `pos` dans `text`.
// Fin de phrase = . ! ? … (+ éventuels guillemets/parenthèses fermantes) suivis
// d'un espace ou de la fin ; un saut de ligne borne aussi la phrase.
function _txtSentenceBounds(text, pos) {
    var ENDERS = '.!?…';
    var CLOSERS = '"\')]»”’';
    var n = text.length;
    if (n === 0) return [0, 0];
    pos = Math.max(0, Math.min(pos, n));

    var end = n;
    for (var i = pos; i < n; i++) {
        var c = text.charAt(i);
        if (c === '\n') { end = i; break; }
        if (ENDERS.indexOf(c) !== -1) {
            var j = i + 1;
            while (j < n && ENDERS.indexOf(text.charAt(j)) !== -1) j++;
            while (j < n && CLOSERS.indexOf(text.charAt(j)) !== -1) j++;
            if (j >= n || /\s/.test(text.charAt(j))) { end = j; break; }
            i = j - 1; // ex: "3.14" : ce point n'est pas une fin de phrase
        }
    }

    var start = 0;
    for (var k = pos - 1; k >= 0; k--) {
        var c2 = text.charAt(k);
        if (c2 === '\n') { start = k + 1; break; }
        if (ENDERS.indexOf(c2) !== -1) {
            var m = k + 1;
            while (m < n && ENDERS.indexOf(text.charAt(m)) !== -1) m++;
            while (m < n && CLOSERS.indexOf(text.charAt(m)) !== -1) m++;
            if (m >= n || /\s/.test(text.charAt(m))) {
                start = m;
                while (start < n && /[^\S\n]/.test(text.charAt(start))) start++;
                break;
            }
        }
    }

    while (start < end && /\s/.test(text.charAt(start))) start++;
    while (end > start && /\s/.test(text.charAt(end - 1))) end--;
    return [start, end];
}

// Tags blocs (bornent les phrases dans un contenteditable)
var _TXT_BLOCK_TAGS = { P:1, DIV:1, LI:1, UL:1, OL:1, H1:1, H2:1, H3:1, H4:1, H5:1, H6:1, TABLE:1, THEAD:1, TBODY:1, TR:1, TD:1, TH:1, SECTION:1, ARTICLE:1, BLOCKQUOTE:1, FIGURE:1, FIGCAPTION:1, PRE:1 };

// Carte texte -> noeuds d'un contenteditable (avec sauts de ligne aux blocs et <br>)
function _txtBuildMap(root) {
    var text = '';
    var segs = [];
    (function walk(node) {
        for (var child = node.firstChild; child; child = child.nextSibling) {
            if (child.nodeType === 3) {
                var v = child.nodeValue;
                segs.push({ node: child, start: text.length, len: v.length });
                text += v;
            } else if (child.nodeType === 1) {
                var tag = child.tagName;
                if (tag === 'BR') {
                    text += '\n';
                } else if (tag === 'HR') {
                    if (text && text.charAt(text.length - 1) !== '\n') text += '\n';
                } else {
                    var block = _TXT_BLOCK_TAGS[tag];
                    if (block && text && text.charAt(text.length - 1) !== '\n') text += '\n';
                    walk(child);
                    if (block && text && text.charAt(text.length - 1) !== '\n') text += '\n';
                }
            }
        }
    })(root);
    return { text: text, segs: segs };
}

function _txtNodeToOffset(map, node, offset) {
    for (var i = 0; i < map.segs.length; i++) {
        if (map.segs[i].node === node) return map.segs[i].start + Math.min(offset, map.segs[i].len);
    }
    return -1;
}

function _txtOffsetToNode(map, off) {
    for (var i = 0; i < map.segs.length; i++) {
        var s = map.segs[i];
        if (off >= s.start && off <= s.start + s.len) return { node: s.node, offset: off - s.start };
    }
    var last = map.segs[map.segs.length - 1];
    return last ? { node: last.node, offset: last.len } : null;
}

function _txtCaretFromPoint(x, y) {
    if (document.caretRangeFromPoint) {
        var r = document.caretRangeFromPoint(x, y);
        if (r) return { node: r.startContainer, offset: r.startOffset };
    }
    if (document.caretPositionFromPoint) {
        var p = document.caretPositionFromPoint(x, y);
        if (p) return { node: p.offsetNode, offset: p.offset };
    }
    return null;
}

// Sélectionne la phrase sous le point (x, y)
function _txtSelectSentence(el, x, y) {
    if (_txtIsField(el)) {
        var val = el.value || '';
        var caret = (el._txtLastCaret != null) ? el._txtLastCaret : (el.selectionStart || 0);
        var b = _txtSentenceBounds(val, caret);
        try { el.setSelectionRange(b[0], b[1]); }
        catch (e) { try { el.select(); } catch (e2) {} }
        return;
    }
    var map = _txtBuildMap(el);
    if (!map.segs.length) return;
    var off = -1;
    var pt = _txtCaretFromPoint(x, y);
    if (pt && pt.node && pt.node.nodeType === 3 && el.contains(pt.node)) {
        off = _txtNodeToOffset(map, pt.node, pt.offset);
    }
    if (off < 0) {
        var s0 = window.getSelection();
        if (s0 && s0.anchorNode && s0.anchorNode.nodeType === 3 && el.contains(s0.anchorNode)) {
            off = _txtNodeToOffset(map, s0.anchorNode, s0.anchorOffset);
        }
    }
    if (off < 0) off = 0;
    var bb = _txtSentenceBounds(map.text, off);
    var a = _txtOffsetToNode(map, bb[0]);
    var z = _txtOffsetToNode(map, bb[1]);
    if (!a || !z) return;
    try {
        var range = document.createRange();
        range.setStart(a.node, a.offset);
        range.setEnd(z.node, z.offset);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    } catch (e) {}
}

// Écouteurs globaux en phase CAPTURE : robustes même si un parent stoppe la
// propagation (ex: cellules de tableau) et exécutés après la sélection native.
var _txtPendingFocusEl = null;

document.addEventListener('mousedown', function(e) {
    var el = _txtEditableTarget(e.target);
    // « Clic de focus » : l'élément n'a pas encore le focus -> on sélectionnera tout au clic
    _txtPendingFocusEl = (el && document.activeElement !== el) ? el : null;
}, true);

document.addEventListener('click', function(e) {
    var el = _txtEditableTarget(e.target);
    var focusEl = _txtPendingFocusEl;
    _txtPendingFocusEl = null;
    if (!el) return;

    // Mémoriser la position du curseur (champs) tant que la sélection est réduite
    if (_txtIsField(el) && el.selectionStart != null && el.selectionStart === el.selectionEnd) {
        el._txtLastCaret = el.selectionStart;
    }

    var detail = e.detail;
    if (detail >= 3) {
        // Triple-clic -> phrase
        _txtSelectSentence(el, e.clientX, e.clientY);
    } else if (detail <= 1 && focusEl === el) {
        // Clic de focus -> tout (sauf cliqué-glissé qui a déjà sélectionné une portion)
        if (_txtIsCollapsed(el)) _txtSelectAll(el);
    }
    // double-clic (detail === 2) ou clic simple hors focus : comportement natif (mot / curseur)
}, true);

// Écouter l'événement paste natif du navigateur
// Avantage : accès direct à clipboardData sans popup de permission
document.addEventListener('paste', function(e) {
    // Vérifier qu'on est dans l'éditeur Course Presentation
    if (!document.getElementById('cpCanvasInner')) return;
    
    // Cas spécial : collage dans le champ URL d'image
    var target = e.target;
    if (target && target.id === 'cpImageUrlInput') {
        var cbd = e.clipboardData && e.clipboardData.getData('text');
        if (cbd) {
            e.preventDefault();
            target.value = cbd.trim();
            cpUpdateImageUrl(target.value);
        }
        return;
    }
    
    if (!cpWaitingForPaste) return;
    cpWaitingForPaste = false;
    
    e.preventDefault();
    
    var clipData = e.clipboardData || window.clipboardData;
    if (!clipData) {
        cpPasteElement();
        return;
    }
    
    // Si l'élément sélectionné est un DragQuestion, rediriger les images vers l'extraction de blocs
    if (cpIsSelectedElementDragQuestion()) {
        // Chercher une image dans le presse-papier
        if (clipData.files && clipData.files.length > 0) {
            for (var i = 0; i < clipData.files.length; i++) {
                if (clipData.files[i].type.indexOf('image/') === 0) {
                    cpDqLoadImageAndExtract(clipData.files[i]);
                    return;
                }
            }
        }
        if (clipData.items) {
            for (var i = 0; i < clipData.items.length; i++) {
                if (clipData.items[i].type.indexOf('image/') === 0) {
                    var blob = clipData.items[i].getAsFile();
                    if (blob) {
                        cpDqLoadImageAndExtract(blob);
                        return;
                    }
                }
            }
        }
        // Pas d'image trouvée, ne rien faire pour un DQ
        return;
    }
    
    // 1. Chercher une image binaire (screenshot, copie d'image)
    if (clipData.files && clipData.files.length > 0) {
        for (var i = 0; i < clipData.files.length; i++) {
            if (clipData.files[i].type.indexOf('image/') === 0) {
                cpPasteImageBlob(clipData.files[i]);
                return;
            }
        }
    }
    
    // 2. Chercher dans les items (images blob)
    if (clipData.items) {
        for (var i = 0; i < clipData.items.length; i++) {
            if (clipData.items[i].type.indexOf('image/') === 0) {
                var blob = clipData.items[i].getAsFile();
                if (blob) {
                    cpPasteImageBlob(blob);
                    return;
                }
            }
        }
    }
    
    // 3. Lire le texte
    var text = clipData.getData('text/plain');
    if (text) {
        text = text.trim();
        // Si c'est notre marqueur interne → coller l'élément copié
        if (text === '__elea_cp_element__') {
            cpPasteElement();
            return;
        }
        // Si c'est une URL d'image → insérer l'image
        if (text.match(/^https?:\/\/.+\.(jpg|jpeg|png|gif|webp|svg|bmp)/i)) {
            cpPasteImageUrl(text);
            return;
        }
    }
    
    // 4. Sinon coller l'élément copié du clipboard interne
    cpPasteElement();
});

// Colle une image depuis un Blob (screenshot, copie d'image)
function cpPasteImageBlob(blob) {
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    if (!slide.elements) slide.elements = [];
    
    showToast('Collage de l\'image...', 'info');
    
    // Déterminer l'extension
    var ext = 'png';
    if (blob.type === 'image/jpeg') ext = 'jpg';
    else if (blob.type === 'image/gif') ext = 'gif';
    else if (blob.type === 'image/webp') ext = 'webp';
    
    var file = new File([blob], 'paste_' + Date.now() + '.' + ext, { type: blob.type });
    
    var formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            showToast(data.error || 'Erreur upload', 'error');
            return;
        }
        cpCreateImageElement(slide, data.url, blob.type);
    })
    .catch(function(err) {
        showToast('Erreur: ' + err.message, 'error');
    });
}

// Colle une image depuis une URL
function cpPasteImageUrl(url) {
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    if (!slide.elements) slide.elements = [];
    
    showToast('Téléchargement de l\'image...', 'info');
    
    var formData = new FormData();
    formData.append('action', 'copy_image_to_uploads');
    formData.append('source_type', 'url');
    formData.append('source', url);
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var path = data.success ? (data.url || data.path) : url;
        cpCreateImageElement(slide, path);
        showToast(data.success ? 'Image collée' : 'Image collée (URL directe)', data.success ? 'success' : 'info');
    })
    .catch(function() {
        cpCreateImageElement(slide, url);
        showToast('Image collée (URL directe)', 'info');
    });
}

// Crée un nouvel élément image sur le slide avec dimensions auto
function cpCreateImageElement(slide, path) {
    var img = new Image();
    img.onload = function() {
        var canvasRatio = 2;
        var imgRatio = img.naturalWidth / img.naturalHeight;
        var newWidth, newHeight;
        
        if (imgRatio > canvasRatio) {
            newWidth = 50;
            newHeight = newWidth / imgRatio * canvasRatio;
        } else {
            newHeight = 50;
            newWidth = newHeight * imgRatio / canvasRatio;
        }
        newWidth = Math.max(10, Math.min(80, newWidth));
        newHeight = Math.max(10, Math.min(80, newHeight));
        
        var element = {
            x: 10 + (slide.elements.length * 3) % 40,
            y: 10 + (slide.elements.length * 3) % 40,
            width: newWidth, height: newHeight,
            action: {
                library: 'H5P.Image 1.1',
                params: {
                    file: { path: path },
                    decorative: false,
                    contentName: 'Image',
                    expandImage: 'Expand Image',
                    minimizeImage: 'Minimize Image'
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Image', license: 'U', title: 'Sans titre Image', authors: [], changes: [] }
            },
            alwaysDisplayComments: false,
            backgroundOpacity: 0,
            displayAsButton: false,
            buttonSize: 'big',
            goToSlideType: 'specified',
            invisible: false,
            solution: ''
        };
        
        slide.elements.push(element);
        cpSelectedElement = slide.elements.length - 1;
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
    };
    img.onerror = function() {
        // Même si l'image ne charge pas dans l'éditeur, créer l'élément
        var element = {
            x: 10 + (slide.elements.length * 3) % 40,
            y: 10 + (slide.elements.length * 3) % 40,
            width: 40, height: 35,
            action: {
                library: 'H5P.Image 1.1',
                params: {
                    file: { path: path },
                    decorative: false,
                    contentName: 'Image',
                    expandImage: 'Expand Image',
                    minimizeImage: 'Minimize Image'
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Image', license: 'U', title: 'Sans titre Image', authors: [], changes: [] }
            },
            alwaysDisplayComments: false,
            backgroundOpacity: 0,
            displayAsButton: false,
            buttonSize: 'big',
            goToSlideType: 'specified',
            invisible: false,
            solution: ''
        };
        slide.elements.push(element);
        cpSelectedElement = slide.elements.length - 1;
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
    };
    img.src = path;
}

// (cpPasteElement est définie plus bas, dans la section menu contextuel)

// Génère un UUID v4 pour les subContentId
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

// Décoder les entités HTML (&lt; &gt; &amp; etc.)
function decodeHtmlEntities(str) {
    if (!str) return '';
    const textarea = document.createElement('textarea');
    textarea.innerHTML = str;
    return textarea.value;
}

function renderCoursePresentationEditor(activity) {
    const content = document.getElementById('editorContent');
    const canvasWrapper = document.getElementById('canvasWrapper');
    
    // Invalider le cache des miniatures (évite d'afficher les vignettes d'un autre parcours)
    _cpThumbCache = [];
    
    // Cleanup YouTube players from previous render
    if (typeof cpCleanupYTPlayers === 'function') cpCleanupYTPlayers();
    
    // Ajouter une classe pour que le wrapper prenne toute la hauteur
    if (canvasWrapper) {
        canvasWrapper.classList.add('cp-mode');
    }
    
    // S'assurer que la structure existe
    if (!activity.content) activity.content = {};
    if (!activity.content.presentation) activity.content.presentation = { slides: [{ elements: [] }] };
    if (!activity.content.presentation.slides || activity.content.presentation.slides.length === 0) {
        activity.content.presentation.slides = [{ elements: [] }];
    }
    
    const slides = activity.content.presentation.slides;
    if (cpCurrentSlide >= slides.length) cpCurrentSlide = 0;
    
    // Générer les miniatures de slides avec aperçu du contenu
    let slideThumbs = '';
    slides.forEach((slide, idx) => {
        const previewHtml = cpGetSlidePreviewHtml(slide);
        slideThumbs += `
            <div class="cp-slide-thumb ${idx === cpCurrentSlide ? 'active' : ''}" 
                 draggable="true"
                 ondragstart="cpStartDragSlide(event, ${idx})"
                 ondragover="cpDragOverSlide(event, ${idx})"
                 ondrop="cpDropSlide(event, ${idx})"
                 ondragend="cpEndDragSlide(event)"
                 onclick="cpGoToSlide(${idx})">
                <div class="cp-slide-thumb-preview">${previewHtml}</div>
                <div class="cp-slide-thumb-actions">
                    <button class="cp-slide-thumb-btn" onclick="event.stopPropagation(); cpDuplicateSlide(${idx})" title="Dupliquer">📋</button>
                    ${slides.length > 1 ? `<button class="cp-slide-thumb-btn cp-slide-thumb-delete" onclick="event.stopPropagation(); cpDeleteSlide(${idx})" title="Supprimer">×</button>` : ''}
                </div>
            </div>`;
    });
    
    content.innerHTML = `
        <div class="cp-editor">
            <div class="cp-editor-header">
                <button class="btn btn-secondary" onclick="selectSection('${selectedSection}')" style="padding: 0.4rem 0.8rem;">← Retour</button>
                <h3>🎬 <span class="activity-name-editable" onclick="startEditActivityNameInHeader(this)">${escapeHtml(activity.name)}</span></h3>
                <div class="ed-header-actions">
                    <button class="ed-undo-btn" onclick="courseUndo()" title="Annuler (Ctrl+Z)" ${courseHistoryIndex > 0 ? '' : 'disabled'}>↩</button>
                    <button class="ed-redo-btn" onclick="courseRedo()" title="Répéter (Ctrl+Y)" ${courseHistoryIndex < courseHistory.length - 1 ? '' : 'disabled'}>↪</button>
                </div>
            </div>
            
            <div class="cp-editor-toolbar">
                <span class="cp-toolbar-label">Ajouter :</span>
                <button class="cp-toolbar-btn" onclick="cpAddElement('text')">
                    <span class="cp-toolbar-btn-icon">📝</span> Texte
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('image')">
                    <span class="cp-toolbar-btn-icon">🖼️</span> Image
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('video')">
                    <span class="cp-toolbar-btn-icon">🎥</span> Vidéo
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('audio')">
                    <span class="cp-toolbar-btn-icon">🔊</span> Audio
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('dialogcards')">
                    <span class="cp-toolbar-btn-icon">🃏</span> Cartes
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('multichoice')">
                    <span class="cp-toolbar-btn-icon">☑️</span> QCM
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('truefalse')">
                    <span class="cp-toolbar-btn-icon">✅</span> Vrai/Faux
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('blanks')">
                    <span class="cp-toolbar-btn-icon">📋</span> Trous
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('table')">
                    <span class="cp-toolbar-btn-icon">▦</span> Tableau
                </button>
                <button class="cp-toolbar-btn" onclick="cpAddElement('dragdrop')">
                    <span class="cp-toolbar-btn-icon">🎯</span> Glisser
                </button>
                <div class="cp-toolbar-dropdown" style="position: relative; display: inline-block;">
                    <button class="cp-toolbar-btn" onclick="this.nextElementSibling.classList.toggle('open')">
                        <span class="cp-toolbar-btn-icon">⬜</span> Forme ▾
                    </button>
                    <div class="cp-shape-dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; background: var(--bg-secondary, #fff); border: 1px solid var(--gray-200, #ddd); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 200; min-width: 130px;">
                        <div onclick="cpAddElement('shape-rectangle'); this.parentElement.classList.remove('open');" style="padding: 8px 12px; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                            <span>⬜</span> Carré
                        </div>
                        <div onclick="cpAddElement('shape-circle'); this.parentElement.classList.remove('open');" style="padding: 8px 12px; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                            <span>⭕</span> Rond
                        </div>
                        <div onclick="cpAddElement('shape-line'); this.parentElement.classList.remove('open');" style="padding: 8px 12px; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                            <span>➖</span> Trait
                        </div>
                    </div>
                </div>
                <div class="cp-toolbar-dropdown" style="position: relative; display: inline-block;">
                    <button class="cp-toolbar-btn" onclick="cpToggleEmojiPicker(this)">
                        <span class="cp-toolbar-btn-icon">😀</span> Emoji ▾
                    </button>
                    <div class="cp-emoji-picker-dropdown" style="display: none; position: absolute; top: 100%; left: 0; background: var(--bg-secondary, #fff); border: 1px solid var(--gray-200, #ddd); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 200; width: 260px; max-height: 300px; overflow-y: auto; padding: 6px;">
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px;">
                        </div>
                    </div>
                </div>
                <div class="cp-toolbar-dropdown" style="position: relative; display: inline-block;">
                    <button class="cp-toolbar-btn cp-toolbar-btn--quick" onclick="cpToggleQuickImageMenu(this)">
                        <span class="cp-toolbar-btn-icon">⚡</span> Accès rapide ▾
                    </button>
                </div>
                <div class="cp-toolbar-dropdown" style="position: relative; display: inline-block;">
                    <button class="cp-toolbar-btn cp-toolbar-btn--tpl" onclick="cpOpenTemplateMenu(this)">
                        <span class="cp-toolbar-btn-icon">📋</span> Templates ▾
                    </button>
                </div>
            </div>
            
            <div class="cp-canvas-container">
                <div class="cp-canvas-wrapper">
                    <div class="cp-canvas" id="cpCanvas" onclick="cpDeselectElement(event)" onmousedown="cpRectSelectStart(event)" oncontextmenu="cpShowCanvasContextMenu(event)" style="transform: scale(${cpZoomLevel / 100}); transform-origin: center center;">
                        <button class="cp-canvas-add-btn cp-canvas-add-left" onclick="event.stopPropagation(); cpInsertSlideBefore()" title="Ajouter une slide avant">+</button>
                        <div class="cp-canvas-inner" id="cpCanvasInner" tabindex="-1" onclick="cpDeselectElement(event)">
                            <!-- Éléments de la slide -->
                        </div>
                        <div class="cp-float-toolbar" id="cpFloatToolbar" onmousedown="clearTimeout(window._textBlurTimer)">
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'bold')" title="Gras"><b>G</b></button>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'italic')" title="Italique"><i>I</i></button>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'underline')" title="Souligné"><u>S</u></button>
                            <button class="ft-btn cp-color-btn" data-kind="fore" onmousedown="cpColorBtn(event,'fore','canvas')" title="Couleur du texte"><span class="cp-ci">A<i class="cp-ci-bar" style="background:${cpLastTextColor}"></i></span></button>
                            <button class="ft-btn cp-color-btn" data-kind="hilite" onmousedown="cpColorBtn(event,'hilite','canvas')" title="Surlignage"><span class="cp-ci cp-ci-hl" style="background:${cpLastHiliteColor}">A</span></button>
                            <div class="ft-sep"></div>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'fontSize','+')">A+</button>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'fontSize','-')">A-</button>
                            <div class="ft-sep"></div>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'justifyLeft')" title="Gauche">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
                            </button>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'justifyCenter')" title="Centrer">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
                            </button>
                            <button class="ft-btn" onmousedown="cpCanvasFormat(event,'justifyRight')" title="Droite">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>
                            </button>
                            <div class="ft-sep"></div>
                            <button class="ft-btn" id="cpFloatEmojiBtn" onmousedown="cpEmojiHoldStart(event)" title="Émoji">😀</button>
                            <button class="ft-btn" onmousedown="event.preventDefault(); event.stopPropagation(); cpInsertLink();" title="Lien">🔗</button>
                            <div class="cp-emoji-popup" id="cpEmojiPopup"></div>
                        </div>
                        <button class="cp-canvas-add-btn cp-canvas-add-right" onclick="event.stopPropagation(); cpInsertSlideAfter()" title="Ajouter une slide après">+</button>
                    </div>
                </div>
            </div>
            
            <div class="cp-slides-nav">
                <span class="cp-slide-counter">${cpCurrentSlide + 1}/${slides.length}</span>
                <div class="cp-slides-scroll">
                    ${slideThumbs}
                    <button class="cp-add-slide" onclick="cpAddSlide()">+</button>
                </div>
                <div class="cp-zoom-control">
                    <span class="cp-zoom-icon">🔍</span>
                    <input type="range" id="cpZoomSlider" min="30" max="100" value="${cpZoomLevel}" 
                           oninput="cpSetZoom(this.value)" title="Zoom: ${cpZoomLevel}%">
                    <span class="cp-zoom-value" id="cpZoomValue">${cpZoomLevel}%</span>
                </div>
            </div>
        </div>
        
        <!-- Panneau de propriétés flottant (hors du flux) -->
        <div class="cp-props-panel ${cpSelectedElements.size > 0 ? 'visible' : ''}" id="cpPropsPanel">
            <div class="cp-props-header" onmousedown="cpStartDragPanel(event)">
                <h4><span class="cp-props-header-icon">⚙️</span>Propriétés</h4>
                <button class="cp-props-close" onclick="cpDeselectAll()">×</button>
            </div>
            <div class="cp-props-body" id="cpPropsBody">
                <!-- Contenu généré dynamiquement -->
            </div>
        </div>`;
    
    cpRenderSlideElements();
    if (cpSelectedElements.size > 0) {
        cpRenderElementProps();
    }
    
    // Appliquer le scale fixe (responsive + zoom) et l'observer de resize
    cpUpdateCanvasTransform();
    cpSetupResizeObserver();
    
    // Appliquer les vignettes en cache, puis générer les manquantes
    var slides2 = activity.content?.presentation?.slides || [];
    for (var ti = 0; ti < slides2.length; ti++) {
        if (_cpThumbCache[ti]) _cpApplyThumbFromCache(ti);
    }
    setTimeout(cpUpdateAllThumbs, 50);
}

function cpDeselectAll() {
    cpSelectedElement = null; cpSelectedElements.clear();
    cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpRenderSlideElements();
    document.getElementById('cpPropsPanel')?.classList.remove('visible');
    document.getElementById('cpCanvasInner')?.focus({ preventScroll: true });
}

function cpSetZoom(value) {
    cpZoomLevel = parseInt(value);
    const zoomValue = document.getElementById('cpZoomValue');
    const slider = document.getElementById('cpZoomSlider');
    
    if (zoomValue) {
        zoomValue.textContent = cpZoomLevel + '%';
    }
    if (slider) {
        slider.title = 'Zoom: ' + cpZoomLevel + '%';
    }
    cpUpdateCanvasTransform();
}

function cpRenderSlideElements(targetCanvas, overrideSlideIdx) {
    const _forThumb = !!targetCanvas;
    const canvas = targetCanvas || document.getElementById('cpCanvasInner');
    if (!canvas) return;
    
    if (!_forThumb) {
        // Synchroniser le Set de multi-sélection
        cpSyncSelection();
        
        // Cacher la toolbar flottante (les éléments vont être recréés)
        if (typeof cpHideFloatToolbar === 'function') cpHideFloatToolbar();
    }
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slideIdx = (overrideSlideIdx !== undefined) ? overrideSlideIdx : cpCurrentSlide;
    const slides = activity.content?.presentation?.slides || [];
    const slide = slides[slideIdx];
    if (!slide) return;
    
    // Collect deferred img src assignments (for data URLs and long paths)
    const deferredImgSrcs = [];
    
    let html = '';
    (slide.elements || []).forEach((el, idx) => {
        const isSelected = _forThumb ? false : cpSelectedElements.has(idx);
        const rotation = el.rotation || 0;
        const rotStyle = rotation ? ` transform: rotate(${rotation}deg);` : '';
        const style = `left: ${el.x ?? 10}%; top: ${el.y ?? 10}%; width: ${el.width ?? 30}%; height: ${el.height ?? 20}%;${rotStyle}`;
        
        let contentHtml = '';
        const type = (el.action?.library || '').split(' ')[0].replace('H5P.', '').toLowerCase();
        const isTextElement = type === 'text' || type === 'advancedtext';
        
        switch (type) {
            case 'text':
            case 'advancedtext':
                // Normaliser le surlignage hérité : si le fond a été posé sur un <strong>/<em>
                // (texte en gras), le déplacer sur un <span> pour qu'il s'affiche (marqueur) et
                // s'exporte correctement vers Éléa.
                if (el.action && el.action.params && el.action.params.text) {
                    var _fixedTxt = cpMoveStylesToSpan(el.action.params.text);
                    if (_fixedTxt !== el.action.params.text) el.action.params.text = _fixedTxt;
                }
                // Texte : pas contenteditable par défaut, double-clic pour éditer
                contentHtml = `<div class="cp-text-element cp-editable-text"
                                   data-idx="${idx}"
                                   spellcheck="false"
                                   oninput="cpTextInput(event, ${idx})"
                                   onblur="cpTextBlur(${idx})">${el.action?.params?.text || '<p>Cliquez pour éditer</p>'}</div>`;
                break;
            case 'table':
                contentHtml = `<div class="cp-table-element" data-idx="${idx}">${cpNormalizeTableHtml(el.action?.params?.text || '')}</div>`;
                break;
            case 'image':
                const imgPath = el.action?.params?.file?.path;
                if (imgPath) {
                    const imgId = 'cpImg_' + idx + '_' + Math.random().toString(36).substr(2,6);
                    contentHtml = `<div class="cp-image-element" ondblclick="cpBrowseImage(event)"><img id="${imgId}" alt="Image"></div>`;
                    deferredImgSrcs.push({ id: imgId, src: imgPath });
                } else {
                    contentHtml = `<div class="cp-image-element" ondblclick="cpBrowseImage(event)"><div class="cp-image-placeholder"><div class="cp-image-placeholder-icon">🖼️</div><small>Double-clic pour ajouter</small></div></div>`;
                }
                break;
            case 'interactivevideo':
                const videoPath = el.action?.params?.interactiveVideo?.video?.files?.[0]?.path || '';
                const videoInteractions = el.action?.params?.interactiveVideo?.assets?.interactions || [];
                const interCount = videoInteractions.length;
                
                if (videoPath) {
                    // Générer les cartes d'interaction avec contenu riche
                    let interactionsHtml = '';
                    let timelineMarkersHtml = '';
                    
                    videoInteractions.forEach((inter, iIdx) => {
                        const interType = inter.action?.library?.split(' ')[0]?.replace('H5P.', '') || 'Text';
                        const typeIcons = {
                            'Text': '💬',
                            'MultiChoice': '✅',
                            'TrueFalse': '⚖️',
                            'Blanks': '📝'
                        };
                        const typeLabels = {
                            'Text': 'Texte',
                            'MultiChoice': 'QCM',
                            'TrueFalse': 'Vrai/Faux',
                            'Blanks': 'Texte à trous'
                        };
                        const icon = typeIcons[interType] || '📌';
                        const typeLabel = typeLabels[interType] || interType;
                        const label = inter.label || typeLabel;
                        const fromTime = inter.duration?.from || 0;
                        const toTime = inter.duration?.to || 999999;
                        
                        // Générer le contenu selon le type
                        let contentHtmlInner = '';
                        switch (interType) {
                            case 'Text':
                                const textContent = inter.action?.params?.text || '';
                                contentHtmlInner = '<div class="cp-interaction-text">' + textContent + '</div>';
                                break;
                            case 'MultiChoice':
                                const mcQuestion = (inter.action?.params?.question || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
                                const mcAnswers = inter.action?.params?.answers || [];
                                contentHtmlInner = '<div class="cp-interaction-question">' + escapeHtml(mcQuestion) + '</div>';
                                contentHtmlInner += '<div class="cp-interaction-answers">';
                                mcAnswers.forEach(ans => {
                                    const ansText = (ans.text || '').replace(/<[^>]*>/g, '');
                                    contentHtmlInner += '<div class="cp-interaction-answer ' + (ans.correct ? 'correct' : '') + '">' +
                                        '<span class="cp-interaction-answer-marker"></span>' +
                                        '<span>' + escapeHtml(ansText) + '</span></div>';
                                });
                                contentHtmlInner += '</div>';
                                break;
                            case 'TrueFalse':
                                const tfQuestion = inter.action?.params?.question || '';
                                const tfCorrect = inter.action?.params?.correct === 'true';
                                contentHtmlInner = '<div class="cp-interaction-question">' + escapeHtml(tfQuestion) + '</div>';
                                contentHtmlInner += '<div class="cp-interaction-tf">';
                                contentHtmlInner += '<div class="cp-interaction-tf-btn true-btn ' + (tfCorrect ? 'correct' : '') + '">✓ Vrai</div>';
                                contentHtmlInner += '<div class="cp-interaction-tf-btn false-btn ' + (!tfCorrect ? 'correct' : '') + '">✗ Faux</div>';
                                contentHtmlInner += '</div>';
                                break;
                            case 'Blanks':
                                let blanksText = (inter.action?.params?.questions?.[0] || inter.action?.params?.text || '').replace(/<[^>]*>/g, '');
                                // Remplacer *mot* par un champ vide
                                blanksText = blanksText.replace(/\*([^*]+)\*/g, '<span class="cp-interaction-blank">$1</span>');
                                contentHtmlInner = '<div class="cp-interaction-blanks">' + blanksText + '</div>';
                                break;
                            default:
                                contentHtmlInner = '<div class="cp-interaction-text">' + escapeHtml(label) + '</div>';
                        }
                        
                        // Carte d'interaction
                        interactionsHtml +=
                            '<div class="cp-video-interaction" ' +
                                 'id="cpInteraction_' + idx + '_' + iIdx + '" ' +
                                 'data-idx="' + iIdx + '" ' +
                                 'data-from="' + fromTime + '" ' +
                                 'data-to="' + toTime + '" ' +
                                 'data-pause="' + (inter.pause !== false ? '1' : '0') + '" ' +
                                 'onmousedown="cpStartDragInteraction(event, ' + idx + ', ' + iIdx + ')" ' +
                                 'title="Glisser pour déplacer" ' +
                                 'style="left: ' + (inter.x ?? 50) + '%; top: ' + (inter.y ?? 50) + '%; cursor: move;">' +
                                '<div class="cp-interaction-header">' +
                                    '<span class="cp-interaction-type">' + icon + ' ' + typeLabel + '</span>' +
                                    '<span class="cp-interaction-time">' + cpFormatTime(fromTime) + '</span>' +
                                '</div>' +
                                '<div class="cp-interaction-body">' + contentHtmlInner + '</div>' +
                                '<button class="cp-interaction-edit-btn" onmousedown="event.stopPropagation();" onclick="event.stopPropagation(); cpEditInteraction(' + iIdx + ')" title="Éditer">✏️</button>' +
                            '</div>';
                        
                        // Marqueur sur la timeline (sera positionné par JS)
                        timelineMarkersHtml += 
                            '<div class="cp-timeline-marker" ' +
                                 'id="cpTimelineMarker_' + idx + '_' + iIdx + '" ' +
                                 'data-idx="' + iIdx + '" ' +
                                 'data-from="' + fromTime + '" ' +
                                 'onclick="event.stopPropagation(); cpGoToInteraction(' + iIdx + ')" ' +
                                 'onmouseenter="cpShowInteraction(' + idx + ', ' + iIdx + ')" ' +
                                 'onmouseleave="cpHideInteraction(' + idx + ', ' + iIdx + ')" ' +
                                 'title="' + escapeHtml(label) + ' (' + cpFormatTime(fromTime) + ')"></div>';
                    });
                    
                    const ytId = cpGetYouTubeId(videoPath);
                    const videoTagHtml = ytId
                        ? '<div id="cpCanvasVideo_' + idx + '" data-yt-id="' + ytId + '" style="width:100%;height:100%;background:#000;"></div>' +
                          '<div class="cp-yt-click-layer" onclick="cpToggleCanvasVideo(event, ' + idx + ')" ondblclick="cpOpenVideoInteractionsPanel(event, ' + idx + ')" style="position:absolute;top:0;left:0;width:100%;height:calc(100% - 32px);z-index:2;cursor:pointer;"></div>'
                        : '<video id="cpCanvasVideo_' + idx + '" src="' + videoPath + '" ' +
                               'onclick="cpToggleCanvasVideo(event, ' + idx + ')" ' +
                               'ondblclick="cpOpenVideoInteractionsPanel(event, ' + idx + ')"></video>';
                    
                    contentHtml = '<div class="cp-video-element cp-video-preview-element" data-video-idx="' + idx + '">' +
                        '<div class="cp-video-wrapper" id="cpVideoWrapper_' + idx + '">' +
                            videoTagHtml +
                            '<div class="cp-video-interactions-layer" id="cpInteractionsLayer_' + idx + '">' +
                                interactionsHtml +
                            '</div>' +
                            '<div class="cp-video-controls">' +
                                '<button class="cp-video-ctrl-btn" onclick="cpToggleCanvasVideo(event, ' + idx + ')">' +
                                    '<span class="cp-video-play-icon">▶</span>' +
                                    '<span class="cp-video-pause-icon" style="display:none;">⏸</span>' +
                                '</button>' +
                                '<span class="cp-video-time" id="cpVideoTime_' + idx + '">00:00</span>' +
                                '<div class="cp-video-progress-container">' +
                                    '<input type="range" class="cp-video-progress" id="cpVideoProgress_' + idx + '" value="0" min="0" max="100" step="0.1" ' +
                                           'onmousedown="event.stopPropagation();" ' +
                                           'oninput="cpSeekCanvasVideo(' + idx + ', this.value)">' +
                                    '<div class="cp-timeline-markers" id="cpTimelineMarkers_' + idx + '">' +
                                        timelineMarkersHtml +
                                    '</div>' +
                                '</div>' +
                                '<span class="cp-video-duration" id="cpVideoDuration_' + idx + '">00:00</span>' +
                            '</div>' +
                        '</div>' +
                        (interCount > 0 ? '<span class="cp-video-badge">' + interCount + ' interaction(s)</span>' : '') +
                    '</div>';
                } else {
                    contentHtml = `<div class="cp-video-element">
                        <div class="cp-video-icon">🎥</div>
                        <div class="cp-video-label">Vidéo interactive</div>
                        <small>Cliquer pour configurer</small>
                    </div>`;
                }
                break;
            case 'dialogcards':
                const dcDialogs = el.action?.params?.dialogs || [];
                const dcTotal = dcDialogs.length;
                const dcState = cpDcGetPreview(slideIdx, idx, dcTotal);
                const dcCard = dcDialogs[dcState.card] || { text: '', answer: '' };
                const dcImage = dcCard.image?.path || dcCard.image;
                // Nettoyer les balises HTML pour l'aperçu sur le canvas
                const dcRaw = dcState.flipped ? (dcCard.answer || 'Verso') : (dcCard.text || 'Recto');
                const dcText = dcRaw.replace(/<[^>]*>/g, '');
                const dcNavHtml = dcTotal > 1 ? `
                        <div class="cp-dialogcard-nav">
                            <button class="cp-dialogcard-nav-btn" ${dcState.card === 0 ? 'disabled' : ''}
                                    onmousedown="event.stopPropagation();"
                                    onclick="event.stopPropagation(); cpDcPreviewNav(${idx}, -1)" title="Carte précédente">◀</button>
                            <span class="cp-dialogcard-progress">Carte ${dcState.card + 1} sur ${dcTotal}</span>
                            <button class="cp-dialogcard-nav-btn" ${dcState.card === dcTotal - 1 ? 'disabled' : ''}
                                    onmousedown="event.stopPropagation();"
                                    onclick="event.stopPropagation(); cpDcPreviewNav(${idx}, 1)" title="Carte suivante">▶</button>
                        </div>` : '';
                contentHtml = `<div class="cp-dialogcard-preview${dcState.flipped ? ' flipped' : ''}">
                    <div class="cp-dialogcard-inner">
                        ${dcImage ? `<img src="${dcImage}" class="cp-dialogcard-img" alt="">` : ''}
                        <div class="cp-dialogcard-text">${escapeHtml(dcText)}</div>
                        <button class="cp-dialogcard-hint"
                                onmousedown="event.stopPropagation();"
                                onclick="event.stopPropagation(); cpDcPreviewFlip(${idx})"
                                title="Retourner la carte">↻ Retourner</button>
                    </div>${dcNavHtml}
                </div>`;
                break;
            case 'multichoice':
                const mcQ = (el.action?.params?.question || 'Question ?').replace(/<[^>]*>/g, '');
                const mcAns = el.action?.params?.answers || [];
                let mcAnswersHtml = '';
                mcAns.forEach(ans => {
                    const ansText = (ans.text || '').replace(/<[^>]*>/g, '');
                    mcAnswersHtml += `<div class="cp-quiz-answer ${ans.correct ? 'correct' : ''}">
                        <span class="cp-quiz-marker"></span>
                        <span>${escapeHtml(ansText)}</span>
                    </div>`;
                });
                contentHtml = `<div class="cp-quiz-element cp-quiz-transparent">
                    <div class="cp-quiz-question">${escapeHtml(mcQ)}</div>
                    <div class="cp-quiz-answers">${mcAnswersHtml}</div>
                    <div class="cp-quiz-btn-container">
                        <button class="cp-quiz-verify-btn">Vérifier</button>
                    </div>
                    <div class="cp-quiz-spacer"></div>
                </div>`;
                break;
            case 'truefalse':
            case 'singlechoiceset':
                // Gestion SingleChoiceSet (format Éléa pour Vrai/Faux)
                // Afficher seulement la première question avec indicateur de navigation
                const choices = el.action?.params?.choices || [];
                let tfHtml = '';
                const totalQuestions = choices.length;
                
                if (choices.length > 0) {
                    // Afficher seulement la première question
                    const firstChoice = choices[0];
                    const qText = (firstChoice.question || 'Question ?').replace(/<[^>]*>/g, '');
                    const answers = firstChoice.answers || ['Vrai', 'Faux'];
                    
                    tfHtml = `<div class="cp-quiz-tf-question">
                        <div class="cp-quiz-question">${escapeHtml(qText)}</div>
                        <div class="cp-quiz-tf-answers">
                            ${answers.map((a, i) => {
                                const aText = (a || '').replace(/<[^>]*>/g, '');
                                return `<div class="cp-quiz-tf-answer ${i === 0 ? 'correct' : ''}">${escapeHtml(aText)}</div>`;
                            }).join('')}
                        </div>
                    </div>`;
                    
                    // Ajouter indicateur de navigation si plusieurs questions
                    if (totalQuestions > 1) {
                        tfHtml += `<div class="cp-quiz-nav-indicator">
                            <span class="cp-quiz-nav-info">Question 1 / ${totalQuestions}</span>
                        </div>`;
                    }
                } else {
                    // Fallback ancien format TrueFalse
                    const tfQ = (el.action?.params?.question || 'Affirmation ?').replace(/<[^>]*>/g, '');
                    const tfCorrect = el.action?.params?.correct === 'true' || el.action?.params?.correct === true;
                    tfHtml = `<div class="cp-quiz-tf-question">
                        <div class="cp-quiz-question">${escapeHtml(tfQ)}</div>
                        <div class="cp-quiz-tf-answers">
                            <div class="cp-quiz-tf-answer ${tfCorrect ? 'correct' : ''}">Vrai</div>
                            <div class="cp-quiz-tf-answer ${!tfCorrect ? 'correct' : ''}">Faux</div>
                        </div>
                    </div>`;
                }
                contentHtml = `<div class="cp-quiz-element cp-quiz-transparent">${tfHtml}<div class="cp-quiz-spacer"></div></div>`;
                break;
            case 'blanks':
                // Afficher le titre et les questions avec formatage HTML
                const blanksTitle = el.action?.params?.text || 'Texte à trous';
                const blanksTitleClean = blanksTitle.replace(/<[^>]*>/g, '');
                const blanksQuestions = el.action?.params?.questions || [];
                let blanksHtml = '';
                blanksQuestions.forEach(q => {
                    let blHtml = (q || '');
                    // Remplacer *mot* par un span trou, en préservant le HTML
                    blHtml = blHtml.replace(/\*([^*]+)\*/g, '<span class="cp-quiz-blank">$1</span>');
                    blanksHtml += `<div class="cp-quiz-blanks-line">${blHtml}</div>`;
                });
                if (!blanksHtml) {
                    blanksHtml = '<div class="cp-quiz-blanks-line">Complétez le mot *manquant*.</div>';
                }
                contentHtml = `<div class="cp-quiz-element cp-quiz-transparent">
                    <div class="cp-quiz-blanks-title">${blanksTitle}</div>
                    <div class="cp-quiz-blanks-text">${blanksHtml}</div>
                    <div class="cp-quiz-btn-container">
                        <button class="cp-quiz-verify-btn">Vérifier</button>
                    </div>
                    <div class="cp-quiz-spacer"></div>
                </div>`;
                break;
            case 'dragquestion':
                // Aperçu visuel interactif du Drag & Drop
                const dqParams = el.action?.params || {};
                const dqQuestion = dqParams.question || {};
                const dqSettings = dqQuestion.settings || {};
                const dqTask = dqQuestion.task || {};
                const dqElements = dqTask.elements || [];
                const dqDropZones = dqTask.dropZones || [];
                const dqBgPath = dqSettings.background?.path || '';
                const dqSize = dqSettings.size || { width: 800, height: 400 };
                const dqZoneOpacity = dqSettings.dropZoneOpacity !== undefined ? dqSettings.dropZoneOpacity : 0;
                
                // Debug: tracer les chemins d'images DQ
                
                // Conversion em → % : H5P utilise fontSize = 16 * (containerWidth / canvasWidth)
                // Les dimensions stockées sont en em, donc :
                //   width%  = stored_em * 1600 / canvasWidth
                //   height% = stored_em * 1600 / canvasHeight
                const dqRatio = dqSize.width / dqSize.height;
                const dqWidthFactor = 1600 / dqSize.width;
                const dqHeightFactor = 1600 / dqSize.height;
                
                // Générer le HTML des étiquettes
                let dqElementsHtml = dqElements.map((elem, eIdx) => {
                    const elemLib = (elem.type?.library || '').toLowerCase();
                    const isImageElem = elemLib.indexOf('h5p.image') !== -1;
                    const elemText = isImageElem ? (elem.type?.params?.alt || 'Image') : decodeHtmlEntities((elem.type?.params?.text || '')).replace(/<[^>]*>/g, '');
                    const ex = elem.x || 0;
                    const ey = elem.y || 0;
                    const ew = (elem.width || 5.5) * dqWidthFactor;
                    const eh = (elem.height || (isImageElem ? 8 : 3.5)) * dqHeightFactor;
                    const isSelected = cpDqSelectedItem && cpDqSelectedItem.type === 'element' && cpDqSelectedItem.idx === eIdx;
                    const imgPath = isImageElem ? (elem.type?.params?.file?.path || '') : '';
                    const innerContent = isImageElem 
                        ? `<img src="${escapeHtml(imgPath)}" style="width:100%; height:100%; object-fit:contain; pointer-events:none;" onerror="this.style.display='none'; this.parentElement.textContent='🖼️';">`
                        : `<span style="overflow: hidden; text-overflow: ellipsis; line-height: 1.1; word-wrap: break-word;">${escapeHtml(elemText)}</span>`;
                    return `<div class="cp-dq-canvas-element cp-dq-interactive ${isSelected ? 'selected' : ''}" 
                                 data-type="element" data-idx="${eIdx}"
                                 style="position: absolute; left: ${ex}%; top: ${ey}%; width: ${ew}%; height: ${eh}%; padding: ${isImageElem ? '0' : '2px 4px'}; background: ${isImageElem ? 'transparent' : (isSelected ? '#e3f2fd' : '#f8f8f8')}; border: ${isSelected ? '2px solid #1976d2' : (isImageElem ? '1px dashed #aaa' : '1px solid #bbb')}; border-radius: 4px; font-size: 0.7em; text-align: center; z-index: ${isSelected ? 15 : 10}; cursor: move; display: flex; align-items: center; justify-content: center; box-sizing: border-box; box-shadow: ${isSelected ? '0 0 8px rgba(25,118,210,0.5)' : (isImageElem ? 'none' : '0 2px 4px rgba(0,0,0,0.15)')};"
                                 onmousedown="cpDqStartDrag(event, 'element', ${eIdx})"
                                 onclick="event.stopPropagation(); cpDqSelectItem('element', ${eIdx}, ${idx})"
                                 title="${isImageElem ? 'Image' : 'Étiquette'} ${eIdx + 1}: ${escapeHtml(elemText)}">
                        ${innerContent}
                        <button onclick="event.stopPropagation(); cpDqDeleteElement(${eIdx})" 
                                style="position: absolute; top: 2px; right: 2px; width: 14px; height: 14px; border-radius: 50%; background: #e53935; color: white; border: none; font-size: 9px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; z-index: 20; opacity: 0.7;"
                                onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'"
                                title="Supprimer l'étiquette">×</button>
                        <div class="cp-dq-resize-handle" onmousedown="cpDqStartResize(event, 'element', ${eIdx})"></div>
                    </div>`;
                }).join('');
                
                // Générer le HTML des zones avec le même facteur
                let dqZonesHtml = dqDropZones.map((dz, zIdx) => {
                    const zx = dz.x || 0;
                    const zy = dz.y || 0;
                    const zw = (dz.width || 6.5) * dqWidthFactor;
                    const zh = (dz.height || 3.5) * dqHeightFactor;
                    return `<div class="cp-dq-canvas-dropzone cp-dq-interactive" 
                                 data-type="zone" data-idx="${zIdx}"
                                 style="position: absolute; left: ${zx}%; top: ${zy}%; width: ${zw}%; height: ${zh}%; border: 2px dashed rgba(156,39,176,0.7); border-radius: 4px; box-sizing: border-box; display: flex; align-items: center; justify-content: center; background: rgba(156,39,176,${dqZoneOpacity / 100}); z-index: 1; cursor: move;"
                                 onmousedown="cpDqStartDrag(event, 'zone', ${zIdx})"
                                 title="Zone ${zIdx + 1}">
                        <span style="font-size: 1.5em; font-weight: bold; color: rgba(156,39,176,0.9); pointer-events: none;">${zIdx + 1}</span>
                        <button onclick="event.stopPropagation(); cpDqDeleteZone(${zIdx})" 
                                style="position: absolute; top: -8px; right: -8px; width: 18px; height: 18px; border-radius: 50%; background: #e53935; color: white; border: 2px solid white; font-size: 10px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; z-index: 20;"
                                title="Supprimer la zone">×</button>
                        <div class="cp-dq-resize-handle zone" onmousedown="cpDqStartResize(event, 'zone', ${zIdx})"></div>
                    </div>`;
                }).join('');
                
                // Construire l'aperçu :
                // - Image + éléments collés en HAUT avec aspect-ratio préservé
                // - Largeur = 100% de la boîte (contrôle la taille de tout)
                // - Boutons toujours en bas de la boîte
                // - Hauteur de la boîte = marge/crop, ne déforme rien
                contentHtml = `<div class="cp-dq-canvas-preview" style="width: 100%; height: 100%; position: relative; background: #f5f5f5; border-radius: 8px; overflow: hidden;">
                    <div class="cp-dq-canvas-content" id="cpDqCanvasContent" 
                         style="position: absolute; top: 0; left: 0; width: 100%; aspect-ratio: ${dqSize.width} / ${dqSize.height};"
                         onclick="if(event.target === this || event.target.tagName === 'IMG') cpDqSelectItem(null)"
                         onmousemove="cpDqHandleMouseMove(event)" onmouseup="cpDqHandleMouseUp(event)" onmouseleave="cpDqHandleMouseUp(event)">
                        ${dqBgPath ? `<img src="${dqBgPath}" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;" onerror="this.style.display='none'">` : ''}
                        ${dqZonesHtml}
                        ${dqElementsHtml}
                        ${!dqBgPath && dqElements.length === 0 && dqDropZones.length === 0 ? '<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #999; font-size: 0.7em; text-align: center; pointer-events: none;">🎯 Glisser-Déposer<br><small>Double-clic pour éditer</small></div>' : ''}
                    </div>
                    <div class="h5p-actions" style="position: absolute; bottom: 0; left: 0; right: 0; padding: 8px; display: flex; align-items: center; justify-content: center; gap: 8px; background: #f0f0f0; border-top: 1px solid #ddd;">
                        <button class="btn btn-primary btn-sm" onclick="cpDqPreviewCheck()" style="background: #0d6efd; color: white; border: none; padding: 4px 12px; border-radius: 4px; font-size: 0.7em; cursor: pointer;">✓ Vérifier</button>
                        <button class="btn btn-secondary btn-sm" onclick="cpDqPreviewReset()" style="background: #6c757d; color: white; border: none; padding: 4px 12px; border-radius: 4px; font-size: 0.7em; cursor: pointer;">↻ Réessayer</button>
                    </div>
                </div>`;
                break;
            case 'shape':
                const shapeType = el.action?.params?.type || 'rectangle';
                const shapeP = el.action?.params?.shape || {};
                const lineP = el.action?.params?.line || {};
                if (shapeType === 'horizontal-line') {
                    contentHtml = `<div style="width: 100%; height: 100%; position: relative; pointer-events: none;">
                        <div style="border-top: ${lineP.borderWidth || 2}px ${lineP.borderStyle || 'solid'} ${lineP.borderColor || '#000'}; width: 100%; position: absolute; top: 50%;"></div>
                    </div>`;
                } else if (shapeType === 'vertical-line') {
                    contentHtml = `<div style="width: 100%; height: 100%; position: relative; pointer-events: none;">
                        <div style="border-left: ${lineP.borderWidth || 2}px ${lineP.borderStyle || 'solid'} ${lineP.borderColor || '#000'}; height: 100%; position: absolute; left: 50%;"></div>
                    </div>`;
                } else {
                    const sFill = shapeP.fillColor || '#d0d0d0';
                    const sBorder = shapeP.borderColor || '#000';
                    const sBW = shapeP.borderWidth || '0';
                    const sBS = shapeP.borderStyle || 'solid';
                    const sBR = shapeType === 'circle' ? '50%' : ((shapeP.borderRadius || '0') + 'px');
                    contentHtml = `<div style="width: 100%; height: 100%; background: ${sFill}; border: ${sBW}px ${sBS} ${sBorder}; border-radius: ${sBR}; box-sizing: border-box;"></div>`;
                }
                break;
            case 'audio':
                const audioSrc = el.action?.params?.files?.[0]?.path || '';
                if (_forThumb) {
                    // MINIATURE : icône statique UNIQUEMENT — surtout pas de <audio> vivant.
                    // Les miniatures sont clonées et gardées en cache ; un <audio> par slide
                    // audio saturerait le navigateur (trop d'éléments média → la lecture finit
                    // par ne plus démarrer). Ici on ne montre que le pictogramme.
                    contentHtml = `<div class="cp-audio-element" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                        <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-width:36px;min-height:36px;
                                    border-radius:50%;background:${audioSrc ? '#1a73e8' : '#9aa0a6'};color:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.3);box-sizing:border-box;">
                            <svg viewBox="0 0 24 24" width="55%" height="55%" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                    </div>`;
                } else if (audioSrc) {
                    // CANVAS PRINCIPAL : bouton play/pause SANS <audio> intégré. La lecture passe
                    // par UN SEUL lecteur partagé pour tout l'éditeur (cpToggleAudioPlay). Ainsi,
                    // quel que soit le nombre de slides/parcours audio, le navigateur ne crée
                    // jamais plus d'un WebMediaPlayer → fini la saturation « au bout d'un moment »
                    // (Chrome bloque à ~75 lecteurs média : un <audio> par élément la déclenchait).
                    contentHtml = `<div class="cp-audio-element"
                                   style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                        <button type="button" class="cp-audio-play-btn" title="Écouter l'audio" data-audio-src="${escapeHtml(audioSrc)}"
                                onclick="event.stopPropagation(); cpToggleAudioPlay(event);"
                                style="width:100%;height:100%;min-width:36px;min-height:36px;border:none;border-radius:50%;
                                       background:#1a73e8;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;
                                       box-shadow:0 1px 4px rgba(0,0,0,0.3);padding:0;box-sizing:border-box;">
                            <svg class="cp-audio-ic-play" viewBox="0 0 24 24" width="55%" height="55%" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                            <svg class="cp-audio-ic-pause" viewBox="0 0 24 24" width="55%" height="55%" fill="currentColor" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                        </button>
                    </div>`;
                } else {
                    // Pas de fichier : placeholder gris, double-clic pour choisir un MP3
                    contentHtml = `<div class="cp-audio-element" ondblclick="cpBrowseAudio(event)"
                                   style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;">
                        <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-width:36px;min-height:36px;
                                    border-radius:50%;background:#9aa0a6;color:#fff;box-shadow:0 1px 4px rgba(0,0,0,0.3);box-sizing:border-box;">
                            <svg viewBox="0 0 24 24" width="55%" height="55%" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                        <small style="color:#666;font-size:10px;white-space:nowrap;">Double-clic : MP3</small>
                    </div>`;
                }
                break;
            default:
                contentHtml = `<div style="padding: 8px; color: #999; font-size: 12px;">${type || 'Élément'}</div>`;
        }
        
        // Pour les éléments texte : comme les autres mais avec double-clic pour éditer
        if (isTextElement) {
            html += `
                <div class="cp-element ${isSelected ? 'selected' : ''} cp-element-text"
                     style="${style}"
                     data-idx="${idx}"
                     data-type="text"
                     onmousedown="cpStartDrag(event, ${idx})"
                     onclick="cpSelectElement(event, ${idx})"
                     ondblclick="cpTextEnterEdit(event, ${idx})"
                     oncontextmenu="cpShowElementContextMenu(event, ${idx})">
                    <div class="cp-element-drag-handle" onmousedown="cpStartDrag(event, ${idx})" title="Déplacer"></div>
                    <div class="cp-element-content">${contentHtml}</div>
                    <div class="cp-element-resize" onmousedown="cpStartResize(event, ${idx})"></div>
                    <button class="cp-element-delete" onmousedown="event.stopPropagation();" onclick="event.stopPropagation(); cpDeleteElement(${idx})">×</button>
                </div>`;
        } else if (type === 'table') {
            html += `
                <div class="cp-element ${isSelected ? 'selected' : ''} cp-element-table"
                     style="${style}"
                     data-idx="${idx}"
                     data-type="table"
                     onmousedown="cpStartDrag(event, ${idx})"
                     onclick="cpSelectElement(event, ${idx})"
                     oncontextmenu="cpShowElementContextMenu(event, ${idx})">
                    <div class="cp-element-content">${contentHtml}</div>
                    <div class="cp-element-resize" onmousedown="cpStartResize(event, ${idx})"></div>
                    <button class="cp-element-delete" onclick="event.stopPropagation(); cpDeleteElement(${idx})">×</button>
                </div>`;
        } else {
            const isShapeEl = type === 'shape';
            const isImageEl = type === 'image';
            html += `
                <div class="cp-element ${isSelected ? 'selected' : ''}" 
                     style="${style}" 
                     data-idx="${idx}"
                     onmousedown="cpStartDrag(event, ${idx})"
                     onclick="cpSelectElement(event, ${idx})"
                     oncontextmenu="cpShowElementContextMenu(event, ${idx})">
                    <div class="cp-element-content">${contentHtml}</div>
                    <div class="cp-element-resize" onmousedown="cpStartResize(event, ${idx})"></div>
                    ${isImageEl && isSelected ? `<div class="cp-element-rotate" onmousedown="cpStartRotate(event, ${idx})" title="Rotation">↻</div>` : ''}
                    <button class="cp-element-delete" onclick="event.stopPropagation(); cpDeleteElement(${idx})">×</button>
                </div>`;
        }
    });
    
    // Cleanup YouTube players before re-rendering DOM
    if (!_forThumb && typeof cpCleanupYTPlayers === 'function') cpCleanupYTPlayers();
    
    canvas.innerHTML = html;
    
    // Apply deferred img src assignments synchronously (after DOM is ready)
    deferredImgSrcs.forEach(item => {
        const imgEl = _forThumb ? canvas.querySelector('#' + item.id) : document.getElementById(item.id);
        if (imgEl) imgEl.src = item.src;
    });
    
    if (!_forThumb) {
        // Ré-accrocher le lecteur audio partagé au bon bouton après reconstruction du canvas
        // (le bouton précédent vient d'être détruit par innerHTML). Si la source en cours de
        // lecture n'existe plus dans ce slide → on coupe.
        cpResyncAudioButton();

        // Initialiser les contrôles vidéo si présents
        cpInitVideoControls();
        
        // Ajuster la taille de police proportionnellement au canvas
        cpUpdateBaseFontSize();
        
        // Mettre à jour la vignette de la slide courante avec un clone du canvas réel
        cpUpdateCurrentThumb();
    }
}

// === SYSTÈME DE VIGNETTES AVEC CACHE ===
// Les vignettes sont capturées depuis le vrai canvas après rendu réel.
// Cache : tableau de clones DOM indexés par numéro de slide.

var _cpThumbCache = []; // Array of DOM clones (or null)

// Capturer la slide courante (depuis le vrai canvas) dans le cache
function _cpCaptureCurrentToCache(slideIdx) {
    var canvas = document.getElementById('cpCanvasInner');
    if (!canvas) return;
    // Mémoriser la largeur réelle du canvas (avant zoom) pour les clones
    _cpThumbCanvasWidth = canvas.offsetWidth || 500;
    _cpThumbCache[slideIdx] = _cpCleanClone(canvas);
    _cpApplyThumbFromCache(slideIdx);
}

// Cloner un élément DOM et nettoyer les éléments d'UI éditeur
function _cpCleanClone(sourceEl) {
    var clone = sourceEl.cloneNode(true);
    clone.querySelectorAll('.cp-element-drag-handle, .cp-element-resize-handle, .cp-element-rotate, .cp-float-toolbar, .cp-emoji-popup, #cpRectSelectBox, .cp-element-delete, .cp-dq-resize-handle, .cp-canvas-add-btn').forEach(function(el) { el.remove(); });
    clone.querySelectorAll('.cp-element.selected').forEach(function(el) { el.classList.remove('selected'); });
    clone.querySelectorAll('[contenteditable]').forEach(function(el) { el.removeAttribute('contenteditable'); });
    clone.querySelectorAll('video, iframe').forEach(function(el) {
        var placeholder = document.createElement('div');
        placeholder.style.cssText = 'width:100%;height:100%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;';
        placeholder.innerHTML = '<span style="color:white;font-size:40px;opacity:0.7;">▶</span>';
        el.replaceWith(placeholder);
    });
    // Filet de sécurité : aucune balise <audio> vivante dans une miniature en cache
    // (chaque lecteur média retenu finit par empêcher la lecture ailleurs).
    clone.querySelectorAll('audio').forEach(function(el) { el.remove(); });
    clone.removeAttribute('id');
    // NE PAS modifier le fontSize : le clone doit garder le même fontSize que la source
    // car la vignette utilise un scale dynamique basé sur la largeur réelle du canvas
    return clone;
}

// Appliquer un clone du cache dans la vignette DOM
function _cpApplyThumbFromCache(slideIdx) {
    var thumbs = document.querySelectorAll('.cp-slide-thumb');
    var thumb = thumbs[slideIdx];
    if (!thumb) return;
    var preview = thumb.querySelector('.cp-slide-thumb-preview');
    if (!preview) return;
    
    var cached = _cpThumbCache[slideIdx];
    if (!cached) return;
    
    var display = cached.cloneNode(true);
    // Utiliser la largeur réelle du canvas pour le clone (aspect ratio 2:1)
    var canvasW = _cpThumbCanvasWidth || 500;
    var canvasH = canvasW / 2;
    // Calculer le scale pour que le clone tienne dans 90×45px
    var thumbW = 90;
    var scale = thumbW / canvasW;
    display.style.cssText = 'position:absolute; top:0; left:0; width:' + canvasW + 'px; height:' + canvasH + 'px; transform:scale(' + scale + '); transform-origin:top left; overflow:hidden; pointer-events:none;';
    
    preview.innerHTML = '';
    preview.appendChild(display);
}

var _cpThumbCanvasWidth = 500; // Mis à jour à chaque capture

// Capturer la vignette de la slide courante (appelé après cpRenderSlideElements)
function cpUpdateCurrentThumb() {
    _cpCaptureCurrentToCache(cpCurrentSlide);
}

// Générer toutes les vignettes au chargement initial
// Utilise le canvas offscreen avec le MÊME code de rendu
var _cpOffscreenInner = null;

function _cpGetOffscreenCanvas() {
    // Obtenir la largeur réelle et le fontSize du canvas
    var realCanvas = document.getElementById('cpCanvasInner');
    var w = (realCanvas ? realCanvas.offsetWidth : 0) || _cpThumbCanvasWidth || 500;
    _cpThumbCanvasWidth = w;
    var h = w / 2;
    // Utiliser le même fontSize que le vrai canvas (zoom-compensé)
    var realFontSize = realCanvas ? realCanvas.style.fontSize : ((CP_ELEA_FONT_BASE || 18.5) + 'px');
    
    if (_cpOffscreenInner && document.body.contains(_cpOffscreenInner)) {
        var parent = _cpOffscreenInner.parentElement;
        if (parent) {
            parent.style.width = w + 'px';
            parent.style.height = h + 'px';
            parent.parentElement.style.width = w + 'px';
        }
        _cpOffscreenInner.style.fontSize = realFontSize;
        return _cpOffscreenInner;
    }
    
    var wrapper = document.createElement('div');
    wrapper.className = 'cp-canvas-wrapper';
    wrapper.style.cssText = 'position:fixed; left:-9999px; top:-9999px; width:' + w + 'px; pointer-events:none; visibility:hidden;';
    
    var canvasDiv = document.createElement('div');
    canvasDiv.className = 'cp-canvas';
    canvasDiv.style.cssText = 'width:' + w + 'px; height:' + h + 'px; transform:none;';
    
    var inner = document.createElement('div');
    inner.className = 'cp-canvas-inner';
    inner.style.cssText = 'position:absolute; top:0; left:0; width:100%; height:100%; overflow:hidden; border-radius:8px;';
    inner.style.fontSize = realFontSize;
    
    canvasDiv.appendChild(inner);
    wrapper.appendChild(canvasDiv);
    document.body.appendChild(wrapper);
    _cpOffscreenInner = inner;
    return inner;
}

function cpUpdateAllThumbs() {
    var activity = getSelectedActivity();
    if (!activity) return;
    var slides = activity.content?.presentation?.slides || [];
    if (slides.length === 0) return;
    
    // Slide courante : capturer depuis le vrai canvas (rendu fidèle)
    cpUpdateCurrentThumb();
    
    // Autres slides : rendre en offscreen puis cacher
    var offscreen = _cpGetOffscreenCanvas();
    if (!offscreen) return;
    
    var queue = [];
    for (var i = 0; i < slides.length; i++) {
        // Ne pas re-rendre les slides déjà en cache
        if (i !== cpCurrentSlide && !_cpThumbCache[i]) queue.push(i);
    }
    
    function processNext() {
        if (queue.length === 0) return;
        var idx = queue.shift();
        
        // Rendre dans le canvas offscreen avec le même code
        cpRenderSlideElements(offscreen, idx);
        
        // Attendre le chargement des images
        var images = offscreen.querySelectorAll('img');
        var pending = 0;
        var done = false;
        
        function onDone() {
            if (done) return;
            done = true;
            // Mettre en cache
            _cpThumbCache[idx] = _cpCleanClone(offscreen);
            _cpApplyThumbFromCache(idx);
            if (queue.length > 0) requestAnimationFrame(processNext);
        }
        
        images.forEach(function(img) {
            if (!img.complete) {
                pending++;
                img.onload = img.onerror = function() { if (--pending <= 0) onDone(); };
            }
        });
        
        if (pending === 0) requestAnimationFrame(onDone);
    }
    
    if (queue.length > 0) requestAnimationFrame(processNext);
}

// Invalider le cache d'une slide (après modification)
function cpInvalidateThumb(slideIdx) {
    _cpThumbCache[slideIdx] = null;
}

// Invalider tout le cache (après ajout/suppression/réordonnancement)
function cpInvalidateAllThumbs() {
    _cpThumbCache = [];
}

function cpGoToSlide(idx) {
    var prevSlide = cpCurrentSlide;
    cpCurrentSlide = idx;
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    
    // Capturer la vignette de la slide qu'on quitte (depuis le vrai canvas)
    if (prevSlide !== idx) {
        _cpCaptureCurrentToCache(prevSlide);
    }
    
    // Mettre à jour le contenu du canvas sans tout reconstruire
    cpRenderSlideElements();
    
    // Mettre à jour le compteur de slides
    var counter = document.querySelector('.cp-slide-counter');
    var activity = getSelectedActivity();
    if (counter && activity) {
        var total = (activity.content?.presentation?.slides || []).length;
        counter.textContent = (idx + 1) + '/' + total;
    }
    
    // Mettre à jour la vignette active (highlight)
    var thumbs = document.querySelectorAll('.cp-slide-thumb');
    thumbs.forEach(function(t, i) {
        t.classList.toggle('active', i === idx);
    });
    
    // Scroll la vignette active en vue
    if (thumbs[idx]) {
        thumbs[idx].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    }
    
    // Fermer le panneau de propriétés
    var propsPanel = document.getElementById('cpPropsPanel');
    if (propsPanel) propsPanel.classList.remove('visible');
}

function cpAddSlide() {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    activity.content.presentation.slides.push({ elements: [] });
    cpCurrentSlide = activity.content.presentation.slides.length - 1;
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
}

function cpInsertSlideBefore() {
    const activity = getSelectedActivity();
    if (!activity) return;
    activity.content.presentation.slides.splice(cpCurrentSlide, 0, { elements: [] });
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
}

function cpInsertSlideAfter() {
    const activity = getSelectedActivity();
    if (!activity) return;
    activity.content.presentation.slides.splice(cpCurrentSlide + 1, 0, { elements: [] });
    cpCurrentSlide = cpCurrentSlide + 1;
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
}

function cpGetSlidePreviewHtml(slide) {
    // Placeholder initial - sera remplacé par cpUpdateAllThumbs avec un rendu fidèle
    if (!slide || !slide.elements || slide.elements.length === 0) {
        return '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:7px;">vide</div>';
    }
    var count = slide.elements.length;
    return '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#bbb;font-size:7px;">' + count + ' élt' + (count > 1 ? 's' : '') + '</div>';
}

function cpDeleteSlide(idx) {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slides = activity.content.presentation.slides;
    if (slides.length <= 1) {
        showToast('Impossible de supprimer la dernière slide', 'error');
        return;
    }
    
    if (!confirm('Supprimer cette slide ?')) return;
    
    slides.splice(idx, 1);
    if (cpCurrentSlide >= slides.length) cpCurrentSlide = slides.length - 1;
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
}

// Dupliquer une slide
function cpDuplicateSlide(idx) {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slides = activity.content.presentation.slides;
    const slideToClone = slides[idx];
    
    // Deep clone de la slide
    const clonedSlide = JSON.parse(JSON.stringify(slideToClone));
    
    // Insérer après la slide actuelle
    slides.splice(idx + 1, 0, clonedSlide);
    cpCurrentSlide = idx + 1;
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
    showToast('Slide dupliquée', 'success');
}

// Drag & Drop des slides
function cpStartDragSlide(event, idx) {
    cpDraggingSlideIdx = idx;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', idx.toString());
    event.target.classList.add('dragging');
}

function cpDragOverSlide(event, idx) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    
    // Ajouter un indicateur visuel
    document.querySelectorAll('.cp-slide-thumb').forEach((el, i) => {
        el.classList.remove('drag-over-before', 'drag-over-after');
        if (i === idx && cpDraggingSlideIdx !== null && cpDraggingSlideIdx !== idx) {
            if (cpDraggingSlideIdx < idx) {
                el.classList.add('drag-over-after');
            } else {
                el.classList.add('drag-over-before');
            }
        }
    });
}

function cpDropSlide(event, targetIdx) {
    event.preventDefault();
    
    if (cpDraggingSlideIdx === null || cpDraggingSlideIdx === targetIdx) {
        cpEndDragSlide(event);
        return;
    }
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slides = activity.content.presentation.slides;
    const sourceIdx = cpDraggingSlideIdx;
    
    // Extraire la slide source
    const [movedSlide] = slides.splice(sourceIdx, 1);
    
    // Calculer le nouvel index
    // Quand on déplace vers la droite (sourceIdx < targetIdx), on insère APRÈS la cible
    // Quand on déplace vers la gauche (sourceIdx > targetIdx), on insère AVANT la cible
    let newIdx = targetIdx;
    if (sourceIdx < targetIdx) {
        // Déplacement vers la droite : pas besoin d'ajuster
        // (l'élément supprimé était avant, donc targetIdx pointe déjà au bon endroit)
        newIdx = targetIdx;
    }
    // Si sourceIdx > targetIdx, on garde newIdx = targetIdx
    
    // Insérer à la nouvelle position
    slides.splice(newIdx, 0, movedSlide);
    
    // Mettre à jour la slide courante
    if (cpCurrentSlide === sourceIdx) {
        cpCurrentSlide = newIdx;
    } else if (sourceIdx < cpCurrentSlide && newIdx >= cpCurrentSlide) {
        cpCurrentSlide--;
    } else if (sourceIdx > cpCurrentSlide && newIdx <= cpCurrentSlide) {
        cpCurrentSlide++;
    }
    
    cpDraggingSlideIdx = null;
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
}

function cpEndDragSlide(event) {
    cpDraggingSlideIdx = null;
    document.querySelectorAll('.cp-slide-thumb').forEach(el => {
        el.classList.remove('dragging', 'drag-over-before', 'drag-over-after');
    });
}

function cpAddElement(type) {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide.elements) slide.elements = [];
    
    let element = {
        x: 10 + (slide.elements.length * 5) % 50,
        y: 10 + (slide.elements.length * 5) % 50,
        width: 30,
        height: 20,
        action: {}
    };
    
    switch (type) {
        case 'text':
            element.action = {
                library: 'H5P.Text 1.1',
                params: { text: '<p style="text-align:center;"><strong><span style="font-size:1.5em;">Nouveau texte</span></strong></p>' }
            };
            element.height = 15;
            break;
        case 'table':
            cpShowTableCreationDialog();
            return;
        case 'image':
            element.action = {
                library: 'H5P.Image 1.1',
                params: { file: { path: '' }, alt: '' }
            };
            element.width = 40;
            element.height = 35;
            break;
        case 'video':
            element.action = {
                library: 'H5P.InteractiveVideo 1.27',
                params: {
                    interactiveVideo: {
                        video: { files: [{ path: '', mime: 'video/mp4' }] },
                        assets: { interactions: [], bookmarks: [], endscreens: [] }
                    },
                    override: {
                        autoplay: false,
                        loop: false,
                        showBookmarksmenuOnLoad: false,
                        showRewind10: false,
                        preventSkippingMode: 'none',
                        deactivateSound: true,
                        showSolutionButton: 'off'
                    }
                }
            };
            // Dimensions généreuses pour inclure la barre de progression H5P
            element.x = 15;
            element.y = 5;
            element.width = 70;
            element.height = 85;
            break;
        case 'audio':
            element.action = {
                library: 'H5P.Audio 1.5',
                params: {
                    fitToWrapper: true,
                    playerMode: 'minimalistic',
                    controls: true,
                    autoplay: false,
                    playAudio: 'Lire le son',
                    pauseAudio: 'Mettre en pause',
                    contentName: 'Audio',
                    audioNotSupported: "Votre navigateur ne supporte pas l'audio",
                    files: []
                }
            };
            // Petit bouton rond (player minimaliste H5P), placé en haut à gauche
            element.x = 0;
            element.y = 0;
            element.width = 5;
            element.height = 9.876543209876543;
            break;
        case 'dialogcards':
            element.action = {
                library: 'H5P.Dialogcards 1.9',
                params: {
                    mode: 'normal',
                    dialogs: [
                        { text: 'Recto de la carte', answer: 'Verso de la carte', tips: {} }
                    ],
                    behaviour: {
                        enableRetry: true,
                        disableBackwardsNavigation: false,
                        scaleTextNotCard: true,
                        randomCards: false,
                        maxProficiency: 5,
                        quickProgression: false
                    },
                    title: '',
                    description: '',
                    answer: 'Retourner',
                    next: 'Suivant',
                    prev: 'Précédent',
                    retry: 'Recommencer',
                    correctAnswer: "J'ai eu bon!",
                    incorrectAnswer: "J'ai eu faux",
                    round: 'Round @round',
                    cardsLeft: 'Cartes restantes: @number',
                    nextRound: 'Procéder au round @round',
                    startOver: 'Recommencer',
                    showSummary: 'Suivant',
                    summary: 'Résumé',
                    summaryCardsRight: 'Cartes correctes:',
                    summaryCardsWrong: 'Cartes incorrectes:',
                    summaryCardsNotShown: 'Cartes non montrées:',
                    summaryOverallScore: 'Score global',
                    summaryCardsCompleted: 'Cartes que vous avez complétées:',
                    summaryCompletedRounds: 'Rounds complétés:',
                    summaryAllDone: 'Bien joué! Vous avez réussi à avoir les @cards cartes correctes @max fois de suite!',
                    progressText: 'Carte @card sur @total',
                    cardFrontLabel: 'Le devant de la carte',
                    cardBackLabel: 'Le dos de la carte',
                    tipButtonLabel: "Montrer l'indice",
                    audioNotSupported: 'Votre navigateur ne supporte pas ce fichier audio',
                    confirmStartingOver: {
                        header: 'Recommencer?',
                        body: 'Toutes les progressions seront perdues. Êtes-vous sûr de vouloir recommencer?',
                        cancelLabel: 'Annuler',
                        confirmLabel: 'Recommencer'
                    }
                },
                subContentId: generateUUID(),
                metadata: {
                    contentType: 'Dialog Cards',
                    license: 'U',
                    title: 'Sans titre Dialog Cards'
                }
            };
            element.width = 35;
            element.height = 80;
            break;
        case 'multichoice':
            element.action = {
                library: 'H5P.MultiChoice 1.16',
                params: {
                    question: '<p>Nouvelle question ?</p>',
                    answers: [
                        { text: '<div>Réponse A</div>', correct: true, tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' } },
                        { text: '<div>Réponse B</div>', correct: false, tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' } }
                    ],
                    behaviour: {
                        enableRetry: true,
                        enableSolutionsButton: false,
                        enableCheckButton: true,
                        type: 'auto',
                        singlePoint: false,
                        randomAnswers: true,
                        showSolutionsRequiresInput: true,
                        confirmCheckDialog: false,
                        confirmRetryDialog: false,
                        autoCheck: false,
                        passPercentage: 100,
                        showScorePoints: true
                    }
                },
                subContentId: generateUUID(),
                metadata: {
                    contentType: 'Multiple Choice',
                    license: 'U',
                    title: 'Sans titre Multiple Choice',
                    authors: [],
                    changes: [],
                    extraTitle: 'Sans titre Multiple Choice'
                }
            };
            element.backgroundOpacity = 0;
            element.width = 50;
            element.height = 50;
            break;
        case 'truefalse':
            // Utiliser H5P.TrueFalse (format Éléa avec support feedback)
            element.action = {
                library: 'H5P.TrueFalse 1.8',
                params: {
                    media: { type: { params: {} }, disableImageZooming: false },
                    question: '<p>Affirmation \u00e0 \u00e9valuer ?</p>',
                    correct: 'true',
                    behaviour: {
                        enableRetry: true,
                        enableSolutionsButton: true,
                        enableCheckButton: true,
                        confirmCheckDialog: false,
                        confirmRetryDialog: false,
                        autoCheck: false,
                        feedbackOnCorrect: '',
                        feedbackOnWrong: ''
                    },
                    l10n: {
                        trueText: 'Vrai',
                        falseText: 'Faux',
                        score: 'Vous avez obtenu @score points sur un total de @total',
                        checkAnswer: 'V\u00e9rifier',
                        submitAnswer: 'V\u00e9rifier',
                        showSolutionButton: 'Voir la solution',
                        tryAgain: 'Recommencer',
                        wrongAnswerMessage: 'R\u00e9ponse incorrecte',
                        correctAnswerMessage: 'Bonne r\u00e9ponse',
                        scoreBarLabel: 'Vous avez obtenu @score points sur un total de @total',
                        a11yCheck: 'V\u00e9rifiez les r\u00e9ponses.',
                        a11yShowSolution: 'Montrer la solution.',
                        a11yRetry: 'R\u00e9essayer l\u0027exercice.'
                    },
                    confirmCheck: {
                        header: 'Terminer ?',
                        body: 'Voulez-vous vraiment terminer ?',
                        cancelLabel: 'Annuler',
                        confirmLabel: 'Confirmer'
                    },
                    confirmRetry: {
                        header: 'Recommencer ?',
                        body: 'Voulez-vous vraiment recommencer ?',
                        cancelLabel: 'Annuler',
                        confirmLabel: 'Confirmer'
                    }
                },
                subContentId: generateUUID(),
                metadata: {
                    contentType: 'True/False Question',
                    license: 'U',
                    title: 'Sans titre True/False Question',
                    authors: [],
                    changes: [],
                    extraTitle: 'Sans titre True/False Question'
                }
            };
            element.backgroundOpacity = 0;
            element.width = 50;
            element.height = 45;
            break;
        case 'blanks':
            element.action = {
                library: 'H5P.Blanks 1.14',
                params: {
                    text: '<p>Texte à trous</p>',
                    questions: ['<p>Complétez le mot *manquant*.</p>'],
                    behaviour: {
                        enableRetry: true,
                        enableSolutionsButton: false,
                        enableCheckButton: true,
                        autoCheck: false,
                        caseSensitive: false,
                        showSolutionsRequiresInput: true,
                        separateLines: false,
                        confirmCheckDialog: false,
                        confirmRetryDialog: false,
                        acceptSpellingErrors: false
                    }
                },
                subContentId: generateUUID(),
                metadata: {
                    contentType: 'Fill in the Blanks',
                    license: 'U',
                    title: 'Sans titre Fill in the Blanks',
                    authors: [],
                    changes: [],
                    extraTitle: 'Sans titre Fill in the Blanks'
                }
            };
            element.backgroundOpacity = 0;
            element.width = 60;
            element.height = 50;
            break;
        case 'dragdrop':
            element.action = {
                library: 'H5P.DragQuestion 1.14',
                params: {
                    scoreShow: 'Vérifier',
                    submit: 'Envoyer',
                    tryAgain: 'Recommencer',
                    scoreExplanation: 'Les réponses correctes donnent +1 point. Les réponses incorrectes -1 point. Le score minimum est 0.',
                    question: {
                        settings: {
                            size: { width: 800, height: 400 },
                            background: { path: '', mime: 'image/png', copyright: { license: 'U' } }
                        },
                        task: {
                            elements: [],
                            dropZones: []
                        }
                    },
                    overallFeedback: [{ from: 0, to: 100 }],
                    behaviour: {
                        enableRetry: true,
                        enableCheckButton: true,
                        singlePoint: false,
                        applyPenalties: true,
                        enableScoreExplanation: true,
                        dropZoneHighlighting: 'dragging',
                        autoAlignSpacing: 2,
                        enableFullScreen: false,
                        showScorePoints: true,
                        showTitle: false
                    },
                    grabbablePrefix: 'Éléments déplaçables {num} de {total}.',
                    grabbableSuffix: 'Placé dans zone {num}.',
                    dropzonePrefix: 'Zone de dépôt {num} de {total}.',
                    noDropzone: 'Pas de zone de dépôt.',
                    tipLabel: "Montrer l'indice.",
                    tipAvailable: 'Indice disponible',
                    correctAnswer: 'Bonne réponse',
                    wrongAnswer: 'Réponse incorrecte',
                    feedbackHeader: 'Commentaire',
                    scoreBarLabel: 'Vous avez :num sur :total au total',
                    scoreExplanationButtonLabel: "Montrer l'explication du score",
                    localize: {
                        fullscreen: 'Plein écran',
                        exitFullscreen: 'Quitter le plein écran'
                    }
                },
                subContentId: generateUUID(),
                metadata: {
                    contentType: 'Drag and Drop',
                    license: 'U',
                    title: 'Sans titre Drag and Drop',
                    authors: [],
                    changes: [],
                    extraTitle: 'Sans titre Drag and Drop'
                }
            };
            element.backgroundOpacity = 0;
            element.x = 5;
            element.y = 10;
            element.width = 90;
            element.height = 80;
            break;
        case 'shape':
        case 'shape-rectangle':
            element.action = {
                library: 'H5P.Shape 1.0',
                params: {
                    type: 'rectangle',
                    shape: { fillColor: '#d0d0d0', borderColor: '#000000', borderWidth: '0', borderStyle: 'solid', borderRadius: '3' },
                    line: { borderColor: '#000000', borderWidth: '1', borderStyle: 'solid' }
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Shapes' }
            };
            element.backgroundOpacity = 0;
            element.width = 15;
            element.height = 25;
            break;
        case 'shape-circle':
            element.action = {
                library: 'H5P.Shape 1.0',
                params: {
                    type: 'circle',
                    shape: { fillColor: '#d0d0d0', borderColor: '#000000', borderWidth: '0', borderStyle: 'solid', borderRadius: '0' },
                    line: { borderColor: '#000000', borderWidth: '1', borderStyle: 'solid' }
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Shapes' }
            };
            element.backgroundOpacity = 0;
            element.width = 15;
            element.height = 25;
            break;
        case 'shape-line':
            element.action = {
                library: 'H5P.Shape 1.0',
                params: {
                    type: 'horizontal-line',
                    line: { borderColor: '#000000', borderWidth: '2', borderStyle: 'solid' },
                    shape: { fillColor: '#ffffff', borderColor: '#000000', borderWidth: '0', borderStyle: 'solid', borderRadius: '0' }
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Shapes' }
            };
            element.backgroundOpacity = 0;
            element.width = 20;
            element.height = 5;
            break;
    }
    
    slide.elements.push(element);
    cpSelectedElement = slide.elements.length - 1;
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Accès rapide : insérer un bloc texte pré-formaté (messages bas de slide)
var CP_QUICK_TEXTS = [
    {
        x: 66.66666666666667, y: 92.18106995884773, width: 33.333333333333336, height: 7.901234567901234,
        text: '<p style="text-align:right;">Continuez \ud83d\udc49</p>',
        backgroundOpacity: 0
    },
    {
        x: 66.66666746139526, y: 92.09876354829764, width: 33.333333333333336, height: 7.901234567901234,
        text: '<p style="text-align:right;">Quand c\'est fait, continuez \ud83d\udc49</p>',
        backgroundOpacity: 0
    },
    {
        x: 51.666666666666664, y: 92.18106995884773, width: 48.333333333333336, height: 7.901234567901234,
        text: '<p style="text-align:right;">\u00c0 la prochaine diapositive, cliquez sur "SUIVANT" \ud83d\udc49</p>',
        backgroundOpacity: 0
    }
];

function cpInsertQuickText(presetIdx) {
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    if (!slide.elements) slide.elements = [];
    
    var preset = CP_QUICK_TEXTS[presetIdx];
    if (!preset) return;
    
    var element = {
        x: preset.x,
        y: preset.y,
        width: preset.width,
        height: preset.height,
        action: {
            library: 'H5P.AdvancedText 1.1',
            params: { text: preset.text },
            subContentId: crypto.randomUUID ? crypto.randomUUID() : (Math.random().toString(36).substr(2) + '-' + Date.now().toString(36)),
            metadata: { contentType: 'Text', license: 'U', title: 'Sans titre Text', authors: [], changes: [] }
        },
        alwaysDisplayComments: false,
        backgroundOpacity: preset.backgroundOpacity,
        displayAsButton: false,
        buttonSize: 'big',
        goToSlideType: 'specified',
        invisible: false,
        solution: ''
    };
    
    slide.elements.push(element);
    cpSelectedElement = slide.elements.length - 1;
    cpSelectedElements.clear();
    cpSelectedElements.add(cpSelectedElement);
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast('Bloc ajouté', 'success');
}

// ==================== ACCÈS RAPIDE : IMAGES MODÈLES ====================

var _cpQuickImageMenuEl = null;

function cpToggleQuickImageMenu(btn) {
    if (_cpQuickImageMenuEl) { _cpQuickImageMenuEl.remove(); _cpQuickImageMenuEl = null; return; }
    
    if (typeof cpTemplateImages === 'undefined' || cpTemplateImages.length === 0) {
        showToast('Aucune image modèle disponible', 'error');
        return;
    }
    
    // Trier : underscore en premier, puis alphabétique
    const sorted = [...cpTemplateImages].sort((a, b) => {
        const aU = a.startsWith('_'), bU = b.startsWith('_');
        if (aU && !bU) return -1;
        if (!aU && bU) return 1;
        return a.localeCompare(b, 'fr', { sensitivity: 'base' });
    });
    
    const menu = document.createElement('div');
    menu.style.cssText = 'position:fixed; background:var(--bg-secondary,white); color:var(--text-primary,inherit); border:1px solid var(--gray-300,#ddd); border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.15); z-index:1000; width:280px; max-height:70vh; overflow-y:auto; padding:6px;';

    let html = '<div style="padding:4px 8px 6px; font-size:0.75rem; color:var(--text-muted,#999); border-bottom:1px solid var(--gray-200,#eee); margin-bottom:4px;">Images modèles — cliquer pour insérer</div>';
    html += '<div style="display:flex; flex-direction:column; gap:2px;">';
    sorted.forEach(f => {
        const label = f.replace(/\.[^.]+$/, '').replace(/[_-]/g, ' ');
        html += `<div onclick="cpInsertQuickTemplateImage('${f}'); cpCloseQuickImageMenu();"
                      style="display:flex; align-items:center; gap:8px; padding:4px 6px; cursor:pointer; border-radius:4px; transition:background 0.15s;"
                      onmouseenter="this.style.background='var(--gray-100)'" onmouseleave="this.style.background=''">
                    <img src="assets/templatesImages/${f}" style="width:40px; height:40px; object-fit:contain; border-radius:3px; flex-shrink:0;">
                    <span style="font-size:0.75rem; color:var(--text-secondary,#555); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${label}</span>
                 </div>`;
    });
    html += '</div>';
    menu.innerHTML = html;
    
    const rect = btn.getBoundingClientRect();
    menu.style.left = Math.min(rect.left, window.innerWidth - 290) + 'px';
    menu.style.top = rect.bottom + 4 + 'px';
    document.body.appendChild(menu);
    _cpQuickImageMenuEl = menu;
    
    setTimeout(() => document.addEventListener('click', cpCloseQuickImageMenu), 0);
}

function cpCloseQuickImageMenu() {
    if (_cpQuickImageMenuEl) { _cpQuickImageMenuEl.remove(); _cpQuickImageMenuEl = null; }
    document.removeEventListener('click', cpCloseQuickImageMenu);
}

function cpInsertQuickTemplateImage(filename) {
    const activity = getSelectedActivity();
    if (!activity) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    if (!slide.elements) slide.elements = [];
    
    showToast('Chargement...', 'info');
    
    // Copier l'image template vers editor_uploads via l'API
    const formData = new FormData();
    formData.append('action', 'copy_image_to_uploads');
    formData.append('source_type', 'template');
    formData.append('source', filename);
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { showToast(data.error || 'Erreur', 'error'); return; }
        
        const serverPath = data.url || data.path;
        const img = new Image();
        img.onload = function() {
            const canvasRatio = 2;
            const imgRatio = img.naturalWidth / img.naturalHeight;
            let w, h;
            if (imgRatio > canvasRatio) {
                w = Math.min(60, 80); h = w / imgRatio * canvasRatio;
            } else {
                h = Math.min(50, 80); w = h * imgRatio / canvasRatio;
            }
            w = Math.max(10, Math.min(90, w));
            h = Math.max(10, Math.min(90, h));
            
            const element = {
                x: Math.max(0, 50 - w / 2), y: Math.max(0, 50 - h / 2),
                width: w, height: h,
                action: {
                    library: 'H5P.Image 1.1',
                    params: { file: { path: serverPath }, alt: filename.replace(/\.[^.]+$/, '') },
                    subContentId: crypto.randomUUID ? crypto.randomUUID() : (Math.random().toString(36).substr(2) + '-' + Date.now().toString(36)),
                    metadata: { contentType: 'Image', license: 'U', title: 'Sans titre Image', authors: [], changes: [] }
                },
                alwaysDisplayComments: false, backgroundOpacity: 0,
                displayAsButton: false, buttonSize: 'big',
                goToSlideType: 'specified', invisible: false, solution: ''
            };
            
            slide.elements.push(element);
            cpSelectedElement = slide.elements.length - 1;
            cpSelectedElements.clear();
            cpSelectedElements.add(cpSelectedElement);
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
            showToast('Image insérée', 'success');
        };
        img.onerror = function() {
            showToast('Erreur chargement image', 'error');
        };
        img.src = serverPath;
    })
    .catch(err => showToast('Erreur: ' + err.message, 'error'));
}

// ==================== CP TEMPLATES ====================

var _cpTemplateMenuEl = null;
var _cpTemplateListCache = null; // Cache de la liste des templates
var _cpTemplateDataCache = {};   // Cache des données de slides par filename

function cpOpenTemplateMenu(btn) {
    if (_cpTemplateMenuEl) { _cpTemplateMenuEl.remove(); _cpTemplateMenuEl = null; return; }
    
    var rect = btn.getBoundingClientRect();
    var menu = document.createElement('div');
    menu.style.cssText = 'position:fixed; left:' + Math.min(rect.left, window.innerWidth - 250) + 'px; top:' + rect.bottom + 'px; ' +
        'background:var(--bg-secondary,white); color:var(--text-primary,inherit); border:1px solid var(--gray-300,#ddd); border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.15); ' +
        'z-index:1000; min-width:230px; max-height:70vh; overflow-y:auto;';
    document.body.appendChild(menu);
    _cpTemplateMenuEl = menu;
    
    setTimeout(function() {
        document.addEventListener('click', _cpCloseTemplateMenu, { once: true });
    }, 10);
    
    if (_cpTemplateListCache) {
        // Affichage instantané depuis le cache
        _cpRenderTemplateMenu(menu, _cpTemplateListCache);
    } else {
        menu.innerHTML = '<div style="padding: 10px 14px; color: var(--text-muted,#999); font-size: 0.8rem;">Chargement...</div>';
        fetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list_cp_templates' })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!_cpTemplateMenuEl) return;
            var templates = (data.success && data.templates) ? data.templates : [];
            _cpTemplateListCache = templates;
            _cpRenderTemplateMenu(menu, templates);
        })
        .catch(function() {
            if (_cpTemplateMenuEl) menu.innerHTML = '<div style="padding: 10px 14px; color: var(--danger-text,#e53935); font-size: 0.85rem;">Erreur de chargement</div>';
        });
    }
}

function _cpRenderTemplateMenu(menu, templates) {
    if (!templates || templates.length === 0) {
        menu.innerHTML = '<div style="padding: 10px 14px; color: var(--text-muted,#999); font-size: 0.85rem;">Aucun template CP disponible</div>';
        return;
    }
    menu.innerHTML = '';
    templates.forEach(function(tpl) {
        var item = document.createElement('div');
        item.style.cssText = 'padding:8px 14px; cursor:pointer; font-size:0.85rem; display:flex; align-items:center; justify-content:space-between; gap:8px; border-bottom:1px solid var(--gray-200,#f0f0f0);';
        item.innerHTML = '<span style="display:flex;align-items:center;gap:6px;"><span>🎬</span>' + escapeHtml(tpl.name) + '</span>' +
            '<span style="color:var(--text-muted,#999);font-size:0.75rem;">' + tpl.slides + ' slide' + (tpl.slides > 1 ? 's' : '') + '</span>';
        item.onmouseover = function() { this.style.background = 'var(--gray-100)'; };
        item.onmouseout = function() { this.style.background = ''; };
        item.onclick = function(e) {
            e.stopPropagation();
            _cpCloseTemplateMenu();
            cpLoadTemplate(tpl.file);
        };
        menu.appendChild(item);
    });
}

function _cpCloseTemplateMenu() {
    if (_cpTemplateMenuEl) { _cpTemplateMenuEl.remove(); _cpTemplateMenuEl = null; }
    document.removeEventListener('click', _cpCloseTemplateMenu);
}

function cpLoadTemplate(filename) {
    var activity = getSelectedActivity();
    if (!activity || !activity.content || !activity.content.presentation) {
        showToast('Aucune présentation en cours', 'error');
        return;
    }
    
    // Si les données sont en cache, appliquer instantanément
    if (_cpTemplateDataCache[filename]) {
        _cpApplyTemplate(activity, JSON.parse(JSON.stringify(_cpTemplateDataCache[filename])));
        return;
    }
    
    showToast('Chargement du template...', 'info');
    
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'load_cp_template',
            file: filename,
            sessionId: (typeof getEditorSessionId === 'function') ? getEditorSessionId() : ''
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.error) { showToast('Erreur : ' + data.error, 'error'); return; }
        if (!data.slides || data.slides.length === 0) { showToast('Template vide', 'error'); return; }
        
        // Mettre en cache
        _cpTemplateDataCache[filename] = data.slides;
        
        _cpApplyTemplate(activity, JSON.parse(JSON.stringify(data.slides)));
    })
    .catch(function(err) {
        console.error(err);
        showToast('Erreur réseau', 'error');
    });
}

function _cpApplyTemplate(activity, tplSlides) {
    var slides = activity.content.presentation.slides;
    var currentSlide = slides[cpCurrentSlide];
    
    var firstTplSlide = tplSlides[0];
    if (firstTplSlide.elements && firstTplSlide.elements.length > 0) {
        if (!currentSlide.elements) currentSlide.elements = [];
        firstTplSlide.elements.forEach(function(el) {
            currentSlide.elements.push(el);
        });
    }
    if (firstTplSlide.slideBackgroundSelector && !currentSlide.slideBackgroundSelector?.fillColor) {
        currentSlide.slideBackgroundSelector = firstTplSlide.slideBackgroundSelector;
    }
    
    for (var i = 1; i < tplSlides.length; i++) {
        slides.splice(cpCurrentSlide + i, 0, tplSlides[i]);
    }
    
    cpInvalidateAllThumbs();
    renderCoursePresentationEditor(activity);
    onCourseModified();
    var msg = tplSlides.length === 1 
        ? 'Éléments ajoutés sur la slide courante'
        : 'Éléments ajoutés + ' + (tplSlides.length - 1) + ' slide' + (tplSlides.length > 2 ? 's' : '') + ' insérée' + (tplSlides.length > 2 ? 's' : '');
    showToast(msg, 'success');
}

function cpSelectElement(event, idx) {
    // Sauvegarder les modifications en attente avant de changer la sélection
    cpDqFlushPendingChanges();

    event.stopPropagation();

    // Si on vient de finir une sélection rectangle, ne pas interférer
    if (_cpRectSelJustDone) { return; }

    var isCtrl = event.ctrlKey || event.metaKey || event.shiftKey;
    
    if (isCtrl) {
        event.preventDefault(); // Empêcher la sélection de texte
        // Ctrl+clic : basculer l'élément dans la multi-sélection
        cpSyncSelection();
        if (cpSelectedElements.has(idx)) {
            cpSelectedElements.delete(idx);
            if (cpSelectedElement === idx) {
                // Mettre à jour le primary : prendre un autre élément du Set
                cpSelectedElement = cpSelectedElements.size > 0 ? Array.from(cpSelectedElements)[cpSelectedElements.size - 1] : null;
            }
        } else {
            cpSelectedElements.add(idx);
            cpSelectedElement = idx;
        }
    } else {
        // Clic normal : sélection unique
        if (cpSelectedElement === idx && cpSelectedElements.size === 1) {
            return; // Déjà sélectionné seul, ne pas re-render (pour ne pas perdre le focus)
        }
        cpSelectedElement = idx;
        cpSelectedElements.clear();
        cpSelectedElements.add(idx);
    }
    
    cpDqSelectedItem = null;
    
    // Cacher la toolbar flottante (on change d'élément)
    if (typeof cpHideFloatToolbar === 'function') cpHideFloatToolbar();
    
    // Mettre à jour visuellement sans re-render complet
    var canvas = document.getElementById('cpCanvasInner');
    if (canvas) {
        canvas.querySelectorAll('.cp-element').forEach(function(elem) {
            var elemIdx = parseInt(elem.dataset.idx);
            if (cpSelectedElements.has(elemIdx)) {
                elem.classList.add('selected');
                // Rotation : seulement si sélection unique d'une image/shape
                if (cpSelectedElements.size === 1 && elemIdx === cpSelectedElement) {
                    var type = (elem.dataset.type || '').toLowerCase();
                    if (!type) {
                        if (elem.querySelector('.cp-image-element')) type = 'image';
                        else if (elem.querySelector('.cp-shape-element')) type = 'shape';
                    }
                    if ((type === 'image' || type === 'shape') && !elem.querySelector('.cp-element-rotate')) {
                        var rotBtn = document.createElement('div');
                        rotBtn.className = 'cp-element-rotate';
                        rotBtn.title = 'Rotation';
                        rotBtn.textContent = '↻';
                        rotBtn.onmousedown = function(ev) { cpStartRotate(ev, elemIdx); };
                        elem.appendChild(rotBtn);
                    }
                } else {
                    // Multi-sélection : pas de rotation
                    var existingRot = elem.querySelector('.cp-element-rotate');
                    if (existingRot) existingRot.remove();
                }
            } else {
                elem.classList.remove('selected');
                var existingRot = elem.querySelector('.cp-element-rotate');
                if (existingRot) existingRot.remove();
            }
        });
    }
    
    cpRenderElementProps();
}

// ==================== RECTANGLE SELECTION (Ctrl/Shift + drag on canvas) ====================

var _cpRectSel = null;
var _cpRectSelJustDone = false;

function cpRectSelectStart(event) {
    // Seulement si Ctrl/Shift est enfoncé
    if (!(event.ctrlKey || event.metaKey || event.shiftKey)) return;
    if (event.button !== 0) return;
    
    // Vérifier qu'on est dans le canvas (pas dans les panels, slides, etc.)
    var canvas = document.getElementById('cpCanvasInner');
    if (!canvas) return;
    if (!canvas.contains(event.target) && event.target.id !== 'cpCanvas') return;
    
    event.preventDefault();
    
    // getBoundingClientRect retourne les dimensions écran (zoom CSS déjà appliqué)
    var rect = canvas.getBoundingClientRect();
    
    // Créer le rectangle visuel
    var selRect = document.createElement('div');
    selRect.id = 'cpRectSelectBox';
    selRect.style.cssText = 'position:absolute; border:2px dashed #1976d2; background:rgba(25,118,210,0.08); z-index:500; pointer-events:none;';
    canvas.appendChild(selRect);
    
    // Calculer le facteur d'échelle entre les pixels écran et les pixels CSS du canvas
    // Le canvas fait 1400px CSS mais est affiché au scale total (responsive × zoom)
    var wrapperEl = canvas.closest('.cp-canvas-wrapper');
    var wrapperW = wrapperEl ? wrapperEl.clientWidth : CP_REF_WIDTH;
    var responsiveScale = Math.min(wrapperW / CP_REF_WIDTH, 1);
    var zoom = responsiveScale * ((cpZoomLevel || 100) / 100);
    
    _cpRectSel = {
        canvasRect: rect,
        zoom: zoom,
        startX: event.clientX,
        startY: event.clientY,
        box: selRect,
        canvas: canvas
    };
    
    document.addEventListener('mousemove', cpRectSelectMove);
    document.addEventListener('mouseup', cpRectSelectEnd);
}

function cpRectSelectMove(event) {
    if (!_cpRectSel) return;
    event.preventDefault(); // Empêcher la sélection de texte
    
    var r = _cpRectSel.canvasRect;
    var zoom = _cpRectSel.zoom;
    
    // Positions en pixels écran relatives au canvas
    var screenX1 = _cpRectSel.startX - r.left;
    var screenY1 = _cpRectSel.startY - r.top;
    var screenX2 = event.clientX - r.left;
    var screenY2 = event.clientY - r.top;
    
    // Convertir en pixels CSS du canvas (diviser par zoom)
    var cssX1 = screenX1 / zoom;
    var cssY1 = screenY1 / zoom;
    var cssX2 = screenX2 / zoom;
    var cssY2 = screenY2 / zoom;
    
    var left = Math.max(0, Math.min(cssX1, cssX2));
    var top = Math.max(0, Math.min(cssY1, cssY2));
    var width = Math.abs(cssX2 - cssX1);
    var height = Math.abs(cssY2 - cssY1);
    
    _cpRectSel.box.style.left = left + 'px';
    _cpRectSel.box.style.top = top + 'px';
    _cpRectSel.box.style.width = width + 'px';
    _cpRectSel.box.style.height = height + 'px';
    
    // Stocker les bornes en % pour la comparaison avec les éléments
    // La taille CSS réelle du canvas = taille écran / zoom
    var cssW = r.width / zoom;
    var cssH = r.height / zoom;
    _cpRectSel.pctLeft = (left / cssW) * 100;
    _cpRectSel.pctTop = (top / cssH) * 100;
    _cpRectSel.pctRight = ((left + width) / cssW) * 100;
    _cpRectSel.pctBottom = ((top + height) / cssH) * 100;
}

function cpRectSelectEnd(event) {
    document.removeEventListener('mousemove', cpRectSelectMove);
    document.removeEventListener('mouseup', cpRectSelectEnd);
    
    if (!_cpRectSel) return;
    
    // Retirer le rectangle visuel
    if (_cpRectSel.box && _cpRectSel.box.parentNode) {
        _cpRectSel.box.remove();
    }
    
    // Vérifier qu'on a bien dessiné un rectangle (pas juste un clic)
    var hasDragged = _cpRectSel.pctRight !== undefined && 
        (Math.abs(_cpRectSel.pctRight - _cpRectSel.pctLeft) > 1 || Math.abs(_cpRectSel.pctBottom - _cpRectSel.pctTop) > 1);
    
    if (!hasDragged) {
        _cpRectSel = null;
        return;
    }
    
    // Trouver les éléments qui intersectent le rectangle
    var activity = getSelectedActivity();
    if (!activity) { _cpRectSel = null; return; }
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) { _cpRectSel = null; return; }
    
    var sL = _cpRectSel.pctLeft, sT = _cpRectSel.pctTop;
    var sR = _cpRectSel.pctRight, sB = _cpRectSel.pctBottom;
    
    cpSelectedElements.clear();
    cpSelectedElement = null;
    
    slide.elements.forEach(function(el, idx) {
        var eL = el.x || 0;
        var eT = el.y || 0;
        var eR = eL + (el.width || 10);
        var eB = eT + (el.height || 10);
        
        // Intersection rectangle
        if (eL < sR && eR > sL && eT < sB && eB > sT) {
            cpSelectedElements.add(idx);
            if (cpSelectedElement === null) cpSelectedElement = idx;
        }
    });
    
    _cpRectSel = null;
    
    if (cpSelectedElements.size > 0) {
        _cpRectSelJustDone = true;
        setTimeout(function() { _cpRectSelJustDone = false; }, 50);
    }
    
    cpRenderSlideElements();
    cpRenderElementProps();
}

function cpDeselectElement(event) {
    // Ne pas déselectionner si on vient de finir une sélection rectangle
    if (_cpRectSelJustDone) { return; }
    
    // Ne pas déselectionner si on clique sur la toolbar flottante ou le popup emoji
    if (event.target.closest('.cp-float-toolbar') || event.target.closest('.cp-emoji-popup')) {
        return;
    }
    
    // Sortir du mode édition texte si actif
    var editingText = document.querySelector('.cp-editable-text[contenteditable="true"]');
    if (editingText) {
        editingText.removeAttribute('contenteditable');
        editingText.blur();
        cpHideFloatToolbar();
    }
    
    if (event.target.id === 'cpCanvas' || event.target.id === 'cpCanvasInner') {
        cpSelectedElement = null; cpSelectedElements.clear();
        cpSelectedElements.clear();
        cpDqSelectedItem = null;

        // Cacher la toolbar flottante
        cpHideFloatToolbar();

        // Mettre à jour visuellement
        const canvas = document.getElementById('cpCanvasInner');
        if (canvas) {
            canvas.querySelectorAll('.cp-element').forEach(elem => {
                elem.classList.remove('selected');
            });
        }

        cpRenderElementProps();
        // Donner le focus au canvas pour que les flèches naviguent entre slides
        // (sinon le focus reste sur un input du panel et capte les flèches).
        canvas?.focus({ preventScroll: true });
    }
}

// Supprime tous les éléments sélectionnés (multi-sélection)
function cpDeleteSelected() {
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) return;
    
    cpSyncSelection();
    if (cpSelectedElements.size === 0) return;
    
    // Trier les indices en ordre décroissant pour supprimer de la fin vers le début
    var indices = Array.from(cpSelectedElements).sort(function(a, b) { return b - a; });
    indices.forEach(function(idx) {
        if (idx < slide.elements.length) {
            slide.elements.splice(idx, 1);
        }
    });
    
    cpSelectedElement = null; cpSelectedElements.clear();
    cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast(indices.length > 1 ? indices.length + ' éléments supprimés' : 'Élément supprimé', 'success');
}

function cpDeleteElement(idx) {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    slide.elements.splice(idx, 1);
    cpSelectedElement = null; cpSelectedElements.clear();
    cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

function cpStartDrag(event, idx) {
    // Ne pas démarrer le drag sur les contrôles
    if (event.target.classList.contains('cp-element-resize') || 
        event.target.classList.contains('cp-element-delete') ||
        event.target.classList.contains('cp-element-rotate')) return;
    
    // Détecter si on clique sur le drag handle
    var isDragHandle = event.target.classList.contains('cp-element-drag-handle');
    
    // Si le texte est en mode édition (contenteditable actif), ne pas draguer
    // SAUF si on clique sur le drag handle
    if (!isDragHandle && (event.target.isContentEditable || event.target.closest('[contenteditable="true"]'))) return;
    
    // Sortir du mode édition texte si on drag un AUTRE élément
    var editingText = document.querySelector('.cp-editable-text[contenteditable="true"]');
    var dragHandleOfEditingElement = isDragHandle && editingText && editingText.closest('.cp-element') === event.target.closest('.cp-element');
    if (editingText && !dragHandleOfEditingElement) {
        editingText.removeAttribute('contenteditable');
        editingText.blur();
        cpHideFloatToolbar();
    }
    
    // Si Ctrl/Meta : ne pas démarrer le drag (multi-sélection via onclick)
    if (event.ctrlKey || event.metaKey || event.shiftKey) {
        event.preventDefault(); // Empêcher sélection texte
        return; // pas de drag → onclick se déclenchera normalement
    }
    
    // Si on est en mode édition texte sur cet élément, garder la toolbar
    var keepToolbar = false;
    if (cpActiveCanvasEditor) {
        var editElem = cpActiveCanvasEditor.closest('.cp-element');
        if (editElem && parseInt(editElem.dataset.idx) === idx) {
            keepToolbar = true;
        }
    }
    
    // Blur le contenteditable sauf si on veut garder la toolbar
    if (!keepToolbar && document.activeElement && document.activeElement.isContentEditable) {
        document.activeElement.blur();
        cpHideFloatToolbar();
    }
    
    // IMPORTANT: Sélectionner l'élément (pour que Delete/Backspace fonctionne après)
    cpSyncSelection();
    if (!cpSelectedElements.has(idx)) {
        // L'élément dragué n'est pas dans la sélection : sélection unique
        cpSelectedElement = idx;
        cpSelectedElements.clear();
        cpSelectedElements.add(idx);
        var canvas = document.getElementById('cpCanvasInner');
        if (canvas) {
            canvas.querySelectorAll('.cp-element').forEach(function(elem) {
                var elemIdx = parseInt(elem.dataset.idx);
                var isSel = elemIdx === idx;
                elem.classList.toggle('selected', isSel);
                if (isSel) {
                    if ((elem.querySelector('.cp-image-element') || elem.querySelector('.cp-shape-element')) && !elem.querySelector('.cp-element-rotate')) {
                        var rotBtn = document.createElement('div');
                        rotBtn.className = 'cp-element-rotate';
                        rotBtn.title = 'Rotation';
                        rotBtn.textContent = '↻';
                        rotBtn.onmousedown = function(ev) { cpStartRotate(ev, elemIdx); };
                        elem.appendChild(rotBtn);
                    }
                } else {
                    var existingRot = elem.querySelector('.cp-element-rotate');
                    if (existingRot) existingRot.remove();
                }
            });
        }
        cpRenderElementProps();
    }
    // Si l'élément est déjà dans la multi-sélection, on garde la sélection telle quelle
    
    event.preventDefault();
    
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    
    cpDragging = {
        idx: idx,
        startX: event.clientX,
        startY: event.clientY,
        element: slide.elements[idx],
        hasMoved: false,
        // Stocker les positions originales de tous les éléments sélectionnés pour le drag groupé
        multiDrag: []
    };
    cpDragging.origX = cpDragging.element.x != null ? cpDragging.element.x : 10;
    cpDragging.origY = cpDragging.element.y != null ? cpDragging.element.y : 10;
    
    // Si multi-sélection, préparer le drag groupé
    if (cpSelectedElements.size > 1 && cpSelectedElements.has(idx)) {
        cpSelectedElements.forEach(function(selIdx) {
            var el = slide.elements[selIdx];
            if (el) {
                cpDragging.multiDrag.push({ idx: selIdx, element: el, origX: el.x != null ? el.x : 10, origY: el.y != null ? el.y : 10 });
            }
        });
    }
    
    document.addEventListener('mousemove', cpOnDrag);
    document.addEventListener('mouseup', cpStopDrag);
}

function cpOnDrag(event) {
    if (!cpDragging) return;
    cpDragging.hasMoved = true;
    
    const canvas = document.getElementById('cpCanvas');
    const rect = canvas.getBoundingClientRect();
    
    var dx = ((event.clientX - cpDragging.startX) / rect.width) * 100;
    var dy = ((event.clientY - cpDragging.startY) / rect.height) * 100;
    
    // Shift = contraindre le mouvement (H, V ou 45°)
    if (event.shiftKey) {
        var absDx = Math.abs(dx);
        var absDy = Math.abs(dy);
        var angle = Math.atan2(absDy, absDx) * 180 / Math.PI;
        if (angle < 22.5) {
            // Horizontal
            dy = 0;
        } else if (angle > 67.5) {
            // Vertical
            dx = 0;
        } else {
            // 45° : garder la plus grande valeur, aligner l'autre
            var maxVal = Math.max(absDx, absDy);
            dx = maxVal * Math.sign(dx);
            dy = maxVal * Math.sign(dy);
        }
    }
    
    if (cpDragging.multiDrag.length > 1) {
        // Drag groupé : déplacer tous les éléments sélectionnés
        cpDragging.multiDrag.forEach(function(item) {
            item.element.x = Math.max(0, Math.min(100 - (item.element.width || 30), item.origX + dx));
            item.element.y = Math.max(0, Math.min(100, item.origY + dy));
            var elDiv = document.querySelector('.cp-element[data-idx="' + item.idx + '"]');
            if (elDiv) {
                elDiv.style.left = item.element.x + '%';
                elDiv.style.top = item.element.y + '%';
            }
        });
    } else {
        // Drag simple
        cpDragging.element.x = Math.max(0, Math.min(100 - (cpDragging.element.width || 30), cpDragging.origX + dx));
        cpDragging.element.y = Math.max(0, Math.min(100, cpDragging.origY + dy));
        var elDiv = document.querySelector('.cp-element[data-idx="' + cpDragging.idx + '"]');
        if (elDiv) {
            elDiv.style.left = cpDragging.element.x + '%';
            elDiv.style.top = cpDragging.element.y + '%';
        }
    }
    
    // Repositionner la toolbar si elle est visible (mode édition texte)
    if (cpActiveCanvasEditor) {
        var toolbar = document.getElementById('cpFloatToolbar');
        if (toolbar && toolbar.classList.contains('visible')) {
            cpShowFloatToolbar(cpActiveCanvasEditor);
        }
    }
}

function cpStopDrag() {
    if (cpDragging) {
        if (cpDragging.hasMoved) {
            onCourseModified();
            cpUpdateSlideThumb(cpCurrentSlide);
        } else if (cpSelectedElements.size > 1) {
            // Clic sans déplacement sur un élément d'une multi-sélection → sélection unique
            var idx = cpDragging.idx;
            cpSelectedElement = idx;
            cpSelectedElements.clear();
            cpSelectedElements.add(idx);
            cpRenderSlideElements();
            cpRenderElementProps();
        }
    }
    cpDragging = null;
    document.removeEventListener('mousemove', cpOnDrag);
    document.removeEventListener('mouseup', cpStopDrag);
}

function cpStartResize(event, idx) {
    event.preventDefault();
    event.stopPropagation();
    
    const element = getSelectedActivity().content.presentation.slides[cpCurrentSlide].elements[idx];
    const lib = (element.action?.library || '').toLowerCase();
    const isImage = lib.indexOf('h5p.image') !== -1;
    
    cpResizing = {
        idx: idx,
        startX: event.clientX,
        startY: event.clientY,
        element: element,
        isImage: isImage
    };
    cpResizing.origW = cpResizing.element.width || 30;
    cpResizing.origH = cpResizing.element.height || 20;
    
    document.addEventListener('mousemove', cpOnResize);
    document.addEventListener('mouseup', cpStopResize);
}

function cpOnResize(event) {
    if (!cpResizing) return;
    
    const canvas = document.getElementById('cpCanvas');
    const rect = canvas.getBoundingClientRect();
    
    const dx = ((event.clientX - cpResizing.startX) / rect.width) * 100;
    const dy = ((event.clientY - cpResizing.startY) / rect.height) * 100;
    
    if (cpResizing.isImage) {
        // Image : conserver le ratio d'aspect
        // Le canvas est 2:1 donc 1% width = 2× la taille physique de 1% height
        // Ratio d'aspect en unités % : aspectRatio = origW / (origH * 2)
        // On prend le déplacement diagonal comme référence
        const diag = (dx + dy) / 2;
        const aspectRatio = cpResizing.origW / cpResizing.origH;
        // Calculer la nouvelle taille basée sur le mouvement diagonal
        let newW = cpResizing.origW + diag;
        let newH = newW / aspectRatio;
        cpResizing.element.width = Math.max(3, Math.min(100, newW));
        cpResizing.element.height = Math.max(2, Math.min(100, newH));
    } else {
        // Autres éléments : redimensionnement libre
        cpResizing.element.width = Math.max(3, Math.min(100, cpResizing.origW + dx));
        cpResizing.element.height = Math.max(2, Math.min(100, cpResizing.origH + dy));
    }
    
    const elDiv = document.querySelector(`.cp-element[data-idx="${cpResizing.idx}"]`);
    if (elDiv) {
        elDiv.style.width = cpResizing.element.width + '%';
        elDiv.style.height = cpResizing.element.height + '%';
    }
    
    // Repositionner la toolbar si visible
    var toolbar = document.getElementById('cpFloatToolbar');
    if (toolbar && toolbar.classList.contains('visible') && cpActiveCanvasEditor) {
        cpShowFloatToolbar(cpActiveCanvasEditor);
    }
}

function cpStopResize() {
    if (cpResizing) {
        onCourseModified();
        cpUpdateSlideThumb(cpCurrentSlide);
    }
    cpResizing = null;
    document.removeEventListener('mousemove', cpOnResize);
    document.removeEventListener('mouseup', cpStopResize);
}

// ==================== ROTATION ====================
var cpRotating = null;

function cpStartRotate(event, idx) {
    event.preventDefault();
    event.stopPropagation();
    
    const elDiv = document.querySelector(`.cp-element[data-idx="${idx}"]`);
    if (!elDiv) return;
    
    const rect = elDiv.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    
    cpRotating = {
        idx: idx,
        centerX: centerX,
        centerY: centerY,
        element: getSelectedActivity().content.presentation.slides[cpCurrentSlide].elements[idx]
    };
    
    document.addEventListener('mousemove', cpOnRotate);
    document.addEventListener('mouseup', cpStopRotate);
}

function cpOnRotate(event) {
    if (!cpRotating) return;
    
    const dx = event.clientX - cpRotating.centerX;
    const dy = event.clientY - cpRotating.centerY;
    var angle = Math.atan2(dy, dx) * (180 / Math.PI) + 90;
    if (angle < 0) angle += 360;
    angle = Math.round(angle);
    
    cpRotating.element.rotation = angle;
    
    const elDiv = document.querySelector(`.cp-element[data-idx="${cpRotating.idx}"]`);
    if (elDiv) {
        elDiv.style.transform = `rotate(${angle}deg)`;
    }
}

function cpStopRotate() {
    if (cpRotating) {
        cpRenderElementProps(); // update rotation slider in panel
        onCourseModified();
    }
    cpRotating = null;
    document.removeEventListener('mousemove', cpOnRotate);
    document.removeEventListener('mouseup', cpStopRotate);
}

function cpRenderElementProps() {
    // Sauvegarder les modifications en attente avant de rerender
    cpDqFlushPendingChanges();

    // Fermer le popup emoji si ouvert
    if (typeof cpClosePropsEmojiPicker === 'function') cpClosePropsEmojiPicker();

    const container = document.getElementById('cpPropsBody');
    const panel = document.getElementById('cpPropsPanel');

    // Libérer le lecteur natif <audio> du panneau avant de le remplacer : sinon son
    // WebMediaPlayer (créé s'il a été lu) subsiste à chaque changement de sélection.
    if (container) container.querySelectorAll('audio').forEach(function(a) {
        try { a.pause(); a.removeAttribute('src'); a.load(); } catch (e) {}
    });
    if (!container || !panel) return;

    cpSyncSelection();
    
    if (cpSelectedElements.size === 0) {
        panel.classList.remove('visible');
        return;
    }
    
    panel.classList.add('visible');
    
    // Multi-sélection : panneau spécial
    if (cpSelectedElements.size > 1) {
        var count = cpSelectedElements.size;
        container.innerHTML = `
            <div style="padding: 1rem; text-align: center;">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📦</div>
                <p style="font-size: 1rem; color: var(--gray-600); margin-bottom: 1rem; font-weight: 600;">${count} éléments sélectionnés</p>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; max-width: 200px; margin: 0 auto;">
                    <button class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="cpCopySelected()">📋 Copier</button>
                    <button class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="cpDuplicateSelected()">📄 Dupliquer</button>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-secondary" style="flex:1; padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="cpBringToFront()">⬆️ Devant</button>
                        <button class="btn btn-secondary" style="flex:1; padding: 0.4rem 0.8rem; font-size: 0.85rem;" onclick="cpSendToBack()">⬇️ Derrière</button>
                    </div>
                    <button class="btn" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background: var(--danger); color: white; border: none; border-radius: 6px; cursor: pointer;" onclick="cpDeleteSelected()">🗑️ Supprimer (${count})</button>
                </div>
                <p style="font-size: 0.75rem; color: var(--gray-400); margin-top: 1rem;">Ctrl+clic pour modifier la sélection</p>
            </div>`;
        return;
    }
    
    if (cpSelectedElement === null) {
        panel.classList.remove('visible');
        return;
    }
    
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const type = (element.action?.library || '').split(' ')[0].replace('H5P.', '').toLowerCase();
    
    let propsHtml = '';
    
    // Propriétés spécifiques selon le type
    switch (type) {
        case 'text':
        case 'advancedtext':
            const text = element.action?.params?.text || '';
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Contenu</label>
                    <div class="rich-text-toolbar">
                        <button class="rich-text-btn" onclick="cpFormatText('bold')" title="Gras"><b>G</b></button>
                        <button class="rich-text-btn" onclick="cpFormatText('italic')" title="Italique"><i>I</i></button>
                        <button class="rich-text-btn" onclick="cpFormatText('underline')" title="Souligné"><u>S</u></button>
                        <button class="rich-text-btn cp-color-btn" data-kind="fore" onmousedown="cpColorBtn(event,'fore','props')" title="Couleur du texte"><span class="cp-ci">A<i class="cp-ci-bar" style="background:${cpLastTextColor}"></i></span></button>
                        <button class="rich-text-btn cp-color-btn" data-kind="hilite" onmousedown="cpColorBtn(event,'hilite','props')" title="Surlignage"><span class="cp-ci cp-ci-hl" style="background:${cpLastHiliteColor}">A</span></button>
                        <span class="rich-text-separator"></span>
                        <select class="rich-text-select" onchange="cpFormatFontSize(this.value)" title="Taille">
                            <option value="">Taille</option>
                            <option value="1em">100</option>
                            <option value="1.25em">125</option>
                            <option value="1.5em">150</option>
                            <option value="1.75em">175</option>
                            <option value="2.25em">225</option>
                            <option value="3em">300</option>
                        </select>
                        <span class="rich-text-separator"></span>
                        <button class="rich-text-btn" onclick="cpFormatText('justifyLeft')" title="Aligner à gauche">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/>
                            </svg>
                        </button>
                        <button class="rich-text-btn" onclick="cpFormatText('justifyCenter')" title="Centrer">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>
                            </svg>
                        </button>
                        <button class="rich-text-btn" onclick="cpFormatText('justifyRight')" title="Aligner à droite">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/>
                            </svg>
                        </button>
                        <span class="rich-text-separator"></span>
                        <button class="rich-text-btn" onclick="cpInsertLink()" title="Lien">🔗</button>
                        ${cpEmojiBarHtml('cpTextEditor')}
                    </div>
                    <div class="rich-text-editor" contenteditable="true" id="cpTextEditor"
                         oninput="cpUpdateTextContentLive()" onblur="cpUpdateTextContent()">${text}</div>
                </div>`;
            break;

        case 'table': {
            const tableText = element.action?.params?.text || '';
            const tableHasBorders = /border-style\s*:\s*solid/.test(tableText)
                || /border\s*:\s*[\d.]+px/.test(tableText);
            const normalizedTableText = cpNormalizeTableHtml(tableText);
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Tableau</label>
                    <p style="font-size:0.75rem;color:var(--gray-500);margin:0 0 6px;">Double-cliquez une cellule pour l'éditer. Glissez les séparateurs pour redimensionner les colonnes.</p>
                    <div class="cp-table-props-wrapper" id="cpTablePropsWrapper">${normalizedTableText}</div>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Bordures</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" id="cpTableBordersToggle" ${tableHasBorders ? 'checked' : ''}
                               onchange="cpToggleTableBorders(this.checked)">
                        <span style="font-size:0.85rem;">Afficher les bordures</span>
                    </label>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Lignes / Colonnes</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button class="btn btn-secondary" style="padding:3px 10px;font-size:0.78rem;" onclick="cpTableAddRow()">+ Ligne</button>
                        <button class="btn btn-secondary" style="padding:3px 10px;font-size:0.78rem;" onclick="cpTableAddCol()">+ Colonne</button>
                        <button class="btn btn-secondary" style="padding:3px 10px;font-size:0.78rem;color:var(--danger-text,#c00);border-color:var(--danger-border,#fcc);" onclick="cpTableDelRow()">− Ligne</button>
                        <button class="btn btn-secondary" style="padding:3px 10px;font-size:0.78rem;color:var(--danger-text,#c00);border-color:var(--danger-border,#fcc);" onclick="cpTableDelCol()">− Colonne</button>
                    </div>
                </div>`;
            break;
        }

        case 'image':
            const imgPath = element.action?.params?.file?.path || '';
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Image</label>
                    <input type="file" class="cp-prop-input" accept="image/*" onchange="cpUploadImage(this)">
                    ${imgPath ? `<p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">Fichier actuel chargé</p>` : ''}
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">URL de l'image</label>
                    <input type="text" class="cp-prop-input" id="cpImageUrlInput" placeholder="https://..."
                           value="${imgPath && imgPath.match(/^https?:\/\//) ? imgPath.replace(/"/g, '&quot;') : ''}"
                           onchange="cpUpdateImageUrl(this.value)"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();cpUpdateImageUrl(this.value);}">
                    <button style="margin-top:4px;font-size:0.78rem;padding:3px 8px;background:var(--gray-100,#f0f0f0);border:1px solid var(--gray-300,#ddd);border-radius:4px;cursor:pointer;color:var(--text-secondary,#555);" onclick="cpPasteImageUrlFromClipboard()" title="Lire le presse-papier et coller l'URL">📋 Coller une URL</button>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Texte alternatif</label>
                    <input type="text" class="cp-prop-input" value="${element.action?.params?.alt || ''}"
                           onchange="cpUpdateNestedProp('action.params.alt', this.value)">
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Rotation : <span id="cpImageRotationVal">${element.rotation || 0}</span>°</label>
                    <input type="range" class="cp-prop-input" min="0" max="359" step="1" value="${element.rotation || 0}"
                           oninput="cpSetImageRotation(parseInt(this.value))">
                    <div style="display:flex; gap:4px; margin-top:4px; flex-wrap:wrap;">
                        <button class="btn btn-secondary" style="padding:2px 8px; font-size:0.7rem;" onclick="cpSetImageRotation(0)">0°</button>
                        <button class="btn btn-secondary" style="padding:2px 8px; font-size:0.7rem;" onclick="cpSetImageRotation(90)">90°</button>
                        <button class="btn btn-secondary" style="padding:2px 8px; font-size:0.7rem;" onclick="cpSetImageRotation(180)">180°</button>
                        <button class="btn btn-secondary" style="padding:2px 8px; font-size:0.7rem;" onclick="cpSetImageRotation(270)">270°</button>
                    </div>
                </div>`;
            break;

        case 'audio': {
            const audioPath = element.action?.params?.files?.[0]?.path || '';
            const audioAutoplay = element.action?.params?.autoplay === true;
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Fichier audio (MP3)</label>
                    <input type="file" class="cp-prop-input" accept="audio/*,.mp3,.ogg,.wav,.m4a" onchange="cpUploadAudio(this)">
                    ${audioPath ? `<p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">✅ Audio chargé</p>
                    <audio controls preload="none" style="width:100%;margin-top:0.4rem;" src="${escapeHtml(audioPath)}"></audio>
                    <button class="btn btn-secondary" style="margin-top:0.4rem;padding:3px 10px;font-size:0.78rem;color:var(--danger-text,#c00);border-color:var(--danger-border,#fcc);" onclick="cpRemoveAudio()">🗑️ Retirer l'audio</button>` : `<p style="font-size: 0.72rem; color: var(--gray-400); margin-top: 0.25rem;">Le MP3 est intégré au cours et se lit au clic sur le bouton.</p>`}
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label" style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" ${audioAutoplay ? 'checked' : ''} onchange="cpUpdateNestedProp('action.params.autoplay', this.checked)" style="width:auto;">
                        Lecture automatique
                    </label>
                </div>`;
            break;
        }

        case 'multichoice':
            const mcQuestion = element.action?.params?.question || '';
            const mcAnswers = element.action?.params?.answers || [];
            let answersHtml = '';
            mcAnswers.forEach((ans, aIdx) => {
                // Nettoyer les balises HTML des réponses pour l'affichage
                const cleanAnswerText = (ans.text || '').replace(/<[^>]*>/g, '').trim();
                const fb = ans.tipsAndFeedback?.chosenFeedback || '';
                const hasFb = fb.replace(/<[^>]*>/g, '').trim().length > 0;
                const fbClean = fb.replace(/<[^>]*>/g, '').trim();
                answersHtml += `
                    <div class="quiz-answer-item">
                        <input type="checkbox" class="quiz-answer-correct" ${ans.correct ? 'checked' : ''}
                               onchange="cpUpdateMcAnswer(${aIdx}, 'correct', this.checked)">
                        <input type="text" class="quiz-answer-text" id="cpMcAnswer_${aIdx}" value="${escapeHtml(cleanAnswerText)}"
                               onchange="cpUpdateMcAnswer(${aIdx}, 'text', this.value)"
                               onfocus="window._lastEmojiTarget='cpMcAnswer_${aIdx}'">
                        <button class="quiz-feedback-toggle ${hasFb ? 'active' : ''}" onclick="cpToggleMcFeedback(${aIdx})" title="Feedback">💬</button>
                        <button class="quiz-answer-delete" onclick="cpDeleteMcAnswer(${aIdx})">🗑️</button>
                    </div>
                    <div class="quiz-feedback-row" id="cpMcFeedback_${aIdx}" style="display:${hasFb ? 'flex' : 'none'};">
                        <input type="text" class="quiz-feedback-input" value="${escapeHtml(fbClean)}"
                               placeholder="Feedback si cochée..."
                               onchange="cpUpdateMcFeedback(${aIdx}, this.value)">
                    </div>`;
            });
            
            // Options behaviour
            const mcBehaviour = element.action?.params?.behaviour || {};
            const mcEnableRetry = mcBehaviour.enableRetry !== false;
            const mcEnableSolutions = mcBehaviour.enableSolutionsButton === true;
            const mcRandomAnswers = mcBehaviour.randomAnswers !== false;
            
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Question</label>
                    <div class="rich-text-toolbar">
                        <button class="rich-text-btn" onclick="cpFormatQuizText('bold')" title="Gras"><b>G</b></button>
                        <button class="rich-text-btn" onclick="cpFormatQuizText('italic')" title="Italique"><i>I</i></button>
                        <button class="rich-text-btn" onclick="cpFormatQuizText('underline')" title="Souligné"><u>S</u></button>
                        <span class="rich-text-separator"></span>
                        <select class="rich-text-select" onchange="cpFormatQuizFontSize(this.value)" title="Taille">
                            <option value="">Taille</option>
                            <option value="1em">100</option>
                            <option value="1.25em">125</option>
                            <option value="1.5em">150</option>
                            <option value="1.75em">175</option>
                            <option value="2.25em">225</option>
                            <option value="3em">300</option>
                        </select>
                        ${cpEmojiBarHtml('cpQuizQuestionEditor')}
                    </div>
                    <div class="rich-text-editor" contenteditable="true" id="cpQuizQuestionEditor"
                         oninput="cpUpdateQuizQuestionLive()" onblur="cpUpdateQuizQuestion()">${mcQuestion}</div>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Réponses (cocher = correct)</label>
                    <div class="quiz-answers-list">${answersHtml}</div>
                    <button class="quiz-add-answer" onclick="cpAddMcAnswer()">+ Ajouter une réponse</button>
                    ${cpEmojiBarHtml('_dynamic_')}
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Options</label>
                    <div class="cp-quiz-options">
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${mcEnableRetry ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('enableRetry', this.checked)">
                            Bouton recommencer
                        </label>
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${mcEnableSolutions ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('enableSolutionsButton', this.checked)">
                            Bouton afficher la solution
                        </label>
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${mcRandomAnswers ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('randomAnswers', this.checked)">
                            Mélanger les réponses
                        </label>
                    </div>
                </div>`;
            break;
            
        case 'truefalse':
        case 'singlechoiceset':
            // Gestion SingleChoiceSet (format Éléa) avec plusieurs questions possibles
            const tfChoices = element.action?.params?.choices || [];
            const tfBehaviour = element.action?.params?.behaviour || {};
            const tfEnableRetry = tfBehaviour.enableRetry !== false;
            const tfEnableSolutions = tfBehaviour.enableSolutionsButton === true;
            
            let tfQuestionsHtml = '';
            if (tfChoices.length > 0) {
                tfChoices.forEach((choice, qIdx) => {
                    const qText = choice.question || '';
                    const answers = choice.answers || ['<p>Vrai</p>', '<p>Faux</p>'];
                    tfQuestionsHtml += `
                        <div class="tf-question-block" data-qidx="${qIdx}">
                            <div class="tf-question-header">
                                <span class="tf-question-num">Q${qIdx + 1}</span>
                                ${tfChoices.length > 1 ? `<button class="tf-question-delete" onclick="cpDeleteTfQuestion(${qIdx})" title="Supprimer">🗑️</button>` : ''}
                            </div>
                            <div class="cp-prop-subgroup">
                                <label class="cp-prop-sublabel">Question</label>
                                <textarea class="cp-prop-input cp-prop-textarea" id="cpTfQuestion_${qIdx}" rows="2"
                                          onfocus="window._lastEmojiTarget='cpTfQuestion_'+${qIdx}"
                                          onchange="cpUpdateTfQuestion(${qIdx}, 'question', this.value)">${escapeHtml((qText || '').replace(/<[^>]*>/g, ''))}</textarea>
                            </div>
                            <div class="cp-prop-subgroup">
                                <label class="cp-prop-sublabel">Réponses (la première = correcte)</label>
                                <div class="tf-answers-list">
                                    ${answers.map((a, aIdx) => `
                                        <div class="tf-answer-item ${aIdx === 0 ? 'correct' : ''}">
                                            <span class="tf-answer-marker">${aIdx === 0 ? '✓' : ''}</span>
                                            <input type="text" class="tf-answer-text" id="cpTfAnswer_${qIdx}_${aIdx}" value="${escapeHtml((a || '').replace(/<[^>]*>/g, ''))}"
                                                   onfocus="window._lastEmojiTarget='cpTfAnswer_${qIdx}_'+${aIdx}"
                                                   onchange="cpUpdateTfAnswer(${qIdx}, ${aIdx}, this.value)">
                                        </div>
                                    `).join('')}
                                </div>
                                ${cpEmojiBarHtml('_dynamic_')}
                            </div>
                        </div>`;
                });
            } else {
                // Fallback ancien format TrueFalse
                const oldTfQ = element.action?.params?.question || '';
                const oldTfCorrect = element.action?.params?.correct === 'true' || element.action?.params?.correct === true;
                
                // Auto-initialiser behaviour et l10n si manquants (pour compatibilité Éléa)
                if (!element.action.params.behaviour) {
                    element.action.params.behaviour = {
                        enableRetry: true, enableSolutionsButton: true, enableCheckButton: true,
                        confirmCheckDialog: false, confirmRetryDialog: false, autoCheck: false,
                        feedbackOnCorrect: '', feedbackOnWrong: ''
                    };
                }
                if (!element.action.params.l10n) {
                    element.action.params.l10n = {
                        trueText: 'Vrai', falseText: 'Faux',
                        score: 'Vous avez obtenu @score points sur un total de @total',
                        checkAnswer: 'V\u00e9rifier', submitAnswer: 'V\u00e9rifier',
                        showSolutionButton: 'Voir la solution', tryAgain: 'Recommencer',
                        wrongAnswerMessage: 'R\u00e9ponse incorrecte', correctAnswerMessage: 'Bonne r\u00e9ponse',
                        scoreBarLabel: 'Vous avez obtenu @score points sur un total de @total',
                        a11yCheck: 'V\u00e9rifiez les r\u00e9ponses.',
                        a11yShowSolution: 'Montrer la solution.',
                        a11yRetry: 'R\u00e9essayer l\'exercice.'
                    };
                }
                if (!element.action.params.media) {
                    element.action.params.media = { type: { params: {} }, disableImageZooming: false };
                }
                if (!element.action.params.confirmCheck) {
                    element.action.params.confirmCheck = { header: 'Terminer ?', body: 'Voulez-vous vraiment terminer ?', cancelLabel: 'Annuler', confirmLabel: 'Confirmer' };
                }
                if (!element.action.params.confirmRetry) {
                    element.action.params.confirmRetry = { header: 'Recommencer ?', body: 'Voulez-vous vraiment recommencer ?', cancelLabel: 'Annuler', confirmLabel: 'Confirmer' };
                }
                
                const oldTfBeh = element.action.params.behaviour;
                const oldTfFbWrong = oldTfBeh.feedbackOnWrong || '';
                const oldTfFbCorrect = oldTfBeh.feedbackOnCorrect || '';
                tfQuestionsHtml = `
                    <div class="tf-question-block" data-qidx="0">
                        <div class="cp-prop-subgroup">
                            <label class="cp-prop-sublabel">Question</label>
                            <textarea class="cp-prop-input cp-prop-textarea" id="cpOldTfQuestion" rows="2"
                                      onchange="cpUpdateNestedProp('action.params.question', this.value)">${escapeHtml((oldTfQ || '').replace(/<[^>]*>/g, ''))}</textarea>
                            ${cpEmojiBarHtml('cpOldTfQuestion')}
                        </div>
                        <div class="cp-prop-subgroup">
                            <label class="cp-prop-sublabel">Réponse correcte</label>
                            <select class="cp-prop-input" onchange="cpUpdateNestedProp('action.params.correct', this.value)">
                                <option value="true" ${oldTfCorrect ? 'selected' : ''}>Vrai</option>
                                <option value="false" ${!oldTfCorrect ? 'selected' : ''}>Faux</option>
                            </select>
                        </div>
                        <div class="cp-prop-subgroup">
                            <label class="cp-prop-sublabel">💬 Feedbacks</label>
                            <div class="quiz-feedback-row" style="display:flex; margin-bottom:0.3rem;">
                                <span style="min-width:20px; color:#22c55e; font-weight:bold; line-height:2;">✓</span>
                                <input type="text" class="quiz-feedback-input" value="${escapeHtml(oldTfFbCorrect)}"
                                       placeholder="Feedback si bonne réponse..."
                                       onchange="cpUpdateTfFeedback('feedbackOnCorrect', this.value)">
                            </div>
                            <div class="quiz-feedback-row" style="display:flex;">
                                <span style="min-width:20px; color:#ef4444; font-weight:bold; line-height:2;">✗</span>
                                <input type="text" class="quiz-feedback-input" value="${escapeHtml(oldTfFbWrong)}"
                                       placeholder="Feedback si mauvaise réponse..."
                                       onchange="cpUpdateTfFeedback('feedbackOnWrong', this.value)">
                            </div>
                        </div>
                    </div>`;
            }
            
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Questions Vrai/Faux</label>
                    <div class="tf-questions-container">${tfQuestionsHtml}</div>
                    <button class="quiz-add-answer" onclick="cpAddTfQuestion()">+ Ajouter une question</button>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Options</label>
                    <div class="cp-quiz-options">
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${tfEnableRetry ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('enableRetry', this.checked)">
                            Bouton recommencer
                        </label>
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${tfEnableSolutions ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('enableSolutionsButton', this.checked)">
                            Bouton afficher la solution
                        </label>
                    </div>
                </div>`;
            break;
            
        case 'blanks':
            const blanksTitle = element.action?.params?.text || 'Texte à trous';
            const blanksTitleClean = blanksTitle.replace(/<[^>]*>/g, '');
            const blanksQuestions = element.action?.params?.questions || [];
            const blanksTextDisplay = blanksQuestions.map(q => (q || '').replace(/<[^>]*>/g, '')).join('\n');
            const blBehaviour = element.action?.params?.behaviour || {};
            const blEnableRetry = blBehaviour.enableRetry !== false;
            const blEnableSolutions = blBehaviour.enableSolutionsButton === true;
            const blCaseSensitive = blBehaviour.caseSensitive === true;
            const blShowSolutionsRequiresInput = blBehaviour.showSolutionsRequiresInput !== false;
            
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Titre <span style="color: #ef4444;">*</span></label>
                    <div class="rich-text-toolbar" style="margin-bottom: 0.25rem;">
                        <button class="rich-text-btn" onclick="cpBlanksTitleExecCmd('bold')" title="Gras (Ctrl+B)"><b>G</b></button>
                        <button class="rich-text-btn" onclick="cpBlanksTitleExecCmd('italic')" title="Italique (Ctrl+I)"><i>I</i></button>
                        <button class="rich-text-btn" onclick="cpBlanksTitleExecCmd('underline')" title="Souligné (Ctrl+U)"><u>S</u></button>
                        <span class="rich-text-separator"></span>
                        <select class="rich-text-select" onchange="cpBlanksTitleFontSize(this.value)" title="Taille">
                            <option value="">Taille</option>
                            <option value="1em">100</option>
                            <option value="1.25em">125</option>
                            <option value="1.5em">150</option>
                            <option value="1.75em">175</option>
                            <option value="2.25em">225</option>
                            <option value="3em">300</option>
                        </select>
                        <span class="rich-text-separator"></span>
                        <button class="rich-text-btn" onclick="cpBlanksTitleExecCmd('justifyLeft')" title="Aligner à gauche">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/>
                            </svg>
                        </button>
                        <button class="rich-text-btn" onclick="cpBlanksTitleExecCmd('justifyCenter')" title="Centrer">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>
                            </svg>
                        </button>
                        <button class="rich-text-btn" onclick="cpBlanksTitleExecCmd('justifyRight')" title="Aligner à droite">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/>
                            </svg>
                        </button>
                        ${cpEmojiBarHtml('cpBlanksTitleEditor')}
                    </div>
                    <div class="rich-text-editor" contenteditable="true" id="cpBlanksTitleEditor"
                         style="min-height: 36px; font-size: 0.85rem;"
                         oninput="cpUpdateBlanksTitleLive()" onblur="cpUpdateBlanksTitleFromEditor()">${blanksTitle}</div>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Questions à trous</label>
                    <p style="font-size: 0.7rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                        Entourez les mots à trouver avec des astérisques: *mot*.<br>
                        Utilisez "/" pour ajouter des bonnes réponses *mot/mots*.
                    </p>
                    <div class="cp-blanks-richtext-wrap">
                        <div class="cp-blanks-richtext-toolbar">
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('bold')" title="Gras"><b>G</b></button>
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('italic')" title="Italique"><i>I</i></button>
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('underline')" title="Souligné"><u>S</u></button>
                            <span class="qs-rt-sep"></span>
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('justifyLeft')" title="Aligner à gauche">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/>
                                </svg>
                            </button>
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('justifyCenter')" title="Centrer">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>
                                </svg>
                            </button>
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('justifyRight')" title="Aligner à droite">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/>
                                </svg>
                            </button>
                            <span class="qs-rt-sep"></span>
                            <button type="button" class="qs-rt-btn" onclick="cpBlanksExecCmd('removeFormat')" title="Effacer formatage">⊘</button>
                            ${cpEmojiBarHtml('cpBlanksEditor')}
                        </div>
                        <div class="cp-blanks-richtext-editor" contenteditable="true" id="cpBlanksEditor"
                             oninput="cpOnBlanksRichTextInput()"
                             onblur="cpOnBlanksRichTextInput()">${blanksQuestions.map(q => q || '').join('<hr class="cp-blanks-sep">')}</div>
                    </div>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Options</label>
                    <div class="cp-quiz-options">
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${blEnableRetry ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('enableRetry', this.checked)">
                            Bouton recommencer
                        </label>
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${blEnableSolutions ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('enableSolutionsButton', this.checked)">
                            Bouton afficher la solution
                        </label>
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${blCaseSensitive ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('caseSensitive', this.checked)">
                            Sensible à la casse
                        </label>
                        <label class="cp-checkbox-label">
                            <input type="checkbox" ${blShowSolutionsRequiresInput ? 'checked' : ''} 
                                   onchange="cpUpdateQuizBehaviour('showSolutionsRequiresInput', this.checked)">
                            Obliger à remplir tous les blancs avant correction
                        </label>
                    </div>
                </div>`;
            break;
        
        case 'interactivevideo':
            const videoUrl = element.action?.params?.interactiveVideo?.video?.files?.[0]?.path || '';
            const interactions = element.action?.params?.interactiveVideo?.assets?.interactions || [];
            
            // Liste des interactions
            let interactionsHtml = '';
            interactions.forEach((inter, iIdx) => {
                const interType = inter.action?.library?.split(' ')[0]?.replace('H5P.', '') || 'Text';
                const interTime = inter.duration?.from || 0;
                const typeLabelsMap = { 'Text': 'Texte', 'MultiChoice': 'QCM', 'TrueFalse': 'Vrai/Faux', 'Blanks': 'Texte \u00e0 trous' };
                const interLabel = typeLabelsMap[interType] || inter.label?.replace(/<[^>]*>/g, '') || 'Interaction ' + (iIdx + 1);
                
                interactionsHtml += `
                    <div class="cp-interaction-item" style="background: var(--gray-50); padding: 0.4rem; border-radius: 4px; margin-bottom: 0.4rem; border-left: 3px solid var(--primary);">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; font-size: 0.7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(interLabel)}</div>
                                <div style="font-size: 0.65rem; color: var(--gray-500);">⏱ ${cpFormatTime(interTime)} • ${interType}</div>
                            </div>
                            <div style="display: flex; gap: 0.15rem; margin-left: 0.25rem;">
                                <button class="tree-action-btn" onclick="cpGoToInteraction(${iIdx})" title="Aller à" style="font-size: 0.65rem;">📍</button>
                                <button class="tree-action-btn" onclick="cpEditInteraction(${iIdx})" title="Éditer" style="font-size: 0.65rem;">✏️</button>
                                <button class="tree-action-btn" onclick="cpDeleteInteraction(${iIdx})" title="Supprimer" style="font-size: 0.65rem;">🗑️</button>
                            </div>
                        </div>
                    </div>`;
            });
            
            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">URL de la vidéo</label>
                    <input type="text" class="cp-prop-input" value="${escapeHtml(videoUrl)}" placeholder="https://..."
                           onchange="cpUpdateVideoUrl(this.value)" style="font-size: 0.8rem;">
                    <p style="font-size: 0.65rem; color: var(--gray-400); margin: 4px 0 0;">YouTube, Vimeo ou URL directe</p>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Interactions (${interactions.length})</label>
                    <div style="max-height: 180px; overflow-y: auto; margin-bottom: 0.5rem;">
                        ${interactionsHtml || '<p style="font-size: 0.7rem; color: var(--gray-400); text-align: center; padding: 0.5rem;">Aucune interaction</p>'}
                    </div>
                    <div style="display: flex; gap: 0.25rem;">
                        <select id="cpNewInteractionType" class="cp-prop-input" style="flex: 1; font-size: 0.7rem; padding: 0.3rem;">
                            <option value="text">💬 Texte (poster)</option>
                            <option value="multichoice">✅ QCM</option>
                            <option value="truefalse">⚖️ Vrai/Faux</option>
                            <option value="blanks">📝 Texte à trous</option>
                        </select>
                        <button class="btn btn-primary" onclick="cpAddInteraction()" style="padding: 0.3rem 0.5rem; font-size: 0.7rem;">
                            + Ajouter
                        </button>
                    </div>
                </div>
                <div id="cpInteractionEditorContainer"></div>
                ${videoUrl ? cpRenderIvOverrideOptions(element) : ''}`;
            break;
        
        case 'dialogcards':
            const dcCards = element.action?.params?.dialogs || [];
            if (dcCards.length === 0) dcCards.push({ text: '', answer: '', tips: {}, image: null });
            const dcCurrent = cpDcGetPreview(cpCurrentSlide, cpSelectedElement, dcCards.length).card;
            const dcRandom = element.action?.params?.behaviour?.randomCards === true;

            let dcCardsHtml = '';
            dcCards.forEach((card, cIdx) => {
                const cImageUrl = card.image?.path || card.image || '';
                const cTitle = (card.text || '').replace(/<[^>]*>/g, '').trim().substring(0, 28) || 'Carte ' + (cIdx + 1);
                dcCardsHtml += `
                <div class="cp-dc-card-item${cIdx === dcCurrent ? ' active' : ''}">
                    <div class="cp-dc-card-head" onclick="cpDcSelectCard(${cIdx})">
                        <span class="cp-dc-card-num">${cIdx + 1}</span>
                        <span class="cp-dc-card-title" id="cpDCTitle${cIdx}">${escapeHtml(cTitle)}</span>
                        <button class="tree-action-btn" onclick="event.stopPropagation(); cpDcMoveCard(${cIdx}, -1)" title="Monter" ${cIdx === 0 ? 'disabled' : ''}>⬆️</button>
                        <button class="tree-action-btn" onclick="event.stopPropagation(); cpDcMoveCard(${cIdx}, 1)" title="Descendre" ${cIdx === dcCards.length - 1 ? 'disabled' : ''}>⬇️</button>
                        <button class="tree-action-btn" onclick="event.stopPropagation(); cpDcDeleteCard(${cIdx})" title="Supprimer" ${dcCards.length === 1 ? 'disabled' : ''}>🗑️</button>
                    </div>
                    <div class="cp-dc-card-body" style="display: ${cIdx === dcCurrent ? 'block' : 'none'};">
                        <div class="cp-prop-group">
                            <label class="cp-prop-label">Image (optionnel)</label>
                            ${cImageUrl ? `
                                <div style="position: relative; margin-bottom: 0.5rem;">
                                    <img src="${cImageUrl}" style="width: 100%; max-height: 120px; object-fit: contain; border-radius: 4px; background: var(--gray-100);">
                                    <button class="tree-action-btn" onclick="cpRemoveDialogCardImage(${cIdx})"
                                            style="position: absolute; top: 4px; right: 4px; background: rgba(255,255,255,0.9);">🗑️</button>
                                </div>
                            ` : ''}
                            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="file" class="cp-prop-input" accept="image/*" onchange="cpUploadDialogCardImage(this, ${cIdx})" style="font-size: 0.75rem; flex: 1;">
                            </div>
                            <div style="display: flex; gap: 0.25rem; align-items: center;">
                                <input type="text" class="cp-prop-input" placeholder="ou URL de l'image..."
                                       value="${cImageUrl && cImageUrl.startsWith('http') ? escapeHtml(cImageUrl) : ''}"
                                       onkeydown="if(event.key==='Enter'){cpSetDialogCardImageUrl(this.value, ${cIdx})}"
                                       style="font-size: 0.75rem; flex: 1;">
                                <button class="btn btn-secondary" onclick="cpSetDialogCardImageUrl(this.previousElementSibling.value, ${cIdx})"
                                        style="padding: 0.3rem 0.5rem; font-size: 0.7rem;">OK</button>
                            </div>
                        </div>
                        <div class="cp-prop-group">
                            <label class="cp-prop-label">Texte recto</label>
                            ${cpDcToolbarHtml(cIdx, 'recto')}
                            <div class="rich-text-editor" contenteditable="true" id="cpDCRectoEditor${cIdx}"
                                 style="min-height: 60px; font-size: 0.85rem;"
                                 oninput="cpUpdateDCTextLive('text', ${cIdx})" onblur="cpUpdateDCText('text', ${cIdx})">${card.text || ''}</div>
                        </div>
                        <div class="cp-prop-group">
                            <label class="cp-prop-label">Texte verso</label>
                            ${cpDcToolbarHtml(cIdx, 'verso')}
                            <div class="rich-text-editor" contenteditable="true" id="cpDCVersoEditor${cIdx}"
                                 style="min-height: 60px; font-size: 0.85rem;"
                                 oninput="cpUpdateDCTextLive('answer', ${cIdx})" onblur="cpUpdateDCText('answer', ${cIdx})">${card.answer || ''}</div>
                        </div>
                    </div>
                </div>`;
            });

            propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Cartes (${dcCards.length})</label>
                    <div class="cp-dc-card-list">${dcCardsHtml}</div>
                    <button class="cp-dc-add-card" onclick="cpDcAddCard()">➕ Ajouter une carte</button>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-checkbox-label" style="font-size: 0.75rem;">
                        <input type="checkbox" ${dcRandom ? 'checked' : ''} onchange="cpDcUpdateBehaviour('randomCards', this.checked)"> Mélanger les cartes
                    </label>
                </div>`;
            break;
            
        case 'dragquestion':
            const dqParams = element.action?.params || {};
            const dqQuestion = dqParams.question || {};
            const dqSettings = dqQuestion.settings || {};
            const dqTask = dqQuestion.task || {};
            const dqElements = dqTask.elements || [];
            const dqDropZones = dqTask.dropZones || [];
            const dqBgPath = dqSettings.background?.path || '';
            const dqSize = dqSettings.size || { width: 800, height: 400 };
            const dqZoneOpacity = dqSettings.dropZoneOpacity !== undefined ? dqSettings.dropZoneOpacity : 0;
            
            // Mode vue étiquette : afficher seulement l'étiquette sélectionnée
            if (cpDqSelectedItem && cpDqSelectedItem.type === 'element' && cpDqSelectedItem.idx < dqElements.length) {
                const eIdx = cpDqSelectedItem.idx;
                const elem = dqElements[eIdx];
                const elemLib = (elem.type?.library || '').toLowerCase();
                const isImgElem = elemLib.indexOf('h5p.image') !== -1;
                const elemText = isImgElem ? (elem.type?.params?.alt || 'Image') : decodeHtmlEntities((elem.type?.params?.text || '')).replace(/<[^>]*>/g, '');
                const imgFilePath = isImgElem ? (elem.type?.params?.file?.path || '') : '';
                
                // Calculer les zones où cette étiquette est une BONNE RÉPONSE
                const correctZonesForElem = [];
                dqDropZones.forEach((dz, zIdx) => {
                    if (dz.correctElements && (dz.correctElements.includes(String(eIdx)) || dz.correctElements.includes(eIdx))) {
                        correctZonesForElem.push(zIdx);
                    }
                });
                
                // Contenu spécifique selon le type
                let elemEditHtml = '';
                if (isImgElem) {
                    elemEditHtml = `
                    <div style="margin-top: 10px;">
                        <label class="cp-prop-label">Image</label>
                        ${imgFilePath ? `<div style="margin: 8px 0; text-align: center; background: #f5f5f5; padding: 8px; border-radius: 6px; border: 1px solid #ddd;">
                            <img src="${escapeHtml(imgFilePath)}" style="max-width: 100%; max-height: 120px; object-fit: contain;" onerror="this.outerHTML='<span style=color:#999>Image introuvable</span>'">
                        </div>` : ''}
                        <button onclick="cpDqChangeElementImage(${eIdx})" style="width: 100%; padding: 8px; background: #1976d2; color: white; border: none; border-radius: 4px; font-size: 0.8rem; cursor: pointer; margin-bottom: 8px;">
                            📁 ${imgFilePath ? 'Changer l&apos;image' : 'Choisir une image'}
                        </button>
                    </div>
                    <div style="margin-top: 10px;">
                        <label class="cp-prop-label">Texte alternatif</label>
                        <input type="text" class="cp-prop-input" value="${escapeHtml(elem.type?.params?.alt || '')}" 
                               placeholder="Description de l'image"
                               onchange="var a=getSelectedActivity(); var el=a.content.presentation.slides[cpCurrentSlide].elements[cpSelectedElement]; el.action.params.question.task.elements[${eIdx}].type.params.alt=this.value; el.action.params.question.task.elements[${eIdx}].type.params.decorative=!this.value; onCourseModified();"
                               style="font-size: 0.9rem; padding: 10px;">
                    </div>
                    <div style="margin-top: 10px;">
                        <label class="cp-prop-label">Taille (% du canvas)</label>
                        <div style="display: flex; gap: 8px; margin-top: 6px;">
                            <div style="flex:1;">
                                <label style="font-size: 0.7rem; color: #888;">Largeur</label>
                                <input type="number" class="cp-prop-input" value="${(elem.width || 8).toFixed(1)}" step="0.5" min="1" max="50"
                                       onchange="cpDqUpdateElementSize(${eIdx}, 'width', this.value)" style="font-size: 0.85rem;">
                            </div>
                            <div style="flex:1;">
                                <label style="font-size: 0.7rem; color: #888;">Hauteur</label>
                                <input type="number" class="cp-prop-input" value="${(elem.height || 8).toFixed(1)}" step="0.5" min="1" max="50"
                                       onchange="cpDqUpdateElementSize(${eIdx}, 'height', this.value)" style="font-size: 0.85rem;">
                            </div>
                        </div>
                    </div>`;
                } else {
                    elemEditHtml = `
                    <div style="margin-top: 10px;">
                        <label class="cp-prop-label">Texte</label>
                        <input type="text" class="cp-prop-input" id="cpDqElemText_${eIdx}" value="${escapeHtml(elemText)}"
                               placeholder="Texte de l'étiquette"
                               oninput="cpDqBufferElementTextChange(${eIdx}, this.value)"
                               style="font-size: 0.9rem; padding: 10px;">
                        ${cpEmojiBarHtml('cpDqElemText_' + eIdx)}
                    </div>`;
                }
                
                propsHtml += `
                <div style="margin-bottom: 12px;">
                    <button onclick="cpDqSelectItem(null)" style="display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: var(--gray-100,#f5f5f5); border: 1px solid var(--gray-300,#ddd); border-radius: 4px; cursor: pointer; font-size: 0.8rem; color: var(--text-secondary,#666);">
                        ← Retour à l'activité
                    </button>
                </div>
                <div class="cp-dq-editor-section">
                    <div class="cp-dq-editor-section-title" style="display: flex; align-items: center; gap: 8px;">
                        <span class="cp-dq-item-num" style="background: #1976d2; color: white;">${eIdx + 1}</span>
                        <span>${isImgElem ? '🖼️ Étiquette image' : 'Étiquette'}</span>
                    </div>
                    ${elemEditHtml}
                    ${dqDropZones.length > 0 ? `
                    <div style="margin-top: 15px;">
                        <label class="cp-prop-label">Bonne réponse (zone correcte)</label>
                        <div style="display: flex; flex-direction: column; gap: 4px; margin-top: 8px;">
                            ${dqDropZones.map((dz, zIdx) => {
                                const isChecked = dz.correctElements && (dz.correctElements.includes(String(eIdx)) || dz.correctElements.includes(eIdx));
                                return `<label style="display: flex; align-items: center; gap: 10px; padding: 10px; cursor: pointer; border: 1px solid var(--gray-300,#ddd); border-radius: 4px; background: ${isChecked ? 'rgba(156,39,176,0.14)' : 'var(--bg-secondary,white)'};">
                                    <input type="checkbox" ${isChecked ? 'checked' : ''}
                                           onchange="cpDqToggleElementZoneNoRender(${eIdx}, ${zIdx}, this.checked)"
                                           style="width: 18px; height: 18px; accent-color: #9c27b0;">
                                    <span style="font-weight: 600; color: var(--dq-zone-text,#9c27b0);">Zone ${zIdx + 1}</span>
                                </label>`;
                            }).join('')}
                        </div>
                    </div>` : '<p style="font-size: 0.8rem; color: var(--text-muted,#999); margin-top: 10px;">Ajoutez des zones de dépôt d\'abord</p>'}
                    <div style="margin-top: 15px;">
                        <button onclick="cpDqDeleteElement(${eIdx}); cpDqSelectItem(null);" style="width: 100%; padding: 10px; background: #f44336; color: white; border: none; border-radius: 4px; font-size: 0.85rem; cursor: pointer;">
                            🗑️ Supprimer cette étiquette
                        </button>
                    </div>
                </div>`;
                break;
            }
            
            // Mode vue complète : liste des étiquettes avec dropdown multi-select pour les zones acceptées
            let dqElementsHtml = '';
            dqElements.forEach((elem, eIdx) => {
                const eLib = (elem.type?.library || '').toLowerCase();
                const isImgEl = eLib.indexOf('h5p.image') !== -1;
                const elemText = isImgEl ? (elem.type?.params?.alt || 'Image') : decodeHtmlEntities((elem.type?.params?.text || '')).replace(/<[^>]*>/g, '');
                const imgPath = isImgEl ? (elem.type?.params?.file?.path || '') : '';
                
                // Calculer les zones où cette étiquette est une BONNE RÉPONSE (correctElements)
                const correctZones = [];
                dqDropZones.forEach((dz, zIdx) => {
                    if (dz.correctElements && (dz.correctElements.includes(String(eIdx)) || dz.correctElements.includes(eIdx))) {
                        correctZones.push(zIdx);
                    }
                });
                
                let zonesSelectedText = 'Aucune zone';
                if (correctZones.length > 0) {
                    const zoneNums = correctZones.map(z => z + 1).sort((a,b) => a-b);
                    zonesSelectedText = zoneNums.length === dqDropZones.length ? 'Toutes' : zoneNums.join(', ');
                }
                
                // Dropdown avec checkboxes
                let zonesDropdown = '';
                if (dqDropZones.length > 0) {
                    zonesDropdown = `
                        <div class="cp-dq-zones-dropdown" style="position: relative; margin-top: 6px;">
                            <div class="cp-dq-zones-dropdown-btn" onclick="cpDqToggleZonesDropdown(${eIdx})"
                                 style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: var(--gray-100,#f5f5f5); color: var(--text-primary,inherit); border: 1px solid var(--gray-300,#ddd); border-radius: 4px; cursor: pointer; font-size: 0.75rem;">
                                <span>Bonne réponse: <strong>${zonesSelectedText}</strong></span>
                                <span style="font-size: 0.6rem;">▼</span>
                            </div>
                            <div id="cpDqZonesMenu${eIdx}" class="cp-dq-zones-menu" onclick="event.stopPropagation()" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-secondary,white); border: 1px solid var(--gray-300,#ddd); border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; max-height: 150px; overflow-y: auto; margin-top: 2px;">
                                ${dqDropZones.map((dz, zIdx) => {
                                    const isChecked = dz.correctElements && (dz.correctElements.includes(String(eIdx)) || dz.correctElements.includes(eIdx));
                                    return `<label style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; cursor: pointer; border-bottom: 1px solid #eee; font-size: 0.8rem; ${isChecked ? 'background: rgba(156,39,176,0.08);' : ''}" 
                                                   onmouseover="this.style.background='rgba(156,39,176,0.12)'" 
                                                   onmouseout="this.style.background='${isChecked ? 'rgba(156,39,176,0.08)' : 'transparent'}'">
                                        <input type="checkbox" ${isChecked ? 'checked' : ''} 
                                               onchange="cpDqToggleElementZone(${eIdx}, ${zIdx}, this.checked)"
                                               style="width: 16px; height: 16px; accent-color: #9c27b0;">
                                        <span style="font-weight: 600; color: var(--dq-zone-text,#9c27b0); min-width: 20px;">Zone ${zIdx + 1}</span>
                                    </label>`;
                                }).join('')}
                            </div>
                        </div>`;
                } else {
                    zonesDropdown = `<p style="font-size: 0.7rem; color: #999; margin-top: 6px;">Ajoutez des zones de dépôt d'abord</p>`;
                }
                
                // Contenu spécifique : texte ou image
                let elemContentHtml = '';
                if (isImgEl) {
                    elemContentHtml = `
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            ${imgPath ? `<img src="${escapeHtml(imgPath)}" style="width: 40px; height: 40px; object-fit: contain; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;" onerror="this.style.display='none'">` : '<div style="width:40px;height:40px;background:#f0f0f0;border-radius:4px;display:flex;align-items:center;justify-content:center;">🖼️</div>'}
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 0.75rem; color: var(--text-secondary,#666); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(elemText)}</div>
                                <div style="font-size: 0.65rem; color: #999;">W: ${(elem.width||8).toFixed(1)}% H: ${(elem.height||8).toFixed(1)}%</div>
                            </div>
                            <button onclick="cpDqChangeElementImage(${eIdx})" style="padding: 4px 8px; background: #1976d2; color: white; border: none; border-radius: 3px; font-size: 0.65rem; cursor: pointer;" title="Changer l&apos;image">📁</button>
                        </div>`;
                } else {
                    elemContentHtml = `
                        <input type="text" class="cp-prop-input" id="cpDqElemListText_${eIdx}" value="${escapeHtml(elemText)}"
                               placeholder="Texte de l'étiquette"
                               onfocus="window._lastEmojiTarget='cpDqElemListText_${eIdx}'"
                               oninput="cpDqBufferElementTextChange(${eIdx}, this.value)"
                               style="font-size: 0.8rem;">`;
                }
                
                dqElementsHtml += `
                    <div class="cp-dq-item-card" id="cpDqElement${eIdx}">
                        <div class="cp-dq-item-header">
                            <span class="cp-dq-item-title"><span class="cp-dq-item-num">${eIdx + 1}</span> ${isImgEl ? '🖼️ Image' : 'Étiquette'}</span>
                            <div class="cp-dq-item-actions">
                                <button class="cp-dq-item-btn delete" onclick="cpDqDeleteElement(${eIdx})" title="Supprimer">×</button>
                            </div>
                        </div>
                        ${elemContentHtml}
                        ${zonesDropdown}
                    </div>`;
            });
            
            propsHtml += `
                <div class="cp-dq-editor-section cp-dq-collapsible">
                    <div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                        <span>🖼️ Image de fond</span>
                        <span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>
                    </div>
                    <div class="cp-dq-collapse-content">
                        <div class="cp-dq-bg-actions" style="margin-bottom: 10px;">
                            <input type="file" id="cpDqBgFile" class="cp-prop-input" accept="image/*" onchange="cpDqUploadBackground(this)" style="display: none;">
                            <button class="cp-dq-bg-btn" onclick="document.getElementById('cpDqBgFile').click()">📁 Parcourir</button>
                            <button class="cp-dq-bg-btn" onclick="cpDqPromptBackgroundUrl()">🔗 URL</button>
                            ${dqBgPath ? '<button class="cp-dq-bg-btn danger" onclick="cpDqClearBackground()">🗑️</button>' : ''}
                        </div>
                    </div>
                </div>
                
                <div class="cp-dq-editor-section cp-dq-collapsible">
                    <div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                        <span>🤖 Automatisation</span>
                        <span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>
                    </div>
                    <div class="cp-dq-collapse-content">
                        <div class="cp-dq-preset-section" style="margin-bottom: 12px;">
                            <label style="font-size: 0.75rem; color: var(--text-secondary,#666); margin-bottom: 6px; display: block;">Images proposées:</label>
                            <div class="cp-dq-dropdown" style="position: relative;">
                                <div onclick="this.nextElementSibling.classList.toggle('open')" style="width: 100%; padding: 8px; border: 1px solid var(--gray-300,#ddd); border-radius: 4px; font-size: 0.8rem; cursor: pointer; background: var(--bg-secondary,white); color: var(--text-primary,inherit); display: flex; align-items: center; gap: 8px;">
                                    ${dqBgPath ? `<img src="${dqBgPath}" style="height: 30px; width: auto; border-radius: 2px;"><span style="flex:1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${dqBgPath.split('/').pop()}</span>` : '<span style="color: var(--text-muted,#999);">-- Sélectionner une image --</span>'}
                                    <span style="color: var(--text-secondary,#666);">▼</span>
                                </div>
                                <div class="cp-dq-dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-secondary,white); border: 1px solid var(--gray-300,#ddd); border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 100; max-height: 200px; overflow-y: auto;">
                                    <div onclick="cpDqApplyPreset('capteurs-actionneurs'); this.parentElement.classList.remove('open');" style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                                        <img src="assets/images/dragdrop/_Capteurs-Actionneurs.png" style="height: 40px; width: auto; border-radius: 2px;">
                                        <span>Capteurs-Actionneurs</span>
                                    </div>
                                    <div onclick="cpDqApplyPreset('actionneurs'); this.parentElement.classList.remove('open');" style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                                        <img src="assets/images/dragdrop/_Actionneurs.png" style="height: 40px; width: auto; border-radius: 2px;">
                                        <span>Actionneurs</span>
                                    </div>
                                    <div onclick="cpDqApplyPreset('capteurs'); this.parentElement.classList.remove('open');" style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                                        <img src="assets/images/dragdrop/_Capteurs.png" style="height: 40px; width: auto; border-radius: 2px;">
                                        <span>Capteurs</span>
                                    </div>
                                    <div onclick="cpDqApplyPreset('chaine-information'); this.parentElement.classList.remove('open');" style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                                        <img src="assets/images/dragdrop/chaine-information.png" style="height: 40px; width: auto; border-radius: 2px;">
                                        <span>Chaîne d'information</span>
                                    </div>
                                    <div onclick="cpDqApplyPreset('chaine-energie'); this.parentElement.classList.remove('open');" style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--gray-200, #eee);" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                                        <img src="assets/images/dragdrop/chaine-energie.png" style="height: 40px; width: auto; border-radius: 2px;">
                                        <span>Chaîne d'énergie</span>
                                    </div>
                                    <div onclick="cpDqApplyPreset('chaine-complete'); this.parentElement.classList.remove('open');" style="padding: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background=''">
                                        <img src="assets/images/dragdrop/chaine-complete.png" style="height: 40px; width: auto; border-radius: 2px;">
                                        <span>Chaîne complète</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="cp-dq-blocks-section">
                            <label style="font-size: 0.75rem; color: var(--text-secondary,#666); margin-bottom: 6px; display: block;">Programme Blocks ou Algorigramme:</label>
                            <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                                <input type="file" id="cpDqBlocksFile" accept="image/*" onchange="cpDqExtractBlocksFromFile(this)" style="display: none;">
                                <button class="cp-dq-blocks-btn" onclick="document.getElementById('cpDqBlocksFile').click()">
                                    🧩 Charger image
                                </button>
                            </div>
                            <div id="cpDqBlocksStatus"></div>
                        </div>
                    </div>
                </div>
                
                <div class="cp-dq-editor-section cp-dq-collapsible">
                    <div class="cp-dq-editor-section-title cp-dq-collapse-toggle" onclick="cpDqToggleSection(this)" style="cursor: pointer; display: flex; align-items: center; justify-content: space-between;">
                        <span>🎯 Zones de dépôt <span class="cp-dq-count">${dqDropZones.length}</span></span>
                        <span class="cp-dq-collapse-icon" style="font-size: 0.7rem; transition: transform 0.2s;">▼</span>
                    </div>
                    <div class="cp-dq-collapse-content">
                        <button onclick="cpDqAddZone()" style="width: 100%; padding: 8px; background: #9c27b0; color: white; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer; margin-bottom: 10px;">+ Ajouter une zone de dépôt</button>
                        <div class="cp-dq-opacity-control" style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <label style="font-size: 0.75rem; color: var(--text-secondary,#666);">Opacité:</label>
                            <input type="range" min="0" max="100" value="${dqZoneOpacity}" 
                                   onchange="cpDqUpdateZoneOpacity(this.value)"
                                   oninput="this.nextElementSibling.textContent = this.value + '%'"
                                   style="flex: 1; height: 4px;">
                            <span style="font-size: 0.7rem; color: #888; min-width: 35px;">${dqZoneOpacity}%</span>
                        </div>
                        <p style="font-size: 0.7rem; color: #888; margin: 0;">Glissez les zones sur le canvas pour les déplacer. Utilisez le coin pour redimensionner.</p>
                    </div>
                </div>
                
                <div class="cp-dq-editor-section">
                    <div class="cp-dq-editor-section-title" style="display: flex; align-items: center; justify-content: space-between;">
                        <span>📦 Étiquettes <span class="cp-dq-count">${dqElements.length}</span></span>
                    </div>
                    <div style="display: flex; gap: 6px; margin-bottom: 10px;">
                        <button onclick="cpDqAddElement()" style="flex: 1; padding: 8px; background: #666; color: white; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer;">+ Texte</button>
                        <button onclick="cpDqAddImageElement()" style="flex: 1; padding: 8px; background: #1976d2; color: white; border: none; border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer;">+ Image</button>
                    </div>
                    ${cpEmojiBarHtml('_dynamic_')}
                    <div class="cp-dq-items-list" id="cpDqItemsList">
                        ${dqElementsHtml || '<p class="cp-dq-empty">Aucune étiquette</p>'}
                    </div>
                </div>`;
            break;
            
        case 'shape':
            const sType = element.action?.params?.type || 'rectangle';
            const sShape = element.action?.params?.shape || {};
            const sLine = element.action?.params?.line || {};
            const isLine = sType === 'horizontal-line' || sType === 'vertical-line';
            
            // Type label
            const shapeLabel = sType === 'circle' ? '⭕ Rond' : isLine ? '➖ Trait' : '⬜ Carré';
            propsHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Type</label>
                <div style="font-size: 0.8rem; font-weight: 600; padding: 4px 0;">${shapeLabel}</div></div>`;
            
            if (isLine) {
                // Ligne: couleur, épaisseur, style
                propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Orientation</label>
                    <select class="cp-prop-input" onchange="cpUpdateNestedProp('action.params.type', this.value)">
                        <option value="horizontal-line" ${sType === 'horizontal-line' ? 'selected' : ''}>Horizontal</option>
                        <option value="vertical-line" ${sType === 'vertical-line' ? 'selected' : ''}>Vertical</option>
                    </select>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Couleur</label>
                    <input type="color" class="cp-prop-input" value="${sLine.borderColor || '#000000'}" style="height: 36px;"
                           onchange="cpUpdateNestedProp('action.params.line.borderColor', this.value)">
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Épaisseur (px)</label>
                    <input type="number" class="cp-prop-input" value="${sLine.borderWidth || 2}" min="1" max="20"
                           onchange="cpUpdateNestedProp('action.params.line.borderWidth', this.value)">
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Style</label>
                    <select class="cp-prop-input" onchange="cpUpdateNestedProp('action.params.line.borderStyle', this.value)">
                        <option value="solid" ${(sLine.borderStyle || 'solid') === 'solid' ? 'selected' : ''}>Plein</option>
                        <option value="dashed" ${sLine.borderStyle === 'dashed' ? 'selected' : ''}>Tirets</option>
                        <option value="dotted" ${sLine.borderStyle === 'dotted' ? 'selected' : ''}>Pointillés</option>
                        <option value="double" ${sLine.borderStyle === 'double' ? 'selected' : ''}>Double</option>
                    </select>
                </div>`;
            } else {
                // Rectangle / Circle: fill, border color, border width, border style, border radius (rect only)
                const isFillTransparent = sShape.fillColor && (
                    sShape.fillColor === 'transparent' ||
                    /rgba\([^)]+,\s*0(?:\.0*)?\s*\)/.test(sShape.fillColor)
                );
                const fillColorForPicker = isFillTransparent ? '#ffffff' : (sShape.fillColor || '#d0d0d0');
                propsHtml += `
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Couleur de remplissage</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 4px; font-size: 0.8rem; cursor: pointer; white-space: nowrap;">
                            <input type="checkbox" ${isFillTransparent ? 'checked' : ''}
                                   onchange="cpToggleFillTransparent(this)">
                            Transparent
                        </label>
                        <input type="color" class="cp-prop-input" value="${fillColorForPicker}" style="height: 36px; flex: 1; ${isFillTransparent ? 'opacity: 0.4; pointer-events: none;' : ''}"
                               onchange="cpUpdateNestedProp('action.params.shape.fillColor', this.value)">
                    </div>
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Couleur de bordure</label>
                    <input type="color" class="cp-prop-input" value="${sShape.borderColor || '#000000'}" style="height: 36px;"
                           onchange="cpUpdateNestedProp('action.params.shape.borderColor', this.value)">
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Épaisseur bordure (px)</label>
                    <input type="number" class="cp-prop-input" value="${sShape.borderWidth || 0}" min="0" max="20"
                           onchange="cpUpdateNestedProp('action.params.shape.borderWidth', this.value)">
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Style bordure</label>
                    <select class="cp-prop-input" onchange="cpUpdateNestedProp('action.params.shape.borderStyle', this.value)">
                        <option value="solid" ${(sShape.borderStyle || 'solid') === 'solid' ? 'selected' : ''}>Plein</option>
                        <option value="dashed" ${sShape.borderStyle === 'dashed' ? 'selected' : ''}>Tirets</option>
                        <option value="dotted" ${sShape.borderStyle === 'dotted' ? 'selected' : ''}>Pointillés</option>
                        <option value="double" ${sShape.borderStyle === 'double' ? 'selected' : ''}>Double</option>
                    </select>
                </div>`;
                // Border radius only for rectangles
                if (sType === 'rectangle') {
                    propsHtml += `
                    <div class="cp-prop-group">
                        <label class="cp-prop-label">Arrondi bordure (px)</label>
                        <input type="number" class="cp-prop-input" value="${sShape.borderRadius || 3}" min="0" max="100"
                               onchange="cpUpdateNestedProp('action.params.shape.borderRadius', this.value)">
                    </div>`;
                }
            }
            break;
    }
    
    container.innerHTML = propsHtml;
    if (type === 'table') {
        requestAnimationFrame(() => cpInitTablePropsEditor());
    }
}

function cpUpdateElementProp(prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    element[prop] = value;
    cpRenderSlideElements();
    onCourseModified();
}

function cpUpdateNestedProp(path, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    const parts = path.split('.');
    let obj = element;
    for (let i = 0; i < parts.length - 1; i++) {
        if (!obj[parts[i]]) obj[parts[i]] = {};
        obj = obj[parts[i]];
    }
    obj[parts[parts.length - 1]] = value;
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpToggleFillTransparent(checkbox) {
    const colorInput = checkbox.closest('.cp-prop-group').querySelector('input[type=color]');
    const newColor = checkbox.checked ? 'rgba(255, 255, 255, 0)' : colorInput.value;
    colorInput.style.opacity = checkbox.checked ? '0.4' : '1';
    colorInput.style.pointerEvents = checkbox.checked ? 'none' : 'auto';
    cpUpdateNestedProp('action.params.shape.fillColor', newColor);
}

// Mise à jour du texte depuis le panneau de propriétés (sur blur)
// Assure que tous les liens <a> ont target="_blank"
function _cpFixLinksTarget(html) {
    if (!html || html.indexOf('<a ') === -1) return html;
    return html.replace(/<a\s/gi, function(match) {
        return match;
    }).replace(/<a ([^>]*)>/gi, function(full, attrs) {
        // Retirer target existant et ajouter target="_blank"
        attrs = attrs.replace(/\s*target\s*=\s*["'][^"']*["']/gi, '');
        return '<a ' + attrs + ' target="_blank">';
    });
}

function cpUpdateTextContent() {
    const editor = document.getElementById('cpTextEditor');
    if (!editor) return;
    
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.text = _cpFixLinksTarget(editor.innerHTML);
    
    // Mettre à jour le canvas
    const canvasText = document.querySelector(`.cp-editable-text[data-idx="${cpSelectedElement}"]`);
    if (canvasText) {
        canvasText.innerHTML = editor.innerHTML;
    }
    
    onCourseModified();
}

// Mise à jour temps réel depuis le panneau (sur input)
function cpUpdateTextContentLive() {
    const editor = document.getElementById('cpTextEditor');
    if (!editor) return;
    
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.text = _cpFixLinksTarget(editor.innerHTML);
    
    // Mettre à jour le canvas en temps réel
    const canvasText = document.querySelector(`.cp-editable-text[data-idx="${cpSelectedElement}"]`);
    if (canvasText && document.activeElement !== canvasText) {
        canvasText.innerHTML = editor.innerHTML;
    }
}

// Quand on clique sur un texte éditable dans le canvas
function cpTextEnterEdit(event, idx) {
    event.stopPropagation();

    // Si ce cadre est DÉJÀ en édition, ne pas re-sélectionner tout le texte :
    // laisser le double-clic sélectionner le mot (et le triple-clic la phrase).
    var _cpAlreadyEditing = (function() {
        var t = document.querySelector('.cp-editable-text[data-idx="' + idx + '"]');
        return !!(t && t.getAttribute('contenteditable') === 'true' && document.activeElement === t);
    })();
    if (!_cpAlreadyEditing) {
        event.preventDefault();
    }

    // Sélectionner l'élément
    cpSelectedElement = idx;
    cpSelectedElements.clear();
    cpSelectedElements.add(idx);
    
    const canvas = document.getElementById('cpCanvasInner');
    if (canvas) {
        canvas.querySelectorAll('.cp-element').forEach(elem => {
            elem.classList.toggle('selected', parseInt(elem.dataset.idx) === idx);
        });
    }
    
    // Trouver le contenteditable et l'activer
    const textEl = document.querySelector(`.cp-editable-text[data-idx="${idx}"]`);
    if (textEl && !_cpAlreadyEditing) {
        textEl.setAttribute('contenteditable', 'true');
        textEl.focus();
        
        // Sélectionner tout le texte (sans toucher au formatage)
        const sel = window.getSelection();
        if (sel) {
            sel.removeAllRanges();
            const range = document.createRange();
            range.selectNodeContents(textEl);
            sel.addRange(range);
        }
        
        // Intercepter la première frappe de caractère imprimable :
        // si tout le texte est encore sélectionné, remplacer avec le formatage par défaut
        function _cpFirstKeyHandler(e) {
            // Ignorer les raccourcis et touches spéciales
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (e.key.length !== 1) return; // Ignore Enter, Backspace, flèches, etc.
            
            // Vérifier que tout le texte est encore sélectionné
            var s = window.getSelection();
            if (!s || s.rangeCount === 0) {
                textEl.removeEventListener('keydown', _cpFirstKeyHandler);
                return;
            }
            
            var selectedText = s.toString();
            var fullText = textEl.textContent;
            
            // Si la sélection couvre (presque) tout le contenu → remplacement formaté
            if (selectedText.length > 0 && selectedText.length >= fullText.length - 1) {
                e.preventDefault();
                textEl.removeEventListener('keydown', _cpFirstKeyHandler);
                
                var ch = escapeHtml(e.key);
                textEl.innerHTML = '<p style="text-align:center;"><strong><span style="font-size:1.5em;">' + ch + '</span></strong></p>';
                
                // Placer le curseur juste après le caractère inséré
                var span = textEl.querySelector('span');
                if (span && span.firstChild) {
                    var r = document.createRange();
                    r.setStart(span.firstChild, e.key.length);
                    r.collapse(true);
                    s.removeAllRanges();
                    s.addRange(r);
                }
                
                // Déclencher input pour mettre à jour le modèle
                textEl.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                // La sélection a changé (clic, flèche), on n'intercepte plus
                textEl.removeEventListener('keydown', _cpFirstKeyHandler);
            }
        }
        
        textEl.addEventListener('keydown', _cpFirstKeyHandler);
        
        // Annuler l'interception si l'utilisateur clique (= repositionne le curseur)
        textEl.addEventListener('mousedown', function _cpCancelFirstKey() {
            textEl.removeEventListener('keydown', _cpFirstKeyHandler);
            textEl.removeEventListener('mousedown', _cpCancelFirstKey);
        });
        
        // Afficher la barre d'outils flottante
        clearTimeout(window._ftHideTimer);
        setTimeout(function() {
            cpShowFloatToolbar(textEl);
        }, 50);
    } else if (textEl && _cpAlreadyEditing) {
        // Déjà en édition (double-clic = sélection du mot) : garder la barre visible
        clearTimeout(window._ftHideTimer);
        cpShowFloatToolbar(textEl);
    }

    cpRenderElementProps();
}

// Compat : ancien nom
function cpTextMouseDown(event, idx) {
    cpTextEnterEdit(event, idx);
}

// Quand on tape directement sur le canvas
function cpTextInput(event, idx) {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[idx];
    
    // Détecter si le contenu est devenu vide → remettre le formatage par défaut
    var textContent = event.target.textContent.trim();
    if (textContent === '') {
        event.target.innerHTML = '<p style="text-align:center;"><strong><span style="font-size:1.5em;"><br></span></strong></p>';
        // Placer le curseur dans le span formaté
        var span = event.target.querySelector('span');
        if (span) {
            var sel = window.getSelection();
            var range = document.createRange();
            range.setStart(span, 0);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }
    
    // Détecter les URLs et les convertir en liens
    cpAutoLinkUrls(event.target);
    
    // event.target est l'élément contenteditable
    const newText = event.target.innerHTML;
    element.action.params.text = _cpFixLinksTarget(newText);
    
    // Sauvegarder la sélection du canvas
    cpSaveCanvasSelection();
    
    // Auto-resize hauteur
    cpAutoResizeElement(event.target, element);
    
    // Mettre à jour le panneau de propriétés si ouvert (sans perdre le focus du canvas)
    const propsEditor = document.getElementById('cpTextEditor');
    if (propsEditor && cpSelectedElement === idx && document.activeElement !== propsEditor) {
        propsEditor.innerHTML = newText;
    }
    
    // Mettre à jour la miniature
    cpUpdateSlideThumb(cpCurrentSlide);
}

/**
 * Détecte les URLs dans un contenteditable et les transforme en liens <a>
 * Se déclenche quand l'utilisateur tape espace, Entrée, ou virgule après une URL
 */
function cpAutoLinkUrls(editable) {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    
    var range = sel.getRangeAt(0);
    if (!range.collapsed) return;
    
    // Trouver le nœud texte où se trouve le curseur
    var node = range.startContainer;
    if (node.nodeType !== 3) return; // Seulement les nœuds texte
    
    // Ne pas agir si on est déjà dans un lien
    if (node.parentElement && node.parentElement.closest('a')) return;
    
    var text = node.textContent;
    var cursorPos = range.startOffset;
    
    // Vérifier que le dernier caractère tapé est un séparateur (espace, newline)
    if (cursorPos < 2) return;
    var lastChar = text.charAt(cursorPos - 1);
    if (lastChar !== ' ' && lastChar !== '\n' && lastChar !== '\u00a0') return;
    
    // Extraire le mot avant le séparateur
    var beforeSpace = text.substring(0, cursorPos - 1);
    var words = beforeSpace.split(/[\s\u00a0]+/);
    var lastWord = words[words.length - 1];
    if (!lastWord) return;
    
    // Pattern URL : protocole://..., www.xxx, ou domaine.tld connu
    var urlPattern = /^(https?:\/\/[^\s]+|www\.[^\s]+|[a-zA-Z0-9][-a-zA-Z0-9]*(?:\.[a-zA-Z0-9][-a-zA-Z0-9]*)*\.(?:com|org|net|fr|eu|io|dev|app|me|info|edu|gov|co|uk|de|es|it|be|ch|ca|us|tv|cc|ai|tech|online|site|xyz|pro)(?:\/[^\s]*)?)$/i;
    if (!urlPattern.test(lastWord)) return;
    
    // Construire le href avec https://
    var href = lastWord;
    if (!/^https?:\/\//i.test(href)) {
        href = 'https://' + href;
    }
    
    // Position du mot dans le nœud texte
    var wordStart = cursorPos - 1 - lastWord.length;
    var wordEnd = cursorPos - 1;
    
    // Découper le nœud texte et insérer le lien
    var beforeText = text.substring(0, wordStart);
    var afterText = text.substring(wordEnd); // inclut l'espace
    
    var link = document.createElement('a');
    link.href = href;
    link.textContent = lastWord;
    link.target = '_blank';
    
    var parent = node.parentNode;
    
    // Créer les nœuds
    var frag = document.createDocumentFragment();
    if (beforeText) frag.appendChild(document.createTextNode(beforeText));
    frag.appendChild(link);
    var afterNode = document.createTextNode(afterText);
    frag.appendChild(afterNode);
    
    parent.replaceChild(frag, node);
    
    // Replacer le curseur après l'espace
    var newRange = document.createRange();
    newRange.setStart(afterNode, afterText.length > 0 ? 1 : 0);
    newRange.collapse(true);
    sel.removeAllRanges();
    sel.addRange(newRange);
}

// Sauvegarde la sélection/curseur du contenteditable canvas
function cpSaveCanvasSelection() {
    var sel = window.getSelection();
    if (sel.rangeCount > 0 && cpActiveCanvasEditor) {
        var range = sel.getRangeAt(0);
        if (cpActiveCanvasEditor.contains(range.commonAncestorContainer)) {
            window._canvasSelRange = range.cloneRange();
        }
    }
}

// Sauvegarder la sélection à chaque changement (pour emoji sidebar)
document.addEventListener('selectionchange', function() {
    if (cpActiveCanvasEditor && document.activeElement === cpActiveCanvasEditor) {
        cpSaveCanvasSelection();
    }
    cpSaveRichSelection();
});

// Mémorise la DERNIÈRE sélection NON VIDE faite dans un éditeur de texte riche CP
// (canvas, panneau propriétés, éditeur d'interaction). Sert de source fiable pour
// appliquer couleur/surlignage sur une sélection partielle, même si la sélection live
// est perdue lors de l'ouverture du popup couleur ou du sélecteur natif.
function cpSaveRichSelection() {
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    var ae = document.activeElement;
    if (!ae) return;
    var isRich = (ae === cpActiveCanvasEditor)
        || (ae.classList && ae.classList.contains('cp-editable-text'))
        || (ae.classList && ae.classList.contains('rich-text-editor'))
        || (ae.id === 'cpTextEditor');
    // On ne touche à la mémoire que si un éditeur riche CP est actif. Quand un popup/
    // sélecteur natif est ouvert, activeElement n'est plus l'éditeur → on préserve la
    // dernière sélection mémorisée pour l'appliquer.
    if (!isRich) return;
    var r = sel.getRangeAt(0);
    if (r.collapsed || !sel.toString().length) {
        // Curseur simple dans l'éditeur (déselection volontaire) → plus rien à mémoriser
        if (window._cpRichSelEditor === ae) { window._cpRichSelRange = null; }
        return;
    }
    if (ae.contains(r.commonAncestorContainer)) {
        window._cpRichSelRange = r.cloneRange();
        window._cpRichSelEditor = ae;
    }
}

// Remonte depuis un noeud jusqu'à l'éditeur de texte CP qui le contient (canvas
// .cp-editable-text ou panneau #cpTextEditor), ou null. Sert à appliquer la couleur là
// où se trouve la sélection, quel que soit le bouton (barre flottante ou panneau).
function cpFindRichEditor(node) {
    var el = (node && node.nodeType === 3) ? node.parentNode : node;
    while (el && el.nodeType === 1) {
        if (el.id === 'cpTextEditor' || (el.classList && el.classList.contains('cp-editable-text'))) {
            return el;
        }
        el = el.parentNode;
    }
    return null;
}

// Quand on quitte l'édition sur le canvas
function cpTextBlur(idx) {
    // Sauvegarder la position du curseur avant que le blur ne la perde
    cpSaveCanvasSelection();
    onCourseModified();
    
    // Désactiver contenteditable après un court délai 
    // (pour permettre les clics sur la toolbar flottante / emoji)
    window._textBlurTimer = setTimeout(function() {
        var textEl = document.querySelector('.cp-editable-text[data-idx="' + idx + '"]');
        if (textEl && !textEl.contains(document.activeElement)) {
            textEl.removeAttribute('contenteditable');
            cpHideFloatToolbar();
        }
    }, 200);
}

// ==================== FLOATING TEXT TOOLBAR ====================

// Référence à l'éditeur canvas actif
var cpActiveCanvasEditor = null;

function cpShowFloatToolbar(editable) {
    cpActiveCanvasEditor = editable;
    var toolbar = document.getElementById('cpFloatToolbar');
    if (!toolbar) return;
    
    var elemDiv = editable.closest('.cp-element');
    if (!elemDiv) return;
    
    var canvasInner = document.getElementById('cpCanvasInner');
    if (!canvasInner) return;
    
    // Rendre visible brièvement pour mesurer la hauteur
    toolbar.style.visibility = 'hidden';
    toolbar.classList.add('visible');
    var tbHeight = toolbar.offsetHeight;
    toolbar.style.visibility = '';
    
    // Utiliser les offsets directs (pas getBoundingClientRect, pas de zoom à gérer)
    var elemLeft = elemDiv.offsetLeft + elemDiv.offsetWidth / 2;
    var elemTop = elemDiv.offsetTop;
    
    var relTop = elemTop - tbHeight - 6;
    
    // Si la toolbar sort par le haut, la mettre en dessous
    if (relTop < -tbHeight) {
        relTop = elemDiv.offsetTop + elemDiv.offsetHeight + 6;
    }
    
    toolbar.style.left = elemLeft + 'px';
    toolbar.style.top = relTop + 'px';
}

function cpHideFloatToolbar() {
    var toolbar = document.getElementById('cpFloatToolbar');
    if (toolbar) {
        toolbar.classList.remove('visible');
    }
    var popup = document.getElementById('cpEmojiPopup');
    if (popup) popup.classList.remove('visible');
    cpActiveCanvasEditor = null;
    window._canvasSelRange = null;
}

// Appliquer une commande de formatage sur le contenteditable actif du canvas
function cpCanvasFormat(event, command, value) {
    event.preventDefault();
    event.stopPropagation();
    
    // Annuler le timer de masquage (clic sur toolbar != blur)
    clearTimeout(window._ftHideTimer);
    
    var editor = cpActiveCanvasEditor;
    if (!editor) return;
    
    // Restaurer le focus
    editor.focus();
    
    // Si rien n'est sélectionné, sélectionner tout le contenu
    var sel = window.getSelection();
    var hasSelection = sel && sel.toString().length > 0;
    if (!hasSelection) {
        var range = document.createRange();
        range.selectNodeContents(editor);
        sel.removeAllRanges();
        sel.addRange(range);
    }
    
    if (command === 'fontSize') {
        // Détecter la taille em actuelle
        var currentEm = cpDetectFontSize(editor);
        
        // Trouver l'index dans le tableau de tailles
        var idx = CP_FONT_SIZES.indexOf(currentEm);
        if (idx === -1) {
            // Chercher la taille la plus proche
            var currentVal = parseFloat(currentEm) || 1.5;
            var minDiff = 999;
            for (var i = 0; i < CP_FONT_SIZES.length; i++) {
                var diff = Math.abs(parseFloat(CP_FONT_SIZES[i]) - currentVal);
                if (diff < minDiff) { minDiff = diff; idx = i; }
            }
        }
        
        var newIdx = value === '+' ? Math.min(CP_FONT_SIZES.length - 1, idx + 1) : Math.max(0, idx - 1);
        if (newIdx !== idx) {
            cpApplyFontSize(editor, CP_FONT_SIZES[newIdx]);
        }
    } else {
        document.execCommand(command, false, null);
    }
    
    // Sync avec les données
    cpSyncCanvasTextToData(editor);
}

// Synchronise le contenu du contenteditable canvas vers les données et le panneau latéral
function cpSyncCanvasTextToData(editor) {
    var idx = editor.dataset.idx;
    if (idx === undefined) return;
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[parseInt(idx)];
    if (!element) return;
    element.action.params.text = _cpFixLinksTarget(editor.innerHTML);
    var propsEditor = document.getElementById('cpTextEditor');
    if (propsEditor) propsEditor.innerHTML = editor.innerHTML;
    
    // Auto-resize hauteur si contenu déborde
    cpAutoResizeElement(editor, element);
    
    // Repositionner la toolbar (la taille de l'élément a pu changer)
    if (cpActiveCanvasEditor === editor) {
        setTimeout(function() { cpShowFloatToolbar(editor); }, 10);
    }
    
    // Mettre à jour la miniature du slide
    cpUpdateSlideThumb(cpCurrentSlide);
}

// Met à jour la miniature d'un slide spécifique
function cpUpdateSlideThumb(slideIdx) {
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[slideIdx];
    if (!slide) return;
    var thumbs = document.querySelectorAll('.cp-slide-thumb');
    if (thumbs[slideIdx]) {
        var preview = thumbs[slideIdx].querySelector('.cp-slide-thumb-preview');
        if (preview) {
            preview.innerHTML = cpGetSlidePreviewHtml(slide);
        }
    }
}

// Adapte la bounding box si le texte déborde (hauteur et largeur)
function cpAutoResizeElement(editor, element) {
    var elemDiv = editor.closest('.cp-element');
    if (!elemDiv) return;
    
    var canvas = document.getElementById('cpCanvasInner');
    if (!canvas) return;
    var canvasWidth = canvas.clientWidth;
    var canvasHeight = canvas.clientHeight;
    if (canvasWidth <= 0 || canvasHeight <= 0) return;
    
    var changed = false;
    
    // === Auto-resize hauteur ===
    var contentHeight = editor.scrollHeight;
    var boxHeight = elemDiv.clientHeight;
    
    if (contentHeight > boxHeight + 4) {
        var newHeightPx = contentHeight + 12;
        var newHeightPct = (newHeightPx / canvasHeight) * 100;
        newHeightPct = Math.min(95, Math.max(element.height || 15, newHeightPct));
        
        if (newHeightPct > element.height + 0.5) {
            element.height = newHeightPct;
            elemDiv.style.height = newHeightPct + '%';
            changed = true;
        }
    }
    
    // === Auto-resize largeur (si le texte ne wrape pas) ===
    // Mesurer la largeur naturelle du contenu
    var contentWidth = editor.scrollWidth;
    var boxWidth = elemDiv.clientWidth;
    
    if (contentWidth > boxWidth + 4) {
        var newWidthPx = contentWidth + 20;
        var newWidthPct = (newWidthPx / canvasWidth) * 100;
        newWidthPct = Math.min(95, Math.max(element.width || 20, newWidthPct));
        
        if (newWidthPct > element.width + 0.5) {
            element.width = newWidthPct;
            elemDiv.style.width = newWidthPct + '%';
            changed = true;
        }
    }
    
    if (changed && cpActiveCanvasEditor === editor) {
        cpShowFloatToolbar(editor);
    }
}

// ==================== EMOJI PRESS-HOLD-DRAG-RELEASE ====================

var cpEmojiPopupOpen = false;

function cpEmojiHoldStart(event) {
    event.preventDefault();
    event.stopPropagation();
    clearTimeout(window._ftHideTimer);
    
    if (!cpActiveCanvasEditor) return;
    
    // Sauvegarder la sélection
    var sel = window.getSelection();
    window._savedRange = (sel.rangeCount > 0) ? sel.getRangeAt(0).cloneRange() : null;
    
    var popup = document.getElementById('cpEmojiPopup');
    if (!popup) return;
    
    // Peupler si vide
    if (popup.children.length === 0) {
        CP_EMOJIS.forEach(function(e) {
            var item = document.createElement('div');
            item.className = 'ep-item';
            item.textContent = e;
            item.dataset.emoji = e;
            popup.appendChild(item);
        });
    }
    
    // Afficher
    popup.classList.add('visible');
    cpEmojiPopupOpen = true;
    
    // Suivi souris
    function onMove(ev) {
        var target = document.elementFromPoint(ev.clientX, ev.clientY);
        popup.querySelectorAll('.ep-item').forEach(function(el) { el.classList.remove('hovered'); });
        if (target && target.classList.contains('ep-item')) {
            target.classList.add('hovered');
        }
    }
    
    function onUp(ev) {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        
        var target = document.elementFromPoint(ev.clientX, ev.clientY);
        popup.classList.remove('visible');
        cpEmojiPopupOpen = false;
        
        if (target && target.dataset && target.dataset.emoji) {
            cpInsertEmojiAtCursor(target.dataset.emoji);
        }
    }
    
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

// Insère un émoji au curseur dans l'éditeur canvas actif
function cpInsertEmojiAtCursor(emoji) {
    var editor = cpActiveCanvasEditor;
    if (!editor) return;
    
    editor.focus();
    
    // Restaurer la sélection sauvegardée
    if (window._savedRange) {
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(window._savedRange);
        window._savedRange = null;
    }
    
    document.execCommand('insertText', false, emoji);
    
    // Sync avec les données
    cpSyncCanvasTextToData(editor);
    onCourseModified();
}

function cpFormatText(command) {
    const editor = document.getElementById('cpTextEditor');
    if (!editor) return;
    
    // Vérifier si du texte est sélectionné
    const selection = window.getSelection();
    const hasSelection = selection && selection.toString().length > 0;
    
    // Si aucune sélection, sélectionner tout le contenu
    if (!hasSelection) {
        const range = document.createRange();
        range.selectNodeContents(editor);
        selection.removeAllRanges();
        selection.addRange(range);
    }
    
    document.execCommand(command, false, null);
    editor.focus();

    // Mettre à jour le contenu
    cpUpdateTextContentLive();
}

// ==================== COULEUR DU TEXTE & SURLIGNAGE ====================
// Palette "Flat UI Colors" + couleur personnalisée. Disponible à la fois dans la
// barre flottante du canvas et dans la barre d'outils du panneau de propriétés.
var CP_TEXT_COLORS = [
    { n: 'Turquoise', c: '#1abc9c' }, { n: 'Émeraude', c: '#2ecc71' }, { n: 'Bleu rivière', c: '#3498db' }, { n: 'Améthyste', c: '#9b59b6' }, { n: 'Bleu ardoise', c: '#34495e' },
    { n: 'Vert sapin', c: '#0e6b58' }, { n: 'Vert foncé', c: '#1d7a43' }, { n: 'Bleu foncé', c: '#1c5780' }, { n: 'Violet foncé', c: '#622d76' }, { n: 'Noir', c: '#000000' },
    { n: 'Tournesol', c: '#f1c40f' }, { n: 'Carotte', c: '#e67e22' }, { n: 'Alizarine', c: '#e74c3c' }, { n: 'Blanc', c: '#ffffff' }, { n: 'Béton', c: '#95a5a6' },
    { n: 'Ambre foncé', c: '#b9760c' }, { n: 'Brun orangé', c: '#9c3d00' }, { n: 'Rouge foncé', c: '#8c281e' }, { n: 'Gris', c: '#7f8c8d' }, { n: 'Ardoise', c: '#4d5656' }
];
var cpLastTextColor = '#2c3e50';
var cpLastHiliteColor = '#f1c40f';
var _cpColorPopup = null;
var _cpColorState = null;

// Ouvre (ou referme) le sélecteur de couleur ancré au bouton cliqué.
//   kind : 'fore' (couleur du texte) | 'hilite' (surlignage)
//   ctx  : 'canvas' (barre flottante) | 'props' (panneau propriétés)
function cpColorBtn(event, kind, ctx) {
    event.preventDefault();
    event.stopPropagation();
    clearTimeout(window._ftHideTimer);
    clearTimeout(window._textBlurTimer);

    var btn = event.currentTarget;
    // Re-clic sur le même bouton => fermer
    if (_cpColorPopup && _cpColorPopup._kind === kind && _cpColorPopup._ctx === ctx) {
        cpCloseColorPicker();
        return;
    }
    cpCloseColorPicker();

    // La couleur doit s'appliquer là où se trouve RÉELLEMENT la sélection, quel que soit le
    // bouton utilisé (barre flottante ou panneau de propriétés). Le panneau (#cpTextEditor)
    // et le canvas (.cp-editable-text) reflètent le même texte : on cible donc l'éditeur qui
    // contient la sélection courante (ou la dernière sélection riche mémorisée), et non
    // aveuglément #cpTextEditor.
    var editor = null;
    var savedRange = null;

    var sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
        var live = sel.getRangeAt(0);
        if (!live.collapsed && sel.toString().length) {
            var liveEditor = cpFindRichEditor(live.commonAncestorContainer);
            if (liveEditor) { editor = liveEditor; savedRange = live.cloneRange(); }
        }
    }
    // À défaut : dernière sélection riche mémorisée (immune aux pertes lors de l'ouverture
    // du popup / sélecteur natif de couleur).
    if (!editor && window._cpRichSelRange && window._cpRichSelEditor
        && document.contains(window._cpRichSelEditor)
        && !window._cpRichSelRange.collapsed
        && window._cpRichSelEditor.contains(window._cpRichSelRange.commonAncestorContainer)) {
        editor = window._cpRichSelEditor;
        savedRange = window._cpRichSelRange.cloneRange();
    }
    // Sinon : éditeur par défaut du contexte (aucune sélection → coloration de tout le texte).
    if (!editor) {
        editor = (ctx === 'canvas') ? cpActiveCanvasEditor : document.getElementById('cpTextEditor');
    }
    if (!editor) return;

    // Fonction de synchronisation adaptée à l'éditeur réellement ciblé.
    var syncFn;
    if (editor.classList && editor.classList.contains('cp-editable-text')) {
        syncFn = cpSyncCanvasTextToData;
    } else {
        syncFn = function () { cpUpdateTextContentLive(); };
    }

    _cpColorState = { editor: editor, savedRange: savedRange, syncFn: syncFn, kind: kind, ctx: ctx };

    cpBuildColorPopup(btn, kind, ctx);
}

function cpBuildColorPopup(btn, kind, ctx) {
    var popup = document.createElement('div');
    popup.className = 'cp-color-popup';
    popup._kind = kind;
    popup._ctx = ctx;

    var grid = document.createElement('div');
    grid.className = 'cp-color-grid';
    CP_TEXT_COLORS.forEach(function (col) {
        var sw = document.createElement('button');
        sw.type = 'button';
        sw.className = 'cp-color-swatch';
        sw.style.background = col.c;
        sw.title = col.n;
        sw.setAttribute('aria-label', col.n);
        // onmousedown + preventDefault => ne fait pas perdre la sélection du texte
        sw.onmousedown = function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            cpApplyPickedColor(col.c);
        };
        grid.appendChild(sw);
    });
    popup.appendChild(grid);

    // Couleur personnalisée (sélecteur natif)
    var custom = document.createElement('label');
    custom.className = 'cp-color-custom';
    var input = document.createElement('input');
    input.type = 'color';
    input.className = 'cp-color-custom-input';
    input.value = (kind === 'fore' ? cpLastTextColor : cpLastHiliteColor);
    // On laisse le sélecteur natif s'ouvrir (pas de preventDefault), mais on évite la
    // fermeture par clic extérieur et on neutralise les timers de masquage de la barre.
    input.onmousedown = function (ev) {
        ev.stopPropagation();
        clearTimeout(window._ftHideTimer);
        clearTimeout(window._textBlurTimer);
    };
    input.onchange = function (ev) {
        ev.stopPropagation();
        cpApplyPickedColor(input.value);
    };
    var txt = document.createElement('span');
    txt.textContent = 'Couleur personnalisée';
    custom.appendChild(input);
    custom.appendChild(txt);
    popup.appendChild(custom);

    // Bouton « Retirer » (enlève la couleur / le surlignage de la sélection ou de tout le texte)
    var clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'cp-color-clear';
    clearBtn.textContent = (kind === 'fore') ? '✕ Retirer la couleur' : '✕ Retirer le surlignage';
    clearBtn.onmousedown = function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        cpRemovePickedColor();
    };
    popup.appendChild(clearBtn);

    // Positionner sous le bouton (en coordonnées écran, body en position:fixed)
    var rect = btn.getBoundingClientRect();
    popup.style.position = 'fixed';
    popup.style.top = (rect.bottom + 6) + 'px';
    popup.style.left = '0px';
    document.body.appendChild(popup);
    var pw = popup.offsetWidth || 180;
    popup.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - pw - 8)) + 'px';

    _cpColorPopup = popup;
    // Fermer au clic extérieur (différé pour ne pas capter le clic d'ouverture)
    setTimeout(function () { document.addEventListener('mousedown', _cpColorOutsideClick); }, 10);
}

function _cpColorOutsideClick(ev) {
    if (_cpColorPopup && !_cpColorPopup.contains(ev.target) &&
        !(ev.target.closest && ev.target.closest('.cp-color-btn'))) {
        cpCloseColorPicker();
    }
}

function cpCloseColorPicker() {
    if (_cpColorPopup) { _cpColorPopup.remove(); _cpColorPopup = null; }
    _cpColorState = null;
    document.removeEventListener('mousedown', _cpColorOutsideClick);
}

function cpApplyPickedColor(color) {
    var st = _cpColorState;
    if (!st || !st.editor) { cpCloseColorPicker(); return; }
    var editor = st.editor;

    // Le sélecteur natif a pu retirer contenteditable via le timer de blur du canvas :
    // on le rétablit pour que execCommand fonctionne.
    editor.setAttribute('contenteditable', 'true');
    editor.focus();

    var sel = window.getSelection();
    if (st.savedRange) {
        try { sel.removeAllRanges(); sel.addRange(st.savedRange); } catch (e) {}
    }
    // On n'applique à TOUT le contenu (comme gras/italique) QUE s'il n'y avait aucune
    // sélection au départ. Une sélection partielle (st.savedRange) doit être respectée.
    if (!st.savedRange && !sel.toString().length) {
        var r = document.createRange();
        r.selectNodeContents(editor);
        sel.removeAllRanges();
        sel.addRange(r);
    }

    // styleWithCSS => couleurs en <span style="color/background-color"> (compatible H5P/Éléa)
    document.execCommand('styleWithCSS', false, true);
    if (st.kind === 'fore') {
        document.execCommand('foreColor', false, color);
        cpLastTextColor = color;
    } else {
        if (!document.execCommand('hiliteColor', false, color)) {
            document.execCommand('backColor', false, color);
        }
        cpLastHiliteColor = color;
    }
    document.execCommand('styleWithCSS', false, false);

    // Quand le texte est en gras/italique, le navigateur pose le surlignage sur le
    // <strong>/<em>. Or le marqueur CSS cible les <span>, et le filtre H5P d'Éléa
    // supprime le style des tags non-stylables → surlignage invisible et perdu à l'export.
    // On déplace donc le style vers un <span> englobant.
    editor.innerHTML = cpMoveStylesToSpan(editor.innerHTML);

    // Surlignage : on conserve les couleurs de texte existantes, sauf si le contraste
    // avec le fond est insuffisant pour bien lire (alors noir/blanc lisible). Le texte
    // sans couleur hérite d'une couleur lisible via le span de fond.
    if (st.kind === 'hilite') {
        cpEnsureReadableOnBg(editor, color);
    }

    if (st.syncFn) st.syncFn(editor);
    if (typeof onCourseModified === 'function') onCourseModified();
    cpRefreshColorIcons();
    cpCloseColorPicker();
}

// Retirer la couleur de texte ou le surlignage sur la sélection (ou tout le texte si aucune).
function cpRemovePickedColor() {
    var st = _cpColorState;
    if (!st || !st.editor) { cpCloseColorPicker(); return; }
    var editor = st.editor;

    editor.setAttribute('contenteditable', 'true');
    editor.focus();

    var sel = window.getSelection();
    if (st.savedRange) {
        try { sel.removeAllRanges(); sel.addRange(st.savedRange); } catch (e) {}
    }
    // Aucune sélection au départ => retirer sur tout le contenu
    if (!st.savedRange && !sel.toString().length) {
        var r = document.createRange();
        r.selectNodeContents(editor);
        sel.removeAllRanges();
        sel.addRange(r);
    }

    cpClearColorOnSelection(editor, st.kind);

    // Renormaliser (déplacement de styles, fusion) puis nettoyer les <span> vidés de tout style
    editor.innerHTML = cpMoveStylesToSpan(editor.innerHTML);
    var tmp = document.createElement('div');
    tmp.innerHTML = editor.innerHTML;
    cpUnwrapEmptySpans(tmp);
    editor.innerHTML = tmp.innerHTML;

    if (st.syncFn) st.syncFn(editor);
    if (typeof onCourseModified === 'function') onCourseModified();
    cpCloseColorPicker();
}

// Sur la sélection courante, retire la propriété color (kind='fore') ou background-color
// (kind='hilite'). Astuce : on applique une couleur "sentinelle" via execCommand pour que
// le navigateur découpe proprement les spans aux bornes de la sélection, puis on retire la
// propriété sur les seuls spans marqués par la sentinelle.
function cpClearColorOnSelection(editor, kind) {
    var SENTINEL = '#010102';               // couleur improbable, uniquement pour marquer
    var sentRgb = cpParseRgb(SENTINEL);
    var domProp = (kind === 'fore') ? 'color' : 'backgroundColor';

    document.execCommand('styleWithCSS', false, true);
    if (kind === 'fore') {
        document.execCommand('foreColor', false, SENTINEL);
    } else if (!document.execCommand('hiliteColor', false, SENTINEL)) {
        document.execCommand('backColor', false, SENTINEL);
    }
    document.execCommand('styleWithCSS', false, false);

    // Après cet appel, les spans de la sélection portent la sentinelle : on retire la propriété.
    var styled = editor.querySelectorAll('[style]');
    for (var i = 0; i < styled.length; i++) {
        var el = styled[i];
        var cur = cpParseRgb(el.style[domProp]);
        if (cur && sentRgb && cur.r === sentRgb.r && cur.g === sentRgb.g && cur.b === sentRgb.b) {
            el.style[domProp] = '';
            // Nettoyer une éventuelle couleur de texte "lisibilité" devenue inutile si on
            // retire le surlignage (le span n'a plus de fond).
            if (kind === 'hilite' && el.style.backgroundColor === '' && el.style.color) {
                // conserver la couleur de texte choisie par l'utilisateur : on ne touche pas.
            }
            if (!el.getAttribute('style')) el.removeAttribute('style');
        }
    }
}

// Remplace les <span> sans aucun attribut (style retiré) par leur contenu, récursivement.
function cpUnwrapEmptySpans(root) {
    var spans = root.querySelectorAll('span');
    for (var i = spans.length - 1; i >= 0; i--) {   // du plus profond vers le haut
        var s = spans[i];
        if (s.attributes.length === 0 && s.parentNode) {
            while (s.firstChild) s.parentNode.insertBefore(s.firstChild, s);
            s.parentNode.removeChild(s);
        }
    }
}

// Met à jour l'indicateur de couleur des boutons sans re-rendre toute la barre
function cpRefreshColorIcons() {
    document.querySelectorAll('.cp-color-btn[data-kind="fore"] .cp-ci-bar').forEach(function (el) { el.style.background = cpLastTextColor; });
    document.querySelectorAll('.cp-color-btn[data-kind="hilite"] .cp-ci-hl').forEach(function (el) { el.style.background = cpLastHiliteColor; });
}

// Renvoie '#ffffff' ou '#000000' selon la luminance d'une couleur de fond (#rrggbb / #rgb),
// pour garder un texte lisible (formule YIQ, seuil 128).
function cpReadableTextColor(bg) {
    var c = (bg || '').replace('#', '');
    if (c.length === 3) c = c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
    if (c.length !== 6) return '#000000';
    var r = parseInt(c.substr(0, 2), 16);
    var g = parseInt(c.substr(2, 2), 16);
    var b = parseInt(c.substr(4, 2), 16);
    var yiq = (r * 299 + g * 587 + b * 114) / 1000;
    return yiq >= 128 ? '#000000' : '#ffffff';
}

// Parse '#rgb' / '#rrggbb' / 'rgb(...)' / 'rgba(...)' -> {r,g,b} ou null
function cpParseRgb(str) {
    if (!str) return null;
    str = String(str).trim();
    var m = str.match(/^#([0-9a-f]{3})$/i);
    if (m) { var h = m[1]; return { r: parseInt(h[0] + h[0], 16), g: parseInt(h[1] + h[1], 16), b: parseInt(h[2] + h[2], 16) }; }
    m = str.match(/^#([0-9a-f]{6})$/i);
    if (m) { return { r: parseInt(m[1].substr(0, 2), 16), g: parseInt(m[1].substr(2, 2), 16), b: parseInt(m[1].substr(4, 2), 16) }; }
    m = str.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
    if (m) { return { r: +m[1], g: +m[2], b: +m[3] }; }
    return null;
}

// Ratio de contraste WCAG entre deux couleurs {r,g,b}
function cpContrastRatio(rgb1, rgb2) {
    function lum(c) {
        var a = [c.r, c.g, c.b].map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    }
    var L1 = lum(rgb1), L2 = lum(rgb2);
    var hi = Math.max(L1, L2), lo = Math.min(L1, L2);
    return (hi + 0.05) / (lo + 0.05);
}

// Seuil en dessous duquel une couleur de texte est jugée illisible sur le fond.
var CP_MIN_CONTRAST = 3.0;

// Sur le surlignage qu'on vient d'appliquer (bgHex), garder les couleurs de texte
// existantes lisibles et ne corriger (noir/blanc) que celles dont le contraste est
// insuffisant. Le texte sans couleur explicite hérite d'une couleur lisible.
function cpEnsureReadableOnBg(root, bgHex) {
    var bg = cpParseRgb(bgHex);
    if (!root || !bg) return;
    var fallback = cpReadableTextColor(bgHex);
    var spans = root.querySelectorAll('span[style*="background-color"]');
    for (var i = 0; i < spans.length; i++) {
        var span = spans[i];
        var sbg = cpParseRgb(span.style.backgroundColor);
        if (!sbg || sbg.r !== bg.r || sbg.g !== bg.g || sbg.b !== bg.b) continue; // seulement le surlignage courant
        // Texte sans couleur explicite : hérite d'une couleur lisible
        span.style.color = fallback;
        // Descendants ayant une couleur de texte : garder si lisible, sinon corriger
        var colored = span.querySelectorAll('[style*="color"]');
        for (var j = 0; j < colored.length; j++) {
            var el = colored[j];
            var col = el.style.color;
            if (!col) continue; // background-color seul
            var c = cpParseRgb(col);
            if (c && cpContrastRatio(c, bg) < CP_MIN_CONTRAST) {
                el.style.color = fallback;
            }
        }
    }
}

// Déplace l'attribut style des tags inline non "styleables" par H5P (strong, em, u...)
// vers un <span> englobant : <strong style="X">…</strong> -> <span style="X"><strong>…</strong></span>.
// Indispensable car le marqueur CSS cible les <span> et le filtre H5P d'Éléa supprime
// le style sur ces tags (surlignage posé sur du gras sinon perdu).
function cpMoveStylesToSpan(html) {
    if (!html || html.indexOf('style=') === -1) return html;
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    // Tags inline dont le filtre H5P d'Éléa supprime l'attribut style (non "styleables").
    var NS = { STRONG: 1, EM: 1, B: 1, I: 1, U: 1, S: 1, STRIKE: 1, DEL: 1, INS: 1, MARK: 1, SUB: 1, SUP: 1, SMALL: 1, ABBR: 1, CITE: 1, CODE: 1, FONT: 1, A: 1 };
    var styled = tmp.querySelectorAll('[style]');
    for (var i = 0; i < styled.length; i++) {
        var node = styled[i];
        if (NS[node.tagName]) {
            var span = document.createElement('span');
            span.setAttribute('style', node.getAttribute('style'));
            node.removeAttribute('style');
            node.parentNode.insertBefore(span, node);
            span.appendChild(node);
        }
    }
    // Fusionner les surlignages adjacents de même couleur (sinon coupure visuelle entre
    // deux mots de couleurs de texte différentes).
    cpMergeAdjacentBgSpans(tmp);
    return tmp.innerHTML;
}

// Deux spans de surlignage côte à côte (même couleur de fond), éventuellement séparés
// par un espace, sont fusionnés en un seul → surlignage continu (un seul marqueur).
function cpSameBg(el, bg) {
    var c = cpParseRgb(el.style && el.style.backgroundColor);
    return !!c && c.r === bg.r && c.g === bg.g && c.b === bg.b;
}
function cpMergeAdjacentBgSpans(root) {
    var spans = root.querySelectorAll('span[style*="background-color"]');
    for (var i = 0; i < spans.length; i++) {
        var span = spans[i];
        if (!span.parentNode) continue; // déjà fusionné
        var bg = cpParseRgb(span.style.backgroundColor);
        if (!bg) continue;
        var next = span.nextSibling;
        while (next) {
            // espace entre deux surlignages de même couleur : l'absorber
            if (next.nodeType === 3 && /^\s*$/.test(next.nodeValue)) {
                var after = next.nextSibling;
                if (after && after.nodeType === 1 && after.tagName === 'SPAN' && cpSameBg(after, bg)) {
                    span.appendChild(next);
                    while (after.firstChild) span.appendChild(after.firstChild);
                    after.parentNode.removeChild(after);
                    next = span.nextSibling;
                    continue;
                }
                break;
            }
            // surlignage adjacent direct de même couleur
            if (next.nodeType === 1 && next.tagName === 'SPAN' && cpSameBg(next, bg)) {
                var rem = next;
                while (rem.firstChild) span.appendChild(rem.firstChild);
                next = rem.nextSibling;
                rem.parentNode.removeChild(rem);
                continue;
            }
            break;
        }
    }
}

// Tailles Éléa disponibles (em)
var CP_FONT_SIZES = ['1em', '1.25em', '1.5em', '1.75em', '2.25em', '3em'];

// Éléa/Moodle affiche H5P CoursePresentation avec font-size:16px (base H5P).
// Dans l'éditeur, le canvas est zoomé avec transform:scale(zoom/100),
// ce qui réduit visuellement la taille du texte.
// On compense en divisant par le facteur de zoom pour que la taille
// VISUELLE du texte corresponde exactement à Éléa.
var CP_ELEA_FONT_BASE = 18.5; // taille de base Éléa en px (calibré pour correspondre à Éléa)
var CP_REF_WIDTH = 1400; // largeur interne fixe du canvas éditeur
var CP_REF_HEIGHT = 700;

function cpUpdateBaseFontSize() {
    var inner = document.getElementById('cpCanvasInner');
    if (!inner) return;
    var zoom = cpZoomLevel / 100;
    if (zoom <= 0) zoom = 0.7;
    var fontSize = CP_ELEA_FONT_BASE / zoom;
    inner.style.fontSize = fontSize.toFixed(2) + 'px';
}

// Applique une taille fixe au canvas et un transform combiné (responsive + zoom)
// Tout le contenu (texte, images, positions) scale uniformément via transform
function cpUpdateCanvasTransform() {
    var canvas = document.getElementById('cpCanvas');
    if (!canvas) return;
    var wrapper = canvas.closest('.cp-canvas-wrapper');
    if (!wrapper) return;
    
    var wrapperW = wrapper.clientWidth;
    var responsiveScale = Math.min(wrapperW / CP_REF_WIDTH, 1);
    var totalScale = responsiveScale * (cpZoomLevel / 100);
    
    // Canvas fixe en taille interne, positionné en absolu pour ne pas déborder le wrapper
    canvas.style.width = CP_REF_WIDTH + 'px';
    canvas.style.height = CP_REF_HEIGHT + 'px';
    canvas.style.position = 'absolute';
    canvas.style.left = '50%';
    canvas.style.top = '50%';
    canvas.style.marginLeft = -(CP_REF_WIDTH / 2) + 'px';
    canvas.style.marginTop = -(CP_REF_HEIGHT / 2) + 'px';
    canvas.style.transform = 'scale(' + totalScale + ')';
    canvas.style.transformOrigin = 'center center';
    wrapper.style.overflow = 'visible';
    
    cpUpdateBaseFontSize();
}

// Observer pour recalculer le scale quand le wrapper change de taille
var _cpResizeObserver = null;
function cpSetupResizeObserver() {
    if (_cpResizeObserver) _cpResizeObserver.disconnect();
    var wrapper = document.querySelector('.cp-canvas-wrapper');
    if (!wrapper || typeof ResizeObserver === 'undefined') return;
    _cpResizeObserver = new ResizeObserver(function() {
        cpUpdateCanvasTransform();
    });
    _cpResizeObserver.observe(wrapper);
}

// Applique une taille de police à un contenteditable
function cpApplyFontSize(editor, sizeEm) {
    if (!editor || !sizeEm) return;
    
    editor.focus();
    var sel = window.getSelection();
    var hasSelection = sel && sel.toString().length > 0;
    
    if (!hasSelection) {
        var range = document.createRange();
        range.selectNodeContents(editor);
        sel.removeAllRanges();
        sel.addRange(range);
    }
    
    // Technique: utiliser execCommand fontSize comme marqueur, puis remplacer les <font> par <span>
    document.execCommand('fontSize', false, '1');
    
    // Remplacer tous les <font size="1"> créés par des <span style="font-size:Xem">
    var fonts = editor.querySelectorAll('font[size="1"]');
    fonts.forEach(function(font) {
        var span = document.createElement('span');
        span.style.fontSize = sizeEm;
        span.innerHTML = font.innerHTML;
        font.parentNode.replaceChild(span, font);
    });
    
    // Aussi nettoyer les anciennes <font size="N"> qui pourraient traîner
    editor.querySelectorAll('font[size]').forEach(function(font) {
        var span = document.createElement('span');
        // Convertir l'ancien format en em
        var oldSize = parseInt(font.getAttribute('size'));
        if (oldSize && !font.style.fontSize) {
            var emMap = {1:'0.75em', 2:'0.85em', 3:'1em', 4:'1.25em', 5:'1.5em', 6:'2.25em', 7:'3em'};
            span.style.fontSize = emMap[oldSize] || '1em';
        }
        span.innerHTML = font.innerHTML;
        font.parentNode.replaceChild(span, font);
    });
}

// Détecte la taille em actuelle du texte sous le curseur
function cpDetectFontSize(editor) {
    if (!editor) return '1.5em';
    
    var sel = window.getSelection();
    if (sel.rangeCount > 0 && sel.anchorNode) {
        var checkEl = sel.anchorNode.nodeType === 3 ? sel.anchorNode.parentElement : sel.anchorNode;
        while (checkEl && checkEl !== editor) {
            if (checkEl.style && checkEl.style.fontSize) {
                return checkEl.style.fontSize;
            }
            checkEl = checkEl.parentElement;
        }
    }
    
    // Fallback: chercher le premier span avec font-size
    var span = editor.querySelector('[style*="font-size"]');
    if (span) {
        var match = span.style.fontSize;
        if (match) return match;
    }
    
    return '1.5em'; // défaut
}

function cpFormatFontSize(size) {
    if (!size) return;
    
    var editor = document.getElementById('cpTextEditor');
    if (!editor) return;
    
    // Si on édite sur le canvas, rediriger
    if (cpActiveCanvasEditor) {
        editor = cpActiveCanvasEditor;
        editor.focus();
        if (window._canvasSelRange) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(window._canvasSelRange);
        }
    }
    
    cpApplyFontSize(editor, size);
    editor.focus();
    
    // Sync
    if (editor === cpActiveCanvasEditor) {
        cpSyncCanvasTextToData(editor);
    } else {
        cpUpdateTextContentLive();
    }
}

function cpInsertLink() {
    // Détecter si le curseur/sélection contient ou touche un lien existant
    var existingUrl = 'https://';
    var foundAnchor = null;
    var sel = window.getSelection();
    if (sel && sel.rangeCount > 0) {
        var range = sel.getRangeAt(0);
        
        // Stratégie 1: chercher un <a> dans les parents de focusNode ou anchorNode
        var nodes = [sel.focusNode, sel.anchorNode];
        for (var n = 0; n < nodes.length && !foundAnchor; n++) {
            var el = nodes[n];
            if (el && el.nodeType === 3) el = el.parentElement;
            while (el && el.tagName !== 'A' && !el.classList?.contains('cp-editable-text') && !el.classList?.contains('rich-text-editor')) {
                el = el.parentElement;
            }
            if (el && el.tagName === 'A') foundAnchor = el;
        }
        
        // Stratégie 2: chercher un <a> dans le contenu sélectionné
        if (!foundAnchor) {
            var container = range.commonAncestorContainer;
            if (container.nodeType === 3) container = container.parentElement;
            if (container) {
                var links = container.tagName === 'A' ? [container] : container.querySelectorAll('a');
                for (var i = 0; i < links.length; i++) {
                    if (range.intersectsNode(links[i])) {
                        foundAnchor = links[i];
                        break;
                    }
                }
            }
        }
        
        if (foundAnchor) {
            existingUrl = foundAnchor.getAttribute('href') || foundAnchor.href;
            // Sélectionner tout le lien pour permettre la modification
            var linkRange = document.createRange();
            linkRange.selectNodeContents(foundAnchor);
            sel.removeAllRanges();
            sel.addRange(linkRange);
        }
    }
    
    var url = prompt("Entrez l'URL du lien:", existingUrl);
    if (url === null) return; // Annulé
    
    if (url === '') {
        // Supprimer le lien
        document.execCommand('unlink', false, null);
    } else {
        // S'assurer que l'URL a un protocole
        if (!/^https?:\/\//i.test(url)) {
            url = 'https://' + url;
        }
        document.execCommand('createLink', false, url);
        // Ajouter target="_blank" au lien créé
        if (sel && sel.rangeCount > 0) {
            var anchor = sel.focusNode;
            var aEl = anchor && anchor.nodeType === 3 ? anchor.parentElement : anchor;
            while (aEl && aEl.tagName !== 'A') aEl = aEl.parentElement;
            if (aEl && aEl.tagName === 'A') aEl.target = '_blank';
        }
    }
    
    // Trigger input pour sauvegarder
    var editor = document.querySelector('.cp-editable-text[contenteditable="true"]') || document.getElementById('cpTextEditor');
    if (editor) editor.dispatchEvent(new Event('input', { bubbles: true }));
}

var _cpInsertTableSavedRange = null;

function cpInsertTable() {
    // Sauvegarder la sélection avant que le dialogue prenne le focus
    const editor = document.getElementById('cpTextEditor');
    _cpInsertTableSavedRange = null;
    if (editor) {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            const range = sel.getRangeAt(0);
            if (editor === range.commonAncestorContainer || editor.contains(range.commonAncestorContainer)) {
                _cpInsertTableSavedRange = range.cloneRange();
            }
        }
        if (!_cpInsertTableSavedRange) {
            // Pas de sélection active : mettre le curseur à la fin
            const range = document.createRange();
            range.selectNodeContents(editor);
            range.collapse(false);
            _cpInsertTableSavedRange = range;
        }
    }

    const dialog = document.createElement('div');
    dialog.className = 'cp-table-dialog-overlay';
    dialog.innerHTML = `
        <div class="cp-table-dialog">
            <h3>Insérer un tableau dans le texte</h3>
            <div class="cp-table-dialog-row">
                <label>Lignes<input type="number" id="cpItRows" value="3" min="1" max="20" style="margin-top:4px;"></label>
                <label>Colonnes<input type="number" id="cpItCols" value="3" min="1" max="10" style="margin-top:4px;"></label>
            </div>
            <div class="cp-table-dialog-row">
                <label class="checkbox-row" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cpItBorders" checked> Bordures
                </label>
            </div>
            <div class="cp-table-dialog-actions">
                <button onclick="this.closest('.cp-table-dialog-overlay').remove()">Annuler</button>
                <button class="btn-primary" onclick="cpInsertTableConfirm()">Insérer</button>
            </div>
        </div>`;
    document.body.appendChild(dialog);
    document.getElementById('cpItRows').focus();
}

function cpInsertTableConfirm() {
    const rows = parseInt(document.getElementById('cpItRows')?.value) || 3;
    const cols = parseInt(document.getElementById('cpItCols')?.value) || 3;
    const borders = document.getElementById('cpItBorders')?.checked ?? true;
    document.querySelector('.cp-table-dialog-overlay')?.remove();

    const editor = document.getElementById('cpTextEditor');
    if (!editor) return;

    const tableHtml = cpBuildTableHtml(rows, cols, borders);

    // Restaurer le focus et la position du curseur avant d'insérer
    editor.focus();
    if (_cpInsertTableSavedRange) {
        const sel = window.getSelection();
        if (sel) {
            sel.removeAllRanges();
            sel.addRange(_cpInsertTableSavedRange);
        }
        _cpInsertTableSavedRange = null;
    }
    document.execCommand('insertHTML', false, tableHtml);
}

// Drag du panneau de propriétés
let cpPanelDrag = null;

function cpStartDragPanel(event) {
    if (event.target.classList.contains('cp-props-close')) return;
    
    const panel = document.getElementById('cpPropsPanel');
    if (!panel) return;
    
    const rect = panel.getBoundingClientRect();
    cpPanelDrag = {
        offsetX: event.clientX - rect.left,
        offsetY: event.clientY - rect.top
    };
    
    document.addEventListener('mousemove', cpOnDragPanel);
    document.addEventListener('mouseup', cpStopDragPanel);
    event.preventDefault();
}

function cpOnDragPanel(event) {
    if (!cpPanelDrag) return;
    
    const panel = document.getElementById('cpPropsPanel');
    if (!panel) return;
    
    let newX = event.clientX - cpPanelDrag.offsetX;
    let newY = event.clientY - cpPanelDrag.offsetY;
    
    // Limiter aux bords de la fenêtre
    newX = Math.max(0, Math.min(window.innerWidth - panel.offsetWidth, newX));
    newY = Math.max(0, Math.min(window.innerHeight - panel.offsetHeight, newY));
    
    panel.style.left = newX + 'px';
    panel.style.top = newY + 'px';
    panel.style.right = 'auto';
}

function cpStopDragPanel() {
    cpPanelDrag = null;
    document.removeEventListener('mousemove', cpOnDragPanel);
    document.removeEventListener('mouseup', cpStopDragPanel);
}

// Double-clic sur image : ouvre le sélecteur de fichier
function cpBrowseImage(event) {
    event.stopPropagation();
    
    // Chercher l'input file dans le panneau de propriétés
    const propPanel = document.getElementById('cpPropertiesPanel');
    if (propPanel) {
        const fileInput = propPanel.querySelector('input[type="file"][accept="image/*"]');
        if (fileInput) {
            fileInput.click();
            return;
        }
    }
    
    // Fallback : créer un input file temporaire
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function() {
        cpUploadImage(this);
    };
    input.click();
}

function cpUploadImage(input) {
    const file = input.files[0];
    if (!file) return;
    
    // Vérifier la limite de poids du cours
    if (typeof canAddImage === 'function' && !canAddImage(file)) {
        input.value = '';
        return;
    }
    
    showToast('Upload en cours...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    
    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => {
                throw new Error('Erreur HTTP ' + r.status + ': ' + (text || 'Réponse vide'));
            });
        }
        return r.text();
    })
    .then(text => {
        if (!text || text.trim() === '') {
            throw new Error('Réponse vide du serveur');
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Réponse non-JSON:', text);
            throw new Error('Réponse invalide: ' + text.substring(0, 200));
        }
    })
    .then(data => {
        if (data.success) {
            // Charger l'image pour obtenir ses dimensions
            const img = new Image();
            img.onload = function() {
                // Ratio du canvas H5P (2:1)
                const canvasRatio = 2; // Canvas H5P Course Presentation = 2:1
                const imgRatio = img.naturalWidth / img.naturalHeight;
                
                // Obtenir l'élément sélectionné
                const activity = getSelectedActivity();
                const slide = activity.content.presentation.slides[cpCurrentSlide];
                const element = slide.elements[cpSelectedElement];
                
                // Calculer la nouvelle taille en gardant le ratio de l'image
                // Taille max: 80% de largeur ou 80% de hauteur
                let newWidth, newHeight;
                
                if (imgRatio > canvasRatio) {
                    // Image plus large que le canvas (limiter par la largeur)
                    newWidth = Math.min(80, element.width || 30);
                    newHeight = newWidth / imgRatio * canvasRatio;
                } else {
                    // Image plus haute que le canvas (limiter par la hauteur)
                    newHeight = Math.min(80, element.height || 20);
                    newWidth = newHeight * imgRatio / canvasRatio;
                }
                
                // S'assurer que les dimensions restent raisonnables
                newWidth = Math.max(10, Math.min(90, newWidth));
                newHeight = Math.max(10, Math.min(90, newHeight));
                
                // Mettre à jour l'élément
                element.width = newWidth;
                element.height = newHeight;
                element.action.params.file = { path: data.url };
                
                cpRenderSlideElements();
                cpRenderElementProps();
                showToast('Image uploadée', 'success');
                onCourseModified();
            };
            img.onerror = function() {
                // En cas d'erreur de chargement, utiliser quand même l'URL
                cpUpdateNestedProp('action.params.file.path', data.url);
                cpRenderSlideElements();
                showToast('Image uploadée', 'success');
            };
            img.src = data.url;
        } else {
            throw new Error(data.error || JSON.stringify(data));
        }
    })
    .catch(err => {
        console.error('Erreur upload:', err);
        showToast('Erreur: ' + err.message, 'error');
    });
}

// ==================== EMOJI ELEMENT PICKER ====================

function cpToggleEmojiPicker(btn) {
    const dropdown = btn.nextElementSibling;
    const isOpen = dropdown.style.display !== 'none';
    // Fermer tous les dropdowns
    document.querySelectorAll('.cp-emoji-picker-dropdown, .cp-shape-dropdown-menu').forEach(d => d.style.display = 'none');
    if (isOpen) return;
    
    // Peupler si vide
    const grid = dropdown.querySelector('div');
    if (grid.children.length === 0 && typeof cpEmojiImages !== 'undefined') {
        cpEmojiImages.forEach(f => {
            const item = document.createElement('div');
            item.style.cssText = 'cursor:pointer; padding:4px; border-radius:4px; display:flex; align-items:center; justify-content:center; transition: background 0.15s;';
            item.onmouseenter = () => item.style.background = 'var(--gray-100)';
            item.onmouseleave = () => item.style.background = '';
            item.onclick = () => { cpAddEmojiElement(f); dropdown.style.display = 'none'; };
            item.innerHTML = `<img src="assets/emojis_png/${f}" style="width:36px; height:36px; object-fit:contain;" title="${f.replace(/\.\w+$/, '')}">`;
            grid.appendChild(item);
        });
    }
    dropdown.style.display = 'block';
    
    // Fermer au clic extérieur
    setTimeout(() => {
        const handler = (e) => {
            if (!dropdown.contains(e.target) && e.target !== btn) {
                dropdown.style.display = 'none';
                document.removeEventListener('click', handler);
            }
        };
        document.addEventListener('click', handler);
    }, 0);
}

function cpAddEmojiElement(filename) {
    // Ajoute un élément image emoji sur la slide, comme cpSelectTemplateImage mais crée l'élément
    const activity = getSelectedActivity();
    if (!activity) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    
    // Copier l'emoji vers cache/editor_uploads via l'API
    showToast('Ajout de l\'emoji...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'copy_image_to_uploads');
    formData.append('source_type', 'emoji');
    formData.append('source', filename);
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            showToast(data.error || 'Erreur', 'error');
            return;
        }
        const serverPath = data.url || data.path;
        
        // Créer l'élément image
        const element = {
            x: 40, y: 35,
            width: 10, height: 20, // carré sur un canvas 2:1
            action: {
                library: 'H5P.Image 1.1',
                params: {
                    file: { path: serverPath },
                    decorative: false,
                    contentName: 'Image',
                    expandImage: 'Expand Image',
                    minimizeImage: 'Minimize Image'
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Image', license: 'U', title: 'Emoji', authors: [], changes: [] }
            },
            alwaysDisplayComments: false,
            backgroundOpacity: 0,
            displayAsButton: false,
            buttonSize: 'big',
            goToSlideType: 'specified',
            invisible: false,
            solution: ''
        };
        
        slide.elements.push(element);
        cpSelectedElement = slide.elements.length - 1;
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
        showToast('Emoji ajouté', 'success');
    })
    .catch(err => showToast('Erreur: ' + err.message, 'error'));
}

// ==================== TEMPLATE IMAGES ====================

function cpSelectTemplateImage(filename) {
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element) return;
    
    showToast('Chargement de l\'image...', 'info');
    
    // Copier l'image template vers cache/editor_uploads via l'API
    const formData = new FormData();
    formData.append('action', 'copy_image_to_uploads');
    formData.append('source_type', 'template');
    formData.append('source', filename);
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            showToast(data.error || 'Erreur', 'error');
            return;
        }
        
        const serverPath = data.url || data.path;
        
        // Charger l'image pour obtenir ses dimensions
        const img = new Image();
        img.onload = function() {
            const canvasRatio = 2; // Canvas H5P Course Presentation = 2:1
            const imgRatio = img.naturalWidth / img.naturalHeight;
            let newWidth, newHeight;
            if (imgRatio > canvasRatio) {
                newWidth = Math.min(80, element.width || 30);
                newHeight = newWidth / imgRatio * canvasRatio;
            } else {
                newHeight = Math.min(80, element.height || 20);
                newWidth = newHeight * imgRatio / canvasRatio;
            }
            newWidth = Math.max(10, Math.min(90, newWidth));
            newHeight = Math.max(10, Math.min(90, newHeight));
            
            element.width = newWidth;
            element.height = newHeight;
            element.action.params.file = { path: serverPath };
            
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
            showToast('Image chargée', 'success');
        };
        img.onerror = function() {
            element.action.params.file = { path: serverPath };
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
        };
        img.src = serverPath;
    })
    .catch(err => {
        showToast('Erreur: ' + err.message, 'error');
    });
}

// ==================== EMOJI PICKER ====================

const CP_EMOJIS = ['👉','👇','👆','👈','👍','👏','😀','😁','🤯','😱','😂','😇','🥰','😍','☺️','🤗','🤓','🧐','😲','😡','🔎','✅','❌','⚠️','⏱️','💡','🌍','🎉'];

// Génère un bouton émoji compact. targetId = id de l'élément cible
function cpEmojiBarHtml(targetId) {
    return `<button type="button" class="cp-emoji-toggle-btn" onclick="cpTogglePropsEmojiPicker(this, '${targetId}')" title="Insérer un émoji">😀</button>`;
}

// Popup emoji partagé pour le panneau propriétés
var _cpPropsEmojiPopup = null;
var _cpPropsEmojiTarget = null;

function cpTogglePropsEmojiPicker(btn, targetId) {
    // Si déjà ouvert pour ce bouton, fermer
    if (_cpPropsEmojiPopup && _cpPropsEmojiPopup._btn === btn) {
        cpClosePropsEmojiPicker();
        return;
    }
    cpClosePropsEmojiPicker();
    
    _cpPropsEmojiTarget = targetId;
    
    // Sauvegarder la sélection de l'éditeur cible
    var sel = window.getSelection();
    window._propsEmojiSelRange = (sel.rangeCount > 0) ? sel.getRangeAt(0).cloneRange() : null;
    
    var popup = document.createElement('div');
    popup.className = 'cp-props-emoji-popup';
    CP_EMOJIS.forEach(function(e) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'cp-props-emoji-item';
        item.textContent = e;
        item.onclick = function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            cpPropsInsertEmoji(e);
        };
        popup.appendChild(item);
    });
    
    // Positionner sous le bouton
    var rect = btn.getBoundingClientRect();
    var propsPanel = btn.closest('.cp-props-scroll, .cp-inter-dialog, .cp-prop-group')?.closest('.cp-properties-panel, .cp-inter-dialog') || document.body;
    popup.style.position = 'fixed';
    popup.style.left = Math.min(rect.left, window.innerWidth - 250) + 'px';
    popup.style.top = (rect.bottom + 4) + 'px';
    popup._btn = btn;
    
    document.body.appendChild(popup);
    _cpPropsEmojiPopup = popup;
    
    // Fermer au clic extérieur (delayed pour ne pas capter le clic courant)
    setTimeout(function() {
        document.addEventListener('mousedown', _cpPropsEmojiOutsideClick);
    }, 10);
}

function _cpPropsEmojiOutsideClick(ev) {
    if (_cpPropsEmojiPopup && !_cpPropsEmojiPopup.contains(ev.target) && !ev.target.classList.contains('cp-emoji-toggle-btn')) {
        cpClosePropsEmojiPicker();
    }
}

function cpClosePropsEmojiPicker() {
    if (_cpPropsEmojiPopup) {
        _cpPropsEmojiPopup.remove();
        _cpPropsEmojiPopup = null;
    }
    _cpPropsEmojiTarget = null;
    document.removeEventListener('mousedown', _cpPropsEmojiOutsideClick);
}

function cpPropsInsertEmoji(emoji) {
    var targetId = _cpPropsEmojiTarget;
    var savedRange = window._propsEmojiSelRange;
    cpClosePropsEmojiPicker();
    if (!targetId) return;
    
    // Résoudre _dynamic_
    if (targetId === '_dynamic_' && window._lastEmojiTarget) {
        targetId = window._lastEmojiTarget;
    }
    
    // Cas spécial : édition texte canvas via le panneau latéral
    if (targetId === 'cpTextEditor' && cpActiveCanvasEditor) {
        cpActiveCanvasEditor.focus();
        if (window._canvasSelRange) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(window._canvasSelRange);
        }
        document.execCommand('insertText', false, emoji);
        cpSyncCanvasTextToData(cpActiveCanvasEditor);
        onCourseModified();
        return;
    }
    
    var el = document.getElementById(targetId);
    if (!el) return;
    
    if (el.contentEditable === 'true') {
        el.focus();
        // Restaurer la sélection sauvegardée après focus
        if (savedRange) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
        }
        document.execCommand('insertText', false, emoji);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    } else {
        // Input/Textarea : utiliser cpInsertEmoji standard
        cpInsertEmoji(emoji, targetId);
    }
}

// Insère un emoji dans un élément cible (contenteditable, textarea, ou input)
function cpInsertEmoji(emoji, targetId) {
    // Support dynamic targeting via last focused element
    if (targetId === '_dynamic_' && window._lastEmojiTarget) {
        targetId = window._lastEmojiTarget;
    }
    
    // Si on édite un texte sur le canvas et que la cible est le panneau latéral texte,
    // rediriger vers l'éditeur canvas pour insérer au curseur
    if (targetId === 'cpTextEditor' && cpActiveCanvasEditor) {
        cpActiveCanvasEditor.focus();
        // Restaurer la sélection sauvegardée du canvas
        if (window._canvasSelRange) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(window._canvasSelRange);
        }
        document.execCommand('insertText', false, emoji);
        cpSyncCanvasTextToData(cpActiveCanvasEditor);
        onCourseModified();
        return;
    }
    
    const el = document.getElementById(targetId);
    if (!el) return;
    
    if (el.contentEditable === 'true') {
        // Contenteditable : focus et insérer au curseur
        el.focus();
        const sel = window.getSelection();
        if (sel.rangeCount === 0) {
            // Pas de sélection, ajouter à la fin
            el.innerHTML += emoji;
        } else {
            // Insérer au curseur
            document.execCommand('insertText', false, emoji);
        }
        // Déclencher l'événement input
        el.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type === 'text')) {
        // Input/Textarea : insérer à la position du curseur
        const start = el.selectionStart || 0;
        const end = el.selectionEnd || 0;
        const val = el.value;
        el.value = val.substring(0, start) + emoji + val.substring(end);
        el.selectionStart = el.selectionEnd = start + emoji.length;
        el.focus();
        // Déclencher l'événement change
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function cpSetImageRotation(angle) {
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element) return;
    
    element.rotation = angle;
    
    // Update visual immediately
    const elDiv = document.querySelector('.cp-element[data-idx="' + cpSelectedElement + '"]');
    if (elDiv) {
        elDiv.style.transform = angle ? 'rotate(' + angle + 'deg)' : '';
    }
    
    // Update slider and value display
    const valSpan = document.getElementById('cpImageRotationVal');
    if (valSpan) valSpan.textContent = angle;
    const slider = document.querySelector('input[type="range"][oninput*="cpSetImageRotation"]');
    if (slider) slider.value = angle;
    
    onCourseModified();
}

// Coller une URL d'image depuis le presse-papier (bouton ou Ctrl+V dans l'input)
function cpPasteImageUrlFromClipboard() {
    if (navigator.clipboard && navigator.clipboard.readText) {
        navigator.clipboard.readText().then(function(text) {
            text = (text || '').trim();
            if (!text) {
                showToast('Presse-papier vide', 'info');
                return;
            }
            var input = document.getElementById('cpImageUrlInput');
            if (input) input.value = text;
            cpUpdateImageUrl(text);
        }).catch(function(err) {
            console.warn('Clipboard readText failed:', err);
            showToast('Impossible de lire le presse-papier', 'error');
        });
    } else {
        showToast('API Clipboard non disponible', 'error');
    }
}

function cpUpdateImageUrl(url) {
    if (!url) {
        cpUpdateNestedProp('action.params.file.path', '');
        return;
    }
    
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element) return;
    
    // Si c'est une URL http(s), essayer de la télécharger côté serveur
    if (url.match(/^https?:\/\//i)) {
        showToast('Téléchargement de l\'image...', 'info');
        
        const formData = new FormData();
        formData.append('action', 'copy_image_to_uploads');
        formData.append('source_type', 'url');
        formData.append('source', url);
        
        fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const serverPath = data.url || data.path;
                cpApplyImagePath(element, serverPath);
                showToast('Image téléchargée et copiée', 'success');
            } else {
                // Téléchargement serveur échoué: utiliser l'URL directe
                // L'éditeur peut l'afficher via le navigateur
                // L'export MBZ la téléchargera plus tard
                console.warn('Server download failed, using URL directly:', data.error);
                cpApplyImagePath(element, url);
                showToast('Image chargée depuis l\'URL', 'info');
            }
        })
        .catch(err => {
            // Erreur réseau/API: utiliser l'URL directe
            console.warn('API error, using URL directly:', err);
            cpApplyImagePath(element, url);
            showToast('Image chargée depuis l\'URL', 'info');
        });
    } else {
        // Chemin local direct
        cpApplyImagePath(element, url);
    }
}

// Applique un chemin d'image à l'élément sélectionné et recalcule les dimensions
function cpApplyImagePath(element, path) {
    const img = new Image();
    img.onload = function() {
        const canvasRatio = 2; // Canvas H5P Course Presentation = 2:1
        const imgRatio = img.naturalWidth / img.naturalHeight;
        let newWidth, newHeight;
        if (imgRatio > canvasRatio) {
            newWidth = Math.min(80, element.width || 30);
            newHeight = newWidth / imgRatio * canvasRatio;
        } else {
            newHeight = Math.min(80, element.height || 20);
            newWidth = newHeight * imgRatio / canvasRatio;
        }
        newWidth = Math.max(10, Math.min(90, newWidth));
        newHeight = Math.max(10, Math.min(90, newHeight));
        
        element.width = newWidth;
        element.height = newHeight;
        element.action.params.file = { path: path };
        
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
    };
    img.onerror = function() {
        // Store the path even if image fails to load for measurement
        // The <img> tag in the editor might still display it
        element.action.params.file = { path: path };
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
    };
    img.src = path;
}

function cpUpdateMcAnswer(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.answers) element.action.params.answers = [];
    if (!element.action.params.answers[idx]) {
        element.action.params.answers[idx] = {
            text: '',
            correct: false,
            tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' }
        };
    }
    
    // Si on met à jour le texte, l'encadrer avec <div> si pas déjà HTML
    if (prop === 'text' && value && !value.startsWith('<')) {
        value = '<div>' + value + '</div>';
    }
    
    element.action.params.answers[idx][prop] = value;
    
    // S'assurer que tipsAndFeedback existe
    if (!element.action.params.answers[idx].tipsAndFeedback) {
        element.action.params.answers[idx].tipsAndFeedback = { tip: '', chosenFeedback: '', notChosenFeedback: '' };
    }
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpAddMcAnswer() {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.answers) element.action.params.answers = [];
    element.action.params.answers.push({ 
        text: '<div>Nouvelle réponse</div>', 
        correct: false,
        tipsAndFeedback: { tip: '', chosenFeedback: '', notChosenFeedback: '' }
    });
    
    cpRenderElementProps();
    onCourseModified();
}

function cpDeleteMcAnswer(idx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.answers.splice(idx, 1);
    cpRenderElementProps();
    cpRenderSlideElements();
    onCourseModified();
}

function cpToggleMcFeedback(idx) {
    var row = document.getElementById('cpMcFeedback_' + idx);
    if (!row) return;
    var isVisible = row.style.display !== 'none';
    row.style.display = isVisible ? 'none' : 'flex';
    
    // Si on ferme et que le champ est vide, pas de changement
    // Si on ouvre, focus sur le champ
    if (!isVisible) {
        var input = row.querySelector('input');
        if (input) input.focus();
    }
    
    // Mettre à jour l'icône toggle
    var btn = row.previousElementSibling.querySelector('.quiz-feedback-toggle');
    if (btn) {
        var hasContent = false;
        var input = row.querySelector('input');
        if (input && input.value.trim()) hasContent = true;
        btn.classList.toggle('active', !isVisible || hasContent);
    }
}

function cpUpdateMcFeedback(idx, value) {
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.answers[idx]) return;
    if (!element.action.params.answers[idx].tipsAndFeedback) {
        element.action.params.answers[idx].tipsAndFeedback = { tip: '', chosenFeedback: '', notChosenFeedback: '' };
    }
    
    element.action.params.answers[idx].tipsAndFeedback.chosenFeedback = value ? '<div>' + value + '</div>' : '';
    
    // Mettre à jour l'icône toggle
    var btn = document.querySelector('#cpMcFeedback_' + idx)?.previousElementSibling?.querySelector('.quiz-feedback-toggle');
    if (btn) btn.classList.toggle('active', !!value.trim());
    
    onCourseModified();
}

function cpUpdateTfFeedback(prop, value) {
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.behaviour) {
        element.action.params.behaviour = {
            enableRetry: true,
            enableSolutionsButton: true,
            enableCheckButton: true,
            confirmCheckDialog: false,
            confirmRetryDialog: false,
            autoCheck: false,
            feedbackOnCorrect: '',
            feedbackOnWrong: ''
        };
    }
    element.action.params.behaviour[prop] = value;
    onCourseModified();
}

function cpUpdateBlanksTitle(title) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // Formater en HTML si nécessaire
    let htmlTitle = title.trim() || 'Texte à trous';
    if (!htmlTitle.startsWith('<p>')) {
        htmlTitle = '<p>' + htmlTitle + '</p>';
    }
    
    element.action.params.text = htmlTitle;
    
    cpRenderSlideElements();
    onCourseModified();
}

// ==================== BLANKS TITLE RICH TEXT ====================
function cpBlanksTitleExecCmd(command) {
    const editor = document.getElementById('cpBlanksTitleEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    cpUpdateBlanksTitleLive();
}

function cpBlanksTitleFontSize(size) {
    if (!size) return;
    var editor = document.getElementById('cpBlanksTitleEditor');
    if (!editor) return;
    cpApplyFontSize(editor, size);
    editor.focus();
    cpUpdateBlanksTitleLive();
}

function cpUpdateBlanksTitleLive() {
    const editor = document.getElementById('cpBlanksTitleEditor');
    if (!editor) return;
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    element.action.params.text = _cpFixLinksTarget(editor.innerHTML);
    
    // Mettre à jour le titre sur le canvas en temps réel
    const canvasTitle = document.querySelector('.cp-element[data-idx="' + cpSelectedElement + '"] .cp-quiz-blanks-title');
    if (canvasTitle) {
        canvasTitle.innerHTML = editor.innerHTML;
    }
}

function cpUpdateBlanksTitleFromEditor() {
    const editor = document.getElementById('cpBlanksTitleEditor');
    if (!editor) return;
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    let html = editor.innerHTML.trim();
    if (!html || html === '<br>') html = '<p>Texte à trous</p>';
    element.action.params.text = html;
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpUpdateBlanksQuestions(text) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // Séparer les lignes en questions multiples
    const lines = text.split('\n').filter(l => l.trim());
    const questions = lines.map(line => {
        let htmlText = line.trim();
        if (!htmlText.startsWith('<p>')) {
            htmlText = '<p>' + htmlText + '</p>';
        }
        return htmlText;
    });
    
    element.action.params.questions = questions.length > 0 ? questions : ['<p>Complétez le mot *manquant*.</p>'];
    
    cpRenderSlideElements();
    onCourseModified();
}

// ==================== BLANKS RICH TEXT ====================
function cpBlanksExecCmd(command) {
    const editor = document.getElementById('cpBlanksEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    cpOnBlanksRichTextInput();
}

function cpOnBlanksRichTextInput() {
    const editor = document.getElementById('cpBlanksEditor');
    if (!editor) return;
    const activity = getSelectedActivity();
    if (!activity) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    const element = slide.elements[cpSelectedElement];
    if (!element) return;
    
    // Séparer par <hr> (séparateur de questions)
    let html = editor.innerHTML;
    // Convertir les balises du navigateur vers le format H5P/Éléa
    html = html.replace(/<b>(.*?)<\/b>/gi, '<strong>$1</strong>');
    html = html.replace(/<b\s/gi, '<strong ').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i>(.*?)<\/i>/gi, '<em>$1</em>');
    html = html.replace(/<i\s/gi, '<em ').replace(/<\/i>/gi, '</em>');
    
    const parts = html.split(/<hr[^>]*>/gi).map(p => {
        let cleaned = p.trim();
        if (!cleaned) return '';
        // S'assurer que c'est enveloppé dans un <p> si nécessaire
        if (!cleaned.startsWith('<p') && !cleaned.startsWith('<div')) {
            cleaned = '<p>' + cleaned + '</p>';
        }
        return cleaned;
    }).filter(p => p);
    
    element.action.params.questions = parts.length > 0 ? parts : ['<p>Complétez le mot *manquant*.</p>'];
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpUpdateBlanksText(text) {
    // Fonction de compatibilité - appelle les deux nouvelles fonctions
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // Séparer les lignes en questions multiples
    const lines = text.split('\n').filter(l => l.trim());
    const questions = lines.map(line => {
        let htmlText = line.trim();
        if (!htmlText.startsWith('<p>')) {
            htmlText = '<p>' + htmlText + '</p>';
        }
        return htmlText;
    });
    
    element.action.params.questions = questions.length > 0 ? questions : ['<p>Complétez le mot *manquant*.</p>'];
    
    cpRenderSlideElements();
    onCourseModified();
}

// === Fonctions pour les options behaviour des quiz ===

function cpUpdateQuizBehaviour(prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.behaviour) {
        element.action.params.behaviour = {};
    }
    element.action.params.behaviour[prop] = value;
    
    onCourseModified();
}

// === Fonctions pour le formatage texte dans les quiz ===

function cpFormatQuizText(command) {
    const editor = document.getElementById('cpQuizQuestionEditor');
    if (!editor) return;
    
    // Vérifier si du texte est sélectionné
    const selection = window.getSelection();
    const hasSelection = selection && selection.toString().length > 0;
    
    // Si aucune sélection, sélectionner tout le contenu
    if (!hasSelection) {
        const range = document.createRange();
        range.selectNodeContents(editor);
        selection.removeAllRanges();
        selection.addRange(range);
    }
    
    document.execCommand(command, false, null);
    editor.focus();
    
    // Mettre à jour le contenu
    cpUpdateQuizQuestionLive();
}

function cpFormatQuizFontSize(size) {
    if (!size) return;
    var editor = document.getElementById('cpQuizQuestionEditor');
    if (!editor) return;
    cpApplyFontSize(editor, size);
    editor.focus();
    cpUpdateQuizQuestionLive();
}

function cpUpdateQuizQuestionLive() {
    // Mise à jour en temps réel (optionnel)
}

function cpUpdateQuizQuestion() {
    const editor = document.getElementById('cpQuizQuestionEditor');
    if (!editor) return;
    
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // Nettoyer les &nbsp; en fin de texte (le navigateur en ajoute dans contenteditable)
    var html = editor.innerHTML;
    html = html.replace(/(&nbsp;|\u00A0)+\s*(<\/)/g, '$2');
    html = html.replace(/(&nbsp;|\u00A0)+\s*$/g, '');
    element.action.params.question = html;
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpFormatBlanksText(command) {
    // Pour blanks, on utilise un textarea donc pas de formatage riche
    // On pourrait convertir en contenteditable si besoin
}

// === Fonctions pour Vrai/Faux (SingleChoiceSet) ===

function cpAddTfQuestion() {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // Convertir en format SingleChoiceSet si nécessaire
    if (!element.action.params.choices) {
        element.action.params.choices = [];
        // Convertir ancien format si présent
        if (element.action.params.question) {
            const correct = element.action.params.correct === 'true' || element.action.params.correct === true;
            element.action.params.choices.push({
                subContentId: generateUUID(),
                question: element.action.params.question,
                answers: correct ? ['<p>Vrai</p>', '<p>Faux</p>'] : ['<p>Faux</p>', '<p>Vrai</p>']
            });
        }
        // Changer la bibliothèque
        element.action.library = 'H5P.SingleChoiceSet 1.11';
        element.action.metadata = {
            contentType: 'Single Choice Set',
            license: 'U',
            title: 'Sans titre Single Choice Set',
            authors: [],
            changes: [],
            extraTitle: 'Sans titre Single Choice Set'
        };
    }
    
    element.action.params.choices.push({
        subContentId: generateUUID(),
        question: '<p>Nouvelle question ?</p>',
        answers: ['<p>Vrai</p>', '<p>Faux</p>']
    });
    
    cpRenderElementProps();
    cpRenderSlideElements();
    onCourseModified();
}

function cpDeleteTfQuestion(qIdx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (element.action.params.choices && element.action.params.choices.length > 1) {
        element.action.params.choices.splice(qIdx, 1);
        cpRenderElementProps();
        cpRenderSlideElements();
        onCourseModified();
    }
}

function cpUpdateTfQuestion(qIdx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // S'assurer que choices existe
    if (!element.action.params.choices) {
        element.action.params.choices = [];
    }
    if (!element.action.params.choices[qIdx]) {
        element.action.params.choices[qIdx] = {
            subContentId: generateUUID(),
            question: '',
            answers: ['<p>Vrai</p>', '<p>Faux</p>']
        };
    }
    
    if (prop === 'question') {
        let htmlValue = value;
        if (!htmlValue.startsWith('<')) {
            htmlValue = '<p>' + htmlValue + '</p>';
        }
        element.action.params.choices[qIdx].question = htmlValue;
    }
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpUpdateTfAnswer(qIdx, aIdx, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.choices || !element.action.params.choices[qIdx]) return;
    
    let htmlValue = value;
    if (!htmlValue.startsWith('<')) {
        htmlValue = '<p>' + htmlValue + '</p>';
    }
    
    element.action.params.choices[qIdx].answers[aIdx] = htmlValue;
    
    cpRenderSlideElements();
    onCourseModified();
}

function cpUpdateVideoUrl(url) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (!element.action.params) element.action.params = {};
    if (!element.action.params.interactiveVideo) {
        element.action.params.interactiveVideo = { 
            video: { files: [] }, 
            assets: { interactions: [] }
        };
    }
    if (!element.action.params.interactiveVideo.video.files[0]) {
        element.action.params.interactiveVideo.video.files[0] = {};
    }
    
    element.action.params.interactiveVideo.video.files[0].path = url;
    element.action.params.interactiveVideo.video.files[0].mime = 'video/mp4';
    
    // Charger la vidéo pour obtenir ses dimensions
    if (url) {
        const video = document.createElement('video');
        video.onloadedmetadata = function() {
            const canvasRatio = 2; // Canvas H5P Course Presentation = 2:1
            const videoRatio = video.videoWidth / video.videoHeight;
            
            let newWidth, newHeight;
            if (videoRatio > canvasRatio) {
                newWidth = Math.min(70, element.width || 60);
                newHeight = newWidth / videoRatio * canvasRatio;
            } else {
                newHeight = Math.min(70, element.height || 50);
                newWidth = newHeight * videoRatio / canvasRatio;
            }
            
            newWidth = Math.max(20, Math.min(90, newWidth));
            newHeight = Math.max(20, Math.min(90, newHeight));
            
            element.width = newWidth;
            element.height = newHeight;
            
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
        };
        video.onerror = function() {
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
        };
        video.src = url;
    } else {
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
    }
}

function cpUploadVideo(input) {
    const file = input.files[0];
    if (!file) return;
    
    // Vérifier la limite de poids du cours
    if (typeof canAddVideo === 'function' && !canAddVideo(file)) {
        input.value = '';
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    
    showToast('Upload en cours...', 'info');
    
    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => {
                throw new Error('Erreur HTTP ' + r.status + ': ' + (text || 'Réponse vide'));
            });
        }
        return r.text();
    })
    .then(text => {
        if (!text || text.trim() === '') {
            throw new Error('Réponse vide du serveur');
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Réponse invalide: ' + text.substring(0, 200));
        }
    })
    .then(data => {
        if (data.success) {
            cpUpdateVideoUrl(data.url);
            showToast('Vidéo uploadée', 'success');
        } else {
            throw new Error(data.error || JSON.stringify(data));
        }
    })
    .catch(err => {
        console.error('Erreur upload vidéo:', err);
        showToast('Erreur: ' + err.message, 'error');
    });
}

// ==================== AUDIO (H5P.Audio) ====================
//
// LECTEUR PARTAGÉ UNIQUE. Les boutons audio du canvas ne contiennent AUCUN <audio> :
// toute la lecture passe par ce seul élément, réutilisé pour chaque source. Un cours
// peut contenir des dizaines d'audios répartis sur de nombreux parcours ; avec un <audio>
// par élément, le navigateur créait un WebMediaPlayer par lecteur et finissait par
// atteindre sa limite (~75) → « certains audios, puis tous les suivants, ne démarrent plus ».
// Un lecteur unique borne le nombre de WebMediaPlayers à 1, quelle que soit la taille du cours.
var cpSharedAudio = null;      // l'unique élément <audio> de l'éditeur
var cpAudioActiveBtn = null;   // le bouton .cp-audio-play-btn qui pilote la lecture en cours

function cpGetSharedAudio() {
    if (!cpSharedAudio) {
        cpSharedAudio = document.createElement('audio');
        cpSharedAudio.preload = 'none';
        // Quand la lecture s'arrête (fin ou pause), remettre le bouton actif sur l'icône ▶
        var reset = function() { cpAudioSetBtnIcon(cpAudioActiveBtn, false); };
        cpSharedAudio.addEventListener('pause', reset);
        cpSharedAudio.addEventListener('ended', reset);
        cpSharedAudio.addEventListener('play', function() { cpAudioSetBtnIcon(cpAudioActiveBtn, true); });
    }
    return cpSharedAudio;
}

// Bascule les icônes ▶ / ⏸ d'un bouton audio (tolère un bouton null ou détaché du DOM)
function cpAudioSetBtnIcon(btn, playing) {
    if (!btn) return;
    var icPlay = btn.querySelector('.cp-audio-ic-play');
    var icPause = btn.querySelector('.cp-audio-ic-pause');
    if (icPlay) icPlay.style.display = playing ? 'none' : 'block';
    if (icPause) icPause.style.display = playing ? 'block' : 'none';
}

// Coupe la lecture du lecteur partagé (appelé avant chaque re-rendu du canvas : l'ancien
// bouton va être détruit, on évite une lecture « fantôme » sans bouton associé).
function cpStopSharedAudio() {
    if (cpSharedAudio && !cpSharedAudio.paused) cpSharedAudio.pause();
    cpAudioActiveBtn = null;
}

// Clic sur le bouton audio du canvas : lecture / pause via le lecteur partagé
function cpToggleAudioPlay(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    var btn = event.currentTarget;
    var src = btn.getAttribute('data-audio-src');
    if (!src) return;
    var audio = cpGetSharedAudio();

    // Ce bouton pilote déjà la lecture en cours → bascule pause/reprise
    if (cpAudioActiveBtn === btn) {
        if (audio.paused) { audio.play().catch(function(){}); } else { audio.pause(); }
        return;
    }

    // Nouvelle source : réinitialiser l'ancien bouton, repointer le lecteur, jouer
    cpAudioSetBtnIcon(cpAudioActiveBtn, false);
    cpAudioActiveBtn = btn;
    // Comparer en absolu (btn.src peut être relatif) pour ne recharger que si nécessaire
    var abs = new URL(src, document.baseURI).href;
    if (audio.src !== abs) audio.src = src;
    audio.currentTime = 0;
    audio.play().catch(function(){});
}

// Après un re-rendu du canvas : le bouton actif a été détruit. Si le lecteur partagé joue
// encore, retrouver dans le nouveau canvas le bouton qui porte la même source et le ré-adopter
// (icône ⏸). Si cette source n'est plus affichée (changement de slide/parcours), on coupe.
function cpResyncAudioButton() {
    if (!cpSharedAudio || cpSharedAudio.paused) { cpAudioActiveBtn = null; return; }
    var canvas = document.getElementById('cpCanvasInner');
    var match = null;
    if (canvas) {
        canvas.querySelectorAll('.cp-audio-play-btn[data-audio-src]').forEach(function(b) {
            if (match) return;
            var abs = new URL(b.getAttribute('data-audio-src'), document.baseURI).href;
            if (abs === cpSharedAudio.src) match = b;
        });
    }
    if (match) {
        cpAudioActiveBtn = match;
        cpAudioSetBtnIcon(match, true);
    } else {
        cpSharedAudio.pause();      // la source jouée n'est plus visible → arrêt propre
        cpAudioActiveBtn = null;
    }
}

// Double-clic sur le bouton audio du canvas : ouvre le sélecteur de fichier
function cpBrowseAudio(event) {
    event.stopPropagation();
    const propPanel = document.getElementById('cpPropertiesPanel');
    if (propPanel) {
        const fileInput = propPanel.querySelector('input[type="file"][accept*="audio"]');
        if (fileInput) { fileInput.click(); return; }
    }
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'audio/*';
    input.onchange = function() { cpUploadAudio(this); };
    input.click();
}

// Enregistre l'URL de l'audio uploadé dans l'élément sélectionné
function cpUpdateAudioUrl(url) {
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element || !element.action) return;
    if (!element.action.params) element.action.params = {};
    element.action.params.files = [{ path: url, mime: 'audio/mpeg' }];
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Retire l'audio de l'élément sélectionné
function cpRemoveAudio() {
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element || !element.action || !element.action.params) return;
    element.action.params.files = [];
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

function cpUploadAudio(input) {
    const file = input.files[0];
    if (!file) return;

    // Vérifier la limite de poids du cours
    if (typeof canAddContent === 'function' && !canAddContent(file.size || 0)) {
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);

    showToast('Upload de l\'audio en cours...', 'info');

    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => {
                throw new Error('Erreur HTTP ' + r.status + ': ' + (text || 'Réponse vide'));
            });
        }
        return r.text();
    })
    .then(text => {
        if (!text || text.trim() === '') {
            throw new Error('Réponse vide du serveur');
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Réponse invalide: ' + text.substring(0, 200));
        }
    })
    .then(data => {
        if (data.success) {
            cpUpdateAudioUrl(data.url);
            showToast('Audio intégré au cours', 'success');
        } else {
            throw new Error(data.error || JSON.stringify(data));
        }
    })
    .catch(err => {
        console.error('Erreur upload audio:', err);
        showToast('Erreur: ' + err.message, 'error');
    });
}

// Formater un temps en mm:ss
function cpGetYouTubeId(url) {
    if (!url) return null;
    const m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|v\/)|youtu\.be\/)([\w-]{11})/);
    return m ? m[1] : null;
}

// ==================== YouTube IFrame API ====================
const cpYTPlayers = {};
let cpYTApiReady = false;
let cpYTApiLoading = false;

function cpLoadYTApi() {
    if (cpYTApiReady || cpYTApiLoading) return;
    cpYTApiLoading = true;
    const tag = document.createElement('script');
    tag.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(tag);
}

// Called by YT API when ready
window.onYouTubeIframeAPIReady = function() {
    cpYTApiReady = true;
    cpInitYTPlayers();
};

function cpInitYTPlayers() {
    if (!cpYTApiReady) return;
    document.querySelectorAll('[data-yt-id]').forEach(el => {
        const ytId = el.dataset.ytId;
        const elId = el.id;
        if (!elId || cpYTPlayers[elId]) return;
        
        cpYTPlayers[elId] = new YT.Player(elId, {
            videoId: ytId,
            playerVars: { controls: 0, modestbranding: 1, rel: 0, showinfo: 0, fs: 0, disablekb: 1 },
            events: {
                onReady: function(evt) {
                    const idx = elId.replace('cpCanvasVideo_', '');
                    const dur = evt.target.getDuration();
                    const durationEl = document.getElementById('cpVideoDuration_' + idx);
                    if (durationEl) durationEl.textContent = cpFormatTime(dur);
                    cpPositionTimelineMarkers(idx, dur);
                    
                    // Restore position if pending (after re-render)
                    if (window._cpYTRestore && window._cpYTRestore.key === elId) {
                        const r = window._cpYTRestore;
                        evt.target.seekTo(r.time, true);
                        if (r.playing) evt.target.playVideo();
                        cpUpdateInteractionMarkers(idx, r.time);
                        delete window._cpYTRestore;
                    } else {
                        cpUpdateInteractionMarkers(idx, 0);
                    }
                },
                onStateChange: function(evt) {
                    const idx = elId.replace('cpCanvasVideo_', '');
                    const wrapper = document.getElementById('cpVideoWrapper_' + idx);
                    if (!wrapper) return;
                    const playIcon = wrapper.querySelector('.cp-video-play-icon');
                    const pauseIcon = wrapper.querySelector('.cp-video-pause-icon');
                    if (evt.data === YT.PlayerState.PLAYING) {
                        if (playIcon) playIcon.style.display = 'none';
                        if (pauseIcon) pauseIcon.style.display = 'inline';
                        cpStartYTPolling(idx);
                    } else {
                        if (playIcon) playIcon.style.display = 'inline';
                        if (pauseIcon) pauseIcon.style.display = 'none';
                        cpStopYTPolling(idx);
                    }
                }
            }
        });
    });
}

const cpYTPollers = {};
function cpStartYTPolling(idx) {
    if (cpYTPollers[idx]) return;
    cpYTPollers[idx] = setInterval(() => {
        const player = cpYTPlayers['cpCanvasVideo_' + idx];
        if (!player || !player.getCurrentTime) return;
        const t = player.getCurrentTime();
        const d = player.getDuration();
        const timeEl = document.getElementById('cpVideoTime_' + idx);
        const progressEl = document.getElementById('cpVideoProgress_' + idx);
        if (timeEl) timeEl.textContent = cpFormatTime(t);
        if (progressEl && d) progressEl.value = (t / d) * 100;
        cpUpdateInteractionMarkers(idx, t);
    }, 250);
}
function cpStopYTPolling(idx) {
    if (cpYTPollers[idx]) { clearInterval(cpYTPollers[idx]); delete cpYTPollers[idx]; }
}

function cpIsYTPlayer(idx) {
    return !!cpYTPlayers['cpCanvasVideo_' + idx];
}

// Helper: save current video position (works for both HTML5 and YT)
function cpSaveVideoPosition(idx) {
    if (cpIsYTPlayer(idx)) {
        const player = cpYTPlayers['cpCanvasVideo_' + idx];
        return {
            time: (player && player.getCurrentTime) ? player.getCurrentTime() : 0,
            playing: (player && player.getPlayerState) ? player.getPlayerState() === YT.PlayerState.PLAYING : false,
            isYT: true
        };
    }
    const video = document.getElementById('cpCanvasVideo_' + idx);
    return { time: video ? video.currentTime : 0, playing: video ? !video.paused : false, isYT: false };
}

// Helper: restore video position after re-render
function cpRestoreVideoPosition(idx, saved) {
    if (saved.isYT) {
        window._cpYTRestore = { key: 'cpCanvasVideo_' + idx, time: saved.time, playing: saved.playing };
    } else {
        setTimeout(() => {
            const v = document.getElementById('cpCanvasVideo_' + idx);
            if (v) { v.currentTime = saved.time; if (saved.playing) v.play(); }
        }, 50);
    }
}

// Helper: get current time for any video type
function cpGetVideoTime(idx) {
    if (cpIsYTPlayer(idx)) {
        const player = cpYTPlayers['cpCanvasVideo_' + idx];
        return (player && player.getCurrentTime) ? player.getCurrentTime() : 0;
    }
    const video = document.getElementById('cpCanvasVideo_' + idx);
    return video ? video.currentTime : 0;
}

function cpFormatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins.toString().padStart(2, '0') + ':' + secs.toString().padStart(2, '0');
}

// Toggle play/pause de la vidéo sur le canvas
function cpToggleCanvasVideo(event, idx) {
    event.stopPropagation();
    
    const wrapper = document.getElementById('cpVideoWrapper_' + idx);
    if (!wrapper) return;
    const btn = wrapper.querySelector('.cp-video-ctrl-btn');
    const playIcon = btn?.querySelector('.cp-video-play-icon');
    const pauseIcon = btn?.querySelector('.cp-video-pause-icon');
    
    if (cpIsYTPlayer(idx)) {
        const player = cpYTPlayers['cpCanvasVideo_' + idx];
        if (!player || !player.getPlayerState) return;
        const state = player.getPlayerState();
        if (state === YT.PlayerState.PLAYING) {
            player.pauseVideo();
        } else {
            // Reprise après une pause d'interaction : avancer légèrement pour ne pas re-pauser aussitôt
            if (cpIvPaused[idx] && player.getCurrentTime && player.seekTo) {
                player.seekTo(player.getCurrentTime() + 0.2, true);
            }
            cpIvPaused[idx] = false;
            player.playVideo();
        }
    } else {
        const video = document.getElementById('cpCanvasVideo_' + idx);
        if (!video) return;
        if (video.paused) {
            // Reprise après une pause d'interaction : avancer légèrement pour ne pas re-pauser aussitôt
            if (cpIvPaused[idx]) {
                video.currentTime = Math.min(video.duration || video.currentTime + 0.2, video.currentTime + 0.2);
            }
            cpIvPaused[idx] = false;
            video.play();
            if (playIcon) playIcon.style.display = 'none';
            if (pauseIcon) pauseIcon.style.display = 'inline';
        } else {
            video.pause();
            if (playIcon) playIcon.style.display = 'inline';
            if (pauseIcon) pauseIcon.style.display = 'none';
        }
    }
}

// Double-clic sur la vidéo pour ouvrir le panneau d'édition des interactions
function cpOpenVideoInteractionsPanel(event, idx) {
    event.stopPropagation();
    event.preventDefault();
    
    // Sélectionner l'élément vidéo
    cpSelectedElement = idx;
    
    // Mettre à jour la sélection visuelle
    document.querySelectorAll('.cp-element').forEach(el => el.classList.remove('selected'));
    const elementDiv = document.querySelector('.cp-element[data-idx="' + idx + '"]');
    if (elementDiv) elementDiv.classList.add('selected');
    
    // Afficher le panneau de propriétés
    cpRenderElementProps();
    
    // Mettre la vidéo en pause pour faciliter l'édition
    if (cpIsYTPlayer(idx)) {
        const player = cpYTPlayers['cpCanvasVideo_' + idx];
        if (player && player.pauseVideo) player.pauseVideo();
    } else {
        const video = document.getElementById('cpCanvasVideo_' + idx);
        if (video && !video.paused) {
            video.pause();
            const btn = video.closest('.cp-video-preview-element').querySelector('.cp-video-ctrl-btn');
            const playIcon = btn?.querySelector('.cp-video-play-icon');
            const pauseIcon = btn?.querySelector('.cp-video-pause-icon');
            if (playIcon) playIcon.style.display = 'inline';
            if (pauseIcon) pauseIcon.style.display = 'none';
        }
    }
    
    // Scroller vers le panneau de propriétés
    const propsPanel = document.getElementById('cpElementProps');
    if (propsPanel) {
        propsPanel.scrollTop = 0;
        showToast('Double-clic: mode édition des interactions', 'info');
    }
}

// Naviguer dans la vidéo
function cpSeekCanvasVideo(idx, percent) {
    if (cpIsYTPlayer(idx)) {
        const player = cpYTPlayers['cpCanvasVideo_' + idx];
        if (!player || !player.getDuration) return;
        const dur = player.getDuration();
        const t = (percent / 100) * dur;
        player.seekTo(t, true);
        cpResetIvInteractionState(idx, t); // cale le temps de référence sur la cible
        cpUpdateInteractionMarkers(idx, t);
    } else {
        const video = document.getElementById('cpCanvasVideo_' + idx);
        if (!video || !video.duration) return;
        const t = (percent / 100) * video.duration;
        video.currentTime = t;
        cpResetIvInteractionState(idx, t); // cale le temps de référence sur la cible
        cpUpdateInteractionMarkers(idx, video.currentTime);
    }
}

// Initialiser les contrôles vidéo après le rendu
function cpInitVideoControls() {
    document.querySelectorAll('.cp-video-preview-element video').forEach(video => {
        const idx = video.id.replace('cpCanvasVideo_', '');
        
        // Éviter de ré-attacher les événements
        if (video.dataset.initialized) return;
        video.dataset.initialized = 'true';
        
        // Mettre à jour la durée et positionner les marqueurs une fois la vidéo chargée
        video.addEventListener('loadedmetadata', function() {
            const durationEl = document.getElementById('cpVideoDuration_' + idx);
            if (durationEl) durationEl.textContent = cpFormatTime(video.duration);
            
            // Positionner les marqueurs sur la timeline
            cpPositionTimelineMarkers(idx, video.duration);
            
            // Mettre à jour les interactions visibles
            cpUpdateInteractionMarkers(idx, video.currentTime);
        });
        
        // Mettre à jour le temps et la progress bar pendant la lecture
        video.addEventListener('timeupdate', function() {
            const timeEl = document.getElementById('cpVideoTime_' + idx);
            const progressEl = document.getElementById('cpVideoProgress_' + idx);
            const currentTimeDisplay = document.getElementById('cpCurrentTimeDisplay');
            
            if (timeEl) timeEl.textContent = cpFormatTime(video.currentTime);
            if (progressEl && video.duration) {
                progressEl.value = (video.currentTime / video.duration) * 100;
            }
            if (currentTimeDisplay && cpSelectedElement !== null) {
                currentTimeDisplay.textContent = cpFormatTime(video.currentTime);
            }
            
            // Mettre à jour la visibilité des interactions et l'état actif des marqueurs
            cpUpdateInteractionMarkers(idx, video.currentTime);
        });
        
        // Gérer la fin de la vidéo
        video.addEventListener('ended', function() {
            const btn = video.closest('.cp-video-preview-element').querySelector('.cp-video-ctrl-btn');
            const playIcon = btn?.querySelector('.cp-video-play-icon');
            const pauseIcon = btn?.querySelector('.cp-video-pause-icon');
            if (playIcon) playIcon.style.display = 'inline';
            if (pauseIcon) pauseIcon.style.display = 'none';
        });
        
        // Si la vidéo est déjà chargée (depuis le cache)
        if (video.readyState >= 1) {
            const durationEl = document.getElementById('cpVideoDuration_' + idx);
            if (durationEl && video.duration) durationEl.textContent = cpFormatTime(video.duration);
            cpPositionTimelineMarkers(idx, video.duration);
            cpUpdateInteractionMarkers(idx, video.currentTime);
        }
    });
    
    // Initialize YouTube players if any
    if (document.querySelector('[data-yt-id]')) {
        cpLoadYTApi();
        if (cpYTApiReady) cpInitYTPlayers();
    }
}

// Cleanup YT players when changing slides
function cpCleanupYTPlayers() {
    Object.keys(cpYTPollers).forEach(k => { clearInterval(cpYTPollers[k]); delete cpYTPollers[k]; });
    Object.keys(cpYTPlayers).forEach(k => {
        try { cpYTPlayers[k].destroy(); } catch(e) {}
        delete cpYTPlayers[k];
    });
}
function cpPositionTimelineMarkers(videoIdx, duration) {
    const container = document.getElementById('cpTimelineMarkers_' + videoIdx);
    if (!container || !duration) return;
    
    container.querySelectorAll('.cp-timeline-marker').forEach(marker => {
        const from = parseFloat(marker.dataset.from) || 0;
        const percent = (from / duration) * 100;
        marker.style.left = percent + '%';
    });
}

// État pour la mise en pause automatique aux interactions (option « Pause vidéo »)
// Clés = videoIdx + '_' + interIdx ; cpIvPaused / cpIvLastTime sont indexés par videoIdx
const cpIvSeenInteractions = {};
const cpIvPaused = {};
const cpIvLastTime = {};

// Réinitialiser l'état des interactions d'une vidéo (lors d'un seek : on doit pouvoir les revoir).
// `atTime` cale le temps de référence (pour ne pas déclencher de franchissement parasite sur un seek).
function cpResetIvInteractionState(videoIdx, atTime) {
    Object.keys(cpIvSeenInteractions).forEach(k => { if (k.indexOf(videoIdx + '_') === 0) delete cpIvSeenInteractions[k]; });
    cpIvPaused[videoIdx] = false;
    cpIvLastTime[videoIdx] = (atTime !== undefined) ? atTime : cpGetVideoTime(videoIdx);
}

// Mettre la vidéo (HTML5 ou YouTube) en pause au temps `at` + mettre à jour l'icône du bouton
function cpPauseVideoAt(videoIdx, at) {
    if (cpIsYTPlayer(videoIdx)) {
        const player = cpYTPlayers['cpCanvasVideo_' + videoIdx];
        if (player) {
            if (at !== null && player.seekTo) player.seekTo(at, true);
            if (player.pauseVideo) player.pauseVideo();
        }
    } else {
        const video = document.getElementById('cpCanvasVideo_' + videoIdx);
        if (video) {
            if (at !== null) video.currentTime = at;
            if (!video.paused) video.pause();
        }
    }
    const wrapper = document.querySelector('.cp-video-preview-element[data-video-idx="' + videoIdx + '"]');
    const btn = wrapper ? wrapper.querySelector('.cp-video-ctrl-btn') : null;
    if (btn) {
        const playIcon = btn.querySelector('.cp-video-play-icon');
        const pauseIcon = btn.querySelector('.cp-video-pause-icon');
        if (playIcon) playIcon.style.display = 'inline';
        if (pauseIcon) pauseIcon.style.display = 'none';
    }
}

function cpUpdateInteractionMarkers(videoIdx, currentTime) {
    const layer = document.getElementById('cpInteractionsLayer_' + videoIdx);
    const markersContainer = document.getElementById('cpTimelineMarkers_' + videoIdx);
    if (!layer) return;

    const lastTime = (cpIvLastTime[videoIdx] !== undefined) ? cpIvLastTime[videoIdx] : currentTime;

    // Mettre à jour les cartes d'interaction + gérer la pause auto
    let pauseAt = null;
    layer.querySelectorAll('.cp-video-interaction').forEach(card => {
        const from = parseFloat(card.dataset.from) || 0;
        const to = parseFloat(card.dataset.to) || 999999;
        const interIdx = card.dataset.idx;
        const doPause = card.dataset.pause === '1';
        const key = videoIdx + '_' + interIdx;

        // Pour les interactions avec durée 0 (from === to), ajouter une tolérance de 0.5s
        const effectiveTo = (to <= from) ? from + 0.5 : to;
        const inRange = currentTime >= from && currentTime <= effectiveTo;

        // Afficher si on est dans l'intervalle de temps (et pas forcé à être caché)
        if (inRange && !card.dataset.forceHidden) {
            card.classList.add('visible');
        } else if (!card.dataset.forceVisible) {
            card.classList.remove('visible');
        }

        // Déclenchement de la pause : on détecte le FRANCHISSEMENT de `from` entre deux ticks
        // de lecture (la fenêtre from→to peut être plus courte que l'intervalle entre deux ticks).
        // Sur un seek manuel, cpIvLastTime est calé sur la cible → pas de franchissement parasite.
        const reached = lastTime < from && currentTime >= from;
        if (doPause && reached && !cpIvSeenInteractions[key] && !cpIvPaused[videoIdx]) {
            cpIvSeenInteractions[key] = true;
            if (pauseAt === null || from < pauseAt) pauseAt = from;
        }
        // Permettre de re-déclencher l'interaction si on revient en arrière avant elle
        if (currentTime < from - 0.3) {
            cpIvSeenInteractions[key] = false;
        }
    });

    if (pauseAt !== null) {
        cpPauseVideoAt(videoIdx, pauseAt);
        cpIvPaused[videoIdx] = true;
        cpIvLastTime[videoIdx] = pauseAt;
    } else {
        cpIvLastTime[videoIdx] = currentTime;
    }

    // Mettre à jour l'état actif des marqueurs timeline
    if (markersContainer) {
        markersContainer.querySelectorAll('.cp-timeline-marker').forEach(marker => {
            const from = parseFloat(marker.dataset.from) || 0;
            const idx = marker.dataset.idx;
            const card = document.getElementById('cpInteraction_' + videoIdx + '_' + idx);
            const to = card ? (parseFloat(card.dataset.to) || 999999) : from;
            const effectiveTo = (to <= from) ? from + 0.5 : to;
            
            if (currentTime >= from && currentTime <= effectiveTo) {
                marker.classList.add('active');
            } else {
                marker.classList.remove('active');
            }
        });
    }
}

// Afficher une interaction au survol du marqueur timeline
function cpShowInteraction(videoIdx, interIdx) {
    const card = document.getElementById('cpInteraction_' + videoIdx + '_' + interIdx);
    if (card) {
        card.dataset.forceVisible = 'true';
        card.classList.add('visible');
    }
}

// Cacher une interaction quand on quitte le marqueur timeline
function cpHideInteraction(videoIdx, interIdx) {
    const card = document.getElementById('cpInteraction_' + videoIdx + '_' + interIdx);
    if (card) {
        delete card.dataset.forceVisible;
        // Vérifier si on doit la garder visible selon le temps
        const currentTime = cpGetVideoTime(videoIdx);
        const from = parseFloat(card.dataset.from) || 0;
        const to = parseFloat(card.dataset.to) || 999999;
        const effectiveTo = (to <= from) ? from + 0.5 : to;
        if (currentTime < from || currentTime > effectiveTo) {
            card.classList.remove('visible');
        }
    }
}

// Variables pour le drag des interactions
let cpDraggingInteraction = null;
let cpDragStartX = 0;
let cpDragStartY = 0;
let cpDragStartLeft = 0;
let cpDragStartTop = 0;

// Démarrer le drag d'une interaction
function cpStartDragInteraction(event, videoIdx, interIdx) {
    event.preventDefault();
    event.stopPropagation();
    
    const card = document.getElementById('cpInteraction_' + videoIdx + '_' + interIdx);
    if (!card) return;
    
    cpDraggingInteraction = { videoIdx, interIdx, card };
    cpDragStartX = event.clientX;
    cpDragStartY = event.clientY;
    cpDragStartLeft = parseFloat(card.style.left) || 50;
    cpDragStartTop = parseFloat(card.style.top) || 50;
    
    card.classList.add('dragging');
    card.dataset.forceVisible = 'true';
    
    document.addEventListener('mousemove', cpDragInteraction);
    document.addEventListener('mouseup', cpStopDragInteraction);
}

// Pendant le drag d'une interaction
function cpDragInteraction(event) {
    if (!cpDraggingInteraction) return;
    
    const { videoIdx, card } = cpDraggingInteraction;
    const layer = document.getElementById('cpInteractionsLayer_' + videoIdx);
    if (!layer) return;
    
    const rect = layer.getBoundingClientRect();
    const deltaX = event.clientX - cpDragStartX;
    const deltaY = event.clientY - cpDragStartY;
    
    // Convertir en pourcentage
    const deltaXPercent = (deltaX / rect.width) * 100;
    const deltaYPercent = (deltaY / rect.height) * 100;
    
    let newLeft = cpDragStartLeft + deltaXPercent;
    let newTop = cpDragStartTop + deltaYPercent;

    // Limiter au cadre de la vidéo (liberté totale de 0 à 100 %)
    newLeft = Math.max(0, Math.min(100, newLeft));
    newTop = Math.max(0, Math.min(100, newTop));

    card.style.left = newLeft + '%';
    card.style.top = newTop + '%';

    // Synchroniser les champs Position du panneau si l'interaction est en cours d'édition
    const interIdx = cpDraggingInteraction.interIdx;
    const inX = document.getElementById('cpInterPosX_' + interIdx);
    const inY = document.getElementById('cpInterPosY_' + interIdx);
    if (inX) inX.value = Math.round(newLeft * 10) / 10;
    if (inY) inY.value = Math.round(newTop * 10) / 10;
}

// Arrêter le drag d'une interaction
function cpStopDragInteraction(event) {
    if (!cpDraggingInteraction) return;
    
    const { videoIdx, interIdx, card } = cpDraggingInteraction;
    card.classList.remove('dragging');
    delete card.dataset.forceVisible;
    
    // Sauvegarder la nouvelle position (attention : 0 est une valeur valide)
    let newX = parseFloat(card.style.left); if (isNaN(newX)) newX = 50;
    let newY = parseFloat(card.style.top);  if (isNaN(newY)) newY = 50;

    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element?.action?.params?.interactiveVideo?.assets?.interactions?.[interIdx];

    if (inter) {
        inter.x = newX;
        inter.y = newY;
        // Recaler les champs Position du panneau si l'interaction est en cours d'édition
        const inX = document.getElementById('cpInterPosX_' + interIdx);
        const inY = document.getElementById('cpInterPosY_' + interIdx);
        if (inX) inX.value = Math.round(newX * 10) / 10;
        if (inY) inY.value = Math.round(newY * 10) / 10;
        onCourseModified();
    }
    
    // Mettre à jour la visibilité selon le temps
    cpUpdateInteractionMarkers(videoIdx, cpGetVideoTime(videoIdx));
    
    document.removeEventListener('mousemove', cpDragInteraction);
    document.removeEventListener('mouseup', cpStopDragInteraction);
    cpDraggingInteraction = null;
}

// Positionner une interaction via les champs du panneau (Position X / Y en %)
function cpUpdateInteractionPosition(interIdx, axis, value) {
    if (cpSelectedElement === null || isNaN(value)) return;
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element?.action?.params?.interactiveVideo?.assets?.interactions?.[interIdx];
    if (!inter) return;

    value = Math.max(0, Math.min(100, value));
    inter[axis] = value;

    // Déplacer la carte sur le canvas sans tout re-rendre (préserve la lecture vidéo)
    const card = document.getElementById('cpInteraction_' + cpSelectedElement + '_' + interIdx);
    if (card) card.style[axis === 'x' ? 'left' : 'top'] = value + '%';

    // Recaler la valeur affichée (au cas où elle a été clampée)
    const input = document.getElementById('cpInterPos' + (axis === 'x' ? 'X' : 'Y') + '_' + interIdx);
    if (input) input.value = Math.round(value * 10) / 10;

    onCourseModified();
}

// Obtenir le temps actuel de la vidéo sélectionnée
function cpGetCurrentVideoTime() {
    if (cpSelectedElement === null) return 0;
    if (cpIsYTPlayer(cpSelectedElement)) {
        const player = cpYTPlayers['cpCanvasVideo_' + cpSelectedElement];
        return (player && player.getCurrentTime) ? player.getCurrentTime() : 0;
    }
    const video = document.getElementById('cpCanvasVideo_' + cpSelectedElement);
    return video ? video.currentTime : 0;
}

// Aller à une interaction (déplacer la vidéo au bon moment)
function cpGoToInteraction(interIdx) {
    if (cpSelectedElement === null) return;
    
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[interIdx];
    
    if (!inter || inter.duration?.from === undefined) return;

    const from = inter.duration.from;
    cpResetIvInteractionState(cpSelectedElement, from); // cale le temps de référence sur la cible
    if (cpIsYTPlayer(cpSelectedElement)) {
        const player = cpYTPlayers['cpCanvasVideo_' + cpSelectedElement];
        if (player && player.seekTo) player.seekTo(from, true);
        cpUpdateInteractionMarkers(cpSelectedElement, from);
    } else {
        const video = document.getElementById('cpCanvasVideo_' + cpSelectedElement);
        if (video) {
            video.currentTime = from;
        }
    }
}

// Ajouter une nouvelle interaction
function cpRenderIvOverrideOptions(element) {
    const override = element.action?.params?.override || {};
    const preventSkip = override.preventSkippingMode || 'none';
    const allowForward = (preventSkip === 'none');
    const deactivateSound = override.deactivateSound !== false;
    const showSolution = override.showSolutionButton || 'off';
    const hideSolution = (showSolution !== 'on');
    
    return `
        <div class="cp-prop-group" style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--gray-200);">
            <label class="cp-prop-label" style="font-size: 0.8rem; font-weight: 600; margin-bottom: 0.5rem;">Options de la vid\u00e9o</label>
            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; margin-bottom: 0.4rem; cursor: pointer;">
                <input type="checkbox" ${allowForward ? 'checked' : ''} 
                       onchange="cpUpdateIvOverride('preventSkippingMode', this.checked ? 'none' : 'both')">
                Autoriser la navigation en avant
            </label>
            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; margin-bottom: 0.4rem; cursor: pointer;">
                <input type="checkbox" ${deactivateSound ? 'checked' : ''} 
                       onchange="cpUpdateIvOverride('deactivateSound', this.checked)">
                D\u00e9sactiver le son
            </label>
            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; cursor: pointer;">
                <input type="checkbox" ${hideSolution ? 'checked' : ''} 
                       onchange="cpUpdateIvOverride('showSolutionButton', this.checked ? 'off' : 'on')">
                Cacher bouton &laquo; Voir la solution &raquo;
            </label>
        </div>`;
}

function cpUpdateIvOverride(prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element?.action?.params) return;
    if (!element.action.params.override) {
        element.action.params.override = {
            autoplay: false,
            loop: false,
            showBookmarksmenuOnLoad: false,
            showRewind10: false,
            preventSkippingMode: 'none',
            deactivateSound: true,
            showSolutionButton: 'off'
        };
    }
    element.action.params.override[prop] = value;
    onCourseModified();
}

function cpAddInteraction() {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (!element.action.params.interactiveVideo) {
        element.action.params.interactiveVideo = { video: { files: [] }, assets: { interactions: [] } };
    }
    if (!element.action.params.interactiveVideo.assets) {
        element.action.params.interactiveVideo.assets = { interactions: [] };
    }
    if (!element.action.params.interactiveVideo.assets.interactions) {
        element.action.params.interactiveVideo.assets.interactions = [];
    }
    
    const typeSelect = document.getElementById('cpNewInteractionType');
    const type = typeSelect ? typeSelect.value : 'text';
    
    // Utiliser le temps actuel de la vidéo sur le canvas
    const time = cpGetCurrentVideoTime();
    
    let newInteraction = {
        x: 45,
        y: 45,
        width: 10,
        height: 10,
        duration: {
            from: time,
            to: time  // Durée 0 = s'affiche au moment et disparaît quand la vidéo reprend
        },
        libraryTitle: 'Text',
        label: '',
        pause: true,
        displayType: 'poster',
        buttonOnMobile: false,
        visuals: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            boxShadow: true
        },
        goto: {
            url: { protocol: 'http://' },
            visualize: false,
            type: ''
        },
        action: {}
    };
    
    switch (type) {
        case 'text':
            newInteraction.action = {
                library: 'H5P.Text 1.1',
                params: {
                    text: '<p>Texte à afficher</p>'
                }
            };
            newInteraction.libraryTitle = 'Text';
            newInteraction.label = '';
            newInteraction.displayType = 'poster';
            break;
        case 'multichoice':
            newInteraction.action = {
                library: 'H5P.MultiChoice 1.16',
                params: {
                    question: '<p>Question ?</p>',
                    answers: [
                        { text: '<p>Réponse A</p>', correct: true },
                        { text: '<p>Réponse B</p>', correct: false }
                    ]
                }
            };
            newInteraction.libraryTitle = 'Multiple Choice';
            newInteraction.label = '<p>Question</p>';
            newInteraction.displayType = 'button';
            break;
        case 'truefalse':
            newInteraction.action = {
                library: 'H5P.TrueFalse 1.8',
                params: {
                    media: { type: { params: {} }, disableImageZooming: false },
                    question: '<p>Affirmation \u00e0 \u00e9valuer</p>',
                    correct: 'true',
                    behaviour: {
                        enableRetry: true,
                        enableSolutionsButton: true,
                        enableCheckButton: true,
                        confirmCheckDialog: false,
                        confirmRetryDialog: false,
                        autoCheck: false,
                        feedbackOnCorrect: '',
                        feedbackOnWrong: ''
                    },
                    l10n: {
                        trueText: 'Vrai',
                        falseText: 'Faux',
                        score: 'Vous avez obtenu @score points sur un total de @total',
                        checkAnswer: 'V\u00e9rifier',
                        submitAnswer: 'V\u00e9rifier',
                        showSolutionButton: 'Voir la solution',
                        tryAgain: 'Recommencer',
                        wrongAnswerMessage: 'R\u00e9ponse incorrecte',
                        correctAnswerMessage: 'Bonne r\u00e9ponse',
                        scoreBarLabel: 'Vous avez obtenu @score points sur un total de @total',
                        a11yCheck: 'V\u00e9rifiez les r\u00e9ponses.',
                        a11yShowSolution: 'Montrer la solution.',
                        a11yRetry: 'R\u00e9essayer l\u0027exercice.'
                    },
                    confirmCheck: {
                        header: 'Terminer ?',
                        body: 'Voulez-vous vraiment terminer ?',
                        cancelLabel: 'Annuler',
                        confirmLabel: 'Confirmer'
                    },
                    confirmRetry: {
                        header: 'Recommencer ?',
                        body: 'Voulez-vous vraiment recommencer ?',
                        cancelLabel: 'Annuler',
                        confirmLabel: 'Confirmer'
                    }
                },
                subContentId: crypto.randomUUID ? crypto.randomUUID() : (Date.now().toString(36) + '-' + Math.random().toString(36).substr(2, 9)),
                metadata: {
                    contentType: 'True/False Question',
                    license: 'U',
                    title: 'Sans titre True/False Question'
                }
            };
            newInteraction.libraryTitle = 'True/False Question';
            newInteraction.label = '<p>Vrai ou Faux ?</p>';
            newInteraction.displayType = 'button';
            break;
        case 'blanks':
            newInteraction.action = {
                library: 'H5P.Blanks 1.14',
                params: {
                    text: 'Compl\u00e9tez les mots manquants',
                    questions: ['<p>Le mot *manquant* est \u00e0 trouver.</p>'],
                    behaviour: {
                        enableRetry: true,
                        enableSolutionsButton: true,
                        enableCheckButton: true,
                        autoCheck: false,
                        caseSensitive: false,
                        showSolutionsRequiresInput: true,
                        separateLines: false,
                        confirmCheckDialog: false,
                        confirmRetryDialog: false,
                        acceptSpellingErrors: false
                    }
                }
            };
            newInteraction.libraryTitle = 'Fill in the Blanks';
            newInteraction.label = '<p>Compl\u00e9ter</p>';
            newInteraction.displayType = 'button';
            break;
    }
    
    element.action.params.interactiveVideo.assets.interactions.push(newInteraction);
    
    // Sauvegarder et restaurer la position vidéo
    const saved = cpSaveVideoPosition(cpSelectedElement);
    const savedIdx = cpSelectedElement;
    
    cpRenderSlideElements(); // Mettre à jour les marqueurs sur le canvas
    cpRenderElementProps();
    onCourseModified();
    
    cpRestoreVideoPosition(savedIdx, saved);
    
    // Ouvrir l'éditeur pour la nouvelle interaction
    cpEditInteraction(element.action.params.interactiveVideo.assets.interactions.length - 1);
}

// Variable pour l'interaction en cours d'édition
let cpEditingInteractionIdx = null;

// Éditer une interaction
function cpEditInteraction(idx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const interactions = element.action?.params?.interactiveVideo?.assets?.interactions;
    
    if (!interactions || !interactions[idx]) return;
    
    const inter = interactions[idx];
    cpEditingInteractionIdx = idx;
    
    const interType = inter.action?.library?.split(' ')[0]?.replace('H5P.', '') || 'Text';
    const displayType = inter.displayType || 'poster';
    const pauseVideo = inter.pause !== false;
    
    // Construire le formulaire d'édition selon le type
    let editFormHtml = `
        <div class="cp-interaction-editor" style="background: white; border: 1px solid var(--gray-300); border-radius: 8px; padding: 1rem; margin-top: 0.5rem;">
            <h4 style="margin: 0 0 0.75rem; font-size: 0.9rem; color: var(--primary);">Éditer l'interaction</h4>
            
            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                <div style="flex: 1;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Mode d'affichage</label>
                    <select class="cp-prop-input" onchange="cpUpdateInteractionProp(${idx}, 'displayType', this.value)" style="font-size: 0.8rem;">
                        <option value="poster" ${displayType === 'poster' ? 'selected' : ''}>📋 Cadre (poster)</option>
                        <option value="button" ${displayType === 'button' ? 'selected' : ''}>🔘 Bouton cliquable</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Pause vidéo</label>
                    <select class="cp-prop-input" onchange="cpUpdateInteractionProp(${idx}, 'pause', this.value === 'true')" style="font-size: 0.8rem;">
                        <option value="true" ${pauseVideo ? 'selected' : ''}>⏸ Oui</option>
                        <option value="false" ${!pauseVideo ? 'selected' : ''}>▶ Non</option>
                    </select>
                </div>
            </div>
            
            <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                <label class="cp-prop-label" style="font-size: 0.75rem;">Label du bouton ${displayType === 'button' ? '(requis)' : '(optionnel)'}</label>
                <input type="text" class="cp-prop-input" value="${escapeHtml((inter.label || '').replace(/<[^>]*>/g, ''))}" 
                       onchange="cpUpdateInteractionProp(${idx}, 'label', '<p>' + this.value + '</p>')" style="font-size: 0.8rem;"
                       placeholder="${displayType === 'button' ? 'Texte affiché sur le bouton' : 'Optionnel'}">
            </div>
            
            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                <div style="flex: 1;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Début (sec)</label>
                    <input type="number" class="cp-prop-input" value="${inter.duration?.from || 0}" min="0" step="0.5"
                           onchange="cpUpdateInteractionTiming(${idx}, 'from', parseFloat(this.value))" style="font-size: 0.8rem;">
                </div>
                <div style="flex: 1;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Fin (sec)</label>
                    <input type="number" class="cp-prop-input" value="${inter.duration?.to || 10}" min="0" step="0.5"
                           onchange="cpUpdateInteractionTiming(${idx}, 'to', parseFloat(this.value))" style="font-size: 0.8rem;">
                </div>
            </div>

            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.25rem;">
                <div style="flex: 1;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Position X (%)</label>
                    <input type="number" class="cp-prop-input" id="cpInterPosX_${idx}" value="${Math.round((inter.x ?? 50) * 10) / 10}" min="0" max="100" step="1"
                           onchange="cpUpdateInteractionPosition(${idx}, 'x', parseFloat(this.value))" style="font-size: 0.8rem;">
                </div>
                <div style="flex: 1;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Position Y (%)</label>
                    <input type="number" class="cp-prop-input" id="cpInterPosY_${idx}" value="${Math.round((inter.y ?? 50) * 10) / 10}" min="0" max="100" step="1"
                           onchange="cpUpdateInteractionPosition(${idx}, 'y', parseFloat(this.value))" style="font-size: 0.8rem;">
                </div>
            </div>
            <p style="font-size: 0.68rem; color: var(--gray-400); margin: 0 0 0.5rem;">💡 Astuce : vous pouvez aussi glisser l'étiquette directement sur la vidéo.</p>`;
    
    // Champs spécifiques selon le type
    switch (interType) {
        case 'Text':
            const textContent = inter.action?.params?.text || '';
            editFormHtml += `
                <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Texte</label>
                    <div class="rich-text-toolbar">
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('cpInterTextEditor','bold')" title="Gras"><b>G</b></button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('cpInterTextEditor','italic')" title="Italique"><i>I</i></button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('cpInterTextEditor','underline')" title="Souligné"><u>S</u></button>
                        <span class="rich-text-separator"></span>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('cpInterTextEditor','justifyLeft')" title="Aligner à gauche">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/>
                            </svg>
                        </button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('cpInterTextEditor','justifyCenter')" title="Centrer">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>
                            </svg>
                        </button>
                        <button class="rich-text-btn" type="button" onclick="cpExecCmd('cpInterTextEditor','justifyRight')" title="Aligner à droite">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/>
                            </svg>
                        </button>
                        ${cpEmojiBarHtml('cpInterTextEditor')}
                    </div>
                    <div id="cpInterTextEditor" class="rich-text-editor" contenteditable="true"
                         style="min-height: 60px; font-size: 0.85rem;"
                         oninput="cpSaveInteractionRichText(${idx})">${textContent}</div>
                </div>`;
            break;
            
        case 'MultiChoice':
            const mcQuestion = inter.action?.params?.question || '';
            const mcAnswers = inter.action?.params?.answers || [];
            editFormHtml += `
                <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Question</label>
                    <input type="text" class="cp-prop-input" id="cpInterMcQ_${idx}" value="${escapeHtml(mcQuestion.replace(/<[^>]*>/g, ''))}" 
                           onfocus="window._lastEmojiTarget='cpInterMcQ_${idx}'"
                           onchange="cpUpdateInteractionContent(${idx}, 'question', '<p>' + this.value + '</p>')" style="font-size: 0.8rem;">
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Réponses</label>`;
            mcAnswers.forEach((ans, aIdx) => {
                editFormHtml += `
                    <div style="display: flex; gap: 0.25rem; align-items: center; margin-bottom: 0.25rem;">
                        <input type="checkbox" ${ans.correct ? 'checked' : ''} 
                               onchange="cpUpdateInteractionAnswer(${idx}, ${aIdx}, 'correct', this.checked)">
                        <input type="text" class="cp-prop-input" id="cpInterMcA_${idx}_${aIdx}" value="${escapeHtml((ans.text || '').replace(/<[^>]*>/g, ''))}" 
                               onfocus="window._lastEmojiTarget='cpInterMcA_${idx}_${aIdx}'"
                               onchange="cpUpdateInteractionAnswer(${idx}, ${aIdx}, 'text', '<p>' + this.value + '</p>')" 
                               style="flex: 1; font-size: 0.75rem;">
                        <button class="tree-action-btn" onclick="cpRemoveInteractionAnswer(${idx}, ${aIdx})">🗑️</button>
                    </div>`;
            });
            editFormHtml += `
                    <button class="btn btn-secondary" onclick="cpAddInteractionAnswer(${idx})" 
                            style="width: 100%; padding: 0.3rem; font-size: 0.7rem; margin-top: 0.25rem;">+ Réponse</button>
                    ${cpEmojiBarHtml('_dynamic_')}
                </div>`;
            break;
            
        case 'TrueFalse':
            const tfQuestion = inter.action?.params?.question || '';
            const tfCorrect = inter.action?.params?.correct === 'true';
            editFormHtml += `
                <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Affirmation</label>
                    <input type="text" class="cp-prop-input" id="cpInterTfQ_${idx}" value="${escapeHtml(tfQuestion)}" 
                           onchange="cpUpdateInteractionContent(${idx}, 'question', this.value)" style="font-size: 0.8rem;">
                    ${cpEmojiBarHtml('cpInterTfQ_' + idx)}
                </div>
                <div class="cp-prop-group">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Réponse correcte</label>
                    <select class="cp-prop-input" onchange="cpUpdateInteractionContent(${idx}, 'correct', this.value)" style="font-size: 0.8rem;">
                        <option value="true" ${tfCorrect ? 'selected' : ''}>Vrai</option>
                        <option value="false" ${!tfCorrect ? 'selected' : ''}>Faux</option>
                    </select>
                </div>`;
            break;
            
        case 'Blanks':
            const blanksInstruction = inter.action?.params?.text || '';
            const blanksQuestions = inter.action?.params?.questions?.[0]?.replace(/<[^>]*>/g, '') || '';
            editFormHtml += `
                <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Consigne</label>
                    <input type="text" class="cp-prop-input" id="cpInterBlConsigne_${idx}" value="${escapeHtml(blanksInstruction)}" 
                           onfocus="window._lastEmojiTarget='cpInterBlConsigne_${idx}'"
                           onchange="cpUpdateInteractionContent(${idx}, 'text', this.value)" style="font-size: 0.8rem;"
                           placeholder="Compl\u00e9tez les mots manquants">
                </div>
                <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                    <label class="cp-prop-label" style="font-size: 0.75rem;">Texte \u00e0 trous (mots entre *ast\u00e9risques*)</label>
                    <textarea class="cp-prop-input" id="cpInterBlText_${idx}" rows="3" style="font-size: 0.8rem;"
                              onfocus="window._lastEmojiTarget='cpInterBlText_${idx}'"
                              onchange="cpUpdateInteractionContent(${idx}, 'questions', ['<p>' + this.value + '</p>'])">${escapeHtml(blanksQuestions)}</textarea>
                    <small style="color: var(--gray-500); font-size: 0.65rem;">Ex: Le *soleil* brille dans le *ciel*.</small>
                    ${cpEmojiBarHtml('_dynamic_')}
                </div>`;
            break;
            
        case 'Nil':
            // Label simple - pas de contenu supplémentaire, juste le label du bouton
            editFormHtml += `
                <div class="cp-prop-group" style="margin-bottom: 0.5rem;">
                    <p style="font-size: 0.75rem; color: var(--gray-500); margin: 0;">
                        🏷️ Ce type "Label" affiche uniquement un bouton cliquable. 
                        Le texte du bouton est défini dans le champ "Label du bouton" ci-dessus.
                    </p>
                </div>`;
            break;
    }
    
    editFormHtml += `
            <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem;">
                <button class="btn btn-secondary" onclick="cpCloseInteractionEditor()" style="flex: 1; padding: 0.4rem; font-size: 0.75rem;">Fermer</button>
            </div>
        </div>`;
    
    // Afficher le formulaire d'édition
    let editorContainer = document.getElementById('cpInteractionEditorContainer');
    if (!editorContainer) {
        editorContainer = document.createElement('div');
        editorContainer.id = 'cpInteractionEditorContainer';
        document.getElementById('cpElementProps').appendChild(editorContainer);
    }
    editorContainer.innerHTML = editFormHtml;

    // En mode édition : forcer la carte éditée à rester visible (même hors de son
    // créneau temporel) pour pouvoir la saisir et la déplacer à la volée.
    const layer = document.getElementById('cpInteractionsLayer_' + cpSelectedElement);
    if (layer) {
        layer.querySelectorAll('.cp-video-interaction').forEach(c => { delete c.dataset.forceVisible; });
        const editedCard = document.getElementById('cpInteraction_' + cpSelectedElement + '_' + idx);
        if (editedCard) {
            editedCard.dataset.forceVisible = 'true';
            editedCard.classList.add('visible', 'editing');
        }
    }
}

// Fermer l'éditeur d'interaction
function cpCloseInteractionEditor() {
    // Retirer le forçage de visibilité et rétablir l'affichage selon le temps
    if (cpSelectedElement !== null) {
        const editedCard = document.getElementById('cpInteraction_' + cpSelectedElement + '_' + cpEditingInteractionIdx);
        if (editedCard) {
            delete editedCard.dataset.forceVisible;
            editedCard.classList.remove('editing');
        }
        cpUpdateInteractionMarkers(cpSelectedElement, cpGetVideoTime(cpSelectedElement));
    }
    cpEditingInteractionIdx = null;
    const container = document.getElementById('cpInteractionEditorContainer');
    if (container) container.innerHTML = '';
}

// Mettre à jour une propriété d'interaction
function cpUpdateInteractionProp(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    
    if (inter) {
        inter[prop] = value;
        // Rafraîchir si c'est une propriété visuelle (position, label)
        if (['x', 'y', 'label'].includes(prop)) {
            const _saved = cpSaveVideoPosition(cpSelectedElement);
            cpRenderSlideElements();
            cpRestoreVideoPosition(cpSelectedElement, _saved);
        }
        onCourseModified();
    }
}

// Mettre à jour le timing d'une interaction
function cpUpdateInteractionTiming(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    
    if (inter) {
        if (!inter.duration) inter.duration = {};
        inter.duration[prop] = value;
        
        // Sauvegarder la position vidéo
        const _saved = cpSaveVideoPosition(cpSelectedElement);
        
        cpRenderSlideElements(); // Rafraîchir pour mettre à jour les attributs data-from/data-to
        
        cpRestoreVideoPosition(cpSelectedElement, _saved);
        
        onCourseModified();
    }
}

// Mettre à jour le contenu d'une interaction
function cpUpdateInteractionContent(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    
    if (inter && inter.action) {
        if (!inter.action.params) inter.action.params = {};
        inter.action.params[prop] = value;
        
        // Rafraîchir le canvas pour afficher le nouveau contenu
        const _saved = cpSaveVideoPosition(cpSelectedElement);
        cpRenderSlideElements();
        cpRestoreVideoPosition(cpSelectedElement, _saved);
        
        onCourseModified();
    }
}

function cpExecCmd(editorId, command) {
    const editor = document.getElementById(editorId);
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
}

function cpSaveInteractionRichText(idx) {
    const editor = document.getElementById('cpInterTextEditor');
    if (!editor) return;
    let html = editor.innerHTML;
    // Normaliser les tags
    html = html.replace(/<b(\s|>)/gi, '<strong$1').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i(\s|>)/gi, '<em$1').replace(/<\/i>/gi, '</em>');
    
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    if (inter && inter.action) {
        if (!inter.action.params) inter.action.params = {};
        inter.action.params.text = html;
        onCourseModified();
    }
}

// Mettre à jour une réponse QCM
function cpUpdateInteractionAnswer(idx, ansIdx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    
    if (inter && inter.action?.params?.answers?.[ansIdx]) {
        inter.action.params.answers[ansIdx][prop] = value;
        
        // Rafraîchir le canvas pour afficher le nouveau contenu
        const _saved = cpSaveVideoPosition(cpSelectedElement);
        cpRenderSlideElements();
        cpRestoreVideoPosition(cpSelectedElement, _saved);
        
        onCourseModified();
    }
}

// Ajouter une réponse QCM
function cpAddInteractionAnswer(idx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    
    if (inter && inter.action?.params) {
        if (!inter.action.params.answers) inter.action.params.answers = [];
        inter.action.params.answers.push({ text: '<p>Nouvelle réponse</p>', correct: false });
        
        const _saved = cpSaveVideoPosition(cpSelectedElement);
        cpRenderSlideElements();
        cpEditInteraction(idx); // Rafraîchir l'éditeur
        cpRestoreVideoPosition(cpSelectedElement, _saved);
        
        onCourseModified();
    }
}

// Supprimer une réponse QCM
function cpRemoveInteractionAnswer(idx, ansIdx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const inter = element.action?.params?.interactiveVideo?.assets?.interactions?.[idx];
    
    if (inter && inter.action?.params?.answers) {
        inter.action.params.answers.splice(ansIdx, 1);
        
        const _saved = cpSaveVideoPosition(cpSelectedElement);
        cpRenderSlideElements();
        cpEditInteraction(idx); // Rafraîchir l'éditeur
        cpRestoreVideoPosition(cpSelectedElement, _saved);
        
        onCourseModified();
    }
}

// Supprimer une interaction
function cpDeleteInteraction(idx) {
    if (!confirm('Supprimer cette interaction ?')) return;
    
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (element.action?.params?.interactiveVideo?.assets?.interactions) {
        // Sauvegarder la position de la vidéo
        const _saved = cpSaveVideoPosition(cpSelectedElement);
        
        element.action.params.interactiveVideo.assets.interactions.splice(idx, 1);
        cpCloseInteractionEditor();
        cpRenderSlideElements(); // Mettre à jour les marqueurs
        cpRenderElementProps();
        onCourseModified();
        
        cpRestoreVideoPosition(cpSelectedElement, _saved);
    }
}

// ==================== DIALOG CARDS ====================

// Élément Dialog Cards sélectionné, avec un tableau `dialogs` toujours non vide.
function cpDcGetElement() {
    const activity = getSelectedActivity();
    const element = activity?.content?.presentation?.slides?.[cpCurrentSlide]?.elements?.[cpSelectedElement];
    if (!element || !element.action) return null;
    if (!element.action.params) element.action.params = {};
    if (!Array.isArray(element.action.params.dialogs)) element.action.params.dialogs = [];
    if (element.action.params.dialogs.length === 0) {
        element.action.params.dialogs.push({ text: '', answer: '', tips: {}, image: null });
    }
    return element;
}

// État d'aperçu (carte affichée / face visible) d'un élément, borné au nombre de cartes.
function cpDcGetPreview(slideIdx, elIdx, total) {
    const key = slideIdx + ':' + elIdx;
    let state = cpDcPreview[key];
    if (!state) state = cpDcPreview[key] = { card: 0, flipped: false };
    if (total > 0 && state.card > total - 1) { state.card = total - 1; state.flipped = false; }
    if (state.card < 0) state.card = 0;
    return state;
}

function cpDcSetPreview(cardIdx, flipped) {
    const element = cpDcGetElement();
    if (!element) return;
    const total = element.action.params.dialogs.length;
    const state = cpDcGetPreview(cpCurrentSlide, cpSelectedElement, total);
    if (cardIdx !== null) state.card = Math.max(0, Math.min(total - 1, cardIdx));
    if (flipped !== null) state.flipped = flipped;
}

// Retourner la carte affichée sur le canvas
function cpDcPreviewFlip(elIdx) {
    const reselected = elIdx !== cpSelectedElement;
    if (reselected) cpSelectSingle(elIdx);
    const element = cpDcGetElement();
    if (!element) return;
    const state = cpDcGetPreview(cpCurrentSlide, elIdx, element.action.params.dialogs.length);
    state.flipped = !state.flipped;
    cpRenderSlideElements();
    if (reselected) cpRenderElementProps();
}

// Carte précédente / suivante depuis le canvas
function cpDcPreviewNav(elIdx, delta) {
    if (elIdx !== cpSelectedElement) cpSelectSingle(elIdx);
    const element = cpDcGetElement();
    if (!element) return;
    const total = element.action.params.dialogs.length;
    const state = cpDcGetPreview(cpCurrentSlide, elIdx, total);
    const target = state.card + delta;
    if (target < 0 || target > total - 1) return;
    state.card = target;
    state.flipped = false;
    cpRenderSlideElements();
    cpRenderElementProps();
}

// Sélection d'une carte depuis le panneau propriétés (déplie + affiche sur le canvas)
function cpDcSelectCard(cardIdx) {
    cpDcSetPreview(cardIdx, false);
    cpRenderElementProps();
    cpRenderSlideElements();
}

function cpDcAddCard() {
    const element = cpDcGetElement();
    if (!element) return;
    element.action.params.dialogs.push({ text: '', answer: '', tips: {}, image: null });
    cpDcSetPreview(element.action.params.dialogs.length - 1, false);
    cpRenderElementProps();
    cpRenderSlideElements();
    onCourseModified();
}

function cpDcDeleteCard(cardIdx) {
    const element = cpDcGetElement();
    if (!element) return;
    const dialogs = element.action.params.dialogs;
    if (dialogs.length <= 1) {
        showToast('Une carte au minimum', 'error');
        return;
    }
    if (!confirm('Supprimer la carte ' + (cardIdx + 1) + ' ?')) return;
    dialogs.splice(cardIdx, 1);
    cpDcSetPreview(Math.min(cardIdx, dialogs.length - 1), false);
    cpRenderElementProps();
    cpRenderSlideElements();
    onCourseModified();
}

function cpDcMoveCard(cardIdx, direction) {
    const element = cpDcGetElement();
    if (!element) return;
    const dialogs = element.action.params.dialogs;
    const newIdx = cardIdx + direction;
    if (newIdx < 0 || newIdx >= dialogs.length) return;
    [dialogs[cardIdx], dialogs[newIdx]] = [dialogs[newIdx], dialogs[cardIdx]];
    cpDcSetPreview(newIdx, false);
    cpRenderElementProps();
    cpRenderSlideElements();
    onCourseModified();
}

function cpDcUpdateBehaviour(prop, value) {
    const element = cpDcGetElement();
    if (!element) return;
    if (!element.action.params.behaviour) element.action.params.behaviour = {};
    element.action.params.behaviour[prop] = value;
    onCourseModified();
}

// Barre d'outils de mise en forme d'une face de carte
function cpDcToolbarHtml(cardIdx, side) {
    const editorId = (side === 'recto' ? 'cpDCRectoEditor' : 'cpDCVersoEditor') + cardIdx;
    const align = (cmd, title, lines) => `
        <button class="rich-text-btn" onclick="cpFormatDCText('${cmd}', '${side}', ${cardIdx})" title="${title}">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${lines}</svg>
        </button>`;
    return `
        <div class="rich-text-toolbar" style="margin-bottom: 0.25rem;">
            <button class="rich-text-btn" onclick="cpFormatDCText('bold', '${side}', ${cardIdx})" title="Gras"><b>G</b></button>
            <button class="rich-text-btn" onclick="cpFormatDCText('italic', '${side}', ${cardIdx})" title="Italique"><i>I</i></button>
            <button class="rich-text-btn" onclick="cpFormatDCText('underline', '${side}', ${cardIdx})" title="Souligné"><u>S</u></button>
            <span class="rich-text-separator"></span>
            ${align('justifyLeft', 'Aligner à gauche', '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/>')}
            ${align('justifyCenter', 'Centrer', '<line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>')}
            ${align('justifyRight', 'Aligner à droite', '<line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/>')}
            ${cpEmojiBarHtml(editorId)}
        </div>`;
}

function cpUpdateDialogCardText(prop, value, cardIdx) {
    const element = cpDcGetElement();
    if (!element) return;
    const card = element.action.params.dialogs[cardIdx || 0];
    if (!card) return;
    if (!card.tips) card.tips = {};
    card[prop] = value;

    cpRenderSlideElements();
    onCourseModified();
}

// Fonctions pour le formatage du texte des DialogCards
function cpFormatDCText(command, side, cardIdx) {
    const editorId = (side === 'recto' ? 'cpDCRectoEditor' : 'cpDCVersoEditor') + (cardIdx || 0);
    const editor = document.getElementById(editorId);
    if (!editor) return;

    // Vérifier si du texte est sélectionné
    const selection = window.getSelection();
    const hasSelection = selection && selection.toString().length > 0
        && editor.contains(selection.anchorNode);

    // Si aucune sélection, sélectionner tout le contenu
    if (!hasSelection) {
        const range = document.createRange();
        range.selectNodeContents(editor);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    document.execCommand(command, false, null);
    editor.focus();

    // Mettre à jour
    const prop = side === 'recto' ? 'text' : 'answer';
    cpUpdateDCText(prop, cardIdx || 0);
}

function cpUpdateDCTextLive(prop, cardIdx) {
    // Rafraîchir le titre de la carte dans la liste, sans re-rendre le panneau
    // (un re-rendu ferait perdre le focus de la zone d'édition).
    if (prop !== 'text') return;
    const editor = document.getElementById('cpDCRectoEditor' + (cardIdx || 0));
    const title = document.getElementById('cpDCTitle' + (cardIdx || 0));
    if (!editor || !title) return;
    const plain = editor.innerHTML.replace(/<[^>]*>/g, '').trim().substring(0, 28);
    title.textContent = plain || 'Carte ' + ((cardIdx || 0) + 1);
}

function cpUpdateDCText(prop, cardIdx) {
    cardIdx = cardIdx || 0;
    const editorId = (prop === 'text' ? 'cpDCRectoEditor' : 'cpDCVersoEditor') + cardIdx;
    const editor = document.getElementById(editorId);
    if (!editor) return;

    const element = cpDcGetElement();
    if (!element) return;
    const card = element.action.params.dialogs[cardIdx];
    if (!card) return;
    if (!card.tips) card.tips = {};

    // Éléa attend <strong>/<em> ; execCommand produit <b>/<i>
    let html = editor.innerHTML;
    html = html.replace(/<b(\s|>)/gi, '<strong$1').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i(\s|>)/gi, '<em$1').replace(/<\/i>/gi, '</em>');
    card[prop] = html;

    cpRenderSlideElements();
    onCourseModified();
}

// Applique une image (uploadée ou distante) à une carte, dimensions incluses si lisibles
function cpDcApplyImage(cardIdx, imageObj, successMsg) {
    const finish = (msg) => {
        const element = cpDcGetElement();
        if (!element) return;
        const card = element.action.params.dialogs[cardIdx];
        if (!card) return;
        if (!card.tips) card.tips = {};
        card.image = imageObj;
        cpRenderElementProps();
        cpRenderSlideElements();
        onCourseModified();
        showToast(msg, 'success');
    };

    const img = new Image();
    img.onload = function() {
        imageObj.width = img.naturalWidth;
        imageObj.height = img.naturalHeight;
        finish(successMsg + ' (' + img.naturalWidth + 'x' + img.naturalHeight + ')');
    };
    img.onerror = function() {
        // Si on ne peut pas charger l'image, on l'ajoute quand même sans dimensions
        finish(successMsg);
    };
    img.src = imageObj.path;
}

function cpUploadDialogCardImage(input, cardIdx) {
    if (!input.files || !input.files[0]) return;
    cardIdx = cardIdx || 0;

    const file = input.files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'upload_file');

    showToast('Upload en cours...', 'info');

    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.url) {
            cpDcApplyImage(cardIdx, {
                path: data.url,
                mime: data.type || 'image/png',
                copyright: { license: 'U' }
            }, 'Image ajoutée');
        } else {
            showToast('Erreur: ' + (data.error || 'Upload échoué'), 'error');
        }
    })
    .catch(err => {
        console.error('Erreur upload:', err);
        showToast('Erreur réseau', 'error');
    });
}

function cpRemoveDialogCardImage(cardIdx) {
    const element = cpDcGetElement();
    if (!element) return;
    const card = element.action.params.dialogs[cardIdx || 0];
    if (card) card.image = null;

    cpRenderElementProps();
    cpRenderSlideElements();
    onCourseModified();
}

function cpSetDialogCardImageUrl(url, cardIdx) {
    if (!url || !url.trim()) {
        showToast('URL vide', 'error');
        return;
    }

    url = url.trim();

    // Vérifier que c'est une URL valide
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
        showToast('URL invalide (doit commencer par http:// ou https://)', 'error');
        return;
    }

    // Déterminer le type MIME à partir de l'extension
    const ext = url.split('.').pop().toLowerCase().split('?')[0];
    const mimeMap = {
        'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'png': 'image/png',
        'gif': 'image/gif', 'webp': 'image/webp', 'svg': 'image/svg+xml'
    };
    const mime = mimeMap[ext] || 'image/png';

    cpDcApplyImage(cardIdx || 0, {
        path: url,
        mime: mime,
        copyright: { license: 'U' }
    }, 'Image ajoutée');
}

function getSelectedActivity() {
    const section = courseData.sections.find(s => s.id === selectedSection);
    return section?.activities.find(a => a.id === selectedActivity);
}

// ==================== MENU CONTEXTUEL COURSE PRESENTATION ====================
let cpContextMenuPosition = { x: 0, y: 0 }; // Position du clic droit pour coller

function cpShowElementContextMenu(event, elementIdx) {
    event.preventDefault();
    event.stopPropagation();
    
    cpSyncSelection();
    
    // Si l'élément cliqué n'est pas dans la sélection, le sélectionner seul
    if (!cpSelectedElements.has(elementIdx)) {
        cpSelectedElement = elementIdx;
        cpSelectedElements.clear();
        cpSelectedElements.add(elementIdx);
        cpRenderSlideElements();
        cpRenderElementProps();
    }
    
    // Supprimer l'ancien menu s'il existe
    cpHideContextMenu();
    
    var isMulti = cpSelectedElements.size > 1;
    var countLabel = isMulti ? ' (' + cpSelectedElements.size + ')' : '';
    
    // Créer le menu
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.id = 'cpContextMenu';
    
    // Vérifier si c'est une image (pour option télécharger)
    let downloadHtml = '';
    if (!isMulti) {
        const activity = getSelectedActivity();
        const slide = activity?.content?.presentation?.slides?.[cpCurrentSlide];
        const elem = slide?.elements?.[elementIdx];
        const lib = (elem?.action?.library || '').toLowerCase();
        if (lib.indexOf('h5p.image') !== -1) {
            const imgSrc = elem?.action?.params?.file?.path || '';
            if (imgSrc) {
                downloadHtml = `<div class="context-menu-separator"></div>
                    <div class="context-menu-item" onclick="cpDownloadImage(${elementIdx})">
                        <span>💾</span> Télécharger l'image
                    </div>`;
            }
        }
    }
    
    menu.innerHTML = `
        <div class="context-menu-item" onclick="${isMulti ? 'cpCopySelected()' : 'cpCopyElement(' + elementIdx + ')'}">
            <span>📋</span> Copier${countLabel}
        </div>
        <div class="context-menu-item" onclick="${isMulti ? 'cpDuplicateSelected()' : 'cpDuplicateElement(' + elementIdx + ')'}">
            <span>📄</span> Dupliquer${countLabel}
        </div>
        <div class="context-menu-separator"></div>
        <div class="context-menu-item" onclick="cpBringToFront(${isMulti ? '' : elementIdx})">
            <span>⬆️</span> Premier plan${countLabel}
        </div>
        <div class="context-menu-item" onclick="cpSendToBack(${isMulti ? '' : elementIdx})">
            <span>⬇️</span> Arrière-plan${countLabel}
        </div>${downloadHtml}
        <div class="context-menu-separator"></div>
        <div class="context-menu-item danger" onclick="${isMulti ? 'cpDeleteSelected()' : 'cpDeleteElement(' + elementIdx + '); cpHideContextMenu();'}">
            <span>🗑️</span> Supprimer${countLabel}
        </div>
    `;
    
    document.body.appendChild(menu);
    
    // Positionner le menu
    const x = event.clientX;
    const y = event.clientY;
    
    const menuRect = menu.getBoundingClientRect();
    const maxX = window.innerWidth - menuRect.width - 10;
    const maxY = window.innerHeight - menuRect.height - 10;
    
    menu.style.left = Math.min(x, maxX) + 'px';
    menu.style.top = Math.min(y, maxY) + 'px';
    
    // Fermer le menu au clic ailleurs
    setTimeout(() => {
        document.addEventListener('click', cpHideContextMenu);
        document.addEventListener('contextmenu', cpHideContextMenu);
    }, 10);
}

function cpShowCanvasContextMenu(event) {
    // Vérifier qu'on a cliqué sur le fond et pas sur un élément
    if (event.target.closest('.cp-element')) {
        return; // L'élément gère son propre menu
    }
    
    event.preventDefault();
    event.stopPropagation();
    
    // Stocker la position du clic pour le collage
    const canvas = document.getElementById('cpCanvasInner');
    if (canvas) {
        const rect = canvas.getBoundingClientRect();
        cpContextMenuPosition = {
            x: ((event.clientX - rect.left) / rect.width) * 100,
            y: ((event.clientY - rect.top) / rect.height) * 100
        };
    }
    
    // Désélectionner l'élément actuel
    cpSelectedElement = null; cpSelectedElements.clear();
    cpDqSelectedItem = null;
    cpRenderSlideElements();
    cpRenderElementProps();
    
    // Supprimer l'ancien menu s'il existe
    cpHideContextMenu();
    
    // Créer le menu
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.id = 'cpContextMenu';
    
    const hasCopied = cpClipboardElements !== null && cpClipboardElements.length > 0;
    
    menu.innerHTML = `
        <div class="context-menu-item ${hasCopied ? '' : 'disabled'}" onclick="${hasCopied ? 'cpPasteElement(true)' : ''}">
            <span>📋</span> Coller ${hasCopied ? '' : '<span style="color: var(--gray-400); font-size: 0.75rem;">(rien à coller)</span>'}
        </div>
    `;
    
    document.body.appendChild(menu);
    
    // Positionner le menu
    const x = event.clientX;
    const y = event.clientY;
    
    const menuRect = menu.getBoundingClientRect();
    const maxX = window.innerWidth - menuRect.width - 10;
    const maxY = window.innerHeight - menuRect.height - 10;
    
    menu.style.left = Math.min(x, maxX) + 'px';
    menu.style.top = Math.min(y, maxY) + 'px';
    
    // Fermer le menu au clic ailleurs
    setTimeout(() => {
        document.addEventListener('click', cpHideContextMenu);
        document.addEventListener('contextmenu', cpHideContextMenu);
    }, 10);
}

function cpHideContextMenu() {
    const menu = document.getElementById('cpContextMenu');
    if (menu) {
        menu.remove();
    }
    document.removeEventListener('click', cpHideContextMenu);
    document.removeEventListener('contextmenu', cpHideContextMenu);
}

function cpDownloadImage(elementIdx) {
    cpHideContextMenu();
    const activity = getSelectedActivity();
    const slide = activity?.content?.presentation?.slides?.[cpCurrentSlide];
    const elem = slide?.elements?.[elementIdx];
    const imgSrc = elem?.action?.params?.file?.path || '';
    if (!imgSrc) return;
    
    // Déduire le nom de fichier
    const alt = elem?.action?.params?.alt || '';
    let filename = alt || 'image';
    // Extraire l'extension depuis l'URL
    const urlMatch = imgSrc.match(/\.(\w{3,4})(?:[?#]|$)/);
    const ext = urlMatch ? '.' + urlMatch[1] : '.png';
    if (!filename.match(/\.\w{3,4}$/)) filename += ext;
    
    // Télécharger via fetch + blob pour forcer le download
    fetch(imgSrc)
    .then(r => r.blob())
    .then(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    })
    .catch(() => {
        // Fallback: ouvrir dans un nouvel onglet
        window.open(imgSrc, '_blank');
    });
}

function cpCopyElement(elementIdx) {
    cpHideContextMenu();
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[elementIdx];
    
    if (element) {
        cpClipboardElements = [JSON.parse(JSON.stringify(element))];
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText('__elea_cp_element__').catch(function(){});
        }
        showToast('Élément copié', 'success');
    }
}

// Copier tous les éléments sélectionnés
function cpCopySelected() {
    cpHideContextMenu();
    cpSyncSelection();
    if (cpSelectedElements.size === 0) return;
    
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) return;
    
    cpClipboardElements = [];
    // Trier par index pour garder l'ordre relatif
    Array.from(cpSelectedElements).sort(function(a,b){return a-b;}).forEach(function(idx) {
        if (slide.elements[idx]) {
            cpClipboardElements.push(JSON.parse(JSON.stringify(slide.elements[idx])));
        }
    });
    if (cpClipboardElements.length === 0) { cpClipboardElements = null; return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText('__elea_cp_element__').catch(function(){});
    }
    showToast(cpClipboardElements.length > 1 ? cpClipboardElements.length + ' éléments copiés' : 'Élément copié', 'success');
}

function cpDuplicateElement(elementIdx) {
    cpHideContextMenu();
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[elementIdx];
    
    if (element) {
        const newElement = JSON.parse(JSON.stringify(element));
        newElement.x = Math.min((newElement.x || 10) + 5, 90);
        newElement.y = Math.min((newElement.y || 10) + 5, 90);
        if (newElement.action && newElement.action.subContentId) {
            newElement.action.subContentId = generateUUID();
        }
        slide.elements.push(newElement);
        cpSelectedElement = slide.elements.length - 1;
        cpSelectedElements.clear();
        cpSelectedElements.add(cpSelectedElement);
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
        showToast('Élément dupliqué', 'success');
    }
}

// Dupliquer tous les éléments sélectionnés
function cpDuplicateSelected() {
    cpHideContextMenu();
    cpSyncSelection();
    if (cpSelectedElements.size === 0) return;
    
    var activity = getSelectedActivity();
    if (!activity) return;
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) return;
    
    var indices = Array.from(cpSelectedElements).sort(function(a,b){return a-b;});
    var newIndices = [];
    
    indices.forEach(function(idx) {
        var element = slide.elements[idx];
        if (!element) return;
        var newElement = JSON.parse(JSON.stringify(element));
        newElement.x = Math.min((newElement.x || 10) + 5, 90);
        newElement.y = Math.min((newElement.y || 10) + 5, 90);
        if (newElement.action && newElement.action.subContentId) {
            newElement.action.subContentId = generateUUID();
        }
        slide.elements.push(newElement);
        newIndices.push(slide.elements.length - 1);
    });
    
    // Sélectionner les nouveaux éléments
    cpSelectedElements.clear();
    newIndices.forEach(function(i) { cpSelectedElements.add(i); });
    cpSelectedElement = newIndices.length > 0 ? newIndices[newIndices.length - 1] : null;
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast(newIndices.length > 1 ? newIndices.length + ' éléments dupliqués' : 'Élément dupliqué', 'success');
}

function cpBringToFront(elementIdx) {
    cpHideContextMenu();
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) return;
    
    cpSyncSelection();
    var indices = (elementIdx !== undefined && cpSelectedElements.size <= 1) 
        ? [elementIdx] 
        : Array.from(cpSelectedElements).sort(function(a,b){return a-b;});
    
    if (indices.length === 0) return;
    
    // Extraire les éléments (du plus grand index au plus petit pour ne pas décaler)
    var extracted = [];
    var sortedDesc = indices.slice().sort(function(a,b){return b-a;});
    sortedDesc.forEach(function(idx) {
        if (idx < slide.elements.length) {
            extracted.unshift(slide.elements.splice(idx, 1)[0]);
        }
    });
    
    // Les remettre à la fin (premier plan)
    extracted.forEach(function(el) { slide.elements.push(el); });
    
    // Mettre à jour la sélection
    cpSelectedElements.clear();
    for (var i = slide.elements.length - extracted.length; i < slide.elements.length; i++) {
        cpSelectedElements.add(i);
    }
    cpSelectedElement = slide.elements.length - 1;
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast('Mis au premier plan', 'success');
}

function cpSendToBack(elementIdx) {
    cpHideContextMenu();
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || !slide.elements) return;
    
    cpSyncSelection();
    var indices = (elementIdx !== undefined && cpSelectedElements.size <= 1) 
        ? [elementIdx] 
        : Array.from(cpSelectedElements).sort(function(a,b){return a-b;});
    
    if (indices.length === 0) return;
    
    // Extraire les éléments (du plus grand index au plus petit)
    var extracted = [];
    var sortedDesc = indices.slice().sort(function(a,b){return b-a;});
    sortedDesc.forEach(function(idx) {
        if (idx < slide.elements.length) {
            extracted.unshift(slide.elements.splice(idx, 1)[0]);
        }
    });
    
    // Les remettre au début (arrière-plan)
    for (var i = extracted.length - 1; i >= 0; i--) {
        slide.elements.unshift(extracted[i]);
    }
    
    // Mettre à jour la sélection
    cpSelectedElements.clear();
    for (var i = 0; i < extracted.length; i++) {
        cpSelectedElements.add(i);
    }
    cpSelectedElement = 0;
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast("Mis à l'arrière-plan", 'success');
}

function cpPasteElement(fromContextMenu) {
    cpHideContextMenu();
    
    if (!cpClipboardElements || cpClipboardElements.length === 0) {
        showToast('Rien à coller', 'info');
        return;
    }
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide) return;
    if (!slide.elements) slide.elements = [];
    
    var newIndices = [];
    
    cpClipboardElements.forEach(function(clipEl, i) {
        var newElement = JSON.parse(JSON.stringify(clipEl));
        
        if (fromContextMenu && i === 0) {
            // Clic-droit : positionner au point du clic, décaler les suivants
            var offsetX = cpContextMenuPosition.x - (newElement.x || 0);
            var offsetY = cpContextMenuPosition.y - (newElement.y || 0);
            newElement.x = Math.max(0, Math.min(cpContextMenuPosition.x, 100 - (newElement.width || 30)));
            newElement.y = Math.max(0, Math.min(cpContextMenuPosition.y, 100 - (newElement.height || 20)));
            // Stocker l'offset pour les éléments suivants
            cpClipboardElements._pasteOffset = { x: offsetX, y: offsetY };
        } else if (fromContextMenu && i > 0 && cpClipboardElements._pasteOffset) {
            // Multi-éléments via clic-droit : appliquer le même offset relatif
            var ox = cpClipboardElements._pasteOffset.x;
            var oy = cpClipboardElements._pasteOffset.y;
            newElement.x = Math.max(0, Math.min((newElement.x || 0) + ox, 100 - (newElement.width || 10)));
            newElement.y = Math.max(0, Math.min((newElement.y || 0) + oy, 100 - (newElement.height || 10)));
        }
        // Sinon (Ctrl+V) : garder les positions d'origine (coller en place)
        
        if (newElement.action && newElement.action.subContentId) {
            newElement.action.subContentId = generateUUID();
        }
        
        slide.elements.push(newElement);
        newIndices.push(slide.elements.length - 1);
    });
    
    // Sélectionner les éléments collés
    delete cpClipboardElements._pasteOffset;
    cpSelectedElements.clear();
    newIndices.forEach(function(i) { cpSelectedElements.add(i); });
    cpSelectedElement = newIndices.length > 0 ? newIndices[newIndices.length - 1] : null;
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast(newIndices.length > 1 ? newIndices.length + ' éléments collés' : 'Élément collé', 'success');
}

// ==================== FONCTIONS DRAG & DROP ====================

// Initialiser la structure DragQuestion si nécessaire
function cpDqEnsureStructure(element) {
    if (!element.action.params) element.action.params = {};
    if (!element.action.params.question) element.action.params.question = {};
    if (!element.action.params.question.settings) element.action.params.question.settings = { size: { width: 800, height: 400 }, background: {} };
    if (!element.action.params.question.task) element.action.params.question.task = { elements: [], dropZones: [] };
    return element;
}

// Upload d'image de fond
function cpDqUploadBackground(input) {
    if (!input.files || !input.files[0]) return;
    
    const file = input.files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('action', 'upload_file');
    
    showToast('Upload en cours...', 'info');
    
    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.url) {
            cpDqSetBackgroundUrl(data.url);
            showToast('Image uploadée', 'success');
        } else {
            showToast('Erreur upload: ' + (data.error || 'Erreur inconnue'), 'error');
        }
    })
    .catch(err => {
        showToast('Erreur réseau', 'error');
        console.error(err);
    });
}

// ==================== EXTRACTION BLOCS MAKECODE ====================
// Utilise MKExtract (makecode_extract.js) côté client — aucune dépendance serveur.

// Extraire les blocs depuis un fichier image uploadé
function cpDqExtractBlocksFromFile(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    if (!file.type.startsWith('image/')) {
        showToast('Fichier non image', 'error');
        return;
    }
    cpDqLoadImageAndExtract(file);
    input.value = '';
}

// Extraire les blocs depuis le presse-papier (Ctrl+V)
function cpDqExtractBlocksFromClipboard() {
    // clipboard.read() fonctionne au clic (geste utilisateur) sur Chrome/Edge
    // La 1ère fois le navigateur demande l'autorisation, ensuite c'est direct
    if (navigator.clipboard && navigator.clipboard.read) {
        navigator.clipboard.read().then(function(items) {
            for (var i = 0; i < items.length; i++) {
                var imageType = items[i].types.find(function(t) { return t.startsWith('image/'); });
                if (imageType) {
                    items[i].getType(imageType).then(function(blob) {
                        cpDqLoadImageAndExtract(blob);
                    });
                    return;
                }
            }
            showToast('Aucune image dans le presse-papier. Copiez d\'abord une capture MakeCode.', 'warning');
        }).catch(function(err) {
            showToast('Accès refusé. Utilisez Ctrl+V à la place.', 'warning');
        });
    } else {
        // Firefox : clipboard.read() non supporté, Ctrl+V uniquement
        showToast('Utilisez Ctrl+V pour coller l\'image', 'info');
    }
}

// Fallback supprimé — le gestionnaire global de paste (en bas) gère Ctrl+V

// Charger un blob/file image dans un canvas et lancer l'extraction
function cpDqLoadImageAndExtract(blob) {
    var statusEl = document.getElementById('cpDqBlocksStatus');
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
        
        // Lancer l'extraction en asynchrone pour ne pas bloquer l'UI
        setTimeout(function() {
            try {
                var result = MKExtract.extract(imageData);
                cpDqProcessExtractionResult(result, imageData, canvas);
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

// Traiter le résultat de MKExtract : router selon le type d'image
function cpDqProcessExtractionResult(result, imageData, srcCanvas) {
    var manifest = result.manifest;
    
    if (manifest.imageType === 'flowchart') {
        cpDqProcessFlowchartResult(result, imageData, srcCanvas);
    } else {
        cpDqProcessBlocksResult(result, imageData, srcCanvas);
    }
}

// Traiter un résultat FLOWCHART : extraire les étiquettes de texte des formes
function cpDqProcessFlowchartResult(result, imageData, srcCanvas) {
    var manifest = result.manifest;
    var labelMasks = result.labelMasks;
    var w = manifest.size.w, h = manifest.size.h;
    var rgba = imageData.data;
    var statusEl = document.getElementById('cpDqBlocksStatus');
    var labels = manifest.labels || [];
    var labelSize = manifest.labelSize;
    
    if (labels.length === 0) {
        if (statusEl) statusEl.innerHTML = '<div style="color:#f57c00; font-size: 0.7rem;">Aucune étiquette détectée</div>';
        showToast('Aucune étiquette détectée dans l\'organigramme', 'warning');
        return;
    }
    
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Génération des images (' + labels.length + ' étiquettes)...</div>';
    
    // Zone de pioche à droite
    var stagingPad = 20;
    var labelPadding = 8;
    var totalLabelsH = labels.length * (labelSize.h + labelPadding);
    var extW = w + stagingPad + labelSize.w + 10;
    var extH = Math.max(h, totalLabelsH + 20);
    
    // 1. Image de fond : original avec les zones de texte blanchies
    var bgCanvas = document.createElement('canvas');
    bgCanvas.width = extW;
    bgCanvas.height = extH;
    var bgCtx = bgCanvas.getContext('2d');
    
    // Fond blanc partout
    bgCtx.fillStyle = '#ffffff';
    bgCtx.fillRect(0, 0, extW, extH);
    
    // Copier l'image originale
    bgCtx.drawImage(srcCanvas, 0, 0);
    
    // Blanchir les zones de texte (via les masques)
    var bgImageData = bgCtx.getImageData(0, 0, w, h);
    var bgData = bgImageData.data;
    for (var li = 0; li < labels.length; li++) {
        var mask = labelMasks[li];
        for (var idx = 0; idx < w * h; idx++) {
            if (mask[idx]) {
                var o = idx * 4;
                bgData[o] = 255;
                bgData[o + 1] = 255;
                bgData[o + 2] = 255;
                bgData[o + 3] = 255;
            }
        }
    }
    bgCtx.putImageData(bgImageData, 0, 0);
    
    // Séparateur discret
    bgCtx.strokeStyle = 'rgba(0,0,0,0.08)';
    bgCtx.setLineDash([4, 4]);
    bgCtx.beginPath();
    bgCtx.moveTo(w + stagingPad / 2, 5);
    bgCtx.lineTo(w + stagingPad / 2, extH - 5);
    bgCtx.stroke();
    bgCtx.setLineDash([]);
    
    // 2. Générer les PNG de chaque étiquette (texte sur fond transparent via les masques)
    var labelCanvases = [];
    for (var li = 0; li < labels.length; li++) {
        var label = labels[li];
        var lx = Math.max(0, label.pos.x);
        var ly = Math.max(0, label.pos.y);
        var lw = label.size.w;
        var lh = label.size.h;
        var mask = labelMasks[li];
        
        var labelCanvas = document.createElement('canvas');
        labelCanvas.width = lw;
        labelCanvas.height = lh;
        var labelCtx = labelCanvas.getContext('2d');
        var labelImgData = labelCtx.createImageData(lw, lh);
        var ld = labelImgData.data;
        
        // Parcourir les pixels de la zone de l'étiquette
        // Ne garder que les pixels sombres (texte) identifiés par le masque
        for (var py = 0; py < lh; py++) {
            for (var px = 0; px < lw; px++) {
                var srcX = lx + px, srcY = ly + py;
                if (srcX < 0 || srcX >= w || srcY < 0 || srcY >= h) continue;
                var srcIdx = srcY * w + srcX;
                var dstOff = (py * lw + px) * 4;
                var srcOff = srcIdx * 4;
                // Pixel dans le masque texte → le garder, sinon transparent
                if (mask[srcIdx]) {
                    ld[dstOff] = rgba[srcOff];
                    ld[dstOff + 1] = rgba[srcOff + 1];
                    ld[dstOff + 2] = rgba[srcOff + 2];
                    ld[dstOff + 3] = 255;
                }
                // sinon ld reste à 0,0,0,0 (transparent)
            }
        }
        labelCtx.putImageData(labelImgData, 0, 0);
        labelCanvases.push(labelCanvas);
    }

    // Détection des étiquettes dupliquées (CP : on garde tout, on étendra correctElements)
    var sigs = labelCanvases.map(sigFromCanvas);
    var paramsFlags = labelCanvases.map(paramFromCanvas);
    var dupGroups = thresholdClustering(labels, sigs, undefined, undefined, paramsFlags);
    var labelToGroup = new Array(labels.length);
    dupGroups.forEach(function(g, gi) { g.forEach(function(i) { labelToGroup[i] = gi; }); });

    // 3. Uploader tout (fond + étiquettes)
    var totalUploads = 1 + labelCanvases.length;
    var uploadsDone = 0;
    var bgUrl = null;
    var labelUrls = new Array(labelCanvases.length);

    function checkAllUploaded() {
        uploadsDone++;
        if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Upload ' + uploadsDone + '/' + totalUploads + '...</div>';
        if (uploadsDone >= totalUploads) {
            cpDqApplyBlockExtraction({
                source_size: { w: w, h: h },
                extended_size: { w: extW, h: extH },
                background_url: bgUrl,
                maxBlockSize: { w: labelSize.w, h: labelSize.h },
                pad: 0,
                blocks: labels.map(function(l, i) {
                    return { id: l.id, url: labelUrls[i], pos: l.pos, size: l.size, type: 'block' };
                }),
                groups: dupGroups,
                labelToGroup: labelToGroup
            });
        }
    }
    
    bgCanvas.toBlob(function(blob) {
        cpDqUploadBlob(blob, 'background.png', function(url) { bgUrl = url; checkAllUploaded(); },
            function(err) { showToast('Erreur upload fond: ' + err, 'error'); checkAllUploaded(); });
    }, 'image/png');
    
    labelCanvases.forEach(function(lc, i) {
        lc.toBlob(function(blob) {
            cpDqUploadBlob(blob, 'label_' + i + '.png', function(url) { labelUrls[i] = url; checkAllUploaded(); },
                function(err) { showToast('Erreur upload étiquette ' + i + ': ' + err, 'error'); checkAllUploaded(); });
        }, 'image/png');
    });
}

// Traiter un résultat BLOCKS (MakeCode/Scratch) : générer les PNG et uploader
function cpDqProcessBlocksResult(result, imageData, srcCanvas) {
    var manifest = result.manifest;
    var blockMasks = result.blockMasks;
    var bgColor = result.bgColor;
    var w = manifest.size.w, h = manifest.size.h;
    var rgba = imageData.data;
    var statusEl = document.getElementById('cpDqBlocksStatus');
    
    // Séparer conteneurs et blocs d'action
    var containerBlocks = manifest.blocks.filter(function(b) { return b.type === 'container'; });
    var actionBlocks = manifest.blocks.filter(function(b) { return b.type === 'block' || b.type === 'diamond'; });
    
    if (actionBlocks.length === 0) {
        if (statusEl) statusEl.innerHTML = '<div style="color:#f57c00; font-size: 0.7rem;">Aucun bloc d\'action détecté</div>';
        showToast('Aucun bloc d\'action détecté (uniquement des conteneurs)', 'warning');
        return;
    }
    
    if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Génération des images (' + actionBlocks.length + ' blocs)...</div>';
    
    // Calculer la taille max des blocs (sans le padding d'extraction)
    var PAD = 3; // EXTRACTION_PAD du script
    var maxBlockW = 0, maxBlockH = 0;
    actionBlocks.forEach(function(b) {
        var realW = b.size.w - 2 * PAD;
        var realH = b.size.h - 2 * PAD;
        if (realW > maxBlockW) maxBlockW = realW;
        if (realH > maxBlockH) maxBlockH = realH;
    });
    
    // Hauteur totale pour empiler les étiquettes dans la pioche
    var labelPadding = 8;
    var totalLabelsH = actionBlocks.reduce(function(sum, b) { return sum + b.size.h + labelPadding; }, 0);
    
    // Canvas élargi : image originale + zone de pioche à droite
    var stagingPad = 20;
    var extW = w + stagingPad + maxBlockW + 2 * PAD + 10;
    var extH = Math.max(h, totalLabelsH + 20);
    
    // 1. Générer l'image de fond : FOND BLANC + conteneurs dessinés depuis l'original
    var bgCanvas = document.createElement('canvas');
    bgCanvas.width = extW;
    bgCanvas.height = extH;
    var bgCtx = bgCanvas.getContext('2d');
    
    // Fond blanc partout
    bgCtx.fillStyle = '#ffffff';
    bgCtx.fillRect(0, 0, extW, extH);
    
    // Dessiner uniquement les pixels des conteneurs SANS les blocs d'action imbriqués
    if (containerBlocks.length > 0) {
        // Construire un masque combiné de tous les blocs d'action à exclure
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
                // Pixel du conteneur SAUF si c'est aussi un bloc d'action
                if (cMask[idx] && !actionMask[idx]) {
                    var o = idx * 4;
                    cData[o] = rgba[o];
                    cData[o + 1] = rgba[o + 1];
                    cData[o + 2] = rgba[o + 2];
                    cData[o + 3] = 255;
                }
            }
        }
        bgCtx.putImageData(containerImageData, 0, 0);
    }
    
    // Séparateur discret entre programme et zone pioche
    bgCtx.strokeStyle = 'rgba(0,0,0,0.08)';
    bgCtx.setLineDash([4, 4]);
    bgCtx.beginPath();
    bgCtx.moveTo(w + stagingPad / 2, 5);
    bgCtx.lineTo(w + stagingPad / 2, extH - 5);
    bgCtx.stroke();
    bgCtx.setLineDash([]);
    
    // 2. Générer les PNG de chaque bloc (avec transparence)
    var blockCanvases = [];
    for (var bi = 0; bi < actionBlocks.length; bi++) {
        var blockInfo = actionBlocks[bi];
        var mask = blockMasks[blockInfo.id];
        var bx = blockInfo.pos.x, by = blockInfo.pos.y;
        var bw = blockInfo.size.w, bh = blockInfo.size.h;
        
        var blockCanvas = document.createElement('canvas');
        blockCanvas.width = bw;
        blockCanvas.height = bh;
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
                bd[dstOff] = rgba[srcOff];
                bd[dstOff + 1] = rgba[srcOff + 1];
                bd[dstOff + 2] = rgba[srcOff + 2];
                bd[dstOff + 3] = mask[srcIdx] ? 255 : 0;
            }
        }
        blockCtx.putImageData(blockImgData, 0, 0);
        blockCanvases.push(blockCanvas);
    }

    // Détection des étiquettes dupliquées (CP : on garde tout, on étendra correctElements)
    var sigs = blockCanvases.map(sigFromCanvas);
    var paramsFlags = blockCanvases.map(paramFromCanvas);
    var dupGroups = thresholdClustering(actionBlocks, sigs, undefined, undefined, paramsFlags);
    var labelToGroup = new Array(actionBlocks.length);
    dupGroups.forEach(function(g, gi) { g.forEach(function(i) { labelToGroup[i] = gi; }); });

    // 3. Uploader tout (fond + blocs)
    var totalUploads = 1 + blockCanvases.length;
    var uploadsDone = 0;
    var bgUrl = null;
    var blockUrls = new Array(blockCanvases.length);

    function checkAllUploaded() {
        uploadsDone++;
        if (statusEl) statusEl.innerHTML = '<div class="cp-dq-blocks-loading"><span class="cp-dq-blocks-spinner"></span> Upload ' + uploadsDone + '/' + totalUploads + '...</div>';
        if (uploadsDone >= totalUploads) {
            cpDqApplyBlockExtraction({
                source_size: { w: w, h: h },
                extended_size: { w: extW, h: extH },
                background_url: bgUrl,
                maxBlockSize: { w: maxBlockW, h: maxBlockH },
                pad: 3,
                blocks: actionBlocks.map(function(b, i) {
                    return { id: b.id, url: blockUrls[i], pos: b.pos, size: b.size, type: b.type };
                }),
                groups: dupGroups,
                labelToGroup: labelToGroup
            });
        }
    }
    
    bgCanvas.toBlob(function(blob) {
        cpDqUploadBlob(blob, 'background.png', function(url) { bgUrl = url; checkAllUploaded(); },
            function(err) { showToast('Erreur upload fond: ' + err, 'error'); checkAllUploaded(); });
    }, 'image/png');
    
    blockCanvases.forEach(function(bc, i) {
        bc.toBlob(function(blob) {
            cpDqUploadBlob(blob, 'block_' + i + '.png', function(url) { blockUrls[i] = url; checkAllUploaded(); },
                function(err) { showToast('Erreur upload bloc ' + i + ': ' + err, 'error'); checkAllUploaded(); });
        }, 'image/png');
    });
}

// Upload d'un blob image via l'endpoint existant
function cpDqUploadBlob(blob, filename, onSuccess, onError) {
    var formData = new FormData();
    formData.append('file', blob, filename);
    formData.append('action', 'upload_file');
    fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.url) onSuccess(data.url);
            else onError(data.error || 'Erreur inconnue');
        })
        .catch(function(err) { onError(err.message); });
}

// Appliquer le résultat de l'extraction : fond + zones + étiquettes
function cpDqApplyBlockExtraction(data) {
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    var srcW = data.source_size.w;    // image originale
    var srcH = data.source_size.h;
    var extW = data.extended_size.w;  // canvas élargi
    var extH = data.extended_size.h;
    var actionBlocks = data.blocks;
    
    if (actionBlocks.length === 0) {
        showToast('Aucun bloc d\'action détecté', 'warning');
        return;
    }
    
    // 1. Canvas interne = dimensions élargies
    element.action.params.question.settings.size = { width: extW, height: extH };
    
    // 2. Image de fond (élargie)
    if (data.background_url) {
        element.action.params.question.settings.background = {
            path: data.background_url,
            mime: 'image/png',
            copyright: { license: 'U' }
        };
    }
    
    // 3. Positionner en haut du slide et étirer proportionnellement jusqu'en bas
    var imgRatio = extW / extH;
    var slideRatio = 2;
    var buttonRatio = 1.18;
    // Hauteur max = 100%, on calcule la largeur proportionnelle
    var newHeight = 100;
    var newWidth = newHeight * imgRatio / (slideRatio * buttonRatio);
    if (newWidth > 100) { newWidth = 100; newHeight = newWidth * slideRatio * buttonRatio / imgRatio; }
    element.x = 0;
    element.y = 0;
    element.width = newWidth;
    element.height = newHeight;
    
    // 4. Vider les zones et éléments existants
    element.action.params.question.task.elements = [];
    element.action.params.question.task.dropZones = [];
    var elements = element.action.params.question.task.elements;
    var dropZones = element.action.params.question.task.dropZones;
    
    // 5. Créer les zones de dépôt
    //    - LARGEUR UNIFORME (= plus large bloc réel) pour ne pas donner d'indice
    //    - Hauteur = hauteur réelle du bloc (sans EXTRACTION_PAD)
    //    - Position = bord gauche/haut réel du bloc (sans EXTRACTION_PAD)
    //    Position x,y en % du canvas. Taille width,height en em (px/16).
    var PAD = data.pad !== undefined ? data.pad : 3; // EXTRACTION_PAD (0 pour flowcharts)
    var maxRealW = data.maxBlockSize.w; // déjà sans PAD pour les blocs, taille réelle pour flowcharts
    var uniformW_em = maxRealW / 16;
    
    console.log('[BlockExtract] Canvas:', extW, '×', extH, '| Zones: largeur uniforme =', maxRealW, 'px =', uniformW_em.toFixed(1), 'em');
    
    actionBlocks.forEach(function(block, idx) {
        // Position réelle (retirer le padding d'extraction)
        var realX = block.pos.x + PAD;
        var realY = block.pos.y + PAD;
        var realH = block.size.h - 2 * PAD;
        
        var zoneX_pct = (realX / extW) * 100;
        var zoneY_pct = (realY / extH) * 100;
        var zH_em = realH / 16;
        
        console.log('[BlockExtract] Zone', idx, ':',
            'raw pos=(' + block.pos.x + ',' + block.pos.y + ') size=(' + block.size.w + '×' + block.size.h + ')',
            '→ real=(' + realX + ',' + realY + ') h=' + realH,
            '→ x=' + zoneX_pct.toFixed(1) + '% y=' + zoneY_pct.toFixed(1) + '%',
            'w=' + uniformW_em.toFixed(1) + 'em h=' + zH_em.toFixed(1) + 'em');
        
        dropZones.push({
            x: zoneX_pct,
            y: zoneY_pct,
            width: uniformW_em,
            height: zH_em,
            correctElements: [],  // sera rempli après le shuffle
            showLabel: false,
            backgroundOpacity: 0,
            tipsAndFeedback: { tip: '' },
            single: true,
            autoAlign: false,
            label: '<div>Zone ' + (idx + 1) + '</div>',
            type: { library: 'H5P.DragQuestionDropzone 0.1' }
        });
    });
    
    // 6. Mélanger l'ordre des étiquettes (Fisher-Yates)
    var shuffledIndices = actionBlocks.map(function(_, i) { return i; });
    for (var si = shuffledIndices.length - 1; si > 0; si--) {
        var sj = Math.floor(Math.random() * (si + 1));
        var tmp = shuffledIndices[si];
        shuffledIndices[si] = shuffledIndices[sj];
        shuffledIndices[sj] = tmp;
    }
    
    // Table inverse : shuffledIndices[elemIdx] = blockIdx
    // → correctElements de la zone blockIdx doit contenir elemIdx
    // Étiquettes dupliquées : chaque zone d'un groupe accepte toutes les copies du groupe
    var blockIdxToElemIdx = {};
    for (var ei = 0; ei < shuffledIndices.length; ei++) blockIdxToElemIdx[shuffledIndices[ei]] = ei;
    var hasGroups = data.groups && data.labelToGroup;
    for (var bi = 0; bi < actionBlocks.length; bi++) {
        var groupBlocks = hasGroups ? data.groups[data.labelToGroup[bi]] : [bi];
        groupBlocks.forEach(function(gbi) {
            dropZones[bi].correctElements.push(String(blockIdxToElemIdx[gbi]));
        });
    }
    
    // 7. Position de la zone de pioche (droite du programme)
    var allZoneIndices = actionBlocks.map(function(_, i) { return String(i); });
    var stagingX_pct = (srcW + 20) / extW * 100;
    var labelPadding = 8;
    var labelY_px = 10;
    
    // Pré-calculer les positions Y des étiquettes dans l'ordre mélangé
    var labelPositions = [];
    for (var li = 0; li < shuffledIndices.length; li++) {
        var blockIdx = shuffledIndices[li];
        var bh = actionBlocks[blockIdx].size.h;
        labelPositions.push({ blockIdx: blockIdx, y_pct: (labelY_px / extH) * 100 });
        labelY_px += bh + labelPadding;
    }
    
    // 8. Créer les étiquettes dans l'ordre mélangé
    var blocksToLoad = actionBlocks.length;
    var blocksLoaded = 0;
    
    labelPositions.forEach(function(lp, elemIdx) {
        var block = actionBlocks[lp.blockIdx];
        var elemW_em = block.size.w / 16;
        var elemH_em = block.size.h / 16;
        var elemY_pct = lp.y_pct;
        
        var img = new Image();
        img.onload = function() {
            elements[elemIdx] = {
                x: stagingX_pct,
                y: elemY_pct,
                width: elemW_em,
                height: elemH_em,
                dropZones: allZoneIndices.slice(),
                type: {
                    library: 'H5P.Image 1.1',
                    params: {
                        decorative: false, contentName: 'Image',
                        expandImage: 'Expand Image', minimizeImage: 'Minimize Image',
                        file: { path: block.url, mime: 'image/png', copyright: { license: 'U' }, width: img.width, height: img.height },
                        alt: 'Bloc ' + (elemIdx + 1)
                    },
                    subContentId: generateUUID(),
                    metadata: { contentType: 'Image', license: 'U', title: 'Bloc ' + (elemIdx + 1) }
                },
                backgroundOpacity: 0,
                multiple: false
            };
            blocksLoaded++;
            if (blocksLoaded >= blocksToLoad) cpDqFinalizeBlockExtraction(element, actionBlocks.length);
        };
        img.onerror = function() {
            elements[elemIdx] = {
                x: stagingX_pct, y: elemY_pct, width: elemW_em, height: elemH_em,
                dropZones: allZoneIndices.slice(),
                type: {
                    library: 'H5P.Image 1.1',
                    params: { decorative: false, contentName: 'Image', expandImage: 'Expand Image', minimizeImage: 'Minimize Image',
                        file: { path: block.url, mime: 'image/png', copyright: { license: 'U' }, width: block.size.w, height: block.size.h },
                        alt: 'Bloc ' + (elemIdx + 1) },
                    subContentId: generateUUID(),
                    metadata: { contentType: 'Image', license: 'U', title: 'Bloc ' + (elemIdx + 1) }
                },
                backgroundOpacity: 0, multiple: false
            };
            blocksLoaded++;
            if (blocksLoaded >= blocksToLoad) cpDqFinalizeBlockExtraction(element, actionBlocks.length);
        };
        img.src = block.url;
    });
}

// Finaliser après chargement de toutes les images
function cpDqFinalizeBlockExtraction(element, blockCount) {
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    
    var statusEl = document.getElementById('cpDqBlocksStatus');
    if (statusEl) statusEl.innerHTML = '<div style="color:#2e7d32; font-size: 0.7rem;">✓ ' + blockCount + ' blocs extraits</div>';
    showToast(blockCount + ' blocs extraits et configurés', 'success');
}

// ==================== PRESETS DRAGDROP ====================
// Données extraites d'un parcours Éléa de référence, normalisées
var CP_DQ_PRESETS = {
    'capteurs-actionneurs': {
        image: 'assets/images/dragdrop/_Capteurs-Actionneurs.png',
        box: { x: 15, y: 15, w: 70, h: 65 },
        canvas: { width: 796, height: 310 },
        elementSize: { w: 7.00, h: 3.00 },
        elements: [],
        dropZones: [
            { x: 32.04, y: 0, w: 16.03, h: 19.38 },
            { x: 64.38, y: 0, w: 18.01, h: 19.36 }
        ]
    },
    'actionneurs': {
        image: 'assets/images/dragdrop/_Actionneurs.png',
        box: { x: 15, y: 15, w: 70, h: 65 },
        canvas: { width: 796, height: 310 },
        elementSize: { w: 7.00, h: 3.00 },
        elements: [],
        dropZones: [
            { x: 32.04, y: 0, w: 16.03, h: 19.38 },
            { x: 64.49, y: 0, w: 17.85, h: 19.41 }
        ]
    },
    'capteurs': {
        image: 'assets/images/dragdrop/_Capteurs.png',
        box: { x: 15, y: 15, w: 70, h: 65 },
        canvas: { width: 796, height: 310 },
        elementSize: { w: 7.00, h: 3.00 },
        elements: [],
        dropZones: [
            { x: 32.04, y: 0, w: 16.03, h: 19.38 },
            { x: 64.49, y: 0, w: 17.85, h: 19.41 }
        ]
    },
    'chaine-information': {
        image: 'assets/images/dragdrop/chaine-information.png',
        box: { x: 20.7, y: 17.3, w: 63.2, h: 63.4 },
        canvas: { width: 762, height: 278 },
        elementSize: { w: 7.02, h: 3.02 },
        elements: [
            { x: 6.3, y: 6.0 },
            { x: 24.9, y: 6.0 },
            { x: 43.9, y: 6.0 },
            { x: 62.2, y: 6.0 }
        ],
        dropZones: [
            { x: 2.0, y: 66.8, w: 8.02, h: 3.60 },
            { x: 26.0, y: 69.7, w: 8.95, h: 3.94 },
            { x: 51.1, y: 70.2, w: 9.04, h: 3.77 },
            { x: 77.8, y: 69.7, w: 8.78, h: 3.85 }
        ]
    },
    'chaine-energie': {
        image: 'assets/images/dragdrop/chaine-energie.png',
        box: { x: 18.2, y: 19.3, w: 63.9, h: 61.8 },
        canvas: { width: 774, height: 280 },
        elementSize: { w: 6.79, h: 2.94 },
        elements: [
            { x: 7.7, y: 6.2 },
            { x: 25.5, y: 6.2 },
            { x: 43.9, y: 6.2 },
            { x: 62.2, y: 6.2 },
            { x: 77.8, y: 6.2 }
        ],
        dropZones: [
            { x: 1.1, y: 56.7, w: 6.70, h: 2.98 },
            { x: 18.9, y: 71.1, w: 8.95, h: 3.94 },
            { x: 39.1, y: 72.0, w: 9.04, h: 3.77 },
            { x: 59.8, y: 71.5, w: 8.78, h: 3.85 },
            { x: 80.3, y: 71.5, w: 8.75, h: 3.82 }
        ]
    },
    'chaine-complete': {
        image: 'assets/images/dragdrop/chaine-complete.png',
        box: { x: 13.9, y: 3.5, w: 72.9, h: 92.9 },
        canvas: { width: 1118, height: 586 },
        elementSize: { w: 8.64, h: 3.78 },
        elements: [
            { x: 4.2, y: 2.3 },
            { x: 4.2, y: 14.6 },
            { x: 4.2, y: 26.9 },
            { x: 4.2, y: 39.2 },
            { x: 4.2, y: 51.5 },
            { x: 4.2, y: 63.8 },
            { x: 18.8, y: 2.9 },
            { x: 18.8, y: 15.1 },
            { x: 18.8, y: 27.3 },
            { x: 18.8, y: 39.5 }
        ],
        dropZones: [
            { x: 34.0, y: 32.9, w: 8.66, h: 4.07 },
            { x: 51.0, y: 33.2, w: 8.46, h: 3.68 },
            { x: 68.9, y: 33.2, w: 8.76, h: 3.78 },
            { x: 18.3, y: 69.4, w: 6.89, h: 2.79 },
            { x: 30.1, y: 75.6, w: 8.76, h: 3.78 },
            { x: 44.4, y: 75.8, w: 8.86, h: 3.78 },
            { x: 58.6, y: 75.8, w: 8.46, h: 3.68 },
            { x: 72.7, y: 75.8, w: 8.66, h: 3.68 },
            { x: 88.3, y: 56.2, w: 7.19, h: 2.99 },
            { x: 88.2, y: 80.1, w: 7.29, h: 3.19 }
        ]
    }
};

// Appliquer un preset DragDrop complet (image + étiquettes + zones + taille de la boîte)
function cpDqApplyPreset(presetName) {
    var preset = CP_DQ_PRESETS[presetName];
    if (!preset) {
        showToast('Preset inconnu: ' + presetName, 'error');
        return;
    }
    
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    // 1. Position et taille de la boîte sur le slide
    element.x = preset.box.x;
    element.y = preset.box.y;
    element.width = preset.box.w;
    element.height = preset.box.h;
    
    // 2. Canvas interne = dimensions de l'image
    element.action.params.question.settings.size = {
        width: preset.canvas.width,
        height: preset.canvas.height
    };
    
    // 3. Image de fond
    element.action.params.question.settings.background = {
        path: preset.image,
        mime: 'image/png',
        copyright: { license: 'U' }
    };
    
    // 4. Générer les étiquettes avec taille normalisée
    var numElements = preset.elements.length;
    var numZones = preset.dropZones.length;
    var allZoneIndices = [];
    for (var z = 0; z < numZones; z++) allZoneIndices.push(String(z));
    
    var elements = [];
    for (var i = 0; i < numElements; i++) {
        elements.push({
            x: preset.elements[i].x,
            y: preset.elements[i].y,
            width: preset.elementSize.w,
            height: preset.elementSize.h,
            dropZones: allZoneIndices.slice(), // toutes les zones (pour pouvoir déposer partout)
            type: {
                library: 'H5P.AdvancedText 1.1',
                params: { text: '<p>Étiquette ' + (i + 1) + '</p>' },
                subContentId: generateUUID(),
                metadata: { contentType: 'Text', license: 'U', title: 'Sans titre Text' }
            },
            backgroundOpacity: 100,
            multiple: false
        });
    }
    
    // 5. Générer les zones de dépôt avec les bonnes positions/tailles
    var dropZones = [];
    for (var j = 0; j < numZones; j++) {
        var dzPreset = preset.dropZones[j];
        dropZones.push({
            x: dzPreset.x,
            y: dzPreset.y,
            width: dzPreset.w,
            height: dzPreset.h,
            correctElements: (j < numElements) ? [String(j)] : [], // zone j → étiquette j (si elle existe)
            showLabel: false,
            backgroundOpacity: 0,
            tipsAndFeedback: { tip: '' },
            single: true,
            autoAlign: false,
            label: '<div>Zone ' + (j + 1) + '</div>',
            type: { library: 'H5P.DragQuestionDropzone 0.1' }
        });
    }
    
    element.action.params.question.task.elements = elements;
    element.action.params.question.task.dropZones = dropZones;
    
    // Déselectionner tout item DQ sélectionné
    cpDqSelectedItem = null;
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
    showToast('Preset "' + presetName + '" appliqué (' + numElements + ' étiquettes, ' + numZones + ' zones)', 'success');
}

// Définir l'URL de l'image de fond et adapter la boîte au ratio de l'image
function cpDqSetBackgroundUrl(url) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    let element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    if (url) {
        // Charger l'image pour obtenir ses dimensions
        const img = new Image();
        img.onload = function() {
            const imgRatio = img.naturalWidth / img.naturalHeight;
            
            // Adapter la taille du canvas interne aux dimensions de l'image
            element.action.params.question.settings.size = {
                width: img.naturalWidth,
                height: img.naturalHeight
            };
            
            element.action.params.question.settings.background = {
                path: url,
                mime: 'image/png',
                copyright: { license: 'U' }
            };
            
            // Calculer l'espace disponible en fonction de la position actuelle
            const currentX = element.x || 0;
            const currentY = element.y || 0;
            const maxWidth = 100 - currentX;
            const maxHeight = 100 - currentY;
            
            // Le slide a un ratio de 2:1 (largeur = 2× hauteur en pixels)
            // Les boutons ajoutent ~18% de hauteur en bas (buttonRatio = 1.18)
            const slideRatio = 2;
            const buttonRatio = 1.18;
            
            // Calculer la largeur max pour que la hauteur ne dépasse pas maxHeight
            // boxHeight% = boxWidth% × slideRatio × buttonRatio / imgRatio
            // Pour boxHeight% ≤ maxHeight : boxWidth% ≤ maxHeight × imgRatio / (slideRatio × buttonRatio)
            let newWidth = Math.min(maxWidth, maxHeight * imgRatio / (slideRatio * buttonRatio));
            let newHeight = newWidth * slideRatio * buttonRatio / imgRatio;
            
            // Sécurité : vérifier que rien ne dépasse
            if (newHeight > maxHeight) {
                newHeight = maxHeight;
                newWidth = newHeight * imgRatio / (slideRatio * buttonRatio);
            }
            if (newWidth > maxWidth) {
                newWidth = maxWidth;
                newHeight = newWidth * slideRatio * buttonRatio / imgRatio;
            }
            
            element.width = newWidth;
            element.height = newHeight;
            
            // Garder la position actuelle
            
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
            showToast(`Image: ${img.naturalWidth}×${img.naturalHeight}`, 'success');
        };
        img.onerror = function() {
            // Si l'image ne charge pas, définir quand même l'URL
            element.action.params.question.settings.background = {
                path: url,
                mime: 'image/png',
                copyright: { license: 'U' }
            };
            
            cpRenderSlideElements();
            cpRenderElementProps();
            onCourseModified();
        };
        img.src = url;
    } else {
        // Effacer l'image
        element.action.params.question.settings.background = {};
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
    }
}

// Ajouter une étiquette
function cpDqAddElement() {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    let element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    const elements = element.action.params.question.task.elements;
    const zones = element.action.params.question.task.dropZones;
    const newIdx = elements.length;
    
    // Par défaut, l'étiquette peut aller dans TOUTES les zones existantes
    // (sinon elle n'est pas déplaçable dans H5P)
    const allZones = zones.map((_, i) => String(i));
    
    elements.push({
        x: 5 + (newIdx * 8) % 40,
        y: 5,
        width: 5.5,   // em (affiché en % via 5.5 × 1600/canvasWidth)
        height: 2.5,   // em (affiché en % via 2.5 × 1600/canvasHeight)
        dropZones: allZones,
        type: {
            library: 'H5P.AdvancedText 1.1',
            params: { text: '<p>Étiquette ' + (newIdx + 1) + '</p>' },
            subContentId: generateUUID(),
            metadata: { contentType: 'Text', license: 'U', title: 'Sans titre Text' }
        },
        backgroundOpacity: 100,
        multiple: false
    });
    
    // Par défaut, étiquette N → zone N (bonne réponse)
    // Si la zone N existe, l'ajouter à ses correctElements
    if (newIdx < zones.length && zones[newIdx]) {
        if (!zones[newIdx].correctElements) zones[newIdx].correctElements = [];
        if (!zones[newIdx].correctElements.includes(String(newIdx))) {
            zones[newIdx].correctElements.push(String(newIdx));
        }
    }
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Ajouter une étiquette IMAGE (H5P.Image 1.1)
function cpDqAddImageElement() {
    // Ouvrir un file input pour sélectionner l'image
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function() {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        var formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload_file');
        
        fetch('api/editor_api.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showToast('Erreur upload: ' + (data.error || ''), 'error'); return; }
                cpDqInsertImageElement(data.url, file.name);
            })
            .catch(function(err) { showToast('Erreur upload', 'error'); });
    };
    input.click();
}

// Insérer l'élément image DQ après upload
function cpDqInsertImageElement(imagePath, fileName) {
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    var elements = element.action.params.question.task.elements;
    var zones = element.action.params.question.task.dropZones;
    var newIdx = elements.length;
    var allZones = zones.map(function(_, i) { return String(i); });
    
    // Charger l'image pour connaître ses dimensions
    var img = new Image();
    img.onload = function() {
        elements.push({
            x: 5 + (newIdx * 8) % 40,
            y: 75,
            width: 8,
            height: 8 * (img.height / img.width),
            dropZones: allZones,
            type: {
                library: 'H5P.Image 1.1',
                params: {
                    decorative: false,
                    contentName: 'Image',
                    expandImage: 'Expand Image',
                    minimizeImage: 'Minimize Image',
                    file: {
                        path: imagePath,
                        mime: fileName.match(/\.png$/i) ? 'image/png' : fileName.match(/\.gif$/i) ? 'image/gif' : fileName.match(/\.webp$/i) ? 'image/webp' : 'image/jpeg',
                        copyright: { license: 'U' },
                        width: img.width,
                        height: img.height
                    },
                    alt: fileName.replace(/\.\w+$/, '')
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Image', license: 'U', title: 'Sans titre Image' }
            },
            backgroundOpacity: 0,
            multiple: false
        });
        
        if (newIdx < zones.length && zones[newIdx]) {
            if (!zones[newIdx].correctElements) zones[newIdx].correctElements = [];
            if (!zones[newIdx].correctElements.includes(String(newIdx))) {
                zones[newIdx].correctElements.push(String(newIdx));
            }
        }
        
        cpRenderSlideElements();
        cpRenderElementProps();
        onCourseModified();
        showToast('Image ajoutée comme étiquette', 'success');
    };
    img.onerror = function() {
        // Fallback si on ne peut pas charger l'image
        elements.push({
            x: 5 + (newIdx * 8) % 40, y: 75, width: 8, height: 8,
            dropZones: allZones,
            type: {
                library: 'H5P.Image 1.1',
                params: { decorative: false, contentName: 'Image', expandImage: 'Expand Image', minimizeImage: 'Minimize Image',
                    file: { path: imagePath, mime: 'image/png', copyright: { license: 'U' }, width: 100, height: 100 },
                    alt: fileName.replace(/\.\w+$/, '')
                },
                subContentId: generateUUID(),
                metadata: { contentType: 'Image', license: 'U', title: 'Sans titre Image' }
            },
            backgroundOpacity: 0, multiple: false
        });
        if (newIdx < zones.length && zones[newIdx]) {
            if (!zones[newIdx].correctElements) zones[newIdx].correctElements = [];
            zones[newIdx].correctElements.push(String(newIdx));
        }
        cpRenderSlideElements(); cpRenderElementProps(); onCourseModified();
    };
    img.src = imagePath;
}

// Changer l&apos;image d'une étiquette image DQ
function cpDqChangeElementImage(idx) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function() {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        var formData = new FormData();
        formData.append('file', file);
        formData.append('action', 'upload_file');
        
        fetch('api/editor_api.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showToast('Erreur upload', 'error'); return; }
                var activity = getSelectedActivity();
                var slide = activity.content.presentation.slides[cpCurrentSlide];
                var element = slide.elements[cpSelectedElement];
                var dqElem = element.action.params.question.task.elements[idx];
                if (!dqElem) return;
                
                var img = new Image();
                img.onload = function() {
                    dqElem.type.params.file = {
                        path: data.url, mime: file.type || 'image/png',
                        copyright: { license: 'U' }, width: img.width, height: img.height
                    };
                    cpRenderSlideElements(); cpRenderElementProps(); onCourseModified();
                    showToast('Image mise à jour', 'success');
                };
                img.onerror = function() {
                    dqElem.type.params.file.path = data.url;
                    cpRenderSlideElements(); cpRenderElementProps(); onCourseModified();
                };
                img.src = data.url;
            })
            .catch(function(err) { showToast('Erreur upload', 'error'); });
    };
    input.click();
}

// Redimensionner une étiquette image DQ
function cpDqUpdateElementSize(idx, prop, value) {
    var activity = getSelectedActivity();
    var slide = activity.content.presentation.slides[cpCurrentSlide];
    var element = slide.elements[cpSelectedElement];
    var dqElem = element.action.params.question.task.elements[idx];
    if (!dqElem) return;
    dqElem[prop] = parseFloat(value);
    cpRenderSlideElements();
    onCourseModified();
}

// Supprimer une étiquette
function cpDqDeleteElement(idx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    let element = slide.elements[cpSelectedElement];
    
    element.action.params.question.task.elements.splice(idx, 1);
    
    // Mettre à jour les références dans les dropZones
    const dropZones = element.action.params.question.task.dropZones || [];
    dropZones.forEach(dz => {
        dz.correctElements = (dz.correctElements || [])
            .filter(e => parseInt(e) !== idx)
            .map(e => parseInt(e) > idx ? String(parseInt(e) - 1) : e);
    });
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Ajouter une modification d'étiquette au buffer de sauvegarde
function cpDqBufferElementTextChange(idx, text) {
    cpDqPendingChanges[String(idx)] = text;
}

// Sauvegarder toutes les modifications d'étiquettes en attente
function cpDqFlushPendingChanges() {
    if (Object.keys(cpDqPendingChanges).length === 0) return;

    const activity = getSelectedActivity();
    if (!activity) {
        cpDqPendingChanges = {};
        return;
    }

    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide || cpSelectedElement === null) {
        cpDqPendingChanges = {};
        return;
    }

    const element = slide.elements[cpSelectedElement];
    if (!element || !element.action || !element.action.params.question.task.elements) {
        cpDqPendingChanges = {};
        return;
    }

    // Appliquer toutes les modifications
    for (const [idx, text] of Object.entries(cpDqPendingChanges)) {
        const elemIdx = parseInt(idx);
        if (element.action.params.question.task.elements[elemIdx]) {
            element.action.params.question.task.elements[elemIdx].type.params.text = '<p>' + text + '</p>';
        }
    }

    // Vider le buffer
    cpDqPendingChanges = {};

    // Marquer comme modifié
    onCourseModified();
}

// Mettre à jour le texte d'une étiquette
function cpDqUpdateElementText(idx, text) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];

    element.action.params.question.task.elements[idx].type.params.text = '<p>' + text + '</p>';

    cpRenderSlideElements();
    onCourseModified();
}

// Mettre à jour la position d'une étiquette
function cpDqUpdateElementPos(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.question.task.elements[idx][prop] = value;
    
    onCourseModified();
}

// Ajouter une zone de dépôt
function cpDqAddZone() {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    let element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    const zones = element.action.params.question.task.dropZones;
    const elements = element.action.params.question.task.elements;
    const newIdx = zones.length;
    
    // Par défaut, lier l'étiquette N à la zone N si elle existe
    const defaultCorrectElements = (newIdx < elements.length) ? [String(newIdx)] : [];
    
    zones.push({
        x: 5 + (newIdx * 10) % 50,
        y: 60,
        width: 6.5,   // em (affiché en % via 6.5 × 1600/canvasWidth)
        height: 2.5,   // em (affiché en % via 2.5 × 1600/canvasHeight)
        correctElements: defaultCorrectElements,
        showLabel: false,
        backgroundOpacity: 0,
        tipsAndFeedback: { tip: '' },
        single: true,
        autoAlign: false,
        label: '<div>Zone ' + (newIdx + 1) + '</div>',
        type: { library: 'H5P.DragQuestionDropzone 0.1' }
    });
    
    // Ajouter cette nouvelle zone à toutes les étiquettes existantes
    // (pour qu'elles puissent y être déplacées)
    elements.forEach(elem => {
        if (!elem.dropZones) elem.dropZones = [];
        if (!elem.dropZones.includes(String(newIdx))) {
            elem.dropZones.push(String(newIdx));
        }
    });
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Supprimer une zone
function cpDqDeleteZone(idx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.question.task.dropZones.splice(idx, 1);
    
    // Mettre à jour les références dropZones dans les éléments
    const elements = element.action.params.question.task.elements || [];
    elements.forEach(el => {
        el.dropZones = (el.dropZones || [])
            .filter(dz => parseInt(dz) !== idx)
            .map(dz => parseInt(dz) > idx ? String(parseInt(dz) - 1) : dz);
    });
    
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Mettre à jour le label d'une zone
function cpDqUpdateZoneLabel(idx, label) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.question.task.dropZones[idx].label = '<div>' + label + '</div>';
    
    cpRenderSlideElements();
    onCourseModified();
}

// Mettre à jour la position d'une zone
function cpDqUpdateZonePos(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.question.task.dropZones[idx][prop] = value;
    
    onCourseModified();
}

// Mettre à jour la taille d'une zone
function cpDqUpdateZoneSize(idx, prop, value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    element.action.params.question.task.dropZones[idx][prop] = value;
    
    onCourseModified();
}

// Mettre à jour la réponse correcte d'une zone
function cpDqUpdateZoneCorrect(idx, elemIdx) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    if (elemIdx === '' || elemIdx === null) {
        element.action.params.question.task.dropZones[idx].correctElements = [];
    } else {
        element.action.params.question.task.dropZones[idx].correctElements = [String(elemIdx)];
        
        // Mettre à jour aussi les dropZones autorisées de l'élément
        const elements = element.action.params.question.task.elements;
        if (elements[elemIdx]) {
            if (!elements[elemIdx].dropZones) elements[elemIdx].dropZones = [];
            if (!elements[elemIdx].dropZones.includes(String(idx))) {
                elements[elemIdx].dropZones.push(String(idx));
            }
        }
    }
    
    cpRenderSlideElements();
    onCourseModified();
}

// Sélection d'un élément dans la mini-prévisualisation
function cpDqSelectElement(idx) {
    // Retirer les sélections précédentes
    document.querySelectorAll('.cp-dq-mini-element.selected, .cp-dq-mini-zone.selected').forEach(el => {
        el.classList.remove('selected');
    });
    document.querySelectorAll('.cp-dq-item-card.highlight').forEach(el => {
        el.classList.remove('highlight');
    });
    
    // Sélectionner dans la mini-preview
    const miniEl = document.querySelector(`.cp-dq-mini-element[data-idx="${idx}"]`);
    if (miniEl) miniEl.classList.add('selected');
    
    // Highlight dans la liste
    const card = document.getElementById(`cpDqElement${idx}`);
    if (card) {
        card.classList.add('highlight');
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Sélection d'une zone dans la mini-prévisualisation
function cpDqSelectZone(idx) {
    // Retirer les sélections précédentes
    document.querySelectorAll('.cp-dq-mini-element.selected, .cp-dq-mini-zone.selected').forEach(el => {
        el.classList.remove('selected');
    });
    document.querySelectorAll('.cp-dq-item-card.highlight').forEach(el => {
        el.classList.remove('highlight');
    });
    
    // Sélectionner dans la mini-preview
    const miniZone = document.querySelector(`.cp-dq-mini-zone[data-idx="${idx}"]`);
    if (miniZone) miniZone.classList.add('selected');
    
    // Highlight dans la liste
    const card = document.getElementById(`cpDqZone${idx}`);
    if (card) {
        card.classList.add('highlight');
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Demander l'URL de l'image de fond via prompt
function cpDqPromptBackgroundUrl() {
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const currentUrl = element?.action?.params?.question?.settings?.background?.path || '';
    
    const url = prompt("URL de l'image:", currentUrl);
    if (url !== null) {
        cpDqSetBackgroundUrl(url);
    }
}

// Effacer l'image de fond
function cpDqClearBackground() {
    cpDqSetBackgroundUrl('');
}

// Basculer une zone acceptée pour une étiquette
// Note: dropZones doit TOUJOURS contenir TOUTES les zones (pour pouvoir déposer partout)
// On modifie seulement correctElements (la bonne réponse)
function cpDqToggleElementZone(elemIdx, zoneIdx, isChecked) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // On ne modifie PAS elem.dropZones - ça doit rester avec toutes les zones
    // On modifie seulement correctElements de la zone
    const zone = element.action.params.question.task.dropZones[zoneIdx];
    if (zone) {
        if (!zone.correctElements) zone.correctElements = [];
        const elemStr = String(elemIdx);
        const correctIndex = zone.correctElements.indexOf(elemStr);
        
        if (isChecked && correctIndex === -1) {
            zone.correctElements.push(elemStr);
        } else if (!isChecked && correctIndex !== -1) {
            zone.correctElements.splice(correctIndex, 1);
        }
    }
    
    // Mettre à jour le texte du bouton dropdown sans re-render complet
    const dqDropZones = element.action.params.question.task.dropZones || [];
    
    // Calculer les zones où cette étiquette est une bonne réponse
    const correctZones = [];
    dqDropZones.forEach((dz, zIdx) => {
        if (dz.correctElements && dz.correctElements.includes(String(elemIdx))) {
            correctZones.push(zIdx);
        }
    });
    
    let zonesSelectedText = 'Aucune zone';
    if (correctZones.length > 0) {
        const zoneNums = correctZones.map(z => z + 1).sort((a,b) => a-b);
        zonesSelectedText = zoneNums.length === dqDropZones.length ? 'Toutes' : zoneNums.join(', ');
    }
    
    // Mettre à jour le texte affiché (chercher dans "Bonne réponse:")
    const btnSpan = document.querySelector(`#cpDqElement${elemIdx} .cp-dq-zones-dropdown-btn span strong`);
    if (btnSpan) btnSpan.textContent = zonesSelectedText;
    
    // Mettre à jour le style de la case cochée
    const checkbox = document.querySelector(`#cpDqZonesMenu${elemIdx} input[onchange*="cpDqToggleElementZone(${elemIdx}, ${zoneIdx}"]`);
    if (checkbox) {
        const label = checkbox.closest('label');
        if (label) {
            label.style.background = isChecked ? 'rgba(156,39,176,0.08)' : 'transparent';
        }
    }
    
    onCourseModified();
}

// Sélectionner une étiquette ou zone (affiche ses propriétés seules)
// parentIdx est l'index de l'élément DragQuestion sur la slide (optionnel)
function cpDqSelectItem(type, idx, parentIdx) {
    // Sauvegarder les modifications en attente avant de changer la sélection
    cpDqFlushPendingChanges();

    // Si on a un parentIdx et que l'élément parent n'est pas sélectionné, le sélectionner d'abord
    if (parentIdx !== undefined && cpSelectedElement !== parentIdx) {
        cpSelectedElement = parentIdx;
    }

    if (type === null || type === undefined) {
        cpDqSelectedItem = null;
    } else {
        cpDqSelectedItem = { type: type, idx: idx };
    }
    cpRenderSlideElements();
    cpRenderElementProps();
}

// Toggle zone acceptée sans re-render complet (pour éviter le reset du scroll)
function cpDqToggleElementZoneNoRender(elemIdx, zoneIdx, isChecked) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    
    // On ne modifie PAS elem.dropZones - ça doit rester avec toutes les zones
    // On modifie seulement correctElements de la zone (la bonne réponse)
    const zone = element.action.params.question.task.dropZones[zoneIdx];
    if (zone) {
        if (!zone.correctElements) zone.correctElements = [];
        const elemStr = String(elemIdx);
        const correctIndex = zone.correctElements.indexOf(elemStr);
        
        if (isChecked && correctIndex === -1) {
            zone.correctElements.push(elemStr);
        } else if (!isChecked && correctIndex !== -1) {
            zone.correctElements.splice(correctIndex, 1);
        }
    }
    
    onCourseModified();
}

// Mettre à jour l'opacité des zones de dépôt
function cpDqUpdateZoneOpacity(value) {
    const activity = getSelectedActivity();
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    let element = slide.elements[cpSelectedElement];
    element = cpDqEnsureStructure(element);
    
    element.action.params.question.settings.dropZoneOpacity = parseInt(value);
    
    cpRenderSlideElements();
    onCourseModified();
}

// Toggle section repliable
function cpDqToggleSection(titleElement) {
    const section = titleElement.closest('.cp-dq-collapsible');
    const content = section.querySelector('.cp-dq-collapse-content');
    const icon = titleElement.querySelector('.cp-dq-collapse-icon');
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.style.transform = 'rotate(0deg)';
    } else {
        content.style.display = 'none';
        icon.style.transform = 'rotate(-90deg)';
    }
}

// Toggle dropdown zones pour une étiquette
function cpDqToggleZonesDropdown(elemIdx) {
    const menu = document.getElementById(`cpDqZonesMenu${elemIdx}`);
    if (!menu) return;
    
    // Fermer tous les autres menus
    document.querySelectorAll('.cp-dq-zones-menu').forEach(m => {
        if (m.id !== `cpDqZonesMenu${elemIdx}`) {
            m.style.display = 'none';
        }
    });
    
    // Toggle celui-ci
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// Preview: Vérifier (placeholder - juste un feedback visuel)
function cpDqPreviewCheck() {
    showToast('Vérification... (aperçu uniquement)', 'info');
}

// Preview: Réessayer (placeholder)
function cpDqPreviewReset() {
    showToast('Réinitialisation... (aperçu uniquement)', 'info');
}

// ==================== DRAG & RESIZE POUR DRAGQUESTION ====================
let cpDqDragState = null;

// Démarrer le déplacement d'un élément ou zone
function cpDqStartDrag(event, type, idx) {
    // Ne pas démarrer le drag si on clique sur la poignée de resize
    if (event.target.classList.contains('cp-dq-resize-handle')) return;
    
    event.stopPropagation();
    event.preventDefault();
    
    const canvas = document.getElementById('cpDqCanvasContent');
    if (!canvas) return;
    
    const rect = canvas.getBoundingClientRect();
    const startX = (event.clientX - rect.left) / rect.width * 100;
    const startY = (event.clientY - rect.top) / rect.height * 100;
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const task = element?.action?.params?.question?.task;
    if (!task) return;
    
    const item = type === 'element' ? task.elements[idx] : task.dropZones[idx];
    if (!item) return;
    
    cpDqDragState = {
        type: type,
        idx: idx,
        mode: 'drag',
        startX: startX,
        startY: startY,
        origX: item.x || 0,
        origY: item.y || 0
    };
    
    // Highlight l'élément en cours de déplacement
    event.target.style.outline = '2px solid #6366f1';
    event.target.style.zIndex = '100';
}

// Démarrer le redimensionnement d'un élément ou zone
function cpDqStartResize(event, type, idx) {
    event.stopPropagation();
    event.preventDefault();
    
    const canvas = document.getElementById('cpDqCanvasContent');
    if (!canvas) return;
    
    const rect = canvas.getBoundingClientRect();
    const startX = (event.clientX - rect.left) / rect.width * 100;
    const startY = (event.clientY - rect.top) / rect.height * 100;
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const task = element?.action?.params?.question?.task;
    const settings = element?.action?.params?.question?.settings;
    if (!task || !settings) return;
    
    const item = type === 'element' ? task.elements[idx] : task.dropZones[idx];
    if (!item) return;
    
    // Conversion em → % : facteurs universels basés sur la taille du canvas
    const size = settings.size || { width: 800, height: 400 };
    const wFactor = 1600 / size.width;
    const hFactor = 1600 / size.height;
    
    cpDqDragState = {
        type: type,
        idx: idx,
        mode: 'resize',
        startX: startX,
        startY: startY,
        origWidth: item.width || 5.5,
        origHeight: item.height || 3.5,
        wFactor: wFactor,
        hFactor: hFactor
    };
    
    // Highlight
    const parent = event.target.parentElement;
    if (parent) {
        parent.style.outline = '2px solid #9c27b0';
        parent.style.zIndex = '100';
    }
}

// Gérer le mouvement de la souris
function cpDqHandleMouseMove(event) {
    if (!cpDqDragState) return;
    
    const canvas = document.getElementById('cpDqCanvasContent');
    if (!canvas) return;
    
    const rect = canvas.getBoundingClientRect();
    const currentX = (event.clientX - rect.left) / rect.width * 100;
    const currentY = (event.clientY - rect.top) / rect.height * 100;
    
    const activity = getSelectedActivity();
    if (!activity) return;
    
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    const task = element?.action?.params?.question?.task;
    if (!task) return;
    
    const item = cpDqDragState.type === 'element' ? task.elements[cpDqDragState.idx] : task.dropZones[cpDqDragState.idx];
    if (!item) return;
    
    if (cpDqDragState.mode === 'drag') {
        // Calcul du déplacement
        const deltaX = currentX - cpDqDragState.startX;
        const deltaY = currentY - cpDqDragState.startY;
        
        let newX = cpDqDragState.origX + deltaX;
        let newY = cpDqDragState.origY + deltaY;
        
        // Limiter aux bornes du canvas
        newX = Math.max(0, Math.min(90, newX));
        newY = Math.max(0, Math.min(90, newY));
        
        item.x = newX;
        item.y = newY;
        
        // Mettre à jour visuellement l'élément
        const el = canvas.querySelector(`.cp-dq-interactive[data-type="${cpDqDragState.type}"][data-idx="${cpDqDragState.idx}"]`);
        if (el) {
            el.style.left = newX + '%';
            el.style.top = newY + '%';
        }
    } else if (cpDqDragState.mode === 'resize') {
        // Calcul du redimensionnement
        const deltaX = currentX - cpDqDragState.startX;
        const deltaY = currentY - cpDqDragState.startY;
        
        // Convertir les deltas CSS en unités H5P (inversant les facteurs d'affichage)
        // deltaX en CSS = deltaW * wFactor, donc deltaW = deltaX / wFactor
        // deltaY en CSS = deltaH * hFactor, donc deltaH = deltaY / hFactor
        const deltaW = deltaX / cpDqDragState.wFactor;
        const deltaH = deltaY / cpDqDragState.hFactor;
        
        // Calculer les nouvelles dimensions en unités H5P
        let newWidth = cpDqDragState.origWidth + deltaW;
        let newHeight = cpDqDragState.origHeight + deltaH;
        
        // Limites
        newWidth = Math.max(3, Math.min(50, newWidth));
        newHeight = Math.max(2, Math.min(30, newHeight));
        
        item.width = newWidth;
        item.height = newHeight;
        
        // Mettre à jour visuellement avec les bons facteurs
        const el = canvas.querySelector(`.cp-dq-interactive[data-type="${cpDqDragState.type}"][data-idx="${cpDqDragState.idx}"]`);
        if (el) {
            el.style.width = (newWidth * cpDqDragState.wFactor) + '%';
            el.style.height = (newHeight * cpDqDragState.hFactor) + '%';
        }
    }
}

// Fin du drag ou resize
function cpDqHandleMouseUp(event) {
    if (!cpDqDragState) return;
    
    const canvas = document.getElementById('cpDqCanvasContent');
    if (canvas) {
        // Retirer les highlights
        canvas.querySelectorAll('.cp-dq-interactive').forEach(el => {
            el.style.outline = '';
            el.style.zIndex = '';
        });
    }
    
    // Mettre à jour le panneau de propriétés et sauvegarder
    cpDqDragState = null;
    cpRenderElementProps();
    onCourseModified();
}

// ==================== H5P.TABLE — CRÉATION ET ÉDITION ====================

/**
 * Normalise le HTML d'un tableau pour l'affichage :
 * - border-collapse:collapse sur le tableau, AUCUNE bordure sur le tableau lui-même
 * - Bordures sur les CELLULES uniquement → une seule ligne entre cellules (pas de double)
 * - Applique à toutes les cellules y compris 1ère colonne
 * - Largeur figure 100%
 */
function cpNormalizeTableHtml(html) {
    if (!html) return html;
    // Détecter si des bordures sont demandées (format Elea ou format normalisé)
    const hasBorders = /border-style\s*:\s*solid/i.test(html)
                    || /border\s*:\s*\d/.test(html);

    const parser = new DOMParser();
    const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
    const wrap = doc.querySelector('div');

    // Figure : width 100%
    wrap.querySelectorAll('figure').forEach(fig => {
        fig.style.margin = '0';
        fig.style.width = '100%';
    });

    // Tableau : border-collapse uniquement, PAS de border sur le tableau
    wrap.querySelectorAll('table').forEach(table => {
        table.style.borderCollapse = 'collapse';
        table.style.width = '100%';
        table.style.removeProperty('border');
        table.style.removeProperty('border-style');
        table.style.removeProperty('border-width');
        table.style.removeProperty('border-color');
    });

    // Cellules : border uniforme sur TOUTES (y compris 1ère colonne)
    wrap.querySelectorAll('td, th').forEach(cell => {
        // Nettoyer les anciens styles de bordure
        cell.style.removeProperty('border-style');
        if (hasBorders) {
            cell.style.border = '2px solid #333';
        } else {
            cell.style.removeProperty('border');
        }
        if (!cell.style.padding) cell.style.padding = '5px 8px';
        if (!cell.style.verticalAlign) cell.style.verticalAlign = 'middle';
    });

    // Masquer overflow-protection
    wrap.querySelectorAll('.table-overflow-protection').forEach(d => {
        d.style.display = 'none';
    });

    return wrap.innerHTML;
}

function cpBuildTableHtml(rows, cols, borders) {
    const colWidth = (100 / cols).toFixed(2);
    // Bordure sur cellules seulement (pas sur le tableau) → une seule ligne entre cellules
    const tableStyle = ' style="border-collapse:collapse;width:100%;"';
    const cellStyle = borders
        ? ' style="border:2px solid #333;padding:5px 8px;vertical-align:middle;"'
        : ' style="padding:5px 8px;vertical-align:middle;"';
    let html = '<figure class="table" style="margin:0;width:100%;"><table class="ck-table-resized"' + tableStyle + '>';
    html += '<colgroup>';
    for (let j = 0; j < cols; j++) html += '<col style="width:' + colWidth + '%;">';
    html += '</colgroup><tbody>';
    for (let i = 0; i < rows; i++) {
        html += '<tr>';
        for (let j = 0; j < cols; j++) html += '<td' + cellStyle + '>&nbsp;</td>';
        html += '</tr>';
    }
    html += '</tbody></table></figure>';
    return html;
}

function cpShowTableCreationDialog() {
    const dialog = document.createElement('div');
    dialog.className = 'cp-table-dialog-overlay';
    dialog.innerHTML = `
        <div class="cp-table-dialog">
            <h3>Créer un tableau</h3>
            <div class="cp-table-dialog-row">
                <label>Lignes<input type="number" id="cpTdRows" value="3" min="1" max="20" style="margin-top:4px;"></label>
                <label>Colonnes<input type="number" id="cpTdCols" value="3" min="1" max="10" style="margin-top:4px;"></label>
            </div>
            <div class="cp-table-dialog-row">
                <label class="checkbox-row">
                    <input type="checkbox" id="cpTdBorders" checked> Bordures
                </label>
            </div>
            <div class="cp-table-dialog-actions">
                <button onclick="cpTableDialogCancel()">Annuler</button>
                <button class="btn-primary" onclick="cpTableDialogConfirm()">Créer</button>
            </div>
        </div>`;
    document.body.appendChild(dialog);
    document.getElementById('cpTdRows').focus();
}

function cpTableDialogCancel() {
    document.querySelector('.cp-table-dialog-overlay')?.remove();
}

function cpTableDialogConfirm() {
    const rows = parseInt(document.getElementById('cpTdRows')?.value) || 3;
    const cols = parseInt(document.getElementById('cpTdCols')?.value) || 3;
    const borders = document.getElementById('cpTdBorders')?.checked ?? true;
    cpTableDialogCancel();

    const activity = getSelectedActivity();
    if (!activity) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    if (!slide.elements) slide.elements = [];

    const element = {
        x: 5 + (slide.elements.length * 4) % 30,
        y: 5 + (slide.elements.length * 4) % 30,
        width: 65,
        height: 42,
        action: {
            library: 'H5P.Table 1.2',
            params: { text: cpBuildTableHtml(rows, cols, borders) }
        },
        alwaysDisplayComments: false,
        backgroundOpacity: 0,
        displayAsButton: false,
        buttonSize: 'big',
        goToSlideType: 'specified',
        invisible: false,
        solution: ''
    };

    slide.elements.push(element);
    cpSelectedElement = slide.elements.length - 1;
    cpSelectedElements.clear();
    cpSelectedElements.add(cpSelectedElement);
    // Utiliser cpRenderSlideElements + cpRenderElementProps plutôt que renderCoursePresentationEditor
    // pour éviter les problèmes de timing avec le panneau de propriétés
    cpRenderSlideElements();
    cpRenderElementProps();
    onCourseModified();
}

// Sérialise le tableau du wrapper propriétés et sauvegarde dans l'élément
function cpSaveTableFromWrapper() {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;
    const activity = getSelectedActivity();
    if (!activity || cpSelectedElement === null) return;
    const slide = activity.content.presentation.slides[cpCurrentSlide];
    const element = slide.elements[cpSelectedElement];
    if (!element) return;
    element.action.params.text = wrapper.innerHTML;
    // Mettre à jour l'aperçu canvas sans refaire le panel
    const canvasEl = document.querySelector(`.cp-table-element[data-idx="${cpSelectedElement}"]`);
    if (canvasEl) canvasEl.innerHTML = wrapper.innerHTML;
    onCourseModified();
}

// Initialise l'éditeur de tableau dans le panneau propriétés
function cpInitTablePropsEditor() {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;

    // Rendre les cellules éditables au double-clic
    wrapper.querySelectorAll('td, th').forEach(cell => {
        cell.setAttribute('title', 'Double-clic pour éditer');
        cell.addEventListener('dblclick', function(e) {
            e.stopPropagation();
            // N'entrer en édition (et tout sélectionner) qu'au PREMIER double-clic.
            // Si la cellule est déjà éditable, laisser le double-clic sélectionner le mot.
            if (this.contentEditable === 'true' && document.activeElement === this) return;
            this.contentEditable = 'true';
            this.focus();
            const sel = window.getSelection();
            if (sel) {
                const range = document.createRange();
                range.selectNodeContents(this);
                sel.removeAllRanges();
                sel.addRange(range);
            }
        });
        cell.addEventListener('blur', function() {
            this.contentEditable = 'false';
            cpSaveTableFromWrapper();
        });
        cell.addEventListener('click', e => e.stopPropagation());
        cell.addEventListener('mousedown', e => e.stopPropagation());
    });

    cpInitTableColResize(wrapper);
}

// Ajoute des poignées de redimensionnement de colonnes
function cpInitTableColResize(wrapper) {
    const table = wrapper.querySelector('table');
    if (!table) return;

    // Créer ou récupérer le colgroup
    let colgroup = table.querySelector('colgroup');
    if (!colgroup) {
        const cellCount = table.querySelector('tr')?.querySelectorAll('td, th').length || 1;
        colgroup = document.createElement('colgroup');
        for (let i = 0; i < cellCount; i++) {
            const col = document.createElement('col');
            col.style.width = (100 / cellCount).toFixed(2) + '%';
            colgroup.appendChild(col);
        }
        table.prepend(colgroup);
    }

    const cols = Array.from(colgroup.querySelectorAll('col'));
    if (cols.length <= 1) return;

    // Retirer les anciens handles
    wrapper.querySelectorAll('.cp-col-resize-handle').forEach(h => h.remove());

    // Positionner le wrapper pour les handles absolus
    wrapper.style.position = 'relative';

    requestAnimationFrame(() => {
        let cumPercent = 0;
        cols.slice(0, -1).forEach((col, i) => {
            const w = parseFloat(col.style.width) || (100 / cols.length);
            cumPercent += w;

            const handle = document.createElement('div');
            handle.className = 'cp-col-resize-handle';
            handle.style.left = 'calc(' + cumPercent + '% - 3px)';
            handle.style.top = '0';
            handle.style.height = table.offsetHeight + 'px';

            let startX, startWidths;

            handle.addEventListener('mousedown', e => {
                e.stopPropagation();
                e.preventDefault();
                handle.classList.add('dragging');
                startX = e.clientX;
                startWidths = cols.map(c => parseFloat(c.style.width) || (100 / cols.length));

                const onMove = e => {
                    const dx = e.clientX - startX;
                    const tableWidth = table.offsetWidth || 1;
                    const dPct = (dx / tableWidth) * 100;
                    const newL = Math.max(5, startWidths[i] + dPct);
                    const newR = Math.max(5, startWidths[i + 1] - dPct);
                    cols[i].style.width = newL.toFixed(2) + '%';
                    cols[i + 1].style.width = newR.toFixed(2) + '%';
                    // Recalculer les positions de tous les handles
                    let cum = 0;
                    wrapper.querySelectorAll('.cp-col-resize-handle').forEach((h, hi) => {
                        cum += parseFloat(cols[hi].style.width) || (100 / cols.length);
                        h.style.left = 'calc(' + cum + '% - 3px)';
                    });
                };

                const onUp = () => {
                    handle.classList.remove('dragging');
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    cpSaveTableFromWrapper();
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });

            wrapper.appendChild(handle);
        });
    });
}

function cpToggleTableBorders(hasBorders) {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;
    const table = wrapper.querySelector('table');
    if (!table) return;
    // Bordures sur cellules uniquement, pas sur le tableau
    table.style.removeProperty('border');
    table.style.removeProperty('border-style');
    table.querySelectorAll('td, th').forEach(c => {
        c.style.removeProperty('border-style');
        if (hasBorders) {
            c.style.border = '2px solid #333';
        } else {
            c.style.removeProperty('border');
        }
    });
    cpSaveTableFromWrapper();
}

function cpTableAddRow() {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;
    const table = wrapper.querySelector('table');
    const tbody = table?.querySelector('tbody') || table;
    if (!tbody) return;
    const firstRow = tbody.querySelector('tr');
    if (!firstRow) return;
    const cells = firstRow.querySelectorAll('td, th');
    const cellCount = cells.length;
    // Copier le style de la première cellule existante
    const sampleStyle = cells[0] ? cells[0].style.cssText : '';
    const tr = document.createElement('tr');
    for (let j = 0; j < cellCount; j++) {
        const td = document.createElement('td');
        td.style.cssText = sampleStyle;
        td.innerHTML = '&nbsp;';
        tr.appendChild(td);
    }
    tbody.appendChild(tr);
    cpInitTablePropsEditor();
    cpSaveTableFromWrapper();
}

function cpTableAddCol() {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;
    const table = wrapper.querySelector('table');
    if (!table) return;
    const rows = table.querySelectorAll('tr');
    if (!rows.length) return;
    // Copier le style de la première cellule existante
    const sampleStyle = rows[0].querySelector('td, th')?.style.cssText || '';
    const colgroup = table.querySelector('colgroup');
    if (colgroup) {
        const existingCols = colgroup.querySelectorAll('col');
        const newW = (100 / (existingCols.length + 1)).toFixed(2);
        existingCols.forEach(c => { c.style.width = newW + '%'; });
        const nc = document.createElement('col');
        nc.style.width = newW + '%';
        colgroup.appendChild(nc);
    }
    rows.forEach(row => {
        const td = document.createElement('td');
        td.style.cssText = sampleStyle;
        td.innerHTML = '&nbsp;';
        row.appendChild(td);
    });
    cpInitTablePropsEditor();
    cpSaveTableFromWrapper();
}

function cpTableDelRow() {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;
    const table = wrapper.querySelector('table');
    const tbody = table?.querySelector('tbody') || table;
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr');
    if (rows.length <= 1) return;
    rows[rows.length - 1].remove();
    cpSaveTableFromWrapper();
}

function cpTableDelCol() {
    const wrapper = document.getElementById('cpTablePropsWrapper');
    if (!wrapper) return;
    const table = wrapper.querySelector('table');
    if (!table) return;
    const rows = table.querySelectorAll('tr');
    if (!rows.length) return;
    const colCount = rows[0].querySelectorAll('td, th').length;
    if (colCount <= 1) return;
    rows.forEach(row => {
        const cells = row.querySelectorAll('td, th');
        if (cells.length) cells[cells.length - 1].remove();
    });
    const colgroup = table.querySelector('colgroup');
    if (colgroup) {
        const cols = colgroup.querySelectorAll('col');
        if (cols.length > 1) {
            cols[cols.length - 1].remove();
            const remaining = colgroup.querySelectorAll('col');
            const nw = (100 / remaining.length).toFixed(2);
            remaining.forEach(c => { c.style.width = nw + '%'; });
        }
    }
    cpInitTablePropsEditor();
    cpSaveTableFromWrapper();
}
