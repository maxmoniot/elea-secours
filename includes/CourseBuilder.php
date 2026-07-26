<?php
/**
 * CourseBuilder - Convertit un JSON de spécification cours en format EleaMbzExporter
 * 
 * Workflow :
 * 1. Reçoit le JSON spec (généré par Claude selon le guide pédagogique)
 * 2. Construit la structure de données attendue par EleaMbzExporter
 * 3. Résout les images de contexte via ImageManager
 * 4. Identifie les slides tutorial_step nécessitant des captures d'écran
 * 5. Retourne le cours prêt à exporter + la liste des captures à faire
 * 
 * Les captures d'écran des tutos sont exécutées côté client (iframe + eleaAuto),
 * puis injectées dans le cours via injectScreenshots() avant l'export final.
 */

require_once __DIR__ . '/ImageManager.php';

class CourseBuilder {
    
    /** @var array JSON spec d'entrée */
    private $spec;
    
    /** @var ImageManager */
    private $imageManager;
    
    /** @var array Liste des captures d'écran à réaliser côté client */
    private $pendingScreenshots = [];
    
    /** @var array Cours au format EleaMbzExporter */
    private $courseData;
    
    /** @var array Erreurs de validation */
    private $errors = [];
    
    /** @var array Warnings non bloquants */
    private $warnings = [];
    
    /**
     * @param array $spec JSON spec décodé
     */
    public function __construct(array $spec) {
        $this->spec = $spec;
        $this->imageManager = new ImageManager();
    }
    
