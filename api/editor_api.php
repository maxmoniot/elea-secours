<?php
/**
 * API Éditeur de cours
 * Gère la sauvegarde des brouillons, l'export MBZ et l'import
 */
define('ELEA_EDITOR_VERSION', '2026-03-01-v3-resource');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');


// Capturer les erreurs critiques seulement
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Handler d'erreur personnalisé - ignore les deprecations et notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Respecter l'opérateur @ (error_reporting = 0 quand @ est utilisé)
    if (!(error_reporting() & $errno)) {
        return true; // @ utilisé, ne pas propager
    }
    // Ignorer les deprecations, notices et warnings.
    // NB : E_STRICT retiré — la constante est dépréciée depuis PHP 8.4 et la référencer
    // depuis le handler émettait un E_DEPRECATED qui rappelait le handler → récursion
    // infinie → erreur fatale → réponse HTML 500 au lieu du JSON attendu.
    if (in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE, E_WARNING, E_USER_WARNING])) {
        return true; // Ne pas propager
    }
    // Convertir les erreurs critiques en exceptions
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Handler pour les erreurs fatales non capturées
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error' => 'Erreur fatale PHP',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

// Prolonger la durée de vie de la session (8 heures)
ini_set('session.gc_maxlifetime', 28800);
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_check.php';

// SERVE_UPLOAD AVANT LES GATES DE SESSION : la lecture des médias de l'éditeur doit
// survivre à l'expiration de la session PHP (onglet ouvert des heures : gc OVH, cap 8h).
// Sinon, tous les audios/images cessent de se charger en pleine édition (403/401) alors
// que la page semble normale. Lecture seule, noms de fichiers non devinables — même
// modèle sans authentification que file_drive.php (viewer). Tout le reste reste gaté.
if (($_GET['action'] ?? '') === 'serve_upload') {
    $editorSessionId = $_SESSION['editor_session_id'] ?? '';
    // Libérer le verrou de session AVANT de servir : PHP verrouille le fichier de session
    // pendant toute la requête, donc sans ça les médias d'un même cours se servent les uns
    // APRÈS les autres, et bloquent en plus toutes les autres requêtes de l'éditeur.
    // C'est invisible tant que les fichiers sont sur le serveur (lecture disque, quelques ms),
    // mais dès qu'ils sont partis sur le Drive chaque image coûte un téléchargement OAuth :
    // 20 images = plusieurs dizaines de secondes de file d'attente, et la page reste sans
    // images. serveUpload() ne fait que LIRE l'identifiant ci-dessus, jamais écrire.
    session_write_close();
    serveUpload();
    exit;
}

// Expiration custom de session (8h, contournement bridage OVH) — retourne 401 JSON si expirée
enforceSessionExpiryJson();

// Vérification accès (tout utilisateur connecté)
if (!isset($_SESSION['elea_access']) || $_SESSION['elea_access'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Accès non autorisé']);
    exit;
}

// Récupérer les données de session nécessaires avant de libérer le verrou
$editorSessionId = $_SESSION['editor_session_id'] ?? '';

header('Content-Type: application/json');

// Dossier des brouillons
$draftsDir = CACHE_DIR . '/drafts';
if (!is_dir($draftsDir)) {
    @mkdir($draftsDir, 0755, true);
}

// Récupérer l'action
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';
} else {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $input = $_POST;
}

// Actions qui doivent écrire dans la session PHP
$sessionWriteActions = ['auto_save_draft', 'session_check'];
if (!in_array($action, $sessionWriteActions)) {
    session_write_close(); // Libérer le verrou de session pour ne pas bloquer les autres requêtes
}

try {
    switch ($action) {
        case 'session_check':
            $_SESSION['_last_activity'] = time();
            echo json_encode(['success' => true]);
            break;
            
        case 'save_draft':
            saveDraft($input, $draftsDir);
            break;
            
        case 'load_draft':
            loadDraft($input, $draftsDir);
            break;
            
        case 'list_drafts':
            listDrafts($draftsDir);
            break;
            
        case 'delete_draft':
            deleteDraft($input, $draftsDir);
            break;
            
        case 'export_mbz':
            exportMbz($input);
            break;
            
        case 'export_elea':
            exportElea($input);
            break;
        
        case 'export_diagnostic':
            exportDiagnostic($input);
            break;
            
        case 'parse_mbz':
            parseMbz();
            break;
            
        case 'list_drive_courses':
            listDriveCourses();
            break;
            
        case 'parse_drive_mbz':
            parseDriveMbz($input);
            break;
            
        case 'parse_local_course':
            parseLocalCourse($input);
            break;
            
        case 'upload_file':
            uploadFile();
            break;
        
        case 'upload_assign_file':
            uploadAssignFile();
            break;
        
        case 'copy_image_to_uploads':
            copyImageToUploads();
            break;
        
        case 'serve_upload':
            serveUpload();
            break;
            
        case 'auto_save_draft':
            autoSaveDraft($input, $draftsDir);
            break;
            
        case 'load_auto_draft':
            loadAutoDraft($input, $draftsDir);
            break;
            
        case 'clear_auto_draft':
            clearAutoDraft($input, $draftsDir);
            break;
            
        case 'clear_editor_uploads':
            clearEditorUploads($input, $draftsDir);
            break;
        

        
        case 'delete_files':
            deleteUploadedFiles($input);
            break;
            
        case 'list_templates':
            listTemplates();
            break;
            
        case 'load_template':
            loadTemplate($input);
            break;
            
        case 'list_cp_templates':
            listCpTemplates();
            break;
            
        case 'load_cp_template':
            loadCpTemplate($input);
            break;
        
        case 'get_progress':
            getProgress();
            break;

        case 'get_file_sizes':
            getFileSizes($input);
            break;
        
        case 'get_session_files_total':
            getSessionFilesTotal($input);
            break;
        
        case 'editor_flush':
            editorFlush($input);
            break;
        
        case 'cleanup_editor_session':
            cleanupEditorSession($input);
            break;

        case 'create_course':
            createCourse($input);
            break;
        
        case 'editor_session_status':
            editorSessionStatus($input);
            break;
        
        case 'sync_editor_files':
            syncEditorFiles($input);
            break;
        
        case 'list_editor_sessions':
            listEditorSessions();
            break;
        
        case 'preview_editor_session':
            previewEditorSession($input);
            break;
        
        case 'load_editor_session_draft':
            loadEditorSessionDraft($input);
            break;
        
        case 'has_draft':
            $sid = $input['sessionId'] ?? $editorSessionId ?? '';
            $safeSid = preg_replace('/[^a-zA-Z0-9_-]/', '', $sid);
            $has = false;
            if ($safeSid) {
                $autoPath = $draftsDir . '/auto/' . $safeSid . '.json';
                if (file_exists($autoPath) && filesize($autoPath) > 50) {
                    $has = true;
                }
            }
            echo json_encode(['success' => true, 'has_draft' => $has]);
            break;
        
        case 'check_version':
            echo json_encode([
                'version' => ELEA_EDITOR_VERSION,
                'file_size' => filesize(__FILE__),
                'file_mtime' => date('Y-m-d H:i:s', filemtime(__FILE__)),
                'has_resource' => method_exists('MbzExporter', 'generateResourceActivity') || strpos(file_get_contents(__FILE__), 'generateResourceActivity') !== false,
                'opcache' => function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_enabled'] ?? false) : 'N/A'
            ]);
            break;
        
        case 'get_server_usage':
            $usage = getServerTotalUsage();
            echo json_encode([
                'success' => true,
                'usage_bytes' => $usage,
                'usage_mb' => round($usage / (1024 * 1024), 1),
                'max_mb' => SERVER_MAX_MB,
                'full' => ($usage >= SERVER_MAX_MB * 1024 * 1024)
            ]);
            break;
        
        case 'preview_pdf':
            previewForPdf($input);
            break;
        
        case 'cleanup_pdf_preview':
            $previewId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['previewId'] ?? '');
            if ($previewId && strpos($previewId, 'pdf-preview-') === 0) {
                $dir = COURSES_PATH . '/' . $previewId;
                if (is_dir($dir)) {
                    deleteDirectory($dir);
                    echo json_encode(['success' => true, 'deleted' => $previewId]);
                } else {
                    echo json_encode(['success' => true]);
                }
            } else {
                echo json_encode(['error' => 'Invalid preview ID']);
            }
            break;
            
        default:
            echo json_encode(['error' => 'Action non reconnue: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Exception globale',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    echo json_encode([
        'error' => 'Error globale',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

/**
 * Sauvegarde un brouillon de cours
 */
function saveDraft($input, $draftsDir) {
    $data = $input['data'] ?? null;
    if (!$data) {
        echo json_encode(['error' => 'Données manquantes']);
        return;
    }
    
    // Générer ou utiliser l'ID existant
    $id = $data['id'] ?? 'draft_' . time() . '_' . bin2hex(random_bytes(4));
    $data['id'] = $id;
    $data['savedAt'] = date('c');
    
    // Sauvegarder le fichier JSON — écriture atomique (une écriture interrompue
    // laisserait un brouillon tronqué, donc un cours illisible).
    $filepath = $draftsDir . '/' . $id . '.json';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ecrit = false;
    if ($json !== false && $json !== '') {
        $tmp = $filepath . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $json) === strlen($json)) {
            for ($essai = 0; $essai < 4 && !$ecrit; $essai++) {
                $ecrit = @rename($tmp, $filepath);
                if (!$ecrit) usleep(30000);
            }
        }
        if (!$ecrit) @unlink($tmp);
    }
    if ($ecrit) {
        echo json_encode([
            'success' => true,
            'id' => $id,
            'savedAt' => $data['savedAt']
        ]);
    } else {
        echo json_encode(['error' => 'Erreur d\'écriture']);
    }
}

/**
 * Charge un brouillon
 */
function loadDraft($input, $draftsDir) {
    $id = $input['id'] ?? '';
    if (!$id) {
        echo json_encode(['error' => 'ID manquant']);
        return;
    }
    
    $filepath = $draftsDir . '/' . $id . '.json';
    if (!file_exists($filepath)) {
        echo json_encode(['error' => 'Brouillon non trouvé']);
        return;
    }
    
    $data = json_decode(file_get_contents($filepath), true);
    echo json_encode(['success' => true, 'data' => $data]);
}

/**
 * Liste tous les brouillons
 */
function listDrafts($draftsDir) {
    $drafts = [];
    
    foreach (glob($draftsDir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $drafts[] = [
                'id' => $data['id'] ?? basename($file, '.json'),
                'name' => $data['name'] ?? 'Sans titre',
                'savedAt' => $data['savedAt'] ?? date('c', filemtime($file)),
                'sectionsCount' => count($data['sections'] ?? [])
            ];
        }
    }
    
    // Trier par date décroissante
    usort($drafts, function($a, $b) {
        return strtotime($b['savedAt']) - strtotime($a['savedAt']);
    });
    
    echo json_encode(['success' => true, 'drafts' => $drafts]);
}

/**
 * Supprime un brouillon
 */
function deleteDraft($input, $draftsDir) {
    $id = $input['id'] ?? '';
    if (!$id) {
        echo json_encode(['error' => 'ID manquant']);
        return;
    }
    
    $filepath = $draftsDir . '/' . $id . '.json';
    if (file_exists($filepath) && unlink($filepath)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Impossible de supprimer']);
    }
}

// ==================== AUTO-SAVE PAR UTILISATEUR ====================

/**
 * Sauvegarde automatique du brouillon pour un utilisateur spécifique
 * Chaque utilisateur a son propre fichier basé sur son sessionId
 */
function autoSaveDraft($input, $draftsDir) {
    $sessionId = $input['sessionId'] ?? '';
    $data = $input['data'] ?? null;
    
    if (!$sessionId) {
        echo json_encode(['error' => 'Session ID manquant']);
        return;
    }
    
    if (!$data) {
        echo json_encode(['error' => 'Données manquantes']);
        return;
    }
    
    // Sécuriser le sessionId pour éviter les path traversal
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    if (strlen($safeSessionId) < 5) {
        echo json_encode(['error' => 'Session ID invalide']);
        return;
    }
    
    // Créer le sous-dossier auto_drafts s'il n'existe pas
    $autoDraftsDir = $draftsDir . '/auto';
    if (!is_dir($autoDraftsDir)) {
        mkdir($autoDraftsDir, 0755, true);
    }
    
    // Stocker le sessionId éditeur dans la session PHP
    $_SESSION['editor_session_id'] = $safeSessionId;
    session_write_close(); // Libérer le verrou immédiatement
    
    // Sauvegarder le fichier — écriture ATOMIQUE (temporaire + rename) et conservation
    // de la version précédente. Le brouillon fait plusieurs centaines de Ko et il est
    // réécrit sans arrêt : une écriture interrompue (timeout du mutualisé, onglet fermé)
    // laissait un JSON tronqué, c'est-à-dire un cours entier illisible.
    $filepath = $autoDraftsDir . '/' . $safeSessionId . '.json';
    $data['savedAt'] = date('c');
    $data['sessionId'] = $safeSessionId;

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $ecrit = false;
    if ($json !== false && $json !== '') {
        $tmp = $filepath . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $json) === strlen($json)) {
            if (is_file($filepath) && filesize($filepath) > 50) {
                @copy($filepath, $filepath . '.prev');
            }
            for ($essai = 0; $essai < 4 && !$ecrit; $essai++) {
                $ecrit = @rename($tmp, $filepath);
                if (!$ecrit) usleep(30000);
            }
        }
        if (!$ecrit) @unlink($tmp);
    }

    if ($ecrit) {
        // Mettre à jour la session EditorDriveSync (timestamp + nom uniquement)
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
        EditorDriveSync::touchSession($safeSessionId, $data['name'] ?? '');
        
        echo json_encode([
            'success' => true,
            'savedAt' => $data['savedAt']
        ]);
    } else {
        echo json_encode(['error' => 'Erreur d\'écriture']);
    }
}

/**
 * Charge le brouillon automatique d'un utilisateur
 */
function loadAutoDraft($input, $draftsDir) {
    $sessionId = $input['sessionId'] ?? '';
    
    if (!$sessionId) {
        echo json_encode(['error' => 'Session ID manquant']);
        return;
    }
    
    // Sécuriser le sessionId
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    
    $filepath = $draftsDir . '/auto/' . $safeSessionId . '.json';

    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'message' => 'Pas de brouillon trouvé']);
        return;
    }

    $data = json_decode(@file_get_contents($filepath), true);
    // Brouillon illisible : reprendre la version précédente plutôt que de rendre une
    // erreur (le professeur perdrait tout le cours). Écart maxi = un cycle d'autosave.
    if (!is_array($data) && is_file($filepath . '.prev')) {
        $data = json_decode(@file_get_contents($filepath . '.prev'), true);
        if (is_array($data)) {
            error_log("loadAutoDraft: brouillon illisible pour $safeSessionId — version précédente restaurée");
            @copy($filepath . '.prev', $filepath);
        }
    }
    if ($data) {
        // === Migration des anciens drafts ===
        if (!empty($data['sections'])) {
            foreach ($data['sections'] as &$section) {
                if (!empty($section['activities'])) {
                    foreach ($section['activities'] as &$activity) {
                        $type = $activity['type'] ?? '';
                        $h5pType = $activity['h5pType'] ?? '';
                        $name = strtolower($activity['name'] ?? '');
                        
                        // Migration: activité "resource" mal routée comme H5P
                        // Cas 1: h5pType='resource' (ancien code routait selectedActivityType directement)
                        // Cas 2: a un champ files[] mais mauvais type (PAS assign qui a aussi files[])
                        // Cas 3: nom contient "fichiers à distribuer" 
                        if ($type !== 'resource' && $type !== 'assign' && (
                            $h5pType === 'resource' ||
                            (isset($activity['files']) && is_array($activity['files'])) ||
                            strpos($name, 'fichiers à distribuer') !== false ||
                            strpos($name, 'fichiers a distribuer') !== false
                        )) {
                            $activity['type'] = 'resource';
                            $activity['h5pType'] = '';
                            if (!isset($activity['files'])) $activity['files'] = [];
                            if (!isset($activity['intro'])) $activity['intro'] = '';
                            unset($activity['content']);
                        }
                        
                        // Migration: renommage du nom par défaut assign
                        if (($activity['type'] ?? '') === 'assign' && 
                            in_array($name, ['nouveau fichier à distribuer', 'nouveau fichier a distribuer'])) {
                            $activity['name'] = 'Nouveau travail à déposer';
                        }
                        
                        // Nettoyer h5pType pour les types non-H5P
                        if (in_array($activity['type'] ?? '', ['assign', 'resource', 'mapmodules', 'quiz'])) {
                            $activity['h5pType'] = '';
                        }
                    }
                    unset($activity);
                }
            }
            unset($section);
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        echo json_encode(['error' => 'Erreur de lecture du brouillon']);
    }
}

/**
 * Supprime le brouillon automatique d'un utilisateur
 */
function clearAutoDraft($input, $draftsDir) {
    $sessionId = $input['sessionId'] ?? '';
    
    if (!$sessionId) {
        echo json_encode(['error' => 'Session ID manquant']);
        return;
    }
    
    // Sécuriser le sessionId
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    
    $filepath = $draftsDir . '/auto/' . $safeSessionId . '.json';
    
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    
    echo json_encode(['success' => true]);
}

/**
 * Supprime tous les fichiers uploadés dans l'éditeur et le brouillon auto
 * Appelé lors de la création d'un nouveau cours
 */
function clearEditorUploads($input, $draftsDir) {
    $deleted = 0;
    $errors = [];
    
    $sessionId = $input['sessionId'] ?? '';
    if (!$sessionId) {
        echo json_encode(['success' => true, 'deleted' => 0, 'errors' => []]);
        return;
    }
    
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    
    // 1. Supprimer le dossier session complet (nouveau chemin)
    $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
    if (is_dir($sessionDir)) {
        $files = glob($sessionDir . '/*');
        $deleted += $files ? count($files) : 0;
        deleteDirectory($sessionDir);
    }
    
    // 2. Aussi supprimer les fichiers de l'ancien chemin plat (rétrocompat)
    $uploadDir = CACHE_DIR . '/editor_uploads';
    $autoPath = $draftsDir . '/auto/' . $safeSessionId . '.json';
    $sessionFiles = extractDraftFilenames($autoPath);
    foreach ($sessionFiles as $fn) {
        $fp = $uploadDir . '/' . $fn;
        if (is_file($fp)) {
            if (@unlink($fp)) $deleted++;
            else $errors[] = $fn;
        }
    }
    
    // 3. Supprimer le brouillon auto
    if (file_exists($autoPath)) {
        @unlink($autoPath);
    }
    
    // 4. Nettoyer la metadata et le dossier Drive
    require_once __DIR__ . '/../includes/EditorDriveSync.php';
    $meta = EditorDriveSync::getMeta($safeSessionId);
    $driveResult = 'skipped';
    if ($meta && !empty($meta['drive_folder_id'])) {
        try {
            require_once ROOT_PATH . '/DriveManager.php';
            $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
            $dm->delete($meta['drive_folder_id']);
            $driveResult = 'deleted';
        } catch (\Throwable $e) {
            $driveResult = 'error:' . $e->getMessage();
        }
    }
    
    // 5. Supprimer la metadata
    $metaFile = EDITOR_SESSIONS_DIR . '/' . $safeSessionId . '.json';
    if (file_exists($metaFile)) @unlink($metaFile);
    
    echo json_encode([
        'success' => true,
        'deleted' => $deleted,
        'drive' => $driveResult,
        'errors' => $errors
    ]);
}


/**
 * Extraire tous les noms de fichiers (upload_*, import_*, tpl_*) référencés dans un brouillon
 */
function extractDraftFilenames($draftPath) {
    $filenames = [];
    if (!file_exists($draftPath)) return $filenames;
    
    $content = file_get_contents($draftPath);
    if (!$content) return $filenames;
    
    // Scanner toutes les occurrences de noms de fichiers éditeur
    if (preg_match_all('/(?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w{2,5}/', $content, $matches)) {
        $filenames = array_unique($matches[0]);
    }
    return $filenames;
}



function deleteUploadedFiles($input) {
    $filenames = $input['filenames'] ?? [];
    if (!is_array($filenames) || empty($filenames)) {
        echo json_encode(['success' => true, 'scheduled' => 0]);
        return;
    }
    
    require_once __DIR__ . '/../includes/cleanup.php';
    schedulePendingDeletes($filenames);
    
    echo json_encode(['success' => true, 'scheduled' => count($filenames)]);
}

/**
 * Export en format MBZ (Moodle Backup)
 */
function exportMbz($input) {
    ignore_user_abort(true);
    @set_time_limit(600);
    @ini_set('max_execution_time', '600');
    @ini_set('memory_limit', '512M');
    
    $data = $input['data'] ?? null;
    if (!$data) {
        echo json_encode(['error' => 'Données manquantes']);
        return;
    }
    
    try {
        $sessionId = $input['sessionId'] ?? '';
        $exporter = new MbzExporter($data, $sessionId);
        $mbzPath = $exporter->export();
        
        // Générer l'URL de téléchargement (via script qui supprime après)
        $filename = basename($mbzPath);
        $downloadUrl = SITE_URL . '/download.php?file=' . urlencode($filename);
        
        echo json_encode([
            'success' => true,
            'downloadUrl' => $downloadUrl,
            'filename' => $filename
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Erreur d\'export: ' . $e->getMessage()]);
    }
}

/**
 * Export en format MBZ compatible Éléa (tar.gz avec structure complète)
 */
function exportElea($input) {
    ignore_user_abort(true);
    @set_time_limit(600);
    @ini_set('max_execution_time', '600');
    @ini_set('memory_limit', '512M');
    
    // Écrire un log de progression dans un fichier (survit aux crashs)
    $logFile = TMP_PATH . '/.export_progress.log';
    $logProgress = function($msg) use ($logFile) {
        $line = date('H:i:s') . ' ' . round(memory_get_usage(true)/(1024*1024),1) . 'Mo ' . $msg . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    };
    @file_put_contents($logFile, "=== Export " . date('H:i:s') . " ===\n"); // Reset avec timestamp
    
    $data = $input['data'] ?? null;
    if (!$data) {
        echo json_encode(['error' => 'Données manquantes']);
        return;
    }
    
    // Installer un handler pour les erreurs fatales
    register_shutdown_function(function() use ($logFile, $logProgress) {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $logProgress('FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            // Nettoyer drive_downloads en cas de crash
            $dlDir = TMP_PATH . '/drive_downloads';
            if (is_dir($dlDir)) {
                foreach (glob($dlDir . '/*') as $f) {
                    if (is_file($f)) @unlink($f);
                }
            }
        }
    });
    
    try {
        $logProgress('START export');
        require_once __DIR__ . '/../includes/EleaMbzExporter.php';
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
        $sessionId = $input['sessionId'] ?? '';
        
        // Attendre que le batch de flush en cours soit terminé (max 15s)
        // Le JS a appelé pauseFlush() mais un batch HTTP peut encore être en cours côté serveur.
        // Une fois terminé, le mapping Drive est à jour et tous les fichiers sont accessibles.
        if (!EditorDriveSync::isFlushLockFree()) {
            $logProgress('Waiting for flush lock...');
            $waited = 0;
            while ($waited < 15 && !EditorDriveSync::isFlushLockFree()) {
                usleep(500000);
                $waited += 0.5;
            }
            $logProgress('Flush lock free after ' . $waited . 's');
        }
        
        $logProgress('Creating exporter, sessionId=' . $sessionId);
        $t0 = microtime(true);
        $exporter = new EleaMbzExporter($data, $sessionId);
        // Barre de progression de l'éditeur : l'exporteur publie son avancement, le
        // navigateur l'interroge en parallèle via l'action get_progress.
        $progressId = $input['progressId'] ?? '';
        if ($progressId !== '') {
            $exporter->setProgressCallback(function ($percent, $label) use ($progressId) {
                progressSet($progressId, $percent, $label);
            });
        }
        $t1 = microtime(true);
        $logProgress('Exporter created in ' . round(($t1-$t0)*1000) . 'ms, calling export()');
        
        $mbzPath = $exporter->export();
        $t2 = microtime(true);
        $logProgress('Export done in ' . round(($t2-$t1)*1000) . 'ms, mbz=' . basename($mbzPath));
        
        // Nettoyer drive_downloads après l'export pour libérer l'espace
        $dlDir = TMP_PATH . '/drive_downloads';
        if (is_dir($dlDir)) {
            foreach (glob($dlDir . '/*') as $f) {
                if (is_file($f)) @unlink($f);
            }
            $logProgress('drive_downloads nettoyé');
        }
        
        $filename = basename($mbzPath);
        $downloadUrl = SITE_URL . '/download.php?file=' . urlencode($filename);
        $mbzSize = file_exists($mbzPath) ? filesize($mbzPath) : 0;
        
        $logProgress('SUCCESS size=' . round($mbzSize/1024) . 'Ko manifest=' . count($exporter->getFilesManifest()));
        if ($progressId !== '') progressClear($progressId);
        
        echo json_encode([
            'success' => true,
            'downloadUrl' => $downloadUrl,
            'filename' => $filename,
            // Activités que l'exporteur n'a pas su traduire : à signaler au professeur,
            // sinon elles disparaissent du .mbz sans qu'il le sache.
            'droppedActivities' => $exporter->getDroppedActivities(),
            // Médias référencés mais introuvables partout (local, Drive toutes sessions) :
            // le .mbz est incomplet pour eux — le professeur doit le savoir AVANT
            // d'importer sur Éléa (constaté le 07/08/2026 : 7 médias perdus en silence).
            'unresolvedFiles' => $exporter->getUnresolvedFiles(),
            '_debug' => [
                'init_ms' => round(($t1 - $t0) * 1000),
                'export_ms' => round(($t2 - $t1) * 1000),
                'total_ms' => round(($t2 - $t0) * 1000),
                'mbz_size_mb' => round($mbzSize / (1024*1024), 2),
                'files_in_manifest' => count($exporter->getFilesManifest()),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / (1024*1024), 1),
                'export_logs' => $exporter->getExportLogs(),
            ]
        ]);
    } catch (\Throwable $e) {
        $logProgress('ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!empty($progressId)) progressClear($progressId);
        // Nettoyer drive_downloads même en cas d'erreur
        $dlDir = TMP_PATH . '/drive_downloads';
        if (is_dir($dlDir)) {
            foreach (glob($dlDir . '/*') as $f) {
                if (is_file($f)) @unlink($f);
            }
        }
        echo json_encode(['error' => 'Erreur d\'export Éléa: ' . $e->getMessage()]);
    }
}

/**
 * Diagnostic d'export : analyse le cours sans exporter, teste la connectivité Drive
 */
