// ==================== ÉDITEUR VIDÉO INTERACTIVE & DIALOG CARDS ====================
// ==================== ÉDITEUR VIDÉO INTERACTIVE ====================
let ivCurrentTime = 0;
let ivDuration = 100;
let ivSelectedInteraction = null;
let ivPlaying = false;
let ivVideoElement = null;
// État pour la mise en pause automatique aux interactions (option « Pause vidéo »)
let ivSeenInteractions = {};     // interactions déjà déclenchées (ne pas re-pauser)
let ivPausedForInteraction = false;
let ivLastTime = 0;              // temps du tick précédent (pour détecter le franchissement)

// Réinitialiser l'état des interactions (lors d'un seek : on doit pouvoir les revoir)
function ivResetInteractionState() {
    ivSeenInteractions = {};
    ivPausedForInteraction = false;
    ivLastTime = ivVideoElement ? ivVideoElement.currentTime : ivCurrentTime;
}

function renderInteractiveVideoEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.id === selectedSection);
    
    // Initialiser la structure
    if (!activity.content) activity.content = {};
    if (!activity.content.interactiveVideo) {
        activity.content.interactiveVideo = {
            video: { files: [], startScreenOptions: {} },
            assets: { interactions: [] }
        };
    }
    
    const iv = activity.content.interactiveVideo;
    const videoUrl = iv.video?.files?.[0]?.path || '';
    const interactions = iv.assets?.interactions || [];
    
    // Générer les marqueurs de timeline
    let markersHtml = '';
    interactions.forEach((inter, idx) => {
        const pos = (inter.time / Math.max(ivDuration, 1)) * 100;
        const type = (inter.action?.library || '').split(' ')[0].replace('H5P.', '');
        const icon = getInteractionIcon(type);
        markersHtml += `
            <div class="iv-timeline-marker ${ivSelectedInteraction === idx ? 'selected' : ''}" 
                 style="left: ${pos}%;" 
                 onclick="ivSelectInteraction(${idx})"
                 title="${formatTime(inter.time)} - ${type}">
                ${icon}
            </div>`;
    });
    
    // Liste des interactions
    let interactionsListHtml = '';
    interactions.forEach((inter, idx) => {
        const type = (inter.action?.library || '').split(' ')[0].replace('H5P.', '');
        const icon = getInteractionIcon(type);
        const typeFr = getInteractionTypeFr(type);
        const preview = getInteractionPreview(inter);
        
        interactionsListHtml += `
            <div class="iv-interaction-card ${ivSelectedInteraction === idx ? 'selected' : ''}" 
                 onclick="ivSelectInteraction(${idx})">
                <div class="iv-interaction-header">
                    <span class="iv-interaction-icon">${icon}</span>
                    <span class="iv-interaction-type">${typeFr}</span>
                    <span class="iv-interaction-time">${formatTime(inter.duration?.from || inter.time || 0)}</span>
                </div>
                <div class="iv-interaction-preview">${escapeHtml(preview)}</div>
                <div class="iv-interaction-actions">
                    <button class="btn btn-secondary" onclick="event.stopPropagation(); ivEditInteraction(${idx})" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">✏️ Éditer</button>
                    <button class="btn btn-secondary" onclick="event.stopPropagation(); ivDeleteInteraction(${idx})" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">🗑️</button>
                </div>
            </div>`;
    });
    
    // Overlays d'interactions sur la vidéo
    let overlaysHtml = '';
    interactions.forEach((inter, idx) => {
        const fromT = inter.duration?.from ?? inter.time ?? 0;
        const toT = inter.duration?.to ?? fromT + 10;
        const visible = ivCurrentTime >= fromT && ivCurrentTime <= toT;
        const iType = (inter.action?.library || '').split(' ')[0].replace('H5P.', '');
        const iIcon = getInteractionIcon(iType);
        const iTypeFr = getInteractionTypeFr(iType);
        const iPreview = getInteractionPreview(inter);
        const isSelected = ivSelectedInteraction === idx;
        overlaysHtml += `
            <div class="iv-overlay-interaction ${visible ? 'visible' : ''} ${isSelected ? 'selected' : ''}"
                 style="left:${inter.x || 45}%;top:${inter.y || 45}%;"
                 data-from="${fromT}" data-to="${toT}" data-idx="${idx}" data-pause="${inter.pause !== false ? '1' : '0'}"
                 onclick="event.stopPropagation(); ivSelectInteraction(${idx})"
                 ondblclick="event.stopPropagation(); ivEditInteraction(${idx})">
                <span class="iv-overlay-icon">${iIcon}</span>
                <span class="iv-overlay-label">${escapeHtml(iTypeFr)}: ${escapeHtml(iPreview.substring(0, 25))}</span>
            </div>`;
    });
    
    content.innerHTML = `
        <div class="iv-editor">
            <div class="iv-editor-header">
                ${editorHeaderHtml('🎥', activity.name, section.id)}
            </div>
            
            <div class="iv-main">
                <div class="iv-video-area">
                    <div class="iv-video-container" id="ivVideoContainer">
                        ${videoUrl ? `
                            <video class="iv-video-player" id="ivVideo" 
                                   onloadedmetadata="ivOnVideoLoaded(this)"
                                   ontimeupdate="ivOnTimeUpdate(this)">
                                <source src="${escapeHtml(videoUrl)}" type="video/mp4">
                            </video>
                        ` : `
                            <div class="iv-video-placeholder">
                                <div class="iv-video-placeholder-icon">🎬</div>
                                <p>Aucune vidéo sélectionnée</p>
                                <button class="btn btn-primary" onclick="ivShowVideoDialog()">Ajouter une vidéo</button>
                            </div>
                        `}
                        ${overlaysHtml}
                    </div>
                    
                    <div class="iv-controls">
                        <button class="iv-control-btn ${ivPlaying ? '' : 'primary'}" onclick="ivTogglePlay()" id="ivPlayBtn">
                            ${ivPlaying ? '⏸' : '▶'}
                        </button>
                        <button class="iv-control-btn" onclick="ivSeek(-5)">⏪</button>
                        <button class="iv-control-btn" onclick="ivSeek(5)">⏩</button>
                        <span class="iv-time-display" id="ivTimeDisplay">${formatTime(ivCurrentTime)} / ${formatTime(ivDuration)}</span>
                        <div style="flex: 1;"></div>
                        ${videoUrl ? `<button class="btn btn-secondary" onclick="ivShowVideoDialog()" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">🔄 Changer</button>` : ''}
                    </div>
                    
                    <div class="iv-timeline">
                        <div class="iv-timeline-track" id="ivTimeline" onclick="ivSeekToClick(event)">
                            <div class="iv-timeline-progress" id="ivProgress" style="width: ${(ivCurrentTime / Math.max(ivDuration, 1)) * 100}%;"></div>
                            <div class="iv-timeline-cursor" id="ivCursor" style="left: ${(ivCurrentTime / Math.max(ivDuration, 1)) * 100}%;"></div>
                            ${markersHtml}
                        </div>
                        <div class="iv-timeline-time">
                            <span>0:00</span>
                            <span>${formatTime(ivDuration)}</span>
                        </div>
                    </div>
                </div>
                
                <div class="iv-sidebar">
                    <div class="iv-sidebar-tabs">
                        <button class="iv-sidebar-tab active" onclick="ivSwitchTab('interactions')">Interactions</button>
                        <button class="iv-sidebar-tab" onclick="ivSwitchTab('settings')">Paramètres</button>
                    </div>
                    
                    <div class="iv-sidebar-content" id="ivSidebarContent">
                        <div id="ivTabInteractions">
                            <div class="iv-add-interaction">
                                <p style="font-size: 0.8rem; color: var(--gray-500); margin-bottom: 0.5rem;">Ajouter à ${formatTime(ivCurrentTime)} :</p>
                                <button class="iv-add-interaction-btn" onclick="ivAddInteraction('text')">
                                    <span>📝</span> Texte à afficher
                                </button>
                                <button class="iv-add-interaction-btn" onclick="ivAddInteraction('multichoice')">
                                    <span>☑️</span> QCM
                                </button>
                                <button class="iv-add-interaction-btn" onclick="ivAddInteraction('truefalse')">
                                    <span>✅</span> Vrai / Faux
                                </button>
                                <button class="iv-add-interaction-btn" onclick="ivAddInteraction('statements')">
                                    <span>📋</span> Résumé
                                </button>
                            </div>
                            
                            <hr style="margin: 1rem 0; border: none; border-top: 1px solid var(--gray-200);">
                            
                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--gray-500); margin-bottom: 0.75rem;">
                                ${interactions.length} interaction(s)
                            </div>
                            
                            ${interactionsListHtml || '<p style="color: var(--gray-400); font-size: 0.85rem; text-align: center; padding: 1rem;">Aucune interaction</p>'}
                        </div>
                        
                        <div id="ivTabSettings" style="display: none;">
                            <div class="cp-prop-group">
                                <label class="cp-prop-label">URL de la vidéo</label>
                                <input type="text" class="cp-prop-input" value="${escapeHtml(videoUrl)}" 
                                       onchange="ivSetVideoUrl(this.value)" placeholder="https://...">
                            </div>
                            <div class="cp-prop-group">
                                <label class="cp-prop-label">Ou uploader un fichier</label>
                                <input type="file" class="cp-prop-input" accept="video/*" onchange="ivUploadVideo(this)">
                            </div>
                            <div class="cp-prop-group">
                                <label class="cp-prop-label">Durée (secondes)</label>
                                <input type="number" class="cp-prop-input" value="${ivDuration}" 
                                       onchange="ivDuration = parseFloat(this.value); renderInteractiveVideoEditor(getSelectedActivity());">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    
    // Réinitialiser la référence vidéo
    ivVideoElement = document.getElementById('ivVideo');
}

