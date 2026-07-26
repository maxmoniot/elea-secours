// ==================== GESTION DU POIDS DU COURS ====================

const MAX_COURSE_SIZE = 200 * 1024 * 1024; // 200 Mo en bytes
const SERVER_MAX_SIZE = 400 * 1024 * 1024; // 400 Mo en bytes
let currentCourseSize = 0;
let serverTotalUsage = 0; // Espace serveur total utilisé
let serverSpaceFull = false;
let _sizeCalcPending = false; // éviter les appels fetch en parallèle

// Récupérer l'espace serveur total depuis le backend
function fetchServerUsage() {
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_server_usage' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            serverTotalUsage = data.usage_bytes || 0;
            serverSpaceFull = (serverTotalUsage >= SERVER_MAX_SIZE);
            updateServerDisplay();
        }
    })
    .catch(() => {});
}

// Mettre à jour l'affichage de l'espace serveur
function updateServerDisplay() {
    var fillEl = document.getElementById('serverSizeFill');
    var textEl = document.getElementById('serverSizeText');
    if (!fillEl || !textEl) return;
    
    var usedMB = (serverTotalUsage / (1024 * 1024)).toFixed(1);
    var maxMB = Math.round(SERVER_MAX_SIZE / (1024 * 1024));
    var pct = Math.min((serverTotalUsage / SERVER_MAX_SIZE) * 100, 100);
    
    fillEl.style.width = pct + '%';
    textEl.textContent = 'Serveur: ' + usedMB + ' Mo / ' + maxMB + ' Mo';
    
    if (pct >= 90) {
        fillEl.style.background = '#ef4444';
        textEl.style.color = '#ef4444';
        textEl.style.fontWeight = '600';
    } else if (pct >= 70) {
        fillEl.style.background = '#f59e0b';
        textEl.style.color = '#f59e0b';
        textEl.style.fontWeight = '';
    } else {
        fillEl.style.background = '#6366f1';
        textEl.style.color = '';
        textEl.style.fontWeight = '';
    }
}

// Calculer le poids estimé du cours
function calculateCourseSize() {
    let textSize = 0;
    
    // Estimer la taille texte/JSON du cours (métadonnées, contenus HTML, etc.)
    courseData.sections.forEach(function(section) {
        textSize += _byteLen(section.name || '');
        textSize += _byteLen(section.summary || '');
        
        (section.activities || []).forEach(function(activity) {
            textSize += _byteLen(activity.name || '');
            textSize += 500; // métadonnées XML
            
            if (activity.content) {
                textSize += _estimateTextContent(activity.content);
            }
            if (activity.type === 'assign' && activity.intro) {
                textSize += _byteLen(activity.intro);
            }
        });
    });
    
    // Marge métadonnées XML (~10%)
    textSize = Math.round(textSize * 1.1);
    
    // Utiliser la taille des fichiers déjà récupérée depuis le serveur
    currentCourseSize = textSize + (_sessionFilesTotal || 0);
    updateSizeDisplay();
    
    // Si pas encore récupéré, demander au serveur la taille totale des fichiers
    if (_sessionFilesTotal === null && !_sizeCalcPending) {
        _fetchSessionFilesTotal();
    }
    
    return currentCourseSize;
}

// Taille totale des fichiers de la session (récupérée du serveur)
var _sessionFilesTotal = null;

function _fetchSessionFilesTotal() {
    _sizeCalcPending = true;
    var sessionId = (typeof getEditorSessionId === 'function') ? getEditorSessionId() : '';
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_session_files_total', sessionId: sessionId })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        _sizeCalcPending = false;
        if (data.success) {
            _sessionFilesTotal = data.total_bytes || 0;
            console.log('[CourseSize] Fichiers session:', data.file_count, 'fichiers,', 
                        (data.total_bytes / (1024*1024)).toFixed(2), 'Mo');
            calculateCourseSize(); // recalculer avec la vraie taille
        }
    })
    .catch(function(err) { 
        console.error('[CourseSize] Erreur:', err); 
        _sizeCalcPending = false; 
    });
}

// Forcer le rafraîchissement de la taille fichiers (après upload, suppression, etc.)
function refreshFilesSize() {
    _sessionFilesTotal = null;
    _fetchSessionFilesTotal();
}

