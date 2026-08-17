<?php
/**
 * EleaSecours - MBZ Parser
 * Extraction et analyse des fichiers de sauvegarde Moodle
 */

class MbzParser {
    private string $mbzPath;
    private string $extractPath;
    private array $courseData = [];
    private array $files = [];
    private array $activities = [];
    private array $sections = [];
    private array $questions = [];
    
    /** Callback (percent, label) pour la barre de progression de l'éditeur */
    private $progressCb = null;

    public function __construct(string $mbzPath, string $extractPath) {
        $this->mbzPath = $mbzPath;
        $this->extractPath = $extractPath;
    }

    public function setProgressCallback(callable $cb): void {
        $this->progressCb = $cb;
    }

    private function progress(float $percent, string $label): void {
        if ($this->progressCb) {
            ($this->progressCb)($percent, $label);
        }
    }

    /**
     * Extrait et parse le fichier MBZ complet.
     * Les pourcentages couvrent 0-70 % : l'appelant garde la fin pour la copie des
     * fichiers et la conversion au format éditeur.
     */
    public function parse(): array {
        // Extraction du tar.gz
        $this->progress(5, 'Décompression de l\'archive…');
        $this->extract();

        // Parse les métadonnées
        $this->progress(25, 'Lecture des informations du cours…');
        $this->parseBackupInfo();

        // Parse les fichiers
        $this->progress(30, 'Inventaire des fichiers…');
        $this->parseFiles();

        // Parse les sections
        $this->progress(38, 'Lecture des sections…');
        $this->parseSections();

        // Parse les activités
        $this->parseActivities();

        // Parse les questions
        $this->progress(65, 'Lecture des questions…');
        $this->parseQuestions();

        // Construit la structure finale
        $this->progress(70, 'Assemblage du cours…');
        return $this->buildCourseStructure();
    }
    
    /**
     * Extrait l'archive MBZ (tar.gz ou zip)
     */
    private function extract(): void {
        if (!is_dir($this->extractPath)) {
            mkdir($this->extractPath, 0755, true);
        }
        
        // Détection du type d'archive
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $this->mbzPath);
        // finfo_close() supprimé - déprécié en PHP 8.5+
        