function getInteractionIcon(type) {
    const icons = {
        'Text': '📝', 'text': '📝',
        'MultiChoice': '☑️', 'multichoice': '☑️',
        'TrueFalse': '✅', 'truefalse': '✅',
        'Summary': '📋', 'summary': '📋', 'Statements': '📋', 'statements': '📋',
        'Blanks': '✏️', 'blanks': '✏️',
        'Image': '🖼️', 'image': '🖼️'
    };
    return icons[type] || '💬';
}

function getInteractionTypeFr(type) {
    const names = {
        'Text': 'Texte', 'text': 'Texte',
        'MultiChoice': 'QCM', 'multichoice': 'QCM',
        'TrueFalse': 'Vrai/Faux', 'truefalse': 'Vrai/Faux',
        'Summary': 'Résumé', 'summary': 'Résumé', 'Statements': 'Résumé', 'statements': 'Résumé',
        'Blanks': 'Texte à trous', 'blanks': 'Texte à trous',
        'Image': 'Image', 'image': 'Image'
    };
    return names[type] || type;
}

function getInteractionPreview(inter) {
    const params = inter.action?.params || {};
    if (params.text) return params.text.replace(/<[^>]+>/g, '').substring(0, 50);
    if (params.question) return params.question.replace(/<[^>]+>/g, '').substring(0, 50);
    if (params.label) return params.label.replace(/<[^>]+>/g, '').substring(0, 50);
    return 'Interaction';
}

