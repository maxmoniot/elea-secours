/**
 * MoodleSecours - Main JavaScript
 * Gestion des quiz, H5P et interactions
 */

// =====================================================
// NAVIGATION
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    initSectionNavigation();
    initQuizInteractions();
    initOrderingDragDrop();
    initBookNavigation();
    initLessonNavigation();
});

/**
 * Navigation entre les sections
 */
function initSectionNavigation() {
    const sectionLinks = document.querySelectorAll('.section-link');
    const sections = document.querySelectorAll('.course-section');
    
    sectionLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href').substring(1);
            
            // Met à jour les liens
            sectionLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');
            
            // Met à jour les sections
            sections.forEach(s => s.classList.remove('active'));
            const targetSection = document.getElementById(targetId);
            if (targetSection) {
                targetSection.classList.add('active');
            }
            
            // Scroll en haut
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
}

// =====================================================
// QUIZ ENGINE
// =====================================================

/**
 * Initialise les interactions des quiz
 */
function initQuizInteractions() {
    // Sélection des réponses
    document.querySelectorAll('.answer-option input').forEach(input => {
        input.addEventListener('change', function() {
            const container = this.closest('.answers-multichoice, .answers-truefalse');
            const options = container.querySelectorAll('.answer-option');
            
            if (this.type === 'radio') {
                options.forEach(opt => opt.classList.remove('selected'));
            }
            
            this.closest('.answer-option').classList.toggle('selected', this.checked);
        });
    });
}

/**
 * Soumet un quiz et affiche les résultats
 */