        if (strpos($mimeType, 'gzip') !== false || strpos($mimeType, 'x-gzip') !== false || strpos($mimeType, 'tar') !== false) {
            // Méthode 1 : Commande tar (plus fiable sur hébergement mutualisé)
            $escaped_mbz = escapeshellarg($this->mbzPath);
            $escaped_dest = escapeshellarg($this->extractPath);
            $result = @exec("tar -xzf {$escaped_mbz} -C {$escaped_dest} 2>&1", $output, $returnCode);
            
            if ($returnCode === 0) {
                return; // Succès
            }
            
            // Méthode 2 : PharData (fallback)
            try {
                $phar = new PharData($this->mbzPath);
                $phar->extractTo($this->extractPath, null, true);
                return;
            } catch (Exception $e) {
                // Continue vers méthode 3
            }
            
            // Méthode 3 : Décompression manuelle gzip + tar
            try {
                $gzPath = $this->mbzPath;
                $tarPath = $this->extractPath . '/temp.tar';
                
                // Décompresse le gzip
                $gz = gzopen($gzPath, 'rb');
                $tar = fopen($tarPath, 'wb');
                while (!gzeof($gz)) {
                    fwrite($tar, gzread($gz, 4096));
                }
                gzclose($gz);
                fclose($tar);
                
                // Extrait le tar avec PharData
                $phar = new PharData($tarPath);
                $phar->extractTo($this->extractPath, null, true);
                unlink($tarPath);
                return;
            } catch (Exception $e) {
                throw new Exception("Impossible d'extraire le fichier .mbz : " . $e->getMessage());
            }
        } else {
            // zip
            $zip = new ZipArchive();
            if ($zip->open($this->mbzPath) === true) {
                $zip->extractTo($this->extractPath);
                $zip->close();
            } else {
                throw new Exception("Impossible d'ouvrir le fichier .mbz comme ZIP");
            }
        }
    }
    
    /**
     * Parse les informations générales du backup
     */
    private function parseBackupInfo(): void {
        $xmlPath = $this->extractPath . '/moodle_backup.xml';
        if (!file_exists($xmlPath)) return;
        
        $xml = simplexml_load_file($xmlPath);
        $info = $xml->information;
        
        $this->courseData = [
            'moodle_version' => (string)$info->moodle_release,
            'backup_date' => (int)$info->backup_date,
            'course_fullname' => (string)$info->original_course_fullname,
            'course_shortname' => (string)$info->original_course_shortname,
            'course_format' => (string)$info->original_course_format,
            'original_url' => (string)$info->original_wwwroot,
        ];
        
        // Parse course.xml pour plus de détails
        $courseXml = $this->extractPath . '/course/course.xml';
        if (file_exists($courseXml)) {
            $course = simplexml_load_file($courseXml);
            $this->courseData['summary'] = $this->cleanHtml((string)$course->summary);
        }
    }
    
    /**
     * Parse le mapping des fichiers
     */
    private function parseFiles(): void {
        $xmlPath = $this->extractPath . '/files.xml';
        if (!file_exists($xmlPath)) return;
        
        $xml = simplexml_load_file($xmlPath);
        
        foreach ($xml->file as $file) {
            $hash = (string)$file->contenthash;
            $filename = (string)$file->filename;
            
            // Ignore les entrées de dossier
            if ($filename === '.' || empty($hash)) continue;
            
            // Inclure itemid dans la clé pour que les fichiers DDI (bgimage/dragimage)
            // portant le même nom dans le même contexte ne s'écrasent pas mutuellement
            $uniqueKey = (int)$file->contextid . ':' . (int)$file->itemid . ':' . (string)$file->filepath . $filename;
            
            $this->files[$uniqueKey] = [
                'id' => (int)$file['id'],
                'hash' => $hash,
                'filename' => $filename,
                'filepath' => (string)$file->filepath,
                'mimetype' => (string)$file->mimetype,
                'filesize' => (int)$file->filesize,
                'component' => (string)$file->component,
                'filearea' => (string)$file->filearea,
                'itemid' => (int)$file->itemid,
                'contextid' => (int)$file->contextid,
            ];
        }
    }
    
    /**
     * Parse les sections du cours
     */
    private function parseSections(): void {
        $sectionsDir = $this->extractPath . '/sections';
        if (!is_dir($sectionsDir)) return;
        
        foreach (scandir($sectionsDir) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $sectionXml = $sectionsDir . '/' . $dir . '/section.xml';
            if (!file_exists($sectionXml)) continue;
            
            $xml = simplexml_load_file($sectionXml);
            $sectionId = (int)$xml['id'];
            
            // Récupère la séquence et nettoie les IDs (trim pour éviter les espaces)
            $rawSequence = (string)$xml->sequence;
            $sequenceIds = array_filter(array_map('trim', explode(',', $rawSequence)));
            
            $this->sections[$sectionId] = [
                'id' => $sectionId,
                'number' => (int)$xml->number,
                'name' => $this->cleanText(((string)$xml->name ?: (string)$xml->n)),
                'summary' => $this->cleanHtml((string)$xml->summary),
                'visible' => (int)$xml->visible,
                'sequence' => $sequenceIds,
                'activities' => [],
            ];
        }
        
        // Tri par numéro de section
        uasort($this->sections, fn($a, $b) => $a['number'] <=> $b['number']);
    }
    
    /**
     * Parse toutes les activités
     */
    private function parseActivities(): void {
        $activitiesDir = $this->extractPath . '/activities';
        if (!is_dir($activitiesDir)) return;

        // Les activités sont le gros du travail : on répartit 40 % → 65 %
        $dossiers = array_values(array_diff(scandir($activitiesDir), ['.', '..']));
        $total = count($dossiers);
        $faites = 0;

        foreach ($dossiers as $dir) {
            $this->progress($total ? 40 + 25 * ($faites / $total) : 40,
                            'Activité ' . ($faites + 1) . '/' . $total . '…');
            $faites++;

            $parts = explode('_', $dir);
            $type = $parts[0];
            $moduleId = $parts[1] ?? null;
            
            $activityPath = $activitiesDir . '/' . $dir;
            
            $activity = match($type) {
                'hvp' => $this->parseHvpActivity($activityPath, $moduleId),
                'h5pactivity' => $this->parseH5pCoreActivity($activityPath, $moduleId),
                'quiz' => $this->parseQuizActivity($activityPath, $moduleId),
                'page' => $this->parsePageActivity($activityPath, $moduleId),
                'resource' => $this->parseResourceActivity($activityPath, $moduleId),
                'url' => $this->parseUrlActivity($activityPath, $moduleId),
                'label' => $this->parseLabelActivity($activityPath, $moduleId),
                'book' => $this->parseBookActivity($activityPath, $moduleId),
                'folder' => $this->parseFolderActivity($activityPath, $moduleId),
                'lesson' => $this->parseLessonActivity($activityPath, $moduleId),
                'mapmodules' => $this->parseMapmodulesActivity($activityPath, $moduleId),
                'assign' => $this->parseAssignActivity($activityPath, $moduleId),
                // « qbank » n'est pas une activité : c'est la banque de questions que
                // Moodle 5 / Éléa crée automatiquement dans chaque cours (elle arrive
                // dans toute sauvegarde). L'afficher comme une activité « Activité
                // qbank » n'a aucun sens pour le professeur.
                'qbank' => null,
                default => $this->parseGenericActivity($activityPath, $moduleId, $type),
            };
            
            if ($activity) {
                // Ignorer les activités sans nom (ressources internes Moodle, fichiers H5P, etc.)
                if (empty(trim($activity['name'] ?? ''))) {
                    continue;
                }
                
                // Lire la visibilité depuis module.xml
                $moduleXmlPath = $activityPath . '/module.xml';
                if (file_exists($moduleXmlPath)) {
                    $modXml = @simplexml_load_file($moduleXmlPath);
                    if ($modXml) {
                        $activity['visible'] = (int)($modXml->visible ?? 1);
                        $activity['visibleold'] = (int)($modXml->visibleold ?? 1);
                    }
                }
                $this->activities[$moduleId] = $activity;
            }
        }
    }
    
    /**
     * Parse une activité H5P (plugin HVP)
     */
    private function parseHvpActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/hvp.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $hvp = $xml->hvp;
        
        $jsonContent = (string)$hvp->json_content;
        $content = json_decode($jsonContent, true);
        
        // Récupère les fichiers associés (images, etc.)
        $files = $this->getActivityFiles((int)$xml['contextid']);
        
        return [
            'type' => 'hvp',
            'module_id' => $moduleId,
            'id' => (int)$hvp['id'],
            'name' => $this->cleanText(((string)$hvp->name ?: (string)$hvp->n)),
            'intro' => $this->cleanHtml((string)$hvp->intro),
            'machine_name' => (string)$hvp->machine_name,
            'major_version' => (int)$hvp->major_version,
            'minor_version' => (int)$hvp->minor_version,
            'content' => $content,
            'json_content' => $jsonContent,
            'files' => $files,
            'embed_type' => (string)$hvp->embed_type,
        ];
    }
    
    /**
     * Parse une activité H5P Core (mod_h5pactivity)
     */
    private function parseH5pCoreActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/h5pactivity.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $h5p = $xml->h5pactivity;
        
        $files = $this->getActivityFiles((int)$xml['contextid']);
        
        // Cherche le fichier .h5p
        $h5pFile = null;
        foreach ($files as $file) {
            if (pathinfo($file['filename'], PATHINFO_EXTENSION) === 'h5p') {
                $h5pFile = $file;
                break;
            }
        }
        
        return [
            'type' => 'h5pactivity',
            'module_id' => $moduleId,
            'id' => (int)$h5p['id'],
            'name' => $this->cleanText(((string)$h5p->name ?: (string)$h5p->n)),
            'intro' => $this->cleanHtml((string)$h5p->intro),
            'h5p_file' => $h5pFile,
            'files' => $files,
        ];
    }
    
    /**
     * Parse une activité Quiz
     */
    private function parseQuizActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/quiz.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $quiz = $xml->quiz;
        
        // Récupère les instances de questions
        $questionSlots = [];
        foreach ($quiz->question_instances->question_instance as $instance) {
            $ref = $instance->question_reference;
            $questionSlots[] = [
                'slot' => (int)$instance->slot,
                'page' => (int)$instance->page,
                'maxmark' => (float)$instance->maxmark,
                'question_bank_entry_id' => (int)$ref->questionbankentryid,
            ];
        }
        
        // Tri par slot
        usort($questionSlots, fn($a, $b) => $a['slot'] <=> $b['slot']);
        
        return [
            'type' => 'quiz',
            'module_id' => $moduleId,
            'id' => (int)$quiz['id'],
            'name' => $this->cleanText(((string)$quiz->name ?: (string)$quiz->n)),
            'intro' => $this->cleanHtml((string)$quiz->intro),
            'time_limit' => (int)$quiz->timelimit,
            'grade' => (float)$quiz->grade,
            'attempts_number' => (int)$quiz->attempts_number,
            'shuffle_answers' => (int)$quiz->shuffleanswers,
            'questions_per_page' => (int)$quiz->questionsperpage,
            'question_slots' => $questionSlots,
            'preferred_behaviour' => (string)$quiz->preferredbehaviour,
        ];
    }
    
    /**
     * Parse une activité Page
     */
    private function parsePageActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/page.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $page = $xml->page;
        
        return [
            'type' => 'page',
            'module_id' => $moduleId,
            'id' => (int)$page['id'],
            'name' => $this->cleanText(((string)$page->name ?: (string)$page->n)),
            'intro' => $this->cleanHtml((string)$page->intro),
            'content' => $this->cleanHtml((string)$page->content),
            'files' => $this->getActivityFiles((int)$xml['contextid']),
        ];
    }
    
    /**
     * Parse une activité Resource (fichier)
     */
    private function parseResourceActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/resource.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $resource = $xml->resource;
        
        $files = $this->getActivityFiles((int)$xml['contextid']);
        $mainFile = null;
        $contentFiles = [];
        
        foreach ($files as $file) {
            if ($file['filearea'] === 'content' && $file['filename'] !== '.') {
                $contentFiles[] = $file;
                if (!$mainFile) $mainFile = $file;
            }
        }
        
        return [
            'type' => 'resource',
            'module_id' => $moduleId,
            'id' => (int)$resource['id'],
            'name' => $this->cleanText(((string)$resource->name ?: (string)$resource->n)),
            'intro' => $this->cleanHtml((string)$resource->intro),
            'main_file' => $mainFile,
            'content_files' => $contentFiles,
            'files' => $files,
        ];
    }
    
    /**
     * Parse une activité Devoir (assign) — fichier à distribuer
     */
    private function parseAssignActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/assign.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $assign = $xml->assign;
        
        $files = $this->getActivityFiles((int)$xml['contextid']);
        $mainFile = null;
        $contentFiles = [];

        // Collecter TOUS les fichiers joints (introattachment)
        foreach ($files as $file) {
            if ($file['filearea'] === 'introattachment' && $file['filename'] !== '.') {
                $contentFiles[] = $file;
                if (!$mainFile) $mainFile = $file;
            }
        }

        // Fallback: chercher dans content area
        if (empty($contentFiles)) {
            foreach ($files as $file) {
                if ($file['filearea'] === 'content' && $file['filename'] !== '.') {
                    $contentFiles[] = $file;
                    if (!$mainFile) $mainFile = $file;
                }
            }
        }

        return [
            'type' => 'assign',
            'module_id' => $moduleId,
            'id' => (int)$assign['id'],
            'name' => $this->cleanText(((string)$assign->name ?: (string)$assign->n)),
            'intro' => $this->cleanHtml((string)$assign->intro),
            'main_file' => $mainFile,
            'content_files' => $contentFiles,
            'files' => $files,
        ];
    }
    
    /**
     * Parse une activité URL
     */
    private function parseUrlActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/url.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $url = $xml->url;
        
        return [
            'type' => 'url',
            'module_id' => $moduleId,
            'id' => (int)$url['id'],
            'name' => $this->cleanText(((string)$url->name ?: (string)$url->n)),
            'intro' => $this->cleanHtml((string)$url->intro),
            'external_url' => (string)$url->externalurl,
        ];
    }
    
    /**
     * Parse une activité Label
     */
    private function parseLabelActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/label.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $label = $xml->label;
        
        return [
            'type' => 'label',
            'module_id' => $moduleId,
            'id' => (int)$label['id'],
            'name' => $this->cleanText(((string)$label->name ?: (string)$label->n)),
            'intro' => $this->cleanHtml((string)$label->intro),
            'files' => $this->getActivityFiles((int)$xml['contextid']),
        ];
    }
    
    /**
     * Parse une activité Book
     */
    private function parseBookActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/book.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $book = $xml->book;
        
        $chapters = [];
        foreach ($book->chapters->chapter as $chapter) {
            $chapters[] = [
                'id' => (int)$chapter['id'],
                'pagenum' => (int)$chapter->pagenum,
                'title' => $this->cleanText((string)$chapter->title),
                'content' => $this->cleanHtml((string)$chapter->content),
                'hidden' => (int)$chapter->hidden,
            ];
        }
        
        usort($chapters, fn($a, $b) => $a['pagenum'] <=> $b['pagenum']);
        
        return [
            'type' => 'book',
            'module_id' => $moduleId,
            'id' => (int)$book['id'],
            'name' => $this->cleanText(((string)$book->name ?: (string)$book->n)),
            'intro' => $this->cleanHtml((string)$book->intro),
            'chapters' => $chapters,
            'files' => $this->getActivityFiles((int)$xml['contextid']),
        ];
    }
    
    /**
     * Parse une activité Folder
     */
    private function parseFolderActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/folder.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $folder = $xml->folder;
        
        return [
            'type' => 'folder',
            'module_id' => $moduleId,
            'id' => (int)$folder['id'],
            'name' => $this->cleanText(((string)$folder->name ?: (string)$folder->n)),
            'intro' => $this->cleanHtml((string)$folder->intro),
            'files' => $this->getActivityFiles((int)$xml['contextid']),
        ];
    }
    
    /**
     * Parse une activité Lesson
     */
    private function parseLessonActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/lesson.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $lesson = $xml->lesson;
        
        $pages = [];
        if (isset($lesson->pages)) {
            foreach ($lesson->pages->page as $page) {
                $pages[] = [
                    'id' => (int)$page['id'],
                    'title' => $this->cleanText((string)$page->title),
                    'contents' => $this->cleanHtml((string)$page->contents),
                    'qtype' => (int)$page->qtype,
                ];
            }
        }
        
        return [
            'type' => 'lesson',
            'module_id' => $moduleId,
            'id' => (int)$lesson['id'],
            'name' => $this->cleanText(((string)$lesson->name ?: (string)$lesson->n)),
            'intro' => $this->cleanHtml((string)$lesson->intro),
            'pages' => $pages,
            'files' => $this->getActivityFiles((int)$xml['contextid']),
        ];
    }
    
    /**
     * Parse une activité mapmodules (carte de progression Éléa)
     */
    private function parseMapmodulesActivity(string $path, string $moduleId): ?array {
        $xmlPath = $path . '/mapmodules.xml';
        if (!file_exists($xmlPath)) return null;
        
        $xml = simplexml_load_file($xmlPath);
        $map = $xml->mapmodules;
        
        return [
            'type' => 'mapmodules',
            'module_id' => $moduleId,
            'id' => (int)$map['id'],
            'name' => $this->cleanText(((string)$map->name ?: (string)$map->n)),
            'intro' => (string)$map->intro,
            'mapPath' => (string)$map->path,
            'iconset' => (int)$map->iconset,
            'buttonWidth' => (int)($map->buttonwidth ?: 50),
            'descriptionHeader' => (string)$map->descriptionheader,
            'descriptionFooter' => (string)$map->descriptionfooter,
            'targetsection' => (string)$map->targetsection,
            'displaymodulenames' => (int)$map->displaymodulenames,
        ];
    }
    
    /**
     * Parse une activité générique (type non supporté)
     */
    private function parseGenericActivity(string $path, string $moduleId, string $type): ?array {
        $moduleXml = $path . '/module.xml';
        if (!file_exists($moduleXml)) return null;
        
        $xml = simplexml_load_file($moduleXml);
        
        return [
            'type' => $type,
            'module_id' => $moduleId,
            'name' => 'Activité ' . $type,
            'unsupported' => true,
        ];
    }
    
    /**
     * Parse les questions du quiz
     */
    private function parseQuestions(): void {
        $xmlPath = $this->extractPath . '/questions.xml';
        if (!file_exists($xmlPath)) return;
        
        $xml = simplexml_load_file($xmlPath);
        
        foreach ($xml->question_category as $category) {
            foreach ($category->question_bank_entries->question_bank_entry as $entry) {
                $entryId = (int)$entry['id'];
                
                foreach ($entry->question_version->question_versions as $version) {
                    foreach ($version->questions->question as $q) {
                        $question = $this->parseQuestion($q);
                        if ($question) {
                            $question['bank_entry_id'] = $entryId;
                            $this->questions[$entryId] = $question;
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Parse une question individuelle
     */
    private function parseQuestion(SimpleXMLElement $q): ?array {
        $qtype = (string)$q->qtype;
        
        $base = [
            'id' => (int)$q['id'],
            'name' => $this->cleanText(((string)$q->name ?: (string)$q->n)),
            'text' => $this->cleanHtml((string)$q->questiontext),
            'qtype' => $qtype,
            'default_mark' => (float)$q->defaultmark,
            'penalty' => (float)$q->penalty,
            'general_feedback' => $this->cleanHtml((string)$q->generalfeedback),
        ];
        
        // Parse selon le type
        return match($qtype) {
            'multichoice' => $this->parseMultichoiceQuestion($q, $base),
            'truefalse' => $this->parseTruefalseQuestion($q, $base),
            'shortanswer' => $this->parseShortanswerQuestion($q, $base),
            'numerical' => $this->parseNumericalQuestion($q, $base),
            'match' => $this->parseMatchQuestion($q, $base),
            'gapselect' => $this->parseGapselectQuestion($q, $base),
            'ddwtos' => $this->parseDdwtosQuestion($q, $base),
            'ddimageortext' => $this->parseDdimageortextQuestion($q, $base),
            'essay' => $this->parseEssayQuestion($q, $base),
            'description' => $base,
            'multianswer' => $this->parseMultianswerQuestion($q, $base),
            'ordering' => $this->parseOrderingQuestion($q, $base),
            default => $base,
        };
    }
    
    /**
     * Parse une question à choix multiple
     */
    private function parseMultichoiceQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_multichoice_question;
        $multichoice = $plugin->multichoice;
        
        $answers = [];
        foreach ($plugin->answers->answer as $a) {
            $answers[] = [
                'id' => (int)$a['id'],
                'text' => $this->cleanHtml((string)$a->answertext),
                'fraction' => (float)$a->fraction,
                'feedback' => $this->cleanHtml((string)$a->feedback),
            ];
        }
        
        return array_merge($base, [
            'answers' => $answers,
            'single' => (int)$multichoice->single === 1,
            'shuffle_answers' => (int)$multichoice->shuffleanswers === 1,
            'answer_numbering' => (string)$multichoice->answernumbering,
            'correct_feedback' => $this->cleanHtml((string)$multichoice->correctfeedback),
            'partially_correct_feedback' => $this->cleanHtml((string)$multichoice->partiallycorrectfeedback),
            'incorrect_feedback' => $this->cleanHtml((string)$multichoice->incorrectfeedback),
        ]);
    }
    
    /**
     * Parse une question vrai/faux
     */
    private function parseTruefalseQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_truefalse_question;
        
        $answers = [];
        foreach ($plugin->answers->answer as $a) {
            $answers[] = [
                'id' => (int)$a['id'],
                'text' => $this->cleanText((string)$a->answertext),
                'fraction' => (float)$a->fraction,
                'feedback' => $this->cleanHtml((string)$a->feedback),
            ];
        }
        
        $trueAnswer = (int)$plugin->truefalse->trueanswer;
        
        return array_merge($base, [
            'answers' => $answers,
            'true_answer_id' => $trueAnswer,
        ]);
    }
    
    /**
     * Parse une question réponse courte
     */
    private function parseShortanswerQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_shortanswer_question;
        
        $answers = [];
        foreach ($plugin->answers->answer as $a) {
            $answers[] = [
                'id' => (int)$a['id'],
                'text' => (string)$a->answertext,
                'fraction' => (float)$a->fraction,
                'feedback' => $this->cleanHtml((string)$a->feedback),
            ];
        }
        
        return array_merge($base, [
            'answers' => $answers,
            'use_case' => (int)$plugin->shortanswer->usecase === 1,
        ]);
    }
    
    /**
     * Parse une question numérique
     */
    private function parseNumericalQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_numerical_question;
        
        $answers = [];
        foreach ($plugin->answers->answer as $a) {
            $answers[] = [
                'id' => (int)$a['id'],
                'text' => (string)$a->answertext,
                'fraction' => (float)$a->fraction,
                'feedback' => $this->cleanHtml((string)$a->feedback),
            ];
        }
        
        $tolerances = [];
        if (isset($plugin->numerical_options)) {
            foreach ($plugin->numerical_options->numerical_option as $opt) {
                $tolerances[(int)$opt->answer] = (float)$opt->tolerance;
            }
        }
        
        return array_merge($base, [
            'answers' => $answers,
            'tolerances' => $tolerances,
        ]);
    }
    
    /**
     * Parse une question d'appariement
     */
    private function parseMatchQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_match_question;
        
        $subquestions = [];
        foreach ($plugin->matches->match as $m) {
            $subquestions[] = [
                'id' => (int)$m['id'],
                'question' => $this->cleanHtml((string)$m->questiontext),
                'answer' => $this->cleanText((string)$m->answertext),
            ];
        }
        
        return array_merge($base, [
            'subquestions' => $subquestions,
            'shuffle_answers' => (int)$plugin->matchoptions->shuffleanswers === 1,
        ]);
    }
    
    /**
     * Parse une question à trous (sélection)
     */
    private function parseGapselectQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_gapselect_question;
        
        $choices = [];
        foreach ($plugin->answers->answer as $a) {
            $group = (int)$a->feedback; // Le groupe est stocké dans feedback pour gapselect
            $choices[] = [
                'id' => (int)$a['id'],
                'text' => $this->cleanText((string)$a->answertext),
                'group' => $group ?: 1,
            ];
        }
        
        return array_merge($base, [
            'choices' => $choices,
            'shuffle_answers' => isset($plugin->gapselect) ? (int)$plugin->gapselect->shuffleanswers === 1 : true,
        ]);
    }
    
    /**
     * Parse une question glisser-déposer sur texte
     */
    private function parseDdwtosQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_ddwtos_question;
        
        $choices = [];
        if (isset($plugin->answers)) {
            foreach ($plugin->answers->answer as $a) {
                $choices[] = [
                    'id' => (int)$a['id'],
                    'text' => $this->cleanText((string)$a->answertext),
                    'group' => (int)$a->feedback ?: 1,
                ];
            }
        }
        
        return array_merge($base, [
            'choices' => $choices,
        ]);
    }
    
    /**
     * Parse une question rédaction
     */
    private function parseEssayQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_essay_question;
        
        return array_merge($base, [
            'response_format' => isset($plugin->essay) ? (string)$plugin->essay->responseformat : 'editor',
            'response_required' => isset($plugin->essay) ? (int)$plugin->essay->responserequired : 1,
        ]);
    }
    
    /**
     * Parse une question Glisser-Déposer sur image (ddimageortext)
     */
    private function parseDdimageortextQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_ddimageortext_question;
        
        $shuffleanswers = 1;
        if (isset($plugin->ddimageortext)) {
            $shuffleanswers = (int)$plugin->ddimageortext->shuffleanswers;
        }
        
        $drags = [];
        if (isset($plugin->drags)) {
            foreach ($plugin->drags->drag as $d) {
                $drags[] = [
                    'drag_id' => (int)$d['id'],
                    'no' => (int)$d->no,
                    'label' => (string)$d->label,
                    'group' => (int)$d->draggroup,
                    'infinite' => (int)$d->infinite === 1,
                ];
            }
        }
        
        $drops = [];
        if (isset($plugin->drops)) {
            foreach ($plugin->drops->drop as $d) {
                $drops[] = [
                    'no' => (int)$d->no,
                    'x' => (int)$d->xleft,
                    'y' => (int)$d->ytop,
                    'choice' => (int)$d->choice,
                    'label' => (string)$d->label,
                ];
            }
        }
        
        return array_merge($base, [
            'shuffleanswers' => $shuffleanswers,
            'drags' => $drags,
            'drops' => $drops,
        ]);
    }
    
    /**
     * Parse une question Cloze (multianswer)
     */
    private function parseMultianswerQuestion(SimpleXMLElement $q, array $base): array {
        // Les questions cloze sont plus complexes, on garde le texte avec les marqueurs
        return $base;
    }
    
    /**
     * Parse une question d'ordonnancement
     */
    private function parseOrderingQuestion(SimpleXMLElement $q, array $base): array {
        $plugin = $q->plugin_qtype_ordering_question;
        
        $items = [];
        if (isset($plugin->answers)) {
            foreach ($plugin->answers->answer as $a) {
                $items[] = [
                    'id' => (int)$a['id'],
                    'text' => $this->cleanText((string)$a->answertext),
                    'fraction' => (float)$a->fraction,
                ];
            }
        }
        
        // Tri par fraction (ordre correct)
        usort($items, fn($a, $b) => $b['fraction'] <=> $a['fraction']);
        
        return array_merge($base, [
            'items' => $items,
        ]);
    }
    
    /**
     * Récupère les fichiers associés à un contexte
     */
    private function getActivityFiles(int $contextId): array {
        $files = [];
        foreach ($this->files as $file) {
            if ($file['contextid'] === $contextId) {
                $files[] = $file;
            }
        }
        return $files;
    }
    
    /**
     * Construit la structure finale du cours
     */
    private function buildCourseStructure(): array {
        // Associe les activités aux sections
        foreach ($this->sections as &$section) {
            foreach ($section['sequence'] as $moduleId) {
                if (isset($this->activities[$moduleId])) {
                    $activity = $this->activities[$moduleId];
                    
                    // Si c'est un quiz, ajoute les questions
                    if ($activity['type'] === 'quiz' && isset($activity['question_slots'])) {
                        $questions = [];
                        foreach ($activity['question_slots'] as $slot) {
                            $entryId = $slot['question_bank_entry_id'];
                            if (isset($this->questions[$entryId])) {
                                $q = $this->questions[$entryId];
                                $q['maxmark'] = $slot['maxmark'];
                                $q['slot'] = $slot['slot'];
                                $questions[] = $q;
                            }
                        }
                        $activity['questions'] = $questions;
                    }
                    
                    $section['activities'][] = $activity;
                }
            }
        }
        
        return [
            'course' => $this->courseData,
            'sections' => array_values($this->sections),
            'files' => $this->files,
            'extract_path' => $this->extractPath,
        ];
    }
    
    /**
     * Nettoie le HTML
     */
    private function cleanHtml(?string $html): string {
        if (empty($html) || $html === '$@NULL@$') return '';
        return html_entity_decode(trim($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Nettoie le texte
     */
    private function cleanText(?string $text): string {
        if (empty($text) || $text === '$@NULL@$') return '';
        return html_entity_decode(trim(strip_tags($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Récupère le chemin d'un fichier par son hash
     */
    public function getFilePath(string $hash): ?string {
        $prefix = substr($hash, 0, 2);
        $path = $this->extractPath . '/files/' . $prefix . '/' . $hash;
        return file_exists($path) ? $path : null;
    }
    
    /**
     * Copie les fichiers nécessaires vers un dossier de destination
     */
    public function copyFilesToDestination(string $destPath): void {
        if (!is_dir($destPath)) {
            mkdir($destPath, 0755, true);
        }
        
        $filesDir = $destPath . '/files';
        if (!is_dir($filesDir)) {
            mkdir($filesDir, 0755, true);
        }
        
        foreach ($this->files as $file) {
            if ($file['filename'] === '.') continue;
            
            $hash = $file['hash'];
            $srcPath = $this->getFilePath($hash);
            if ($srcPath) {
                // Crée le sous-dossier si nécessaire
                $subDir = $filesDir . '/' . substr($hash, 0, 2);
                if (!is_dir($subDir)) {
                    mkdir($subDir, 0755, true);
                }
                copy($srcPath, $subDir . '/' . $hash);
            }
        }
    }
}
