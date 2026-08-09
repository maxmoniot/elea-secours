// ==================== ÉDITEUR ÉVALUATION (ex Question Set) ====================

// Nettoyage défensif des balises HTML dans le texte d'une réponse (héritage des
// imports MBZ : Moodle stocke les réponses en HTML <p>...</p>). On normalise
// au rendu pour que les drafts déjà importés affichent correctement.
function qsStripAnswerTags(s) {
    if (!s) return '';
    return s.replace(/<\/?[a-zA-Z][^>]*>/g, '').trim();
}

// Migration: convertir l'ancien format H5P vers le nouveau format Moodle-compatible
function migrateQuestionSetData(activity) {
    if (!activity.content) activity.content = {};
    if (!activity.content.questions) activity.content.questions = [];

    // Ancien format : l'image vivait dans un champ `questionimage` à part, affiché SOUS
    // l'énoncé. Éléa, lui, l'intègre dans le texte. On la remet donc dans l'énoncé, une
    // fois pour toutes — l'export produisait déjà exactement ce HTML, le .mbz est identique.
    activity.content.questions.forEach(q => {
        if (!q || !q.questionimage || !q.questionimage.path) return;
        const im = q.questionimage;
        const taille = (im.width && im.height) ? ` width="${im.width}" height="${im.height}"` : '';
        const tag = `<img class="img-fluid" role="presentation" src="${im.path}" alt=""${taille}>`;
        const txt = q.questiontext || '';
        q.questiontext = /<\/p>\s*$/.test(txt) ? txt.replace(/<\/p>\s*$/, tag + '</p>') : (txt + tag);
        q.questionimage = null;
    });

    activity.content.questions = activity.content.questions.map(q => {
        if (q.qtype) return q;

        const lib = q.library?.split(' ')[0]?.replace('H5P.', '') || 'MultiChoice';
        const p = q.params || {};

        if (lib === 'MultiChoice') {
            return {
                qtype: 'multichoice',
                name: stripHtml(p.question || 'Question').substring(0, 50),
                questiontext: p.question || '',
                questionimage: null,
                defaultmark: 1,
                single: true,
                shuffleanswers: true,
                answers: (p.answers || []).map(a => ({ text: a.text || '', correct: !!a.correct }))
            };
        } else if (lib === 'TrueFalse') {
            return {
                qtype: 'truefalse',
                name: stripHtml(p.question || 'Vrai/Faux').substring(0, 50),
                questiontext: p.question || '',
                questionimage: null,
                defaultmark: 1,
                correctanswer: p.correct === 'true' || p.correct === true
            };
        }

        return {
            qtype: 'multichoice', name: 'Question', questiontext: p.question || '',
            questionimage: null, defaultmark: 1, single: true, shuffleanswers: true, answers: []
        };
    });

    // Migration des choix gapselect : nettoyer le champ correct obsolète
    // Nettoyage défensif : retirer les balises HTML héritées d'imports MBZ Moodle
    // (Moodle stocke les réponses en HTML <p>...</p>, mais l'éditeur les affiche
    // dans des input text — sans nettoyage, on voit littéralement <p>Réponse</p>).
    //
    // ⚠️ Uniquement pour les types dont les réponses sont VRAIMENT éditées en texte brut,
    // c'est-à-dire la réponse courte. Un QCM édite ses réponses dans une zone riche et
    // elles peuvent contenir une image ; le Vrai/Faux ne se sert même pas de `answers`.
    // Cette fonction tourne à CHAQUE rendu de l'éditeur : sans cette restriction, le
    // nettoyage effaçait toutes les images de réponses à la moindre modification (ajout
    // ou suppression d'une réponse, changement d'image, bascule d'une option…), et un
    // aller-retour de type QCM → Vrai/Faux → QCM les perdait aussi.
    const TYPES_TEXTE_BRUT = { shortanswer: 1, numerical: 1 };
    activity.content.questions.forEach(q => {
        if (q.qtype === 'gapselect' && q.choices) {
            q.choices.forEach(c => { delete c.correct; });
        }
        if (Array.isArray(q.answers) && TYPES_TEXTE_BRUT[q.qtype]) {
            q.answers.forEach(a => {
                if (a && typeof a.text === 'string' && /<[a-zA-Z]/.test(a.text)) {
                    a.text = qsStripAnswerTags(a.text);
                }
            });
        }
        if (Array.isArray(q.choices)) {
            q.choices.forEach(c => {
                if (c && typeof c.text === 'string' && /<[a-zA-Z]/.test(c.text)) {
                    c.text = qsStripAnswerTags(c.text);
                }
            });
        }
    });
}

// ==================== RENDU PRINCIPAL ====================
function renderQuestionSetEditor(activity) {
    const content = document.getElementById('editorContent');
    
    // Sauvegarder scroll et états ouverts avant re-render
    const canvas = document.querySelector('.editor-canvas');
    const savedScroll = canvas ? canvas.scrollTop : 0;
    const openQuestions = [];
    document.querySelectorAll('.quiz-question-body').forEach(el => {
        if (el.style.display !== 'none') {
            const m = el.id.match(/qsQuestion(\d+)/);
            if (m) openQuestions.push(parseInt(m[1]));
        }
    });
    
    migrateQuestionSetData(activity);
    
    let questionsHtml = '';
    activity.content.questions.forEach((q, idx) => {
        const typeLabel = getQTypeLabel(q.qtype);
        const qName = q.name || stripHtml(q.questiontext || '').substring(0, 60) || 'Question ' + (idx + 1);
        const points = q.defaultmark || 1;
        
        questionsHtml += `
            <div class="quiz-question-card" data-qidx="${idx}">
                <div class="quiz-question-header" onclick="qsToggleQuestion(${idx})">
                    <div class="quiz-question-num">${idx + 1}</div>
                    <div class="quiz-question-title" onclick="event.stopPropagation(); qsStartEditTitle(${idx}, this)" title="Cliquer pour renommer">${escapeHtml(qName)}</div>
                    <span class="quiz-question-type">${typeLabel}</span>
                    <span class="qs-points-badge">${points} pt${points > 1 ? 's' : ''}</span>
                    <button class="tree-action-btn" onclick="event.stopPropagation(); qsMoveQuestion(${idx}, -1)" title="Monter">⬆️</button>
                    <button class="tree-action-btn" onclick="event.stopPropagation(); qsMoveQuestion(${idx}, 1)" title="Descendre">⬇️</button>
                    <button class="tree-action-btn" onclick="event.stopPropagation(); qsDeleteQuestion(${idx})" title="Supprimer">🗑️</button>
                </div>
                <div class="quiz-question-body" id="qsQuestion${idx}" style="display: none;">
                    ${renderEvalQuestionEditor(q, idx)}
                </div>
            </div>`;
    });
    
    const totalPoints = activity.content.questions.reduce((sum, q) => sum + (q.defaultmark || 1), 0);
    
    content.innerHTML = `
        <div class="quiz-editor">
            ${editorHeaderHtml('📝', activity.name)}
            <div class="qs-total-sticky"><span class="qs-total-points">${totalPoints} point${totalPoints > 1 ? 's' : ''}</span></div>

            <div class="quiz-questions-list">
                ${questionsHtml || '<p style="color: var(--gray-400); text-align: center; padding: 2rem;">Aucune question. Cliquez ci-dessous pour en ajouter.</p>'}
            </div>
            
            <div class="qs-add-buttons">
                <button class="qs-add-btn" onclick="qsAddQuestion('multichoice')"><span>☑️</span> Choix multiple</button>
                <button class="qs-add-btn" onclick="qsAddQuestion('truefalse')"><span>✅</span> Vrai / Faux</button>
                <button class="qs-add-btn" onclick="qsAddQuestion('shortanswer')"><span>✏️</span> Réponse courte</button>
                <button class="qs-add-btn" onclick="qsAddQuestion('gapselect')"><span>🔽</span> Sélection de mots</button>
                <button class="qs-add-btn" onclick="qsAddDdimageortext()"><span>🎯</span> Glisser Image</button>
            </div>
        </div>`;
    
    // Restaurer les questions ouvertes et le scroll
    openQuestions.forEach(idx => {
        const body = document.getElementById('qsQuestion' + idx);
        if (body) body.style.display = 'block';
    });
    if (canvas) requestAnimationFrame(() => { canvas.scrollTop = savedScroll; });
    
    // Initialiser les handlers paste d'image
    qsInitPasteHandlers();
}

function getQTypeLabel(qtype) {
    return { multichoice: 'Choix multiple', truefalse: 'Vrai/Faux', shortanswer: 'Réponse courte', gapselect: 'Sélection de mots', ddimageortext: '🎯 Glisser Image' }[qtype] || qtype;
}

function stripHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