function exportDiagnostic($input) {
    @ini_set('memory_limit', '256M');
    
    $data = $input['data'] ?? null;
    $sessionId = $input['sessionId'] ?? '';
    if (!$data) {
        echo json_encode(['error' => 'Données manquantes']);
        return;
    }
    
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    $jsonSize = strlen($json);
    
    // Compter les types d'URLs
    $lh3Count = 0;
    $lh3Ids = [];
    if (preg_match_all('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $json, $m)) {
        $lh3Ids = array_unique($m[1]);
        $lh3Count = count($lh3Ids);
    }
    
    $serveUploadCount = 0;
    $serveUploadFiles = [];
    if (preg_match_all('/(?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w{2,5}/', $json, $m)) {
        $serveUploadFiles = array_unique($m[0]);
        $serveUploadCount = count($serveUploadFiles);
    }
    
    // Vérifier les fichiers locaux
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeId;
    $localFiles = is_dir($sessionDir) ? (glob($sessionDir . '/*') ?: []) : [];
    $localCount = count($localFiles);
    $localSize = array_sum(array_map('filesize', $localFiles));
    
    // Vérifier le mapping Drive
    $driveMapping = [];
    $driveMappingCount = 0;
    if ($safeId) {
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
        $meta = EditorDriveSync::getMeta($safeId);
        $driveMapping = $meta['file_mapping'] ?? [];
        $driveMappingCount = count($driveMapping);
    }
    
    // Vérifier le cache drive_downloads
    $cacheDir = TMP_PATH . '/drive_downloads';
    $cachedFiles = is_dir($cacheDir) ? (glob($cacheDir . '/*') ?: []) : [];
    $cachedCount = count($cachedFiles);
    
    // Combien de lh3 sont déjà en cache ?
    $lh3InCache = 0;
    foreach ($lh3Ids as $did) {
        $match = glob($cacheDir . '/' . $did . '_*');
        if ($match && file_exists($match[0]) && filesize($match[0]) > 0) $lh3InCache++;
    }
    
    // Tester curl_multi
    $curlMultiAvailable = function_exists('curl_multi_init');
    
    // Tester le téléchargement d'UN fichier lh3 (si disponible)
    $testDownload = null;
    if ($lh3Count > 0) {
        $testId = $lh3Ids[0];
        $testUrl = 'https://lh3.googleusercontent.com/d/' . $testId;
        $t0 = microtime(true);
        $ch = curl_init($testUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $t1 = microtime(true);
        
        $testDownload = [
            'driveId' => $testId,
            'http_code' => $httpCode,
            'size_bytes' => $result ? strlen($result) : 0,
            'time_ms' => round(($t1 - $t0) * 1000),
            'curl_error' => $curlError ?: null,
        ];
    }
    
    // Lire le log de progression du dernier export
    $lastExportLog = null;
    $logFile = TMP_PATH . '/.export_progress.log';
    if (file_exists($logFile)) {
        $lastExportLog = file_get_contents($logFile);
    }
    
    echo json_encode([
        'success' => true,
        'diagnostic' => [
            'json_size_kb' => round($jsonSize / 1024),
            'sections' => count($data['sections'] ?? []),
            'lh3_urls_unique' => $lh3Count,
            'lh3_in_cache' => $lh3InCache,
            'serve_upload_unique' => $serveUploadCount,
            'local_files' => $localCount,
            'local_size_mb' => round($localSize / (1024*1024), 2),
            'drive_mapping_count' => $driveMappingCount,
            'cache_files' => $cachedCount,
            'curl_multi' => $curlMultiAvailable,
            'test_download' => $testDownload,
            'php_max_execution' => ini_get('max_execution_time'),
            'php_memory_limit' => ini_get('memory_limit'),
            'last_export_log' => $lastExportLog,
        ]
    ]);
}

/**
 * Parse un fichier MBZ pour import
 */
/**
 * Étiquette (module « label ») et Page (module « page ») : des modules de TEXTE
 * Moodle, pas du H5P.
 *
 * Sans cette conversion ils passaient dans la moulinette H5P et ressortaient en
 * « H5P.label » / « H5P.page » — des bibliothèques qui n'existent pas, donc une
 * activité vide et cassée dans Éléa (c'était le cas de l'étiquette d'accueil
 * « Bonjour, faites les activités ci-dessous… », présente dans tous les parcours).
 */
function buildTextModuleActivity(array $activity, array $fileMapping, string $id): array {
    $type = ($activity['type'] ?? 'label') === 'page' ? 'page' : 'label';
    $out = [
        'id' => $id,
        'type' => $type,
        'name' => $activity['name'] ?? ($type === 'page' ? 'Page' : 'Étiquette'),
        'h5pType' => '',
        // Le texte de l'étiquette vit dans « intro » ; celui d'une page dans « content ».
        'intro' => resolvePluginfileUrls($activity['intro'] ?? '', $fileMapping),
        'content' => '',
    ];
    if ($type === 'page') {
        $corps = $activity['content'] ?? '';
        $out['content'] = resolvePluginfileUrls(is_string($corps) ? $corps : '', $fileMapping);
    }
    return $out;
}

/**
 * Vignette du cours (image de la carte du parcours dans Éléa).
 *
 * Dans le .mbz c'est un fichier du CONTEXTE DU COURS : component « course »,
 * filearea « overviewfiles ». Il n'appartient à aucune activité, donc rien ne le
 * ramenait dans l'éditeur — et il disparaissait à l'export. Les fichiers ayant
 * déjà été recopiés dans le dossier de la session, on se contente de retrouver
 * l'URL correspondante.
 *
 * @return array{url:string,name:string}|null
 */
function findCourseVignette(array $mbzFiles, array $hashMapping, array $fileMapping): ?array {
    foreach ($mbzFiles as $f) {
        if (($f['component'] ?? '') !== 'course') continue;
        if (($f['filearea'] ?? '') !== 'overviewfiles') continue;
        $nom = $f['filename'] ?? '.';
        if ($nom === '.' || $nom === '') continue;   // entrée de dossier

        $hash = $f['hash'] ?? '';
        $url = null;
        if ($hash !== '' && isset($hashMapping[$hash])) {
            $url = $hashMapping[$hash]['url'];
        } elseif (isset($fileMapping[$nom])) {
            $url = $fileMapping[$nom];
        } else {
            foreach ($fileMapping as $cle => $u) {
                if (basename($cle) === $nom) { $url = $u; break; }
            }
        }
        if ($url) return ['url' => $url, 'name' => $nom];
    }
    return null;
}

function parseMbz() {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Erreur d\'upload']);
        return;
    }
    
    // Vérifier taille max
    $fileSize = $_FILES['file']['size'] ?? 0;
    if ($fileSize > MAX_UPLOAD_SIZE) {
        echo json_encode(['error' => 'Fichier trop volumineux (' . round($fileSize / (1024*1024)) . ' Mo). Limite : ' . round(MAX_UPLOAD_SIZE / (1024*1024)) . ' Mo.']);
        return;
    }
    
    // Vérifier espace serveur avant extraction
    $fileSize = $_FILES['file']['size'] ?? 0;
    if (!canUpload($fileSize * 3)) { // x3 : MBZ compressé → fichiers extraits
        echo json_encode(['error' => 'Espace serveur insuffisant (' . round(getServerTotalUsage() / (1024*1024)) . ' Mo / ' . SERVER_MAX_MB . ' Mo). Libérez de l\'espace avant d\'ouvrir un cours.']);
        return;
    }
    
    $tmpFile = $_FILES['file']['tmp_name'];
    
    try {
        require_once __DIR__ . '/../includes/MbzParser.php';
        
        // Extraire temporairement
        $extractDir = CACHE_DIR . '/import_' . time();
        mkdir($extractDir, 0777, true);
        
        // Dossier pour les fichiers permanents (namespacé par session)
        $sessionId = $_POST['session_id'] ?? '';
        $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if ($safeSessionId) {
            $uploadDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
        } else {
            $uploadDir = CACHE_DIR . '/editor_uploads';
        }
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Barre de progression de l'éditeur : le parser publie ses phases (0-70 %),
        // la copie des fichiers et la conversion prennent la suite (70-100 %).
        $progressId = $_POST['progressId'] ?? '';
        $parser = new MbzParser($tmpFile, $extractDir);
        if ($progressId !== '') {
            $parser->setProgressCallback(function ($percent, $label) use ($progressId) {
                progressSet($progressId, $percent, $label);
            });
        }
        $courseData = $parser->parse();
        
        // Extraire les informations
        $courseInfo = $courseData['course'] ?? [];
        $sections = $courseData['sections'] ?? [];
        $mbzFiles = $courseData['files'] ?? [];
        
        // Base URL pour les fichiers uploadés
        $baseUrl = dirname(dirname($_SERVER['SCRIPT_NAME']));
        
        // Copier les fichiers et créer un mapping ancien chemin -> nouveau URL
        $fileMapping = [];
        $hashMapping = []; // hash -> {url, originalName}
        $totalFichiers = count($mbzFiles);
        $fichiersFaits = 0;
        foreach ($mbzFiles as $file) {
            // Un point d'avancement tous les 5 fichiers : écrire à chaque fichier coûterait
            // plus cher que la copie elle-même sur les petites images.
            if ($progressId !== '' && ($fichiersFaits % 5) === 0) {
                progressSet($progressId,
                    $totalFichiers ? 72 + 22 * ($fichiersFaits / $totalFichiers) : 72,
                    'Fichier ' . ($fichiersFaits + 1) . '/' . $totalFichiers . '…');
            }
            $fichiersFaits++;

            if (empty($file['hash']) || $file['filename'] === '.') continue;

            // Chemin source dans l'archive extraite
            $prefix = substr($file['hash'], 0, 2);
            $srcPath = $extractDir . '/files/' . $prefix . '/' . $file['hash'];

            if (!file_exists($srcPath)) continue;

            // Générer un nouveau nom unique
            $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
            if (empty($ext)) {
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                           'image/webp' => 'webp', 'video/mp4' => 'mp4'];
                $ext = $extMap[$file['mimetype']] ?? 'bin';
            }

            $newFilename = 'import_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . '/' . $newFilename;

            // Copier le fichier
            if (copy($srcPath, $destPath)) {
                $newUrl = 'api/editor_api.php?action=serve_upload&file=' . urlencode($newFilename);
                if ($safeSessionId) $newUrl .= '&session=' . urlencode($safeSessionId);

                // Mapping par hash (pour assign et lookups directs)
                $hashMapping[$file['hash']] = ['url' => $newUrl, 'name' => $file['filename']];
                
                // Créer plusieurs variantes de mapping pour le chemin H5P
                // Format MBZ: filepath="/images/", filename="file-xxx.png"
                // Format H5P: "images/file-xxx.png" ou "images/file-xxx.png#tmp"
                
                $filepath = trim($file['filepath'], '/'); // "images" ou ""
                $filename = $file['filename']; // "file-xxx.png"
                
                // Chemin complet sans slash initial
                $fullPath = $filepath ? $filepath . '/' . $filename : $filename;
                
                // Ajouter toutes les variantes possibles
                $fileMapping[$fullPath] = $newUrl;                    // "images/file-xxx.png"
                $fileMapping[$fullPath . '#tmp'] = $newUrl;           // "images/file-xxx.png#tmp"
                $fileMapping['/' . $fullPath] = $newUrl;              // "/images/file-xxx.png"
                $fileMapping[$filename] = $newUrl;                    // "file-xxx.png" (fallback)
            }
        }
        
        // Convertir en format éditeur
        if ($progressId !== '') progressSet($progressId, 95, 'Conversion du cours…');
        $editorSections = [];
        foreach ($sections as $section) {
            $editorSection = [
                'id' => 'import_' . ($section['id'] ?? uniqid()),
                'name' => $section['name'] ?? 'Section',
                'summary' => strip_tags($section['summary'] ?? ''),
                'visible' => ($section['visible'] ?? 1) ? true : false,
                'activities' => []
            ];

            foreach ($section['activities'] ?? [] as $activity) {
                $actType = $activity['type'] ?? 'hvp';

                // Carte de progression: pas un H5P, copier les champs spécifiques
                if ($actType === 'mapmodules') {
                    // Détecter image personnalisée via le nom Éléa
                    $mapName = $activity['name'] ?? '';
                    $mapImage = null;
                    
                    if (stripos($mapName, 'Carte personnalisée') !== false) {
                        // Chercher le fichier image dans les fichiers MBZ (component=mod_mapmodules, filearea=maps)
                        foreach ($mbzFiles as $mf) {
                            if (($mf['component'] ?? '') === 'mod_mapmodules' 
                                && ($mf['filearea'] ?? '') === 'maps'
                                && ($mf['filename'] ?? '.') !== '.') {
                                // Chercher dans le fileMapping
                                $fn = $mf['filename'];
                                if (isset($fileMapping[$fn])) {
                                    $mapImage = $fileMapping[$fn];
                                } else {
                                    // Essayer de trouver par hash
                                    foreach ($fileMapping as $key => $url) {
                                        if (strpos($key, $fn) !== false) {
                                            $mapImage = $url;
                                            break;
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }
                    
                    $editorSection['activities'][] = [
                        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type' => 'mapmodules',
                        'name' => $activity['name'] ?? 'Carte de progression',
                        'mapPath' => $activity['mapPath'] ?? $activity['path'] ?? '',
                        'mapImage' => $mapImage,
                        'descriptionHeader' => $activity['descriptionHeader'] ?? '',
                        'descriptionFooter' => $activity['descriptionFooter'] ?? '',
                        'iconset' => $activity['iconset'] ?? 4,
                        'buttonWidth' => $activity['buttonWidth'] ?? 50,
                        'targetsection' => $activity['targetsection'] ?? '666',
                    ];
                    continue;
                }
                
                // Devoir (travail à déposer)
                if ($actType === 'assign') {
                    // Collecter TOUS les fichiers (content_files, fallback main_file)
                    $assignContentFiles = $activity['content_files'] ?? [];
                    $assignFiles = [];
                    foreach ($assignContentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null;
                            $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) {
                                $fUrl = $hashMapping[$cf['hash']]['url'];
                            } elseif (isset($fileMapping[$fName])) {
                                $fUrl = $fileMapping[$fName];
                            } else {
                                foreach ($fileMapping as $key => $url) {
                                    if (basename($key) === $fName) { $fUrl = $url; break; }
                                }
                            }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                        }
                    }
                    // Fallback : main_file seul
                    if (empty($assignFiles)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fn = $mainFile['filename'];
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? $fileMapping[$fn] ?? null;
                            if (!$fUrl) {
                                foreach ($fileMapping as $key => $url) {
                                    if (basename($key) === $fn) { $fUrl = $url; break; }
                                }
                            }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fn];
                        }
                    }

                    // Traiter l'intro (description avec images)
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) {
                        $intro = resolvePluginfileUrls($intro, $fileMapping);
                    }

                    $editorSection['activities'][] = [
                        'id'    => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type'  => 'assign',
                        'name'  => $activity['name'] ?? 'Travail à déposer',
                        'files' => $assignFiles,
                        'intro' => $intro,
                    ];
                    continue;
                }

                // Ressource (fichiers à distribuer)
                if ($actType === 'resource') {
                    $contentFiles = $activity['content_files'] ?? [];
                    $files = [];
                    
                    foreach ($contentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null;
                            $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) {
                                $fUrl = $hashMapping[$cf['hash']]['url'];
                            } elseif (isset($fileMapping[$fName])) {
                                $fUrl = $fileMapping[$fName];
                            }
                            if ($fUrl) {
                                $files[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                            }
                        }
                    }
                    
                    // Fallback: main_file seul
                    if (empty($files)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? ($fileMapping[$mainFile['filename']] ?? null);
                            if ($fUrl) {
                                $files[] = ['fileUrl' => $fUrl, 'fileName' => $mainFile['filename']];
                            }
                        }
                    }
                    
                    // Traiter l'intro
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) {
                        $intro = resolvePluginfileUrls($intro, $fileMapping);
                    }
                    
                    $editorSection['activities'][] = [
                        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type' => 'resource',
                        'name' => $activity['name'] ?? 'Fichiers à distribuer',
                        'files' => $files,
                        'intro' => $intro,
                    ];
                    continue;
                }
                
                // Quiz : ddimageortext standalone (1 seule question DDI) ou évaluation standard (multichoice, etc.)
                if ($actType === 'quiz') {
                    // Toute quiz importée devient une évaluation (QuestionSet) — standalone DDI
                    // n'est plus distinguable dans l'MBZ d'une évaluation à 1 question. Unifier simplifie
                    // l'aller-retour et le rendu.
                    $quizEditorAct = convertStandardQuizForEditor($activity, $fileMapping, $mbzFiles, $hashMapping, $extractDir);
                    if ($quizEditorAct) {
                        $editorSection['activities'][] = $quizEditorAct;
                    }
                    continue;
                }

                // Étiquette / Page : modules de texte, jamais du H5P
                if ($actType === 'label' || $actType === 'page') {
                    $editorSection['activities'][] = buildTextModuleActivity(
                        $activity, $fileMapping,
                        'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()));
                    continue;
                }

                // Détecter le type H5P et récupérer le contenu
                $h5pType = detectH5pType($activity);
                $h5pContent = [];

                // Le contenu H5P est dans 'content' pour hvp
                if (isset($activity['content'])) {
                    $h5pContent = $activity['content'];
                } elseif (isset($activity['json_content'])) {
                    $h5pContent = json_decode($activity['json_content'], true) ?: [];
                }
                
                // Remplacer les chemins d'images dans le contenu H5P
                $h5pContent = replaceFilePathsInContent($h5pContent, $fileMapping);
                
                $editorActivity = [
                    'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                    'type' => $activity['type'] ?? 'hvp',
                    'name' => $activity['name'] ?? 'Activité',
                    'h5pType' => $h5pType,
                    // La consigne affichée AU-DESSUS de l'activité (champ `intro` de Moodle).
                    // Sans elle, le « Dans le schéma ci-dessus, localisez… » d'un
                    // Trouver-les-zones était perdu dès l'import, donc aussi à l'export.
                    'intro' => resolvePluginfileUrls($activity['intro'] ?? '', $fileMapping),
                    'content' => $h5pContent
                ];
                $editorSection['activities'][] = $editorActivity;
            }
            
            // Ajouter la visibilité des parcours
            $sectionVisible = ($section['visible'] ?? 1) ? true : false;
            foreach ($editorSection['activities'] as &$edAct) {
                // Trouver l'activité source pour la visibilité
                foreach ($section['activities'] ?? [] as $srcAct) {
                    $srcId = 'import_' . ($srcAct['module_id'] ?? $srcAct['id'] ?? '');
                    if ($srcId === ($edAct['id'] ?? '')) {
                        $actVisible = ($srcAct['visible'] ?? 1) ? true : false;
                        $actVisibleOld = ($srcAct['visibleold'] ?? 1) ? true : false;
                        // Si section cachée: visible=false est hérité, visibleold donne la vraie valeur
                        // Si section visible: visible donne directement la valeur
                        if (!$sectionVisible) {
                            $edAct['visible'] = $actVisibleOld;
                        } else {
                            $edAct['visible'] = $actVisible;
                        }
                        break;
                    }
                }
                if (!isset($edAct['visible'])) $edAct['visible'] = true;
            }
            unset($edAct);
            $editorSections[] = $editorSection;
        }
        
        // Nettoyer le dossier temporaire
        if ($progressId !== '') progressSet($progressId, 97, 'Finalisation…');
        deleteDirectory($extractDir);

        if ($progressId !== '') progressClear($progressId);
        echo json_encode([
            'success' => true,
            'course' => [
                'name' => $courseInfo['course_fullname'] ?? $courseInfo['fullname'] ?? $courseInfo['name'] ?? 'Cours importé',
                'shortname' => $courseInfo['shortname'] ?? 'import',
                'vignette' => findCourseVignette($mbzFiles, $hashMapping, $fileMapping),
                'sections' => $editorSections
            ],
        ]);

    } catch (Exception $e) {
        if (!empty($progressId)) progressClear($progressId);
        echo json_encode(['error' => 'Erreur de parsing: ' . $e->getMessage()]);
    }

    // Supprimer le dossier d'extraction temporaire (les fichiers sont copiés dans editor_uploads)
    if (!empty($extractDir) && is_dir($extractDir)) {
        deleteDirectory($extractDir);
    }
}

/**
 * Résout les URLs @@PLUGINFILE@@ dans du HTML (intro, description)
 * en les remplaçant par les URLs des fichiers uploadés dans l'éditeur
 */
function resolvePluginfileUrls($html, array $fileMapping) {
    if (empty($html)) return '';
    
    // Remplacer @@PLUGINFILE@@/filename par l'URL réelle
    return preg_replace_callback('/@@PLUGINFILE@@\/([^"\'<>\s]+)/', function($m) use ($fileMapping) {
        $filename = urldecode($m[1]);
        // Chercher dans le mapping par nom de fichier
        if (isset($fileMapping[$filename])) {
            return $fileMapping[$filename];
        }
        // Chercher en parcourant les clés
        foreach ($fileMapping as $key => $url) {
            if (basename($key) === $filename || basename($key) === basename($filename)) {
                return $url;
            }
        }
        return $m[0]; // Garder l'original si non trouvé
    }, $html);
}

/**
 * Remplace récursivement les chemins de fichiers dans le contenu H5P
 */
function replaceFilePathsInContent($content, array $fileMapping) {
    if (is_string($content)) {
        // Vérifier si c'est un chemin de fichier à remplacer
        foreach ($fileMapping as $oldPath => $newUrl) {
            if (strpos($content, $oldPath) !== false) {
                $content = str_replace($oldPath, $newUrl, $content);
            }
        }
        return $content;
    }
    
    if (is_array($content)) {
        foreach ($content as $key => $value) {
            // Cas spécial pour les chemins de fichiers H5P
            if ($key === 'path' && is_string($value)) {
                // Une URL ABSOLUE (podeduc, YouTube, Vimeo…) ne désigne pas un fichier de
                // l'archive : on n'y touche jamais. Sinon le repli « par nom de fichier »
                // plus bas l'écrasait dès que le .mbz contenait un fichier homonyme — et
                // comme cette fonction tourne à CHAQUE ouverture (parseLocalCourse), l'URL
                // était perdue à chaque réouverture du parcours.
                // Idem pour un fichier déjà servi par l'éditeur : rien à réécrire.
                if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $value)
                    || strpos($value, 'api/editor_api.php') === 0) {
                    continue;
                }

                // Enlever le suffixe #tmp si présent
                $cleanPath = preg_replace('/#tmp$/', '', $value);
                
                // Essayer de trouver dans le mapping directement
                if (isset($fileMapping[$value])) {
                    $content[$key] = $fileMapping[$value];
                } elseif (isset($fileMapping[$cleanPath])) {
                    $content[$key] = $fileMapping[$cleanPath];
                } else {
                    // Chercher par nom de fichier uniquement
                    $basename = basename($cleanPath);
                    if (isset($fileMapping[$basename])) {
                        $content[$key] = $fileMapping[$basename];
                    }
                }
            } else {
                $content[$key] = replaceFilePathsInContent($value, $fileMapping);
            }
        }
    }
    
    return $content;
}

/**
 * Convertit une activité quiz parsée (MbzParser) en format éditeur DDI.
 * Utilisé par parseMbz, parseDriveMbz, parseLocalCourse, loadTemplate.
 */
function convertQuizDdimageForEditor($activity, $mbzFiles, $hashMapping, $extractDir) {
    $questions = $activity['questions'] ?? [];
    $firstQ = $questions[0] ?? null;
    if (!$firstQ || ($firstQ['qtype'] ?? '') !== 'ddimageortext') return null;
    
    // Pré-indexer les fichiers DDI par itemid
    $ddiBgByQid = [];
    $ddiDragById = [];
    foreach ($mbzFiles as $f) {
        if (($f['component'] ?? '') !== 'qtype_ddimageortext') continue;
        if (($f['filename'] ?? '.') === '.') continue;
        $itemid = $f['itemid'] ?? 0;
        if (($f['filearea'] ?? '') === 'bgimage') {
            $ddiBgByQid[$itemid] = $f;
        } elseif (($f['filearea'] ?? '') === 'dragimage') {
            $ddiDragById[$itemid] = $f;
        }
    }

    // Image de fond : matcher par itemid = question id
    $questionId = $firstQ['id'] ?? 0;
    $bgFile = $ddiBgByQid[$questionId] ?? null;
    $bgUrl = null;
    $bgName = null;
    if ($bgFile && isset($hashMapping[$bgFile['hash']])) {
        $bgUrl = $hashMapping[$bgFile['hash']]['url'];
        $bgName = $bgFile['filename'];
    }

    // Drags avec images : matcher par itemid = drag_id (id XML du drag)
    $drags = $firstQ['drags'] ?? [];
    foreach ($drags as &$d) {
        $dragId = $d['drag_id'] ?? 0;
        $d['imageUrl'] = null;
        $d['imageName'] = null;
        if ($dragId && isset($ddiDragById[$dragId], $hashMapping[$ddiDragById[$dragId]['hash']])) {
            $d['imageUrl'] = $hashMapping[$ddiDragById[$dragId]['hash']]['url'];
            $d['imageName'] = $ddiDragById[$dragId]['filename'];
        }
    }
    unset($d);

    // Dimensions canvas depuis l'image de fond
    $canvasWidth = 800;
    $canvasHeight = 600;
    if ($bgFile && $extractDir) {
        $prefix = substr($bgFile['hash'], 0, 2);
        $srcPath = $extractDir . '/files/' . $prefix . '/' . $bgFile['hash'];
        if (file_exists($srcPath)) {
            $imgSize = @getimagesize($srcPath);
            if ($imgSize) {
                $canvasWidth = $imgSize[0];
                $canvasHeight = $imgSize[1];
            }
        }
    }
    
    // Drops (width/height non stockés dans MBZ, estimer)
    $drops = $firstQ['drops'] ?? [];
    foreach ($drops as &$dp) {
        if (!isset($dp['width'])) $dp['width'] = 120;
        if (!isset($dp['height'])) $dp['height'] = 35;
    }
    unset($dp);
    
    return [
        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
        'type' => 'quiz',
        'quizType' => 'ddimageortext',
        'name' => $activity['name'] ?? 'Glisser-Déposer',
        'content' => [
            'questiontext' => $firstQ['text'] ?? '<p>Compléter le schéma</p>',
            'shuffleanswers' => $firstQ['shuffleanswers'] ?? 1,
            'attempts_number' => $activity['attempts_number'] ?? 1,
            'defaultmark' => $firstQ['default_mark'] ?? 1,
            'backgroundUrl' => $bgUrl,
            'bgImageName' => $bgName,
            'canvasWidth' => $canvasWidth,
            'canvasHeight' => $canvasHeight,
            'drags' => $drags,
            'drops' => $drops,
        ],
    ];
}

/**
 * Convertit un quiz Moodle standard (multichoice, truefalse, ddimageortext, etc.) en
 * activité h5pactivity / QuestionSet (nouveau format) pour l'éditeur.
 */
function convertStandardQuizForEditor($activity, $fileMapping, $mbzFiles = [], $hashMapping = [], $extractDir = null) {
    $questions = $activity['questions'] ?? [];
    if (empty($questions)) return null;

    // Pré-indexer les fichiers DDI par filearea pour éviter les recherches répétées
    $ddiBgFiles = [];   // itemid (= question id) => file entry
    $ddiDragFiles = []; // itemid (= drag xml id) => file entry
    foreach ($mbzFiles as $f) {
        if (($f['component'] ?? '') !== 'qtype_ddimageortext') continue;
        if (($f['filename'] ?? '.') === '.') continue;
        $filearea = $f['filearea'] ?? '';
        $itemid = $f['itemid'] ?? 0;
        if ($filearea === 'bgimage') {
            $ddiBgFiles[$itemid] = $f;
        } elseif ($filearea === 'dragimage') {
            $ddiDragFiles[$itemid] = $f;
        }
    }

    $editorQuestions = [];

    foreach ($questions as $q) {
        $qtype = $q['qtype'] ?? 'multichoice';

        $eq = [
            'qtype'        => $qtype,
            'name'         => $q['name'] ?? 'Question',
            'questiontext' => resolvePluginfileUrls($q['text'] ?? '', $fileMapping),
            'defaultmark'  => $q['default_mark'] ?? 1,
        ];

        if ($qtype === 'multichoice') {
            $answers = [];
            foreach ($q['answers'] ?? [] as $a) {
                // Le HTML est CONSERVÉ : une réponse de QCM peut contenir une image
                // (Éléa l'écrit en <img src="@@PLUGINFILE@@/…"> dans answertext).
                // strip_tags() vidait complètement ces réponses-là.
                $answers[] = [
                    'text'    => trim(resolvePluginfileUrls($a['text'] ?? '', $fileMapping)),
                    'correct' => ($a['fraction'] ?? 0) > 0,
                ];
            }
            $eq['single']         = $q['single'] ?? true;
            $eq['shuffleanswers'] = $q['shuffle_answers'] ?? true;
            $eq['answers']        = $answers;

        } elseif ($qtype === 'truefalse') {
            $answers = [];
            foreach ($q['answers'] ?? [] as $a) {
                $answers[] = [
                    'text'    => trim(strip_tags($a['text'] ?? '')),
                    'correct' => ($a['fraction'] ?? 0) > 0,
                ];
            }
            $eq['answers'] = $answers;

        } elseif ($qtype === 'shortanswer') {
            $answers = [];
            foreach ($q['answers'] ?? [] as $a) {
                $answers[] = [
                    'text'    => trim(strip_tags($a['text'] ?? '')),
                    'correct' => ($a['fraction'] ?? 0) > 0,
                ];
            }
            $eq['answers']  = $answers;
            $eq['use_case'] = $q['use_case'] ?? false;

        } elseif ($qtype === 'ddimageortext') {
            // Image de fond : matcher par itemid = question id
            $questionId = $q['id'] ?? 0;
            $bgFile = $ddiBgFiles[$questionId] ?? null;
            $bgUrl = null;
            $bgName = null;
            if ($bgFile && isset($hashMapping[$bgFile['hash']])) {
                $bgUrl = $hashMapping[$bgFile['hash']]['url'];
                $bgName = $bgFile['filename'];
            }
            // Dimensions canvas
            $canvasWidth = 800;
            $canvasHeight = 600;
            if ($bgFile && $extractDir) {
                $prefix = substr($bgFile['hash'], 0, 2);
                $srcPath = $extractDir . '/files/' . $prefix . '/' . $bgFile['hash'];
                if (file_exists($srcPath)) {
                    $imgSize = @getimagesize($srcPath);
                    if ($imgSize) {
                        $canvasWidth = $imgSize[0];
                        $canvasHeight = $imgSize[1];
                    }
                }
            }
            // Drags avec images : matcher par itemid = drag_id (id XML du drag)
            $drags = $q['drags'] ?? [];
            foreach ($drags as &$d) {
                $dragId = $d['drag_id'] ?? 0;
                $d['imageUrl'] = null;
                $d['imageName'] = null;
                if ($dragId && isset($ddiDragFiles[$dragId], $hashMapping[$ddiDragFiles[$dragId]['hash']])) {
                    $d['imageUrl'] = $hashMapping[$ddiDragFiles[$dragId]['hash']]['url'];
                    $d['imageName'] = $ddiDragFiles[$dragId]['filename'];
                }
            }
            unset($d);
            // Drops avec dimensions estimées
            $drops = $q['drops'] ?? [];
            foreach ($drops as &$dp) {
                if (!isset($dp['width']))  $dp['width']  = 120;
                if (!isset($dp['height'])) $dp['height'] = 35;
            }
            unset($dp);

            $eq['backgroundUrl']  = $bgUrl;
            $eq['bgImageName']    = $bgName;
            $eq['canvasWidth']    = $canvasWidth;
            $eq['canvasHeight']   = $canvasHeight;
            $eq['shuffleanswers'] = $q['shuffleanswers'] ?? 1;
            $eq['drags']          = $drags;
            $eq['drops']          = $drops;

        } else {
            foreach ($q as $k => $v) {
                if (!array_key_exists($k, $eq) && !in_array($k, ['id', 'text', 'default_mark', 'penalty', 'general_feedback'])) {
                    $eq[$k] = $v;
                }
            }
        }

        $editorQuestions[] = $eq;
    }

    return [
        'id'      => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
        'type'    => 'h5pactivity',
        'h5pType' => 'QuestionSet',
        'name'    => $activity['name'] ?? 'Évaluation',
        'content' => [
            'settings' => [
                'attempts_number'   => $activity['attempts_number'] ?? 1,
                'preferredbehaviour' => $activity['preferred_behaviour'] ?? 'deferredfeedback',
                'questionsperpage'  => $activity['questions_per_page'] ?? 1,
                'shuffleanswers'    => $activity['shuffle_answers'] ?? 1,
                'grade'             => $activity['grade'] ?? 10,
            ],
            'questions' => $editorQuestions,
        ],
    ];
}

/**
 * Upload un fichier (image, vidéo) pour l'éditeur
 */
/**
 * Copie une image (template locale ou URL externe) vers cache/editor_uploads/
 * pour qu'elle soit traitée comme une image uploadée par l'export MBZ.
 * 
 * Paramètres POST:
 *   source_type: 'template' | 'url'
 *   source: nom du fichier template OU URL complète
 */
