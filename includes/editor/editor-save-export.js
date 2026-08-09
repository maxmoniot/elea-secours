// ==================== SAUVEGARDE & EXPORT ====================

// ==================== DÉTECTION SESSION EXPIRÉE ====================
(function() {
    var _sessionExpired = false;
    var _originalFetch = window.fetch;
    
    function _handleSessionExpired() {
        if (_sessionExpired) return;
        // Double-vérification avec le heartbeat avant d'alerter
        _originalFetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'session_check' })
        }).then(function(r) {
            if (r.status === 403 && !_sessionExpired) {
                _sessionExpired = true;
                alert('Votre session a expiré. Vous allez être redirigé vers la page de connexion.');
                window.location.href = 'index.php';
            }
            // Si session_check passe → c'était un faux positif, on ignore
        }).catch(function() {});
    }
    
    // Intercepter tous les fetch vers l'API pour détecter les 403
    window.fetch = function(url, options) {
        return _originalFetch.apply(this, arguments).then(function(response) {
            if (response.status === 403 && !_sessionExpired) {
                var urlStr = (typeof url === 'string') ? url : (url && url.url) || '';
                if (urlStr.indexOf('editor_api.php') !== -1 || urlStr.indexOf('api/') !== -1) {
                    _handleSessionExpired();
                }
            }
            return response;
        });
    };
    
    // Heartbeat toutes les 5 minutes pour garder la session active
    setInterval(function() {
        if (_sessionExpired) return;
        _originalFetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'session_check' })
        }).then(function(r) {
            if (r.status === 403 && !_sessionExpired) {
                _sessionExpired = true;
                alert('Votre session a expiré. Vous allez être redirigé vers la page de connexion.');
                window.location.href = 'index.php';
            }
        }).catch(function() {});
    }, 300000); // 5 minutes au lieu de 2
})();

// ==================== SYSTÈME DE BROUILLON AUTOMATIQUE ====================

let autoSaveTimeout = null;
const AUTO_SAVE_DELAY = 2000; // 2 secondes après la dernière modification

// Sauvegarder quand l'utilisateur quitte la page ou change d'onglet
window.addEventListener('beforeunload', function() {
    // sendBeacon : sauvegarde locale uniquement
    var sessionId = getEditorSessionId();
    var courseNameInput = document.getElementById('courseName');
    if (courseNameInput) courseData.name = courseNameInput.value;
    
    var draftData = Object.assign({}, courseData, {
        sessionId: sessionId,
        savedAt: new Date().toISOString()
    });
    
    navigator.sendBeacon('api/editor_api.php', new Blob([JSON.stringify({
        action: 'auto_save_draft',
        sessionId: sessionId,
        data: draftData
    })], { type: 'application/json' }));
});

// Sauvegarder quand l'onglet passe en arrière-plan
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = null;
        }
        autoSaveDraft();
    }
});

/**
 * Génère ou récupère un identifiant unique pour cet utilisateur/navigateur
 * Stocké en localStorage pour persister entre les sessions
 */
function getEditorSessionId() {
    let sessionId = localStorage.getItem('elea_editor_session_id');
    if (!sessionId) {
        sessionId = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('elea_editor_session_id', sessionId);
    }
    return sessionId;
}

/**
 * Génère un NOUVEAU session_id (après cleanup de l'ancien)
 */
function regenerateEditorSessionId() {
    var newId = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    localStorage.setItem('elea_editor_session_id', newId);
    return newId;
}

// ==================== OVERLAY DE CHARGEMENT ====================

/**
 * Voile d'attente. Avec `withProgress`, affiche une vraie barre alimentée par
 * setLoadingProgress() / watchServerProgress() au lieu du seul rond qui tourne :
 * sur un gros cours l'import et l'export durent longtemps et semblaient bloqués.
 */
var _loadingCancelFn = null;

/**
 * Voile d'attente. Avec `withProgress`, affiche une vraie barre alimentée par
 * setLoadingProgress() / watchServerProgress() au lieu du seul rond qui tourne :
 * sur un gros cours l'import et l'export durent longtemps et semblaient bloqués.
 * `onCancel` ajoute une croix + un bouton « Annuler » (et la touche Échap).
 */