// ==================== ÉDITEUR PAR TYPE ====================
function renderEvalQuestionEditor(q, idx) {
    let html = '';
    
    // Barre: type + points
    html += `
        <div class="qs-topbar">
            <div class="cp-prop-group" style="flex: 1;">
                <label class="cp-prop-label">Type</label>
                <select class="cp-prop-input" onchange="qsChangeType(${idx}, this.value)">
                    <option value="multichoice" ${q.qtype === 'multichoice' ? 'selected' : ''}>Choix multiple</option>
                    <option value="truefalse" ${q.qtype === 'truefalse' ? 'selected' : ''}>Vrai / Faux</option>
                    <option value="shortanswer" ${q.qtype === 'shortanswer' ? 'selected' : ''}>Réponse courte</option>
                    <option value="gapselect" ${q.qtype === 'gapselect' ? 'selected' : ''}>Sélection de mots</option>
                    <option value="ddimageortext" ${q.qtype === 'ddimageortext' ? 'selected' : ''}>🎯 Glisser Image</option>
                </select>
            </div>
            <div class="cp-prop-group" style="width: 100px;">
                <label class="cp-prop-label">Points</label>
                <input type="number" class="cp-prop-input" value="${q.defaultmark || 1}" min="0.5" max="100" step="0.5"
                       onchange="qsUpdateProp(${idx}, 'defaultmark', parseFloat(this.value))">
            </div>
        </div>`;
    
    // Pour ddimageortext: afficher un aperçu + bouton pour ouvrir l'éditeur DDI
    if (q.qtype === 'ddimageortext') {
        const bgUrl = q.backgroundUrl || null;
        const dragCount = (q.drags || []).length;
        const dropCount = (q.drops || []).length;
        html += `
            <div style="padding: 12px; text-align: center;">
                ${bgUrl ? '<img src="' + escapeHtml(bgUrl) + '" style="max-width: 100%; max-height: 200px; border-radius: 8px; margin-bottom: 12px; border: 1px solid #ddd;" onerror="this.style.display=\'none\'">' : ''}
                <div style="color: #666; margin-bottom: 12px; font-size: 0.85rem;">
                    ${dragCount} étiquette${dragCount > 1 ? 's' : ''} · ${dropCount} zone${dropCount > 1 ? 's' : ''} de dépôt
                </div>
                <button onclick="qsOpenDdiEditor(${idx})" style="padding: 12px 24px; background: #9c27b0; color: white; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer;">
                    🎯 Ouvrir l'éditeur Glisser-Déposer
                </button>
            </div>`;
        return html;
    }
    
    // Question richtext
    html += `
        <div class="cp-prop-group">
            <label class="cp-prop-label">Question</label>
            <div class="qs-richtext-wrap">
                <div class="qs-richtext-toolbar">
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'bold')" title="Gras"><b>G</b></button>
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'italic')" title="Italique"><i>I</i></button>
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'underline')" title="Souligné"><u>S</u></button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'justifyLeft')" title="Aligner à gauche">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
                    </button>
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'justifyCenter')" title="Centrer">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
                    </button>
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'justifyRight')" title="Aligner à droite">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="qsInsertLink(${idx})" title="Lien">🔗</button>
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'insertUnorderedList')" title="Liste">☰</button>
                    <button type="button" class="qs-rt-btn" onclick="qsInsertQuestionImage(${idx})" title="Insérer image">🖼️</button>
                    ${typeof cpEmojiBarHtml === 'function' ? cpEmojiBarHtml('qsRichText' + idx) : ''}
                    <button type="button" class="qs-rt-btn" onclick="qsExecCmd(${idx}, 'removeFormat')" title="Effacer formatage">⊘</button>
                    ${q.qtype === 'gapselect' ? `<span class="qs-rt-sep"></span><button type="button" class="qs-rt-btn qs-rt-btn-gap" onclick="qsAddGapWord(${idx})" title="Ajouter une sélection de mot">+ AJOUTER</button>` : ''}
                </div>
                <div class="qs-richtext-editor" contenteditable="true" id="qsRichText${idx}"
                     oninput="qsOnRichTextInput(${idx})"
                     onblur="qsOnRichTextInput(${idx})">${q.questiontext || ''}</div>
            </div>
            <input type="file" id="qsImageInput${idx}" accept="image/*" style="display:none" onchange="qsHandleImageUpload(${idx}, this)">
        </div>`;
    
    // Image séparée (si présente) avec redimensionnement
    if (q.questionimage) {
        const w = q.questionimage.width || '';
        const h = q.questionimage.height || '';
        const sizeLabel = w && h ? `${w} \u00d7 ${h} px` : 'Chargement...';
        html += `
            <div class="qs-question-image-preview">
                <div class="qs-image-resize-wrapper" id="qsImgWrap${idx}"
                     style="display:inline-block; position:relative; ${w ? 'width:'+w+'px;' : ''} max-width:100%;">
                    <img src="${q.questionimage.path}" alt="Image" id="qsImg${idx}"
                         draggable="false"
                         style="${w ? 'width:'+w+'px;height:'+h+'px;' : ''} display:block; border-radius:6px;"
                         onload="if(!this.dataset.inited){this.dataset.inited='1';qsInitImageSize(${idx},this)}">
                    <div class="qs-resize-handle" onmousedown="qsStartResize(event,${idx})" title="Redimensionner">\u2922</div>
                </div>
                <div class="qs-image-controls">
                    <span class="qs-image-size-label" id="qsSizeLabel${idx}">${sizeLabel}</span>
                    <button class="qs-btn-small" onclick="qsResetImageSize(${idx})">\u21bb Original</button>
                    <button class="qs-remove-image-btn" onclick="qsRemoveQuestionImage(${idx})">\u2715 Retirer</button>
                </div>
            </div>`;
    }
    
    // Éditeur spécifique
    if (q.qtype === 'multichoice') html += renderMultichoiceEditor(q, idx);
    else if (q.qtype === 'truefalse') html += renderTrueFalseEditor(q, idx);
    else if (q.qtype === 'shortanswer') html += renderShortAnswerEditor(q, idx);
    else if (q.qtype === 'gapselect') html += renderGapSelectEditor(q, idx);
    
    return html;
}

// ==================== CHOIX MULTIPLE ====================
function renderMultichoiceEditor(q, idx) {
    const answers = q.answers || [];
    let answersHtml = '';
    answers.forEach((ans, aIdx) => {
        // Le HTML est CONSERVÉ : une réponse peut contenir une image, comme dans Éléa.
        // (Auparavant qsStripAnswerTags vidait ces réponses-là.)
        answersHtml += `
            <div class="quiz-answer-item">
                <input type="${q.single !== false ? 'radio' : 'checkbox'}" name="qsMulti${idx}"
                       class="quiz-answer-correct" ${ans.correct ? 'checked' : ''}
                       onchange="qsUpdateMCAnswer(${idx}, ${aIdx}, this.checked)">
                <div class="quiz-answer-text qs-answer-rich" contenteditable="true"
                     id="qsAnswer${idx}_${aIdx}"
                     data-placeholder="Texte de la réponse"
                     oninput="qsUpdateAnswerHtml(${idx}, ${aIdx}, this.innerHTML)"
                     onblur="qsUpdateAnswerHtml(${idx}, ${aIdx}, this.innerHTML)">${ans.text || ''}</div>
                <button class="quiz-answer-img" onclick="qsInsertAnswerImage(${idx}, ${aIdx})" title="Insérer une image">🖼️</button>
                <button class="quiz-answer-delete" onclick="qsDeleteAnswer(${idx}, ${aIdx})">🗑️</button>
            </div>`;
    });
    
    return `
        <div class="cp-prop-group">
            <label class="cp-prop-label">Réponses (sélectionner = correct)</label>
            <div class="qs-mc-options" style="margin-bottom: 0.5rem;">
                <label style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem; margin-right: 1rem;">
                    <input type="checkbox" ${q.single !== false ? 'checked' : ''}
                           onchange="qsUpdateProp(${idx}, 'single', this.checked)"> Réponse unique
                </label>
                <label style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <input type="checkbox" ${q.shuffleanswers !== false ? 'checked' : ''}
                           onchange="qsUpdateProp(${idx}, 'shuffleanswers', this.checked)"> Mélanger
                </label>
            </div>
            <div class="quiz-answers-list">${answersHtml}</div>
            <button class="quiz-add-answer" onclick="qsAddMCAnswer(${idx})">+ Ajouter une réponse</button>
        </div>`;
}

// ==================== VRAI / FAUX ====================
function renderTrueFalseEditor(q, idx) {
    const correct = q.correctanswer !== false;
    return `
        <div class="cp-prop-group">
            <label class="cp-prop-label">Réponse correcte</label>
            <div class="qs-tf-choices">
                <label class="qs-tf-option ${correct ? 'selected' : ''}">
                    <input type="radio" name="qsTF${idx}" ${correct ? 'checked' : ''} onchange="qsSetTF(${idx}, true)"> Vrai
                </label>
                <label class="qs-tf-option ${!correct ? 'selected' : ''}">
                    <input type="radio" name="qsTF${idx}" ${!correct ? 'checked' : ''} onchange="qsSetTF(${idx}, false)"> Faux
                </label>
            </div>
        </div>`;
}

