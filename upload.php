<?php
/**
 * EleaSecours - Gestionnaire d'upload
 * Traite les fichiers .mbz uploadés
 *
 * Tous les utilisateurs : stockage local
 * Admin : si sync échoue, le cours reste en local (gestion manuelle)
 * Prof  : si sync échoue, le cours est supprimé après 2h
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/MbzParser.php';
require_once __DIR__ . '/includes/session_check.php';

// Expiration custom de session (8h, contournement bridage OVH) — retourne 401 JSON si expirée
enforceSessionExpiryJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

if (!isset($_SESSION['elea_access']) || $_SESSION['elea_access'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Non autorisé. Veuillez vous connecter.']);
    exit;
}

$isAdmin = isset($_SESSION['elea_admin']) && $_SESSION['elea_admin'] === true;

try {
    $profName = sanitizeProfName($_POST['prof_name'] ?? '');
    if (empty($profName) || strlen($profName) < 2) {
        $profName = 'cours-' . date('ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    }
    
    $baseName = $profName;
    $counter = 1;
    while (profExists($profName)) {
        $profName = $baseName . '-' . $counter;
        $counter++;
    }
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite serveur)',
            UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux',
            UPLOAD_ERR_PARTIAL => 'Fichier partiellement uploadé',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
            UPLOAD_ERR_CANT_WRITE => 'Erreur d\'écriture',
            UPLOAD_ERR_EXTENSION => 'Extension bloquée',
        ];
        $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new Exception($errorMessages[$errorCode] ?? 'Erreur d\'upload');
    }
    
    $file = $_FILES['file'];
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        throw new Exception('Type de fichier non autorisé. Seuls les fichiers .mbz sont acceptés.');
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new Exception('Fichier trop volumineux (max. ' . round(MAX_UPLOAD_SIZE / (1024*1024)) . ' Mo)');
    }
    
    // Vérifier le quota (pour tout le monde, car extraction locale nécessaire)
    if (!canUpload($file['size'])) {
        throw new Exception('Espace de stockage insuffisant. Veuillez réessayer plus tard.');
    }
    
    // Extraction locale
    $coursePath = COURSES_PATH . '/' . $profName;
    if (!mkdir($coursePath, 0755, true)) {
        throw new Exception('Impossible de créer le dossier du cours');
    }
    
    $mbzPath = $coursePath . '/course.mbz';
    if (!move_uploaded_file($file['tmp_name'], $mbzPath)) {
        deleteDirectory($coursePath);
        throw new Exception('Erreur lors de la sauvegarde du fichier');
    }
    
    try {
        $parser = new MbzParser($mbzPath, $coursePath);
        $courseData = $parser->parse();
        $parser->copyFilesToDestination($coursePath);
    } catch (Exception $e) {
        deleteDirectory($coursePath);
        throw new Exception('Erreur lors de l\'analyse du fichier : ' . $e->getMessage());
    }
    
    $info = [
        'prof_id' => $profName,
        'prof_name' => $_POST['prof_name'] ?? $profName,
        'course_name' => $courseData['course']['course_fullname'] ?? 'Cours sans nom',
        'created_at' => time(),
        'expires_at' => time() + (COURSE_LIFETIME_HOURS * 3600),
        'file_size' => $file['size'],
        'source' => 'upload',
        'uploaded_by' => $isAdmin ? 'admin' : 'prof',
    ];
    
    file_put_contents($coursePath . '/info.json', json_encode($info, JSON_PRETTY_PRINT));
    file_put_contents($coursePath . '/course_data.json', json_encode($courseData, JSON_PRETTY_PRINT));
    unlink($mbzPath);

    // Enqueue automatique pour la sync Drive : garantit que le cours sera repris
    // même si l'utilisateur ferme le navigateur immédiatement après l'upload.
    $queueFile = TMP_PATH . '/.drive_upload_queue.json';
    $queue = file_exists($queueFile) ? (json_decode(@file_get_contents($queueFile), true) ?: []) : [];
    $alreadyIn = false;
    foreach ($queue as $item) {
        if (($item['gdrive_id'] ?? '') === $profName) { $alreadyIn = true; break; }
    }
    if (!$alreadyIn) {
        $queue[] = [
            'gdrive_id' => $profName,
            'name' => $info['course_name'],
            'type' => 'temp',
            'added' => time(),
        ];
        @file_put_contents($queueFile, json_encode(array_values($queue), JSON_PRETTY_PRINT), LOCK_EX);
    }

    $courseUrl = 'view.php?id=' . urlencode($profName);

    echo json_encode([
        'success' => true,
        'url' => $courseUrl,
        'prof_id' => $profName,
        'course_name' => $info['course_name'],
        'expires_at' => $info['expires_at'],
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
