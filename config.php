<?php
/**
 * Éléa-Secours - Configuration
 * Solution de secours pour afficher les parcours Éléa (.mbz)
 */

// === CONFIGURATION GÉNÉRALE ===
define('SITE_NAME', 'Éléa-Secours');
define('SITE_URL', 'https://lejardindesoiseaux.com/elea-secours'); // ← Change cette URL

// === CHEMIN CREDENTIALS (hors du dossier web) ===
// Sous-dossier dédié à elea-secours (les autres apps ont leur propre dossier
// dans /home/lejardj/credentials). elea_secrets.php, gdrive_oauth_token.json
// (+ .lock) et OauthEleaSecours.json en dérivent (voir plus bas).
define('CREDENTIALS_PATH', '/home/lejardj/credentials/elea-secours');

// === CHARGEMENT DES SECRETS ===
// Les données sensibles sont stockées dans un fichier séparé, hors du dossier web
// Voir secrets.example.php pour le format attendu
$_SECRETS = [];
$_secretsFile = CREDENTIALS_PATH . '/elea_secrets.php';
if (file_exists($_secretsFile)) {
    $_SECRETS = require $_secretsFile;
}

define('UPLOAD_PASSWORD', $_SECRETS['upload_password'] ?? 'CHANGEZ_MOI');
define('MAX_STORAGE_MB', 250); // Limite cache lecture
define('MAX_EDITOR_STORAGE_MB', 250); // Limite cache création cours
define('COURSE_LIFETIME_HOURS', 24);

// === GOOGLE DRIVE ===
define('GDRIVE_API_KEY', $_SECRETS['gdrive_api_key'] ?? '');

// Liste des dossiers Google Drive à scanner (un par niveau/catégorie)
$GDRIVE_FOLDERS = $_SECRETS['gdrive_folders'] ?? [];

// ID du dossier racine /CoursElea sur Drive (pour découverte automatique des sous-dossiers)
define('GDRIVE_COURSES_ROOT_ID', $_SECRETS['gdrive_courses_root_id'] ?? '');

// OAuth Google Drive (pour le cache des cours permanents via DriveStorage)
define('GDRIVE_OAUTH_CLIENT_ID', $_SECRETS['gdrive_oauth_client_id'] ?? '');
define('GDRIVE_OAUTH_CLIENT_SECRET', $_SECRETS['gdrive_oauth_client_secret'] ?? '');
define('GDRIVE_OAUTH_TOKEN_PATH', CREDENTIALS_PATH . '/gdrive_oauth_token.json');
define('GDRIVE_CACHE_FOLDER_ID', $_SECRETS['gdrive_cache_folder_id'] ?? '');

// Drive direct : upload des cours permanents
define('DRIVE_OAUTH_CLIENT_JSON', CREDENTIALS_PATH . '/OauthEleaSecours.json');
define('DRIVE_COURSEPERMANENTS_FOLDER_ID', $_SECRETS['drive_coursepermanents_folder_id'] ?? '');
define('DRIVE_COURSETEMP_FOLDER_ID', $_SECRETS['drive_coursetemp_folder_id'] ?? '');
define('DRIVE_COURSCREATION_FOLDER_ID', $_SECRETS['drive_courscreation_folder_id'] ?? '');


// === GOOGLE DRIVE OAUTH (stockage cache pour les utilisateurs non-admin) ===

// === CHEMINS ===
define('ROOT_PATH', __DIR__);
define('COURSES_PATH', ROOT_PATH . '/courses');
define('TMP_PATH', ROOT_PATH . '/tmp');
define('CACHE_DIR', ROOT_PATH . '/cache');
define('DRIVE_INDEX_DIR', CACHE_DIR . '/drive_index');
define('EDITOR_SESSIONS_DIR', CACHE_DIR . '/editor_sessions');
define('H5P_LIBRARIES_PATH', ROOT_PATH . '/h5p-libraries');
define('QUIZ_TYPES_PATH', ROOT_PATH . '/quiz-types');

// === TYPES DE FICHIERS AUTORISÉS ===
define('ALLOWED_EXTENSIONS', ['mbz']);
define('MAX_UPLOAD_SIZE', 200 * 1024 * 1024); // 200 Mo par fichier

