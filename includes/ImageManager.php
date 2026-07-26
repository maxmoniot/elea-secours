<?php
/**
 * ImageManager - Gestion des images pour le générateur de cours
 * 
 * Responsabilités :
 * - Télécharger des images depuis des URLs
 * - Rechercher des images via API (Pixabay, Wikimedia Commons)
 * - Sauvegarder en local dans cache/editor_uploads/
 * - Générer les structures H5P compatibles pour CoursePresentation
 * 
 * Les images téléchargées sont placées dans cache/editor_uploads/ avec le préfixe
 * "upload_" pour être automatiquement traitées par EleaMbzExporter::processFilePath().
 */

class ImageManager {
    
    private $uploadDir;
    private $maxWidth = 1200;
    private $maxHeight = 900;
    private $jpegQuality = 85;
    private $pixabayKey = null;
    
    /** @var array Cache des images déjà téléchargées (query => localPath) */
    private $cache = [];
    
    /** @var array Journal d'opérations pour debug */
    private $log = [];
    
    public function __construct() {
        $this->uploadDir = defined('CACHE_DIR') 
            ? CACHE_DIR . '/editor_uploads' 
            : dirname(__DIR__) . '/cache/editor_uploads';
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
        
        // Charger la clé Pixabay depuis config si disponible
        if (defined('PIXABAY_API_KEY')) {
            $this->pixabayKey = PIXABAY_API_KEY;
        }
    }
    
    /**
     * Retourne le journal d'opérations.
     */
    public function getLog(): array {
        return $this->log;
    }
    
    /**
     * Ajoute une entrée au journal.
     */
    private function addLog(string $msg): void {
        $this->log[] = $msg;
    }
    
    /**
     * Point d'entrée principal : résout une spécification d'image en fichier local.
     * 
     * Accepte plusieurs formats :
     *   - URL directe : ["url" => "https://example.com/photo.jpg"]
     *   - Recherche :   ["search" => "chicken coop automated", "index" => 0]
     *   - Chaîne simple : "https://example.com/photo.jpg" (traité comme URL)
     *   - Chaîne recherche : "search:chicken coop" (traité comme recherche)
     * 
     * @param string|array $spec Spécification de l'image
     * @return array|null ['localPath' => ..., 'width' => ..., 'height' => ..., 'mime' => ..., 'filename' => ...]
     */
    public function resolve($spec) {
        // Normaliser la spec
        if (is_string($spec)) {
            if (preg_match('#^https?://#i', $spec)) {
                $spec = ['url' => $spec];
            } elseif (strpos($spec, 'search:') === 0) {
                $spec = ['search' => substr($spec, 7)];
            } else {
                $spec = ['search' => $spec];
            }
        }
        
        if (!is_array($spec)) {
            $this->addLog("Erreur: spec invalide (ni string ni array)");
            return null;
        }
        
        // URL directe
        if (!empty($spec['url'])) {
            $this->addLog("Résolution URL directe : " . $spec['url']);
            return $this->downloadFromUrl($spec['url'], $spec['alt'] ?? '');
        }
        
        // Recherche d'image
        if (!empty($spec['search'])) {
            $index = $spec['index'] ?? 0;
            $this->addLog("Recherche image : '{$spec['search']}' (index=$index)");
            return $this->searchAndDownload($spec['search'], $index);
        }
        
        $this->addLog("Erreur: spec sans 'url' ni 'search'");
        return null;
    }
    