function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function ivOnVideoLoaded(video) {
    ivDuration = video.duration || 100;
    ivVideoElement = video;
    const timeDisplay = document.getElementById('ivTimeDisplay');
    if (timeDisplay) {
        timeDisplay.textContent = `${formatTime(ivCurrentTime)} / ${formatTime(ivDuration)}`;
    }
}

function ivOnTimeUpdate(video) {
    ivCurrentTime = video.currentTime;
    const progress = document.getElementById('ivProgress');
    const cursor = document.getElementById('ivCursor');
    const timeDisplay = document.getElementById('ivTimeDisplay');
    
    const percent = (ivCurrentTime / Math.max(ivDuration, 1)) * 100;
    if (progress) progress.style.width = percent + '%';
    if (cursor) cursor.style.left = percent + '%';
    if (timeDisplay) timeDisplay.textContent = `${formatTime(ivCurrentTime)} / ${formatTime(ivDuration)}`;
    
    // Mettre à jour la visibilité des overlays d'interactions + gérer la pause auto
    let pauseAt = null;
    document.querySelectorAll('.iv-overlay-interaction').forEach(el => {
        const from = parseFloat(el.dataset.from || 0);
        const to = parseFloat(el.dataset.to || 0);
        const idx = el.dataset.idx;
        const doPause = el.dataset.pause === '1';
        // Interaction ponctuelle (durée 0) : fenêtre de 0.5s ; sinon intervalle from→to
        const effectiveTo = (to <= from) ? from + 0.5 : to;
        el.classList.toggle('visible', ivCurrentTime >= from && ivCurrentTime <= effectiveTo);

        // Déclenchement de la pause : on détecte le FRANCHISSEMENT de `from` entre deux ticks
        // de lecture (la fenêtre from→to peut être plus courte que l'intervalle entre deux ticks).
        // Sur un seek manuel, ivLastTime est calé sur la cible → pas de franchissement parasite.
        const reached = ivLastTime < from && ivCurrentTime >= from;
        if (doPause && reached && !ivSeenInteractions[idx] && !ivPausedForInteraction) {
            ivSeenInteractions[idx] = true;
            if (pauseAt === null || from < pauseAt) pauseAt = from;
        }
        // Permettre de re-déclencher l'interaction si on revient en arrière avant elle
        if (ivCurrentTime < from - 0.3) {
            ivSeenInteractions[idx] = false;
        }
    });

    if (pauseAt !== null && ivVideoElement) {
        // Caler la vidéo exactement sur l'interaction pour que le cadre s'affiche
        ivVideoElement.currentTime = pauseAt;
        ivVideoElement.pause();
        ivPausedForInteraction = true;
        ivPlaying = false;
        ivLastTime = pauseAt;
        const btn = document.getElementById('ivPlayBtn');
        if (btn) {
            btn.innerHTML = '▶';
            btn.classList.add('primary');
        }
    } else {
        ivLastTime = ivCurrentTime;
    }
}

function ivTogglePlay() {
    if (!ivVideoElement) return;
    
    if (ivVideoElement.paused) {
        // Si on était en pause à cause d'une interaction, avancer légèrement pour ne pas re-pauser aussitôt
        if (ivPausedForInteraction) {
            ivVideoElement.currentTime = Math.min(ivDuration, ivVideoElement.currentTime + 0.2);
            ivPausedForInteraction = false;
        }
        ivVideoElement.play();
        ivPlaying = true;
    } else {
        ivVideoElement.pause();
        ivPlaying = false;
    }
    
    const btn = document.getElementById('ivPlayBtn');
    if (btn) {
        btn.innerHTML = ivPlaying ? '⏸' : '▶';
        btn.classList.toggle('primary', !ivPlaying);
    }
}

function ivSeek(delta) {
    if (ivVideoElement) {
        ivVideoElement.currentTime = Math.max(0, Math.min(ivDuration, ivVideoElement.currentTime + delta));
    } else {
        ivCurrentTime = Math.max(0, Math.min(ivDuration, ivCurrentTime + delta));
        renderInteractiveVideoEditor(getSelectedActivity());
    }
    ivResetInteractionState(); // après le seek : cale ivLastTime sur la nouvelle position
}

function ivSeekToClick(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const percent = (event.clientX - rect.left) / rect.width;
    const time = percent * ivDuration;

    if (ivVideoElement) {
        ivVideoElement.currentTime = time;
    } else {
        ivCurrentTime = time;
        renderInteractiveVideoEditor(getSelectedActivity());
    }
    ivResetInteractionState(); // après le seek : cale ivLastTime sur la nouvelle position
}

function ivSwitchTab(tab) {
    document.querySelectorAll('.iv-sidebar-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.iv-sidebar-tab[onclick*="${tab}"]`)?.classList.add('active');
    
    document.getElementById('ivTabInteractions').style.display = tab === 'interactions' ? 'block' : 'none';
    document.getElementById('ivTabSettings').style.display = tab === 'settings' ? 'block' : 'none';
}

function ivShowVideoDialog() {
    ivSwitchTab('settings');
}

function ivSetVideoUrl(url) {
    const activity = getSelectedActivity();
    if (!activity.content.interactiveVideo.video.files) {
        activity.content.interactiveVideo.video.files = [];
    }
    activity.content.interactiveVideo.video.files = [{ path: url, mime: 'video/mp4' }];
    renderInteractiveVideoEditor(activity);
    onCourseModified();
}

function ivUploadVideo(input) {
    const file = input.files[0];
    if (!file) return;
    
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    
    showToast('Upload en cours...', 'info');
    
    fetch('api/editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            ivSetVideoUrl(data.url);
            showToast('Vidéo uploadée', 'success');
        } else {
            throw new Error(data.error);
        }
    })
    .catch(err => showToast('Erreur: ' + err.message, 'error'));
}