// === BIBLIOTHÈQUES H5P SUPPORTÉES ===
// Liste des bibliothèques H5P disponibles (nom machine => version)
$H5P_LIBRARIES = [
    'H5P.CoursePresentation' => '1.26',
    'H5P.MultiChoice' => '1.16',
    'H5P.Image' => '1.1',
    'H5P.AdvancedText' => '1.1',
    'H5P.DragQuestion' => '1.14',
    'H5P.Blanks' => '1.14',
    'H5P.InteractiveVideo' => '1.27',
    'H5P.TrueFalse' => '1.8',
    'H5P.DragText' => '1.10',
    'H5P.MarkTheWords' => '1.11',
    'H5P.Summary' => '1.10',
    'H5P.SingleChoiceSet' => '1.11',
    'H5P.ImageHotspots' => '1.10',
    'H5P.Accordion' => '1.0',
    'H5P.Column' => '1.16',
    'H5P.Video' => '1.6',
    'H5P.Audio' => '1.5',
    'H5P.Timeline' => '1.1',
    'H5P.Chart' => '1.2',
    'H5P.Collage' => '0.3',
    'H5P.MemoryGame' => '1.3',
    'H5P.ImageMultipleHotspotQuestion' => '1.0',
    'H5P.Flashcards' => '1.7',
    'H5P.QuestionSet' => '1.20',
    'H5P.DocumentationTool' => '1.8',
    'H5P.ImageSlider' => '1.1',
    'H5P.Essay' => '1.5',
    'H5P.Speak' => '1.0',
    'H5P.Crossword' => '0.6',
    'H5P.FindTheWords' => '1.5',
    'H5P.ImageSequencing' => '1.1',
    'H5P.SortParagraphs' => '0.12',
    'H5P.BranchingScenario' => '1.8',
    'H5P.MultiMediaChoice' => '0.3',
];

// === TYPES DE QUESTIONS MOODLE SUPPORTÉS ===
$QUIZ_TYPES = [
    'multichoice',      // QCM
    'truefalse',        // Vrai/Faux
    'shortanswer',      // Réponse courte
    'numerical',        // Numérique
    'match',            // Appariement
    'gapselect',        // Texte à trous (sélection)
    'ddwtos',           // Glisser-déposer sur texte
    'ddimageortext',    // Glisser-déposer sur image
    'ddmarker',         // Marqueurs à glisser
    'ordering',         // Ordonner
    'essay',            // Rédaction (affichage seul)
    'description',      // Description (pas de question)
    'calculated',       // Calculée
    'calculatedmulti',  // Calculée à choix multiples
    'calculatedsimple', // Calculée simple
    'multianswer',      // Cloze (réponses intégrées)
    'random',           // Aléatoire
];

// === FONCTIONS UTILITAIRES ===

/**
 * Génère un identifiant unique pour un cours
 */
function generateCourseId(): string {
    return bin2hex(random_bytes(8));
}

/**
 * Vérifie si le mot de passe prof est correct
 */
function verifyPassword(string $password): bool {
    return $password === UPLOAD_PASSWORD;
}

/**
 * Calcule l'espace utilisé par les cours locaux
 */
function getUsedStorage(): int {
    $total = 0;
    if (!is_dir(COURSES_PATH)) return 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(COURSES_PATH, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $total += $file->getSize();
    }
    return $total;
}

/**
 * Calcule la taille d'un répertoire
 */
function getDirSize(string $dir): int {
    if (!is_dir($dir)) return 0;
    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $total += $file->getSize();
    }
    return $total;
}

/**
 * Calcule l'espace TOTAL utilisé sur le serveur
 * (cours temporaires + cache cours permanents + création)
 */
function getServerTotalUsage(): int {
    $total = 0;
    
    // 1. Cours temporaires
    if (is_dir(COURSES_PATH)) $total += getDirSize(COURSES_PATH);
    
    // 2. Cache cours permanents (tmp/course_*)
    if (is_dir(TMP_PATH)) {
        foreach (scandir(TMP_PATH) as $item) {
            if (strpos($item, 'course_') === 0 && is_dir(TMP_PATH . '/' . $item)) {
                $total += getDirSize(TMP_PATH . '/' . $item);
            }
        }
    }
    
    // 3. Création de cours
    $editorDirs = ['drafts', 'editor_uploads', 'exports', 'editor_drafts'];
    foreach ($editorDirs as $d) {
        $path = CACHE_DIR . '/' . $d;
        if (is_dir($path)) $total += getDirSize($path);
    }
    if (is_dir(CACHE_DIR)) {
        foreach (scandir(CACHE_DIR) as $item) {
            if ((strpos($item, 'import_') === 0 || strpos($item, 'tpl_') === 0) && is_dir(CACHE_DIR . '/' . $item)) {
                $total += getDirSize(CACHE_DIR . '/' . $item);
            }
        }
    }
    
    return $total;
}

