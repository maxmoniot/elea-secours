<?php
/**
 * EleaSecours - Course Renderer avec support H5P amélioré
 */

class CourseRenderer {
    private array $courseData;
    private string $basePath;
    private string $baseUrl;
    private bool $printMode = false;
    private ?array $fileIndex = null;
    
    public function __construct(array $courseData, string $basePath, string $baseUrl) {
        $this->courseData = $courseData;
        $this->basePath = $basePath;
        $this->baseUrl = $baseUrl;
        $this->fileIndex = $courseData['file_index'] ?? null;
    }
    
    public function setPrintMode(bool $mode): void {
        $this->printMode = $mode;
    }
    
    public function isPrintMode(): bool {
        return $this->printMode;
    }
    
    public function render(): string {
        $sections = $this->courseData['sections'] ?? [];
        
        ob_start();
        foreach ($sections as $index => $section):
            $sectionName = !empty($section['name']) ? $section['name'] : 'Section ' . ($section['number'] + 1);
        ?>
        <section id="section-<?= $section['id'] ?>" class="course-section <?= $index === 0 ? 'active' : '' ?>">
            <h2 class="section-title"><?= htmlspecialchars($sectionName) ?></h2>
            
            <?php if (!empty($section['summary'])): ?>
            <div class="section-summary"><?= $this->processContent($section['summary']) ?></div>
            <?php endif; ?>
            
            <div class="activities-list">
                <?php 
                $activities = $section['activities'] ?? [];
                if (empty($activities)): ?>
                <div class="empty-state">
                    <p>Aucune activité dans cette section</p>
                </div>
                <?php else:
                    foreach ($activities as $activity): ?>
                    <?= $this->renderActivity($activity) ?>
                    <?php endforeach;
                endif; ?>
            </div>
        </section>
        <?php endforeach;
        
        return ob_get_clean();
    }
    
    /**
     * Rend une seule activité (pour la navigation par activité)
     */
    public function renderSingleActivity(array $activity): string {
        $html = $this->renderActivity($activity);

        // Afficher les labels attachés sous l'activité
        if (!empty($activity['attached_labels'])) {
            foreach ($activity['attached_labels'] as $label) {
                $html .= $this->renderLabel($label);
            }
        }

        return $this->deferImages($html);
    }

    /**
     * Chargement différé des médias.
     * Le lecteur met TOUTES les activités du cours dans le DOM et n'en montre qu'une :
     * sans ça, ouvrir un cours téléchargeait d'un coup les images des activités masquées
     * (mesuré : 48 requêtes en une seconde pour un cours de 4 séances, aucune image à
     * l'écran). Cette rafale déclenchait l'anti-flood d'OVH sur l'hébergement mutualisé.
     * Le navigateur ne charge pas une image `lazy` dont un parent est masqué : elle part
     * quand l'élève ouvre l'activité.
     * Jamais en mode impression (PDF) : là, tout doit être chargé.
     */
    private function deferImages(string $html): string {
        if ($this->printMode || $html === '') {
            return $html;
        }
        // Ne JAMAIS toucher au contenu des <script> : le lecteur y sérialise du JSON
        // (window.quizData…) dont les guillemets sont échappés. Y injecter un attribut
        // cassait le script — « Unexpected identifier 'lazy' », et tout le quiz avec.
        $parts = preg_split('#(<script\b[^>]*>.*?</script>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) continue;   // indices impairs = blocs <script> capturés
            $parts[$i] = preg_replace(
                '/<img(?![^>]*\bloading\s*=)/i',
                '<img loading="lazy" decoding="async"',
                $part
            );
        }
        return implode('', $parts);
    }
    
    private function renderActivity(array $activity): string {
        $type = $activity['type'] ?? 'unknown';
        
        return match($type) {
            'hvp' => $this->renderHvp($activity),
            'h5pactivity' => $this->renderH5pCore($activity),
            'quiz' => $this->renderQuiz($activity),
            'page' => $this->renderPage($activity),
            'resource' => $this->renderResource($activity),
            'url' => $this->renderUrl($activity),
            'label' => $this->renderLabel($activity),
            'book' => $this->renderBook($activity),
            'folder' => $this->renderFolder($activity),
            'lesson' => $this->renderLesson($activity),
            'mapmodules' => $this->renderMapmodules($activity),
            'assign' => $this->renderAssign($activity),
            default => $this->renderUnsupported($activity),
        };
    }
    
    // ========== RENDU H5P ==========
    