function copyImageToUploads() {
    $sourceType = $_POST['source_type'] ?? '';
    $source = $_POST['source'] ?? '';
    
    if (!$sourceType || !$source) {
        echo json_encode(['error' => 'Paramètres manquants (source_type, source)']);
        return;
    }
    
    // Préparer le dossier d'upload (namespacé par session)
    $sessionId = $_POST['session_id'] ?? '';
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    
    if ($safeSessionId) {
        $uploadDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
    } else {
        $uploadDir = CACHE_DIR . '/editor_uploads';
    }
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    
    $tempFile = null;
    $ext = 'png';
    
    if ($sourceType === 'template') {
        // Image template locale
        $safeFilename = basename($source); // Sécurité: pas de traversée de répertoire
        $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        
        $possiblePaths = [
            $appRoot . '/assets/templatesImages/' . $safeFilename,
            __DIR__ . '/../assets/templatesImages/' . $safeFilename,
        ];
        
        $localPath = null;
        foreach ($possiblePaths as $p) {
            if (file_exists($p)) {
                $localPath = $p;
                break;
            }
        }
        
        if (!$localPath) {
            echo json_encode(['error' => 'Image template introuvable: ' . $safeFilename]);
            return;
        }
        
        $tempFile = $localPath;
        $ext = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION)) ?: 'png';
        
    } elseif ($sourceType === 'emoji') {
        // Image emoji locale
        $safeFilename = basename($source);
        $appRoot = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        
        $possiblePaths = [
            $appRoot . '/assets/emojis_png/' . $safeFilename,
            __DIR__ . '/../assets/emojis_png/' . $safeFilename,
        ];
        
        $localPath = null;
        foreach ($possiblePaths as $p) {
            if (file_exists($p)) {
                $localPath = $p;
                break;
            }
        }
        
        if (!$localPath) {
            echo json_encode(['error' => 'Image emoji introuvable: ' . $safeFilename]);
            return;
        }
        
        $tempFile = $localPath;
        $ext = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION)) ?: 'png';
        
    } elseif ($sourceType === 'url') {
        // Image depuis URL
        if (!preg_match('#^https?://#i', $source)) {
            echo json_encode(['error' => 'URL invalide']);
            return;
        }
        
        // Déterminer l'extension
        $urlPath = parse_url($source, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (!in_array($ext, $allowedExts)) {
            $ext = 'png'; // Fallback
        }
        
        // Télécharger - essayer curl d'abord (meilleur support headers)
        $data = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($source);
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
            if ($httpCode < 200 || $httpCode >= 400) $data = null;
        }
        
        if (!$data || strlen($data) < 100) {
            // Fallback file_get_contents
            try {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 15,
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'header' => "Accept: image/webp,image/apng,image/*,*/*;q=0.8\r\n"
                    ],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
                ]);
                $data = @file_get_contents($source, false, $ctx);
            } catch (\Exception $e) {
                $data = null;
            }
        }
        
        if (!$data || strlen($data) < 100) {
            echo json_encode(['error' => 'Impossible de télécharger l\'image']);
            return;
        }
        
        // Sauvegarder dans un fichier temporaire
        $tempFile = tempnam(sys_get_temp_dir(), 'img_');
        file_put_contents($tempFile, $data);
        
    } else {
        echo json_encode(['error' => 'source_type invalide']);
        return;
    }
    
    // Copier vers editor_uploads avec un nom unique
    $filename = 'upload_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;
    
    if ($sourceType === 'template' || $sourceType === 'emoji') {
        // Copier (pas déplacer)
        if (!copy($tempFile, $destPath)) {
            echo json_encode(['error' => 'Erreur de copie du fichier']);
            return;
        }
    } else {
        // Déplacer le fichier temporaire
        rename($tempFile, $destPath);
    }
    
    // Construire l'URL via le endpoint PHP
    $url = 'api/editor_api.php?action=serve_upload&file=' . urlencode($filename);
    if ($safeSessionId) {
        $url .= '&session=' . urlencode($safeSessionId);
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
        $mime = mime_content_type($destPath) ?: 'image/png';
        EditorDriveSync::addPendingFile($safeSessionId, $filename, $mime);
    }
    
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'url' => $url,
        'path' => $url
    ]);
}

/**
 * Retourne la taille totale des fichiers de la session éditeur
 * Beaucoup plus fiable que le scan JS côté client
 */
function getSessionFilesTotal($input) {
    global $editorSessionId;
    $sessionId = $input['sessionId'] ?? $editorSessionId ?? '';

    $totalBytes = 0;
    $fileCount = 0;

    if (!$sessionId) {
        echo json_encode(['success' => true, 'total_bytes' => 0, 'file_count' => 0]);
        return;
    }

    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

    // 1) Fichiers encore présents localement dans le dossier session
    $localFilenames = [];
    $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
    if (is_dir($sessionDir)) {
        foreach (glob($sessionDir . '/*') as $fp) {
            if (is_file($fp)) {
                $fn = basename($fp);
                $localFilenames[$fn] = true;
                $totalBytes += filesize($fp);
                $fileCount++;
            }
        }
    }

    // 2) Fallback ancien chemin plat (rétrocompat sessions pré-refactor)
    if ($fileCount === 0) {
        $uploadDir = CACHE_DIR . '/editor_uploads';
        $autoPath  = CACHE_DIR . '/drafts/auto/' . $safeSessionId . '.json';
        foreach (extractDraftFilenames($autoPath) as $fn) {
            $fp = $uploadDir . '/' . $fn;
            if (file_exists($fp)) {
                $localFilenames[$fn] = true;
                $totalBytes += filesize($fp);
                $fileCount++;
            }
        }
    }

    // 3) Fichiers déjà uploadés sur Drive (supprimés du serveur) — tailles dans metadata
    $driveCount = 0;
    require_once __DIR__ . '/../includes/EditorDriveSync.php';
    $meta = EditorDriveSync::getMeta($safeSessionId);
    if ($meta) {
        $fileMapping = $meta['file_mapping'] ?? [];
        $fileSizes   = $meta['file_sizes']   ?? [];
        $driveCount  = count($fileMapping);

        $knownDriveBytes    = 0;
        $knownDriveFiles    = 0;
        $unknownDriveFiles  = 0;

        foreach ($fileMapping as $fn => $driveId) {
            if (isset($localFilenames[$fn])) continue; // déjà compté localement
            if (isset($fileSizes[$fn])) {
                $knownDriveBytes += $fileSizes[$fn];
                $knownDriveFiles++;
            } else {
                $unknownDriveFiles++;
            }
        }

        $totalBytes += $knownDriveBytes;

        // Estimation pour les fichiers Drive sans taille connue (sessions antérieures)
        if ($unknownDriveFiles > 0) {
            if ($knownDriveFiles > 0) {
                $avgSize = $knownDriveBytes / $knownDriveFiles;
            } elseif ($fileCount > 0 && $totalBytes > 0) {
                $avgSize = $totalBytes / $fileCount;
            } else {
                $avgSize = 200 * 1024; // 200 Ko par défaut
            }
            $totalBytes += (int)($unknownDriveFiles * $avgSize);
        }
    }

    echo json_encode([
        'success'     => true,
        'total_bytes' => $totalBytes,
        'file_count'  => $fileCount,
        'drive_count' => $driveCount,
    ]);
}

// ==================== PROGRESSION DES TÂCHES LONGUES ====================
// L'import et l'export durent parfois plusieurs dizaines de secondes. La tâche publie son
// avancement dans un petit fichier ; le navigateur l'interroge en parallèle via get_progress.
// Le verrou de session PHP est relâché plus haut pour toutes les actions sauf deux, donc ces
// requêtes de suivi ne sont pas mises en attente derrière la tâche en cours.

function progressPath($id): ?string {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
    if ($id === '') return null;
    $dir = CACHE_DIR . '/progress';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/' . $id . '.json';
}

/**
 * Publie l'avancement d'une tâche. $percent est borné à 0-100 et ne recule jamais :
 * une barre qui repart en arrière est plus inquiétante qu'une barre lente.
 */
function progressSet($id, $percent, string $label): void {
    $path = progressPath($id);
    if (!$path) return;

    $percent = max(0, min(100, (int)round($percent)));
    if (file_exists($path)) {
        $prev = json_decode(@file_get_contents($path), true);
        if (isset($prev['percent']) && $prev['percent'] > $percent) $percent = (int)$prev['percent'];
    } else {
        progressSweep();   // premier appel de la tâche : purger les suivis abandonnés
    }
    @file_put_contents($path, json_encode([
        'percent' => $percent,
        'label'   => $label,
        'at'      => time(),
    ]), LOCK_EX);
}

function progressClear($id): void {
    $path = progressPath($id);
    if ($path) @unlink($path);
}

// Suivis laissés par un onglet fermé ou une tâche interrompue
function progressSweep(): void {
    $dir = CACHE_DIR . '/progress';
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (@filemtime($f) < time() - 3600) @unlink($f);
    }
}

function getProgress(): void {
    $path = progressPath($_GET['id'] ?? ($_POST['id'] ?? ''));
    $data = ($path && file_exists($path)) ? json_decode(@file_get_contents($path), true) : null;
    echo json_encode($data ?: ['percent' => null, 'label' => '']);
}

// Retourner les tailles de fichiers du dossier editor_uploads
function getFileSizes($input) {
    // Accepte 'filenames' (noms directs) ou 'files' (URLs legacy)
    $filenames = $input['filenames'] ?? [];
    
    // Rétrocompatibilité : extraire noms depuis URLs
    if (empty($filenames) && !empty($input['files'])) {
        foreach ($input['files'] as $url) {
            if (preg_match('/[?&]file=([^&]+)/', $url, $m)) {
                $filenames[] = urldecode($m[1]);
            }
        }
    }
    
    if (empty($filenames)) {
        echo json_encode(['success' => true, 'sizes' => []]);
        return;
    }
    
    $uploadDir = CACHE_DIR . '/editor_uploads';
    $sizes = [];
    $missingOnLocal = [];
    
    foreach ($filenames as $fn) {
        // Sécurité
        if (!preg_match('/^(?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w{2,5}$/i', $fn)) {
            continue;
        }
        $fp = $uploadDir . '/' . $fn;
        if (file_exists($fp)) {
            $sizes[$fn] = filesize($fp);
        } else {
            $missingOnLocal[] = $fn;
        }
    }
    
    // Fichiers non trouvés en local → 0
    foreach ($missingOnLocal as $fn) {
        if (!isset($sizes[$fn])) $sizes[$fn] = 0;
    }
    
    echo json_encode(['success' => true, 'sizes' => $sizes]);
}

/**
 * Télécharge EN MÉMOIRE le contenu d'un fichier d'éditeur flushé sur Drive (supprimé du
 * serveur après upload). Aucune écriture disque : le contenu est streamé directement au
 * navigateur par l'appelant — même modèle que file_drive.php (viewer). L'objectif « le
 * serveur reste vide, tout vit sur Drive » est donc respecté à l'octet près.
 * Renvoie null en cas d'échec (l'appelant retombe sur la redirection publique).
 * $dm injectable pour les tests.
 */
function editorFetchDriveFileContent(string $driveId, $dm = null): ?string {
    try {
        if ($dm === null) {
            require_once ROOT_PATH . '/DriveManager.php';
            $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
        }
        $content = $dm->getFileContentById($driveId);
        return ($content === null || $content === '') ? null : $content;
    } catch (\Throwable $e) {
        error_log("serveUpload: telechargement Drive echoue ($driveId): " . $e->getMessage());
        return null;
    }
}

/** MIME d'un fichier d'éditeur d'après son extension (partagé local / streaming Drive). */
function editorUploadMime(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
        'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
    ];
    return $mimeTypes[$ext] ?? 'application/octet-stream';
}

function serveUpload() {
    $filename = $_GET['file'] ?? '';
    if (!$filename || !preg_match('/^(?:upload|import|tpl)_[a-zA-Z0-9_]+\.\w{2,5}$/', $filename)) {
        http_response_code(400);
        echo 'Invalid filename';
        return;
    }
    
    $sessionId = $_GET['session'] ?? '';
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    
    // Fallback : utiliser le session_id de la session PHP (si pas de param GET)
    global $editorSessionId;
    if (!$safeSessionId && !empty($editorSessionId)) {
        $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $editorSessionId);
    }
    
    // Chercher le fichier : dossier session → dossier plat (rétrocompat) → Drive
    $filepath = null;
    if ($safeSessionId) {
        $sessionPath = CACHE_DIR . '/editor_uploads/' . $safeSessionId . '/' . $filename;
        if (file_exists($sessionPath)) {
            $filepath = $sessionPath;
        }
    }
    if (!$filepath) {
        $flatPath = CACHE_DIR . '/editor_uploads/' . $filename;
        if (file_exists($flatPath)) {
            $filepath = $flatPath;
        }
    }

    // Le fichier peut vivre dans le dossier d'une AUTRE session : session PHP expirée
    // (plus de fallback $editorSessionId), brouillon repris dans une nouvelle session,
    // URL sans paramètre session… Les noms (upload|import|tpl)_<ts>_<aléa>.<ext> sont
    // uniques : une recherche par nom dans tous les dossiers de session est sûre.
    // C'est ce qui rend les médias insensibles à la perte de session PHP (gc OVH).
    if (!$filepath) {
        foreach (glob(CACHE_DIR . '/editor_uploads/*/' . $filename) ?: [] as $candidate) {
            if (is_file($candidate)) {
                $filepath = $candidate;
                break;
            }
        }
    }

    // Pas trouvé localement → le fichier a été flushé sur Drive (EditorDriveSync supprime le
    // local après upload : le serveur doit rester vide, c'est voulu). Les fichiers Drive sont
    // PRIVÉS (aucune permission publique posée) : rediriger vers drive.google.com/uc ou lh3
    // renvoie une page HTML Google, pas le média — c'est ce qui cassait la lecture (audio en
    // tête) dans l'éditeur au fil du flush, alors que le viewer marche (file_drive.php streame
    // côté serveur avec le token OAuth).
    // → Même modèle ici : téléchargement OAuth EN MÉMOIRE puis streaming direct au navigateur.
    //   RIEN n'est écrit sur le serveur. Le cache navigateur (ETag + 24h) évite les
    //   re-téléchargements Drive répétés.
    if (!$filepath) {
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
        $driveId = null;
        if ($safeSessionId) {
            $meta = EditorDriveSync::getMeta($safeSessionId);
            $driveId = $meta['file_mapping'][$filename] ?? null;
        }
        // Mapping introuvable dans la session indiquée (ou pas de session du tout) :
        // chercher le nom dans les mappings de TOUTES les sessions actives — même
        // logique inter-sessions que pour les fichiers locaux ci-dessus.
        if (!$driveId) {
            $driveId = EditorDriveSync::findDriveIdAnySession($filename);
        }
        if ($driveId) {
            // ETag stable par fichier Drive : si le navigateur l'a déjà, 304 sans appel Drive
            $etag = '"' . md5($driveId) . '"';
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=86400');
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                http_response_code(304);
                exit;
            }

            $content = editorFetchDriveFileContent($driveId);
            if ($content !== null) {
                header('Content-Type: ' . editorUploadMime($filename));
                header('Content-Length: ' . strlen($content));
                if (!empty($_GET['download'])) {
                    $downloadName = $_GET['download_name'] ?? $filename;
                    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
                }
                echo $content;
                exit;
            }

            // Secours si le téléchargement OAuth a échoué : ancienne redirection (ne fonctionne
            // que si le fichier est public, mais mieux qu'un 404 sec). Journalisé : si ce cas
            // se produit en masse, c'est le téléchargement OAuth qu'il faut diagnostiquer.
            error_log("serveUpload: OAuth KO pour $filename ($driveId) — redirection publique de secours (fichier privé : échouera probablement)");
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (in_array($ext, $imageExts)) {
                header('Location: https://lh3.googleusercontent.com/d/' . $driveId);
            } else {
                $exportMode = !empty($_GET['download']) ? 'download' : 'view';
                header('Location: https://drive.google.com/uc?id=' . $driveId . '&export=' . $exportMode);
            }
            exit;
        }
    }

    if (!$filepath) {
        http_response_code(404);
        // Trace de diagnostic : un 404 ici = fichier ni local (toutes sessions), ni dans
        // aucun mapping Drive → contenu réellement perdu ou nom erroné dans courseData.
        error_log("serveUpload 404: $filename (session='" . ($safeSessionId ?: 'aucune') . "') — introuvable partout");
        echo 'File not found';
        return;
    }

    // Envoyer le fichier
    $taille = filesize($filepath);
    header('Content-Type: ' . editorUploadMime($filename));
    header('Cache-Control: public, max-age=86400');

    if (!empty($_GET['download'])) {
        $downloadName = $_GET['download_name'] ?? $filename;
        header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    }

    // Requêtes Range : indispensables pour SE DÉPLACER dans une vidéo ou un audio.
    // Sans elles le navigateur lit bien le média mais ne peut pas y sauter : le curseur
    // repart à 0 (vérifié en Chromium — lecture OK, seek à 3s → retour à 0).
    header('Accept-Ranges: bytes');
    $range = trim($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && $taille > 0) {
        if ($m[1] === '') {
            // « bytes=-N » : les N derniers octets
            $longueur = min((int)$m[2], $taille);
            $debut = $taille - $longueur;
            $fin = $taille - 1;
        } else {
            $debut = (int)$m[1];
            $fin = ($m[2] === '') ? $taille - 1 : min((int)$m[2], $taille - 1);
        }

        if ($debut > $fin || $debut >= $taille) {
            http_response_code(416);
            header('Content-Range: bytes */' . $taille);
            exit;
        }

        $longueur = $fin - $debut + 1;
        http_response_code(206);
        header("Content-Range: bytes {$debut}-{$fin}/{$taille}");
        header('Content-Length: ' . $longueur);

        $fp = fopen($filepath, 'rb');
        if ($fp) {
            fseek($fp, $debut);
            $reste = $longueur;
            while ($reste > 0 && !feof($fp)) {
                $bloc = fread($fp, (int)min(65536, $reste));
                if ($bloc === false || $bloc === '') break;
                echo $bloc;
                $reste -= strlen($bloc);
            }
            fclose($fp);
        }
        exit;
    }

    header('Content-Length: ' . $taille);
    readfile($filepath);
    exit;
}

function uploadFile() {
    try {
        // Debug: vérifier ce qu'on reçoit
        if (empty($_FILES)) {
            echo json_encode([
                'error' => 'Aucun fichier reçu',
                'debug' => [
                    'POST' => $_POST,
                    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'non défini',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'non défini'
                ]
            ]);
            return;
        }
        
        if (!isset($_FILES['file'])) {
            echo json_encode(['error' => 'Clé "file" manquante', 'files_keys' => array_keys($_FILES)]);
            return;
        }
        
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite: ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux (formulaire)',
                UPLOAD_ERR_PARTIAL => 'Fichier partiellement uploadé',
                UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
                UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier',
                UPLOAD_ERR_EXTENSION => 'Upload bloqué par une extension'
            ];
            $errorMsg = $errors[$_FILES['file']['error']] ?? 'Erreur inconnue (code: ' . $_FILES['file']['error'] . ')';
            echo json_encode(['error' => $errorMsg]);
            return;
        }
        
        $file = $_FILES['file'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm', 'image/svg+xml',
                         'audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a', 'audio/aac'];
        
        // Vérifier le type MIME
        $mimeType = $file['type'] ?: 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detectedMime = @finfo_file($finfo, $file['tmp_name']);
                // Note: finfo_close() supprimé car déprécié en PHP 8.5+
                // Les objets finfo sont libérés automatiquement
                if ($detectedMime) {
                    $mimeType = $detectedMime;
                }
            }
        }
        
        if (!in_array($mimeType, $allowedTypes) && !in_array($file['type'], $allowedTypes)) {
            echo json_encode(['error' => 'Type de fichier non autorisé: ' . $mimeType]);
            return;
        }
        
        // Dossier d'upload namespacé par session (obligatoire pour éviter les orphelins)
        $sessionId = $_POST['session_id'] ?? '';
        $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if (!$safeSessionId) {
            echo json_encode(['error' => 'Session éditeur manquante. Rechargez la page.']);
            return;
        }
        $uploadDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                echo json_encode(['error' => 'Impossible de créer le dossier: ' . $uploadDir]);
                return;
            }
        }

        if (!is_writable($uploadDir)) {
            echo json_encode(['error' => 'Dossier non accessible en écriture: ' . $uploadDir]);
            return;
        }
        
        // Générer un nom unique
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'svg', 'mp3', 'ogg', 'wav', 'm4a', 'aac'];
        if (!in_array($ext, $allowedExt)) {
            $extMap = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                'image/webp' => 'webp', 'image/svg+xml' => 'svg',
                'video/mp4' => 'mp4', 'video/webm' => 'webm',
                'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/ogg' => 'ogg',
                'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a',
                'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac'
            ];
            $ext = $extMap[$mimeType] ?? 'bin';
        }
        
        $filename = 'upload_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $url = 'api/editor_api.php?action=serve_upload&file=' . urlencode($filename)
                 . '&session=' . urlencode($safeSessionId);

            require_once __DIR__ . '/../includes/EditorDriveSync.php';
            EditorDriveSync::addPendingFile($safeSessionId, $filename, $mimeType);

            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'url' => $url,
                'type' => $mimeType
            ]);
        } else {
            echo json_encode(['error' => 'Erreur de déplacement du fichier']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Exception upload: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['error' => 'Error upload: ' . $e->getMessage()]);
    }
}

/**
 * Upload un fichier pour une activité assign (tout type accepté)
 */
function uploadAssignFile() {
    try {
        if (empty($_FILES) || !isset($_FILES['file'])) {
            echo json_encode(['error' => 'Aucun fichier reçu']);
            return;
        }
        
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite: ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux',
                UPLOAD_ERR_PARTIAL => 'Fichier partiellement uploadé',
                UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé',
            ];
            echo json_encode(['error' => $errors[$file['error']] ?? 'Erreur upload (code: ' . $file['error'] . ')']);
            return;
        }
        
        // Limite de taille : 50 Mo
        if ($file['size'] > 50 * 1024 * 1024) {
            echo json_encode(['error' => 'Fichier trop volumineux (max 50 Mo)']);
            return;
        }
        
        $sessionId = $_POST['session_id'] ?? '';
        $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if (!$safeSessionId) {
            echo json_encode(['error' => 'Session éditeur manquante. Rechargez la page.']);
            return;
        }
        $uploadDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Garder l'extension originale
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (empty($ext)) $ext = 'bin';

        // Nettoyer le nom original pour stockage
        $originalName = basename($file['name']);

        $filename = 'upload_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $url = 'api/editor_api.php?action=serve_upload&file=' . urlencode($filename)
                 . '&session=' . urlencode($safeSessionId);

            require_once __DIR__ . '/../includes/EditorDriveSync.php';
            EditorDriveSync::addPendingFile($safeSessionId, $filename, $file['type'] ?? 'application/octet-stream');

            echo json_encode([
                'success' => true,
                'url' => $url,
                'originalName' => $originalName,
                'filename' => $filename,
                'size' => $file['size']
            ]);
        } else {
            echo json_encode(['error' => 'Erreur de déplacement du fichier']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Exception: ' . $e->getMessage()]);
    }
}


/**
 * Détecte le type H5P d'une activité
 */
function detectH5pType($activity) {
    // Le contenu peut être dans 'content' ou 'h5p_content'
    $content = $activity['content'] ?? $activity['h5p_content'] ?? [];
    
    // Si le contenu est une chaîne JSON, la décoder
    if (is_string($content)) {
        $content = json_decode($content, true) ?: [];
    }
    
    if (isset($content['presentation']['slides'])) {
        return 'CoursePresentation';
    }
    if (isset($content['gamemapSteps'])) {
        return 'GameMap';
    }
    if (isset($content['sequenceImages'])) {
        return 'ImageSequencing';
    }
    if (isset($content['imageMultipleHotspotQuestion'])) {
        return 'ImageMultipleHotspotQuestion';
    }
    // H5P.MemoryGame : liste `cards` d'images. `cards` existe aussi dans Flashcards,
    // mais avec question/answer/text sur chaque carte — d'où le garde-fou.
    if (isset($content['cards']) && is_array($content['cards'])) {
        $firstCard = $content['cards'][0] ?? null;
        $looksMemory = isset($content['lookNFeel'])
            || isset($content['behaviour']['useGrid'])
            || (is_array($firstCard) && isset($firstCard['image'])
                && !isset($firstCard['question'])
                && !isset($firstCard['answer'])
                && !isset($firstCard['text']));
        if ($looksMemory) {
            return 'MemoryGame';
        }
    }
    if (isset($content['threeImage']['scenes']) || isset($content['threeImage'])) {
        return 'ThreeImage';
    }
    if (isset($content['interactiveVideo'])) {
        return 'InteractiveVideo';
    }
    if (isset($content['textField'])) {
        return 'DragText';
    }
    if (isset($content['wordList'])) {
        return 'FindTheWords';
    }
    if (isset($content['dialogs'])) {
        return 'DialogCards';
    }
    if (isset($content['answers']) && isset($content['question'])) {
        return 'MultiChoice';
    }
    if (isset($content['correct']) && isset($content['question'])) {
        return 'TrueFalse';
    }
    // Blanks vs QuestionSet : les deux ont 'questions', mais QuestionSet a des objets avec 'library'
    if (isset($content['questions']) && !empty($content['questions'])) {
        $first = $content['questions'][0] ?? null;
        if (is_array($first) && isset($first['library'])) {
            return 'QuestionSet';
        }
        // Blanks a des strings dans questions
        if (is_string($first)) {
            return 'Blanks';
        }
    }
    if (isset($content['questions'])) {
        return 'QuestionSet';
    }
    
    // Fallback : détecter depuis le machine_name
    $machineName = $activity['machine_name'] ?? '';
    if (strpos($machineName, 'CoursePresentation') !== false) return 'CoursePresentation';
    if (strpos($machineName, 'InteractiveVideo') !== false) return 'InteractiveVideo';
    if (strpos($machineName, 'QuestionSet') !== false) return 'QuestionSet';
    if (strpos($machineName, 'Dialogcards') !== false || strpos($machineName, 'DialogCards') !== false) return 'DialogCards';
    if (strpos($machineName, 'DragText') !== false) return 'DragText';
    if (strpos($machineName, 'FindTheWords') !== false) return 'FindTheWords';
    if (strpos($machineName, 'MultiMediaChoice') !== false) return 'MultiMediaChoice';
    if (strpos($machineName, 'MultiChoice') !== false) return 'MultiChoice';
    if (strpos($machineName, 'TrueFalse') !== false) return 'TrueFalse';
    if (strpos($machineName, 'Blanks') !== false) return 'Blanks';
    if (strpos($machineName, 'ThreeImage') !== false) return 'ThreeImage';
    if (strpos($machineName, 'GameMap') !== false) return 'GameMap';
    if (strpos($machineName, 'ImageSequencing') !== false) return 'ImageSequencing';
    if (strpos($machineName, 'MemoryGame') !== false) return 'MemoryGame';
    if (strpos($machineName, 'ImageMultipleHotspotQuestion') !== false) return 'ImageMultipleHotspotQuestion';

    return $activity['type'] ?? 'unknown';
}

/**
 * Classe d'export MBZ
 */
class MbzExporter {
    private $data;
    private $exportDir;
    private $filesDir;
    private $exportFiles = [];
    private $quizQuestions = []; // Données des questions pour questions.xml
    private $sectionActivityIds = []; // Activity IDs par section (indexé par sIdx)
    private $sessionId;
    private $sessionDir;    // cache/editor_uploads/{sessionId}/
    private $driveMapping;  // filename => driveFileId
    private $driveTempDir;  // dossier temp pour les fichiers téléchargés depuis Drive
    