function showLoadingOverlay(title, subtitle, withProgress, onCancel) {
    let overlay = document.getElementById('editorLoadingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'editorLoadingOverlay';
        document.body.appendChild(overlay);
    }
    _loadingCancelFn = (typeof onCancel === 'function') ? onCancel : null;
    overlay.innerHTML = `
        <div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;">
            <div style="position:relative;background:var(--bg-secondary,white);border-radius:16px;padding:2rem 3rem;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,0.3);max-width:420px;min-width:320px;">
                ${_loadingCancelFn ? `
                <button id="editorLoadingCloseBtn" onclick="cancelLoadingOverlay()" title="Annuler (Échap)"
                        style="position:absolute;top:0.6rem;right:0.6rem;width:1.8rem;height:1.8rem;border-radius:50%;border:1px solid var(--gray-300,#d1d5db);background:var(--bg-secondary,#fff);color:var(--gray-600,#4b5563);font-size:0.9rem;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>` : ''}
                ${withProgress ? '' : '<div class="spinner" style="margin:0 auto 1rem;"></div>'}
                <p style="font-size:1rem;color:var(--gray-700);margin:0;">${title || 'Chargement...'}</p>
                ${subtitle ? '<p style="font-size:0.85rem;color:var(--gray-400);margin-top:0.5rem;word-break:break-word;">' + subtitle + '</p>' : ''}
                ${withProgress ? `
                <div style="margin-top:1.1rem;">
                    <div style="height:10px;background:var(--gray-200,#e5e7eb);border-radius:999px;overflow:hidden;">
                        <div id="editorProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#6d28d9,#8b5cf6);border-radius:999px;transition:width 0.35s ease;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;gap:1rem;margin-top:0.45rem;">
                        <span id="editorProgressLabel" style="font-size:0.78rem;color:var(--gray-500);text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Démarrage…</span>
                        <span id="editorProgressPercent" style="font-size:0.78rem;color:var(--gray-500);font-variant-numeric:tabular-nums;">0 %</span>
                    </div>
                </div>` : ''}
                ${_loadingCancelFn ? `
                <button onclick="cancelLoadingOverlay()"
                        style="margin-top:1.25rem;padding:0.45rem 1.1rem;background:var(--gray-100,#f1f5f9);border:1px solid var(--gray-300,#cbd5e1);border-radius:6px;color:var(--gray-600,#475569);font-size:0.85rem;cursor:pointer;">Annuler</button>` : ''}
            </div>
        </div>`;
    overlay.style.display = 'block';
    _loadingPercent = 0;
    document.addEventListener('keydown', _loadingEscapeHandler);
}

function _loadingEscapeHandler(e) {
    if (e.key === 'Escape' && _loadingCancelFn) {
        e.preventDefault();
        cancelLoadingOverlay();
    }
}

/** Déclenche l'annulation en cours (croix, bouton, ou Échap). */
function cancelLoadingOverlay() {
    const fn = _loadingCancelFn;
    _loadingCancelFn = null;   // une seule fois
    hideLoadingOverlay();
    if (fn) fn();
}

var _loadingPercent = 0;

/**
 * Met la barre à jour. La progression ne recule jamais : entre l'envoi du fichier et les
 * étapes serveur, une barre qui repart en arrière donne l'impression d'un plantage.
 */
function setLoadingProgress(percent, label) {
    if (typeof percent === 'number' && !isNaN(percent)) {
        percent = Math.max(0, Math.min(100, percent));
        if (percent < _loadingPercent) percent = _loadingPercent;
        _loadingPercent = percent;
        const bar = document.getElementById('editorProgressBar');
        const pct = document.getElementById('editorProgressPercent');
        if (bar) bar.style.width = percent + '%';
        if (pct) pct.textContent = Math.round(percent) + ' %';
    }
    if (label) {
        const el = document.getElementById('editorProgressLabel');
        if (el) el.textContent = label;
    }
}

var _progressWatchTimer = null;

/**
 * Interroge l'avancement publié par la tâche serveur.
 * `from`/`to` remappent les 0-100 % du serveur sur une portion de la barre, quand une
 * phase navigateur (l'envoi du fichier) occupe déjà le début.
 */
function watchServerProgress(progressId, from, to) {
    stopServerProgress();
    from = (typeof from === 'number') ? from : 0;
    to = (typeof to === 'number') ? to : 100;
    _progressWatchTimer = setInterval(function() {
        fetch('api/editor_api.php?action=get_progress&id=' + encodeURIComponent(progressId), { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(p) {
                if (!p || p.percent === null || p.percent === undefined) return;
                setLoadingProgress(from + (to - from) * (p.percent / 100), p.label);
            })
            .catch(function() { /* un sondage raté ne doit pas casser la tâche */ });
    }, 400);
}

function stopServerProgress() {
    if (_progressWatchTimer) {
        clearInterval(_progressWatchTimer);
        _progressWatchTimer = null;
    }
}

function newProgressId() {
    return 'p_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
}

/**
 * POST d'un FormData en XHR plutôt qu'en fetch : seul XHR expose l'avancement de l'ENVOI,
 * et sur un .mbz de plusieurs dizaines de Mo c'est une bonne part de l'attente.
 * `onUpload(fraction)` reçoit 0→1 pendant le transfert.
 */
function postFormWithProgress(formData, onUpload) {
    var xhr = new XMLHttpRequest();
    var promesse = new Promise(function(resolve, reject) {
        xhr.open('POST', 'api/editor_api.php');
        if (xhr.upload && onUpload) {
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) onUpload(e.loaded / e.total);
            };
        }
        xhr.onload = function() {
            if (xhr.status < 200 || xhr.status >= 300) {
                reject(new Error('Erreur serveur : HTTP ' + xhr.status));
                return;
            }
            try { resolve(JSON.parse(xhr.responseText)); }
            catch (e) { reject(new Error('Réponse serveur invalide')); }
        };
        xhr.onerror = function() { reject(new Error('Erreur réseau')); };
        // Abandon volontaire : marqué pour que l'appelant n'affiche pas d'erreur
        xhr.onabort = function() {
            var err = new Error('Chargement annulé');
            err.annule = true;
            reject(err);
        };
        xhr.send(formData);
    });
    promesse.abort = function() { try { xhr.abort(); } catch (e) {} };
    return promesse;
}

function hideLoadingOverlay() {
    stopServerProgress();
    _loadingCancelFn = null;
    document.removeEventListener('keydown', _loadingEscapeHandler);
    const overlay = document.getElementById('editorLoadingOverlay');
    if (overlay) overlay.style.display = 'none';
}

// ==================== ANNULATION D'UN CHARGEMENT ====================

/**
 * Photographie l'état de l'éditeur avant un chargement, pour pouvoir y revenir si
 * l'utilisateur annule : soit un éditeur vide, soit le cours précédemment ouvert.
 */
function snapshotEditorState() {
    return {
        courseData: JSON.parse(JSON.stringify(courseData)),
        sectionId: (typeof selectedSection !== 'undefined') ? selectedSection : null,
        activityId: (typeof selectedActivity !== 'undefined') ? selectedActivity : null,
        sessionId: getEditorSessionId()
    };
}

function restoreEditorState(snap, message) {
    if (!snap) return;
    courseData = JSON.parse(JSON.stringify(snap.courseData));
    selectedSection = snap.sectionId;
    selectedActivity = snap.activityId;

    // Remettre l'identifiant de session de l'éditeur : les URLs des médias du cours
    // restauré pointent vers SON dossier, pas vers celui du chargement abandonné.
    try { localStorage.setItem('elea_editor_session_id', snap.sessionId); } catch (e) {}
    if (typeof EditorDriveSync !== 'undefined' && EditorDriveSync.reset) {
        EditorDriveSync.reset();
        EditorDriveSync.init(snap.sessionId);
    }

    const nameInput = document.getElementById('courseName');
    if (nameInput) nameInput.value = courseData.name || '';

    renderTree();
    renderProperties();
    if ((courseData.sections || []).length && typeof showStructureView === 'function') {
        showStructureView();
    } else {
        const vide = document.getElementById('emptyCanvas');
        const contenu = document.getElementById('editorContent');
        if (vide) vide.style.display = 'flex';
        if (contenu) contenu.style.display = 'none';
    }
    if (typeof cpInvalidateAllThumbs === 'function') cpInvalidateAllThumbs();
    if (typeof calculateCourseSize === 'function') calculateCourseSize();
    if (message !== null) showToast(message || 'Chargement annulé', 'info');
}

/**
 * Appelé à chaque modification du cours
 * Déclenche une sauvegarde automatique après un délai (debounce)
 */
function onCourseModified() {
    updateSaveStatus('modified');

    // Aperçu de la vignette dans le header (ne touche au DOM que si l'URL a changé,
    // p.ex. après une réécriture d'URL par la synchro Drive)
    if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();

    // Recalculer la taille du cours
    if (typeof calculateCourseSize === 'function') {
        calculateCourseSize();
    }
    
    // Rafraîchir la taille des fichiers depuis le serveur (debounced 3s)
    if (typeof refreshFilesSize === 'function') {
        clearTimeout(window._refreshFilesSizeDebounce);
        window._refreshFilesSizeDebounce = setTimeout(refreshFilesSize, 3000);
    }
    
    // Mettre à jour la vignette de la slide courante (debounced 300ms)
    if (typeof cpUpdateCurrentThumb === 'function') {
        clearTimeout(window._cpThumbDebounce);
        window._cpThumbDebounce = setTimeout(cpUpdateCurrentThumb, 300);
    }
    
    // Sauvegarder dans l'historique undo/redo
    if (typeof courseSaveToHistory === 'function') courseSaveToHistory();
    
    // Annuler la sauvegarde précédente si elle n'a pas encore eu lieu
    if (autoSaveTimeout) {
        clearTimeout(autoSaveTimeout);
    }
    
    // Programmer une nouvelle sauvegarde
    autoSaveTimeout = setTimeout(() => {
        autoSaveDraft();
    }, AUTO_SAVE_DELAY);
}

/**
 * Sauvegarde automatique du brouillon sur le serveur
 */
function autoSaveDraft() {
    // Auto-sync DDI editor si ouvert
    if (typeof _qsDdiEditIdx !== 'undefined' && _qsDdiEditIdx !== null && typeof qsCloseDdiEditor === 'function') {
        // Sync silencieuse: copier les données sans fermer visuellement l'éditeur
        var tempAct = window._qsDdiTempActivity;
        if (tempAct && tempAct.content) {
            var parentAct = tempAct._parentQuizActivity || getSelectedActivity();
            if (parentAct && parentAct.content && parentAct.content.questions && parentAct.content.questions[_qsDdiEditIdx]) {
                var q = parentAct.content.questions[_qsDdiEditIdx];
                q.backgroundUrl = tempAct.content.backgroundUrl;
                q.bgImageName = tempAct.content.bgImageName;
                q.canvasWidth = tempAct.content.canvasWidth;
                q.canvasHeight = tempAct.content.canvasHeight;
                q.sourceWidth = tempAct.content.sourceWidth;
                q.drags = tempAct.content.drags || [];
                q.drops = tempAct.content.drops || [];
                // Même précaution qu'à la fermeture : l'éditeur glisser affiche un énoncé
                // par défaut, ne pas l'imposer à une question qui n'en avait pas.
                var enonceEdite = tempAct.content.questiontext || '';
                if (enonceEdite !== (tempAct._enonceOuverture || '')) {
                    q.questiontext = enonceEdite || q.questiontext;
                }
                q.shuffleanswers = tempAct.content.shuffleanswers;
            }
        }
    }
    
    const sessionId = getEditorSessionId();
    
    // Mettre à jour le nom du cours depuis l'input
    const courseNameInput = document.getElementById('courseName');
    if (courseNameInput) {
        courseData.name = courseNameInput.value;
    }
    
    // Préparer les données avec l'ID de session
    const draftData = {
        ...courseData,
        sessionId: sessionId,
        savedAt: new Date().toISOString()
    };
    
    updateSaveStatus('saving');
    
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'auto_save_draft',
            sessionId: sessionId,
            data: draftData
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateSaveStatus('saved');
        } else {
            console.error('Erreur auto-save:', data.error);
            updateSaveStatus('modified');
        }
    })
    .catch(err => {
        console.error('Erreur auto-save:', err);
        updateSaveStatus('modified');
    });
}

/**
 * Charge le brouillon au démarrage de l'éditeur
 * Retourne une Promise qui se résout quand le chargement est terminé
 */
function loadDraftOnStartup() {
    return new Promise((resolve) => {
        
        // Vérifier si un cleanup est nécessaire (ouverture d'un autre cours / nouveau)
        var needsCleanup = sessionStorage.getItem('editor_needs_cleanup');
        
        function _proceedWithDraft() {
            var sessionId = getEditorSessionId();
            EditorDriveSync.init(sessionId);
            
            function _setLoadingStep(pct, msg) {
                var bar = document.getElementById('editorLoadingBar');
                var text = document.getElementById('editorLoadingText');
                var title = document.getElementById('editorLoadingTitle');
                if (bar) bar.style.width = pct + '%';
                if (text) text.textContent = msg;
                if (title && pct > 0) title.textContent = 'Restauration du brouillon...';
            }
            
            _setLoadingStep(5, 'Vérification du brouillon local...');
            _setLoadingStep(30, 'Chargement des données du cours...');
            
            fetch('api/editor_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'load_auto_draft',
                        sessionId: sessionId
                    })
                })
            .then(r => r.json())
            .then(data => {
                if (window._editorLoadCancelled) { resolve(false); return; }
                
                if (data.success && data.data) {
                    _setLoadingStep(50, 'Construction de l\'arborescence...');
                    const draft = data.data;
                    
                    courseData = {
                        id: draft.id || generateId(),
                        name: draft.name || 'Cours restauré',
                        shortname: draft.shortname || 'cours',
                        vignette: draft.vignette || null,
                        sections: draft.sections || []
                    };

                    document.getElementById('courseName').value = courseData.name;
                    if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();
                    selectedSection = null;
                    selectedActivity = null;
                    
                    // Convertir les éléments H5P.Video en InteractiveVideo (anciens cours)
                    convertH5pVideoToInteractiveVideo();
                    
                    _setLoadingStep(60, 'Rendu des sections et activités...');
                    renderTree();
                    showStructureView();
                    renderProperties();
                    
                    _setLoadingStep(70, 'Chargement des images...');
                    
                    if (typeof calculateCourseSize === 'function') {
                        calculateCourseSize();
                    }
                    
                    if (courseData.sections.length > 0) {
                        const savedAt = draft.savedAt ? new Date(draft.savedAt).toLocaleString('fr-FR') : '';
                        showToast('Brouillon restauré' + (savedAt ? ' (' + savedAt + ')' : ''), 'success');
                    }
                    
                    updateSaveStatus('saved');
                    
                    // Synchroniser les fichiers avec Drive immédiatement
                    if (typeof EditorDriveSync !== 'undefined') {
                        EditorDriveSync.syncAndFlush();
                    }
                    
                    resolve(true);
                } else {
                    resolve(false);
                }
            })
            .catch(err => {
                console.log('Pas de brouillon trouvé ou erreur:', err);
                resolve(false);
            });
        }
        
        if (needsCleanup) {
            sessionStorage.removeItem('editor_needs_cleanup');
            
            // Cleanup l'ancienne session
            var oldSessionId = getEditorSessionId();
            EditorDriveSync.reset();
            
            fetch('api/editor_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'cleanup_editor_session',
                    sessionId: oldSessionId
                })
            })
            .then(function(r) { return r.json(); })
            .then(function() {
                // Générer un nouveau session_id
                var newId = regenerateEditorSessionId();
                console.log('[Editor] Cleanup done, new session:', newId);
                _proceedWithDraft();
            })
            .catch(function() {
                regenerateEditorSessionId();
                _proceedWithDraft();
            });
        } else {
            // Simple refresh → restaurer le draft existant
            _proceedWithDraft();
        }
    });
}

/**
 * Supprime le brouillon actuel (appelé après un export réussi par exemple)
 */
function clearDraft() {
    const sessionId = getEditorSessionId();
    
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'clear_auto_draft',
            sessionId: sessionId
        })
    })
    .catch(err => console.log('Erreur suppression brouillon:', err));
}

// ==================== STATUT DE SAUVEGARDE ====================

function updateSaveStatus(status) {
    const el = document.getElementById('saveStatus');
    if (!el) return;
    
    switch(status) {
        case 'saving':
            el.textContent = '⏳ Sauvegarde...';
            el.className = 'save-status saving';
            break;
        case 'saved':
            el.textContent = '✓ Sauvegardé';
            el.className = 'save-status saved';
            break;
        case 'modified':
            el.textContent = '● Non sauvegardé';
            el.className = 'save-status';
            break;
        default:
            el.textContent = '';
            el.className = 'save-status';
    }
}

// ==================== OUVRIR UN FICHIER MBZ ====================

function openMbzDialog() {
    // Ouvrir directement le sélecteur de fichier
    document.getElementById('openFileInput').value = '';
    document.getElementById('openFileInput').click();
}

function loadMbzFile() {
    const file = document.getElementById('openFileInput').files[0];
    if (!file) {
        showToast('Sélectionnez un fichier', 'error');
        return;
    }
    
    // Limite 200 Mo
    if (file.size > 200 * 1024 * 1024) {
        showToast('Le fichier est trop volumineux (' + (file.size / (1024*1024)).toFixed(1) + ' Mo). Limite : 200 Mo.', 'error');
        document.getElementById('openFileInput').value = '';
        return;
    }
    
    const fileName = file.name.replace(/\.mbz$/i, '');
    const etatPrecedent = snapshotEditorState();
    const oldSessionId = etatPrecedent.sessionId;
    var progressId = newProgressId();
    var annule = false;
    var requete = null;

    // L'ancienne session n'est PLUS supprimée d'entrée : on lit d'abord le nouveau cours
    // dans une session neuve, et on ne jette l'ancienne qu'une fois la lecture réussie.
    // Sinon une annulation — ou un simple échec de lecture — laissait l'éditeur avec un
    // cours dont tous les fichiers venaient d'être effacés.
    var newId = regenerateEditorSessionId();
    EditorDriveSync.reset();
    EditorDriveSync.init(newId);

    function annulerOuverture() {
        annule = true;
        if (requete && requete.abort) requete.abort();
        stopServerProgress();
        // Jeter la session du chargement abandonné, revenir à la précédente
        fetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cleanup_editor_session', sessionId: newId })
        }).catch(function() {});
        restoreEditorState(etatPrecedent);
    }

    showLoadingOverlay('Ouverture du parcours...', fileName, true, annulerOuverture);
    setLoadingProgress(0, 'Envoi du fichier…');

    const formData = new FormData();
    formData.append('action', 'parse_mbz');
    formData.append('file', file);
    formData.append('session_id', newId);
    formData.append('progressId', progressId);

    // 0-35 % : envoi du fichier (mesuré) ; 35-100 % : traitement serveur (sondé)
    requete = postFormWithProgress(formData, function(fraction) {
        if (annule) return;
        setLoadingProgress(fraction * 35, 'Envoi du fichier… ' + Math.round(fraction * 100) + ' %');
        if (fraction >= 1) {
            setLoadingProgress(35, 'Lecture du cours…');
            watchServerProgress(progressId, 35, 100);
        }
    });

    requete
    .then(data => {
        if (annule) return;
        stopServerProgress();
        hideLoadingOverlay();

        if (data.success && data.course) {
            // Lecture réussie : on peut libérer l'ancienne session sans risque
            fetch('api/editor_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cleanup_editor_session', sessionId: oldSessionId })
            }).catch(function() {});

            data.course.name = fileName.replace(/[-_]+/g, ' ');
            data.course.shortname = file.name.replace(/\.mbz$/i, '');
            loadParsedCourse(data.course);
            showToast('Cours chargé avec succès !', 'success');
            onCourseModified();
        } else {
            throw new Error(data.error || 'Erreur de lecture du fichier');
        }
    })
    .catch(err => {
        if (annule || err.annule) return;
        hideLoadingOverlay();
        // La lecture a échoué : rendre l'éditeur tel qu'il était, l'ancienne session est intacte
        restoreEditorState(etatPrecedent, null);
        showToast('Erreur: ' + err.message, 'error');
    });
}

// Charger un cours parsé depuis MBZ dans l'éditeur
function loadParsedCourse(parsedCourse) {
    // Réinitialiser courseData avec les données du MBZ
    courseData = {
        id: generateId(),
        name: parsedCourse.name || 'Cours importé',
        shortname: parsedCourse.shortname || 'cours',
        // Vignette du cours : présente dans le .mbz (course/overviewfiles), elle doit
        // survivre à l'aller-retour import → export.
        vignette: parsedCourse.vignette || null,
        sections: []
    };
    if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();
    
    // Copier les sections avec de nouveaux IDs
    if (parsedCourse.sections && Array.isArray(parsedCourse.sections)) {
        parsedCourse.sections.forEach(section => {
            const newSection = {
                id: generateId(),
                name: section.name || 'Section',
                summary: section.summary || '',
                visible: section.visible !== undefined ? section.visible : true,
                activities: []
            };
            
            // Copier les activités avec de nouveaux IDs
            if (section.activities && Array.isArray(section.activities)) {
                section.activities.forEach(activity => {
                    const act = {
                        id: generateId(),
                        type: activity.type || 'h5pactivity',
                        h5pType: activity.h5pType || '',
                        name: activity.name || 'Activité',
                        visible: activity.visible !== undefined ? activity.visible : true,
                        content: activity.content || {}
                    };
                    // Ne pas garder h5pType pour les types non-H5P
                    if (['assign', 'resource', 'mapmodules', 'quiz'].includes(act.type)) {
                        act.h5pType = '';
                    }
                    // Copier les champs spécifiques mapmodules
                    // Détection robuste: type explicite OU présence de champs mapmodules
                    const isMapmodules = activity.type === 'mapmodules' 
                        || activity.mapPath !== undefined 
                        || activity.descriptionHeader !== undefined
                        || activity.iconset !== undefined;
                    if (isMapmodules) {
                        act.type = 'mapmodules'; // Forcer le type
                        act.mapPath = activity.mapPath || activity.path || '';
                        act.mapImage = activity.mapImage || null;
                        act.descriptionHeader = activity.descriptionHeader || '';
                        act.descriptionFooter = activity.descriptionFooter || '';
                        act.iconset = activity.iconset || 4;
                        act.buttonWidth = activity.buttonWidth || 50;
                    }
                    // Copier les champs spécifiques assign (travail à déposer)
                    if (activity.type === 'assign') {
                        act.type = 'assign';
                        act.files = (activity.files || []).map(f => ({
                            fileUrl: f.fileUrl || null,
                            fileName: f.fileName || null
                        }));
                        // Rétrocompatibilité mono-fichier
                        if (act.files.length === 0 && activity.fileUrl && activity.fileName) {
                            act.files = [{ fileUrl: activity.fileUrl, fileName: activity.fileName }];
                        }
                        act.intro = activity.intro || '';
                    }
                    // Copier les champs spécifiques resource (fichiers à distribuer)
                    if (activity.type === 'resource') {
                        act.type = 'resource';
                        act.files = (activity.files || []).map(f => ({
                            fileUrl: f.fileUrl || null,
                            fileName: f.fileName || null
                        }));
                        act.intro = activity.intro || '';
                    }
                    if (activity.type === 'quiz') {
                        act.type = 'quiz';
                        act.quizType = activity.quizType || '';
                        act.content = activity.content || {};
                    }
                    newSection.activities.push(act);
                });
            }
            
            courseData.sections.push(newSection);
        });
    }
    
    // Mettre à jour l'interface
    document.getElementById('courseName').value = courseData.name;
    selectedSection = null;
    selectedActivity = null;
    
    // Convertir les éléments H5P.Video en InteractiveVideo (anciens cours)
    convertH5pVideoToInteractiveVideo();
    
    renderTree();
    
    // Afficher la structure si le cours a des sections
    if (courseData.sections.length > 0) {
        showStructureView();
    } else {
        showStructureView();
    }
    renderProperties();
    
    // Synchroniser les fichiers avec Drive immédiatement
    if (typeof EditorDriveSync !== 'undefined') {
        EditorDriveSync.syncAndFlush();
    }
}

/**
 * Convertit les éléments H5P.Video en H5P.InteractiveVideo dans courseData
 * Les anciens cours contiennent des vidéos simples (H5P.Video) dans les CoursePresentation
 * On les convertit en InteractiveVideo pour compatibilité avec l'éditeur et l'export
 */
/**
 * Convertit UN élément H5P.Video en H5P.InteractiveVideo, sur place.
 * Renvoie true si l'élément a été converti (false s'il ne s'agit pas d'une vidéo simple).
 *
 * Idempotent : un élément déjà en InteractiveVideo est ignoré. C'est ce qui permet de
 * l'appeler aussi au moment du rendu, en filet de sécurité — le rendu CoursePresentation
 * ne sait afficher QUE 'interactivevideo' : un 'video' non converti tombait dans le
 * `default:` du switch et s'affichait comme une simple étiquette grise, sans lecteur.
 */
function convertVideoElementToIV(element) {
    var lib = (element && element.action && element.action.library) || '';
    if (!lib.startsWith('H5P.Video ')) return false;

    var sources = (element.action.params && element.action.params.sources) || [];

    element.action.library = 'H5P.InteractiveVideo 1.27';
    element.action.params = {
        interactiveVideo: {
            video: {
                startScreenOptions: { title: '', hideStartTitle: true },
                textTracks: { videoTrack: [] },
                files: sources.map(function(s) {
                    return {
                        path: s.path || '',
                        mime: s.mime || 'video/mp4',
                        copyright: s.copyright || { license: 'U' }
                    };
                })
            },
            assets: { interactions: [], endScreens: [] }
        }
    };
    if (element.action.metadata) {
        element.action.metadata.contentType = 'Interactive Video';
    }
    return true;
}

function convertH5pVideoToInteractiveVideo() {
    if (!courseData || !courseData.sections) return;
    var converted = 0;
    courseData.sections.forEach(function(section) {
        (section.activities || []).forEach(function(activity) {
            if (activity.h5pType !== 'CoursePresentation') return;
            var slides = activity.content && activity.content.presentation && activity.content.presentation.slides;
            if (!slides) return;
            slides.forEach(function(slide) {
                (slide.elements || []).forEach(function(element) {
                    if (convertVideoElementToIV(element)) converted++;
                });
            });
        });
    });
    if (converted > 0) {
        console.log('[Editor] Converti ' + converted + ' élément(s) H5P.Video → InteractiveVideo');
    }
}

// ==================== EXPORT MBZ ====================

function exportMbz() {
    // Auto-sync DDI editor si ouvert
    if (typeof qsCloseDdiEditor === 'function') qsCloseDdiEditor();
    
    showLoadingOverlay('Enregistrement en cours...', courseData.name || 'cours');
    updateSaveStatus('saving');
    
    courseData.name = document.getElementById('courseName').value;
    
    // Pas de flush avant l'export : resolveFileToLocal côté PHP gère les fichiers
    // qu'ils soient locaux (serve_upload) ou déjà sur Drive (lh3 URLs)
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'export_mbz',
            data: courseData,
            sessionId: getEditorSessionId()
        })
    })
    .then(r => r.json())
    .then(data => {
        hideLoadingOverlay();
        if (data.success && data.downloadUrl) {
            const a = document.createElement('a');
            a.href = data.downloadUrl;
            a.download = (courseData.name || 'cours').replace(/[^a-z0-9àâäéèêëïîôùûüç\s-]/gi, '_') + '.mbz';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            showToast('Fichier enregistré !', 'success');
            updateSaveStatus('saved');
        } else {
            throw new Error(data.error || 'Erreur d\'enregistrement');
        }
    })
    .catch(err => {
        hideLoadingOverlay();
        showToast('Erreur: ' + err.message, 'error');
        updateSaveStatus('modified');
    });
}

// ==================== EXPORT ÉLÉA (format tar.gz compatible Moodle/Éléa) ====================

function exportElea() {
    console.log('[Export] v20260309-3 exportElea called');
    if (typeof qsCloseDdiEditor === 'function') qsCloseDdiEditor();
    
    // Stopper la flush loop Drive pour que les fichiers locaux ne soient pas supprimés pendant l'export
    if (typeof EditorDriveSync !== 'undefined' && EditorDriveSync.pauseFlush) {
        EditorDriveSync.pauseFlush();
    }
    
    var _exportProgressId = newProgressId();
    var _exportAnnule = false;
    var _exportAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    // L'export ne modifie pas le cours : annuler consiste à cesser d'attendre. Le serveur
    // finit son travail dans son coin, l'archive produite sera nettoyée avec le cache.
    function annulerExport() {
        _exportAnnule = true;
        stopServerProgress();
        if (_exportAbort) _exportAbort.abort();
        if (typeof EditorDriveSync !== 'undefined' && EditorDriveSync.resumeFlush) {
            EditorDriveSync.resumeFlush();
        }
        showToast('Export annulé', 'info');
    }

    showLoadingOverlay('Export Éléa en cours...', courseData.name || 'cours', true, annulerExport);
    setLoadingProgress(0, 'Analyse du cours…');
    courseData.name = document.getElementById('courseName').value;

    var _exportStart = Date.now();
    var _exportTimer = setInterval(function() {
        console.log('[Export] En cours...', Math.round((Date.now() - _exportStart) / 1000) + 's');
    }, 10000);
    
    // Phase 1 : Diagnostic (rapide, ~1s)
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'export_diagnostic', data: courseData, sessionId: getEditorSessionId() })
    })
    .then(function(r) { return r.json(); })
    .then(function(diag) {
        if (diag.diagnostic) {
            var d = diag.diagnostic;
            console.group('%c[EXPORT DIAGNOSTIC]', 'color: #ec4899; font-weight: bold;');
            console.log('JSON:', d.json_size_kb + ' Ko |', d.sections, 'sections');
            console.log('URLs lh3:', d.lh3_urls_unique, '(en cache:', d.lh3_in_cache + ')');
            console.log('serve_upload:', d.serve_upload_unique);
            console.log('Fichiers locaux:', d.local_files, '(' + d.local_size_mb + ' Mo)');
            console.log('Mapping Drive:', d.drive_mapping_count);
            console.log('Cache drive_downloads:', d.cache_files);
            console.log('curl_multi:', d.curl_multi ? 'OUI' : 'NON');
            console.log('PHP limits: exec=' + d.php_max_execution + 's, mem=' + d.php_memory_limit);
            if (d.test_download) {
                console.log('Test DL:', 'HTTP ' + d.test_download.http_code, d.test_download.size_bytes + ' bytes,', d.test_download.time_ms + 'ms', d.test_download.curl_error || '');
            }
            if (d.last_export_log) {
                console.log('--- Dernier export log ---\n' + d.last_export_log);
            }
            console.groupEnd();
        }
    })
    .catch(function(e) { console.warn('[Export] Diagnostic échoué (pas grave):', e.message); })
    .then(function() {
        if (_exportAnnule) return null;
        // Phase 2 : Export réel — la barre suit l'avancement publié par l'exporteur
        console.log('[Export] Lancement export...');
        watchServerProgress(_exportProgressId, 0, 100);
        return fetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'export_elea', data: courseData, sessionId: getEditorSessionId(), progressId: _exportProgressId }),
            signal: _exportAbort ? _exportAbort.signal : undefined
        });
    })
    .then(function(r) {
        if (_exportAnnule || !r) return null;
        console.log('[Export] HTTP', r.status, r.statusText);
        if (!r.ok) {
            return r.text().then(function(t) { 
                console.error('[Export] Réponse erreur:', t.substring(0, 500));
                throw new Error('Erreur serveur: HTTP ' + r.status); 
            });
        }
        return r.text().then(function(t) {
            console.log('[Export] Réponse brute (500 premiers):', t.substring(0, 500));
            try { return JSON.parse(t); }
            catch(e) { console.error('[Export] JSON invalide'); throw new Error('Réponse serveur invalide'); }
        });
    })
    .then(function(data) {
        clearInterval(_exportTimer);
        if (_exportAnnule || !data) return;
        stopServerProgress();
        setLoadingProgress(100, 'Terminé');
        console.log('[Export] Réponse reçue en', Math.round((Date.now() - _exportStart) / 1000) + 's');
        hideLoadingOverlay();
        if (data.success && data.downloadUrl) {
            if (data._debug) {
                console.group('%c[EXPORT TIMING]', 'color: #f59e0b; font-weight: bold;');
                console.log('Init:', data._debug.init_ms + 'ms | Export:', data._debug.export_ms + 'ms | Total:', data._debug.total_ms + 'ms');
                console.log('MBZ:', data._debug.mbz_size_mb + ' Mo |', data._debug.files_in_manifest, 'fichiers | Mémoire peak:', data._debug.memory_peak_mb + ' Mo');
                if (data._debug.export_logs && data._debug.export_logs.length > 0) {
                    console.group('%c[EXPORT DDI LOGS]', 'color: #8b5cf6; font-weight: bold;');
                    data._debug.export_logs.forEach(function(msg) {
                        if (msg.indexOf('FAILED') !== -1) console.warn(msg);
                        else if (msg.indexOf('FALLBACK') !== -1) console.warn(msg);
                        else console.log(msg);
                    });
                    console.groupEnd();
                }
                console.groupEnd();
            }
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = data.downloadUrl;
            document.body.appendChild(iframe);
            setTimeout(function() { try { document.body.removeChild(iframe); } catch(e) {} }, 30000);
            // Prévenir si des activités n'ont pas pu être traduites : sans ce message elles
            // disparaîtraient du .mbz sans que le professeur le sache.
            var ecartees = data.droppedActivities || [];
            // Médias référencés mais introuvables partout au moment de l'export : le .mbz
            // est incomplet pour eux — à signaler AVANT que le prof importe sur Éléa.
            var manquants = data.unresolvedFiles || [];
            if (manquants.length) {
                console.error('[Export] Médias introuvables, .mbz incomplet :', manquants);
                showToast('⚠️ Export terminé mais ' + manquants.length + ' média(s) INTROUVABLE(S) — le .mbz est incomplet ('
                          + manquants.slice(0, 3).join(', ') + (manquants.length > 3 ? '…' : '') + ')', 'error');
            }
            if (ecartees.length) {
                showToast('Export terminé, mais ' + ecartees.length + ' activité(s) non exportable(s) ont été retirées : '
                          + ecartees.map(function(a) { return a.name; }).join(', '), 'error');
            } else if (!manquants.length) {
                showToast('Export Éléa terminé !', 'success');
            }
            // Reprendre la flush loop Drive
            if (typeof EditorDriveSync !== 'undefined' && EditorDriveSync.resumeFlush) {
                EditorDriveSync.resumeFlush();
            }
        } else {
            throw new Error(data.error || 'Erreur d\'export Éléa');
        }
    })
    .catch(function(err) {
        clearInterval(_exportTimer);
        // Abandon volontaire : annulerExport() a déjà tout remis en ordre
        if (_exportAnnule || err.name === 'AbortError') return;
        console.error('[Export] ERREUR après', Math.round((Date.now() - _exportStart) / 1000) + 's:', err.message);
        hideLoadingOverlay();
        // Reprendre la flush loop Drive même en cas d'erreur
        if (typeof EditorDriveSync !== 'undefined' && EditorDriveSync.resumeFlush) {
            EditorDriveSync.resumeFlush();
        }
        // Lire le log de progression pour comprendre où ça a crashé
        fetch('api/editor_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'export_diagnostic', data: {sections:[]}, sessionId: getEditorSessionId() })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.diagnostic && d.diagnostic.last_export_log) {
                console.error('[Export] === LOG SERVEUR DU CRASH ===\n' + d.diagnostic.last_export_log);
            }
        })
        .catch(function() {});
        showToast('Erreur: ' + err.message, 'error');
    });
}

// ==================== NOUVEAU COURS ====================

function newCourse() {
    if (serverSpaceFull) {
        showToast('Espace disque serveur plein (400 Mo). Impossible de créer un nouveau cours.', 'error');
        return;
    }
    if (courseData.sections.length > 0) {
        if (!confirm('Créer un nouveau cours ?\n\nLe cours actuel et tous ses fichiers (images, vidéos) seront supprimés du serveur et du Drive.\n\nCette action est irréversible.')) {
            return;
        }
    }
    
    // Création atomique côté serveur :
    //   1. cleanup de l'ancienne session (local + Drive + metadata + draft)
    //   2. génération nouveau session_id
    //   3. création de la nouvelle session
    // Le serveur retourne le nouveau session_id, on l'écrit en localStorage pour aligner les deux côtés.
    var oldSessionId = getEditorSessionId();

    // CRITIQUE : arrêter la boucle d'upload AVANT le fetch pour éviter qu'un flush
    // concurrent recrée le dossier Drive après la suppression. Sans ce reset préalable,
    // l'ancienne session continue à uploader pendant les ~500ms d'attente du fetch.
    EditorDriveSync.reset();

    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'create_course',
            old_session_id: oldSessionId,
            course_name: 'Nouveau cours'
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.session_id) {
            localStorage.setItem('elea_editor_session_id', data.session_id);
            EditorDriveSync.init(data.session_id);
            if (data.cleanup && data.cleanup.deleted) {
                console.log('[Editor] Cleanup session:', data.cleanup.deleted);
            }
        } else {
            // Fallback côté client si le serveur échoue (au pire, l'ancienne session reste)
            console.error('[Editor] create_course server error:', data.error || 'unknown');
            var fallbackId = regenerateEditorSessionId();
            EditorDriveSync.init(fallbackId);
        }
        if (typeof fetchServerUsage === 'function') fetchServerUsage();
    })
    .catch(function(err) {
        console.error('[Editor] create_course fetch error:', err);
        var fallbackId = regenerateEditorSessionId();
        EditorDriveSync.init(fallbackId);
    });

    courseData = {
        id: generateId(),
        name: 'Nouveau cours',
        shortname: 'cours1',
        vignette: null,
        sections: []
    };
    if (typeof courseVignetteRefreshUI === 'function') courseVignetteRefreshUI();

    document.getElementById('courseName').value = 'Nouveau cours';
    selectedSection = null;
    selectedActivity = null;

    // Restaurer le contenu original de emptyCanvas (peut avoir été remplacé
    // par le spinner de chargement lors d'une édition de cours permanent)
    var emptyCanvas = document.getElementById('emptyCanvas');
    if (emptyCanvas) {
        emptyCanvas.innerHTML = `
            <div class="empty-canvas-icon">📚</div>
            <h3>Commencez votre cours</h3>
            <p>Créez votre première section pour organiser vos activités</p>
            <button class="btn btn-primary" onclick="addSection()" style="margin-top: 1.5rem; padding: 0.75rem 1.5rem; font-size: 1rem;">
                ➕ Ajouter une section
            </button>`;
    }

    renderTree();
    showStructureView();
    renderProperties();
    
    _sessionFilesTotal = 0;
    
    onCourseModified();
    showToast('Nouveau cours créé', 'success');
}

// ==================== GÉNÉRATION PDF ====================

function editorGeneratePDF() {
    // Sync DDI editor si ouvert
    if (typeof qsCloseDdiEditor === 'function') qsCloseDdiEditor();
    
    courseData.name = document.getElementById('courseName').value;
    
    if (!courseData.sections || courseData.sections.length === 0) {
        showToast('Le cours est vide, impossible de générer un PDF', 'error');
        return;
    }
    
    showLoadingOverlay('Préparation du PDF...', courseData.name || 'cours');
    
    // Pas de flush avant le PDF : les images locales sont servies via serve_upload
    // et les images Drive via lh3.googleusercontent.com (accessibles publiquement)
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'preview_pdf',
            data: courseData,
            sessionId: getEditorSessionId()
        })
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(txt => {
                throw new Error('Erreur serveur ' + r.status);
            });
        }
        return r.text().then(txt => {
            try {
                return JSON.parse(txt);
            } catch (e) {
                throw new Error('Réponse invalide du serveur');
            }
        });
    })
    .then(data => {
        hideLoadingOverlay();
        if (data.success && data.viewUrl) {
            window.open(data.viewUrl, '_blank');
            showToast('Le PDF va se générer dans le nouvel onglet', 'success');
        } else {
            throw new Error(data.error || 'Erreur de prévisualisation');
        }
    })
    .catch(err => {
        hideLoadingOverlay();
        showToast('Erreur: ' + err.message, 'error');
    });
}
