<?php
/**
 * API de preparation des cours permanents pour Google Drive.
 * Upload pilote par le navigateur, par lots.
 *
 * Actions :
 *   status       (GET)  — retourne l'etat de preparation d'un cours
 *   start        (POST) — extrait le cours et cree les dossiers Drive
 *   upload_batch (POST) — uploade un lot de fichiers (partie 3)
 *   finalize     (POST) — construit l'index, nettoie le local (partie 3)
 *   abort        (POST) — annule la preparation (partie 3)
 */

session_start();
require_once __DIR__ . '/../includes/session_check.php';
// Expiration custom de session (8h, contournement bridage OVH).
// Si la session prof est expirée, on la nettoie et on retourne 401.
// Les visiteurs anonymes sont laissés passer ; ils accèderont aux actions publiques (pending, etc.).
enforceSessionExpiryJson();
$hasAccess = isset($_SESSION['elea_access']) && $_SESSION['elea_access'] === true;
$isAdmin = isset($_SESSION['elea_admin']) && $_SESSION['elea_admin'] === true;
session_write_close();

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (empty($action)) {
    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : [];
    $action = $input['action'] ?? '';
} else {
    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : [];
}

$fileId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['gdrive_id'] ?? ($_GET['gdrive_id'] ?? ''));
$courseType = ($input['type'] ?? ($_GET['type'] ?? 'permanent')) === 'temp' ? 'temp' : 'permanent';

// Anonyme autorise pour finir un upload deja amorce : si le cours est deja
// extrait localement (ou si un fichier d etat existe), c est qu un porteur
// de code valide ou un prof a deja initie l acces. Laisser l anonyme terminer
// l upload Drive permet de liberer le serveur sans elargir la surface : il ne
// peut rien demarrer sur un gdrive_id arbitraire (pas de cache local), il ne
// peut que pousser vers Drive un cours deja accessible publiquement.
if (!$hasAccess && !empty($fileId) && in_array($action, ['enqueue', 'start', 'upload_batch', 'finalize'], true)) {
    $localCachePath = getDefaultExtractPath($fileId, $courseType);
    $stateFilePath  = getStateFilePath($fileId, $courseType);
    if (is_dir($localCachePath) || file_exists($stateFilePath)) {
        $hasAccess = true;
    }
}

// Actions de lecture publiques : permettent a un visiteur anonyme de scanner
// l etat des reprises pendantes pour declencher la sync Drive sans avoir a se logger.
// Les actions qui consomment du quota Drive (abort + start/upload_batch/finalize/enqueue
// sans cache local prouve) restent protegees par session prof.
$publicActions = ['pending', 'queue_status', 'status', 'courses_status'];
if (!$hasAccess && !in_array($action, $publicActions, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Non autorise']);
    exit;
}

switch ($action) {
    case 'status':
        handleStatus($fileId, $courseType);
        break;
    case 'pending':
        handlePending();
        break;
    case 'enqueue':
        handleEnqueue($fileId, $input['name'] ?? '', $courseType);
        break;
    case 'queue_status':
        handleQueueStatus();
        break;
    case 'start':
        handleStart($fileId, $courseType);
        break;
    case 'upload_batch':
        handleUploadBatch($fileId, $input, $courseType);
        break;
    case 'finalize':
        handleFinalize($fileId, $courseType);
        break;
    case 'abort':
        handleAbort($fileId, $courseType);
        break;
    case 'courses_status':
        handleCoursesStatus();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Action inconnue : ' . $action]);
}

// ============================================================
// STATUS — Retourne l'etat de preparation d'un cours
// ============================================================
// ============================================================
// COURSES_STATUS — Retourne le statut (icone) de tous les cours permanents
// Leger : lecture filesystem uniquement, pas d'appel API Drive
// ============================================================
function handleCoursesStatus(): void {
    // Liste des gdrive_ids passes en parametre
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = $input['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'ids manquants']);
        return;
    }

    // Index Drive locaux (permanents + temporaires)
    $driveReadyIds = [];
    if (is_dir(DRIVE_INDEX_DIR)) {
        foreach (glob(DRIVE_INDEX_DIR . '/*.json') as $f) {
            $fid = basename($f, '.json');
            if ($fid !== '' && $fid[0] !== '_' && strpos($fid, '_data') === false) {
                $driveReadyIds[$fid] = true;
            }
        }
    }

    // Cache serveur : fichiers extraits localement (cours permanents)
    $serverCachedIds = [];
    $driveCacheFile = TMP_PATH . '/gdrive_courses_cache.json';
    if (file_exists($driveCacheFile)) {
        $driveCache = json_decode(file_get_contents($driveCacheFile), true) ?? [];
        foreach ($driveCache as $fid => $cacheInfo) {
            $extractPath = $cacheInfo['extract_path'] ?? null;
            if ($extractPath && is_dir($extractPath)) {
                $serverCachedIds[$fid] = true;
            }
        }
    }

    $result = [];
    foreach ($ids as $id) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        
        // Cours temporaire (prefixe temp_)
        if (strpos($id, 'temp_') === 0) {
            $profId = substr($id, 5);
            if (isset($driveReadyIds['temp_' . $profId])) {
                $result[$id] = 'drive';
            } elseif (is_dir(COURSES_PATH . '/' . $profId)) {
                $result[$id] = 'cached';
            } else {
                $result[$id] = 'none';
            }
        }
        // Cours permanent
        else {
            if (isset($driveReadyIds[$id])) {
                $result[$id] = 'drive';
            } elseif (isset($serverCachedIds[$id])) {
                $result[$id] = 'cached';
            } else {
                $result[$id] = 'none';
            }
        }
    }

    echo json_encode(['success' => true, 'statuses' => $result]);
}

