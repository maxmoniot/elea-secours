// ==================== VIGNETTE DU COURS ====================
//
// La vignette est l'image de la carte du parcours dans Éléa. Dans le .mbz elle vit
// à part des activités : component « course », filearea « overviewfiles ».
// Ici elle est stockée dans courseData.vignette = { url, name } et suit le cours
// comme n'importe quel média (auto-save, Drive, export).
//
// Format Éléa : 300 × 215. Une image d'un autre format est recadrée (centrée) à
// cette taille avant l'envoi ; une image déjà au bon format est envoyée telle quelle.

const CV_LARGEUR = 300;
const CV_HAUTEUR = 215;

var _cvDerniereUrl = null;   // évite de recharger l'aperçu à chaque frappe

function courseVignetteGet() {
    if (typeof courseData === 'undefined' || !courseData) return null;
    var v = courseData.vignette;
    if (!v) return null;
    if (typeof v === 'string') return { url: v, name: '' };
    return v.url ? v : null;
}

/**
 * Met l'aperçu du header à jour. Appelée après chaque chargement de cours et à
 * chaque modification : elle ne touche au DOM que si l'URL a réellement changé.
 * `force` sert quand on vient de changer la vignette (y compris vers « aucune »,
 * que la comparaison avec l'état initial ne distinguerait pas).
 */
function courseVignetteRefreshUI(force) {
    var thumb = document.getElementById('courseVignetteThumb');
    var vide = document.getElementById('courseVignetteEmpty');
    if (!thumb || !vide) return;

    var v = courseVignetteGet();
    var url = v ? v.url : null;
    if (!force && url === _cvDerniereUrl) return;
    _cvDerniereUrl = url;

    if (url) {
        thumb.src = url;
        thumb.style.display = 'block';
        vide.style.display = 'none';
    } else {
        thumb.removeAttribute('src');
        thumb.style.display = 'none';
        vide.style.display = 'flex';
    }

    var btn = document.getElementById('courseVignetteBtn');
    if (btn) {
        btn.title = url
            ? 'Vignette du cours — cliquer pour la changer'
            : 'Aucune vignette — cliquer pour en ajouter une (300 × 215)';
    }
}

function openCourseVignetteModal() {
    courseVignetteRenderModal();
    openModal('courseVignetteModal');
}

function closeCourseVignetteModal() {
    closeModal('courseVignetteModal');
}

function courseVignetteRenderModal() {
    var apercu = document.getElementById('courseVignettePreview');
    var info = document.getElementById('courseVignetteInfo');
    var btnRetirer = document.getElementById('courseVignetteRemoveBtn');
    if (!apercu) return;

    var v = courseVignetteGet();
    if (!v) {
        apercu.innerHTML = '<div class="cvg-placeholder">'
            + '<div class="cvg-placeholder-icon">🖼️</div>'
            + '<div>Aucune vignette</div>'
            + '<div class="cvg-placeholder-hint">Glissez une image ici ou cliquez sur « Choisir une image »</div>'
            + '</div>';
        if (info) info.textContent = 'Le cours sera affiché sans image sur Éléa.';
        if (btnRetirer) btnRetirer.style.display = 'none';
        return;
    }

    // L'URL est posée par l'API (elle peut contenir des caractères encodés) :
    // on la place via l'objet Image, jamais par concaténation de HTML.
    apercu.innerHTML = '';
    var img = document.createElement('img');
    img.id = 'courseVignetteBigImg';
    img.alt = 'Vignette du cours';
    apercu.appendChild(img);
    if (btnRetirer) btnRetirer.style.display = '';
    if (info) info.textContent = (v.name || 'vignette') + ' — mesure en cours…';

    if (info) {
        img.onload = function() {
            var dims = this.naturalWidth + ' × ' + this.naturalHeight;
            var attendu = (this.naturalWidth === CV_LARGEUR && this.naturalHeight === CV_HAUTEUR);
            info.textContent = (v.name || 'vignette') + ' — ' + dims
                + (attendu ? ' (format Éléa)' : ' (Éléa attend ' + CV_LARGEUR + ' × ' + CV_HAUTEUR + ')');
        };
        img.onerror = function() {
            info.textContent = 'Image introuvable — choisissez-en une nouvelle.';
        };
    }
    img.src = v.url;   // après les gestionnaires : une image en cache déclenche load tout de suite
}

function courseVignetteChoose(input) {
    var fichier = input.files && input.files[0];
    input.value = '';
    if (fichier) courseVignetteSetFromFile(fichier);
}

function courseVignetteDrop(ev) {
    ev.preventDefault();
    ev.currentTarget.classList.remove('cvg-dragover');
    var fichier = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
    if (fichier) courseVignetteSetFromFile(fichier);
}

