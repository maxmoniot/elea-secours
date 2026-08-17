// ==================== ÉTIQUETTE ET PAGE (modules de texte Moodle) ====================
//
// Deux activités Éléa qui ne sont PAS du H5P :
//   • Étiquette (« label ») : du texte posé directement sur la page du parcours,
//     sans clic — le « Bonjour, faites les activités ci-dessous… » de Max.
//     Le texte vit dans `intro`.
//   • Page (« page ») : une page de contenu qui s'ouvre depuis le parcours.
//     Le texte vit dans `content`.
//
// Elles passaient auparavant par la moulinette H5P et ressortaient en
// « H5P.label » / « H5P.page » : des bibliothèques inexistantes, donc une
// activité vide dans Éléa.

const TXT_MODULES = {
    label: {
        champ: 'intro', icone: '💬', titre: 'Étiquette',
        desc: 'Texte affiché directement sur la page du parcours (sans clic)',
    },
    page: {
        champ: 'content', icone: '📄', titre: 'Page',
        desc: 'Page de contenu que l\'élève ouvre depuis le parcours',
    },
};

function txtModuleInfo(activity) {
    return TXT_MODULES[activity && activity.type] || TXT_MODULES.label;
}

/** Texte courant du module, quel que soit le champ qui le porte. */
function txtModuleTexte(activity) {
    const info = txtModuleInfo(activity);
    let v = activity[info.champ];
    if (typeof v !== 'string') v = '';
    // Un cours enregistré avant la prise en charge peut avoir rangé le texte
    // dans l'autre champ : on le récupère plutôt que de l'effacer.
    if (!v) {
        const autre = info.champ === 'intro' ? 'content' : 'intro';
        if (typeof activity[autre] === 'string') v = activity[autre];
    }
    return v;
}

function renderTextModuleEditor(activity) {
    const content = document.getElementById('editorContent');
    const section = courseData.sections.find(s => s.activities && s.activities.some(a => a.id === activity.id));
    const sectionId = section ? section.id : '';
    const info = txtModuleInfo(activity);

    content.innerHTML = `
        <div class="section-preview">
            <div class="section-preview-header">
                ${editorHeaderHtml(info.icone, activity.name, sectionId)}
                <p class="section-preview-desc">${info.desc}</p>
            </div>
            <div style="padding: 1.5rem;">
                <div class="rich-text-toolbar" style="display: flex; gap: 0.25rem; margin-bottom: 0.25rem; flex-wrap: wrap;">
                    <button class="rich-text-btn" type="button" onclick="txtModuleCmd('bold')" title="Gras"><b>G</b></button>
                    <button class="rich-text-btn" type="button" onclick="txtModuleCmd('italic')" title="Italique"><i>I</i></button>
                    <button class="rich-text-btn" type="button" onclick="txtModuleCmd('underline')" title="Souligné"><u>S</u></button>
                    <span style="border-left: 1px solid var(--gray-300); margin: 0 0.25rem;"></span>
                    <button class="rich-text-btn" type="button" onclick="txtModuleCmd('formatBlock','<h4>')" title="Titre">T</button>
                    <button class="rich-text-btn" type="button" onclick="txtModuleCmd('formatBlock','<p>')" title="Paragraphe">¶</button>
                    <button class="rich-text-btn" type="button" onclick="txtModuleCmd('insertUnorderedList')" title="Liste à puces">☰</button>
                    <span style="border-left: 1px solid var(--gray-300); margin: 0 0.25rem;"></span>
                    <label class="rich-text-btn" style="cursor: pointer; margin: 0;" title="Insérer une image">
                        🖼️
                        <input type="file" accept="image/*" style="display: none;" onchange="txtModuleInsertImage(this)">
                    </label>
                </div>
                <div id="txtModuleEditor" class="rich-text-editor" contenteditable="true"
                     style="min-height: 220px; font-size: 0.95rem; padding: 1rem; border: 1px solid var(--gray-300); border-radius: 8px; background: var(--bg-secondary,white); color: var(--text-primary,inherit); outline: none;"
                     oninput="txtModuleUpdate()">${txtModuleTexte(activity)}</div>
                <p style="margin-top: 0.75rem; font-size: 0.8rem; color: var(--gray-500);">
                    ${activity.type === 'label'
                        ? 'Ce texte s\'affiche tel quel dans le parcours, entre les activités.'
                        : 'Ce contenu s\'affiche quand l\'élève ouvre la page.'}
                </p>
            </div>
        </div>`;
}

function txtModuleCmd(commande, valeur) {
    const editor = document.getElementById('txtModuleEditor');
    if (!editor) return;
    editor.focus();
    document.execCommand(commande, false, valeur || null);
    txtModuleUpdate();
}

function txtModuleUpdate() {
    const editor = document.getElementById('txtModuleEditor');
    const activity = getSelectedActivity();
    if (!editor || !activity) return;

    // Éléa écrit <strong>/<em> ; l'éditeur produit <b>/<i>
    let html = editor.innerHTML
        .replace(/<b(\s|>)/gi, '<strong$1').replace(/<\/b>/gi, '</strong>')
        .replace(/<i(\s|>)/gi, '<em$1').replace(/<\/i>/gi, '</em>');

    const info = txtModuleInfo(activity);
    activity[info.champ] = html;
    // Ne pas laisser un doublon dans l'autre champ (il repartirait à l'export)
    const autre = info.champ === 'intro' ? 'content' : 'intro';
    if (typeof activity[autre] === 'string' && activity[autre] !== '') activity[autre] = '';

    onCourseModified();
}

function txtModuleInsertImage(input) {
    if (!input.files || !input.files.length) return;
    const file = input.files[0];
    input.value = '';
    if (!file.type.startsWith('image/')) {
        showToast('Seules les images sont acceptées', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('session_id', getEditorSessionId());
    formData.append('file', file);

    showToast('Envoi de l\'image…', 'info');
    fetch('api/editor_api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.url) throw new Error(data.error || 'Upload échoué');
            if (data.filename && typeof EditorDriveSync !== 'undefined') {
                EditorDriveSync.onFileUploaded(data.filename, data.url, data.type || '');
            }
            const editor = document.getElementById('txtModuleEditor');
            if (!editor) return;
            editor.focus();
            const img = document.createElement('img');
            img.src = data.url;
            img.className = 'img-fluid';
            img.setAttribute('role', 'presentation');
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            const sel = window.getSelection();
            if (sel && sel.rangeCount > 0 && editor.contains(sel.anchorNode)) {
                const range = sel.getRangeAt(0);
                range.deleteContents();
                range.insertNode(img);
                range.collapse(false);
            } else {
                editor.appendChild(img);
            }
            txtModuleUpdate();
            showToast('Image insérée', 'success');
        })
        .catch(err => showToast('Erreur : ' + err.message, 'error'));
}

/** Étiquette vierge, ajoutée depuis « Ajouter une activité ». */
function createTextModuleActivity(type) {
    const info = TXT_MODULES[type] || TXT_MODULES.label;
    return {
        id: generateId(),
        type: type,
        h5pType: '',
        name: info.titre,
        visible: true,
        intro: type === 'label' ? '<h4>Nouveau texte</h4>' : '',
        content: type === 'page' ? '<p>Nouveau contenu</p>' : '',
    };
}