define('SERVER_MAX_MB', 400);
define('SERVER_WARN_MB', 320); // Seuil d'alerte (80% de SERVER_MAX_MB) : prévient le prof avant le blocage

// === GARDE-FOU EXTRACTION ===
// Lock file pour empêcher les décompressions simultanées
define('EXTRACTION_LOCK_FILE', TMP_PATH . '/.extracting.lock');
define('EXTRACTION_LOCK_TIMEOUT', 45); // 45 secondes max (protection contre les crash)

/**
 * Vérifie si un lock est périmé (processus mort ou timeout)
 */
function _isLockStale(): bool {
    $lockFile = EXTRACTION_LOCK_FILE;
    if (!file_exists($lockFile)) return true;
    
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge >= EXTRACTION_LOCK_TIMEOUT) return true;
    
    $lockData = @json_decode(file_get_contents($lockFile), true);
    if (!$lockData) return true;
    
    $pid = intval($lockData['pid'] ?? 0);
    if ($pid > 0) {
        // Méthode 1 : posix_kill (si disponible)
        if (function_exists('posix_kill')) {
            if (!posix_kill($pid, 0)) {
                error_log("[ExtractionLock] PID $pid mort (posix), lock périmé (âge: {$lockAge}s)");
                return true;
            }
        }
        // Méthode 2 : /proc/PID (Linux)
        elseif (is_dir('/proc') && !is_dir("/proc/$pid")) {
            error_log("[ExtractionLock] PID $pid mort (/proc), lock périmé (âge: {$lockAge}s)");
            return true;
        }
    }
    
    return false;
}

/**
 * Vérifie l'espace serveur et acquiert le lock d'extraction.
 * DOIT être suivi de releaseExtractionLock() après la décompression.
 */
function acquireExtractionLock(int $estimatedSize = 0): array {
    $maxBytes = SERVER_MAX_MB * 1024 * 1024;
    
    // 1. Vérifier l'espace serveur
    $used = getServerTotalUsage();
    if (($used + $estimatedSize) >= $maxBytes) {
        $usedMB = round($used / (1024 * 1024), 1);
        return ['ok' => false, 'reason' => 'server_full', 'message' => "Espace serveur plein ({$usedMB} Mo / " . SERVER_MAX_MB . " Mo). Videz le cache avant de continuer."];
    }
    
    // 2. Vérifier/acquérir le lock
    $lockFile = EXTRACTION_LOCK_FILE;
    if (file_exists($lockFile) && !_isLockStale()) {
        $lockData = @json_decode(file_get_contents($lockFile), true);
        $lockInfo = $lockData['info'] ?? 'un cours';
        return ['ok' => false, 'reason' => 'extraction_in_progress', 'message' => "Un chargement est en cours ({$lockInfo}). Réessayez dans quelques instants."];
    }
    
    // Supprimer le lock périmé s'il existe
    if (file_exists($lockFile)) @unlink($lockFile);
    
    // 3. Créer le lock
    $lockData = [
        'pid' => getmypid(),
        'started' => time(),
        'info' => $estimatedSize > 0 ? round($estimatedSize / (1024*1024), 1) . ' Mo' : 'cours',
    ];
    @file_put_contents($lockFile, json_encode($lockData));
    
    // 4. Enregistrer un shutdown handler pour libérer le lock en cas de crash
    register_shutdown_function(function() {
        releaseExtractionLock();
    });
    
    return ['ok' => true];
}

/**
 * Libère le lock d'extraction
 */
function releaseExtractionLock(): void {
    $lockFile = EXTRACTION_LOCK_FILE;
    if (file_exists($lockFile)) {
        // Ne supprimer que si c'est notre lock (même PID)
        $lockData = @json_decode(file_get_contents($lockFile), true);
        if (!$lockData || ($lockData['pid'] ?? 0) == getmypid()) {
            @unlink($lockFile);
        }
    }
}

/**
 * Vérifie juste l'espace et le lock SANS acquérir (pour les checks JS)
 */