// ==================== RÉPONSE COURTE ====================
function renderShortAnswerEditor(q, idx) {
    const answers = q.answers || [];
    let answersHtml = '';
    answers.forEach((ans, aIdx) => {
        if (ans.text && /<[a-zA-Z]/.test(ans.text)) ans.text = qsStripAnswerTags(ans.text);
        answersHtml += `
            <div class="quiz-answer-item">
                <input type="text" class="quiz-answer-text" onfocus="this.select()" value="${escapeHtml(ans.text || '')}"
                       onchange="qsUpdateSAAnswer(${idx}, ${aIdx}, 'text', this.value)" placeholder="Réponse acceptée">
                <select class="qs-fraction-select" onchange="qsUpdateSAAnswer(${idx}, ${aIdx}, 'fraction', parseFloat(this.value))">
                    <option value="1" ${(ans.fraction || 1) === 1 ? 'selected' : ''}>100%</option>
                    <option value="0.5" ${ans.fraction === 0.5 ? 'selected' : ''}>50%</option>
                    <option value="0.25" ${ans.fraction === 0.25 ? 'selected' : ''}>25%</option>
                </select>
                <button class="quiz-answer-delete" onclick="qsDeleteSAAnswer(${idx}, ${aIdx})">🗑️</button>
            </div>`;
    });
    
    return `
        <div class="cp-prop-group">
            <label class="cp-prop-label">Réponses acceptées</label>
            <p class="qs-help-text">Ajoutez les différentes réponses acceptées. Chaque réponse peut valoir 100%, 50% ou 25% des points.</p>
            <div style="margin-bottom: 0.5rem;">
                <label style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <input type="checkbox" ${q.usecase ? 'checked' : ''}
                           onchange="qsUpdateProp(${idx}, 'usecase', this.checked)"> Sensible à la casse
                </label>
            </div>
            <div class="quiz-answers-list">${answersHtml}</div>
            <button class="quiz-add-answer" onclick="qsAddSAAnswer(${idx})">+ Ajouter une réponse acceptée</button>
        </div>`;
}

// ==================== SÉLECTION DE MOTS ====================
function renderGapSelectEditor(q, idx) {
    const choices = q.choices || [];
    
    // Nombre max de groupes = nombre de choix (au moins 1)
    const maxGroup = Math.max(1, choices.length, ...choices.map(c => c.group || 1));
    const groupNums = Array.from({length: maxGroup}, (_, i) => i + 1);
    
    // Couleurs par groupe pour lisibilité
    const groupColors = ['#4f46e5', '#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#65a30d'];
    
    // Construire le HTML des choix, numérotés globalement
    let choicesHtml = '';
    choices.forEach((c, ci) => {
        const num = ci + 1;
        const g = c.group || 1;
        const gColor = groupColors[(g - 1) % groupColors.length];
        choicesHtml += `
            <div class="qs-gap-choice-item">
                <span class="qs-gap-choice-num" style="background:${gColor}20; color:${gColor}; border:1px solid ${gColor}40;">[[${num}]]</span>
                <input type="text" class="quiz-answer-text" onfocus="this.select()" value="${escapeHtml(c.text || '')}"
                       onchange="qsUpdateChoice(${idx}, ${ci}, 'text', this.value)" placeholder="Mot/option">
                <select class="qs-gap-group-select" onchange="qsUpdateChoice(${idx}, ${ci}, 'group', parseInt(this.value))" title="Groupe">
                    ${groupNums.map(g2 => `<option value="${g2}" ${g === g2 ? 'selected' : ''}>Grp ${g2}</option>`).join('')}
                </select>
                <button class="quiz-answer-delete" onclick="qsDeleteChoice(${idx}, ${ci})" title="Supprimer">\u{1f5d1}\ufe0f</button>
            </div>`;
    });
    
    // Résumé par groupe
    const groups = [...new Set(choices.map(c => c.group || 1))].sort((a,b) => a-b);
    let groupSummaryHtml = '';
    const previewText = stripHtml(q.questiontext || '');
    groups.forEach(g => {
        const gColor = groupColors[(g - 1) % groupColors.length];
        const gChoices = choices.map((c, ci) => ({...c, num: ci + 1})).filter(c => (c.group || 1) === g);
        const labels = gChoices.map(c => {
            const usedIn = previewText.includes('[[' + c.num + ']]');
            return '<span class="qs-gap-summary-choice' + (usedIn ? ' used' : '') + '" style="border-color:' + gColor + '40; background:' + gColor + '08;">' + escapeHtml(c.text || '?') + ' <code>[[' + c.num + ']]</code></span>';
        }).join(' ');
        groupSummaryHtml += `
            <div class="qs-gap-group-summary">
                <span class="qs-gap-group-badge" style="background:${gColor}; color:white;">Groupe ${g}</span>
                <span class="qs-gap-summary-choices">${labels}</span>
            </div>`;
    });
    
    return `
        <div class="cp-prop-group">
            <label class="cp-prop-label">Comment \u00e7a marche</label>
            <p class="qs-help-text">Chaque choix est num\u00e9rot\u00e9. Dans le texte, <code>[[n]]</code> cr\u00e9e un menu d\u00e9roulant dont la bonne r\u00e9ponse est le choix n\u00b0n. L'\u00e9l\u00e8ve verra toutes les options du m\u00eame <b>groupe</b> dans le menu.</p>
        </div>
        <div class="cp-prop-group">
            <label class="cp-prop-label">Choix</label>
            <div class="qs-gap-choices-list">${choicesHtml}</div>
            <div style="margin-top: 0.5rem;">
                <label style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <input type="checkbox" ${q.shuffleanswers !== false ? 'checked' : ''}
                           onchange="qsUpdateProp(${idx}, 'shuffleanswers', this.checked)"> M\u00e9langer les choix dans les menus
                </label>
            </div>
        </div>
        <div class="cp-prop-group">
            <label class="cp-prop-label">Groupes (r\u00e9sum\u00e9)</label>
            <div class="qs-gap-groups-summary">${groupSummaryHtml}</div>
        </div>`;
}

// ==================== ACTIONS COMMUNES ====================
function qsToggleQuestion(idx) {
    const body = document.getElementById('qsQuestion' + idx);
    if (!body) return;
    const opening = body.style.display === 'none';
    body.style.display = opening ? 'block' : 'none';
    // En repliant : garder la question cliquée en vue (sinon le scroll anchoring peut laisser
    // l'utilisateur bloqué en bas d'un contenu devenu court, avec l'en-tête hors écran).
    if (!opening) {
        const card = body.closest('.quiz-question-card');
        if (card) card.scrollIntoView({ block: 'nearest' });
    }
}

function qsStartEditTitle(idx, el) {
    const activity = getSelectedActivity();
    if (!activity) return;
    const q = activity.content.questions[idx];
    if (!q) return;
    
    const currentName = q.name || stripHtml(q.questiontext || '').substring(0, 60) || 'Question ' + (idx + 1);
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentName;
    input.className = 'qs-inline-title-input';
    input.style.cssText = 'flex:1; font-size:0.85rem; font-weight:600; padding:2px 6px; border:1px solid #1976d2; border-radius:4px; outline:none; background:var(--bg-secondary,white); color:var(--text-primary,inherit); min-width:0;';
    
    el.innerHTML = '';
    el.appendChild(input);
    input.focus();
    input.select();
    
    function commit() {
        const val = input.value.trim();
        if (val) {
            q.name = val;
            // Titre saisi à la main : l'énoncé ne doit plus jamais le réécrire
            q._autoName = false;
            onCourseModified();
        }
        el.textContent = q.name || currentName;
    }
    
    input.addEventListener('blur', commit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { input.value = currentName; input.blur(); }
    });
    input.addEventListener('click', function(e) { e.stopPropagation(); });
}

function qsAddQuestion(qtype) {
    const activity = getSelectedActivity();
    migrateQuestionSetData(activity);
    
    // _autoName : le titre affiché n'est qu'un libellé par défaut, il suivra l'énoncé
    // jusqu'à ce que le professeur le renomme lui-même (voir qsAutoName).
    const defaults = {
        multichoice: { qtype:'multichoice', name:'Nouvelle question', _autoName:true, questiontext:'', questionimage:null, defaultmark:1, single:true, shuffleanswers:true, answers:[{text:'Réponse A',correct:true},{text:'Réponse B',correct:false}] },
        truefalse: { qtype:'truefalse', name:'Vrai ou Faux', _autoName:true, questiontext:'', questionimage:null, defaultmark:1, correctanswer:true },
        shortanswer: { qtype:'shortanswer', name:'Réponse courte', _autoName:true, questiontext:'', questionimage:null, defaultmark:1, usecase:false, answers:[{text:'',fraction:1.0}] },
        gapselect: { qtype:'gapselect', name:'Sélection de mots', _autoName:true, questiontext:'', questionimage:null, defaultmark:1, shuffleanswers:true, choices:[] }
    };
    
    activity.content.questions.push(defaults[qtype] || defaults.multichoice);
    renderQuestionSetEditor(activity);
    const lastIdx = activity.content.questions.length - 1;
    // Ouvrir la nouvelle question
    const body = document.getElementById('qsQuestion' + lastIdx);
    if (body) body.style.display = 'block';
    // Scroller vers la nouvelle question
    const card = body?.closest('.quiz-question-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    onCourseModified();
}

function qsDeleteQuestion(idx) {
    if (!confirm('Supprimer cette question ?')) return;
    const activity = getSelectedActivity();
    activity.content.questions.splice(idx, 1);
    renderQuestionSetEditor(activity);
    onCourseModified();
}

function qsMoveQuestion(idx, direction) {
    const activity = getSelectedActivity();
    const qs = activity.content.questions;
    const newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= qs.length) return;
    
    // Deep clone pour éviter toute référence croisée entre questions
    var a = JSON.parse(JSON.stringify(qs[idx]));
    var b = JSON.parse(JSON.stringify(qs[newIdx]));
    qs[idx] = b;
    qs[newIdx] = a;
    
    renderQuestionSetEditor(activity);
    onCourseModified();
}