    /**
     * Télécharge une image depuis une URL.
     * 
     * @param string $url URL de l'image
     * @param string $alt Texte alternatif (pour le nom de fichier)
     * @return array|null Infos du fichier local
     */
    public function downloadFromUrl($url, $alt = '') {
        // Cache : éviter de re-télécharger
        $cacheKey = md5($url);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        // Vérifier si c'est déjà un fichier local
        if (!preg_match('#^https?://#i', $url)) {
            if (file_exists($url)) {
                $info = $this->getImageInfo($url);
                if ($info) {
                    $this->cache[$cacheKey] = $info;
                    return $info;
                }
            }
            return null;
        }
        
        // Télécharger
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_');
        $success = $this->curlDownload($url, $tmpFile);
        
        if (!$success || !file_exists($tmpFile) || filesize($tmpFile) < 100) {
            @unlink($tmpFile);
            $this->addLog("Erreur: échec téléchargement " . $url);
            error_log("ImageManager: Échec téléchargement " . $url);
            return null;
        }
        
        // Vérifier que c'est bien une image
        $imageInfo = @getimagesize($tmpFile);
        if (!$imageInfo) {
            @unlink($tmpFile);
            $this->addLog("Erreur: pas une image valide " . $url);
            error_log("ImageManager: Pas une image valide " . $url);
            return null;
        }
        
        // Déterminer l'extension
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        $mime = $imageInfo['mime'];
        $ext = $mimeToExt[$mime] ?? 'jpg';
        
        // Redimensionner si nécessaire (sauf SVG)
        if ($ext !== 'svg') {
            $this->resizeIfNeeded($tmpFile, $imageInfo, $ext);
            // Relire les infos après redimensionnement
            $imageInfo = @getimagesize($tmpFile);
        }
        
        // Générer un nom unique au format attendu par processFilePath
        $uniqueId = bin2hex(random_bytes(8));
        $filename = 'upload_' . $uniqueId . '.' . $ext;
        $destPath = $this->uploadDir . '/' . $filename;
        
        // Déplacer vers editor_uploads
        rename($tmpFile, $destPath);
        
        $result = [
            'localPath' => $destPath,
            'relativePath' => 'cache/editor_uploads/' . $filename,
            'filename' => $filename,
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'mime' => $mime,
        ];
        
        $this->cache[$cacheKey] = $result;
        $this->addLog("Image sauvegardée : {$filename} ({$imageInfo[0]}x{$imageInfo[1]})");
        error_log("ImageManager: Image sauvegardée " . $filename . " (" . $imageInfo[0] . "x" . $imageInfo[1] . ")");
        
        return $result;
    }
    
    /**
     * Recherche une image par mots-clés et la télécharge.
     * Sources : Pixabay (si clé configurée), sinon Wikimedia Commons.
     * 
     * @param string $query Mots-clés de recherche
     * @param int $index Index du résultat à utiliser (0 = premier)
     * @return array|null Infos du fichier local
     */
    public function searchAndDownload($query, $index = 0) {
        $cacheKey = md5('search:' . $query . ':' . $index);
        if (isset($this->cache[$cacheKey])) {
            $this->addLog("Cache hit pour '$query'");
            return $this->cache[$cacheKey];
        }
        
        // Essayer Pixabay en premier (meilleure qualité)
        if ($this->pixabayKey) {
            $this->addLog("Recherche Pixabay : '$query'");
            $url = $this->searchPixabay($query, $index);
            if ($url) {
                $this->addLog("Pixabay trouvé : $url");
                $result = $this->downloadFromUrl($url, $query);
                if ($result) {
                    $this->cache[$cacheKey] = $result;
                    return $result;
                }
            } else {
                $this->addLog("Pixabay : aucun résultat");
            }
        }
        
        // Fallback : Wikimedia Commons (pas besoin de clé)
        $this->addLog("Recherche Wikimedia : '$query'");
        $url = $this->searchWikimedia($query, $index);
        if ($url) {
            $this->addLog("Wikimedia trouvé : $url");
            $result = $this->downloadFromUrl($url, $query);
            if ($result) {
                $this->cache[$cacheKey] = $result;
                return $result;
            }
        } else {
            $this->addLog("Wikimedia : aucun résultat");
        }
        
        // Dernier fallback : image placeholder avec texte
        $this->addLog("Fallback placeholder pour '$query'");
        $result = $this->generatePlaceholder($query);
        if ($result) {
            $this->addLog("Placeholder créé : " . $result['filename']);
        }
        $this->cache[$cacheKey] = $result;
        return $result;
    }
    