function checkExtractionStatus(): array {
    $maxBytes = SERVER_MAX_MB * 1024 * 1024;
    $warnBytes = SERVER_WARN_MB * 1024 * 1024;
    $used = getServerTotalUsage();
    $usedMB = round($used / (1024 * 1024), 1);

    $result = [
        'can_extract' => true,
        'used_mb' => $usedMB,
        'max_mb' => SERVER_MAX_MB,
        'warn_mb' => SERVER_WARN_MB,
        'pct' => round(($used / $maxBytes) * 100, 1),
        'warning' => false,
    ];

    if ($used >= $maxBytes) {
        $result['can_extract'] = false;
        $result['reason'] = 'server_full';
        $result['message'] = "Espace serveur plein ({$usedMB} Mo / " . SERVER_MAX_MB . " Mo)";
    } elseif ($used >= $warnBytes) {
        // Seuil d'alerte intermédiaire : on peut encore uploader, mais on prévient
        $result['warning'] = true;
        $result['warning_message'] = "Espace serveur à {$result['pct']}% ({$usedMB} Mo / " . SERVER_MAX_MB . " Mo) — videz bientôt le cache pour éviter le blocage.";
    }
    
    $lockFile = EXTRACTION_LOCK_FILE;
    if (file_exists($lockFile) && !_isLockStale()) {
        $lockData = @json_decode(file_get_contents($lockFile), true);
        $result['extraction_in_progress'] = true;
        $result['lock_info'] = $lockData['info'] ?? '';
        $result['lock_age'] = time() - filemtime($lockFile);
        if ($result['can_extract']) {
            $result['can_extract'] = false;
            $result['reason'] = 'extraction_in_progress';
            $result['message'] = 'Un chargement est en cours. Réessayez dans quelques instants.';
        }
    }
    
    return $result;
}

/**
 * Vérifie si on peut encore uploader/décompresser (quota 400 Mo total)
 */
function canUpload(int $fileSize): bool {
    $used = getServerTotalUsage();
    $max = SERVER_MAX_MB * 1024 * 1024;
    return ($used + $fileSize) <= $max;
}

/**
 * Nettoie un nom de prof pour l'URL
 */
function sanitizeProfName(string $name): string {
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\-]/', '', $name);
    return substr($name, 0, 30);
}

/**
 * Vérifie si un nom de prof existe déjà
 */
function profExists(string $name): bool {
    $path = COURSES_PATH . '/' . sanitizeProfName($name);
    return is_dir($path);
}

/**
 * Liste les cours locaux disponibles
 */
function getLocalCourses(): array {
    $courses = [];
    if (!is_dir(COURSES_PATH)) return $courses;
    
    foreach (scandir(COURSES_PATH) as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        // Exclure les dossiers pdf-preview (prévisualisations PDF, pas des vrais cours)
        if (strpos($dir, 'pdf-preview-') === 0) continue;
        $infoFile = COURSES_PATH . '/' . $dir . '/info.json';
        if (file_exists($infoFile)) {
            $info = json_decode(file_get_contents($infoFile), true);
            $info['prof_id'] = $dir;
            $info['is_local'] = true;
            $courses[] = $info;
        }
    }
    return $courses;
}

/**
 * Retourne la liste des cours locaux + ceux qui n'existent plus que sur Drive (expirés localement)
 */
/**
 * Calcule la taille totale d'un cours depuis les sources disponibles.
 * Essaie dans l'ordre : dossier local → index Drive _data.json (somme des files)
 */
function getCourseTotalSize(string $localPath, string $indexOrStateFile = ''): int {
    // 1. Dossier local existe → getDirSize (le plus précis, inclut tous les fichiers)
    if (is_dir($localPath)) {
        $sz = getDirSize($localPath);
        if ($sz > 0) return $sz;
    }
    
    // 2. Lire course_data.json local (si seulement le JSON reste)
    $localCourseData = $localPath . '/course_data.json';
    if (file_exists($localCourseData)) {
        $sz = _sumFileSizesFromCourseData($localCourseData);
        if ($sz > 0) return $sz;
    }
    
    if (!$indexOrStateFile) return 0;
    
    // 3. total_size dans le fichier index/state (stocké lors de l'upload Drive)
    if (file_exists($indexOrStateFile)) {
        $data = json_decode(file_get_contents($indexOrStateFile), true);
        if ($data && !empty($data['total_size'])) return (int)$data['total_size'];
    }
    
    // 4. Lire _data.json (copie du course_data.json sauvegardée sur Drive)
    $dataFile = preg_replace('/\.json$/', '_data.json', $indexOrStateFile);
    if ($dataFile !== $indexOrStateFile && file_exists($dataFile)) {
        $sz = _sumFileSizesFromCourseData($dataFile);
        if ($sz > 0) return $sz;
    }
    
    return 0;
}

/**
 * Lit un course_data.json et somme les tailles des fichiers depuis le tableau 'files'
 */
