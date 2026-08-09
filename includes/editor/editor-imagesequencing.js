// ==================== ÉDITEUR REMETTRE DANS L'ORDRE (H5P.ImageSequencing) ====================
// L'élève reçoit les images mélangées et doit les replacer. L'ordre saisi ICI est la solution.

function seqGetContent(activity) {
    if (!activity.content) activity.content = getActivityDefaultContent('ImageSequencing');
    if (!Array.isArray(activity.content.sequenceImages)) activity.content.sequenceImages = [];
    if (!activity.content.behaviour) activity.content.behaviour = { enableSolution: true, enableRetry: true, enableResume: true };
    return activity.content;
}

function renderImageSequencingEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';

    const c = seqGetContent(activity);
    const cards = c.sequenceImages;
    const aid = activity.id;

    const cardsHtml = cards.map((card, i) => {
        const path = card.image?.path || '';
        return `
        <div class="seq-card">
            <span class="seq-card-num">${i + 1}</span>
            <div class="seq-card-thumb">
                ${path ? `<img src="${path}" alt="">`
                       : `<span class="seq-card-noimg">aucune image</span>`}
            </div>
            <input type="text" class="cp-prop-input seq-card-label" value="${escapeHtml(card.imageDescription || '')}"
                   placeholder="Légende sous l'image"
                   onchange="seqUpdateLabel('${aid}', ${i}, this.value)">
            <div class="seq-card-actions">
                <label class="tree-action-btn" title="Remplacer l'image" style="cursor: pointer;">🖼️
                    <input type="file" accept="image/*" style="display: none;" onchange="seqUploadImage(this, '${aid}', ${i})">
                </label>
                <button class="tree-action-btn" onclick="seqMoveCard('${aid}', ${i}, -1)" title="Monter" ${i === 0 ? 'disabled' : ''}>⬆️</button>
                <button class="tree-action-btn" onclick="seqMoveCard('${aid}', ${i}, 1)" title="Descendre" ${i === cards.length - 1 ? 'disabled' : ''}>⬇️</button>
                <button class="tree-action-btn" onclick="seqDeleteCard('${aid}', ${i})" title="Supprimer">🗑️</button>
            </div>
        </div>`;
    }).join('');

    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml('🔢', activity.name, sectionId)}
                <p class="section-preview-desc">Remettre dans l'ordre : les images sont mélangées pour l'élève</p>
            </div>
            <div style="padding: 1.5rem;">
                <div class="cp-prop-group">
                    <label class="cp-prop-label">Consigne</label>
                    <input type="text" class="cp-prop-input" value="${escapeHtml(c.taskDescription || '')}"
                           placeholder="Ex. : organisez les fichiers du plus petit au plus gros."
                           onchange="seqUpdateTask('${aid}', this.value)">
                </div>

                <div class="cp-prop-group">
                    <label class="cp-prop-label">Images, dans le BON ordre</label>
                    <p style="font-size: 0.75rem; color: var(--gray-500); margin: 0 0 0.5rem;">
                        C'est cet ordre qui sert de solution ; l'élève les reçoit mélangées.
                    </p>
                    <div class="seq-cards">
                        ${cardsHtml || '<p style="font-size:0.8rem;color:var(--gray-400);margin:0;">Aucune image pour l\'instant.</p>'}
                    </div>
                    <label class="btn btn-primary" style="cursor: pointer; padding: 0.4rem 0.9rem; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.75rem;">
                        🖼️ Ajouter des images
                        <input type="file" accept="image/*" multiple style="display: none;" onchange="seqUploadImage(this, '${aid}', -1)">
                    </label>
                </div>

                <div class="cp-prop-group">
                    <label class="cp-prop-label">Options</label>
                    <label class="cp-checkbox-label">
                        <input type="checkbox" ${c.behaviour.enableSolution !== false ? 'checked' : ''}
                               onchange="seqUpdateBehaviour('${aid}', 'enableSolution', this.checked)">
                        Bouton « Afficher la solution »
                    </label>
                    <label class="cp-checkbox-label">
                        <input type="checkbox" ${c.behaviour.enableRetry !== false ? 'checked' : ''}
                               onchange="seqUpdateBehaviour('${aid}', 'enableRetry', this.checked)">
                        Bouton « Recommencer »
                    </label>
                </div>
            </div>
        </div>`;
}

function seqUpdateTask(activityId, value) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    seqGetContent(activity).taskDescription = value;
    onCourseModified();
}

function seqUpdateLabel(activityId, idx, value) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const cards = seqGetContent(activity).sequenceImages;
    if (!cards[idx]) return;
    cards[idx].imageDescription = value;
    onCourseModified();
}

function seqUpdateBehaviour(activityId, prop, checked) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    seqGetContent(activity).behaviour[prop] = !!checked;
    onCourseModified();
}

function seqMoveCard(activityId, idx, dir) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    const cards = seqGetContent(activity).sequenceImages;
    const target = idx + dir;
    if (target < 0 || target >= cards.length) return;
    const tmp = cards[idx];
    cards[idx] = cards[target];
    cards[target] = tmp;
    onCourseModified();
    renderImageSequencingEditor(activity);
}

function seqDeleteCard(activityId, idx) {
    const activity = findActivityById(activityId);
    if (!activity) return;
    seqGetContent(activity).sequenceImages.splice(idx, 1);
    onCourseModified();
    renderImageSequencingEditor(activity);
}

// idx = -1 : ajouter à la fin (sélection multiple possible) ; sinon remplacer l'image de la carte
function seqUploadImage(input, activityId, idx) {
    const files = Array.prototype.slice.call(input.files || []);
    if (!files.length) return;
    const activity = findActivityById(activityId);
    if (!activity) return;

    for (const file of files) {
        if (typeof canAddImage === 'function' && !canAddImage(file)) { input.value = ''; return; }
    }
    showToast('Upload en cours...', 'info');

    const uploads = files.map(file => {
        const formData = new FormData();
        formData.append('file', file);
        if (typeof getEditorSessionId === 'function') formData.append('session_id', getEditorSessionId());
        return fetch('api/editor_api.php?action=upload_file', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                // Lire les dimensions : Éléa les attend dans le contenu
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
    });

    Promise.all(uploads)
        .then(images => {
            const cards = seqGetContent(activity).sequenceImages;
            if (idx >= 0 && cards[idx]) {
                cards[idx].image = images[0];
            } else {
                images.forEach(image => cards.push({ image: image, imageDescription: '' }));
            }
            onCourseModified();
            renderImageSequencingEditor(activity);
            showToast(images.length > 1 ? images.length + ' images ajoutées' : 'Image ajoutée', 'success');
        })
        .catch(err => { showToast('Erreur : ' + err.message, 'error'); console.error(err); });
}