function ivAddInteraction(type) {
    const activity = getSelectedActivity();
    const iv = activity.content.interactiveVideo;
    if (!iv.assets) iv.assets = {};
    if (!iv.assets.interactions) iv.assets.interactions = [];
    
    let interaction = {
        time: ivCurrentTime,
        x: 45,
        y: 45,
        width: 10,
        height: 10,
        duration: { from: ivCurrentTime, to: ivCurrentTime + 10 },
        pause: true,
        displayType: 'poster',
        buttonOnMobile: false,
        action: {}
    };
    
    switch (type) {
        case 'text':
            interaction.action = {
                library: 'H5P.Text 1.1',
                params: { text: '<p>Texte à afficher</p>' }
            };
            interaction.label = '<p>Texte</p>';
            break;
        case 'multichoice':
            interaction.action = {
                library: 'H5P.MultiChoice 1.16',
                params: {
                    question: '<p>Question ?</p>',
                    answers: [
                        { text: '<p>Réponse A</p>', correct: true, tpiAndTci: { tip: '', chosenFeedback: '', notChosenFeedback: '' } },
                        { text: '<p>Réponse B</p>', correct: false, tpiAndTci: { tip: '', chosenFeedback: '', notChosenFeedback: '' } }
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
                    },
                    UI: {
                        checkAnswerButton: 'V\u00e9rifier',
                        submitAnswerButton: 'Soumettre',
                        showSolutionButton: 'Afficher la solution',
                        tryAgainButton: 'Recommencer',
                        tipsLabel: 'Afficher un indice',
                        scoreBarLabel: 'Vous avez :num sur :total points',
                        tipAvailable: 'Indice disponible',
                        feedbackAvailable: 'Feedback disponible',
                        readFeedback: 'Lire le feedback',
                        wrongAnswer: 'Mauvaise r\u00e9ponse',
                        correctAnswer: 'Bonne r\u00e9ponse',
                        shouldCheck: 'Aurait d\u00fb \u00eatre coch\u00e9e',
                        shouldNotCheck: 'N\u2019aurait pas d\u00fb \u00eatre coch\u00e9e',
                        noInput: 'Veuillez r\u00e9pondre avant de voir la solution',
                        a11yCheck: 'V\u00e9rifier les r\u00e9ponses.',
                        a11yShowSolution: 'Afficher la solution.',
                        a11yRetry: 'Recommencer.'
                    },
                    confirmCheck: { header: 'Terminer ?', body: '\u00cates-vous s\u00fbr de vouloir terminer ?', cancelLabel: 'Annuler', confirmLabel: 'Terminer' },
                    confirmRetry: { header: 'Recommencer ?', body: '\u00cates-vous s\u00fbr de vouloir recommencer ?', cancelLabel: 'Annuler', confirmLabel: 'Confirmer' }
                }
            };
            interaction.label = '<p>QCM</p>';
            break;
        case 'truefalse':
            interaction.action = {
                library: 'H5P.TrueFalse 1.8',
                params: {
                    question: '<p>Affirmation ?</p>',
                    correct: 'true',
                    behaviour: {
                        enableRetry: true,
                        enableSolutionsButton: false,
                        confirmCheckDialog: false,
                        confirmRetryDialog: false
                    },
                    l10n: {
                        trueText: 'Vrai',
                        falseText: 'Faux',
                        score: 'Vous avez @score sur @total points',
                        checkAnswer: 'V\u00e9rifier',
                        submitAnswer: 'Soumettre',
                        showSolutionButton: 'Afficher la solution',
                        tryAgain: 'Recommencer',
                        wrongAnswerMessage: 'Mauvaise r\u00e9ponse',
                        correctAnswerMessage: 'Bonne r\u00e9ponse'
                    },
                    confirmCheck: { header: 'Terminer ?', body: '\u00cates-vous s\u00fbr ?', cancelLabel: 'Annuler', confirmLabel: 'Terminer' },
                    confirmRetry: { header: 'Recommencer ?', body: '\u00cates-vous s\u00fbr ?', cancelLabel: 'Annuler', confirmLabel: 'Confirmer' }
                }
            };
            interaction.label = '<p>Vrai/Faux</p>';
            break;
        case 'statements':
            interaction.action = {
                library: 'H5P.Summary 1.10',
                params: {
                    intro: 'Choisissez les bonnes affirmations',
                    summaries: [{ summary: ['Affirmation correcte'], tip: '' }]
                }
            };
            interaction.label = '<p>Résumé</p>';
            break;
    }
    
    iv.assets.interactions.push(interaction);
    ivSelectedInteraction = iv.assets.interactions.length - 1;
    renderInteractiveVideoEditor(activity);
    onCourseModified();
    showToast('Interaction ajoutée', 'success');
}

function ivSelectInteraction(idx) {
    ivSelectedInteraction = idx;

    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (inter && ivVideoElement) {
        ivVideoElement.currentTime = inter.time;
    } else if (inter) {
        ivCurrentTime = inter.time;
    }

    ivResetInteractionState(); // après le déplacement : cale ivLastTime sur la nouvelle position
    renderInteractiveVideoEditor(activity);
}

function ivEditInteraction(idx) {
    ivSelectedInteraction = idx;
    const modal = document.getElementById('ivEditInteractionModal');
    if (modal) modal.style.display = 'flex';
    ivRenderInteractionEditor();
}