    public function __construct($data, $sessionId = '') {
        $this->data = $data;
        $this->sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        $this->sessionDir = $this->sessionId ? (CACHE_DIR . '/editor_uploads/' . $this->sessionId) : '';
        $this->driveMapping = [];
        $this->driveTempDir = null;
        
        // Charger le mapping Drive depuis la metadata de session
        if ($this->sessionId) {
            $metaFile = CACHE_DIR . '/editor_sessions/' . $this->sessionId . '.json';
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                $this->driveMapping = $meta['file_mapping'] ?? [];
            }
        }
    }
    
    /**
     * Résout un fichier (URL ou nom) en chemin local.
     * Cherche dans : session dir → flat dir → Drive (téléchargement si nécessaire)
     * Gère les URLs : serve_upload, lh3.googleusercontent.com
     */
    private function resolveFileToLocal($urlOrFilename) {
        $filename = null;
        $driveId = null;
        
        // Normaliser : décoder les entités HTML (&amp; → &)
        $url = html_entity_decode($urlOrFilename);
        
        // Extraire le nom de fichier depuis une URL serve_upload
        if (preg_match('/[?&]file=([^&\s"\'<>]+)/', $url, $m)) {
            $filename = urldecode($m[1]);
        }
        // URL Drive directe (lh3.googleusercontent.com/d/{driveId})
        elseif (preg_match('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            $driveId = $m[1];
            // Reverse lookup: trouver le nom de fichier original depuis le mapping
            foreach ($this->driveMapping as $fname => $did) {
                if ($did === $driveId) { $filename = $fname; break; }
            }
        }
        // Nom de fichier brut
        elseif (!preg_match('#^https?://#', $url)) {
            $filename = basename($url);
        }
        
        // 1. Chercher en local (session dir puis flat dir)
        if ($filename) {
            if ($this->sessionDir && file_exists($this->sessionDir . '/' . $filename)) {
                return $this->sessionDir . '/' . $filename;
            }
            if (file_exists(CACHE_DIR . '/editor_uploads/' . $filename)) {
                return CACHE_DIR . '/editor_uploads/' . $filename;
            }
            // 2. Chercher dans le mapping Drive par nom de fichier
            if (isset($this->driveMapping[$filename])) {
                $driveId = $this->driveMapping[$filename];
            }
        }
        
        // 3. Télécharger depuis Drive si on a un driveId
        if ($driveId) {
            $dl = $this->downloadDriveFile($driveId, $filename ?: ($driveId . '.bin'));
            if ($dl) return $dl;
        }

        // 4. Le fichier peut vivre dans le dossier local ou le mapping Drive d'une
        // AUTRE session (brouillon repris, cours ré-importé). Les noms étant uniques,
        // la recherche par nom est sûre — même logique que serve_upload et
        // EleaMbzExporter::findFileMultiPath.
        if ($filename) {
            foreach (glob(CACHE_DIR . '/editor_uploads/*/' . $filename) ?: [] as $candidate) {
                if (is_file($candidate)) return $candidate;
            }
            require_once __DIR__ . '/../includes/EditorDriveSync.php';
            $anyId = EditorDriveSync::findDriveIdAnySession($filename);
            if ($anyId && $anyId !== $driveId) {
                $dl = $this->downloadDriveFile($anyId, $filename);
                if ($dl) return $dl;
            }
            error_log("MbzExporter: fichier référencé INTROUVABLE partout : $filename — le .mbz sera incomplet");
        }

        return null;
    }
    
    /**
     * Télécharge un fichier depuis Google Drive vers un dossier temporaire
     */
    private function downloadDriveFile($driveId, $filename) {
        if (!$this->driveTempDir) {
            $this->driveTempDir = CACHE_DIR . '/exports/drive_tmp_' . time() . '_' . bin2hex(random_bytes(3));
            mkdir($this->driveTempDir, 0777, true);
        }
        
        $localPath = $this->driveTempDir . '/' . $filename;
        if (file_exists($localPath)) return $localPath; // déjà téléchargé
        
        try {
            require_once ROOT_PATH . '/DriveManager.php';
            $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
            $content = $dm->getFileContentById($driveId);
            if ($content) {
                file_put_contents($localPath, $content);
                return $localPath;
            }
        } catch (\Throwable $e) {
            error_log("[MbzExporter] Drive download error for $driveId: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Copie un fichier résolu dans le dossier d'export et retourne @@PLUGINFILE@@/nom
     */
    private function copyFileForExport($localFile, $origName, $contextId, $component, $filearea, $itemId, &$nextFileId) {
        $hash = sha1_file($localFile);
        $size = filesize($localFile);
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $localFile) ?: 'application/octet-stream';
        }
        
        // Nom propre
        $origName = preg_replace('/^import_\d+_[a-f0-9]+\./', 'image.', $origName);
        
        $prefix = substr($hash, 0, 2);
        $destDir = $this->filesDir . '/' . $prefix;
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        copy($localFile, $destDir . '/' . $hash);
        
        $this->exportFiles[] = [
            'id' => $nextFileId++,
            'contenthash' => $hash,
            'contextid' => $contextId,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemId,
            'filepath' => '/',
            'filename' => $origName,
            'filesize' => $size,
            'mimetype' => $mime,
        ];
        
        return '@@PLUGINFILE@@/' . $origName;
    }

    public function export() {
        // Créer le dossier d'export
        $exportId = 'export_' . time() . '_' . bin2hex(random_bytes(4));
        $this->exportDir = CACHE_DIR . '/exports/' . $exportId;
        $this->filesDir = $this->exportDir . '/files';
        
        if (!is_dir(CACHE_DIR . '/exports')) {
            mkdir(CACHE_DIR . '/exports', 0777, true);
        }
        mkdir($this->exportDir, 0777, true);
        mkdir($this->filesDir, 0777, true);
        
        // Générer la structure Moodle
        $this->generateMoodleBackup();
        
        // Créer le ZIP
        $mbzPath = CACHE_DIR . '/exports/' . ($this->data['shortname'] ?? 'cours') . '_' . date('Ymd_His') . '.mbz';
        $this->createZip($mbzPath);
        
        // Nettoyer le dossier temporaire d'export
        deleteDirectory($this->exportDir);
        
        // Nettoyer le dossier temporaire des fichiers Drive téléchargés
        if ($this->driveTempDir && is_dir($this->driveTempDir)) {
            deleteDirectory($this->driveTempDir);
        }
        
        return $mbzPath;
    }
    
    private function generateMoodleBackup() {
        // activities/ (en premier pour compter les activités par section)
        $this->generateActivities();
        
        // moodle_backup.xml
        $this->generateMoodleBackupXml();
        
        // course/course.xml
        $this->generateCourseXml();
        
        // sections/ (après activités pour avoir les bonnes séquences)
        $this->generateSections();
        
        // questions.xml (après activités pour collecter les données quiz)
        $this->generateQuestionsXml();
        
        // files.xml
        $this->generateFilesXml();
        
        // grade_history.xml (racine)
        $gradeHistoryXml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        file_put_contents($this->exportDir . '/grade_history.xml', $gradeHistoryXml);
        
        // gradebook.xml (racine) — nécessaire pour les activités avec notes (assign, quiz)
        $gradebookXml = '<?xml version="1.0" encoding="UTF-8"?>
<gradebook>
  <attributes>
  </attributes>
  <grade_categories>
    <grade_category id="1">
      <parent>$@NULL@$</parent>
      <depth>1</depth>
      <path>/1/</path>
      <fullname>?</fullname>
      <aggregation>13</aggregation>
      <keephigh>0</keephigh>
      <droplow>0</droplow>
      <aggregateonlygraded>1</aggregateonlygraded>
      <aggregateoutcomes>0</aggregateoutcomes>
      <timecreated>' . time() . '</timecreated>
      <timemodified>' . time() . '</timemodified>
      <hidden>0</hidden>
    </grade_category>
  </grade_categories>
  <grade_items>
    <grade_item id="1">
      <categoryid>$@NULL@$</categoryid>
      <itemname>$@NULL@$</itemname>
      <itemtype>course</itemtype>
      <itemmodule>$@NULL@$</itemmodule>
      <iteminstance>1</iteminstance>
      <itemnumber>$@NULL@$</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber>$@NULL@$</idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>100.00000</grademax>
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
      <timecreated>' . time() . '</timecreated>
      <timemodified>' . time() . '</timemodified>
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
        file_put_contents($this->exportDir . '/gradebook.xml', $gradebookXml);
        
        // outcomes.xml (racine)
        $outcomesXml = '<?xml version="1.0" encoding="UTF-8"?>
<outcomes_definition>
</outcomes_definition>';
        file_put_contents($this->exportDir . '/outcomes.xml', $outcomesXml);
        
        // Autres fichiers racine requis
        file_put_contents($this->exportDir . '/scales.xml', '<?xml version="1.0" encoding="UTF-8"?>
<scales_definition>
</scales_definition>');
        file_put_contents($this->exportDir . '/groups.xml', '<?xml version="1.0" encoding="UTF-8"?>
<groups>
  <groupings>
  </groupings>
</groups>');
        // questions.xml sera généré après generateActivities() via generateQuestionsXml()
        file_put_contents($this->exportDir . '/roles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<roles_definition>
</roles_definition>');
        file_put_contents($this->exportDir . '/completion.xml', '<?xml version="1.0" encoding="UTF-8"?>
<course_completion>
</course_completion>');
        file_put_contents($this->exportDir . '/badges.xml', '<?xml version="1.0" encoding="UTF-8"?>
<badges>
</badges>');
    }
    
    private function generateMoodleBackupXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<moodle_backup>
    <information>
        <name>' . htmlspecialchars($this->data['name'] ?? 'Cours') . '</name>
        <moodle_version>2023100900</moodle_version>
        <moodle_release>4.3</moodle_release>
        <backup_version>2023100900</backup_version>
        <backup_release>4.3</backup_release>
        <backup_date>' . time() . '</backup_date>
        <mnet_remoteusers>0</mnet_remoteusers>
        <include_files>1</include_files>
        <include_file_references_to_external_content>0</include_file_references_to_external_content>
        <original_wwwroot>https://elea.apps.education.fr</original_wwwroot>
        <original_site_identifier_hash>' . md5('elea-secours') . '</original_site_identifier_hash>
        <original_course_id>1</original_course_id>
        <original_course_format>topics</original_course_format>
        <original_course_fullname>' . htmlspecialchars($this->data['name'] ?? 'Cours') . '</original_course_fullname>
        <original_course_shortname>' . htmlspecialchars($this->data['shortname'] ?? 'cours') . '</original_course_shortname>
        <original_course_startdate>' . time() . '</original_course_startdate>
        <original_course_enddate>0</original_course_enddate>
        <original_course_contextid>1</original_course_contextid>
        <original_system_contextid>1</original_system_contextid>
    </information>
    <settings>
        <setting><level>root</level><name>filename</name><value>backup.mbz</value></setting>
        <setting><level>root</level><name>users</name><value>0</value></setting>
        <setting><level>root</level><name>anonymize</name><value>0</value></setting>
        <setting><level>root</level><name>role_assignments</name><value>0</value></setting>
        <setting><level>root</level><name>activities</name><value>1</value></setting>
        <setting><level>root</level><name>blocks</name><value>0</value></setting>
        <setting><level>root</level><name>files</name><value>1</value></setting>
        <setting><level>root</level><name>filters</name><value>0</value></setting>
        <setting><level>root</level><name>comments</name><value>0</value></setting>
        <setting><level>root</level><name>badges</name><value>0</value></setting>
        <setting><level>root</level><name>calendarevents</name><value>0</value></setting>
        <setting><level>root</level><name>userscompletion</name><value>0</value></setting>
        <setting><level>root</level><name>logs</name><value>0</value></setting>
        <setting><level>root</level><name>grade_histories</name><value>0</value></setting>
        <setting><level>root</level><name>questionbank</name><value>1</value></setting>
        <setting><level>root</level><name>groups</name><value>0</value></setting>
        <setting><level>root</level><name>competencies</name><value>0</value></setting>
        <setting><level>root</level><name>customfield</name><value>0</value></setting>
        <setting><level>root</level><name>contentbankcontent</name><value>0</value></setting>
        <setting><level>root</level><n>legacyfiles</n><value>0</value></setting>';
        
        // Section-level settings
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $sectionId = $sIdx + 1;
            $xml .= '
        <setting><level>section</level><section>section_' . $sectionId . '</section><n>section_' . $sectionId . '_included</n><value>1</value></setting>
        <setting><level>section</level><section>section_' . $sectionId . '</section><n>section_' . $sectionId . '_userinfo</n><value>0</value></setting>';
        }
        
        // Activity-level settings
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $activityIds = $this->sectionActivityIds[$sIdx] ?? [];
            foreach ($activityIds as $actId) {
                $dir = $this->exportDir . '/activities';
                $modName = 'hvp';
                if (is_dir($dir . '/quiz_' . $actId)) $modName = 'quiz';
                elseif (is_dir($dir . '/assign_' . $actId)) $modName = 'assign';
                elseif (is_dir($dir . '/resource_' . $actId)) $modName = 'resource';
                $xml .= '
        <setting><level>activity</level><activity>' . $modName . '_' . $actId . '</activity><n>' . $modName . '_' . $actId . '_included</n><value>1</value></setting>
        <setting><level>activity</level><activity>' . $modName . '_' . $actId . '</activity><n>' . $modName . '_' . $actId . '_userinfo</n><value>0</value></setting>';
            }
        }
        
        $xml .= '
    </settings>
    <contents>
        <activities>';
        
        // Utiliser les activity IDs tracés par generateActivities()
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $activityIds = $this->sectionActivityIds[$sIdx] ?? [];
            foreach ($activityIds as $activityId) {
                // Déterminer le type par le nom du dossier
                $dir = $this->exportDir . '/activities';
                $moduleName = 'hvp';
                $dirPrefix = 'hvp';
                if (is_dir($dir . '/quiz_' . $activityId)) {
                    $moduleName = 'quiz';
                    $dirPrefix = 'quiz';
                } elseif (is_dir($dir . '/assign_' . $activityId)) {
                    $moduleName = 'assign';
                    $dirPrefix = 'assign';
                } elseif (is_dir($dir . '/resource_' . $activityId)) {
                    $moduleName = 'resource';
                    $dirPrefix = 'resource';
                }
                
                $title = 'Activité';
                // Lire le nom depuis module.xml
                $modXml = $dir . '/' . $dirPrefix . '_' . $activityId . '/module.xml';
                if (file_exists($modXml)) {
                    // Le nom est dans le fichier principal (quiz.xml, hvp.xml, etc.)
                    $mainXml = $dir . '/' . $dirPrefix . '_' . $activityId . '/' . $dirPrefix . '.xml';
                    if (file_exists($mainXml)) {
                        $content = file_get_contents($mainXml);
                        if (preg_match('/<n>(.*?)<\/n>/', $content, $m)) {
                            $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                        }
                    }
                }
                
                $xml .= '
            <activity>
                <moduleid>' . $activityId . '</moduleid>
                <sectionid>' . ($sIdx + 1) . '</sectionid>
                <modulename>' . $moduleName . '</modulename>
                <title>' . htmlspecialchars($title) . '</title>
                <directory>activities/' . $dirPrefix . '_' . $activityId . '</directory>
            </activity>';
            }
        }
        
        $xml .= '
        </activities>
        <sections>';
        
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $xml .= '
            <section>
                <sectionid>' . ($sIdx + 1) . '</sectionid>
                <title>' . htmlspecialchars($section['name'] ?? 'Section') . '</title>
                <directory>sections/section_' . ($sIdx + 1) . '</directory>
            </section>';
        }
        
        $xml .= '
        </sections>
        <course>
            <courseid>1</courseid>
            <title>' . htmlspecialchars($this->data['name'] ?? 'Cours') . '</title>
            <directory>course</directory>
        </course>
    </contents>
</moodle_backup>';
        
        file_put_contents($this->exportDir . '/moodle_backup.xml', $xml);
    }
    
    private function generateCourseXml() {
        mkdir($this->exportDir . '/course', 0777, true);
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<course id="1" contextid="1">
    <shortname>' . htmlspecialchars($this->data['shortname'] ?? 'cours') . '</shortname>
    <fullname>' . htmlspecialchars($this->data['name'] ?? 'Cours') . '</fullname>
    <idnumber></idnumber>
    <summary></summary>
    <summaryformat>1</summaryformat>
    <format>topics</format>
    <showgrades>1</showgrades>
    <newsitems>5</newsitems>
    <startdate>' . time() . '</startdate>
    <enddate>0</enddate>
    <marker>0</marker>
    <maxbytes>0</maxbytes>
    <legacyfiles>0</legacyfiles>
    <showreports>0</showreports>
    <visible>1</visible>
    <groupmode>0</groupmode>
    <groupmodeforce>0</groupmodeforce>
    <defaultgroupingid>0</defaultgroupingid>
    <lang></lang>
    <theme></theme>
    <timecreated>' . time() . '</timecreated>
    <timemodified>' . time() . '</timemodified>
    <requested>0</requested>
    <showactivitydates>1</showactivitydates>
    <showcompletionconditions>1</showcompletionconditions>
    <enablecompletion>1</enablecompletion>
    <completionnotify>0</completionnotify>
    <category id="1">
        <name>Défaut</name>
        <description></description>
    </category>
</course>';
        
        file_put_contents($this->exportDir . '/course/course.xml', $xml);
        
        // course/inforef.xml — doit référencer les catégories de questions course-level
        $courseInforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>';
        if (!empty($this->quizQuestions)) {
            $courseInforefXml .= '
  <question_categoryref>';
            foreach ($this->quizQuestions as $q) {
                $topCourseCatId = $q['bankEntryId'] + 2000;
                $defaultCourseCatId = $q['bankEntryId'] + 1000;
                $courseInforefXml .= '
    <question_category>
      <id>' . $topCourseCatId . '</id>
    </question_category>
    <question_category>
      <id>' . $defaultCourseCatId . '</id>
    </question_category>';
            }
            $courseInforefXml .= '
  </question_categoryref>';
        }
        $courseInforefXml .= '
</inforef>';
        file_put_contents($this->exportDir . '/course/inforef.xml', $courseInforefXml);
        
        // course/enrolments.xml
        file_put_contents($this->exportDir . '/course/enrolments.xml', '<?xml version="1.0" encoding="UTF-8"?>
<enrolments>
  <enrols>
  </enrols>
</enrolments>');
        
        // course/roles.xml
        file_put_contents($this->exportDir . '/course/roles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>');
        
        // course/filters.xml
        file_put_contents($this->exportDir . '/course/filters.xml', '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>');
        
        // course/completiondefaults.xml
        file_put_contents($this->exportDir . '/course/completiondefaults.xml', '<?xml version="1.0" encoding="UTF-8"?>
<course_completion_defaults>
</course_completion_defaults>');
        
        // course/contentbank.xml
        file_put_contents($this->exportDir . '/course/contentbank.xml', '<?xml version="1.0" encoding="UTF-8"?>
<contentbank>
</contentbank>');
    }
    
    private function generateSections() {
        mkdir($this->exportDir . '/sections', 0777, true);
        
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $sectionId = $sIdx + 1;
            $sectionDir = $this->exportDir . '/sections/section_' . $sectionId;
            mkdir($sectionDir, 0777, true);
            
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<section id="' . $sectionId . '">
    <number>' . $sIdx . '</number>
    <name>' . htmlspecialchars($section['name'] ?? 'Section ' . $sectionId) . '</name>
    <summary>' . htmlspecialchars($section['summary'] ?? '') . '</summary>
    <summaryformat>1</summaryformat>
    <sequence>' . implode(',', $this->sectionActivityIds[$sIdx] ?? range(1, count($section['activities'] ?? []))) . '</sequence>
    <visible>1</visible>
    <availabilityjson></availabilityjson>
    <timemodified>' . time() . '</timemodified>
</section>';
            
            file_put_contents($sectionDir . '/section.xml', $xml);
        }
    }
    
    private function generateActivities() {
        mkdir($this->exportDir . '/activities', 0777, true);
        
        $activityId = 1;
        foreach ($this->data['sections'] ?? [] as $sIdx => $section) {
            $this->sectionActivityIds[$sIdx] = [];
            foreach ($section['activities'] ?? [] as $activity) {
                $activityType = $activity['type'] ?? 'h5pactivity';
                if ($activityType === 'quiz') {
                    $this->generateQuizActivity($activityId, $sIdx + 1, $activity);
                    $this->sectionActivityIds[$sIdx][] = $activityId;
                    $activityId++;
                } elseif ($activityType === 'assign') {
                    $this->generateAssignActivity($activityId, $sIdx + 1, $activity);
                    $this->sectionActivityIds[$sIdx][] = $activityId;
                    $activityId++;
                } elseif ($activityType === 'resource') {
                    $this->generateResourceActivity($activityId, $sIdx + 1, $activity);
                    $this->sectionActivityIds[$sIdx][] = $activityId;
                    $activityId++;
                } else {
                    // Vérifier si c'est un QuestionSet qui contient des questions ddimageortext
                    $h5pType = $activity['h5pType'] ?? '';
                    $questions = $activity['content']['questions'] ?? [];
                    $ddiQuestions = [];
                    $otherQuestions = [];
                    
                    if ($h5pType === 'QuestionSet') {
                        foreach ($questions as $q) {
                            if (($q['qtype'] ?? '') === 'ddimageortext') {
                                $ddiQuestions[] = $q;
                            } else {
                                $otherQuestions[] = $q;
                            }
                        }
                    }
                    
                    // Exporter les questions non-DDI comme QuestionSet H5P
                    if (!empty($ddiQuestions) && !empty($otherQuestions)) {
                        $activityCopy = $activity;
                        $activityCopy['content']['questions'] = $otherQuestions;
                        $this->generateH5pActivity($activityId, $sIdx + 1, $activityCopy);
                        $this->sectionActivityIds[$sIdx][] = $activityId;
                        $activityId++;
                    } elseif (empty($ddiQuestions)) {
                        $this->generateH5pActivity($activityId, $sIdx + 1, $activity);
                        $this->sectionActivityIds[$sIdx][] = $activityId;
                        $activityId++;
                    }
                    // Si toutes les questions sont DDI, pas de QuestionSet H5P
                    
                    // Exporter chaque question DDI comme activité quiz Moodle séparée
                    foreach ($ddiQuestions as $ddiQ) {
                        $quizActivity = [
                            'type' => 'quiz',
                            'quizType' => 'ddimageortext',
                            'name' => $ddiQ['name'] ?? 'Glisser-Déposer',
                            'content' => [
                                'questiontext' => $ddiQ['questiontext'] ?? '<p>Compléter le schéma</p>',
                                'shuffleanswers' => $ddiQ['shuffleanswers'] ?? 1,
                                'attempts_number' => 1,
                                'defaultmark' => $ddiQ['defaultmark'] ?? 1,
                                'backgroundUrl' => $ddiQ['backgroundUrl'] ?? null,
                                'bgImageName' => $ddiQ['bgImageName'] ?? null,
                                'canvasWidth' => $ddiQ['canvasWidth'] ?? 800,
                                'canvasHeight' => $ddiQ['canvasHeight'] ?? 600,
                                'drags' => $ddiQ['drags'] ?? [],
                                'drops' => $ddiQ['drops'] ?? [],
                            ],
                        ];
                        $this->generateQuizActivity($activityId, $sIdx + 1, $quizActivity);
                        $this->sectionActivityIds[$sIdx][] = $activityId;
                        $activityId++;
                    }
                }
            }
        }
    }
    
    private function generateH5pActivity($activityId, $sectionId, $activity) {
        $activityDir = $this->exportDir . '/activities/hvp_' . $activityId;
        mkdir($activityDir, 0777, true);
        
        // Générer le JSON H5P
        $h5pContent = $this->buildH5pContent($activity);
        $jsonContent = json_encode($h5pContent, JSON_UNESCAPED_UNICODE);
        
        // Déterminer le machine_name selon le type
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
        
        // hvp.xml - format Éléa
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $activityId . '" moduleid="' . $activityId . '" modulename="hvp" contextid="' . ($activityId + 100) . '">
  <hvp id="' . $activityId . '">
    <name>' . htmlspecialchars($activity['name'] ?? 'Activité H5P') . '</name>
    <machine_name>' . $machineName . '</machine_name>
    <major_version>' . $version['major'] . '</major_version>
    <minor_version>' . $version['minor'] . '</minor_version>
    <intro></intro>
    <introformat>1</introformat>
    <json_content>' . htmlspecialchars($jsonContent) . '</json_content>
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
    <timecreated>' . time() . '</timecreated>
    <timemodified>' . time() . '</timemodified>
    <authors>[]</authors>
    <license>U</license>
    <completionpass>0</completionpass>
    <content_user_data>
    </content_user_data>
  </hvp>
</activity>';
        
        file_put_contents($activityDir . '/hvp.xml', $xml);
        
        // module.xml - format Éléa
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024120900">
  <modulename>hvp</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . ($sectionId - 1) . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . time() . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>1</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>1</visibleold>
  <groupmode>0</groupmode>
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
        
        file_put_contents($activityDir . '/module.xml', $moduleXml);
        
        // grades.xml
        $gradesXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        file_put_contents($activityDir . '/grades.xml', $gradesXml);
        
        // grade_history.xml
        $gradeHistoryXml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        file_put_contents($activityDir . '/grade_history.xml', $gradeHistoryXml);
        
        // inforef.xml
        $inforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <fileref>
  </fileref>
</inforef>';
        file_put_contents($activityDir . '/inforef.xml', $inforefXml);
        
        // roles.xml
        $rolesXml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        file_put_contents($activityDir . '/roles.xml', $rolesXml);
        
        // filters.xml
        $filtersXml = '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>';
        file_put_contents($activityDir . '/filters.xml', $filtersXml);
    }
    
    private function generateQuizActivity($activityId, $sectionId, $activity) {
        $activityDir = $this->exportDir . '/activities/quiz_' . $activityId;
        mkdir($activityDir, 0777, true);
        
        $content = $activity['content'] ?? [];
        $name = htmlspecialchars($activity['name'] ?? 'Quiz', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $intro = $content['intro'] ?? '';
        if (!empty($intro) && strpos($intro, '<') === false) {
            $intro = '<p>' . htmlspecialchars($intro) . '</p>';
        }
        $attemptsNumber = $content['attempts_number'] ?? 1;
        $preferredBehaviour = $content['preferredbehaviour'] ?? 'deferredfeedback';
        $questionsPerPage = $content['questionsperpage'] ?? 1;
        $shuffleAnswers = $content['shuffleanswers'] ?? 1;
        $navMethod = $content['navmethod'] ?? 'free';
        $grade = number_format($content['grade'] ?? 10, 5, '.', '');
        $now = time();
        $quizId = $activityId + 2000;
        $contextId = $activityId + 10000;
        $courseContextId = 100000;
        
        $questionInstancesXml = '';
        $quizType = $activity['quizType'] ?? '';
        $sumGrades = $grade;
        
        if ($quizType === 'ddimageortext') {
            $questionId = $activityId + 30000;
            $bankEntryId = $activityId + 40000;
            $defaultMark = $content['defaultmark'] ?? 1;
            $sumGrades = number_format($defaultMark, 5, '.', '');
            
            $questionInstancesXml = '
      <question_instance id="' . ($activityId + 50000) . '">
        <quizid>' . $quizId . '</quizid>
        <slot>1</slot>
        <page>1</page>
        <displaynumber>$@NULL@$</displaynumber>
        <requireprevious>0</requireprevious>
        <maxmark>' . number_format($defaultMark, 7, '.', '') . '</maxmark>
        <quizgradeitemid>$@NULL@$</quizgradeitemid>
        <question_reference id="' . ($activityId + 60000) . '">
          <usingcontextid>' . $contextId . '</usingcontextid>
          <component>mod_quiz</component>
          <questionarea>slot</questionarea>
          <questionbankentryid>' . $bankEntryId . '</questionbankentryid>
          <version>$@NULL@$</version>
        </question_reference>
      </question_instance>';
            
            $drags = $content['drags'] ?? [];
            $drops = $content['drops'] ?? [];
            $questionText = $content['questiontext'] ?? '<p>Compléter le schéma</p>';
            $questionText = $this->extractQuestiontextImages($questionText, $courseContextId, $questionId);
            
            $bgFileEntries = [];
            $bgUrl = $content['backgroundUrl'] ?? null;
            $bgName = $content['bgImageName'] ?? 'background.png';
            if ($bgUrl) {
                $bgFileData = $this->resolveEditorFile($bgUrl, $bgName, $courseContextId, 'qtype_ddimageortext', 'bgimage', $questionId);
                if ($bgFileData) $bgFileEntries[] = $bgFileData;
            }
            
            $dragFileEntries = [];
            foreach ($drags as $dIdx => $drag) {
                $dragImgUrl = $drag['imageUrl'] ?? null;
                $dragImgName = $drag['imageName'] ?? ('drag_' . ($dIdx + 1) . '.png');
                if ($dragImgUrl) {
                    // itemId pour dragimage = ID du drag dans le XML (questionId + 300 + index)
                    $dragXmlId = $questionId + 300 + $dIdx;
                    $dragFileData = $this->resolveEditorFile($dragImgUrl, $dragImgName, $courseContextId, 'qtype_ddimageortext', 'dragimage', $dragXmlId);
                    if ($dragFileData) {
                        $dragFileData['dragno'] = $dIdx + 1;
                        $dragFileEntries[] = $dragFileData;
                    }
                }
            }
            
            $this->quizQuestions[] = [
                'questionId' => $questionId,
                'bankEntryId' => $bankEntryId,
                'courseContextId' => $courseContextId,
                'quizContextId' => $contextId,
                'qtype' => 'ddimageortext',
                'name' => $activity['name'] ?? 'Glisser-déposer',
                'questiontext' => $questionText,
                'defaultmark' => $defaultMark,
                'shuffleanswers' => $shuffleAnswers,
                'drags' => $drags,
                'drops' => $drops,
                'bgFiles' => $bgFileEntries,
                'dragFiles' => $dragFileEntries
            ];
        }
        
        $quizXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $quizId . '" moduleid="' . $activityId . '" modulename="quiz" contextid="' . $contextId . '">
  <quiz id="' . $quizId . '">
    <name>' . $name . '</name>
    <intro>' . htmlspecialchars($intro, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</intro>
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
    <sumgrades>' . $sumGrades . '</sumgrades>
    <grade>' . $grade . '</grade>
    <timecreated>' . $now . '</timecreated>
    <timemodified>' . $now . '</timemodified>
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
        file_put_contents($activityDir . '/quiz.xml', $quizXml);
        
        // module.xml
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>quiz</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . ($sectionId - 1) . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>1</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>1</visibleold>
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
        file_put_contents($activityDir . '/module.xml', $moduleXml);
        
        // grades.xml
        $gradesXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>';
        file_put_contents($activityDir . '/grades.xml', $gradesXml);
        
        // grade_history.xml
        $gradeHistoryXml = '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>';
        file_put_contents($activityDir . '/grade_history.xml', $gradeHistoryXml);
        
        // inforef.xml — doit contenir question_categoryref pour que Moodle relie les questions
        $bankEntryId = $activityId + 40000;
        $topCourseCatId = $bankEntryId + 2000;
        $defaultCourseCatId = $bankEntryId + 1000;
        $topQuizCatId = $bankEntryId + 4000;
        $defaultQuizCatId = $bankEntryId + 3000;
        $questionCatRefXml = '';
        if ($quizType === 'ddimageortext') {
            $questionCatRefXml = '
  <question_categoryref>
    <question_category>
      <id>' . $topCourseCatId . '</id>
    </question_category>
    <question_category>
      <id>' . $defaultCourseCatId . '</id>
    </question_category>
    <question_category>
      <id>' . $defaultQuizCatId . '</id>
    </question_category>
    <question_category>
      <id>' . $topQuizCatId . '</id>
    </question_category>
  </question_categoryref>';
        }
        $inforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <grade_itemref>
    <grade_item>
      <id>' . ($activityId + 70000) . '</id>
    </grade_item>
  </grade_itemref>' . $questionCatRefXml . '
</inforef>';
        file_put_contents($activityDir . '/inforef.xml', $inforefXml);
        
        // roles.xml
        $rolesXml = '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>';
        file_put_contents($activityDir . '/roles.xml', $rolesXml);
        
        // filters.xml
        $filtersXml = '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>';
        file_put_contents($activityDir . '/filters.xml', $filtersXml);
    }
    
    
    private function generateAssignActivity($activityId, $sectionId, $activity) {
        $activityDir = $this->exportDir . '/activities/assign_' . $activityId;
        mkdir($activityDir, 0777, true);
        
        $name = htmlspecialchars($activity['name'] ?? 'Fichier');
        $contextId = $activityId + 200;
        $now = time();
        $nextFileId = $activityId + 5000;
        
        // Gérer les fichiers joints (multi-fichier + rétrocompatibilité mono-fichier)
        $editorFiles = $activity['files'] ?? [];
        if (empty($editorFiles) && !empty($activity['fileUrl']) && !empty($activity['fileName'])) {
            $editorFiles = [['fileUrl' => $activity['fileUrl'], 'fileName' => $activity['fileName']]];
        }
        
        $hasAttachments = false;
        foreach ($editorFiles as $f) {
            $fileUrl = $f['fileUrl'] ?? null;
            $fileName = $f['fileName'] ?? null;
            if (!$fileUrl || !$fileName) continue;
            
            // Décoder les entités HTML potentielles (&amp; → &)
            $cleanUrl = html_entity_decode($fileUrl);
            $uploadedFile = $this->resolveFileToLocal($cleanUrl);
            
            if ($uploadedFile && file_exists($uploadedFile)) {
                $fileHash = sha1_file($uploadedFile);
                $fileSize = filesize($uploadedFile);
                $fileMime = 'application/octet-stream';
                
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $fileMime = finfo_file($finfo, $uploadedFile) ?: 'application/octet-stream';
                }
                
                $prefix = substr($fileHash, 0, 2);
                $destDir = $this->filesDir . '/' . $prefix;
                if (!is_dir($destDir)) mkdir($destDir, 0777, true);
                copy($uploadedFile, $destDir . '/' . $fileHash);
                
                $this->exportFiles[] = [
                    'id' => $nextFileId++,
                    'contenthash' => $fileHash,
                    'contextid' => $contextId,
                    'component' => 'mod_assign',
                    'filearea' => 'introattachment',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $fileName,
                    'filesize' => $fileSize,
                    'mimetype' => $fileMime,
                ];
                $hasAttachments = true;
            }
        }
        
        // Entrée répertoire (une seule pour tous les fichiers)
        if ($hasAttachments) {
            $this->exportFiles[] = [
                'id' => $nextFileId++,
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
        
        // Gérer l'intro (description) et ses images
        $intro = $activity['intro'] ?? '';
        $introXml = '';
        if (!empty($intro)) {
            // Extraire et copier les images de l'intro, remplacer par @@PLUGINFILE@@/
            // Pattern 1: URLs serve_upload — tolère un param session avant/après file=
            // et le consomme pour qu'il ne reste pas incrusté dans le HTML exporté.
            $introXml = preg_replace_callback(
                '/api\/editor_api\.php\?action=serve_upload(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?&(?:amp;)?file=([^"\'<>\s&]+)(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?/',
                function($m) use ($contextId, &$nextFileId) {
                    $encodedName = $m[1];
                    $localFile = $this->resolveFileToLocal('file=' . $encodedName);
                    if (!$localFile || !file_exists($localFile)) return $m[0];
                    
                    return $this->copyFileForExport($localFile, urldecode($encodedName), $contextId, 'mod_assign', 'intro', 0, $nextFileId);
                },
                $intro
            );
            // Pattern 2: URLs Drive (lh3.googleusercontent.com)
            $introXml = preg_replace_callback(
                '#https://lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#',
                function($m) use ($contextId, &$nextFileId) {
                    $driveId = $m[1];
                    $localFile = $this->resolveFileToLocal($m[0]);
                    if (!$localFile || !file_exists($localFile)) return $m[0];
                    
                    return $this->copyFileForExport($localFile, basename($localFile), $contextId, 'mod_assign', 'intro', 0, $nextFileId);
                },
                $introXml
            );
            
            // Ajouter l'entrée répertoire pour intro
            $this->exportFiles[] = [
                'id' => $nextFileId++,
                'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'contextid' => $contextId,
                'component' => 'mod_assign',
                'filearea' => 'intro',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => '.',
                'filesize' => 0,
                'mimetype' => '$@NULL@$',
            ];
        }
        
        // assign.xml
        $pcId = $activityId * 10 + 50000;
        $assignXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $activityId . '" moduleid="' . $activityId . '" modulename="assign" contextid="' . $contextId . '">
  <assign id="' . $activityId . '">
    <name>' . $name . '</name>
    <intro>' . htmlspecialchars($introXml) . '</intro>
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
    <grade>100</grade>
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
        file_put_contents($activityDir . '/assign.xml', $assignXml);
        
        // module.xml
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>assign</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . ($sectionId - 1) . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>1</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>1</visibleold>
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
        file_put_contents($activityDir . '/module.xml', $moduleXml);
        
        // inforef.xml
        $inforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <fileref>';
        if ($fileHash) {
            $inforefXml .= '
    <file>
      <id>' . $fileId . '</id>
    </file>
    <file>
      <id>' . ($fileId + 1) . '</id>
    </file>';
        }
        $inforefXml .= '
  </fileref>
  <grade_itemref>
    <grade_item>
      <id>' . ($activityId + 3000) . '</id>
    </grade_item>
  </grade_itemref>
</inforef>';
        file_put_contents($activityDir . '/inforef.xml', $inforefXml);
        
        // Fichiers auxiliaires
        $gradeItemId = $activityId + 3000;
        
        // grades.xml avec grade_item
        $gradesXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
    <grade_item id="' . $gradeItemId . '">
      <categoryid>1</categoryid>
      <itemname>' . $name . '</itemname>
      <itemtype>mod</itemtype>
      <itemmodule>assign</itemmodule>
      <iteminstance>' . $activityId . '</iteminstance>
      <itemnumber>0</itemnumber>
      <iteminfo>$@NULL@$</iteminfo>
      <idnumber></idnumber>
      <calculation>$@NULL@$</calculation>
      <gradetype>1</gradetype>
      <grademax>100.00000</grademax>
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
        file_put_contents($activityDir . '/grades.xml', $gradesXml);
        
        // grading.xml avec id
        $gradingXml = '<?xml version="1.0" encoding="UTF-8"?>
<areas>
  <area id="' . ($activityId + 6000) . '">
    <areaname>submissions</areaname>
    <activemethod>$@NULL@$</activemethod>
    <definitions>
    </definitions>
  </area>
</areas>';
        file_put_contents($activityDir . '/grading.xml', $gradingXml);
        
        foreach (['roles' => 'roles', 'filters' => 'filters', 'grade_history' => 'grade_history'] as $auxFile => $rootTag) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<' . $rootTag . '>
</' . $rootTag . '>';
            file_put_contents($activityDir . '/' . $auxFile . '.xml', $xml);
        }
    }
    
    private function generateResourceActivity($activityId, $sectionId, $activity) {
        $activityDir = $this->exportDir . '/activities/resource_' . $activityId;
        mkdir($activityDir, 0777, true);
        
        $name = htmlspecialchars($activity['name'] ?? 'Fichiers à distribuer');
        $contextId = $activityId + 300;
        $now = time();
        $nextFileId = $activityId * 100 + 50000;
        $fileIds = []; // Traquer les IDs pour inforef
        
        // Gérer les fichiers joints (content)
        $editorFiles = $activity['files'] ?? [];
        
        foreach ($editorFiles as $f) {
            $fileUrl = $f['fileUrl'] ?? null;
            $fileName = $f['fileName'] ?? null;
            if (!$fileUrl || !$fileName) continue;
            
            $cleanUrl = html_entity_decode($fileUrl);
            $uploadedFile = $this->resolveFileToLocal($cleanUrl);
            
            if ($uploadedFile && file_exists($uploadedFile)) {
                $fileHash = sha1_file($uploadedFile);
                $fileSize = filesize($uploadedFile);
                $fileMime = 'application/octet-stream';
                
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $fileMime = finfo_file($finfo, $uploadedFile) ?: 'application/octet-stream';
                }
                
                $prefix = substr($fileHash, 0, 2);
                $destDir = $this->filesDir . '/' . $prefix;
                if (!is_dir($destDir)) mkdir($destDir, 0777, true);
                copy($uploadedFile, $destDir . '/' . $fileHash);
                
                $fid = $nextFileId++;
                $fileIds[] = $fid;
                $this->exportFiles[] = [
                    'id' => $fid,
                    'contenthash' => $fileHash,
                    'contextid' => $contextId,
                    'component' => 'mod_resource',
                    'filearea' => 'content',
                    'itemid' => 0,
                    'filepath' => '/',
                    'filename' => $fileName,
                    'filesize' => $fileSize,
                    'mimetype' => $fileMime,
                ];
            }
        }
        
        // Entrée répertoire pour content
        $fid = $nextFileId++;
        $fileIds[] = $fid;
        $this->exportFiles[] = [
            'id' => $fid,
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
        
        // Gérer l'intro (description) et ses images
        $intro = $activity['intro'] ?? '';
        $introXml = '';
        if (!empty($intro)) {
            // Tolère un param session avant/après file= et le consomme (cf. plus haut).
            $introXml = preg_replace_callback(
                '/api\/editor_api\.php\?action=serve_upload(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?&(?:amp;)?file=([^\"\'<>\s&]+)(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?/',
                function($m) use ($contextId, &$nextFileId, &$fileIds) {
                    $encodedName = $m[1];
                    $localFile = $this->resolveFileToLocal('file=' . $encodedName);
                    if (!$localFile || !file_exists($localFile)) return $m[0];
                    
                    $origName = urldecode($encodedName);
                    $origName = preg_replace('/^import_\d+_[a-f0-9]+\./', 'image.', $origName);
                    $hash = sha1_file($localFile);
                    $size = filesize($localFile);
                    $mime = 'image/png';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $localFile) ?: 'image/png';
                    }
                    
                    $origName = urldecode($encodedName);
                    $origName = preg_replace('/^import_\d+_[a-f0-9]+\./', 'image.', $origName);
                    
                    $prefix = substr($hash, 0, 2);
                    $destDir = $this->filesDir . '/' . $prefix;
                    if (!is_dir($destDir)) mkdir($destDir, 0777, true);
                    copy($localFile, $destDir . '/' . $hash);
                    
                    $fid = $nextFileId++;
                    $fileIds[] = $fid;
                    $this->exportFiles[] = [
                        'id' => $fid,
                        'contenthash' => $hash,
                        'contextid' => $contextId,
                        'component' => 'mod_resource',
                        'filearea' => 'intro',
                        'itemid' => 0,
                        'filepath' => '/',
                        'filename' => $origName,
                        'filesize' => $size,
                        'mimetype' => $mime,
                    ];
                    
                    return '@@PLUGINFILE@@/' . $origName;
                },
                $intro
            );
            // Pattern 2: URLs Drive (lh3.googleusercontent.com) dans resource intro
            $introXml = preg_replace_callback(
                '#https://lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#',
                function($m) use ($contextId, &$nextFileId, &$fileIds) {
                    $localFile = $this->resolveFileToLocal($m[0]);
                    if (!$localFile || !file_exists($localFile)) return $m[0];
                    
                    $origName = basename($localFile);
                    $hash = sha1_file($localFile);
                    $size = filesize($localFile);
                    $mime = 'image/png';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $localFile) ?: 'image/png';
                    }
                    
                    $prefix = substr($hash, 0, 2);
                    $destDir = $this->filesDir . '/' . $prefix;
                    if (!is_dir($destDir)) mkdir($destDir, 0777, true);
                    copy($localFile, $destDir . '/' . $hash);
                    
                    $fid = $nextFileId++;
                    $fileIds[] = $fid;
                    $this->exportFiles[] = [
                        'id' => $fid, 'contenthash' => $hash, 'contextid' => $contextId,
                        'component' => 'mod_resource', 'filearea' => 'intro', 'itemid' => 0,
                        'filepath' => '/', 'filename' => $origName, 'filesize' => $size, 'mimetype' => $mime,
                    ];
                    
                    return '@@PLUGINFILE@@/' . $origName;
                },
                $introXml
            );
            
            // Entrée répertoire pour intro
            $fid = $nextFileId++;
            $fileIds[] = $fid;
            $this->exportFiles[] = [
                'id' => $fid,
                'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'contextid' => $contextId,
                'component' => 'mod_resource',
                'filearea' => 'intro',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => '.',
                'filesize' => 0,
                'mimetype' => '$@NULL@$',
            ];
        }
        
        // resource.xml
        $resourceXml = '<?xml version="1.0" encoding="UTF-8"?>
<activity id="' . $activityId . '" moduleid="' . $activityId . '" modulename="resource" contextid="' . $contextId . '">
  <resource id="' . $activityId . '">
    <name>' . $name . '</name>
    <intro>' . htmlspecialchars($introXml) . '</intro>
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
        file_put_contents($activityDir . '/resource.xml', $resourceXml);
        
        // module.xml
        $moduleXml = '<?xml version="1.0" encoding="UTF-8"?>
<module id="' . $activityId . '" version="2024100700">
  <modulename>resource</modulename>
  <sectionid>' . $sectionId . '</sectionid>
  <sectionnumber>' . $sectionId . '</sectionnumber>
  <idnumber></idnumber>
  <added>' . $now . '</added>
  <score>0</score>
  <indent>0</indent>
  <visible>1</visible>
  <visibleoncoursepage>1</visibleoncoursepage>
  <visibleold>1</visibleold>
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
        file_put_contents($activityDir . '/module.xml', $moduleXml);
        
        // inforef.xml avec les IDs des fichiers
        $inforefXml = '<?xml version="1.0" encoding="UTF-8"?>
<inforef>
  <fileref>';
        foreach ($fileIds as $fid) {
            $inforefXml .= '
    <file>
      <id>' . $fid . '</id>
    </file>';
        }
        $inforefXml .= '
  </fileref>
</inforef>';
        file_put_contents($activityDir . '/inforef.xml', $inforefXml);
        
        // Fichiers auxiliaires (format identique à Éléa)
        file_put_contents($activityDir . '/grades.xml', '<?xml version="1.0" encoding="UTF-8"?>
<activity_gradebook>
  <grade_items>
  </grade_items>
  <grade_letters>
  </grade_letters>
</activity_gradebook>');
        
        file_put_contents($activityDir . '/grade_history.xml', '<?xml version="1.0" encoding="UTF-8"?>
<grade_history>
  <grade_grades>
  </grade_grades>
</grade_history>');
        
        file_put_contents($activityDir . '/roles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<roles>
  <role_overrides>
  </role_overrides>
  <role_assignments>
  </role_assignments>
</roles>');
        
        file_put_contents($activityDir . '/filters.xml', '<?xml version="1.0" encoding="UTF-8"?>
<filters>
  <filter_actives>
  </filter_actives>
  <filter_configs>
  </filter_configs>
</filters>');
    }
    

    private function buildH5pContent($activity) {
        $type = $activity['h5pType'] ?? 'CoursePresentation';
        $content = $activity['content'] ?? [];
        
        // Si le contenu est déjà complet (importé d'un MBZ), le retourner tel quel
        if ($type === 'CoursePresentation' && isset($content['presentation']['slides']) && isset($content['l10n'])) {
            return $content;
        }
        if ($type === 'InteractiveVideo' && isset($content['interactiveVideo']) && isset($content['l10n'])) {
            return $content;
        }
        if ($type === 'ThreeImage' && isset($content['threeImage']['scenes']) && isset($content['l10n'])) {
            return $content;
        }
        
        switch ($type) {
            case 'CoursePresentation':
                return $this->buildCoursePresentation($content);
            case 'InteractiveVideo':
                return $this->buildInteractiveVideo($content);
            case 'QuestionSet':
                return $this->buildQuestionSet($content);
            case 'MultiChoice':
                return $this->buildMultiChoice($content);
            case 'TrueFalse':
                return $this->buildTrueFalse($content);
            case 'Blanks':
                return $this->buildBlanks($content);
            case 'DialogCards':
                return $this->buildDialogCards($content);
            case 'DragText':
                return $this->buildDragText($content);
            case 'FindTheWords':
                return $this->buildFindTheWords($content);
            case 'ThreeImage':
                return $this->buildThreeImage($content);
            default:
                return $content;
        }
    }
    
    private function buildCoursePresentation($content) {
        $slides = $content['presentation']['slides'] ?? [['elements' => []]];
        
        // Compléter chaque élément des slides
        foreach ($slides as &$slide) {
            if (!isset($slide['elements'])) $slide['elements'] = [];
            foreach ($slide['elements'] as &$element) {
                $element = $this->completeSlideElement($element);
            }
        }
        
        return [
            'presentation' => [
                'slides' => $slides,
                'keywordListEnabled' => true,
                'globalBackgroundSelector' => [],
                'keywordListAlwaysShow' => false,
                'keywordListAutoHide' => false,
                'keywordListOpacity' => 90
            ],
            'override' => [
                'activeSurface' => false,
                'hideSummarySlide' => false,
                'summarySlideSolutionButton' => true,
                'summarySlideRetryButton' => true,
                'enablePrintButton' => false,
                'social' => []
            ],
            'l10n' => [
                'slide' => 'Diapositive',
                'score' => 'Score',
                'yourScore' => 'Votre score',
                'maxScore' => 'Score maximum',
                'total' => 'Total',
                'totalScore' => 'Score total',
                'showSolutions' => 'Voir les réponses',
                'retry' => 'Réessayer',
                'exportAnswers' => 'Exporter le texte',
                'hideKeywords' => 'Masquer la liste des mots-clés',
                'showKeywords' => 'Afficher la liste des mots-clés',
                'fullscreen' => 'Plein écran',
                'exitFullscreen' => 'Quitter le plein écran',
                'prevSlide' => 'Diapositive précédente',
                'nextSlide' => 'Diapositive suivante',
                'currentSlide' => 'Diapositive actuelle',
                'lastSlide' => 'Dernière diapositive',
                'solutionModeTitle' => 'Quitter le mode solution',
                'solutionModeText' => 'Mode solution',
                'summaryMultipleTaskText' => 'Exercices multiples',
                'scoreMessage' => 'Vous avez obtenu :',
                'shareFacebook' => 'Partager sur Facebook',
                'shareTwitter' => 'Partager sur Twitter',
                'shareGoogle' => 'Partager sur Google+',
                'summary' => 'Résumé',
                'solutionsButtonTitle' => 'Voir les réponses',
                'printTitle' => 'Imprimer',
                'printIngress' => 'Comment souhaitez-vous imprimer cette présentation ?',
                'printAllSlides' => 'Imprimer toutes les diapositives',
                'printCurrentSlide' => 'Imprimer la diapositive actuelle',
                'noTitle' => 'Sans intitulé',
                'accessibilitySlideNavigationExplanation' => 'Utilisez les flèches gauche et droite pour naviguer entre les diapositives',
                'accessibilityCanvasLabel' => 'Zone de présentation. Utilisez les flèches gauche et droite pour naviguer entre les diapositives.',
                'containsNotCompleted' => '@slideName contient des interactions incomplètes',
                'containsCompleted' => '@slideName contient des interactions complètes',
                'slideCount' => 'Diapositive @index de @total',
                'containsOnlyCorrect' => 'toutes les réponses sont bonnes sur @slideName',
                'containsIncorrectAnswers' => '@slideName contient des réponses incorrectes',
                'shareResult' => 'Partager le résultat',
                'accessibilityTotalScore' => 'Vous avez obtenu @score sur @maxScore points au total',
                'accessibilityEnteredFullscreen' => 'Mode plein-écran activé',
                'accessibilityExitedFullscreen' => 'Mode plein-écran désactivé',
                'confirmDialogHeader' => 'Envoyer vos réponses',
                'confirmDialogText' => 'Cette action va envoyer vos réponses, voulez-vous continuer?',
                'confirmDialogConfirmText' => 'Envoyer et voir les résultats'
            ]
        ];
    }
    
    /**
     * Complète un élément de slide avec toutes les propriétés requises
     */
    private function completeSlideElement($element) {
        // Propriétés par défaut pour tout élément
        if (!isset($element['alwaysDisplayComments'])) $element['alwaysDisplayComments'] = false;
        if (!isset($element['backgroundOpacity'])) $element['backgroundOpacity'] = 0;
        if (!isset($element['displayAsButton'])) $element['displayAsButton'] = false;
        if (!isset($element['buttonSize'])) $element['buttonSize'] = 'big';
        if (!isset($element['goToSlideType'])) $element['goToSlideType'] = 'specified';
        if (!isset($element['invisible'])) $element['invisible'] = false;
        if (!isset($element['solution'])) $element['solution'] = '';
        
        // Compléter l'action si présente
        if (isset($element['action'])) {
            $library = $element['action']['library'] ?? '';
            
            // Ajouter subContentId et metadata si manquants
            if (!isset($element['action']['subContentId'])) {
                $element['action']['subContentId'] = $this->generateUUID();
            }
            if (!isset($element['action']['metadata'])) {
                $contentType = str_replace('H5P.', '', explode(' ', $library)[0]);
                $element['action']['metadata'] = [
                    'contentType' => $contentType,
                    'license' => 'U',
                    'title' => 'Sans titre ' . $contentType,
                    'authors' => [],
                    'changes' => []
                ];
            }
            
            // Si c'est une vidéo interactive, compléter la structure
            if (strpos($library, 'InteractiveVideo') !== false) {
                $element['action']['params'] = $this->completeInteractiveVideoParams($element['action']['params'] ?? []);
            }
        }
        
        return $element;
    }
    
    /**
     * Complète les paramètres d'une vidéo interactive
     */
    private function completeInteractiveVideoParams($params) {
        $iv = $params['interactiveVideo'] ?? [];
        
        // Compléter la structure vidéo
        if (!isset($iv['video'])) $iv['video'] = ['files' => []];
        if (!isset($iv['video']['startScreenOptions'])) {
            $iv['video']['startScreenOptions'] = [
                'title' => 'Vidéo interactive',
                'hideStartTitle' => false
            ];
        }
        if (!isset($iv['video']['textTracks'])) {
            $iv['video']['textTracks'] = [
                'videoTrack' => [
                    ['label' => 'Sous-titres', 'kind' => 'subtitles', 'srcLang' => 'fr']
                ]
            ];
        }
        
        // Ajouter copyright aux fichiers vidéo
        if (isset($iv['video']['files'])) {
            foreach ($iv['video']['files'] as &$file) {
                if (!isset($file['copyright'])) {
                    $file['copyright'] = ['license' => 'U'];
                }
            }
        }
        
        // Compléter les assets
        if (!isset($iv['assets'])) $iv['assets'] = [];
        if (!isset($iv['assets']['interactions'])) $iv['assets']['interactions'] = [];
        if (!isset($iv['assets']['bookmarks'])) $iv['assets']['bookmarks'] = [];
        if (!isset($iv['assets']['endscreens'])) $iv['assets']['endscreens'] = [];
        
        // Compléter chaque interaction
        foreach ($iv['assets']['interactions'] as &$inter) {
            $inter = $this->completeVideoInteraction($inter);
        }
        
        // Ajouter summary si manquant
        if (!isset($iv['summary'])) {
            $iv['summary'] = [
                'task' => [
                    'library' => 'H5P.Summary 1.10',
                    'params' => [
                        'intro' => 'Choisissez l\'affirmation exacte.',
                        'summaries' => [['subContentId' => $this->generateUUID(), 'tip' => '']],
                        'overallFeedback' => [['from' => 0, 'to' => 100]],
                        'solvedLabel' => 'Progression :',
                        'scoreLabel' => 'Erreurs :',
                        'resultLabel' => 'Votre résultat :',
                        'labelCorrect' => 'Correct.',
                        'labelIncorrect' => 'Incorrect! Please try again.',
                        'alternativeIncorrectLabel' => 'Incorrect',
                        'labelCorrectAnswers' => 'Réponses correctes.',
                        'tipButtonLabel' => 'Montrer l\'indice',
                        'scoreBarLabel' => 'Vous avez :num points sur un total de :total',
                        'progressText' => 'Progression de :num sur :total'
                    ],
                    'subContentId' => $this->generateUUID(),
                    'metadata' => [
                        'contentType' => 'Summary',
                        'license' => 'U',
                        'title' => 'Sans titre Summary',
                        'authors' => [],
                        'changes' => []
                    ]
                ],
                'displayAt' => 3
            ];
        }
        
        $params['interactiveVideo'] = $iv;
        
        // Ajouter override si manquant
        if (!isset($params['override'])) {
            $params['override'] = [
                'autoplay' => false,
                'loop' => false,
                'showBookmarksmenuOnLoad' => false,
                'showRewind10' => false,
                'preventSkippingMode' => 'none',
                'deactivateSound' => false
            ];
        }
        
        // Ajouter l10n pour la vidéo interactive si manquant
        if (!isset($params['l10n'])) {
            $params['l10n'] = [
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
                'endcardInformationNoAnswers' => 'Vous n\'avez répondu à aucune question.',
                'endcardInformationMustHaveAnswer' => 'Vous devez répondre à au moins une question avant de pouvoir soumettre vos réponses.',
                'endcardSubmitButton' => 'Remettre vos réponses',
                'endcardSubmitMessage' => 'Vos réponses ont été remises !',
                'endcardTableRowAnswered' => 'Questions auxquelles vous avez répondu',
                'endcardTableRowScore' => 'Score',
                'endcardAnsweredScore' => 'Réponses',
                'endCardTableRowSummaryWithScore' => 'Vous avez obtenu @score sur @points pour la question @question à @minutes:@seconds.',
                'endCardTableRowSummaryWithoutScore' => 'Vous avez répondu à @question à @minutes:@seconds.',
                'videoProgressBar' => 'Video progress'
            ];
        }
        
        return $params;
    }
    
    /**
     * Complète une interaction vidéo avec toutes les propriétés requises
     */
    private function completeVideoInteraction($inter) {
        $library = $inter['action']['library'] ?? 'H5P.Text 1.1';
        $libraryName = str_replace('H5P.', '', explode(' ', $library)[0]);
        
        // Propriétés de base
        if (!isset($inter['libraryTitle'])) $inter['libraryTitle'] = $libraryName;
        if (!isset($inter['pause'])) $inter['pause'] = true;
        if (!isset($inter['displayType'])) $inter['displayType'] = 'poster';
        if (!isset($inter['buttonOnMobile'])) $inter['buttonOnMobile'] = false;
        
        // Visuals
        if (!isset($inter['visuals'])) {
            $inter['visuals'] = [
                'backgroundColor' => 'rgba(255, 255, 255, 0.9)',
                'boxShadow' => true
            ];
        }
        
        // Goto
        if (!isset($inter['goto'])) {
            $inter['goto'] = [
                'url' => ['protocol' => 'http://'],
                'visualize' => false,
                'type' => ''
            ];
        }
        
        // Label (utiliser le label existant ou vide)
        if (!isset($inter['label'])) $inter['label'] = '';
        
        // Compléter l'action
        if (isset($inter['action'])) {
            if (!isset($inter['action']['subContentId'])) {
                $inter['action']['subContentId'] = $this->generateUUID();
            }
            if (!isset($inter['action']['metadata'])) {
                $inter['action']['metadata'] = [
                    'contentType' => $libraryName,
                    'license' => 'U',
                    'title' => 'Sans titre ' . $libraryName,
                    'authors' => [],
                    'changes' => []
                ];
            }
        }
        
        return $inter;
    }
    
    /**
     * Génère un UUID v4
     */
    private function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    private function buildInteractiveVideo($content) {
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
            return $result;
        }
        
        $videoUrl = $content['video']['url'] ?? '';
        $interactions = $content['video']['interactions'] ?? [];
        
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
            'endcardInformationNoAnswers' => 'Vous n\'avez répondu à aucune question.',
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
                    'intro' => 'Choisissez l\'affirmation exacte.',
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
                    'tipButtonLabel' => 'Montrer l\'indice',
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
    
    private function buildQuestionSet($content) {
        return [
            'introPage' => [
                'showIntroPage' => false,
                'title' => '',
                'introduction' => ''
            ],
            'progressType' => 'dots',
            'passPercentage' => 50,
            'questions' => $content['questions'] ?? [],
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
    
    private function buildMultiChoice($content) {
        // Wrapper la question en HTML si nécessaire
        $question = $content['question'] ?? '<p>Question ?</p>';
        if (!empty($question) && strpos($question, '<') === false) {
            $question = '<p>' . htmlspecialchars($question) . '</p>';
        }
        
        // Enrichir les réponses
        $answers = $content['answers'] ?? [];
        foreach ($answers as &$answer) {
            if (!isset($answer['tipsAndFeedback'])) {
                $answer['tipsAndFeedback'] = [
                    'tip' => '',
                    'chosenFeedback' => '',
                    'notChosenFeedback' => ''
                ];
            }
            if (isset($answer['text']) && strpos($answer['text'], '<') === false) {
                $answer['text'] = '<div>' . htmlspecialchars($answer['text']) . '</div>';
            }
        }
        unset($answer);
        
        return [
            'question' => $question,
            'answers' => $answers,
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
                'showScorePoints' => true
            ],
            'UI' => [
                'checkAnswerButton' => 'Vérifier',
                'showSolutionButton' => 'Voir la réponse',
                'tryAgainButton' => 'Réessayer',
                'tipsLabel' => 'Voir un indice',
                'scoreBarLabel' => 'Vous avez obtenu :score sur :total points',
                'tipAvailable' => 'Indice disponible',
                'feedbackAvailable' => 'Commentaire disponible',
                'readFeedback' => 'Lire le commentaire',
                'wrongAnswer' => 'Mauvaise réponse',
                'correctAnswer' => 'Bonne réponse',
                'shouldCheck' => 'Aurait dû être coché',
                'shouldNotCheck' => 'N\'aurait pas dû être coché',
                'noInput' => 'Veuillez répondre avant de voir la solution'
            ],
            'confirmCheck' => [
                'header' => 'Terminer?',
                'body' => 'Êtes-vous sûr de vouloir terminer?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Terminer'
            ],
            'confirmRetry' => [
                'header' => 'Réessayer?',
                'body' => 'Êtes-vous sûr de vouloir réessayer?',
                'cancelLabel' => 'Annuler',
                'confirmLabel' => 'Confirmer'
            ],
            'media' => ['type' => []],
            'overallFeedback' => [
                ['from' => 0, 'to' => 100, 'feedback' => '']
            ]
        ];
    }
    
    private function buildTrueFalse($content) {
        // Wrapper la question en HTML si nécessaire
        $question = $content['question'] ?? '<p>Affirmation ?</p>';
        if (!empty($question) && strpos($question, '<') === false) {
            $question = '<p>' . htmlspecialchars($question) . '</p>';
        }
        
        return [
            'media' => [
                'type' => ['params' => (object)[]],
                'disableImageZooming' => false
            ],
            'correct' => $content['correct'] ?? 'true',
            'behaviour' => [
                'enableRetry' => true,
                'enableSolutionsButton' => true,
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
    
    private function buildBlanks($content) {
        // S'assurer que questions est un tableau avec le bon format
        $questions = $content['questions'] ?? [];
        if (empty($questions) && isset($content['text']) && !empty($content['text'])) {
            $text = $content['text'];
            if (strpos($text, '<p>') === false) {
                $text = '<p>' . $text . '</p>';
            }
            $questions = [$text];
        }
        
        return [
            'media' => [
                'disableImageZooming' => false,
                'type' => ['params' => (object)[]]
            ],
            'text' => $content['text'] ?? 'Complétez les mots manquants',
            'overallFeedback' => [
                ['from' => 0, 'to' => 100]
            ],
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
                'enableRetry' => true,
                'enableSolutionsButton' => false,
                'enableCheckButton' => true,
                'autoCheck' => false,
                'caseSensitive' => false,
                'showSolutionsRequiresInput' => false,
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
    
    private function buildDialogCards($content) {
        // Enrichir chaque carte
        $dialogs = $content['dialogs'] ?? [];
        foreach ($dialogs as &$dialog) {
            if (isset($dialog['text']) && strpos($dialog['text'], '<') === false) {
                $dialog['text'] = '<p>' . htmlspecialchars($dialog['text']) . '</p>';
            }
            if (isset($dialog['answer']) && strpos($dialog['answer'], '<') === false) {
                $dialog['answer'] = '<p>' . htmlspecialchars($dialog['answer']) . '</p>';
            }
            if (!isset($dialog['tips'])) {
                $dialog['tips'] = (object)[];
            }
        }
        unset($dialog);
        
        return [
            'mode' => 'normal',
            'dialogs' => $dialogs,
            'behaviour' => [
                'enableRetry' => true,
                'disableBackwardsNavigation' => false,
                'scaleTextNotCard' => false,
                'randomCards' => false,
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
    
    private function buildDragText($content) {
        return [
            'media' => [
                'type' => ['params' => (object)[]],
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
            'tipLabel' => 'Montrer l\'indice',
            'correctText' => 'Correct !',
            'incorrectText' => 'Incorrect !',
            'resetDropTitle' => 'Reparamétrer le déplacement',
            'resetDropDescription' => 'Etes-vous certain de vouloir reparamétrer cet élément déplaçable ?',
            'grabbed' => 'L\'élément déplaçable est saisi.',
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
    
    private function buildFindTheWords($content) {
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
    
    private function buildThreeImage($content) {
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
                // S'assurer que subContentId existe
                if (isset($inter['action']) && !isset($inter['action']['subContentId'])) {
                    $inter['action']['subContentId'] = $this->generateUUID();
                }
                if (isset($inter['action']) && !isset($inter['action']['metadata'])) {
                    $inter['action']['metadata'] = [
                        'contentType' => 'Text',
                        'license' => 'U',
                        'title' => 'Sans titre',
                        'authors' => [],
                        'changes' => []
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
    
    private function resolveEditorFile($url, $filename, $contextId, $component, $filearea, $itemId) {
        $uploadedFile = $this->resolveFileToLocal($url);
        
        if (!$uploadedFile || !file_exists($uploadedFile)) return null;
        
        $fileHash = sha1_file($uploadedFile);
        $fileSize = filesize($uploadedFile);
        $fileMime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $fileMime = finfo_file($finfo, $uploadedFile) ?: 'application/octet-stream';
        }
        
        $prefix = substr($fileHash, 0, 2);
        $destDir = $this->filesDir . '/' . $prefix;
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        copy($uploadedFile, $destDir . '/' . $fileHash);
        
        $fileId = crc32($fileHash . $filearea . $itemId) & 0x7FFFFFFF;
        
        $this->exportFiles[] = [
            'id' => $fileId,
            'contenthash' => $fileHash,
            'contextid' => $contextId,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemId,
            'filepath' => '/',
            'filename' => $filename,
            'filesize' => $fileSize,
            'mimetype' => $fileMime
        ];
        
        // Ajouter aussi l'entrée "." (répertoire) pour être conforme au format MBZ
        $dotHash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $this->exportFiles[] = [
            'id' => $fileId + 1,
            'contenthash' => $dotHash,
            'contextid' => $contextId,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemId,
            'filepath' => '/',
            'filename' => '.',
            'filesize' => 0,
            'mimetype' => '$@NULL@$'
        ];
        
        return ['hash' => $fileHash, 'name' => $filename, 'size' => $fileSize, 'mime' => $fileMime];
    }

    /**
     * Extrait les images du questiontext, les copie dans l'archive et remplace
     * leurs URLs par @@PLUGINFILE@@/filename pour la conformité Elea.
     */
    private function extractQuestiontextImages($html, $contextId, $questionId) {
        if (empty($html)) return $html;

        $dotAdded = false;

        // Pattern 1 : URLs serve_upload — tolère un param session avant/après file=
        // et le consomme.
        $html = preg_replace_callback(
            '/api\/editor_api\.php\?action=serve_upload(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?&(?:amp;)?file=([^"\'<>\s&]+)(?:&(?:amp;)?session=[a-zA-Z0-9_-]+)?/',
            function($m) use ($contextId, $questionId, &$dotAdded) {
                $localFile = $this->resolveFileToLocal('file=' . $m[1]);
                if (!$localFile || !file_exists($localFile)) return $m[0];
                $filename = urldecode($m[1]);
                return $this->_addQuestiontextFile($localFile, $filename, $contextId, $questionId, $dotAdded);
            },
            $html
        );

        // Pattern 2 : URLs Drive
        $html = preg_replace_callback(
            '#https://lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#',
            function($m) use ($contextId, $questionId, &$dotAdded) {
                $localFile = $this->resolveFileToLocal($m[0]);
                if (!$localFile || !file_exists($localFile)) return $m[0];
                $filename = basename($localFile);
                return $this->_addQuestiontextFile($localFile, $filename, $contextId, $questionId, $dotAdded);
            },
            $html
        );

        return $html;
    }

    private function _addQuestiontextFile($localFile, $filename, $contextId, $questionId, &$dotAdded) {
        $hash = sha1_file($localFile);
        $size = filesize($localFile);
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $localFile) ?: 'application/octet-stream';
        }
        $prefix  = substr($hash, 0, 2);
        $destDir = $this->filesDir . '/' . $prefix;
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        copy($localFile, $destDir . '/' . $hash);

        $fileId = crc32($hash . 'questiontext' . $questionId) & 0x7FFFFFFF;
        $this->exportFiles[] = [
            'id'          => $fileId,
            'contenthash' => $hash,
            'contextid'   => $contextId,
            'component'   => 'question',
            'filearea'    => 'questiontext',
            'itemid'      => $questionId,
            'filepath'    => '/',
            'filename'    => $filename,
            'filesize'    => $size,
            'mimetype'    => $mime,
        ];

        // Une seule entrée répertoire par questionId
        if (!$dotAdded) {
            $dotId = crc32('dot_questiontext_' . $questionId) & 0x7FFFFFFF;
            $this->exportFiles[] = [
                'id'          => $dotId,
                'contenthash' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'contextid'   => $contextId,
                'component'   => 'question',
                'filearea'    => 'questiontext',
                'itemid'      => $questionId,
                'filepath'    => '/',
                'filename'    => '.',
                'filesize'    => 0,
                'mimetype'    => '$@NULL@$',
            ];
            $dotAdded = true;
        }

        return '@@PLUGINFILE@@/' . rawurlencode($filename);
    }

    private function generateQuestionsXml() {
        if (empty($this->quizQuestions)) {
            file_put_contents($this->exportDir . '/questions.xml', '<?xml version="1.0" encoding="UTF-8"?>
<question_categories>
</question_categories>');
            return;
        }
        
        $now = time();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<question_categories>';
        
        foreach ($this->quizQuestions as $q) {
            // IDs des 4 catégories (structure Éléa de référence)
            $topCourseCatId = $q['bankEntryId'] + 2000;
            $defaultCourseCatId = $q['bankEntryId'] + 1000;
            $topQuizCatId = $q['bankEntryId'] + 4000;
            $defaultQuizCatId = $q['bankEntryId'] + 3000;
            $activityId = $q['quizContextId'] - 10000;
            
            // 1. Catégorie top course-level (parent=0, vide)
            $xml .= '
  <question_category id="' . $topCourseCatId . '">
    <n>top</n>
    <contextid>' . $q['courseContextId'] . '</contextid>
    <contextlevel>50</contextlevel>
    <contextinstanceid>1</contextinstanceid>
    <info></info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . $now . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>0</parent>
    <sortorder>0</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
    </question_bank_entries>
  </question_category>';
            
            // 2. Catégorie par défaut course-level (parent=topCourseCatId, CONTIENT la question)
            $xml .= '
  <question_category id="' . $defaultCourseCatId . '">
    <n>Défaut pour cours</n>
    <contextid>' . $q['courseContextId'] . '</contextid>
    <contextlevel>50</contextlevel>
    <contextinstanceid>1</contextinstanceid>
    <info>La catégorie par défaut pour les questions partagées dans le contexte du cours.</info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . $now . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>' . $topCourseCatId . '</parent>
    <sortorder>999</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
      <question_bank_entry id="' . $q['bankEntryId'] . '">
        <questioncategoryid>' . $defaultCourseCatId . '</questioncategoryid>
        <idnumber>$@NULL@$</idnumber>
        <ownerid>1</ownerid>
        <question_version>
          <question_versions id="' . ($q['questionId'] + 100) . '">
            <version>1</version>
            <status>ready</status>
            <questions>
              <question id="' . $q['questionId'] . '">
                <parent>0</parent>
                <n>' . htmlspecialchars($q['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</n>
                <questiontext>' . htmlspecialchars($q['questiontext'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</questiontext>
                <questiontextformat>1</questiontextformat>
                <generalfeedback></generalfeedback>
                <generalfeedbackformat>1</generalfeedbackformat>
                <defaultmark>' . number_format($q['defaultmark'], 7, '.', '') . '</defaultmark>
                <penalty>0.3333333</penalty>
                <qtype>' . $q['qtype'] . '</qtype>
                <length>1</length>
                <stamp>elea-secours+' . $now . '+' . bin2hex(random_bytes(3)) . '</stamp>
                <timecreated>' . $now . '</timecreated>
                <timemodified>' . $now . '</timemodified>
                <createdby>1</createdby>
                <modifiedby>1</modifiedby>';
            
            if ($q['qtype'] === 'ddimageortext') {
                $xml .= '
                <plugin_qtype_ddimageortext_question>
                  <ddimageortext id="' . ($q['questionId'] + 200) . '">
                    <shuffleanswers>' . ($q['shuffleanswers'] ?? 1) . '</shuffleanswers>
                    <correctfeedback>&lt;p&gt;Votre réponse est correcte.&lt;/p&gt;</correctfeedback>
                    <correctfeedbackformat>1</correctfeedbackformat>
                    <partiallycorrectfeedback>&lt;p&gt;Votre réponse est partiellement correcte.&lt;/p&gt;</partiallycorrectfeedback>
                    <partiallycorrectfeedbackformat>1</partiallycorrectfeedbackformat>
                    <incorrectfeedback>&lt;p&gt;Votre réponse est incorrecte.&lt;/p&gt;</incorrectfeedback>
                    <incorrectfeedbackformat>1</incorrectfeedbackformat>
                    <shownumcorrect>1</shownumcorrect>
                  </ddimageortext>
                  <drags>';
                
                $dragId = $q['questionId'] + 300;
                foreach ($q['drags'] as $drag) {
                    $xml .= '
                    <drag id="' . ($dragId++) . '">
                      <no>' . ($drag['no'] ?? 1) . '</no>
                      <draggroup>' . ($drag['group'] ?? 1) . '</draggroup>
                      <infinite>' . (($drag['infinite'] ?? false) ? 1 : 0) . '</infinite>
                      <label>' . htmlspecialchars($drag['label'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</label>
                    </drag>';
                }
                
                $xml .= '
                  </drags>
                  <drops>';
                
                $dropId = $q['questionId'] + 500;
                foreach ($q['drops'] as $drop) {
                    $xml .= '
                    <drop id="' . ($dropId++) . '">
                      <no>' . ($drop['no'] ?? 1) . '</no>
                      <xleft>' . round($drop['x'] ?? 0) . '</xleft>
                      <ytop>' . round($drop['y'] ?? 0) . '</ytop>
                      <choice>' . ($drop['choice'] ?? 0) . '</choice>
                      <label>' . htmlspecialchars($drop['label'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</label>
                    </drop>';
                }
                
                $xml .= '
                  </drops>
                </plugin_qtype_ddimageortext_question>';
            }
            
            $xml .= '
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
      </question_bank_entry>
    </question_bank_entries>
  </question_category>';
            
            // 3. Catégorie top quiz-level (parent=0, vide)
            $xml .= '
  <question_category id="' . $topQuizCatId . '">
    <n>top</n>
    <contextid>' . $q['quizContextId'] . '</contextid>
    <contextlevel>70</contextlevel>
    <contextinstanceid>' . $activityId . '</contextinstanceid>
    <info></info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . $now . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>0</parent>
    <sortorder>0</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
    </question_bank_entries>
  </question_category>';
            
            // 4. Catégorie par défaut quiz-level (parent=topQuizCatId, vide)
            $xml .= '
  <question_category id="' . $defaultQuizCatId . '">
    <n>Défaut pour quiz</n>
    <contextid>' . $q['quizContextId'] . '</contextid>
    <contextlevel>70</contextlevel>
    <contextinstanceid>' . $activityId . '</contextinstanceid>
    <info>La catégorie par défaut pour les questions partagées dans le contexte du quiz.</info>
    <infoformat>0</infoformat>
    <stamp>elea-secours+' . $now . '+' . bin2hex(random_bytes(3)) . '</stamp>
    <parent>' . $topQuizCatId . '</parent>
    <sortorder>999</sortorder>
    <idnumber>$@NULL@$</idnumber>
    <question_bank_entries>
    </question_bank_entries>
  </question_category>';
        }
        
        $xml .= '
</question_categories>';
        
        file_put_contents($this->exportDir . '/questions.xml', $xml);
    }
    
    private function generateFilesXml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<files>';
        
        foreach ($this->exportFiles as $f) {
            $xml .= '
  <file id="' . $f['id'] . '">
    <contenthash>' . $f['contenthash'] . '</contenthash>
    <contextid>' . $f['contextid'] . '</contextid>
    <component>' . $f['component'] . '</component>
    <filearea>' . $f['filearea'] . '</filearea>
    <itemid>' . $f['itemid'] . '</itemid>
    <filepath>' . htmlspecialchars($f['filepath']) . '</filepath>
    <filename>' . htmlspecialchars($f['filename']) . '</filename>
    <userid>1</userid>
    <filesize>' . $f['filesize'] . '</filesize>
    <mimetype>' . $f['mimetype'] . '</mimetype>
    <status>0</status>
    <timecreated>' . time() . '</timecreated>
    <timemodified>' . time() . '</timemodified>
    <source>' . htmlspecialchars($f['filename'] === '.' ? '$@NULL@$' : $f['filename']) . '</source>
    <author>$@NULL@$</author>
    <license>unknown</license>
    <sortorder>0</sortorder>
    <repositorytype>$@NULL@$</repositorytype>
    <repositoryid>$@NULL@$</repositoryid>
    <reference>$@NULL@$</reference>
  </file>';
        }
        
        $xml .= '
</files>';
        
        file_put_contents($this->exportDir . '/files.xml', $xml);
    }
    
    private function createZip($mbzPath) {
        $zip = new ZipArchive();
        if ($zip->open($mbzPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Impossible de créer le fichier ZIP');
        }
        
        $this->addFolderToZip($zip, $this->exportDir, '');
        $zip->close();
    }
    
    private function addFolderToZip($zip, $folder, $zipPath) {
        $files = scandir($folder);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $folder . '/' . $file;
            $zipFilePath = $zipPath ? $zipPath . '/' . $file : $file;
            
            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipFilePath);
                $this->addFolderToZip($zip, $filePath, $zipFilePath);
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }
    
    private function slugify($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        return empty($text) ? 'n-a' : $text;
    }
}

/**
 * Liste les cours disponibles sur Google Drive
 */
function listDriveCourses() {
    try {
        require_once __DIR__ . '/../includes/GoogleDriveLoader.php';
        
        $driveLoader = new GoogleDriveLoader();
        
        if (!$driveLoader->isConfigured()) {
            echo json_encode([
                'success' => false,
                'error' => 'Google Drive non configuré'
            ]);
            return;
        }
        
        $coursesByFolder = $driveLoader->listCoursesByFolder();
        
        echo json_encode([
            'success' => true,
            'folders' => $coursesByFolder
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Erreur: ' . $e->getMessage()
        ]);
    }
}

/**
 * Parse un fichier MBZ depuis Google Drive pour import
 */
function parseDriveMbz($input) {
    $gdriveId = $input['gdrive_id'] ?? '';
    
    if (empty($gdriveId)) {
        echo json_encode(['error' => 'ID Google Drive manquant']);
        return;
    }
    
    // Vérifier espace et lock avant extraction
    $check = checkExtractionStatus();
    if (!$check['can_extract']) {
        echo json_encode(['error' => $check['message'] ?? 'Serveur plein ou chargement en cours']);
        return;
    }
    
    try {
        require_once __DIR__ . '/../includes/GoogleDriveLoader.php';
        require_once __DIR__ . '/../includes/MbzParser.php';
        
        $driveLoader = new GoogleDriveLoader();

        // Barre de progression de l'éditeur. Le téléchargement + la lecture se font dans
        // GoogleDriveLoader (avec son propre cache) : on ne peut jalonner que ses bornes.
        $progressId = $input['progressId'] ?? '';
        if ($progressId !== '') progressSet($progressId, 5, 'Téléchargement depuis le Drive…');

        // Utiliser le cache partagé (viewer + éditeur)
        // Si le cours est déjà extrait sur le serveur, ça sera instantané
        $courseData = $driveLoader->loadAndParseCourse($gdriveId);
        if (!$courseData) {
            if ($progressId !== '') progressClear($progressId);
            echo json_encode(['error' => 'Impossible de charger le cours depuis Google Drive']);
            return;
        }
        if ($progressId !== '') progressSet($progressId, 60, 'Cours lu, copie des fichiers…');
        
        $extractDir = $courseData['tmp_path'] ?? '';
        $courseInfo = $courseData['course'] ?? [];
        $sections = $courseData['sections'] ?? [];
        $mbzFiles = $courseData['files'] ?? [];
        
        // Dossier pour les fichiers de l'éditeur (namespacé par session)
        $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['sessionId'] ?? '');
        if ($safeSessionId) {
            $uploadDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
        } else {
            $uploadDir = CACHE_DIR . '/editor_uploads';
        }
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Copier les fichiers et créer un mapping ancien chemin -> nouveau URL
        $fileMapping = [];
        $hashMapping = []; // hash -> {url, originalName}
        $totalFichiers = count($mbzFiles);
        $fichiersFaits = 0;
        foreach ($mbzFiles as $file) {
            if ($progressId !== '' && ($fichiersFaits % 5) === 0) {
                progressSet($progressId,
                    $totalFichiers ? 62 + 30 * ($fichiersFaits / $totalFichiers) : 62,
                    'Fichier ' . ($fichiersFaits + 1) . '/' . $totalFichiers . '…');
            }
            $fichiersFaits++;

            if (empty($file['hash']) || $file['filename'] === '.') continue;

            $prefix = substr($file['hash'], 0, 2);
            $srcPath = $extractDir . '/files/' . $prefix . '/' . $file['hash'];

            if (!file_exists($srcPath)) continue;

            $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
            if (empty($ext)) {
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                           'image/webp' => 'webp', 'video/mp4' => 'mp4'];
                $ext = $extMap[$file['mimetype']] ?? 'bin';
            }

            $newFilename = 'import_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . '/' . $newFilename;
            
            if (copy($srcPath, $destPath)) {
                $newUrl = 'api/editor_api.php?action=serve_upload&file=' . urlencode($newFilename);
                if ($safeSessionId) $newUrl .= '&session=' . urlencode($safeSessionId);
                
                // Mapping par hash (pour assign et lookups directs)
                $hashMapping[$file['hash']] = ['url' => $newUrl, 'name' => $file['filename']];
                
                $filepath = trim($file['filepath'], '/');
                $filename = $file['filename'];
                $fullPath = $filepath ? $filepath . '/' . $filename : $filename;
                
                $fileMapping[$fullPath] = $newUrl;
                $fileMapping[$fullPath . '#tmp'] = $newUrl;
                $fileMapping['/' . $fullPath] = $newUrl;
                $fileMapping[$filename] = $newUrl;
            }
        }
        
        // Convertir en format éditeur
        $editorSections = [];
        foreach ($sections as $section) {
            $editorSection = [
                'id' => 'import_' . ($section['id'] ?? uniqid()),
                'name' => $section['name'] ?? 'Section',
                'summary' => strip_tags($section['summary'] ?? ''),
                'visible' => ($section['visible'] ?? 1) ? true : false,
                'activities' => []
            ];
            
            foreach ($section['activities'] ?? [] as $activity) {
                $actType = $activity['type'] ?? 'hvp';
                
                if ($actType === 'mapmodules') {
                    $mapImage = null;
                    $mapName = $activity['name'] ?? '';
                    if (stripos($mapName, 'Carte personnalisée') !== false) {
                        foreach ($mbzFiles as $mf) {
                            if (($mf['component'] ?? '') === 'mod_mapmodules' 
                                && ($mf['filearea'] ?? '') === 'maps'
                                && ($mf['filename'] ?? '.') !== '.') {
                                $fn = $mf['filename'];
                                if (isset($fileMapping[$fn])) $mapImage = $fileMapping[$fn];
                                break;
                            }
                        }
                    }
                    
                    $editorSection['activities'][] = [
                        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type' => 'mapmodules',
                        'name' => $activity['name'] ?? 'Carte de progression',
                        'mapPath' => $activity['mapPath'] ?? $activity['path'] ?? '',
                        'mapImage' => $mapImage,
                        'descriptionHeader' => $activity['descriptionHeader'] ?? '',
                        'descriptionFooter' => $activity['descriptionFooter'] ?? '',
                        'iconset' => $activity['iconset'] ?? 4,
                        'buttonWidth' => $activity['buttonWidth'] ?? 50,
                        'targetsection' => $activity['targetsection'] ?? '666',
                    ];
                    continue;
                }
                
                if ($actType === 'assign') {
                    $assignContentFiles = $activity['content_files'] ?? [];
                    $assignFiles = [];
                    foreach ($assignContentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null; $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) { $fUrl = $hashMapping[$cf['hash']]['url']; }
                            elseif (isset($fileMapping[$fName])) { $fUrl = $fileMapping[$fName]; }
                            else { foreach ($fileMapping as $k => $u) { if (basename($k) === $fName) { $fUrl = $u; break; } } }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                        }
                    }
                    if (empty($assignFiles)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fn = $mainFile['filename'];
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? $fileMapping[$fn] ?? null;
                            if (!$fUrl) { foreach ($fileMapping as $k => $u) { if (basename($k) === $fn) { $fUrl = $u; break; } } }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fn];
                        }
                    }
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) { $intro = resolvePluginfileUrls($intro, $fileMapping); }
                    $editorSection['activities'][] = [
                        'id'    => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type'  => 'assign',
                        'name'  => $activity['name'] ?? 'Travail à déposer',
                        'files' => $assignFiles,
                        'intro' => $intro,
                    ];
                    continue;
                }
                
                // Ressource (fichiers à distribuer)
                if ($actType === 'resource') {
                    $contentFiles = $activity['content_files'] ?? [];
                    $files = [];
                    
                    foreach ($contentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null;
                            $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) {
                                $fUrl = $hashMapping[$cf['hash']]['url'];
                            } elseif (isset($fileMapping[$fName])) {
                                $fUrl = $fileMapping[$fName];
                            }
                            if ($fUrl) {
                                $files[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                            }
                        }
                    }
                    
                    // Fallback: main_file seul
                    if (empty($files)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? ($fileMapping[$mainFile['filename']] ?? null);
                            if ($fUrl) {
                                $files[] = ['fileUrl' => $fUrl, 'fileName' => $mainFile['filename']];
                            }
                        }
                    }
                    
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) {
                        $intro = resolvePluginfileUrls($intro, $fileMapping);
                    }
                    
                    $editorSection['activities'][] = [
                        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type' => 'resource',
                        'name' => $activity['name'] ?? 'Fichiers à distribuer',
                        'files' => $files,
                        'intro' => $intro,
                    ];
                    continue;
                }
                
                // Quiz : ddimageortext ou évaluation standard (multichoice, etc.)
                if ($actType === 'quiz') {
                    // Toute quiz importée devient une évaluation (QuestionSet) — standalone DDI
                    // n'est plus distinguable dans l'MBZ d'une évaluation à 1 question. Unifier simplifie
                    // l'aller-retour et le rendu.
                    $quizEditorAct = convertStandardQuizForEditor($activity, $fileMapping, $mbzFiles, $hashMapping, $extractDir ?? '');
                    if ($quizEditorAct) {
                        $editorSection['activities'][] = $quizEditorAct;
                    }
                    continue;
                }

                // Étiquette / Page : modules de texte, jamais du H5P
                if ($actType === 'label' || $actType === 'page') {
                    $editorSection['activities'][] = buildTextModuleActivity(
                        $activity, $fileMapping,
                        'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()));
                    continue;
                }

                $h5pType = detectH5pType($activity);
                $h5pContent = [];

                if (isset($activity['content'])) {
                    $h5pContent = $activity['content'];
                } elseif (isset($activity['json_content'])) {
                    $h5pContent = json_decode($activity['json_content'], true) ?: [];
                }

                $h5pContent = replaceFilePathsInContent($h5pContent, $fileMapping);

                $editorActivity = [
                    'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                    'type' => $activity['type'] ?? 'hvp',
                    'name' => $activity['name'] ?? 'Activité',
                    'h5pType' => $h5pType,
                    // La consigne affichée AU-DESSUS de l'activité (champ `intro` de Moodle).
                    // Sans elle, le « Dans le schéma ci-dessus, localisez… » d'un
                    // Trouver-les-zones était perdu dès l'import, donc aussi à l'export.
                    'intro' => resolvePluginfileUrls($activity['intro'] ?? '', $fileMapping),
                    'content' => $h5pContent
                ];
                $editorSection['activities'][] = $editorActivity;
            }
            
            // Ajouter la visibilité des parcours
            $sectionVisible = ($section['visible'] ?? 1) ? true : false;
            foreach ($editorSection['activities'] as &$edAct) {
                // Trouver l'activité source pour la visibilité
                foreach ($section['activities'] ?? [] as $srcAct) {
                    $srcId = 'import_' . ($srcAct['module_id'] ?? $srcAct['id'] ?? '');
                    if ($srcId === ($edAct['id'] ?? '')) {
                        $actVisible = ($srcAct['visible'] ?? 1) ? true : false;
                        $actVisibleOld = ($srcAct['visibleold'] ?? 1) ? true : false;
                        // Si section cachée: visible=false est hérité, visibleold donne la vraie valeur
                        // Si section visible: visible donne directement la valeur
                        if (!$sectionVisible) {
                            $edAct['visible'] = $actVisibleOld;
                        } else {
                            $edAct['visible'] = $actVisible;
                        }
                        break;
                    }
                }
                if (!isset($edAct['visible'])) $edAct['visible'] = true;
            }
            unset($edAct);
            $editorSections[] = $editorSection;
        }

        if ($progressId !== '') progressClear($progressId);
        echo json_encode([
            'success' => true,
            'course' => [
                'name' => $courseInfo['course_fullname'] ?? $courseInfo['fullname'] ?? $courseInfo['name'] ?? 'Cours importé',
                'shortname' => $courseInfo['shortname'] ?? 'import',
                'vignette' => findCourseVignette($mbzFiles, $hashMapping, $fileMapping),
                'sections' => $editorSections
            ]
        ]);

    } catch (Exception $e) {
        if (!empty($progressId)) progressClear($progressId);
        echo json_encode(['error' => 'Erreur de parsing: ' . $e->getMessage()]);
    }

    // Supprimer le cache d'extraction (tmp/course_{md5}/)
    // Les fichiers sont déjà copiés dans editor_uploads/{sessionId}/
    // Le viewer recréera le cache à la demande si un élève ouvre le cours
    if (!empty($extractDir) && is_dir($extractDir) && strpos($extractDir, TMP_PATH) === 0) {
        deleteDirectory($extractDir);
    }
}

/**
 * Parse un cours local temporaire pour l'éditeur
 */
function parseLocalCourse($input) {
    $courseId = $input['course_id'] ?? '';
    $progressId = $input['progressId'] ?? '';

    if (empty($courseId)) {
        echo json_encode(['error' => 'ID de cours manquant']);
        return;
    }
    if ($progressId !== '') progressSet($progressId, 5, 'Lecture du cours…');
    
    // Vérifier espace serveur (les fichiers seront copiés dans editor_uploads)
    $check = checkExtractionStatus();
    if (!$check['can_extract']) {
        echo json_encode(['error' => $check['message'] ?? 'Espace serveur insuffisant']);
        return;
    }
    
    // Sécuriser le chemin
    $courseId = basename($courseId);
    $coursePath = COURSES_PATH . '/' . $courseId;
    
    // Charger course_data : local OU Drive index
    $courseData = null;
    $fileIndex = null; // Drive file_index si disponible
    
    $dataFile = $coursePath . '/course_data.json';
    if (is_dir($coursePath) && file_exists($dataFile)) {
        $courseData = json_decode(file_get_contents($dataFile), true);
    }
    
    // Fallback : Drive index (cours drive-only ou fichiers locaux nettoyés)
    $tempDataFile = DRIVE_INDEX_DIR . '/temp_' . $courseId . '_data.json';
    $tempIndexFile = DRIVE_INDEX_DIR . '/temp_' . $courseId . '.json';
    if (!$courseData && file_exists($tempDataFile)) {
        $courseData = json_decode(file_get_contents($tempDataFile), true);
    }
    if (file_exists($tempIndexFile)) {
        $fileIndex = json_decode(file_get_contents($tempIndexFile), true);
    }
    
    if (!$courseData) {
        echo json_encode(['error' => 'Cours non trouvé']);
        return;
    }
    
    try {
        $courseInfo = $courseData['course'] ?? [];
        $sections = $courseData['sections'] ?? [];
        $mbzFiles = $courseData['files'] ?? [];
        
        // Dossier pour les fichiers permanents de l'éditeur (namespacé par session)
        $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['sessionId'] ?? '');
        if ($safeSessionId) {
            $uploadDir = CACHE_DIR . '/editor_uploads/' . $safeSessionId;
        } else {
            $uploadDir = CACHE_DIR . '/editor_uploads';
        }
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Base URL pour les fichiers uploadés
        $baseUrl = dirname(dirname($_SERVER['SCRIPT_NAME']));
        
        // Copier les fichiers et créer un mapping ancien chemin -> nouveau URL
        $fileMapping = [];
        $hashMapping = []; // hash -> {url, originalName}
        $totalFichiers = count($mbzFiles);
        $fichiersFaits = 0;
        foreach ($mbzFiles as $file) {
            if ($progressId !== '' && ($fichiersFaits % 5) === 0) {
                progressSet($progressId,
                    $totalFichiers ? 10 + 80 * ($fichiersFaits / $totalFichiers) : 10,
                    'Fichier ' . ($fichiersFaits + 1) . '/' . $totalFichiers . '…');
            }
            $fichiersFaits++;

            if (empty($file['hash']) || $file['filename'] === '.') continue;

            // Chemin source dans l'archive extraite (local)
            $prefix = substr($file['hash'], 0, 2);
            $srcPath = $coursePath . '/files/' . $prefix . '/' . $file['hash'];
            
            $newUrl = null;
            
            if (file_exists($srcPath)) {
                // Fichier local : copier dans editor_uploads
                $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
                if (empty($ext)) {
                    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 
                               'image/webp' => 'webp', 'video/mp4' => 'mp4'];
                    $ext = $extMap[$file['mimetype']] ?? 'bin';
                }
                
                $newFilename = 'import_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destPath = $uploadDir . '/' . $newFilename;
                
                if (copy($srcPath, $destPath)) {
                    $newUrl = 'api/editor_api.php?action=serve_upload&file=' . urlencode($newFilename);
                    if ($safeSessionId) $newUrl .= '&session=' . urlencode($safeSessionId);
                }
            } elseif ($fileIndex && isset($fileIndex['files'][$file['hash']])) {
                // Fichier sur Drive : utiliser l'URL lh3 directe
                $driveId = $fileIndex['files'][$file['hash']];
                $mime = $fileIndex['mimetypes'][$file['hash']] ?? ($file['mimetype'] ?? '');
                if (str_starts_with($mime, 'image/')) {
                    $newUrl = 'https://lh3.googleusercontent.com/d/' . $driveId;
                } else {
                    $newUrl = 'https://drive.google.com/uc?id=' . $driveId . '&export=view';
                }
            }
            
            if ($newUrl) {
                $hashMapping[$file['hash']] = ['url' => $newUrl, 'name' => $file['filename']];
                
                $filepath = trim($file['filepath'] ?? '', '/');
                $filename = $file['filename'];
                $fullPath = $filepath ? $filepath . '/' . $filename : $filename;
                
                $fileMapping[$fullPath] = $newUrl;
                $fileMapping[$fullPath . '#tmp'] = $newUrl;
                $fileMapping['/' . $fullPath] = $newUrl;
                $fileMapping[$filename] = $newUrl;
            }
        }
        
        // Convertir en format éditeur (même format que parseDriveMbz)
        $editorSections = [];
        foreach ($sections as $section) {
            $editorSection = [
                'id' => 'import_' . ($section['id'] ?? uniqid()),
                'name' => $section['name'] ?? 'Section',
                'summary' => strip_tags($section['summary'] ?? ''),
                'visible' => ($section['visible'] ?? 1) ? true : false,
                'activities' => []
            ];
            
            foreach ($section['activities'] ?? [] as $activity) {
                $actType = $activity['type'] ?? 'hvp';
                
                // Carte de progression
                if ($actType === 'mapmodules') {
                    $mapName = $activity['name'] ?? '';
                    $mapImage = null;
                    
                    if (stripos($mapName, 'Carte personnalisée') !== false) {
                        foreach ($mbzFiles as $mf) {
                            if (($mf['component'] ?? '') === 'mod_mapmodules' 
                                && ($mf['filearea'] ?? '') === 'maps'
                                && ($mf['filename'] ?? '.') !== '.') {
                                $fn = $mf['filename'];
                                if (isset($fileMapping[$fn])) {
                                    $mapImage = $fileMapping[$fn];
                                } else {
                                    foreach ($fileMapping as $key => $url) {
                                        if (strpos($key, $fn) !== false) {
                                            $mapImage = $url;
                                            break;
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }
                    
                    $editorSection['activities'][] = [
                        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type' => 'mapmodules',
                        'name' => $activity['name'] ?? 'Carte de progression',
                        'mapPath' => $activity['mapPath'] ?? $activity['path'] ?? '',
                        'mapImage' => $mapImage,
                        'descriptionHeader' => $activity['descriptionHeader'] ?? '',
                        'descriptionFooter' => $activity['descriptionFooter'] ?? '',
                        'iconset' => $activity['iconset'] ?? 4,
                        'buttonWidth' => $activity['buttonWidth'] ?? 50,
                        'targetsection' => $activity['targetsection'] ?? '666',
                    ];
                    continue;
                }
                
                // Fichier à distribuer
                if ($actType === 'assign') {
                    $assignContentFiles = $activity['content_files'] ?? [];
                    $assignFiles = [];
                    foreach ($assignContentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null; $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) { $fUrl = $hashMapping[$cf['hash']]['url']; }
                            elseif (isset($fileMapping[$fName])) { $fUrl = $fileMapping[$fName]; }
                            else { foreach ($fileMapping as $k => $u) { if (basename($k) === $fName) { $fUrl = $u; break; } } }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                        }
                    }
                    if (empty($assignFiles)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fn = $mainFile['filename'];
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? $fileMapping[$fn] ?? null;
                            if (!$fUrl) { foreach ($fileMapping as $k => $u) { if (basename($k) === $fn) { $fUrl = $u; break; } } }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fn];
                        }
                    }
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) { $intro = resolvePluginfileUrls($intro, $fileMapping); }
                    $editorSection['activities'][] = [
                        'id'    => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type'  => 'assign',
                        'name'  => $activity['name'] ?? 'Travail à déposer',
                        'files' => $assignFiles,
                        'intro' => $intro,
                    ];
                    continue;
                }
                
                // Ressource (fichiers à distribuer)
                if ($actType === 'resource') {
                    $contentFiles = $activity['content_files'] ?? [];
                    $files = [];
                    foreach ($contentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null;
                            $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) {
                                $fUrl = $hashMapping[$cf['hash']]['url'];
                            } elseif (isset($fileMapping[$fName])) {
                                $fUrl = $fileMapping[$fName];
                            }
                            if ($fUrl) {
                                $files[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                            }
                        }
                    }
                    if (empty($files)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? ($fileMapping[$mainFile['filename']] ?? null);
                            if ($fUrl) {
                                $files[] = ['fileUrl' => $fUrl, 'fileName' => $mainFile['filename']];
                            }
                        }
                    }
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) {
                        $intro = resolvePluginfileUrls($intro, $fileMapping);
                    }
                    $editorSection['activities'][] = [
                        'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                        'type' => 'resource',
                        'name' => $activity['name'] ?? 'Fichiers à distribuer',
                        'files' => $files,
                        'intro' => $intro,
                    ];
                    continue;
                }
                
                // Quiz : ddimageortext ou évaluation standard (multichoice, etc.)
                if ($actType === 'quiz') {
                    // Toute quiz importée devient une évaluation (QuestionSet) — standalone DDI
                    // n'est plus distinguable dans l'MBZ d'une évaluation à 1 question. Unifier simplifie
                    // l'aller-retour et le rendu.
                    $quizEditorAct = convertStandardQuizForEditor($activity, $fileMapping, $mbzFiles, $hashMapping, $extractDir ?? '');
                    if ($quizEditorAct) {
                        $editorSection['activities'][] = $quizEditorAct;
                    }
                    continue;
                }

                // Étiquette / Page : modules de texte, jamais du H5P
                if ($actType === 'label' || $actType === 'page') {
                    $editorSection['activities'][] = buildTextModuleActivity(
                        $activity, $fileMapping,
                        'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()));
                    continue;
                }

                $h5pType = detectH5pType($activity);
                $h5pContent = [];

                if (isset($activity['content'])) {
                    $h5pContent = $activity['content'];
                } elseif (isset($activity['json_content'])) {
                    $h5pContent = json_decode($activity['json_content'], true) ?: [];
                } elseif (isset($activity['h5p_content']['params'])) {
                    $h5pContent = $activity['h5p_content']['params'];
                }
                
                // Remplacer les chemins d'images dans le contenu H5P
                $h5pContent = replaceFilePathsInContent($h5pContent, $fileMapping);
                
                $editorActivity = [
                    'id' => 'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()),
                    'type' => $activity['type'] ?? 'hvp',
                    'name' => $activity['name'] ?? 'Activité',
                    'h5pType' => $h5pType,
                    // La consigne affichée AU-DESSUS de l'activité (champ `intro` de Moodle).
                    // Sans elle, le « Dans le schéma ci-dessus, localisez… » d'un
                    // Trouver-les-zones était perdu dès l'import, donc aussi à l'export.
                    'intro' => resolvePluginfileUrls($activity['intro'] ?? '', $fileMapping),
                    'content' => $h5pContent
                ];
                $editorSection['activities'][] = $editorActivity;
            }
            
            // Ajouter la visibilité des parcours
            $sectionVisible = ($section['visible'] ?? 1) ? true : false;
            foreach ($editorSection['activities'] as &$edAct) {
                // Trouver l'activité source pour la visibilité
                foreach ($section['activities'] ?? [] as $srcAct) {
                    $srcId = 'import_' . ($srcAct['module_id'] ?? $srcAct['id'] ?? '');
                    if ($srcId === ($edAct['id'] ?? '')) {
                        $actVisible = ($srcAct['visible'] ?? 1) ? true : false;
                        $actVisibleOld = ($srcAct['visibleold'] ?? 1) ? true : false;
                        // Si section cachée: visible=false est hérité, visibleold donne la vraie valeur
                        // Si section visible: visible donne directement la valeur
                        if (!$sectionVisible) {
                            $edAct['visible'] = $actVisibleOld;
                        } else {
                            $edAct['visible'] = $actVisible;
                        }
                        break;
                    }
                }
                if (!isset($edAct['visible'])) $edAct['visible'] = true;
            }
            unset($edAct);
            $editorSections[] = $editorSection;
        }

        if ($progressId !== '') progressClear($progressId);
        echo json_encode([
            'success' => true,
            'course' => [
                'name' => $courseInfo['course_fullname'] ?? $courseInfo['fullname'] ?? $courseInfo['name'] ?? 'Cours importé',
                'shortname' => $courseInfo['shortname'] ?? 'import',
                'vignette' => findCourseVignette($mbzFiles, $hashMapping, $fileMapping),
                'sections' => $editorSections
            ]
        ]);

    } catch (Exception $e) {
        if (!empty($progressId)) progressClear($progressId);
        echo json_encode(['error' => 'Erreur de parsing: ' . $e->getMessage()]);
    }
}

// ==================== TEMPLATES ====================

/**
 * Liste les templates MBZ disponibles dans assets/templates/
 */
function listTemplates() {
    $templatesDir = ROOT_PATH . '/assets/templates';
    if (!is_dir($templatesDir)) {
        echo json_encode(['success' => true, 'templates' => []]);
        return;
    }
    
    $templates = [];
    foreach (glob($templatesDir . '/*.mbz') as $mbzPath) {
        $filename = basename($mbzPath);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $label = str_replace(['_', '-'], ' ', $name);
        $label = ucfirst(trim($label));
        
        $templates[] = [
            'file' => $filename,
            'name' => $label,
            'size' => filesize($mbzPath),
            'cached' => file_exists($mbzPath . '.cache.json')
        ];
    }
    
    echo json_encode(['success' => true, 'templates' => $templates]);
}

/**
 * Charge et parse un template MBZ, avec cache JSON
 */
/**
 * Vérifie qu'un cache de template est encore valide
 * (les fichiers images référencés existent toujours dans cache/editor_uploads/)
 */
function cpTemplateCacheIsValid($cachePath) {
    $cacheJson = file_get_contents($cachePath);
    if (!$cacheJson) return false;

    // Chercher les URLs serve_upload dans le JSON (plus rapide qu'un json_decode complet)
    if (preg_match('/serve_upload&(?:amp;)?file=([^"&\\\\]+)/', $cacheJson, $m)) {
        $checkFile = CACHE_DIR . '/editor_uploads/' . urldecode($m[1]);
        return file_exists($checkFile);
    }

    // Pas d'URLs d'images → cache valide (template sans images)
    return true;
}

/**
 * Copie les fichiers d'un template MBZ extrait vers le dossier d'upload de la session
 * du prof (cache/editor_uploads/{sessionId}/), et les ajoute au pending Drive.
 *
 * Si $sessionId est vide, les fichiers atterrissent dans le dossier plat historique
 * (cache/editor_uploads/), mais ne sont PAS ajoutés au pending — fallback de compat.
 *
 * Retourne ['fileMapping' => [...], 'hashMapping' => [...]] pour le rewriting
 * des contenus H5P.
 */
function copyTemplateFilesToSession(array $mbzFiles, string $extractDir, string $sessionId): array {
    $fileMapping = [];
    $hashMapping = [];

    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    $uploadDir = $safeSessionId
        ? (CACHE_DIR . '/editor_uploads/' . $safeSessionId)
        : (CACHE_DIR . '/editor_uploads');
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    if ($safeSessionId) {
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
    }

    foreach ($mbzFiles as $file) {
        if (empty($file['hash']) || ($file['filename'] ?? '.') === '.') continue;
        $prefix = substr($file['hash'], 0, 2);
        $srcPath = $extractDir . '/files/' . $prefix . '/' . $file['hash'];
        if (!file_exists($srcPath)) continue;

        $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        if (empty($ext)) {
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                       'image/webp' => 'webp', 'video/mp4' => 'mp4'];
            $ext = $extMap[$file['mimetype'] ?? ''] ?? 'bin';
        }

        $newFilename = 'tpl_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $newFilename;

        if (copy($srcPath, $destPath)) {
            $newUrl = 'api/editor_api.php?action=serve_upload&file=' . urlencode($newFilename);
            if ($safeSessionId) {
                $newUrl .= '&session=' . urlencode($safeSessionId);
                EditorDriveSync::addPendingFile($safeSessionId, $newFilename, $file['mimetype'] ?? '');
            }

            $hashMapping[$file['hash']] = ['url' => $newUrl, 'name' => $file['filename']];

            $filepath = trim($file['filepath'] ?? '', '/');
            $fname = $file['filename'];
            $fullPath = $filepath ? $filepath . '/' . $fname : $fname;
            $fileMapping[$fullPath] = $newUrl;
            $fileMapping[$fullPath . '#tmp'] = $newUrl;
            $fileMapping['/' . $fullPath] = $newUrl;
            $fileMapping[$fname] = $newUrl;
        }
    }

    return ['fileMapping' => $fileMapping, 'hashMapping' => $hashMapping];
}

function loadTemplate($input) {
    $filename = basename($input['file'] ?? '');
    if (empty($filename) || !preg_match('/\.mbz$/', $filename)) {
        echo json_encode(['error' => 'Fichier invalide']);
        return;
    }

    $sessionId = $input['sessionId'] ?? '';
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

    $templatesDir = ROOT_PATH . '/assets/templates';
    $mbzPath = $templatesDir . '/' . $filename;
    // Cache désactivé si sessionId présent : les URLs des fichiers sont session-specific
    $cachePath = $safeSessionId ? null : ($mbzPath . '.cache.json');

    if (!file_exists($mbzPath)) {
        echo json_encode(['error' => 'Template non trouvé']);
        return;
    }

    // Cache valide = plus récent que le MBZ ET fichiers images encore présents
    if ($cachePath && file_exists($cachePath) && filemtime($cachePath) >= filemtime($mbzPath)) {
        if (cpTemplateCacheIsValid($cachePath)) {
            echo file_get_contents($cachePath);
            return;
        }
        // Cache invalide (fichiers supprimés), re-extraire
        @unlink($cachePath);
    }

    try {
        require_once __DIR__ . '/../includes/MbzParser.php';

        $extractDir = CACHE_DIR . '/tpl_' . time() . '_' . bin2hex(random_bytes(3));
        @mkdir($extractDir, 0777, true);

        $parser = new MbzParser($mbzPath, $extractDir);
        $courseData = $parser->parse();

        $sections = $courseData['sections'] ?? [];
        $mbzFiles = $courseData['files'] ?? [];

        // Copier fichiers vers le dossier session + enqueue Drive pending
        $maps = copyTemplateFilesToSession($mbzFiles, $extractDir, $safeSessionId);
        $fileMapping = $maps['fileMapping'];
        $hashMapping = $maps['hashMapping'];
        
        // Convertir en sections éditeur avec activités
        $editorSections = [];
        $allActivities = []; // Rétrocompatibilité
        foreach ($sections as $section) {
            $editorSection = [
                'id' => 'tpl_' . uniqid(),
                'name' => $section['name'] ?? 'Section',
                'summary' => strip_tags($section['summary'] ?? ''),
                'visible' => ($section['visible'] ?? 1) ? true : false,
                'activities' => []
            ];
            foreach ($section['activities'] ?? [] as $activity) {
                $actType = $activity['type'] ?? 'hvp';
                
                if ($actType === 'mapmodules') {
                    $mapImage = null;
                    foreach ($mbzFiles as $mf) {
                        if (($mf['component'] ?? '') === 'mod_mapmodules' 
                            && ($mf['filearea'] ?? '') === 'maps'
                            && ($mf['filename'] ?? '.') !== '.') {
                            $fn = $mf['filename'];
                            if (isset($fileMapping[$fn])) $mapImage = $fileMapping[$fn];
                            break;
                        }
                    }
                    $editorSection['activities'][] = [
                        'id' => 'tpl_' . uniqid(),
                        'type' => 'mapmodules',
                        'name' => $activity['name'] ?? 'Carte de progression',
                        'mapPath' => $activity['mapPath'] ?? $activity['path'] ?? '',
                        'mapImage' => $mapImage,
                        'descriptionHeader' => $activity['descriptionHeader'] ?? '',
                        'descriptionFooter' => $activity['descriptionFooter'] ?? '',
                        'iconset' => $activity['iconset'] ?? 4,
                        'buttonWidth' => $activity['buttonWidth'] ?? 50,
                    ];
                    continue;
                }
                
                // Assign (fichier à distribuer)
                if ($actType === 'assign') {
                    $assignContentFiles = $activity['content_files'] ?? [];
                    $assignFiles = [];
                    foreach ($assignContentFiles as $cf) {
                        if (!empty($cf['hash']) && ($cf['filename'] ?? '.') !== '.') {
                            $fUrl = null; $fName = $cf['filename'];
                            if (isset($hashMapping[$cf['hash']])) { $fUrl = $hashMapping[$cf['hash']]['url']; }
                            elseif (isset($fileMapping[$fName])) { $fUrl = $fileMapping[$fName]; }
                            else { foreach ($fileMapping as $k => $u) { if (basename($k) === $fName) { $fUrl = $u; break; } } }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fName];
                        }
                    }
                    if (empty($assignFiles)) {
                        $mainFile = $activity['main_file'] ?? null;
                        if ($mainFile && !empty($mainFile['hash']) && ($mainFile['filename'] ?? '.') !== '.') {
                            $fn = $mainFile['filename'];
                            $fUrl = $hashMapping[$mainFile['hash']]['url'] ?? $fileMapping[$fn] ?? null;
                            if (!$fUrl) { foreach ($fileMapping as $k => $u) { if (basename($k) === $fn) { $fUrl = $u; break; } } }
                            if ($fUrl) $assignFiles[] = ['fileUrl' => $fUrl, 'fileName' => $fn];
                        }
                    }
                    $intro = $activity['intro'] ?? '';
                    if (!empty($intro)) { $intro = resolvePluginfileUrls($intro, $fileMapping); }
                    $editorSection['activities'][] = [
                        'id'    => 'tpl_' . uniqid(),
                        'type'  => 'assign',
                        'name'  => $activity['name'] ?? 'Travail à déposer',
                        'files' => $assignFiles,
                        'intro' => $intro,
                    ];
                    continue;
                }
                
                // Quiz : ddimageortext ou évaluation standard (multichoice, etc.)
                if ($actType === 'quiz') {
                    // Toute quiz importée devient une évaluation (QuestionSet) — standalone DDI
                    // n'est plus distinguable dans l'MBZ d'une évaluation à 1 question. Unifier simplifie
                    // l'aller-retour et le rendu.
                    $quizEditorAct = convertStandardQuizForEditor($activity, $fileMapping, $mbzFiles, $hashMapping, $extractDir ?? '');
                    if ($quizEditorAct) {
                        $editorSection['activities'][] = $quizEditorAct;
                    }
                    continue;
                }

                // Étiquette / Page : modules de texte, jamais du H5P
                if ($actType === 'label' || $actType === 'page') {
                    $editorSection['activities'][] = buildTextModuleActivity(
                        $activity, $fileMapping,
                        'import_' . ($activity['module_id'] ?? $activity['id'] ?? uniqid()));
                    continue;
                }

                $h5pType = detectH5pType($activity);
                $h5pContent = [];
                if (isset($activity['content'])) {
                    $h5pContent = $activity['content'];
                } elseif (isset($activity['json_content'])) {
                    $h5pContent = json_decode($activity['json_content'], true) ?: [];
                }
                $h5pContent = replaceFilePathsInContent($h5pContent, $fileMapping);

                $editorSection['activities'][] = [
                    'id' => 'tpl_' . uniqid(),
                    'type' => $activity['type'] ?? 'hvp',
                    'name' => $activity['name'] ?? 'Activité',
                    'h5pType' => $h5pType,
                    'content' => $h5pContent
                ];
            }
            $editorSections[] = $editorSection;
            // Rétrocompatibilité : liste plate
            foreach ($editorSection['activities'] as $a) { $allActivities[] = $a; }
        }
        
        deleteDirectory($extractDir);

        $result = json_encode(['success' => true, 'activities' => $allActivities, 'sections' => $editorSections]);
        // Ne cacher que si les URLs ne sont pas session-specific
        if ($cachePath) @file_put_contents($cachePath, $result);
        echo $result;

    } catch (Exception $e) {
        if (isset($extractDir) && is_dir($extractDir)) deleteDirectory($extractDir);
        echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
    }
}

/**
 * Liste les templates MBZ contenant exactement 1 CoursePresentation
 */
function listCpTemplates() {
    $templatesDir = ROOT_PATH . '/assets/templates';
    if (!is_dir($templatesDir)) {
        echo json_encode(['success' => true, 'templates' => []]);
        return;
    }
    
    $templates = [];
    foreach (glob($templatesDir . '/*.mbz') as $mbzPath) {
        $filename = basename($mbzPath);
        $cachePath = $mbzPath . '.cache.json';
        
        // Lire le cache s'il existe
        $activities = null;
        if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($mbzPath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if ($cached && isset($cached['activities'])) {
                $activities = $cached['activities'];
            }
        }
        
        // Pas de cache → parser le MBZ (et créer le cache via loadTemplate)
        if ($activities === null) {
            // Parse rapide pour indexer
            try {
                require_once __DIR__ . '/../includes/MbzParser.php';
                $extractDir = CACHE_DIR . '/tpl_idx_' . bin2hex(random_bytes(3));
                @mkdir($extractDir, 0777, true);
                $parser = new MbzParser($mbzPath, $extractDir);
                $courseData = $parser->parse();
                deleteDirectory($extractDir);
                
                // Vérifier si c'est un CP
                $cpCount = 0;
                $cpSlides = 0;
                foreach ($courseData['sections'] ?? [] as $section) {
                    foreach ($section['activities'] ?? [] as $act) {
                        $type = detectH5pType($act);
                        if ($type === 'CoursePresentation') {
                            $cpCount++;
                            $content = $act['content'] ?? [];
                            if (isset($act['json_content'])) {
                                $content = json_decode($act['json_content'], true) ?: [];
                            }
                            $cpSlides = count($content['presentation']['slides'] ?? []);
                        }
                    }
                }
                
                if ($cpCount !== 1) continue;
                
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucfirst(trim(str_replace(['_', '-'], ' ', $name)));
                $templates[] = [
                    'file' => $filename,
                    'name' => $label,
                    'slides' => $cpSlides
                ];
                continue;
                
            } catch (Exception $e) {
                continue;
            }
        }
        
        // Filtrer : exactement 1 activité de type CoursePresentation
        $cpCount = 0;
        $cpSlides = 0;
        foreach ($activities as $act) {
            $h5p = $act['h5pType'] ?? '';
            if ($h5p === 'CoursePresentation') {
                $cpCount++;
                $cpSlides = count($act['content']['presentation']['slides'] ?? []);
            }
        }
        if ($cpCount !== 1) continue;
        
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $label = ucfirst(trim(str_replace(['_', '-'], ' ', $name)));
        $templates[] = [
            'file' => $filename,
            'name' => $label,
            'slides' => $cpSlides
        ];
    }
    
    echo json_encode(['success' => true, 'templates' => $templates]);
}

/**
 * Charge un template CP et retourne ses slides
 */
function loadCpTemplate($input) {
    $filename = basename($input['file'] ?? '');
    if (empty($filename) || !preg_match('/\.mbz$/', $filename)) {
        echo json_encode(['error' => 'Fichier invalide']);
        return;
    }

    $sessionId = $input['sessionId'] ?? '';
    $safeSessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

    $templatesDir = ROOT_PATH . '/assets/templates';
    $mbzPath = $templatesDir . '/' . $filename;
    // Cache désactivé si sessionId présent : les URLs des fichiers sont session-specific
    $cachePath = $safeSessionId ? null : ($mbzPath . '.cp_cache.json');

    if (!file_exists($mbzPath)) {
        echo json_encode(['error' => 'Template non trouvé']);
        return;
    }

    // Cache CP spécifique (slides prêtes à insérer)
    if ($cachePath && file_exists($cachePath) && filemtime($cachePath) >= filemtime($mbzPath)) {
        if (cpTemplateCacheIsValid($cachePath)) {
            echo file_get_contents($cachePath);
            return;
        }
        // Cache invalide (fichiers supprimés), re-extraire
        @unlink($cachePath);
    }

    try {
        require_once __DIR__ . '/../includes/MbzParser.php';

        $extractDir = CACHE_DIR . '/tpl_cp_' . time() . '_' . bin2hex(random_bytes(3));
        @mkdir($extractDir, 0777, true);

        $parser = new MbzParser($mbzPath, $extractDir);
        $courseData = $parser->parse();
        $mbzFiles = $courseData['files'] ?? [];

        // Copier fichiers vers le dossier session + enqueue Drive pending
        $maps = copyTemplateFilesToSession($mbzFiles, $extractDir, $safeSessionId);
        $fileMapping = $maps['fileMapping'];

        // Trouver le CP et extraire ses slides
        $slides = null;
        foreach ($courseData['sections'] ?? [] as $section) {
            foreach ($section['activities'] ?? [] as $activity) {
                $h5pType = detectH5pType($activity);
                if ($h5pType === 'CoursePresentation') {
                    $h5pContent = [];
                    if (isset($activity['content'])) {
                        $h5pContent = $activity['content'];
                    } elseif (isset($activity['json_content'])) {
                        $h5pContent = json_decode($activity['json_content'], true) ?: [];
                    }
                    $h5pContent = replaceFilePathsInContent($h5pContent, $fileMapping);
                    $slides = $h5pContent['presentation']['slides'] ?? [];
                    break 2;
                }
            }
        }

        deleteDirectory($extractDir);

        if ($slides === null) {
            echo json_encode(['error' => 'Aucun CoursePresentation trouvé']);
            return;
        }

        $result = json_encode(['success' => true, 'slides' => $slides]);
        // Ne cacher que si les URLs ne sont pas session-specific
        if ($cachePath) @file_put_contents($cachePath, $result);
        echo $result;

    } catch (Exception $e) {
        if (isset($extractDir) && is_dir($extractDir)) deleteDirectory($extractDir);
        echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
    }
}

/**
 * Exporte le cours en MBZ, le parse et crée un cours local temporaire pour la prévisualisation PDF
 */
function previewForPdf($input) {
    $data = $input['data'] ?? null;
    if (!$data) {
        echo json_encode(['error' => 'Données manquantes']);
        return;
    }
    
    try {
        // Nettoyer les anciens dossiers pdf-preview (> 1 heure)
        foreach (glob(COURSES_PATH . '/pdf-preview-*') as $oldDir) {
            if (is_dir($oldDir) && (time() - filemtime($oldDir)) > 3600) {
                deleteDirectory($oldDir);
            }
        }
        
        $previewId = 'pdf-preview-' . bin2hex(random_bytes(4));
        $coursePath = COURSES_PATH . '/' . $previewId;
        if (!mkdir($coursePath, 0755, true)) {
            throw new Exception('Impossible de créer le dossier de prévisualisation');
        }
        mkdir($coursePath . '/files', 0755, true);
        
        // Convertir le format éditeur en format viewer + copier les fichiers locaux
        $sections = [];
        $allFiles = [];
        $editorUploadsDir = CACHE_DIR . '/editor_uploads';
        
        foreach ($data['sections'] ?? [] as $sIdx => $section) {
            $viewSection = [
                'name' => $section['name'] ?? 'Section ' . ($sIdx + 1),
                'summary' => $section['summary'] ?? '',
                'visible' => $section['visible'] ?? true,
                'activities' => [],
            ];
            
            foreach ($section['activities'] ?? [] as $activity) {
                $viewActivity = [
                    'type' => $activity['type'] ?? 'h5pactivity',
                    'name' => $activity['name'] ?? 'Activité',
                    'module_id' => $activity['id'] ?? ('act_' . bin2hex(random_bytes(4))),
                    'content' => $activity['content'] ?? [],
                    'files' => [],
                    'intro' => $activity['intro'] ?? '',
                    'visible' => $activity['visible'] ?? true,
                ];
                
                $h5pType = $activity['h5pType'] ?? '';
                $viewActivity['machine_name'] = $h5pType ? ('H5P.' . $h5pType) : 'H5P.Unknown';
                
                foreach (['mapPath','mapImage','descriptionHeader','descriptionFooter','iconset','buttonWidth','fileUrl','fileName','quizType'] as $k) {
                    if (isset($activity[$k])) $viewActivity[$k] = $activity[$k];
                }
                if ($activity['type'] === 'quiz') {
                    $viewActivity['questions'] = $activity['content']['questions'] ?? [];
                }
                
                // Collecter et copier les fichiers image
                _collectAndCopyFiles($viewActivity['content'], $allFiles, $coursePath, $editorUploadsDir);
                
                $viewSection['activities'][] = $viewActivity;
            }
            $sections[] = $viewSection;
        }
        
        $courseData = [
            'course' => [
                'course_fullname' => $data['name'] ?? 'Cours',
                'course_shortname' => $data['shortname'] ?? 'cours',
            ],
            'sections' => $sections,
            'files' => $allFiles,
            'extract_path' => $coursePath,
        ];
        
        file_put_contents($coursePath . '/course_data.json', json_encode($courseData, JSON_UNESCAPED_UNICODE));
        file_put_contents($coursePath . '/info.json', json_encode([
            'prof_id' => $previewId,
            'course_name' => $data['name'] ?? 'Cours',
            'created_at' => time(),
            'source' => 'pdf_preview',
        ]));
        
        echo json_encode([
            'success' => true,
            'viewUrl' => 'view.php?id=' . urlencode($previewId) . '&pdf=1',
            'previewId' => $previewId
        ]);
    } catch (\Throwable $e) {
        if (isset($coursePath) && is_dir($coursePath)) {
            deleteDirectory($coursePath);
        }
        echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
    }
}

/**
 * Parcourt le contenu H5P et copie les fichiers depuis editor_uploads (local uniquement, pas de Drive).
 * Structure identique au viewer normal : courses/xxx/files/ab/hash
 */
function _collectAndCopyFiles(&$content, &$allFiles, $coursePath, $editorUploadsDir) {
    if (!is_array($content)) return;
    
    static $seen = [];
    
    foreach ($content as &$value) {
        if (is_array($value)) {
            if (isset($value['path']) && is_string($value['path'])) {
                $filename = basename($value['path']);
                if (!isset($seen[$filename])) {
                    $seen[$filename] = true;
                    $hash = md5($filename . $value['path']);
                    $srcPath = $editorUploadsDir . '/' . $filename;
                    
                    if (file_exists($srcPath)) {
                        $subDir = $coursePath . '/files/' . substr($hash, 0, 2);
                        if (!is_dir($subDir)) mkdir($subDir, 0755, true);
                        copy($srcPath, $subDir . '/' . $hash);
                    }
                    
                    $allFiles[] = [
                        'filename' => $filename,
                        'filepath' => dirname($value['path']) . '/',
                        'hash' => $hash,
                    ];
                }
            }
            _collectAndCopyFiles($value, $allFiles, $coursePath, $editorUploadsDir);
        }
    }
}

// ============================================================
// EDITOR DRIVE SYNC — Flush, Cleanup, Status
// ============================================================

/**
 * Flush les fichiers pending vers Google Drive
 */
function editorFlush($input) {
    // Un lot = jusqu'à 10 uploads Drive séquentiels. Il ne doit être interrompu ni par
    // le max_execution_time par défaut du mutualisé OVH, ni par l'abandon du fetch côté
    // client (timeout 60 s dans editor-drive-sync.js) : une interruption au milieu du
    // lot est exactement la « fenêtre de perte » qui a fait disparaître des médias
    // (corrigée aussi dans flushToDrive : mapping écrit par fichier, avant l'unlink).
    ignore_user_abort(true);
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    $sessionId = $input['sessionId'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'error' => 'sessionId manquant']);
        return;
    }

    require_once __DIR__ . '/../includes/EditorDriveSync.php';
    
    $maxFiles = (int)($input['maxFiles'] ?? 5);
    $keepLocal = !empty($input['keepLocal']);
    $result = EditorDriveSync::flushToDrive($sessionId, $maxFiles, $keepLocal);
    
    echo json_encode($result);
}