    private function renderHvp(array $activity): string {
        $machineName = $activity['machine_name'] ?? '';
        $content = $activity['content'] ?? [];
        $files = $activity['files'] ?? [];
        
        ob_start();
        ?>
        <div class="activity activity-h5p">
            <div class="activity-header">
                <span class="activity-icon">🎮</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Activité H5P') ?></h3>
            </div>
            <?php if (!empty($activity['intro'])): ?>
            <div class="activity-intro"><?= $this->processContent($activity['intro']) ?></div>
            <?php endif; ?>
            
            <div class="h5p-content">
                <?php 
                // Si pas de contenu, affiche un message
                if (empty($content)) {
                    echo '<div class="h5p-placeholder"><p>⚠️ Contenu H5P non disponible</p></div>';
                } else {
                    echo $this->renderH5pContent($machineName, $content, $files);
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Modules Moodle « h5pactivity » : d'ordinaire un paquet .h5p opaque, qu'on ne sait pas jouer.
     * MAIS l'éditeur produit aussi ce type pour ses activités (previewEditorSession recopie
     * type = h5pactivity) : quand le contenu est là et que le machine_name est connu, on le rend
     * comme une activité hvp. Sans ça, l'aperçu d'un cours en création affichait le message
     * « Contenu H5P au format .h5p » pour TOUS les types (parcours compris).
     */
    private function renderH5pCore(array $activity): string {
        $machineName = $activity['machine_name'] ?? '';
        $content = $activity['content'] ?? [];
        $files = $activity['files'] ?? [];

        $rendered = '';
        if (is_array($content) && !empty($content)
            && strpos($machineName, 'H5P.') === 0 && $machineName !== 'H5P.Unknown') {
            $rendered = $this->renderH5pContent($machineName, $content, $files);
        }

        ob_start();
        ?>
        <div class="activity activity-h5p">
            <div class="activity-header">
                <span class="activity-icon">🎮</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Activité H5P') ?></h3>
            </div>
            <?php if (!empty($activity['intro'])): ?>
            <div class="activity-intro"><?= $this->processContent($activity['intro']) ?></div>
            <?php endif; ?>

            <div class="h5p-content">
                <?php if ($rendered !== ''): ?>
                <?= $rendered ?>
                <?php else: ?>
                <div class="h5p-placeholder">
                    <p>⚠️ Contenu H5P au format .h5p</p>
                    <small>Ce type de contenu nécessite le lecteur H5P complet</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pContent(string $machineName, array $content, array $files): string {
        // Routing vers le bon renderer selon le type H5P
        $rendered = match($machineName) {
            'H5P.MultiChoice' => $this->renderH5pMultiChoice($content, $files),
            'H5P.TrueFalse' => $this->renderH5pTrueFalse($content, $files),
            'H5P.Blanks' => $this->renderH5pBlanks($content, $files),
            'H5P.DragText' => $this->renderH5pDragText($content, $files),
            'H5P.FindTheWords' => $this->renderH5pFindTheWords($content, $files),
            'H5P.DragQuestion' => $this->renderH5pDragQuestion($content, $files),
            'H5P.MarkTheWords' => $this->renderH5pMarkTheWords($content, $files),
            'H5P.Accordion' => $this->renderH5pAccordion($content, $files),
            'H5P.Column' => $this->renderH5pColumn($content, $files),
            'H5P.SingleChoiceSet' => $this->renderH5pSingleChoiceSet($content, $files),
            'H5P.QuestionSet' => $this->renderH5pQuestionSet($content, $files),
            'H5P.Flashcards' => $this->renderH5pFlashcards($content, $files),
            'H5P.DialogCards' => $this->renderH5pDialogCards($content, $files),
            'H5P.Dialogcards' => $this->renderH5pDialogCards($content, $files),
            'H5P.MemoryGame' => $this->renderH5pMemoryGame($content, $files),
            'H5P.CoursePresentation' => $this->renderH5pCoursePresentation($content, $files),
            'H5P.InteractiveVideo' => $this->renderH5pInteractiveVideo($content, $files),
            'H5P.ImageHotspots' => $this->renderH5pImageHotspots($content, $files),
            'H5P.ImageMultipleHotspotQuestion' => $this->renderH5pMultiHotspot($content, $files),
            'H5P.Summary' => $this->renderH5pSummary($content, $files),
            'H5P.GameMap' => $this->renderH5pGameMap($content, $files),
            'H5P.ImageSequencing' => $this->renderH5pImageSequencing($content, $files),
            'H5P.ThreeSixty' => $this->renderH5pVirtualTour($content, $files),
            'H5P.VirtualTour' => $this->renderH5pVirtualTour($content, $files),
            'H5P.ThreeImage' => $this->renderH5pVirtualTour($content, $files),
            'H5P.AdvancedText' => $this->renderH5pAdvancedText($content, $files),
            'H5P.ExportableTextArea' => $this->renderH5pExportableTextArea($content, $files),
            'H5P.MultiMediaChoice' => $this->renderH5pMultiMediaChoice($content, $files),
            'H5P.Video' => $this->renderH5pVideo($content, $files),
            'H5P.Audio' => $this->renderH5pAudio($content, $files),
            default => $this->renderH5pGeneric($machineName, $content, $files),
        };
        
        return $rendered ?: ''; // Ne pas afficher de placeholder pour les types non supportés
    }
    
    private function renderH5pMultiChoice(array $content, array $files): string {
        $question = $content['question'] ?? '';
        $answers = $content['answers'] ?? [];
        $id = 'h5p-mc-' . uniqid();
        $behaviour = $content['behaviour'] ?? [];
        
        // Compter le nombre de bonnes réponses pour déterminer si c'est un choix multiple ou unique
        $correctCount = 0;
        foreach ($answers as $answer) {
            if ($answer['correct'] ?? false) {
                $correctCount++;
            }
        }
        // Utiliser checkbox si plusieurs bonnes réponses, sinon radio
        $inputType = $correctCount > 1 ? 'checkbox' : 'radio';
        
        if (empty($answers)) {
            return '<div class="h5p-placeholder"><p>QCM sans réponses définies</p></div>';
        }
        
        // Nettoyer le texte de la question
        $questionClean = strip_tags($question);
        
        ob_start();
        ?>
        <div class="h5p-multichoice h5p-quiz-modern" id="<?= $id ?>">
            <div class="h5p-quiz-question"><?= $this->processH5pText($question, $files) ?></div>
            <div class="h5p-quiz-answers">
                <?php foreach ($answers as $i => $answer): 
                    $isCorrect = $answer['correct'] ?? false;
                    $text = $answer['text'] ?? '';
                    $textClean = strip_tags($text);
                ?>
                <label class="h5p-quiz-answer <?= $isCorrect ? 'correct' : '' ?>" data-correct="<?= $isCorrect ? '1' : '0' ?>">
                    <span class="h5p-quiz-marker"></span>
                    <input type="<?= $inputType ?>" name="<?= $id ?>" value="<?= $i ?>" style="display:none;">
                    <span class="h5p-quiz-answer-text"><?= htmlspecialchars($textClean) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="h5p-quiz-btn-container">
                <button class="h5p-quiz-verify-btn" onclick="checkH5pMultiChoice('<?= $id ?>')">Vérifier</button>
            </div>
            <div class="h5p-quiz-spacer"></div>
            <div class="h5p-feedback" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pTrueFalse(array $content, array $files): string {
        $question = $content['question'] ?? '';
        $correct = $content['correct'] ?? 'true';
        $id = 'h5p-tf-' . uniqid();
        
        ob_start();
        ?>
        <div class="h5p-truefalse h5p-quiz-modern" id="<?= $id ?>" data-correct="<?= $correct ?>">
            <div class="h5p-quiz-question"><?= $this->processH5pText($question, $files) ?></div>
            <div class="h5p-quiz-tf-answers">
                <label class="h5p-quiz-tf-answer <?= $correct === 'true' || $correct === true ? 'correct' : '' ?>" data-value="true">
                    <input type="radio" name="<?= $id ?>" value="true" style="display:none;">
                    <span>Vrai</span>
                </label>
                <label class="h5p-quiz-tf-answer <?= $correct === 'false' || $correct === false ? 'correct' : '' ?>" data-value="false">
                    <input type="radio" name="<?= $id ?>" value="false" style="display:none;">
                    <span>Faux</span>
                </label>
            </div>
            <div class="h5p-quiz-spacer"></div>
            <div class="h5p-feedback" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pMultiMediaChoice(array $content, array $files): string {
        $question = $content['question'] ?? '';
        $options = $content['options'] ?? [];
        $behaviour = $content['behaviour'] ?? [];
        $id = 'h5p-mmc-' . uniqid();
        
        $maxPerRow = (int)($behaviour['maxAlternativesPerRow'] ?? 4);
        if ($maxPerRow < 1) $maxPerRow = 4;
        
        // Compter les bonnes réponses pour déterminer checkbox vs radio
        $correctCount = 0;
        foreach ($options as $opt) {
            if ($opt['correct'] ?? false) $correctCount++;
        }
        $inputType = $correctCount > 1 ? 'checkbox' : 'radio';
        
        if (empty($options)) {
            return '<div class="h5p-placeholder"><p>Choix multimédia sans options définies</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-multimediachoice h5p-quiz-modern" id="<?= $id ?>" data-input-type="<?= $inputType ?>">
            <div class="h5p-quiz-question"><?= $this->processH5pText($question, $files) ?></div>
            <div class="h5p-mmc-grid" style="grid-template-columns: repeat(<?= $maxPerRow ?>, 1fr);">
                <?php foreach ($options as $i => $opt):
                    $isCorrect = $opt['correct'] ?? false;
                    $media = $opt['media'] ?? [];
                    $imgPath = $media['params']['file']['path'] ?? '';
                    $imgUrl = $imgPath ? $this->getH5pFileUrl($imgPath, $files) : '';
                ?>
                <div class="h5p-mmc-option" data-index="<?= $i ?>" data-correct="<?= $isCorrect ? '1' : '0' ?>" onclick="toggleMmcOption(this)">
                    <input type="<?= $inputType ?>" name="<?= $id ?>" value="<?= $i ?>" style="display:none;">
                    <?php if ($imgUrl): ?>
                    <img src="<?= htmlspecialchars($imgUrl) ?>" alt="Option <?= $i + 1 ?>" draggable="false">
                    <?php else: ?>
                    <div class="h5p-mmc-no-image">Option <?= $i + 1 ?></div>
                    <?php endif; ?>
                    <div class="h5p-mmc-check-indicator"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="h5p-quiz-btn-container">
                <button class="h5p-quiz-verify-btn" onclick="checkH5pMultiMediaChoice('<?= $id ?>')">Vérifier</button>
            </div>
            <div class="h5p-quiz-spacer"></div>
            <div class="h5p-feedback" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pBlanks(array $content, array $files): string {
        // Pour H5P.Blanks, le texte à trous est TOUJOURS dans 'questions', pas dans 'text'
        // Le champ 'text' ne contient que le titre/instruction
        $questions = [];
        $title = '';
        
        // Récupère le titre s'il existe
        if (!empty($content['text'])) {
            $title = strip_tags($content['text']);
        }
        
        // Cherche dans questions (format standard H5P.Blanks)
        if (isset($content['questions']) && is_array($content['questions'])) {
            foreach ($content['questions'] as $q) {
                $qText = is_string($q) ? $q : ($q['text'] ?? '');
                if (!empty($qText)) {
                    $questions[] = $qText;
                }
            }
        }
        
        // Fallback sur 'text' si pas de questions
        if (empty($questions) && !empty($title)) {
            $questions[] = $title;
            $title = 'Texte à trous';
        }
        
        $id = 'h5p-blanks-' . uniqid();
        
        if (empty($questions)) {
            return '<div class="h5p-placeholder"><p>Texte à trous sans contenu</p></div>';
        }
        
        $blanksData = [];
        $processedQuestions = [];
        
        foreach ($questions as $text) {
            // Décoder les entités HTML
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = strip_tags($text);
            
            $processed = preg_replace_callback('/\*([^*]+)\*/', function($m) use (&$blanksData) {
                $idx = count($blanksData);
                $answers = array_map('trim', explode('/', $m[1]));
                $blanksData[] = $answers;
                $width = max(100, min(200, strlen($answers[0]) * 14));
                return '<input type="text" class="h5p-quiz-blank-input" data-idx="' . $idx . '" style="width:' . $width . 'px;" placeholder="...">';
            }, $text);
            
            $processedQuestions[] = $processed;
        }
        
        ob_start();
        ?>
        <div class="h5p-blanks h5p-quiz-modern" id="<?= $id ?>">
            <?php if (!empty($title)): ?>
            <div class="h5p-quiz-blanks-title"><?= htmlspecialchars($title) ?></div>
            <?php endif; ?>
            <div class="h5p-quiz-blanks-text">
                <?php foreach ($processedQuestions as $pq): ?>
                <div class="h5p-quiz-blanks-line"><?= $pq ?></div>
                <?php endforeach; ?>
            </div>
            <div class="h5p-quiz-btn-container">
                <button class="h5p-quiz-verify-btn" onclick="checkH5pBlanks('<?= $id ?>', <?= htmlspecialchars(json_encode($blanksData), ENT_QUOTES) ?>)">Vérifier</button>
            </div>
            <div class="h5p-quiz-spacer"></div>
            <div class="h5p-feedback" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pDragText(array $content, array $files): string {
        $text = $content['textField'] ?? '';
        $id = 'h5p-dragtext-' . uniqid();
        
        if (empty($text)) {
            return '<div class="h5p-placeholder"><p>Glisser-déposer sans contenu</p></div>';
        }
        
        $words = [];
        $processedText = preg_replace_callback('/\*([^*]+)\*/', function($m) use (&$words) {
            $idx = count($words);
            $words[] = trim($m[1]);
            return '<span class="h5p-drop-zone" data-idx="' . $idx . '"></span>';
        }, $text);
        
        shuffle($words);
        
        ob_start();
        ?>
        <div class="h5p-dragtext" id="<?= $id ?>">
            <div class="h5p-dragtext-text"><?= $this->processH5pText($processedText, $files) ?></div>
            <div class="h5p-draggables">
                <?php foreach ($words as $word): ?>
                <span class="h5p-draggable" draggable="true"><?= htmlspecialchars($word) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="h5p-actions">
                <button class="btn btn-secondary btn-sm" onclick="resetH5pDragText('<?= $id ?>')">Réessayer</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pFindTheWords(array $content, array $files): string {
        $wordListStr = $content['wordList'] ?? '';
        $taskDesc = $content['taskDescription'] ?? 'Retrouvez les mots dans la grille';
        $id = 'h5p-ftw-' . uniqid();
        
        if (empty($wordListStr)) {
            return '<div class="h5p-placeholder"><p>Mots mêlés sans contenu</p></div>';
        }
        
        // Parse les mots
        $words = array_filter(array_map('trim', explode(',', $wordListStr)));
        $words = array_map('mb_strtoupper', $words);
        
        // Calculer la taille de la grille
        $maxLen = max(array_map('mb_strlen', $words));
        $gridSize = max($maxLen + 2, (int)ceil(sqrt(count($words) * $maxLen * 1.5)));
        $gridSize = max($gridSize, 8);
        $gridSize = min($gridSize, 16);
        
        // Initialiser la grille vide
        $grid = array_fill(0, $gridSize, array_fill(0, $gridSize, ''));
        $placements = [];
        
        // Directions : [dr, dc]
        $directions = [[0,1],[0,-1],[1,0],[-1,0],[1,1],[1,-1],[-1,1],[-1,-1]];
        
        // Placer les mots
        foreach ($words as $word) {
            $placed = false;
            $wordLen = mb_strlen($word);
            $letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            $shuffledDirs = $directions;
            shuffle($shuffledDirs);
            
            for ($attempt = 0; $attempt < 200 && !$placed; $attempt++) {
                $dir = $shuffledDirs[$attempt % count($shuffledDirs)];
                $r = rand(0, $gridSize - 1);
                $c = rand(0, $gridSize - 1);
                $endR = $r + $dir[0] * ($wordLen - 1);
                $endC = $c + $dir[1] * ($wordLen - 1);
                if ($endR < 0 || $endR >= $gridSize || $endC < 0 || $endC >= $gridSize) continue;
                
                $ok = true;
                for ($i = 0; $i < $wordLen; $i++) {
                    $cr = $r + $dir[0] * $i;
                    $cc = $c + $dir[1] * $i;
                    if ($grid[$cr][$cc] !== '' && $grid[$cr][$cc] !== $letters[$i]) { $ok = false; break; }
                }
                if ($ok) {
                    $cells = [];
                    for ($i = 0; $i < $wordLen; $i++) {
                        $cr = $r + $dir[0] * $i;
                        $cc = $c + $dir[1] * $i;
                        $grid[$cr][$cc] = $letters[$i];
                        $cells[] = [$cr, $cc];
                    }
                    $placements[] = ['word' => $word, 'cells' => $cells];
                    $placed = true;
                }
            }
        }
        
        // Remplir les cellules vides
        $fillPool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($r = 0; $r < $gridSize; $r++) {
            for ($c = 0; $c < $gridSize; $c++) {
                if ($grid[$r][$c] === '') {
                    $grid[$r][$c] = $fillPool[rand(0, strlen($fillPool) - 1)];
                }
            }
        }
        
        $placementsJson = json_encode($placements, JSON_UNESCAPED_UNICODE);
        $wordsJson = json_encode($words, JSON_UNESCAPED_UNICODE);
        $totalWords = count($words);
        
        ob_start();
        ?>
        <div class="h5p-findthewords" id="<?= $id ?>" style="max-width:700px;">
            <p style="margin-bottom:0.75rem;color:var(--text-secondary);font-size:0.9rem;"><?= htmlspecialchars($taskDesc) ?></p>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;overflow-x:auto;">
                    <table class="ftw-grid" style="border-collapse:collapse;user-select:none;cursor:pointer;">
                        <?php for ($r = 0; $r < $gridSize; $r++): ?>
                        <tr>
                            <?php for ($c = 0; $c < $gridSize; $c++): ?>
                            <td data-r="<?= $r ?>" data-c="<?= $c ?>" style="width:2.2rem;height:2.2rem;text-align:center;font-weight:bold;font-size:1.05rem;border:1px solid #ddd;background:#fff;transition:background 0.15s;"><?= htmlspecialchars($grid[$r][$c]) ?></td>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </table>
                </div>
                <div style="min-width:130px;">
                    <div style="font-weight:bold;margin-bottom:0.5rem;font-size:0.85rem;">📋 Retrouvez les mots</div>
                    <ul style="list-style:none;padding:0;margin:0;font-size:0.85rem;">
                        <?php foreach ($words as $w): ?>
                        <li class="ftw-word" data-word="<?= htmlspecialchars($w) ?>" style="padding:0.15rem 0;color:#333;"><?= htmlspecialchars(mb_strtolower($w)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.75rem;font-size:0.8rem;color:var(--text-secondary);">
                <span>⏱ Temps passé : <span class="ftw-timer">0:00</span></span>
                <span class="ftw-score">0 of <?= $totalWords ?> trouvés</span>
            </div>
            <div class="h5p-actions" style="margin-top:0.5rem;">
                <button class="btn btn-primary btn-sm" onclick="ftwCheck('<?= $id ?>')">✓ Vérifier</button>
                <button class="btn btn-secondary btn-sm" onclick="ftwReset('<?= $id ?>')">↻ Recommencer</button>
            </div>
        </div>
        <script>
        (function(){
            const id='<?= $id ?>',el=document.getElementById(id),placements=<?= $placementsJson ?>,allWords=<?= $wordsJson ?>;
            const grid=el.querySelector('.ftw-grid');
            let selecting=false,startCell=null,currentCells=[],foundWords=new Set(),timerSec=0,timerInt=null;
            timerInt=setInterval(()=>{timerSec++;const m=Math.floor(timerSec/60),s=timerSec%60;el.querySelector('.ftw-timer').textContent=m+':'+(s<10?'0':'')+s;},1000);
            function getCells(r1,c1,r2,c2){const cells=[],dr=Math.sign(r2-r1),dc=Math.sign(c2-c1),len=Math.max(Math.abs(r2-r1),Math.abs(c2-c1))+1;if(r1===r2||c1===c2||Math.abs(r2-r1)===Math.abs(c2-c1)){for(let i=0;i<len;i++)cells.push([r1+dr*i,c1+dc*i]);}return cells;}
            function hl(cells,color){cells.forEach(([r,c])=>{const td=grid.querySelector(`td[data-r="${r}"][data-c="${c}"]`);if(td)td.style.background=color;});}
            function clearSel(){currentCells.forEach(([r,c])=>{const t=grid.querySelector(`td[data-r="${r}"][data-c="${c}"]`);if(t&&!t.classList.contains('ff'))t.style.background='#fff';});}
            function startSel(td){selecting=true;startCell=[+td.dataset.r,+td.dataset.c];hl([startCell],'#b3d4fc');currentCells=[startCell];}
            function moveSel(td){if(!selecting)return;const endCell=[+td.dataset.r,+td.dataset.c];clearSel();currentCells=getCells(startCell[0],startCell[1],endCell[0],endCell[1]);hl(currentCells,'#b3d4fc');}
            function endSel(){if(!selecting)return;selecting=false;
                let word=currentCells.map(([r,c])=>{const td=grid.querySelector(`td[data-r="${r}"][data-c="${c}"]`);return td?td.textContent.trim():'';}).join('');
                let found=false;
                placements.forEach(p=>{if(foundWords.has(p.word))return;
                    const pw=p.cells.map(([r,c])=>{const td=grid.querySelector(`td[data-r="${r}"][data-c="${c}"]`);return td?td.textContent.trim():'';}).join('');
                    if(word===pw||word===pw.split('').reverse().join('')){found=true;foundWords.add(p.word);
                        p.cells.forEach(([r,c])=>{const td=grid.querySelector(`td[data-r="${r}"][data-c="${c}"]`);if(td){td.style.background='#c8e6c9';td.classList.add('ff');}});
                        const li=el.querySelector(`.ftw-word[data-word="${p.word}"]`);if(li)li.style.textDecoration='line-through';}});
                if(!found)clearSel();
                el.querySelector('.ftw-score').textContent=foundWords.size+' of '+allWords.length+' trouvés';
                if(foundWords.size===allWords.length){clearInterval(timerInt);el.querySelector('.ftw-score').style.color='#2e7d32';el.querySelector('.ftw-score').style.fontWeight='bold';}
            }
            grid.addEventListener('mousedown',e=>{const td=e.target.closest('td');if(td){startSel(td);e.preventDefault();}});
            grid.addEventListener('mousemove',e=>{const td=e.target.closest('td');if(td)moveSel(td);});
            grid.addEventListener('mouseup',()=>endSel());
            grid.addEventListener('touchstart',e=>{const t=e.touches[0],td=document.elementFromPoint(t.clientX,t.clientY)?.closest('td');if(td){startSel(td);e.preventDefault();}},{passive:false});
            grid.addEventListener('touchmove',e=>{const t=e.touches[0],td=document.elementFromPoint(t.clientX,t.clientY)?.closest('td');if(td)moveSel(td);e.preventDefault();},{passive:false});
            grid.addEventListener('touchend',()=>endSel());
            window['ftwChk_'+id]=function(){placements.forEach(p=>{if(!foundWords.has(p.word)){p.cells.forEach(([r,c])=>{const td=grid.querySelector(`td[data-r="${r}"][data-c="${c}"]`);if(td){td.style.background='#ffecb3';td.classList.add('ff');}});const li=el.querySelector(`.ftw-word[data-word="${p.word}"]`);if(li){li.style.textDecoration='line-through';li.style.color='#e65100';}}});clearInterval(timerInt);};
            window['ftwRst_'+id]=function(){foundWords.clear();grid.querySelectorAll('td').forEach(td=>{td.style.background='#fff';td.classList.remove('ff');});el.querySelectorAll('.ftw-word').forEach(li=>{li.style.textDecoration='none';li.style.color='#333';});el.querySelector('.ftw-score').textContent='0 of '+allWords.length+' trouvés';el.querySelector('.ftw-score').style.color='';el.querySelector('.ftw-score').style.fontWeight='';timerSec=0;clearInterval(timerInt);timerInt=setInterval(()=>{timerSec++;const m=Math.floor(timerSec/60),s=timerSec%60;el.querySelector('.ftw-timer').textContent=m+':'+(s<10?'0':'')+s;},1000);};
        })();
        function ftwCheck(id){window['ftwChk_'+id]();}
        function ftwReset(id){window['ftwRst_'+id]();}
        </script>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pMarkTheWords(array $content, array $files): string {
        $text = $content['textField'] ?? $content['taskDescription'] ?? '';
        $id = 'h5p-markwords-' . uniqid();
        
        if (empty($text)) {
            return '<div class="h5p-placeholder"><p>Marquer les mots sans contenu</p></div>';
        }
        
        $processedText = preg_replace_callback('/\*([^*]+)\*/', function($m) {
            return '<span class="h5p-markable" data-correct="1">' . htmlspecialchars($m[1]) . '</span>';
        }, $text);
        
        ob_start();
        ?>
        <div class="h5p-markwords" id="<?= $id ?>">
            <p style="margin-bottom:0.5rem;color:var(--text-secondary);">Cliquez sur les mots corrects :</p>
            <div class="h5p-markwords-text"><?= $processedText ?></div>
            <div class="h5p-actions">
                <button class="btn btn-primary btn-sm" onclick="checkH5pMarkWords('<?= $id ?>')">Vérifier</button>
                <button class="btn btn-secondary btn-sm" onclick="resetH5pMarkWords('<?= $id ?>')">Réessayer</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pAccordion(array $content, array $files): string {
        $panels = $content['panels'] ?? [];
        $id = 'h5p-accordion-' . uniqid();
        
        if (empty($panels)) {
            return '<div class="h5p-placeholder"><p>Accordéon sans panneaux</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-accordion" id="<?= $id ?>">
            <?php foreach ($panels as $i => $panel): ?>
            <div class="h5p-accordion-panel">
                <button class="h5p-accordion-header" onclick="toggleAccordion(this)">
                    <span><?= htmlspecialchars($panel['title'] ?? 'Section ' . ($i+1)) ?></span>
                    <span class="h5p-accordion-icon">▼</span>
                </button>
                <div class="h5p-accordion-content">
                    <?= $this->processH5pText($panel['content'] ?? '', $files) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pColumn(array $content, array $files): string {
        $items = $content['content'] ?? [];
        
        if (empty($items)) {
            return '<div class="h5p-placeholder"><p>Colonne sans contenu</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-column">
            <?php foreach ($items as $item): 
                $lib = $item['content']['library'] ?? '';
                $params = $item['content']['params'] ?? [];
                $machineName = explode(' ', $lib)[0] ?? '';
            ?>
            <div class="h5p-column-item">
                <?= $this->renderH5pContent($machineName, $params, $files) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pSingleChoiceSet(array $content, array $files): string {
        $questions = $content['choices'] ?? [];
        $id = 'h5p-scs-' . uniqid();
        $total = count($questions);
        
        if (empty($questions)) {
            return '<div class="h5p-placeholder"><p>QCM en série sans questions</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-singlechoiceset h5p-quiz-modern" id="<?= $id ?>" data-total="<?= $total ?>">
            <?php foreach ($questions as $qi => $q): 
                $questionText = strip_tags($q['question'] ?? '');
                $answers = $q['answers'] ?? ['Vrai', 'Faux'];
            ?>
            <div class="h5p-scs-question" data-idx="<?= $qi ?>" <?= $qi > 0 ? 'style="display:none;"' : '' ?>>
                <div class="h5p-quiz-question"><?= htmlspecialchars($questionText) ?></div>
                <div class="h5p-quiz-tf-answers">
                    <?php foreach ($answers as $ai => $answer): 
                        $answerText = strip_tags($answer);
                    ?>
                    <button class="h5p-quiz-tf-answer <?= $ai === 0 ? 'correct' : '' ?>" 
                            data-correct="<?= $ai === 0 ? '1' : '0' ?>" 
                            onclick="selectScsAnswer(this, '<?= $id ?>', <?= $qi ?>, <?= $total ?>)">
                        <?= htmlspecialchars($answerText) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if ($total > 1): ?>
            <div class="h5p-quiz-nav-indicator">
                <span class="h5p-quiz-nav-info" id="<?= $id ?>-nav">Question 1 / <?= $total ?></span>
            </div>
            <?php endif; ?>
            
            <div class="h5p-quiz-spacer"></div>
            
            <div class="h5p-scs-results" style="display:none;">
                <div class="h5p-scs-score"></div>
                <button class="h5p-quiz-verify-btn" onclick="resetScs('<?= $id ?>')">Recommencer</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pQuestionSet(array $content, array $files): string {
        $questions = $content['questions'] ?? [];
        $introPage = $content['introPage'] ?? [];
        $passPercentage = $content['passPercentage'] ?? 50;
        $id = 'h5p-qs-' . uniqid();
        $total = count($questions);
        
        if (empty($questions)) {
            return '<div class="h5p-placeholder"><p>Série de questions sans contenu</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-questionset" id="<?= $id ?>" data-total="<?= $total ?>" data-pass="<?= $passPercentage ?>">
            <?php if (!empty($introPage['introduction'])): ?>
            <div class="h5p-qs-intro"><?= $this->processH5pText($introPage['introduction'], $files) ?></div>
            <?php endif; ?>
            
            <!-- Questions (une seule visible à la fois) -->
            <?php foreach ($questions as $qi => $q):
                $lib = $q['library'] ?? '';
                $params = $q['params'] ?? [];
                $machineName = explode(' ', $lib)[0] ?? '';
            ?>
            <div class="h5p-qs-question" data-idx="<?= $qi ?>" style="<?= $qi > 0 ? 'display:none;' : '' ?>">
                <?= $this->renderH5pContent($machineName, $params, $files) ?>
            </div>
            <?php endforeach; ?>
            
            <!-- Barre de progression -->
            <div class="h5p-qs-progressbar">
                <?php for ($i = 0; $i < $total; $i++): ?>
                <div class="h5p-qs-dot<?= $i === 0 ? ' active' : '' ?>" data-idx="<?= $i ?>" onclick="goToQsQuestion('<?= $id ?>', <?= $i ?>)"></div>
                <?php endfor; ?>
            </div>
            
            <!-- Navigation -->
            <div class="h5p-qs-nav">
                <button class="h5p-qs-btn h5p-qs-prev" onclick="navigateQs('<?= $id ?>', -1)" style="visibility:hidden;">← Précédent</button>
                <span class="h5p-qs-progress">Question 1 / <?= $total ?></span>
                <button class="h5p-qs-btn h5p-qs-next" onclick="navigateQs('<?= $id ?>', 1)">Suivant →</button>
            </div>
            
            <!-- Résultat final (caché par défaut) -->
            <div class="h5p-qs-result" style="display:none;">
                <div class="h5p-qs-result-content">
                    <div class="h5p-qs-score"></div>
                    <div class="h5p-qs-message"></div>
                    <button class="h5p-qs-btn" onclick="restartQs('<?= $id ?>')">🔄 Recommencer</button>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var id = '<?= $id ?>';
            var total = <?= $total ?>;
            var current = 0;
            
            window['qsState_' + id] = { current: 0 };
            
            window.navigateQs = function(qsId, dir) {
                if (qsId !== id) return;
                var state = window['qsState_' + id];
                var newIdx = state.current + dir;
                if (newIdx < 0 || newIdx >= total) return;
                goToQsQuestion(id, newIdx);
            };
            
            window.goToQsQuestion = function(qsId, idx) {
                if (qsId !== id) return;
                var state = window['qsState_' + id];
                var container = document.getElementById(id);
                
                // Masquer la question actuelle
                var currentQ = container.querySelector('.h5p-qs-question[data-idx="' + state.current + '"]');
                if (currentQ) currentQ.style.display = 'none';
                
                // Afficher la nouvelle question
                var newQ = container.querySelector('.h5p-qs-question[data-idx="' + idx + '"]');
                if (newQ) newQ.style.display = 'block';
                
                state.current = idx;
                
                // Mettre à jour les dots
                container.querySelectorAll('.h5p-qs-dot').forEach(function(dot, i) {
                    dot.classList.toggle('active', i === idx);
                    if (i < idx) dot.classList.add('done');
                });
                
                // Mettre à jour la navigation
                var prevBtn = container.querySelector('.h5p-qs-prev');
                var nextBtn = container.querySelector('.h5p-qs-next');
                var progress = container.querySelector('.h5p-qs-progress');
                
                prevBtn.style.visibility = idx === 0 ? 'hidden' : 'visible';
                nextBtn.textContent = idx === total - 1 ? 'Terminer ✓' : 'Suivant →';
                progress.textContent = 'Question ' + (idx + 1) + ' / ' + total;
                
                if (idx === total - 1) {
                    nextBtn.onclick = function() { showQsResult(id); };
                } else {
                    nextBtn.onclick = function() { navigateQs(id, 1); };
                }
            };
            
            window.showQsResult = function(qsId) {
                if (qsId !== id) return;
                var container = document.getElementById(id);
                
                // Compter les bonnes réponses
                var correct = 0;
                container.querySelectorAll('.h5p-qs-question').forEach(function(q) {
                    // Vérifier si la question a été correctement répondue
                    var correctAnswers = q.querySelectorAll('.answer-correct');
                    var wrongAnswers = q.querySelectorAll('.answer-wrong');
                    if (correctAnswers.length > 0 && wrongAnswers.length === 0) {
                        correct++;
                    }
                });
                
                var percentage = Math.round((correct / total) * 100);
                var pass = parseInt(container.dataset.pass) || 50;
                var passed = percentage >= pass;
                
                // Masquer questions et nav
                container.querySelectorAll('.h5p-qs-question').forEach(function(q) { q.style.display = 'none'; });
                container.querySelector('.h5p-qs-nav').style.display = 'none';
                container.querySelector('.h5p-qs-progressbar').style.display = 'none';
                
                // Afficher résultat
                var result = container.querySelector('.h5p-qs-result');
                var scoreEl = result.querySelector('.h5p-qs-score');
                var msgEl = result.querySelector('.h5p-qs-message');
                
                scoreEl.innerHTML = '<span class="score-value">' + correct + ' / ' + total + '</span><span class="score-percent">' + percentage + '%</span>';
                msgEl.innerHTML = passed ? '🎉 Bravo ! Vous avez réussi !' : '😕 Essayez encore !';
                result.className = 'h5p-qs-result ' + (passed ? 'passed' : 'failed');
                result.style.display = 'block';
            };
            
            window.restartQs = function(qsId) {
                if (qsId !== id) return;
                var container = document.getElementById(id);
                var state = window['qsState_' + id];
                state.current = 0;
                
                // Réafficher les éléments
                container.querySelector('.h5p-qs-nav').style.display = 'flex';
                container.querySelector('.h5p-qs-progressbar').style.display = 'flex';
                container.querySelector('.h5p-qs-result').style.display = 'none';
                
                // Reset des questions
                container.querySelectorAll('.h5p-qs-question').forEach(function(q, i) {
                    q.style.display = i === 0 ? 'block' : 'none';
                    // Reset les réponses
                    q.querySelectorAll('.answer-correct, .answer-wrong').forEach(function(a) {
                        a.classList.remove('answer-correct', 'answer-wrong');
                    });
                    q.querySelectorAll('.h5p-mc-feedback').forEach(function(fb) {
                        fb.style.display = 'none';
                    });
                    q.querySelectorAll('.h5p-mc-check').forEach(function(btn) {
                        btn.disabled = false;
                    });
                });
                
                // Reset dots
                container.querySelectorAll('.h5p-qs-dot').forEach(function(dot, i) {
                    dot.classList.toggle('active', i === 0);
                    dot.classList.remove('done');
                });
                
                // Reset nav
                goToQsQuestion(id, 0);
            };
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pFlashcards(array $content, array $files): string {
        $cards = $content['cards'] ?? [];
        $id = 'h5p-fc-' . uniqid();
        
        if (empty($cards)) {
            return '<div class="h5p-placeholder"><p>Flashcards sans cartes</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-flashcards" id="<?= $id ?>">
            <div class="h5p-fc-container">
                <?php foreach ($cards as $i => $card): ?>
                <div class="h5p-fc-card" data-idx="<?= $i ?>" <?= $i > 0 ? 'style="display:none;"' : '' ?>>
                    <div class="h5p-fc-front">
                        <?php if (!empty($card['image']['path'])): ?>
                        <img src="<?= $this->getH5pFileUrl($card['image']['path'], $files) ?>" alt="">
                        <?php endif; ?>
                        <div class="h5p-fc-text"><?= $this->processH5pText($card['text'] ?? '', $files) ?></div>
                    </div>
                    <div class="h5p-fc-answer">
                        <input type="text" class="form-input" placeholder="Votre réponse...">
                        <button class="btn btn-primary btn-sm" onclick="checkFlashcard(this, '<?= htmlspecialchars($card['answer'] ?? '') ?>')">Vérifier</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="h5p-fc-nav">
                <button class="btn btn-secondary btn-sm" onclick="prevFlashcard('<?= $id ?>', <?= count($cards) ?>)">← Précédent</button>
                <span class="h5p-fc-progress">1 / <?= count($cards) ?></span>
                <button class="btn btn-secondary btn-sm" onclick="nextFlashcard('<?= $id ?>', <?= count($cards) ?>)">Suivant →</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pDialogCards(array $content, array $files): string {
        // Structure: dialogs[] avec text, answer, image
        $cards = $content['dialogs'] ?? [];
        $id = 'h5p-dc-' . uniqid();
        $total = count($cards);
        
        if (empty($cards)) {
            return '<div class="h5p-placeholder"><p>Dialog Cards sans contenu</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-dialogcards" id="<?= $id ?>" data-total="<?= $total ?>">
            <?php foreach ($cards as $i => $card): 
                // Décoder les entités HTML dans le texte
                $frontText = html_entity_decode($card['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $backText = html_entity_decode($card['answer'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            ?>
            <div class="h5p-dc-card" data-idx="<?= $i ?>" style="<?= $i > 0 ? 'display:none;' : '' ?>">
                <div class="h5p-dc-card-inner">
                    <div class="h5p-dc-front">
                        <?php if (!empty($card['image']['path'])): ?>
                        <img src="<?= $this->getH5pFileUrl($card['image']['path'], $files) ?>" alt="">
                        <?php endif; ?>
                        <div class="h5p-dc-text"><?= $this->processH5pText($frontText, $files) ?></div>
                        <button type="button" class="h5p-dc-btn" onclick="flipDialogCard(this)">↻ Retourner</button>
                    </div>
                    <div class="h5p-dc-back">
                        <?php if (!empty($card['image']['path'])): ?>
                        <img src="<?= $this->getH5pFileUrl($card['image']['path'], $files) ?>" alt="">
                        <?php endif; ?>
                        <div class="h5p-dc-text"><?= $this->processH5pText($backText, $files) ?></div>
                        <button type="button" class="h5p-dc-btn" onclick="flipDialogCard(this)">↻ Retourner</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($total > 1): ?>
            <div class="h5p-dc-nav">
                <button type="button" class="h5p-dc-nav-btn" disabled onclick="prevDialogCard('<?= $id ?>', <?= $total ?>)" title="Carte précédente">◀</button>
                <span class="h5p-dc-progress">Carte 1 sur <?= $total ?></span>
                <button type="button" class="h5p-dc-nav-btn" onclick="nextDialogCard('<?= $id ?>', <?= $total ?>)" title="Carte suivante">▶</button>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * H5P.MemoryGame : retrouver les paires de cartes.
     * Rendu calqué sur Éléa : dos gris clair avec un « ? » à la couleur du thème
     * (lookNFeel.themeColor), grille carrée de ceil(sqrt(n)) colonnes quand
     * behaviour.useGrid est actif, retournement 3D, paires trouvées estompées.
     * Une entrée de `cards` = UNE paire ; `match` porte l'image de la jumelle
     * quand elle diffère de `image`.
     */
    private function renderH5pMemoryGame(array $content, array $files): string {
        $rawCards = $content['cards'] ?? [];
        $id = 'h5p-memory-' . uniqid();

        $pairs = [];
        foreach ($rawCards as $card) {
            if (!is_array($card)) continue;
            $path = $card['image']['path'] ?? '';
            if ($path === '') continue;
            $matchPath = $card['match']['path'] ?? '';
            $alt = (string)($card['imageAlt'] ?? '');
            $pairs[] = [
                'img'      => $path,
                'alt'      => $alt,
                'matchImg' => $matchPath !== '' ? $matchPath : $path,
                'matchAlt' => (string)($card['matchAlt'] ?? $alt),
                'desc'     => (string)($card['description'] ?? ''),
            ];
        }

        if (empty($pairs)) {
            return '<div class="h5p-placeholder"><p>Memory sans cartes</p></div>';
        }

        $behaviour = $content['behaviour'] ?? [];

        // numCardsToUse : Éléa ne tire qu'un sous-ensemble des paires (au moins 2)
        $numToUse = (int)($behaviour['numCardsToUse'] ?? 0);
        if (!$this->printMode && $numToUse >= 2 && $numToUse < count($pairs)) {
            $keys = (array)array_rand($pairs, $numToUse);
            $subset = [];
            foreach ($keys as $k) $subset[] = $pairs[$k];
            $pairs = $subset;
        }

        $lookNFeel  = $content['lookNFeel'] ?? [];
        $themeColor = (string)($lookNFeel['themeColor'] ?? '#909090');
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $themeColor)) {
            $themeColor = '#909090';
        }
        $backPath = $lookNFeel['cardBack']['path'] ?? '';

        $l10n = $content['l10n'] ?? [];
        $txt = [
            'timeSpent'    => (string)($l10n['timeSpent']    ?? 'Temps écoulé :'),
            'cardTurns'    => (string)($l10n['cardTurns']    ?? 'Cartes retournées :'),
            'feedback'     => (string)($l10n['feedback']     ?? 'Bien joué !'),
            'tryAgain'     => (string)($l10n['tryAgain']     ?? 'Réessayer'),
            'closeLabel'   => (string)($l10n['closeLabel']   ?? 'Fermer'),
            'label'        => (string)($l10n['label']        ?? 'Jeu de mémoire. Trouver les cartes qui se correspondent.'),
            'cardPrefix'   => (string)($l10n['cardPrefix']   ?? 'Carte %num sur %total:'),
            'cardUnturned' => (string)($l10n['cardUnturned'] ?? 'Non retournée.'),
            'cardTurned'   => (string)($l10n['cardTurned']   ?? 'Retournée.'),
            'cardMatched'  => (string)($l10n['cardMatched']  ?? 'Correspondance trouvée.'),
        ];

        // Impression : les paires côte à côte, numérotées (corrigé papier)
        if ($this->printMode) {
            ob_start();
            ?>
            <div class="h5p-memo-print">
                <div class="h5p-memo-print-pairs">
                    <?php foreach ($pairs as $i => $p): ?>
                    <div class="h5p-memo-print-pair">
                        <span class="h5p-memo-print-num"><?= $i + 1 ?></span>
                        <div class="h5p-memo-print-imgs">
                            <img src="<?= $this->getH5pFileUrl($p['img'], $files) ?>" alt="<?= htmlspecialchars($p['alt']) ?>">
                            <img src="<?= $this->getH5pFileUrl($p['matchImg'], $files) ?>" alt="<?= htmlspecialchars($p['matchAlt']) ?>">
                        </div>
                        <?php if ($p['desc'] !== ''): ?>
                        <div class="h5p-memo-print-desc"><?= htmlspecialchars($p['desc']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $allowRetry = ($behaviour['allowRetry'] ?? true) !== false;
        $useGrid    = ($behaviour['useGrid'] ?? true) !== false;
        $total      = count($pairs) * 2;
        // Éléa : grille carrée => ceil(racine(nombre de cartes)) colonnes
        $cols       = max(2, (int)ceil(sqrt($total)));

        // Le paquet est émis paire par paire ; le mélange se fait en JS (comme Éléa),
        // ce qui permet de re-mélanger sans recharger la page.
        $deck = [];
        foreach ($pairs as $i => $p) {
            $deck[] = ['pair' => $i, 'img' => $p['img'],      'alt' => $p['alt'],      'desc' => $p['desc']];
            $deck[] = ['pair' => $i, 'img' => $p['matchImg'], 'alt' => $p['matchAlt'], 'desc' => $p['desc']];
        }

        ob_start();
        ?>
        <div class="h5p-memorygame" id="<?= $id ?>" style="--memo-color: <?= htmlspecialchars($themeColor) ?>;"
             role="application" aria-label="<?= htmlspecialchars($txt['label']) ?>">
            <ul class="h5p-memory-grid<?= $useGrid ? '' : ' h5p-memory-free' ?>" style="--memo-cols: <?= $cols ?>;">
                <?php foreach ($deck as $ci => $card): ?>
                <li class="h5p-memory-wrap">
                    <div class="h5p-memory-card" role="button" tabindex="0"
                         data-pair="<?= $card['pair'] ?>"
                         data-desc="<?= htmlspecialchars($card['desc']) ?>"
                         onclick="flipMemoryCard(this, '<?= $id ?>')"
                         onkeydown="memoryCardKey(event, this, '<?= $id ?>')">
                        <span class="h5p-memory-face h5p-memory-front" aria-hidden="true">
                            <?php if ($backPath !== ''): ?>
                            <img src="<?= $this->getH5pFileUrl($backPath, $files) ?>" alt="">
                            <?php else: ?>
                            <span class="h5p-memory-qmark">?</span>
                            <?php endif; ?>
                        </span>
                        <span class="h5p-memory-face h5p-memory-back">
                            <img src="<?= $this->getH5pFileUrl($card['img'], $files) ?>" alt="<?= htmlspecialchars($card['alt']) ?>">
                        </span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>

            <dl class="h5p-memory-status">
                <dt><?= htmlspecialchars($txt['timeSpent']) ?></dt>
                <dd class="h5p-memory-timer">00:00:00</dd>
                <dt><?= htmlspecialchars($txt['cardTurns']) ?></dt>
                <dd class="h5p-memory-counter">0</dd>
            </dl>

            <div class="h5p-memory-done" style="display: none;">
                <span class="h5p-memory-done-text"><?= htmlspecialchars($txt['feedback']) ?></span>
                <?php if ($allowRetry): ?>
                <button type="button" class="h5p-memory-retry" onclick="resetMemoryGame('<?= $id ?>')">
                    <span aria-hidden="true">↻</span> <?= htmlspecialchars($txt['tryAgain']) ?>
                </button>
                <?php endif; ?>
            </div>

            <div class="h5p-memory-popup" style="display: none;" onclick="closeMemoryPopup('<?= $id ?>')">
                <div class="h5p-memory-popup-inner" onclick="event.stopPropagation();">
                    <p class="h5p-memory-popup-text"></p>
                    <button type="button" class="h5p-memory-popup-close" onclick="closeMemoryPopup('<?= $id ?>')">
                        <?= htmlspecialchars($txt['closeLabel']) ?>
                    </button>
                </div>
            </div>
        </div>
        <script>
            window.memoryGameState = window.memoryGameState || {};
            window.memoryGameState['<?= $id ?>'] = {
                pairs: <?= count($pairs) ?>,
                found: 0,
                flips: 0,
                seconds: 0,
                timer: null,
                opened: [],
                done: false,
                l10n: <?= json_encode([
                    'cardPrefix'   => $txt['cardPrefix'],
                    'cardUnturned' => $txt['cardUnturned'],
                    'cardTurned'   => $txt['cardTurned'],
                    'cardMatched'  => $txt['cardMatched'],
                ]) ?>
            };
            initMemoryGame('<?= $id ?>');
        </script>
        <?php
        return ob_get_clean();
    }


    /**
     * H5P.ImageSequencing : remettre des images dans le bon ordre.
     * L'ordre de sequenceImages EST la solution ; les cartes sont mélangées à l'affichage,
     * comme dans Éléa.
     */
    private function renderH5pImageSequencing(array $content, array $files): string {
        $id = 'h5p-is-' . uniqid();
        $cards = $content['sequenceImages'] ?? [];
        if (empty($cards)) {
            return '<div class="h5p-placeholder"><p>Séquence d\'images sans carte</p></div>';
        }

        $task = $content['taskDescription'] ?? '';

        // Impression : les images dans l'ordre attendu, numérotées (corrigé papier)
        if ($this->printMode) {
            ob_start();
            ?>
            <div class="h5p-is-print">
                <?php if ($task !== ''): ?>
                <div class="h5p-is-print-task"><?= $this->processH5pText($task, $files) ?></div>
                <?php endif; ?>
                <div class="h5p-is-print-cards">
                    <?php foreach ($cards as $i => $card):
                        $path = $card['image']['path'] ?? '';
                        $desc = $card['imageDescription'] ?? '';
                    ?>
                    <div class="h5p-is-print-card">
                        <span class="h5p-is-print-num"><?= $i + 1 ?></span>
                        <?php if ($path !== ''): ?>
                        <img src="<?= $this->getH5pFileUrl($path, $files) ?>" alt="<?= htmlspecialchars($desc) ?>">
                        <?php endif; ?>
                        <div class="h5p-is-print-label"><?= htmlspecialchars($desc) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        $behaviour = $content['behaviour'] ?? [];
        $showSolution = ($behaviour['enableSolution'] ?? true) !== false;
        $enableRetry = ($behaviour['enableRetry'] ?? true) !== false;

        $l10n = $content['l10n'] ?? [];
        $txt = [
            'timeSpent'    => $l10n['timeSpent']    ?? 'Time spent',
            'totalMoves'   => $l10n['totalMoves']   ?? 'Total Moves',
            'checkAnswer'  => $l10n['checkAnswer']  ?? 'Check',
            'showSolution' => $l10n['showSolution'] ?? 'ShowSolution',
            'tryAgain'     => $l10n['tryAgain']     ?? 'Retry',
            'score'        => $l10n['score']        ?? 'You got @score of @total points',
        ];

        ob_start();
        ?>
        <div class="h5p-imageseq" id="<?= $id ?>">
            <?php if ($task !== ''): ?>
            <div class="h5p-is-task"><?= $this->processH5pText($task, $files) ?></div>
            <?php endif; ?>
            <div class="h5p-is-cards">
                <?php foreach ($cards as $i => $card):
                    $path = $card['image']['path'] ?? '';
                    $desc = $card['imageDescription'] ?? '';
                ?>
                <div class="h5p-is-card" draggable="true" data-solution="<?= $i ?>"
                     ondragstart="isDragStart(event, '<?= $id ?>', <?= $i ?>)"
                     ondragover="isDragOver(event)"
                     ondrop="isDrop(event, '<?= $id ?>', <?= $i ?>)"
                     ondragend="isDragEnd(event)"
                     onclick="isTapCard('<?= $id ?>', <?= $i ?>)">
                    <div class="h5p-is-card-img">
                        <?php if ($path !== ''): ?>
                        <img src="<?= $this->getH5pFileUrl($path, $files) ?>" alt="<?= htmlspecialchars($desc) ?>" draggable="false">
                        <?php endif; ?>
                        <span class="h5p-is-mark"></span>
                    </div>
                    <?php if ($desc !== ''): ?>
                    <div class="h5p-is-card-label"><?= htmlspecialchars($desc) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="h5p-is-footer">
                <div class="h5p-is-stats">
                    <div class="h5p-is-stat">
                        <span class="h5p-is-stat-label"><?= htmlspecialchars($txt['timeSpent']) ?></span>
                        <span class="h5p-is-stat-value h5p-is-time">0:00</span>
                    </div>
                    <div class="h5p-is-stat">
                        <span class="h5p-is-stat-label"><?= htmlspecialchars($txt['totalMoves']) ?></span>
                        <span class="h5p-is-stat-value h5p-is-moves">0</span>
                    </div>
                </div>
                <div class="h5p-is-buttons">
                    <button type="button" class="h5p-is-btn h5p-is-check" onclick="isCheckOrder('<?= $id ?>')">
                        <span aria-hidden="true">✔</span> <?= htmlspecialchars($txt['checkAnswer']) ?>
                    </button>
                    <?php if ($showSolution): ?>
                    <button type="button" class="h5p-is-btn h5p-is-solution" onclick="isShowSolution('<?= $id ?>')">
                        <span aria-hidden="true">👁</span> <?= htmlspecialchars($txt['showSolution']) ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($enableRetry): ?>
                    <button type="button" class="h5p-is-btn h5p-is-retry" style="display:none;" onclick="isRetry('<?= $id ?>')">
                        <span aria-hidden="true">↻</span> <?= htmlspecialchars($txt['tryAgain']) ?>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="h5p-is-feedback" style="display:none;"></div>
            </div>
        </div>
        <script>
            window.imageSeqState = window.imageSeqState || {};
            window.imageSeqState['<?= $id ?>'] = {
                total: <?= count($cards) ?>,
                moves: 0,
                seconds: 0,
                timer: null,
                selected: null,
                done: false,
                scoreText: <?= json_encode($txt['score']) ?>
            };
            initImageSequencing('<?= $id ?>');
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * H5P.GameMap : carte avec des étapes reliées par des chemins.
     * Les couleurs, le style des chemins et la taille des pastilles viennent du contenu
     * (visual.stages / visual.paths / telemetry) pour rendre exactement comme Éléa.
     * telemetry.x/y est le COIN HAUT-GAUCHE de la pastille, pas son centre.
     */
    private function renderH5pGameMap(array $content, array $files): string {
        $id = 'h5p-gamemap-' . uniqid();

        $background = $content['gamemapSteps']['backgroundImageSettings']['backgroundImage'] ?? null;
        $steps = $content['gamemapSteps']['gamemap']['elements'] ?? [];

        if (empty($steps)) {
            return '<div class="h5p-placeholder"><p>Game Map sans étapes</p></div>';
        }

        // Apparence : reprendre les réglages du contenu, avec les défauts d'Éléa
        $visual = $content['visual'] ?? [];
        $colorStage        = $visual['stages']['colorStage']        ?? 'rgba(250, 223, 10, 0.7)';
        $colorStageLocked  = $visual['stages']['colorStageLocked']  ?? 'rgba(153, 0, 0, 0.7)';
        $colorStageCleared = $visual['stages']['colorStageCleared'] ?? 'rgba(0, 130, 0, 0.7)';
        $pathStyleCfg      = $visual['paths']['style'] ?? [];
        $colorPath         = $pathStyleCfg['colorPath']        ?? 'rgba(255, 255, 255, 0.904)';
        $colorPathCleared  = $pathStyleCfg['colorPathCleared']  ?? 'rgba(0, 130, 0, 0.7)';
        $pathWidth         = (float)($pathStyleCfg['pathWidth'] ?? 0.2);
        $pathStyle         = $pathStyleCfg['pathStyle'] ?? 'dotted';
        $displayPaths      = ($visual['paths']['displayPaths'] ?? true) !== false;
        $showLabels        = ($content['behaviour']['map']['showLabels'] ?? true) !== false;

        // Le SVG est gradué en % de la LARGEUR de la carte sur les deux axes : sans cela
        // (viewBox carré étiré) les pointillés ronds deviendraient des ellipses.
        $bgW = floatval($background['width'] ?? 0);
        $bgH = floatval($background['height'] ?? 0);
        $mapRatio = ($bgW > 0 && $bgH > 0) ? ($bgH / $bgW) : (9 / 16);
        $viewH = 100 * $mapRatio;

        // Épaisseur et espacement exprimés en % de la largeur de la carte, comme H5P
        $strokeWidth = max(0.1, $pathWidth * 3);
        $dashArray = match($pathStyle) {
            'dashed' => ($strokeWidth * 3) . ',' . ($strokeWidth * 2),
            'solid'  => 'none',
            default  => '0.01,' . ($strokeWidth * 2.2),   // pointillés ronds
        };

        // Point de départ et étape finale
        $startStep = 0;
        foreach ($steps as $i => $step) {
            if (!empty($step['canBeStartStage'])) {
                $startStep = $i;
                break;
            }
        }
        $lastStep = count($steps) - 1;

        // Centre de chaque pastille, pour tracer les chemins
        $centers = [];
        foreach ($steps as $i => $step) {
            $t = $step['telemetry'] ?? [];
            $centers[$i] = [
                'x' => floatval($t['x'] ?? 50) + floatval($t['width'] ?? 4.375) / 2,
                'y' => floatval($t['y'] ?? 50) + floatval($t['height'] ?? 7.814) / 2,
            ];
        }

        $connections = [];
        foreach ($steps as $i => $step) {
            foreach ($step['neighbors'] ?? [] as $neighborIdx) {
                $ni = intval($neighborIdx);
                // Une seule ligne par paire : on ne trace que vers les indices supérieurs
                if ($ni > $i && isset($centers[$ni])) {
                    $connections[] = [
                        'x1' => $centers[$i]['x'], 'y1' => $centers[$i]['y'],
                        'x2' => $centers[$ni]['x'], 'y2' => $centers[$ni]['y'],
                        'from' => $i, 'to' => $ni,
                    ];
                }
            }
        }

        $lockIcon = '<svg class="h5p-gamemap-step-svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
                  . '<path d="M18 8h-1V6a5 5 0 0 0-10 0v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zM9 6a3 3 0 0 1 6 0v2H9V6z"/></svg>';
        $starIcon = '<svg class="h5p-gamemap-step-svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
                  . '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';

        $styleVars = 'style="'
            . '--gm-stage:' . htmlspecialchars($colorStage) . ';'
            . '--gm-stage-locked:' . htmlspecialchars($colorStageLocked) . ';'
            . '--gm-stage-cleared:' . htmlspecialchars($colorStageCleared) . ';'
            . '--gm-path:' . htmlspecialchars($colorPath) . ';'
            . '--gm-path-cleared:' . htmlspecialchars($colorPathCleared) . ';'
            . '"';

        ob_start();
        ?>
        <div class="h5p-gamemap<?= $showLabels ? '' : ' no-labels' ?>" id="<?= $id ?>" <?= $styleVars ?>
             data-start="<?= $startStep ?>" data-last="<?= $lastStep ?>">
            <div class="h5p-gamemap-container">
                <?php if ($background && !empty($background['path'])): ?>
                <img class="h5p-gamemap-bg" src="<?= $this->getH5pFileUrl($background['path'], $files) ?>" alt="Carte">
                <?php else: ?>
                <div class="h5p-gamemap-bg h5p-gamemap-bg-empty"></div>
                <?php endif; ?>

                <?php if ($displayPaths): ?>
                <svg class="h5p-gamemap-paths" viewBox="0 0 100 <?= $viewH ?>" preserveAspectRatio="none">
                    <?php foreach ($connections as $conn): ?>
                    <line class="h5p-gamemap-path"
                          x1="<?= $conn['x1'] ?>" y1="<?= $conn['y1'] * $mapRatio ?>"
                          x2="<?= $conn['x2'] ?>" y2="<?= $conn['y2'] * $mapRatio ?>"
                          stroke-width="<?= $strokeWidth ?>"
                          stroke-dasharray="<?= htmlspecialchars($dashArray) ?>"
                          data-from="<?= $conn['from'] ?>" data-to="<?= $conn['to'] ?>"/>
                    <?php endforeach; ?>
                </svg>
                <?php endif; ?>

                <div class="h5p-gamemap-steps">
                    <?php foreach ($steps as $i => $step):
                        $label = $step['label'] ?? ('Étape ' . ($i + 1));
                        $t = $step['telemetry'] ?? [];
                        $x = floatval($t['x'] ?? 50);
                        $y = floatval($t['y'] ?? 50);
                        $w = floatval($t['width'] ?? 4.375);
                        $h = floatval($t['height'] ?? 7.814);
                        $neighbors = json_encode($step['neighbors'] ?? []);
                        $isStart = ($i === $startStep);
                        $isLocked = !$isStart;
                    ?>
                    <button type="button"
                            class="h5p-gamemap-step<?= $isLocked ? ' locked' : '' ?>"
                            style="left: <?= $x ?>%; top: <?= $y ?>%; width: <?= $w ?>%; height: <?= $h ?>%;"
                            data-step="<?= $i ?>"
                            data-neighbors='<?= $neighbors ?>'
                            data-locked="<?= $isLocked ? 'true' : 'false' ?>"
                            aria-label="<?= htmlspecialchars($label) ?>"
                            onclick="openGameMapStep('<?= $id ?>', <?= $i ?>)">
                        <span class="h5p-gamemap-step-icon"><?= $isLocked ? $lockIcon : '' ?></span>
                        <span class="h5p-gamemap-step-label"><?= htmlspecialchars($label) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Panneau d'étape : recouvre la carte, comme dans Éléa -->
                <?php foreach ($steps as $i => $step):
                    $label = $step['label'] ?? ('Étape ' . ($i + 1));
                    $library = $step['contentType']['library'] ?? '';
                    $params = $step['contentType']['params'] ?? [];
                    $isFinish = (($step['specialStageType'] ?? '') === 'finish') || ($i === $lastStep);
                ?>
                <div class="h5p-gamemap-modal" id="<?= $id ?>-step-<?= $i ?>" style="display:none;">
                    <button class="h5p-gamemap-close" aria-label="Fermer"
                            onclick="closeGameMapStep('<?= $id ?>', <?= $i ?>)">✕</button>
                    <div class="h5p-gamemap-modal-content">
                        <div class="h5p-gamemap-modal-header">
                            <h4><?= htmlspecialchars($label) ?></h4>
                        </div>
                        <div class="h5p-gamemap-modal-body">
                            <?php
                            $machineName = $library ? (explode(' ', $library)[0] ?? '') : '';
                            if ($machineName) {
                                echo $this->renderH5pContent($machineName, $params, $files);
                            } elseif (!empty($params['text'])) {
                                $text = html_entity_decode($params['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                echo '<div class="h5p-generic-content">' . $this->processH5pText($text, $files) . '</div>';
                            } elseif ($isFinish) {
                                echo '<div class="h5p-gamemap-finish"><div class="h5p-gamemap-finish-icon">🎉</div>'
                                   . '<h3>Félicitations !</h3><p>Vous avez terminé cette activité.</p></div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="h5p-gamemap-progress">
                <span>Progression : <strong class="h5p-gamemap-completed">0</strong> / <?= count($steps) ?></span>
            </div>
        </div>
        <script>
            window.gameMapState = window.gameMapState || {};
            window.gameMapState['<?= $id ?>'] = {
                completed: [],
                total: <?= count($steps) ?>,
                start: <?= $startStep ?>,
                last: <?= $lastStep ?>,
                starIcon: <?= json_encode($starIcon) ?>
            };
        </script>
        <?php
        return ob_get_clean();
    }

    private function renderH5pVirtualTour(array $content, array $files): string {
        $id = 'h5p-vt-' . uniqid();
        
        // Structure ThreeImage: threeImage.scenes[]
        $scenes = $content['threeImage']['scenes'] ?? $content['scenes'] ?? [];
        $startScene = $content['threeImage']['startSceneId'] ?? $content['startSceneId'] ?? 0;
        
        if (empty($scenes)) {
            return '<div class="h5p-placeholder"><p>Visite virtuelle 360 sans scènes</p></div>';
        }
        
        // Fonction pour normaliser un angle en radians vers -π à π
        $normalizeAngle = function($angle) {
            while ($angle > M_PI) $angle -= 2 * M_PI;
            while ($angle < -M_PI) $angle += 2 * M_PI;
            return $angle;
        };
        
        // Calibration validée : miroir (pas de signe négatif) + offset 95°
        $yawOffset = 95;
        
        // Préparer les données pour Pannellum
        $pannellumScenes = [];
        
        foreach ($scenes as $sceneId => $scene) {
            $scenePath = $scene['scenesrc']['path'] ?? '';
            $sceneLabel = html_entity_decode($scene['scenename'] ?? ('Scène ' . ($sceneId + 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $interactions = $scene['interactions'] ?? [];
            
            // Position initiale de la caméra
            $cameraStart = $scene['cameraStartPosition'] ?? '0,0';
            $camParts = explode(',', $cameraStart);
            $startYaw = floatval($camParts[0] ?? 0);
            $startPitch = floatval($camParts[1] ?? 0);
            
            // Convertir radians en degrés pour Pannellum (miroir = pas de signe négatif, + offset)
            $yawDeg = rad2deg($normalizeAngle($startYaw)) + $yawOffset;
            $pitchDeg = rad2deg($startPitch);
            
            $sceneConfig = [
                'type' => 'equirectangular',
                'title' => $sceneLabel,
                'panorama' => $this->getH5pFileUrl($scenePath, $files),
                'yaw' => $yawDeg,
                'pitch' => $pitchDeg,
                'hfov' => 100,
                'autoLoad' => true,
                'hotSpots' => []
            ];
            
            // Ajouter les hotspots
            foreach ($interactions as $intIdx => $interaction) {
                $intLabel = html_entity_decode($interaction['labelText'] ?? $interaction['label']['labelText'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
                $posStr = $interaction['interactionpos'] ?? '0,0';
                $posParts = explode(',', $posStr);
                $yaw = floatval($posParts[0] ?? 0);
                $pitch = floatval($posParts[1] ?? 0);
                
                // Convertir et normaliser (miroir = pas de signe négatif, + offset)
                $hsYawDeg = rad2deg($normalizeAngle($yaw)) + $yawOffset;
                $hsPitchDeg = rad2deg($pitch);
                
                $sceneConfig['hotSpots'][] = [
                    'pitch' => $hsPitchDeg,
                    'yaw' => $hsYawDeg,
                    'type' => 'info',
                    'text' => $intLabel,
                    'clickHandlerArgs' => ['scene' => $sceneId, 'idx' => $intIdx]
                ];
            }
            
            $pannellumScenes['scene' . $sceneId] = $sceneConfig;
        }
        
        $firstSceneKey = 'scene' . $startScene;
        
        ob_start();
        ?>
        <div class="h5p-virtualtour" id="<?= $id ?>">
            <div class="h5p-vt-pannellum" id="<?= $id ?>-viewer"></div>
            
            <!-- Navigation entre scènes -->
            <?php if (count($scenes) > 1): ?>
            <div class="h5p-vt-nav">
                <?php foreach ($scenes as $sceneId => $scene): 
                    $sceneLabel = html_entity_decode($scene['scenename'] ?? ('Scène ' . ($sceneId + 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                ?>
                <button class="h5p-vt-nav-btn <?= $sceneId == $startScene ? 'active' : '' ?>" 
                        onclick="vtLoadScene('<?= $id ?>', 'scene<?= $sceneId ?>', this)">
                    <?= htmlspecialchars($sceneLabel) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Modals pour les interactions -->
            <?php foreach ($scenes as $sceneId => $scene): 
                $interactions = $scene['interactions'] ?? [];
                foreach ($interactions as $intIdx => $interaction):
                    $intLabel = html_entity_decode($interaction['labelText'] ?? $interaction['label']['labelText'] ?? 'Information', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $intType = $interaction['action']['library'] ?? '';
                    $intParams = $interaction['action']['params'] ?? [];
            ?>
            <div class="h5p-vt-modal" id="<?= $id ?>-int-<?= $sceneId ?>-<?= $intIdx ?>" style="display:none;">
                <div class="h5p-vt-modal-content">
                    <div class="h5p-vt-modal-header">
                        <h4><?= htmlspecialchars($intLabel) ?></h4>
                        <button class="h5p-vt-close" onclick="closeVtInteraction('<?= $id ?>', <?= $sceneId ?>, <?= $intIdx ?>)">✕</button>
                    </div>
                    <div class="h5p-vt-modal-body">
                        <?php 
                        if (!empty($intType)) {
                            $machineName = explode(' ', $intType)[0] ?? '';
                            if ($machineName) {
                                if (isset($intParams['text'])) {
                                    $intParams['text'] = html_entity_decode($intParams['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                }
                                echo $this->renderH5pContent($machineName, $intParams, $files);
                            }
                        } else {
                            $text = $intParams['text'] ?? '';
                            if ($text) {
                                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                echo '<div class="h5p-generic-content">' . $this->processH5pText($text, $files) . '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endforeach;
            endforeach; ?>
        </div>
        <script>
            (function() {
                var viewerId = '<?= $id ?>-viewer';
                var containerId = '<?= $id ?>';
                var scenesConfig = <?= json_encode($pannellumScenes, JSON_UNESCAPED_UNICODE) ?>;
                var firstScene = '<?= $firstSceneKey ?>';
                var viewerInitialized = false;
                
                function initViewer() {
                    if (viewerInitialized) return;
                    
                    if (typeof pannellum === 'undefined') {
                        setTimeout(initViewer, 100);
                        return;
                    }
                    
                    var container = document.getElementById(viewerId);
                    if (!container || container.offsetWidth === 0) {
                        // Conteneur pas encore visible, réessayer
                        setTimeout(initViewer, 200);
                        return;
                    }
                    
                    viewerInitialized = true;
                    
                    // Fonction pour créer un tooltip personnalisé (+ avec texte visible)
                    function createCustomTooltip(hotSpotDiv, args) {
                        var wrapper = document.createElement('div');
                        wrapper.classList.add('vt-custom-hotspot');
                        
                        var plus = document.createElement('span');
                        plus.classList.add('vt-hotspot-plus');
                        plus.textContent = '+';
                        wrapper.appendChild(plus);
                        
                        if (args && args.text) {
                            var label = document.createElement('span');
                            label.classList.add('vt-hotspot-label');
                            label.textContent = args.text;
                            wrapper.appendChild(label);
                        }
                        
                        hotSpotDiv.appendChild(wrapper);
                    }
                    
                    // Ajouter les handlers pour chaque hotspot
                    Object.keys(scenesConfig).forEach(function(sceneKey) {
                        scenesConfig[sceneKey].hotSpots.forEach(function(hs) {
                            hs.createTooltipFunc = createCustomTooltip;
                            hs.createTooltipArgs = { text: hs.text };
                            hs.clickHandlerFunc = function(evt, args) {
                                var modal = document.getElementById(containerId + '-int-' + args.scene + '-' + args.idx);
                                if (modal) modal.style.display = 'flex';
                            };
                        });
                    });
                    
                    window['vtViewer_' + containerId] = pannellum.viewer(viewerId, {
                        default: {
                            firstScene: firstScene,
                            autoLoad: true,
                            showControls: true,
                            compass: false,
                            hotSpotDebug: false
                        },
                        scenes: scenesConfig
                    });
                }
                
                // Observer pour détecter quand l'élément devient visible
                function setupObserver() {
                    var container = document.getElementById(viewerId);
                    if (!container) {
                        setTimeout(setupObserver, 100);
                        return;
                    }
                    
                    // Essayer d'initialiser immédiatement si visible
                    if (container.offsetWidth > 0) {
                        initViewer();
                        return;
                    }
                    
                    // Sinon utiliser IntersectionObserver
                    if ('IntersectionObserver' in window) {
                        var observer = new IntersectionObserver(function(entries) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting && entry.intersectionRatio > 0) {
                                    initViewer();
                                    observer.disconnect();
                                }
                            });
                        }, { threshold: 0.1 });
                        observer.observe(container);
                    } else {
                        // Fallback : vérifier périodiquement
                        var checkInterval = setInterval(function() {
                            if (container.offsetWidth > 0) {
                                clearInterval(checkInterval);
                                initViewer();
                            }
                        }, 300);
                    }
                }
                
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', setupObserver);
                } else {
                    setupObserver();
                }
            })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pDragQuestion(array $content, array $files): string {
        $question = $content['question'] ?? [];
        $settings = $question['settings'] ?? [];
        $task = $question['task'] ?? [];
        $elements = $task['elements'] ?? [];
        $dropZones = $task['dropZones'] ?? [];
        $background = $settings['background'] ?? [];
        $size = $settings['size'] ?? ['width' => 800, 'height' => 400];
        $id = 'h5p-dq-' . uniqid();
        
        if (empty($dropZones) && empty($elements)) {
            return '<div class="h5p-placeholder"><p>Glisser-déposer sans éléments</p></div>';
        }
        
        // Prépare les données pour le JS - mapping zone -> éléments corrects
        // Les clés doivent être des strings pour correspondre à dataset.idx
        $correctMapping = [];
        foreach ($dropZones as $dzIdx => $dz) {
            $correctMapping[strval($dzIdx)] = $dz['correctElements'] ?? [];
        }
        
        // Dimensions du canvas H5P
        $canvasWidth = $size['width'] ?? 800;
        $canvasHeight = $size['height'] ?? 400;
        
        // Conversion em → % : H5P utilise fontSize = 16 * (containerWidth / canvasWidth)
        // Les dimensions stockées sont en em, donc :
        //   width%  = stored_em * 1600 / canvasWidth
        //   height% = stored_em * 1600 / canvasHeight
        $wFactor = 1600 / $canvasWidth;
        $hFactor = 1600 / $canvasHeight;
        
        ob_start();
        ?>
        <div class="h5p-dragquestion" id="<?= $id ?>">
            <div class="h5p-dq-container" 
                 data-canvas-width="<?= $canvasWidth ?>"
                 data-canvas-height="<?= $canvasHeight ?>"
                 style="position: relative; width: 100%; aspect-ratio: <?= $canvasWidth ?> / <?= $canvasHeight ?>; background: #f5f5f5; border-radius: 8px; overflow: visible;"
                 ondragover="event.preventDefault();"
                 ondrop="event.preventDefault(); handleDqDropOutside(event, '<?= $id ?>');">
                <?php if (!empty($background['path'])): ?>
                <img src="<?= $this->getH5pFileUrl($background['path'], $files) ?>" class="h5p-dq-background" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; pointer-events: none;">
                <?php endif; ?>
                
                <!-- Éléments draggables (étiquettes) -->
                <?php foreach ($elements as $elIdx => $el): 
                    $elParams = $el['type']['params'] ?? [];
                    // Décoder les entités HTML puis supprimer les balises
                    $elText = strip_tags(html_entity_decode($elParams['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $elImg = $elParams['file']['path'] ?? '';
                    // X et Y sont utilisés directement
                    $x = $el['x'] ?? 0;
                    $y = $el['y'] ?? 0;
                    // W et H : conversion em → % avec les facteurs universels
                    $w = ($el['width'] ?? 10) * $wFactor;
                    $h = ($el['height'] ?? 5) * $hFactor;
                ?>
                <div class="h5p-dq-draggable" 
                     data-idx="<?= $elIdx ?>"
                     data-origx="<?= $x ?>"
                     data-origy="<?= $y ?>"
                     draggable="true"
                     ondragstart="startDqDrag(event, this, '<?= $id ?>')"
                     ondragend="endDqDrag(event, this, '<?= $id ?>')"
                     style="position: absolute; left: <?= $x ?>%; top: <?= $y ?>%; width: <?= $w ?>%; height: <?= $h ?>%; cursor: grab; background: #f8f8f8; border: 1px solid #bbb; border-radius: 4px; font-size: 0.7em; box-shadow: 0 2px 4px rgba(0,0,0,0.15); z-index: 10; user-select: none; display: flex; align-items: center; justify-content: center; text-align: center; box-sizing: border-box; overflow: hidden; padding: 2px 4px;">
                    <?php if ($elImg): ?>
                    <img src="<?= $this->getH5pFileUrl($elImg, $files) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php else: ?>
                    <span style="line-height: 1.1; overflow: hidden; word-wrap: break-word; overflow-wrap: break-word; hyphens: auto;"><?= htmlspecialchars($elText) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                
                <!-- Zones de dépôt (invisibles par défaut, visibles au drag) -->
                <?php foreach ($dropZones as $dzIdx => $dz): 
                    // X et Y sont utilisés directement
                    $x = $dz['x'] ?? 0;
                    $y = $dz['y'] ?? 0;
                    // W et H : conversion em → % avec les facteurs universels
                    $w = ($dz['width'] ?? 10) * $wFactor;
                    $h = ($dz['height'] ?? 10) * $hFactor;
                    $label = strip_tags(html_entity_decode($dz['label'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $showLabel = $dz['showLabel'] ?? false;
                ?>
                <div class="h5p-dq-dropzone"
                     data-idx="<?= $dzIdx ?>"
                     data-label="<?= htmlspecialchars($label) ?>"
                     style="position: absolute; left: <?= $x ?>%; top: <?= $y ?>%; width: <?= $w ?>%; height: <?= $h ?>%; border: 2px dashed transparent; border-radius: 4px; box-sizing: border-box; background: transparent; transition: border-color 0.2s, background 0.2s;"
                     ondragover="event.preventDefault(); this.style.background='rgba(255,220,100,0.3)';"
                     ondragleave="this.style.background='transparent';"
                     ondrop="dropDqElement(event, '<?= $id ?>')">
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="h5p-actions" style="margin-top: 0.75rem; text-align: center;">
                <button class="btn btn-primary btn-sm" onclick="checkH5pDragQuestion('<?= $id ?>', <?= htmlspecialchars(json_encode($correctMapping), ENT_QUOTES) ?>)">✓ Vérifier</button>
                <button class="btn btn-secondary btn-sm" onclick="resetH5pDragQuestion('<?= $id ?>')">↻ Réessayer</button>
            </div>
            <div class="h5p-feedback" style="display:none; text-align: center; margin-top: 0.5rem;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Normalise le HTML d'un tableau H5P.Table pour l'affichage :
     * - border-collapse:collapse sur le tableau, AUCUNE border sur le tableau lui-même
     * - Bordures sur cellules uniquement → une seule ligne entre cellules
     * - Toutes les cellules (y compris 1ère colonne sans style inline)
     * - Largeur figure 100%
     */
    private function normalizeH5pTableHtml(string $html): string {
        if (empty($html)) return $html;

        $hasBorders = strpos($html, 'border-style:solid') !== false
                   || strpos($html, 'border-style: solid') !== false
                   || preg_match('/border\s*:\s*\d/', $html);

        // Figure : forcer width:100%
        $html = preg_replace_callback('/<figure(\b[^>]*)>/i', function ($m) {
            $attrs = preg_replace('/\bstyle\s*=\s*"[^"]*"/', '', $m[1]);
            return '<figure' . $attrs . ' style="margin:0;width:100%;">';
        }, $html);

        // Tableau : border-collapse uniquement, PAS de border sur le tableau
        $html = preg_replace_callback('/<table(\b[^>]*)>/i', function ($m) {
            $attrs = preg_replace('/\bstyle\s*=\s*"[^"]*"/', '', $m[1]);
            return '<table' . $attrs . ' style="border-collapse:collapse;width:100%;">';
        }, $html);

        // Cellules : border uniforme sur TOUTES (y compris 1ère colonne)
        $html = preg_replace_callback('/<(td|th)(\b[^>]*)>/i', function ($m) use ($hasBorders) {
            $tag   = $m[1];
            $attrs = $m[2];
            // Conserver les styles non-border (text-align, etc.)
            $existingStyle = '';
            if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                $existingStyle = preg_replace('/\bborder[^;]*;?\s*/i', '', $sm[1]);
                $existingStyle = trim($existingStyle, '; ');
                $attrs = preg_replace('/\bstyle\s*=\s*"[^"]*"/', '', $attrs);
            }
            $cellStyle = $hasBorders ? 'border:2px solid #333;' : '';
            $cellStyle .= 'padding:5px 8px;vertical-align:middle;';
            if ($existingStyle !== '') $cellStyle .= $existingStyle . ';';
            return '<' . $tag . $attrs . ' style="' . $cellStyle . '">';
        }, $html);

        // Masquer table-overflow-protection
        $html = str_replace(
            '<div class="table-overflow-protection">',
            '<div class="table-overflow-protection" style="display:none;">',
            $html
        );

        return $html;
    }

    private function renderH5pCoursePresentation(array $content, array $files): string {
        $slides = $content['presentation']['slides'] ?? [];
        $id = 'h5p-cp-' . uniqid();
        $total = count($slides);
        
        if (empty($slides)) {
            return '<div class="h5p-placeholder"><p>Présentation sans slides</p></div>';
        }
        
        // Mode print : afficher toutes les slides linéairement
        if ($this->printMode) {
            return $this->renderH5pCoursePresentationPrint($slides, $files, $id);
        }
        
        // Détecter les slides avec des activités interactives
        $activityTypes = ['H5P.MultiChoice', 'H5P.Blanks', 'H5P.TrueFalse', 'H5P.SingleChoiceSet', 'H5P.DragQuestion'];
        $slidesWithActivity = [];
        foreach ($slides as $i => $slide) {
            foreach ($slide['elements'] ?? [] as $element) {
                $lib = $element['action']['library'] ?? '';
                $machineName = explode(' ', $lib)[0] ?? '';
                if (in_array($machineName, $activityTypes)) {
                    $slidesWithActivity[$i] = true;
                    break;
                }
            }
        }
        
        ob_start();
        ?>
        <div class="h5p-coursepresentation" id="<?= $id ?>" data-total="<?= $total ?>">
            <div class="h5p-cp-slides-wrapper">
                <?php foreach ($slides as $i => $slide): ?>
                <div class="h5p-cp-slide" data-idx="<?= $i ?>" style="<?= $i > 0 ? 'display:none;' : '' ?>">
                    <?php foreach ($slide['elements'] ?? [] as $element):
                        $lib = $element['action']['library'] ?? '';
                        $params = $element['action']['params'] ?? [];
                        $machineName = explode(' ', $lib)[0] ?? '';
                        
                        // Position et taille en pourcentage
                        $x = $element['x'] ?? 0;
                        $y = $element['y'] ?? 0;
                        $w = $element['width'] ?? 50;
                        $h = $element['height'] ?? 50;
                        $rotation = $element['rotation'] ?? 0;
                        $style = "left:{$x}%;top:{$y}%;width:{$w}%;height:{$h}%;";
                        if ($rotation) {
                            $style .= "transform:rotate({$rotation}deg);";
                        }
                        // La zone de saisie mémorise la réponse : lui transmettre son identifiant
                        // de sous-contenu, seule clé stable d'une session à l'autre.
                        if ($machineName === 'H5P.ExportableTextArea') {
                            $params['_subContentId'] = $element['action']['subContentId'] ?? '';
                        }
                    ?>
                    <div class="h5p-cp-element" style="<?= $style ?>">
                        <?php if ($machineName === 'H5P.Text' || $machineName === 'H5P.AdvancedText'): ?>
                        <div class="h5p-cp-text"><?= $params['text'] ?? '' ?></div>
                        <?php elseif ($machineName === 'H5P.Table'): ?>
                        <div class="h5p-cp-table"><?= $this->normalizeH5pTableHtml($params['text'] ?? '') ?></div>
                        <?php elseif ($machineName === 'H5P.Image' && !empty($params['file']['path'])): ?>
                        <img src="<?= $this->getH5pFileUrl($params['file']['path'], $files) ?>" class="h5p-cp-image" alt="">
                        <?php elseif ($machineName === 'H5P.Shape'): ?>
                        <?php
                            $shapeType = $params['type'] ?? 'rectangle';
                            $shapeParams = $params['shape'] ?? [];
                            $lineParams = $params['line'] ?? [];
                            
                            $fillColor = $shapeParams['fillColor'] ?? '#ffffff';
                            $borderColor = $shapeParams['borderColor'] ?? '#000000';
                            $borderWidth = $shapeParams['borderWidth'] ?? '0';
                            $borderStyle = $shapeParams['borderStyle'] ?? 'solid';
                            $borderRadius = $shapeParams['borderRadius'] ?? '0';
                            
                            // Pour les lignes
                            $lineBorderWidth = $lineParams['borderWidth'] ?? '2';
                            $lineBorderStyle = $lineParams['borderStyle'] ?? 'solid';
                            $lineBorderColor = $lineParams['borderColor'] ?? '#000000';
                        ?>
                        <?php if ($shapeType === 'horizontal-line'): ?>
                        <div class="h5p-cp-shape h5p-cp-shape-hline" style="border-top: <?= $lineBorderWidth ?>px <?= $lineBorderStyle ?> <?= htmlspecialchars($lineBorderColor) ?>; width: 100%; height: 0; position: absolute; top: 50%;"></div>
                        <?php elseif ($shapeType === 'vertical-line'): ?>
                        <div class="h5p-cp-shape h5p-cp-shape-vline" style="border-left: <?= $lineBorderWidth ?>px <?= $lineBorderStyle ?> <?= htmlspecialchars($lineBorderColor) ?>; height: 100%; width: 0; position: absolute; left: 50%;"></div>
                        <?php else: ?>
                        <?php $radiusValue = ($shapeType === 'circle') ? '50%' : $borderRadius . 'px'; ?>
                        <div class="h5p-cp-shape h5p-cp-shape-<?= htmlspecialchars($shapeType) ?>" style="background-color: <?= htmlspecialchars($fillColor) ?>; border: <?= $borderWidth ?>px <?= $borderStyle ?> <?= htmlspecialchars($borderColor) ?>; border-radius: <?= $radiusValue ?>; width: 100%; height: 100%; box-sizing: border-box;"></div>
                        <?php endif; ?>
                        <?php elseif ($machineName === 'H5P.MultiChoice'): ?>
                        <?= $this->renderH5pMultiChoice($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.Blanks'): ?>
                        <?= $this->renderH5pBlanks($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.TrueFalse'): ?>
                        <?= $this->renderH5pTrueFalse($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.SingleChoiceSet'): ?>
                        <?= $this->renderH5pSingleChoiceSet($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.DragQuestion'): ?>
                        <?= $this->renderH5pDragQuestion($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.Dialogcards' || $machineName === 'H5P.DialogCards'): ?>
                        <?= $this->renderH5pDialogCards($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.Video'): ?>
                        <?= $this->renderH5pVideo($params, $files) ?>
                        <?php elseif (!empty($machineName)): ?>
                        <?= $this->renderH5pContent($machineName, $params, $files) ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="h5p-cp-progressbar-container">
                <div class="h5p-cp-progressbar" onclick="onCpProgressClick(event, '<?= $id ?>', <?= $total ?>)">
                    <?php for ($i = 0; $i < $total; $i++): ?>
                    <div class="h5p-cp-segment<?= $i === 0 ? ' viewed current' : '' ?>" data-idx="<?= $i ?>"></div>
                    <?php endfor; ?>
                </div>
                <!-- Indicateurs uniquement pour les slides avec activité -->
                <?php if (!empty($slidesWithActivity)): ?>
                <div class="h5p-cp-indicators">
                    <?php foreach ($slidesWithActivity as $slideIdx => $hasActivity): 
                        // Position de l'indicateur sur la barre (en pourcentage)
                        $position = (($slideIdx + 0.5) / $total) * 100;
                    ?>
                    <div class="h5p-cp-indicator" 
                         data-slide="<?= $slideIdx ?>" 
                         style="left: <?= $position ?>%;"
                         onclick="goToCpSlide('<?= $id ?>', <?= $slideIdx ?>, <?= $total ?>)"></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Navigation -->
            <?php if ($total > 1): ?>
            <div class="h5p-cp-nav">
                <div class="h5p-cp-nav-left"></div>
                <div class="h5p-cp-nav-center">
                    <button class="h5p-cp-nav-btn h5p-cp-prev" onclick="navigateCpSlide('<?= $id ?>', <?= $total ?>, -1)" style="visibility:hidden;">◀</button>
                    <span class="h5p-cp-progress">1 / <?= $total ?></span>
                    <button class="h5p-cp-nav-btn h5p-cp-next" onclick="navigateCpSlide('<?= $id ?>', <?= $total ?>, 1)">▶</button>
                </div>
                <div class="h5p-cp-nav-right">
                    <button class="h5p-cp-nav-btn h5p-cp-fullscreen" onclick="toggleCpFullscreen('<?= $id ?>')" title="Plein écran">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu du CoursePresentation en mode print (toutes les slides visibles)
     */
    private function renderH5pCoursePresentationPrint(array $slides, array $files, string $id): string {
        $total = count($slides);
        
        // Détecter les types d'activités
        $activityTypes = ['H5P.MultiChoice', 'H5P.Blanks', 'H5P.TrueFalse', 'H5P.SingleChoiceSet', 'H5P.DragQuestion'];
        
        ob_start();
        ?>
        <div class="h5p-coursepresentation-print" id="<?= $id ?>">
            <?php foreach ($slides as $i => $slide): 
                // Vérifier s'il y a une activité sur cette slide
                $hasActivity = false;
                foreach ($slide['elements'] ?? [] as $element) {
                    $lib = $element['action']['library'] ?? '';
                    $machineName = explode(' ', $lib)[0] ?? '';
                    if (in_array($machineName, $activityTypes)) {
                        $hasActivity = true;
                        break;
                    }
                }
            ?>
            <div class="h5p-cp-slide-print" data-idx="<?= $i ?>">
                <div class="slide-header">
                    <span class="slide-number">Slide <?= $i + 1 ?>/<?= $total ?></span>
                    <?php if ($hasActivity): ?>
                    <span class="h5p-cp-indicator">⭐ Activité</span>
                    <?php endif; ?>
                </div>
                <div class="slide-content">
                    <?php foreach ($slide['elements'] ?? [] as $element):
                        $lib = $element['action']['library'] ?? '';
                        $params = $element['action']['params'] ?? [];
                        $machineName = explode(' ', $lib)[0] ?? '';
                    ?>
                    <div class="h5p-cp-element-print">
                        <?php if ($machineName === 'H5P.Text' || $machineName === 'H5P.AdvancedText'): ?>
                        <div class="h5p-cp-text"><?= $params['text'] ?? '' ?></div>
                        <?php elseif ($machineName === 'H5P.Table'): ?>
                        <div class="h5p-cp-table"><?= $this->normalizeH5pTableHtml($params['text'] ?? '') ?></div>
                        <?php elseif ($machineName === 'H5P.Image' && !empty($params['file']['path'])): ?>
                        <img src="<?= $this->getH5pFileUrl($params['file']['path'], $files) ?>" class="h5p-cp-image-print" alt="">
                        <?php elseif ($machineName === 'H5P.Shape'): ?>
                        <?php 
                            $shapeType = $params['type'] ?? 'rectangle';
                            $lineParams = $params['line'] ?? [];
                            $lineBorderWidth = $lineParams['borderWidth'] ?? '2';
                            $lineBorderStyle = $lineParams['borderStyle'] ?? 'solid';
                            $lineBorderColor = $lineParams['borderColor'] ?? '#000000';
                        ?>
                        <?php if ($shapeType === 'horizontal-line'): ?>
                        <hr style="border: none; border-top: <?= $lineBorderWidth ?>px <?= $lineBorderStyle ?> <?= htmlspecialchars($lineBorderColor) ?>; margin: 10px 0;">
                        <?php elseif ($shapeType === 'vertical-line'): ?>
                        <div style="display: inline-block; border-left: <?= $lineBorderWidth ?>px <?= $lineBorderStyle ?> <?= htmlspecialchars($lineBorderColor) ?>; height: 50px;"></div>
                        <?php endif; ?>
                        <?php elseif ($machineName === 'H5P.MultiChoice'): ?>
                        <?= $this->renderH5pMultiChoicePrint($params) ?>
                        <?php elseif ($machineName === 'H5P.Blanks'): ?>
                        <?= $this->renderH5pBlanksPrint($params) ?>
                        <?php elseif ($machineName === 'H5P.TrueFalse'): ?>
                        <?= $this->renderH5pTrueFalsePrint($params) ?>
                        <?php elseif ($machineName === 'H5P.SingleChoiceSet'): ?>
                        <?= $this->renderH5pSingleChoiceSetPrint($params) ?>
                        <?php elseif ($machineName === 'H5P.DragQuestion'): ?>
                        <?= $this->renderH5pDragQuestionPrint($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.Dialogcards' || $machineName === 'H5P.DialogCards'): ?>
                        <?= $this->renderH5pDialogCardsPrint($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.ExportableTextArea'): ?>
                        <?= $this->renderH5pExportableTextAreaPrint($params, $files) ?>
                        <?php elseif ($machineName === 'H5P.Video'): ?>
                        <?= $this->renderH5pVideo($params, $files) ?>
                        <?php elseif (!empty($machineName)): ?>
                        <?= $this->renderH5pContent($machineName, $params, $files) ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu ExportableTextArea pour impression : consigne + lignes vierges à remplir
     */
    private function renderH5pExportableTextAreaPrint(array $params, array $files): string {
        $label = $params['label'] ?? '';
        ob_start();
        ?>
        <div class="h5p-eta-print">
            <?php if ($label !== ''): ?>
            <div class="h5p-eta-print-label"><?= $this->processH5pText($label, $files) ?></div>
            <?php endif; ?>
            <div class="h5p-eta-print-lines"><span></span><span></span><span></span></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Rendu MultiChoice pour impression
     */
    private function renderH5pMultiChoicePrint(array $params): string {
        $question = $params['question'] ?? '';
        $answers = $params['answers'] ?? [];
        
        ob_start();
        ?>
        <div class="h5p-question-print">
            <h4>❓ Question à choix multiple</h4>
            <div class="question-text"><?= $question ?></div>
            <ul class="h5p-options-print">
                <?php foreach ($answers as $answer): 
                    $isCorrect = $answer['correct'] ?? false;
                ?>
                <li class="<?= $isCorrect ? 'correct-answer' : '' ?>">
                    <?= $isCorrect ? '✓ ' : '○ ' ?><?= strip_tags($answer['text'] ?? '') ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu Blanks pour impression
     */
    private function renderH5pBlanksPrint(array $params): string {
        $questions = $params['questions'] ?? [];
        
        ob_start();
        ?>
        <div class="h5p-question-print">
            <h4>📝 Texte à trous</h4>
            <?php foreach ($questions as $q): 
                // Remplacer les *réponse* par des blancs visibles
                $text = $q['text'] ?? '';
                $text = preg_replace('/\*([^*]+)\*/', '<span class="blank-answer">[$1]</span>', $text);
            ?>
            <div class="question-text"><?= $text ?></div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu TrueFalse pour impression
     */
    private function renderH5pTrueFalsePrint(array $params): string {
        $question = $params['question'] ?? '';
        $correct = $params['correct'] ?? 'true';
        
        ob_start();
        ?>
        <div class="h5p-question-print">
            <h4>✓✗ Vrai ou Faux</h4>
            <div class="question-text"><?= $question ?></div>
            <ul class="h5p-options-print">
                <li class="<?= $correct === 'true' ? 'correct-answer' : '' ?>">
                    <?= $correct === 'true' ? '✓ ' : '○ ' ?>Vrai
                </li>
                <li class="<?= $correct === 'false' ? 'correct-answer' : '' ?>">
                    <?= $correct === 'false' ? '✓ ' : '○ ' ?>Faux
                </li>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu SingleChoiceSet pour impression
     */
    private function renderH5pSingleChoiceSetPrint(array $params): string {
        $choices = $params['choices'] ?? [];
        
        ob_start();
        ?>
        <div class="h5p-question-print">
            <h4>🎯 Choix unique</h4>
            <?php foreach ($choices as $choice): ?>
            <div class="question-text"><?= $choice['question'] ?? '' ?></div>
            <ul class="h5p-options-print">
                <?php foreach ($choice['answers'] ?? [] as $idx => $answer): ?>
                <li class="<?= $idx === 0 ? 'correct-answer' : '' ?>">
                    <?= $idx === 0 ? '✓ ' : '○ ' ?><?= strip_tags($answer) ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu DragQuestion pour impression
     */
    private function renderH5pDragQuestionPrint(array $params, array $files): string {
        $question = $params['question']['settings']['questionTitle'] ?? 'Glisser-déposer';
        $task = $params['question']['task'] ?? [];
        
        ob_start();
        ?>
        <div class="h5p-question-print">
            <h4>🎯 <?= htmlspecialchars($question) ?></h4>
            <?php if (!empty($task['elements'])): ?>
            <p><em>Éléments à placer :</em></p>
            <ul class="h5p-options-print">
                <?php foreach ($task['elements'] as $element): 
                    $text = $element['type']['params']['text'] ?? '';
                ?>
                <li><?= strip_tags($text) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pDialogCardsPrint(array $params, array $files): string {
        $cards = $params['dialogs'] ?? [];
        
        if (empty($cards)) {
            return '<div class="h5p-question-print"><p>Dialog Cards (vide)</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-question-print">
            <h4>🃏 Cartes dialogue</h4>
            <?php foreach ($cards as $i => $card): 
                $frontText = html_entity_decode($card['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $backText = html_entity_decode($card['answer'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            ?>
            <div style="margin: 0.5rem 0; padding: 0.5rem; background: #f5f5f5; border-radius: 8px;">
                <strong>Carte <?= $i + 1 ?> - Recto:</strong>
                <div><?= $this->processH5pText($frontText, $files) ?></div>
                <strong>Verso:</strong>
                <div><?= $this->processH5pText($backText, $files) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rendu d'un élément H5P.Video (vidéo simple, souvent dans CoursePresentation)
     */
    private function renderH5pVideo(array $content, array $files): string {
        $sources = $content['sources'] ?? [];
        if (empty($sources)) return '<div class="h5p-video-empty" style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;">Vidéo sans source</div>';
        
        $source = $sources[0];
        $path = $source['path'] ?? '';
        $mime = $source['mime'] ?? '';
        
        if (empty($path)) return '';
        
        // Vidéo YouTube
        if ($mime === 'video/YouTube' || strpos($path, 'youtu') !== false) {
            $ytId = '';
            if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([^&?\s]+)/', $path, $m)) {
                $ytId = $m[1];
            }
            if (!$ytId && preg_match('/^[a-zA-Z0-9_-]{11}$/', $path)) {
                $ytId = $path;
            }
            if ($ytId) {
                return '<div class="h5p-video-container" style="width:100%;height:100%;"><iframe src="https://www.youtube.com/embed/' . htmlspecialchars($ytId) . '?rel=0" style="width:100%;height:100%;border:none;" allowfullscreen></iframe></div>';
            }
        }
        
        // Vidéo fichier local
        $url = $this->getH5pFileUrl($path, $files);
        return '<div class="h5p-video-container" style="width:100%;height:100%;"><video src="' . htmlspecialchars($url) . '" controls style="width:100%;height:100%;object-fit:contain;"></video></div>';
    }

    /**
     * Rendu d'un élément H5P.Audio : bouton rond play/pause (player minimaliste H5P).
     * Le MP3 est joué au clic sur le bouton.
     */
    private function renderH5pAudio(array $content, array $files): string {
        $audioFiles = $content['files'] ?? [];
        if (empty($audioFiles)) {
            return '<div class="h5p-audio-empty" style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:0.8rem;">Audio sans fichier</div>';
        }
        $src = $this->getH5pFileUrl($audioFiles[0]['path'] ?? '', $files);
        $mime = $audioFiles[0]['mime'] ?? 'audio/mpeg';
        if (empty($src)) return '';

        $id = 'h5p-audio-' . uniqid();
        $autoplay = !empty($content['autoplay']);
        $playLabel = htmlspecialchars($content['playAudio'] ?? 'Lire le son');
        $pauseLabel = htmlspecialchars($content['pauseAudio'] ?? 'Mettre en pause');
        $notSupported = htmlspecialchars($content['audioNotSupported'] ?? "Votre navigateur ne supporte pas l'audio");

        ob_start();
        ?>
        <div class="h5p-audio-minimal" id="<?= $id ?>" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
            <!-- preload="none" : le bouton n'affiche ni durée ni progression, aucune raison de
                 télécharger le MP3 avant le clic. Un cours à 27 consignes audio déclenchait
                 sinon 27 requêtes au chargement, activité masquée ou non. -->
            <audio preload="<?= $autoplay ? 'metadata' : 'none' ?>"<?= $autoplay ? ' autoplay' : '' ?> style="display:none;">
                <source src="<?= htmlspecialchars($src) ?>" type="<?= htmlspecialchars($mime) ?>">
                <?= $notSupported ?>
            </audio>
            <button type="button" class="h5p-audio-btn" aria-label="<?= $playLabel ?>"
                    data-play-label="<?= $playLabel ?>" data-pause-label="<?= $pauseLabel ?>"
                    style="width:100%;height:100%;min-width:36px;min-height:36px;aspect-ratio:1;border:none;border-radius:50%;
                           background:#1a73e8;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;
                           box-shadow:0 1px 4px rgba(0,0,0,0.3);padding:0;box-sizing:border-box;">
                <svg class="h5p-audio-icon-play" viewBox="0 0 24 24" width="55%" height="55%" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                <svg class="h5p-audio-icon-pause" viewBox="0 0 24 24" width="55%" height="55%" fill="currentColor" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            </button>
        </div>
        <script>
        (function() {
            var wrap = document.getElementById('<?= $id ?>');
            if (!wrap || wrap.dataset.init) return;
            wrap.dataset.init = '1';
            var audio = wrap.querySelector('audio');
            var btn = wrap.querySelector('.h5p-audio-btn');
            var iconPlay = wrap.querySelector('.h5p-audio-icon-play');
            var iconPause = wrap.querySelector('.h5p-audio-icon-pause');
            function setPlaying(playing) {
                iconPlay.style.display = playing ? 'none' : 'block';
                iconPause.style.display = playing ? 'block' : 'none';
                btn.setAttribute('aria-label', playing ? btn.dataset.pauseLabel : btn.dataset.playLabel);
            }
            btn.addEventListener('click', function(e) {
                e.preventDefault(); e.stopPropagation();
                if (audio.paused) {
                    // Un seul audio à la fois : couper tous les autres lecteurs du cours
                    document.querySelectorAll('.h5p-audio-minimal audio').forEach(function(a) {
                        if (a !== audio) a.pause();
                    });
                    audio.play().catch(function(){});
                } else { audio.pause(); }
            });
            audio.addEventListener('play', function() { setPlaying(true); });
            audio.addEventListener('pause', function() { setPlaying(false); });
            audio.addEventListener('ended', function() { setPlaying(false); });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private function renderH5pInteractiveVideo(array $content, array $files): string {
        $video = $content['interactiveVideo']['video']['files'][0] ?? [];
        $assets = $content['interactiveVideo']['assets'] ?? [];
        $interactions = $assets['interactions'] ?? [];
        $id = 'h5p-iv-' . uniqid();
        
        // Déterminer l'URL de la vidéo
        $videoPath = $video['path'] ?? '';
        // Si c'est une URL externe (http/https), l'utiliser directement
        if (preg_match('/^https?:\/\//', $videoPath)) {
            $videoUrl = $videoPath;
        } else {
            $videoUrl = $this->getH5pFileUrl($videoPath, $files);
        }
        
        // Préparer les interactions pour JavaScript
        $jsInteractions = [];
        foreach ($interactions as $idx => $inter) {
            $lib = $inter['action']['library'] ?? '';
            $machineName = explode(' ', $lib)[0] ?? '';
            $params = $inter['action']['params'] ?? [];
            $label = $inter['label'] ?? '';
            $displayType = $inter['displayType'] ?? 'poster'; // 'button' ou 'poster'
            
            // Décoder le HTML des labels
            $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $labelText = strip_tags($label);
            
            // ID unique pour cette interaction
            $interId = $id . '-inter-' . $idx;
            
            // Contenu selon le type - rendu interactif
            $htmlContent = '';
            $correctData = null; // Données pour la validation
            
            if ($machineName === 'H5P.Text' || $machineName === 'H5P.AdvancedText') {
                $text = html_entity_decode($params['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $htmlContent = '<div class="iv-text-content">' . $text . '</div>';
                
            } elseif ($machineName === 'H5P.Nil') {
                // Juste un label/info tip
                $htmlContent = '<div class="iv-label-content">' . $label . '</div>';
                
            } elseif ($machineName === 'H5P.MultiChoice') {
                $question = html_entity_decode($params['question'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $answers = $params['answers'] ?? [];
                $correctIndices = [];
                
                $htmlContent = '<div class="iv-multichoice" data-id="' . $interId . '">';
                $htmlContent .= '<div class="iv-mc-question">' . $question . '</div>';
                $htmlContent .= '<div class="iv-mc-answers">';
                
                foreach ($answers as $aIdx => $ans) {
                    $ansText = html_entity_decode($ans['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $isCorrect = $ans['correct'] ?? false;
                    if ($isCorrect) $correctIndices[] = $aIdx;
                    
                    $inputType = count(array_filter($answers, fn($a) => $a['correct'] ?? false)) > 1 ? 'checkbox' : 'radio';
                    
                    $htmlContent .= '<label class="iv-mc-option" data-idx="' . $aIdx . '">';
                    $htmlContent .= '<input type="' . $inputType . '" name="' . $interId . '-ans" value="' . $aIdx . '">';
                    $htmlContent .= '<span class="iv-mc-text">' . strip_tags($ansText) . '</span>';
                    $htmlContent .= '</label>';
                }
                
                $htmlContent .= '</div>';
                $htmlContent .= '<button class="iv-check-btn">Vérifier</button>';
                $htmlContent .= '<div class="iv-feedback" id="' . $interId . '-feedback"></div>';
                $htmlContent .= '</div>';
                
                $correctData = $correctIndices;
                
            } elseif ($machineName === 'H5P.TrueFalse') {
                $question = html_entity_decode($params['question'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $correct = ($params['correct'] ?? 'true') === 'true';
                
                $htmlContent = '<div class="iv-truefalse" data-id="' . $interId . '">';
                $htmlContent .= '<div class="iv-tf-question">' . htmlspecialchars($question) . '</div>';
                $htmlContent .= '<div class="iv-tf-buttons">';
                $htmlContent .= '<button class="iv-tf-btn iv-tf-true">✓ Vrai</button>';
                $htmlContent .= '<button class="iv-tf-btn iv-tf-false">✗ Faux</button>';
                $htmlContent .= '</div>';
                $htmlContent .= '<div class="iv-feedback" id="' . $interId . '-feedback"></div>';
                $htmlContent .= '</div>';
                
                $correctData = $correct;
                
            } elseif ($machineName === 'H5P.Blanks') {
                $text = $params['text'] ?? '';
                $questions = $params['questions'] ?? [$text];
                $textWithBlanks = is_array($questions) ? ($questions[0] ?? $text) : $text;
                $textWithBlanks = html_entity_decode($textWithBlanks, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $textWithBlanks = strip_tags($textWithBlanks);
                
                // Extraire les mots corrects et créer les champs
                $blanks = [];
                $blankIdx = 0;
                $processedText = preg_replace_callback('/\*([^*]+)\*/', function($matches) use ($interId, &$blankIdx, &$blanks) {
                    $correct = $matches[1];
                    // Gérer les alternatives (mot1/mot2)
                    $alternatives = array_map('trim', explode('/', $correct));
                    $blanks[] = $alternatives;
                    $inputHtml = '<input type="text" class="iv-blank-input" data-idx="' . $blankIdx . '" id="' . $interId . '-blank-' . $blankIdx . '" autocomplete="off">';
                    $blankIdx++;
                    return $inputHtml;
                }, $textWithBlanks);
                
                $htmlContent = '<div class="iv-blanks" data-id="' . $interId . '">';
                $htmlContent .= '<div class="iv-blanks-text">' . $processedText . '</div>';
                $htmlContent .= '<button class="iv-check-btn">Vérifier</button>';
                $htmlContent .= '<div class="iv-feedback" id="' . $interId . '-feedback"></div>';
                $htmlContent .= '</div>';
                
                $correctData = $blanks;
                
            } elseif ($machineName === 'H5P.SingleChoiceSet') {
                // SingleChoiceSet : série de questions à choix unique
                $choices = $params['choices'] ?? [];
                if (!empty($choices) && !empty($choices[0]['question'])) {
                    $firstChoice = $choices[0];
                    $question = html_entity_decode($firstChoice['question'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $answers = $firstChoice['answers'] ?? [];
                    
                    $htmlContent = '<div class="iv-singlechoice" data-id="' . $interId . '">';
                    $htmlContent .= '<div class="iv-sc-question">' . $question . '</div>';
                    $htmlContent .= '<div class="iv-sc-answers">';
                    
                    // La première réponse est toujours la bonne dans SingleChoiceSet
                    // Mais on va les mélanger pour l'affichage
                    $answerData = [];
                    foreach ($answers as $aIdx => $ans) {
                        $ansText = html_entity_decode($ans, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $answerData[] = ['text' => strip_tags($ansText), 'correct' => ($aIdx === 0)];
                    }
                    // Mélanger les réponses
                    shuffle($answerData);
                    
                    foreach ($answerData as $aIdx => $ans) {
                        $htmlContent .= '<button class="iv-sc-option" data-idx="' . $aIdx . '" data-correct="' . ($ans['correct'] ? '1' : '0') . '">';
                        $htmlContent .= '<span class="iv-sc-text">' . $ans['text'] . '</span>';
                        $htmlContent .= '</button>';
                    }
                    
                    $htmlContent .= '</div>';
                    $htmlContent .= '<div class="iv-feedback" id="' . $interId . '-feedback"></div>';
                    $htmlContent .= '</div>';
                } else {
                    $htmlContent = '<div class="iv-generic"><em>(SingleChoiceSet vide)</em></div>';
                }
                
            } else {
                $htmlContent = '<div class="iv-generic">' . ($label ?: '<em>(Interaction ' . htmlspecialchars($machineName) . ')</em>') . '</div>';
            }
            
            $jsInteractions[] = [
                'idx' => $idx,
                'from' => floatval($inter['duration']['from'] ?? 0),
                'to' => floatval($inter['duration']['to'] ?? 0),
                'pause' => $inter['pause'] ?? true,
                'x' => floatval($inter['x'] ?? 50),
                'y' => floatval($inter['y'] ?? 50),
                'width' => floatval($inter['width'] ?? 10),
                'height' => floatval($inter['height'] ?? 10),
                'type' => $machineName,
                'displayType' => $displayType,
                'label' => $labelText,
                'content' => $htmlContent,
                'correctData' => $correctData
            ];
        }
        
        ob_start();
        ?>
        <div class="h5p-interactivevideo" id="<?= $id ?>">
            <?php if (!empty($videoUrl)): ?>
            <div class="iv-container">
                <div class="iv-video-wrapper">
                    <video id="<?= $id ?>-video" class="iv-video" playsinline preload="metadata">
                        <source src="<?= htmlspecialchars($videoUrl) ?>" type="<?= htmlspecialchars($video['mime'] ?? 'video/mp4') ?>">
                        Votre navigateur ne supporte pas la lecture vidéo.
                    </video>
                    <div class="iv-overlay" id="<?= $id ?>-overlay"></div>
                    <div class="iv-loading" id="<?= $id ?>-loading">⏳ Chargement...</div>
                </div>
                <div class="iv-controls-wrapper">
                    <div class="iv-progress-container">
                        <div class="iv-progress-bar" id="<?= $id ?>-progress-bar">
                            <div class="iv-progress-played" id="<?= $id ?>-progress-played"></div>
                            <div class="iv-progress-markers" id="<?= $id ?>-markers"></div>
                        </div>
                    </div>
                    <div class="iv-controls">
                        <button class="iv-btn" id="<?= $id ?>-play" title="Lecture/Pause">
                            <svg id="<?= $id ?>-icon-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            <svg id="<?= $id ?>-icon-pause" viewBox="0 0 24 24" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                        </button>
                        <span class="iv-time" id="<?= $id ?>-time">0:00 / 0:00</span>
                        <div class="iv-spacer"></div>
                        <button class="iv-speed-btn" id="<?= $id ?>-speed" title="Vitesse">1x</button>
                        <button class="iv-btn" id="<?= $id ?>-fullscreen" title="Plein écran">
                            <svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="iv-error" id="<?= $id ?>-error" style="display:none;">
                    ⚠️ Erreur de chargement vidéo
                </div>
            </div>
            <script>
            // Fonctions globales pour vérifier les interactions
            function ivCheckMultiChoice(interId, correctIndices) {
                var container = document.querySelector('.iv-multichoice[data-id="' + interId + '"]');
                if (!container || !correctIndices) return;
                
                var options = container.querySelectorAll('.iv-mc-option');
                var selectedIndices = [];
                
                options.forEach(function(opt) {
                    var input = opt.querySelector('input');
                    var idx = parseInt(opt.dataset.idx);
                    if (input && input.checked) {
                        selectedIndices.push(idx);
                    }
                    // Reset classes
                    opt.classList.remove('correct', 'incorrect', 'was-correct');
                });
                
                // Vérifier les réponses
                var allCorrect = true;
                options.forEach(function(opt) {
                    var idx = parseInt(opt.dataset.idx);
                    var isSelected = selectedIndices.includes(idx);
                    var isCorrect = correctIndices.includes(idx);
                    
                    if (isSelected && isCorrect) {
                        opt.classList.add('correct');
                    } else if (isSelected && !isCorrect) {
                        opt.classList.add('incorrect');
                        allCorrect = false;
                    } else if (!isSelected && isCorrect) {
                        opt.classList.add('was-correct');
                        allCorrect = false;
                    }
                });
                
                // Afficher le feedback
                var feedback = document.getElementById(interId + '-feedback');
                if (feedback) {
                    feedback.className = 'iv-feedback show ' + (allCorrect ? 'correct' : 'incorrect');
                    feedback.textContent = allCorrect ? '✓ Bonne réponse !' : '✗ Essayez encore';
                }
                
                // Désactiver le bouton
                var btn = container.querySelector('.iv-check-btn');
                if (btn && allCorrect) btn.disabled = true;
            }
            
            function ivCheckTrueFalse(interId, answer, correct) {
                var container = document.querySelector('.iv-truefalse[data-id="' + interId + '"]');
                if (!container) return;
                
                var isCorrect = (answer === correct);
                
                // Reset et appliquer les styles
                var trueBtn = container.querySelector('.iv-tf-true');
                var falseBtn = container.querySelector('.iv-tf-false');
                
                trueBtn.classList.remove('selected-correct', 'selected-incorrect', 'was-correct');
                falseBtn.classList.remove('selected-correct', 'selected-incorrect', 'was-correct');
                
                if (answer === true) {
                    trueBtn.classList.add(isCorrect ? 'selected-correct' : 'selected-incorrect');
                    if (!isCorrect) falseBtn.classList.add('was-correct');
                } else {
                    falseBtn.classList.add(isCorrect ? 'selected-correct' : 'selected-incorrect');
                    if (!isCorrect) trueBtn.classList.add('was-correct');
                }
                
                // Afficher le feedback
                var feedback = document.getElementById(interId + '-feedback');
                if (feedback) {
                    feedback.className = 'iv-feedback show ' + (isCorrect ? 'correct' : 'incorrect');
                    feedback.textContent = isCorrect ? '✓ Bonne réponse !' : '✗ Mauvaise réponse';
                }
            }
            
            function ivCheckBlanks(interId, blanksData) {
                var container = document.querySelector('.iv-blanks[data-id="' + interId + '"]');
                if (!container || !blanksData) return;
                
                var inputs = container.querySelectorAll('.iv-blank-input');
                var allCorrect = true;
                
                inputs.forEach(function(input) {
                    var idx = parseInt(input.dataset.idx);
                    var userAnswer = input.value.trim().toLowerCase();
                    var correctAlternatives = blanksData[idx] || [];
                    
                    // Vérifier si la réponse correspond à une des alternatives
                    var isCorrect = correctAlternatives.some(function(alt) {
                        return alt.toLowerCase() === userAnswer;
                    });
                    
                    input.classList.remove('correct', 'incorrect');
                    input.classList.add(isCorrect ? 'correct' : 'incorrect');
                    
                    if (!isCorrect) allCorrect = false;
                });
                
                // Afficher le feedback
                var feedback = document.getElementById(interId + '-feedback');
                if (feedback) {
                    feedback.className = 'iv-feedback show ' + (allCorrect ? 'correct' : 'incorrect');
                    feedback.textContent = allCorrect ? '✓ Parfait !' : '✗ Vérifiez vos réponses';
                }
                
                // Désactiver le bouton si tout est correct
                var btn = container.querySelector('.iv-check-btn');
                if (btn && allCorrect) btn.disabled = true;
            }
            
            function ivCheckSingleChoice(clickedBtn) {
                var container = clickedBtn.closest('.iv-singlechoice');
                if (!container) return;
                
                var interId = container.dataset.id;
                var isCorrect = clickedBtn.dataset.correct === '1';
                var options = container.querySelectorAll('.iv-sc-option');
                
                // Désactiver tous les boutons
                options.forEach(function(opt) {
                    opt.disabled = true;
                    opt.classList.remove('correct', 'incorrect', 'was-correct');
                    
                    if (opt === clickedBtn) {
                        opt.classList.add(isCorrect ? 'correct' : 'incorrect');
                    } else if (opt.dataset.correct === '1') {
                        opt.classList.add('was-correct');
                    }
                });
                
                // Afficher le feedback
                var feedback = document.getElementById(interId + '-feedback');
                if (feedback) {
                    feedback.className = 'iv-feedback show ' + (isCorrect ? 'correct' : 'incorrect');
                    feedback.textContent = isCorrect ? '✓ Bonne réponse !' : '✗ Mauvaise réponse';
                }
            }
            
            (function() {
                var id = '<?= $id ?>';
                var initialized = false;
                var interactions = <?= json_encode($jsInteractions, JSON_UNESCAPED_UNICODE) ?>;
                var activeInteractions = {};
                var seenInteractions = {}; // Interactions déjà déclenchées (ne pas re-déclencher)
                var pausedForInteraction = false;
                var ivLastTime = 0; // temps du tick précédent (pour détecter le franchissement)
                var speeds = [0.5, 0.75, 1, 1.25, 1.5, 2];
                var currentSpeedIndex = 2; // 1x par défaut
                
                function initVideo() {
                    if (initialized) return;
                    
                    var container = document.getElementById(id);
                    if (!container) return;
                    
                    // Vérifier si visible
                    if (container.offsetWidth === 0) {
                        return; // Pas encore visible, sera initialisé plus tard
                    }
                    
                    initialized = true;
                    
                    var video = document.getElementById(id + '-video');
                    var overlay = document.getElementById(id + '-overlay');
                    var progressBar = document.getElementById(id + '-progress-bar');
                    var progressPlayed = document.getElementById(id + '-progress-played');
                    var markersContainer = document.getElementById(id + '-markers');
                    var timeDisplay = document.getElementById(id + '-time');
                    var playBtn = document.getElementById(id + '-play');
                    var iconPlay = document.getElementById(id + '-icon-play');
                    var iconPause = document.getElementById(id + '-icon-pause');
                    var speedBtn = document.getElementById(id + '-speed');
                    var fullscreenBtn = document.getElementById(id + '-fullscreen');
                    var loading = document.getElementById(id + '-loading');
                    var errorDiv = document.getElementById(id + '-error');
                    var ivContainer = document.querySelector('#' + id + ' .iv-container');
                    
                    if (!video) return;
                    
                    function formatTime(sec) {
                        var m = Math.floor(sec / 60);
                        var s = Math.floor(sec % 60);
                        return m + ':' + (s < 10 ? '0' : '') + s;
                    }
                    
                    function updateTime() {
                        var current = video.currentTime;
                        var duration = video.duration || 0;
                        timeDisplay.textContent = formatTime(current) + ' / ' + formatTime(duration);
                        var percent = duration ? (current / duration) * 100 : 0;
                        progressPlayed.style.width = percent + '%';
                    }
                    
                    // Créer les marqueurs d'interaction sur la barre de progression
                    function createMarkers() {
                        if (!video.duration) return;
                        markersContainer.innerHTML = '';
                        
                        // Filtrer les interactions qui provoquent une pause
                        var pauseInteractions = interactions.filter(function(i) { return i.pause; });
                        
                        // Grouper par temps (éviter les doublons visuels)
                        var times = {};
                        pauseInteractions.forEach(function(inter) {
                            var timeKey = Math.round(inter.from * 10) / 10; // Arrondir à 0.1s
                            if (!times[timeKey]) {
                                times[timeKey] = inter.from;
                            }
                        });
                        
                        Object.values(times).forEach(function(time) {
                            var percent = (time / video.duration) * 100;
                            var marker = document.createElement('div');
                            marker.className = 'iv-marker';
                            marker.style.left = percent + '%';
                            marker.title = 'Interaction à ' + formatTime(time);
                            markersContainer.appendChild(marker);
                        });
                    }
                    
                    function updatePlayButton() {
                        if (video.paused) {
                            iconPlay.style.display = 'block';
                            iconPause.style.display = 'none';
                        } else {
                            iconPlay.style.display = 'none';
                            iconPause.style.display = 'block';
                        }
                    }
                    
                    // Ajuster la position de l'overlay pour correspondre à la vidéo
                    function updateOverlayPosition() {
                        if (!video || !overlay) return;
                        
                        var videoRect = video.getBoundingClientRect();
                        var wrapperRect = video.parentElement.getBoundingClientRect();
                        
                        // Calculer les dimensions réelles de la vidéo affichée (avec object-fit: contain)
                        var videoRatio = video.videoWidth / video.videoHeight;
                        var containerRatio = wrapperRect.width / wrapperRect.height;
                        
                        var displayWidth, displayHeight, offsetX, offsetY;
                        
                        if (videoRatio > containerRatio) {
                            // Vidéo plus large : bandes en haut/bas
                            displayWidth = wrapperRect.width;
                            displayHeight = wrapperRect.width / videoRatio;
                            offsetX = 0;
                            offsetY = (wrapperRect.height - displayHeight) / 2;
                        } else {
                            // Vidéo plus haute : bandes sur les côtés
                            displayHeight = wrapperRect.height;
                            displayWidth = wrapperRect.height * videoRatio;
                            offsetX = (wrapperRect.width - displayWidth) / 2;
                            offsetY = 0;
                        }
                        
                        overlay.style.left = offsetX + 'px';
                        overlay.style.top = offsetY + 'px';
                        overlay.style.width = displayWidth + 'px';
                        overlay.style.height = displayHeight + 'px';
                    }
                    
                    function checkInteractions() {
                        var t = video.currentTime;
                        var pauseAt = null;

                        interactions.forEach(function(inter) {
                            // Pour les interactions avec durée 0 (from === to),
                            // afficher seulement quand on atteint le temps exact
                            var hasDuration = inter.to > inter.from;
                            var isInTimeRange;

                            if (hasDuration) {
                                // Interaction avec durée : active pendant l'intervalle
                                isInTimeRange = (t >= inter.from && t <= inter.to + 0.3);
                            } else {
                                // Interaction ponctuelle (durée 0) : active dans une fenêtre de 0.5s
                                isInTimeRange = (t >= inter.from && t <= inter.from + 0.5);
                            }

                            // Afficher / cacher l'interaction selon la zone de temps
                            if (isInTimeRange && !activeInteractions[inter.idx]) {
                                showInteraction(inter);
                                activeInteractions[inter.idx] = true;
                            } else if (!isInTimeRange && activeInteractions[inter.idx]) {
                                hideInteraction(inter.idx);
                                activeInteractions[inter.idx] = false;
                            }

                            // Déclenchement de la pause : on détecte le FRANCHISSEMENT de `from`
                            // entre deux ticks (la fenêtre from→to peut être plus courte que
                            // l'intervalle entre deux ticks). Sur un seek, ivLastTime est calé
                            // sur la cible → pas de franchissement parasite.
                            var crossed = ivLastTime < inter.from && t >= inter.from;
                            if (inter.pause && crossed && !seenInteractions[inter.idx] && !pausedForInteraction) {
                                seenInteractions[inter.idx] = true;
                                if (pauseAt === null || inter.from < pauseAt) pauseAt = inter.from;
                            }

                            // Reset "seen" quand on revient avant l'interaction (pour pouvoir la revoir)
                            if (t < inter.from - 0.3) {
                                seenInteractions[inter.idx] = false;
                            }
                        });

                        if (pauseAt !== null) {
                            // Caler la vidéo exactement sur l'interaction pour que le cadre s'affiche
                            video.currentTime = pauseAt;
                            video.pause();
                            pausedForInteraction = true;
                            ivLastTime = pauseAt;
                            updatePlayButton();
                        } else {
                            ivLastTime = t;
                        }
                    }
                    
                    function showInteraction(inter) {
                        var el = document.createElement('div');
                        el.className = 'iv-interaction iv-type-' + inter.type.replace('H5P.', '').toLowerCase();
                        el.id = id + '-inter-' + inter.idx;
                        el.style.left = inter.x + '%';
                        el.style.top = inter.y + '%';
                        
                        if (inter.displayType === 'button') {
                            // Mode bouton : afficher un bouton cliquable
                            var btnLabel = inter.label || '?';
                            el.className += ' iv-display-button';
                            el.innerHTML = '<button class="iv-inter-btn" title="' + btnLabel + '">' + 
                                '<span class="iv-inter-btn-icon">+</span>' +
                                '<span class="iv-inter-btn-label">' + btnLabel + '</span>' +
                                '</button>' +
                                '<div class="iv-inter-popup" style="display:none;">' +
                                '<button class="iv-inter-popup-close">&times;</button>' +
                                '<div class="iv-inter-popup-content">' + inter.content + '</div>' +
                                '</div>';
                            
                            // Gestionnaire de clic sur le bouton
                            var btn = el.querySelector('.iv-inter-btn');
                            var popup = el.querySelector('.iv-inter-popup');
                            var closeBtn = el.querySelector('.iv-inter-popup-close');
                            
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                popup.style.display = popup.style.display === 'none' ? 'block' : 'none';
                            });
                            
                            closeBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                popup.style.display = 'none';
                            });
                        } else {
                            // Mode poster : afficher directement le contenu
                            if (inter.type === 'H5P.Nil' && inter.label) {
                                el.innerHTML = '<div class="iv-label">' + inter.label + '</div>';
                            } else {
                                el.innerHTML = '<div class="iv-interaction-content">' + inter.content + '</div>';
                            }
                        }
                        
                        overlay.appendChild(el);
                        
                        // Attacher les événements pour les interactions (après insertion dans le DOM)
                        attachInteractionEvents(el, inter);
                    }
                    
                    // Attacher les événements aux éléments interactifs
                    function attachInteractionEvents(container, inter) {
                        // Bouton Vérifier pour MultiChoice
                        var mcCheckBtn = container.querySelector('.iv-multichoice .iv-check-btn');
                        if (mcCheckBtn) {
                            var interId = container.querySelector('.iv-multichoice').dataset.id;
                            mcCheckBtn.addEventListener('click', function() {
                                ivCheckMultiChoice(interId, inter.correctData);
                            });
                        }
                        
                        // Boutons Vrai/Faux
                        var tfContainer = container.querySelector('.iv-truefalse');
                        if (tfContainer) {
                            var interId = tfContainer.dataset.id;
                            var trueBtn = tfContainer.querySelector('.iv-tf-true');
                            var falseBtn = tfContainer.querySelector('.iv-tf-false');
                            if (trueBtn) {
                                trueBtn.addEventListener('click', function() {
                                    ivCheckTrueFalse(interId, true, inter.correctData);
                                });
                            }
                            if (falseBtn) {
                                falseBtn.addEventListener('click', function() {
                                    ivCheckTrueFalse(interId, false, inter.correctData);
                                });
                            }
                        }
                        
                        // Bouton Vérifier pour Blanks
                        var blanksCheckBtn = container.querySelector('.iv-blanks .iv-check-btn');
                        if (blanksCheckBtn) {
                            var interId = container.querySelector('.iv-blanks').dataset.id;
                            blanksCheckBtn.addEventListener('click', function() {
                                ivCheckBlanks(interId, inter.correctData);
                            });
                        }
                        
                        // Boutons SingleChoice
                        var scOptions = container.querySelectorAll('.iv-sc-option');
                        scOptions.forEach(function(opt) {
                            opt.addEventListener('click', function() {
                                ivCheckSingleChoice(opt);
                            });
                        });
                    }
                    
                    function hideInteraction(idx) {
                        var el = document.getElementById(id + '-inter-' + idx);
                        if (el) el.remove();
                    }
                    
                    // Event listeners
                    video.addEventListener('loadedmetadata', function() {
                        loading.style.display = 'none';
                        updateTime();
                        createMarkers();
                        updateOverlayPosition();
                    });
                    
                    video.addEventListener('canplay', function() {
                        loading.style.display = 'none';
                    });
                    
                    video.addEventListener('waiting', function() {
                        loading.style.display = 'flex';
                    });
                    
                    video.addEventListener('playing', function() {
                        loading.style.display = 'none';
                        // Cacher les interactions ponctuelles (durée 0) quand la vidéo reprend
                        interactions.forEach(function(inter) {
                            if (inter.to <= inter.from && activeInteractions[inter.idx]) {
                                hideInteraction(inter.idx);
                                activeInteractions[inter.idx] = false;
                            }
                        });
                    });
                    
                    video.addEventListener('timeupdate', function() {
                        updateTime();
                        checkInteractions();
                    });
                    
                    video.addEventListener('play', function() {
                        updatePlayButton();
                        pausedForInteraction = false;
                    });
                    video.addEventListener('pause', updatePlayButton);
                    
                    video.addEventListener('ended', function() {
                        overlay.innerHTML = '';
                        activeInteractions = {};
                        seenInteractions = {};
                        updatePlayButton();
                    });
                    
                    // Mettre à jour l'overlay quand la fenêtre est redimensionnée
                    window.addEventListener('resize', updateOverlayPosition);
                    
                    video.addEventListener('error', function(e) {
                        loading.style.display = 'none';
                        errorDiv.style.display = 'block';
                        errorDiv.innerHTML = '⚠️ Erreur de chargement vidéo. <a href="<?= htmlspecialchars($videoUrl) ?>" target="_blank">Ouvrir dans un nouvel onglet</a>';
                        console.error('Video error:', e);
                    });
                    
                    playBtn.addEventListener('click', function() {
                        if (video.paused) {
                            // Si on était en pause à cause d'une interaction, avancer légèrement
                            if (pausedForInteraction) {
                                video.currentTime += 0.2;
                                // Fermer les interactions visuellement
                                overlay.innerHTML = '';
                                activeInteractions = {};
                            }
                            pausedForInteraction = false;
                            video.play().catch(function(err) {
                                console.error('Play error:', err);
                                errorDiv.style.display = 'block';
                                errorDiv.textContent = '⚠️ Impossible de lire la vidéo: ' + err.message;
                            });
                        } else {
                            video.pause();
                        }
                    });
                    
                    // Clic sur la barre de progression (seek)
                    progressBar.addEventListener('click', function(e) {
                        var rect = progressBar.getBoundingClientRect();
                        var percent = (e.clientX - rect.left) / rect.width;
                        video.currentTime = percent * video.duration;
                        // Réinitialiser tout pour permettre de revoir les interactions
                        overlay.innerHTML = '';
                        activeInteractions = {};
                        seenInteractions = {};
                        pausedForInteraction = false;
                        ivLastTime = video.currentTime; // cale le temps de référence sur la cible (pas de pause parasite)
                    });
                    
                    // Bouton vitesse
                    speedBtn.addEventListener('click', function() {
                        currentSpeedIndex = (currentSpeedIndex + 1) % speeds.length;
                        var newSpeed = speeds[currentSpeedIndex];
                        video.playbackRate = newSpeed;
                        speedBtn.textContent = newSpeed + 'x';
                    });
                    
                    // Plein écran
                    fullscreenBtn.addEventListener('click', function() {
                        if (document.fullscreenElement || document.webkitFullscreenElement) {
                            if (document.exitFullscreen) {
                                document.exitFullscreen();
                            } else if (document.webkitExitFullscreen) {
                                document.webkitExitFullscreen();
                            }
                        } else {
                            if (ivContainer.requestFullscreen) {
                                ivContainer.requestFullscreen();
                            } else if (ivContainer.webkitRequestFullscreen) {
                                ivContainer.webkitRequestFullscreen();
                            }
                        }
                    });
                    
                    // Charger la vidéo
                    video.load();
                }
                
                // Observer pour détecter quand l'élément devient visible
                function setupObserver() {
                    var container = document.getElementById(id);
                    if (!container) {
                        setTimeout(setupObserver, 100);
                        return;
                    }
                    
                    // Essayer d'initialiser immédiatement si visible
                    if (container.offsetWidth > 0) {
                        initVideo();
                        return;
                    }
                    
                    // Sinon utiliser IntersectionObserver
                    if ('IntersectionObserver' in window) {
                        var observer = new IntersectionObserver(function(entries) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting && entry.intersectionRatio > 0) {
                                    initVideo();
                                }
                            });
                        }, { threshold: 0.1 });
                        observer.observe(container);
                    } else {
                        // Fallback: vérifier périodiquement
                        var checkInterval = setInterval(function() {
                            if (container.offsetWidth > 0) {
                                clearInterval(checkInterval);
                                initVideo();
                            }
                        }, 300);
                    }
                }
                
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', setupObserver);
                } else {
                    setupObserver();
                }
            })();
            </script>
            <?php else: ?>
            <div class="h5p-placeholder">
                <p>🎬 Vidéo interactive</p>
                <small>Le fichier vidéo n'est pas disponible</small>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pImageHotspots(array $content, array $files): string {
        $image = $content['image'] ?? [];
        $hotspots = $content['hotspots'] ?? [];
        $id = 'h5p-hotspots-' . uniqid();
        
        ob_start();
        ?>
        <div class="h5p-imagehotspots" id="<?= $id ?>">
            <div class="h5p-hotspots-container" style="position:relative;">
                <?php if (!empty($image['path'])): ?>
                <img src="<?= $this->getH5pFileUrl($image['path'], $files) ?>" style="max-width:100%;">
                <?php endif; ?>
                
                <?php foreach ($hotspots as $hotspot): 
                    $x = $hotspot['position']['x'] ?? 50;
                    $y = $hotspot['position']['y'] ?? 50;
                    $popupContent = $hotspot['content'][0]['params']['text'] ?? '';
                ?>
                <button class="h5p-hotspot" style="position:absolute;left:<?= $x ?>%;top:<?= $y ?>%;" 
                        onclick="showHotspotContent(this)" data-content="<?= htmlspecialchars($this->processH5pText($popupContent, $files)) ?>">
                    <span>+</span>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="h5p-hotspot-popup" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * H5P.ImageMultipleHotspotQuestion (« Trouver les zones ») : une image de fond et des
     * zones à retrouver au clic. x/y/width/height sont des POURCENTAGES de l'image de fond ;
     * x/y désignent le COIN HAUT-GAUCHE de la zone, pas son centre.
     * Comme dans Éléa : une zone juste se marque d'une pastille verte, un clic à côté
     * d'une croix rouge, et une barre de score « trouvées / total » s'affiche dessous.
     */
    private function renderH5pMultiHotspot(array $content, array $files): string {
        $q = $content['imageMultipleHotspotQuestion'] ?? [];
        $bgSettings = $q['backgroundImageSettings'] ?? [];
        $image = $bgSettings['backgroundImage'] ?? [];
        $hotspots = $q['hotspotSettings']['hotspot'] ?? [];

        if (empty($image['path'])) {
            return '<div class="h5p-placeholder"><p>Zones à trouver : image de fond manquante</p></div>';
        }

        $id = 'h5p-fmh-' . uniqid();
        $imgUrl = $this->getH5pFileUrl($image['path'], $files);

        // Normaliser les zones ; une zone sans « correct » explicite est une bonne réponse
        $zones = [];
        foreach ($hotspots as $hs) {
            $cs = $hs['computedSettings'] ?? [];
            $us = $hs['userSettings'] ?? [];
            if (!isset($cs['x'], $cs['y'])) continue;
            $zones[] = [
                'x'       => (float)$cs['x'],
                'y'       => (float)$cs['y'],
                'w'       => (float)($cs['width'] ?? 5),
                'h'       => (float)($cs['height'] ?? 5),
                'figure'  => ($cs['figure'] ?? 'circle') === 'rectangle' ? 'rectangle' : 'circle',
                'correct' => ($us['correct'] ?? true) !== false,
                'feedback' => (string)($us['feedbackText'] ?? ''),
            ];
        }
        $total = count(array_filter($zones, fn($z) => $z['correct']));

        // Impression : l'image avec toutes les bonnes zones entourées (corrigé papier)
        if ($this->printMode) {
            ob_start();
            ?>
            <div class="h5p-fmh-print">
                <div class="h5p-fmh-print-image">
                    <img src="<?= $imgUrl ?>" alt="">
                    <?php foreach ($zones as $z): if (!$z['correct']) continue; ?>
                    <span class="h5p-fmh-print-zone<?= $z['figure'] === 'rectangle' ? ' rect' : '' ?>"
                          style="left:<?= $z['x'] ?>%;top:<?= $z['y'] ?>%;width:<?= $z['w'] ?>%;height:<?= $z['h'] ?>%;"></span>
                    <?php endforeach; ?>
                </div>
                <p class="h5p-fmh-print-note"><?= $total ?> zone<?= $total > 1 ? 's' : '' ?> à trouver</p>
            </div>
            <?php
            return ob_get_clean();
        }

        ob_start();
        ?>
        <div class="h5p-fmh" id="<?= $id ?>">
            <div class="h5p-fmh-image" onclick="fmhClick(event, '<?= $id ?>')">
                <img src="<?= $imgUrl ?>" alt="" draggable="false">
                <div class="h5p-fmh-marques"></div>
            </div>
            <div class="h5p-fmh-bar">
                <div class="h5p-fmh-jauge"><span class="h5p-fmh-jauge-fill" style="width:0%;"></span></div>
                <span class="h5p-fmh-etoile" aria-hidden="true">★</span>
                <span class="h5p-fmh-score"><span class="h5p-fmh-trouvees">0</span> / <?= $total ?></span>
            </div>
            <div class="h5p-fmh-feedback" style="display:none;"></div>
        </div>
        <script>
            window.fmhState = window.fmhState || {};
            window.fmhState['<?= $id ?>'] = {
                zones: <?= json_encode($zones) ?>,
                total: <?= $total ?>,
                trouvees: []
            };
        </script>
        <?php
        return ob_get_clean();
    }

    private function renderH5pSummary(array $content, array $files): string {
        $summaries = $content['summaries'] ?? [];
        $intro = $content['intro'] ?? '';
        $id = 'h5p-summary-' . uniqid();
        
        if (empty($summaries)) {
            return '<div class="h5p-placeholder"><p>Résumé sans contenu</p></div>';
        }
        
        ob_start();
        ?>
        <div class="h5p-summary" id="<?= $id ?>">
            <?php if ($intro): ?>
            <div class="h5p-summary-intro"><?= $this->processH5pText($intro, $files) ?></div>
            <?php endif; ?>
            
            <?php foreach ($summaries as $si => $summary): ?>
            <div class="h5p-summary-set" data-idx="<?= $si ?>">
                <?php 
                $statements = $summary['summary'] ?? [];
                $correctIndex = 0;
                $shuffled = $statements;
                shuffle($shuffled);
                foreach ($shuffled as $statement): 
                    $isCorrect = ($statement === ($statements[0] ?? ''));
                ?>
                <div class="h5p-summary-statement" data-correct="<?= $isCorrect ? '1' : '0' ?>" onclick="selectSummary(this)">
                    <?= $this->processH5pText($statement, $files) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderH5pAdvancedText(array $content, array $files): string {
        $text = $content['text'] ?? '';
        if (empty($text)) {
            return '';
        }
        return '<div class="h5p-advanced-text">' . $this->processH5pText($text, $files) . '</div>';
    }
    
    /**
     * H5P.ExportableTextArea : consigne (label) + zone de saisie libre pour l'élève.
     * Éléa mémorise la saisie côté serveur (content user data) ; ici on la conserve
     * en localStorage pour qu'elle survive au changement de slide et au rechargement.
     */
    private function renderH5pExportableTextArea(array $content, array $files): string {
        $id = 'h5p-eta-' . uniqid();
        $label = $content['label'] ?? '';
        $placeholder = $content['placeholder'] ?? '';
        // Clé de persistance stable : le subContentId suit l'élément d'une session à l'autre.
        $key = $content['_subContentId'] ?? ($content['index'] ?? '');
        $courseKey = (string)($this->courseData['id'] ?? $this->courseData['name'] ?? '');
        $storageKey = 'elea_eta_' . md5($courseKey . '|' . $key . '|' . strip_tags($label));

        ob_start();
        ?>
        <div class="h5p-eta" id="<?= $id ?>" data-storage-key="<?= htmlspecialchars($storageKey) ?>">
            <?php if ($label !== ''): ?>
            <div class="h5p-eta-label"><?= $this->processH5pText($label, $files) ?></div>
            <?php endif; ?>
            <textarea class="h5p-eta-input" rows="3" spellcheck="false"
                      placeholder="<?= htmlspecialchars($placeholder) ?>"></textarea>
        </div>
        <script>
        (function() {
            var wrap = document.getElementById('<?= $id ?>');
            if (!wrap || wrap.dataset.init) return;
            wrap.dataset.init = '1';
            var ta = wrap.querySelector('.h5p-eta-input');
            var key = wrap.dataset.storageKey;
            try { var saved = localStorage.getItem(key); if (saved !== null) ta.value = saved; } catch (e) {}
            ta.addEventListener('input', function() {
                try { localStorage.setItem(key, ta.value); } catch (e) {}
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private function renderH5pGeneric(string $machineName, array $content, array $files): string {
        ob_start();
        ?>
        <div class="h5p-generic">
            <div class="h5p-generic-header">
                <span class="h5p-generic-type"><?= htmlspecialchars($machineName) ?></span>
            </div>
            <?php 
            $textFields = ['text', 'question', 'taskDescription', 'description', 'introduction', 'textField'];
            foreach ($textFields as $field) {
                if (!empty($content[$field])) {
                    echo '<div class="h5p-generic-content">' . $this->processH5pText($content[$field], $files) . '</div>';
                    break;
                }
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // ========== HELPERS H5P ==========
    
    private function processH5pText(string $text, array $files): string {
        $text = preg_replace_callback('/<img[^>]+src="([^"]+)"[^>]*>/i', function($m) use ($files) {
            $src = $m[1];
            if (strpos($src, 'http') !== 0) {
                $newSrc = $this->getH5pFileUrl($src, $files);
                return str_replace($src, $newSrc, $m[0]);
            }
            return $m[0];
        }, $text);
        
        return $text;
    }
    
    private function getH5pFileUrl(string $path, array $files): string {
        // Nettoie le chemin (enlève #tmp et autres suffixes)
        $cleanPath = preg_replace('/#.*$/', '', $path);
        $filename = basename($cleanPath);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 0. Si le basename est un hash SHA1 (40 hex), l'utiliser directement
        //    (cas des cours créés via previewEditorSession : chemin = courses/.../files/ab/{hash})
        if (preg_match('/^[0-9a-f]{40}$/', $filename)) {
            return $this->getFileUrl($filename);
        }
        
        // 1. Match exact par nom de fichier
        foreach ($files as $file) {
            if ($file['filename'] === $filename) {
                return $this->getFileUrl($file['hash']);
            }
        }
        
        // 2. Match par chemin complet (filepath + filename)
        foreach ($files as $file) {
            $fullPath = ltrim($file['filepath'], '/') . $file['filename'];
            if ($fullPath === $cleanPath || strpos($fullPath, $cleanPath) !== false) {
                return $this->getFileUrl($file['hash']);
            }
        }
        
        // 3. Match par le nom du fichier contenu dans filepath (ex: images/file-xxx.jpg)
        // Cherche un fichier dont le chemin complet contient le filename recherché
        foreach ($files as $file) {
            $fullPath = ltrim($file['filepath'], '/') . $file['filename'];
            if (strpos($fullPath, $filename) !== false || $file['filename'] === $filename) {
                return $this->getFileUrl($file['hash']);
            }
        }
        
        // 4. Match par extension seule (dernier recours pour les petits ensembles de fichiers)
        $sameExtFiles = array_filter($files, function($f) use ($extension) {
            return strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION)) === $extension 
                   && $f['filename'] !== '.';
        });
        if (count($sameExtFiles) === 1) {
            $file = reset($sameExtFiles);
            return $this->getFileUrl($file['hash']);
        }
        
        return $path;
    }
    
    // ========== QUIZ MOODLE ==========
    
    private function renderQuiz(array $activity): string {
        $id = 'quiz-' . $activity['module_id'];
        $questions = $activity['questions'] ?? [];
        $totalQuestions = count($questions);
        
        ob_start();
        ?>
        <div class="activity activity-quiz" id="<?= $id ?>" data-total-questions="<?= $totalQuestions ?>">
            <div class="activity-header">
                <span class="activity-icon">📝</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Quiz') ?></h3>
            </div>
            <?php if (!empty($activity['intro'])): ?>
            <div class="activity-intro"><?= $this->processContent($activity['intro']) ?></div>
            <?php endif; ?>
            
            <?php if (empty($questions)): ?>
            <div class="h5p-placeholder" style="margin:1rem;">
                <p>Quiz sans questions</p>
            </div>
            <?php else: ?>
            
            <!-- Barre de progression -->
            <div class="quiz-progress" style="padding:1rem 1rem 0;">
                <div class="quiz-progress-text">Question <span class="quiz-current-q">1</span> / <?= $totalQuestions ?></div>
                <div class="quiz-progress-bar">
                    <div class="quiz-progress-fill" style="width: <?= (1 / $totalQuestions) * 100 ?>%"></div>
                </div>
            </div>
            
            <div class="quiz-questions" style="padding:1rem;">
                <?php foreach ($questions as $qi => $q): ?>
                <div class="quiz-question <?= $qi === 0 ? 'active' : '' ?>" data-qindex="<?= $qi ?>" data-qtype="<?= $q['qtype'] ?>" style="<?= $qi === 0 ? '' : 'display:none;' ?>">
                    <div class="question-header">
                        <span class="question-number"><?= $qi + 1 ?></span>
                        <div class="question-text"><?= $this->processContent($q['text'] ?? $q['questiontext'] ?? '') ?></div>
                    </div>
                    <?= $this->renderQuizAnswers($q) ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="quiz-navigation" style="padding:0 1rem 1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                <button class="btn btn-secondary quiz-prev-btn" onclick="quizPrevQuestion('<?= $id ?>')" style="display:none;">← Précédent</button>
                <button class="btn btn-primary quiz-next-btn" onclick="quizNextQuestion('<?= $id ?>')">Question suivante →</button>
                <button class="btn btn-success quiz-submit-btn" onclick="showQuizRecap('<?= $id ?>')" style="display:none;">📋 Vérifier mes réponses</button>
            </div>
            
            <!-- Récapitulatif avant validation -->
            <div class="quiz-recap" style="display:none; padding:1rem;">
                <div class="quiz-recap-header">
                    <h4>📋 Récapitulatif de vos réponses</h4>
                    <p>Vérifiez vos réponses avant de valider définitivement le test.</p>
                    <button class="btn btn-success quiz-final-submit-btn" onclick="finalSubmitQuiz('<?= $id ?>')">✓ Terminer et valider</button>
                </div>
                <div class="quiz-recap-questions"></div>
                <div class="quiz-recap-footer">
                    <button class="btn btn-secondary" onclick="backToQuizQuestions('<?= $id ?>')">← Modifier mes réponses</button>
                    <button class="btn btn-success quiz-final-submit-btn" onclick="finalSubmitQuiz('<?= $id ?>')">✓ Terminer et valider</button>
                </div>
            </div>
            
            <div class="quiz-actions" style="padding:0 1rem 1rem; display:none;">
                <button class="btn btn-secondary quiz-restart-btn" onclick="resetQuiz('<?= $id ?>')">🔄 Recommencer</button>
            </div>
            
            <div class="quiz-results" style="display:none;margin:0 1rem 1rem;">
                <div class="quiz-score"></div>
            </div>
            <?php endif; ?>
        </div>
        <script>
            window.quizData = window.quizData || {};
            window.quizData['<?= $id ?>'] = <?= json_encode($questions) ?>;
            window.quizState = window.quizState || {};
            window.quizState['<?= $id ?>'] = { currentQuestion: 0, completed: false };
        </script>
        <?php
        return ob_get_clean();
    }
    
    private function renderQuizAnswers(array $q): string {
        $qtype = $q['qtype'];
        
        ob_start();
        
        if ($qtype === 'multichoice' || $qtype === 'truefalse') {
            $single = $qtype === 'truefalse' || count(array_filter($q['answers'] ?? [], fn($a) => $a['fraction'] > 0)) === 1;
            ?>
            <div class="answers-multichoice">
                <?php foreach ($q['answers'] ?? [] as $ai => $answer): ?>
                <label class="answer-option">
                    <input type="<?= $single ? 'radio' : 'checkbox' ?>" 
                           name="q<?= $q['id'] ?>" 
                           value="<?= $ai ?>"
                           data-fraction="<?= $answer['fraction'] ?>">
                    <span class="answer-text"><?= $this->processContent($answer['text']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php
        } elseif ($qtype === 'shortanswer') {
            ?>
            <div class="answers-shortanswer">
                <input type="text" class="form-input answer-input" placeholder="Votre réponse...">
            </div>
            <?php
        } elseif ($qtype === 'match') {
            $leftItems = $q['matchings'] ?? $q['subquestions'] ?? [];
            $rightItems = array_column($leftItems, 'answer');
            shuffle($rightItems);
            ?>
            <div class="answers-match">
                <table class="match-table">
                    <?php foreach ($leftItems as $mi => $match): ?>
                    <tr data-match-id="<?= $mi ?>">
                        <td><?= $this->processContent($match['question']) ?></td>
                        <td>
                            <select class="form-input" data-correct="<?= htmlspecialchars($match['answer']) ?>">
                                <option value="">Choisir...</option>
                                <?php foreach ($rightItems as $right): ?>
                                <option value="<?= htmlspecialchars($right) ?>"><?= htmlspecialchars($right) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php
        } elseif ($qtype === 'gapselect' || $qtype === 'ddwtos') {
            // Questions à trous avec sélection
            $choices = $q['choices'] ?? [];
            $text = $q['text'] ?? '';
            
            // Remplace [[n]] par des select
            $output = preg_replace_callback('/\[\[(\d+)\]\]/', function($matches) use ($choices) {
                $group = (int)$matches[1];
                $groupChoices = array_filter($choices, fn($c) => ($c['group'] ?? 1) == $group);
                
                $html = '<select class="form-input form-input-inline gapselect-input" data-group="' . $group . '">';
                $html .= '<option value="">...</option>';
                foreach ($groupChoices as $choice) {
                    $html .= '<option value="' . htmlspecialchars($choice['text']) . '">' . htmlspecialchars($choice['text']) . '</option>';
                }
                $html .= '</select>';
                return $html;
            }, $text);
            
            echo '<div class="answers-gapselect">' . $output . '</div>';
        } else {
            ?>
            <div class="answers-generic">
                <textarea class="form-input" rows="3" placeholder="Votre réponse..."></textarea>
            </div>
            <?php
        }
        
        return ob_get_clean();
    }
    
    // ========== AUTRES ACTIVITÉS ==========
    
    private function renderPage(array $activity): string {
        ob_start();
        ?>
        <div class="activity activity-page">
            <div class="activity-header">
                <span class="activity-icon">📄</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Page') ?></h3>
            </div>
            <div class="page-content">
                <?= $this->processContent($activity['content'] ?? '') ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderResource(array $activity): string {
        // Fichiers depuis MBZ (content_files ou main_file) ou depuis éditeur (files array)
        $contentFiles = $activity['content_files'] ?? [];
        $editorFiles = $activity['files'] ?? [];
        $mainFile = $activity['main_file'] ?? null;
        $intro = $activity['intro'] ?? '';
        
        // Construire la liste des fichiers téléchargeables
        $downloadFiles = [];
        
        // Depuis MBZ : content_files
        if (!empty($contentFiles)) {
            foreach ($contentFiles as $f) {
                if (!empty($f['hash']) && ($f['filename'] ?? '.') !== '.') {
                    $downloadFiles[] = [
                        'url' => $this->getFileUrl($f['hash']),
                        'name' => $f['filename'],
                    ];
                }
            }
        }
        // Fallback MBZ : main_file seul
        elseif ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
            $downloadFiles[] = [
                'url' => $this->getFileUrl($mainFile['hash']),
                'name' => $mainFile['filename'],
            ];
        }
        
        // Depuis éditeur : files array
        if (!empty($editorFiles)) {
            foreach ($editorFiles as $f) {
                if (!empty($f['fileUrl']) && !empty($f['fileName'])) {
                    $downloadFiles[] = [
                        'url' => $f['fileUrl'],
                        'name' => $f['fileName'],
                    ];
                }
            }
        }
        
        // Traiter les @@PLUGINFILE@@ dans l'intro
        if (!empty($intro)) {
            $intro = $this->processContent($intro);
        }
        
        ob_start();
        ?>
        <div class="activity activity-resource">
            <div class="activity-header">
                <span class="activity-icon">📎</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Fichiers à distribuer') ?></h3>
            </div>
            <div style="padding: 1rem;">
                <?php if (!empty($intro)): ?>
                <div class="assign-description" style="margin-bottom: 0.75rem; line-height: 1.6; overflow: hidden;">
                    <style>.assign-description img { max-width: 100%; height: auto; }</style>
                    <?= $intro ?>
                </div>
                <?php endif; ?>
                <?= $this->renderDownloadFilesHtml($downloadFiles) ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rend la liste des fichiers téléchargeables (assign + resource) avec un bouton
     * « Tout télécharger » et un téléchargement qui FORCE le nom d'origine côté client
     * (blob same-origin) — immunisé contre le renommage des redirections Drive.
     */
    private function renderDownloadFilesHtml(array $downloadFiles): string {
        if (empty($downloadFiles)) {
            return '<p style="color: #888; font-style: italic;">Aucun fichier disponible.</p>';
        }
        $items = [];
        foreach ($downloadFiles as $df) {
            $dlUrl = $df['url'];
            if (strpos($dlUrl, 'action=serve_upload') !== false) {
                $dlUrl .= '&download=1&download_name=' . rawurlencode($df['name']);
            } elseif (strpos($dlUrl, 'drive.google.com/uc?') !== false) {
                $dlUrl = str_replace('export=view', 'export=download', $dlUrl);
                if (strpos($dlUrl, 'export=') === false) $dlUrl .= '&export=download';
            } elseif (preg_match('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $dlUrl, $m)) {
                $dlUrl = 'https://drive.google.com/uc?id=' . $m[1] . '&export=download';
            }
            $items[] = ['url' => $dlUrl, 'name' => $df['name']];
        }
        ob_start();
        ?>
        <div class="elea-dl-group">
            <?php if (count($items) > 1): ?>
            <div style="display:flex; justify-content:flex-end; margin-bottom:0.5rem;">
                <button type="button" class="elea-dl-all" onclick="eleaDownloadAll(this)" style="border:none; padding:0.45rem 1rem; background:#0d6efd; color:white; border-radius:6px; font-size:0.85rem; font-weight:500; cursor:pointer;">⬇️ Tout télécharger (<?= count($items) ?>)</button>
            </div>
            <?php endif; ?>
            <?php foreach ($items as $it): ?>
            <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; background: #f0f7ff; border-radius: 8px; margin-bottom: 0.5rem;">
                <span style="font-size: 1.5rem;">📄</span>
                <div style="flex: 1;"><strong><?= htmlspecialchars($it['name']) ?></strong></div>
                <a href="<?= htmlspecialchars($it['url']) ?>" class="elea-dl-link" download="<?= htmlspecialchars($it['name']) ?>" data-name="<?= htmlspecialchars($it['name']) ?>" style="text-decoration: none; padding: 0.4rem 1rem; background: #0d6efd; color: white; border-radius: 6px; font-size: 0.85rem;">📥 Télécharger</a>
            </div>
            <?php endforeach; ?>
        </div>
        <script>
        if (!window.eleaDownloadAll) {
            window.eleaDownloadOne = function(url, name) {
                return fetch(url, { credentials: 'same-origin' })
                    .then(function(r){ if (!r.ok) throw 0; return r.blob(); })
                    .then(function(blob){
                        var o = URL.createObjectURL(blob);
                        var a = document.createElement('a'); a.href = o; a.download = name;
                        document.body.appendChild(a); a.click(); document.body.removeChild(a);
                        setTimeout(function(){ URL.revokeObjectURL(o); }, 5000);
                    })
                    .catch(function(){
                        var a = document.createElement('a'); a.href = url; a.download = name;
                        document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    });
            };
            window.eleaDownloadAll = function(btn) {
                var group = btn.closest('.elea-dl-group'); if (!group) return;
                var links = group.querySelectorAll('.elea-dl-link'), i = 0;
                (function next(){
                    if (i >= links.length) return;
                    var l = links[i++];
                    window.eleaDownloadOne(l.getAttribute('href'), l.getAttribute('data-name'))
                        .finally(function(){ setTimeout(next, 400); });
                })();
            };
            // Clic individuel : forcer le nom via blob pour les URLs same-origin (serve_upload)
            document.addEventListener('click', function(e){
                var a = e.target.closest ? e.target.closest('.elea-dl-link') : null;
                if (!a) return;
                var href = a.getAttribute('href');
                if (href.indexOf('serve_upload') !== -1 || href.charAt(0) === '/' || href.indexOf(location.origin) === 0) {
                    e.preventDefault();
                    window.eleaDownloadOne(href, a.getAttribute('data-name'));
                }
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }

    private function renderAssign(array $activity): string {
        // Fichiers depuis MBZ (content_files ou main_file) ou depuis éditeur (files array)
        $contentFiles = $activity['content_files'] ?? [];
        $mainFile     = $activity['main_file'] ?? null;
        $editorFiles  = $activity['files'] ?? [];
        $intro        = $activity['intro'] ?? '';

        // Construire la liste des fichiers téléchargeables
        $downloadFiles = [];

        if (!empty($contentFiles)) {
            foreach ($contentFiles as $f) {
                if (!empty($f['hash']) && ($f['filename'] ?? '.') !== '.') {
                    $downloadFiles[] = ['url' => $this->getFileUrl($f['hash']), 'name' => $f['filename']];
                }
            }
        } elseif ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
            $downloadFiles[] = ['url' => $this->getFileUrl($mainFile['hash']), 'name' => $mainFile['filename']];
        }

        // Fichiers depuis l'éditeur (format {fileUrl, fileName})
        foreach ($editorFiles as $f) {
            if (!empty($f['fileUrl']) && !empty($f['fileName'])) {
                $downloadFiles[] = ['url' => $f['fileUrl'], 'name' => $f['fileName']];
            }
        }

        // Traiter les @@PLUGINFILE@@ dans l'intro
        if (!empty($intro)) {
            $intro = $this->processContent($intro);
        }

        ob_start();
        ?>
        <div class="activity activity-assign">
            <div class="activity-header">
                <span class="activity-icon">📝</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Travail à déposer') ?></h3>
            </div>
            <div style="padding: 1rem;">
                <?php if (!empty($intro)): ?>
                <div class="assign-description" style="margin-bottom: 0.75rem; line-height: 1.6; overflow: hidden;">
                    <style>.assign-description img { max-width: 100%; height: auto; }</style>
                    <?= $intro ?>
                </div>
                <?php endif; ?>
                <?= $this->renderDownloadFilesHtml($downloadFiles) ?>
                <div style="padding: 0.5rem 0.75rem; background: #fff3cd; border-radius: 6px; font-size: 0.8rem; color: #856404;">
                    ℹ️ Le dépôt de fichier n'est pas disponible sur Éléa-Secours. Rendez votre travail directement sur Éléa.
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderUrl(array $activity): string {
        ob_start();
        ?>
        <div class="activity activity-url">
            <div class="activity-header">
                <span class="activity-icon">🔗</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Lien') ?></h3>
            </div>
            <div style="padding:1rem;">
                <a href="<?= htmlspecialchars($activity['external_url'] ?? '#') ?>" class="btn btn-primary" target="_blank" rel="noopener">
                    🌐 Ouvrir le lien
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderLabel(array $activity): string {
        ob_start();
        ?>
        <div class="activity activity-label">
            <?= $this->processContent($activity['intro'] ?? '') ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderBook(array $activity): string {
        $chapters = $activity['chapters'] ?? [];
        $id = 'book-' . ($activity['module_id'] ?? uniqid());
        
        ob_start();
        ?>
        <div class="activity activity-book" id="<?= $id ?>">
            <div class="activity-header">
                <span class="activity-icon">📖</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Livre') ?></h3>
            </div>
            <?php if (!empty($chapters)): ?>
            <div class="book-nav" style="padding:1rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                <?php foreach ($chapters as $i => $chapter): ?>
                <button class="btn btn-sm <?= $i === 0 ? 'btn-primary' : 'btn-secondary' ?> book-chapter-btn" onclick="showChapter(this, <?= $chapter['id'] ?>)">
                    <?= htmlspecialchars($chapter['title']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="book-content" style="padding:1rem;">
                <?php foreach ($chapters as $i => $chapter): ?>
                <div class="book-chapter <?= $i === 0 ? 'active' : '' ?>" id="chapter-<?= $chapter['id'] ?>" style="<?= $i > 0 ? 'display:none;' : '' ?>">
                    <?= $this->processContent($chapter['content']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderFolder(array $activity): string {
        $files = array_filter($activity['files'] ?? [], fn($f) => $f['filename'] !== '.' && ($f['filearea'] ?? '') === 'content');
        
        ob_start();
        ?>
        <div class="activity activity-folder">
            <div class="activity-header">
                <span class="activity-icon">📁</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Dossier') ?></h3>
            </div>
            <?php if (!empty($files)): ?>
            <ul class="folder-files" style="padding:1rem;list-style:none;">
                <?php foreach ($files as $file): ?>
                <li style="margin-bottom:0.5rem;">
                    <a href="<?= $this->getFileUrl($file['hash']) ?>" target="_blank">
                        📄 <?= htmlspecialchars($file['filename']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function renderLesson(array $activity): string {
        $pages = $activity['pages'] ?? [];
        $id = 'lesson-' . ($activity['module_id'] ?? uniqid());
        
        ob_start();
        ?>
        <div class="activity activity-lesson" id="<?= $id ?>">
            <div class="activity-header">
                <span class="activity-icon">📚</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Leçon') ?></h3>
            </div>
            <?php if (!empty($pages)): ?>
            <div style="padding:1rem;">
                <?php foreach ($pages as $i => $page): ?>
                <div class="lesson-page <?= $i === 0 ? 'active' : '' ?>" data-idx="<?= $i ?>" style="<?= $i > 0 ? 'display:none;' : '' ?>">
                    <h4><?= htmlspecialchars($page['title'] ?? 'Page') ?></h4>
                    <?= $this->processContent($page['contents'] ?? '') ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($pages) > 1): ?>
            <div class="lesson-nav" style="padding:1rem;display:flex;justify-content:space-between;align-items:center;background:var(--gray-50);">
                <button class="btn btn-secondary btn-sm" onclick="prevLessonPage(this)">← Précédent</button>
                <span class="page-indicator"><span class="current">1</span> / <?= count($pages) ?></span>
                <button class="btn btn-secondary btn-sm" onclick="nextLessonPage(this)">Suivant →</button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Rend une carte de progression (mapmodules Éléa)
     */
    private function renderMapmodules(array $activity): string {
        $intro = $activity['intro'] ?? '';
        $mapPath = $activity['mapPath'] ?? '';
        $defaultPath = 'M 22 120 C 38 95 68 37 99 34 C 131 31 198 79 206 105 C 214 131 208 162 184 180 C 159 197 119 202 104 236 C 89 270 99 304 112 318 C 125 332 160 351 234 342 C 307 334 342 306 353 288 C 363 271 370 216 359 189 C 349 162 323 107 323 97 C 323 88 323 60 351 49 C 378 39 450 20 493 47 C 536 73 532 116 521 150 C 511 183 477 264 477 272 C 477 280 482 314 510 329 C 537 344 591 361 633 348 C 675 335 697 320 703 307 C 709 294 720 265 704 236 C 689 208 667 170 670 146 C 673 122 680 83 715 68 C 750 53 782 45 796 65 C 810 84 835 126 840 170 C 844 213 866 259 881 264 C 896 268 910 272 930 265 C 949 258 971 245 971 245';
        $descHeader = $activity['descriptionHeader'] ?? '';
        $descFooter = $activity['descriptionFooter'] ?? '';
        $customImage = $activity['mapImage'] ?? '';
        
        // Convertir les images externes en base64 pour éviter les problèmes CORS
        if (!empty($intro)) {
            $intro = $this->convertExternalImagesToBase64($intro);
        }
        
        // Construire la liste des activités non-mapmodules avec leurs IDs viewer
        $activityList = [];
        foreach ($this->courseData['sections'] ?? [] as $sIdx => $section) {
            foreach ($section['activities'] ?? [] as $aIdx => $act) {
                if (($act['type'] ?? '') !== 'mapmodules') {
                    $activityList[] = [
                        'id' => 'activity-' . $sIdx . '-' . $aIdx,
                        'name' => $act['name'] ?? 'Activité'
                    ];
                }
            }
        }
        $activityListJson = json_encode($activityList, JSON_HEX_TAG | JSON_HEX_APOS);
        $mapId = 'mapmodules_' . uniqid();
        
        // Déterminer si on a un chemin ou seulement l'intro HTML
        $hasPath = !empty($mapPath);
        
        // Convertir l'image custom en base64 si c'est une URL locale
        if (!empty($customImage)) {
            $customImage = $this->convertExternalImagesToBase64('<img src="' . htmlspecialchars($customImage) . '">');
            // Extraire le src de l'img
            if (preg_match('/src="([^"]+)"/', $customImage, $m)) {
                $customImage = $m[1];
            }
        }
        
        ob_start();
        ?>
        <div class="activity activity-mapmodules">
            <div class="activity-header">
                <span class="activity-icon">🗺️</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Carte de progression') ?></h3>
            </div>
            <div class="mapmodules-content" style="padding: 1rem;">
                <?php if (!empty($descHeader)): ?>
                <div style="margin-bottom: 0.75rem;"><?= $descHeader ?></div>
                <?php endif; ?>
                
                <?php if ($hasPath): ?>
                <div style="position: relative; width: 100%; border-radius: 12px; overflow: hidden;">
                    <?php if (!empty($customImage)): ?>
                    <img src="<?= htmlspecialchars($customImage) ?>" style="width: 100%; display: block;" alt="carte">
                    <?php endif; ?>
                    <svg id="<?= $mapId ?>" viewBox="0 0 1000 400" style="<?= !empty($customImage) ? 'position: absolute; top: 0; left: 0;' : '' ?> width: 100%; height: 100%; display: block;">
                        <?php if (empty($customImage)): ?>
                        <rect width="1000" height="400" fill="#FF9800" rx="12"/>
                        <path d="<?= htmlspecialchars($defaultPath) ?>" fill="none" stroke="white" stroke-width="4" stroke-dasharray="8 12" stroke-linecap="round"/>
                        <?php endif; ?>
                        <path id="<?= $mapId ?>_path" d="<?= htmlspecialchars($mapPath) ?>" fill="none" stroke="none"/>
                        <g id="<?= $mapId ?>_buttons"></g>
                    </svg>
                </div>
                <script>
                (function() {
                    var mapId = '<?= $mapId ?>';
                    var activities = <?= $activityListJson ?>;
                    
                    function placeButtons() {
                        var pathEl = document.getElementById(mapId + '_path');
                        var buttonsG = document.getElementById(mapId + '_buttons');
                        if (!pathEl || !buttonsG || activities.length === 0) return;
                        
                        // Filtrer les activités cachées (sections ou activités individuelles)
                        var visibleActivities = activities.filter(function(act) {
                            var navItem = document.querySelector('.nav-item[data-id="' + act.id + '"]');
                            if (!navItem) return true; // Par défaut visible si pas trouvé
                            // Activité directement cachée
                            if (navItem.classList.contains('visibility-hidden')) return false;
                            // Section parente cachée
                            var section = navItem.closest('.nav-section');
                            if (section && section.classList.contains('visibility-hidden')) return false;
                            return true;
                        });
                        
                        buttonsG.innerHTML = '';
                        var totalLen = pathEl.getTotalLength();
                        var count = visibleActivities.length;
                        
                        for (var i = 0; i < count; i++) {
                            var t = count <= 1 ? 0 : i / (count - 1);
                            var pt = pathEl.getPointAtLength(t * totalLen);
                            var act = visibleActivities[i];
                            
                            // Vérifier si l'activité est complétée
                            var navItem = document.querySelector('.nav-item[data-id="' + act.id + '"]');
                            var isCompleted = navItem && navItem.classList.contains('completed');
                            
                            var g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                            g.style.cursor = 'pointer';
                            g.setAttribute('data-activity-id', act.id);
                            g.setAttribute('data-btn-index', i);
                            g.innerHTML = '<circle cx="' + pt.x + '" cy="' + pt.y + '" r="18" fill="' + (isCompleted ? '#1565C0' : '#2E7D32') + '" stroke="white" stroke-width="2"/>' +
                                '<text x="' + pt.x + '" y="' + (pt.y + 1) + '" text-anchor="middle" dominant-baseline="central" fill="white" font-size="' + (isCompleted ? '16' : '18') + '" font-weight="bold">' + (isCompleted ? '✓' : '?') + '</text>' +
                                '<title>' + act.name.replace(/</g, '&lt;') + '</title>';
                            
                            (function(actId) {
                                g.addEventListener('click', function(e) {
                                    e.stopPropagation();
                                    if (typeof showActivity === 'function') showActivity(actId);
                                });
                            })(act.id);
                            
                            buttonsG.appendChild(g);
                        }
                    }
                    
                    // Placer les boutons au chargement
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', placeButtons);
                    } else {
                        requestAnimationFrame(placeButtons);
                    }
                    // Re-placer après l'init des activités cachées (tokens/codes)
                    setTimeout(placeButtons, 200);
                    
                    // Observer les changements de complétion ET de visibilité
                    var observer = new MutationObserver(function(mutations) {
                        var needsRedraw = false;
                        mutations.forEach(function(m) {
                            if (m.type === 'attributes' && m.attributeName === 'class') {
                                var el = m.target;
                                // Changement de visibilité → redessiner tout
                                if (el.classList.contains('nav-item') || el.classList.contains('nav-section')) {
                                    needsRedraw = true;
                                }
                            }
                        });
                        if (needsRedraw) placeButtons();
                    });
                    
                    var buttonsG = document.getElementById(mapId + '_buttons');
                    document.addEventListener('DOMContentLoaded', function() {
                        var navItems = document.querySelectorAll('.nav-item, .nav-section');
                        navItems.forEach(function(item) {
                            observer.observe(item, { attributes: true });
                        });
                    });
                })();
                </script>
                <?php elseif (!empty($intro)): ?>
                <div class="mapmodules-map">
                    <?= $intro ?>
                </div>
                <?php else: ?>
                <div class="h5p-placeholder">
                    <p>🗺️ Carte de progression</p>
                    <small>Contenu non disponible en mode hors-ligne</small>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($descFooter)): ?>
                <div style="margin-top: 0.75rem;"><?= $descFooter ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Convertit les images externes en base64 dans le HTML
     */
    private function convertExternalImagesToBase64(string $html): string {
        // 1. Convertir les balises img avec src externe
        $pattern = '/<img([^>]*)src=["\']((https?:\/\/[^"\']+))["\']([^>]*)>/i';
        
        $html = preg_replace_callback($pattern, function($matches) {
            $beforeSrc = $matches[1];
            $url = $matches[2];
            $afterSrc = $matches[4];
            
            // Essayer de télécharger et convertir en base64
            $base64 = $this->urlToBase64($url);
            
            if ($base64) {
                return '<img' . $beforeSrc . 'src="' . $base64 . '"' . $afterSrc . '>';
            }
            
            // Si échec, garder l'URL originale avec crossorigin
            return '<img' . $beforeSrc . 'src="' . $url . '" crossorigin="anonymous"' . $afterSrc . '>';
        }, $html);
        
        // 2. Convertir les background-image dans les styles inline
        $bgPattern = '/background(-image)?:\s*url\(["\']?((https?:\/\/[^"\')\s]+))["\']?\)/i';
        
        $html = preg_replace_callback($bgPattern, function($matches) {
            $prop = $matches[1] ?? '';
            $url = $matches[2];
            
            $base64 = $this->urlToBase64($url);
            
            if ($base64) {
                return 'background' . $prop . ': url("' . $base64 . '")';
            }
            
            return $matches[0];
        }, $html);
        
        return $html;
    }
    
    /**
     * Télécharge une image et la convertit en base64
     */
    private function urlToBase64(string $url): ?string {
        try {
            $imageData = null;
            
            // Méthode 1: Essayer avec curl (plus fiable)
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT => 'EleaSecours/1.0'
                ]);
                $imageData = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($httpCode !== 200 || empty($imageData)) {
                    $imageData = null;
                }
            }
            
            // Méthode 2: Fallback sur file_get_contents
            if ($imageData === null && ini_get('allow_url_fopen')) {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 15,
                        'user_agent' => 'EleaSecours/1.0'
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                $imageData = @file_get_contents($url, false, $context);
            }
            
            if (empty($imageData)) {
                return null;
            }
            
            // Déterminer le type MIME
            $mimeType = null;
            if (function_exists('finfo_open')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageData);
            }
            
            if (!$mimeType || strpos($mimeType, 'image/') !== 0) {
                // Essayer de deviner par l'extension
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                $mimeTypes = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                    'webp' => 'image/webp'
                ];
                $mimeType = $mimeTypes[$ext] ?? 'image/jpeg';
            }
            
            return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            
        } catch (Exception $e) {
            error_log('EleaSecours: Erreur téléchargement image ' . $url . ': ' . $e->getMessage());
            return null;
        }
    }
    
    private function renderUnsupported(array $activity): string {
        ob_start();
        ?>
        <div class="activity activity-unsupported">
            <div class="activity-header">
                <span class="activity-icon">⚠️</span>
                <h3 class="activity-title"><?= htmlspecialchars($activity['name'] ?? 'Activité') ?></h3>
            </div>
            <div class="h5p-placeholder" style="margin:1rem;">
                <p>Type non supporté : <?= htmlspecialchars($activity['type'] ?? 'inconnu') ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // ========== HELPERS ==========
    
    private function processContent(string $content): string {
        // Remplacer les références aux fichiers
        $content = preg_replace_callback('/@@PLUGINFILE@@([^"\']+)/', function($m) {
            $filename = urldecode($m[1]);
            foreach ($this->courseData['files'] ?? [] as $file) {
                if ($file['filename'] === basename($filename)) {
                    return $this->getFileUrl($file['hash']);
                }
            }
            return $m[0];
        }, $content);
        
        // Supprimer les scripts Moodle/YUI qui causeraient des erreurs
        $content = preg_replace('/<script[^>]*>.*?YUI.*?<\/script>/is', '', $content);
        $content = preg_replace('/<script[^>]*>.*?M\.cfg.*?<\/script>/is', '', $content);
        $content = preg_replace('/<script[^>]*>.*?require\s*\(.*?<\/script>/is', '', $content);
        
        return $content;
    }
    
    private function getFileUrl(string $hash): string {
        // URLs Google directes si le cours est sur Drive
        if ($this->fileIndex && isset($this->fileIndex['files'][$hash])) {
            $driveId = $this->fileIndex['files'][$hash];
            $mime = $this->fileIndex['mimetypes'][$hash] ?? '';
            if (str_starts_with($mime, 'image/')) {
                return 'https://lh3.googleusercontent.com/d/' . $driveId;
            }
            return 'https://drive.google.com/uc?id=' . $driveId . '&export=view';
        }
        // Fallback local
        if (strpos($this->baseUrl, 'file.php') !== false) {
            return $this->baseUrl . 'files/' . substr($hash, 0, 2) . '/' . $hash;
        }
        return $this->baseUrl . '/files/' . substr($hash, 0, 2) . '/' . $hash;
    }
}
