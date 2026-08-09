// ==================== ÉDITEUR MEMORY (H5P.MemoryGame) ====================
// Une entrée de `cards` = UNE PAIRE. Éléa affiche deux cartes par entrée :
// `image` et, si elle est renseignée, `match` (image jumelle différente).
// Sans `match`, la même image est utilisée pour les deux cartes de la paire.

function memoGetContent(activity) {
    if (!activity.content) activity.content = getActivityDefaultContent('MemoryGame');
    const c = activity.content;
    if (!Array.isArray(c.cards)) c.cards = [];
    if (!c.behaviour) c.behaviour = { useGrid: true, allowRetry: true };
    if (!c.lookNFeel) c.lookNFeel = { themeColor: '#707070' };
    if (!c.l10n) c.l10n = getActivityDefaultContent('MemoryGame').l10n;
    return c;
}

function renderMemoryGameEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';

    const c = memoGetContent(activity);
    const cards = c.cards;
    const aid = activity.id;
    const themeColor = c.lookNFeel.themeColor || '#707070';
    const backPath = (c.lookNFeel.cardBack && c.lookNFeel.cardBack.path) || '';

    // Éléa dispose les cartes sur une grille carrée : ceil(racine(nombre de cartes))
    const totalCards = cards.length * 2;
    const cols = Math.max(2, Math.ceil(Math.sqrt(totalCards)));

    const cardsHtml = cards.map((card, i) => {
        const path = (card.image && card.image.path) || '';
        const matchPath = (card.match && card.match.path) || '';
        return `
        <div class="memo-pair">
            <span class="memo-pair-num">${i + 1}</span>
            <div class="memo-pair-imgs">
                <label class="memo-thumb" title="Remplacer l'image">
                    ${path ? `<img src="${path}" alt="">`
                           : `<span class="memo-thumb-empty">aucune image</span>`}
                    <input type="file" accept="image/*" style="display: none;" onchange="memoUploadImage(this, '${aid}', ${i}, 'image')">
                </label>
                <span class="memo-pair-link">↔</span>
                <label class="memo-thumb memo-thumb-match" title="${matchPath ? 'Remplacer l\'image jumelle' : 'Ajouter une image jumelle différente'}">
                    ${matchPath ? `<img src="${matchPath}" alt="">`
                                : (path ? `<img src="${path}" alt="" class="memo-thumb-mirror">`
                                        : `<span class="memo-thumb-empty">identique</span>`)}
                    <input type="file" accept="image/*" style="display: none;" onchange="memoUploadImage(this, '${aid}', ${i}, 'match')">
                </label>
                ${matchPath ? `<button class="tree-action-btn" onclick="memoRemoveMatch('${aid}', ${i})" title="Utiliser deux fois la même image">✖</button>` : ''}
            </div>
            <div class="memo-pair-fields">
                <input type="text" class="cp-prop-input" value="${escapeHtml(card.imageAlt || '')}"
                       placeholder="Texte alternatif (lecteur d'écran)"
                       onchange="memoUpdateField('${aid}', ${i}, 'imageAlt', this.value)">
                <input type="text" class="cp-prop-input" value="${escapeHtml(card.description || '')}"
                       placeholder="Message affiché quand la paire est trouvée (facultatif)"
                       onchange="memoUpdateField('${aid}', ${i}, 'description', this.value)">
            </div>
            <div class="memo-pair-actions">
                <button class="tree-action-btn" onclick="memoMoveCard('${aid}', ${i}, -1)" title="Monter" ${i === 0 ? 'disabled' : ''}>⬆️</button>
                <button class="tree-action-btn" onclick="memoMoveCard('${aid}', ${i}, 1)" title="Descendre" ${i === cards.length - 1 ? 'disabled' : ''}>⬇️</button>
                <button class="tree-action-btn" onclick="memoDeleteCard('${aid}', ${i})" title="Supprimer la paire">🗑️</button>
            </div>
        </div>`;
    }).join('');

    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml('🧠', activity.name, sectionId)}
                <p class="section-preview-desc">Memory : chaque image ci-dessous donne UNE paire de cartes</p>
            </div>
            <div style="padding: 1.5rem;">
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Paires de cartes</label>
                    <p style="font-size: 0.75rem; color: var(--gray-500); margin: 0 0 0.5rem;">
                        ${cards.length ? `${cards.length} paire${cards.length > 1 ? 's' : ''} = ${totalCards} cartes, disposées sur ${cols} colonnes.`
                                       : 'Ajoutez au moins deux paires pour que le jeu ait un intérêt.'}
                        Laissez la case de droite vide pour utiliser deux fois la même image.
                    </p>
                    <div class="memo-pairs">
                        ${cardsHtml || '<p style="font-size:0.8rem;color:var(--gray-400);margin:0;">Aucune paire pour l\'instant.</p>'}
                    </div>
                    <label class="btn btn-primary" style="cursor: pointer; padding: 0.4rem 0.9rem; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.75rem;">
                        🖼️ Ajouter des paires
                        <input type="file" accept="image/*" multiple style="display: none;" onchange="memoUploadImage(this, '${aid}', -1, 'image')">
                    </label>
                </div>

                <div class="cp-prop-group">
                    <label class="cp-prop-label">Apparence</label>
                    <div class="memo-look">
                        <label class="memo-look-item">
                            <span>Couleur du « ? » au dos</span>
                            <input type="color" value="${escapeHtml(themeColor)}"
                                   onchange="memoUpdateTheme('${aid}', this.value)">
                        </label>
                        <div class="memo-look-item">
                            <span>Dos de carte personnalisé</span>
                            <div class="memo-back-slot">
                                <label class="memo-thumb" title="Choisir une image de dos">
                                    ${backPath ? `<img src="${backPath}" alt="">`
                                               : `<span class="memo-thumb-qmark" style="color:${escapeHtml(themeColor)}">?</span>`}
                                    <input type="file" accept="image/*" style="display: none;" onchange="memoUploadCardBack(this, '${aid}')">
                                </label>
                                ${backPath ? `<button class="tree-action-btn" onclick="memoRemoveCardBack('${aid}')" title="Revenir au dos standard">✖</button>` : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="cp-prop-group">
                    <label class="cp-prop-label">Options</label>
                    <label class="cp-checkbox-label">
                        <input type="checkbox" ${c.behaviour.useGrid !== false ? 'checked' : ''}
                               onchange="memoUpdateBehaviour('${aid}', 'useGrid', this.checked)">
                        Disposer les cartes sur une grille carrée
                    </label>
                    <label class="cp-checkbox-label">
                        <input type="checkbox" ${c.behaviour.allowRetry !== false ? 'checked' : ''}
                               onchange="memoUpdateBehaviour('${aid}', 'allowRetry', this.checked)">
                        Bouton « Réessayer » à la fin de la partie
                    </label>
                </div>
            </div>
        </div>`;
}

function memoUpdateField(activityId, idx, prop, value) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const cards = memoGetContent(activity).cards;
    if (!cards[idx]) return;
    cards[idx][prop] = value;
    onCourseModified();
}

function memoUpdateBehaviour(activityId, prop, checked) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    memoGetContent(activity).behaviour[prop] = !!checked;
    onCourseModified();
}

function memoUpdateTheme(activityId, value) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    memoGetContent(activity).lookNFeel.themeColor = value;
    onCourseModified();
    renderMemoryGameEditor(activity);
}

function memoMoveCard(activityId, idx, dir) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const cards = memoGetContent(activity).cards;
    const target = idx + dir;
    if (target < 0 || target >= cards.length) return;
    const tmp = cards[idx];
    cards[idx] = cards[target];
    cards[target] = tmp;
    onCourseModified();
    renderMemoryGameEditor(activity);
}

function memoDeleteCard(activityId, idx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    memoGetContent(activity).cards.splice(idx, 1);
    onCourseModified();
    renderMemoryGameEditor(activity);
}

function memoRemoveMatch(activityId, idx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const cards = memoGetContent(activity).cards;
    if (!cards[idx]) return;
    delete cards[idx].match;
    delete cards[idx].matchAlt;
    onCourseModified();
    renderMemoryGameEditor(activity);
}

function memoRemoveCardBack(activityId) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    delete memoGetContent(activity).lookNFeel.cardBack;
    onCourseModified();
    renderMemoryGameEditor(activity);
}

// Envoie les fichiers et renvoie les objets image H5P (avec les dimensions, qu'Éléa attend)
function memoUploadFiles(files) {
    return Promise.all(files.map(file => {
        const formData = new FormData();
        formData.append('file', file);
        if (typeof getEditorSessionId === 'function') formData.append('session_id', getEditorSessionId());
        return fetch('api/editor_api.php?action=upload_file', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                return new Promise((resolve, reject) => {
                    const img = new Image();
                    img.onload = () => resolve({
                        path: data.url,
                        mime: file.type || 'image/jpeg',
                        copyright: { license: 'U' },
                        width: img.naturalWidth,
                        height: img.naturalHeight
                    });
                    img.onerror = () => reject(new Error('Image illisible'));
                    img.src = data.url;
                });
            });
    }));
}

// idx = -1 : ajouter des paires à la fin (sélection multiple) ;
// sinon remplacer l'image (`slot` = 'image') ou l'image jumelle (`slot` = 'match') de la paire idx
function memoUploadImage(input, activityId, idx, slot) {
    const files = Array.prototype.slice.call(input.files || []);
    if (!files.length) return;
    const activity = findActivityById(activityId);
    if (!activity) return;

    for (const file of files) {
        if (typeof canAddImage === 'function' && !canAddImage(file)) { input.value = ''; return; }
    }
    showToast('Upload en cours...', 'info');

    memoUploadFiles(files)
        .then(images => {
            const cards = memoGetContent(activity).cards;
            if (idx >= 0 && cards[idx]) {
                cards[idx][slot === 'match' ? 'match' : 'image'] = images[0];
            } else {
                images.forEach(image => cards.push({ image: image, imageAlt: '', description: '' }));
            }
            onCourseModified();
            renderMemoryGameEditor(activity);
            showToast(images.length > 1 ? images.length + ' paires ajoutées' : 'Image ajoutée', 'success');
        })
        .catch(err => { showToast('Erreur : ' + err.message, 'error'); console.error(err); });
}

function memoUploadCardBack(input, activityId) {
    const files = Array.prototype.slice.call(input.files || []);
    if (!files.length) return;
    const activity = findActivityById(activityId);
    if (!activity) return;
    if (typeof canAddImage === 'function' && !canAddImage(files[0])) { input.value = ''; return; }
    showToast('Upload en cours...', 'info');

    memoUploadFiles([files[0]])
        .then(images => {
            memoGetContent(activity).lookNFeel.cardBack = images[0];
            onCourseModified();
            renderMemoryGameEditor(activity);
            showToast('Dos de carte mis à jour', 'success');
        })
        .catch(err => { showToast('Erreur : ' + err.message, 'error'); console.error(err); });
}