function ivRenderInteractionEditor() {
    const container = document.getElementById('ivInteractionEditorContent');
    if (!container || ivSelectedInteraction === null) return;
    
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[ivSelectedInteraction];
    if (!inter) return;
    
    const idx = ivSelectedInteraction;
    const type = (inter.action?.library || '').split(' ')[0].replace('H5P.', '').toLowerCase();
    const typeFr = getInteractionTypeFr(type.charAt(0).toUpperCase() + type.slice(1));
    const params = inter.action?.params || {};
    const displayType = inter.displayType || 'poster';
    const pauseVideo = inter.pause !== false;
    
    const titleEl = document.getElementById('ivModalTitle');
    if (titleEl) titleEl.textContent = `${getInteractionIcon(type)} ${typeFr}`;
    
    let html = `
        <div style="display: flex; gap: 0.75rem; margin-bottom: 0.75rem;">
            <div style="flex: 1;">
                <label class="cp-prop-label" style="font-size: 0.8rem;">Mode d'affichage</label>
                <select class="cp-prop-input" onchange="ivUpdateProp(${idx}, 'displayType', this.value)" style="font-size: 0.85rem;">
                    <option value="poster" ${displayType === 'poster' ? 'selected' : ''}>📋 Cadre (poster)</option>
                    <option value="button" ${displayType === 'button' ? 'selected' : ''}>🔘 Bouton cliquable</option>
                </select>
            </div>
            <div style="flex: 1;">
                <label class="cp-prop-label" style="font-size: 0.8rem;">Pause vidéo</label>
                <select class="cp-prop-input" onchange="ivUpdateProp(${idx}, 'pause', this.value === 'true')" style="font-size: 0.85rem;">
                    <option value="true" ${pauseVideo ? 'selected' : ''}>⏸ Oui</option>
                    <option value="false" ${!pauseVideo ? 'selected' : ''}>▶ Non</option>
                </select>
            </div>
        </div>
        
        <div style="display: flex; gap: 0.75rem; margin-bottom: 0.75rem;">
            <div style="flex: 1;">
                <label class="cp-prop-label" style="font-size: 0.8rem;">Début (sec)</label>
                <input type="number" class="cp-prop-input" value="${inter.duration?.from || 0}" min="0" step="0.5"
                       onchange="ivUpdateTiming(${idx}, 'from', parseFloat(this.value))" style="font-size: 0.85rem;">
            </div>
            <div style="flex: 1;">
                <label class="cp-prop-label" style="font-size: 0.8rem;">Fin (sec)</label>
                <input type="number" class="cp-prop-input" value="${inter.duration?.to || 10}" min="0" step="0.5"
                       onchange="ivUpdateTiming(${idx}, 'to', parseFloat(this.value))" style="font-size: 0.85rem;">
            </div>
        </div>
        
        <hr style="margin: 0.75rem 0; border: none; border-top: 1px solid var(--gray-200);">`;
    
    switch (type) {
        case 'text': {
            const textContent = params.text || '';
            html += `
                <label class="cp-prop-label" style="font-size: 0.8rem;">Contenu</label>
                <div class="cp-blanks-formatting-bar" style="margin-bottom: 2px;">
                    <button type="button" onclick="ivExecCmd('ivRichText','bold')" title="Gras"><b>G</b></button>
                    <button type="button" onclick="ivExecCmd('ivRichText','italic')" title="Italique"><i>I</i></button>
                    <button type="button" onclick="ivExecCmd('ivRichText','underline')" title="Souligné"><u>S</u></button>
                    <span style="width:1px;background:var(--gray-300);margin:0 4px;"></span>
                    <button type="button" onclick="ivExecCmd('ivRichText','justifyLeft')" title="Aligner à gauche">⬅</button>
                    <button type="button" onclick="ivExecCmd('ivRichText','justifyCenter')" title="Centrer">⬌</button>
                    <button type="button" onclick="ivExecCmd('ivRichText','justifyRight')" title="Aligner à droite">➡</button>
                </div>
                <div id="ivRichText" class="cp-blanks-richtext-editor" contenteditable="true" 
                     style="min-height: 80px; font-size: 0.9rem;"
                     oninput="ivOnRichInput(${idx}, 'text')">${textContent}</div>`;
            break;
        }
        case 'multichoice': {
            const question = params.question || '';
            const answers = params.answers || [];
            html += `
                <label class="cp-prop-label" style="font-size: 0.8rem;">Question</label>
                <div class="cp-blanks-formatting-bar" style="margin-bottom: 2px;">
                    <button type="button" onclick="ivExecCmd('ivMcQuestion','bold')" title="Gras"><b>G</b></button>
                    <button type="button" onclick="ivExecCmd('ivMcQuestion','italic')" title="Italique"><i>I</i></button>
                    <button type="button" onclick="ivExecCmd('ivMcQuestion','underline')" title="Souligné"><u>S</u></button>
                </div>
                <div id="ivMcQuestion" class="cp-blanks-richtext-editor" contenteditable="true" 
                     style="min-height: 40px; font-size: 0.9rem;"
                     oninput="ivOnRichInput(${idx}, 'question')">${question}</div>
                
                <label class="cp-prop-label" style="font-size: 0.8rem; margin-top: 0.75rem;">Réponses</label>`;
            answers.forEach((ans, aIdx) => {
                const ansText = (ans.text || '').replace(/<[^>]*>/g, '');
                html += `
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem;">
                    <input type="checkbox" ${ans.correct ? 'checked' : ''} 
                           onchange="ivUpdateAnswer(${idx}, ${aIdx}, 'correct', this.checked)" title="Bonne réponse">
                    <input type="text" class="cp-prop-input" value="${escapeHtml(ansText)}" style="flex:1; font-size: 0.85rem;"
                           onchange="ivUpdateAnswer(${idx}, ${aIdx}, 'text', '<p>' + this.value + '</p>')">
                    <button class="btn btn-secondary" style="padding:0.2rem 0.4rem; font-size:0.7rem;" 
                            onclick="ivRemoveAnswer(${idx}, ${aIdx})">✗</button>
                </div>`;
            });
            html += `<button class="btn btn-secondary" style="margin-top: 0.3rem; font-size: 0.8rem; padding: 0.3rem 0.8rem;"
                             onclick="ivAddAnswer(${idx})">+ Ajouter une réponse</button>`;
            break;
        }
        case 'truefalse': {
            const question = params.question || '';
            const correct = params.correct === 'true' || params.correct === true;
            html += `
                <label class="cp-prop-label" style="font-size: 0.8rem;">Affirmation</label>
                <div class="cp-blanks-formatting-bar" style="margin-bottom: 2px;">
                    <button type="button" onclick="ivExecCmd('ivTfQuestion','bold')" title="Gras"><b>G</b></button>
                    <button type="button" onclick="ivExecCmd('ivTfQuestion','italic')" title="Italique"><i>I</i></button>
                    <button type="button" onclick="ivExecCmd('ivTfQuestion','underline')" title="Souligné"><u>S</u></button>
                </div>
                <div id="ivTfQuestion" class="cp-blanks-richtext-editor" contenteditable="true" 
                     style="min-height: 40px; font-size: 0.9rem;"
                     oninput="ivOnRichInput(${idx}, 'question')">${question}</div>
                
                <label class="cp-prop-label" style="font-size: 0.8rem; margin-top: 0.75rem;">Réponse correcte</label>
                <select class="cp-prop-input" style="font-size: 0.85rem;"
                        onchange="ivUpdateParam(${idx}, 'correct', this.value)">
                    <option value="true" ${correct ? 'selected' : ''}>Vrai</option>
                    <option value="false" ${!correct ? 'selected' : ''}>Faux</option>
                </select>`;
            break;
        }
        case 'summary': {
            html += `<p style="color: var(--gray-500); font-size: 0.85rem;">Éditeur de résumé - modifiable dans la liste des interactions.</p>`;
            break;
        }
        default:
            html += `<p style="color: var(--gray-500); font-size: 0.85rem;">Éditeur non disponible pour ce type d'interaction.</p>`;
    }
    
    container.innerHTML = html;
}