function submitQuiz(quizId) {
    const quizContainer = document.getElementById(quizId);
    const questions = quizContainer.querySelectorAll('.quiz-question');
    const quizData = window.quizData[quizId] || [];
    
    let totalScore = 0;
    let maxScore = 0;
    let correctCount = 0;
    
    questions.forEach((questionEl, index) => {
        const qData = quizData[index];
        if (!qData) return;
        
        const qtype = questionEl.dataset.qtype;
        const maxMark = parseFloat(qData.maxmark) || 1;
        maxScore += maxMark;
        
        let score = 0;
        let isCorrect = false;
        let feedback = '';
        
        switch (qtype) {
            case 'multichoice':
                const result = evaluateMultichoice(questionEl, qData);
                score = result.score * maxMark;
                isCorrect = result.isCorrect;
                feedback = result.feedback;
                break;
                
            case 'truefalse':
                const tfResult = evaluateTruefalse(questionEl, qData);
                score = tfResult.score * maxMark;
                isCorrect = tfResult.isCorrect;
                break;
                
            case 'shortanswer':
                const saResult = evaluateShortanswer(questionEl, qData);
                score = saResult.score * maxMark;
                isCorrect = saResult.isCorrect;
                feedback = saResult.feedback;
                break;
                
            case 'numerical':
                const numResult = evaluateNumerical(questionEl, qData);
                score = numResult.score * maxMark;
                isCorrect = numResult.isCorrect;
                break;
                
            case 'match':
                const matchResult = evaluateMatch(questionEl, qData);
                score = matchResult.score * maxMark;
                isCorrect = matchResult.isCorrect;
                break;
                
            case 'gapselect':
            case 'ddwtos':
                const gapResult = evaluateGapselect(questionEl, qData);
                score = gapResult.score * maxMark;
                isCorrect = gapResult.isCorrect;
                break;
                
            case 'ordering':
                const orderResult = evaluateOrdering(questionEl, qData);
                score = orderResult.score * maxMark;
                isCorrect = orderResult.isCorrect;
                break;
                
            case 'essay':
                // Pas de correction automatique
                score = 0;
                feedback = 'Cette question nécessite une correction manuelle.';
                break;
        }
        
        totalScore += score;
        if (isCorrect) correctCount++;
        
        // Affiche le feedback
        showQuestionFeedback(questionEl, score, maxMark, isCorrect, feedback, qData);
    });
    
    // Affiche le score final
    const resultsEl = quizContainer.querySelector('.quiz-results');
    const scoreEl = quizContainer.querySelector('.quiz-score');
    
    const percentage = maxScore > 0 ? Math.round((totalScore / maxScore) * 100) : 0;
    
    let emoji = '😊';
    if (percentage >= 80) emoji = '🎉';
    else if (percentage >= 60) emoji = '👍';
    else if (percentage >= 40) emoji = '💪';
    else emoji = '📚';
    
    scoreEl.innerHTML = `
        ${emoji} Score : <strong>${totalScore.toFixed(1)} / ${maxScore.toFixed(1)}</strong> 
        (${percentage}%)
        <br>
        <span style="font-size: 0.875rem; font-weight: normal;">
            ${correctCount} réponse(s) correcte(s) sur ${questions.length}
        </span>
    `;
    
    resultsEl.style.display = 'block';
    resultsEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/**
 * Évalue une question à choix multiple
 */
function evaluateMultichoice(questionEl, qData) {
    const inputs = questionEl.querySelectorAll('.answer-option input');
    const single = qData.single;
    
    let score = 0;
    let isCorrect = true;
    let feedback = '';
    
    inputs.forEach(input => {
        const option = input.closest('.answer-option');
        const fraction = parseFloat(input.dataset.fraction);
        const isSelected = input.checked;
        const isRightAnswer = fraction > 0;
        
        if (isSelected) {
            score += fraction;
            if (fraction <= 0) {
                option.classList.add('wrong-answer');
                isCorrect = false;
            } else {
                option.classList.add('correct-answer');
            }
        } else if (isRightAnswer && !single) {
            // Réponse correcte non sélectionnée (QCM multiple)
            option.classList.add('correct-answer');
            isCorrect = false;
        }
    });
    
    // Normalise le score entre 0 et 1
    score = Math.max(0, Math.min(1, score));
    
    if (isCorrect && score >= 1) {
        feedback = qData.correct_feedback || 'Bonne réponse !';
    } else if (score > 0) {
        feedback = qData.partially_correct_feedback || 'Partiellement correct.';
        isCorrect = false;
    } else {
        feedback = qData.incorrect_feedback || 'Incorrect.';
        isCorrect = false;
    }
    
    return { score, isCorrect, feedback };
}

/**
 * Évalue une question vrai/faux
 */
function evaluateTruefalse(questionEl, qData) {
    const selected = questionEl.querySelector('.answer-option input:checked');
    
    if (!selected) {
        return { score: 0, isCorrect: false };
    }
    
    const fraction = parseFloat(selected.dataset.fraction);
    const isCorrect = fraction > 0;
    
    const option = selected.closest('.answer-option');
    option.classList.add(isCorrect ? 'correct-answer' : 'wrong-answer');
    
    // Montre la bonne réponse
    if (!isCorrect) {
        questionEl.querySelectorAll('.answer-option input').forEach(input => {
            if (parseFloat(input.dataset.fraction) > 0) {
                input.closest('.answer-option').classList.add('correct-answer');
            }
        });
    }
    
    return { score: isCorrect ? 1 : 0, isCorrect };
}

/**
 * Évalue une question réponse courte
 */
function evaluateShortanswer(questionEl, qData) {
    const input = questionEl.querySelector('.answer-input');
    const userAnswer = input.value.trim();
    const useCase = qData.use_case || false;
    
    let bestScore = 0;
    let feedback = '';
    
    for (const answer of qData.answers || []) {
        let expected = answer.text;
        let actual = userAnswer;
        
        if (!useCase) {
            expected = expected.toLowerCase();
            actual = actual.toLowerCase();
        }
        
        // Gère les wildcards simples (*)
        if (expected.includes('*')) {
            const regex = new RegExp('^' + expected.replace(/\*/g, '.*') + '$', useCase ? '' : 'i');
            if (regex.test(userAnswer)) {
                if (answer.fraction > bestScore) {
                    bestScore = answer.fraction;
                    feedback = answer.feedback || '';
                }
            }
        } else if (expected === actual) {
            if (answer.fraction > bestScore) {
                bestScore = answer.fraction;
                feedback = answer.feedback || '';
            }
        }
    }
    
    input.classList.add(bestScore > 0 ? 'correct-answer' : 'wrong-answer');
    input.style.borderColor = bestScore > 0 ? 'var(--secondary)' : 'var(--danger)';
    
    return { score: bestScore, isCorrect: bestScore >= 1, feedback };
}

/**
 * Évalue une question numérique
 */
function evaluateNumerical(questionEl, qData) {
    const input = questionEl.querySelector('.answer-input');
    const userAnswer = parseFloat(input.value);
    
    if (isNaN(userAnswer)) {
        return { score: 0, isCorrect: false };
    }
    
    let bestScore = 0;
    
    for (const answer of qData.answers || []) {
        const expected = parseFloat(answer.text);
        const tolerance = qData.tolerances?.[answer.id] || 0;
        
        if (Math.abs(userAnswer - expected) <= tolerance) {
            if (answer.fraction > bestScore) {
                bestScore = answer.fraction;
            }
        }
    }
    
    input.style.borderColor = bestScore > 0 ? 'var(--secondary)' : 'var(--danger)';
    
    return { score: bestScore, isCorrect: bestScore >= 1 };
}

/**
 * Évalue une question d'appariement
 */
function evaluateMatch(questionEl, qData) {
    const rows = questionEl.querySelectorAll('tr[data-match-id]');
    let correctCount = 0;
    const total = rows.length;
    
    rows.forEach(row => {
        const select = row.querySelector('select');
        const expected = select.dataset.correct;
        const actual = select.value;
        
        if (actual === expected) {
            correctCount++;
            select.style.borderColor = 'var(--secondary)';
            select.style.backgroundColor = 'rgb(16 185 129 / 0.1)';
        } else {
            select.style.borderColor = 'var(--danger)';
            select.style.backgroundColor = 'rgb(239 68 68 / 0.1)';
        }
    });
    
    const score = total > 0 ? correctCount / total : 0;
    return { score, isCorrect: score >= 1 };
}

/**
 * Évalue une question à trous
 */
function evaluateGapselect(questionEl, qData) {
    const selects = questionEl.querySelectorAll('.gap-select');
    const choices = qData.choices || [];
    
    let correctCount = 0;
    let total = selects.length;
    
    selects.forEach(select => {
        const gapNum = parseInt(select.dataset.gap);
        const userAnswer = select.value;
        
        // Trouve la bonne réponse pour ce gap
        const correctChoice = choices.find(c => c.group === gapNum);
        const isCorrect = correctChoice && userAnswer === correctChoice.text;
        
        if (isCorrect) {
            correctCount++;
            select.style.borderColor = 'var(--secondary)';
            select.style.backgroundColor = 'rgb(16 185 129 / 0.1)';
        } else {
            select.style.borderColor = 'var(--danger)';
            select.style.backgroundColor = 'rgb(239 68 68 / 0.1)';
        }
    });
    
    const score = total > 0 ? correctCount / total : 0;
    return { score, isCorrect: score >= 1 };
}

/**
 * Évalue une question d'ordonnancement
 */
function evaluateOrdering(questionEl, qData) {
    const container = questionEl.querySelector('.answers-ordering');
    const correctOrder = JSON.parse(container.dataset.correct || '[]');
    const items = container.querySelectorAll('.ordering-item');
    
    let correctCount = 0;
    
    items.forEach((item, index) => {
        const text = item.dataset.text;
        const isCorrect = correctOrder[index] === text;
        
        if (isCorrect) {
            correctCount++;
            item.style.borderColor = 'var(--secondary)';
            item.style.backgroundColor = 'rgb(16 185 129 / 0.1)';
        } else {
            item.style.borderColor = 'var(--danger)';
            item.style.backgroundColor = 'rgb(239 68 68 / 0.1)';
        }
    });
    
    const score = correctOrder.length > 0 ? correctCount / correctOrder.length : 0;
    return { score, isCorrect: score >= 1 };
}

/**
 * Affiche le feedback d'une question
 */
function showQuestionFeedback(questionEl, score, maxMark, isCorrect, feedback, qData) {
    // Met à jour le style de la question
    questionEl.classList.remove('correct', 'incorrect', 'partial');
    if (isCorrect) {
        questionEl.classList.add('correct');
    } else if (score > 0) {
        questionEl.classList.add('partial');
    } else {
        questionEl.classList.add('incorrect');
    }
    
    // Affiche le feedback
    let feedbackEl = questionEl.querySelector('.question-feedback');
    if (!feedbackEl) {
        feedbackEl = document.createElement('div');
        feedbackEl.className = 'question-feedback';
        questionEl.querySelector('.question-answers').after(feedbackEl);
    }
    
    let feedbackHtml = '';
    
    if (isCorrect) {
        feedbackHtml = `<div class="success">✓ Correct ! (+${score.toFixed(1)} pt)</div>`;
    } else if (score > 0) {
        feedbackHtml = `<div class="success">◐ Partiellement correct (+${score.toFixed(1)} pt)</div>`;
    } else {
        feedbackHtml = `<div class="error">✗ Incorrect (0 pt)</div>`;
    }
    
    if (feedback) {
        feedbackHtml += `<p style="margin-top: 0.5rem;">${feedback}</p>`;
    }
    
    // Ajoute le feedback général si disponible
    if (qData.general_feedback) {
        feedbackHtml += `<p style="margin-top: 0.5rem; color: var(--text-secondary);">${qData.general_feedback}</p>`;
    }
    
    feedbackEl.innerHTML = feedbackHtml;
    feedbackEl.style.display = 'block';
    feedbackEl.className = 'question-feedback ' + (isCorrect ? 'success' : (score > 0 ? 'success' : 'error'));
}

/**
 * Réinitialise un quiz
 */
function resetQuiz(quizId) {
    const quizContainer = document.getElementById(quizId);
    
    // Réinitialise les inputs
    quizContainer.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => {
        input.checked = false;
    });
    
    quizContainer.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(input => {
        input.value = '';
        input.style.borderColor = '';
        input.style.backgroundColor = '';
    });
    
    quizContainer.querySelectorAll('select').forEach(select => {
        select.selectedIndex = 0;
        select.style.borderColor = '';
        select.style.backgroundColor = '';
    });
    
    // Réinitialise les styles
    quizContainer.querySelectorAll('.answer-option').forEach(opt => {
        opt.classList.remove('selected', 'correct-answer', 'wrong-answer');
    });
    
    quizContainer.querySelectorAll('.quiz-question').forEach(q => {
        q.classList.remove('correct', 'incorrect', 'partial');
    });
    
    quizContainer.querySelectorAll('.question-feedback').forEach(fb => {
        fb.style.display = 'none';
    });
    
    quizContainer.querySelectorAll('.ordering-item').forEach(item => {
        item.style.borderColor = '';
        item.style.backgroundColor = '';
    });
    
    // Cache les résultats
    const resultsEl = quizContainer.querySelector('.quiz-results');
    if (resultsEl) {
        resultsEl.style.display = 'none';
    }
    
    // Scroll en haut du quiz
    quizContainer.scrollIntoView({ behavior: 'smooth' });
}