/**
 * Nettoie complètement une session éditeur (local + Drive + metadata + draft)
 */
function cleanupEditorSession($input) {
    $sessionId = $input['sessionId'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'error' => 'sessionId manquant']);
        return;
    }

    require_once __DIR__ . '/../includes/EditorDriveSync.php';

    $result = EditorDriveSync::cleanupSession($sessionId);

    echo json_encode($result);
}

/**
 * Crée un nouveau cours de manière atomique :
 * 1. Nettoie l'ancienne session (local + Drive + metadata + draft) si elle existe
 * 2. Génère un nouvel ID de session
 * 3. Crée la nouvelle session
 * 4. Met à jour $_SESSION['editor_session_id']
 *
 * Cette atomicité garantit qu'aucun fichier de l'ancien cours ne reste sur le serveur,
 * même si l'utilisateur recharge la page entre la suppression et la création.
 */
function createCourse($input) {
    global $editorSessionId;

    $courseName = trim((string)($input['course_name'] ?? 'Nouveau cours'));
    if ($courseName === '') $courseName = 'Nouveau cours';

    require_once __DIR__ . '/../includes/EditorDriveSync.php';

    // 1. Nettoyer l'ancienne session si elle existe
    // Source : input.old_session_id (envoyé par le client depuis localStorage) en priorité,
    // sinon $_SESSION['editor_session_id'] (côté serveur).
    $oldSessionId = trim((string)($input['old_session_id'] ?? $editorSessionId));
    $cleanupResult = null;
    if (!empty($oldSessionId)) {
        try {
            $cleanupResult = EditorDriveSync::cleanupSession($oldSessionId);
        } catch (\Throwable $e) {
            // Ne pas bloquer la création du nouveau cours si le cleanup échoue
            error_log('createCourse cleanup error for ' . $oldSessionId . ' : ' . $e->getMessage());
            $cleanupResult = ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // 2. Générer un nouvel ID de session
    $newSessionId = 'user_' . time() . '_' . bin2hex(random_bytes(5));
    $safeNewId = preg_replace('/[^a-zA-Z0-9_-]/', '', $newSessionId);

    // 3. Créer la nouvelle session
    $createResult = EditorDriveSync::createSession($safeNewId, $courseName);
    if (!($createResult['success'] ?? false)) {
        echo json_encode([
            'success' => false,
            'error' => 'Erreur création session : ' . ($createResult['error'] ?? 'inconnue'),
            'cleanup' => $cleanupResult,
        ]);
        return;
    }

    // 4. Mettre à jour $_SESSION (la session a pu être fermée par d'autres fonctions)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['editor_session_id'] = $safeNewId;
    session_write_close();
    $editorSessionId = $safeNewId;

    echo json_encode([
        'success' => true,
        'session_id' => $safeNewId,
        'course_name' => $courseName,
        'cleanup' => $cleanupResult,
    ]);
}

/**
 * Retourne le statut d'une session éditeur
 */
function editorSessionStatus($input) {
    $sessionId = $input['sessionId'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'error' => 'sessionId manquant']);
        return;
    }
    
    require_once __DIR__ . '/../includes/EditorDriveSync.php';
    
    $meta = EditorDriveSync::getMeta($sessionId);
    if (!$meta) {
        echo json_encode(['success' => true, 'exists' => false]);
        return;
    }
    
    // Comptage réel des fichiers locaux (pas le cache metadata)
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeId;
    $realLocalCount = 0;
    if (is_dir($sessionDir)) {
        $files = glob($sessionDir . '/*');
        $realLocalCount = $files ? count($files) : 0;
    }
    
    echo json_encode([
        'success' => true,
        'exists' => true,
        'course_name' => $meta['course_name'] ?? '',
        'local_count' => $realLocalCount,
        'drive_count' => $meta['drive_count'] ?? 0,
        'pending_count' => count($meta['pending_files'] ?? []),
        'last_activity' => $meta['last_activity'] ?? 0,
        'has_drive' => !empty($meta['drive_folder_id']),
    ]);
}