function _sumFileSizesFromCourseData(string $filePath): int {
    $raw = @file_get_contents($filePath);
    if (!$raw) return 0;
    $courseData = json_decode($raw, true);
    if (!$courseData) return 0;
    
    // Le tableau files peut être à la racine ou sous 'files'
    $files = $courseData['files'] ?? [];
    if (empty($files)) return 0;
    
    $total = 0;
    foreach ($files as $f) {
        // Format MBZ : chaque entrée a 'filesize' (int)
        if (is_array($f) && isset($f['filesize'])) {
            $total += (int)$f['filesize'];
        }
    }
    
    // Si total > 0, ajouter la taille du JSON lui-même (overhead structure)
    if ($total > 0) return $total + filesize($filePath);
    
    // Fallback : taille du fichier JSON (mieux que 0)
    return filesize($filePath);
}

function getAllCoursesIncludingDrive(array $localCourses): array {
    // Collecter les prof_id déjà connus localement
    $localIds = [];
    foreach ($localCourses as $lc) {
        $localIds[$lc['prof_id']] = true;
    }
    
    // Scanner les index Drive temporaires pour trouver les cours sans dossier local
    if (is_dir(DRIVE_INDEX_DIR)) {
        foreach (glob(DRIVE_INDEX_DIR . '/temp_*_data.json') as $dataFile) {
            $basename = basename($dataFile); // temp_{profId}_data.json
            $profId = preg_replace('/^temp_|_data\.json$/', '', $basename);
            if (empty($profId) || isset($localIds[$profId])) continue;
            
            // Vérifier que l'index existe aussi
            $indexFile = DRIVE_INDEX_DIR . '/temp_' . $profId . '.json';
            if (!file_exists($indexFile)) continue;
            
            // Reconstruire une entrée minimale depuis le course_data
            $courseData = json_decode(file_get_contents($dataFile), true);
            if (!$courseData) continue;
            
            $localCourses[] = [
                'prof_id' => $profId,
                'course_name' => $courseData['course']['course_fullname'] ?? $courseData['course_fullname'] ?? $courseData['course']['course_shortname'] ?? $courseData['course_shortname'] ?? $profId,
                'is_local' => false,
                'is_drive_only' => true,
                'created_at' => filemtime($dataFile),
            ];
        }
    }
    
    return $localCourses;
}

/**
 * Supprime les cours expirés
 */
function cleanExpiredCourses(): int {
    $count = 0;
    $maxAge = COURSE_LIFETIME_HOURS * 3600;
    
    foreach (scandir(COURSES_PATH) as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        $path = COURSES_PATH . '/' . $dir;
        $infoFile = $path . '/info.json';
        
        if (file_exists($infoFile)) {
            $info = json_decode(file_get_contents($infoFile), true);
            
            // Utiliser created_at si présent, sinon fallback sur mtime du fichier info.json
            $createdAt = $info['created_at'] ?? null;
            if (!$createdAt) {
                $createdAt = filemtime($infoFile);
                // Persister le created_at manquant pour les prochains affichages
                $info['created_at'] = $createdAt;
                $info['expires_at'] = $createdAt + $maxAge;
                @file_put_contents($infoFile, json_encode($info, JSON_PRETTY_PRINT), LOCK_EX);
            }
            
            $age = time() - $createdAt;
            if ($age > $maxAge) {
                deleteDirectory($path);
                
                // Nettoyer le state file d'upload
                $stateFile = TMP_PATH . '/.drive_prep_temp_' . $dir . '.json';
                if (file_exists($stateFile)) @unlink($stateFile);
                
                // Retirer de la file d'upload
                $queueFile = TMP_PATH . '/.drive_upload_queue.json';
                if (file_exists($queueFile)) {
                    $queue = json_decode(file_get_contents($queueFile), true) ?? [];
                    $newQueue = array_filter($queue, function($item) use ($dir) {
                        return $item['gdrive_id'] !== $dir;
                    });
                    if (count($newQueue) !== count($queue)) {
                        file_put_contents($queueFile, json_encode(array_values($newQueue), JSON_PRETTY_PRINT), LOCK_EX);
                    }
                }
                // Supprimer aussi l'index Drive temporaire
                $tempIndex = DRIVE_INDEX_DIR . '/temp_' . $dir . '.json';
                $tempData = DRIVE_INDEX_DIR . '/temp_' . $dir . '_data.json';
                if (file_exists($tempIndex)) @unlink($tempIndex);
                if (file_exists($tempData)) @unlink($tempData);
                
                $count++;
            }
        }
    }
    
    // Nettoyer aussi les cours temporaires drive-only expirés (dossier local supprimé, index Drive restant)
    if (is_dir(DRIVE_INDEX_DIR)) {
        foreach (glob(DRIVE_INDEX_DIR . '/temp_*_data.json') as $dataFile) {
            $basename = basename($dataFile);
            $profId = preg_replace('/^temp_|_data\.json$/', '', $basename);
            if (empty($profId)) continue;
            
            // Si le dossier local existe encore, il sera nettoyé par la boucle ci-dessus
            $localPath = COURSES_PATH . '/' . $profId;
            if (is_dir($localPath)) continue;
            
            // Vérifier l'âge via mtime du fichier d'index
            $age = time() - filemtime($dataFile);
            if ($age > $maxAge) {
                $indexFile = DRIVE_INDEX_DIR . '/temp_' . $profId . '.json';
                @unlink($dataFile);
                @unlink($indexFile);
                $count++;
            }
        }
    }
    
    return $count;
}