function ivExecCmd(editorId, command) {
    const editor = document.getElementById(editorId);
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    // Trigger input pour sauvegarder
    editor.dispatchEvent(new Event('input'));
}

function ivOnRichInput(idx, param) {
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (!inter) return;
    
    // Trouver l'éditeur approprié
    let editorId;
    if (param === 'text') editorId = 'ivRichText';
    else if (param === 'question') {
        const type = (inter.action?.library || '').toLowerCase();
        editorId = type.includes('multichoice') ? 'ivMcQuestion' : 'ivTfQuestion';
    }
    
    const editor = document.getElementById(editorId);
    if (!editor || !inter.action) return;
    if (!inter.action.params) inter.action.params = {};
    
    // Convertir <b> -> <strong>, <i> -> <em>
    let html = editor.innerHTML;
    html = html.replace(/<b(\s|>)/gi, '<strong$1').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i(\s|>)/gi, '<em$1').replace(/<\/i>/gi, '</em>');
    
    inter.action.params[param] = html;
    onCourseModified();
}

function ivUpdateProp(idx, prop, value) {
    const activity = getSelectedActivity();
    activity.content.interactiveVideo.assets.interactions[idx][prop] = value;
    onCourseModified();
}

function ivUpdateTiming(idx, prop, value) {
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (!inter.duration) inter.duration = { from: 0, to: 10 };
    inter.duration[prop] = value;
    if (prop === 'from') inter.time = value;
    onCourseModified();
}

function ivUpdateParam(idx, prop, value) {
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (!inter.action) inter.action = {};
    if (!inter.action.params) inter.action.params = {};
    inter.action.params[prop] = value;
    onCourseModified();
}

function ivUpdateAnswer(idx, aIdx, prop, value) {
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (!inter.action.params.answers) return;
    if (!inter.action.params.answers[aIdx]) return;
    inter.action.params.answers[aIdx][prop] = value;
    onCourseModified();
}

function ivAddAnswer(idx) {
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (!inter.action.params.answers) inter.action.params.answers = [];
    inter.action.params.answers.push({ text: '<p>Nouvelle réponse</p>', correct: false, tpiAndTci: { tip: '', chosenFeedback: '', notChosenFeedback: '' } });
    ivRenderInteractionEditor();
    onCourseModified();
}

function ivRemoveAnswer(idx, aIdx) {
    const activity = getSelectedActivity();
    const inter = activity.content.interactiveVideo.assets.interactions[idx];
    if (!inter.action.params.answers || inter.action.params.answers.length <= 2) {
        showToast('Il faut au moins 2 réponses', 'error');
        return;
    }
    inter.action.params.answers.splice(aIdx, 1);
    ivRenderInteractionEditor();
    onCourseModified();
}

function ivDeleteInteraction(idx) {
    if (!confirm('Supprimer cette interaction ?')) return;
    
    const activity = getSelectedActivity();
    activity.content.interactiveVideo.assets.interactions.splice(idx, 1);
    ivSelectedInteraction = null;
    renderInteractiveVideoEditor(activity);
    onCourseModified();
}