function qsChangeType(idx, newType) {
    const activity = getSelectedActivity();
    const q = activity.content.questions[idx];
    const oldType = q.qtype;
    q.qtype = newType;
    
    if (newType === 'multichoice' && !q.answers) q.answers = [{text:'Réponse A',correct:true},{text:'Réponse B',correct:false}];
    if (newType === 'multichoice' && q.single === undefined) q.single = true;
    if (newType === 'truefalse' && q.correctanswer === undefined) q.correctanswer = true;
    if (newType === 'shortanswer' && (!q.answers || oldType !== 'shortanswer')) q.answers = [{text:'',fraction:1.0}];
    if (newType === 'gapselect' && !q.choices) q.choices = [{text:'bonne réponse',group:1,correct:true},{text:'mauvaise réponse',group:1,correct:false}];
    
    renderQuestionSetEditor(activity);
    onCourseModified();
}

function qsUpdateProp(idx, prop, value) {
    const activity = getSelectedActivity();
    activity.content.questions[idx][prop] = value;
    if (prop === 'defaultmark' || prop === 'single') {
        renderQuestionSetEditor(activity);
    }
    onCourseModified();
}

// ==================== RICH TEXT ====================
function qsExecCmd(idx, command) {
    const editor = document.getElementById('qsRichText' + idx);
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    qsOnRichTextInput(idx);
}

function qsInsertLink(idx) {
    const editor = document.getElementById('qsRichText' + idx);
    if (!editor) return;
    editor.focus();
    
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
        var sel2 = window.getSelection();
        if (sel2 && sel2.focusNode) {
            var aEl = sel2.focusNode.nodeType === 3 ? sel2.focusNode.parentElement : sel2.focusNode;
            while (aEl && aEl.tagName !== 'A') aEl = aEl.parentElement;
            if (aEl && aEl.tagName === 'A') aEl.target = '_blank';
        }
    }
    qsOnRichTextInput(idx);
}

function qsOnRichTextInput(idx) {
    const editor = document.getElementById('qsRichText' + idx);
    if (!editor) return;
    const activity = getSelectedActivity();
    if (!activity) return;
    const q = activity.content.questions[idx];
    if (!q) return;
    q.questiontext = editor.innerHTML;
    qsAutoName(q, idx, editor.innerHTML);
    onCourseModified();
}

/**
 * Renseigne le titre de la question depuis son énoncé, mais UNIQUEMENT tant qu'il est
 * automatique : un titre saisi par le professeur (ou repris d'un .mbz importé) ne doit
 * jamais être écrasé. Ce handler est aussi appelé au `blur` de l'énoncé, donc un simple
 * clic dans le champ suffisait auparavant à remplacer le titre.
 */
function qsAutoName(q, idx, html) {
    if (!q._autoName && (q.name || '').trim() !== '') return;
    const nouveau = stripHtml(html).trim().substring(0, 50);
    q.name = nouveau || 'Question ' + (idx + 1);
    // Le titre reste automatique tant qu'il n'a pas été saisi à la main
    q._autoName = true;
    // Rafraîchir l'en-tête de la carte sans reconstruire tout l'éditeur
    const titre = document.querySelector('.quiz-question-card[data-qidx="' + idx + '"] .quiz-question-title');
    // Ne pas écraser le champ de saisie si le titre est justement en cours de renommage
    if (titre && !titre.querySelector('input')) titre.textContent = q.name;
}

// Handler paste d'image dans une zone éditable (énoncé ou réponse)
function qsHandleRichTextPaste(cible, event) {
    var items = (event.clipboardData || event.originalEvent.clipboardData).items;
    for (var i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
            event.preventDefault();
            var file = items[i].getAsFile();
            if (file) qsUploadImageFile(cible.idx, file, cible);
            return;
        }
    }
}

// Initialiser les handlers paste sur les zones éditables (énoncés ET réponses)
function qsInitPasteHandlers() {
    document.querySelectorAll('.qs-richtext-editor').forEach(function(editor) {
        if (editor.dataset.pasteInited) return;
        editor.dataset.pasteInited = '1';
        var idx = parseInt(editor.id.replace('qsRichText', ''));
        if (!isNaN(idx)) {
            editor.addEventListener('paste', function(e) { qsHandleRichTextPaste({ type: 'question', idx: idx }, e); });
        }
    });
    document.querySelectorAll('.qs-answer-rich').forEach(function(zone) {
        if (zone.dataset.pasteInited) return;
        zone.dataset.pasteInited = '1';
        var m = /^qsAnswer(\d+)_(\d+)$/.exec(zone.id || '');
        if (!m) return;
        var cible = { type: 'answer', idx: parseInt(m[1]), aIdx: parseInt(m[2]) };
        zone.addEventListener('paste', function(e) { qsHandleRichTextPaste(cible, e); });
    });
}

// ==================== IMAGES ====================
// L'image est insérée DANS le texte, à l'endroit du curseur, comme le fait Éléa
// (<img> dans le questiontext / l'answertext). Cible courante de l'insertion :
// { type: 'question', idx } ou { type: 'answer', idx, aIdx }.
let _qsCibleImage = null;

function qsZoneCible(cible) {
    if (!cible) return null;
    return document.getElementById(cible.type === 'answer'
        ? 'qsAnswer' + cible.idx + '_' + cible.aIdx
        : 'qsRichText' + cible.idx);
}

function qsInsertQuestionImage(idx) {
    _qsCibleImage = { type: 'question', idx: idx };
    document.getElementById('qsImageInput' + idx)?.click();
}

function qsInsertAnswerImage(idx, aIdx) {
    _qsCibleImage = { type: 'answer', idx: idx, aIdx: aIdx };
    document.getElementById('qsImageInput' + idx)?.click();
}

function qsHandleImageUpload(idx, input) {
    const file = input.files[0];
    if (!file) return;
    if (typeof canAddImage === 'function' && !canAddImage(file)) { input.value = ''; return; }

    qsUploadImageFile(idx, file, _qsCibleImage || { type: 'question', idx: idx });
    input.value = '';
}

// Insère du HTML à la position du curseur dans une zone éditable (ou à la fin si le
// curseur n'y est pas), puis répercute le contenu dans le modèle.
function qsInsererDansZone(zone, html) {
    if (!zone) return;
    zone.focus();
    const sel = window.getSelection();
    let insere = false;
    if (sel && sel.rangeCount) {
        const range = sel.getRangeAt(0);
        if (zone.contains(range.commonAncestorContainer)) {
            range.deleteContents();
            const frag = range.createContextualFragment(html);
            const dernier = frag.lastChild;
            range.insertNode(frag);
            if (dernier) {
                const apres = document.createRange();
                apres.setStartAfter(dernier);
                apres.collapse(true);
                sel.removeAllRanges();
                sel.addRange(apres);
            }
            insere = true;
        }
    }
    if (!insere) zone.insertAdjacentHTML('beforeend', html);
}

// Upload générique d'image pour une question ou une réponse (via bouton ou collage)
function qsUploadImageFile(idx, file, cible) {
    cible = cible || { type: 'question', idx: idx };
    showToast('Upload en cours...', 'info');
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('file', file);
    formData.append('session_id', typeof getEditorSessionId === 'function' ? getEditorSessionId() : '');

    fetch('api/editor_api.php', { method: 'POST', body: formData })
    .then(r => r.ok ? r.text() : Promise.reject('Erreur HTTP ' + r.status))
    .then(text => { try { return JSON.parse(text); } catch(e) { throw new Error('Réponse invalide'); } })
    .then(data => {
        if (!data.success) throw new Error(data.error || 'Erreur');
        const img = new Image();
        img.onload = () => {
            const natW = img.naturalWidth, natH = img.naturalHeight;
            // Une image de réponse reste petite (elle vit dans une ligne de choix)
            const hMax = cible.type === 'answer' ? 90 : 300;
            const h = Math.min(hMax, natH);
            const w = Math.round(natW * (h / natH));
            const tag = `<img class="img-fluid" role="presentation" src="${data.url}" alt=""`
                      + ` width="${w}" height="${h}">`;
            const zone = qsZoneCible(cible);
            qsInsererDansZone(zone, tag);
            if (cible.type === 'answer') qsUpdateAnswerHtml(cible.idx, cible.aIdx, zone ? zone.innerHTML : tag);
            else qsOnRichTextInput(cible.idx);
            showToast('Image insérée (' + natW + '×' + natH + 'px)', 'success');
        };
        img.onerror = () => showToast('Image illisible', 'error');
        img.src = data.url;
    })
    .catch(err => { console.error('Erreur upload:', err); showToast('Erreur: ' + err.message, 'error'); });
}