// =====================================================
// DRAG & DROP (Ordering)
// =====================================================

function initOrderingDragDrop() {
    document.querySelectorAll('.ordering-list').forEach(list => {
        let draggedItem = null;
        
        list.querySelectorAll('.ordering-item').forEach(item => {
            item.addEventListener('dragstart', function(e) {
                draggedItem = this;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            
            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                draggedItem = null;
            });
            
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });
            
            item.addEventListener('dragenter', function(e) {
                e.preventDefault();
                if (this !== draggedItem) {
                    this.style.borderTopColor = 'var(--primary)';
                }
            });
            
            item.addEventListener('dragleave', function() {
                this.style.borderTopColor = '';
            });
            
            item.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderTopColor = '';
                
                if (this !== draggedItem) {
                    const allItems = [...list.querySelectorAll('.ordering-item')];
                    const draggedIndex = allItems.indexOf(draggedItem);
                    const targetIndex = allItems.indexOf(this);
                    
                    if (draggedIndex < targetIndex) {
                        this.after(draggedItem);
                    } else {
                        this.before(draggedItem);
                    }
                }
            });
        });
    });
}

// =====================================================
// BOOK NAVIGATION
// =====================================================

function initBookNavigation() {
    // Géré par les onclick sur les boutons
}

function showChapter(btn, chapterId) {
    const book = btn.closest('.activity-book');
    
    // Met à jour les boutons
    book.querySelectorAll('.book-chapter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Met à jour les chapitres
    book.querySelectorAll('.book-chapter').forEach(c => c.classList.remove('active'));
    document.getElementById('chapter-' + chapterId).classList.add('active');
}

// =====================================================
// LESSON NAVIGATION
// =====================================================

function initLessonNavigation() {
    // Géré par les onclick
}

function nextLessonPage(btn) {
    navigateLessonPage(btn, 1);
}

function prevLessonPage(btn) {
    navigateLessonPage(btn, -1);
}

function navigateLessonPage(btn, direction) {
    const lesson = btn.closest('.activity-lesson');
    const pages = lesson.querySelectorAll('.lesson-page');
    const currentIndex = [...pages].findIndex(p => p.classList.contains('active'));
    const newIndex = currentIndex + direction;
    
    if (newIndex >= 0 && newIndex < pages.length) {
        pages.forEach(p => p.classList.remove('active'));
        pages[newIndex].classList.add('active');
        
        // Met à jour l'indicateur
        lesson.querySelector('.page-indicator .current').textContent = newIndex + 1;
    }
}

// =====================================================
// H5P INITIALIZATION
// =====================================================

/**
 * Initialise un contenu H5P (plugin HVP)
 */
function initH5PHvp(containerId, h5pData, filesMapping) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    // Pour l'instant, affiche un message
    // L'intégration complète H5P nécessite les bibliothèques
    container.innerHTML = `
        <div style="padding: 2rem; text-align: center; background: var(--gray-50); border-radius: var(--radius);">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🎮</div>
            <h4 style="margin-bottom: 0.5rem;">${h5pData.machineName}</h4>
            <p style="color: var(--text-secondary);">
                Contenu interactif H5P
            </p>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin-top: 1rem;">
                Les bibliothèques H5P seront chargées automatiquement.
            </p>
        </div>
    `;
    
    // TODO: Charger h5p-standalone et initialiser le contenu
}