/**
 * Nettoie les fichiers d'index Drive orphelins dans cache/drive_index/
 * - Index permanents ({gdrive_id}.json) dont le cours n'existe plus sur le Drive
 * - Index temporaires (temp_{profId}.json) dont le cours n'existe plus ni localement ni en tant que drive-only
 * 
 * @param array $validGdriveIds  IDs Google Drive des cours permanents existants
 * @param array $validTempIds    IDs prof des cours temporaires existants (locaux + drive-only)
 */
/**
 * Purge le cache décompressé d'un cours permanent :
 *  - supprime le sous-dossier Drive (fichiers décompressés) via course_folder_id
 *  - supprime l'index local ({id}.json + {id}_data.json)
 * Après purge, le cours repasse « pending » et sera re-décompressé à la
 * prochaine ouverture (ensureSubfolder réutilise/recrée le dossier par nom).
 *
 * @param string        $fileId        ID Drive du .mbz source
 * @param callable|null $folderDeleter fn(string $folderId): void — défaut : DriveManager->delete
 * @return bool true si un index local existait
 */
function purgePermanentDriveCache(string $fileId, ?callable $folderDeleter = null): bool {
    $fileId = preg_replace('/[^a-zA-Z0-9_-]/', '', $fileId);
    if ($fileId === '') return false;

    $indexFile = DRIVE_INDEX_DIR . '/' . $fileId . '.json';
    $indexData = DRIVE_INDEX_DIR . '/' . $fileId . '_data.json';

    $courseFolderId = '';
    if (file_exists($indexFile)) {
        $idx = json_decode(@file_get_contents($indexFile), true) ?: [];
        $courseFolderId = $idx['course_folder_id'] ?? '';
    }

    // 1. Supprimer le dossier décompressé sur Drive (jamais fatal)
    if (!empty($courseFolderId)) {
        try {
            if ($folderDeleter !== null) {
                $folderDeleter($courseFolderId);
            } elseif (defined('DRIVE_OAUTH_CLIENT_JSON')) {
                require_once ROOT_PATH . '/DriveManager.php';
                $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
                $dm->delete($courseFolderId);
            }
        } catch (\Throwable $e) {
            // Non fatal : l'index local est tout de même retiré. Le dossier Drive
            // sera réutilisé (ensureSubfolder par nom) ou re-purgé au prochain passage.
            error_log("purgePermanentDriveCache: echec suppression dossier Drive $courseFolderId ($fileId): " . $e->getMessage());
        }
    }

    // 2. Supprimer l'index local
    $existed = file_exists($indexFile) || file_exists($indexData);
    if (file_exists($indexFile)) @unlink($indexFile);
    if (file_exists($indexData)) @unlink($indexData);

    return $existed;
}

/**
 * Réconcilie les index Drive locaux avec l'état réel de Drive.
 *
 * - temp_ : orphelin (profId absent) → retrait de l'index local (inchangé).
 * - permanent : orphelin (fileId absent du listing). Si $permanentExistenceCheck
 *   est fourni, on ne purge (dossier Drive décompressé + index local) que sur
 *   disparition CONFIRMÉE ('gone'). 'exists'/'unknown' (listing incomplet ou
 *   panne API) → on ne touche à rien : garde-fou anti-suppression massive.
 *   Sans vérificateur, ancien comportement (retrait de l'index local seul).
 *
 * @param callable|null $permanentExistenceCheck fn(string $fileId): 'exists'|'gone'|'unknown'
 * @param callable|null $permanentFolderDeleter  fn(string $folderId): void
 */