function handleStatus(string $fileId, string $type = 'permanent'): void {
    if (empty($fileId)) {
        echo json_encode(['success' => false, 'error' => 'gdrive_id manquant']);
        return;
    }

    // 1. Index local final existe ? → cours pret
    $indexFile = getIndexFilePath($fileId, $type);
    if (file_exists($indexFile)) {
        $index = json_decode(file_get_contents($indexFile), true);
        echo json_encode([
            'success' => true,
            'status' => 'ready',
            'total_files' => count($index['files'] ?? []),
        ]);
        return;
    }

    // 2. Fichier d'etat existe ? → upload en cours ou erreur
    $stateFile = getStateFilePath($fileId, $type);
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        if ($state) {
            echo json_encode([
                'success' => true,
                'status' => $state['status'] ?? 'unknown',
                'uploaded_count' => $state['uploaded_count'] ?? 0,
                'total_files' => $state['total_files'] ?? 0,
                'error' => $state['error'] ?? null,
                'lock_owner' => $state['lock_owner'] ?? null,
                'lock_until' => $state['lock_until'] ?? 0,
                'updated' => $state['updated'] ?? 0,
            ]);
            return;
        }
    }

    // 3. Rien → cours pas encore prepare
    echo json_encode([
        'success' => true,
        'status' => 'none',
    ]);
}

// ============================================================
// RECONCILE — Ajoute a la queue les cours decompresses orphelins
// (presents sur le serveur mais ni en queue, ni en upload, ni sur Drive).
// Appele a chaque pending : toute connexion a l'app rattrape les cours
// laisses pour compte par d anciennes sessions.
// ============================================================
function reconcileOrphanedCourses(): void {
    $queue = loadQueue();
    $queueIndex = [];
    foreach ($queue as $item) {
        $key = ($item['type'] ?? 'permanent') . ':' . ($item['gdrive_id'] ?? '');
        $queueIndex[$key] = true;
    }

    $changed = false;
    $now = time();
    $seenPermanentPaths = [];

    $tryEnqueuePermanent = function (string $gid, string $extractPath, ?array $courseData = null) use (
        &$queue, &$queueIndex, &$changed, &$seenPermanentPaths, $now
    ): void {
        $gid = preg_replace('/[^a-zA-Z0-9_-]/', '', $gid);
        if ($gid === '') return;
        if (empty($extractPath) || !is_dir($extractPath)) return;
        if (!file_exists($extractPath . '/course_data.json')) return;
        if (file_exists(getIndexFilePath($gid, 'permanent'))) return;
        if (file_exists(getStateFilePath($gid, 'permanent'))) return;
        if (isset($queueIndex['permanent:' . $gid])) return;

        $seenPermanentPaths[realpath($extractPath) ?: $extractPath] = true;

        if ($courseData === null) {
            $courseData = json_decode(@file_get_contents($extractPath . '/course_data.json'), true) ?: [];
        }
        $name = $courseData['course']['course_fullname']
            ?? $courseData['course']['course_shortname']
            ?? $gid;

        $queue[] = [
            'gdrive_id' => $gid,
            'name' => $name,
            'type' => 'permanent',
            'added' => $now,
        ];
        $queueIndex['permanent:' . $gid] = true;
        $changed = true;
    };

    // 1) Source principale : tmp/gdrive_courses_cache.json (mapping gdrive_id -> extract_path)
    $cacheFile = TMP_PATH . '/gdrive_courses_cache.json';
    if (file_exists($cacheFile)) {
        $cacheMap = json_decode(@file_get_contents($cacheFile), true) ?: [];
        foreach ($cacheMap as $gid => $info) {
            $extractPath = $info['extract_path'] ?? '';
            $tryEnqueuePermanent((string)$gid, (string)$extractPath);
        }
    }

    // 2) Fallback : balayer tmp/course_* en lisant le champ gdrive_id du course_data.json
    foreach (glob(TMP_PATH . '/course_*') ?: [] as $coursePath) {
        if (!is_dir($coursePath)) continue;
        $real = realpath($coursePath) ?: $coursePath;
        if (isset($seenPermanentPaths[$real])) continue;
        $dataFile = $coursePath . '/course_data.json';
        if (!file_exists($dataFile)) continue;
        $courseData = json_decode(@file_get_contents($dataFile), true) ?: [];
        $gid = $courseData['gdrive_id'] ?? '';
        if ($gid === '') continue;
        $tryEnqueuePermanent((string)$gid, $coursePath, $courseData);
    }

    // 3) Cours temporaires : courses/{profId}/info.json
    foreach (glob(COURSES_PATH . '/*/info.json') ?: [] as $infoFile) {
        $coursePath = dirname($infoFile);
        $profId = preg_replace('/[^a-zA-Z0-9_-]/', '', basename($coursePath));
        if ($profId === '') continue;
        if (!file_exists($coursePath . '/course_data.json')) continue;
        if (file_exists(getIndexFilePath($profId, 'temp'))) continue;
        if (file_exists(getStateFilePath($profId, 'temp'))) continue;
        if (isset($queueIndex['temp:' . $profId])) continue;

        $info = json_decode(@file_get_contents($infoFile), true) ?: [];
        $name = $info['course_name'] ?? $profId;

        $queue[] = [
            'gdrive_id' => $profId,
            'name' => $name,
            'type' => 'temp',
            'added' => $now,
        ];
        $queueIndex['temp:' . $profId] = true;
        $changed = true;
    }

    if ($changed) {
        saveQueue($queue);
    }
}