function qsRemoveQuestionImage(idx) {
    const activity = getSelectedActivity();
    activity.content.questions[idx].questionimage = null;
    renderQuestionSetEditor(activity);
    onCourseModified();
}

// ==================== IMAGE RESIZE ====================
function qsInitImageSize(idx, imgEl) {
    const activity = getSelectedActivity();
    if (!activity) return;
    const q = activity.content.questions[idx];
    if (!q || !q.questionimage) return;
    // Store original natural size if not already set
    if (!q.questionimage.originalWidth) {
        q.questionimage.originalWidth = imgEl.naturalWidth;
        q.questionimage.originalHeight = imgEl.naturalHeight;
    }
    // If no custom size yet, use natural size
    if (!q.questionimage.width) {
        q.questionimage.width = imgEl.naturalWidth;
        q.questionimage.height = imgEl.naturalHeight;
        qsUpdateSizeLabel(idx);
    }
}

function qsUpdateSizeLabel(idx) {
    const label = document.getElementById('qsSizeLabel' + idx);
    const activity = getSelectedActivity();
    if (!label || !activity) return;
    const q = activity.content.questions[idx];
    if (q && q.questionimage && q.questionimage.width) {
        label.textContent = q.questionimage.width + ' \u00d7 ' + q.questionimage.height + ' px';
    }
}

function qsStartResize(e, idx) {
    e.preventDefault();
    e.stopPropagation();
    const img = document.getElementById('qsImg' + idx);
    const wrap = document.getElementById('qsImgWrap' + idx);
    if (!img || !wrap) return;
    
    const activity = getSelectedActivity();
    const q = activity.content.questions[idx];
    if (!q || !q.questionimage) return;
    
    const startX = e.clientX;
    const startY = e.clientY;
    const startW = img.offsetWidth;
    const startH = img.offsetHeight;
    const ratio = startW / startH;
    
    wrap.classList.add('qs-resizing');
    
    function onMove(ev) {
        const dx = ev.clientX - startX;
        const newW = Math.max(32, Math.round(startW + dx));
        const newH = Math.round(newW / ratio);
        img.style.width = newW + 'px';
        img.style.height = newH + 'px';
        wrap.style.width = newW + 'px';
        q.questionimage.width = newW;
        q.questionimage.height = newH;
        qsUpdateSizeLabel(idx);
    }
    
    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        wrap.classList.remove('qs-resizing');
        onCourseModified();
    }
    
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
}

function qsResetImageSize(idx) {
    const activity = getSelectedActivity();
    if (!activity) return;
    const q = activity.content.questions[idx];
    if (!q || !q.questionimage) return;
    q.questionimage.width = q.questionimage.originalWidth;
    q.questionimage.height = q.questionimage.originalHeight;
    renderQuestionSetEditor(activity);
    onCourseModified();
}

// ==================== MULTICHOICE ACTIONS ====================
function qsUpdateMCAnswer(idx, aIdx, checked) {
    const activity = getSelectedActivity();
    const q = activity.content.questions[idx];
    if (q.single !== false) { q.answers.forEach((a, i) => a.correct = (i === aIdx)); }
    else { q.answers[aIdx].correct = checked; }
    onCourseModified();
}

function qsUpdateAnswerText(idx, aIdx, value) {
    getSelectedActivity().content.questions[idx].answers[aIdx].text = value;
    onCourseModified();
}

// Réponse de QCM : le contenu est du HTML (il peut contenir une image).
// Une réponse réduite à une image ne doit pas être considérée comme vide.
function qsUpdateAnswerHtml(idx, aIdx, html) {
    const activity = getSelectedActivity();
    const rep = activity && activity.content.questions[idx] && activity.content.questions[idx].answers[aIdx];
    if (!rep) return;
    const vide = !html || (!/<img/i.test(html) && stripHtml(html).trim() === '');
    rep.text = vide ? '' : html;
    onCourseModified();
}