function ivCloseInteractionEditor() {
    const modal = document.getElementById('ivEditInteractionModal');
    if (modal) modal.style.display = 'none';
    renderInteractiveVideoEditor(getSelectedActivity());
}

// ==================== ÉDITEUR DIALOG CARDS ====================
function renderDialogCardsEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.id === selectedSection);
    
    if (!activity.content) activity.content = {};
    if (!activity.content.dialogs) activity.content.dialogs = [{ text: '', answer: '', tips: {}, image: null }];
    const beh = activity.content.behaviour || {};
    const randomCards = beh.randomCards === true;
    
    let cardsHtml = '';
    activity.content.dialogs.forEach((card, idx) => {
        const textPreview = (card.text || '').replace(/<[^>]*>/g, '').substring(0, 40) || 'Carte ' + (idx + 1);
        
        // Image section
        let imageHtml = '';
        if (card.image && card.image.path) {
            const w = card.image.width || '';
            const h = card.image.height || '';
            imageHtml = `
                <div class="dc-image-preview">
                    <img src="${card.image.path}" alt="Image" style="max-width: 100%; height: auto; max-height: 200px; border-radius: 6px;">
                    <div class="qs-image-controls" style="margin-top: 0.3rem;">
                        ${w && h ? `<span class="qs-image-size-label">${w}×${h}</span>` : ''}
                        <button type="button" class="qs-btn-small" onclick="dcRemoveImage(${idx})">✕ Retirer</button>
                    </div>
                </div>`;
        } else {
            imageHtml = `<button type="button" class="quiz-add-answer" onclick="dcInsertImage(${idx})">📷 Ajouter une image</button>`;
        }
        
        cardsHtml += `
            <div class="quiz-question-card" data-dcidx="${idx}">
                <div class="quiz-question-header" onclick="dcToggleCard(${idx})">
                    <div class="quiz-question-num">${idx + 1}</div>
                    <div class="quiz-question-title">${escapeHtml(textPreview)}</div>
                    <button class="tree-action-btn" onclick="event.stopPropagation(); dcMoveCard(${idx}, -1)" title="Monter">⬆️</button>
                    <button class="tree-action-btn" onclick="event.stopPropagation(); dcMoveCard(${idx}, 1)" title="Descendre">⬇️</button>
                    <button class="tree-action-btn" onclick="event.stopPropagation(); dcDeleteCard(${idx})" title="Supprimer">🗑️</button>
                </div>
                <div class="quiz-question-body" id="dcCard${idx}" style="display: ${idx === 0 ? "block" : "none"};">
                    <div class="cp-prop-group">
                        <label class="cp-prop-label">📷 Image</label>
                        <input type="file" id="dcImageInput${idx}" accept="image/*" style="display:none" onchange="dcHandleImageUpload(${idx}, this)">
                        ${imageHtml}
                    </div>
                    <div class="cp-prop-group">
                        <label class="cp-prop-label">Recto (question)</label>
                        <div class="cp-blanks-richtext-wrap">
                            <div class="cp-blanks-richtext-toolbar">
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'recto', 'bold')" title="Gras"><b>G</b></button>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'recto', 'italic')" title="Italique"><i>I</i></button>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'recto', 'underline')" title="Souligné"><u>S</u></button>
                                <span class="qs-rt-sep"></span>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'recto', 'justifyCenter')" title="Centrer">⬌</button>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'recto', 'removeFormat')" title="Effacer">⊘</button>
                            </div>
                            <div class="cp-blanks-richtext-editor" contenteditable="true" id="dcRecto${idx}"
                                 oninput="dcOnRichInput(${idx}, 'text')"
                                 onblur="dcOnRichInput(${idx}, 'text')">${card.text || ''}</div>
                        </div>
                    </div>
                    <div class="cp-prop-group">
                        <label class="cp-prop-label">Verso (réponse)</label>
                        <div class="cp-blanks-richtext-wrap">
                            <div class="cp-blanks-richtext-toolbar">
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'verso', 'bold')" title="Gras"><b>G</b></button>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'verso', 'italic')" title="Italique"><i>I</i></button>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'verso', 'underline')" title="Souligné"><u>S</u></button>
                                <span class="qs-rt-sep"></span>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'verso', 'justifyCenter')" title="Centrer">⬌</button>
                                <button type="button" class="qs-rt-btn" onclick="dcExecCmd(${idx}, 'verso', 'removeFormat')" title="Effacer">⊘</button>
                            </div>
                            <div class="cp-blanks-richtext-editor" contenteditable="true" id="dcVerso${idx}"
                                 oninput="dcOnRichInput(${idx}, 'answer')"
                                 onblur="dcOnRichInput(${idx}, 'answer')">${card.answer || ''}</div>
                        </div>
                    </div>
                </div>
            </div>`;
    });
    
    // Save scroll and open states
    const canvas = document.querySelector('.editor-canvas');
    const savedScroll = canvas ? canvas.scrollTop : 0;
    
    content.innerHTML = `
        <div class="quiz-editor">
            ${editorHeaderHtml('🃏', activity.name, section.id)}
            <span class="qs-total-points" style="position:absolute;right:1.5rem;top:1.5rem;">${activity.content.dialogs.length} carte${activity.content.dialogs.length > 1 ? 's' : ''}</span>
            
            <div class="cp-prop-group" style="margin-bottom: 1rem;">
                <div class="cp-quiz-options">
                    <label class="cp-checkbox-label"><input type="checkbox" ${randomCards ? 'checked' : ''} onchange="dcUpdateBehaviour('randomCards', this.checked)"> Mélanger les cartes</label>
                </div>
            </div>
            
            ${cardsHtml}
            
            <div class="quiz-add-question" onclick="dcAddCard()">
                <span>➕</span>
                <span>Ajouter une carte</span>
            </div>
        </div>`;
    
    if (canvas) requestAnimationFrame(() => { canvas.scrollTop = savedScroll; });
}