    /**
     * Point d'entrée : construit le cours complet.
     * 
     * @return array ['course' => ..., 'screenshots' => ..., 'errors' => ..., 'warnings' => ...]
     */
    public function build() {
        // Validation
        $this->validate();
        if (!empty($this->errors)) {
            return [
                'course' => null,
                'screenshots' => [],
                'errors' => $this->errors,
                'warnings' => $this->warnings,
            ];
        }
        
        // Construction
        $this->courseData = [
            'name' => $this->spec['title'] ?? 'Cours généré',
            'shortname' => $this->spec['shortname'] ?? 'gen_' . date('ymdHis'),
            'sections' => [],
        ];
        
        foreach ($this->spec['sections'] as $sIdx => $section) {
            $this->courseData['sections'][] = $this->buildSection($section, $sIdx);
        }
        
        return [
            'course' => $this->courseData,
            'screenshots' => $this->pendingScreenshots,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
    
    /**
     * Injecte les captures d'écran réalisées côté client dans le cours.
     * Appelé après que le frontend a exécuté les actions eleaAuto et capturé les screenshots.
     * 
     * @param array $screenshots ['sIdx_aIdx_slideIdx' => 'data:image/png;base64,...', ...]
     */
    public function injectScreenshots(array $screenshots) {
        if (!$this->courseData) return;
        
        foreach ($screenshots as $key => $dataUrl) {
            // Sauvegarder l'image en fichier local
            $imageInfo = $this->saveDataUrlAsFile($dataUrl);
            if (!$imageInfo) continue;
            
            // Parser la clé : sIdx_aIdx_slideIdx
            $parts = explode('_', $key);
            if (count($parts) !== 3) continue;
            [$sIdx, $aIdx, $slideIdx] = array_map('intval', $parts);
            
            // Trouver le slide et injecter l'image
            $section = &$this->courseData['sections'][$sIdx] ?? null;
            if (!$section) continue;
            
            $activity = &$section['activities'][$aIdx] ?? null;
            if (!$activity || ($activity['h5pType'] ?? '') !== 'CoursePresentation') continue;
            
            $slides = &$activity['content']['presentation']['slides'] ?? null;
            if (!$slides || !isset($slides[$slideIdx])) continue;
            
            // Construire l'élément image H5P et l'insérer en premier dans le slide
            $imgElement = $this->imageManager->buildH5pImageElement(
                $imageInfo,
                'Capture d\'écran du tutoriel',
                0, 0, 100, 60
            );
            
            if ($imgElement) {
                // Insérer l'image au début des éléments du slide
                array_unshift($slides[$slideIdx]['elements'], $imgElement);
                
                // Repositionner le texte d'instruction en dessous
                foreach ($slides[$slideIdx]['elements'] as $eIdx => &$element) {
                    if ($eIdx === 0) continue; // C'est l'image qu'on vient d'ajouter
                    if (isset($element['action']['library']) && 
                        strpos($element['action']['library'], 'H5P.AdvancedText') !== false) {
                        $element['y'] = 62;
                        $element['height'] = 35;
                    }
                }
            }
        }
    }
    
    /**
     * Retourne le cours au format EleaMbzExporter (après build + injectScreenshots).
     */
    public function getCourseData() {
        return $this->courseData;
    }
    
    // =========================================================================
    // VALIDATION
    // =========================================================================
    
    private function validate() {
        if (empty($this->spec['title'])) {
            $this->errors[] = 'Champ "title" manquant';
        }
        if (empty($this->spec['sections']) || !is_array($this->spec['sections'])) {
            $this->errors[] = 'Champ "sections" manquant ou invalide';
            return;
        }
        
        $hasEval = false;
        
        foreach ($this->spec['sections'] as $sIdx => $section) {
            $role = $section['role'] ?? 'seance';
            
            // Vérifier section 0
            if ($sIdx === 0 && $role !== 'carte') {
                $this->warnings[] = "Section 0 devrait avoir role='carte'";
            }
            
            if ($role === 'evaluation') {
                $hasEval = true;
                $quizCount = 0;
                foreach ($section['activities'] ?? [] as $act) {
                    if (($act['type'] ?? '') === 'quiz_moodle') $quizCount++;
                }
                if ($quizCount !== 2) {
                    $this->warnings[] = "Section évaluation devrait contenir exactement 2 quiz_moodle (trouvé: $quizCount)";
                }
            }
            
            // Vérifier les CoursePresentation ont au moins 1 QCM
            foreach ($section['activities'] ?? [] as $aIdx => $act) {
                if (($act['type'] ?? '') === 'h5p_course_presentation') {
                    $hasQuiz = false;
                    foreach ($act['slides'] ?? [] as $slide) {
                        $layout = $slide['layout'] ?? 'text_only';
                        if (in_array($layout, ['quiz', 'checkpoint', 'image_quiz'])) {
                            $hasQuiz = true;
                            break;
                        }
                    }
                    if (!$hasQuiz) {
                        $this->warnings[] = "Section $sIdx, activité $aIdx ('{$act['title']}') : pas de slide quiz (règle : ≥1 par CoursePresentation)";
                    }
                }
                
                // Vérifier séparation simulateur/matériel
                if (($act['type'] ?? '') === 'h5p_course_presentation' && 
                    ($act['phase'] ?? '') === 'tuto_simulateur') {
                    foreach ($act['slides'] ?? [] as $slide) {
                        if (($slide['layout'] ?? '') === 'warning') {
                            $this->warnings[] = "Section $sIdx, activité $aIdx : un parcours tuto_simulateur ne devrait pas contenir de slides warning (réserver au parcours tuto_materiel)";
                        }
                    }
                }
            }
        }
        
        if (!$hasEval) {
            $this->warnings[] = "Aucune section d'évaluation (role='evaluation') trouvée";
        }
    }
    
    // =========================================================================
    // CONSTRUCTION DES SECTIONS
    // =========================================================================
    
    private function buildSection(array $section, int $sIdx) {
        $built = [
            'name' => $section['name'] ?? ('Section ' . $sIdx),
            'summary' => $section['summary'] ?? '',
            'activities' => [],
        ];
        
        foreach ($section['activities'] ?? [] as $aIdx => $activity) {
            $builtActivity = $this->buildActivity($activity, $sIdx, $aIdx);
            if ($builtActivity) {
                $built['activities'][] = $builtActivity;
            }
        }
        
        return $built;
    }
    
    // =========================================================================
    // CONSTRUCTION DES ACTIVITÉS
    // =========================================================================
    
    private function buildActivity(array $activity, int $sIdx, int $aIdx) {
        $type = $activity['type'] ?? '';
        
        switch ($type) {
            case 'mapmodules':
                return $this->buildMapModules($activity);
                
            case 'label':
                return $this->buildLabel($activity);
                
            case 'h5p_course_presentation':
                return $this->buildCoursePresentation($activity, $sIdx, $aIdx);
                
            case 'h5p_blanks':
                return $this->buildBlanks($activity);
                
            case 'quiz_moodle':
                return $this->buildQuizMoodle($activity);
                
            default:
                $this->warnings[] = "Type d'activité inconnu : '$type'";
                return null;
        }
    }
    
    /**
     * Carte de progression (mapmodules).
     * Converti en label HTML car EleaMbzExporter ne gère pas mapmodules nativement.
     * Note : dans Éléa, il faudra ajouter manuellement la carte après import.
     */
    private function buildMapModules(array $activity) {
        $color = 'orange';
        $title = $activity['title'] ?? 'Carte standard : orange';
        if (preg_match('/:\s*(orange|bleu|vert|rouge|violet)/i', $title, $m)) {
            $color = strtolower($m[1]);
        }
        
        $colorHex = [
            'orange' => '#ff9800', 'bleu' => '#2196F3', 'vert' => '#4CAF50',
            'rouge' => '#f44336', 'violet' => '#9C27B0',
        ][$color] ?? '#ff9800';
        
        // Créer un label HTML qui simule la carte de progression
        return [
            'type' => 'hvp',
            'name' => $title,
            'h5pType' => 'CoursePresentation',
            'content' => [
                'presentation' => [
                    'slides' => [
                        [
                            'elements' => [
                                $this->imageManager->buildH5pTextElement(
                                    '<p style="text-align:center;font-size:1.5em;">👇 Voici la carte de progression de l\'activité 👇</p>'
                                    . '<p style="text-align:center;color:' . $colorHex . ';font-size:1.2em;"><strong>📍 ' . htmlspecialchars($title) . '</strong></p>'
                                    . '<p style="text-align:center;font-style:italic;">(La carte de progression mapmodules sera à configurer manuellement dans Éléa après import)</p>',
                                    5, 10, 90, 80
                                ),
                            ],
                            'slideBackgroundSelector' => new \stdClass(),
                        ],
                    ],
                    'keywordListEnabled' => false,
                    'globalBackgroundSelector' => new \stdClass(),
                    'keywordListAlwaysShow' => false,
                    'keywordListAutoHide' => false,
                    'keywordListOpacity' => 90,
                ],
            ],
        ];
    }
    
    /**
     * Label d'accueil.
     * Converti en CoursePresentation 1 slide car l'exporteur ne gère pas les labels natifs.
     */
    private function buildLabel(array $activity) {
        $content = $activity['content'] ?? '';
        $content = str_replace("\n", '<br/>', htmlspecialchars($content));
        
        return [
            'type' => 'hvp',
            'name' => $activity['title'] ?? 'Accueil',
            'h5pType' => 'CoursePresentation',
            'content' => [
                'presentation' => [
                    'slides' => [
                        [
                            'elements' => [
                                $this->imageManager->buildH5pTextElement(
                                    '<p style="text-align:center;font-size:1.1em;">' . $content . '</p>',
                                    5, 10, 90, 80
                                ),
                            ],
                            'slideBackgroundSelector' => new \stdClass(),
                        ],
                    ],
                    'keywordListEnabled' => false,
                    'globalBackgroundSelector' => new \stdClass(),
                    'keywordListAlwaysShow' => false,
                    'keywordListAutoHide' => false,
                    'keywordListOpacity' => 90,
                ],
            ],
        ];
    }
    
    /**
     * CoursePresentation H5P — le type d'activité principal.
     * Construit slide par slide à partir de la spec.
     */
    private function buildCoursePresentation(array $activity, int $sIdx, int $aIdx) {
        $slides = [];
        
        foreach ($activity['slides'] ?? [] as $slideIdx => $slideSpec) {
            $builtSlides = $this->buildSlides($slideSpec, $sIdx, $aIdx, $slideIdx);
            foreach ($builtSlides as $s) {
                $slides[] = $s;
            }
        }
        
        if (empty($slides)) {
            $slides[] = [
                'elements' => [],
                'slideBackgroundSelector' => new \stdClass(),
            ];
        }
        
        return [
            'type' => 'hvp',
            'name' => $activity['title'] ?? 'Activité',
            'h5pType' => 'CoursePresentation',
            'content' => [
                'presentation' => [
                    'slides' => $slides,
                    'keywordListEnabled' => false,
                    'globalBackgroundSelector' => new \stdClass(),
                    'keywordListAlwaysShow' => false,
                    'keywordListAutoHide' => false,
                    'keywordListOpacity' => 90,
                ],
            ],
        ];
    }
    
    /**
     * Construit un ou plusieurs slides H5P à partir d'une spec de slide.
     * Retourne un tableau car `warning_type: "all"` génère plusieurs slides.
     */
    private function buildSlides(array $slideSpec, int $sIdx, int $aIdx, int $slideIdx) {
        $layout = $slideSpec['layout'] ?? 'text_only';
        
        switch ($layout) {
            case 'warning':
                return $this->buildWarningSlides($slideSpec);
                
            case 'tutorial_step':
                return [$this->buildTutorialStepSlide($slideSpec, $sIdx, $aIdx, $slideIdx)];
                
            case 'quiz':
            case 'checkpoint':
                return [$this->buildQuizSlide($slideSpec)];
                
            case 'image_quiz':
                return [$this->buildImageQuizSlide($slideSpec)];
                
            case 'image_left':
            case 'image_right':
            case 'image_top':
            case 'image_full':
                return [$this->buildImageSlide($slideSpec, $layout)];
                
            case 'text_only':
            default:
                return [$this->buildTextOnlySlide($slideSpec)];
        }
    }
    
    // =========================================================================
    // CONSTRUCTION DES SLIDES
    // =========================================================================
    
    /**
     * Slide texte seul.
     */
    private function buildTextOnlySlide(array $spec) {
        $text = $spec['text'] ?? '';
        if (empty($text)) {
            $text = '<p>&nbsp;</p>';
        }
        
        return [
            'elements' => [
                $this->imageManager->buildH5pTextElement($text, 2, 2, 96, 96),
            ],
            'slideBackgroundSelector' => new \stdClass(),
        ];
    }
    
    /**
     * Slide avec image + texte.
     */
    private function buildImageSlide(array $spec, string $layout) {
        $elements = [];
        $imageInfo = null;
        
        if (!empty($spec['image'])) {
            $imageInfo = $this->imageManager->resolve($spec['image']);
        }
        
        $text = $spec['text'] ?? '';
        $alt = $spec['alt'] ?? '';
        
        switch ($layout) {
            case 'image_left':
                if ($imageInfo) {
                    $elements[] = $this->imageManager->buildH5pImageElement($imageInfo, $alt, 0, 0, 48, 100);
                }
                if ($text) {
                    $elements[] = $this->imageManager->buildH5pTextElement($text, 50, 5, 48, 90);
                }
                break;
                
            case 'image_right':
                if ($text) {
                    $elements[] = $this->imageManager->buildH5pTextElement($text, 2, 5, 48, 90);
                }
                if ($imageInfo) {
                    $elements[] = $this->imageManager->buildH5pImageElement($imageInfo, $alt, 52, 0, 48, 100);
                }
                break;
                
            case 'image_top':
                if ($imageInfo) {
                    $elements[] = $this->imageManager->buildH5pImageElement($imageInfo, $alt, 5, 0, 90, 55);
                }
                if ($text) {
                    $elements[] = $this->imageManager->buildH5pTextElement($text, 5, 58, 90, 38);
                }
                break;
                
            case 'image_full':
                if ($imageInfo) {
                    $elements[] = $this->imageManager->buildH5pImageElement($imageInfo, $alt, 0, 0, 100, 100);
                }
                if ($text) {
                    $cleanText = strip_tags($text, '<strong><em><br><span>');
                    $elements[] = $this->imageManager->buildH5pTextElement(
                        '<p style="background:rgba(0,0,0,0.7);color:#fff;padding:12px;border-radius:8px;">' . $cleanText . '</p>',
                        5, 65, 90, 30
                    );
                }
                break;
        }
        
        // Si l'image n'a pas pu être résolue, fallback texte seul
        if (!$imageInfo && $text) {
            $this->warnings[] = "Image non résolue pour slide '$layout', fallback texte seul";
            return $this->buildTextOnlySlide($spec);
        }
        
        return [
            'elements' => array_filter($elements),
            'slideBackgroundSelector' => new \stdClass(),
        ];
    }
    
    /**
     * Slide QCM / Checkpoint.
     */
    private function buildQuizSlide(array $spec) {
        $question = $spec['question'] ?? 'Question ?';
        $answers = $spec['answers'] ?? [];
        
        if (empty($answers)) {
            $answers = [
                ['text' => 'Oui ✅', 'correct' => true, 'feedback' => 'Continuez 👉'],
                ['text' => 'Non ❌', 'correct' => false, 'feedback' => 'Relisez les instructions'],
            ];
        }
        
        return [
            'elements' => [
                $this->imageManager->buildH5pMultiChoiceElement(
                    $question, $answers,
                    1.235, 1.458, 97.53, 93.05
                ),
            ],
            'slideBackgroundSelector' => new \stdClass(),
        ];
    }
    
    /**
     * Slide image + QCM.
     */
    private function buildImageQuizSlide(array $spec) {
        $elements = [];
        
        if (!empty($spec['image'])) {
            $imageInfo = $this->imageManager->resolve($spec['image']);
            if ($imageInfo) {
                $elements[] = $this->imageManager->buildH5pImageElement(
                    $imageInfo, $spec['alt'] ?? '', 5, 0, 90, 40
                );
            }
        }
        
        $elements[] = $this->imageManager->buildH5pMultiChoiceElement(
            $spec['question'] ?? 'Question ?',
            $spec['answers'] ?? [],
            2, 42, 96, 55
        );
        
        return [
            'elements' => array_filter($elements),
            'slideBackgroundSelector' => new \stdClass(),
        ];
    }
    
    /**
     * Slide tutoriel pas-à-pas.
     * L'image sera une capture d'écran réalisée côté client.
     * On crée le slide avec un espace pour l'image (qui sera injectée par injectScreenshots).
     */
    private function buildTutorialStepSlide(array $spec, int $sIdx, int $aIdx, int $slideIdx) {
        $instruction = $spec['instruction'] ?? '';
        $app = $spec['app'] ?? 'microbit';
        
        // Enregistrer cette étape pour capture côté client
        $screenshotKey = "{$sIdx}_{$aIdx}_{$slideIdx}";
        $this->pendingScreenshots[] = [
            'key' => $screenshotKey,
            'app' => $app,
            'actions' => $spec['actions'] ?? [],
            'screenshot' => $spec['screenshot'] ?? ['focus' => 'workspace', 'mode' => 'overview'],
            'pointer' => $spec['pointer'] ?? null,
            'instruction' => strip_tags($instruction),
        ];
        
        // Construire le slide avec placeholder pour l'image
        // L'image sera ajoutée en haut (0,0, 100%, 60%) par injectScreenshots()
        // Le texte est en bas pour laisser la place
        $elements = [
            // Zone texte instruction — positionnée en bas (sera repositionnée si screenshot injecté)
            $this->imageManager->buildH5pTextElement(
                $instruction . '<p style="color:#888;font-style:italic;">Quand c\'est fait, continuez 👉</p>',
                2, 5, 96, 90
            ),
        ];
        
        return [
            'elements' => $elements,
            'slideBackgroundSelector' => new \stdClass(),
        ];
    }
    
    /**
     * Slides d'avertissement matériel.
     * warning_type: "all" génère toutes les slides dans l'ordre.
     */
    private function buildWarningSlides(array $spec) {
        $type = $spec['warning_type'] ?? 'all';
        $slides = [];
        
        $warnings = [
            'fragile_card' => '<p style="text-align:center;font-size:1.3em;">⚠️ <strong>Attention, la carte est fragile, il faut la manipuler avec délicatesse.</strong> ⚠️</p>',
            
            'usb_plug' => '<p style="text-align:center;font-size:1.2em;">⚠️ <strong>Attention, la prise USB a un sens. Manipulez doucement les cartes pour ne pas les casser, elles sont fragiles.</strong> ⚠️</p>',
            
            'batteries' => '<p style="text-align:center;font-size:1.2em;">‼️ <strong>Les piles branchées à l\'envers pourraient griller la carte Micro:bit définitivement</strong> ‼️</p>'
                . '<p style="text-align:center;">💡 Vérifiez le sens grâce au détrompeur présent au dessus de la prise</p>',
            
            'method_reminder' => '<p style="text-align:center;">💡 Si besoin, retournez dans le parcours "Téléverser un programme" pour retrouver la méthode</p>',
            
            'cleanup' => '<p style="text-align:center;font-size:1.2em;">⚠️ <strong>Attention à tout remettre correctement avant de sortir de classe.</strong></p>',
        ];
        
        if ($type === 'all') {
            // Générer toutes les slides dans l'ordre
            foreach ($warnings as $wType => $html) {
                $slides[] = [
                    'elements' => [
                        $this->imageManager->buildH5pTextElement($html, 5, 15, 90, 70),
                    ],
                    'slideBackgroundSelector' => new \stdClass(),
                ];
            }
        } elseif (isset($warnings[$type])) {
            $slides[] = [
                'elements' => [
                    $this->imageManager->buildH5pTextElement($warnings[$type], 5, 15, 90, 70),
                ],
                'slideBackgroundSelector' => new \stdClass(),
            ];
        }
        
        return $slides;
    }
    
    // =========================================================================
    // H5P BLANKS (Trace résumée)
    // =========================================================================
    
    /**
     * Texte à trous H5P (trace résumée).
     * Le texte utilise *mot/alternative* pour les blancs.
     */
    private function buildBlanks(array $activity) {
        $text = $activity['text'] ?? '';
        
        // Le format attendu par H5P Blanks : les mots entre * sont les blancs
        // H5P utilise *mot* pour un blanc, et / pour les alternatives
        // Notre format spec est identique → pas de conversion nécessaire
        
        // S'assurer que le texte est en HTML
        if (strpos($text, '<p>') === false) {
            $text = '<p>' . $text . '</p>';
        }
        
        return [
            'type' => 'hvp',
            'name' => $activity['title'] ?? 'Trace résumée',
            'h5pType' => 'Blanks',
            'content' => [
                'text' => $text,
                'questions' => [$text],
            ],
        ];
    }
    
    // =========================================================================
    // QUIZ MOODLE
    // =========================================================================
    
    /**
     * Quiz Moodle natif (évaluation).
     */
    private function buildQuizMoodle(array $activity) {
        $questions = [];
        $totalGrade = 0;
        
        foreach ($activity['questions'] ?? [] as $qIdx => $q) {
            $points = $q['points'] ?? 1;
            $totalGrade += $points;
            
            $built = $this->buildQuizQuestion($q, $qIdx);
            if ($built) {
                $questions[] = $built;
            }
        }
        
        return [
            'type' => 'quiz',
            'name' => $activity['title'] ?? 'Quiz',
            'content' => [
                'intro' => $activity['intro'] ?? '',
                'attempts_number' => $activity['attempts'] ?? 1,
                'preferredbehaviour' => $activity['behaviour'] ?? 'deferredfeedback',
                'grade' => $activity['grade'] ?? $totalGrade,
                'gradepass' => $activity['gradepass'] ?? round(($activity['grade'] ?? $totalGrade) / 2),
                'questionsperpage' => 1,
                'shuffleanswers' => 1,
                'navmethod' => 'free',
                'questions' => $questions,
            ],
        ];
    }
    
    /**
     * Construit une question de quiz Moodle.
     */
    private function buildQuizQuestion(array $q, int $index) {
        $type = $q['type'] ?? 'multichoice';
        $base = [
            'type' => $type,
            'name' => $q['name'] ?? ('Q' . ($index + 1)),
            'text' => $q['text'] ?? '<p>Question ?</p>',
            'defaultmark' => number_format($q['points'] ?? 1, 7, '.', ''),
        ];
        
        switch ($type) {
            case 'multichoice':
                $base['single'] = 1; // Une seule réponse correcte
                $base['shuffleanswers'] = 1;
                $base['answernumbering'] = 'abc';
                $base['answers'] = [];
                foreach ($q['answers'] ?? [] as $a) {
                    $base['answers'][] = [
                        'text' => $a['text'] ?? '',
                        'fraction' => number_format(($a['fraction'] ?? 0) / 100, 7, '.', ''),
                        'feedback' => $a['feedback'] ?? '',
                    ];
                }
                break;
                
            case 'truefalse':
                $correct = $q['correct'] ?? true;
                $base['answers'] = [
                    [
                        'text' => 'Vrai',
                        'fraction' => $correct ? '1.0000000' : '0.0000000',
                        'feedback' => $q['feedback_true'] ?? '',
                    ],
                    [
                        'text' => 'Faux',
                        'fraction' => $correct ? '0.0000000' : '1.0000000',
                        'feedback' => $q['feedback_false'] ?? '',
                    ],
                ];
                break;
                
            case 'shortanswer':
                $base['usecase'] = 0;
                $base['answers'] = [];
                foreach ($q['answers'] ?? [] as $a) {
                    $base['answers'][] = [
                        'text' => $a['text'] ?? '',
                        'fraction' => number_format(($a['fraction'] ?? 100) / 100, 7, '.', ''),
                        'feedback' => $a['feedback'] ?? '',
                    ];
                }
                break;
                
            default:
                $this->warnings[] = "Type de question quiz non supporté : '$type'";
                return null;
        }
        
        return $base;
    }
    
    // =========================================================================
    // UTILITAIRES
    // =========================================================================
    
    /**
     * Sauvegarde une data URL (base64) en fichier local.
     */
    private function saveDataUrlAsFile(string $dataUrl) {
        if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,(.+)$/s', $dataUrl, $matches)) {
            return null;
        }
        
        $ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $data = base64_decode($matches[2]);
        if (!$data) return null;
        
        $uploadDir = defined('CACHE_DIR') 
            ? CACHE_DIR . '/editor_uploads' 
            : dirname(__DIR__) . '/cache/editor_uploads';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $filename = 'upload_screenshot_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;
        
        file_put_contents($destPath, $data);
        
        $info = @getimagesize($destPath);
        if (!$info) {
            @unlink($destPath);
            return null;
        }
        
        return [
            'localPath' => $destPath,
            'relativePath' => 'cache/editor_uploads/' . $filename,
            'filename' => $filename,
            'width' => $info[0],
            'height' => $info[1],
            'mime' => $info['mime'],
        ];
    }
}