/**
 * Initialise un contenu H5P Core
 */
function initH5PCore(containerId, h5pFileUrl) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = `
        <div style="padding: 2rem; text-align: center; background: var(--gray-50); border-radius: var(--radius);">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🎮</div>
            <p style="color: var(--text-secondary);">
                Chargement du contenu H5P...
            </p>
        </div>
    `;
    
    // TODO: Charger le fichier .h5p et l'initialiser
}

// =====================================================
// UPLOAD MODAL
// =====================================================

function openUploadModal() {
    document.getElementById('uploadModal').classList.add('active');
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
}

function openDriveModal() {
    document.getElementById('driveModal').classList.add('active');
}

function closeDriveModal() {
    document.getElementById('driveModal').classList.remove('active');
}

// Ferme les modales en cliquant à l'extérieur
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
    }
});

// =====================================================
// UPLOAD HANDLING
// =====================================================

function initUploadZone() {
    const zone = document.getElementById('uploadZone');
    const input = document.getElementById('fileInput');
    
    if (!zone || !input) return;
    
    zone.addEventListener('click', () => input.click());
    
    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        zone.classList.add('dragover');
    });
    
    zone.addEventListener('dragleave', () => {
        zone.classList.remove('dragover');
    });
    
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFileSelect(files[0]);
        }
    });
    
    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            handleFileSelect(input.files[0]);
        }
    });
}