function dcToggleCard(idx) {
    const body = document.getElementById('dcCard' + idx);
    if (body) body.style.display = body.style.display === 'none' ? 'block' : 'none';
}

function dcExecCmd(idx, side, command) {
    const editorId = side === 'recto' ? 'dcRecto' + idx : 'dcVerso' + idx;
    const editor = document.getElementById(editorId);
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    const prop = side === 'recto' ? 'text' : 'answer';
    dcOnRichInput(idx, prop);
}

function dcOnRichInput(idx, prop) {
    const editorId = prop === 'text' ? 'dcRecto' + idx : 'dcVerso' + idx;
    const editor = document.getElementById(editorId);
    if (!editor) return;
    let html = editor.innerHTML;
    html = html.replace(/<b>(.*?)<\/b>/gi, '<strong>$1</strong>');
    html = html.replace(/<b\s/gi, '<strong ').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i>(.*?)<\/i>/gi, '<em>$1</em>');
    html = html.replace(/<i\s/gi, '<em ').replace(/<\/i>/gi, '</em>');
    const activity = getSelectedActivity();
    if (activity) {
        activity.content.dialogs[idx][prop] = html;
        onCourseModified();
    }
}

function dcUpdateCard(idx, prop, value) {
    const activity = getSelectedActivity();
    activity.content.dialogs[idx][prop] = value;
    onCourseModified();
}

function dcAddCard() {
    const activity = getSelectedActivity();
    activity.content.dialogs.push({ text: '', answer: '', tips: {}, image: null });
    renderDialogCardsEditor(activity);
    const lastIdx = activity.content.dialogs.length - 1;
    const body = document.getElementById('dcCard' + lastIdx);
    if (body) body.style.display = 'block';
    const card = body?.closest('.quiz-question-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    onCourseModified();
}

function dcDeleteCard(idx) {
    if (!confirm('Supprimer cette carte ?')) return;
    const activity = getSelectedActivity();
    activity.content.dialogs.splice(idx, 1);
    renderDialogCardsEditor(activity);
    onCourseModified();
}

function dcMoveCard(idx, direction) {
    const activity = getSelectedActivity();
    const cards = activity.content.dialogs;
    const newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= cards.length) return;
    [cards[idx], cards[newIdx]] = [cards[newIdx], cards[idx]];
    renderDialogCardsEditor(activity);
    onCourseModified();
}

function dcUpdateBehaviour(prop, value) {
    const activity = getSelectedActivity();
    if (!activity.content.behaviour) activity.content.behaviour = {};
    activity.content.behaviour[prop] = value;
    onCourseModified();
}

function dcInsertImage(idx) {
    document.getElementById('dcImageInput' + idx)?.click();
}

function dcHandleImageUpload(idx, input) {
    const file = input.files[0];
    if (!file) return;
    if (typeof canAddImage === 'function' && !canAddImage(file)) { input.value = ''; return; }
    
    showToast('Upload en cours...', 'info');
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    
    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.ok ? r.text() : Promise.reject('Erreur HTTP ' + r.status))
    .then(text => { try { return JSON.parse(text); } catch(e) { throw new Error('Réponse invalide'); } })
    .then(data => {
        if (data.success) {
            const activity = getSelectedActivity();
            const img = new Image();
            img.onload = () => {
                const natW = img.naturalWidth;
                const natH = img.naturalHeight;
                activity.content.dialogs[idx].image = {
                    path: data.url,
                    mime: file.type || 'image/png',
                    copyright: { license: 'U' },
                    width: natW,
                    height: natH
                };
                renderDialogCardsEditor(activity);
                // Rouvrir la carte
                const body = document.getElementById('dcCard' + idx);
                if (body) body.style.display = 'block';
                showToast('Image ajoutée (' + natW + '×' + natH + 'px)', 'success');
                onCourseModified();
            };
            img.src = data.url;
        } else { throw new Error(data.error || 'Erreur'); }
    })
    .catch(err => { showToast('Erreur: ' + err.message, 'error'); });
    input.value = '';
}

function dcRemoveImage(idx) {
    const activity = getSelectedActivity();
    activity.content.dialogs[idx].image = null;
    renderDialogCardsEditor(activity);
    const body = document.getElementById('dcCard' + idx);
    if (body) body.style.display = 'block';
    onCourseModified();
}

function renderGenericEditor(activity, section, message = null) {
    const content = document.getElementById('editorContent');
    const icon = getActivityIcon(activity.h5pType);
    
    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml(icon, activity.name, section.id)}
                <p class="section-preview-desc">Type : ${activity.h5pType}</p>
            </div>
            <div style="padding: 2rem; text-align: center; color: var(--gray-400);">
                <p style="font-size: 3rem; margin-bottom: 1rem;">🚧</p>
                <p>${message || 'L\'éditeur détaillé pour <strong>' + activity.h5pType + '</strong> sera disponible prochainement.'}</p>
                <p style="margin-top: 0.5rem; font-size: 0.85rem;">Vous pouvez modifier les propriétés dans le panneau de droite.</p>
            </div>
        </div>`;
}

function editActivity(sectionId, activityId) {
    selectActivity(sectionId, activityId);
}