    /**
     * Recherche sur Pixabay (gratuit avec clé API).
     * https://pixabay.com/api/docs/
     */
    private function searchPixabay($query, $index = 0) {
        $params = http_build_query([
            'key' => $this->pixabayKey,
            'q' => $query,
            'image_type' => 'photo',
            'orientation' => 'horizontal',
            'min_width' => 640,
            'per_page' => max(3, $index + 1),
            'safesearch' => 'true',
            'lang' => 'fr',
        ]);
        
        $url = 'https://pixabay.com/api/?' . $params;
        $response = $this->curlGet($url);
        
        if (!$response) return null;
        
        $data = json_decode($response, true);
        if (!$data || empty($data['hits'])) return null;
        
        $hit = $data['hits'][$index] ?? $data['hits'][0];
        // Utiliser webformatURL (640px) pour un bon compromis taille/qualité
        return $hit['webformatURL'] ?? $hit['largeImageURL'] ?? null;
    }
    
    /**
     * Recherche sur Wikimedia Commons (gratuit, pas de clé).
     * Utilise l'API MediaWiki pour chercher des images.
     */
    private function searchWikimedia($query, $index = 0) {
        $params = http_build_query([
            'action' => 'query',
            'format' => 'json',
            'generator' => 'images',
            'titles' => $query,
            'gimlimit' => max(5, $index + 1),
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|mime',
            'iiurlwidth' => 800,
        ]);
        
        // Essayer la recherche directe par fichiers
        $params2 = http_build_query([
            'action' => 'query',
            'format' => 'json',
            'list' => 'search',
            'srnamespace' => 6, // File namespace
            'srsearch' => $query,
            'srlimit' => max(5, $index + 3),
        ]);
        
        $url = 'https://commons.wikimedia.org/w/api.php?' . $params2;
        $response = $this->curlGet($url);
        
        if (!$response) return null;
        
        $data = json_decode($response, true);
        $results = $data['query']['search'] ?? [];
        
        if (empty($results)) return null;
        
        // Filtrer pour ne garder que les images (pas les SVG de catégories, etc.)
        $imageResults = [];
        foreach ($results as $r) {
            $title = $r['title'] ?? '';
            if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $title)) {
                $imageResults[] = $title;
            }
        }
        
        if (empty($imageResults)) return null;
        
        $targetTitle = $imageResults[$index] ?? $imageResults[0];
        
        // Récupérer l'URL de l'image
        $params3 = http_build_query([
            'action' => 'query',
            'format' => 'json',
            'titles' => $targetTitle,
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|mime',
            'iiurlwidth' => 800,
        ]);
        
        $url = 'https://commons.wikimedia.org/w/api.php?' . $params3;
        $response = $this->curlGet($url);
        
        if (!$response) return null;
        
        $data = json_decode($response, true);
        $pages = $data['query']['pages'] ?? [];
        
        foreach ($pages as $page) {
            $imageinfo = $page['imageinfo'][0] ?? null;
            if ($imageinfo) {
                // Préférer la version redimensionnée (thumburl) si disponible
                return $imageinfo['thumburl'] ?? $imageinfo['url'] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * Génère une image placeholder avec le texte de recherche.
     * Utilisé quand aucune source d'image n'est disponible.
     */
    private function generatePlaceholder($text, $width = 800, $height = 450) {
        $img = imagecreatetruecolor($width, $height);
        
        // Fond dégradé violet (cohérent avec Éléa-Secours)
        for ($y = 0; $y < $height; $y++) {
            $r = (int)(79 + ($y / $height) * 40);
            $g = (int)(70 + ($y / $height) * 20);
            $b = (int)(229 - ($y / $height) * 40);
            $color = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $width - 1, $y, $color);
        }
        
        // Texte centré
        $white = imagecolorallocate($img, 255, 255, 255);
        $alpha = imagecolorallocate($img, 200, 200, 255);
        
        // Texte principal (tronqué si trop long)
        $displayText = mb_strlen($text) > 40 ? mb_substr($text, 0, 37) . '...' : $text;
        $fontSize = 5; // Built-in font
        $textWidth = imagefontwidth($fontSize) * strlen($displayText);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        imagestring($img, $fontSize, (int)$x, (int)$y, $displayText, $white);
        
        // Sous-titre
        $subText = '[Image placeholder]';
        $subWidth = imagefontwidth(3) * strlen($subText);
        imagestring($img, 3, (int)(($width - $subWidth) / 2), (int)$y + 25, $subText, $alpha);
        
        // Icône caméra simple (cercle + rectangle)
        $iconY = $y - 50;
        imagefilledellipse($img, (int)($width / 2), (int)$iconY, 40, 40, $alpha);
        
        // Sauvegarder
        $uniqueId = bin2hex(random_bytes(8));
        $filename = 'upload_placeholder_' . $uniqueId . '.jpg';
        $destPath = $this->uploadDir . '/' . $filename;
        
        imagejpeg($img, $destPath, $this->jpegQuality);
        imagedestroy($img);
        
        return [
            'localPath' => $destPath,
            'relativePath' => 'cache/editor_uploads/' . $filename,
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
            'mime' => 'image/jpeg',
        ];
    }
    
    // =========================================================================
    // CONSTRUCTION DES STRUCTURES H5P
    // =========================================================================
    
    /**
     * Construit un élément H5P.Image pour un slide CoursePresentation.
     * 
     * @param array $imageInfo Résultat de resolve() ou downloadFromUrl()
     * @param string $alt Texte alternatif
     * @param float $x Position X (% du slide, 0-100)
     * @param float $y Position Y (% du slide, 0-100)
     * @param float $width Largeur (% du slide)
     * @param float $height Hauteur (% du slide)
     * @return array Élément H5P prêt à insérer dans un slide
     */
    public function buildH5pImageElement($imageInfo, $alt = '', $x = 0, $y = 0, $width = 100, $height = 100) {
        if (!$imageInfo) return null;
        
        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'action' => [
                'library' => 'H5P.Image 1.1',
                'params' => [
                    'file' => [
                        'path' => $imageInfo['relativePath'],
                        'mime' => $imageInfo['mime'],
                        'width' => $imageInfo['width'],
                        'height' => $imageInfo['height'],
                        'copyright' => ['license' => 'U'],
                    ],
                    'alt' => $alt,
                    'decorative' => empty($alt),
                    'contentName' => 'Image',
                    'expandImage' => 'Expand Image',
                    'minimizeImage' => 'Minimize Image',
                ],
                'metadata' => [
                    'contentType' => 'Image',
                    'license' => 'U',
                    'title' => '',
                ],
                'subContentId' => $this->generateUUID(),
            ],
        ];
    }
    
    /**
     * Construit un élément H5P.AdvancedText pour un slide CoursePresentation.
     * 
     * @param string $html Contenu HTML du texte
     * @param float $x Position X (%)
     * @param float $y Position Y (%)
     * @param float $width Largeur (%)
     * @param float $height Hauteur (%)
     * @return array Élément H5P
     */
    public function buildH5pTextElement($html, $x = 0, $y = 0, $width = 100, $height = 50) {
        // S'assurer que le texte est en HTML
        if (strpos($html, '<') === false) {
            $html = '<p>' . htmlspecialchars($html) . '</p>';
        }
        
        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'action' => [
                'library' => 'H5P.AdvancedText 1.1',
                'params' => [
                    'text' => $html,
                ],
                'metadata' => [
                    'contentType' => 'Text',
                    'license' => 'U',
                    'title' => '',
                ],
                'subContentId' => $this->generateUUID(),
            ],
        ];
    }
    
    /**
     * Construit un élément H5P.MultiChoice pour un slide CoursePresentation (checkpoint).
     * 
     * @param string $question La question
     * @param array $answers [['text' => '...', 'correct' => true/false, 'feedback' => '...'], ...]
     * @param float $x Position X (%)
     * @param float $y Position Y (%)
     * @param float $width Largeur (%)
     * @param float $height Hauteur (%)
     * @return array Élément H5P
     */
    public function buildH5pMultiChoiceElement($question, $answers, $x = 0, $y = 0, $width = 100, $height = 100) {
        // Formater la question en HTML
        if (strpos($question, '<') === false) {
            $question = '<p>' . htmlspecialchars($question) . '</p>';
        }
        
        // Formater les réponses
        $formattedAnswers = [];
        foreach ($answers as $a) {
            $text = $a['text'] ?? '';
            if (strpos($text, '<') === false) {
                $text = '<div>' . htmlspecialchars($text) . "</div>\n";
            }
            $formattedAnswers[] = [
                'text' => $text,
                'correct' => $a['correct'] ?? false,
                'tipsAndFeedback' => [
                    'tip' => '',
                    'chosenFeedback' => '<div>' . htmlspecialchars($a['feedback'] ?? '') . "</div>\n",
                    'notChosenFeedback' => '',
                ],
            ];
        }
        
        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'action' => [
                'library' => 'H5P.MultiChoice 1.16',
                'params' => [
                    'question' => $question,
                    'answers' => $formattedAnswers,
                    'media' => [
                        'disableImageZooming' => false,
                        'type' => ['params' => new \stdClass()],
                    ],
                    'overallFeedback' => [['from' => 0, 'to' => 100]],
                    'behaviour' => [
                        'enableRetry' => true,
                        'enableSolutionsButton' => true,
                        'enableCheckButton' => true,
                        'type' => 'auto',
                        'singlePoint' => false,
                        'randomAnswers' => true,
                        'showSolutionsRequiresInput' => true,
                        'confirmCheckDialog' => false,
                        'confirmRetryDialog' => false,
                        'autoCheck' => false,
                        'passPercentage' => 100,
                        'showScorePoints' => true,
                    ],
                    'UI' => [
                        'checkAnswerButton' => 'Vérifier',
                        'submitAnswerButton' => 'Envoyer',
                        'showSolutionButton' => 'Voir la solution',
                        'tryAgainButton' => 'Recommencer',
                        'tipsLabel' => 'Afficher les indices',
                        'scoreBarLabel' => 'Vous avez obtenu :num points sur :total',
                        'tipAvailable' => 'Indice disponible',
                        'feedbackAvailable' => 'Feedback disponible',
                        'readFeedback' => 'Lire le feedback',
                        'wrongAnswer' => 'Mauvaise réponse',
                        'correctAnswer' => 'Bonne réponse',
                        'shouldCheck' => "Aurait dû être cochée",
                        'shouldNotCheck' => "N'aurait pas dû être cochée",
                        'noInput' => 'Veuillez répondre avant de voir la solution',
                        'a11yCheck' => 'Vérifiez les réponses.',
                        'a11yShowSolution' => 'Montrez la solution.',
                        'a11yRetry' => 'Réessayez la tâche.',
                    ],
                    'confirmCheck' => [
                        'header' => 'Terminer ?',
                        'body' => 'Êtes-vous certain de vouloir terminer ?',
                        'cancelLabel' => 'Annuler',
                        'confirmLabel' => 'Terminer',
                    ],
                    'confirmRetry' => [
                        'header' => 'Recommencer ?',
                        'body' => 'Êtes-vous certain de vouloir recommencer ?',
                        'cancelLabel' => 'Annuler',
                        'confirmLabel' => 'Confirmer',
                    ],
                ],
                'metadata' => [
                    'contentType' => 'Multiple Choice',
                    'license' => 'U',
                    'title' => '',
                ],
                'subContentId' => $this->generateUUID(),
            ],
        ];
    }
    
    /**
     * Construit un slide CoursePresentation complet avec disposition automatique.
     * 
     * Layout types :
     *   - 'image_left'  : image à gauche (50%), texte à droite (50%)
     *   - 'image_right' : texte à gauche, image à droite
     *   - 'image_top'   : image en haut (60%), texte en bas (40%)
     *   - 'image_full'  : image plein écran, texte superposé en bas
     *   - 'text_only'   : texte seul plein écran
     *   - 'quiz_only'   : QCM plein écran
     *   - 'image_quiz'  : image en haut, QCM en bas
     * 
     * @param array $options Contenu du slide
     * @return array Slide H5P
     */
    public function buildSlide($options = []) {
        $elements = [];
        $layout = $options['layout'] ?? 'text_only';
        $this->addLog("buildSlide: layout=$layout");
        
        // Résoudre l'image si nécessaire
        $imageInfo = null;
        if (!empty($options['image'])) {
            $imageInfo = $this->resolve($options['image']);
            if (!$imageInfo) {
                $this->addLog("Erreur: image non résolue pour layout '$layout'");
            }
        }
        
        switch ($layout) {
            case 'image_left':
                if ($imageInfo) {
                    $elements[] = $this->buildH5pImageElement($imageInfo, $options['alt'] ?? '', 0, 0, 48, 100);
                }
                if (!empty($options['text'])) {
                    $elements[] = $this->buildH5pTextElement($options['text'], 50, 10, 48, 72);
                }
                $elements[] = $this->buildNavElement('Continuez 👉', 52, 87, 44, 8);
                break;
                
            case 'image_right':
                if (!empty($options['text'])) {
                    $elements[] = $this->buildH5pTextElement($options['text'], 2, 10, 48, 72);
                }
                if ($imageInfo) {
                    $elements[] = $this->buildH5pImageElement($imageInfo, $options['alt'] ?? '', 52, 0, 48, 100);
                }
                $elements[] = $this->buildNavElement('Continuez 👉', 2, 87, 46, 8);
                break;
                
            case 'image_top':
                if ($imageInfo) {
                    $elements[] = $this->buildH5pImageElement($imageInfo, $options['alt'] ?? '', 2, 0, 96, 55);
                }
                if (!empty($options['text'])) {
                    $elements[] = $this->buildH5pTextElement($options['text'], 5, 58, 90, 28);
                }
                $elements[] = $this->buildNavElement('Continuez 👉', 52, 89, 46, 8);
                break;
                
            case 'image_full':
                if ($imageInfo) {
                    $elements[] = $this->buildH5pImageElement($imageInfo, $options['alt'] ?? '', 0, 0, 100, 100);
                }
                if (!empty($options['text'])) {
                    $cleanText = strip_tags($options['text'], '<strong><em><br><span>');
                    $elements[] = $this->buildH5pTextElement(
                        '<p style="background:rgba(0,0,0,0.7);color:#fff;padding:12px;border-radius:8px;">' . $cleanText . '</p>',
                        5, 65, 90, 30
                    );
                }
                break;
                
            case 'quiz_only':
            case 'checkpoint':
                if (!empty($options['question']) && !empty($options['answers'])) {
                    $elements[] = $this->buildH5pMultiChoiceElement(
                        $options['question'], $options['answers'],
                        10, 10, 80, 80
                    );
                }
                break;
                
            case 'image_quiz':
                if ($imageInfo) {
                    $elements[] = $this->buildH5pImageElement($imageInfo, $options['alt'] ?? '', 5, 2, 90, 40);
                }
                if (!empty($options['question']) && !empty($options['answers'])) {
                    $elements[] = $this->buildH5pMultiChoiceElement(
                        $options['question'], $options['answers'],
                        10, 45, 80, 52
                    );
                }
                break;
                
            case 'text_only':
            default:
                if (!empty($options['text'])) {
                    $elements[] = $this->buildH5pTextElement($options['text'], 5, 15, 90, 65);
                }
                $elements[] = $this->buildNavElement('À la prochaine diapositive, cliquez sur "SUIVANT" 👉', 25, 87, 73, 8);
                break;
        }
        
        // Filtrer les éléments null
        $elements = array_values(array_filter($elements));
        
        return [
            'elements' => $elements,
            'slideBackgroundSelector' => new \stdClass(),
        ];
    }
    
    /**
     * Construit un petit élément de navigation (bas à droite, italique, grisé).
     */
    public function buildNavElement($text, $x = 55, $y = 87, $w = 43, $h = 10) {
        return $this->buildH5pTextElement(
            '<p style="text-align:right;color:#888;font-style:italic;font-size:0.9em;">' . htmlspecialchars($text) . '</p>',
            $x, $y, $w, $h
        );
    }
    
    /**
     * Construit une CoursePresentation H5P complète à partir d'un tableau de slides.
     * 
     * @param array $slidesSpecs Tableau de specs (chaque élément = options pour buildSlide())
     * @param array $keywords Mots-clés optionnels pour la navigation
     * @return array Structure H5P complète pour CoursePresentation
     */
    public function buildCoursePresentation($slidesSpecs, $keywords = []) {
        $slides = [];
        foreach ($slidesSpecs as $spec) {
            $slides[] = $this->buildSlide($spec);
        }
        
        return [
            'presentation' => [
                'slides' => $slides,
                'keywordListEnabled' => !empty($keywords),
                'globalBackgroundSelector' => new \stdClass(),
                'keywordListAlwaysShow' => false,
                'keywordListAutoHide' => false,
                'keywordListOpacity' => 90,
            ],
            'override' => [
                'activeSurface' => false,
                'hideSummarySlide' => false,
                'summarySlideSolutionButton' => true,
                'summarySlideRetryButton' => true,
                'enablePrintButton' => false,
                'social' => [
                    'showFacebookShare' => false,
                    'facebookShare' => [
                        'url' => '@currentpageurl',
                        'quote' => "J'ai un score de @score sur un total de @maxScore pour @currentpageurl.",
                    ],
                    'showTwitterShare' => false,
                    'twitterShare' => [
                        'statement' => "J'ai un score de @score sur un total de @maxScore pour @currentpageurl.",
                        'url' => '@currentpageurl',
                        'hashtags' => 'h5p, cours',
                    ],
                    'showGoogleShare' => false,
                    'googleShareUrl' => '@currentpageurl',
                ],
            ],
            'l10n' => $this->getCoursePresentationL10n(),
        ];
    }
    
    // =========================================================================
    // UTILITAIRES PRIVÉS
    // =========================================================================
    
    /**
     * Télécharge un fichier via cURL.
     */
    private function curlDownload($url, $destPath) {
        $ch = curl_init($url);
        $fp = fopen($destPath, 'wb');
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'EleaSecours/1.0 (Educational Course Generator)',
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);
        
        return $success && $httpCode >= 200 && $httpCode < 400;
    }
    
    /**
     * GET request via cURL, retourne le body.
     */
    private function curlGet($url) {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'EleaSecours/1.0 (Educational Course Generator)',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode >= 200 && $httpCode < 400) {
            return $response;
        }
        
        error_log("ImageManager: HTTP $httpCode pour $url");
        return null;
    }
    
    /**
     * Redimensionne une image si elle dépasse les limites.
     */
    private function resizeIfNeeded($filePath, $imageInfo, $ext) {
        $origWidth = $imageInfo[0];
        $origHeight = $imageInfo[1];
        
        if ($origWidth <= $this->maxWidth && $origHeight <= $this->maxHeight) {
            return; // Pas besoin de redimensionner
        }
        
        // Calculer les nouvelles dimensions
        $ratio = min($this->maxWidth / $origWidth, $this->maxHeight / $origHeight);
        $newWidth = (int)($origWidth * $ratio);
        $newHeight = (int)($origHeight * $ratio);
        
        // Charger l'image
        switch ($imageInfo['mime']) {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $src = @imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                $src = @imagecreatefromwebp($filePath);
                break;
            default:
                return;
        }
        
        if (!$src) return;
        
        // Redimensionner
        $dst = imagecreatetruecolor($newWidth, $newHeight);
        
        // Préserver la transparence pour PNG/GIF
        if ($imageInfo['mime'] === 'image/png' || $imageInfo['mime'] === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        
        // Sauvegarder
        switch ($imageInfo['mime']) {
            case 'image/jpeg':
                imagejpeg($dst, $filePath, $this->jpegQuality);
                break;
            case 'image/png':
                imagepng($dst, $filePath, 6);
                break;
            case 'image/gif':
                imagegif($dst, $filePath);
                break;
            case 'image/webp':
                imagewebp($dst, $filePath, $this->jpegQuality);
                break;
        }
        
        imagedestroy($src);
        imagedestroy($dst);
    }
    
    /**
     * Récupère les infos d'une image locale.
     */
    private function getImageInfo($path) {
        $info = @getimagesize($path);
        if (!$info) return null;
        
        $filename = basename($path);
        
        return [
            'localPath' => $path,
            'relativePath' => 'cache/editor_uploads/' . $filename,
            'filename' => $filename,
            'width' => $info[0],
            'height' => $info[1],
            'mime' => $info['mime'],
        ];
    }
    
    /**
     * Génère un UUID v4.
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Textes de localisation FR pour CoursePresentation.
     */
    private function getCoursePresentationL10n() {
        return [
            'slide' => 'Diapositive',
            'score' => 'Score',
            'yourScore' => 'Votre score',
            'maxScore' => 'Score maximal',
            'total' => 'Total',
            'totalScore' => 'Score total',
            'showSolutions' => 'Voir les solutions',
            'retry' => 'Recommencer',
            'exportAnswers' => 'Exporter le texte',
            'hideKeywords' => 'Masquer la barre latérale',
            'showKeywords' => 'Afficher la barre latérale',
            'fullscreen' => 'Plein écran',
            'exitFullscreen' => 'Quitter le plein écran',
            'prevSlide' => 'Diapositive précédente',
            'nextSlide' => 'Diapositive suivante',
            'currentSlide' => 'Diapositive actuelle',
            'lastSlide' => 'Dernière diapositive',
            'solutionModeTitle' => 'Quitter le mode solution',
            'solutionModeText' => "Vous êtes en mode solution. Cliquez sur Retour pour revenir.",
            'summaryMultipleTaskText' => 'Résumé - plusieurs activités',
            'scoreMessage' => 'Vous avez obtenu :achieved sur :max.',
            'shareFacebook' => 'Partager sur Facebook',
            'shareTwitter' => 'Partager sur Twitter',
            'shareGoogle' => 'Partager sur Google+',
            'goToSlide' => 'Aller à la diapositive :num',
            'solutionsButtonTitle' => 'Voir les solutions',
            'printTitle' => 'Imprimer',
            'printIngress' => 'Comment souhaitez-vous imprimer ?',
            'printAllSlides' => 'Imprimer toutes les diapositives',
            'printCurrentSlide' => 'Imprimer la diapositive actuelle',
            'noTitle' => 'Sans titre',
            'accessibilitySlideNavigationExplanation' => 'Utilisez les flèches pour naviguer entre les diapositives.',
            'accessibilityCanvasLabel' => 'Diapositive de la présentation',
            'containsNotCompleted' => '@slideName contient des interactions non complétées',
            'containsCompleted' => '@slideName contient des interactions complétées',
            'slideCount' => 'Diapositive @index sur @total',
            'containsOnlyCorrect' => '@slideName – toutes les réponses sont correctes',
            'containsIncorrectAnswers' => '@slideName – réponses incorrectes',
            'shareResult' => 'Partager le résultat',
            'accessibilityTotalScore' => 'Vous avez obtenu @score points sur @maxScore',
            'accessibilityEnteredFullscreen' => 'Plein écran activé',
            'accessibilityExitedFullscreen' => 'Plein écran désactivé',
            'confirmDialogHeader' => 'Envoyer les réponses',
            'confirmDialogText' => 'Cette action va soumettre vos réponses. Voulez-vous continuer ?',
            'confirmDialogConfirmText' => 'Envoyer',
        ];
    }
    
    /**
     * Nettoie les images temporaires plus anciennes que $maxAge secondes.
     */
    public function cleanup($maxAge = 86400) {
        $count = 0;
        foreach (glob($this->uploadDir . '/upload_*.{jpg,png,gif,webp}', GLOB_BRACE) as $file) {
            if (filemtime($file) < time() - $maxAge) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }
}