/**
 * Liste toutes les sessions éditeur actives (admin)
 */
function listEditorSessions() {
    require_once __DIR__ . '/../includes/EditorDriveSync.php';
    
    $sessions = EditorDriveSync::listActiveSessions();
    
    echo json_encode(['success' => true, 'sessions' => $sessions]);
}

/**
 * Ouvre un cours en création dans le viewer (copie temporaire)
 * Crée un dossier temporaire dans COURSES_PATH avec les fichiers copiés
 */
function previewEditorSession($input) {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    
    $sessionId = $input['sessionId'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['error' => 'sessionId manquant']);
        return;
    }
    
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    $draftFile = CACHE_DIR . '/drafts/auto/' . $safeId . '.json';
    
    if (!file_exists($draftFile)) {
        echo json_encode(['error' => 'Brouillon introuvable pour cette session']);
        return;
    }
    
    $rawJson = file_get_contents($draftFile);
    $data = json_decode($rawJson, true);
    if (!$data || empty($data['sections'])) {
        echo json_encode(['error' => 'Brouillon vide ou invalide']);
        return;
    }
    
    try {
        $previewId = 'apercu-' . date('ymd-His') . '-' . bin2hex(random_bytes(3));
        $coursePath = COURSES_PATH . '/' . $previewId;
        if (!mkdir($coursePath, 0755, true)) {
            throw new Exception('Impossible de créer le dossier');
        }
        mkdir($coursePath . '/files', 0755, true);
        
        $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeId;
        require_once __DIR__ . '/../includes/EditorDriveSync.php';
        $meta = EditorDriveSync::getMeta($safeId);
        $driveMapping = $meta['file_mapping'] ?? [];
        // Reverse mapping driveId → filename
        $driveIdToFilename = [];
        foreach ($driveMapping as $fn => $did) {
            $driveIdToFilename[$did] = $fn;
        }
        
        $allFiles = [];
        $replacements = []; // old_url → new_path
        $driveFilesToCopy = []; // sourceDriveId → {filename, ext, rawUrl}
        $localHasFiles = false;
        
        // Helper : copier un fichier local dans le dossier cours avec hash
        $copyToHash = function($localPath, $filename) use ($coursePath, &$allFiles, &$localHasFiles) {
            $hash = sha1_file($localPath);
            $prefix = substr($hash, 0, 2);
            $destDir = $coursePath . '/files/' . $prefix;
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $dest = $destDir . '/' . $hash;
            if (!file_exists($dest)) copy($localPath, $dest);
            static $seen = [];
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $allFiles[] = [
                    'hash' => $hash,
                    'filename' => $filename,
                    'filepath' => '/',
                    'filesize' => filesize($localPath),
                    'mimetype' => mime_content_type($localPath) ?: 'application/octet-stream',
                ];
            }
            $localHasFiles = true;
            return $hash;
        };
        
        // === ÉTAPE 1 : Scanner et catégoriser les URLs ===
        
        // 1a. URLs serve_upload
        if (preg_match_all('/api[\\\\\/]+editor_api\.php\?action=serve_upload[^"\'<>\s}\\\\]*/u', $rawJson, $matches)) {
            $uniqueUrls = array_unique($matches[0]);
            foreach ($uniqueUrls as $rawUrl) {
                $decoded = str_replace(['\\/', '&amp;'], ['/', '&'], $rawUrl);
                if (preg_match('/[?&]file=([^&\s]+)/', $decoded, $m)) {
                    $fn = urldecode($m[1]);
                    $localPath = null;
                    if (is_dir($sessionDir) && file_exists($sessionDir . '/' . $fn)) {
                        $localPath = $sessionDir . '/' . $fn;
                    } elseif (file_exists(CACHE_DIR . '/editor_uploads/' . $fn)) {
                        $localPath = CACHE_DIR . '/editor_uploads/' . $fn;
                    }
                    
                    if ($localPath) {
                        // Fichier local → copier avec hash
                        $hash = $copyToHash($localPath, $fn);
                        $prefix = substr($hash, 0, 2);
                        $replacements[$rawUrl] = 'courses/' . $previewId . '/files/' . $prefix . '/' . $hash;
                    } elseif (isset($driveMapping[$fn])) {
                        // Fichier sur Drive → marquer pour files.copy
                        $driveId = $driveMapping[$fn];
                        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                        if (!isset($driveFilesToCopy[$driveId])) {
                            $driveFilesToCopy[$driveId] = ['filename' => $fn, 'ext' => $ext, 'rawUrls' => []];
                        }
                        $driveFilesToCopy[$driveId]['rawUrls'][] = $rawUrl;
                    }
                }
            }
        }
        
        // 1b. URLs lh3 déjà dans le JSON
        if (preg_match_all('#https?:[\\\\\/]+[\\\\\/]*lh3\.googleusercontent\.com[\\\\\/]+d[\\\\\/]+([a-zA-Z0-9_-]+)#u', $rawJson, $matches, PREG_SET_ORDER)) {
            $seen = [];
            foreach ($matches as $m) {
                $fullMatch = $m[0];
                $driveId = $m[1];
                if (isset($seen[$fullMatch])) continue;
                $seen[$fullMatch] = true;
                
                $fn = $driveIdToFilename[$driveId] ?? ($driveId . '.bin');
                $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                if (!isset($driveFilesToCopy[$driveId])) {
                    $driveFilesToCopy[$driveId] = ['filename' => $fn, 'ext' => $ext, 'rawUrls' => []];
                }
                $driveFilesToCopy[$driveId]['rawUrls'][] = $fullMatch;
            }
        }
        
        // === ÉTAPE 2 : Copier les fichiers Drive avec files.copy (côté Google) ===
        $driveFileIndex = []; // hash-like key → new driveId (pour le file_index)
        $driveMimetypes = [];
        
        if (!empty($driveFilesToCopy)) {
            require_once ROOT_PATH . '/DriveManager.php';
            $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
            
            // Créer le dossier destination sur Drive
            $destCourseFolderId = $dm->ensureSubfolder(DRIVE_COURSETEMP_FOLDER_ID, $previewId);
            $destFilesFolderId = $dm->ensureSubfolder($destCourseFolderId, 'files');
            
            foreach ($driveFilesToCopy as $srcDriveId => $info) {
                try {
                    $newDriveId = $dm->copyFile($srcDriveId, $destFilesFolderId);
                    $newLh3Url = 'https://lh3.googleusercontent.com/d/' . $newDriveId;
                    
                    // Remplacer toutes les URLs qui référencent ce fichier
                    foreach ($info['rawUrls'] as $rawUrl) {
                        $replacements[$rawUrl] = $newLh3Url;
                    }
                    
                    // Construire le file_index pour le viewer
                    // Utiliser le srcDriveId comme clé (sera aussi dans le course_data via les URLs)
                    $driveFileIndex[$srcDriveId] = $newDriveId;
                    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                    $driveMimetypes[$srcDriveId] = in_array($info['ext'], $imageExts) 
                        ? 'image/' . ($info['ext'] === 'jpg' ? 'jpeg' : $info['ext'])
                        : 'application/octet-stream';
                } catch (\Throwable $copyErr) {
                    error_log("previewEditorSession: files.copy failed for $srcDriveId: " . $copyErr->getMessage());
                    // Fallback : garder l'URL source (fonctionnera tant que la session source existe)
                    foreach ($info['rawUrls'] as $rawUrl) {
                        // Ne pas remplacer → l'URL originale reste
                    }
                }
            }
            
            // Uploader course_data.json et file_index sur Drive après la construction du JSON
            // (fait plus bas après les remplacements)
        }
        
        // === ÉTAPE 3 : Appliquer les remplacements ===
        uksort($replacements, function($a, $b) { return strlen($b) - strlen($a); });
        $jsonStr = str_replace(array_keys($replacements), array_values($replacements), $rawJson);
        
        $courseEditorData = json_decode($jsonStr, true);
        if (!$courseEditorData) {
            throw new Exception('Erreur de parsing JSON après remplacement des URLs');
        }
        
        // Convertir le format éditeur en format viewer
        $sections = [];
        foreach ($courseEditorData['sections'] ?? [] as $sIdx => $section) {
            $viewSection = [
                'name' => $section['name'] ?? 'Section ' . ($sIdx + 1),
                'summary' => $section['summary'] ?? '',
                'visible' => $section['visible'] ?? true,
                'activities' => [],
            ];
            foreach ($section['activities'] ?? [] as $activity) {
                $viewActivity = [
                    'type' => $activity['type'] ?? 'h5pactivity',
                    'name' => $activity['name'] ?? 'Activité',
                    'module_id' => $activity['id'] ?? ('act_' . bin2hex(random_bytes(4))),
                    'content' => $activity['content'] ?? [],
                    'visible' => $activity['visible'] ?? true,
                    'intro' => $activity['intro'] ?? '',
                ];
                $h5pType = $activity['h5pType'] ?? '';
                $viewActivity['machine_name'] = $h5pType ? ('H5P.' . $h5pType) : 'H5P.Unknown';
                foreach (['files','mapPath','mapImage','descriptionHeader','descriptionFooter','iconset','buttonWidth','fileUrl','fileName','quizType','content_files','main_file'] as $k) {
                    if (isset($activity[$k])) $viewActivity[$k] = $activity[$k];
                }
                if (($activity['type'] ?? '') === 'quiz') {
                    $viewActivity['questions'] = $activity['content']['questions'] ?? [];
                }
                $viewSection['activities'][] = $viewActivity;
            }
            $sections[] = $viewSection;
        }
        
        // 4. Extraire les images base64 (_dataUrl) intégrées dans le contenu (ex: MultiMediaChoice)
        foreach ($sections as &$_sec4) {
            foreach ($_sec4['activities'] as &$_act4) {
                if (!isset($_act4['content']['options']) || !is_array($_act4['content']['options'])) continue;
                foreach ($_act4['content']['options'] as &$_opt4) {
                    if (!isset($_opt4['media']['params']['file']['_dataUrl'])) continue;
                    if (empty($_opt4['media']['params']['file']['path'])) continue;
                    $b64data = $_opt4['media']['params']['file']['_dataUrl'];
                    if (preg_match('#^data:([^;]+);base64,(.+)$#s', $b64data, $b64m)) {
                        $decoded = base64_decode($b64m[2]);
                        if ($decoded !== false) {
                            $tmpFile = sys_get_temp_dir() . '/mmc_' . bin2hex(random_bytes(6));
                            file_put_contents($tmpFile, $decoded);
                            $hash = $copyToHash($tmpFile, basename($_opt4['media']['params']['file']['path']));
                            $prefix = substr($hash, 0, 2);
                            $_opt4['media']['params']['file']['path'] = 'courses/' . $previewId . '/files/' . $prefix . '/' . $hash;
                            @unlink($tmpFile);
                        }
                    }
                    unset($_opt4['media']['params']['file']['_dataUrl']);
                }
                unset($_opt4);
                unset($_act4['content']['_imageFiles']);
            }
            unset($_act4);
        }
        unset($_sec4);
        
        $courseData = [
            'course' => [
                'course_fullname' => $data['name'] ?? 'Cours',
                'course_shortname' => $data['shortname'] ?? 'cours',
            ],
            'sections' => $sections,
            'files' => $allFiles,
            'extract_path' => $coursePath,
        ];
        
        $courseDataJson = json_encode($courseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($coursePath . '/course_data.json', $courseDataJson);
        file_put_contents($coursePath . '/info.json', json_encode([
            'prof_id' => $previewId,
            'prof_name' => 'admin',
            'course_name' => $data['name'] ?? 'Cours',
            'created_at' => time(),
            'expires_at' => time() + (COURSE_LIFETIME_HOURS * 3600),
            'source' => 'editor_preview',
            'uploaded_by' => 'admin',
        ]));
        
        // === ÉTAPE 5 : Écrire le drive_index si TOUS les fichiers sont sur Drive (pas de local) ===
        // Si mixte (local + Drive), le DriveUploadWidget gérera l'upload des locaux
        // et écrira le drive_index complet à la fin
        if (!empty($driveFileIndex) && !$localHasFiles) {
            $fileIndex = [
                'version' => 1,
                'gdrive_id' => $previewId,
                'type' => 'temp',
                'course_folder_id' => $destCourseFolderId ?? '',
                'prepared_at' => date('c'),
                'files' => $driveFileIndex,
                'mimetypes' => $driveMimetypes,
            ];
            $fileIndexJson = json_encode($fileIndex, JSON_PRETTY_PRINT);
            
            // Index local pour le viewer
            file_put_contents(DRIVE_INDEX_DIR . '/temp_' . $previewId . '.json', $fileIndexJson, LOCK_EX);
            file_put_contents(DRIVE_INDEX_DIR . '/temp_' . $previewId . '_data.json', $courseDataJson, LOCK_EX);
            
            // Uploader les métadonnées sur Drive aussi
            if (isset($dm) && isset($destCourseFolderId)) {
                try {
                    $dm->uploadFile('_file_index.json', $fileIndexJson, 'application/json', $destCourseFolderId);
                    $dm->uploadFile('course_data.json', $courseDataJson, 'application/json', $destCourseFolderId);
                } catch (\Throwable $e) {
                    error_log("previewEditorSession: upload metadata failed: " . $e->getMessage());
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'viewUrl' => 'view.php?id=' . urlencode($previewId),
        ]);
    } catch (\Throwable $e) {
        if (isset($coursePath) && is_dir($coursePath)) {
            deleteDirectory($coursePath);
        }
        echo json_encode(['error' => 'Erreur: ' . $e->getMessage()]);
    }
}