function handleFileSelect(file) {
    if (!file.name.endsWith('.mbz')) {
        alert('Veuillez sélectionner un fichier .mbz');
        return;
    }
    
    // Limite 200 Mo
    if (file.size > 200 * 1024 * 1024) {
        alert('Le fichier est trop volumineux (' + (file.size / (1024*1024)).toFixed(1) + ' Mo). La limite est de 200 Mo.');
        return;
    }
    
    // Upload direct : afficher le modal loading et envoyer immédiatement
    document.getElementById('uploadModal').classList.add('active');
    
    processUploadDirect(file);
}

async function processUploadDirect(file) {
    const statusEl = document.getElementById('uploadStatusText');
    const errorEl = document.getElementById('uploadError');
    const errorTextEl = document.getElementById('uploadErrorText');
    const loadingEl = document.getElementById('uploadLoading');
    
    // Reset
    loadingEl.style.display = 'block';
    errorEl.style.display = 'none';
    
    // Créer une barre de progression si elle n'existe pas
    var progressBar = document.getElementById('uploadProgressBar');
    if (!progressBar) {
        var container = document.createElement('div');
        container.style.cssText = 'width:100%;background:#e5e7eb;border-radius:8px;height:12px;margin:0.5rem 0;overflow:hidden;';
        progressBar = document.createElement('div');
        progressBar.id = 'uploadProgressBar';
        progressBar.style.cssText = 'width:0%;height:100%;background:linear-gradient(135deg,#5b21b6,#7c3aed);border-radius:8px;transition:width 0.3s;';
        container.appendChild(progressBar);
        statusEl.parentNode.insertBefore(container, statusEl.nextSibling);
    }
    progressBar.style.width = '0%';
    
    statusEl.textContent = 'Envoi du fichier (' + (file.size / (1024*1024)).toFixed(1) + ' Mo)...';
    
    const formData = new FormData();
    formData.append('file', file);
    
    try {
        // Upload avec suivi de progression via XMLHttpRequest
        const result = await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload.php');
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 50); // 0-50% = envoi
                    progressBar.style.width = pct + '%';
                    statusEl.textContent = 'Envoi... ' + pct * 2 + '%';
                }
            };
            
            xhr.onload = function() {
                progressBar.style.width = '80%';
                statusEl.textContent = 'Analyse du cours...';
                try {
                    resolve(JSON.parse(xhr.responseText));
                } catch(e) {
                    reject(new Error('Réponse invalide du serveur'));
                }
            };
            
            xhr.onerror = function() { reject(new Error('Erreur réseau')); };
            xhr.ontimeout = function() { reject(new Error('Timeout')); };
            xhr.timeout = 300000; // 5 minutes
            xhr.send(formData);
        });
        
        if (result.success) {
            progressBar.style.width = '100%';
            statusEl.textContent = 'Cours prêt ! Ouverture...';
            
            // Redirection immédiate
            setTimeout(() => {
                window.location.href = result.url;
            }, 200);
        } else {
            loadingEl.style.display = 'none';
            errorEl.style.display = 'block';
            errorTextEl.textContent = 'Erreur : ' + result.error;
        }
    } catch (error) {
        loadingEl.style.display = 'none';
        errorEl.style.display = 'block';
        errorTextEl.textContent = 'Erreur de connexion : ' + error.message;
    }
}