function qsAddMCAnswer(idx) {
    getSelectedActivity().content.questions[idx].answers.push({ text: '', correct: false });
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

function qsDeleteAnswer(idx, aIdx) {
    getSelectedActivity().content.questions[idx].answers.splice(aIdx, 1);
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

// ==================== VRAI/FAUX ACTIONS ====================
function qsSetTF(idx, value) {
    getSelectedActivity().content.questions[idx].correctanswer = value;
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

// ==================== RÉPONSE COURTE ACTIONS ====================
function qsUpdateSAAnswer(idx, aIdx, prop, value) {
    getSelectedActivity().content.questions[idx].answers[aIdx][prop] = value;
    onCourseModified();
}

function qsAddSAAnswer(idx) {
    getSelectedActivity().content.questions[idx].answers.push({ text: '', fraction: 1.0 });
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

function qsDeleteSAAnswer(idx, aIdx) {
    getSelectedActivity().content.questions[idx].answers.splice(aIdx, 1);
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

// ==================== SÉLECTION DE MOTS ACTIONS ====================
function qsUpdateChoice(idx, cIdx, prop, value) {
    getSelectedActivity().content.questions[idx].choices[cIdx][prop] = value;
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

function qsAddChoice(idx, group) {
    const choices = getSelectedActivity().content.questions[idx].choices;
    if (group === undefined) group = 1;
    choices.push({ text: '', group: group });
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

function qsAddGapWord(idx) {
    const q = getSelectedActivity().content.questions[idx];
    if (!q.choices) q.choices = [];
    const newNum = q.choices.length + 1;
    q.choices.push({ text: '', group: 1 });
    
    var tag = '[[' + newNum + ']]';
    
    // Insérer [[n]] au point du curseur dans l'éditeur rich text
    var editor = document.getElementById('qsRichText' + idx);
    if (editor) {
        var sel = window.getSelection();
        if (sel.rangeCount > 0 && editor.contains(sel.anchorNode)) {
            var range = sel.getRangeAt(0);
            range.deleteContents();
            var textNode = document.createTextNode(tag + ' ');
            range.insertNode(textNode);
            // Placer le curseur après le texte inséré
            range.setStartAfter(textNode);
            range.setEndAfter(textNode);
            sel.removeAllRanges();
            sel.addRange(range);
        } else {
            editor.focus();
            document.execCommand('insertText', false, ' ' + tag + ' ');
        }
        q.questiontext = editor.innerHTML;
    } else {
        if (q.questiontext && q.questiontext.includes('</p>')) {
            q.questiontext = q.questiontext.replace(/<\/p>\s*$/, ' ' + tag + '</p>');
        } else {
            q.questiontext = (q.questiontext || '') + ' ' + tag;
        }
    }
    
    // Sauvegarder la position du curseur (offset texte dans le innerHTML)
    var cursorTag = '\u200B\u200B';  // Double zero-width space comme marqueur
    if (editor) {
        var sel2 = window.getSelection();
        if (sel2.rangeCount > 0) {
            var r = sel2.getRangeAt(0);
            r.insertNode(document.createTextNode(cursorTag));
            q.questiontext = editor.innerHTML;
        }
    }
    
    // Re-render (met à jour la liste des choix)
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
    
    // Restaurer le curseur au marqueur
    var newEditor = document.getElementById('qsRichText' + idx);
    if (newEditor) {
        _qsRestoreCursorAtMarker(newEditor, cursorTag);
    }
}

function _qsRestoreCursorAtMarker(editor, marker) {
    var walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, null, false);
    var node;
    while (node = walker.nextNode()) {
        var pos = node.textContent.indexOf(marker);
        if (pos !== -1) {
            // Supprimer le marqueur
            node.textContent = node.textContent.replace(marker, '');
            // Placer le curseur
            var range = document.createRange();
            range.setStart(node, pos);
            range.collapse(true);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            editor.focus();
            return;
        }
    }
}

function qsAddGapGroup(idx) {
    const choices = getSelectedActivity().content.questions[idx].choices;
    const maxGroup = choices.length > 0 ? Math.max(...choices.map(c => c.group || 1)) : 0;
    const newGroup = maxGroup + 1;
    choices.push({ text: '', group: newGroup });
    choices.push({ text: '', group: newGroup });
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

function qsSetGapCorrect(idx, cIdx, group) {
    const choices = getSelectedActivity().content.questions[idx].choices;
    // Une seule bonne réponse par groupe
    choices.forEach((c, i) => {
        if ((c.group || 1) === group) c.correct = (i === cIdx);
    });
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

function qsDeleteChoice(idx, cIdx) {
    getSelectedActivity().content.questions[idx].choices.splice(cIdx, 1);
    renderQuestionSetEditor(getSelectedActivity());
    onCourseModified();
}

// ==================== ÉDITEURS SIMPLES (inchangés) ====================
function renderSimpleQuizEditor(activity) {
    const content = document.getElementById('editorContent');
    const type = activity.h5pType;
    if (!activity.content) activity.content = {};
    
    let editorHtml = `
        <div class="quiz-editor">
            ${editorHeaderHtml(getActivityIcon(type), activity.name)}
            <div class="quiz-question-card">
                <div class="quiz-question-body" style="display: block;">`;
    
    if (type === 'MultiChoice') {
        const questionHtml = activity.content.question || '';
        const answers = activity.content.answers || [];
        const mcBeh = activity.content.behaviour || {};
        const mcEnableRetry = mcBeh.enableRetry !== false;
        const mcEnableSolutions = mcBeh.enableSolutionsButton === true;
        let answersHtml = '';
        answers.forEach((ans, idx) => {
            // Nettoyer les balises HTML héritées d'un import MBZ
            if (ans.text && /<[a-zA-Z]/.test(ans.text)) ans.text = qsStripAnswerTags(ans.text);
            answersHtml += `<div class="quiz-answer-item">
                <input type="checkbox" class="quiz-answer-correct" ${ans.correct ? 'checked' : ''} onchange="simpleUpdateAnswer(${idx}, 'correct', this.checked)">
                <input type="text" class="quiz-answer-text" onfocus="this.select()" value="${escapeHtml(ans.text || '')}" onchange="simpleUpdateAnswer(${idx}, 'text', this.value)">
                <button class="quiz-answer-delete" onclick="simpleDeleteAnswer(${idx})">🗑️</button>
            </div>`;
        });
        editorHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Question</label>
            <div class="cp-blanks-richtext-wrap">
                <div class="cp-blanks-richtext-toolbar">
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('bold')" title="Gras"><b>G</b></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('italic')" title="Italique"><i>I</i></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('underline')" title="Souligné"><u>S</u></button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('justifyLeft')" title="Gauche">⬅</button>
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('justifyCenter')" title="Centrer">⬌</button>
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('justifyRight')" title="Droite">➡</button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleMcExecCmd('removeFormat')" title="Effacer formatage">⊘</button>
                </div>
                <div class="cp-blanks-richtext-editor" contenteditable="true" id="simpleMcEditor"
                     oninput="simpleOnMcInput()"
                     onblur="simpleOnMcInput()">${questionHtml}</div>
            </div></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Réponses (cocher = correct)</label>
            <div class="quiz-answers-list">${answersHtml}</div>
            <button class="quiz-add-answer" onclick="simpleAddAnswer()">+ Ajouter une réponse</button></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Options</label>
            <div class="cp-quiz-options">
                <label class="cp-checkbox-label"><input type="checkbox" ${mcEnableRetry ? 'checked' : ''} onchange="simpleUpdateMcBehaviour('enableRetry', this.checked)"> Bouton recommencer</label>
                <label class="cp-checkbox-label"><input type="checkbox" ${mcEnableSolutions ? 'checked' : ''} onchange="simpleUpdateMcBehaviour('enableSolutionsButton', this.checked)"> Bouton afficher la solution</label>
            </div></div>`;
    } else if (type === 'TrueFalse') {
        const questionHtml = activity.content.question || '';
        const correct = activity.content.correct === 'true' || activity.content.correct === true;
        const tfBeh = activity.content.behaviour || {};
        const tfEnableRetry = tfBeh.enableRetry !== false;
        const tfEnableSolutions = tfBeh.enableSolutionsButton === true;
        editorHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Affirmation</label>
            <div class="cp-blanks-richtext-wrap">
                <div class="cp-blanks-richtext-toolbar">
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('bold')" title="Gras"><b>G</b></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('italic')" title="Italique"><i>I</i></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('underline')" title="Souligné"><u>S</u></button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('justifyLeft')" title="Gauche">⬅</button>
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('justifyCenter')" title="Centrer">⬌</button>
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('justifyRight')" title="Droite">➡</button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleTfExecCmd('removeFormat')" title="Effacer formatage">⊘</button>
                </div>
                <div class="cp-blanks-richtext-editor" contenteditable="true" id="simpleTfEditor"
                     oninput="simpleOnTfInput()"
                     onblur="simpleOnTfInput()">${questionHtml}</div>
            </div></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Réponse correcte</label>
            <select class="cp-prop-input" onchange="simpleUpdateProp('correct', this.value)">
                <option value="true" ${correct ? 'selected' : ''}>Vrai</option>
                <option value="false" ${!correct ? 'selected' : ''}>Faux</option>
            </select></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Options</label>
            <div class="cp-quiz-options">
                <label class="cp-checkbox-label"><input type="checkbox" ${tfEnableRetry ? 'checked' : ''} onchange="simpleUpdateTfBehaviour('enableRetry', this.checked)"> Bouton recommencer</label>
                <label class="cp-checkbox-label"><input type="checkbox" ${tfEnableSolutions ? 'checked' : ''} onchange="simpleUpdateTfBehaviour('enableSolutionsButton', this.checked)"> Bouton afficher la solution</label>
            </div></div>`;
    } else if (type === 'Blanks') {
        const questions = activity.content.questions || [];
        const questionsHtml = questions.map(q => q || '').join('<hr class="cp-blanks-sep">');
        const beh = activity.content.behaviour || {};
        const blEnableRetry = beh.enableRetry !== false;
        const blEnableSolutions = beh.enableSolutionsButton === true;
        const blCaseSensitive = beh.caseSensitive === true;
        const blRequiresInput = beh.showSolutionsRequiresInput === true;
        editorHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Texte à trous</label>
            <p style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">Entourez les mots à trouver avec des astérisques: *mot*<br>Utilisez <code>&lt;hr&gt;</code> ou la barre d'outils pour séparer les questions.</p>
            <div class="cp-blanks-richtext-wrap">
                <div class="cp-blanks-richtext-toolbar">
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('bold')" title="Gras"><b>G</b></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('italic')" title="Italique"><i>I</i></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('underline')" title="Souligné"><u>S</u></button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('justifyLeft')" title="Gauche">⬅</button>
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('justifyCenter')" title="Centrer">⬌</button>
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('justifyRight')" title="Droite">➡</button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleBlanksExecCmd('removeFormat')" title="Effacer formatage">⊘</button>
                </div>
                <div class="cp-blanks-richtext-editor" contenteditable="true" id="simpleBlanksEditor"
                     oninput="simpleOnBlanksInput()"
                     onblur="simpleOnBlanksInput()">${questionsHtml}</div>
            </div></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Options</label>
            <div class="cp-quiz-options">
                <label class="cp-checkbox-label"><input type="checkbox" ${blEnableRetry ? 'checked' : ''} onchange="simpleUpdateBlanksBehaviour('enableRetry', this.checked)"> Bouton recommencer</label>
                <label class="cp-checkbox-label"><input type="checkbox" ${blEnableSolutions ? 'checked' : ''} onchange="simpleUpdateBlanksBehaviour('enableSolutionsButton', this.checked)"> Bouton afficher la solution</label>
                <label class="cp-checkbox-label"><input type="checkbox" ${blCaseSensitive ? 'checked' : ''} onchange="simpleUpdateBlanksBehaviour('caseSensitive', this.checked)"> Sensible à la casse</label>
                <label class="cp-checkbox-label"><input type="checkbox" ${blRequiresInput ? 'checked' : ''} onchange="simpleUpdateBlanksBehaviour('showSolutionsRequiresInput', this.checked)"> Obliger à remplir tous les blancs avant correction</label>
            </div></div>`;
    } else if (type === 'DragText') {
        const textField = activity.content.textField || '';
        const distractors = activity.content.distractors || '';
        editorHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Texte avec mots à glisser</label>
            <p style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">Entourez les mots à déplacer avec des astérisques: *mot*</p>
            <textarea class="cp-prop-input cp-prop-textarea" rows="6" onchange="simpleUpdateProp('textField', this.value)">${escapeHtml(textField)}</textarea></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Distracteurs (optionnel)</label>
            <p style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">Mots supplémentaires: *mot1* *mot2*</p>
            <textarea class="cp-prop-input cp-prop-textarea" rows="2" onchange="simpleUpdateProp('distractors', this.value)">${escapeHtml(distractors)}</textarea></div>`;
    } else if (type === 'FindTheWords') {
        const wordList = activity.content.wordList || '';
        const taskDesc = activity.content.taskDescription || 'Retrouvez les mots dans la grille';
        editorHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Description</label>
            <input type="text" class="cp-prop-input" value="${escapeHtml(taskDesc)}" onchange="simpleUpdateProp('taskDescription', this.value)"></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Liste de mots (séparés par des virgules)</label>
            <textarea class="cp-prop-input cp-prop-textarea" rows="4" onchange="simpleUpdateProp('wordList', this.value)">${escapeHtml(wordList)}</textarea></div>`;
    } else if (type === 'MultiMediaChoice') {
        const questionHtml = activity.content.question || '';
        const options = activity.content.options || [];
        const mmcBeh = activity.content.behaviour || {};
        const mmcMaxPerRow = mmcBeh.maxAlternativesPerRow || 4;
        const mmcEnableRetry = mmcBeh.enableRetry !== false;
        const mmcEnableSolutions = mmcBeh.enableSolutionsButton === true;
        
        let optionsHtml = '';
        options.forEach((opt, idx) => {
            const hasImage = opt.media && opt.media.params && opt.media.params.file && opt.media.params.file.path;
            const imgSrc = hasImage ? (opt.media.params.file._dataUrl || opt.media.params.file.path) : '';
            const imgPreview = hasImage 
                ? `<img src="${imgSrc}" style="width:60px;height:60px;object-fit:cover;border-radius:6px;" onerror="this.style.display='none';this.nextElementSibling.style.display=''">`
                  + `<span style="font-size:1.5rem;display:${imgSrc ? 'none' : ''}">🖼️</span>`
                : '<span style="font-size:1.5rem;">🖼️</span>';
            optionsHtml += `<div class="quiz-answer-item" style="align-items:center;">
                <input type="checkbox" class="quiz-answer-correct" ${opt.correct ? 'checked' : ''} onchange="mmcUpdateOption(${idx}, 'correct', this.checked)">
                <div style="width:60px;height:60px;border:2px dashed #ccc;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;cursor:pointer;" onclick="mmcPickImage(${idx})" title="Cliquer pour choisir une image">
                    ${imgPreview}
                </div>
                <label class="btn" style="cursor:pointer;background:var(--primary);color:white;padding:0.3rem 0.6rem;border-radius:6px;font-size:0.75rem;flex-shrink:0;">
                    📷 Image
                    <input type="file" accept="image/*" style="display:none;" onchange="mmcUploadImage(this, ${idx})">
                </label>
                <button class="quiz-answer-delete" onclick="mmcDeleteOption(${idx})">🗑️</button>
            </div>`;
        });
        editorHtml += `<div class="cp-prop-group"><label class="cp-prop-label">Question</label>
            <div class="cp-blanks-richtext-wrap">
                <div class="cp-blanks-richtext-toolbar">
                    <button type="button" class="qs-rt-btn" onclick="simpleMmcExecCmd('bold')" title="Gras"><b>G</b></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleMmcExecCmd('italic')" title="Italique"><i>I</i></button>
                    <button type="button" class="qs-rt-btn" onclick="simpleMmcExecCmd('underline')" title="Souligné"><u>S</u></button>
                    <span class="qs-rt-sep"></span>
                    <button type="button" class="qs-rt-btn" onclick="simpleMmcExecCmd('removeFormat')" title="Effacer formatage">⊘</button>
                </div>
                <div class="cp-blanks-richtext-editor" contenteditable="true" id="simpleMmcEditor"
                     oninput="simpleOnMmcInput()"
                     onblur="simpleOnMmcInput()">${questionHtml}</div>
            </div></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Options (cocher = correct, cliquer 📷 pour ajouter une image)</label>
            <div class="quiz-answers-list">${optionsHtml}</div>
            <button class="quiz-add-answer" onclick="mmcAddOption()">+ Ajouter une option</button></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Images par ligne</label>
            <select class="cp-prop-input" style="width:auto;" onchange="mmcUpdateBehaviour('maxAlternativesPerRow', parseInt(this.value))">
                <option value="2" ${mmcMaxPerRow == 2 ? 'selected' : ''}>2</option>
                <option value="3" ${mmcMaxPerRow == 3 ? 'selected' : ''}>3</option>
                <option value="4" ${mmcMaxPerRow == 4 ? 'selected' : ''}>4</option>
                <option value="5" ${mmcMaxPerRow == 5 ? 'selected' : ''}>5</option>
                <option value="6" ${mmcMaxPerRow == 6 ? 'selected' : ''}>6</option>
            </select></div>
            <div class="cp-prop-group"><label class="cp-prop-label">Options</label>
            <div class="cp-quiz-options">
                <label class="cp-checkbox-label"><input type="checkbox" ${mmcEnableRetry ? 'checked' : ''} onchange="mmcUpdateBehaviour('enableRetry', this.checked)"> Bouton recommencer</label>
                <label class="cp-checkbox-label"><input type="checkbox" ${mmcEnableSolutions ? 'checked' : ''} onchange="mmcUpdateBehaviour('enableSolutionsButton', this.checked)"> Bouton afficher la solution</label>
            </div></div>`;
    }
    
    editorHtml += `</div></div></div>`;
    content.innerHTML = editorHtml;
}

function simpleUpdateProp(prop, value) { getSelectedActivity().content[prop] = value; onCourseModified(); }
function simpleUpdateAnswer(idx, prop, value) { getSelectedActivity().content.answers[idx][prop] = value; onCourseModified(); }
function simpleAddAnswer() {
    const a = getSelectedActivity(); if (!a.content.answers) a.content.answers = [];
    a.content.answers.push({ text: 'Nouvelle réponse', correct: false }); renderSimpleQuizEditor(a); onCourseModified();
}
function simpleDeleteAnswer(idx) { getSelectedActivity().content.answers.splice(idx, 1); renderSimpleQuizEditor(getSelectedActivity()); onCourseModified(); }
function simpleUpdateBlanks(text) { const a = getSelectedActivity(); a.content.text = text; a.content.questions = ['<p>' + text + '</p>']; onCourseModified(); }

function simpleBlanksExecCmd(command) {
    const editor = document.getElementById('simpleBlanksEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    simpleOnBlanksInput();
}

function simpleOnBlanksInput() {
    const editor = document.getElementById('simpleBlanksEditor');
    if (!editor) return;
    const a = getSelectedActivity();
    if (!a) return;
    
    let html = editor.innerHTML;
    // Convertir les balises du navigateur vers le format H5P/Éléa
    html = html.replace(/<b>(.*?)<\/b>/gi, '<strong>$1</strong>');
    html = html.replace(/<b\s/gi, '<strong ').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i>(.*?)<\/i>/gi, '<em>$1</em>');
    html = html.replace(/<i\s/gi, '<em ').replace(/<\/i>/gi, '</em>');
    
    const parts = html.split(/<hr[^>]*>/gi).map(p => {
        let cleaned = p.trim();
        if (!cleaned) return '';
        if (!cleaned.startsWith('<p') && !cleaned.startsWith('<div')) {
            cleaned = '<p>' + cleaned + '</p>';
        }
        return cleaned;
    }).filter(p => p);
    
    a.content.questions = parts.length > 0 ? parts : ['<p>Complétez le mot *manquant*.</p>'];
    a.content.text = (parts[0] || '').replace(/<[^>]*>/g, '');
    onCourseModified();
}

function simpleUpdateBlanksBehaviour(prop, value) {
    const a = getSelectedActivity();
    if (!a.content.behaviour) a.content.behaviour = {};
    a.content.behaviour[prop] = value;
    onCourseModified();
}

function simpleTfExecCmd(command) {
    const editor = document.getElementById('simpleTfEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    simpleOnTfInput();
}

function simpleOnTfInput() {
    const editor = document.getElementById('simpleTfEditor');
    if (!editor) return;
    const a = getSelectedActivity();
    if (!a) return;
    let html = editor.innerHTML;
    html = html.replace(/<b>(.*?)<\/b>/gi, '<strong>$1</strong>');
    html = html.replace(/<b\s/gi, '<strong ').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i>(.*?)<\/i>/gi, '<em>$1</em>');
    html = html.replace(/<i\s/gi, '<em ').replace(/<\/i>/gi, '</em>');
    a.content.question = html;
    onCourseModified();
}

function simpleUpdateTfBehaviour(prop, value) {
    const a = getSelectedActivity();
    if (!a.content.behaviour) a.content.behaviour = {};
    a.content.behaviour[prop] = value;
    onCourseModified();
}

function simpleMcExecCmd(command) {
    const editor = document.getElementById('simpleMcEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    simpleOnMcInput();
}

function simpleOnMcInput() {
    const editor = document.getElementById('simpleMcEditor');
    if (!editor) return;
    const a = getSelectedActivity();
    if (!a) return;
    let html = editor.innerHTML;
    html = html.replace(/<b>(.*?)<\/b>/gi, '<strong>$1</strong>');
    html = html.replace(/<b\s/gi, '<strong ').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i>(.*?)<\/i>/gi, '<em>$1</em>');
    html = html.replace(/<i\s/gi, '<em ').replace(/<\/i>/gi, '</em>');
    a.content.question = html;
    onCourseModified();
}

function simpleUpdateMcBehaviour(prop, value) {
    const a = getSelectedActivity();
    if (!a.content.behaviour) a.content.behaviour = {};
    a.content.behaviour[prop] = value;
    onCourseModified();
}

// ==================== CHOIX MULTIMÉDIA (MultiMediaChoice) ====================

function simpleMmcExecCmd(command) {
    const editor = document.getElementById('simpleMmcEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(command, false, null);
    simpleOnMmcInput();
}

function simpleOnMmcInput() {
    const editor = document.getElementById('simpleMmcEditor');
    if (!editor) return;
    const a = getSelectedActivity();
    if (!a) return;
    let html = editor.innerHTML;
    html = html.replace(/<b>(.*?)<\/b>/gi, '<strong>$1</strong>');
    html = html.replace(/<b\s/gi, '<strong ').replace(/<\/b>/gi, '</strong>');
    html = html.replace(/<i>(.*?)<\/i>/gi, '<em>$1</em>');
    html = html.replace(/<i\s/gi, '<em ').replace(/<\/i>/gi, '</em>');
    a.content.question = html;
    onCourseModified();
}

function mmcUpdateOption(idx, prop, value) {
    const a = getSelectedActivity();
    if (!a.content.options || !a.content.options[idx]) return;
    a.content.options[idx][prop] = value;
    onCourseModified();
}

function mmcAddOption() {
    const a = getSelectedActivity();
    if (!a.content.options) a.content.options = [];
    a.content.options.push({
        media: { params: { file: null }, library: 'H5P.Image 1.1' },
        correct: false
    });
    renderSimpleQuizEditor(a);
    onCourseModified();
}

function mmcDeleteOption(idx) {
    const a = getSelectedActivity();
    a.content.options.splice(idx, 1);
    renderSimpleQuizEditor(a);
    onCourseModified();
}

function mmcUpdateBehaviour(prop, value) {
    const a = getSelectedActivity();
    if (!a.content.behaviour) a.content.behaviour = {};
    a.content.behaviour[prop] = value;
    onCourseModified();
}

function mmcUploadImage(input, idx) {
    const file = input.files[0];
    if (!file) return;
    const a = getSelectedActivity();
    if (!a.content.options || !a.content.options[idx]) return;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const dataUrl = e.target.result;
        const fileName = 'images/mmc-' + Date.now() + '-' + file.name;
        
        // Stocker l'image en base64 dans les fichiers du cours
        if (!a.content._imageFiles) a.content._imageFiles = {};
        a.content._imageFiles[fileName] = dataUrl;
        
        // Mettre à jour l'option
        if (!a.content.options[idx].media) {
            a.content.options[idx].media = { params: {}, library: 'H5P.Image 1.1' };
        }
        if (!a.content.options[idx].media.params) {
            a.content.options[idx].media.params = {};
        }
        a.content.options[idx].media.params.file = {
            path: fileName,
            mime: file.type,
            width: 200,
            height: 200,
            _dataUrl: dataUrl
        };
        
        renderSimpleQuizEditor(a);
        onCourseModified();
    };
    reader.readAsDataURL(file);
}

function mmcPickImage(idx) {
    // Trigger le file input correspondant
    const items = document.querySelectorAll('.quiz-answer-item');
    if (items[idx]) {
        const fileInput = items[idx].querySelector('input[type="file"]');
        if (fileInput) fileInput.click();
    }
}

// ==================== GLISSER-DÉPOSER IMAGE (ddimageortext) dans l'évaluation ====================

var _qsDdiEditIdx = null;

function qsAddDdimageortext() {
    const activity = getSelectedActivity();
    migrateQuestionSetData(activity);
    
    activity.content.questions.push({
        qtype: 'ddimageortext',
        name: 'Glisser-Déposer',
        _autoName: true,
        questiontext: '<p>Compléter le schéma</p>',
        questionimage: null,
        defaultmark: 1,
        shuffleanswers: 1,
        backgroundUrl: null,
        bgImageName: null,
        canvasWidth: 800,
        canvasHeight: 600,
        drags: [],
        drops: []
    });
    
    const lastIdx = activity.content.questions.length - 1;
    onCourseModified();
    qsOpenDdiEditor(lastIdx);
}

/**
 * Renommage du titre depuis l'en-tête de l'éditeur glisser : celui-ci affiche le titre de la
 * QUESTION, il doit donc modifier la question et non l'évaluation sélectionnée.
 */
function qsStartEditTitleFromDdi(element) {
    if (_qsDdiEditIdx === null) return;
    if (element.contentEditable === 'true') return;
    const activity = getSelectedActivity();
    const q = activity?.content?.questions?.[_qsDdiEditIdx];
    if (!q) return;

    const titreInitial = q.name || '';
    element.classList.add('editing');
    element.contentEditable = true;
    element.textContent = titreInitial;
    element.focus();

    const range = document.createRange();
    range.selectNodeContents(element);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);

    const finishEdit = () => {
        element.classList.remove('editing');
        element.contentEditable = false;
        const nouveau = element.textContent.trim();
        if (nouveau && nouveau !== titreInitial) {
            q.name = nouveau;
            q._autoName = false;   // titre saisi : l'énoncé ne le réécrira plus
            if (window._qsDdiTempActivity) window._qsDdiTempActivity.name = nouveau;
            onCourseModified();
        }
        element.textContent = q.name || titreInitial;
    };

    element.onblur = finishEdit;
    element.onkeydown = (e) => {
        if (e.key === 'Enter') { e.preventDefault(); element.blur(); }
        else if (e.key === 'Escape') { element.textContent = titreInitial; element.blur(); }
    };
}

function qsOpenDdiEditor(questionIdx) {
    const activity = getSelectedActivity();
    if (!activity || !activity.content || !activity.content.questions[questionIdx]) return;

    _qsDdiEditIdx = questionIdx;
    var q = activity.content.questions[questionIdx];

    // Créer un objet activity temporaire compatible avec renderDdimageortextEditor
    var tempActivity = {
        id: activity.id + '_ddi_' + questionIdx,
        name: q.name || 'Glisser-Déposer',
        // Énoncé tel qu'il est chargé dans l'éditeur (avec son texte par défaut si la
        // question n'en avait pas) : sert à ne réécrire la question que si l'utilisateur
        // l'a réellement modifié (voir qsCloseDdiEditor).
        _enonceOuverture: q.questiontext || '<p>Compléter le schéma</p>',
        type: 'quiz',
        quizType: 'ddimageortext',
        _parentQuizActivity: activity,
        _parentQuestionIdx: questionIdx,
        content: {
            questiontext: q.questiontext || '<p>Compléter le schéma</p>',
            shuffleanswers: q.shuffleanswers || 1,
            attempts_number: 1,
            defaultmark: q.defaultmark || 1,
            backgroundUrl: q.backgroundUrl || null,
            bgImageName: q.bgImageName || null,
            canvasWidth: q.canvasWidth || 800,
            canvasHeight: q.canvasHeight || 600,
            sourceWidth: q.sourceWidth || undefined,
            drags: q.drags || [],
            drops: q.drops || []
        }
    };
    
    window._qsDdiTempActivity = tempActivity;
    // renderDdimageortextEditor gère lui-même le cp-mode et le bouton retour
    renderDdimageortextEditor(tempActivity);
}

function qsCloseDdiEditor() {
    if (_qsDdiEditIdx === null) return;
    
    var activity = getSelectedActivity();
    if (!activity || !activity.content || !activity.content.questions[_qsDdiEditIdx]) {
        _qsDdiEditIdx = null;
        window._qsDdiTempActivity = null;
        return;
    }
    
    // Synchroniser les données du tempActivity vers la question du quiz
    var tempAct = window._qsDdiTempActivity;
    if (tempAct && tempAct.content) {
        var q = activity.content.questions[_qsDdiEditIdx];
        q.backgroundUrl = tempAct.content.backgroundUrl;
        q.bgImageName = tempAct.content.bgImageName;
        q.canvasWidth = tempAct.content.canvasWidth;
        q.canvasHeight = tempAct.content.canvasHeight;
        q.sourceWidth = tempAct.content.sourceWidth;
        q.drags = tempAct.content.drags || [];
        q.drops = tempAct.content.drops || [];
        // L'éditeur glisser affiche un énoncé par défaut quand la question n'en a pas.
        // Ne réécrire la question que si l'énoncé a réellement changé, sinon un simple
        // aller-retour lui collait ce texte par défaut — et donc un nouveau titre.
        var enonceEdite = tempAct.content.questiontext || '';
        if (enonceEdite !== (tempAct._enonceOuverture || '')) {
            q.questiontext = enonceEdite || q.questiontext;
            // Titre depuis l'énoncé, seulement s'il est encore automatique
            qsAutoName(q, _qsDdiEditIdx, q.questiontext || '');
        }
        q.shuffleanswers = tempAct.content.shuffleanswers;
    }
    
    _qsDdiEditIdx = null;
    window._qsDdiTempActivity = null;
    
    // Retirer le mode pleine largeur
    var canvasWrapper = document.getElementById('canvasWrapper');
    if (canvasWrapper) canvasWrapper.classList.remove('cp-mode');
    
    onCourseModified();
    renderQuestionSetEditor(activity);
}