function cleanOrphanedDriveIndexes(
    array $validGdriveIds,
    array $validTempIds,
    ?callable $permanentExistenceCheck = null,
    ?callable $permanentFolderDeleter = null
): int {
    if (!is_dir(DRIVE_INDEX_DIR)) return 0;
    $count = 0;
    $seenPermanent = []; // évite de traiter 2× un cours ({id}.json + {id}_data.json)

    foreach (glob(DRIVE_INDEX_DIR . '/*.json') as $f) {
        $basename = basename($f, '.json');

        // Fichiers temp_ (inchangé)
        if (strpos($basename, 'temp_') === 0) {
            $profId = preg_replace('/^temp_|_data$/', '', $basename);
            if (!empty($profId) && !isset($validTempIds[$profId])) {
                @unlink($f);
                $count++;
            }
            continue;
        }

        // Fichiers internes (commencent par .)
        if (strpos($basename, '.') === 0) continue;

        // Fichiers permanents : enlever _data si présent pour obtenir le gdrive_id
        $gdriveId = preg_replace('/_data$/', '', $basename);
        if (empty($gdriveId) || isset($validGdriveIds[$gdriveId])) continue;
        if (isset($seenPermanent[$gdriveId])) continue;
        $seenPermanent[$gdriveId] = true;

        // Rétrocompat : sans vérificateur → retrait de l'index local uniquement
        if ($permanentExistenceCheck === null) {
            $i1 = DRIVE_INDEX_DIR . '/' . $gdriveId . '.json';
            $i2 = DRIVE_INDEX_DIR . '/' . $gdriveId . '_data.json';
            if (file_exists($i1)) { @unlink($i1); $count++; }
            if (file_exists($i2)) { @unlink($i2); $count++; }
            continue;
        }

        // GARDE-FOU : purge (Drive + local) uniquement sur disparition CONFIRMÉE.
        if ($permanentExistenceCheck($gdriveId) === 'gone') {
            purgePermanentDriveCache($gdriveId, $permanentFolderDeleter);
            $count++;
        }
    }

    return $count;
}

/**
 * Nettoie les sessions éditeur expirées (> 24h d'inactivité)
 * Supprime : dossier local, draft auto, metadata, dossier Drive
 */
function cleanExpiredEditorSessions(): int {
    $count = 0;
    $maxAge = 24 * 3600;
    $now = time();
    
    if (!is_dir(EDITOR_SESSIONS_DIR)) return 0;

    // Restes d'écritures atomiques interrompues (> 1 h) : sans intérêt, mais autant
    // ne rien laisser traîner sur le mutualisé.
    foreach (glob(EDITOR_SESSIONS_DIR . '/*.tmp*') ?: [] as $tmpFile) {
        if (is_file($tmpFile) && ($now - @filemtime($tmpFile)) > 3600) @unlink($tmpFile);
    }

    foreach (glob(EDITOR_SESSIONS_DIR . '/*.json') as $metaFile) {
        $meta = json_decode(@file_get_contents($metaFile), true);

        // Un metadata illisible n'est JAMAIS supprimé : il contient le file_mapping,
        // c'est-à-dire les seuls pointeurs vers les fichiers déjà partis sur le Drive
        // (le local est vidé au fur et à mesure pour libérer le mutualisé). L'ancienne
        // version faisait @unlink() ici, et comme ce nettoyage tourne à CHAQUE chargement
        // de index.php / editor.php, il suffisait de tomber pendant une réécriture du
        // JSON pour perdre tout le mapping d'un cours en cours d'édition — toutes les
        // images du cours disparaissaient d'un coup (incident du 07/08/2026, 552 → 0).
        // Désormais : on tente de reconstruire depuis le journal, sinon on met de côté.
        if (!is_array($meta)) {
            $safeIdCorrompu = preg_replace('/[^a-zA-Z0-9_-]/', '', basename($metaFile, '.json'));
            require_once __DIR__ . '/includes/EditorDriveSync.php';
            $repare = $safeIdCorrompu ? EditorDriveSync::getMeta($safeIdCorrompu) : null; // reconstruit via le journal
            if (is_array($repare)) {
                error_log("cleanExpiredEditorSessions: metadata illisible reconstruite depuis le journal ($safeIdCorrompu)");
                $meta = $repare;
            } else {
                $quarantaine = $metaFile . '.corrompu-' . date('Ymd-His');
                @rename($metaFile, $quarantaine);
                error_log("cleanExpiredEditorSessions: metadata illisible mise de côté ($quarantaine) — AUCUNE suppression");
                continue;
            }
        }

        $lastActivity = $meta['last_activity'] ?? $meta['created_at'] ?? 0;
        if (($now - $lastActivity) < $maxAge) continue;

        $sessionId = $meta['session_id'] ?? basename($metaFile, '.json');
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if (empty($safeId)) continue;
        
        // 1. Supprimer le dossier uploads local
        $uploadsDir = CACHE_DIR . '/editor_uploads/' . $safeId;
        if (is_dir($uploadsDir)) { deleteDirectory($uploadsDir); }
        
        // 2. Supprimer le draft auto
        $draftFile = CACHE_DIR . '/drafts/auto/' . $safeId . '.json';
        @unlink($draftFile . '.prev');
        foreach (glob($draftFile . '.tmp*') ?: [] as $f) @unlink($f);
        if (file_exists($draftFile)) { @unlink($draftFile); }
        
        // 3. Supprimer le dossier Drive si présent
        $driveFolderId = $meta['drive_folder_id'] ?? null;
        if ($driveFolderId) {
            try {
                require_once ROOT_PATH . '/DriveManager.php';
                $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
                $dm->delete($driveFolderId);
            } catch (\Throwable $e) {
                error_log("cleanExpiredEditorSessions: Drive delete error for $safeId: " . $e->getMessage());
            }
        }
        
        // 4. Supprimer la metadata, son journal et son verrou (session réellement expirée).
        //    Journal d'abord : getMeta() sait reconstruire une session depuis lui.
        @unlink(EDITOR_SESSIONS_DIR . '/' . $safeId . '.map.log');
        @unlink($metaFile);
        @unlink($metaFile . '.lock');
        $count++;
    }
    
    return $count;
}