// Estimer la taille texte d'un contenu H5P (récursif, ignore les fichiers)
function _estimateTextContent(content) {
    if (!content) return 0;
    if (typeof content === 'string') {
        // Ignorer les data URLs base64 et les URLs de fichiers pour le calcul texte
        if (content.length > 200 && content.includes('base64')) {
            return 0; // les fichiers sont comptés via le serveur
        }
        if (content.includes('serve_upload')) {
            return 50; // juste l'URL elle-même
        }
        return _byteLen(content);
    }
    if (Array.isArray(content)) {
        var size = 0;
        for (var i = 0; i < content.length; i++) size += _estimateTextContent(content[i]);
        return size;
    }
    if (typeof content === 'object') {
        var size = 0;
        for (var key in content) {
            if (!content.hasOwnProperty(key)) continue;
            size += _estimateTextContent(content[key]);
        }
        return size;
    }
    return 0;
}

// Taille UTF-8 d'une string
function _byteLen(str) {
    if (!str) return 0;
    // Approximation rapide: ASCII=1, accentués~2, emoji~4
    let len = 0;
    for (let i = 0; i < str.length; i++) {
        const c = str.charCodeAt(i);
        if (c < 128) len += 1;
        else if (c < 2048) len += 2;
        else len += 3;
    }
    return len;
}

function invalidateFileSizeCache(url) {
    // Déclencher un refresh de la taille fichiers depuis le serveur
    refreshFilesSize();
}
function updateSizeDisplay() {
    const fillEl = document.getElementById('courseSizeFill');
    const textEl = document.getElementById('courseSizeText');
    
    if (!fillEl || !textEl) return;
    
    const sizeMo = currentCourseSize / (1024 * 1024);
    const maxMo = MAX_COURSE_SIZE / (1024 * 1024);
    const percentage = Math.min((currentCourseSize / MAX_COURSE_SIZE) * 100, 100);
    
    fillEl.style.width = percentage + '%';
    textEl.textContent = `Cours en création: ${sizeMo.toFixed(1)} Mo / ${maxMo} Mo`;
    
    fillEl.classList.remove('warning', 'danger');
    textEl.classList.remove('warning', 'danger');
    
    if (percentage >= 90) {
        fillEl.classList.add('danger');
        textEl.classList.add('danger');
    } else if (percentage >= 70) {
        fillEl.classList.add('warning');
        textEl.classList.add('warning');
    }
}

// Vérifier si on peut ajouter du contenu
function canAddContent(estimatedSize = 0) {
    // Vérifier la limite du cours (200 Mo)
    if (currentCourseSize + estimatedSize > MAX_COURSE_SIZE) {
        const currentMo = (currentCourseSize / (1024 * 1024)).toFixed(1);
        const maxMo = (MAX_COURSE_SIZE / (1024 * 1024)).toFixed(0);
        showToast(`Limite de ${maxMo} Mo atteinte ! (Actuel: ${currentMo} Mo)`, 'error');
        return false;
    }
    // Vérifier la limite serveur (400 Mo)
    if (serverSpaceFull) {
        showToast('Espace disque serveur plein (400 Mo). Impossible d\'ajouter du contenu.', 'error');
        return false;
    }
    return true;
}

function canAddImage(file) {
    if (!file) return true;
    if (!canAddContent(file.size || 0)) return false;
    if (file.size > 5 * 1024 * 1024) {
        showToast(`Image volumineuse (${(file.size / (1024 * 1024)).toFixed(1)} Mo)`, 'warning');
    }
    return true;
}

function canAddVideo(file) {
    if (!file) return true;
    if (!canAddContent(file.size || 0)) return false;
    if (file.size > 20 * 1024 * 1024) {
        showToast(`Vidéo volumineuse (${(file.size / (1024 * 1024)).toFixed(1)} Mo). Considérez une compression.`, 'warning');
    }
    return true;
}

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    fetchServerUsage();
    // Rafraîchir l'espace serveur toutes les 60 secondes
    setInterval(fetchServerUsage, 60000);
    // Rafraîchir la jauge cours toutes les 15 secondes pour refléter les flushs Drive en arrière-plan
    setInterval(function() {
        if (!document.hidden && !_sizeCalcPending) {
            refreshFilesSize();
        }
    }, 15000);
});