// Ancienne fonction conservée pour compatibilité
async function processUpload() {
    const fileInput = document.getElementById('fileInput');
    if (!fileInput.files[0]) {
        alert('Veuillez sélectionner un fichier');
        return;
    }
    processUploadDirect(fileInput.files[0]);
}

// Ajoute dynamiquement un cours à la liste des cours temporaires
function addCourseToTempList(result) {
    const container = document.querySelector('.section-card-local .local-courses-grid');
    const emptyState = document.querySelector('.section-card-local .empty-state');
    
    // Si la liste était vide, on crée le conteneur
    if (!container) {
        const sectionBody = document.querySelector('.section-card-local .section-body');
        if (emptyState) {
            emptyState.remove();
        }
        const newGrid = document.createElement('div');
        newGrid.className = 'local-courses-grid';
        sectionBody.appendChild(newGrid);
        addCourseCard(newGrid, result);
    } else {
        // Cache l'état vide s'il existe
        if (emptyState) {
            emptyState.style.display = 'none';
        }
        addCourseCard(container, result);
    }
    
    // Met à jour le compteur
    const countEl = document.querySelector('.section-card-local .section-header p');
    if (countEl) {
        const currentCount = parseInt(countEl.textContent) || 0;
        countEl.textContent = (currentCount + 1) + ' cours uploadé(s)';
    }
}

function addCourseCard(container, result) {
    const card = document.createElement('div');
    card.className = 'local-course-card';
    
    const profId = result.prof_id || result.url.split('id=')[1];
    const courseName = result.course_name || profId;
    const viewUrl = 'view.php?id=' + encodeURIComponent(profId);
    
    // Calcule l'heure d'expiration (24h après maintenant)
    const expireDate = new Date(Date.now() + 24 * 60 * 60 * 1000);
    const expireStr = expireDate.toLocaleDateString('fr-FR', {day: '2-digit', month: '2-digit'}) + 
                      ' à ' + expireDate.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'});
    
    card.innerHTML = `
        <div class="local-course-icon" onclick="window.location.href='${viewUrl}'" style="cursor:pointer;">📖</div>
        <div class="local-course-info" onclick="window.location.href='${viewUrl}'" style="cursor:pointer;">
            <div class="local-course-name">${escapeHtml(courseName)}</div>
            <div class="local-course-meta">Expire ${expireStr}</div>
        </div>
        <span class="course-arrow" onclick="window.location.href='${viewUrl}'" style="cursor:pointer;">→</span>
    `;
    
    container.insertBefore(card, container.firstChild);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function copyUrl() {
    const input = document.getElementById('courseUrl');
    if (!input) return;
    input.select();
    document.execCommand('copy');
    
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '✓ Copié !';
    setTimeout(() => btn.textContent = originalText, 2000);
}

function resetUpload() {
    const loadingEl = document.getElementById('uploadLoading');
    const errorEl = document.getElementById('uploadError');
    if (loadingEl) loadingEl.style.display = 'block';
    if (errorEl) errorEl.style.display = 'none';
    const fileInput = document.getElementById('fileInput');
    if (fileInput) fileInput.value = '';
}

// Initialize upload zone on page load
document.addEventListener('DOMContentLoaded', initUploadZone);