/**
 * Supprime un dossier récursivement
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) return false;
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    return rmdir($dir);
}

// Création des dossiers si nécessaires
if (!is_dir(COURSES_PATH)) mkdir(COURSES_PATH, 0755, true);
if (!is_dir(TMP_PATH)) mkdir(TMP_PATH, 0755, true);
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
if (!is_dir(DRIVE_INDEX_DIR)) mkdir(DRIVE_INDEX_DIR, 0755, true);
if (!is_dir(EDITOR_SESSIONS_DIR)) mkdir(EDITOR_SESSIONS_DIR, 0755, true);

/**
 * Filet de sécurité : nettoie les fichiers temporaires de drive_downloads > 1h.
 * En fonctionnement normal, ces fichiers sont supprimés juste après usage.
 * Cette fonction attrape ceux qui resteraient après un crash.
 */
function cleanDriveDownloads(): int {
    $dir = TMP_PATH . '/drive_downloads';
    if (!is_dir($dir)) return 0;
    $count = 0;
    $now = time();
    foreach (glob($dir . '/*') as $f) {
        if (is_file($f) && ($now - filemtime($f)) > 3600) {
            @unlink($f);
            $count++;
        }
    }
    return $count;
}

/**
 * Keep-alive du token OAuth2 Google Drive.
 * Effectue un appel API minimal (about.get) toutes les 6 heures
 * pour éviter la révocation du refresh_token par Google (7 jours d'inactivité).
 * Appelé silencieusement à chaque chargement de page.
 */
function driveTokenKeepAlive(): void {
    // Vérifier que OAuth est configuré
    if (!defined('DRIVE_OAUTH_CLIENT_JSON') || !defined('GDRIVE_OAUTH_TOKEN_PATH')) return;
    if (!file_exists(DRIVE_OAUTH_CLIENT_JSON) || !file_exists(GDRIVE_OAUTH_TOKEN_PATH)) return;
    
    // Vérifier que c'est bien du OAuth (pas un service account)
    $clientConfig = @json_decode(@file_get_contents(DRIVE_OAUTH_CLIENT_JSON), true);
    if (!$clientConfig) return;
    if (($clientConfig['type'] ?? '') === 'service_account') return;
    
    // Throttle : une fois toutes les 6 heures max
    $stateFile = TMP_PATH . '/.drive_keepalive.json';
    $now = time();
    if (file_exists($stateFile)) {
        $state = @json_decode(@file_get_contents($stateFile), true);
        if ($state && ($now - ($state['last'] ?? 0)) < 6 * 3600) return;
    }
    
    // Mettre à jour le timestamp AVANT l'appel (évite les appels concurrents)
    @file_put_contents($stateFile, json_encode(['last' => $now]), LOCK_EX);
    
    try {
        require_once ROOT_PATH . '/DriveManager.php';
        $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
        // Appel API minimal : récupérer les infos de quota (about.get)
        $dm->getQuotaInfo();
    } catch (\Throwable $e) {
        // Échec silencieux — ne pas bloquer le chargement de la page
        error_log('Drive keep-alive error: ' . $e->getMessage());
    }
}

// Exécuter le keep-alive uniquement depuis la page d'accueil (pas les API)
// driveTokenKeepAlive() est appelé dans index.php
