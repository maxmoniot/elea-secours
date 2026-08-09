// ==================== UTILITAIRES ====================
function generateId() {
return 'id_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

function showToast(message, type = 'info') {
const container = document.getElementById('toastContainer');
const toast = document.createElement('div');
toast.className = 'toast ' + type;
toast.textContent = message;
container.appendChild(toast);
setTimeout(() => toast.remove(), 3000);
}

function closeModal(modalId) {
    document.getElementById(modalId)?.classList.remove('active');
}

function openModal(modalId) {
    document.getElementById(modalId)?.classList.add('active');
}

function escapeHtml(text) {
const div = document.createElement('div');
div.textContent = text || '';
return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ==================== CAPTURES SCRATCH : ZONES À EFFACER DU FOND ====================
/**
 * Renvoie le masque des pixels à blanchir pour fabriquer le fond d'une capture Scratch.
 *
 * Le fond garde l'espace de travail Scratch (gris clair pointillé) et les blocs
 * CONTENEURS — ceux qui accueillent un opérateur — avec un trou à l'emplacement de
 * chaque pièce à glisser. C'est la même lecture que pour MakeCode, où le « si » reste
 * dans le fond ; la différence est qu'ici on part de l'image d'origine au lieu d'un
 * fond blanc, pour ne pas perdre le quadrillage.
 *
 * On efface l'union des masques des pièces, dilatée de quelques pixels pour emporter
 * les bords anti-aliasés (sans cette marge il reste un liseré coloré autour du trou).
 *
 * N'est utilisé QUE pour les captures Scratch : les pipelines MakeCode et Diagram
 * gardent leur composition d'origine.
 */
function extractionMasqueFondScratch(actionBlocks, blockMasks, rgba, w, h) {
    var MARGE = 2;
    var n = w * h;
    var brut = new Uint8Array(n);

    for (var i = 0; i < actionBlocks.length; i++) {
        var m = blockMasks[actionBlocks[i].id];
        if (!m) continue;
        for (var p = 0; p < n; p++) if (m[p]) brut[p] = 1;
    }

    // Dilatation séparable (horizontale puis verticale)
    var tmp = new Uint8Array(n), out = new Uint8Array(n);
    var x, y, k, base, xx, yy;
    for (y = 0; y < h; y++) {
        base = y * w;
        for (x = 0; x < w; x++) {
            if (!brut[base + x]) continue;
            for (k = -MARGE; k <= MARGE; k++) {
                xx = x + k;
                if (xx >= 0 && xx < w) tmp[base + xx] = 1;
            }
        }
    }
    for (y = 0; y < h; y++) {
        for (x = 0; x < w; x++) {
            if (!tmp[y * w + x]) continue;
            for (k = -MARGE; k <= MARGE; k++) {
                yy = y + k;
                if (yy >= 0 && yy < h) out[yy * w + x] = 1;
            }
        }
    }
    return out;
}
