<?php
/**
 * EleaMbzExporter - Export MBZ compatible avec Éléa/Moodle
 * 
 * Génère un fichier .mbz au format tar.gz avec tous les fichiers XML requis
 * pour une importation réussie dans Éléa.
 */

class EleaMbzExporter {
    private $data;
    private $exportDir;
    private $filesDir;
    private $courseId;
    private $contextId;
    private $backupDate;
    private $activityId = 1;
    private $fileId = 1;
    private $gradeItemId = 1;
    private $gradeCategoryId = 1;
    private $filesManifest = [];
    private $archiveIndex = [];
    private $baseModuleId;
    private $baseSectionId;
    private $baseHvpId;
    private $questionsBank = [];
    private $questionCategoryIds = [];
    private $questionFileIds = [];
    private $quizActivityDirs = [];
    private $questionBankEntryId = 189700;
    private $questionVersionId = 203500;
    private $questionId = 217100;
    private $answerId = 579800;
    private $questionInstanceId = 27670;
    private $questionRefId = 23130;
    private $gapSelectId = 3270;
    private $multichoicePluginId = 112500;
    private $trueFalsePluginId = 8860;
    private $shortAnswerPluginId = 23220;
    private $ddiDragId = 48700;   // Compteur global pour les drag IDs (évite les collisions entre questions)
    private $ddiDropId = 47900;   // Compteur global pour les drop IDs
    private $ddiPluginId = 6500;  // Compteur global pour le plugin ddimageortext ID
    private $_lastDdiDragIds = []; // Drag IDs assignés par le dernier appel à buildDdimageortextQuestionXml
    private $editorSessionId = '';
    private $_driveManager = null; // Singleton DriveManager pour l'export
    private $exportLogs = []; // Log messages for browser console
    private $droppedActivities = []; // Activités non exportables écartées (voir dropUnsupportedActivities)
    private $courseImageFileIds = []; // IDs files.xml de la vignette du cours (image + entrée dossier)
    private $htmlFilesByArea = [];    // zone de fichiers => [contenthash => nom] (voir inlineHtmlFiles)
    private $htmlDirEntryDone = [];   // zones ayant déjà leur entrée de dossier « . »
    private $unresolvedFiles = []; // Fichiers référencés introuvables partout (nom => true) — signalés au prof
    private $progressCb = null;      // Callback d'avancement (barre de progression du navigateur)
    
    public function __construct($data, string $sessionId = '') {
        $this->data = $data;
        $this->editorSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        // Utiliser des IDs réalistes comme Éléa (5 chiffres)
        $this->courseId = rand(10000, 99999);
        $this->contextId = $this->courseId + 100000;
        $this->backupDate = time();
        // IDs de base pour les activités et sections
        $this->baseModuleId = rand(70000, 79999);
        $this->baseSectionId = rand(15000, 19999);
        $this->baseHvpId = rand(5000, 9999);
    }
    
    /**
     * CORRECTION: Recherche un fichier dans plusieurs emplacements possibles
     * Résout le problème des chemins relatifs vs absolus
     */
    public function getFilesManifest(): array {
        return $this->filesManifest;
    }
    
    /**
     * Reçoit une fonction (percent, label) appelée au fil de l'export, pour alimenter
     * la barre de progression affichée dans l'éditeur.
     */
    public function setProgressCallback(callable $cb): void {
        $this->progressCb = $cb;
    }

    private function progress(float $percent, string $label): void {
        if ($this->progressCb) {
            ($this->progressCb)($percent, $label);
        }
    }

    public function getExportLogs(): array {
        return $this->exportLogs;
    }
    
    private function logExport(string $msg) {
        $this->exportLogs[] = $msg;
    }
    
    public function findFileMultiPath($filename) {
        $possiblePaths = [];
        
        // Nettoyer le nom de fichier (enlever tout chemin potentiel)
        $cleanFilename = basename($filename);
        
        // 0. Chemin prioritaire : dossier session (si session_id défini)
        if ($this->editorSessionId) {
            if (defined('CACHE_DIR')) {
                $possiblePaths[] = CACHE_DIR . '/editor_uploads/' . $this->editorSessionId . '/' . $cleanFilename;
            }
            if (defined('ROOT_PATH')) {
                $possiblePaths[] = ROOT_PATH . '/cache/editor_uploads/' . $this->editorSessionId . '/' . $cleanFilename;
            }
        }
        
        // 1. Chemin standard via CACHE_DIR (ancien chemin plat, rétrocompat)
        if (defined('CACHE_DIR')) {
            $possiblePaths[] = CACHE_DIR . '/editor_uploads/' . $cleanFilename;
        }
        
        // 2. Chemin relatif à ROOT_PATH
        if (defined('ROOT_PATH')) {
            $possiblePaths[] = ROOT_PATH . '/cache/editor_uploads/' . $cleanFilename;
        }
        
        // 3. Chemin relatif au script courant (includes/)
        $possiblePaths[] = dirname(__DIR__) . '/cache/editor_uploads/' . $cleanFilename;
        
        // 4. Chemin basé sur __DIR__
        $possiblePaths[] = __DIR__ . '/../cache/editor_uploads/' . $cleanFilename;
        
        // 5. Chemin basé sur __FILE__ (le plus fiable)
        $possiblePaths[] = dirname(dirname(__FILE__)) . '/cache/editor_uploads/' . $cleanFilename;
        
        // 6. Chemin relatif au répertoire de travail courant
        $possiblePaths[] = getcwd() . '/cache/editor_uploads/' . $cleanFilename;
        
        // 7. Si c'est un chemin absolu avec cache/editor_uploads dedans, extraire et réessayer
        if (preg_match('#/cache/editor_uploads/([^/]+)$#', $filename, $m)) {
            $extractedFilename = $m[1];
            if ($extractedFilename !== $cleanFilename) {
                return $this->findFileMultiPath($extractedFilename);
            }
        }
        
        // 8. Chemin absolu si c'est déjà un chemin complet vers un fichier existant
        if (strpos($filename, '/') === 0 && file_exists($filename)) {
            return $filename;
        }
        
        // Tester chaque chemin
        foreach ($possiblePaths as $path) {
            // Normaliser le chemin
            $normalizedPath = str_replace('//', '/', $path);
            
            // Essayer avec realpath
            $realPath = @realpath($normalizedPath);
            if ($realPath && file_exists($realPath) && is_file($realPath)) {
                return $realPath;
            }
            // Essayer sans realpath (liens symboliques, etc.)
            if (@file_exists($normalizedPath) && @is_file($normalizedPath)) {
                return $normalizedPath;
            }
        }
        
        // Debug: logger les chemins essayés
        error_log("EleaMbzExporter: findFileMultiPath - Fichier non trouvé localement: $filename");
        
        // 9. Fallback Drive : si session_id défini, chercher dans le mapping Drive
        if ($this->editorSessionId) {
            require_once __DIR__ . '/EditorDriveSync.php';
            $resolved = EditorDriveSync::resolveFile($this->editorSessionId, $cleanFilename);
            if ($resolved && file_exists($resolved)) {
                return $resolved;
            }
        }
        
        // 10. DERNIER RECOURS : scan de tous les sous-dossiers de editor_uploads
        $cacheDirs = [];
        if (defined('CACHE_DIR')) $cacheDirs[] = CACHE_DIR . '/editor_uploads';
        if (defined('ROOT_PATH')) $cacheDirs[] = ROOT_PATH . '/cache/editor_uploads';
        $cacheDirs[] = dirname(__DIR__) . '/cache/editor_uploads';
        foreach (array_unique($cacheDirs) as $uploadsDir) {
            if (!is_dir($uploadsDir)) continue;
            // Dossier plat
            $flat = $uploadsDir . '/' . $cleanFilename;
            if (file_exists($flat) && is_file($flat)) return $flat;
            // Sous-dossiers de session
            $dirs = @scandir($uploadsDir);
            if ($dirs) {
                foreach ($dirs as $d) {
                    if ($d === '.' || $d === '..' || $d === 'auto') continue;
                    $candidate = $uploadsDir . '/' . $d . '/' . $cleanFilename;
                    if (file_exists($candidate) && is_file($candidate)) return $candidate;
                }
            }
        }

        // 11. Le nom peut être mappé sur Drive par une AUTRE session que celle de
        // l'export (brouillon repris dans une nouvelle session, cours ré-importé) :
        // chercher dans les metadata de toutes les sessions actives.
        require_once __DIR__ . '/EditorDriveSync.php';
        $anyId = \EditorDriveSync::findDriveIdAnySession($cleanFilename);
        if ($anyId) {
            $resolved = \EditorDriveSync::resolveFileByDriveId($anyId, $cleanFilename);
            if ($resolved && file_exists($resolved)) {
                $this->logExport("[findFileMultiPath] $cleanFilename résolu via le mapping d'une autre session ($anyId)");
                return $resolved;
            }
        }

        // Introuvable PARTOUT : mémorisé pour être signalé au professeur à la fin de
        // l'export — sans ça, l'URL brute restait incrustée dans le .mbz et l'activité
        // perdait ce média en silence (constaté le 07/08/2026 : 7 médias perdus).
        $this->unresolvedFiles[$cleanFilename] = true;
        error_log("EleaMbzExporter: fichier référencé INTROUVABLE partout : $cleanFilename — le .mbz sera incomplet");

        return null;
    }

    /**
     * Fichiers référencés par le cours mais introuvables partout au moment de l'export
     * (le .mbz est incomplet pour ces médias). À afficher au professeur.
     */
    public function getUnresolvedFiles(): array {
        return array_keys($this->unresolvedFiles);
    }
    
    
    public function export() {
        $progressLog = TMP_PATH . '/.export_progress.log';
        $logP = function($msg) use ($progressLog) {
            @file_put_contents($progressLog, date('H:i:s') . ' ' . round(memory_get_usage(true)/(1024*1024),1) . 'Mo ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        };
        
        $logP('export() start');
        $this->progress(2, 'Préparation de l\'export…');

        // Écarter les activités qu'on ne sait pas exporter, AVANT toute génération : les
        // séquences de sections et moodle_backup.xml sont numérotées sur cette même liste.
        $this->dropUnsupportedActivities();

        // Créer le dossier d'export
        $exportId = 'elea_' . time() . '_' . bin2hex(random_bytes(4));
        $this->exportDir = CACHE_DIR . '/exports/' . $exportId;
        $this->filesDir = $this->exportDir . '/files';
        
        if (!is_dir(CACHE_DIR . '/exports')) {
            mkdir(CACHE_DIR . '/exports', 0777, true);
        }
        mkdir($this->exportDir, 0777, true);
        mkdir($this->filesDir, 0777, true);
        
        // Pré-télécharger les fichiers Drive en parallèle (curl_multi)
        $this->progress(4, 'Récupération des fichiers du Drive…');
        $this->prefetchDriveFiles();
        
        // Log prefetch result
        $dlDir = TMP_PATH . '/drive_downloads';
        $dlCount = is_dir($dlDir) ? count(glob($dlDir . '/*') ?: []) : 0;
        $dlSize = 0;
        if (is_dir($dlDir)) {
            foreach (glob($dlDir . '/*') ?: [] as $f) $dlSize += filesize($f);
        }
        error_log("EleaMbzExporter: prefetch cache: $dlCount fichiers, " . round($dlSize/(1024*1024),1) . " Mo");
        $logP("prefetch done: $dlCount fichiers, " . round($dlSize/(1024*1024),1) . " Mo");
        
        // Générer la structure complète Moodle/Éléa
        $logP('generateCompleteBackup start');
        $this->progress(12, 'Construction du cours…');
        $this->generateCompleteBackup();
        $logP('generateCompleteBackup done, manifest=' . count($this->filesManifest));

        // Créer le fichier .ARCHIVE_INDEX
        $this->progress(90, 'Index de l\'archive…');
        $this->generateArchiveIndex();

        // Créer l'archive tar.gz
        $mbzPath = CACHE_DIR . '/exports/' . $this->sanitizeFilename($this->data['name'] ?? 'cours') . '-' . date('Ymd-His') . '.mbz';
        $logP('creating tar.gz');
        $this->progress(92, 'Compression de l\'archive…');
        $this->createTarGz($mbzPath);
        $logP('tar.gz done: ' . (file_exists($mbzPath) ? round(filesize($mbzPath)/1024) . 'Ko' : 'MISSING'));

        // Nettoyer le dossier temporaire
        $this->progress(99, 'Finalisation…');
        $this->deleteDirectory($this->exportDir);

        return $mbzPath;
    }
    
    /**
     * Retire les activités dont le contenu n'est pas un arbre H5P (ex. les pages Moodle,
     * dont le contenu est du HTML). Sans ce filtre elles arrivaient dans generateH5pActivity
     * et faisaient planter la totalité de l'export.
     * Retourne la liste des activités écartées.
     */
    private function dropUnsupportedActivities(): array {
        $dropped = [];
        // Modules Moodle que l'exporteur sait écrire tels quels
        $handled = ['mapmodules' => 1, 'assign' => 1, 'resource' => 1, 'quiz' => 1,
                    'label' => 1, 'page' => 1];
        // Types qui SONT du H5P : eux seuls ont le droit de partir en activité hvp.
        // Sans ce filtre, un module Moodle inconnu (qbank, url, forum…) sortait en
        // « H5P.qbank », « H5P.url »… : une bibliothèque qui n'existe pas, donc une
        // activité vide et cassée dans Éléa (constaté sur l'étiquette d'accueil).
        $h5p = ['h5pactivity' => 1, 'hvp' => 1, '' => 1];

        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $kept = [];
            foreach ($section['activities'] ?? [] as $activity) {
                $type = $activity['type'] ?? 'h5pactivity';
                if (!isset($handled[$type]) && !isset($h5p[$type])) {
                    $name = $activity['name'] ?? '?';
                    // La banque de questions de Moodle 5 n'est pas du contenu de prof :
                    // elle traînait dans les anciens brouillons, on la retire sans le
                    // signaler (Éléa recrée la sienne à l'import).
                    if ($type === 'qbank') continue;
                    $dropped[] = ['type' => $type, 'name' => $name];
                    $this->logExport("Activité écartée de l'export (type « $type » non pris en charge) : $name");
                    error_log("EleaMbzExporter: activité écartée (type $type non pris en charge) : $name");
                    continue;
                }
                $kept[] = $activity;
            }
            $this->data['sections'][$sIdx]['activities'] = $kept;
        }
        $this->droppedActivities = $dropped;
        return $dropped;
    }

    /**
     * Activités écartées par dropUnsupportedActivities() lors du dernier export.
     */
    public function getDroppedActivities(): array {
        return $this->droppedActivities;
    }

    /**
     * Pré-télécharge tous les fichiers Drive référencés en parallèle (curl_multi).
     * Remplit le cache drive_downloads/ pour que processFilePath les trouve instantanément.
     */
    private function prefetchDriveFiles() {
        $json = json_encode($this->data, JSON_UNESCAPED_SLASHES);
        
        // Collecter tous les driveIds depuis les URLs lh3
        $driveIds = [];
        if (preg_match_all('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $json, $m)) {
            foreach ($m[1] as $id) $driveIds[$id] = true;
        }
        if (preg_match_all('#drive\.google\.com/uc\?.*?id=([a-zA-Z0-9_-]+)#', $json, $m)) {
            foreach ($m[1] as $id) $driveIds[$id] = true;
        }
        
        // Collecter les driveIds depuis le mapping serve_upload → Drive
        // Note: la flush loop JS est PAUSÉE pendant l'export,
        // donc les fichiers pas encore flushés sont toujours en local
        // et seront trouvés par findFileMultiPath directement.
        
        // Collecter les noms de fichiers upload et les sessions référencées dans le JSON
        $jsonUploadFiles = [];
        if (preg_match_all('/(?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w{2,5}/', $json, $m)) {
            $jsonUploadFiles = array_unique($m[0]);
        }
        $jsonSessions = [];
        if (preg_match_all('/session=([a-zA-Z0-9_-]+)/', $json, $m)) {
            $jsonSessions = array_unique($m[1]);
        }
        
        // Chercher dans TOUTES les sessions référencées + la session de l'export
        $sessionsToCheck = array_unique(array_filter(array_merge(
            [$this->editorSessionId],
            $jsonSessions
        )));
        
        $this->logExport("[prefetch] Sessions: " . implode(', ', $sessionsToCheck) . " | Upload files in JSON: " . count($jsonUploadFiles));
        
        if (!empty($sessionsToCheck) && !empty($jsonUploadFiles)) {
            require_once __DIR__ . '/EditorDriveSync.php';
            foreach ($sessionsToCheck as $sid) {
                $meta = \EditorDriveSync::getMeta($sid);
                $mapping = $meta['file_mapping'] ?? [];
                $pending = $meta['pending_files'] ?? [];
                $this->logExport("[prefetch] Session $sid: mapping=" . count($mapping) . " pending=" . count($pending));
                $found = 0;
                foreach ($jsonUploadFiles as $fn) {
                    if (isset($mapping[$fn])) {
                        $driveIds[$mapping[$fn]] = true;
                        $found++;
                    }
                }
                if ($found > 0) $this->logExport("[prefetch] → $found fichiers trouvés dans mapping de $sid");
            }
        }
        
        if (empty($driveIds)) return;
        
        $tmpDir = TMP_PATH . '/drive_downloads';
        
        // Nettoyer le cache drive_downloads pour repartir à zéro
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir . '/*') as $f) {
                if (is_file($f)) @unlink($f);
            }
        } else {
            @mkdir($tmpDir, 0755, true);
        }
        
        // Télécharger tous les fichiers Drive référencés.
        // AVEC LE JETON OAUTH (api Drive alt=media), pas via lh3.googleusercontent.com :
        // cette URL publique ne sert que des IMAGES et seulement si le fichier est public.
        // Nos fichiers sont privés → elle renvoyait une page HTML ou une erreur pour une
        // grande partie du lot (constaté le 07/08/2026 : 184 fichiers récupérés sur 288),
        // et tous les médias manquants sortaient absents du .mbz.
        $accessToken = null;
        try {
            require_once ROOT_PATH . '/DriveManager.php';
            if (!$this->_driveManager) {
                $this->_driveManager = new \DriveManager(
                    DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php'
                );
            }
            $accessToken = $this->_driveManager->getAccessTokenString();
        } catch (\Throwable $e) {
            error_log('EleaMbzExporter: jeton Drive indisponible pour le prefetch: ' . $e->getMessage());
        }

        $toDownload = [];
        foreach (array_keys($driveIds) as $driveId) {
            $toDownload[$driveId] = $accessToken
                ? 'https://www.googleapis.com/drive/v3/files/' . $driveId . '?alt=media&supportsAllDrives=true'
                : 'https://lh3.googleusercontent.com/d/' . $driveId;
        }
        $enteteAuth = $accessToken ? ['Authorization: Bearer ' . $accessToken] : [];

        if (empty($toDownload)) return;
        
        error_log("EleaMbzExporter: prefetch " . count($toDownload) . " fichiers Drive (curl_multi)");
        
        // Progress log
        $progressLog = TMP_PATH . '/.export_progress.log';
        @file_put_contents($progressLog, date('H:i:s') . " prefetch: " . count($toDownload) . " fichiers à télécharger\n", FILE_APPEND | LOCK_EX);
        
        // Téléchargement parallèle par lots de 20
        $batchSize = 20;
        $batches = array_chunk($toDownload, $batchSize, true);
        $downloaded = 0;
        $batchNum = 0;
        
        foreach ($batches as $batch) {
            $batchNum++;
            @file_put_contents($progressLog, date('H:i:s') . " batch $batchNum/" . count($batches) . " (" . count($batch) . " fichiers)\n", FILE_APPEND | LOCK_EX);
            $mh = curl_multi_init();
            $handles = [];
            
            foreach ($batch as $driveId => $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0',
                    CURLOPT_HTTPHEADER => $enteteAuth,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$driveId] = $ch;
            }
            
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 1);
                }
            } while ($active && $status === CURLM_OK);
            
            foreach ($handles as $driveId => $ch) {
                $data = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                
                // Rejeter les pages HTML d'erreur Google (fichier privé → lh3 renvoie parfois
                // du HTML en 200) : les mettre en cache ferait embarquer du HTML à la place
                // du média dans le .mbz. Le téléchargement OAuth par fichier prendra le relais.
                $debut = ltrim(substr($data ?: '', 0, 64));
                $estHtml = (stripos($debut, '<!doctype') === 0 || stripos($debut, '<html') === 0);
                if ($data && strlen($data) > 100 && $httpCode >= 200 && $httpCode < 400 && !$estHtml) {
                    $cachePath = $tmpDir . '/' . $driveId . '_prefetch.bin';
                    file_put_contents($cachePath, $data);
                    $downloaded++;
                }
            }
            
            curl_multi_close($mh);
            @file_put_contents($progressLog, date('H:i:s') . " batch $batchNum done, total downloaded: $downloaded\n", FILE_APPEND | LOCK_EX);
        }
        
        // Rattrapage : tout ce que le lot parallèle n'a pas ramené est retenté un par un
        // en OAuth. C'est lent mais ça ne concerne que les échecs, et un média manquant
        // ici = un média absent du .mbz livré au professeur.
        $rattrapes = 0; $echecs = [];
        foreach (array_keys($toDownload) as $driveId) {
            if (file_exists($tmpDir . '/' . $driveId . '_prefetch.bin')) continue;
            $contenu = null;
            try {
                if ($this->_driveManager) $contenu = $this->_driveManager->getFileContentById($driveId);
            } catch (\Throwable $e) {
                $contenu = null;
            }
            if ($contenu !== null && strlen($contenu) > 100) {
                file_put_contents($tmpDir . '/' . $driveId . '_prefetch.bin', $contenu);
                $downloaded++; $rattrapes++;
            } else {
                $echecs[] = $driveId;
            }
        }

        error_log("EleaMbzExporter: prefetch terminé, $downloaded/" . count($toDownload) . " fichiers");
        $this->logExport("[prefetch] Downloaded $downloaded/" . count($toDownload) . " fichiers Drive"
            . ($rattrapes ? " (dont $rattrapes rattrapés en OAuth)" : '')
            . ($echecs ? " — ÉCHECS: " . implode(', ', array_slice($echecs, 0, 10)) : ''));
        if ($echecs) {
            error_log("EleaMbzExporter: " . count($echecs) . " fichiers Drive NON téléchargés : " . implode(', ', array_slice($echecs, 0, 20)));
        }
    }
    
    private function generateCompleteBackup() {
        // 1. Fichiers racine
        $this->generateMoodleBackupXml();
        $this->generateBadgesXml();
        $this->generateCompletionXml();
        $this->generateGradeHistoryXml();
        $this->generateGradebookXml();
        $this->generateGroupsXml();
        $this->generateOutcomesXml();
        $this->generateRolesXml();
        $this->generateScalesXml();

        // 1b. Vignette du cours — AVANT course/ : ses IDs de fichiers doivent figurer
        // dans le fileref de course/inforef.xml, écrit par generateCourseFolder().
        $this->addCourseImageFiles();

        // 2. Dossier course/
        $this->generateCourseFolder();
        
        // 3. Dossier sections/
        $this->generateSections();
        
        // 4. Dossier activities/ (collecte aussi les questions pour le quiz)
        $this->generateActivities();
        
        // 5. questions.xml (après les activités pour avoir toutes les questions)
        $this->progress(82, 'Banque de questions…');
        $this->generateQuestionsXml();

        // 5b. Mettre à jour les inforef avec les question_categoryref
        $this->updateInforefsWithQuestionCategories();

        // 5. files.xml (après les activités pour avoir tous les fichiers)
        $this->progress(86, 'Inventaire des fichiers…');
        $this->generateFilesXml();

        // 6. Log de backup
        $this->generateBackupLog();
    }
    
    // ==================== FICHIERS RACINE ====================
    
    private function generateMoodleBackupXml() {
        $sections = $this->data['sections'] ?? [];
        $backupId = bin2hex(random_bytes(16));
        
        // Calculer les activités
        $activitiesXml = '';
        $activitySettings = '';
        $actId = 1;
        foreach ($sections as $sIdx => $section) {
            $sectionId = ($sIdx + 1) * 1000;
            foreach ($section['activities'] ?? [] as $activity) {
                $activityType = $activity['type'] ?? 'h5pactivity';
                
                // Déterminer le module réel (QuestionSet nouveau format → quiz)
                $isEvalQuiz = ($activityType === 'h5pactivity' 
                    && ($activity['h5pType'] ?? '') === 'QuestionSet'
                    && $this->isNewFormatQuestionSet($activity));
                
                if ($activityType === 'mapmodules') {
                    $moduleName = 'mapmodules';
                    $dirPrefix = 'mapmodules';
                } elseif ($activityType === 'assign') {
                    $moduleName = 'assign';
                    $dirPrefix = 'assign';
                } elseif ($activityType === 'resource') {
                    $moduleName = 'resource';
                    $dirPrefix = 'resource';
                } elseif ($activityType === 'label') {
                    $moduleName = 'label';
                    $dirPrefix = 'label';
                } elseif ($activityType === 'page') {
                    $moduleName = 'page';
                    $dirPrefix = 'page';
                } elseif ($activityType === 'quiz' || $isEvalQuiz) {
                    $moduleName = 'quiz';
                    $dirPrefix = 'quiz';
                } else {
                    $moduleName = 'hvp';
                    $dirPrefix = 'hvp';
                }
                
                $activitiesXml .= "
        <activity>
          <moduleid>{$actId}</moduleid>
          <sectionid>{$sectionId}</sectionid>
          <modulename>{$moduleName}</modulename>
          <title>" . $this->xmlEncode($activity['name'] ?? 'Activité') . "</title>
          <directory>activities/{$dirPrefix}_{$actId}</directory>
          <insubsection></insubsection>
        </activity>";
                
                $activitySettings .= "
      <setting>
        <level>activity</level>
        <activity>{$dirPrefix}_{$actId}</activity>
        <name>{$dirPrefix}_{$actId}_included</name>
        <value>1</value>
      </setting>
      <setting>
        <level>activity</level>
        <activity>{$dirPrefix}_{$actId}</activity>
        <name>{$dirPrefix}_{$actId}_userinfo</name>
        <value>0</value>
      </setting>";
                $actId++;
            }
        }
        
        // Sections XML
        $sectionsXml = '';
        $sectionSettings = '';
        foreach ($sections as $sIdx => $section) {
            $sectionId = ($sIdx + 1) * 1000;
            $sectionTitle = $this->xmlEncode($section['name'] ?? 'Section ' . $sIdx);
            $sectionsXml .= "
        <section>
          <sectionid>{$sectionId}</sectionid>
          <title>{$sectionTitle}</title>
          <directory>sections/section_{$sectionId}</directory>
          <parentcmid></parentcmid>
          <modname></modname>
        </section>";
            
            $sectionSettings .= "
      <setting>
        <level>section</level>
        <section>section_{$sectionId}</section>
        <name>section_{$sectionId}_included</name>
        <value>1</value>
      </setting>
      <setting>
        <level>section</level>
        <section>section_{$sectionId}</section>
        <name>section_{$sectionId}_userinfo</name>
        <value>0</value>
      </setting>";
        }
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<moodle_backup>
  <information>
    <name>backup.mbz</name>
    <moodle_version>2024100708</moodle_version>
    <moodle_release>4.5.8 (Build: 20251208)</moodle_release>
    <backup_version>2024100700</backup_version>
    <backup_release>4.5</backup_release>
    <backup_date>' . $this->backupDate . '</backup_date>
    <mnet_remoteusers>0</mnet_remoteusers>
    <include_files>1</include_files>
    <include_file_references_to_external_content>0</include_file_references_to_external_content>
    <original_wwwroot>https://elea.apps.education.fr</original_wwwroot>
    <original_site_identifier_hash>' . md5('elea-secours-' . $this->backupDate) . '</original_site_identifier_hash>
    <original_course_id>' . $this->courseId . '</original_course_id>
    <original_course_format>topics</original_course_format>
    <original_course_fullname>' . $this->xmlEncode($this->data['name'] ?? 'Cours') . '</original_course_fullname>
    <original_course_shortname>' . $this->xmlEncode($this->data['shortname'] ?? 'cours') . '</original_course_shortname>
    <original_course_startdate>' . $this->backupDate . '</original_course_startdate>
    <original_course_enddate>0</original_course_enddate>
    <original_course_contextid>' . $this->contextId . '</original_course_contextid>
    <original_system_contextid>1</original_system_contextid>
    <details>
      <detail backup_id="' . $backupId . '">
        <type>course</type>
        <format>moodle2</format>
        <interactive></interactive>
        <mode>10</mode>
        <execution>1</execution>
        <executiontime>0</executiontime>
      </detail>
    </details>
    <contents>
      <activities>' . $activitiesXml . '
      </activities>
      <sections>' . $sectionsXml . '
      </sections>
      <course>
        <courseid>' . $this->courseId . '</courseid>
        <title>' . $this->xmlEncode($this->data['shortname'] ?? 'cours') . '</title>
        <directory>course</directory>
      </course>
    </contents>
    <settings>
      <setting>
        <level>root</level>
        <name>filename</name>
        <value>backup.mbz</value>
      </setting>
      <setting>
        <level>root</level>
        <name>imscc11</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>users</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>anonymize</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>role_assignments</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>activities</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>blocks</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>files</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>filters</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>comments</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>badges</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>calendarevents</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>userscompletion</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>logs</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>grade_histories</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>questionbank</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>groups</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>competencies</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>customfield</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>contentbankcontent</name>
        <value>1</value>
      </setting>
      <setting>
        <level>root</level>
        <name>xapistate</name>
        <value>0</value>
      </setting>
      <setting>
        <level>root</level>
        <name>legacyfiles</name>
        <value>0</value>
      </setting>' . $sectionSettings . $activitySettings . '
    </settings>
  </information>
</moodle_backup>';
        
        $this->writeFile('moodle_backup.xml', $xml);
    }
    
    private function generateBadgesXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<badges>
</badges>';
        $this->writeFile('badges.xml', $xml);
    }
    
    private function generateCompletionXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<course_completion>
</course_completion>';
        $this->writeFile('completion.xml', $xml);
    }
    
    private function generateGradeHistoryXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        $this->writeFile('grade_history.xml', $xml);
    }
    
    private function generateGradebookXml() {
        // CORRECTION: Utiliser un ID dédié pour le grade_item du cours
        // et incrémenter pour éviter les conflits avec les activités
        $courseGradeItemId = $this->gradeItemId++;
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<gradebook>
  <attributes>
  </attributes>
  <grade_categories>
    <grade_category id="' . $this->gradeCategoryId . '">
      <parent>$@NULL@$</parent>
      <depth>1</depth>
      <path>/' . $this->gradeCategoryId . '/</path>
      <fullname>?</fullname>
      <aggregation>13</aggregation>
      <keephigh>0</keephigh>
      <droplow>0</droplow>
      <aggregateonlygraded>1</aggregateonlygraded>
      <aggregateoutcomes>0</aggregateoutcomes>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <hidden>0</hidden>
    </grade_category>
  </grade_categories>
  <grade_items>
    <grade_item id="' . $courseGradeItemId . '">
      <categoryid>$@NULL@$</categoryid>
      <itemname>$@NULL@$</itemname>
      <itemtype>course</itemtype>
      <itemmodule>$@NULL@$</itemmodule>
      <iteminstance>' . $this->gradeCategoryId . '</iteminstance>
      <itemnumber>$@NULL@$</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber>$@NULL@$</idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>10.00000</grademax>
      <grademin>0.00000</grademin>
      <scaleid>$@NULL@$</scaleid>
      <outcomeid>$@NULL@$</outcomeid>
      <gradepass>0.00000</gradepass>
      <multfactor>1.00000</multfactor>
      <plusfactor>0.00000</plusfactor>
      <aggregationcoef>0.00000</aggregationcoef>
      <aggregationcoef2>0.00000</aggregationcoef2>
      <weightoverride>0</weightoverride>
      <sortorder>1</sortorder>
      <display>0</display>
      <decimals>$@NULL@$</decimals>
      <hidden>0</hidden>
      <locked>0</locked>
      <locktime>0</locktime>
      <needsupdate>1</needsupdate>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <grade_grades>
      </grade_grades>
    </grade_item>
  </grade_items>
  <grade_letters>
  </grade_letters>
  <grade_settings>
    <grade_setting id="">
      <name>minmaxtouse</name>
      <value>1</value>
    </grade_setting>
  </grade_settings>
</gradebook>';
        $this->writeFile('gradebook.xml', $xml);
    }
    
    private function generateGroupsXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<groups>
  <groupcustomfields>
  </groupcustomfields>
  <groupings>
    <groupingcustomfields>
    </groupingcustomfields>
  </groupings>
</groups>';
        $this->writeFile('groups.xml', $xml);
    }
    
    private function generateOutcomesXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<outcomes_definition>
</outcomes_definition>';
        $this->writeFile('outcomes.xml', $xml);
    }
    
    private function generateQuestionsXml() {
        if (empty($this->questionsBank)) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<question_categories>
</question_categories>';
            $this->writeFile('questions.xml', $xml);
            return;
        }
        
        // Regrouper les questions par contextId (= par quiz)
        $byContext = [];
        foreach ($this->questionsBank as $q) {
            $ctxId = $q['contextId'];
            if (!isset($byContext[$ctxId])) $byContext[$ctxId] = [];
            $byContext[$ctxId][] = $q;
        }
        
        $categoriesXml = '';
        $catId = 39360;
        $topCatId = $catId + 1;
        
        foreach ($byContext as $contextId => $questions) {
            // Catégorie "top" 
            $topCatId = $catId++;
            $defaultCatId = $catId++;
            
            // Stocker les IDs pour les inforef
            $this->questionCategoryIds[] = $defaultCatId;
            $this->questionCategoryIds[] = $topCatId;
            
            // Récupérer le courseId depuis la première question du groupe
            $courseId = $questions[0]['courseId'] ?? 0;
            
            // Catégorie par défaut avec les questions
            $entriesXml = '';
            foreach ($questions as $q) {
                $entriesXml .= '
      <question_bank_entry id="' . $q['bankEntryId'] . '">
        <questioncategoryid>' . $defaultCatId . '</questioncategoryid>
        <idnumber>$@NULL@$</idnumber>
        <ownerid>120</ownerid>
        <question_version>
          <question_versions id="' . $q['versionId'] . '">
            <version>1</version>
            <status>ready</status>
            <questions>
              <question id="' . $q['questionId'] . '">
                <parent>0</parent>
                <name>' . $this->xmlEncode($q['name']) . '</name>
                <questiontext>' . $this->xmlEncode($q['questiontext']) . '</questiontext>
                <questiontextformat>1</questiontextformat>
                <generalfeedback></generalfeedback>
                <generalfeedbackformat>1</generalfeedbackformat>
                <defaultmark>' . $q['defaultmark'] . '</defaultmark>
                <penalty>' . $q['penalty'] . '</penalty>
                <qtype>' . $q['qtype'] . '</qtype>
                <length>1</length>
                <stamp>' . $q['stamp'] . '</stamp>
                <timecreated>' . $this->backupDate . '</timecreated>
                <timemodified>' . $this->backupDate . '</timemodified>
                <createdby>120</createdby>
                <modifiedby>120</modifiedby>' . $q['pluginXml'] . '
                <plugin_qbank_comment_question>
                  <comments>
                  </comments>
                </plugin_qbank_comment_question>
                <plugin_qbank_customfields_question>
                  <customfields>
                  </customfields>
                </plugin_qbank_customfields_question>
                <question_hints>
                </question_hints>
                <tags>
                </tags>
              </question>
            </questions>
          </question_versions>
        </question_version>
      </question_bank_entry>';
            }
            
            $categoriesXml .= '
  <question_category id="' . $defaultCatId . '">
    <name>La catégorie par défaut pour les questions partagées dans le contexte</name>
    <contextid>' . $contextId . '</contextid>
    <contextlevel>50</contextlevel>
    <contextinstanceid>' . $courseId . '</contextinstanceid>
    <info>&lt;p&gt;La catégorie par défaut pour les questions partagées dans le contexte.&lt;/p&gt;</info>
    <infoformat>1</infoformat>
    <stamp>elea-secours+' . date('ymdHis') . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>' . $topCatId . '</parent>
    <sortorder>999</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>' . $entriesXml . '
    </question_bank_entries>
  </question_category>
  <question_category id="' . $topCatId . '">
    <name>top</name>
    <contextid>' . $contextId . '</contextid>
    <contextlevel>50</contextlevel>
    <contextinstanceid>' . $courseId . '</contextinstanceid>
    <info></info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . date('ymdHis') . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>0</parent>
    <sortorder>0</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
    </question_bank_entries>
  </question_category>';
        }
        
        // Ajouter les catégories quiz-level (contextlevel=70) pour chaque quiz
        // Référence Éléa : 2 catégories par quiz (top + défaut), vides
        foreach ($this->quizActivityDirs as $quiz) {
            $quizContextId = $quiz['contextId'] ?? 0;
            $quizActivityId = $quiz['activityId'] ?? 0;
            if (!$quizContextId) continue;
            
            $topQuizCatId = $catId++;
            $defaultQuizCatId = $catId++;
            
            // Stocker les IDs pour les inforef
            $this->questionCategoryIds[] = $topQuizCatId;
            $this->questionCategoryIds[] = $defaultQuizCatId;
            
            $categoriesXml .= '
  <question_category id="' . $defaultQuizCatId . '">
    <n>Défaut pour quiz</n>
    <contextid>' . $quizContextId . '</contextid>
    <contextlevel>70</contextlevel>
    <contextinstanceid>' . $quizActivityId . '</contextinstanceid>
    <info>La catégorie par défaut pour les questions partagées dans le contexte du quiz.</info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . date('ymdHis') . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>' . $topQuizCatId . '</parent>
    <sortorder>999</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
    </question_bank_entries>
  </question_category>
  <question_category id="' . $topQuizCatId . '">
    <n>top</n>
    <contextid>' . $quizContextId . '</contextid>
    <contextlevel>70</contextlevel>
    <contextinstanceid>' . $quizActivityId . '</contextinstanceid>
    <info></info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . date('ymdHis') . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>0</parent>
    <sortorder>0</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
    </question_bank_entries>
  </question_category>';
        }
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<question_categories>' . $categoriesXml . '
</question_categories>';
        $this->writeFile('questions.xml', $xml);
    }
    
    /**
     * Met à jour les inforef.xml du quiz et du cours avec les question_categoryref
     * Doit être appelé APRÈS generateQuestionsXml() pour avoir les IDs de catégories
     */
    private function updateInforefsWithQuestionCategories() {
        if (empty($this->questionCategoryIds)) return;
        
        // Construire le XML question_categoryref
        $catRefXml = "\n  <question_categoryref>";
        foreach ($this->questionCategoryIds as $catId) {
            $catRefXml .= "\n    <question_category>\n      <id>" . $catId . "</id>\n    </question_category>";
        }
        $catRefXml .= "\n  </question_categoryref>";
        
        // 1. Mettre à jour chaque quiz inforef.xml — sans oublier ses propres fichiers
        // (images de la consigne), sinon cette réécriture les déréférence.
        foreach ($this->quizActivityDirs as $quiz) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <grade_itemref>
    <grade_item>
      <id>' . $quiz['gradeItemId'] . '</id>
    </grade_item>
  </grade_itemref>' . $catRefXml . $this->filerefXml($quiz['fileIds'] ?? []) . '
</inforef>';
            $this->writeFile($quiz['dir'] . '/inforef.xml', $xml);
        }
        
        // 2. Mettre à jour course/inforef.xml (vignette du cours + fichiers des questions)
        $fileRefXml = $this->buildCourseFilerefXml();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <roleref>
    <role>
      <id>5</id>
    </role>
  </roleref>' . $catRefXml . $fileRefXml . '
</inforef>';
        $this->writeFile('course/inforef.xml', $xml);
    }
    
    private function generateRolesXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<roles_definition>
  <role id="5">
    <name></name>
    <shortname>student</shortname>
    <nameincourse>$@NULL@$</nameincourse>
    <description></description>
    <sortorder>5</sortorder>
    <archetype>student</archetype>
  </role>
</roles_definition>';
        $this->writeFile('roles.xml', $xml);
    }
    
    private function generateScalesXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<scales_definition>
</scales_definition>';
        $this->writeFile('scales.xml', $xml);
    }
    
    private function generateBackupLog() {
        // Éléa génère un fichier log vide
        $this->writeFile('moodle_backup.log', '');
    }
    
    // ==================== DOSSIER COURSE ====================
    
    private function generateCourseFolder() {
        mkdir($this->exportDir . '/course', 0777, true);
        
        // course.xml
        $this->generateCourseXml();
        
        // completiondefaults.xml
        $this->generateCompletionDefaultsXml();
        
        // contentbank.xml
        $this->generateContentBankXml();
        
        // enrolments.xml
        $this->generateEnrolmentsXml();
        
        // filters.xml
        $this->generateCourseFiltersXml();
        
        // inforef.xml
        $this->generateCourseInforefXml();
        
        // roles.xml
        $this->generateCourseRolesXml();
        
        // CORRECTION: Ajouter le bloc course_toolbar (comme Éléa)
        $this->generateCourseToolbarBlock();
    }
    
    /**
     * Génère le bloc course_toolbar dans course/blocks/ (comme Éléa)
     */
    private function generateCourseToolbarBlock() {
        $blockId = rand(1000, 9999);
        $blockContextId = $this->contextId + 1;
        $blockDir = 'course/blocks/course_toolbar_' . $blockId;
        mkdir($this->exportDir . '/' . $blockDir, 0777, true);
        
        // block.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<block id="' . $blockId . '" contextid="' . $blockContextId . '" version="2025121100">
  <blockname>course_toolbar</blockname>
  <parentcontextid>' . $this->contextId . '</parentcontextid>
  <showinsubcontexts>0</showinsubcontexts>
  <pagetypepattern>course-view-*</pagetypepattern>
  <subpagepattern>$@NULL@$</subpagepattern>
  <defaultregion>side-pre</defaultregion>
  <defaultweight>0</defaultweight>
  <configdata></configdata>
  <timecreated>' . $this->backupDate . '</timecreated>
  <timemodified>' . $this->backupDate . '</timemodified>
  <block_positions>
  </block_positions>
</block>';
        $this->writeFile($blockDir . '/block.xml', $xml);
        
        // inforef.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
</inforef>';
        $this->writeFile($blockDir . '/inforef.xml', $xml);
        
        // roles.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        $this->writeFile($blockDir . '/roles.xml', $xml);
        
        // Ajouter au ARCHIVE_INDEX
        $this->archiveIndex[] = "course/blocks/\td\t0\t?";
        $this->archiveIndex[] = $blockDir . "/\td\t0\t?";
        $this->archiveIndex[] = $blockDir . "/block.xml\tf\t555\t" . $this->backupDate;
        $this->archiveIndex[] = $blockDir . "/inforef.xml\tf\t59\t" . $this->backupDate;
        $this->archiveIndex[] = $blockDir . "/roles.xml\tf\t137\t" . $this->backupDate;
    }
    
    private function generateCourseXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<course id="' . $this->courseId . '" contextid="' . $this->contextId . '">
  <shortname>' . $this->xmlEncode($this->data['shortname'] ?? 'cours') . '</shortname>
  <fullname>' . $this->xmlEncode($this->data['name'] ?? 'Cours') . '</fullname>
  <idnumber></idnumber>
  <summary></summary>
  <summaryformat>0</summaryformat>
  <format>topics</format>
  <showgrades>1</showgrades>
  <newsitems>0</newsitems>
  <startdate>' . $this->backupDate . '</startdate>
  <enddate>0</enddate>
  <marker>0</marker>
  <maxbytes>0</maxbytes>
  <legacyfiles>0</legacyfiles>
  <showreports>0</showreports>
  <visible>1</visible>
  <groupmode>1</groupmode>
  <groupmodeforce>0</groupmodeforce>
  <defaultgroupingid>0</defaultgroupingid>
  <lang></lang>
  <theme></theme>
  <timecreated>' . $this->backupDate . '</timecreated>
  <timemodified>' . $this->backupDate . '</timemodified>
  <requested>0</requested>
  <showactivitydates>0</showactivitydates>
  <showcompletionconditions>1</showcompletionconditions>
  <pdfexportfont>$@NULL@$</pdfexportfont>
  <enablecompletion>1</enablecompletion>
  <completionnotify>0</completionnotify>
  <category id="1">
    <name>Défaut</name>
    <description></description>
  </category>
  <tags>
  </tags>
  <customfields>
    <customfield id="1">
      <shortname>duration</shortname>
      <type>text</type>
      <value></value>
      <valueformat>0</valueformat>
      <valuetrust></valuetrust>
    </customfield>
    <customfield id="2">
      <shortname>subject</shortname>
      <type>text</type>
      <value>Technologie</value>
      <valueformat>0</valueformat>
      <valuetrust></valuetrust>
    </customfield>
    <customfield id="3">
      <shortname>base_conception_id</shortname>
      <type>text</type>
      <value></value>
      <valueformat>0</valueformat>
      <valuetrust></valuetrust>
    </customfield>
  </customfields>
  <courseformatoptions>
    <courseformatoption>
      <format>topics</format>
      <sectionid>0</sectionid>
      <name>coursedisplay</name>
      <value>1</value>
    </courseformatoption>
    <courseformatoption>
      <format>topics</format>
      <sectionid>0</sectionid>
      <name>hiddensections</name>
      <value>1</value>
    </courseformatoption>
  </courseformatoptions>
</course>';
        $this->writeFile('course/course.xml', $xml);
    }
    
    private function generateCompletionDefaultsXml() {
        // Liste complète des 36 modules Éléa avec leurs paramètres de completion
        $modules = [
            ['name' => 'assign', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'board', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'book', 'completion' => 2, 'view' => 1, 'grade' => 0, 'rules' => '{"modids":["3","16","18","21"]}'],
            ['name' => 'chat', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'choice', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'choicegroup', 'completion' => 2, 'view' => 1, 'grade' => 0, 'rules' => '{"completionsubmit":1,"modids":["24"]}'],
            ['name' => 'collabora', 'completion' => 0, 'view' => 0, 'grade' => 0, 'rules' => '{"modids":["4","8","9","13","20","22","30","35","40"]}'],
            ['name' => 'data', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'epikmatch', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '{"completionminattemptsenabled":1,"completionminattempts":1,"modids":["25","32","34"]}'],
            ['name' => 'feedback', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'folder', 'completion' => 0, 'view' => 0, 'grade' => 0, 'rules' => '{"modids":["8","13","30"]}'],
            ['name' => 'forum', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'game', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'geogebra', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'glossary', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'h5pactivity', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'hvp', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'imscp', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'label', 'completion' => 0, 'view' => 0, 'grade' => 0, 'rules' => '{"modids":["8","13","30"]}'],
            ['name' => 'lesson', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'lti', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'mapmodules', 'completion' => 0, 'view' => 0, 'grade' => 0, 'rules' => '{"modids":["8","13","30"]}'],
            ['name' => 'millionnairev2', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '{"completionminattemptsenabled":1,"completionminattempts":1,"modids":["25","32","34"]}'],
            ['name' => 'page', 'completion' => 2, 'view' => 1, 'grade' => 0, 'rules' => '{"modids":["3","16","18","21"]}'],
            ['name' => 'programcourse', 'completion' => 2, 'view' => 0, 'grade' => 0, 'rules' => '{"completionall":1,"modids":["41"]}'],
            ['name' => 'questionnaire', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'quiz', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'resource', 'completion' => 2, 'view' => 1, 'grade' => 0, 'rules' => '{"modids":["3","16","18","21"]}'],
            ['name' => 'scorm', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'simplequiz', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '{"completionminattemptsenabled":1,"completionminattempts":1,"modids":["25","32","34"]}'],
            ['name' => 'stickynotes', 'completion' => 2, 'view' => 0, 'grade' => 0, 'rules' => '{"completionstickynotesenabled":1,"completionstickynotes":1,"modids":["42"]}'],
            ['name' => 'studentquiz', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'survey', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'url', 'completion' => 2, 'view' => 1, 'grade' => 0, 'rules' => '{"modids":["3","16","18","21"]}'],
            ['name' => 'wiki', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
            ['name' => 'workshop', 'completion' => 2, 'view' => 1, 'grade' => 1, 'rules' => '$@NULL@$'],
        ];
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<course_completion_defaults>';
        
        $id = 1;
        foreach ($modules as $mod) {
            $xml .= '
  <course_completion_default id="' . $id . '">
    <modulename>' . $mod['name'] . '</modulename>
    <completion>' . $mod['completion'] . '</completion>
    <completionview>' . $mod['view'] . '</completionview>
    <completionusegrade>' . $mod['grade'] . '</completionusegrade>
    <completionpassgrade>0</completionpassgrade>
    <completionexpected>0</completionexpected>
    <customrules>' . $mod['rules'] . '</customrules>
  </course_completion_default>';
            $id++;
        }
        
        $xml .= '
</course_completion_defaults>';
        $this->writeFile('course/completiondefaults.xml', $xml);
    }
    
    private function generateContentBankXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<contents>
</contents>';
        $this->writeFile('course/contentbank.xml', $xml);
    }
    
    private function generateEnrolmentsXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<enrolments>
  <enrols>
    <enrol id="1">
      <enrol>manual</enrol>
      <status>0</status>
      <name>$@NULL@$</name>
      <enrolperiod>0</enrolperiod>
      <enrolstartdate>0</enrolstartdate>
      <enrolenddate>0</enrolenddate>
      <expirynotify>0</expirynotify>
      <expirythreshold>86400</expirythreshold>
      <notifyall>0</notifyall>
      <password>$@NULL@$</password>
      <cost>$@NULL@$</cost>
      <currency>$@NULL@$</currency>
      <roleid>5</roleid>
      <customint1>0</customint1>
      <customint2>$@NULL@$</customint2>
      <customint3>$@NULL@$</customint3>
      <customint4>$@NULL@$</customint4>
      <customint5>$@NULL@$</customint5>
      <customint6>$@NULL@$</customint6>
      <customint7>$@NULL@$</customint7>
      <customint8>$@NULL@$</customint8>
      <customchar1>$@NULL@$</customchar1>
      <customchar2>$@NULL@$</customchar2>
      <customchar3>$@NULL@$</customchar3>
      <customdec1>$@NULL@$</customdec1>
      <customdec2>$@NULL@$</customdec2>
      <customtext1>$@NULL@$</customtext1>
      <customtext2>$@NULL@$</customtext2>
      <customtext3>$@NULL@$</customtext3>
      <customtext4>$@NULL@$</customtext4>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <user_enrolments>
      </user_enrolments>
    </enrol>
    <enrol id="2">
      <enrol>guest</enrol>
      <status>1</status>
      <name>$@NULL@$</name>
      <enrolperiod>0</enrolperiod>
      <enrolstartdate>0</enrolstartdate>
      <enrolenddate>0</enrolenddate>
      <expirynotify>0</expirynotify>
      <expirythreshold>0</expirythreshold>
      <notifyall>0</notifyall>
      <password></password>
      <cost>$@NULL@$</cost>
      <currency>$@NULL@$</currency>
      <roleid>0</roleid>
      <customint1>$@NULL@$</customint1>
      <customint2>$@NULL@$</customint2>
      <customint3>$@NULL@$</customint3>
      <customint4>$@NULL@$</customint4>
      <customint5>$@NULL@$</customint5>
      <customint6>$@NULL@$</customint6>
      <customint7>$@NULL@$</customint7>
      <customint8>$@NULL@$</customint8>
      <customchar1>$@NULL@$</customchar1>
      <customchar2>$@NULL@$</customchar2>
      <customchar3>$@NULL@$</customchar3>
      <customdec1>$@NULL@$</customdec1>
      <customdec2>$@NULL@$</customdec2>
      <customtext1>$@NULL@$</customtext1>
      <customtext2>$@NULL@$</customtext2>
      <customtext3>$@NULL@$</customtext3>
      <customtext4>$@NULL@$</customtext4>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <user_enrolments>
      </user_enrolments>
    </enrol>
    <enrol id="3">
      <enrol>self</enrol>
      <status>1</status>
      <name>$@NULL@$</name>
      <enrolperiod>0</enrolperiod>
      <enrolstartdate>0</enrolstartdate>
      <enrolenddate>0</enrolenddate>
      <expirynotify>0</expirynotify>
      <expirythreshold>86400</expirythreshold>
      <notifyall>0</notifyall>
      <password>$@NULL@$</password>
      <cost>$@NULL@$</cost>
      <currency>$@NULL@$</currency>
      <roleid>5</roleid>
      <customint1>0</customint1>
      <customint2>0</customint2>
      <customint3>0</customint3>
      <customint4>1</customint4>
      <customint5>0</customint5>
      <customint6>1</customint6>
      <customint7>$@NULL@$</customint7>
      <customint8>$@NULL@$</customint8>
      <customchar1>$@NULL@$</customchar1>
      <customchar2>$@NULL@$</customchar2>
      <customchar3>$@NULL@$</customchar3>
      <customdec1>$@NULL@$</customdec1>
      <customdec2>$@NULL@$</customdec2>
      <customtext1>$@NULL@$</customtext1>
      <customtext2>$@NULL@$</customtext2>
      <customtext3>$@NULL@$</customtext3>
      <customtext4>$@NULL@$</customtext4>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <user_enrolments>
      </user_enrolments>
    </enrol>
  </enrols>
</enrolments>';
        $this->writeFile('course/enrolments.xml', $xml);
    }
    
    private function generateCourseFiltersXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>';
        $this->writeFile('course/filters.xml', $xml);
    }
    
    private function generateCourseInforefXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <roleref>
    <role>
      <id>5</id>
    </role>
  </roleref>' . $this->buildCourseFilerefXml() . '
</inforef>';
        $this->writeFile('course/inforef.xml', $xml);
    }

    /**
     * Le <fileref> de course/inforef.xml : vignette du cours + fichiers des questions.
     * Sans cette référence, Moodle ignore les entrées files.xml du contexte de cours
     * (l'image est dans l'archive mais n'est jamais restaurée).
     */
    private function buildCourseFilerefXml(): string {
        $ids = array_merge($this->courseImageFileIds, $this->questionFileIds);
        if (empty($ids)) return '';
        $xml = "\n  <fileref>";
        foreach ($ids as $fid) {
            $xml .= "\n    <file>\n      <id>{$fid}</id>\n    </file>";
        }
        return $xml . "\n  </fileref>";
    }

    /**
     * Vignette du cours : l'image affichée sur la carte du parcours dans Éléa.
     * Elle ne dépend d'aucune activité — c'est un fichier du CONTEXTE DU COURS
     * (component « course », filearea « overviewfiles », itemid 0), accompagné de
     * son entrée de dossier « . ». Sans ces deux lignes dans files.xml + leurs IDs
     * dans course/inforef.xml, le cours réimporté sur Éléa perd sa vignette.
     */
    private function addCourseImageFiles() {
        $vignette = $this->data['vignette'] ?? null;
        if (empty($vignette)) return;

        $url = is_array($vignette) ? ($vignette['url'] ?? '') : (string)$vignette;
        $nom = is_array($vignette) ? ($vignette['name'] ?? '') : '';
        if ($url === '') return;

        $localPath = $this->resolveImagePath($url);
        if (!$localPath || !file_exists($localPath)) {
            // Signalé au professeur comme n'importe quel média manquant : mieux vaut
            // un avertissement qu'une vignette disparue en silence.
            $manquant = $nom !== '' ? $nom : 'vignette du cours';
            $this->unresolvedFiles[$manquant] = true;
            $this->logExport('[vignette] fichier introuvable : ' . substr($url, 0, 150));
            return;
        }

        $contenu = @file_get_contents($localPath);
        if ($contenu === false || strlen($contenu) < 10) {
            $this->logExport('[vignette] fichier illisible : ' . $localPath);
            return;
        }

        $filename = $this->buildCourseImageFilename($nom, $url, $localPath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        $mime = $mimeMap[$ext] ?? 'image/png';

        $contenthash = sha1($contenu);
        $filesize = strlen($contenu);
        $hashPrefix = substr($contenthash, 0, 2);

        $filesSubDir = $this->exportDir . '/files/' . $hashPrefix;
        if (!is_dir($filesSubDir)) {
            mkdir($filesSubDir, 0777, true);
        }
        if (!file_exists($filesSubDir . '/' . $contenthash)) {
            file_put_contents($filesSubDir . '/' . $contenthash, $contenu);
        }
        $this->archiveIndex[] = "files/\td\t0\t?";
        $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
        $this->archiveIndex[] = "files/{$hashPrefix}/{$contenthash}\tf\t{$filesize}\t" . $this->backupDate;

        $fileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $fileId,
            'contenthash' => $contenthash,
            'contextid' => $this->contextId,
            'component' => 'course',
            'filearea' => 'overviewfiles',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'filesize' => $filesize,
            'mimetype' => $mime,
            'source' => $filename,
        ];

        $dirFileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $dirFileId,
            'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
            'contextid' => $this->contextId,
            'component' => 'course',
            'filearea' => 'overviewfiles',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => '.',
            'filesize' => 0,
            'mimetype' => '$@NULL@$',
        ];

        $this->courseImageFileIds = [$fileId, $dirFileId];
        $this->logExport('[vignette] ' . $filename . ' (' . round($filesize / 1024) . ' Ko) ajoutée au cours');
    }

    /**
     * Nom de fichier de la vignette dans l'archive. On garde le nom d'origine quand
     * il est parlant ; les noms techniques de l'éditeur (upload_…, import_…) sont
     * remplacés par le nom du cours, comme le fait Éléa.
     */
    private function buildCourseImageFilename($nom, $url, $localPath): string {
        $candidat = $nom !== '' ? $nom : basename(parse_url($url, PHP_URL_PATH) ?: '');
        if ($candidat === '' && preg_match('#file=([^&]+)#', $url, $m)) {
            $candidat = urldecode($m[1]);
        }
        $candidat = basename(str_replace('\\', '/', $candidat));
        $candidat = preg_replace('#[/:*?"<>|]+#', '', $candidat);

        $ext = strtolower(pathinfo($candidat, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            $detecte = @getimagesize($localPath);
            $parType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png',
                        IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            $ext = $parType[$detecte[2] ?? 0] ?? 'png';
        }

        if ($candidat === '' || preg_match('#^(?:upload|import|tpl)_#i', $candidat)) {
            $base = $this->data['name'] ?? 'cours';
            $base = preg_replace('#[^\p{L}\p{N}\s._-]+#u', '', $base);
            $base = trim(preg_replace('#\s+#u', '-', trim($base)), '-');
            if ($base === '') $base = 'vignette';
            $candidat = mb_substr($base, 0, 80) . '.' . $ext;
        } elseif (strtolower(pathinfo($candidat, PATHINFO_EXTENSION)) !== $ext) {
            $candidat = pathinfo($candidat, PATHINFO_FILENAME) . '.' . $ext;
        }

        return $candidat;
    }
    
    private function generateCourseRolesXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        $this->writeFile('course/roles.xml', $xml);
    }
    
    // ==================== SECTIONS ====================
    
    private function generateSections() {
        mkdir($this->exportDir . '/sections', 0777, true);
        
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $sectionId = ($sIdx + 1) * 1000;
            $sectionDir = 'sections/section_' . $sectionId;
            mkdir($this->exportDir . '/' . $sectionDir, 0777, true);
            
            // Calculer la séquence des activités
            $sequence = [];
            $actId = 1;
            foreach ($this->data['sections'] as $i => $s) {
                foreach ($s['activities'] ?? [] as $a) {
                    if ($i === $sIdx) {
                        $sequence[] = $actId;
                    }
                    $actId++;
                }
            }
            
            // section.xml
            $sectionNameXml = !empty($section['name']) ? $this->xmlEncode($section['name']) : '$@NULL@$';
            $sectionVisible = (isset($section['visible']) && $section['visible'] === false) ? 0 : 1;
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<section id="' . $sectionId . '">
  <number>' . $sIdx . '</number>
  <name>' . $sectionNameXml . '</name>
  <summary>' . $this->xmlEncode($section['summary'] ?? '') . '</summary>
  <summaryformat>1</summaryformat>
  <sequence>' . implode(',', $sequence) . '</sequence>
  <visible>' . $sectionVisible . '</visible>
  <availabilityjson>$@NULL@$</availabilityjson>
  <component>$@NULL@$</component>
  <itemid>$@NULL@$</itemid>
  <timemodified>' . $this->backupDate . '</timemodified>
</section>';
            $this->writeFile($sectionDir . '/section.xml', $xml);
            
            // inforef.xml
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
</inforef>';
            $this->writeFile($sectionDir . '/inforef.xml', $xml);
        }
    }
    
    // ==================== ACTIVITÉS ====================
    
    private function generateActivities() {
        mkdir($this->exportDir . '/activities', 0777, true);

        // Les activités représentent l'essentiel du temps d'export : on répartit 15 % → 80 %
        $totalActivites = 0;
        foreach ($this->data['sections'] ?? [] as $s) {
            $totalActivites += count($s['activities'] ?? []);
        }
        $faites = 0;

        $activityId = 1;
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $sectionId = ($sIdx + 1) * 1000;

            foreach ($section['activities'] ?? [] as $activity) {
                $activityType = $activity['type'] ?? 'h5pactivity';
                $this->progress(
                    $totalActivites ? 15 + 65 * ($faites / $totalActivites) : 15,
                    'Activité ' . ($faites + 1) . '/' . $totalActivites . ' — ' . ($activity['name'] ?? '')
                );
                $faites++;
                
                // Calculer la visibilité (section + activité)
                $sectionVis = (isset($section['visible']) && $section['visible'] === false) ? false : true;
                $actVis = (isset($activity['visible']) && $activity['visible'] === false) ? false : true;
                if (!$sectionVis) {
                    $activity['_moduleVisible'] = 0;
                    $activity['_moduleVisibleold'] = $actVis ? 1 : 0;
                } else {
                    $activity['_moduleVisible'] = $actVis ? 1 : 0;
                    $activity['_moduleVisibleold'] = $actVis ? 1 : 0;
                }
                
                if ($activityType === 'mapmodules') {
                    $this->generateMapmodulesActivity($activityId, $sectionId, $sIdx, $activity);
                } elseif ($activityType === 'assign') {
                    @file_put_contents(TMP_PATH . '/.export_progress.log', date('H:i:s') . " ROUTE: actId=$activityId type=ASSIGN name='{$activity['name']}'\n", FILE_APPEND | LOCK_EX);
                    $this->generateAssignActivity($activityId, $sectionId, $sIdx, $activity);
                } elseif ($activityType === 'resource') {
                    @file_put_contents(TMP_PATH . '/.export_progress.log', date('H:i:s') . " ROUTE: actId=$activityId type=RESOURCE name='{$activity['name']}'\n", FILE_APPEND | LOCK_EX);
                    $this->generateResourceActivity($activityId, $sectionId, $sIdx, $activity);
                } elseif ($activityType === 'label') {
                    $this->generateLabelActivity($activityId, $sectionId, $sIdx, $activity);
                } elseif ($activityType === 'page') {
                    $this->generatePageActivity($activityId, $sectionId, $sIdx, $activity);
                } elseif ($activityType === 'h5pactivity' && ($activity['h5pType'] ?? '') === 'QuestionSet'
                    && $this->isNewFormatQuestionSet($activity)) {
                    $this->generateEvalQuizActivity($activityId, $sectionId, $sIdx, $activity);
                } elseif ($activityType === 'quiz') {
                    $this->generateQuizActivity($activityId, $sectionId, $sIdx, $activity);
                } else {
                    $this->generateH5pActivity($activityId, $sectionId, $sIdx, $activity);
                }
                $activityId++;
            }
        }
    }
    
    private function generateMapmodulesActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/mapmodules_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);
        
        $contextId = $this->contextId + $activityId + 1;
        $mapId = $activityId + 2000;
        $name = $this->xmlEncode($activity['name'] ?? 'Carte de progression');
        $mapPath = $activity['mapPath'] ?? '';
        $iconset = $activity['iconset'] ?? 4;
        $buttonWidth = $activity['buttonWidth'] ?? 50;
        // Textes autour de la carte : ils peuvent contenir des images (même traitement
        // que les autres consignes — sinon l'URL elea-secours partait telle quelle).
        $mapIntroFileIds = [];
        $descHeader = $this->inlineHtmlFiles($activity['descriptionHeader'] ?? '', $contextId,
                                             'mod_mapmodules', 'intro', $mapIntroFileIds);
        $descFooter = $this->inlineHtmlFiles($activity['descriptionFooter'] ?? '', $contextId,
                                             'mod_mapmodules', 'intro', $mapIntroFileIds);
        $now = time();
        $gameId = 'game' . $activityId;
        
        // Nom interne Éléa : doit être "Carte standard : orange" ou "Carte personnalisée"
        // sinon Éléa refuse l'import avec "Unknown origin of image"
        $mapImage = $activity['mapImage'] ?? null;
        $customImageFilename = null;
        $fileId = null;
        $dirFileId = null;
        
        if (!empty($mapImage)) {
            // Image personnalisée : résoudre le fichier
            $localPath = $this->resolveImagePath($mapImage);
            if ($localPath && file_exists($localPath)) {
                // Extraire le nom du fichier original
                $customImageFilename = basename($mapImage);
                // Nettoyer le nom (enlever les query params)
                if (strpos($customImageFilename, '?') !== false) {
                    // C'est une URL serve_upload, extraire le nom
                    if (preg_match('#file=([^&]+)#', $mapImage, $m)) {
                        $customImageFilename = urldecode($m[1]);
                    }
                }
                // Si le nom est un import/upload name, utiliser un nom plus propre
                if (preg_match('#^(?:upload|import|tpl)_#', $customImageFilename)) {
                    $ext = pathinfo($customImageFilename, PATHINFO_EXTENSION) ?: 'png';
                    $customImageFilename = 'carte_progression.' . $ext;
                }
                
                $contenthash = sha1_file($localPath);
                $filesize = filesize($localPath);
                $hashPrefix = substr($contenthash, 0, 2);
                $mime = mime_content_type($localPath) ?: 'image/png';
                
                // Copier dans le répertoire files de l'export
                $filesSubDir = $this->exportDir . '/files/' . $hashPrefix;
                if (!is_dir($filesSubDir)) {
                    mkdir($filesSubDir, 0777, true);
                }
                copy($localPath, $filesSubDir . '/' . $contenthash);
                
                // Ajouter au manifest
                $fileId = $this->fileId++;
                $this->filesManifest[] = [
                    'id' => $fileId,
                    'contenthash' => $contenthash,
                    'contextid' => $contextId,
                    'component' => 'mod_mapmodules',
                    'filearea' => 'maps',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $customImageFilename,
                    'filesize' => $filesize,
                    'mimetype' => $mime,
                ];
                // Entrée répertoire
                $dirFileId = $this->fileId++;
                $this->filesManifest[] = [
                    'id' => $dirFileId,
                    'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                    'contextid' => $contextId,
                    'component' => 'mod_mapmodules',
                    'filearea' => 'maps',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => '.',
                    'filesize' => 0,
                    'mimetype' => '$@NULL@$',
                ];
                
                $this->archiveIndex[] = "files/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/{$contenthash}\tf\t{$filesize}\t" . $this->backupDate;
                
                $eleaName = 'Carte personnalisée : ' . $this->xmlEncode($customImageFilename);
            } else {
                $eleaName = 'Carte standard : orange';
            }
        } else {
            $eleaName = 'Carte standard : orange';
        }
        
        // Générer le HTML intro avec SVG et script (comme Éléa le fait)
        $introHtml = '<div>' . $descHeader . '</div>';
        $introHtml .= '<div><div id="container_' . $gameId . '" style="width:100%;position:relative;margin:0 auto;">';
        
        if (!empty($customImageFilename)) {
            // Image personnalisée : utiliser le format $@PLUGINFILEBYCONTEXT@$
            $introHtml .= '<img id="back_' . $gameId . '" style="width:100%;" src="$@PLUGINFILEBYCONTEXT*' . $contextId . '@$/mod_mapmodules/maps/0/' . htmlspecialchars($customImageFilename) . '" alt="bg">';
        } else {
            $introHtml .= '<img id="back_' . $gameId . '" style="width:100%;" src="@@PLUGINFILE@@/maps/map_orange.jpg" alt="bg">';
        }
        
        $introHtml .= '<svg id="path_container_' . $gameId . '" style=\'position:absolute;top:0px;left:0px;\' viewBox="0 0 1000 400" version="1.1">';
        $introHtml .= '<path id="path" fill="none" d="' . $this->xmlEncode($mapPath) . '" />';
        $introHtml .= '</svg>';
        $introHtml .= '</div></div>';
        if (!empty($descFooter)) {
            $introHtml .= '<div>' . $descFooter . '</div>';
        }
        
        // Déterminer la section cible (toutes les sections sauf celle-ci)
        // 666 est la valeur par défaut observée dans Éléa
        $targetSection = $activity['targetsection'] ?? '666';
        
        // mapmodules.xml
        $mapXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $mapId . '" moduleid="' . $activityId . '" modulename="mapmodules" contextid="' . $contextId . '">
  <mapmodules id="' . $mapId . '">
    <course>' . ($this->data['id'] ?? '1') . '</course>
    <name>' . $eleaName . '</name>
    <intro>' . $this->xmlEncode($introHtml) . '</intro>
    <introformat>1</introformat>
    <timecreated>' . $now . '</timecreated>
    <timemodified>' . $now . '</timemodified>
    <targetsection>' . $targetSection . '</targetsection>
    <path>' . $this->xmlEncode($mapPath) . '</path>
    <iconset>' . $iconset . '</iconset>
    <displaymodulenames>0</displaymodulenames>
    <buttonwidth>' . $buttonWidth . '</buttonwidth>
    <descriptionheader>' . $this->xmlEncode($descHeader) . '</descriptionheader>
    <descriptionheaderformat>1</descriptionheaderformat>
    <descriptionfooter>' . $this->xmlEncode($descFooter) . '</descriptionfooter>
    <descriptionfooterformat>1</descriptionfooterformat>
  </mapmodules>
</activity>';
        $this->writeFile($activityDir . '/mapmodules.xml', $mapXml);
        
        // module.xml
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2020032500">
  <modulename>mapmodules</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>0</groupmode>
  <groupingid>0</groupingid>
  <completion>0</completion>
  <completiongradeitemnumber>$@NULL@$</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>0</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>1</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>';
        $this->writeFile($activityDir . '/module.xml', $moduleXml);
        
        // grades.xml
        $gradeItemId = 30000 + $activityId;
        $gradesXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
    <grade_item id="' . $gradeItemId . '">
      <categoryid>1</categoryid>
      <itemname>' . $name . '</itemname>
      <itemtype>mod</itemtype>
      <itemmodule>mapmodules</itemmodule>
      <iteminstance>' . $mapId . '</iteminstance>
      <itemnumber>0</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber>$@NULL@$</idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>10.00000</grademax>
      <grademin>0.00000</grademin>
      <scaleid>$@NULL@$</scaleid>
      <outcomeid>$@NULL@$</outcomeid>
      <gradepass>0.00000</gradepass>
      <multfactor>1.00000</multfactor>
      <plusfactor>0.00000</plusfactor>
      <aggregationcoef>0.00000</aggregationcoef>
      <aggregationcoef2>0.08333</aggregationcoef2>
      <weightoverride>0</weightoverride>
      <sortorder>' . ($activityId * 2) . '</sortorder>
      <display>0</display>
      <decimals>$@NULL@$</decimals>
      <hidden>0</hidden>
      <locked>0</locked>
      <locktime>0</locktime>
      <needsupdate>1</needsupdate>
      <timecreated>' . $now . '</timecreated>
      <timemodified>' . $now . '</timemodified>
      <grade_grades>
      </grade_grades>
    </grade_item>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        $this->writeFile($activityDir . '/grades.xml', $gradesXml);
        
        // inforef.xml : image de fond personnalisée + images des textes autour
        $refs = $mapIntroFileIds;
        if (!empty($customImageFilename)) {
            array_unshift($refs, $fileId, $dirFileId);
        }
        $filerefXml = $this->filerefXml($refs);
        $inforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>' . $filerefXml . '
  <grade_itemref>
    <grade_item>
      <id>' . $gradeItemId . '</id>
    </grade_item>
  </grade_itemref>
</inforef>';
        $this->writeFile($activityDir . '/inforef.xml', $inforefXml);
        
        // Fichiers standard
        $this->writeFile($activityDir . '/grade_history.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<grade_history>\n  <grade_grades>\n  </grade_grades>\n</grade_history>");
        $this->writeFile($activityDir . '/roles.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<roles>\n  <role_overrides>\n  </role_overrides>\n  <role_assignments>\n  </role_assignments>\n</roles>");
        $this->writeFile($activityDir . '/filters.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<filters>\n  <filter_actives>\n  </filter_actives>\n  <filter_configs>\n  </filter_configs>\n</filters>");
    }
    
    private function generateAssignActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/assign_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);
        
        $contextId = $this->contextId + $activityId + 1;
        $assignId = $activityId + 4000;
        $name = $this->xmlEncode($activity['name'] ?? 'Fichier à distribuer');
        // Note max : personnalisable (norme Max = évaluations /10). Défaut Moodle = 100.
        $assignGrade = isset($activity['grade']) ? (int)$activity['grade'] : 100;
        $now = time();
        
        // Gérer les fichiers joints (multi-fichier + rétrocompatibilité mono-fichier)
        $fileIds = [];
        $editorFiles = $activity['files'] ?? [];
        
        // Rétrocompatibilité : ancien format mono-fichier (fileUrl/fileName)
        if (empty($editorFiles) && !empty($activity['fileUrl']) && !empty($activity['fileName'])) {
            $editorFiles = [['fileUrl' => $activity['fileUrl'], 'fileName' => $activity['fileName']]];
        }
        
        foreach ($editorFiles as $f) {
            $fileUrl = $f['fileUrl'] ?? null;
            $fileName = $f['fileName'] ?? null;
            if (!$fileUrl || !$fileName) continue;
            
            $localPath = $this->resolveImagePath($fileUrl);
            error_log("[EXPORT-ASSIGN] fileUrl=" . substr($fileUrl, 0, 150) . " fileName=$fileName resolved=" . ($localPath ?: 'NULL') . " exists=" . ($localPath && file_exists($localPath) ? 'YES(' . filesize($localPath) . ')' : 'NO'));
            $progressLog = TMP_PATH . '/.export_progress.log';
            @file_put_contents($progressLog, date('H:i:s') . " ASSIGN: $fileName → " . ($localPath && file_exists($localPath) ? 'OK' : 'FAIL') . " url=" . substr($fileUrl, 0, 80) . "\n", FILE_APPEND | LOCK_EX);
            if ($localPath && file_exists($localPath)) {
                $contenthash = sha1_file($localPath);
                $filesize = filesize($localPath);
                $hashPrefix = substr($contenthash, 0, 2);
                $mime = $this->getMoodleMimetype($fileName);
                
                // Copier dans files/
                $filesSubDir = $this->exportDir . '/files/' . $hashPrefix;
                if (!is_dir($filesSubDir)) {
                    mkdir($filesSubDir, 0777, true);
                }
                copy($localPath, $filesSubDir . '/' . $contenthash);
                
                // Entrée fichier
                $fid = $this->fileId++;
                $fileIds[] = $fid;
                $this->filesManifest[] = [
                    'id' => $fid,
                    'contenthash' => $contenthash,
                    'contextid' => $contextId,
                    'component' => 'mod_assign',
                    'filearea' => 'introattachment',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $fileName,
                    'filesize' => $filesize,
                    'mimetype' => $mime,
                ];
                
                $this->archiveIndex[] = "files/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/{$contenthash}\tf\t{$filesize}\t" . $this->backupDate;
            }
        }
        
        // Entrée répertoire (une seule pour tous les fichiers)
        if (!empty($fileIds)) {
            $dirFileId = $this->fileId++;
            $fileIds[] = $dirFileId;
            $this->filesManifest[] = [
                'id' => $dirFileId,
                'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'contextid' => $contextId,
                'component' => 'mod_assign',
                'filearea' => 'introattachment',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => '.',
                'filesize' => 0,
                'mimetype' => '$@NULL@$',
            ];
        }
        
        // Consigne (intro) et ses images. Les IDs partent dans $fileIds, donc dans le
        // <fileref> de l'inforef du devoir : sans ça Moodle ne restaure tout simplement
        // pas ces images (le devoir s'affichait sans ses captures sur Éléa).
        $introXml = $this->inlineHtmlFiles($activity['intro'] ?? '', $contextId,
                                           'mod_assign', 'intro', $fileIds);
        
        // assign.xml
        $pcId = $assignId * 10;
        $assignXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $assignId . '" moduleid="' . $activityId . '" modulename="assign" contextid="' . $contextId . '">
  <assign id="' . $assignId . '">
    <name>' . $name . '</name>
    <intro>' . $this->xmlEncode($introXml) . '</intro>
    <introformat>1</introformat>
    <alwaysshowdescription>0</alwaysshowdescription>
    <submissiondrafts>0</submissiondrafts>
    <sendnotifications>0</sendnotifications>
    <sendlatenotifications>0</sendlatenotifications>
    <sendstudentnotifications>1</sendstudentnotifications>
    <duedate>0</duedate>
    <cutoffdate>0</cutoffdate>
    <gradingduedate>0</gradingduedate>
    <allowsubmissionsfromdate>0</allowsubmissionsfromdate>
    <grade>' . $assignGrade . '</grade>
    <timemodified>' . $now . '</timemodified>
    <completionsubmit>1</completionsubmit>
    <requiresubmissionstatement>0</requiresubmissionstatement>
    <teamsubmission>0</teamsubmission>
    <requireallteammemberssubmit>0</requireallteammemberssubmit>
    <teamsubmissiongroupingid>0</teamsubmissiongroupingid>
    <blindmarking>0</blindmarking>
    <hidegrader>0</hidegrader>
    <revealidentities>0</revealidentities>
    <attemptreopenmethod>untilpass</attemptreopenmethod>
    <maxattempts>1</maxattempts>
    <markingworkflow>0</markingworkflow>
    <markingallocation>0</markingallocation>
    <markinganonymous>0</markinganonymous>
    <preventsubmissionnotingroup>0</preventsubmissionnotingroup>
    <activity></activity>
    <activityformat>1</activityformat>
    <timelimit>0</timelimit>
    <submissionattachments>0</submissionattachments>
    <userflags>
    </userflags>
    <submissions>
    </submissions>
    <grades>
    </grades>
    <plugin_configs>
      <plugin_config id="' . ($pcId + 1) . '">
        <plugin>onlinetext</plugin>
        <subtype>assignsubmission</subtype>
        <name>enabled</name>
        <value>0</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 2) . '">
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 3) . '">
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>maxfilesubmissions</name>
        <value>20</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 4) . '">
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>maxsubmissionsizebytes</name>
        <value>0</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 5) . '">
        <plugin>file</plugin>
        <subtype>assignsubmission</subtype>
        <name>filetypeslist</name>
        <value></value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 6) . '">
        <plugin>comments</plugin>
        <subtype>assignsubmission</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 7) . '">
        <plugin>comments</plugin>
        <subtype>assignfeedback</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 8) . '">
        <plugin>comments</plugin>
        <subtype>assignfeedback</subtype>
        <name>commentinline</name>
        <value>0</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 9) . '">
        <plugin>editpdf</plugin>
        <subtype>assignfeedback</subtype>
        <name>enabled</name>
        <value>1</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 10) . '">
        <plugin>offline</plugin>
        <subtype>assignfeedback</subtype>
        <name>enabled</name>
        <value>0</value>
      </plugin_config>
      <plugin_config id="' . ($pcId + 11) . '">
        <plugin>file</plugin>
        <subtype>assignfeedback</subtype>
        <name>enabled</name>
        <value>0</value>
      </plugin_config>
    </plugin_configs>
    <overrides>
    </overrides>
  </assign>
</activity>';
        $this->writeFile($activityDir . '/assign.xml', $assignXml);
        
        // module.xml
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>assign</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>1</groupmode>
  <groupingid>0</groupingid>
  <completion>2</completion>
  <completiongradeitemnumber>0</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>1</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>';
        $this->writeFile($activityDir . '/module.xml', $moduleXml);
        
        // grades.xml
        $gradeItemId = 40000 + $activityId;
        $gradesXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
    <grade_item id="' . $gradeItemId . '">
      <categoryid>1</categoryid>
      <itemname>' . $name . '</itemname>
      <itemtype>mod</itemtype>
      <itemmodule>assign</itemmodule>
      <iteminstance>' . $assignId . '</iteminstance>
      <itemnumber>0</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber></idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>' . number_format($assignGrade, 5, '.', '') . '</grademax>
      <grademin>0.00000</grademin>
      <scaleid>$@NULL@$</scaleid>
      <outcomeid>$@NULL@$</outcomeid>
      <gradepass>0.00000</gradepass>
      <multfactor>1.00000</multfactor>
      <plusfactor>0.00000</plusfactor>
      <aggregationcoef>0.00000</aggregationcoef>
      <aggregationcoef2>1.00000</aggregationcoef2>
      <weightoverride>0</weightoverride>
      <sortorder>' . ($activityId * 2) . '</sortorder>
      <display>0</display>
      <decimals>$@NULL@$</decimals>
      <hidden>0</hidden>
      <locked>0</locked>
      <locktime>0</locktime>
      <needsupdate>0</needsupdate>
      <timecreated>' . $now . '</timecreated>
      <timemodified>' . $now . '</timemodified>
      <grade_grades>
      </grade_grades>
    </grade_item>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        $this->writeFile($activityDir . '/grades.xml', $gradesXml);
        
        // inforef.xml
        $filerefXml = '';
        if (!empty($fileIds)) {
            // Guillemets DOUBLES : les sauts de ligne doivent être RÉELS. En simple quote, PHP écrit
            // le littéral « \n » dans inforef.xml → whitespace malformé (parseurs qui échouent, dont
            // le linter du pipeline qui comptait alors 0 fichier → faux « Espace professeur vide »).
            $filerefXml = "\n  <fileref>";
            foreach ($fileIds as $fid) {
                $filerefXml .= "\n    <file>\n      <id>" . $fid . "</id>\n    </file>";
            }
            $filerefXml .= "\n  </fileref>";
        }
        $inforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>' . $filerefXml . '
  <grade_itemref>
    <grade_item>
      <id>' . $gradeItemId . '</id>
    </grade_item>
  </grade_itemref>
</inforef>';
        $this->writeFile($activityDir . '/inforef.xml', $inforefXml);
        
        // Fichiers auxiliaires
        $gradingAreaId = $assignId + 3000;
        $this->writeFile($activityDir . '/grading.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<areas>\n  <area id=\"{$gradingAreaId}\">\n    <areaname>submissions</areaname>\n    <activemethod>\$@NULL@\$</activemethod>\n    <definitions>\n    </definitions>\n  </area>\n</areas>");
        $this->writeFile($activityDir . '/grade_history.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<grade_history>\n  <grade_grades>\n  </grade_grades>\n</grade_history>");
        $this->writeFile($activityDir . '/roles.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<roles>\n  <role_overrides>\n  </role_overrides>\n  <role_assignments>\n  </role_assignments>\n</roles>");
        $this->writeFile($activityDir . '/filters.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<filters>\n  <filter_actives>\n  </filter_actives>\n  <filter_configs>\n  </filter_configs>\n</filters>");
    }
    
    private function generateResourceActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/resource_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);
        
        $contextId = $this->contextId + $activityId + 1;
        $resourceId = $activityId + 5000;
        $name = $this->xmlEncode($activity['name'] ?? 'Fichiers à distribuer');
        $now = time();
        $fileIds = [];
        
        // Gérer les fichiers joints (content)
        $editorFiles = $activity['files'] ?? [];
        
        foreach ($editorFiles as $f) {
            $fileUrl = $f['fileUrl'] ?? null;
            $fileName = $f['fileName'] ?? null;
            if (!$fileUrl || !$fileName) continue;
            
            $localPath = $this->resolveImagePath($fileUrl);
            error_log("[EXPORT-RESOURCE] fileUrl=" . substr($fileUrl, 0, 150) . " fileName=$fileName resolved=" . ($localPath ?: 'NULL') . " exists=" . ($localPath && file_exists($localPath) ? 'YES(' . filesize($localPath) . ')' : 'NO'));
            $progressLog = TMP_PATH . '/.export_progress.log';
            @file_put_contents($progressLog, date('H:i:s') . " RESOURCE: $fileName → " . ($localPath && file_exists($localPath) ? 'OK' : 'FAIL') . " url=" . substr($fileUrl, 0, 80) . "\n", FILE_APPEND | LOCK_EX);
            if ($localPath && file_exists($localPath)) {
                $contenthash = sha1_file($localPath);
                $filesize = filesize($localPath);
                $mime = $this->getMoodleMimetype($fileName);
                
                $hashPrefix = substr($contenthash, 0, 2);
                $filesSubDir = $this->exportDir . '/files/' . $hashPrefix;
                if (!is_dir($filesSubDir)) mkdir($filesSubDir, 0777, true);
                copy($localPath, $filesSubDir . '/' . $contenthash);
                
                $fid = $this->fileId++;
                $fileIds[] = $fid;
                $this->filesManifest[] = [
                    'id' => $fid,
                    'contenthash' => $contenthash,
                    'contextid' => $contextId,
                    'component' => 'mod_resource',
                    'filearea' => 'content',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $fileName,
                    'filesize' => $filesize,
                    'mimetype' => $mime,
                    'sortorder' => 1,
                    'source' => $fileName,
                    'license' => 'unknown',
                ];
                
                $this->archiveIndex[] = "files/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/{$contenthash}\tf\t{$filesize}\t" . $this->backupDate;
            }
        }
        
        // Entrée répertoire pour content
        $dirFid = $this->fileId++;
        $fileIds[] = $dirFid;
        $this->filesManifest[] = [
            'id' => $dirFid,
            'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
            'contextid' => $contextId,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => '.',
            'filesize' => 0,
            'mimetype' => '$@NULL@$',
        ];
        
        // Consigne (intro) et ses images — même traitement que partout ailleurs :
        // nom = empreinte du contenu, une seule entrée par image, IDs dans le fileref.
        $introXml = $this->inlineHtmlFiles($activity['intro'] ?? '', $contextId,
                                           'mod_resource', 'intro', $fileIds);

        // resource.xml
        $resourceXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $resourceId . '" moduleid="' . $activityId . '" modulename="resource" contextid="' . $contextId . '">
  <resource id="' . $resourceId . '">
    <name>' . $name . '</name>
    <intro>' . $this->xmlEncode($introXml) . '</intro>
    <introformat>1</introformat>
    <tobemigrated>0</tobemigrated>
    <legacyfiles>0</legacyfiles>
    <legacyfileslast>$@NULL@$</legacyfileslast>
    <display>4</display>
    <displayoptions>a:0:{}</displayoptions>
    <filterfiles>0</filterfiles>
    <revision>1</revision>
    <timemodified>' . $now . '</timemodified>
  </resource>
</activity>';
        $this->writeFile($activityDir . '/resource.xml', $resourceXml);
        
        // module.xml
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>resource</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>0</groupmode>
  <groupingid>0</groupingid>
  <completion>2</completion>
  <completiongradeitemnumber>$@NULL@$</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>1</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>';
        $this->writeFile($activityDir . '/module.xml', $moduleXml);
        
        // grades.xml (vide pour resource - pas de notes)
        $this->writeFile($activityDir . '/grades.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<activity_gradebook>\n  <grade_items>\n  </grade_items>\n  <grade_letters>\n  </grade_letters>\n</activity_gradebook>");
        
        // inforef.xml avec les file IDs
        $filerefXml = '';
        if (!empty($fileIds)) {
            $filerefXml = "\n  <fileref>";
            foreach ($fileIds as $fid) {
                $filerefXml .= "\n    <file>\n      <id>{$fid}</id>\n    </file>";
            }
            $filerefXml .= "\n  </fileref>";
        }
        $this->writeFile($activityDir . '/inforef.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<inforef>{$filerefXml}\n</inforef>");
        
        // Fichiers auxiliaires
        $this->writeFile($activityDir . '/grade_history.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<grade_history>\n  <grade_grades>\n  </grade_grades>\n</grade_history>");
        $this->writeFile($activityDir . '/roles.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<roles>\n  <role_overrides>\n  </role_overrides>\n  <role_assignments>\n  </role_assignments>\n</roles>");
        $this->writeFile($activityDir . '/filters.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<filters>\n  <filter_actives>\n  </filter_actives>\n  <filter_configs>\n  </filter_configs>\n</filters>");
    }
    
    /**
     * Réécrit un HTML de l'éditeur pour Moodle : chaque image servie par
     * serve_upload est copiée dans l'archive, déclarée dans files.xml pour la zone
     * demandée, et son URL remplacée par @@PLUGINFILE@@/<nom>.
     * Renvoie le HTML prêt à écrire ; $fileIds reçoit les IDs à mettre en fileref.
     */
    private function inlineHtmlFiles(string $html, int $contextId, string $component,
                                     string $filearea, array &$fileIds): string {
        if ($html === '') return '';
        $self = $this;
        $zone = $contextId . '|' . $component . '|' . $filearea;
        $trouve = false;

        $sortie = preg_replace_callback(
            // Le paramètre session peut précéder ou suivre file= (les deux existent
            // dans les cours déjà enregistrés) : on l'absorbe dans les deux sens.
            '/api\/editor_api\.php\?action=serve_upload(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?&(?:amp;)?file=([^"\x27<>\s&]+)(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?/',
            function ($m) use ($self, $contextId, $component, $filearea, $zone, &$fileIds, &$trouve) {
                $localFile = $self->findFileMultiPath(urldecode($m[1]));
                if (!$localFile || !file_exists($localFile)) return $m[0];

                $hash = sha1_file($localFile);

                // Même image citée deux fois dans le même texte : UNE seule entrée.
                // Deux entrées de même nom dans une zone = sauvegarde invalide, et
                // Moodle abandonne alors TOUTE la zone (toutes les images de la
                // consigne disparaissent — constaté le 17/08/2026 sur un devoir).
                if (isset($self->htmlFilesByArea[$zone][$hash])) {
                    $trouve = true;
                    return '@@PLUGINFILE@@/' . rawurlencode($self->htmlFilesByArea[$zone][$hash]);
                }

                $size = filesize($localFile);
                $mime = function_exists('mime_content_type')
                    ? (mime_content_type($localFile) ?: 'image/png') : 'image/png';

                $nom = $self->moodleFileName(urldecode($m[1]), $hash, $mime);

                $prefix = substr($hash, 0, 2);
                $dest = $self->exportDir . '/files/' . $prefix;
                if (!is_dir($dest)) mkdir($dest, 0777, true);
                if (!file_exists($dest . '/' . $hash)) copy($localFile, $dest . '/' . $hash);

                $fid = $self->fileId++;
                $fileIds[] = $fid;
                $self->filesManifest[] = [
                    'id' => $fid,
                    'contenthash' => $hash,
                    'contextid' => $contextId,
                    'component' => $component,
                    'filearea' => $filearea,
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $nom,
                    'filesize' => $size,
                    'mimetype' => $mime,
                    'source' => $nom,
                ];
                $self->archiveIndex[] = "files/\td\t0\t?";
                $self->archiveIndex[] = "files/{$prefix}/\td\t0\t?";
                $self->archiveIndex[] = "files/{$prefix}/{$hash}\tf\t{$size}\t" . $self->backupDate;

                $self->htmlFilesByArea[$zone][$hash] = $nom;
                $trouve = true;
                return '@@PLUGINFILE@@/' . rawurlencode($nom);
            },
            $html
        );

        // Entrée de dossier « . » : Moodle l'attend dès qu'une zone contient un
        // fichier, et une seule fois par zone.
        if ($trouve && !isset($this->htmlDirEntryDone[$zone])) {
            $this->htmlDirEntryDone[$zone] = true;
            $dirFid = $this->fileId++;
            $fileIds[] = $dirFid;
            $this->filesManifest[] = [
                'id' => $dirFid,
                'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'contextid' => $contextId,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => '.',
                'filesize' => 0,
                'mimetype' => '$@NULL@$',
            ];
        }

        return $sortie;
    }

    /**
     * Bloc <fileref> d'un inforef.xml. Moodle ne restaure QUE les fichiers cités ici :
     * une image absente de cette liste n'arrive jamais sur Éléa, même présente dans
     * files.xml et dans l'archive.
     */
    /**
     * Nom d'un fichier tel que Moodle/Éléa le nomme quand l'image vient d'un éditeur :
     * l'empreinte du contenu + l'extension. Unique par construction, et deux fois la
     * même image donne le même nom (donc une seule entrée dans files.xml).
     * Les noms techniques d'elea-secours (import_…, <idDrive>_prefetch.bin) n'ont
     * aucun sens côté professeur et leur extension est parfois fausse.
     */
    private function moodleFileName(string $nomSource, string $contenthash, string $mime): string {
        $ext = strtolower(pathinfo($nomSource, PATHINFO_EXTENSION));
        $parMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                    'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        if ($ext === '' || $ext === 'bin' || strlen($ext) > 5) {
            $ext = $parMime[$mime] ?? 'png';
        }
        return $contenthash . '.' . $ext;
    }

    private function filerefXml(array $fileIds): string {
        if (empty($fileIds)) return '';
        $xml = "\n  <fileref>";
        foreach (array_unique($fileIds) as $fid) {
            $xml .= "\n    <file>\n      <id>{$fid}</id>\n    </file>";
        }
        return $xml . "\n  </fileref>";
    }

    /** Fichiers XML communs à tous les modules simples (pas de notes, pas de rôles). */
    private function writeModuleAuxFiles(string $activityDir, array $fileIds): void {
        $filerefXml = $this->filerefXml($fileIds);
        $this->writeFile($activityDir . '/inforef.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<inforef>{$filerefXml}\n</inforef>");
        $this->writeFile($activityDir . '/grades.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<activity_gradebook>\n  <grade_items>\n  </grade_items>\n  <grade_letters>\n  </grade_letters>\n</activity_gradebook>");
        $this->writeFile($activityDir . '/grade_history.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<grade_history>\n  <grade_grades>\n  </grade_grades>\n</grade_history>");
        $this->writeFile($activityDir . '/roles.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<roles>\n  <role_overrides>\n  </role_overrides>\n  <role_assignments>\n  </role_assignments>\n</roles>");
        $this->writeFile($activityDir . '/filters.xml',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<filters>\n  <filter_actives>\n  </filter_actives>\n  <filter_configs>\n  </filter_configs>\n</filters>");
    }

    /**
     * Étiquette (module Moodle « label ») : le texte affiché directement sur la page
     * du parcours, comme le « Bonjour, faites les activités ci-dessous… » d'Éléa.
     * Tout est dans l'intro, et c'est showdescription=1 qui la fait afficher.
     */
    private function generateLabelActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/label_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);

        $contextId = $this->contextId + $activityId + 1;
        $labelId = $activityId + 6000;
        $now = time();
        $fileIds = [];

        // Le texte de l'étiquette : « intro » dans le .mbz. On tolère « content »
        // (chaîne) pour les brouillons qui l'auraient rangé là.
        $texte = $activity['intro'] ?? '';
        if ($texte === '' && isset($activity['content']) && is_string($activity['content'])) {
            $texte = $activity['content'];
        }
        $texte = $this->inlineHtmlFiles($texte, $contextId, 'mod_label', 'intro', $fileIds);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $labelId . '" moduleid="' . $activityId . '" modulename="label" contextid="' . $contextId . '">
  <label id="' . $labelId . '">
    <name>' . $this->xmlEncode($activity['name'] ?? 'Étiquette') . '</name>
    <intro>' . $this->xmlEncode($texte) . '</intro>
    <introformat>1</introformat>
    <timemodified>' . $now . '</timemodified>
  </label>
</activity>';
        $this->writeFile($activityDir . '/label.xml', $xml);

        $this->writeFile($activityDir . '/module.xml', '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>label</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>0</groupmode>
  <groupingid>0</groupingid>
  <completion>0</completion>
  <completiongradeitemnumber>$@NULL@$</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>0</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>1</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>');

        $this->writeModuleAuxFiles($activityDir, $fileIds);
    }

    /**
     * Page (module Moodle « page ») : une page de contenu HTML, ouverte depuis le
     * parcours. Le corps est dans <content> (zone de fichiers « content »),
     * l'intro sert de description facultative.
     */
    private function generatePageActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/page_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);

        $contextId = $this->contextId + $activityId + 1;
        $pageId = $activityId + 7000;
        $now = time();
        $fileIds = [];

        $corps = $activity['content'] ?? '';
        if (is_array($corps)) $corps = '';   // une page n'a jamais de contenu H5P
        $corps = $this->inlineHtmlFiles($corps, $contextId, 'mod_page', 'content', $fileIds);
        $intro = $this->inlineHtmlFiles($activity['intro'] ?? '', $contextId, 'mod_page', 'intro', $fileIds);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $pageId . '" moduleid="' . $activityId . '" modulename="page" contextid="' . $contextId . '">
  <page id="' . $pageId . '">
    <name>' . $this->xmlEncode($activity['name'] ?? 'Page') . '</name>
    <intro>' . $this->xmlEncode($intro) . '</intro>
    <introformat>1</introformat>
    <content>' . $this->xmlEncode($corps) . '</content>
    <contentformat>1</contentformat>
    <legacyfiles>0</legacyfiles>
    <legacyfileslast>$@NULL@$</legacyfileslast>
    <display>5</display>
    <displayoptions>a:2:{s:10:"printintro";s:1:"0";s:17:"printlastmodified";s:1:"0";}</displayoptions>
    <revision>1</revision>
    <timemodified>' . $now . '</timemodified>
  </page>
</activity>';
        $this->writeFile($activityDir . '/page.xml', $xml);

        $this->writeFile($activityDir . '/module.xml', '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>page</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>0</groupmode>
  <groupingid>0</groupingid>
  <completion>2</completion>
  <completiongradeitemnumber>$@NULL@$</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>1</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>');

        $this->writeModuleAuxFiles($activityDir, $fileIds);
    }

    private function generateH5pActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/hvp_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);
        
        $contextId = $this->contextId + $activityId + 1;
        $hvpId = $activityId + 1000;
        
        // Construire le contenu H5P
        $h5pContent = $this->buildH5pContent($activity);
        // CORRECTION CRITIQUE: Enrichir avec subContentId et metadata
        $this->enrichH5pContent($h5pContent);
        
        // Traiter les fichiers embarqués (images, etc.) AVANT l'encodage JSON
        // Cela évite les problèmes d'échappement de slashes
        $activityFileIds = [];
        
        // Pré-traiter les rotations d'images (crée des copies pivotées, met à jour le JSON)
        $this->preprocessImageRotations($h5pContent, $contextId, $hvpId, $activityFileIds);
        
        $this->processFilesInArray($h5pContent, $contextId, $hvpId, $activityFileIds);
        
        // TOUJOURS créer l'entrée répertoire racine "/" pour cette activité H5P
        // Moodle en a besoin même s'il n'y a pas de fichiers embarqués
        $this->ensureRootDirectoryEntry($contextId, $hvpId, $activityFileIds);

        // Consigne affichée au-dessus de l'activité : elle peut contenir des images.
        // Sans ce traitement, l'URL serve_upload d'elea-secours restait telle quelle
        // dans le .mbz → image absente sur Éléa (alors qu'elle s'affiche encore ici).
        $introHvp = $this->inlineHtmlFiles($activity['intro'] ?? '', $contextId,
                                           'mod_hvp', 'intro', $activityFileIds);

        // CEINTURE DE SÉCURITÉ : le validateur H5P de Moodle/Éléa (PHP 8) plante fatalement
        // (« Attempt to assign property "path" on null » dans _validateFilelike) sur toute
        // propriété média (image/video/audio/file/files) à valeur null explicite → activité 404.
        // On retire donc récursivement ces propriétés null avant l'encodage du json_content.
        $this->stripNullMediaProperties($h5pContent);

        // Encoder le JSON au format H5P/Moodle :
        // - PAS de JSON_UNESCAPED_UNICODE : les caractères accentués doivent être \u00e9
        // - PAS de JSON_UNESCAPED_SLASHES : les / doivent être \/
        $jsonContent = json_encode($h5pContent);
        
        // Déterminer le type H5P
        $h5pType = $activity['h5pType'] ?? 'CoursePresentation';
        $machineName = 'H5P.' . $h5pType;
        
        // Version spécifique à chaque type H5P (référence Éléa)
        $h5pVersions = [
            'CoursePresentation' => ['major' => 1, 'minor' => 26],
            'InteractiveVideo'   => ['major' => 1, 'minor' => 27],
            'MultiChoice'        => ['major' => 1, 'minor' => 16],
            'TrueFalse'          => ['major' => 1, 'minor' => 8],
            'Blanks'             => ['major' => 1, 'minor' => 14],
            'Dialogcards'        => ['major' => 1, 'minor' => 9],
            'DialogCards'        => ['major' => 1, 'minor' => 9],
            'DragText'           => ['major' => 1, 'minor' => 10],
            'FindTheWords'       => ['major' => 1, 'minor' => 4],
            'DragQuestion'       => ['major' => 1, 'minor' => 14],
            'SingleChoiceSet'    => ['major' => 1, 'minor' => 11],
            'QuestionSet'        => ['major' => 1, 'minor' => 20],
            'ThreeImage'         => ['major' => 0, 'minor' => 5],
            'MultiMediaChoice'   => ['major' => 0, 'minor' => 3],
            'GameMap'            => ['major' => 1, 'minor' => 2],
            'ImageSequencing'    => ['major' => 1, 'minor' => 1],
            'MemoryGame'         => ['major' => 1, 'minor' => 3],
            'ImageMultipleHotspotQuestion' => ['major' => 1, 'minor' => 0],
        ];
        $version = $h5pVersions[$h5pType] ?? ['major' => 1, 'minor' => 0];
        
        // Normaliser le machine_name (Éléa utilise Dialogcards, pas DialogCards)
        if ($h5pType === 'DialogCards') {
            $machineName = 'H5P.Dialogcards';
        }
        
        // 1. hvp.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $hvpId . '" moduleid="' . $activityId . '" modulename="hvp" contextid="' . $contextId . '">
  <hvp id="' . $hvpId . '">
    <name>' . $this->xmlEncode($activity['name'] ?? 'Activité H5P') . '</name>
    <machine_name>' . $machineName . '</machine_name>
    <major_version>' . $version['major'] . '</major_version>
    <minor_version>' . $version['minor'] . '</minor_version>
    <intro>' . $this->xmlEncode($introHvp) . '</intro>
    <introformat>1</introformat>
    <json_content>' . $this->xmlEncodeJson($jsonContent) . '</json_content>
    <embed_type>div</embed_type>
    <disable>15</disable>
    <content_type>$@NULL@$</content_type>
    <source>$@NULL@$</source>
    <year_from>$@NULL@$</year_from>
    <year_to>$@NULL@$</year_to>
    <license_version>$@NULL@$</license_version>
    <changes>[]</changes>
    <license_extras>$@NULL@$</license_extras>
    <author_comments>$@NULL@$</author_comments>
    <slug>' . $this->slugify($activity['name'] ?? 'activite') . '</slug>
    <timecreated>' . $this->backupDate . '</timecreated>
    <timemodified>' . $this->backupDate . '</timemodified>
    <authors>[]</authors>
    <license>U</license>
    <completionpass>0</completionpass>
    <content_user_data>
    </content_user_data>
  </hvp>
</activity>';
        $this->writeFile($activityDir . '/hvp.xml', $xml);
        
        // 2. module.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024120900">
  <modulename>hvp</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $this->backupDate . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>1</groupmode>
  <groupingid>0</groupingid>
  <completion>2</completion>
  <completiongradeitemnumber>0</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>1</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>';
        $this->writeFile($activityDir . '/module.xml', $xml);
        
        // 3. grades.xml
        $gradeItemId = $this->gradeItemId++;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
    <grade_item id="' . $gradeItemId . '">
      <categoryid>' . $this->gradeCategoryId . '</categoryid>
      <itemname>' . $this->xmlEncode($activity['name'] ?? 'Activité') . '</itemname>
      <itemtype>mod</itemtype>
      <itemmodule>hvp</itemmodule>
      <iteminstance>' . $hvpId . '</iteminstance>
      <itemnumber>0</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber>$@NULL@$</idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>10.00000</grademax>
      <grademin>0.00000</grademin>
      <scaleid>$@NULL@$</scaleid>
      <outcomeid>$@NULL@$</outcomeid>
      <gradepass>0.00000</gradepass>
      <multfactor>1.00000</multfactor>
      <plusfactor>0.00000</plusfactor>
      <aggregationcoef>0.00000</aggregationcoef>
      <aggregationcoef2>1.00000</aggregationcoef2>
      <weightoverride>0</weightoverride>
      <sortorder>' . ($gradeItemId + 1) . '</sortorder>
      <display>0</display>
      <decimals>$@NULL@$</decimals>
      <hidden>0</hidden>
      <locked>0</locked>
      <locktime>0</locktime>
      <needsupdate>0</needsupdate>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <grade_grades>
      </grade_grades>
    </grade_item>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        $this->writeFile($activityDir . '/grades.xml', $xml);
        
        // 4. filters.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>';
        $this->writeFile($activityDir . '/filters.xml', $xml);
        
        // 5. grade_history.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        $this->writeFile($activityDir . '/grade_history.xml', $xml);
        
        // 6. inforef.xml (avec références aux fichiers)
        $fileRefXml = '';
        if (!empty($activityFileIds)) {
            $fileRefXml = "\n  <fileref>";
            foreach ($activityFileIds as $fid) {
                $fileRefXml .= "\n    <file>\n      <id>{$fid}</id>\n    </file>";
            }
            $fileRefXml .= "\n  </fileref>";
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>' . $fileRefXml . '
  <grade_itemref>
    <grade_item>
      <id>' . $gradeItemId . '</id>
    </grade_item>
  </grade_itemref>
</inforef>';
        $this->writeFile($activityDir . '/inforef.xml', $xml);
        
        // 7. roles.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        $this->writeFile($activityDir . '/roles.xml', $xml);
    }
    
    /**
     * Génère une activité Quiz Moodle native
     */
    private function generateQuizActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/quiz_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);
        
        $contextId = $this->contextId + $activityId + 1;
        $quizId = $activityId + 2000;
        $content = $activity['content'] ?? [];
        
        $name = $this->xmlEncode($activity['name'] ?? 'Quiz');
        $intro = $content['intro'] ?? ($activity['intro'] ?? '');
        if (!empty($intro) && strpos($intro, '<') === false) {
            $intro = '<p>' . htmlspecialchars($intro) . '</p>';
        }
        // Images de la consigne : copiées dans l'archive et référencées, sinon l'URL
        // elea-secours restait incrustée et l'image manquait sur Éléa.
        $quizFileIds = [];
        $intro = $this->inlineHtmlFiles($intro, $contextId, 'mod_quiz', 'intro', $quizFileIds);
        $attemptsNumber = $content['attempts_number'] ?? 1;
        $preferredBehaviour = $content['preferredbehaviour'] ?? 'deferredfeedback';
        $questionsPerPage = $content['questionsperpage'] ?? 1;
        $shuffleAnswers = $content['shuffleanswers'] ?? 1;
        $navMethod = $content['navmethod'] ?? 'free';
        $grade = number_format($content['grade'] ?? 10, 5, '.', '');
        
        $questionInstancesXml = '';
        $sumGrades = $grade;
        $quizType = $activity['quizType'] ?? '';
        
        // Gérer les questions ddimageortext
        if ($quizType === 'ddimageortext') {
            $defaultMark = $content['defaultmark'] ?? 1;
            $sumGrades = number_format($defaultMark, 5, '.', '');
            $bankEntryId = $this->bankEntryId++;
            // Réserver le questionId ici pour les fichiers (bgimage/dragimage)
            // et le passer à addQuestionToBank pour éviter un double incrément
            $questionId = $this->questionId; // NE PAS incrémenter ici
            $courseContextId = $this->contextId;
            
            // Résoudre les fichiers (bgimage, dragimage)
            $drags = $content['drags'] ?? [];
            $drops = $content['drops'] ?? [];
            $bgUrl = $content['backgroundUrl'] ?? null;
            $bgName = $content['bgImageName'] ?? 'background.png';
            $bgLocalPath = null;
            
            if ($bgUrl) {
                $bgLocalPath = $this->resolveEditorUrl($bgUrl);
                if ($bgLocalPath) {
                    $this->logExport("[DDI] Background OK: $bgName ← " . substr($bgUrl, 0, 120));
                } else {
                    $this->logExport("[DDI] Background FAILED: " . substr($bgUrl, 0, 200));
                }
            }

            // Si le fond est étendu (mode auto), le recadrer à sourceWidth avant export
            $croppedBgTempPath = null;
            $sourceWidth = $content['sourceWidth'] ?? null;
            if ($sourceWidth && $bgLocalPath) {
                $croppedBgTempPath = $this->cropDdiBackground($bgLocalPath, (int)$sourceWidth);
                if ($croppedBgTempPath) $bgLocalPath = $croppedBgTempPath;
            }

            // Images des drags : collecter les chemins
            $this->logExport("[DDI] Exporting " . count($drags) . " drags, sessionId=" . $this->editorSessionId);
            $dragLocalPaths = [];
            $dragImgNames = [];
            foreach ($drags as $dIdx => $drag) {
                $dragImgUrl = $drag['imageUrl'] ?? null;
                $dragImgName = $drag['imageName'] ?? ('drag_' . ($dIdx + 1) . '.png');
                $dragImgNames[] = $dragImgName;
                $localPath = null;
                if ($dragImgUrl) {
                    $localPath = $this->resolveEditorUrl($dragImgUrl);
                    if ($localPath) {
                        $this->logExport("[DDI] Drag $dIdx OK: $dragImgName ← " . basename($localPath));
                    } else {
                        $this->logExport("[DDI] Drag $dIdx FAILED for URL: " . substr($dragImgUrl, 0, 200));
                    }
                } else {
                    $this->logExport("[DDI] Drag $dIdx: text-only (label=" . ($drag['label'] ?? '?') . ")");
                }
                $dragLocalPaths[] = $localPath;
            }

            // Adapter : agrandir le fond et les positions des drops, uniformiser les tailles
            [$adaptedBgPath, $bgScale, $adaptedDragPaths] = $this->adaptDdiForExport($dragLocalPaths, $drops, $bgLocalPath);

            // Construire la question et l'ajouter à la banque
            // buildDdimageortextQuestionXml assigne des drag IDs globaux uniques dans _lastDdiDragIds
            $this->_lastDdiDragIds = [];
            $q = [
                'qtype' => 'ddimageortext',
                'name' => $activity['name'] ?? 'Glisser-Déposer',
                'questiontext' => $content['questiontext'] ?? '<p>Compléter le schéma</p>',
                'defaultmark' => $defaultMark,
                'shuffleanswers' => $shuffleAnswers,
                'drags' => $drags,
                'drops' => $drops,
            ];
            $this->addQuestionToBank($q, $bankEntryId, $courseContextId, $this->courseId);

            // Archiver le fond (adapté si nécessaire)
            $finalBgPath = $adaptedBgPath ?: $bgLocalPath;
            if ($finalBgPath) {
                $this->addQuizFileToArchive($finalBgPath, $courseContextId, $questionId, 'qtype_ddimageortext', 'bgimage', $bgName);
                if ($adaptedBgPath && $adaptedBgPath !== $bgLocalPath) @unlink($adaptedBgPath);
                if ($croppedBgTempPath) @unlink($croppedBgTempPath);
            }
            
            // Archiver les images drag avec les IDs globaux
            $dragIds = $this->_lastDdiDragIds;
            foreach ($adaptedDragPaths as $dIdx => $dPath) {
                if ($dPath && isset($dragIds[$dIdx])) {
                    $this->addQuizFileToArchive($dPath, $courseContextId, $dragIds[$dIdx], 'qtype_ddimageortext', 'dragimage', $dragImgNames[$dIdx]);
                    if ($dPath !== $dragLocalPaths[$dIdx]) @unlink($dPath);
                }
            }
            
            // Question instance
            $instanceId = $this->questionInstanceId++;
            $refId = $this->questionRefId++;
            $maxMark = number_format($defaultMark, 7, '.', '');
            
            $questionInstancesXml = '
      <question_instance id="' . $instanceId . '">
        <quizid>' . $quizId . '</quizid>
        <slot>1</slot>
        <page>1</page>
        <displaynumber>$@NULL@$</displaynumber>
        <requireprevious>0</requireprevious>
        <maxmark>' . $maxMark . '</maxmark>
        <quizgradeitemid>$@NULL@$</quizgradeitemid>
        <question_reference id="' . $refId . '">
          <usingcontextid>' . $contextId . '</usingcontextid>
          <component>mod_quiz</component>
          <questionarea>slot</questionarea>
          <questionbankentryid>' . $bankEntryId . '</questionbankentryid>
          <version>$@NULL@$</version>
        </question_reference>
      </question_instance>';
        }
        
        // 1. quiz.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $quizId . '" moduleid="' . $activityId . '" modulename="quiz" contextid="' . $contextId . '">
  <quiz id="' . $quizId . '">
    <name>' . $name . '</name>
    <intro>' . $this->xmlEncode($intro) . '</intro>
    <introformat>1</introformat>
    <timeopen>0</timeopen>
    <timeclose>0</timeclose>
    <timelimit>0</timelimit>
    <overduehandling>autosubmit</overduehandling>
    <graceperiod>0</graceperiod>
    <preferredbehaviour>' . $preferredBehaviour . '</preferredbehaviour>
    <canredoquestions>0</canredoquestions>
    <attempts_number>' . $attemptsNumber . '</attempts_number>
    <attemptonlast>0</attemptonlast>
    <grademethod>1</grademethod>
    <decimalpoints>2</decimalpoints>
    <questiondecimalpoints>-1</questiondecimalpoints>
    <reviewattempt>69888</reviewattempt>
    <reviewcorrectness>4352</reviewcorrectness>
    <reviewmaxmarks>69904</reviewmaxmarks>
    <reviewmarks>4352</reviewmarks>
    <reviewspecificfeedback>4352</reviewspecificfeedback>
    <reviewgeneralfeedback>4352</reviewgeneralfeedback>
    <reviewrightanswer>4352</reviewrightanswer>
    <reviewoverallfeedback>4352</reviewoverallfeedback>
    <questionsperpage>' . $questionsPerPage . '</questionsperpage>
    <navmethod>' . $navMethod . '</navmethod>
    <shuffleanswers>' . $shuffleAnswers . '</shuffleanswers>
    <sumgrades>' . $grade . '</sumgrades>
    <grade>' . $grade . '</grade>
    <timecreated>' . $this->backupDate . '</timecreated>
    <timemodified>' . $this->backupDate . '</timemodified>
    <password></password>
    <subnet></subnet>
    <browsersecurity>-</browsersecurity>
    <delay1>0</delay1>
    <delay2>0</delay2>
    <showuserpicture>0</showuserpicture>
    <showblocks>0</showblocks>
    <completionattemptsexhausted>0</completionattemptsexhausted>
    <completionminattempts>0</completionminattempts>
    <allowofflineattempts>0</allowofflineattempts>
    <subplugin_quizaccess_seb_quiz>
    </subplugin_quizaccess_seb_quiz>
    <quiz_grade_items>
    </quiz_grade_items>
    <question_instances>' . $questionInstancesXml . '
    </question_instances>
    <sections>
      <section id="' . ($quizId + 100) . '">
        <firstslot>1</firstslot>
        <heading></heading>
        <shufflequestions>0</shufflequestions>
      </section>
    </sections>
    <feedbacks>
      <feedback id="' . ($quizId + 200) . '">
        <feedbacktext></feedbacktext>
        <feedbacktextformat>1</feedbacktextformat>
        <mingrade>0.00000</mingrade>
        <maxgrade>11.00000</maxgrade>
      </feedback>
    </feedbacks>
    <overrides>
    </overrides>
    <grades>
    </grades>
    <attempts>
    </attempts>
  </quiz>
</activity>';
        $this->writeFile($activityDir . '/quiz.xml', $xml);
        
        // 2. module.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>quiz</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $this->backupDate . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>1</groupmode>
  <groupingid>0</groupingid>
  <completion>2</completion>
  <completiongradeitemnumber>0</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>1</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>';
        $this->writeFile($activityDir . '/module.xml', $xml);
        
        // 3. Fichiers auxiliaires
        $gradeItemId = $this->fileId++;
        
        // grades.xml avec grade_item (requis pour Moodle)
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
    <grade_item id="' . $gradeItemId . '">
      <categoryid>1</categoryid>
      <itemname>' . $name . '</itemname>
      <itemtype>mod</itemtype>
      <itemmodule>quiz</itemmodule>
      <iteminstance>' . $quizId . '</iteminstance>
      <itemnumber>0</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber>$@NULL@$</idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>10.00000</grademax>
      <grademin>0.00000</grademin>
      <scaleid>$@NULL@$</scaleid>
      <outcomeid>$@NULL@$</outcomeid>
      <gradepass>0.00000</gradepass>
      <multfactor>1.00000</multfactor>
      <plusfactor>0.00000</plusfactor>
      <aggregationcoef>0.00000</aggregationcoef>
      <aggregationcoef2>1.00000</aggregationcoef2>
      <weightoverride>0</weightoverride>
      <sortorder>1</sortorder>
      <display>0</display>
      <decimals>$@NULL@$</decimals>
      <hidden>0</hidden>
      <locked>0</locked>
      <locktime>0</locktime>
      <needsupdate>0</needsupdate>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <grade_grades>
      </grade_grades>
    </grade_item>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        $this->writeFile($activityDir . '/grades.xml', $xml);
        
        // grade_history.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        $this->writeFile($activityDir . '/grade_history.xml', $xml);
        
        // inforef.xml (sera réécrit par updateInforefsWithQuestionCategories)
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <grade_itemref>
    <grade_item>
      <id>' . $gradeItemId . '</id>
    </grade_item>
  </grade_itemref>' . $this->filerefXml($quizFileIds) . '
</inforef>';
        $this->writeFile($activityDir . '/inforef.xml', $xml);

        // filters.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>';
        $this->writeFile($activityDir . '/filters.xml', $xml);
        
        // roles.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        $this->writeFile($activityDir . '/roles.xml', $xml);
        
        // Stocker pour mise à jour post-questions.xml (quiz-level categories + inforef)
        $this->quizActivityDirs[] = [
            'dir' => $activityDir,
            'gradeItemId' => $gradeItemId,
            'contextId' => $contextId,
            'activityId' => $activityId,
            'fileIds' => $quizFileIds,
        ];
    }
    
    /**
     * Détecte si un QuestionSet utilise le nouveau format (qtype) vs ancien H5P
     */
    private function isNewFormatQuestionSet($activity) {
        $questions = $activity['content']['questions'] ?? [];
        if (empty($questions)) return true; // Vide = nouveau format par défaut
        // Si la première question a un qtype, c'est le nouveau format
        return isset($questions[0]['qtype']);
    }
    
    /**
     * Génère une activité Évaluation (QuestionSet) en tant que quiz Moodle natif
     * avec banque de questions complète
     */
    private function generateEvalQuizActivity($activityId, $sectionId, $sectionNumber, $activity) {
        $activityDir = 'activities/quiz_' . $activityId;
        mkdir($this->exportDir . '/' . $activityDir, 0777, true);
        
        $contextId = $this->contextId + $activityId + 1;
        $quizId = $activityId + 2000;
        $content = $activity['content'] ?? [];
        $settings = $content['settings'] ?? [];
        $questions = $content['questions'] ?? [];
        
        $name = $this->xmlEncode($activity['name'] ?? 'Évaluation');
        // Consigne de l'évaluation : elle était purement perdue (<intro></intro> en dur),
        // images comprises.
        $introEval = $activity['intro'] ?? ($content['intro'] ?? '');
        $quizFileIds = [];
        $introEval = $this->inlineHtmlFiles($introEval, $contextId, 'mod_quiz', 'intro', $quizFileIds);
        $attemptsNumber = $settings['attempts_number'] ?? 1;
        $preferredBehaviour = $settings['preferredbehaviour'] ?? 'deferredfeedback';
        $questionsPerPage = $settings['questionsperpage'] ?? 1;
        $shuffleAnswers = $settings['shuffleanswers'] ?? 1;
        $grade = number_format($settings['grade'] ?? 10, 5, '.', '');
        
        // Calculer sumgrades (somme des points des questions)
        $sumGrades = 0;
        foreach ($questions as $q) {
            $sumGrades += ($q['defaultmark'] ?? 1);
        }
        $sumGradesStr = number_format($sumGrades, 5, '.', '');
        
        // Générer les questions dans la banque et les instances
        $questionInstancesXml = '';
        $slot = 1;
        foreach ($questions as $q) {
            $bankEntryId = $this->questionBankEntryId++;
            $maxMark = number_format($q['defaultmark'] ?? 1, 7, '.', '');
            $page = ($questionsPerPage > 0) ? (int)ceil($slot / $questionsPerPage) : 1;
            
            // Pour les questions ddimageortext : résoudre les fichiers,
            // puis addQuestionToBank assigne les IDs globaux uniques via buildDdimageortextQuestionXml,
            // et ensuite on archive les fichiers avec ces IDs.
            $ddiFileData = null;
            if (($q['qtype'] ?? '') === 'ddimageortext') {
                $courseContextId = $this->contextId;
                $upcomingQuestionId = $this->questionId; // Sera utilisé par addQuestionToBank
                
                // Résoudre l'image de fond
                $bgUrl = $q['backgroundUrl'] ?? null;
                $bgName = $q['bgImageName'] ?? 'background.png';
                $bgLocalPath = null;
                if ($bgUrl) {
                    $bgLocalPath = $this->resolveEditorUrl($bgUrl);
                    if ($bgLocalPath) {
                        $this->logExport("[EVAL-DDI] Background OK: $bgName ← " . substr($bgUrl, 0, 120));
                    } else {
                        $this->logExport("[EVAL-DDI] Background FAILED: " . substr($bgUrl, 0, 200));
                    }
                }

                // Si le fond est étendu (mode auto), le recadrer à sourceWidth avant export
                $croppedBgTempPath = null;
                $sourceWidth = $q['sourceWidth'] ?? null;
                if ($sourceWidth && $bgLocalPath) {
                    $croppedBgTempPath = $this->cropDdiBackground($bgLocalPath, (int)$sourceWidth);
                    if ($croppedBgTempPath) $bgLocalPath = $croppedBgTempPath;
                }

                // Résoudre les images des drags
                $drags = $q['drags'] ?? [];
                $qDrops = $q['drops'] ?? [];
                $this->logExport("[EVAL-DDI] Exporting " . count($drags) . " drags, sessionId=" . $this->editorSessionId);
                $dragLocalPaths = [];
                $dragImgNames = [];
                foreach ($drags as $dIdx => $drag) {
                    $dragImgUrl = $drag['imageUrl'] ?? null;
                    $dragImgName = $drag['imageName'] ?? ('drag_' . ($dIdx + 1) . '.png');
                    $dragImgNames[] = $dragImgName;
                    $localPath = null;
                    if ($dragImgUrl) {
                        $localPath = $this->resolveEditorUrl($dragImgUrl);
                        if ($localPath) {
                            $this->logExport("[EVAL-DDI] Drag $dIdx OK: $dragImgName ← " . basename($localPath));
                        } else {
                            $this->logExport("[EVAL-DDI] Drag $dIdx FAILED for URL: " . substr($dragImgUrl, 0, 200));
                        }
                    } else {
                        $this->logExport("[EVAL-DDI] Drag $dIdx: text-only (label=" . ($drag['label'] ?? '?') . ")");
                    }
                    $dragLocalPaths[] = $localPath;
                }

                // Adapter le fond et uniformiser les images
                [$adaptedBgPath, $bgScale, $adaptedDragPaths] = $this->adaptDdiForExport($dragLocalPaths, $qDrops, $bgLocalPath);
                $q['drops'] = $qDrops;

                // Stocker pour archivage APRÈS addQuestionToBank
                $ddiFileData = [
                    'bgLocalPath' => $bgLocalPath,
                    'adaptedBgPath' => $adaptedBgPath,
                    'croppedBgTempPath' => $croppedBgTempPath,
                    'bgName' => $bgName,
                    'adaptedDragPaths' => $adaptedDragPaths,
                    'dragLocalPaths' => $dragLocalPaths,
                    'dragImgNames' => $dragImgNames,
                    'questionId' => $upcomingQuestionId,
                    'contextId' => $courseContextId,
                ];
            }

            // Ajouter à la banque de questions (contexte COURS, pas quiz)
            // Pour DDI, ceci appelle buildDdimageortextQuestionXml qui assigne les drag IDs uniques
            $this->_lastDdiDragIds = [];
            $this->addQuestionToBank($q, $bankEntryId, $this->contextId, $this->courseId);

            // Archiver les fichiers DDI avec les IDs assignés par buildDdimageortextQuestionXml
            if ($ddiFileData) {
                $d = $ddiFileData;
                // Fond
                $finalBgPath = $d['adaptedBgPath'] ?: $d['bgLocalPath'];
                if ($finalBgPath) {
                    $this->addQuizFileToArchive($finalBgPath, $d['contextId'], $d['questionId'], 'qtype_ddimageortext', 'bgimage', $d['bgName']);
                    if ($d['adaptedBgPath'] && $d['adaptedBgPath'] !== $d['bgLocalPath']) @unlink($d['adaptedBgPath']);
                    if ($d['croppedBgTempPath'] ?? null) @unlink($d['croppedBgTempPath']);
                }
                // Drags : utiliser les IDs globaux assignés par buildDdimageortextQuestionXml
                $dragIds = $this->_lastDdiDragIds;
                foreach ($d['adaptedDragPaths'] as $dIdx => $dPath) {
                    if ($dPath && isset($dragIds[$dIdx])) {
                        $this->addQuizFileToArchive($dPath, $d['contextId'], $dragIds[$dIdx], 'qtype_ddimageortext', 'dragimage', $d['dragImgNames'][$dIdx]);
                        if ($dPath !== $d['dragLocalPaths'][$dIdx]) @unlink($dPath);
                    }
                }
            }
            
            // Question instance dans quiz.xml
            $instanceId = $this->questionInstanceId++;
            $refId = $this->questionRefId++;
            
            $questionInstancesXml .= '
      <question_instance id="' . $instanceId . '">
        <quizid>' . $quizId . '</quizid>
        <slot>' . $slot . '</slot>
        <page>' . $page . '</page>
        <displaynumber>$@NULL@$</displaynumber>
        <requireprevious>0</requireprevious>
        <maxmark>' . $maxMark . '</maxmark>
        <quizgradeitemid>$@NULL@$</quizgradeitemid>
        <question_reference id="' . $refId . '">
          <usingcontextid>' . $contextId . '</usingcontextid>
          <component>mod_quiz</component>
          <questionarea>slot</questionarea>
          <questionbankentryid>' . $bankEntryId . '</questionbankentryid>
          <version>$@NULL@$</version>
        </question_reference>
      </question_instance>';
            $slot++;
        }
        
        // 1. quiz.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $quizId . '" moduleid="' . $activityId . '" modulename="quiz" contextid="' . $contextId . '">
  <quiz id="' . $quizId . '">
    <name>' . $name . '</name>
    <intro>' . $this->xmlEncode($introEval) . '</intro>
    <introformat>1</introformat>
    <timeopen>0</timeopen>
    <timeclose>0</timeclose>
    <timelimit>0</timelimit>
    <overduehandling>autosubmit</overduehandling>
    <graceperiod>0</graceperiod>
    <preferredbehaviour>' . $preferredBehaviour . '</preferredbehaviour>
    <canredoquestions>0</canredoquestions>
    <attempts_number>' . $attemptsNumber . '</attempts_number>
    <attemptonlast>0</attemptonlast>
    <grademethod>1</grademethod>
    <decimalpoints>2</decimalpoints>
    <questiondecimalpoints>-1</questiondecimalpoints>
    <reviewattempt>69888</reviewattempt>
    <reviewcorrectness>4352</reviewcorrectness>
    <reviewmaxmarks>69904</reviewmaxmarks>
    <reviewmarks>4352</reviewmarks>
    <reviewspecificfeedback>4352</reviewspecificfeedback>
    <reviewgeneralfeedback>4352</reviewgeneralfeedback>
    <reviewrightanswer>4352</reviewrightanswer>
    <reviewoverallfeedback>4352</reviewoverallfeedback>
    <questionsperpage>' . $questionsPerPage . '</questionsperpage>
    <navmethod>free</navmethod>
    <shuffleanswers>' . $shuffleAnswers . '</shuffleanswers>
    <sumgrades>' . $sumGradesStr . '</sumgrades>
    <grade>' . $grade . '</grade>
    <timecreated>' . $this->backupDate . '</timecreated>
    <timemodified>' . $this->backupDate . '</timemodified>
    <password></password>
    <subnet></subnet>
    <browsersecurity>-</browsersecurity>
    <delay1>0</delay1>
    <delay2>0</delay2>
    <showuserpicture>0</showuserpicture>
    <showblocks>0</showblocks>
    <completionattemptsexhausted>0</completionattemptsexhausted>
    <completionminattempts>0</completionminattempts>
    <allowofflineattempts>0</allowofflineattempts>
    <subplugin_quizaccess_seb_quiz>
    </subplugin_quizaccess_seb_quiz>
    <quiz_grade_items>
    </quiz_grade_items>
    <question_instances>' . $questionInstancesXml . '
    </question_instances>
    <sections>
      <section id="' . ($quizId + 100) . '">
        <firstslot>1</firstslot>
        <heading></heading>
        <shufflequestions>0</shufflequestions>
      </section>
    </sections>
    <feedbacks>
      <feedback id="' . ($quizId + 200) . '">
        <feedbacktext></feedbacktext>
        <feedbacktextformat>1</feedbacktextformat>
        <mingrade>0.00000</mingrade>
        <maxgrade>11.00000</maxgrade>
      </feedback>
    </feedbacks>
    <overrides>
    </overrides>
    <grades>
    </grades>
    <attempts>
    </attempts>
  </quiz>
</activity>';
        $this->writeFile($activityDir . '/quiz.xml', $xml);
        
        // 2. module.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>quiz</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionNumber . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $this->backupDate . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>' . ($activity['_moduleVisible'] ?? 1) . '</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>' . ($activity['_moduleVisibleold'] ?? 1) . '</visibleold>
  <groupmode>1</groupmode>
  <groupingid>0</groupingid>
  <completion>2</completion>
  <completiongradeitemnumber>0</completiongradeitemnumber>
  <completionpassgrade>0</completionpassgrade>
  <completionview>1</completionview>
  <completionexpected>0</completionexpected>
  <availability>$@NULL@$</availability>
  <showdescription>0</showdescription>
  <downloadcontent>1</downloadcontent>
  <lang></lang>
  <tags>
  </tags>
</module>';
        $this->writeFile($activityDir . '/module.xml', $xml);
        
        // 3. grades.xml - avec grade_item complet comme le fait H5P
        $gradeItemId = $this->gradeItemId++;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
    <grade_item id="' . $gradeItemId . '">
      <categoryid>' . $this->gradeCategoryId . '</categoryid>
      <itemname>' . $name . '</itemname>
      <itemtype>mod</itemtype>
      <itemmodule>quiz</itemmodule>
      <iteminstance>' . $quizId . '</iteminstance>
      <itemnumber>0</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber>$@NULL@$</idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>' . $grade . '</grademax>
      <grademin>0.00000</grademin>
      <scaleid>$@NULL@$</scaleid>
      <outcomeid>$@NULL@$</outcomeid>
      <gradepass>0.00000</gradepass>
      <multfactor>1.00000</multfactor>
      <plusfactor>0.00000</plusfactor>
      <aggregationcoef>0.00000</aggregationcoef>
      <aggregationcoef2>1.00000</aggregationcoef2>
      <weightoverride>0</weightoverride>
      <sortorder>' . ($gradeItemId + 1) . '</sortorder>
      <display>0</display>
      <decimals>$@NULL@$</decimals>
      <hidden>0</hidden>
      <locked>0</locked>
      <locktime>0</locktime>
      <needsupdate>0</needsupdate>
      <timecreated>' . $this->backupDate . '</timecreated>
      <timemodified>' . $this->backupDate . '</timemodified>
      <grade_grades>
      </grade_grades>
    </grade_item>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        $this->writeFile($activityDir . '/grades.xml', $xml);
        
        // 4. grade_history.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        $this->writeFile($activityDir . '/grade_history.xml', $xml);
        
        // 5. filters.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>';
        $this->writeFile($activityDir . '/filters.xml', $xml);
        
        // 6. roles.xml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        $this->writeFile($activityDir . '/roles.xml', $xml);
        
        // 7. inforef.xml - sera mis à jour avec question_categoryref après generateQuestionsXml
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <grade_itemref>
    <grade_item>
      <id>' . $gradeItemId . '</id>
    </grade_item>
  </grade_itemref>' . $this->filerefXml($quizFileIds) . '
</inforef>';
        $this->writeFile($activityDir . '/inforef.xml', $xml);
        
        // Stocker pour mise à jour post-questions.xml
        $this->quizActivityDirs[] = [
            'dir' => $activityDir,
            'gradeItemId' => $gradeItemId,
            'contextId' => $contextId,
            'activityId' => $activityId,
            'fileIds' => $quizFileIds,
        ];
    }
    private function addQuestionToBank($q, $bankEntryId, $contextId, $courseId) {
        $qtype = $q['qtype'] ?? 'multichoice';
        $questionText = $q['questiontext'] ?? '';
        if (!empty($questionText) && strpos($questionText, '<') === false) {
            $questionText = '<p>' . htmlspecialchars($questionText) . '</p>';
        }
        $defaultMark = number_format($q['defaultmark'] ?? 1, 7, '.', '');
        $name = $q['name'] ?? 'Question';
        $stamp = 'elea-secours+' . date('ymdHis') . '+' . bin2hex(random_bytes(3));
        
        $versionId = $this->questionVersionId++;
        $questionId = $this->questionId++;
        
        // Traiter l'image de la question si présente
        $questionFileIds = [];
        if (!empty($q['questionimage']['path'])) {
            $imgPath = $q['questionimage']['path'];
            // Extraire le nom de fichier depuis l'URL.
            // ⚠️ Une image ajoutée dans l'éditeur a pour chemin
            //    « api/editor_api.php?action=serve_upload&file=upload_….jpg&session=… ».
            //    basename(parse_url(PHP_URL_PATH)) rendait « editor_api.php » : le fichier
            //    n'était JAMAIS trouvé, le bloc entier était sauté et l'image disparaissait
            //    du .mbz SANS message — d'où « l'image de la question a disparu » à la
            //    réouverture. Le nom vit dans le paramètre `file`.
            $imgFilename = '';
            if (preg_match('#[?&]file=([^&]+)#', $imgPath, $mf)) {
                $imgFilename = basename(urldecode($mf[1]));
            }
            if ($imgFilename === '' || $imgFilename === 'editor_api.php') {
                $imgFilename = basename(parse_url($imgPath, PHP_URL_PATH) ?: $imgPath);
            }
            $localPath = $this->findFileMultiPath($imgFilename);

            if (!$localPath || !file_exists($localPath)) {
                $this->unresolvedFiles[$imgFilename] = true;
                $this->logExport("Image de question introuvable, non embarquée : $imgFilename");
                error_log("EleaMbzExporter: image de question INTROUVABLE : $imgFilename");
            }

            if ($localPath && file_exists($localPath)) {
                $fileContent = file_get_contents($localPath);
                $contenthash = sha1($fileContent);
                $filesize = strlen($fileContent);
                $mime = mime_content_type($localPath) ?: 'image/png';
                // Nom à la mode Moodle : les images venues du Drive arrivaient sous le
                // nom du cache de téléchargement (« <idDrive>_prefetch.bin »).
                $imgFilename = $this->moodleFileName($imgFilename, $contenthash, $mime);

                // Copier dans files/XX/contenthash
                $hashPrefix = substr($contenthash, 0, 2);
                $destDir = $this->filesDir . '/' . $hashPrefix;
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
                file_put_contents($destDir . '/' . $contenthash, $fileContent);
                
                // Archive index
                $this->archiveIndex[] = "files/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
                $this->archiveIndex[] = "files/{$hashPrefix}/{$contenthash}\tf\t{$filesize}\t" . $this->backupDate;
                
                // Fichier image dans filesManifest
                $fileId = $this->fileId++;
                $this->filesManifest[] = [
                    'id' => $fileId,
                    'contenthash' => $contenthash,
                    'contextid' => $contextId,
                    'component' => 'question',
                    'filearea' => 'questiontext',
                    'itemid' => $questionId,
                    'filepath' => '/',
                    'filename' => $imgFilename,
                    'filesize' => $filesize,
                    'mimetype' => $mime,
                ];
                $questionFileIds[] = $fileId;
                
                // Entrée répertoire "/" pour ce fichier
                $dirFileId = $this->fileId++;
                $this->filesManifest[] = [
                    'id' => $dirFileId,
                    'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                    'contextid' => $contextId,
                    'component' => 'question',
                    'filearea' => 'questiontext',
                    'itemid' => $questionId,
                    'filepath' => '/',
                    'filename' => '.',
                    'filesize' => 0,
                    'mimetype' => '$@NULL@$',
                ];
                $questionFileIds[] = $dirFileId;
                
                // Ajouter @@PLUGINFILE@@ dans le questiontext avec dimensions
                $imgWidth = !empty($q['questionimage']['width']) ? intval($q['questionimage']['width']) : '';
                $imgHeight = !empty($q['questionimage']['height']) ? intval($q['questionimage']['height']) : '';
                $sizeAttrs = ($imgWidth && $imgHeight) ? ' width="' . $imgWidth . '" height="' . $imgHeight . '"' : '';
                $imgTag = '<img class="img-fluid" role="presentation" src="@@PLUGINFILE@@/' . htmlspecialchars($imgFilename) . '" alt=""' . $sizeAttrs . '>';
                if (strpos($questionText, '</p>') !== false) {
                    // Insérer avant le dernier </p>
                    $questionText = preg_replace('#</p>\s*$#', $imgTag . '</p>', $questionText);
                } else {
                    $questionText .= $imgTag;
                }
            }
        }
        
        // Traiter les images inline dans le questiontext (img src= avec URLs éditeur)
        $questionText = $this->processQuestionTextInlineImages($questionText, $contextId, $questionId, $questionFileIds);
        
        // Construire le XML spécifique au type
        // (AVANT le stockage des fileIds : buildMultichoiceQuestionXml embarque les images
        //  des réponses et ajoute ses propres entrées à $questionFileIds)
        $answersXml = '';
        $pluginXml = '';
        $penalty = '0.3333333';

        switch ($qtype) {
            case 'multichoice':
                $pluginXml = $this->buildMultichoiceQuestionXml($q, $questionId, $contextId, $questionFileIds);
                break;
            case 'truefalse':
                $pluginXml = $this->buildTrueFalseQuestionXml($q, $questionId);
                $penalty = '1.0000000';
                break;
            case 'shortanswer':
                $pluginXml = $this->buildShortAnswerQuestionXml($q, $questionId);
                break;
            case 'gapselect':
                $pluginXml = $this->buildGapSelectQuestionXml($q, $questionId);
                break;
            case 'ddimageortext':
                $pluginXml = $this->buildDdimageortextQuestionXml($q, $questionId);
                break;
        }

        // Stocker les fileIds pour les inforef des questions
        if (!empty($questionFileIds)) {
            $this->questionFileIds = array_merge($this->questionFileIds ?? [], $questionFileIds);
        }

        $this->questionsBank[] = [
            'bankEntryId' => $bankEntryId,
            'contextId' => $contextId,
            'courseId' => $courseId,
            'versionId' => $versionId,
            'questionId' => $questionId,
            'name' => $name,
            'questiontext' => $questionText,
            'defaultmark' => $defaultMark,
            'penalty' => $penalty,
            'qtype' => $qtype,
            'stamp' => $stamp,
            'pluginXml' => $pluginXml,
        ];
    }
    
    /**
     * Génère le XML plugin pour une question multichoice
     */
    private function buildMultichoiceQuestionXml($q, $questionId, $contextId = 0, &$questionFileIds = null) {
        $answers = $q['answers'] ?? [];
        if ($questionFileIds === null) $questionFileIds = [];
        $single = ($q['single'] ?? true) ? 1 : 0;
        $shuffle = ($q['shuffleanswers'] ?? true) ? 1 : 0;
        
        // Compter les bonnes réponses pour répartir le score
        $correctCount = 0;
        foreach ($answers as $ans) {
            if (!empty($ans['correct'])) $correctCount++;
        }
        if ($correctCount === 0) $correctCount = 1;
        
        // Fraction par bonne réponse : 1.0 si réponse unique, sinon répartition
        $correctFraction = ($single || $correctCount === 1) 
            ? '1.0000000' 
            : number_format(1.0 / $correctCount, 7, '.', '');
        
        $answersXml = '';
        foreach ($answers as $ans) {
            $aid = $this->answerId++;
            $fraction = ($ans['correct'] ?? false) ? $correctFraction : '0.0000000';
            $text = $ans['text'] ?? '';
            if (!empty($text) && strpos($text, '<') === false) {
                $text = '<p>' . htmlspecialchars($text) . '</p>';
            }
            // Une réponse peut contenir une image : l'embarquer comme Éléa, en filearea
            // « answer » avec l'id de la réponse comme itemid.
            if (strpos($text, '<img') !== false) {
                $text = $this->processQuestionTextInlineImages($text, $contextId, $aid, $questionFileIds, 'answer');
            }
            $answersXml .= '
                    <answer id="' . $aid . '">
                      <answertext>' . $this->xmlEncode($text) . '</answertext>
                      <answerformat>1</answerformat>
                      <fraction>' . $fraction . '</fraction>
                      <feedback></feedback>
                      <feedbackformat>1</feedbackformat>
                    </answer>';
        }
        
        $pluginId = $this->multichoicePluginId++;
        return '
                <plugin_qtype_multichoice_question>
                  <answers>' . $answersXml . '
                  </answers>
                  <multichoice id="' . $pluginId . '">
                    <layout>0</layout>
                    <single>' . $single . '</single>
                    <shuffleanswers>' . $shuffle . '</shuffleanswers>
                    <correctfeedback>Votre réponse est correcte.</correctfeedback>
                    <correctfeedbackformat>1</correctfeedbackformat>
                    <partiallycorrectfeedback>Votre réponse est partiellement correcte.</partiallycorrectfeedback>
                    <partiallycorrectfeedbackformat>1</partiallycorrectfeedbackformat>
                    <incorrectfeedback>Votre réponse est incorrecte.</incorrectfeedback>
                    <incorrectfeedbackformat>1</incorrectfeedbackformat>
                    <answernumbering>abc</answernumbering>
                    <shownumcorrect>1</shownumcorrect>
                    <showstandardinstruction>0</showstandardinstruction>
                  </multichoice>
                </plugin_qtype_multichoice_question>';
    }
    
    /**
     * Génère le XML plugin pour une question vrai/faux
     */
    private function buildTrueFalseQuestionXml($q, $questionId) {
        $correctAnswer = ($q['correctanswer'] ?? true);
        
        $trueId = $this->answerId++;
        $falseId = $this->answerId++;
        $trueFraction = $correctAnswer ? '1.0000000' : '0.0000000';
        $falseFraction = $correctAnswer ? '0.0000000' : '1.0000000';
        
        $pluginId = $this->trueFalsePluginId++;
        return '
                <plugin_qtype_truefalse_question>
                  <answers>
                    <answer id="' . $trueId . '">
                      <answertext>Vrai</answertext>
                      <answerformat>0</answerformat>
                      <fraction>' . $trueFraction . '</fraction>
                      <feedback></feedback>
                      <feedbackformat>1</feedbackformat>
                    </answer>
                    <answer id="' . $falseId . '">
                      <answertext>Faux</answertext>
                      <answerformat>0</answerformat>
                      <fraction>' . $falseFraction . '</fraction>
                      <feedback></feedback>
                      <feedbackformat>1</feedbackformat>
                    </answer>
                  </answers>
                  <truefalse id="' . $pluginId . '">
                    <trueanswer>' . $trueId . '</trueanswer>
                    <falseanswer>' . $falseId . '</falseanswer>
                    <showstandardinstruction>0</showstandardinstruction>
                  </truefalse>
                </plugin_qtype_truefalse_question>';
    }
    
    /**
     * Génère le XML plugin pour une question réponse courte
     */
    private function buildShortAnswerQuestionXml($q, $questionId) {
        $answers = $q['answers'] ?? [];
        $usecase = ($q['usecase'] ?? false) ? 1 : 0;
        
        $answersXml = '';
        foreach ($answers as $ans) {
            $aid = $this->answerId++;
            $fraction = number_format($ans['fraction'] ?? 1.0, 7, '.', '');
            $text = $ans['text'] ?? '';
            $answersXml .= '
                    <answer id="' . $aid . '">
                      <answertext>' . $this->xmlEncode($text) . '</answertext>
                      <answerformat>0</answerformat>
                      <fraction>' . $fraction . '</fraction>
                      <feedback></feedback>
                      <feedbackformat>1</feedbackformat>
                    </answer>';
        }
        
        $pluginId = $this->shortAnswerPluginId++;
        return '
                <plugin_qtype_shortanswer_question>
                  <answers>' . $answersXml . '
                  </answers>
                  <shortanswer id="' . $pluginId . '">
                    <usecase>' . $usecase . '</usecase>
                  </shortanswer>
                </plugin_qtype_shortanswer_question>';
    }
    
    /**
     * Génère le XML plugin pour une question sélection de mots (gapselect)
     */
    private function buildGapSelectQuestionXml($q, $questionId) {
        $choices = $q['choices'] ?? [];
        $shuffle = ($q['shuffleanswers'] ?? true) ? 1 : 0;
        
        // Les choix sont déjà dans l'ordre : [[1]] = choix[0], [[2]] = choix[1], etc.
        // Chaque choix a un groupe (feedback) qui détermine quels choix apparaissent ensemble
        $answersXml = '';
        foreach ($choices as $choice) {
            $aid = $this->answerId++;
            $group = $choice['group'] ?? 1;
            $text = $choice['text'] ?? '';
            $answersXml .= '
                    <answer id="' . $aid . '">
                      <answertext>' . $this->xmlEncode($text) . '</answertext>
                      <answerformat>1</answerformat>
                      <fraction>0.0000000</fraction>
                      <feedback>' . $group . '</feedback>
                      <feedbackformat>0</feedbackformat>
                    </answer>';
        }
        
        $pluginId = $this->gapSelectId++;
        return '
                <plugin_qtype_gapselect_question>
                  <answers>' . $answersXml . '
                  </answers>
                  <gapselect id="' . $pluginId . '">
                    <shuffleanswers>' . $shuffle . '</shuffleanswers>
                    <correctfeedback>Votre réponse est correcte.</correctfeedback>
                    <correctfeedbackformat>1</correctfeedbackformat>
                    <partiallycorrectfeedback>Votre réponse est partiellement correcte.</partiallycorrectfeedback>
                    <partiallycorrectfeedbackformat>1</partiallycorrectfeedbackformat>
                    <incorrectfeedback>Votre réponse est incorrecte.</incorrectfeedback>
                    <incorrectfeedbackformat>1</incorrectfeedbackformat>
                    <shownumcorrect>1</shownumcorrect>
                  </gapselect>
                </plugin_qtype_gapselect_question>';
    }
    
    /**
     * Génère le XML plugin pour une question ddimageortext (Glisser-Déposer)
     */
    private function buildDdimageortextQuestionXml($q, $questionId) {
        $shuffleanswers = $q['shuffleanswers'] ?? 1;
        $drags = $q['drags'] ?? [];
        $drops = $q['drops'] ?? [];
        
        // Stocker les drag IDs utilisés pour cette question (pour files.xml)
        $this->_lastDdiDragIds = [];
        
        $dragsXml = '';
        foreach ($drags as $drag) {
            $dragId = $this->ddiDragId++;
            $this->_lastDdiDragIds[] = $dragId;
            $dragsXml .= '
                    <drag id="' . $dragId . '">
                      <no>' . ($drag['no'] ?? 1) . '</no>
                      <draggroup>' . ($drag['group'] ?? 1) . '</draggroup>
                      <infinite>' . (($drag['infinite'] ?? false) ? 1 : 0) . '</infinite>
                      <label>' . $this->xmlEncode($drag['label'] ?? '') . '</label>
                    </drag>';
        }
        
        $dropsXml = '';
        foreach ($drops as $drop) {
            $dropId = $this->ddiDropId++;
            $dropsXml .= '
                    <drop id="' . $dropId . '">
                      <no>' . ($drop['no'] ?? 1) . '</no>
                      <xleft>' . round($drop['x'] ?? 0) . '</xleft>
                      <ytop>' . round($drop['y'] ?? 0) . '</ytop>
                      <choice>' . ($drop['choice'] ?? 0) . '</choice>
                      <label>' . $this->xmlEncode($drop['label'] ?? '') . '</label>
                    </drop>';
        }
        
        $pluginId = $this->ddiPluginId++;
        return '
                <plugin_qtype_ddimageortext_question>
                  <ddimageortext id="' . $pluginId . '">
                    <shuffleanswers>' . $shuffleanswers . '</shuffleanswers>
                    <correctfeedback>&lt;p&gt;Votre réponse est correcte.&lt;/p&gt;</correctfeedback>
                    <correctfeedbackformat>1</correctfeedbackformat>
                    <partiallycorrectfeedback>&lt;p&gt;Votre réponse est partiellement correcte.&lt;/p&gt;</partiallycorrectfeedback>
                    <partiallycorrectfeedbackformat>1</partiallycorrectfeedbackformat>
                    <incorrectfeedback>&lt;p&gt;Votre réponse est incorrecte.&lt;/p&gt;</incorrectfeedback>
                    <incorrectfeedbackformat>1</incorrectfeedbackformat>
                    <shownumcorrect>1</shownumcorrect>
                  </ddimageortext>
                  <drags>' . $dragsXml . '
                  </drags>
                  <drops>' . $dropsXml . '
                  </drops>
                </plugin_qtype_ddimageortext_question>';
    }
    
    /**
     * Traite les images inline d'un HTML de question (énoncé OU réponse).
     * Trouve les <img src="..."> pointant vers des URLs éditeur (file_editor.php, cache/editor_uploads, etc.),
     * les copie dans l'archive MBZ, et remplace les src par @@PLUGINFILE@@/filename.
     *
     * $filearea vaut « questiontext » pour l'énoncé et « answer » pour une réponse, avec
     * $itemId = l'id de la question ou l'id de la réponse — c'est exactement ce qu'écrit
     * Éléa (vérifié sur un parcours réel : 30 fichiers en questiontext, 38 en answer).
     */
    private function processQuestionTextInlineImages($questionText, $contextId, $questionId, &$questionFileIds, $filearea = 'questiontext') {
        if (empty($questionText)) return $questionText;

        // Pattern: img tags avec src contenant des URLs d'éditeur
        $result = preg_replace_callback(
            '#<img\s([^>]*?)src=["\']([^"\']+)["\']([^>]*?)>#i',
            function($matches) use ($contextId, $questionId, &$questionFileIds, $filearea) {
                $beforeSrc = $matches[1];
                $srcUrl = $matches[2];
                $afterSrc = $matches[3];
                
                // Ignorer les images déjà en @@PLUGINFILE@@
                if (strpos($srcUrl, '@@PLUGINFILE@@') !== false) {
                    return $matches[0];
                }
                // Ignorer les data: URLs
                if (strpos($srcUrl, 'data:') === 0) {
                    return $matches[0];
                }
                // Ignorer les URLs externes (http/https vers d'autres sites)
                if (preg_match('#^https?://#', $srcUrl) && !preg_match('#file_editor\.php|editor_uploads|cache/#', $srcUrl)) {
                    return $matches[0];
                }
                
                // Résoudre le chemin local
                $localPath = $this->resolveEditorUrl($srcUrl);
                if (!$localPath || !file_exists($localPath)) {
                    // Signaler au professeur : sans ça l'URL interne restait incrustée dans
                    // le .mbz et l'image disparaissait silencieusement à la réouverture.
                    $nom = basename(parse_url($srcUrl, PHP_URL_PATH) ?: $srcUrl);
                    if (preg_match('#[?&]file=([^&]+)#', $srcUrl, $mf)) $nom = urldecode($mf[1]);
                    $this->unresolvedFiles[$nom] = true;
                    $this->logExport("Image de question introuvable, laissée telle quelle : $nom");
                    error_log("EleaMbzExporter: image de question INTROUVABLE : $nom");
                    return $matches[0]; // Garder tel quel si non trouvé
                }
                
                // Lire et copier dans l'archive
                $fileContent = file_get_contents($localPath);
                $contenthash = sha1($fileContent);
                $filesize = strlen($fileContent);
                $mime = @mime_content_type($localPath) ?: 'image/png';

                // Même image citée deux fois dans le même énoncé : une seule entrée.
                // Deux entrées de même nom dans une zone = sauvegarde invalide et
                // Moodle abandonne toute la zone (toutes les images de la question).
                $zoneQ = $contextId . '|question|' . $filearea . '|' . $questionId;
                if (isset($this->htmlFilesByArea[$zoneQ][$contenthash])) {
                    $nomDeja = $this->htmlFilesByArea[$zoneQ][$contenthash];
                    $attrs = $beforeSrc . $afterSrc;
                    return '<img ' . $beforeSrc . 'src="@@PLUGINFILE@@/' . htmlspecialchars($nomDeja) . '"' . $afterSrc . '>';
                }
                $imgFilename = $this->moodleFileName(basename($localPath), $contenthash, $mime);
                $this->htmlFilesByArea[$zoneQ][$contenthash] = $imgFilename;
                
                $hashPrefix = substr($contenthash, 0, 2);
                $destDir = $this->filesDir . '/' . $hashPrefix;
                if (!is_dir($destDir)) mkdir($destDir, 0777, true);
                if (!file_exists($destDir . '/' . $contenthash)) {
                    file_put_contents($destDir . '/' . $contenthash, $fileContent);
                    $this->archiveIndex[] = "files/\td\t0\t?";
                    $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
                    $this->archiveIndex[] = "files/{$hashPrefix}/{$contenthash}\tf\t{$filesize}\t" . $this->backupDate;
                }
                
                // Entrée fichier dans le manifest
                $fileId = $this->fileId++;
                $this->filesManifest[] = [
                    'id' => $fileId,
                    'contenthash' => $contenthash,
                    'contextid' => $contextId,
                    'component' => 'question',
                    'filearea' => $filearea,
                    'itemid' => $questionId,
                    'filepath' => '/',
                    'filename' => $imgFilename,
                    'filesize' => $filesize,
                    'mimetype' => $mime,
                ];
                $questionFileIds[] = $fileId;
                
                // Entrée répertoire "." (une seule par zone)
                if (!isset($this->htmlDirEntryDone[$zoneQ])) {
                    $this->htmlDirEntryDone[$zoneQ] = true;
                    $dirFileId = $this->fileId++;
                    $this->filesManifest[] = [
                        'id' => $dirFileId,
                        'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                        'contextid' => $contextId,
                        'component' => 'question',
                        'filearea' => $filearea,
                        'itemid' => $questionId,
                        'filepath' => '/',
                        'filename' => '.',
                        'filesize' => 0,
                        'mimetype' => '$@NULL@$',
                    ];
                    $questionFileIds[] = $dirFileId;
                }
                
                // Reconstruire le tag img avec @@PLUGINFILE@@
                // Préserver width/height et autres attributs
                $newSrc = '@@PLUGINFILE@@/' . htmlspecialchars($imgFilename);
                // Ajouter class img-fluid et role presentation comme Éléa
                $attrs = $beforeSrc . $afterSrc;
                if (strpos($attrs, 'class=') === false) {
                    $attrs .= ' class="img-fluid" role="presentation"';
                }
                return '<img ' . $beforeSrc . 'src="' . $newSrc . '"' . $afterSrc . '>';
            },
            $questionText
        );
        
        return $result;
    }
    
    /**
     * Résout une URL serve_upload en chemin local
     */
    private function resolveEditorUrl($url) {
        if (!$url) return null;
        
        // ===== CAS 1: URL lh3 Google Drive (après remplacement par flush loop) =====
        // Ex: https://lh3.googleusercontent.com/d/1oO_jIl2GR-m7IhCUG6xys7fhvXm_cH-E
        if (preg_match('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            $driveId = $m[1];
            // Chercher dans le cache drive_downloads (rempli par prefetchDriveFiles)
            $tmpDir = defined('TMP_PATH') ? TMP_PATH . '/drive_downloads' : sys_get_temp_dir() . '/drive_downloads';
            if (is_dir($tmpDir)) {
                $cached = glob($tmpDir . '/' . $driveId . '_*');
                if ($cached && file_exists($cached[0]) && filesize($cached[0]) > 100) {
                    return $cached[0];
                }
            }
            // Pas en cache → télécharger via EditorDriveSync
            if ($this->editorSessionId) {
                require_once __DIR__ . '/EditorDriveSync.php';
                $downloaded = \EditorDriveSync::resolveFileByDriveId($driveId, 'drive_image.png');
                if ($downloaded && file_exists($downloaded)) {
                    $this->logExport("[resolveEditorUrl] lh3 downloaded: $driveId");
                    return $downloaded;
                }
            }
            $this->logExport("[resolveEditorUrl] lh3 FAILED: $driveId");
            return null;
        }
        
        // ===== CAS 2: URL drive.google.com =====
        if (preg_match('#drive\.google\.com/.*[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
            $driveId = $m[1];
            $tmpDir = defined('TMP_PATH') ? TMP_PATH . '/drive_downloads' : sys_get_temp_dir() . '/drive_downloads';
            if (is_dir($tmpDir)) {
                $cached = glob($tmpDir . '/' . $driveId . '_*');
                if ($cached && file_exists($cached[0]) && filesize($cached[0]) > 100) {
                    return $cached[0];
                }
            }
            if ($this->editorSessionId) {
                require_once __DIR__ . '/EditorDriveSync.php';
                $downloaded = \EditorDriveSync::resolveFileByDriveId($driveId, 'drive_image.png');
                if ($downloaded && file_exists($downloaded)) return $downloaded;
            }
            $this->logExport("[resolveEditorUrl] drive.google FAILED: $driveId");
            return null;
        }
        
        // ===== CAS 3: URL serve_upload locale =====
        $cleanFilename = null;
        $urlSession = null;
        if (preg_match('/file=([^&]+)/', $url, $m)) {
            $cleanFilename = urldecode($m[1]);
        }
        if (preg_match('/session=([^&]+)/', $url, $m)) {
            $urlSession = preg_replace('/[^a-zA-Z0-9_-]/', '', urldecode($m[1]));
        }
        
        if ($cleanFilename) {
            // findFileMultiPath : local session → local plat → scandir → Drive mapping → download
            $localPath = $this->findFileMultiPath($cleanFilename);
            if ($localPath) return $localPath;
            
            // Essayer avec la session de l'URL si différente
            if ($urlSession && $urlSession !== $this->editorSessionId) {
                require_once __DIR__ . '/EditorDriveSync.php';
                $resolved = \EditorDriveSync::resolveFile($urlSession, $cleanFilename);
                if ($resolved && file_exists($resolved)) {
                    $this->logExport("[resolveEditorUrl] Found via URL session=$urlSession: $cleanFilename");
                    return $resolved;
                }
            }
            
            // Retry explicite avec editorSessionId
            if ($this->editorSessionId) {
                require_once __DIR__ . '/EditorDriveSync.php';
                $resolved = \EditorDriveSync::resolveFile($this->editorSessionId, $cleanFilename);
                if ($resolved && file_exists($resolved)) {
                    return $resolved;
                }
            }
        }
        
        // Extraire depuis un chemin cache/editor_uploads/...
        if (preg_match('#(?:^|/)cache/editor_uploads/(?:[^/]+/)?([^/]+)$#', $url, $m)) {
            $localPath = $this->findFileMultiPath($m[1]);
            if ($localPath) return $localPath;
        }
        
        // Chemin local direct
        $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        $localPath = $appRoot . '/' . ltrim($url, '/');
        if (file_exists($localPath)) return $localPath;
        
        // Basename en dernier recours
        $basename = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($basename && $basename !== '.' && $basename !== '..') {
            $localPath = $this->findFileMultiPath($basename);
            if ($localPath) return $localPath;
        }
        
        $this->logExport("[resolveEditorUrl] FAILED: " . substr($url, 0, 200));
        return null;
    }

    /**
     * Parcourt le JSON content pour trouver les fichiers locaux (images uploadées),
     * les copie dans l'archive au format Moodle (files/XX/contenthash),
     * met à jour les chemins dans le JSON, et enregistre les métadonnées dans filesManifest.
     */
    private function processEmbeddedFiles($jsonContent, $contextId, $hvpId, &$activityFileIds) {
        // Trouver le répertoire racine de l'application
        $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        
        // Collecter les dossiers de fichiers utilisés (pour les entrées "." répertoire)
        $usedDirs = [];
        
        // Pattern base64 data URLs (data:image/jpeg;base64,...)
        $jsonContent = preg_replace_callback(
            '/"path"\s*:\s*"(data:image\/(\w+);base64,([A-Za-z0-9+\/=\\\\\/\n\r]+))"/',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, &$usedDirs) {
                $ext = $matches[2] === 'jpeg' ? 'jpg' : $matches[2];
                $b64Data = str_replace(['\\/', "\n", "\r"], ['/', '', ''], $matches[3]);
                $data = base64_decode($b64Data);
                if ($data && strlen($data) > 100) {
                    $tmpFile = tempnam(sys_get_temp_dir(), 'b64_') . '.' . $ext;
                    file_put_contents($tmpFile, $data);
                    
                    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                    $mime = $mimeMap[$ext] ?? 'image/png';
                    $usedDirs['images'] = true;
                    
                    $h5pFilename = 'b64-image-' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $h5pPath = 'images/' . $h5pFilename;
                    
                    $this->addFileToArchive($tmpFile, $contextId, $hvpId, '/images/', $h5pFilename, $mime, $activityFileIds);
                    $this->ensureDirectoryEntry($contextId, $hvpId, '/images/', $activityFileIds);
                    @unlink($tmpFile);
                    
                    return '"path": "' . $h5pPath . '"';
                }
                return $matches[0];
            },
            $jsonContent
        );
        
        // Pattern template images (assets/templatesImages/xxx.jpg)
        $jsonContent = preg_replace_callback(
            '#"path"\s*:\s*"(assets/templatesImages/([^"]+\.(?:jpg|jpeg|png|gif|webp)))"#i',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, $appRoot, &$usedDirs) {
                $relPath = $matches[1];
                $filename = $matches[2];
                
                $possiblePaths = [
                    $appRoot . '/' . $relPath,
                    dirname(__DIR__) . '/' . $relPath,
                    __DIR__ . '/../assets/templatesImages/' . $filename,
                ];
                
                foreach ($possiblePaths as $testPath) {
                    if (file_exists($testPath)) {
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                        $mime = $mimeMap[$ext] ?? 'image/png';
                        $usedDirs['images'] = true;
                        
                        $h5pFilename = 'image-' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $h5pPath = 'images/' . $h5pFilename;
                        
                        $this->addFileToArchive($testPath, $contextId, $hvpId, '/images/', $h5pFilename, $mime, $activityFileIds);
                        $this->ensureDirectoryEntry($contextId, $hvpId, '/images/', $activityFileIds);
                        
                        return '"path": "' . $h5pPath . '"';
                    }
                }
                
                return $matches[0];
            },
            $jsonContent
        );
        
        // Pattern 0: chemins assets locaux (assets/images/xxx.png, assets/images/dragdrop/xxx.png)
        $jsonContent = preg_replace_callback(
            '#"path"\s*:\s*"(assets/[^"]+\.(?:jpg|jpeg|png|gif|webp|svg))"#i',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, $appRoot, &$usedDirs) {
                $assetPath = $matches[1];
                $filename = basename($assetPath);
                
                // Trouver le fichier dans le dossier assets
                $possiblePaths = [
                    $appRoot . '/' . $assetPath,
                    dirname($appRoot) . '/' . $assetPath,
                ];
                
                $localPath = null;
                foreach ($possiblePaths as $testPath) {
                    if (file_exists($testPath)) {
                        $localPath = $testPath;
                        break;
                    }
                }
                
                if ($localPath) {
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $mimeMap = [
                        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'
                    ];
                    $mime = $mimeMap[$ext] ?? 'image/png';
                    
                    // Générer un nom de fichier au format H5P
                    $h5pFilename = 'image-' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $h5pPath = 'images/' . $h5pFilename;
                    $usedDirs['images'] = true;
                    
                    // Ajouter le fichier à l'archive
                    $this->addFileToArchive($localPath, $contextId, $hvpId, '/images/', $h5pFilename, $mime, $activityFileIds);
                    $this->ensureDirectoryEntry($contextId, $hvpId, '/images/', $activityFileIds);
                    
                    return '"path": "' . $h5pPath . '"';
                }
                
                return $matches[0]; // Garder inchangé si fichier non trouvé
            },
            $jsonContent
        );
        
        // Pattern 0: chemins PHP passthrough (api/editor_api.php?action=serve_upload&file=upload_XXX.ext)
        $jsonContent = preg_replace_callback(
            '#api[/\\\\]*editor_api\\.php\\?action=serve_upload[&;]file=((upload|import|tpl)_[a-zA-Z0-9_]+\\.(?:jpg|jpeg|png|gif|webp|svg|mp4|webm|mp3|ogg|wav|m4a|aac))#i',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, $appRoot, &$usedDirs) {
                $uploadFilename = urldecode($matches[1]);

                $localPath = $this->findFileMultiPath($uploadFilename);
                if (!$localPath) {
                    error_log("EleaMbzExporter: serve_upload fichier non trouvé: " . $uploadFilename);
                    return $matches[0];
                }

                $ext = strtolower(pathinfo($uploadFilename, PATHINFO_EXTENSION));
                $mimeMap = [
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                    'mp4' => 'video/mp4', 'webm' => 'video/webm',
                    'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                    'm4a' => 'audio/mp4', 'aac' => 'audio/aac'
                ];
                $mime = $mimeMap[$ext] ?? 'application/octet-stream';

                $isVideo = in_array($ext, ['mp4', 'webm']);
                $isAudio = in_array($ext, ['mp3', 'ogg', 'wav', 'm4a', 'aac']);
                $subDir = $isVideo ? 'videos' : ($isAudio ? 'audios' : 'images');
                $usedDirs[$subDir] = true;

                $prefix = $isAudio ? 'audio-' : 'image-';
                $h5pFilename = $prefix . bin2hex(random_bytes(6)) . '.' . $ext;
                $h5pPath = $subDir . '/' . $h5pFilename;

                $this->addFileToArchive($localPath, $contextId, $hvpId, '/' . $subDir . '/', $h5pFilename, $mime, $activityFileIds);
                $this->ensureDirectoryEntry($contextId, $hvpId, '/' . $subDir . '/', $activityFileIds);

                return $h5pPath;
            },
            $jsonContent
        );
        
        // Pattern 1: chemins locaux d'upload éditeur (ex: /elea-secours/cache/editor_uploads/upload_XXX.png)
        $jsonContent = preg_replace_callback(
            '#(?:/[a-zA-Z0-9_-]+)*?/cache/editor_uploads/((upload|import|tpl)_[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|gif|webp|svg|mp4|webm|mp3|ogg|wav|m4a|aac))#i',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, $appRoot, &$usedDirs) {
                $uploadFilename = $matches[1];

                // CORRECTION: Utiliser la nouvelle méthode multi-chemins
                $localPath = $this->findFileMultiPath($uploadFilename);

                if (!$localPath) {
                    // Fichier introuvable - logger pour debug
                    error_log("EleaMbzExporter: Fichier non trouvé: " . $uploadFilename);
                    return $matches[0];
                }

                // Déterminer l'extension et le type MIME
                $ext = strtolower(pathinfo($uploadFilename, PATHINFO_EXTENSION));
                $mimeMap = [
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                    'mp4' => 'video/mp4', 'webm' => 'video/webm',
                    'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                    'm4a' => 'audio/mp4', 'aac' => 'audio/aac'
                ];
                $mime = $mimeMap[$ext] ?? 'application/octet-stream';

                // Déterminer le sous-dossier H5P (images/, videos/ ou audios/)
                $isVideo = in_array($ext, ['mp4', 'webm']);
                $isAudio = in_array($ext, ['mp3', 'ogg', 'wav', 'm4a', 'aac']);
                $subDir = $isVideo ? 'videos' : ($isAudio ? 'audios' : 'images');
                $usedDirs[$subDir] = true;

                // Générer un nom de fichier au format Éléa
                $prefix = $isAudio ? 'audio-' : 'image-';
                $h5pFilename = $prefix . bin2hex(random_bytes(6)) . '.' . $ext;
                $h5pPath = $subDir . '/' . $h5pFilename;

                // CORRECTION: Ajouter le fichier EN PREMIER (comme Éléa)
                $this->addFileToArchive($localPath, $contextId, $hvpId, '/' . $subDir . '/', $h5pFilename, $mime, $activityFileIds);

                // Créer les entrées de répertoire APRÈS le fichier
                $this->ensureDirectoryEntry($contextId, $hvpId, '/' . $subDir . '/', $activityFileIds);

                return $h5pPath;
            },
            $jsonContent
        );
        
        // Pattern 2: chemins H5P existants vers des fichiers locaux (images/file-XXX.jpg, etc.)
        // Ces fichiers proviennent d'un import MBZ précédent et sont stockés dans le cache du cours
        $jsonContent = preg_replace_callback(
            '#"path"\s*:\s*"(images/[^"]+)"#',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, $appRoot, &$usedDirs) {
                $h5pPath = $matches[1];
                $filename = basename($h5pPath);
                
                // On ne re-traite pas les fichiers déjà traités par pattern 1
                if (strpos($filename, 'image-') === 0) {
                    return $matches[0];
                }
                
                // Essayer de trouver le fichier dans les caches de cours
                $localFile = $this->findLocalFile($h5pPath, $appRoot);
                if ($localFile && file_exists($localFile)) {
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $mimeMap = [
                        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'
                    ];
                    $mime = $mimeMap[$ext] ?? 'image/png';
                    $usedDirs['images'] = true;
                    
                    // CORRECTION: Ajouter le fichier EN PREMIER (comme Éléa)
                    $this->addFileToArchive($localFile, $contextId, $hvpId, '/images/', $filename, $mime, $activityFileIds);
                    
                    // Créer les entrées de répertoire APRÈS le fichier
                    $this->ensureDirectoryEntry($contextId, $hvpId, '/images/', $activityFileIds);
                }
                
                return $matches[0]; // Le chemin H5P est déjà correct
            },
            $jsonContent
        );

        // Pattern 3: chemins H5P audio existants (audios/xxx.mp3) — cours importé puis ré-exporté
        $jsonContent = preg_replace_callback(
            '#"path"\s*:\s*"(audios/[^"]+)"#',
            function($matches) use ($contextId, $hvpId, &$activityFileIds, $appRoot, &$usedDirs) {
                $h5pPath = preg_replace('/#.*$/', '', $matches[1]); // enlever #tmp éventuel
                $filename = basename($h5pPath);

                $localFile = $this->findLocalFile($h5pPath, $appRoot);
                if ($localFile && file_exists($localFile)) {
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $mimeMap = [
                        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                        'm4a' => 'audio/mp4', 'aac' => 'audio/aac'
                    ];
                    $mime = $mimeMap[$ext] ?? 'audio/mpeg';
                    $usedDirs['audios'] = true;

                    $this->addFileToArchive($localFile, $contextId, $hvpId, '/audios/', $filename, $mime, $activityFileIds);
                    $this->ensureDirectoryEntry($contextId, $hvpId, '/audios/', $activityFileIds);
                }

                return $matches[0]; // Le chemin H5P est déjà correct
            },
            $jsonContent
        );

        // Les entrées de répertoire sont maintenant créées automatiquement
        // via ensureDirectoryEntry() dans les callbacks ci-dessus

        return $jsonContent;
    }
    
    /**
     * Parcourt récursivement le contenu H5P (tableau PHP) pour trouver les fichiers locaux,
     * les copier dans l'archive et mettre à jour les chemins.
     * Cette méthode travaille sur le tableau AVANT l'encodage JSON pour éviter les problèmes d'échappement.
     */
    private function processFilesInArray(&$content, $contextId, $hvpId, &$activityFileIds, $depth = 0) {
        if (!is_array($content) || $depth > 50) {
            return;
        }
        
        foreach ($content as $key => &$value) {
            if (is_array($value)) {
                // Récursion dans les sous-tableaux
                $this->processFilesInArray($value, $contextId, $hvpId, $activityFileIds, $depth + 1);
            } elseif (is_string($value) && $key === 'path') {
                // C'est un chemin de fichier - vérifier s'il s'agit d'un fichier local à traiter
                $newPath = $this->processFilePath($value, $contextId, $hvpId, $activityFileIds);
                if ($newPath !== $value) {
                    $value = $newPath;
                }
            }
        }
    }
    
    /**
     * Traite un chemin de fichier individuel.
     * Si c'est un fichier local, le copie dans l'archive et retourne le nouveau chemin.
     * Sinon, retourne le chemin inchangé.
     */
    private function processFilePath($path, $contextId, $hvpId, &$activityFileIds) {
        // Ignorer les valeurs nulles ou vides
        if (empty($path)) {
            return $path;
        }
        
        // Nettoyer #tmp ajouté par enrichH5pContent (AVANT tout regex pour éviter les conflits de délimiteurs)
        $originalPath = $path;
        $path = str_replace('#tmp', '', $path);
        
        // Base64 data URL : décoder et embarquer
        if (preg_match('#^data:image/(\w+);base64,#', $path)) {
            if (preg_match('#^data:image/(\w+);base64,(.+)$#s', $path, $b64m)) {
                $ext = $b64m[1] === 'jpeg' ? 'jpg' : $b64m[1];
                $data = base64_decode($b64m[2]);
                if ($data && strlen($data) > 100) {
                    $tmpFile = tempnam(sys_get_temp_dir(), 'b64_') . '.' . $ext;
                    file_put_contents($tmpFile, $data);
                    $newPath = $this->copyFileToArchive($tmpFile, 'b64-image-' . bin2hex(random_bytes(4)) . '.' . $ext, $contextId, $hvpId, $activityFileIds);
                    @unlink($tmpFile);
                    return $newPath . '#tmp';
                }
            }
            return $originalPath;
        }
        
        // Ignorer les URLs externes sauf si c'est une image/vidéo (qu'on doit télécharger et embarquer)
        if (preg_match('#^https?://#i', $path)) {
            // Google Drive direct URLs (lh3.googleusercontent.com/d/XXXXX) → pas d'extension
            if (preg_match('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $path, $driveMatch)) {
                $tmpFile = $this->downloadDriveFileToTemp($driveMatch[1], 'img');
                if ($tmpFile) {
                    $mime = @mime_content_type($tmpFile) ?: 'image/png';
                    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
                    $realExt = $mimeToExt[$mime] ?? 'jpg';
                    $newPath = $this->copyFileToArchive($tmpFile, 'image-' . bin2hex(random_bytes(6)) . '.' . $realExt, $contextId, $hvpId, $activityFileIds);
                    // NE PAS supprimer : le fichier peut être réutilisé par d'autres activités (assign, resource)
                    // Le nettoyage est fait en fin d'export par exportElea()
                    return $newPath . '#tmp';
                }
                return $originalPath;
            }
            // Google Drive export URLs (drive.google.com/uc?id=XXXXX)
            if (preg_match('#drive\.google\.com/uc\?.*id=([a-zA-Z0-9_-]+)#', $path, $driveMatch)) {
                $tmpFile = $this->downloadDriveFileToTemp($driveMatch[1], 'drv');
                if ($tmpFile) {
                    $mime = @mime_content_type($tmpFile) ?: 'image/png';
                    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
                    $realExt = $mimeToExt[$mime] ?? 'jpg';
                    $newPath = $this->copyFileToArchive($tmpFile, 'image-' . bin2hex(random_bytes(6)) . '.' . $realExt, $contextId, $hvpId, $activityFileIds);
                    // NE PAS supprimer (même raison)
                    return $newPath . '#tmp';
                }
                return $originalPath;
            }
            // Une IMAGE désignée par une URL est embarquée : le cours reste autonome et
            // c'est léger. Une VIDÉO, non : une capsule podeduc ou toute vidéo hébergée
            // ailleurs doit rester un LIEN dans le .mbz — Éléa lit les URL externes
            // nativement. La télécharger remplaçait l'URL saisie par le professeur par un
            // mp4 embarqué (« mon URL devient une vidéo mp4 embed ») et gonflait l'archive.
            // Les vidéos réellement déposées dans l'éditeur ne passent pas ici : leur
            // chemin est un serve_upload, traité plus haut.
            $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (in_array($ext, $imageExts)) {
                $tmpFile = $this->downloadUrlToTemp($path, $ext);
                if ($tmpFile) {
                    $newPath = $this->copyFileToArchive($tmpFile, 'url-image-' . bin2hex(random_bytes(4)) . '.' . $ext, $contextId, $hvpId, $activityFileIds);
                    @unlink($tmpFile);
                    return $newPath . '#tmp';
                }
            }
            return $originalPath;
        }
        
        // Pattern template images (assets/templatesImages/xxx.jpg)
        if (preg_match('#^assets/templatesImages/(.+\.(?:jpg|jpeg|png|gif|webp))$#i', $path, $matches)) {
            $filename = $matches[1];
            $scriptDir = __DIR__; // /path/to/includes
            $baseDir = dirname($scriptDir); // /path/to/elea-secours
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : $baseDir;
            
            $possiblePaths = [
                $appRoot . '/' . $path,
                $baseDir . '/' . $path,
                $scriptDir . '/../assets/templatesImages/' . $filename,
            ];
            
            foreach ($possiblePaths as $testPath) {
                if (file_exists($testPath)) {
                    return $this->copyFileToArchive($testPath, $filename, $contextId, $hvpId, $activityFileIds);
                }
            }
            error_log("EleaMbzExporter: Template image not found: " . $path);
        }
        
        // Pattern 0: chemins assets locaux (assets/images/dragdrop/xxx.png)
        if (preg_match('#^assets/(.+\.(?:jpg|jpeg|png|gif|webp|svg|mp4|webm))$#i', $path, $matches)) {
            $assetPath = $matches[1];
            $filename = basename($assetPath);
            error_log("=== EleaMbzExporter - Pattern assets matché ===");
            error_log("  Path original: " . $path);
            error_log("  AssetPath: " . $assetPath);
            error_log("  Filename: " . $filename);
            
            // Trouver le fichier dans le dossier assets - plusieurs stratégies
            $scriptDir = __DIR__; // /path/to/includes
            $baseDir = dirname($scriptDir); // /path/to/elea-secours
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : $baseDir;
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html';
            
            error_log("  __DIR__: " . $scriptDir);
            error_log("  baseDir: " . $baseDir);
            error_log("  ROOT_PATH: " . (defined('ROOT_PATH') ? ROOT_PATH : 'non défini'));
            error_log("  DOCUMENT_ROOT: " . $docRoot);
            
            $possiblePaths = [
                // Depuis le dossier du script (includes/../assets)
                $baseDir . '/' . $path,
                // Depuis ROOT_PATH
                $appRoot . '/' . $path,
                // Depuis DOCUMENT_ROOT
                $docRoot . '/' . $path,
                $docRoot . '/elea-secours/' . $path,
                // Chemins absolus possibles
                '/var/www/html/elea-secours/' . $path,
                '/var/www/html/' . $path,
            ];
            
            // Dédupliquer les chemins
            $possiblePaths = array_unique($possiblePaths);
            
            $localPath = null;
            foreach ($possiblePaths as $testPath) {
                error_log("  Test: " . $testPath . " => " . (file_exists($testPath) ? 'EXISTE' : 'non trouvé'));
                if (file_exists($testPath)) {
                    $localPath = $testPath;
                    break;
                }
            }
            
            if ($localPath) {
                error_log("  => Fichier trouvé: " . $localPath);
                $newPath = $this->copyFileToArchive($localPath, $filename, $contextId, $hvpId, $activityFileIds);
                error_log("  => Nouveau chemin H5P: " . $newPath);
                return $newPath;
            } else {
                error_log("  => FICHIER NON TROUVÉ!");
            }
        }
        
        // Pattern 1: chemins d'upload éditeur (plusieurs formats possibles)
        // Format PHP passthrough: api/editor_api.php?action=serve_upload&file=upload_XXX.ext
        if (preg_match('#action=serve_upload[&;]file=((upload|import|tpl)_[a-zA-Z0-9_]+\.\w+)#i', $path, $matches)) {
            $uploadFilename = urldecode($matches[1]);
            error_log("EleaMbzExporter - Pattern serve_upload matché: " . $uploadFilename);
            
            $localPath = $this->findFileMultiPath($uploadFilename);
            if ($localPath) {
                $newPath = $this->copyFileToArchive($localPath, $uploadFilename, $contextId, $hvpId, $activityFileIds);
                return $newPath;
            }
        }
        
        // Capture: /xxx/cache/editor_uploads/upload_XXX.ext ou cache/editor_uploads/upload_XXX.ext
        if (preg_match('#(?:^|/)cache/editor_uploads/((upload|import|tpl)_[a-zA-Z0-9_]+\.(?:jpg|jpeg|png|gif|webp|svg|mp4|webm|mp3|ogg|wav|m4a|aac))#i', $path, $matches)) {
            $uploadFilename = $matches[1];
            error_log("EleaMbzExporter - Pattern upload matché: " . $uploadFilename);
            
            $localPath = $this->findFileMultiPath($uploadFilename);
            
            if ($localPath) {
                error_log("EleaMbzExporter - Fichier trouvé: " . $localPath);
                $newPath = $this->copyFileToArchive($localPath, $uploadFilename, $contextId, $hvpId, $activityFileIds);
                error_log("EleaMbzExporter - Nouveau chemin H5P: " . $newPath);
                return $newPath;
            } else {
                error_log("EleaMbzExporter - FICHIER NON TROUVÉ: " . $uploadFilename);
                // Debug: afficher les chemins testés
                if (defined('CACHE_DIR')) error_log("  CACHE_DIR: " . CACHE_DIR);
                if (defined('ROOT_PATH')) error_log("  ROOT_PATH: " . ROOT_PATH);
                return $path;
            }
        }
        
        // Pattern 2: chemins H5P existants (images/file-XXX.jpg)
        if (preg_match('#^images/(.+)$#i', $path, $matches)) {
            $filename = $matches[1];
            // Ne pas re-traiter les fichiers déjà au bon format
            if (strpos($filename, 'image-') === 0) {
                // Essayer de trouver le fichier localement
                $localPath = $this->findFileMultiPath($filename);
                if ($localPath) {
                    $this->copyFileToArchive($localPath, $filename, $contextId, $hvpId, $activityFileIds);
                }
                return $path; // Garder le chemin inchangé
            }
            
            // Essayer de trouver le fichier dans les caches
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            $localFile = $this->findLocalFile($path, $appRoot);
            if ($localFile && file_exists($localFile)) {
                $this->copyFileToArchive($localFile, $filename, $contextId, $hvpId, $activityFileIds);
            }
        }

        // Pattern 3: chemins H5P audio existants (audios/xxx.mp3) — cours importé puis ré-exporté
        if (preg_match('#^audios/(.+)$#i', $path, $matches)) {
            $filename = basename($matches[1]);
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            $localFile = $this->findLocalFile($path, $appRoot);
            if (!$localFile) {
                $localFile = $this->findFileMultiPath($filename);
            }
            if ($localFile && file_exists($localFile)) {
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $audioMimeMap = [
                    'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                    'm4a' => 'audio/mp4', 'aac' => 'audio/aac'
                ];
                $mime = $audioMimeMap[$ext] ?? 'audio/mpeg';
                $this->addFileToArchive($localFile, $contextId, $hvpId, '/audios/', $filename, $mime, $activityFileIds);
                $this->ensureDirectoryEntry($contextId, $hvpId, '/audios/', $activityFileIds);
            }
            return $path; // Le chemin H5P est déjà correct
        }

        return $path;
    }

    /**
     * Retire récursivement toute propriété média (image/video/audio/file/files) à valeur
     * null explicite. Nécessaire car le validateur H5P de Moodle/Éléa (PHP 8) tente
     * d'assigner ->path sur ces valeurs et plante fatalement si elles valent null.
     * Les tableaux vides ([]) et objets valides sont conservés : seul le null est retiré.
     */
    private function stripNullMediaProperties(&$content) {
        if (!is_array($content)) {
            return;
        }
        static $mediaKeys = ['image' => 1, 'video' => 1, 'audio' => 1, 'file' => 1, 'files' => 1,
                             'backgroundImage' => 1];
        foreach ($content as $key => &$value) {
            if (isset($mediaKeys[$key]) && $value === null) {
                unset($content[$key]);
                continue;
            }
            if (is_array($value)) {
                $this->stripNullMediaProperties($value);
            }
        }
        unset($value);
    }

    /**
     * Copie un fichier local dans l'archive et retourne le nouveau chemin H5P.
     */
    private function copyFileToArchive($localPath, $originalFilename, $contextId, $hvpId, &$activityFileIds) {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $mimeMap = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
            'm4a' => 'audio/mp4', 'aac' => 'audio/aac'
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        // Déterminer le sous-dossier H5P (images/, videos/ ou audios/)
        $isVideo = in_array($ext, ['mp4', 'webm']);
        $isAudio = in_array($ext, ['mp3', 'ogg', 'wav', 'm4a', 'aac']);
        $subDir = $isVideo ? 'videos' : ($isAudio ? 'audios' : 'images');

        // Générer un nom de fichier au format Éléa
        $prefix = $isAudio ? 'audio-' : 'image-';
        $h5pFilename = $prefix . bin2hex(random_bytes(6)) . '.' . $ext;
        $h5pPath = $subDir . '/' . $h5pFilename;
        
        // CORRECTION: Ajouter le fichier EN PREMIER (comme Éléa)
        // Les fichiers doivent avoir des IDs plus petits que les répertoires
        $this->addFileToArchive($localPath, $contextId, $hvpId, '/' . $subDir . '/', $h5pFilename, $mime, $activityFileIds);
        
        // Ajouter les entrées de répertoire APRÈS le fichier
        $this->ensureDirectoryEntry($contextId, $hvpId, '/' . $subDir . '/', $activityFileIds);
        
        return $h5pPath;
    }
    
    /**
     * S'assure qu'une entrée de répertoire "." existe pour un sous-dossier H5P.
     */
    private function ensureDirectoryEntry($contextId, $hvpId, $dirPath, &$activityFileIds) {
        // Vérifier si l'entrée du sous-répertoire existe déjà POUR CETTE ACTIVITÉ
        foreach ($this->filesManifest as $existing) {
            if ($existing['filepath'] === $dirPath && $existing['filename'] === '.'
                && $existing['contextid'] == $contextId && $existing['itemid'] == $hvpId) {
                // Sous-répertoire existe pour cette activité, créer la racine si nécessaire
                $this->ensureRootDirectoryEntry($contextId, $hvpId, $activityFileIds);
                return;
            }
        }
        
        // CORRECTION: Créer le sous-répertoire EN PREMIER (comme Éléa)
        // Ordre Éléa: fichier → /images/ → /
        $fileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $fileId,
            'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709', // SHA1 de chaîne vide
            'contextid' => $contextId,
            'component' => 'mod_hvp',
            'filearea' => 'content',
            'itemid' => $hvpId,
            'filepath' => $dirPath,
            'filename' => '.',
            'filesize' => 0,
            'mimetype' => '$@NULL@$',
        ];
        $activityFileIds[] = $fileId;
        
        // Créer l'entrée racine "/" EN DERNIER (ID le plus élevé)
        $this->ensureRootDirectoryEntry($contextId, $hvpId, $activityFileIds);
    }
    
    /**
     * S'assure qu'une entrée de répertoire racine "/" existe.
     */
    /**
     * Télécharge une URL vers un fichier temporaire.
     */
    private function downloadUrlToTemp($url, $ext = 'png') {
        $tmpDir = defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir();
        $tmpFile = $tmpDir . '/dl_' . bin2hex(random_bytes(8)) . '.' . $ext;
        
        // Essayer avec curl d'abord (meilleur support des headers)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                    'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
                ],
            ]);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($data && strlen($data) > 100 && $httpCode >= 200 && $httpCode < 400) {
                file_put_contents($tmpFile, $data);
                return $tmpFile;
            }
        }
        
        // Fallback avec file_get_contents
        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'header' => "Accept: image/webp,image/apng,image/*,*/*;q=0.8\r\n"
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $data = @file_get_contents($url, false, $ctx);
            if ($data && strlen($data) > 100) {
                file_put_contents($tmpFile, $data);
                return $tmpFile;
            }
        } catch (\Exception $e) {
            error_log("EleaMbzExporter: Download failed for {$url}: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Télécharge un fichier depuis Google Drive via l'API (plus fiable que l'URL publique)
     */
    private function downloadDriveFileToTemp(string $driveId, string $prefix = 'drv'): ?string {
        // 1. Vérifier le cache drive_downloads (éviter les re-téléchargements)
        $tmpDir = TMP_PATH . '/drive_downloads';
        if (is_dir($tmpDir)) {
            $cached = glob($tmpDir . '/' . $driveId . '_*');
            if ($cached && file_exists($cached[0]) && filesize($cached[0]) > 0) {
                return $cached[0];
            }
        } else {
            @mkdir($tmpDir, 0755, true);
        }
        
        // 2. Télécharger via l'API Drive authentifiée (singleton DriveManager)
        try {
            if (!$this->_driveManager) {
                require_once ROOT_PATH . '/DriveManager.php';
                $this->_driveManager = new \DriveManager(
                    DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php'
                );
            }
            $content = $this->_driveManager->getFileContentById($driveId);
            if ($content && strlen($content) > 0) {
                $tmpPath = $tmpDir . '/' . $driveId . '_' . $prefix . '.bin';
                file_put_contents($tmpPath, $content);
                return $tmpPath;
            }
        } catch (\Throwable $e) {
            error_log("EleaMbzExporter: Drive API download failed for {$driveId}: " . $e->getMessage());
        }
        
        // 3. Fallback : télécharger via URL publique lh3
        $tmpPath = $this->downloadUrlToTemp('https://lh3.googleusercontent.com/d/' . $driveId, 'png');
        if ($tmpPath) {
            // Renommer dans le cache pour les prochains appels
            $cachePath = $tmpDir . '/' . $driveId . '_' . $prefix . '.bin';
            @rename($tmpPath, $cachePath);
            return $cachePath;
        }
        
        return null;
    }
    
    /**
     * Pré-traite les rotations d'images dans le contenu Course Presentation.
     * Pour chaque image avec rotation:
     * 1. Résout le fichier image (local ou URL)
     * 2. Crée une copie pivotée avec GD
     * 3. Met à jour le JSON: image pivotée = fichier principal, sans originalImage (pour taille correcte dans Éléa)
     * 4. Supprime la propriété rotation (Éléa ne la supporte pas sur les images)
     */
    private function preprocessImageRotations(&$h5pContent, $contextId, $hvpId, &$activityFileIds) {
        // Vérifier que c'est un Course Presentation avec des slides.
        // Le test précède la prise de référence : `&$h5pContent['presentation']['slides']`
        // CRÉE les clés manquantes, et tous les autres types H5P (Memory, GameMap,
        // remise en ordre…) se retrouvaient exportés avec un « presentation.slides: null »
        // parasite dans leur json_content.
        if (empty($h5pContent['presentation']['slides']) || !is_array($h5pContent['presentation']['slides'])) {
            return;
        }
        $slides = &$h5pContent['presentation']['slides'];
        
        foreach ($slides as &$slide) {
            $elements = &$slide['elements'] ?? null;
            if (!$elements || !is_array($elements)) continue;
            
            foreach ($elements as &$element) {
                $library = $element['action']['library'] ?? '';
                if (strpos($library, 'H5P.Image') === false) continue;
                
                $rotation = $element['rotation'] ?? 0;
                if ($rotation == 0) continue;
                
                $filePath = $element['action']['params']['file']['path'] ?? '';
                if (empty($filePath)) continue;
                
                error_log("EleaMbzExporter: Image rotation detected: {$rotation}° on {$filePath}");
                
                // Résoudre le fichier image
                $localPath = $this->resolveImagePath($filePath);
                $isDownloaded = $localPath && (
                    preg_match('#[/\\\\]dl_[a-f0-9]+\.#', $localPath) ||
                    preg_match('#[/\\\\]b64_#', $localPath)
                );
                if (!$localPath) {
                    error_log("EleaMbzExporter: Cannot resolve image for rotation: {$filePath}");
                    // Supprimer rotation quand même (Éléa ne la supporte pas)
                    unset($element['rotation']);
                    continue;
                }
                
                // Créer l'image pivotée
                $rotatedPath = $this->createRotatedImage($localPath, $rotation);
                if (!$rotatedPath) {
                    error_log("EleaMbzExporter: Failed to create rotated image");
                    unset($element['rotation']);
                    continue;
                }
                
                // Déterminer les extensions et noms
                $origExt = strtolower(pathinfo($localPath, PATHINFO_EXTENSION)) ?: 'png';
                $rotExt = strtolower(pathinfo($rotatedPath, PATHINFO_EXTENSION)) ?: 'png';
                $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                
                // Ajouter UNIQUEMENT l'image pivotée à l'archive (pas besoin de l'originale)
                $rotH5pFilename = 'file-' . bin2hex(random_bytes(6)) . '.' . $rotExt;
                $rotH5pPath = 'images/' . $rotH5pFilename;
                $rotMime = $mimeMap[$rotExt] ?? 'image/png';
                $this->addFileToArchive($rotatedPath, $contextId, $hvpId, '/images/', $rotH5pFilename, $rotMime, $activityFileIds);
                $this->ensureDirectoryEntry($contextId, $hvpId, '/images/', $activityFileIds);
                
                // Obtenir les dimensions de l'image originale et de l'image pivotée
                $origSize = @getimagesize($localPath);
                $origW = $origSize[0] ?? 512;
                $origH = $origSize[1] ?? 512;
                
                $rotSize = @getimagesize($rotatedPath);
                $rotW = $rotSize[0] ?? $origW;
                $rotH = $rotSize[1] ?? $origH;
                
                // === Ajuster les dimensions de l'élément sur le canvas ===
                // But: que le rendu dans Éléa (image bbox complète dans l'élément)
                // corresponde au rendu dans l'éditeur (CSS rotate sur l'élément original)
                //
                // Formule:
                // 1. Calculer comment l'image s'affiche en contain dans l'élément éditeur
                // 2. Calculer la bounding box du visuel pivoté
                // 3. Utiliser cette bbox comme nouvelles dimensions d'élément
                
                $elemW = $element['width'] ?? 30;
                $elemH = $element['height'] ?? 30;
                $pW = $elemW * 2;  // pixel-equiv sur canvas 2:1 (ratio H5P Course Presentation)
                $pH = $elemH * 1;
                
                // Image en contain dans l'élément
                $imgRatio = $origW / max($origH, 1);
                $elemRatio = $pW / max($pH, 1);
                if ($imgRatio > $elemRatio) {
                    $dispW = $pW;
                    $dispH = $pW / $imgRatio;
                } else {
                    $dispH = $pH;
                    $dispW = $pH * $imgRatio;
                }
                
                // Bounding box du visuel pivoté
                $rad = deg2rad($rotation);
                $bboxW = $dispW * abs(cos($rad)) + $dispH * abs(sin($rad));
                $bboxH = $dispW * abs(sin($rad)) + $dispH * abs(cos($rad));
                
                // Nouvelles dimensions d'élément
                $newElemW = $bboxW / 2;
                $newElemH = $bboxH / 1;
                
                // Recentrer l'élément pour garder le centre visuel au même endroit
                $element['x'] = ($element['x'] ?? 10) + ($elemW - $newElemW) / 2;
                $element['y'] = ($element['y'] ?? 10) + ($elemH - $newElemH) / 2;
                if ($element['x'] < 0) $element['x'] = 0;
                if ($element['y'] < 0) $element['y'] = 0;
                
                $element['width'] = $newElemW;
                $element['height'] = $newElemH;
                
                // Limites du canvas
                if ($element['width'] > 95) $element['width'] = 95;
                if ($element['height'] > 95) $element['height'] = 95;
                
                error_log("EleaMbzExporter: Rotation {$rotation}°: elem {$elemW}x{$elemH} -> {$element['width']}x{$element['height']}, img {$origW}x{$origH} -> {$rotW}x{$rotH}");
                
                // Mettre à jour le fichier dans le contenu H5P
                // PAS d'originalImage → Éléa affiche l'image à 100% de l'élément
                $element['action']['params']['file'] = [
                    'path' => $rotH5pPath . '#tmp',
                    'mime' => $rotMime,
                    'copyright' => ['license' => 'U'],
                    'width' => $rotW,
                    'height' => $rotH
                ];
                
                // Supprimer la propriété rotation (baked into the image now)
                unset($element['rotation']);
                
                // Nettoyage du fichier temporaire pivoté
                @unlink($rotatedPath);
                // Nettoyage du fichier téléchargé si c'était une URL
                if ($isDownloaded) @unlink($localPath);
                
                error_log("EleaMbzExporter: Rotation baked into image: {$rotH5pPath}");
            }
        }
    }
    
    /**
     * Résout un chemin d'image (local ou URL) vers un fichier local.
     */
    private function resolveImagePath($filePath) {
        // Nettoyer le #tmp
        $filePath = str_replace('#tmp', '', $filePath);
        
        // Base64 data URL : décoder et sauvegarder en fichier temporaire
        if (preg_match('#^data:image/(\w+);base64,(.+)$#s', $filePath, $m)) {
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $data = base64_decode($m[2]);
            if ($data && strlen($data) > 100) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'b64_') . '.' . $ext;
                file_put_contents($tmpFile, $data);
                return $tmpFile;
            }
            return null;
        }
        
        // URL Google Drive directe : extraire l'ID et télécharger via API
        if (preg_match('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $filePath, $m)) {
            return $this->downloadDriveFileToTemp($m[1], 'img');
        }
        if (preg_match('#drive\.google\.com/uc\?id=([a-zA-Z0-9_-]+)#', $filePath, $m)) {
            return $this->downloadDriveFileToTemp($m[1], 'file');
        }
        
        // URL externe : télécharger
        if (preg_match('#^https?://#i', $filePath)) {
            $ext = strtolower(pathinfo(parse_url($filePath, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'png';
            return $this->downloadUrlToTemp($filePath, $ext);
        }
        
        // Chemin d'upload éditeur (PHP passthrough)
        if (preg_match('#action=serve_upload[&;]file=((?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w+)#i', $filePath, $m)) {
            return $this->findFileMultiPath(urldecode($m[1]));
        }
        
        // Chemin d'upload éditeur (accès direct)
        if (preg_match('#(?:^|/)cache/editor_uploads/((?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w+)#i', $filePath, $m)) {
            return $this->findFileMultiPath($m[1]);
        }
        
        // Chemin H5P existant (images/file-XXX.ext)
        if (preg_match('/^images\/(.+?)(?:\#tmp)?$/', $filePath, $m)) {
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            return $this->findLocalFile($filePath, $appRoot);
        }
        
        // Chemin assets
        if (preg_match('#^assets/(.+)$#', $filePath, $m)) {
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            $testPath = $appRoot . '/' . $filePath;
            return file_exists($testPath) ? $testPath : null;
        }
        
        // Template images
        if (preg_match('#^assets/templatesImages/(.+)$#', $filePath, $m)) {
            $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            $testPaths = [
                $appRoot . '/' . $filePath,
                dirname(__DIR__) . '/' . $filePath,
                __DIR__ . '/../assets/templatesImages/' . $m[1],
            ];
            foreach ($testPaths as $tp) {
                if (file_exists($tp)) return $tp;
            }
        }
        
        return null;
    }
    
    /**
     * Crée une copie pivotée d'une image en utilisant GD.
     * Retourne le chemin du fichier pivoté temporaire, ou null en cas d'erreur.
     */
    private function createRotatedImage($sourcePath, $angleDegrees) {
        if (!function_exists('imagecreatefrompng')) {
            error_log("EleaMbzExporter: GD not available for image rotation");
            return null;
        }
        
        $tmpDir = defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir();
        $outPath = $tmpDir . '/rot_' . bin2hex(random_bytes(8)) . '.png';
        
        // Charger l'image source (auto-détection du format par le contenu, pas l'extension)
        $data = @file_get_contents($sourcePath);
        if (!$data) {
            error_log("EleaMbzExporter: Failed to read image: {$sourcePath}");
            return null;
        }
        $srcImg = @imagecreatefromstring($data);
        
        if (!$srcImg) {
            error_log("EleaMbzExporter: Failed to load image: {$sourcePath}");
            return null;
        }
        
        // GD angle: négatif pour correspondre au sens CSS (horaire)
        $gdAngle = -$angleDegrees;
        
        // Pivoter avec fond transparent (conserve le bounding box complet)
        imagesavealpha($srcImg, true);
        imagealphablending($srcImg, true);
        $transparent = imagecolorallocatealpha($srcImg, 0, 0, 0, 127);
        $rotImg = imagerotate($srcImg, $gdAngle, $transparent);
        
        if (!$rotImg) {
            imagedestroy($srcImg);
            return null;
        }
        
        // Sauvegarder en PNG avec transparence (bounding box complet)
        imagesavealpha($rotImg, true);
        imagealphablending($rotImg, false);
        imagepng($rotImg, $outPath);
        
        imagedestroy($srcImg);
        imagedestroy($rotImg);
        
        return file_exists($outPath) ? $outPath : null;
    }
    
    private function ensureRootDirectoryEntry($contextId, $hvpId, &$activityFileIds) {
        foreach ($this->filesManifest as $existing) {
            if ($existing['filepath'] === '/' && $existing['filename'] === '.'
                && $existing['contextid'] == $contextId && $existing['itemid'] == $hvpId) {
                // S'assurer que l'ID est dans activityFileIds
                if (!in_array($existing['id'], $activityFileIds)) {
                    $activityFileIds[] = $existing['id'];
                }
                return;
            }
        }
        
        $fileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $fileId,
            'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
            'contextid' => $contextId,
            'component' => 'mod_hvp',
            'filearea' => 'content',
            'itemid' => $hvpId,
            'filepath' => '/',
            'filename' => '.',
            'filesize' => 0,
            'mimetype' => '$@NULL@$',
        ];
        $activityFileIds[] = $fileId;
    }
    
    /**
     * Ajoute un fichier physique à l'archive au format Moodle
     * (files/XX/contenthash) et enregistre ses métadonnées.
     */
    private function addFileToArchive($localPath, $contextId, $hvpId, $filepath, $filename, $mime, &$activityFileIds) {
        $fileContent = file_get_contents($localPath);
        $contenthash = sha1($fileContent);
        $filesize = strlen($fileContent);
        
        // Vérifier si le fichier physique est déjà dans l'archive (même contenthash)
        $fileAlreadyCopied = false;
        foreach ($this->filesManifest as $existing) {
            if ($existing['contenthash'] === $contenthash && $existing['filename'] !== '.') {
                // Le fichier physique existe déjà dans l'archive
                // Mais si le filename est différent, il faut quand même une entrée manifest
                if ($existing['filename'] === $filename && $existing['filepath'] === $filepath
                    && $existing['contextid'] == $contextId && $existing['itemid'] == $hvpId) {
                    // Exactement la même entrée → juste ajouter la référence
                    $activityFileIds[] = $existing['id'];
                    return;
                }
                $fileAlreadyCopied = true;
                break;
            }
        }
        
        if (!$fileAlreadyCopied) {
            // Copier le fichier dans files/XX/contenthash
            $hashPrefix = substr($contenthash, 0, 2);
            $destDir = $this->filesDir . '/' . $hashPrefix;
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }
            file_put_contents($destDir . '/' . $contenthash, $fileContent);
            
            // Ajouter à l'index d'archive
            $relPath = 'files/' . $hashPrefix . '/' . $contenthash;
            $this->archiveIndex[] = "files/\td\t0\t?";
            $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
            $this->archiveIndex[] = "{$relPath}\tf\t{$filesize}\t" . $this->backupDate;
        }
        
        // Toujours créer une entrée manifest (même contenthash mais filename différent)
        $fileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $fileId,
            'contenthash' => $contenthash,
            'contextid' => $contextId,
            'component' => 'mod_hvp',
            'filearea' => 'content',
            'itemid' => $hvpId,
            'filepath' => $filepath,
            'filename' => $filename,
            'filesize' => $filesize,
            'mimetype' => $mime,
        ];
        $activityFileIds[] = $fileId;
    }
    
    /**
     * Ajoute un fichier quiz (bgimage/dragimage) à l'archive MBZ
     * Retourne l'ID du fichier créé, ou null si échec
     */
    private function addQuizFileToArchive($localPath, $contextId, $itemId, $component, $filearea, $filename) {
        if (!file_exists($localPath)) return null;
        $fileContent = file_get_contents($localPath);
        $contenthash = sha1($fileContent);
        $filesize = strlen($fileContent);
        $mime = 'image/png';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        if (isset($mimeMap[$ext])) $mime = $mimeMap[$ext];
        
        // Copier le fichier
        $hashPrefix = substr($contenthash, 0, 2);
        $destDir = $this->filesDir . '/' . $hashPrefix;
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        if (!file_exists($destDir . '/' . $contenthash)) {
            file_put_contents($destDir . '/' . $contenthash, $fileContent);
            $relPath = 'files/' . $hashPrefix . '/' . $contenthash;
            $this->archiveIndex[] = "files/\td\t0\t?";
            $this->archiveIndex[] = "files/{$hashPrefix}/\td\t0\t?";
            $this->archiveIndex[] = "{$relPath}\tf\t{$filesize}\t" . $this->backupDate;
        }
        
        // Entrée manifest
        $fileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $fileId,
            'contenthash' => $contenthash,
            'contextid' => $contextId,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemId,
            'filepath' => '/',
            'filename' => $filename,
            'filesize' => $filesize,
            'mimetype' => $mime,
        ];
        
        // Entrée répertoire "."
        $dirFileId = $this->fileId++;
        $this->filesManifest[] = [
            'id' => $dirFileId,
            'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
            'contextid' => $contextId,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemId,
            'filepath' => '/',
            'filename' => '.',
            'filesize' => 0,
            'mimetype' => '$@NULL@$',
        ];
        
        return $fileId;
    }
    
    /**
     * Recadre l'image de fond DDI à sourceWidth (retire la zone staging peinte en mode auto).
     * @return string|null Chemin temporaire de l'image recadrée, ou null si inutile/impossible.
     */
    private function cropDdiBackground($bgLocalPath, $sourceWidth) {
        if (!$bgLocalPath || !file_exists($bgLocalPath)) return null;
        if (!function_exists('imagecreatetruecolor')) return null;
        $bgInfo = @getimagesize($bgLocalPath);
        if (!$bgInfo) return null;
        $origW = $bgInfo[0];
        $origH = $bgInfo[1];
        if ($sourceWidth >= $origW) return null;

        $src = null;
        switch ($bgInfo[2]) {
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($bgLocalPath); break;
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($bgLocalPath); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($bgLocalPath); break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($bgLocalPath); break;
        }
        if (!$src) return null;

        $dst = imagecreatetruecolor($sourceWidth, $origH);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $sourceWidth, $origH);
        imagedestroy($src);

        $tmpPath = tempnam(sys_get_temp_dir(), 'ddi_crop_');
        imagepng($dst, $tmpPath);
        imagedestroy($dst);

        $this->logExport("[cropDDI] Fond recadré: {$origW}x{$origH} → {$sourceWidth}x{$origH}");
        return $tmpPath;
    }

    /**
     * Adapte les images drag DDI pour l'export Moodle/Éléa.
     *
     * Problème: Moodle rend TOUTES les images drag d'un groupe à la taille de la plus grande.
     * Si les images font 50px de haut et les zones sont espacées de 36px → chevauchement.
     *
     * Solution: au lieu de réduire les images (perte de qualité), on AGRANDIT l'image de fond
     * et les positions des drops pour que les gaps correspondent à la taille des étiquettes.
     * Les images drag restent à leur taille native → qualité 100%.
     *
     * @param array $dragPaths Chemins locaux des images drag (null = texte)
     * @param array &$drops Les zones de dépôt (modifiées: x, y mis à l'échelle)
     * @param string|null $bgLocalPath Chemin local du fond (sera upscalé si nécessaire)
     * @return array [nouveau bgPath ou null, scale, adaptedDragPaths]
     */
    private function adaptDdiForExport($dragPaths, &$drops, $bgLocalPath) {
        if (!function_exists('imagecreatefrompng')) return [null, 1.0, $dragPaths];
        
        // 1. Lire les dimensions de toutes les images drag
        $sizes = [];
        $maxImgW = 0;
        $maxImgH = 0;
        foreach ($dragPaths as $path) {
            if (!$path || !file_exists($path)) {
                $sizes[] = null;
                continue;
            }
            $info = @getimagesize($path);
            if (!$info) { $sizes[] = null; continue; }
            $s = ['w' => $info[0], 'h' => $info[1], 'type' => $info[2]];
            $sizes[] = $s;
            if ($s['w'] > $maxImgW) $maxImgW = $s['w'];
            if ($s['h'] > $maxImgH) $maxImgH = $s['h'];
        }
        
        if ($maxImgH === 0) return [null, 1.0, $dragPaths];
        
        // 2. Calculer le gap minimum entre les zones de dépôt (par colonne)
        $minGap = PHP_INT_MAX;
        if (count($drops) > 1) {
            $columns = [];
            foreach ($drops as $drop) {
                $x = $drop['x'] ?? 0;
                $placed = false;
                foreach ($columns as $colX => &$col) {
                    if (abs($x - $colX) < 30) { $col[] = $drop; $placed = true; break; }
                }
                unset($col);
                if (!$placed) $columns[(int)$x] = [$drop];
            }
            foreach ($columns as $colDrops) {
                if (count($colDrops) <= 1) continue;
                usort($colDrops, function($a, $b) { return ($a['y'] ?? 0) - ($b['y'] ?? 0); });
                for ($i = 1; $i < count($colDrops); $i++) {
                    $gap = ($colDrops[$i]['y'] ?? 0) - ($colDrops[$i-1]['y'] ?? 0);
                    if ($gap > 0 && $gap < $minGap) $minGap = $gap;
                }
            }
        }
        
        if ($minGap === PHP_INT_MAX || $minGap <= 0) return [null, 1.0];
        
        // 3. Calculer le facteur d'agrandissement du fond
        // Les images drag font maxImgH pixels de haut, il faut que les gaps >= maxImgH + 4px
        $targetGap = $maxImgH + 4;
        $scale = $targetGap / $minGap;
        
        if ($scale <= 1.05) {
            $this->logExport("[adaptDDI] Gaps OK (scale=" . round($scale, 2) . "), uniformisation des largeurs uniquement");
            // Même si pas besoin d'agrandir le fond, uniformiser les largeurs des drags
            $adaptedDrags = $this->uniformizeDragImages($dragPaths, $sizes, $maxImgW, $maxImgH);
            return [null, 1.0, $adaptedDrags];
        }
        
        $this->logExport("[adaptDDI] maxImgH={$maxImgH}px minGap={$minGap}px → scale=" . round($scale, 2) . " (agrandissement fond)");
        
        // 4. Agrandir les positions des drops
        foreach ($drops as &$drop) {
            $drop['x'] = round(($drop['x'] ?? 0) * $scale);
            $drop['y'] = round(($drop['y'] ?? 0) * $scale);
            // width et height des drops ne sont pas utilisés par Moodle MBZ (pas de champ dans le XML)
        }
        unset($drop);
        
        // 5. Agrandir l'image de fond
        $newBgPath = null;
        if ($bgLocalPath && file_exists($bgLocalPath)) {
            $bgInfo = @getimagesize($bgLocalPath);
            if ($bgInfo) {
                $origW = $bgInfo[0];
                $origH = $bgInfo[1];
                $newW = (int)round($origW * $scale);
                $newH = (int)round($origH * $scale);
                
                $src = null;
                switch ($bgInfo[2]) {
                    case IMAGETYPE_PNG: $src = @imagecreatefrompng($bgLocalPath); break;
                    case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($bgLocalPath); break;
                    case IMAGETYPE_GIF: $src = @imagecreatefromgif($bgLocalPath); break;
                    case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($bgLocalPath); break;
                }
                
                if ($src) {
                    $canvas = imagecreatetruecolor($newW, $newH);
                    // Préserver la transparence
                    imagesavealpha($canvas, true);
                    imagealphablending($canvas, false);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefill($canvas, 0, 0, $transparent);
                    imagealphablending($canvas, true);
                    
                    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                    imagedestroy($src);
                    
                    $newBgPath = tempnam(sys_get_temp_dir(), 'ddi_bg_');
                    imagepng($canvas, $newBgPath);
                    imagedestroy($canvas);
                    
                    $this->logExport("[adaptDDI] Background: {$origW}x{$origH} → {$newW}x{$newH}");
                }
            }
        }
        
        // 6. Uniformiser les dimensions des images drag (canvas maxW × maxH, contenu centré)
        $adaptedDrags = $this->uniformizeDragImages($dragPaths, $sizes, $maxImgW, $maxImgH);
        
        return [$newBgPath, $scale, $adaptedDrags];
    }
    
    /**
     * Met toutes les images drag sur un canvas transparent de taille maxW × maxH.
     * Chaque image est placée en haut à gauche, à sa taille originale (pas d'étirement).
     * Moodle affiche toutes les étiquettes d'un groupe à la même taille (celle du plus grand),
     * donc sans cette uniformisation les petites images sont étirées/déformées.
     */
    private function uniformizeDragImages($dragPaths, $sizes, $maxW, $maxH) {
        if ($maxW <= 0 || $maxH <= 0) return $dragPaths;
        
        $needsUniform = false;
        foreach ($sizes as $s) {
            if ($s && ($s['w'] !== $maxW || $s['h'] !== $maxH)) {
                $needsUniform = true;
                break;
            }
        }
        if (!$needsUniform) return $dragPaths;
        
        $this->logExport("[uniformize] Target: {$maxW}x{$maxH}px");
        
        $result = [];
        foreach ($dragPaths as $idx => $path) {
            if (!$path || !$sizes[$idx]) {
                $result[] = $path;
                continue;
            }
            
            $s = $sizes[$idx];
            if ($s['w'] === $maxW && $s['h'] === $maxH) {
                $result[] = $path;
                continue;
            }
            
            $src = null;
            switch ($s['type']) {
                case IMAGETYPE_PNG: $src = @imagecreatefrompng($path); break;
                case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
                case IMAGETYPE_GIF: $src = @imagecreatefromgif($path); break;
                case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($path); break;
            }
            if (!$src) { $result[] = $path; continue; }
            
            // Canvas transparent maxW × maxH
            $canvas = imagecreatetruecolor($maxW, $maxH);
            imagesavealpha($canvas, true);
            imagealphablending($canvas, false);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagealphablending($canvas, true);
            
            // Copier l'image originale en haut à gauche, à sa taille native (PAS de resize)
            imagecopy($canvas, $src, 0, 0, 0, 0, $s['w'], $s['h']);
            imagedestroy($src);
            
            $tmpPath = tempnam(sys_get_temp_dir(), 'ddi_uni_');
            imagepng($canvas, $tmpPath);
            imagedestroy($canvas);
            
            $result[] = $tmpPath;
        }
        
        return $result;
    }
    
    /**
     * Tente de trouver un fichier H5P local dans les caches de cours
     */
    private function findLocalFile($h5pPath, $appRoot) {
        // Chercher dans les cours en cache (tmp/)
        $tmpDir = defined('TMP_PATH') ? TMP_PATH : $appRoot . '/tmp';
        if (is_dir($tmpDir)) {
            foreach (scandir($tmpDir) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $candidate = $tmpDir . '/' . $dir . '/' . $h5pPath;
                if (file_exists($candidate)) return $candidate;
                // Aussi chercher dans les sous-dossiers content/
                $candidate2 = $tmpDir . '/' . $dir . '/content/' . $h5pPath;
                if (file_exists($candidate2)) return $candidate2;
            }
        }
        
        // Chercher dans les cours locaux
        $coursesDir = defined('COURSES_PATH') ? COURSES_PATH : $appRoot . '/courses';
        if (is_dir($coursesDir)) {
            foreach (scandir($coursesDir) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $candidate = $coursesDir . '/' . $dir . '/' . $h5pPath;
                if (file_exists($candidate)) return $candidate;
            }
        }
        
        return null;
    }
    
    // ==================== CONTENU H5P ====================
    
    /**
     * Génère un UUID v4 aléatoire pour subContentId
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
     * Extrait le type de contenu depuis le nom de bibliothèque
     */
    private function getContentTypeFromLibrary($library) {
        $map = [
            'H5P.Text' => 'Text',
            'H5P.AdvancedText' => 'Text',
            'H5P.Image' => 'Image',
            'H5P.Video' => 'Video',
            'H5P.Audio' => 'Audio',
            'H5P.MultiChoice' => 'Multiple Choice',
            'H5P.TrueFalse' => 'True/False Question',
            'H5P.Blanks' => 'Fill in the Blanks',
            'H5P.DragQuestion' => 'Drag and Drop',
            'H5P.DragText' => 'Drag the Words',
            'H5P.MarkTheWords' => 'Mark the Words',
            'H5P.Summary' => 'Summary',
            'H5P.SingleChoiceSet' => 'Single Choice Set',
            'H5P.InteractiveVideo' => 'Interactive Video',
            'H5P.CoursePresentation' => 'Course Presentation',
            'H5P.DialogCards' => 'Dialog Cards',
            'H5P.Dialogcards' => 'Dialog Cards',
            'H5P.Accordion' => 'Accordion',
            'H5P.ImageHotspots' => 'Image Hotspots',
            'H5P.QuestionSet' => 'Question Set',
            'H5P.Column' => 'Column',
            'H5P.Table' => 'Table',
            'H5P.Link' => 'Link',
            'H5P.Nil' => 'Label',
            'H5P.ExportableTextArea' => 'Exportable Text Area',
            'H5P.GameMap' => 'Game Map',
            'H5P.ImageSequencing' => 'Image Sequencing',
            'H5P.MemoryGame' => 'Memory Game',
            'H5P.ImageMultipleHotspotQuestion' => 'Find Multiple Hotspots',
        ];
        
        $parts = explode(' ', $library);
        $baseName = $parts[0];
        return $map[$baseName] ?? str_replace('H5P.', '', $baseName);
    }
    
    /**
     * CORRECTION CRITIQUE: Ajoute subContentId et metadata à tous les éléments H5P
     * Sans ces champs, Moodle/Éléa ne peut pas reconstruire le contenu H5P !
     */
    private function enrichH5pContent(&$content) {
        if (!is_array($content)) {
            return;
        }
        
        // Si c'est un élément avec une action (library), ajouter les métadonnées
        if (isset($content['action']) && isset($content['action']['library'])) {
            $action = &$content['action'];
            
            if (!isset($action['subContentId'])) {
                $action['subContentId'] = $this->generateUUID();
            }
            
            if (!isset($action['metadata'])) {
                $library = $action['library'];
                $contentType = $this->getContentTypeFromLibrary($library);
                $action['metadata'] = [
                    'contentType' => $contentType,
                    'license' => 'U',
                    'title' => 'Sans titre ' . $contentType,
                    'authors' => [],
                    'changes' => []
                ];
            }
            
            // CORRECTION: Ajouter les 7 propriétés manquantes sur chaque élément (comme Éléa)
            if (!isset($content['alwaysDisplayComments'])) {
                $content['alwaysDisplayComments'] = false;
            }
            if (!isset($content['backgroundOpacity'])) {
                $content['backgroundOpacity'] = 0;
            }
            if (!isset($content['displayAsButton'])) {
                $content['displayAsButton'] = false;
            }
            if (!isset($content['buttonSize'])) {
                $content['buttonSize'] = 'big';
            }
            if (!isset($content['goToSlideType'])) {
                $content['goToSlideType'] = 'specified';
            }
            if (!isset($content['invisible'])) {
                $content['invisible'] = false;
            }
            if (!isset($content['solution'])) {
                $content['solution'] = '';
            }
            
            // CORRECTION: Compléter les params de H5P.Image (comme Éléa)
            if (strpos($action['library'], 'H5P.Image') !== false && isset($action['params'])) {
                $params = &$action['params'];
                if (!isset($params['decorative'])) {
                    $params['decorative'] = false;
                }
                if (!isset($params['contentName'])) {
                    $params['contentName'] = 'Image';
                }
                if (!isset($params['expandImage'])) {
                    $params['expandImage'] = 'Expand Image';
                }
                if (!isset($params['minimizeImage'])) {
                    $params['minimizeImage'] = 'Minimize Image';
                }
                // Compléter file si présent
                if (isset($params['file']) && is_array($params['file'])) {
                    $file = &$params['file'];
                    // Ajouter #tmp au path si pas déjà présent
                    if (isset($file['path']) && strpos($file['path'], '#tmp') === false) {
                        $file['path'] .= '#tmp';
                    }
                    if (!isset($file['mime'])) {
                        $ext = strtolower(pathinfo($file['path'] ?? '', PATHINFO_EXTENSION));
                        $ext = str_replace('#tmp', '', $ext);
                        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
                        $file['mime'] = $mimeMap[$ext] ?? 'image/png';
                    }
                    if (!isset($file['copyright'])) {
                        $file['copyright'] = ['license' => 'U'];
                    }
                    // width et height seront ajoutés si on a les infos de l'image
                }
                // Supprimer alt vide si présent (Éléa ne l'a pas)
                if (isset($params['alt']) && $params['alt'] === '') {
                    unset($params['alt']);
                }
            }
            
            if (isset($action['params'])) {
                $this->enrichH5pContent($action['params']);
            }
        }
        
        // Si c'est directement une library (sous-contenus)
        if (isset($content['library']) && is_string($content['library'])) {
            if (!isset($content['subContentId'])) {
                $content['subContentId'] = $this->generateUUID();
            }
            if (!isset($content['metadata'])) {
                $library = $content['library'];
                $contentType = $this->getContentTypeFromLibrary($library);
                $content['metadata'] = [
                    'contentType' => $contentType,
                    'license' => 'U',
                    'title' => 'Sans titre ' . $contentType,
                    'authors' => [],
                    'changes' => []
                ];
            }
            if (isset($content['params'])) {
                $this->enrichH5pContent($content['params']);
            }
        }
        
        // CORRECTION: Ajouter "keywords": [] aux slides (comme Éléa)
        // Un slide a "elements" et "slideBackgroundSelector" mais pas de "library"
        if (isset($content['elements']) && isset($content['slideBackgroundSelector']) && !isset($content['library'])) {
            if (!isset($content['keywords'])) {
                $content['keywords'] = [];
            }
        }
        
        // Parcourir récursivement
        foreach ($content as $key => &$value) {
            if (is_array($value)) {
                $this->enrichH5pContent($value);
            }
        }
    }
    
    private function buildH5pContent($activity) {
        $h5pType = $activity['h5pType'] ?? 'CoursePresentation';
        $content = $activity['content'] ?? [];
        
        switch ($h5pType) {
            case 'CoursePresentation':
                return $this->buildCoursePresentationContent($content);
            case 'InteractiveVideo':
                return $this->buildInteractiveVideoContent($content);
            case 'QuestionSet':
                return $this->buildQuestionSetContent($content);
            case 'MultiChoice':
                return $this->buildMultiChoiceContent($content);
            case 'TrueFalse':
                return $this->buildTrueFalseContent($content);
            case 'Blanks':
                return $this->buildBlanksContent($content);
            case 'DialogCards':
                return $this->buildDialogCardsContent($content);
            case 'DragText':
                return $this->buildDragTextContent($content);
            case 'FindTheWords':
                return $this->buildFindTheWordsContent($content);
            case 'DragQuestion':
                return $this->buildDragQuestionContent($content);
            case 'ThreeImage':
                return $this->buildThreeImageContent($content);
            case 'MultiMediaChoice':
                return $this->buildMultiMediaChoiceContent($content);
            case 'GameMap':
                return $this->buildGameMapContent($content);
            case 'ImageSequencing':
                return $this->buildImageSequencingContent($content);
            case 'MemoryGame':
                return $this->buildMemoryGameContent($content);
            case 'ImageMultipleHotspotQuestion':
                return $this->buildMultiHotspotContent($content);
            default:
                return $content;
        }
    }

    /**
     * H5P.ImageMultipleHotspotQuestion (« Trouver les zones »).
     * Structure relevée sur un export Éléa réel : une image de fond et une liste de zones
     * dont x/y/width/height sont des POURCENTAGES, x/y étant le coin haut-gauche.
     * Éléa n'écrit que ces deux blocs — on s'en tient donc exactement à ce format.
     */
    private function buildMultiHotspotContent($content) {
        $q = $content['imageMultipleHotspotQuestion'] ?? [];
        $bg = $q['backgroundImageSettings'] ?? [];

        $image = $this->normalizeMemoryImage($bg['backgroundImage'] ?? null);

        $zones = [];
        foreach ($q['hotspotSettings']['hotspot'] ?? [] as $hs) {
            if (!is_array($hs)) continue;
            $cs = $hs['computedSettings'] ?? [];
            if (!isset($cs['x'], $cs['y'])) continue;
            $us = $hs['userSettings'] ?? [];
            $zones[] = [
                'userSettings' => [
                    'correct'      => ($us['correct'] ?? true) !== false,
                    'feedbackText' => (string)($us['feedbackText'] ?? ''),
                ],
                'computedSettings' => [
                    'x'      => (float)$cs['x'],
                    'y'      => (float)$cs['y'],
                    'width'  => (float)($cs['width'] ?? 5),
                    'height' => (float)($cs['height'] ?? 5),
                    'figure' => ($cs['figure'] ?? 'circle') === 'rectangle' ? 'rectangle' : 'circle',
                ],
            ];
        }

        $settings = ['questionTitle' => (string)($bg['questionTitle'] ?? 'Image hotspot question')];
        if ($image !== null) $settings['backgroundImage'] = $image;

        return [
            'imageMultipleHotspotQuestion' => [
                'backgroundImageSettings' => $settings,
                'hotspotSettings' => ['hotspot' => $zones],
            ],
        ];
    }

    /**
     * H5P.MemoryGame : une entrée de `cards` = une paire.
     * `match` (image de la jumelle) reste optionnel — Éléa réutilise `image` s'il est absent.
     * Éléa attend le bloc l10n au complet, sinon le validateur refuse le contenu.
     */
    private function buildMemoryGameContent($content) {
        $cards = [];
        foreach ($content['cards'] ?? [] as $card) {
            if (!is_array($card)) continue;
            $image = $this->normalizeMemoryImage($card['image'] ?? null);
            if ($image === null) continue;   // une carte sans image ferait planter Éléa

            $entry = ['image' => $image];
            if (($card['imageAlt'] ?? '') !== '') $entry['imageAlt'] = $card['imageAlt'];

            $match = $this->normalizeMemoryImage($card['match'] ?? null);
            if ($match !== null) {
                $entry['match'] = $match;
                // À défaut de texte alternatif propre à la jumelle, reprendre celui de l'image
                $matchAlt = ($card['matchAlt'] ?? '') !== '' ? $card['matchAlt'] : ($card['imageAlt'] ?? '');
                if ($matchAlt !== '') $entry['matchAlt'] = $matchAlt;
            }
            if (($card['description'] ?? '') !== '') $entry['description'] = $card['description'];

            $cards[] = $entry;
        }

        $behaviour = $content['behaviour'] ?? [];
        $out = [
            'cards' => $cards,
            'behaviour' => [
                'useGrid'    => ($behaviour['useGrid'] ?? true) !== false,
                'allowRetry' => ($behaviour['allowRetry'] ?? true) !== false,
            ],
            'lookNFeel' => [
                'themeColor' => $content['lookNFeel']['themeColor'] ?? '#909090',
            ],
            'l10n' => array_merge([
                'cardTurns'           => 'Cartes retournées :',
                'timeSpent'           => 'Temps écoulé :',
                'feedback'            => 'Bien joué !',
                'tryAgain'            => 'Réessayer',
                'closeLabel'          => 'Fermer',
                'label'               => 'Jeu de mémoire. Trouver les cartes qui se correspondent.',
                'done'                => 'Toutes les cartes ont été trouvées.',
                'cardPrefix'          => 'Carte %num sur %total:',
                'cardUnturned'        => 'Non retournées. Click to turn.',
                'cardTurned'          => 'Turned.',
                'cardMatched'         => 'Correspondance trouvée.',
                'cardMatchedA11y'     => 'Your cards match!',
                'cardNotMatchedA11y'  => 'Your chosen cards do not match. Turn other cards to try again.',
            ], $content['l10n'] ?? []),
        ];

        // numCardsToUse : n'utiliser qu'une partie des paires (facultatif dans Éléa)
        $numToUse = (int)($behaviour['numCardsToUse'] ?? 0);
        if ($numToUse >= 2) {
            $out['behaviour']['numCardsToUse'] = $numToUse;
        }

        $cardBack = $this->normalizeMemoryImage($content['lookNFeel']['cardBack'] ?? null);
        if ($cardBack !== null) {
            $out['lookNFeel']['cardBack'] = $cardBack;
        }

        return $out;
    }

    /**
     * Complète une image de Memory (mime + copyright) ; retourne null si elle est vide,
     * pour ne jamais laisser une propriété média à null dans le JSON envoyé à Éléa.
     */
    private function normalizeMemoryImage($image) {
        if (!is_array($image) || empty($image['path'])) {
            return null;
        }
        if (!isset($image['mime'])) {
            $ext = strtolower(pathinfo(parse_url($image['path'], PHP_URL_PATH) ?: $image['path'], PATHINFO_EXTENSION));
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                        'gif' => 'image/gif', 'webp' => 'image/webp'];
            $image['mime'] = $mimeMap[$ext] ?? 'image/png';
        }
        if (!isset($image['copyright'])) {
            $image['copyright'] = ['license' => 'U'];
        }
        return $image;
    }

    /**
     * H5P.ImageSequencing : l'ordre de sequenceImages EST la solution.
     * Éléa attend une image complète (mime, copyright) et le bloc l10n au complet.
     */
    private function buildImageSequencingContent($content) {
        $cards = [];
        foreach ($content['sequenceImages'] ?? [] as $card) {
            $image = $card['image'] ?? null;
            if (is_array($image) && !empty($image['path'])) {
                if (!isset($image['mime'])) {
                    $ext = strtolower(pathinfo(parse_url($image['path'], PHP_URL_PATH) ?: $image['path'], PATHINFO_EXTENSION));
                    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                                'gif' => 'image/gif', 'webp' => 'image/webp'];
                    $image['mime'] = $mimeMap[$ext] ?? 'image/png';
                }
                if (!isset($image['copyright'])) $image['copyright'] = ['license' => 'U'];
            } else {
                $image = null;
            }
            $entry = ['imageDescription' => $card['imageDescription'] ?? ''];
            if ($image !== null) $entry['image'] = $image;
            $cards[] = $entry;
        }

        $behaviour = $content['behaviour'] ?? [];

        return [
            'taskDescription' => $content['taskDescription'] ?? '',
            'altTaskDescription' => $content['altTaskDescription']
                ?? 'Make the following list be ordered correctly. Use the cursor keys to navigate through the list items, use space to activate or deactivate an item and the cursor keys to move it',
            'sequenceImages' => $cards,
            'behaviour' => [
                'enableSolution' => ($behaviour['enableSolution'] ?? true) !== false,
                'enableRetry'    => ($behaviour['enableRetry'] ?? true) !== false,
                'enableResume'   => ($behaviour['enableResume'] ?? true) !== false,
            ],
            'l10n' => array_merge([
                'totalMoves' => 'Total Moves',
                'timeSpent' => 'Time spent',
                'score' => 'You got @score of @total points',
                'checkAnswer' => 'Check',
                'tryAgain' => 'Retry',
                'showSolution' => 'ShowSolution',
                'resume' => 'Resume',
                'audioNotSupported' => 'Audio Error',
                'ariaPlay' => 'Play the corresponding audio',
                'ariaMoveDescription' => 'Moved @cardDesc from @posSrc to @posDes',
                'ariaCardDesc' => 'sequencing item',
            ], $content['l10n'] ?? []),
        ];
    }

    /**
     * H5P.GameMap : carte d'étapes reliées. Éléa attend l'arborescence complète
     * (titleScreen / endScreen / visual / audio / behaviour / l10n / a11y) et, sur chaque
     * étape, les champs content, time, accessRestrictions et specialStageExtra*.
     */
    private function buildGameMapContent($content) {
        $steps = $content['gamemapSteps']['gamemap']['elements'] ?? [];
        $background = $content['gamemapSteps']['backgroundImageSettings']['backgroundImage'] ?? null;

        foreach ($steps as $i => &$step) {
            if (!isset($step['id']) || $step['id'] === '') $step['id'] = $this->generateUUID();
            if (!isset($step['label'])) $step['label'] = 'Étape ' . ($i + 1);
            if (!isset($step['content']) || !is_array($step['content'])) {
                $step['content'] = ['params' => new \stdClass(), 'dom' => ['count' => 0]];
            }
            $step['telemetry'] = [
                'x'      => (string)($step['telemetry']['x'] ?? '50'),
                'y'      => (string)($step['telemetry']['y'] ?? '50'),
                'width'  => (string)($step['telemetry']['width'] ?? '4.375'),
                'height' => (string)($step['telemetry']['height'] ?? '7.814060667441372'),
            ];
            // Les voisins sont des INDICES d'étapes, stockés en chaînes par Éléa
            $step['neighbors'] = array_values(array_map('strval', $step['neighbors'] ?? []));
            $step['canBeStartStage'] = !empty($step['canBeStartStage']);
            if (!isset($step['time']) || (is_array($step['time']) && empty($step['time']))) {
                $step['time'] = new \stdClass();
            }
            if (!isset($step['accessRestrictions'])) {
                $step['accessRestrictions'] = ['openOnScoreSufficient' => false];
            }
            if (!isset($step['specialStageExtraLives'])) $step['specialStageExtraLives'] = 1;
            if (!isset($step['specialStageExtraTime'])) $step['specialStageExtraTime'] = 1;
            // Une étape sans contenu (case d'arrivée) garde un contentType vide, comme Éléa
            if (!isset($step['contentType']) || !is_array($step['contentType'])) {
                $step['contentType'] = ['params' => new \stdClass()];
            } elseif (empty($step['contentType']['library'])) {
                $step['contentType'] = ['params' => new \stdClass()];
            }
        }
        unset($step);

        $visual = $content['visual'] ?? [];
        $behaviour = $content['behaviour'] ?? [];

        return [
            'showTitleScreen' => !empty($content['showTitleScreen']),
            'titleScreen' => [
                'titleScreenIntroduction' => $content['titleScreen']['titleScreenIntroduction'] ?? '<p style="text-align: center;"></p>',
                'titleScreenMedium' => ['params' => new \stdClass()],
            ],
            'gamemapSteps' => [
                'backgroundImageSettings' => [
                    'backgroundImage' => $background ?: null,
                ],
                'gamemap' => ['elements' => array_values($steps)],
            ],
            'endScreen' => [
                'noSuccess' => [
                    'endScreenTextNoSuccess' => $content['endScreen']['noSuccess']['endScreenTextNoSuccess'] ?? '<p style="text-align: center;"></p>',
                    'endScreenMediumNoSuccess' => ['params' => new \stdClass()],
                ],
                'success' => [
                    'endScreenTextSuccess' => $content['endScreen']['success']['endScreenTextSuccess'] ?? '<p style="text-align: center;"></p>',
                    'endScreenMediumSuccess' => ['params' => new \stdClass()],
                ],
                'overallFeedback' => [['from' => 0, 'to' => 100]],
            ],
            'visual' => [
                'stages' => [
                    'colorStage'        => $visual['stages']['colorStage']        ?? 'rgba(250, 223, 10, 0.7)',
                    'colorStageLocked'  => $visual['stages']['colorStageLocked']  ?? 'rgba(153, 0, 0, 0.7)',
                    'colorStageCleared' => $visual['stages']['colorStageCleared'] ?? 'rgba(0, 130, 0, 0.7)',
                ],
                'paths' => [
                    'displayPaths' => ($visual['paths']['displayPaths'] ?? true) !== false,
                    'style' => [
                        'colorPath'        => $visual['paths']['style']['colorPath']        ?? 'rgba(255, 255, 255, 0.904)',
                        'colorPathCleared' => $visual['paths']['style']['colorPathCleared'] ?? 'rgba(0, 130, 0, 0.7)',
                        'pathWidth'        => (string)($visual['paths']['style']['pathWidth'] ?? '0.2'),
                        'pathStyle'        => $visual['paths']['style']['pathStyle'] ?? 'dotted',
                    ],
                ],
                'misc' => ['useAnimation' => ($visual['misc']['useAnimation'] ?? true) !== false],
            ],
            'audio' => [
                'backgroundMusic' => ['muteDuringExercise' => true],
                'ambient' => new \stdClass(),
            ],
            'behaviour' => [
                'enableRetry' => ($behaviour['enableRetry'] ?? true) !== false,
                'enableSolutionsButton' => ($behaviour['enableSolutionsButton'] ?? true) !== false,
                'map' => [
                    'showLabels' => ($behaviour['map']['showLabels'] ?? true) !== false,
                    'roaming'    => $behaviour['map']['roaming'] ?? 'complete',
                    'fog'        => $behaviour['map']['fog'] ?? 'all',
                ],
            ],
            'l10n' => array_merge([
                'start' => 'Start',
                'continue' => 'Continue',
                'restart' => 'Restart',
                'showSolutions' => 'Show solutions',
                'completedMap' => 'You have completed the map!',
                'fullScoreButnoLivesLeft' => 'You have achieved full score, but lost all your lifes!',
                'fullScoreButTimeout' => 'You have achieved full score, but ran out of time!',
                'confirmFinishHeader' => "Finir l'activité ?",
                'confirmFinishDialog' => 'Avant de terminer, explorez tous les points et répondez au questions',
                'confirmFinishDialogSubmission' => 'Your score will be submitted.',
                'confirmFinishDialogQuestion' => 'Voulez-vous finir cette activité ?',
                'confirmAccessDeniedHeader' => 'Stage locked',
                'confirmAccessDeniedDialog' => 'This stage requires you to meet some goals before it can be opened.',
                'confirmAccessDeniedMinScore' => 'You need at least a certain number of points: @minscore',
                'yes' => 'Yes',
                'no' => 'No',
                'confirmGameOverHeader' => 'Game over!',
                'confirmGameOverDialog' => 'You have lost all your lives. Please try again!',
                'confirmGameOverDialogTimeout' => 'You have run out of time. Please try again!',
                'confirmTimeoutHeader' => 'Time out!',
                'confirmTimeoutDialog' => 'You ran out of time.',
                'confirmTimeoutDialogLostLife' => 'You ran out of time and lost a life.',
                'confirmScoreIncompleteHeader' => 'Not full score!',
                'confirmIncompleteScoreDialogLostLife' => 'You did not achieve full score and lost a life.',
                'confirmFullScoreHeader' => 'You achieved full score!',
                'confirmFullScoreDialog' => 'You have collected enough points to finish this map with a full score, but you are free to explore the rest if you wish to.',
                'confirmFullScoreDialogLoseLivesAmendmend' => 'But beware! You may still lose lives!',
                'ok' => 'OK',
                'noBackground' => 'No background image was set for the map.',
                'noStages' => 'No valid stages were set for the map.',
            ], $content['l10n'] ?? []),
            'a11y' => array_merge([
                'buttonFinish' => 'Finir',
                'buttonAudioActive' => 'Mute audio. Currently unmuted.',
                'buttonAudioInactive' => 'Unmute audio. Currently muted.',
                'close' => 'Close',
                'yourResult' => 'You got @score out of @total points',
                'mapWasOpened' => 'The map was opened.',
                'mapSolutionsWasOpened' => 'The map was opened in solutions mode.',
                'startScreenWasOpened' => 'The title screen was opened.',
                'endScreenWasOpened' => 'The end screen was opened.',
                'exerciseLabel' => '. Exercise for @stagelabel',
                'stageButtonLabel' => 'Stage: @stagelabel',
                'adjacentStageLabel' => 'Adjacent stage of @stagelabelOrigin: @stagelabelNeighbor',
                'locked' => 'Locked',
                'cleared' => 'Cleared',
                'applicationInstructions' => 'Use space or enter key to activate current stage. Use arrow keys to select adjacent stage. Use space or enter key on adjacent stage to navigate there.',
                'applicationDescription' => 'Map',
                'movedToStage' => 'Moved to @stagelabel',
                'stageUnlocked' => 'Stage @stagelabel was unlocked.',
                'toolbarFallbackLabel' => 'Game Map',
                'enterFullscreen' => 'Enter fullscreen mode',
                'exitFullscreen' => 'Exit fullscreen mode',
            ], $content['a11y'] ?? []),
        ];
    }
    
    /**
     * Déplace l'attribut style des tags inline non "styleables" par H5P
     * (strong, em, u, ...) vers un <span> englobant, sinon Éléa supprime le style
     * à l'import (ex. surlignage posé sur un texte en gras).
     *   <strong style="X">…</strong>  ->  <span style="X"><strong>…</strong></span>
     */
    private function moveInlineStylesToSpan(string $html): string {
        if (stripos($html, 'style=') === false) {
            return $html;
        }
        $pattern = '/<(strong|em|b|i|u|s|del|ins|mark|sub|sup|small|abbr|cite|code)\b([^>]*?)\s+style="([^"]*)"([^>]*)>(.*?)<\/\1>/is';
        $guard = 0;
        do {
            $html = preg_replace($pattern, '<span style="$3"><$1$2$4>$5</$1></span>', $html, -1, $count);
            $guard++;
        } while ($count > 0 && $guard < 20);
        return $html;
    }

    private function buildCoursePresentationContent($content) {
        // Récupérer les slides existantes ou créer un tableau vide
        $slides = [];
        if (isset($content['presentation']['slides'])) {
            $slides = $content['presentation']['slides'];
        } elseif (isset($content['slides'])) {
            $slides = $content['slides'];
        } else {
            $slides = [['elements' => [], 'slideBackgroundSelector' => new \stdClass()]];
        }
        
        // S'assurer que chaque slide a slideBackgroundSelector
        // Et corriger les bibliothèques : H5P.Text doit être H5P.AdvancedText dans les slides
        foreach ($slides as &$slide) {
            // S'assurer que slideBackgroundSelector est un objet (pas un tableau vide)
            if (!isset($slide['slideBackgroundSelector']) || 
                (is_array($slide['slideBackgroundSelector']) && empty($slide['slideBackgroundSelector']))) {
                $slide['slideBackgroundSelector'] = new \stdClass();
            }
            
            // Corriger les éléments de texte dans les slides
            if (isset($slide['elements']) && is_array($slide['elements'])) {
                foreach ($slide['elements'] as &$element) {
                    // Si c'est un élément avec H5P.Text, le convertir en H5P.AdvancedText
                    if (isset($element['action']['library'])) {
                        $lib = $element['action']['library'];
                        if (strpos($lib, 'H5P.Text ') === 0) {
                            // Convertir H5P.Text 1.1 en H5P.AdvancedText 1.1
                            $element['action']['library'] = 'H5P.AdvancedText 1.1';
                            // Mettre à jour le contentType dans metadata si présent
                            if (isset($element['action']['metadata']['contentType'])) {
                                $element['action']['metadata']['contentType'] = 'Text';
                            }
                        }
                        
                        // Normaliser <b>→<strong>, <i>→<em> dans tous les champs texte riches
                        // (navigateurs produisent <b>/<i> via Ctrl+B/I, Éléa attend <strong>/<em>)
                        // 'label' : consigne d'une zone de saisie libre (H5P.ExportableTextArea)
                        $richTextFields = ['text', 'question', 'label'];
                        foreach ($richTextFields as $rtf) {
                            if (isset($element['action']['params'][$rtf]) && is_string($element['action']['params'][$rtf])) {
                                $element['action']['params'][$rtf] = preg_replace('/<b(\s|>)/i', '<strong$1', $element['action']['params'][$rtf]);
                                $element['action']['params'][$rtf] = str_ireplace('</b>', '</strong>', $element['action']['params'][$rtf]);
                                $element['action']['params'][$rtf] = preg_replace('/<i(\s|>)/i', '<em$1', $element['action']['params'][$rtf]);
                                $element['action']['params'][$rtf] = str_ireplace('</i>', '</em>', $element['action']['params'][$rtf]);
                                // Le filtre H5P d'Éléa ne conserve l'attribut style QUE sur des tags
                                // "styleables" (span, p, div, h1-3, table, td, th, li, col, figure).
                                // Sur <strong>, <em>, <u>, etc. le style est SUPPRIMÉ. Quand le texte est
                                // en gras, le navigateur pose le surlignage sur le <strong> → il disparaît
                                // à l'import. On déplace donc ce style vers un <span> englobant.
                                $element['action']['params'][$rtf] = $this->moveInlineStylesToSpan($element['action']['params'][$rtf]);
                            }
                        }
                        
                        // Zone de saisie libre : Éléa attend les 4 paramètres, même vides
                        if (strpos($lib, 'H5P.ExportableTextArea') !== false) {
                            $element['action']['library'] = 'H5P.ExportableTextArea 1.3';
                            $etaParams = &$element['action']['params'];
                            if (!isset($etaParams['index'])) $etaParams['index'] = 0;
                            if (!isset($etaParams['exportNotSupported'])) {
                                $etaParams['exportNotSupported'] = "La fonction export n'est pas supportée sur cet équipement.";
                            }
                            if (!isset($etaParams['exportComments'])) $etaParams['exportComments'] = false;
                            if (!isset($etaParams['label'])) $etaParams['label'] = '';
                            unset($etaParams);
                        }

                        // Enrichir les DialogCards avec tous les champs requis
                        if (strpos($lib, 'H5P.Dialogcards') !== false || strpos($lib, 'H5P.DialogCards') !== false) {
                            $element['action']['library'] = 'H5P.Dialogcards 1.9'; // Forcer le bon nom
                            $params = &$element['action']['params'];
                            
                            // S'assurer que title et description sont définis et vides
                            if (!isset($params['title'])) $params['title'] = '';
                            if (!isset($params['description'])) $params['description'] = '';
                            
                            // S'assurer que dialogs existe et a la structure correcte
                            if (!isset($params['dialogs']) || !is_array($params['dialogs'])) {
                                $params['dialogs'] = [['text' => '', 'answer' => '', 'tips' => new \stdClass()]];
                            }
                            
                            // Enrichir chaque dialog avec tips s'il manque et s'assurer que le texte est en HTML
                            foreach ($params['dialogs'] as &$dialog) {
                                if (!isset($dialog['tips'])) {
                                    $dialog['tips'] = new \stdClass();
                                } elseif (is_array($dialog['tips']) && empty($dialog['tips'])) {
                                    $dialog['tips'] = new \stdClass();
                                }
                                
                                // S'assurer que le texte recto est en HTML centré
                                if (isset($dialog['text']) && !empty($dialog['text'])) {
                                    if (strpos($dialog['text'], '<') === false) {
                                        $dialog['text'] = '<p style="text-align: center;">' . htmlspecialchars($dialog['text']) . '</p>';
                                    } elseif (strpos($dialog['text'], 'text-align') === false) {
                                        $dialog['text'] = str_replace('<p>', '<p style="text-align: center;">', $dialog['text']);
                                        $dialog['text'] = str_replace('<p ', '<p style="text-align: center;" ', $dialog['text']);
                                    }
                                } else {
                                    $dialog['text'] = '<p style="text-align: center;"></p>';
                                }
                                
                                // S'assurer que le texte verso est en HTML centré
                                if (isset($dialog['answer']) && !empty($dialog['answer'])) {
                                    if (strpos($dialog['answer'], '<') === false) {
                                        $dialog['answer'] = '<p style="text-align: center;">' . htmlspecialchars($dialog['answer']) . '</p>';
                                    } elseif (strpos($dialog['answer'], 'text-align') === false) {
                                        $dialog['answer'] = str_replace('<p>', '<p style="text-align: center;">', $dialog['answer']);
                                        $dialog['answer'] = str_replace('<p ', '<p style="text-align: center;" ', $dialog['answer']);
                                    }
                                } else {
                                    $dialog['answer'] = '<p style="text-align: center;"></p>';
                                }
                            }
                            
                            // S'assurer que behaviour existe
                            if (!isset($params['behaviour'])) {
                                $params['behaviour'] = [
                                    'enableRetry' => true,
                                    'disableBackwardsNavigation' => false,
                                    'scaleTextNotCard' => true,
                                    'randomCards' => false,
                                    'maxProficiency' => 5,
                                    'quickProgression' => false
                                ];
                            }
                            
                            // Ajouter tous les textes de localisation s'ils manquent
                            $defaultTexts = [
                                'answer' => 'Retourner',
                                'next' => 'Suivant',
                                'prev' => 'Précédent',
                                'retry' => 'Recommencer',
                                'correctAnswer' => "J'ai eu bon!",
                                'incorrectAnswer' => "J'ai eu faux",
                                'round' => 'Round @round',
                                'cardsLeft' => 'Cartes restantes: @number',
                                'nextRound' => 'Procéder au round @round',
                                'startOver' => 'Recommencer',
                                'showSummary' => 'Suivant',
                                'summary' => 'Résumé',
                                'summaryCardsRight' => 'Cartes correctes:',
                                'summaryCardsWrong' => 'Cartes incorrectes:',
                                'summaryCardsNotShown' => 'Cartes non montrées:',
                                'summaryOverallScore' => 'Score global',
                                'summaryCardsCompleted' => 'Cartes que vous avez complétées:',
                                'summaryCompletedRounds' => 'Rounds complétés:',
                                'summaryAllDone' => 'Bien joué! Vous avez réussi à avoir les @cards cartes correctes @max fois de suite!',
                                'progressText' => 'Carte @card sur @total',
                                'cardFrontLabel' => 'Le devant de la carte',
                                'cardBackLabel' => 'Le dos de la carte',
                                'tipButtonLabel' => "Montrer l'indice",
                                'audioNotSupported' => 'Votre navigateur ne supporte pas ce fichier audio'
                            ];
                            foreach ($defaultTexts as $key => $value) {
                                if (!isset($params[$key])) $params[$key] = $value;
                            }
                            
                            // Ajouter confirmStartingOver s'il manque
                            if (!isset($params['confirmStartingOver'])) {
                                $params['confirmStartingOver'] = [
                                    'header' => 'Recommencer?',
                                    'body' => 'Toutes les progressions seront perdues. Êtes-vous sûr de vouloir recommencer?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Recommencer'
                                ];
                            }
                        }
                        
                        // Enrichir les MultiChoice avec tous les champs requis
                        if (strpos($lib, 'H5P.MultiChoice') !== false) {
                            $params = &$element['action']['params'];
                            
                            // Enrichir les réponses avec tipsAndFeedback
                            if (isset($params['answers']) && is_array($params['answers'])) {
                                foreach ($params['answers'] as &$answer) {
                                    if (!isset($answer['tipsAndFeedback'])) {
                                        $answer['tipsAndFeedback'] = [
                                            'tip' => '',
                                            'chosenFeedback' => '',
                                            'notChosenFeedback' => ''
                                        ];
                                    }
                                    // S'assurer que le texte est en HTML
                                    if (isset($answer['text']) && strpos($answer['text'], '<') === false) {
                                        $answer['text'] = '<div>' . htmlspecialchars($answer['text']) . '</div>' . "\n";
                                    }
                                }
                            }
                            
                            // Ajouter media s'il manque
                            if (!isset($params['media'])) {
                                $params['media'] = [
                                    'disableImageZooming' => false,
                                    'type' => ['params' => new \stdClass()]
                                ];
                            }
                            
                            // Convertir <b>→<strong>, <i>→<em> dans la question
                            if (isset($params['question'])) {
                                $params['question'] = preg_replace('/<b(\s|>)/i', '<strong$1', $params['question']);
                                $params['question'] = str_ireplace('</b>', '</strong>', $params['question']);
                                $params['question'] = preg_replace('/<i(\s|>)/i', '<em$1', $params['question']);
                                $params['question'] = str_ireplace('</i>', '</em>', $params['question']);
                            }
                            if (!isset($params['overallFeedback'])) {
                                $params['overallFeedback'] = [['from' => 0, 'to' => 100]];
                            }
                            
                            // Ajouter behaviour complet
                            if (!isset($params['behaviour'])) {
                                $params['behaviour'] = [
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
                                    'showScorePoints' => true
                                ];
                            }
                            
                            // Ajouter UI s'il manque
                            if (!isset($params['UI'])) {
                                $params['UI'] = $this->getMultiChoiceUI();
                            }
                            
                            // Ajouter confirmCheck et confirmRetry
                            if (!isset($params['confirmCheck'])) {
                                $params['confirmCheck'] = [
                                    'header' => 'Terminer ?',
                                    'body' => 'Êtes-vous certain de vouloir terminer ?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Terminer'
                                ];
                            }
                            if (!isset($params['confirmRetry'])) {
                                $params['confirmRetry'] = [
                                    'header' => 'Recommencer ?',
                                    'body' => 'Êtes-vous certain de vouloir recommencer ?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Confirmer'
                                ];
                            }
                        }
                        
                        // Enrichir les TrueFalse avec tous les champs requis
                        if (strpos($lib, 'H5P.TrueFalse') !== false) {
                            $params = &$element['action']['params'];
                            
                            // IMPORTANT: correct doit être STRING "true"/"false"
                            if (isset($params['correct'])) {
                                if (is_bool($params['correct'])) {
                                    $params['correct'] = $params['correct'] ? 'true' : 'false';
                                }
                                $params['correct'] = (string) $params['correct'];
                            } else {
                                $params['correct'] = 'true';
                            }
                            
                            // Convertir <b>→<strong>, <i>→<em> dans la question
                            if (isset($params['question'])) {
                                $params['question'] = preg_replace('/<b(\s|>)/i', '<strong$1', $params['question']);
                                $params['question'] = str_ireplace('</b>', '</strong>', $params['question']);
                                $params['question'] = preg_replace('/<i(\s|>)/i', '<em$1', $params['question']);
                                $params['question'] = str_ireplace('</i>', '</em>', $params['question']);
                            }
                            
                            // Ajouter media s'il manque
                            if (!isset($params['media'])) {
                                $params['media'] = [
                                    'disableImageZooming' => false,
                                    'type' => ['params' => new \stdClass()]
                                ];
                            }
                            
                            // Ajouter behaviour complet
                            if (!isset($params['behaviour'])) {
                                $params['behaviour'] = [
                                    'enableRetry' => true,
                                    'enableSolutionsButton' => true,
                                    'enableCheckButton' => true,
                                    'confirmCheckDialog' => false,
                                    'confirmRetryDialog' => false
                                ];
                            }
                            
                            // Ajouter l10n complet
                            if (!isset($params['l10n'])) {
                                $params['l10n'] = [
                                    'trueText' => 'Vrai',
                                    'falseText' => 'Faux',
                                    'checkAnswer' => 'Vérifier',
                                    'submitAnswer' => 'Envoyer',
                                    'showSolutionButton' => 'Voir la solution',
                                    'tryAgain' => 'Recommencer',
                                    'correctAnswerMessage' => 'Bonne réponse !',
                                    'wrongAnswerMessage' => 'Mauvaise réponse',
                                    'scoreBarLabel' => 'Vous avez obtenu :num points sur :total',
                                    'a11yCheck' => 'Vérifiez les réponses.',
                                    'a11yShowSolution' => 'Montrez la solution.',
                                    'a11yRetry' => 'Réessayez la tâche.'
                                ];
                            }
                            
                            // Ajouter confirmCheck et confirmRetry
                            if (!isset($params['confirmCheck'])) {
                                $params['confirmCheck'] = [
                                    'header' => 'Terminer ?',
                                    'body' => 'Êtes-vous sûr de vouloir terminer ?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Terminer'
                                ];
                            }
                            if (!isset($params['confirmRetry'])) {
                                $params['confirmRetry'] = [
                                    'header' => 'Recommencer ?',
                                    'body' => 'Êtes-vous sûr de vouloir recommencer ?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Confirmer'
                                ];
                            }
                        }
                        
                        // Enrichir les Blanks avec tous les champs requis
                        if (strpos($lib, 'H5P.Blanks') !== false) {
                            $params = &$element['action']['params'];
                            
                            // Ajouter media s'il manque
                            if (!isset($params['media'])) {
                                $params['media'] = [
                                    'type' => ['params' => new \stdClass()],
                                    'disableImageZooming' => false
                                ];
                            }
                            
                            // S'assurer que questions existe
                            if (!isset($params['questions']) || empty($params['questions'])) {
                                $text = $params['text'] ?? 'Le mot *manquant*.';
                                if (strpos($text, '<p>') === false) {
                                    $text = '<p>' . $text . '</p>';
                                }
                                $params['questions'] = [$text];
                            }
                            
                            // Si text contient des astérisques, c'est le texte à trous, pas l'instruction
                            if (isset($params['text']) && strpos($params['text'], '*') !== false) {
                                $params['text'] = 'Complétez les mots manquants';
                            }
                            
                            // Convertir <b>→<strong>, <i>→<em> pour compatibilité Éléa
                            foreach ($params['questions'] as &$bq) {
                                $bq = preg_replace('/<b(\s|>)/i', '<strong$1', $bq);
                                $bq = str_ireplace('</b>', '</strong>', $bq);
                                $bq = preg_replace('/<i(\s|>)/i', '<em$1', $bq);
                                $bq = str_ireplace('</i>', '</em>', $bq);
                            }
                            unset($bq);
                            
                            // Ajouter overallFeedback s'il manque
                            if (!isset($params['overallFeedback'])) {
                                $params['overallFeedback'] = [['from' => 0, 'to' => 100]];
                            }
                            
                            // Ajouter behaviour complet
                            if (!isset($params['behaviour'])) {
                                $params['behaviour'] = [
                                    'enableRetry' => true,
                                    'enableSolutionsButton' => true,
                                    'enableCheckButton' => true,
                                    'autoCheck' => false,
                                    'caseSensitive' => false,
                                    'showSolutionsRequiresInput' => true,
                                    'separateLines' => false,
                                    'confirmCheckDialog' => false,
                                    'confirmRetryDialog' => false,
                                    'acceptSpellingErrors' => false
                                ];
                            }
                            
                            // Ajouter tous les textes de localisation
                            $blanksTexts = [
                                'showSolutions' => 'Voir la correction',
                                'tryAgain' => 'Recommencer',
                                'checkAnswer' => 'Vérifier',
                                'submitAnswer' => 'Vérifier',
                                'notFilledOut' => 'Vous devez avoir rempli tous les blancs avant de voir la correction',
                                'answerIsCorrect' => "':ans' est une réponse exacte",
                                'answerIsWrong' => "':ans' est une réponse inexacte",
                                'answeredCorrectly' => 'Réponse exacte',
                                'answeredIncorrectly' => 'Mauvaise réponse',
                                'solutionLabel' => 'Réponse correcte :',
                                'inputLabel' => 'Blanc @num sur @total',
                                'inputHasTipLabel' => 'Indice disponible',
                                'tipLabel' => 'Indice',
                                'scoreBarLabel' => 'Vous avez obtenu :num points sur un total de :total',
                                'a11yCheck' => 'Vérifiez les réponses.',
                                'a11yShowSolution' => 'Montrez la solution.',
                                'a11yRetry' => 'Réessayez la tâche.',
                                'a11yCheckingModeHeader' => 'Mode de contrôle'
                            ];
                            foreach ($blanksTexts as $key => $value) {
                                if (!isset($params[$key])) $params[$key] = $value;
                            }
                            
                            // Ajouter confirmCheck et confirmRetry
                            if (!isset($params['confirmCheck'])) {
                                $params['confirmCheck'] = [
                                    'header' => 'Terminer ?',
                                    'body' => 'Êtes-vous sûr de vouloir terminer ?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Terminer'
                                ];
                            }
                            if (!isset($params['confirmRetry'])) {
                                $params['confirmRetry'] = [
                                    'header' => 'Recommencer ?',
                                    'body' => 'Êtes-vous sûr de vouloir recommencer ?',
                                    'cancelLabel' => 'Annuler',
                                    'confirmLabel' => 'Confirmer'
                                ];
                            }
                        }
                        
                        // Enrichir les SingleChoiceSet (Vrai/Faux) avec tous les champs requis
                        if (strpos($lib, 'H5P.SingleChoiceSet') !== false) {
                            $params = &$element['action']['params'];
                            
                            // S'assurer que choices existe et a la bonne structure
                            if (!isset($params['choices']) || empty($params['choices'])) {
                                $params['choices'] = [[
                                    'subContentId' => $this->generateUUID(),
                                    'question' => '<p>Question ?</p>',
                                    'answers' => ['<p>Vrai</p>', '<p>Faux</p>']
                                ]];
                            } else {
                                // Enrichir chaque choice avec subContentId si manquant
                                foreach ($params['choices'] as &$choice) {
                                    if (!isset($choice['subContentId'])) {
                                        $choice['subContentId'] = $this->generateUUID();
                                    }
                                    // S'assurer que question est en HTML
                                    if (isset($choice['question']) && strpos($choice['question'], '<') === false) {
                                        $choice['question'] = '<p>' . htmlspecialchars($choice['question']) . '</p>';
                                    }
                                    // S'assurer que answers sont en HTML
                                    if (isset($choice['answers']) && is_array($choice['answers'])) {
                                        foreach ($choice['answers'] as &$ans) {
                                            if (strpos($ans, '<') === false) {
                                                $ans = '<p>' . htmlspecialchars($ans) . '</p>';
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // Ajouter overallFeedback s'il manque
                            if (!isset($params['overallFeedback'])) {
                                $params['overallFeedback'] = [['from' => 0, 'to' => 100]];
                            }
                            
                            // Ajouter behaviour complet
                            if (!isset($params['behaviour'])) {
                                $params['behaviour'] = [
                                    'autoContinue' => true,
                                    'timeoutCorrect' => 2000,
                                    'timeoutWrong' => 3000,
                                    'soundEffectsEnabled' => false,
                                    'enableRetry' => true,
                                    'enableSolutionsButton' => false,
                                    'passPercentage' => 100
                                ];
                            } else {
                                // S'assurer que les options par défaut sont présentes
                                if (!isset($params['behaviour']['autoContinue'])) $params['behaviour']['autoContinue'] = true;
                                if (!isset($params['behaviour']['timeoutCorrect'])) $params['behaviour']['timeoutCorrect'] = 2000;
                                if (!isset($params['behaviour']['timeoutWrong'])) $params['behaviour']['timeoutWrong'] = 3000;
                                if (!isset($params['behaviour']['soundEffectsEnabled'])) $params['behaviour']['soundEffectsEnabled'] = false;
                                if (!isset($params['behaviour']['passPercentage'])) $params['behaviour']['passPercentage'] = 100;
                            }
                            
                            // Ajouter l10n complet
                            if (!isset($params['l10n'])) {
                                $params['l10n'] = $this->getSingleChoiceSetL10n();
                            }
                        }
                        
                        // Enrichir les DragQuestion avec tous les champs requis
                        if (strpos($lib, 'H5P.DragQuestion') !== false) {
                            $params = &$element['action']['params'];
                            
                            // S'assurer que question existe avec la bonne structure
                            if (!isset($params['question'])) {
                                $params['question'] = [
                                    'settings' => ['size' => ['width' => 800, 'height' => 400], 'background' => new \stdClass()],
                                    'task' => ['elements' => [], 'dropZones' => []]
                                ];
                            }
                            
                            // S'assurer que settings a la taille
                            if (!isset($params['question']['settings']['size'])) {
                                $params['question']['settings']['size'] = ['width' => 800, 'height' => 400];
                            }
                            
                            // S'assurer que background est un objet s'il est vide
                            if (!isset($params['question']['settings']['background']) || 
                                (is_array($params['question']['settings']['background']) && empty($params['question']['settings']['background']))) {
                                $params['question']['settings']['background'] = new \stdClass();
                            } else if (is_array($params['question']['settings']['background']) && 
                                       isset($params['question']['settings']['background']['path']) &&
                                       !empty($params['question']['settings']['background']['path'])) {
                                // Le background a un chemin - s'assurer qu'il a tous les champs requis
                                $bg = &$params['question']['settings']['background'];
                                $bgPath = $bg['path'];
                                error_log("EleaMbzExporter - DragQuestion background path détecté: " . $bgPath);
                                $ext = strtolower(pathinfo($bgPath, PATHINFO_EXTENSION));
                                $mimeMap = [
                                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                                    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'
                                ];
                                
                                if (!isset($bg['mime'])) {
                                    $bg['mime'] = $mimeMap[$ext] ?? 'image/png';
                                }
                                if (!isset($bg['copyright'])) {
                                    $bg['copyright'] = ['license' => 'U'];
                                }
                            }
                            
                            // S'assurer que task existe
                            if (!isset($params['question']['task'])) {
                                $params['question']['task'] = ['elements' => [], 'dropZones' => []];
                            }
                            
                            // Enrichir chaque élément draggable
                            if (isset($params['question']['task']['elements']) && is_array($params['question']['task']['elements'])) {
                                foreach ($params['question']['task']['elements'] as &$elem) {
                                    if (!isset($elem['backgroundOpacity'])) $elem['backgroundOpacity'] = 100;
                                    if (!isset($elem['multiple'])) $elem['multiple'] = false;
                                    if (!isset($elem['dropZones'])) $elem['dropZones'] = [];
                                    
                                    // S'assurer que type a la structure correcte
                                    if (isset($elem['type'])) {
                                        if (!isset($elem['type']['subContentId'])) {
                                            $elem['type']['subContentId'] = $this->generateUUID();
                                        }
                                        
                                        // Détecter le type de la library
                                        $elemLib = $elem['type']['library'] ?? '';
                                        $isImageLib = (stripos($elemLib, 'H5P.Image') !== false);
                                        
                                        if (!isset($elem['type']['metadata'])) {
                                            $elem['type']['metadata'] = [
                                                'contentType' => $isImageLib ? 'Image' : 'Text',
                                                'license' => 'U',
                                                'title' => $isImageLib ? 'Sans titre Image' : 'Sans titre Text'
                                            ];
                                        }
                                        
                                        // Enrichir les champs spécifiques Image
                                        if ($isImageLib) {
                                            if (!isset($elem['type']['params']['decorative'])) {
                                                $elem['type']['params']['decorative'] = empty($elem['type']['params']['alt']);
                                            }
                                            if (!isset($elem['type']['params']['contentName'])) $elem['type']['params']['contentName'] = 'Image';
                                            if (!isset($elem['type']['params']['expandImage'])) $elem['type']['params']['expandImage'] = 'Expand Image';
                                            if (!isset($elem['type']['params']['minimizeImage'])) $elem['type']['params']['minimizeImage'] = 'Minimize Image';
                                            if (isset($elem['type']['params']['file'])) {
                                                if (!isset($elem['type']['params']['file']['copyright'])) {
                                                    $elem['type']['params']['file']['copyright'] = ['license' => 'U'];
                                                }
                                            }
                                            // backgroundOpacity=0 pour les images (transparent)
                                            if (!isset($elem['backgroundOpacity']) || $elem['backgroundOpacity'] === 100) {
                                                $elem['backgroundOpacity'] = 0;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // Récupérer l'opacité globale des zones définie dans settings
                            $globalZoneOpacity = $params['question']['settings']['dropZoneOpacity'] ?? 0;
                            
                            // Enrichir chaque zone de dépôt
                            if (isset($params['question']['task']['dropZones']) && is_array($params['question']['task']['dropZones'])) {
                                foreach ($params['question']['task']['dropZones'] as &$dz) {
                                    if (!isset($dz['showLabel'])) $dz['showLabel'] = false;
                                    // Utiliser l'opacité globale si non définie individuellement
                                    if (!isset($dz['backgroundOpacity'])) $dz['backgroundOpacity'] = $globalZoneOpacity;
                                    if (!isset($dz['tipsAndFeedback'])) $dz['tipsAndFeedback'] = ['tip' => ''];
                                    if (!isset($dz['single'])) $dz['single'] = true;
                                    if (!isset($dz['autoAlign'])) $dz['autoAlign'] = false;
                                    if (!isset($dz['correctElements'])) $dz['correctElements'] = [];
                                    if (!isset($dz['type'])) {
                                        $dz['type'] = ['library' => 'H5P.DragQuestionDropzone 0.1'];
                                    }
                                }
                            }
                            
                            // Ajouter overallFeedback s'il manque
                            if (!isset($params['overallFeedback'])) {
                                $params['overallFeedback'] = [['from' => 0, 'to' => 100]];
                            }
                            
                            // Ajouter behaviour complet
                            if (!isset($params['behaviour'])) {
                                $params['behaviour'] = [
                                    'enableRetry' => true,
                                    'enableCheckButton' => true,
                                    'singlePoint' => false,
                                    'applyPenalties' => true,
                                    'enableScoreExplanation' => true,
                                    'dropZoneHighlighting' => 'dragging',
                                    'autoAlignSpacing' => 2,
                                    'enableFullScreen' => false,
                                    'showScorePoints' => true,
                                    'showTitle' => false
                                ];
                            }
                            
                            // Ajouter les textes de localisation
                            $dqL10n = [
                                'scoreShow' => 'Vérifier',
                                'submit' => 'Envoyer',
                                'tryAgain' => 'Recommencer',
                                'scoreExplanation' => 'Les réponses correctes donnent +1 point. Les réponses incorrectes -1 point. Le score minimum est 0.',
                                'grabbablePrefix' => 'Éléments déplaçables {num} de {total}.',
                                'grabbableSuffix' => 'Placé dans zone {num}.',
                                'dropzonePrefix' => 'Zone de dépôt {num} de {total}.',
                                'noDropzone' => 'Pas de zone de dépôt.',
                                'tipLabel' => 'Montrer l\'indice.',
                                'tipAvailable' => 'Indice disponible',
                                'correctAnswer' => 'Bonne réponse',
                                'wrongAnswer' => 'Réponse incorrecte',
                                'feedbackHeader' => 'Commentaire',
                                'scoreBarLabel' => 'Vous avez :num sur :total au total',
                                'scoreExplanationButtonLabel' => 'Montrer l\'explication du score',
                                'a11yCheck' => 'Vérifier les réponses.',
                                'a11yRetry' => 'Réessayer la tâche.'
                            ];
                            foreach ($dqL10n as $key => $value) {
                                if (!isset($params[$key])) $params[$key] = $value;
                            }
                            
                            // Ajouter localize s'il manque
                            if (!isset($params['localize'])) {
                                $params['localize'] = [
                                    'fullscreen' => 'Plein écran',
                                    'exitFullscreen' => 'Quitter le plein écran'
                                ];
                            }
                        }
                        
                        // Enrichir InteractiveVideo avec override, l10n, et interactions
                        if (strpos($lib, 'H5P.InteractiveVideo') !== false) {
                            $params = &$element['action']['params'];
                            
                            // Ajouter override avec valeurs par défaut
                            if (!isset($params['override'])) {
                                $params['override'] = [
                                    'autoplay' => false,
                                    'loop' => false,
                                    'showBookmarksmenuOnLoad' => false,
                                    'showRewind10' => false,
                                    'preventSkippingMode' => 'none',
                                    'deactivateSound' => true,
                                    'showSolutionButton' => 'off'
                                ];
                            }
                            
                            // Ajouter l10n si manquant
                            if (!isset($params['l10n'])) {
                                $params['l10n'] = $this->getInteractiveVideoL10n();
                            }
                            
                            // Ajouter summary si manquant
                            if (!isset($params['interactiveVideo']['summary'])) {
                                $params['interactiveVideo']['summary'] = $this->getInteractiveVideoSummary();
                            }
                            
                            // Enrichir les interactions
                            if (isset($params['interactiveVideo']['assets']['interactions'])) {
                                $params['interactiveVideo']['assets']['interactions'] = $this->enrichIvInteractions(
                                    $params['interactiveVideo']['assets']['interactions']
                                );
                                
                                // Forcer caseSensitive: false pour les Blanks dans les interactions
                                foreach ($params['interactiveVideo']['assets']['interactions'] as &$ivInter) {
                                    if (isset($ivInter['action']['library']) && strpos($ivInter['action']['library'], 'H5P.Blanks') !== false) {
                                        if (!isset($ivInter['action']['params']['behaviour'])) {
                                            $ivInter['action']['params']['behaviour'] = [];
                                        }
                                        $ivInter['action']['params']['behaviour']['caseSensitive'] = false;
                                    }
                                }
                                unset($ivInter);
                            }
                            
                            // Ajouter bookmarks et endscreens si manquants
                            if (!isset($params['interactiveVideo']['assets']['bookmarks'])) {
                                $params['interactiveVideo']['assets']['bookmarks'] = [];
                            }
                            if (!isset($params['interactiveVideo']['assets']['endscreens'])) {
                                $params['interactiveVideo']['assets']['endscreens'] = [];
                            }
                        }
                    }
                }
            }
        }
        
        // S'assurer que globalBackgroundSelector est un objet (pas un tableau vide)
        $globalBgSelector = $content['presentation']['globalBackgroundSelector'] ?? new \stdClass();
        if (is_array($globalBgSelector) && empty($globalBgSelector)) {
            $globalBgSelector = new \stdClass();
        }
        
        // Préparer override avec social comme objet
        $override = $content['override'] ?? [];
        if (!isset($override['activeSurface'])) $override['activeSurface'] = false;
        if (!isset($override['hideSummarySlide'])) $override['hideSummarySlide'] = false;
        if (!isset($override['summarySlideSolutionButton'])) $override['summarySlideSolutionButton'] = true;
        if (!isset($override['summarySlideRetryButton'])) $override['summarySlideRetryButton'] = true;
        if (!isset($override['enablePrintButton'])) $override['enablePrintButton'] = false;
        
        // S'assurer que social est un objet (pas un tableau vide)
        if (!isset($override['social']) || (is_array($override['social']) && empty($override['social']))) {
            $override['social'] = [
                'showFacebookShare' => false,
                'facebookShare' => [
                    'url' => '@currentpageurl',
                    'quote' => 'J\'ai un score de @score sur un total de @maxScore pour @currentpageurl.'
                ],
                'showTwitterShare' => false,
                'twitterShare' => [
                    'statement' => 'J\'ai un score de @score sur un total de @maxScore pour @currentpageurl.',
                    'url' => '@currentpageurl',
                    'hashtags' => 'h5p, cours'
                ],
                'showGoogleShare' => false,
                'googleShareUrl' => '@currentpageurl'
            ];
        }
        
        // Toujours construire la structure complète avec l10n et override
        return [
            'presentation' => [
                'slides' => $slides,
                'keywordListEnabled' => $content['presentation']['keywordListEnabled'] ?? true,
                'globalBackgroundSelector' => $globalBgSelector,
                'keywordListAlwaysShow' => $content['presentation']['keywordListAlwaysShow'] ?? false,
                'keywordListAutoHide' => $content['presentation']['keywordListAutoHide'] ?? false,
                'keywordListOpacity' => $content['presentation']['keywordListOpacity'] ?? 90
            ],
            'override' => $override,
            'l10n' => $content['l10n'] ?? $this->getCoursePresentationL10n()
        ];
    }
    
    private function buildInteractiveVideoContent($content) {
        if (isset($content['interactiveVideo'])) {
            // Contenu déjà complet (importé), enrichir les clés manquantes
            $result = $content;
            if (!isset($result['override'])) {
                $result['override'] = [
                    'autoplay' => false,
                    'loop' => false,
                    'showBookmarksmenuOnLoad' => false,
                    'showRewind10' => false,
                    'preventSkippingMode' => 'none',
                    'deactivateSound' => false
                ];
            }
            if (!isset($result['l10n'])) {
                $result['l10n'] = $this->getInteractiveVideoL10n();
            }
            if (!isset($result['interactiveVideo']['summary'])) {
                $result['interactiveVideo']['summary'] = $this->getInteractiveVideoSummary();
            }
            // Enrichir les interactions
            if (isset($result['interactiveVideo']['assets']['interactions'])) {
                $result['interactiveVideo']['assets']['interactions'] = $this->enrichIvInteractions(
                    $result['interactiveVideo']['assets']['interactions']
                );
            }
            if (!isset($result['interactiveVideo']['assets']['bookmarks'])) {
                $result['interactiveVideo']['assets']['bookmarks'] = [];
            }
            if (!isset($result['interactiveVideo']['assets']['endscreens'])) {
                $result['interactiveVideo']['assets']['endscreens'] = [];
            }
            return $result;
        }
        
        $videoUrl = $content['video']['url'] ?? '';
        $interactions = $this->enrichIvInteractions($content['video']['interactions'] ?? []);
        
        return [
            'interactiveVideo' => [
                'video' => [
                    'startScreenOptions' => [
                        'title' => 'Vidéo interactive',
                        'hideStartTitle' => false
                    ],
                    'textTracks' => [
                        'videoTrack' => [
                            [
                                'label' => 'Sous-titres',
                                'kind' => 'subtitles',
                                'srcLang' => 'fr'
                            ]
                        ]
                    ],
                    'files' => $videoUrl ? [['path' => $videoUrl, 'mime' => 'video/mp4', 'copyright' => ['license' => 'U']]] : []
                ],
                'assets' => [
                    'interactions' => $interactions,
                    'bookmarks' => [],
                    'endscreens' => []
                ],
                'summary' => $this->getInteractiveVideoSummary()
            ],
            'override' => [
                'autoplay' => false,
                'loop' => false,
                'showBookmarksmenuOnLoad' => false,
                'showRewind10' => false,
                'preventSkippingMode' => 'none',
                'deactivateSound' => false
            ],
            'l10n' => $this->getInteractiveVideoL10n()
        ];
    }
    
    
    /**
     * Enrichit les interactions d'une vidéo interactive avec les propriétés requises par H5P
     */
    private function enrichIvInteractions($interactions) {
        $enriched = [];
        foreach ($interactions as $inter) {
            // Assurer les propriétés de base
            if (!isset($inter['pause'])) {
                $inter['pause'] = true;  // Pause par défaut pour toutes les interactions
            }
            if (!isset($inter['displayType'])) {
                $inter['displayType'] = 'poster';
            }
            if (!isset($inter['buttonOnMobile'])) {
                $inter['buttonOnMobile'] = false;
            }
            if (!isset($inter['adaptivity'])) {
                $inter['adaptivity'] = [
                    'correct' => ['allowOptOut' => false, 'message' => ''],
                    'wrong' => ['allowOptOut' => false, 'message' => ''],
                    'requireCompletion' => false
                ];
            }
            // S'assurer que label est en HTML
            if (isset($inter['label']) && is_string($inter['label']) && strpos($inter['label'], '<') === false) {
                $inter['label'] = '<p>' . $this->xmlEncode($inter['label']) . '</p>';
            }
            // S'assurer que duration existe
            if (!isset($inter['duration'])) {
                $from = $inter['time'] ?? 0;
                $inter['duration'] = ['from' => $from, 'to' => $from + 10];
            }
            // Enrichir action avec subContentId et metadata
            if (isset($inter['action']['library'])) {
                if (!isset($inter['action']['subContentId'])) {
                    $inter['action']['subContentId'] = $this->generateUUID();
                }
                if (!isset($inter['action']['metadata'])) {
                    $contentType = $this->getContentTypeFromLibrary($inter['action']['library']);
                    $inter['action']['metadata'] = [
                        'contentType' => $contentType,
                        'license' => 'U',
                        'title' => 'Sans titre ' . $contentType,
                        'authors' => [],
                        'changes' => []
                    ];
                }
                // Tag conversion <b> -> <strong>, <i> -> <em>
                if (isset($inter['action']['params']['text'])) {
                    $inter['action']['params']['text'] = preg_replace('/<b(\s|>)/i', '<strong$1', $inter['action']['params']['text']);
                    $inter['action']['params']['text'] = preg_replace('/<\/b>/i', '</strong>', $inter['action']['params']['text']);
                    $inter['action']['params']['text'] = preg_replace('/<i(\s|>)/i', '<em$1', $inter['action']['params']['text']);
                    $inter['action']['params']['text'] = preg_replace('/<\/i>/i', '</em>', $inter['action']['params']['text']);
                }
                if (isset($inter['action']['params']['question'])) {
                    $inter['action']['params']['question'] = preg_replace('/<b(\s|>)/i', '<strong$1', $inter['action']['params']['question']);
                    $inter['action']['params']['question'] = preg_replace('/<\/b>/i', '</strong>', $inter['action']['params']['question']);
                    $inter['action']['params']['question'] = preg_replace('/<i(\s|>)/i', '<em$1', $inter['action']['params']['question']);
                    $inter['action']['params']['question'] = preg_replace('/<\/i>/i', '</em>', $inter['action']['params']['question']);
                }
                // TrueFalse: forcer correct en string et ajouter l10n FR
                if (strpos($inter['action']['library'], 'TrueFalse') !== false) {
                    if (isset($inter['action']['params']['correct'])) {
                        $v = $inter['action']['params']['correct'];
                        if (is_bool($v)) $inter['action']['params']['correct'] = $v ? 'true' : 'false';
                    }
                    // Ajouter l10n FR
                    if (!isset($inter['action']['params']['l10n'])) {
                        $inter['action']['params']['l10n'] = [];
                    }
                    $tfL10nDefaults = [
                        'trueText' => 'Vrai',
                        'falseText' => 'Faux',
                        'score' => 'Vous avez obtenu @score points sur un total de @total',
                        'checkAnswer' => 'Vérifier',
                        'submitAnswer' => 'Vérifier',
                        'showSolutionButton' => 'Voir la solution',
                        'tryAgain' => 'Recommencer',
                        'wrongAnswerMessage' => 'Réponse incorrecte',
                        'correctAnswerMessage' => 'Bonne réponse',
                        'scoreBarLabel' => 'Vous avez obtenu @score points sur un total de @total',
                        'a11yCheck' => 'Vérifiez les réponses.',
                        'a11yShowSolution' => 'Montrer la solution.',
                        'a11yRetry' => 'Réessayer.'
                    ];
                    foreach ($tfL10nDefaults as $k => $v) {
                        if (!isset($inter['action']['params']['l10n'][$k])) {
                            $inter['action']['params']['l10n'][$k] = $v;
                        }
                    }
                    // Ajouter behaviour, media, confirmCheck, confirmRetry
                    if (!isset($inter['action']['params']['media'])) {
                        $inter['action']['params']['media'] = [
                            'type' => ['params' => new \stdClass()],
                            'disableImageZooming' => false
                        ];
                    }
                    if (!isset($inter['action']['params']['behaviour'])) {
                        $inter['action']['params']['behaviour'] = [
                            'enableRetry' => true,
                            'enableSolutionsButton' => true,
                            'enableCheckButton' => true,
                            'confirmCheckDialog' => false,
                            'confirmRetryDialog' => false,
                            'autoCheck' => false
                        ];
                    }
                    if (!isset($inter['action']['params']['confirmCheck'])) {
                        $inter['action']['params']['confirmCheck'] = [
                            'header' => 'Terminer ?',
                            'body' => 'Voulez-vous vraiment terminer ?',
                            'cancelLabel' => 'Annuler',
                            'confirmLabel' => 'Confirmer'
                        ];
                    }
                    if (!isset($inter['action']['params']['confirmRetry'])) {
                        $inter['action']['params']['confirmRetry'] = [
                            'header' => 'Recommencer ?',
                            'body' => 'Voulez-vous vraiment recommencer ?',
                            'cancelLabel' => 'Annuler',
                            'confirmLabel' => 'Confirmer'
                        ];
                    }
                }
                
                // Blanks: enrichir avec l10n, behaviour, media
                if (strpos($inter['action']['library'], 'Blanks') !== false) {
                    $bParams = &$inter['action']['params'];
                    // S'assurer que questions existe
                    if (!isset($bParams['questions']) || empty($bParams['questions'])) {
                        $text = $bParams['text'] ?? 'Le mot *manquant*.';
                        if (strpos($text, '<p>') === false) $text = '<p>' . $text . '</p>';
                        $bParams['questions'] = [$text];
                    }
                    // Si text contient des astérisques, c'est le texte à trous, pas l'instruction
                    if (isset($bParams['text']) && strpos($bParams['text'], '*') !== false) {
                        $bParams['text'] = 'Complétez les mots manquants';
                    }
                    if (!isset($bParams['media'])) {
                        $bParams['media'] = [
                            'type' => ['params' => new \stdClass()],
                            'disableImageZooming' => false
                        ];
                    }
                    if (!isset($bParams['behaviour'])) {
                        $bParams['behaviour'] = [
                            'enableRetry' => true, 'enableSolutionsButton' => true,
                            'enableCheckButton' => true, 'autoCheck' => false,
                            'caseSensitive' => false, 'showSolutionsRequiresInput' => true,
                            'separateLines' => false, 'confirmCheckDialog' => false,
                            'confirmRetryDialog' => false, 'acceptSpellingErrors' => false
                        ];
                    }
                    $bParams['behaviour']['caseSensitive'] = false;
                    if (!isset($bParams['overallFeedback'])) {
                        $bParams['overallFeedback'] = [['from' => 0, 'to' => 100]];
                    }
                    $blanksL10n = [
                        'showSolutions' => 'Voir la correction',
                        'tryAgain' => 'Recommencer',
                        'checkAnswer' => 'Vérifier',
                        'submitAnswer' => 'Vérifier',
                        'notFilledOut' => 'Vous devez avoir rempli tous les blancs',
                        'answerIsCorrect' => "':ans' est une réponse exacte",
                        'answerIsWrong' => "':ans' est une réponse inexacte",
                        'answeredCorrectly' => 'Réponse exacte',
                        'answeredIncorrectly' => 'Mauvaise réponse',
                        'solutionLabel' => 'Réponse correcte :',
                        'inputLabel' => 'Blanc @num sur @total',
                        'inputHasTipLabel' => 'Indice disponible',
                        'tipLabel' => 'Indice',
                        'scoreBarLabel' => 'Vous avez obtenu :num points sur un total de :total',
                        'a11yCheck' => 'Vérifiez les réponses.',
                        'a11yShowSolution' => 'Montrez la solution.',
                        'a11yRetry' => 'Réessayez.',
                        'a11yCheckingModeHeader' => 'Mode de contrôle'
                    ];
                    foreach ($blanksL10n as $k => $v) {
                        if (!isset($bParams[$k])) $bParams[$k] = $v;
                    }
                    if (!isset($bParams['confirmCheck'])) {
                        $bParams['confirmCheck'] = [
                            'header' => 'Terminer ?',
                            'body' => 'Êtes-vous sûr de vouloir terminer ?',
                            'cancelLabel' => 'Annuler',
                            'confirmLabel' => 'Terminer'
                        ];
                    }
                    if (!isset($bParams['confirmRetry'])) {
                        $bParams['confirmRetry'] = [
                            'header' => 'Recommencer ?',
                            'body' => 'Êtes-vous sûr de vouloir recommencer ?',
                            'cancelLabel' => 'Annuler',
                            'confirmLabel' => 'Confirmer'
                        ];
                    }
                    unset($bParams);
                }
                
                // MultiChoice: enrichir
                if (strpos($inter['action']['library'], 'MultiChoice') !== false) {
                    $mcParams = &$inter['action']['params'];
                    if (!isset($mcParams['media'])) {
                        $mcParams['media'] = [
                            'type' => ['params' => new \stdClass()],
                            'disableImageZooming' => false
                        ];
                    }
                    if (!isset($mcParams['overallFeedback'])) {
                        $mcParams['overallFeedback'] = [['from' => 0, 'to' => 100]];
                    }
                    if (!isset($mcParams['behaviour'])) {
                        $mcParams['behaviour'] = [
                            'enableRetry' => true, 'enableSolutionsButton' => false,
                            'enableCheckButton' => true, 'type' => 'auto',
                            'singlePoint' => false, 'randomAnswers' => true,
                            'showSolutionsRequiresInput' => true, 'confirmCheckDialog' => false,
                            'confirmRetryDialog' => false, 'autoCheck' => false,
                            'passPercentage' => 100, 'showScorePoints' => true
                        ];
                    }
                    if (!isset($mcParams['UI'])) {
                        $mcParams['UI'] = $this->getMultiChoiceUI();
                    }
                    if (!isset($mcParams['confirmCheck'])) {
                        $mcParams['confirmCheck'] = [
                            'header' => 'Terminer ?',
                            'body' => 'Êtes-vous sûr de vouloir terminer ?',
                            'cancelLabel' => 'Annuler',
                            'confirmLabel' => 'Terminer'
                        ];
                    }
                    if (!isset($mcParams['confirmRetry'])) {
                        $mcParams['confirmRetry'] = [
                            'header' => 'Recommencer ?',
                            'body' => 'Êtes-vous sûr de vouloir recommencer ?',
                            'cancelLabel' => 'Annuler',
                            'confirmLabel' => 'Confirmer'
                        ];
                    }
                    // Enrichir les réponses
                    if (isset($mcParams['answers'])) {
                        foreach ($mcParams['answers'] as &$ans) {
                            if (!isset($ans['tpiAndTci'])) {
                                $ans['tpiAndTci'] = ['tip' => '', 'chosenFeedback' => '', 'notChosenFeedback' => ''];
                            }
                        }
                        unset($ans);
                    }
                    unset($mcParams);
                }
            }
            $enriched[] = $inter;
        }
        return $enriched;
    }

    private function buildQuestionSetContent($content) {
        $questions = $content['questions'] ?? [];
        return [
            'introPage' => [
                'showIntroPage' => false,
                'title' => '',
                'introduction' => ''
            ],
            'progressType' => 'dots',
            'passPercentage' => 50,
            'questions' => $questions,
            'disableBackwardsNavigation' => false,
            'randomQuestions' => false,
            'endGame' => [
                'showResultPage' => true,
                'showSolutionButton' => true,
                'showRetryButton' => true,
                'noResultMessage' => 'Quiz terminé',
                'message' => 'Votre résultat :',
                'scoreString' => '@score sur @total',
                'successGreeting' => 'Félicitations !',
                'successComment' => 'Vous avez réussi !',
                'failGreeting' => 'Dommage...',
                'failComment' => 'Essayez encore !',
                'solutionButtonText' => 'Voir les réponses',
                'retryButtonText' => 'Recommencer',
                'finishButtonText' => 'Terminer',
                'showAnimations' => false,
                'skipButtonText' => 'Passer la vidéo'
            ],
            'override' => [
                'checkButton' => true,
                'showSolutionButton' => 'on',
                'retryButton' => 'on'
            ],
            'texts' => [
                'prevButton' => 'Précédent',
                'nextButton' => 'Suivant',
                'finishButton' => 'Terminer',
                'textualProgress' => 'Question @current sur @total',
                'jumpToQuestion' => 'Question %d sur %total',
                'questionLabel' => 'Question',
                'readSpeakerProgress' => 'Question @current sur @total',
                'unansweredText' => 'Non répondu'
            ]
        ];
    }
    
    private function buildMultiChoiceContent($content) {
        // Enrichir les réponses avec tipsAndFeedback si manquant
        $answers = $content['answers'] ?? [];
        foreach ($answers as &$answer) {
            if (!isset($answer['tipsAndFeedback'])) {
                $answer['tipsAndFeedback'] = [
                    'tip' => '',
                    'chosenFeedback' => '',
                    'notChosenFeedback' => ''
                ];
            }
            // S'assurer que le texte est bien formaté en HTML
            if (isset($answer['text']) && strpos($answer['text'], '<') === false) {
                $answer['text'] = '<div>' . htmlspecialchars($answer['text']) . '</div>';
            }
        }
        unset($answer);
        
        // Wrapper la question en HTML si nécessaire
        $question = $content['question'] ?? '<p>Question ?</p>';
        if (!empty($question) && strpos($question, '<') === false) {
            $question = '<p>' . htmlspecialchars($question) . '</p>';
        }
        // Convertir <b>→<strong>, <i>→<em> pour compatibilité Éléa
        $question = preg_replace('/<b(\s|>)/i', '<strong$1', $question);
        $question = str_ireplace('</b>', '</strong>', $question);
        $question = preg_replace('/<i(\s|>)/i', '<em$1', $question);
        $question = str_ireplace('</i>', '</em>', $question);
        
        return [
            'media' => [
                'disableImageZooming' => false,
                'type' => ['params' => new \stdClass()]
            ],
            'question' => $question,
            'answers' => $answers,
            'overallFeedback' => [['from' => 0, 'to' => 100]],
            'behaviour' => [
                'enableRetry' => $content['behaviour']['enableRetry'] ?? true,
                'enableSolutionsButton' => $content['behaviour']['enableSolutionsButton'] ?? false,
                'enableCheckButton' => true,
                'type' => 'auto',
                'singlePoint' => false,
                'randomAnswers' => true,
                'showSolutionsRequiresInput' => true,
                'confirmCheckDialog' => false,
                'confirmRetryDialog' => false,
                'autoCheck' => false,
                'passPercentage' => 100,
                'showScorePoints' => true
            ],
            'UI' => $this->getMultiChoiceUI(),
            'confirmCheck' => [
                'header' => 'Terminer ?',
                'body' => 'Êtes-vous certain de vouloir terminer ?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Terminer'
            ],
            'confirmRetry' => [
                'header' => 'Recommencer ?',
                'body' => 'Êtes-vous certain de vouloir recommencer ?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Confirmer'
            ]
        ];
    }
    
    private function buildTrueFalseContent($content) {
        // Wrapper la question en HTML si nécessaire
        $question = $content['question'] ?? '<p>Affirmation ?</p>';
        if (!empty($question) && strpos($question, '<') === false) {
            $question = '<p>' . htmlspecialchars($question) . '</p>';
        }
        // Convertir <b>→<strong>, <i>→<em> pour compatibilité Éléa
        $question = preg_replace('/<b(\s|>)/i', '<strong$1', $question);
        $question = str_ireplace('</b>', '</strong>', $question);
        $question = preg_replace('/<i(\s|>)/i', '<em$1', $question);
        $question = str_ireplace('</i>', '</em>', $question);
        
        // IMPORTANT: correct doit être une STRING "true"/"false", pas un booléen
        $correctVal = $content['correct'] ?? 'true';
        if (is_bool($correctVal)) {
            $correctVal = $correctVal ? 'true' : 'false';
        }
        $correctVal = (string) $correctVal;
        
        return [
            'media' => [
                'type' => ['params' => new \stdClass()],
                'disableImageZooming' => false
            ],
            'correct' => $correctVal,
            'behaviour' => [
                'enableRetry' => $content['behaviour']['enableRetry'] ?? true,
                'enableSolutionsButton' => $content['behaviour']['enableSolutionsButton'] ?? false,
                'enableCheckButton' => true,
                'confirmCheckDialog' => false,
                'confirmRetryDialog' => false,
                'autoCheck' => false
            ],
            'l10n' => [
                'trueText' => 'Vrai',
                'falseText' => 'Faux',
                'score' => 'Vous avez obtenu @score points sur un total de @total',
                'checkAnswer' => 'Vérifier',
                'submitAnswer' => 'Vérifier',
                'showSolutionButton' => 'Voir la solution',
                'tryAgain' => 'Recommencer',
                'wrongAnswerMessage' => 'Réponse incorrecte',
                'correctAnswerMessage' => 'Bonne réponse',
                'scoreBarLabel' => 'Vous avez obtenu @score points sur un total de @total',
                'a11yCheck' => 'Vérifiez les réponses.  Les réponses seront marquées comme correcte, incorrecte ou sans réponse.',
                'a11yShowSolution' => "Montrer la solution. L'exercice s'affichera avec la solution correcte.",
                'a11yRetry' => "Réessayer l'exercice. Réinitialisez toutes les réponses et recommencer l'exercice depuis le début."
            ],
            'confirmCheck' => [
                'header' => 'Terminer ?',
                'body' => 'Voulez-vous vraiment terminer ?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Confirmer'
            ],
            'confirmRetry' => [
                'header' => 'Recommencer ?',
                'body' => 'Voulez-vous vraiment recommencer ?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Confirmer'
            ],
            'question' => $question
        ];
    }
    
    private function buildMultiMediaChoiceContent($content) {
        $question = $content['question'] ?? '<p><strong>Sélectionnez les bonnes réponses</strong></p>';
        if (!empty($question) && strpos($question, '<') === false) {
            $question = '<p>' . htmlspecialchars($question) . '</p>';
        }
        $question = preg_replace('/<b(\s|>)/i', '<strong$1', $question);
        $question = str_ireplace('</b>', '</strong>', $question);
        $question = preg_replace('/<i(\s|>)/i', '<em$1', $question);
        $question = str_ireplace('</i>', '</em>', $question);
        
        $options = [];
        foreach (($content['options'] ?? []) as $opt) {
            $media = $opt['media'] ?? [];
            $file = $media['params']['file'] ?? null;
            
            $mediaData = [
                'params' => new \stdClass(),
                'library' => 'H5P.Image 1.1',
                'subContentId' => $this->generateUUID(),
                'metadata' => [
                    'contentType' => 'Image',
                    'license' => 'U',
                    'title' => 'Sans titre Image',
                    'authors' => [],
                    'changes' => []
                ]
            ];
            
            if ($file && !empty($file['path'])) {
                $mediaData['params'] = [
                    'decorative' => false,
                    'contentName' => 'Image',
                    'expandImage' => 'Expand Image',
                    'minimizeImage' => 'Minimize Image',
                    'file' => [
                        'path' => $file['path'],
                        'mime' => $file['mime'] ?? 'image/jpeg',
                        'copyright' => ['license' => 'U'],
                        'width' => $file['width'] ?? 200,
                        'height' => $file['height'] ?? 200,
                    ]
                ];
            }
            
            $options[] = [
                'media' => $mediaData,
                'correct' => (bool)($opt['correct'] ?? false)
            ];
        }
        
        $behaviour = $content['behaviour'] ?? [];
        
        return [
            'media' => [
                'disableImageZooming' => false,
                'type' => ['params' => new \stdClass()]
            ],
            'options' => $options,
            'overallFeedback' => [['from' => 0, 'to' => 100]],
            'behaviour' => [
                'enableRetry' => isset($behaviour['enableRetry']) ? (string)$behaviour['enableRetry'] : 'true',
                'enableSolutionsButton' => isset($behaviour['enableSolutionsButton']) ? (string)$behaviour['enableSolutionsButton'] : 'false',
                'confirmCheckDialog' => false,
                'confirmRetryDialog' => false,
                'singlePoint' => false,
                'showSolutionsRequiresInput' => true,
                'questionType' => $behaviour['questionType'] ?? 'auto',
                'aspectRatio' => $behaviour['aspectRatio'] ?? 'auto',
                'maxAlternativesPerRow' => (string)($behaviour['maxAlternativesPerRow'] ?? '4'),
                'passPercentage' => $behaviour['passPercentage'] ?? 100
            ],
            'l10n' => [
                'checkAnswerButtonText' => 'Vérifier',
                'submitAnswerButtonText' => 'Soumettre',
                'checkAnswer' => 'Vérifier les réponses.',
                'showSolutionButtonText' => 'Afficher la solution',
                'showSolution' => 'Afficher la solution.',
                'correctAnswer' => 'Réponse correcte',
                'wrongAnswer' => 'Mauvaise réponse',
                'shouldCheck' => 'Aurait due être cochée',
                'shouldNotCheck' => "N'aurait pas due être cochée",
                'noAnswer' => 'Veuillez répondre avant de voir la solution',
                'retryText' => 'Réessayer',
                'retry' => 'Réessayer la tâche.',
                'result' => 'Vous avez obtenu :num sur :total points',
                'missingAltText' => 'Texte Alt manquant',
                'confirmCheck' => [
                    'header' => 'Fini ?',
                    'body' => 'Êtes-vous sûr de vouloir finir ?',
                    'cancelLabel' => 'Annuler',
                    'confirmLabel' => 'Fini'
                ],
                'confirmRetry' => [
                    'header' => 'Réessayer ?',
                    'body' => 'Êtes-vous sûr de vouloir réessayer ?',
                    'cancelLabel' => 'Annuler',
                    'confirmLabel' => 'Réessayer'
                ]
            ],
            'question' => $question
        ];
    }
    
    private function buildBlanksContent($content) {
        // S'assurer que questions est un tableau avec le bon format
        $questions = $content['questions'] ?? [];
        if (empty($questions) && isset($content['text']) && !empty($content['text'])) {
            // Convertir text en questions si nécessaire
            $text = $content['text'];
            if (strpos($text, '<p>') === false) {
                $text = '<p>' . $text . '</p>';
            }
            $questions = [$text];
        }
        
        // Convertir les balises navigateur vers le format H5P/Éléa
        // <b> → <strong>, <i> → <em> (Éléa n'accepte que strong/em/u)
        foreach ($questions as &$q) {
            $q = preg_replace('/<b(\s|>)/i', '<strong$1', $q);
            $q = str_ireplace('</b>', '</strong>', $q);
            $q = preg_replace('/<i(\s|>)/i', '<em$1', $q);
            $q = str_ireplace('</i>', '</em>', $q);
        }
        unset($q);
        
        return [
            'media' => [
                'disableImageZooming' => false,
                'type' => ['params' => new \stdClass()]
            ],
            'text' => (isset($content['text']) && strpos($content['text'], '*') === false) ? $content['text'] : 'Complétez les mots manquants',
            'overallFeedback' => [['from' => 0, 'to' => 100]],
            'showSolutions' => 'Voir la correction',
            'tryAgain' => 'Recommencer',
            'checkAnswer' => 'Vérifier',
            'submitAnswer' => 'Vérifier',
            'notFilledOut' => 'Vous devez avoir rempli tous les blancs avant de voir la correction',
            'answerIsCorrect' => "':ans' est une réponse exacte",
            'answerIsWrong' => "':ans' est une réponse inexacte",
            'answeredCorrectly' => 'Réponse exacte',
            'answeredIncorrectly' => 'Mauvaise réponse',
            'solutionLabel' => 'Réponse correcte :',
            'inputLabel' => 'Blanc @num sur @total',
            'inputHasTipLabel' => 'Indice disponible',
            'tipLabel' => 'Indice',
            'behaviour' => [
                'enableRetry' => $content['behaviour']['enableRetry'] ?? true,
                'enableSolutionsButton' => $content['behaviour']['enableSolutionsButton'] ?? false,
                'enableCheckButton' => true,
                'autoCheck' => false,
                'caseSensitive' => $content['behaviour']['caseSensitive'] ?? false,
                'showSolutionsRequiresInput' => $content['behaviour']['showSolutionsRequiresInput'] ?? false,
                'separateLines' => false,
                'confirmCheckDialog' => false,
                'confirmRetryDialog' => false,
                'acceptSpellingErrors' => false
            ],
            'scoreBarLabel' => 'Vous avez obtenu :num points sur un total de :points',
            'a11yCheck' => 'Vérifiez les réponses. Les réponses seront marquées comme correctes, incorrectes ou sans réponse.',
            'a11yShowSolution' => 'Montrez la solution. La tâche sera marquée avec sa solution correcte.',
            'a11yRetry' => 'Réessayez la tâche. Réinitialisez toutes les réponses et recommencez la tâche.',
            'a11yCheckingModeHeader' => 'Mode de contrôle',
            'confirmCheck' => [
                'header' => 'Terminer ?',
                'body' => 'Êtes-vous sûr de vouloir terminer ?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Terminer'
            ],
            'confirmRetry' => [
                'header' => 'Recommencer ?',
                'body' => 'Êtes-vous sûr de vouloir recommencer ?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Confirmer'
            ],
            'questions' => $questions
        ];
    }
    
    private function buildDialogCardsContent($content) {
        // L'éditeur stocke dans 'dialogs', pas 'cards'
        $dialogs = $content['dialogs'] ?? [];
        
        // Enrichir chaque carte avec le format Éléa
        foreach ($dialogs as &$dialog) {
            // Wrapper le texte en HTML centré
            if (isset($dialog['text']) && !empty($dialog['text'])) {
                if (strpos($dialog['text'], '<') === false) {
                    $dialog['text'] = '<p style="text-align: center;">' . htmlspecialchars($dialog['text']) . '</p>';
                } elseif (strpos($dialog['text'], 'text-align') === false) {
                    $dialog['text'] = str_replace('<p>', '<p style="text-align: center;">', $dialog['text']);
                    $dialog['text'] = str_replace('<p ', '<p style="text-align: center;" ', $dialog['text']);
                }
            }
            if (isset($dialog['answer']) && !empty($dialog['answer'])) {
                if (strpos($dialog['answer'], '<') === false) {
                    $dialog['answer'] = '<p style="text-align: center;">' . htmlspecialchars($dialog['answer']) . '</p>';
                } elseif (strpos($dialog['answer'], 'text-align') === false) {
                    $dialog['answer'] = str_replace('<p>', '<p style="text-align: center;">', $dialog['answer']);
                    $dialog['answer'] = str_replace('<p ', '<p style="text-align: center;" ', $dialog['answer']);
                }
            }
            // Convertir <b>→<strong>, <i>→<em>
            foreach (['text', 'answer'] as $field) {
                if (isset($dialog[$field])) {
                    $dialog[$field] = preg_replace('/<b(\s|>)/i', '<strong$1', $dialog[$field]);
                    $dialog[$field] = str_ireplace('</b>', '</strong>', $dialog[$field]);
                    $dialog[$field] = preg_replace('/<i(\s|>)/i', '<em$1', $dialog[$field]);
                    $dialog[$field] = str_ireplace('</i>', '</em>', $dialog[$field]);
                }
            }
            // Ajouter tips si manquant
            if (!isset($dialog['tips'])) {
                $dialog['tips'] = new \stdClass();
            }
            // S'assurer que image a copyright si présent
            if (isset($dialog['image']) && is_array($dialog['image'])) {
                if (!isset($dialog['image']['copyright'])) {
                    $dialog['image']['copyright'] = ['license' => 'U'];
                }
            }
        }
        unset($dialog);
        
        $beh = $content['behaviour'] ?? [];
        
        return [
            'mode' => 'normal',
            'dialogs' => $dialogs,
            'behaviour' => [
                'enableRetry' => true,
                'disableBackwardsNavigation' => false,
                'scaleTextNotCard' => false,
                'randomCards' => $beh['randomCards'] ?? false,
                'maxProficiency' => 5,
                'quickProgression' => false
            ],
            'answer' => 'Retourner',
            'next' => 'Suivant',
            'prev' => 'Précédent',
            'retry' => 'Recommencer',
            'correctAnswer' => 'J\'ai eu bon!',
            'incorrectAnswer' => 'J\'ai eu faux',
            'round' => 'Round @round',
            'cardsLeft' => 'Cartes restantes: @number',
            'nextRound' => 'Procéder au round @round',
            'startOver' => 'Recommencer',
            'showSummary' => 'Suivant',
            'summary' => 'Résumé',
            'summaryCardsRight' => 'Cartes correctes:',
            'summaryCardsWrong' => 'Cartes incorrectes:',
            'summaryCardsNotShown' => 'Cartes non montrées:',
            'summaryOverallScore' => 'Score global',
            'summaryCardsCompleted' => 'Cartes que vous avez complétées:',
            'summaryCompletedRounds' => 'Rounds complétés:',
            'summaryAllDone' => 'Bien joué! Vous avez réussi à avoir les @cards cartes correctes @max fois de suite!',
            'progressText' => 'Carte @card sur @total',
            'cardFrontLabel' => 'Le devant de la carte',
            'cardBackLabel' => 'Le dos de la carte',
            'tipButtonLabel' => 'Montrer l\'indice',
            'audioNotSupported' => 'Votre navigateur ne supporte pas ce fichier audio',
            'confirmStartingOver' => [
                'header' => 'Recommencer?',
                'body' => 'Toutes les progressions seront perdues. Êtes-vous sûr de vouloir recommencer?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Recommencer'
            ],
            'title' => '',
            'description' => ''
        ];
    }
    
    private function buildDragTextContent($content) {
        return [
            'media' => [
                'type' => ['params' => new \stdClass()],
                'disableImageZooming' => false
            ],
            'taskDescription' => 'Déplacez les textes dans les emplacements qui leur correspondent.',
            'overallFeedback' => [['from' => 0, 'to' => 100]],
            'checkAnswer' => 'Vérifier',
            'submitAnswer' => 'Vérifier',
            'tryAgain' => 'Recommencer',
            'showSolution' => 'Voir la correction',
            'dropZoneIndex' => 'Zone de dépôt @index.',
            'empty' => 'La zone de dépôt @index est vide.',
            'contains' => 'La zone de dépôt @index contient un élément déplaçable @draggable.',
            'ariaDraggableIndex' => '@index sur @count éléments déplaçables.',
            'tipLabel' => "Montrer l'indice",
            'correctText' => 'Correct !',
            'incorrectText' => 'Incorrect !',
            'resetDropTitle' => 'Reparamétrer le déplacement',
            'resetDropDescription' => 'Etes-vous certain de vouloir reparamétrer cet élément déplaçable ?',
            'grabbed' => "L'élément déplaçable est saisi.",
            'cancelledDragging' => 'Annuler le déplacement.',
            'correctAnswer' => 'Réponse correcte :',
            'feedbackHeader' => 'Commentaire de retour',
            'behaviour' => [
                'enableRetry' => true,
                'enableSolutionsButton' => true,
                'enableCheckButton' => true,
                'instantFeedback' => false
            ],
            'scoreBarLabel' => 'Vous avez obtenu :num sur un total de :points',
            'a11yCheck' => 'Check the answers. The responses will be marked as correct, incorrect, or unanswered.',
            'a11yShowSolution' => 'Afficher la solution. La tâche sera notée avec sa solution correcte.',
            'a11yRetry' => 'Réessayer la tâche. Réinitialiser toutes les réponses et recommencer la tâche.',
            'textField' => $content['textField'] ?? '',
            'distractors' => $content['distractors'] ?? ''
        ];
    }
    
    private function buildFindTheWordsContent($content) {
        return [
            'taskDescription' => $content['taskDescription'] ?? 'Retrouvez les mots dans la grille',
            'wordList' => $content['wordList'] ?? '',
            'behaviour' => [
                'orientations' => [
                    'horizontal' => true,
                    'horizontalBack' => true,
                    'vertical' => true,
                    'verticalUp' => true,
                    'diagonal' => true,
                    'diagonalBack' => true,
                    'diagonalUp' => true,
                    'diagonalUpBack' => true
                ],
                'fillPool' => 'abcdefghijklmnopqrstuvwxyz',
                'preferOverlap' => true,
                'showVocabulary' => true,
                'enableShowSolution' => true,
                'enableRetry' => true
            ],
            'l10n' => [
                'check' => 'Vérifier',
                'tryAgain' => 'Recommencer',
                'showSolution' => 'Montrer la solution',
                'found' => '@found of @totalWords trouvés',
                'timeSpent' => 'Temps passé',
                'score' => 'Vous avez @score de @total points',
                'wordListHeader' => 'Retrouvez les mots'
            ]
        ];
    }
    
    private function buildDragQuestionContent($content) {
        return [
            'scoreShow' => 'Vérifier',
            'tryAgain' => 'Recommencer',
            'question' => $content['question'] ?? ['settings' => [], 'task' => ['elements' => [], 'dropZones' => []]],
            'overallFeedback' => [['from' => 0, 'to' => 100]],
            'behaviour' => [
                'enableRetry' => true,
                'enableCheckButton' => true,
                'singlePoint' => false,
                'applyPenalties' => true,
                'enableScoreExplanation' => true,
                'dropZoneHighlighting' => 'dragging',
                'autoAlignSpacing' => 2,
                'enableFullScreen' => false,
                'showScorePoints' => true,
                'showTitle' => false
            ]
        ];
    }
    
    private function buildThreeImageContent($content) {
        $scenes = $content['threeImage']['scenes'] ?? [];
        $startSceneId = $content['threeImage']['startSceneId'] ?? 0;
        
        // S'assurer que chaque scène et interaction a les champs requis
        foreach ($scenes as &$scene) {
            $scene['sceneType'] = $scene['sceneType'] ?? '360';
            $scene['showBackButton'] = $scene['showBackButton'] ?? true;
            $scene['iconType'] = $scene['iconType'] ?? 'arrow';
            $scene['scenedescription'] = $scene['scenedescription'] ?? '';
            $scene['cameraStartPosition'] = $scene['cameraStartPosition'] ?? '0,0';
            
            foreach (($scene['interactions'] ?? []) as &$inter) {
                if (!isset($inter['label'])) {
                    $inter['label'] = ['labelPosition' => 'inherit', 'showLabel' => 'inherit'];
                }
                if (isset($inter['action']) && !isset($inter['action']['subContentId'])) {
                    $inter['action']['subContentId'] = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
                }
                if (isset($inter['action']) && !isset($inter['action']['metadata'])) {
                    $inter['action']['metadata'] = [
                        'contentType' => 'Text', 'license' => 'U',
                        'title' => 'Sans titre', 'authors' => [], 'changes' => []
                    ];
                }
            }
        }
        
        return [
            'threeImage' => [
                'scenes' => $scenes,
                'startSceneId' => $startSceneId
            ],
            'behaviour' => $content['behaviour'] ?? [
                'sceneRenderingQuality' => 'high',
                'label' => ['labelPosition' => 'right', 'showLabel' => true]
            ],
            'l10n' => $content['l10n'] ?? [
                'title' => 'Visite virtuelle',
                'playAudioTrack' => 'Lecture de la piste audio',
                'pauseAudioTrack' => 'Pauser la piste audio',
                'sceneDescription' => 'Description de la scène',
                'resetCamera' => 'Réinitialiser la caméra',
                'submitDialog' => 'Boîte de dialogue Soumettre',
                'closeDialog' => 'Fermer la boîte de dialogue',
                'expandButtonAriaLabel' => 'Élargir la vignette visuelle',
                'backgroundLoading' => "Chargement de l'image de fond...",
                'noContent' => 'Pas de contenu'
            ]
        ];
    }
    
    // ==================== LOCALISATIONS ====================
    
    private function getCoursePresentationL10n() {
        return [
            'slide' => 'Diapositive',
            'score' => 'Score',
            'yourScore' => 'Votre score',
            'maxScore' => 'Score maximum',
            'total' => 'Total',
            'totalScore' => 'Score total',
            'showSolutions' => 'Voir la correction',
            'retry' => 'Recommencer',
            'exportAnswers' => 'Exporter',
            'hideKeywords' => 'Cacher la liste des mots-clés',
            'showKeywords' => 'Afficher la liste des mots-clés',
            'fullscreen' => 'Plein écran',
            'exitFullscreen' => 'Quitter le plein écran',
            'prevSlide' => 'Diapositive précédente',
            'nextSlide' => 'Diapositive suivante',
            'currentSlide' => 'Diapositive courante',
            'lastSlide' => 'Dernière diapositive',
            'solutionModeTitle' => 'Sortir du mode "Correction"',
            'solutionModeText' => 'Passer en mode "correction"',
            'summaryMultipleTaskText' => 'Activités multiples',
            'scoreMessage' => 'Votre score :',
            'summary' => 'Résumé',
            'solutionsButtonTitle' => 'Afficher les commentaires',
            'printTitle' => 'Imprimer',
            'noTitle' => 'Sans intitulé',
            'accessibilitySlideNavigationExplanation' => 'Utilisez les flèches pour naviguer',
            'accessibilityCanvasLabel' => 'Zone de présentation',
            'slideCount' => 'Diapositive @index de @total',
            'containsNotCompleted' => '@slideName contient des interactions incomplètes',
            'containsCompleted' => '@slideName contient des interactions complètes',
            'shareResult' => 'Partager le résultat',
            'accessibilityTotalScore' => 'Vous avez obtenu @score sur @maxScore points',
            'confirmDialogHeader' => 'Envoyer vos réponses',
            'confirmDialogText' => 'Cette action va envoyer vos réponses, voulez-vous continuer?',
            'confirmDialogConfirmText' => 'Envoyer et voir les résultats',
            'confirmDialogConfirmLabel' => 'Confirmer',
            'confirmDialogCancelLabel' => 'Annuler',
            'accessibilityProgressBarLabel' => 'Choose slide to display',
            'slideshowNavigationLabel' => 'Slideshow navigation',
            'accessibilityEnteredFullscreen' => 'Mode plein-écran activé',
            'accessibilityExitedFullscreen' => 'Mode plein-écran désactivé',
            'containsIncorrectAnswers' => '@slideName contient des réponses incorrectes',
            'containsOnlyCorrect' => 'toutes les réponses sont bonnes sur @slideName',
            'printAllSlides' => 'Imprimer toutes les diapositives',
            'printCurrentSlide' => 'Imprimer la diapositive courante',
            'printIngress' => 'Comment souhaitez-vous imprimer cette présentation ?',
            'shareFacebook' => 'Partager sur Facebook',
            'shareGoogle' => 'Partager sur Google+',
            'shareTwitter' => 'Partager sur Twitter'
        ];
    }
    
    private function getInteractiveVideoL10n() {
        return [
            'interaction' => 'Activité',
            'play' => 'Jouer',
            'pause' => 'Pause',
            'mute' => 'Sourdine, présentement le son est activé.',
            'unmute' => 'Activer le son, présentement en sourdine.',
            'quality' => 'Qualité de la vidéo',
            'captions' => 'Sous-titres',
            'close' => 'Fermer',
            'fullscreen' => 'Plein écran',
            'exitFullscreen' => 'Sortir du plein écran',
            'summary' => 'Résumé',
            'bookmarks' => 'Signets',
            'endscreen' => 'Continuer',
            'defaultAdaptivitySeekLabel' => 'Continue',
            'continueWithVideo' => 'Reprendre la lecture',
            'more' => 'More player options',
            'playbackRate' => 'Vitesse de lecture',
            'rewind10' => 'Revenir en arrière de 10 secondes',
            'navDisabled' => 'La navigation est désactivée',
            'navForwardDisabled' => 'Navigating forward is disabled',
            'sndDisabled' => 'Le son est désactivé',
            'requiresCompletionWarning' => 'Vous devez répondre correctement à toutes les questions avant de continuer.',
            'back' => 'Retour',
            'hours' => 'Heures',
            'minutes' => 'Minutes',
            'seconds' => 'Secondes',
            'currentTime' => 'Durée actuelle :',
            'totalTime' => 'Temps total :',
            'singleInteractionAnnouncement' => 'Une interaction est apparue.',
            'multipleInteractionsAnnouncement' => 'De multiples interactions sont apparues.',
            'videoPausedAnnouncement' => 'La vidéo est en pause.',
            'content' => 'Contenu',
            'answered' => '@answered réponses données',
            'endcardTitle' => '@answered question(s) auxquelles vous avez répondu',
            'endcardInformation' => 'Vous avez répondu à @answered questions.',
            'endcardInformationOnSubmitButtonDisabled' => 'Vous avez répondu à @answered questions. Cliquez ci-dessous pour les remettre.',
            'endcardInformationNoAnswers' => "Vous n'avez répondu à aucune question.",
            'endcardInformationMustHaveAnswer' => 'Vous devez répondre à au moins une question avant de pouvoir soumettre vos réponses.',
            'endcardSubmitButton' => 'Remettre vos réponses',
            'endcardSubmitMessage' => 'Vos réponses ont été remises !',
            'endcardTableRowAnswered' => 'Questions auxquelles vous avez répondu',
            'endcardTableRowScore' => 'Score',
            'endcardAnsweredScore' => 'Réponses',
            'endCardTableRowSummaryWithScore' => 'Vous avez obtenu de @score sur un total de @points pour la question @question qui apparaissait à @minutes minutes et @secondes secondes.',
            'endCardTableRowSummaryWithoutScore' => 'Vous avez répondu aux @question qui sont apparues après @minutes minutes et @seconds secondes.',
            'videoProgressBar' => 'Video progress',
            'howToCreateInteractions' => 'Play the video to start creating interactions'
        ];
    }
    
    private function getInteractiveVideoSummary() {
        return [
            'task' => [
                'library' => 'H5P.Summary 1.10',
                'params' => [
                    'intro' => "Choisissez l'affirmation exacte.",
                    'summaries' => [
                        [
                            'subContentId' => $this->generateUUID(),
                            'tip' => ''
                        ]
                    ],
                    'overallFeedback' => [['from' => 0, 'to' => 100]],
                    'solvedLabel' => 'Progression :',
                    'scoreLabel' => 'Erreurs :',
                    'resultLabel' => 'Votre résultat :',
                    'labelCorrect' => 'Correct.',
                    'labelIncorrect' => 'Incorrect! Please try again.',
                    'alternativeIncorrectLabel' => 'Incorrect',
                    'labelCorrectAnswers' => 'Réponses correctes.',
                    'tipButtonLabel' => "Montrer l'indice",
                    'scoreBarLabel' => 'Vous avez :num points sur un total de :total',
                    'progressText' => 'Progression de :num sur :total'
                ],
                'subContentId' => $this->generateUUID(),
                'metadata' => [
                    'contentType' => 'Summary',
                    'license' => 'U',
                    'title' => 'Sans titre Summary',
                    'authors' => [],
                    'changes' => [],
                    'extraTitle' => 'Sans titre Summary'
                ]
            ],
            'displayAt' => 3
        ];
    }
    
    private function getMultiChoiceUI() {
        return [
            'checkAnswerButton' => 'Vérifier',
            'submitAnswerButton' => 'Envoyer',
            'showSolutionButton' => 'Afficher la solution',
            'tryAgainButton' => 'Recommencer',
            'tipsLabel' => 'Afficher les indices',
            'scoreBarLabel' => 'Vous avez :num points sur :total',
            'tipAvailable' => 'Indice disponible',
            'feedbackAvailable' => 'Retour disponible',
            'readFeedback' => 'Lire le commentaire',
            'wrongAnswer' => 'Mauvaise réponse',
            'correctAnswer' => 'Bonne réponse',
            'shouldCheck' => 'Il fallait cocher ici',
            'shouldNotCheck' => 'Il ne fallait pas cocher ici !',
            'noInput' => 'Veuillez répondre avant de consulter la solution',
            'a11yCheck' => 'Vérifiez les réponses. Les réponses seront marquées comme correctes, incorrectes ou sans réponse.',
            'a11yShowSolution' => 'Montrez la solution. La tâche sera marquée avec sa solution correcte.',
            'a11yRetry' => 'Réessayer la tâche. Réinitialisez toutes les réponses et recommencez la tâche.'
        ];
    }
    
    private function getSingleChoiceSetL10n() {
        return [
            'nextButtonLabel' => 'Question suivante',
            'showSolutionButtonLabel' => 'Voir la solution',
            'retryButtonLabel' => 'Correction',
            'solutionViewTitle' => 'Recommencer',
            'correctText' => 'Correct !',
            'incorrectText' => 'Incorrect !',
            'shouldSelect' => 'Vous auriez dû sélectionner cette réponse',
            'shouldNotSelect' => 'Vous n\'auriez pas dû sélectionner cette réponse',
            'muteButtonLabel' => 'Couper les retours sons',
            'closeButtonLabel' => 'Fermer',
            'slideOfTotal' => 'Diapositive :num sur :total',
            'scoreBarLabel' => 'Vous avez :num points sur un total de :total',
            'solutionListQuestionNumber' => 'Question :num',
            'a11yShowSolution' => 'Montrer la solution. La tâche sera marquée avec sa solution correcte.',
            'a11yRetry' => 'Réessayer la tâche. Réinitialiser toutes les réponses et recommencer la tâche.'
        ];
    }
    
    // ==================== FILES.XML ====================
    
    private function generateFilesXml() {
        // CORRECTION: Trier les fichiers dans le bon ordre selon Éléa
        // 1. Fichiers réels EN PREMIER
        // 2. Entrées de répertoires enfants (/images/, /videos/, etc.)
        // 3. Entrée de répertoire racine (/) EN DERNIER
        usort($this->filesManifest, function($a, $b) {
            $aIsDir = ($a['filename'] === '.');
            $bIsDir = ($b['filename'] === '.');
            
            // Les fichiers réels AVANT les répertoires (inverse de ma correction précédente)
            if (!$aIsDir && $bIsDir) return -1;
            if ($aIsDir && !$bIsDir) return 1;
            
            // Entre répertoires: sous-répertoires avant "/", "/" en dernier
            if ($aIsDir && $bIsDir) {
                if ($a['filepath'] === '/') return 1;  // "/" va à la fin
                if ($b['filepath'] === '/') return -1;
                return strcmp($a['filepath'], $b['filepath']);
            }
            
            // Entre fichiers: garder l'ordre actuel (par ID)
            return $a['id'] - $b['id'];
        });
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<files>';
        
        foreach ($this->filesManifest as $file) {
            $xml .= '
  <file id="' . $file['id'] . '">
    <contenthash>' . $file['contenthash'] . '</contenthash>
    <contextid>' . $file['contextid'] . '</contextid>
    <component>' . $file['component'] . '</component>
    <filearea>' . $file['filearea'] . '</filearea>
    <itemid>' . $file['itemid'] . '</itemid>
    <filepath>' . $file['filepath'] . '</filepath>
    <filename>' . $this->xmlEncode($file['filename']) . '</filename>
    <userid>$@NULL@$</userid>
    <filesize>' . $file['filesize'] . '</filesize>
    <mimetype>' . (empty($file['mimetype']) ? '$@NULL@$' : $file['mimetype']) . '</mimetype>
    <status>0</status>
    <timecreated>' . $this->backupDate . '</timecreated>
    <timemodified>' . $this->backupDate . '</timemodified>
    <source>' . (isset($file['source']) ? $this->xmlEncode($file['source']) : '$@NULL@$') . '</source>
    <author>$@NULL@$</author>
    <license>' . ($file['license'] ?? '$@NULL@$') . '</license>
    <sortorder>' . ($file['sortorder'] ?? 0) . '</sortorder>
    <repositorytype>$@NULL@$</repositorytype>
    <repositoryid>$@NULL@$</repositoryid>
    <reference>$@NULL@$</reference>
  </file>';
        }
        
        $xml .= '
</files>';
        $this->writeFile('files.xml', $xml);
    }
    
    // ==================== ARCHIVE INDEX ====================
    
    private function generateArchiveIndex() {
        // Dédupliquer par nom de fichier (garde la dernière version en cas de réécriture)
        $seen = [];
        foreach ($this->archiveIndex as $entry) {
            $filename = explode("\t", $entry)[0];
            $seen[$filename] = $entry;
        }
        $dedupedIndex = array_values($seen);
        
        $count = count($dedupedIndex);
        $content = "Moodle archive file index. Count: {$count}\n";
        
        foreach ($dedupedIndex as $entry) {
            $content .= $entry . "\n";
        }
        
        file_put_contents($this->exportDir . '/.ARCHIVE_INDEX', $content);
    }
    
    // ==================== UTILITAIRES ====================
    
    /**
     * Détermine le mimetype d'un fichier basé sur son extension (comme Moodle)
     * PHP mime_content_type() analyse le CONTENU du fichier et retourne des types
     * non reconnus par Moodle (ex: text/x-hex). Moodle utilise l'extension.
     */
    private function getMoodleMimetype(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            // Documents
            'pdf' => 'application/pdf', 'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'rtf' => 'application/rtf',
            // Images
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
            'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
            // Audio/Video
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo',
            // Archives
            'zip' => 'application/zip', 'gz' => 'application/gzip',
            'tar' => 'application/x-tar', 'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            // Web
            'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
            'js' => 'application/javascript', 'json' => 'application/json',
            'xml' => 'application/xml',
            // Code/Dev
            'py' => 'text/x-python', 'c' => 'text/x-c', 'h' => 'text/x-c',
            'cpp' => 'text/x-c++src', 'java' => 'text/x-java', 'php' => 'application/x-httpd-php',
            // Micro:bit / Arduino
            'hex' => 'text/plain', 'ino' => 'text/plain',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
    
    private function writeFile($relativePath, $content) {
        $fullPath = $this->exportDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
        
        // Ajouter à l'index
        $size = strlen($content);
        $type = 'f';
        $this->archiveIndex[] = "{$relativePath}\t{$type}\t{$size}\t" . $this->backupDate;
        
        // Ajouter les dossiers parents
        $parts = explode('/', $relativePath);
        array_pop($parts);
        $currentPath = '';
        foreach ($parts as $part) {
            $currentPath .= $part . '/';
            if (!in_array("{$currentPath}\td\t0\t?", $this->archiveIndex)) {
                $this->archiveIndex[] = "{$currentPath}\td\t0\t?";
            }
        }
    }
    
    private function createTarGz($outputPath) {
        // Utiliser la commande tar système pour inclure les entrées de répertoires
        // Moodle/Éléa s'attend à avoir les répertoires listés dans l'archive
        
        $currentDir = getcwd();
        chdir($this->exportDir);
        
        // Créer l'archive tar.gz avec la commande tar
        // L'option --no-recursion permet de contrôler exactement ce qu'on ajoute
        $tarPath = tempnam(sys_get_temp_dir(), 'moodle_backup_') . '.tar.gz';
        
        // Construire la liste des fichiers et répertoires dans l'ordre Moodle
        $items = $this->collectArchiveItems();
        
        // Créer l'archive avec tar.
        // Passer la liste des fichiers via un FICHIER (-T), pas en arguments de ligne de commande :
        // sous Windows cmd.exe la ligne est limitée (~8191 car.) et un gros cours (beaucoup d'audio/
        // fichiers embarqués) la dépasse → « La ligne de commande est trop longue » → aucune archive.
        // -T/--files-from et --no-recursion sont supportés par bsdtar (Windows) ET GNU tar (Linux prod).
        $listFile = tempnam(sys_get_temp_dir(), 'moodle_items_');
        file_put_contents($listFile, implode("\n", $items) . "\n");
        $cmd = "tar -czf " . escapeshellarg($tarPath) . " --no-recursion -T " . escapeshellarg($listFile);
        exec($cmd . " 2>/dev/null");
        @unlink($listFile);
        
        // Déplacer vers la destination finale
        if (file_exists($tarPath)) {
            rename($tarPath, $outputPath);
        }
        
        chdir($currentDir);
    }
    
    private function collectArchiveItems() {
        $items = [];
        
        // 1. D'abord .ARCHIVE_INDEX
        if (file_exists('.ARCHIVE_INDEX')) {
            $items[] = '.ARCHIVE_INDEX';
        }
        
        // 2. Répertoire activities/ et son contenu
        if (is_dir('activities')) {
            $items[] = 'activities';
            $this->addDirRecursive('activities', $items);
        }
        
        // 3. Fichiers racine XML (sauf files.xml qui vient plus tard)
        $rootXmlFirst = ['badges.xml', 'completion.xml'];
        foreach ($rootXmlFirst as $file) {
            if (file_exists($file)) {
                $items[] = $file;
            }
        }
        
        // 4. Répertoire course/ et son contenu
        if (is_dir('course')) {
            $items[] = 'course';
            $this->addDirRecursive('course', $items);
        }
        
        // 5. Répertoire files/ (si présent)
        if (is_dir('files')) {
            $items[] = 'files';
            $this->addDirRecursive('files', $items);
        }
        
        // 6. Autres fichiers racine
        $otherRootFiles = ['grade_history.xml', 'gradebook.xml', 'groups.xml', 
                          'moodle_backup.log', 'moodle_backup.xml', 'outcomes.xml',
                          'questions.xml', 'roles.xml', 'scales.xml', 'files.xml'];
        foreach ($otherRootFiles as $file) {
            if (file_exists($file) && !in_array($file, $items)) {
                $items[] = $file;
            }
        }
        
        // 7. Répertoire sections/ et son contenu
        if (is_dir('sections')) {
            $items[] = 'sections';
            $this->addDirRecursive('sections', $items);
        }
        
        return $items;
    }
    
    private function addDirRecursive($dir, &$items) {
        $entries = scandir($dir);
        sort($entries);
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            
            if (is_dir($path)) {
                $items[] = $path;
                $this->addDirRecursive($path, $items);
            } else {
                $items[] = $path;
            }
        }
    }
    
    private function xmlEncode($str) {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Encode le JSON pour XML sans encoder les guillemets
     * Les guillemets " n'ont pas besoin d'être encodés dans le contenu XML (seulement dans les attributs)
     */
    private function xmlEncodeJson($str) {
        // Encoder seulement &, < et > (pas les guillemets)
        return htmlspecialchars($str, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
    }
    
    private function slugify($str) {
        $str = strtolower(trim($str));
        $str = preg_replace('/[àâäáã]/u', 'a', $str);
        $str = preg_replace('/[éèêë]/u', 'e', $str);
        $str = preg_replace('/[ïîí]/u', 'i', $str);
        $str = preg_replace('/[ôöóõ]/u', 'o', $str);
        $str = preg_replace('/[ùûüú]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        $str = trim($str, '-');
        return $str ?: 'activite';
    }
    
    private function sanitizeFilename($str) {
        $str = preg_replace('/[àâäáã]/u', 'a', $str);
        $str = preg_replace('/[éèêë]/u', 'e', $str);
        $str = preg_replace('/[ïîí]/u', 'i', $str);
        $str = preg_replace('/[ôöóõ]/u', 'o', $str);
        $str = preg_replace('/[ùûüú]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        $str = preg_replace('/[^a-zA-Z0-9]+/', '-', $str);
        $str = trim($str, '-');
        return $str ?: 'cours';
    }
    
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