/**
 * Charge le brouillon d'une session éditeur (pour l'éditer depuis un autre navigateur)
 * Retourne le cours dans le même format que parseDriveMbz
 */
function loadEditorSessionDraft($input) {
    $sessionId = $input['sessionId'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['error' => 'sessionId manquant']);
        return;
    }
    
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    $draftFile = CACHE_DIR . '/drafts/auto/' . $safeId . '.json';
    
    if (!file_exists($draftFile)) {
        echo json_encode(['error' => 'Brouillon introuvable']);
        return;
    }
    
    $data = json_decode(file_get_contents($draftFile), true);
    if (!$data) {
        echo json_encode(['error' => 'Brouillon invalide']);
        return;
    }
    
    // Retourner le cours avec les URLs modifiées pour inclure le session source
    // Cela permet à serve_upload de retrouver les fichiers dans le dossier d'origine
    $jsonStr = json_encode([
        'success' => true,
        'course' => [
            'name' => $data['name'] ?? 'Cours',
            'shortname' => $data['shortname'] ?? 'cours',
            'vignette' => $data['vignette'] ?? null,
            'sections' => $data['sections'] ?? [],
        ]
    ], JSON_UNESCAPED_UNICODE);
    
    // Ajouter &session=xxx aux URLs serve_upload qui n'ont AUCUN param session.
    // Le lookahead balaie l'URL entière : une URL de la forme file=X&session=Y ne doit
    // PAS être re-stampée (PHP retient le DERNIER param → l'URL pointerait sur la
    // mauvaise session, cf. les doubles stamps constatés le 07/08/2026).
    $jsonStr = preg_replace(
        '#action=serve_upload&(?![^"\\\\\s]*session=)file=#',
        'action=serve_upload&session=' . urlencode($safeId) . '&file=',
        $jsonStr
    );
    $jsonStr = preg_replace(
        '#action=serve_upload&amp;(?![^"\\\\\s]*session=)file=#',
        'action=serve_upload&amp;session=' . urlencode($safeId) . '&amp;file=',
        $jsonStr
    );
    
    echo $jsonStr;
}