// ============================================================
// PENDING — Cherche un upload en cours sur le serveur
// ============================================================
function handlePending(): void {
    reconcileOrphanedCourses();

    $pattern = TMP_PATH . '/.drive_prep_*.json';
    $files = glob($pattern);

    foreach ($files as $f) {
        $state = json_decode(file_get_contents($f), true);
        if (!$state) continue;
        if (($state['status'] ?? '') !== 'uploading') continue;

        // Verifier que les fichiers extraits existent encore
        $extractPath = $state['extract_path'] ?? '';
        if (empty($extractPath) || !is_dir($extractPath) || !is_dir($extractPath . '/files')) {
            @unlink($f);
            continue;
        }

        // Extraire le fileId et le type du nom de fichier
        $basename = basename($f);
        $fid = preg_replace('/^\.drive_prep_|\.json$/', '', $basename);
        $itemType = $state['type'] ?? 'permanent';
        // Stripper le prefixe temp_ du fid si present
        if (strpos($fid, 'temp_') === 0) {
            $itemType = 'temp';
            $fid = substr($fid, 5);
        }
        
        // Ignorer et supprimer les state files de preview (ne doivent pas être uploadés)
        if (strpos($fid, 'preview-') === 0 || strpos($fid, 'pdf-preview-') === 0) {
            @unlink($f);
            continue;
        }

        echo json_encode([
            'success' => true,
            'found' => true,
            'gdrive_id' => $fid,
            'type' => $itemType,
            'name' => resolveCourseName($fid, $state['course_name'] ?? '', null, $itemType),
            'uploaded_count' => $state['uploaded_count'] ?? 0,
            'total_files' => $state['total_files'] ?? 0,
            'also_queued' => buildQueueList(loadQueue(), $fid),
        ]);
        return;
    }

    // Rien en cours — verifier la queue pour le prochain a traiter
    $queue = loadQueue();
    $queueModified = false;
    
    // D'abord nettoyer les entrées fantômes (dossiers supprimés, previews)
    foreach ($queue as $idx => $item) {
        $fid = $item['gdrive_id'] ?? '';
        $itemType = $item['type'] ?? 'permanent';
        if (strpos($fid, 'preview-') === 0 || strpos($fid, 'pdf-preview-') === 0) {
            unset($queue[$idx]);
            $queueModified = true;
            continue;
        }
        if ($itemType === 'temp') {
            $extractPath = getDefaultExtractPath($fid, $itemType);
            if (!is_dir($extractPath)) {
                unset($queue[$idx]);
                $queueModified = true;
            }
        }
    }
    if ($queueModified) {
        $queue = array_values($queue);
        file_put_contents(getQueueFile(), json_encode($queue, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    // Chercher le prochain cours à uploader
    foreach ($queue as $item) {
        $fid = $item['gdrive_id'];
        $itemType = $item['type'] ?? 'permanent';
        $indexFile = getIndexFilePath($fid, $itemType);
        if (file_exists($indexFile)) continue;

        echo json_encode([
            'success' => true,
            'found' => true,
            'gdrive_id' => $fid,
            'type' => $itemType,
            'name' => resolveCourseName($fid, $item['name'] ?? '', null, $itemType),
            'uploaded_count' => 0,
            'total_files' => 0,
            'also_queued' => buildQueueList($queue, $fid),
        ]);
        return;
    }

    echo json_encode(['success' => true, 'found' => false]);
}

/**
 * Construit la liste des items en queue (hors l'item actif) avec noms resolus.
 */
function buildQueueList(array $queue, string $excludeId): array {
    $namesIndex = loadNamesIndex();
    $list = [];
    foreach ($queue as $item) {
        $fid = $item['gdrive_id'];
        $itemType = $item['type'] ?? 'permanent';
        if ($fid === $excludeId) continue;
        $indexFile = getIndexFilePath($fid, $itemType);
        if (file_exists($indexFile)) continue;
        $name = resolveCourseName($fid, $item['name'] ?? '', $namesIndex, $itemType);
        $total = 0;
        $sf = getStateFilePath($fid, $itemType);
        if (file_exists($sf)) {
            $sd = json_decode(file_get_contents($sf), true);
            $total = $sd['total_files'] ?? 0;
        }
        $list[] = ['gdrive_id' => $fid, 'name' => $name, 'total_files' => $total, 'type' => $itemType];
    }
    return $list;
}

/**
 * Resout le nom d'un cours en essayant toutes les sources.
 */
function resolveCourseName(string $fid, string $queueName, ?array $namesIndex = null, string $type = 'permanent'): string {
    // 1. Nom de la queue
    if (!empty($queueName)) return $queueName;
    // 2. State file course_name
    $sf = getStateFilePath($fid, $type);
    if (file_exists($sf)) {
        $sd = json_decode(file_get_contents($sf), true);
        $name = $sd['course_name'] ?? '';
        if (!empty($name)) return $name;
    }
    // 3. Index de noms (genere par index.php)
    if ($namesIndex === null) $namesIndex = loadNamesIndex();
    if (!empty($namesIndex[$fid])) return $namesIndex[$fid];
    // 4. Dernier recours
    return $fid;
}

function loadNamesIndex(): array {
    $f = TMP_PATH . '/.drive_names_index.json';
    if (file_exists($f)) {
        $data = json_decode(file_get_contents($f), true);
        if (is_array($data)) return $data;
    }
    return [];
}

// ============================================================
// QUEUE — Helpers pour la file d'attente
// ============================================================
// ============================================================
// HELPERS — Chemins selon le type (permanent vs temp)
// ============================================================
function getStateFilePath(string $id, string $type): string {
    if ($type === 'temp') {
        return TMP_PATH . '/.drive_prep_temp_' . $id . '.json';
    }
    return TMP_PATH . '/.drive_prep_' . $id . '.json';
}

function getIndexFilePath(string $id, string $type): string {
    if ($type === 'temp') {
        return DRIVE_INDEX_DIR . '/temp_' . $id . '.json';
    }
    return DRIVE_INDEX_DIR . '/' . $id . '.json';
}

function getIndexDataFilePath(string $id, string $type): string {
    if ($type === 'temp') {
        return DRIVE_INDEX_DIR . '/temp_' . $id . '_data.json';
    }
    return DRIVE_INDEX_DIR . '/' . $id . '_data.json';
}

function getDriveParentFolderId(string $type): string {
    if ($type === 'temp') {
        return DRIVE_COURSETEMP_FOLDER_ID;
    }
    return DRIVE_COURSEPERMANENTS_FOLDER_ID;
}

function getDefaultExtractPath(string $id, string $type): string {
    if ($type === 'temp') {
        return COURSES_PATH . '/' . $id;
    }
    return TMP_PATH . '/course_' . md5($id);
}

function getQueueFile(): string {
    return TMP_PATH . '/.drive_upload_queue.json';
}

function loadQueue(): array {
    $file = getQueueFile();
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveQueue(array $queue): void {
    file_put_contents(getQueueFile(), json_encode(array_values($queue), JSON_PRETTY_PRINT), LOCK_EX);
}

// ============================================================
// ENQUEUE — Ajoute un cours a la file d'attente
// ============================================================
function handleEnqueue(string $fileId, string $name, string $type = 'permanent'): void {
    if (empty($fileId)) {
        echo json_encode(['success' => false, 'error' => 'gdrive_id manquant']);
        return;
    }

    // Deja sur Drive ? Pas besoin
    $indexFile = getIndexFilePath($fileId, $type);
    if (file_exists($indexFile)) {
        echo json_encode(['success' => true, 'already_ready' => true]);
        return;
    }

    $queue = loadQueue();

    // Deja dans la queue ?
    foreach ($queue as &$item) {
        if ($item['gdrive_id'] === $fileId && ($item['type'] ?? 'permanent') === $type) {
            if (!empty($name) && empty($item['name'])) {
                $item['name'] = $name;
                saveQueue($queue);
            }
            echo json_encode(['success' => true, 'already_queued' => true, 'queue_length' => count($queue)]);
            return;
        }
    }
    unset($item);

    $queue[] = [
        'gdrive_id' => $fileId,
        'name' => $name,
        'type' => $type,
        'added' => time(),
    ];
    saveQueue($queue);

    echo json_encode(['success' => true, 'queued' => true, 'queue_length' => count($queue)]);
}

// ============================================================
// QUEUE_STATUS — Retourne l'etat complet de la file
// ============================================================
function handleQueueStatus(): void {
    $queue = loadQueue();
    $result = [];
    $namesIndex = loadNamesIndex();

    foreach ($queue as $item) {
        $fid = $item['gdrive_id'];
        $itemType = $item['type'] ?? 'permanent';
        $indexFile = getIndexFilePath($fid, $itemType);
        $stateFile = getStateFilePath($fid, $itemType);

        if (file_exists($indexFile)) {
            $status = 'ready';
            $uploaded = 0;
            $total = 0;
        } elseif (file_exists($stateFile)) {
            $state = json_decode(file_get_contents($stateFile), true);
            $status = $state['status'] ?? 'unknown';
            $uploaded = $state['uploaded_count'] ?? 0;
            $total = $state['total_files'] ?? 0;
        } else {
            $status = 'pending';
            $uploaded = 0;
            $total = 0;
        }

        $name = resolveCourseName($fid, $item['name'] ?? '', $namesIndex);

        $result[] = [
            'gdrive_id' => $fid,
            'name' => $name,
            'type' => $itemType,
            'status' => $status,
            'uploaded_count' => $uploaded,
            'total_files' => $total,
        ];
    }

    // Nettoyer la queue : retirer les ready
    $cleaned = array_filter($queue, function($item) {
        $indexFile = getIndexFilePath($item['gdrive_id'], $item['type'] ?? 'permanent');
        return !file_exists($indexFile);
    });
    if (count($cleaned) !== count($queue)) {
        saveQueue($cleaned);
    }

    echo json_encode(['success' => true, 'queue' => $result]);
}

// ============================================================
// START — Extrait le cours et cree les dossiers Drive
// ============================================================
function handleStart(string $fileId, string $type = 'permanent'): void {
    if (empty($fileId)) {
        echo json_encode(['success' => false, 'error' => 'gdrive_id manquant']);
        return;
    }
    
    // Rejeter les prévisualisations (pas des vrais cours)
    if (strpos($fileId, 'pdf-preview-') === 0 || strpos($fileId, 'preview-') === 0) {
        echo json_encode(['success' => false, 'error' => 'Les previews ne sont pas uploadées sur Drive']);
        return;
    }

    // Deja pret ?
    $indexFile = getIndexFilePath($fileId, $type);
    if (file_exists($indexFile)) {
        echo json_encode(['success' => true, 'status' => 'ready', 'message' => 'Cours deja prepare']);
        return;
    }

    // Deja en cours pour CE cours ?
    $stateFile = getStateFilePath($fileId, $type);
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        if ($state && ($state['status'] ?? '') === 'uploading') {
            $lastUpdate = $state['updated'] ?? 0;
            if (time() - $lastUpdate < 300) {
                echo json_encode([
                    'success' => true,
                    'status' => 'uploading',
                    'message' => 'Upload deja en cours',
                    'uploaded_count' => $state['uploaded_count'] ?? 0,
                    'total_files' => $state['total_files'] ?? 0,
                ]);
                return;
            }
        }
    }

    // Un flush éditeur est-il en cours ? Si oui, refuser de demarrer
    $flushLockFile = TMP_PATH . '/.drive_flush_lock.json';
    if (file_exists($flushLockFile)) {
        $flushLock = json_decode(file_get_contents($flushLockFile), true);
        if ($flushLock && ($flushLock['until'] ?? 0) > time()) {
            echo json_encode([
                'success' => false,
                'error' => 'Un flush éditeur est en cours',
                'busy' => true,
            ]);
            return;
        }
    }

    // Un AUTRE cours est-il en upload actif ? Si oui, refuser de demarrer
    // On vérifie lock_until (= un batch est en train de tourner), pas updated (= stale possible)
    foreach (glob(TMP_PATH . '/.drive_prep_*.json') ?: [] as $sf) {
        if ($sf === $stateFile) continue; // C'est nous-meme, ignorer
        $otherState = json_decode(file_get_contents($sf), true);
        if (!$otherState) continue;
        if (($otherState['status'] ?? '') === 'uploading' && ($otherState['lock_until'] ?? 0) > time()) {
            // Un autre cours a un batch activement en cours — refuser
            $otherName = $otherState['course_name'] ?? basename($sf);
            echo json_encode([
                'success' => false,
                'error' => 'Un autre cours est en cours d\'upload : ' . $otherName,
                'busy' => true,
            ]);
            return;
        }
    }

    $extractPath = getDefaultExtractPath($fileId, $type);
    $courseDataFile = $extractPath . '/course_data.json';
    $courseData = null;

    if ($type === 'temp') {
        // Cours temporaire : deja extrait dans COURSES_PATH/{profId}/
        if (!is_dir($extractPath) || !file_exists($courseDataFile)) {
            echo json_encode(['success' => false, 'error' => 'Cours temporaire non trouve dans ' . $extractPath]);
            return;
        }
        $courseData = json_decode(file_get_contents($courseDataFile), true);
        if (!$courseData) {
            echo json_encode(['success' => false, 'error' => 'course_data.json invalide']);
            return;
        }
    } else {
        // Cours permanent : verifier le cache local puis telecharger/extraire
        if (is_dir($extractPath) && file_exists($courseDataFile)) {
            $courseData = json_decode(file_get_contents($courseDataFile), true);
        }

        if (!$courseData) {
            $extractionCheck = checkExtractionStatus();
            if (!$extractionCheck['can_extract']) {
                echo json_encode([
                    'success' => false,
                    'error' => $extractionCheck['message'] ?? 'Serveur plein ou extraction en cours',
                ]);
                return;
            }

            require_once __DIR__ . '/../includes/GoogleDriveLoader.php';
            require_once __DIR__ . '/../includes/MbzParser.php';

            $driveLoader = new GoogleDriveLoader();
            $courseData = $driveLoader->loadAndParseCourse($fileId);

            if (!$courseData) {
                echo json_encode(['success' => false, 'error' => 'Impossible de charger le cours depuis Google Drive']);
                return;
            }

            $extractPath = $courseData['tmp_path'] ?? $extractPath;
        }
    }

    // Lister tous les fichiers a uploader
    $fileList = [];
    $mimetypes = [];
    $filesDir = $extractPath . '/files';

    if (is_dir($filesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $hash = $file->getFilename();
                $fileList[] = $hash;

                $mime = 'application/octet-stream';
                foreach ($courseData['files'] ?? [] as $f) {
                    if (($f['hash'] ?? '') === $hash) {
                        $mime = $f['mimetype'] ?? 'application/octet-stream';
                        break;
                    }
                }
                $mimetypes[$hash] = $mime;
            }
        }
    }

    $fileList = array_unique($fileList);
    $totalFiles = count($fileList);

    if ($totalFiles === 0) {
        echo json_encode(['success' => false, 'error' => 'Aucun fichier trouve dans le cours extrait']);
        return;
    }

    // Creer le dossier sur Drive
    try {
        require_once ROOT_PATH . '/DriveManager.php';
        $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');

        $parentFolderId = getDriveParentFolderId($type);
        $courseFolderId = $dm->ensureSubfolder($parentFolderId, $fileId);
        $filesFolderId = $dm->ensureSubfolder($courseFolderId, 'files');

    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Erreur Drive : ' . $e->getMessage()]);
        return;
    }

    // Date de modification source
    $sourceModified = date('c');
    if ($type === 'permanent') {
        require_once __DIR__ . '/../includes/GoogleDriveLoader.php';
        $loader = new GoogleDriveLoader();
        $metadata = $loader->getFileMetadata($fileId);
        $sourceModified = $metadata['modifiedTime'] ?? date('c');
    }

    // Recuperer l'etat existant si reprise
    $existingUploaded = [];
    if (file_exists($stateFile)) {
        $oldState = json_decode(file_get_contents($stateFile), true);
        if ($oldState) {
            $existingUploaded = $oldState['uploaded'] ?? [];
        }
    }

    // Resoudre le nom du cours
    $courseInfo = $courseData['course'] ?? [];
    $courseName = $courseInfo['course_fullname'] ?? $courseInfo['course_shortname'] 
        ?? $courseData['course_fullname'] ?? $courseData['course_shortname'] ?? '';
    if (empty($courseName) && $type === 'temp') {
        // Lire info.json pour les cours temporaires (contient le nom du fichier)
        $infoFile = $extractPath . '/info.json';
        if (file_exists($infoFile)) {
            $infoData = json_decode(file_get_contents($infoFile), true);
            $courseName = $infoData['course_name'] ?? '';
        }
    }
    if (empty($courseName)) $courseName = $fileId;

    // Ecrire le fichier d'etat
    $totalSize = getDirSize($extractPath);
    $state = [
        'status' => 'uploading',
        'type' => $type,
        'course_name' => $courseName,
        'total_files' => $totalFiles,
        'total_size' => $totalSize,
        'uploaded' => $existingUploaded,
        'uploaded_count' => count($existingUploaded),
        'drive_folder_id' => $courseFolderId,
        'files_folder_id' => $filesFolderId,
        'file_list' => array_values($fileList),
        'mimetypes' => $mimetypes,
        'extract_path' => $extractPath,
        'source_modified' => $sourceModified,
        'lock_owner' => null,
        'lock_until' => 0,
        'updated' => time(),
        'error' => null,
    ];

    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

    if (!file_exists($courseDataFile)) {
        file_put_contents($courseDataFile, json_encode($courseData, JSON_PRETTY_PRINT));
    }

    echo json_encode([
        'success' => true,
        'status' => 'uploading',
        'total_files' => $totalFiles,
        'uploaded_count' => count($existingUploaded),
        'drive_folder_id' => $courseFolderId,
    ]);
}

// ============================================================
// UPLOAD_BATCH — Uploade un lot de fichiers vers Drive
// ============================================================
function handleUploadBatch(string $fileId, array $input, string $type = 'permanent'): void {
    if (empty($fileId)) {
        echo json_encode(['success' => false, 'error' => 'gdrive_id manquant']);
        return;
    }

    $stateFile = getStateFilePath($fileId, $type);
    if (!file_exists($stateFile)) {
        echo json_encode(['success' => false, 'error' => 'Aucun upload en cours. Lancez start.']);
        return;
    }

    $state = json_decode(file_get_contents($stateFile), true);
    if (!$state || ($state['status'] ?? '') !== 'uploading') {
        echo json_encode(['success' => false, 'error' => 'Etat invalide']);
        return;
    }

    $clientId = $input['client_id'] ?? ('client_' . bin2hex(random_bytes(4)));
    $now = time();

    // Vérifier si un flush éditeur est en cours (lock partagé)
    $flushLockFile = TMP_PATH . '/.drive_flush_lock.json';
    if (file_exists($flushLockFile)) {
        $flushLock = json_decode(file_get_contents($flushLockFile), true);
        if ($flushLock && ($flushLock['until'] ?? 0) > $now) {
            echo json_encode([
                'success' => true,
                'locked' => true,
                'uploaded_count' => $state['uploaded_count'] ?? 0,
                'total_files' => $state['total_files'] ?? 0,
                'lock_expires_in' => ($flushLock['until'] ?? 0) - $now,
            ]);
            return;
        }
    }

    // Lock : un autre client est actif ?
    if (!empty($state['lock_owner']) && $state['lock_owner'] !== $clientId && ($state['lock_until'] ?? 0) > $now) {
        echo json_encode([
            'success' => true,
            'locked' => true,
            'uploaded_count' => $state['uploaded_count'] ?? 0,
            'total_files' => $state['total_files'] ?? 0,
            'lock_expires_in' => ($state['lock_until'] ?? 0) - $now,
        ]);
        return;
    }

    // Prendre le lock
    $state['lock_owner'] = $clientId;
    $state['lock_until'] = $now + 30;
    $state['updated'] = $now;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

    $uploaded = $state['uploaded'] ?? [];
    $fileList = $state['file_list'] ?? [];
    $remaining = array_diff($fileList, array_keys($uploaded));

    if (empty($remaining)) {
        $state['lock_owner'] = null;
        $state['lock_until'] = 0;
        $state['updated'] = time();
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
        echo json_encode(['success' => true, 'done' => true, 'uploaded_count' => $state['uploaded_count'] ?? 0, 'total_files' => $state['total_files'] ?? 0]);
        return;
    }

    $batchSize = max(1, min(15, (int)($input['batch_size'] ?? 10)));
    $batch = array_slice(array_values($remaining), 0, $batchSize);

    $extractPath = $state['extract_path'] ?? getDefaultExtractPath($fileId, $type);
    $filesFolderId = $state['files_folder_id'] ?? '';
    $mimetypes = $state['mimetypes'] ?? [];

    if (empty($filesFolderId)) {
        echo json_encode(['success' => false, 'error' => 'files_folder_id manquant']);
        return;
    }

    try {
        require_once ROOT_PATH . '/DriveManager.php';
        $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
        
        // Vérifier que le dossier Drive existe encore (peut avoir été supprimé/corrompu)
        try {
            $dm->getService()->files->get($filesFolderId, ['fields' => 'id']);
        } catch (\Throwable $checkErr) {
            if (strpos($checkErr->getMessage(), '404') !== false || strpos($checkErr->getMessage(), 'notFound') !== false) {
                // Dossier Drive disparu → recréer
                $parentFolderId = getDriveParentFolderId($type);
                $courseFolderId = $dm->ensureSubfolder($parentFolderId, $fileId);
                $filesFolderId = $dm->ensureSubfolder($courseFolderId, 'files');
                $state['drive_folder_id'] = $courseFolderId;
                $state['files_folder_id'] = $filesFolderId;
                $state['updated'] = time();
                file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
            } else {
                throw $checkErr;
            }
        }
        
        $batchUploaded = 0;
        $errors = [];

        foreach ($batch as $hash) {
            $prefix = substr($hash, 0, 2);
            $localFile = $extractPath . '/files/' . $prefix . '/' . $hash;

            if (!file_exists($localFile)) {
                $uploaded[$hash] = '__missing__';
                $errors[] = "Fichier local manquant : $hash";
                $state['uploaded'] = $uploaded;
                $state['uploaded_count'] = countValidUploads($uploaded);
                $state['updated'] = time();
                file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
                continue;
            }

            // Upload direct dans files/ (pas de sous-dossiers)
            $content = file_get_contents($localFile);
            $mime = $mimetypes[$hash] ?? 'application/octet-stream';
            $result = $dm->uploadFile($hash, $content, $mime, $filesFolderId);
            $driveFileId = $result['id'] ?? null;

            if ($driveFileId) {
                $uploaded[$hash] = $driveFileId;
                $batchUploaded++;
            } else {
                $errors[] = "Echec upload : $hash";
            }

            $state['uploaded'] = $uploaded;
            $state['uploaded_count'] = countValidUploads($uploaded);
            $state['updated'] = time();
            $state['lock_until'] = time() + 30;
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
        }

        $state['lock_owner'] = null;
        $state['lock_until'] = 0;
        $state['updated'] = time();
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

        $allDone = empty(array_diff($fileList, array_keys($uploaded)));

        echo json_encode([
            'success' => true,
            'done' => $allDone,
            'batch_uploaded' => $batchUploaded,
            'uploaded_count' => $state['uploaded_count'],
            'total_files' => $state['total_files'],
            'errors' => $errors ?: null,
            'client_id' => $clientId,
        ]);

    } catch (\Throwable $e) {
        $state['lock_owner'] = null;
        $state['lock_until'] = 0;
        $state['error'] = $e->getMessage();
        $state['updated'] = time();
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
        echo json_encode(['success' => false, 'error' => 'Erreur Drive : ' . $e->getMessage()]);
    }
}

// ============================================================
// FINALIZE — Construit l index, uploade sur Drive, nettoie
// ============================================================
function handleFinalize(string $fileId, string $type = 'permanent'): void {
    if (empty($fileId)) {
        echo json_encode(['success' => false, 'error' => 'gdrive_id manquant']);
        return;
    }

    $stateFile = getStateFilePath($fileId, $type);
    if (!file_exists($stateFile)) {
        echo json_encode(['success' => false, 'error' => 'Aucun upload en cours']);
        return;
    }

    $state = json_decode(file_get_contents($stateFile), true);
    if (!$state) {
        echo json_encode(['success' => false, 'error' => 'Fichier etat corrompu']);
        return;
    }

    $uploaded = $state['uploaded'] ?? [];
    $fileList = $state['file_list'] ?? [];
    $mimetypes = $state['mimetypes'] ?? [];
    $courseFolderId = $state['drive_folder_id'] ?? '';
    $extractPath = $state['extract_path'] ?? getDefaultExtractPath($fileId, $type);

    $remaining = array_diff($fileList, array_keys($uploaded));
    if (!empty($remaining)) {
        echo json_encode(['success' => false, 'error' => 'Upload incomplet : ' . count($remaining) . ' fichiers restants']);
        return;
    }

    // Construire le file_index
    $filesIndex = [];
    foreach ($uploaded as $hash => $driveId) {
        if ($driveId !== '__missing__') {
            $filesIndex[$hash] = $driveId;
        }
    }

    $fileIndex = [
        'version' => 1,
        'gdrive_id' => $fileId,
        'type' => $type,
        'course_folder_id' => $courseFolderId,
        'source_modified' => $state['source_modified'] ?? '',
        'total_size' => $state['total_size'] ?? 0,
        'prepared_at' => date('c'),
        'files' => $filesIndex,
        'mimetypes' => $mimetypes,
    ];

    $courseDataFile = $extractPath . '/course_data.json';
    $courseDataJson = file_exists($courseDataFile) ? file_get_contents($courseDataFile) : null;
    if (!$courseDataJson) {
        echo json_encode(['success' => false, 'error' => 'course_data.json introuvable']);
        return;
    }

    $fileIndexJson = json_encode($fileIndex, JSON_PRETTY_PRINT);

    try {
        require_once ROOT_PATH . '/DriveManager.php';
        $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
        $dm->uploadFile('_file_index.json', $fileIndexJson, 'application/json', $courseFolderId);
        $dm->uploadFile('course_data.json', $courseDataJson, 'application/json', $courseFolderId);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Erreur upload Drive : ' . $e->getMessage()]);
        return;
    }

    // Sauvegarder l'index en local
    $indexFile = getIndexFilePath($fileId, $type);
    $indexDataFile = getIndexDataFilePath($fileId, $type);
    file_put_contents($indexFile, $fileIndexJson, LOCK_EX);
    file_put_contents($indexDataFile, $courseDataJson, LOCK_EX);

    // Supprimer le dossier local après upload réussi
    if (is_dir($extractPath)) {
        deleteDirectory($extractPath);
    }

    // Supprimer le fichier d'etat
    @unlink($stateFile);

    // Nettoyer gdrive_courses_cache.json (cours permanents uniquement)
    if ($type === 'permanent') {
        $courseCacheFile = TMP_PATH . '/gdrive_courses_cache.json';
        if (file_exists($courseCacheFile)) {
            $cache = json_decode(file_get_contents($courseCacheFile), true) ?: [];
            if (isset($cache[$fileId])) {
                unset($cache[$fileId]);
                file_put_contents($courseCacheFile, json_encode($cache, JSON_PRETTY_PRINT));
            }
        }
    }
    
    // Nettoyer la file d'attente serveur
    $queueFile = getQueueFile();
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true) ?: [];
        $newQueue = array_filter($queue, function($item) use ($fileId) {
            return ($item['gdrive_id'] ?? '') !== $fileId;
        });
        if (count($newQueue) !== count($queue)) {
            file_put_contents($queueFile, json_encode(array_values($newQueue), JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    echo json_encode(['success' => true, 'status' => 'ready', 'files_indexed' => count($filesIndex)]);
}

// ============================================================
// ABORT — Annule la preparation en cours
// ============================================================
function handleAbort(string $fileId, string $type = 'permanent'): void {
    if (empty($fileId)) {
        echo json_encode(['success' => false, 'error' => 'gdrive_id manquant']);
        return;
    }

    $stateFile = getStateFilePath($fileId, $type);
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        @unlink($stateFile);
        $uploadedCount = count($state['uploaded'] ?? []);
        echo json_encode(['success' => true, 'message' => 'Annule. ' . $uploadedCount . ' fichiers resteront sur Drive.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Aucune preparation en cours']);
    }
}

// ============================================================
// UTILITAIRE
// ============================================================
function countValidUploads(array $uploaded): int {
    $count = 0;
    foreach ($uploaded as $v) {
        if ($v !== '__missing__') $count++;
    }
    return $count;
}