function courseVignetteDragOver(ev) {
    ev.preventDefault();
    ev.currentTarget.classList.add('cvg-dragover');
}

function courseVignetteDragLeave(ev) {
    ev.currentTarget.classList.remove('cvg-dragover');
}

function courseVignetteSetFromFile(fichier) {
    if (!fichier.type || fichier.type.indexOf('image/') !== 0) {
        showToast('Seules les images sont acceptées', 'error');
        return;
    }

    var info = document.getElementById('courseVignetteInfo');
    if (info) info.textContent = 'Préparation de l\'image…';

    courseVignettePrepare(fichier).then(function(pret) {
        var formData = new FormData();
        formData.append('action', 'upload_file');
        formData.append('session_id', getEditorSessionId());
        formData.append('file', pret.blob, pret.nom);

        if (info) info.textContent = 'Envoi…';

        return fetch('api/editor_api.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.url) throw new Error(data.error || 'Upload échoué');
                if (data.filename && typeof EditorDriveSync !== 'undefined') {
                    EditorDriveSync.onFileUploaded(data.filename, data.url, data.type || '');
                }
                courseData.vignette = { url: data.url, name: pret.nom };
                courseVignetteRefreshUI(true);
                courseVignetteRenderModal();
                onCourseModified();
                showToast('Vignette mise à jour', 'success');
            });
    }).catch(function(err) {
        console.error('[Vignette]', err);
        showToast('Erreur : ' + (err.message || 'vignette non enregistrée'), 'error');
        courseVignetteRenderModal();
    });
}

/**
 * Ramène l'image au format Éléa (300 × 215) par recadrage centré. Une image déjà
 * à ce format est renvoyée telle quelle : pas de ré-encodage inutile.
 * @return Promise<{blob: Blob, nom: string}>
 */
function courseVignettePrepare(fichier) {
    return new Promise(function(resolve, reject) {
        var url = URL.createObjectURL(fichier);
        var img = new Image();

        img.onload = function() {
            var l = img.naturalWidth, h = img.naturalHeight;

            if (l === CV_LARGEUR && h === CV_HAUTEUR) {
                URL.revokeObjectURL(url);
                resolve({ blob: fichier, nom: courseVignetteNom(fichier.name, fichier.type) });
                return;
            }

            var canvas = document.createElement('canvas');
            canvas.width = CV_LARGEUR;
            canvas.height = CV_HAUTEUR;
            var ctx = canvas.getContext('2d');

            // Recadrage « cover » centré : on remplit le cadre sans déformer l'image
            var echelle = Math.max(CV_LARGEUR / l, CV_HAUTEUR / h);
            var lSrc = CV_LARGEUR / echelle;
            var hSrc = CV_HAUTEUR / echelle;
            ctx.drawImage(img, (l - lSrc) / 2, (h - hSrc) / 2, lSrc, hSrc,
                               0, 0, CV_LARGEUR, CV_HAUTEUR);

            var type = (fichier.type === 'image/jpeg') ? 'image/jpeg' : 'image/png';
            canvas.toBlob(function(blob) {
                URL.revokeObjectURL(url);
                if (!blob) { reject(new Error('recadrage impossible')); return; }
                resolve({ blob: blob, nom: courseVignetteNom(fichier.name, type) });
            }, type, 0.92);
        };

        img.onerror = function() {
            URL.revokeObjectURL(url);
            reject(new Error('image illisible'));
        };
        img.src = url;
    });
}

/** Nom du fichier tel qu'il figurera dans le .mbz (extension alignée sur le type produit). */
function courseVignetteNom(nomOrigine, type) {
    var ext = (type === 'image/jpeg') ? 'jpg' : (type === 'image/png' ? 'png' : '');
    var base = (nomOrigine || 'vignette').replace(/\\/g, '/').split('/').pop();
    base = base.replace(/[/:*?"<>|]+/g, '').replace(/\.[^.]+$/, '');
    if (!base) base = 'vignette';
    if (!ext) {
        ext = (nomOrigine.match(/\.([a-z0-9]{2,5})$/i) || [null, 'png'])[1].toLowerCase();
    }
    return base.slice(0, 80) + '.' + ext;
}

function courseVignetteRemove() {
    if (!courseVignetteGet()) return;
    if (!confirm('Retirer la vignette du cours ?\n\nLe cours sera exporté sans image de parcours.')) return;
    courseData.vignette = null;
    courseVignetteRefreshUI(true);
    courseVignetteRenderModal();
    onCourseModified();
    showToast('Vignette retirée', 'info');
}