/**
 * Synchronise les fichiers locaux d'une session éditeur
 * Détecte les fichiers non trackés et les ajoute au pending
 */
function syncEditorFiles($input) {
    $sessionId = $input['sessionId'] ?? '';
    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'error' => 'sessionId manquant']);
        return;
    }
    
    $referencedFiles = $input['files'] ?? [];
    $courseName = $input['courseName'] ?? $input['course_name'] ?? '';
    $cleanMapped = !empty($input['cleanMapped']);
    
    require_once __DIR__ . '/../includes/EditorDriveSync.php';
    
    // Mettre à jour le nom du cours si fourni
    if ($courseName) {
        EditorDriveSync::touchSession($sessionId, $courseName);
    }
    
    // === DEBUG: état AVANT sync ===
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
    $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeId;
    $localFilesBefore = is_dir($sessionDir) ? (glob($sessionDir . '/*') ?: []) : [];
    $localFilenamesBefore = array_map('basename', $localFilesBefore);
    $localSizeBefore = array_sum(array_map('filesize', $localFilesBefore));
    $metaBefore = EditorDriveSync::getMeta($safeId);
    $pendingBefore = $metaBefore ? count($metaBefore['pending_files'] ?? []) : 0;
    $mappingBefore = $metaBefore ? count($metaBefore['file_mapping'] ?? []) : 0;
    
    // Fichiers locaux NON référencés
    $referencedSet = array_flip($referencedFiles);
    $orphanLocal = array_filter($localFilenamesBefore, function($fn) use ($referencedSet) {
        return !isset($referencedSet[$fn]);
    });
    
    $debugInfo = [
        'referenced_count' => count($referencedFiles),
        'local_files_count' => count($localFilesBefore),
        'local_size_mb' => round($localSizeBefore / (1024*1024), 2),
        'pending_before' => $pendingBefore,
        'mapping_before' => $mappingBefore,
        'orphan_local_count' => count($orphanLocal),
        'orphan_local_files' => array_values(array_slice($orphanLocal, 0, 10)), // max 10 pour lisibilité
    ];
    
    $result = EditorDriveSync::syncLocalFiles($sessionId, $referencedFiles);
    
    // Récupérer le mapping Drive existant
    $meta = EditorDriveSync::getMeta($safeId);
    $fileMapping = $meta['file_mapping'] ?? [];
    
    // Mapping orphelins (sur Drive mais plus dans courseData)
    $orphanDrive = array_filter(array_keys($fileMapping), function($fn) use ($referencedSet) {
        return !isset($referencedSet[$fn]);
    });
    $debugInfo['mapping_after'] = count($fileMapping);
    $debugInfo['pending_after'] = count($meta['pending_files'] ?? []);
    $debugInfo['orphan_drive_count'] = count($orphanDrive);
    $debugInfo['orphan_drive_files'] = array_values(array_slice($orphanDrive, 0, 10));
    
    // Nettoyer les fichiers locaux déjà sur Drive
    $cleaned = 0;
    if ($cleanMapped && !empty($fileMapping)) {
        $sessionDir = CACHE_DIR . '/editor_uploads/' . $safeId;
        if (is_dir($sessionDir)) {
            // Supprimer TOUS les fichiers locaux qui sont dans le mapping Drive
            foreach ($fileMapping as $filename => $driveId) {
                $localPath = $sessionDir . '/' . $filename;
                if (file_exists($localPath)) {
                    @unlink($localPath);
                    $cleaned++;
                }
            }
            // Aussi supprimer les fichiers locaux qui ne sont NI référencés NI dans le mapping
            // (orphelins d'anciennes éditions).
            //
            // GARDE-FOU : on épargne les fichiers récents. « Importer un parcours » décompresse
            // TOUS les fichiers du .mbz avant que le professeur n'ait choisi ce qu'il importe ;
            // tant qu'il est dans la boîte de dialogue, ces fichiers ne sont référencés nulle
            // part et un tick de synchronisation Drive les effaçait — le parcours arrivait
            // ensuite sans aucune image. Ces orphelins seront repris au tick suivant, et de
            // toute façon par le nettoyage automatique des 24 h.
            $delaiDeGrace = 30 * 60; // 30 min : largement de quoi choisir dans la liste
            $limiteAge = time() - $delaiDeGrace;
            $referencedSet = array_flip($referencedFiles);
            foreach (glob($sessionDir . '/*') ?: [] as $fp) {
                if (!is_file($fp)) continue;
                $fn = basename($fp);
                if (isset($referencedSet[$fn]) || isset($fileMapping[$fn])) continue;
                if (@filemtime($fp) > $limiteAge) continue;   // import peut-être en cours
                @unlink($fp);
                $cleaned++;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'synced' => $result['synced'],
        'pending' => $result['pending'],
        'file_mapping' => $fileMapping,
        'cleaned' => $cleaned,
        '_debug' => $debugInfo
    ]);
}
