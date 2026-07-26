<?php
/**
 * EleaSecours - Visualisation de cours (style Éléa)
 */

// Limites PHP pour le téléchargement et l'extraction de cours volumineux
@ini_set('memory_limit', '512M');
@set_time_limit(300);

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session_check.php';

// Expiration custom de session (8h, contournement bridage OVH)
// Anonyme (élève via code) : laisse passer.
enforceSessionExpiry();

// Libérer le lock session immédiatement (évite corruption en cas de crash)
session_write_close();

require_once __DIR__ . '/includes/MbzParser.php';
require_once __DIR__ . '/includes/GoogleDriveLoader.php';
require_once __DIR__ . '/includes/CourseRenderer.php';

/**
 * Charge un cours Google Drive avec priorite a l index local Drive.
 * Retourne [courseData, basePath, baseUrl, needsDriveUpload]
 */
function loadGdriveCourse(string $fileId): array {
    // 1. Index local existe ? -> cours deja sur Drive, URLs directes
    $indexFile = DRIVE_INDEX_DIR . '/' . $fileId . '.json';
    $dataFile = DRIVE_INDEX_DIR . '/' . $fileId . '_data.json';

    if (file_exists($indexFile) && file_exists($dataFile)) {
        $fileIndex = json_decode(file_get_contents($indexFile), true);
        $courseData = json_decode(file_get_contents($dataFile), true);
        if ($courseData && $fileIndex) {
            $courseData['file_index'] = $fileIndex;
            $courseData['drive_direct'] = true;

            // Nettoyage opportuniste du gros dossier local s il reste
            $extractPath = TMP_PATH . '/course_' . md5($fileId);
            if (is_dir($extractPath)) {
                deleteDirectory($extractPath);
                error_log("view.php: nettoyage opportuniste de $extractPath");
            }

            return [$courseData, '', '', false];
        }
    }

    // 2. Sinon : flow existant (telecharge/extrait)
    $extractionCheck = checkExtractionStatus();
    if (!$extractionCheck['can_extract']) {
        throw new Exception($extractionCheck['message'] ?? "Serveur plein ou chargement en cours.");
    }

    $driveLoader = new GoogleDriveLoader();
    $courseData = $driveLoader->loadCourseViaDriveCache($fileId);

    if (!$courseData) {
        throw new Exception("Impossible de charger le cours depuis Google Drive");
    }

    if (!empty($courseData['drive_cached'])) {
        $basePath = '';
        $baseUrl = 'file_drive.php?course=' . urlencode($fileId) . '&file=';
    } else {
        $basePath = $courseData['tmp_path'];
        $baseUrl = 'file.php?path=' . urlencode($basePath) . '&file=';
    }

    return [$courseData, $basePath, $baseUrl, true];
}

$courseData = null;
$basePath = '';
$baseUrl = '';
$error = null;
$needsDriveUpload = false;
$tempCourseId = null;
$tempCourseName = '';
$source = 'local';
$gdriveId = '';
$courseIdentifier = '';
$courseType = 'local';
$hiddenActivities = ''; // Activités cachées pour le mode élève

try {
    // Code élève (nouveau système)
    if (isset($_GET['code'])) {
        $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['code']));
        $codesFile = COURSES_PATH . '/student_codes.json';
        
        if (!file_exists($codesFile)) {
            throw new Exception("Code invalide ou expiré");
        }
        
        $codes = json_decode(file_get_contents($codesFile), true) ?: [];
        
        if (!isset($codes[$code])) {
            throw new Exception("Code invalide ou expiré");
        }
        
        $codeData = $codes[$code];
        
        if (($codeData['expires'] ?? 0) < time()) {
            unset($codes[$code]);
            file_put_contents($codesFile, json_encode($codes));
            throw new Exception("Ce code a expiré. Demandez un nouveau code à votre professeur.");
        }
        
        $codeType = $codeData['type'] ?? 'gdrive';
        
        if ($codeType === 'local') {
            $profId = sanitizeProfName($codeData['local_id']);
            $coursePath = COURSES_PATH . '/' . $profId;
            
            if (!is_dir($coursePath)) {
                throw new Exception("Cours non trouvé ou expiré");
            }
            
            $dataFile = $coursePath . '/course_data.json';
            if (!file_exists($dataFile)) {
                throw new Exception("Données du cours non trouvées");
            }
            
            $courseData = json_decode(file_get_contents($dataFile), true);
            $courseData['extract_path'] = $coursePath;
            $basePath = $coursePath;
            $baseUrl = 'courses/' . $profId;
            $source = 'token';
            $courseIdentifier = $profId;
            $courseType = 'local';
            $hiddenActivities = $codeData['hidden'] ?? '';
        } else {
            [$courseData, $basePath, $baseUrl, $needsDriveUpload] = loadGdriveCourse($codeData['gdrive_id']);
            
            $source = 'token';
            $gdriveId = $codeData['gdrive_id'];
            $courseIdentifier = $gdriveId;
            $courseType = 'gdrive';
            $hiddenActivities = $codeData['hidden'] ?? '';
        }
    }
    // Token temporaire pour lien élève (ancien système, rétrocompatibilité)
    elseif (isset($_GET['token'])) {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['token']);
        $tokenFile = COURSES_PATH . '/tokens.json';
        
        if (!file_exists($tokenFile)) {
            throw new Exception("Lien invalide ou expiré");
        }
        
        $tokens = json_decode(file_get_contents($tokenFile), true) ?: [];
        
        if (!isset($tokens[$token])) {
            throw new Exception("Lien invalide ou expiré");
        }
        
        $tokenData = $tokens[$token];
        
        if ($tokenData['expires'] < time()) {
            unset($tokens[$token]);
            file_put_contents($tokenFile, json_encode($tokens));
            throw new Exception("Ce lien a expiré. Demandez un nouveau lien à votre professeur.");
        }
        
        $tokenType = $tokenData['type'] ?? 'gdrive';
        
        if ($tokenType === 'local') {
            // Token pour cours local
            $profId = sanitizeProfName($tokenData['local_id']);
            $coursePath = COURSES_PATH . '/' . $profId;
            
            if (!is_dir($coursePath)) {
                throw new Exception("Cours non trouvé ou expiré");
            }
            
            $dataFile = $coursePath . '/course_data.json';
            if (!file_exists($dataFile)) {
                throw new Exception("Données du cours non trouvées");
            }
            
            $courseData = json_decode(file_get_contents($dataFile), true);
            $courseData['extract_path'] = $coursePath;
            $basePath = $coursePath;
            $baseUrl = 'courses/' . $profId;
            $source = 'token';
            $courseIdentifier = $profId;
            $courseType = 'local';
            $hiddenActivities = $tokenData['hidden'] ?? '';
        } else {
            // Token pour cours Google Drive
            [$courseData, $basePath, $baseUrl, $needsDriveUpload] = loadGdriveCourse($tokenData['gdrive_id']);
            
            $source = 'token';
            $gdriveId = $tokenData['gdrive_id'];
            $courseIdentifier = $gdriveId;
            $courseType = 'gdrive';
            $hiddenActivities = $tokenData['hidden'] ?? '';
        }
    }
    // Cours local (par ID)
    elseif (isset($_GET['id'])) {
        $profId = sanitizeProfName($_GET['id']);
        $coursePath = COURSES_PATH . '/' . $profId;
        
        // Priorite : index Drive temporaire existe ? → servir via Drive directes
        $tempIndexFile = DRIVE_INDEX_DIR . '/temp_' . $profId . '.json';
        $tempDataFile = DRIVE_INDEX_DIR . '/temp_' . $profId . '_data.json';
        
        if (file_exists($tempIndexFile) && file_exists($tempDataFile)) {
            $fileIndex = json_decode(file_get_contents($tempIndexFile), true);
            $courseData = json_decode(file_get_contents($tempDataFile), true);
            if ($courseData && $fileIndex) {
                $courseData['file_index'] = $fileIndex;
                $courseData['drive_direct'] = true;
                $basePath = '';
                $baseUrl = '';
                $source = 'local';
                $courseIdentifier = $profId;
                $courseType = 'local';
                $needsDriveUpload = false;
                // Réinitialiser le compteur d'expiration à chaque ouverture
                // Toucher les fichiers d'index Drive (reset mtime = reset 24h)
                @touch($tempIndexFile);
                @touch($tempDataFile);
                // Aussi mettre à jour info.json si le dossier local existe encore
                $infoFile = $coursePath . '/info.json';
                if (file_exists($infoFile)) {
                    $infoData = json_decode(file_get_contents($infoFile), true);
                    $infoData['created_at'] = time();
                    $infoData['expires_at'] = time() + (COURSE_LIFETIME_HOURS * 3600);
                    @file_put_contents($infoFile, json_encode($infoData, JSON_PRETTY_PRINT), LOCK_EX);
                }
            }
        }
        
        // Sinon : servir localement
        if (!$courseData && is_dir($coursePath) && file_exists($coursePath . '/course_data.json')) {
            $dataFile = $coursePath . '/course_data.json';
            $courseData = json_decode(file_get_contents($dataFile), true);
            $courseData['extract_path'] = $coursePath;
            $basePath = $coursePath;
            $baseUrl = 'courses/' . $profId;
            $source = 'local';
            $courseIdentifier = $profId;
            $courseType = 'local';
            // Marquer pour upload Drive si pas encore fait
            $needsDriveUpload = true;
            $tempCourseId = $profId;
            // Lire le nom depuis info.json (contient le nom du fichier uploadé)
            $infoFile = $coursePath . '/info.json';
            if (file_exists($infoFile)) {
                $infoData = json_decode(file_get_contents($infoFile), true);
                $tempCourseName = $infoData['course_name'] ?? '';
                // Réinitialiser le compteur d'expiration à chaque ouverture (24h à partir de maintenant)
                $infoData['created_at'] = time();
                $infoData['expires_at'] = time() + (COURSE_LIFETIME_HOURS * 3600);
                @file_put_contents($infoFile, json_encode($infoData, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }
        
        if (!$courseData) {
            throw new Exception("Cours non trouvé ou expiré");
        }
    }
    // Cours Google Drive
    elseif (isset($_GET['gdrive'])) {
        $fileId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['gdrive']);
        
        if (empty($fileId)) {
            throw new Exception("ID de fichier invalide");
        }
        
        [$courseData, $basePath, $baseUrl, $needsDriveUpload] = loadGdriveCourse($fileId);
        
        $source = 'gdrive';
        $gdriveId = $fileId;
        $courseIdentifier = $fileId;
        $courseType = 'gdrive';
    }
    else {
        throw new Exception("Aucun cours spécifié");
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

if ($error) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erreur - <?= SITE_NAME ?></title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
        <link rel="stylesheet" href="assets/css/style.css">
        <?php include __DIR__ . '/includes/theme_assets.php'; ?>
    </head>
    <body>
        <div class="error-page">
            <div class="error-content">
                <div class="error-icon">😕</div>
                <h1>Oups !</h1>
                <p><?= htmlspecialchars($error) ?></p>
                <a href="index.php" class="btn btn-primary">← Retour à l'accueil</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$isDriveSource = (strpos($baseUrl, 'file_drive.php') !== false);
$renderer = new CourseRenderer($courseData, $basePath, $baseUrl);
$course = $courseData['course'] ?? [];
$sections = $courseData['sections'] ?? [];

// Prétraitement : fusionner les labels avec l'activité précédente
foreach ($sections as $sIndex => &$section) {
    $activities = $section['activities'] ?? [];
    $mergedActivities = [];
    
    foreach ($activities as $aIndex => $activity) {
        $type = $activity['type'] ?? '';
        
        if ($type === 'label') {
            // Fusionner le label avec l'activité précédente
            if (!empty($mergedActivities)) {
                $lastKey = array_key_last($mergedActivities);
                if (!isset($mergedActivities[$lastKey]['attached_labels'])) {
                    $mergedActivities[$lastKey]['attached_labels'] = [];
                }
                $mergedActivities[$lastKey]['attached_labels'][] = $activity;
            }
            // Si pas d'activité précédente, on ignore le label orphelin
        } else {
            $mergedActivities[] = $activity;
        }
    }
    
    $section['activities'] = $mergedActivities;
}
unset($section);

// Construire la liste plate des activités
$allActivities = [];
foreach ($sections as $sIndex => $section) {
    foreach ($section['activities'] ?? [] as $aIndex => $activity) {
        $allActivities[] = [
            'id' => 'activity-' . $sIndex . '-' . $aIndex,
            'section_index' => $sIndex,
            'activity_index' => $aIndex,
            'name' => $activity['name'] ?? 'Activité',
            'type' => $activity['type'] ?? 'unknown'
        ];
    }
}

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($course['course_fullname'] ?? 'Cours') ?> - <?= SITE_NAME ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
    <!-- Stub pour éviter les erreurs YUI/Moodle dans le contenu importé -->
    <script>
    window.YUI = window.YUI || function() { return { use: function() {} }; };
    window.M = window.M || { cfg: {}, util: {}, str: {} };
    window.require = window.require || function() {};
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php include __DIR__ . '/includes/theme_assets.php'; ?>
    <!-- Librairies pour génération PDF -->
    <script src="assets/js/html2canvas.min.js"></script>
    <script src="assets/js/jspdf.umd.min.js"></script>
    <!-- Pannellum pour les panoramas 360° (embarqué en inline) -->
    <style><?php readfile(__DIR__ . '/assets/css/pannellum.css'); ?></style>
    <script><?php readfile(__DIR__ . '/assets/js/pannellum.js'); ?></script>
    <style>
    * { box-sizing: border-box; }
    .pnlm-container, .pnlm-container * { box-sizing: content-box !important; }
    body { margin: 0; font-family: 'DM Sans', sans-serif; background: #f5f5f5; }
    
    .course-layout { display: flex; min-height: 100vh; }
    
    /* Sidebar */
    .course-sidebar {
        width: 280px;
        background: white;
        border-right: 1px solid #e0e0e0;
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0; left: 0;
        height: 100vh;
        z-index: 100;
        overflow-y: auto;
        transition: transform 0.3s, width 0.3s;
        padding-bottom: 70px;
    }
    .course-sidebar.collapsed {
        transform: translateX(-280px);
    }
    .sidebar-header {
        padding: 1rem;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        position: sticky;
        top: 0;
        background: white;
        z-index: 10;
    }
    .sidebar-close { display: none; background: none; border: none; font-size: 1.5rem; cursor: pointer; }
    .sidebar-title { font-weight: 600; font-size: 0.9rem; }
    
    /* Bouton toggle sidebar */
    .sidebar-toggle {
        position: fixed;
        top: 50%;
        left: 280px;
        transform: translateY(-50%);
        width: 24px;
        height: 48px;
        background: #5b21b6;
        border: none;
        border-radius: 0 6px 6px 0;
        color: white;
        cursor: pointer;
        z-index: 101;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        transition: left 0.3s, background 0.2s;
        box-shadow: 2px 0 8px rgba(0,0,0,0.1);
    }
    .sidebar-toggle:hover {
        background: #7c3aed;
    }
    .sidebar-toggle.collapsed {
        left: 0;
    }
    .sidebar-toggle .toggle-icon {
        transition: transform 0.3s;
    }
    .sidebar-toggle.collapsed .toggle-icon {
        transform: rotate(180deg);
    }
    
    /* Sections */
    .nav-section { border-bottom: 1px solid #f0f0f0; }
    .nav-section-header {
        display: flex;
        align-items: center;
        padding: 0.75rem 1rem;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        color: #333;
        background: #fafafa;
        gap: 0.5rem;
    }
    .nav-section-header:hover { background: #f0f0f0; }
    .nav-section-icon { transition: transform 0.2s; font-size: 0.7rem; flex-shrink: 0; }
    .nav-section.collapsed .nav-section-icon { transform: rotate(-90deg); }
    .nav-section.collapsed .nav-section-list { display: none; }
    .nav-section-header .section-title { flex: 1; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    
    /* Icônes de visibilité - alignées à droite */
    .visibility-toggle {
        width: 24px;
        height: 24px;
        padding: 0;
        background: none;
        border: none;
        cursor: pointer;
        font-size: 0.85rem;
        opacity: 0.4;
        transition: opacity 0.2s;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .visibility-toggle:hover { opacity: 1; }
    
    /* Œil très transparent quand masqué */
    .visibility-toggle.is-hidden {
        opacity: 0.15;
    }
    .visibility-toggle.is-hidden:hover {
        opacity: 0.5;
    }
    
    /* Éléments cachés */
    .nav-section.visibility-hidden .nav-section-header { opacity: 0.4; }
    .nav-section.visibility-hidden .nav-section-header .section-title { text-decoration: line-through; }
    .nav-item.visibility-hidden { opacity: 0.4; }
    .nav-item.visibility-hidden .nav-link { text-decoration: line-through; }
    
    /* Mode élève : cacher complètement les éléments non visibles */
    body.student-mode .nav-section.visibility-hidden { display: none; }
    body.student-mode .nav-item.visibility-hidden { display: none; }
    body.student-mode .activity-wrapper.visibility-hidden { display: none; }
    body.student-mode .visibility-toggle { display: none; }
    
    .nav-section-list { list-style: none; padding: 0; margin: 0; }
    .nav-item { 
        border-left: 3px solid transparent;
        display: flex;
        align-items: center;
        padding-right: 0.5rem;
    }
    .nav-item.active { border-left-color: #5b21b6; background: #f3f0ff; }
    
    /* Indicateur de complétion */
    .nav-completion-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        border: 2px solid #ccc;
        background: transparent;
        margin-left: 0.75rem;
        flex-shrink: 0;
        transition: all 0.3s;
    }
    .nav-item.completed .nav-completion-indicator {
        border-color: #4caf50;
        background: #4caf50;
    }
    
    .nav-link { display: block; padding: 0.6rem 0.5rem 0.6rem 0.5rem; color: #555; text-decoration: none; font-size: 0.85rem; cursor: pointer; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .nav-link:hover { background: #f5f5f5; }
    .nav-item.active .nav-link { color: #5b21b6; font-weight: 500; }
    
    /* Contenu principal */
    .course-main { flex: 1; margin-left: 280px; display: flex; flex-direction: column; min-height: 100vh; transition: margin-left 0.3s; }
    .course-main.sidebar-collapsed { margin-left: 0; }
    
    .course-header { background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%); color: white; padding: 1rem 2rem; }
    .course-header::after { display: none; } /* Supprime l'arrondi */
    .course-header-content { display: flex; align-items: center; gap: 1rem; }
    .back-btn { color: white; text-decoration: none; opacity: 0.8; font-size: 0.9rem; }
    .back-btn:hover { opacity: 1; }
    .course-title { margin: 0; font-size: 1.25rem; font-weight: 600; color: white; flex: 1; }
    .menu-toggle { display: none; background: rgba(255,255,255,0.2); border: none; color: white; padding: 0.5rem; border-radius: 4px; cursor: pointer; font-size: 1.2rem; }
    
    /* Badge Mode professeur */
    .mode-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.4rem 0.8rem;
        background: rgba(255,255,255,0.15);
        color: white;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        white-space: nowrap;
    }
    
    /* Boutons d'action header */
    .btn-header-action {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        background: rgba(255,255,255,0.2);
        color: white;
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 6px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .btn-header-action:hover {
        background: rgba(255,255,255,0.3);
        border-color: rgba(255,255,255,0.5);
    }
    .btn-header-action.btn-edit {
        background: rgba(34, 197, 94, 0.3);
        border-color: rgba(34, 197, 94, 0.5);
    }
    .btn-header-action.btn-edit:hover {
        background: rgba(34, 197, 94, 0.5);
        border-color: rgba(34, 197, 94, 0.7);
    }
    
    /* Bouton générer URL élève */
    .btn-student-link {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        background: rgba(255,255,255,0.2);
        color: white;
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 6px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .btn-student-link:hover {
        background: rgba(255,255,255,0.3);
        border-color: rgba(255,255,255,0.5);
    }
    
    .course-content { flex: 1; padding: 0.5rem 0.5rem 80px; max-width: 1100px; margin: 0 auto; width: 100%; }

    /* Conteneur pour le scale responsive (pas de transition par défaut : le scale est appliqué
       instantanément à l'ouverture de chaque activité pour éviter un effet de zoom visible) */
    .activity-wrapper { display: none; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; transform-origin: top center; }
    .activity-wrapper.active { display: block; }
    /* Transition activée uniquement pendant un zoom manuel (slider, boutons + / −) */
    .activity-wrapper.zooming { transition: transform 0.15s ease; }

    /* Barre de zoom : élément flex de la nav-bar, calée à droite après les boutons.
       flex-shrink:0 → elle garde sa taille ; c'est le bloc Précédent/Suivant qui
       rétrécit avant elle, ce qui évite tout chevauchement quelle que soit la largeur. */
    .viewer-zoom-bar {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        background: rgba(255,255,255,0.15);
        border-radius: 6px;
        font-size: 0.85rem;
    }
    .viewer-zoom-bar button {
        width: 26px; height: 26px;
        border: 1px solid rgba(255,255,255,0.3); border-radius: 4px;
        background: rgba(255,255,255,0.2); color: #fff;
        font-size: 14px; font-weight: bold; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .viewer-zoom-bar button:hover { background: rgba(255,255,255,0.35); }
    .viewer-zoom-bar input[type="range"] {
        width: 90px; height: 4px;
        accent-color: #fff;
    }
    .viewer-zoom-bar #viewerZoomLabel {
        min-width: 36px; text-align: center; color: #fff; font-weight: 600;
    }
    /* Sur écrans étroits, masquer slider et label pour économiser la place */
    @media (max-width: 600px) {
        .viewer-zoom-bar input[type="range"],
        .viewer-zoom-bar #viewerZoomLabel { display: none; }
    }
    
    /* Barre de navigation - pleine largeur, fixée en bas.
       display:flex pour aligner le bloc Précédent/Suivant et la barre de zoom sur
       une même ligne sans qu'ils se chevauchent (le bloc rétrécit avant le zoom). */
    .course-nav-bar {
        background: #5b21b6;
        padding: 1rem 2rem;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 100;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .nav-bar-content { width: 100%; max-width: 1100px; min-width: 0; margin: 0 auto; display: flex; justify-content: space-between; gap: 1rem; padding-left: 280px; transition: padding-left 0.3s; }
    .nav-bar-content.sidebar-collapsed { padding-left: 2rem; }
    .nav-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: rgba(255,255,255,0.15); color: white; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 500; cursor: pointer; }
    .nav-btn:hover { background: rgba(255,255,255,0.25); }
    .nav-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .nav-btn-next { background: white; color: #5b21b6; margin-left: auto; }
    .nav-btn-next:hover { background: #f3f0ff; }
    
    /* ===== H5P CoursePresentation ===== */
    /* Ratio 2:1 (même que l'éditeur). Le viewer est calé sur le rendu de l'ÉDITEUR (référence,
       zoom par défaut 70 %) : font de base 26.43px/1400px = 1.888cqi, line-height 1.5em,
       padding 8px≈0.303em, marge de paragraphe 1em. */
    .h5p-coursepresentation { background: #fff; border-radius: 8px; overflow: hidden; }
    .h5p-cp-slides-wrapper {
        position: relative;
        width: 100%;
        background: #F5F5F5;
        container-type: inline-size;
    }
    .h5p-cp-slide {
        position: relative;
        width: 100%;
        padding-bottom: 50%; /* ratio 2:1 */
        font-size: 1.888cqi; /* = 18.5/0.7 px sur 1400 : calé sur l'éditeur au zoom 70 % */
    }
    .h5p-cp-element {
        position: absolute;
        overflow: hidden;
    }
    .h5p-cp-element > * {
        width: 100%;
        height: 100%;
    }
    /* Les tableaux H5P.Table peuvent légèrement dépasser : overflow auto */
    .h5p-cp-table {
        height: 100%;
        overflow: auto;
        box-sizing: border-box;
    }
    .h5p-cp-table figure { margin: 0; width: 100%; }
    .h5p-cp-table table { width: 100%; }
    .h5p-cp-table td, .h5p-cp-table th { word-wrap: break-word; }
    .h5p-cp-table .table-overflow-protection { display: none; }
    /* Override pour les quiz dans CoursePresentation */
    .h5p-cp-element > .h5p-quiz-modern,
    .h5p-cp-element > .h5p-multichoice,
    .h5p-cp-element > .h5p-truefalse,
    .h5p-cp-element > .h5p-blanks,
    .h5p-cp-element > .h5p-singlechoiceset {
        height: auto !important;
        overflow: visible !important;
    }
    /* Exception pour DragQuestion qui gère son propre aspect-ratio */
    .h5p-cp-element > .h5p-dragquestion {
        height: auto !important;
        overflow: visible !important;
    }
    /* Exception pour Dialog Cards qui gère sa propre hauteur.
       Dans un parcours CP l'élément parent est dimensionné : pas de hauteur minimale
       (sinon un petit élément déborderait). Hors CP, c'est .h5p-dialogcards qui
       impose sa hauteur — voir min-height plus bas. */
    .h5p-cp-element > .h5p-dialogcards {
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
    .h5p-cp-text {
        background: transparent;
        color: #333;
        font-size: 1em;
        line-height: 1.5; /* sans unité : suit la taille de chaque portion (grosses polices/emoji non coupés). Identique à l'éditeur .cp-text-element */
        padding: 0.378em;    /* 10px sur 1400 = bordure 2px + padding 8px de l'élément éditeur */
        height: auto !important;
    }
    /* Interligne de paragraphe identique à l'éditeur (.cp-text-element p { margin:0 0 1em 0 }) */
    .h5p-cp-text p { margin: 0 0 1em 0; }
    .h5p-cp-text p:last-child { margin-bottom: 0; }
    .h5p-cp-image { max-width: 100%; height: auto; border-radius: 8px; }
    /* Surlignage : léger débordement façon marqueur (cohérent avec l'éditeur Course Presentation) */
    .course-content span[style*="background-color"] {
        /* Padding vertical 0 : sur le gros texte (1.5em) le fond fait déjà la hauteur de ligne,
           donc toute marge verticale le ferait déborder sur les lignes voisines. */
        padding: 0 0.4em;
        border-radius: 0.2em;
        -webkit-box-decoration-break: clone;
        box-decoration-break: clone;
    }
    /* Laisser le surlignage déborder de l'élément CoursePresentation (sinon rogné par sa box) */
    .course-content .h5p-cp-element:has(span[style*="background-color"]) { overflow: visible; }
    
    /* H5P MultiChoice - réponses avec étiquettes style Éléa */
    .h5p-multichoice .h5p-answers { display: flex; flex-direction: column; gap: 0.5rem; }
    .h5p-multichoice .h5p-answer-option {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        cursor: pointer;
        padding: 0.6rem 0.75rem;
        background: #f8f8f8;
        border: 1px solid #ddd;
        border-radius: 4px;
        transition: all 0.15s;
    }
    .h5p-multichoice .h5p-answer-option:hover {
        background: #f0f0f0;
        border-color: #ccc;
    }
    .h5p-multichoice .h5p-answer-option input[type="checkbox"],
    .h5p-multichoice .h5p-answer-option input[type="radio"] {
        width: 18px;
        height: 18px;
        margin: 0;
        flex-shrink: 0;
        accent-color: #1a73e8;
    }
    .h5p-multichoice .h5p-answer-text {
        font-size: 0.95rem;
        line-height: 1.4;
        color: #333;
    }
    .h5p-multichoice .h5p-answer-option.h5p-correct { 
        background: #e8f5e9; 
        border-color: #4caf50;
    }
    .h5p-multichoice .h5p-answer-option.h5p-incorrect { 
        background: #ffebee; 
        border-color: #f44336;
    }
    
    /* H5P MultiMediaChoice - grille d'images cliquables */
    .h5p-multimediachoice { padding: 0.5rem 0; }
    .h5p-mmc-grid {
        display: grid;
        gap: 0.75rem;
        margin: 1rem 0;
    }
    .h5p-mmc-option {
        position: relative;
        border: 3px solid #e0e0e0;
        border-radius: 12px;
        overflow: hidden;
        cursor: pointer;
        transition: border-color 0.2s, box-shadow 0.2s, transform 0.15s;
        aspect-ratio: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fafafa;
        user-select: none;
    }
    .h5p-mmc-option:hover {
        border-color: #90caf9;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transform: scale(1.02);
    }
    .h5p-mmc-option.selected {
        border-color: #1a73e8;
        box-shadow: 0 0 0 2px rgba(26,115,232,0.3);
    }
    .h5p-mmc-option.selected .h5p-mmc-check-indicator {
        opacity: 1;
    }
    .h5p-mmc-option img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .h5p-mmc-check-indicator {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #1a73e8;
        opacity: 0;
        transition: opacity 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .h5p-mmc-check-indicator::after {
        content: '✓';
        color: white;
        font-size: 14px;
        font-weight: bold;
    }
    .h5p-mmc-option.h5p-correct {
        border-color: #4caf50 !important;
        box-shadow: 0 0 0 2px rgba(76,175,80,0.3);
    }
    .h5p-mmc-option.h5p-correct .h5p-mmc-check-indicator {
        background: #4caf50;
        opacity: 1;
    }
    .h5p-mmc-option.h5p-incorrect {
        border-color: #f44336 !important;
        box-shadow: 0 0 0 2px rgba(244,67,54,0.3);
    }
    .h5p-mmc-option.h5p-incorrect .h5p-mmc-check-indicator {
        background: #f44336;
        opacity: 1;
    }
    .h5p-mmc-option.h5p-incorrect .h5p-mmc-check-indicator::after {
        content: '✗';
    }
    .h5p-mmc-option.h5p-missed {
        border-color: #ff9800 !important;
        box-shadow: 0 0 0 2px rgba(255,152,0,0.3);
    }
    .h5p-mmc-option.h5p-missed .h5p-mmc-check-indicator {
        background: #ff9800;
        opacity: 1;
    }
    .h5p-mmc-option.h5p-missed .h5p-mmc-check-indicator::after {
        content: '!';
    }
    .h5p-mmc-option.h5p-checked { pointer-events: none; }
    .h5p-mmc-no-image {
        font-size: 0.85rem;
        color: #999;
        padding: 1rem;
        text-align: center;
    }
    @media (max-width: 600px) {
        .h5p-mmc-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 0.5rem; }
    }
    
    /* ========== STYLES QUIZ MODERNES (comme éditeur) ========== */
    
    /* Container quiz moderne */
    .h5p-quiz-modern {
        padding: 12px !important;
        background: transparent !important;
        display: flex !important;
        flex-direction: column !important;
        height: auto !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* Question */
    .h5p-quiz-question {
        font-size: 1.1rem !important;
        color: #1f2937 !important;
        margin-bottom: 12px !important;
        line-height: 1.4 !important;
        font-weight: 500 !important;
        display: block !important;
    }
    
    /* Réponses QCM */
    .h5p-quiz-answers {
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
        flex: 1 !important;
        width: 100% !important;
    }
    .h5p-quiz-answer {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        padding: 8px 12px !important;
        background: rgba(255, 255, 255, 0.9) !important;
        border-radius: 6px !important;
        font-size: 0.95rem !important;
        border: 1px solid #d1d5db !important;
        cursor: pointer !important;
        transition: all 0.2s !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .h5p-quiz-answer:hover {
        background: #f3f4f6 !important;
        border-color: #9ca3af !important;
    }
    .h5p-quiz-answer.selected {
        background: #dbeafe !important;
        border-color: #2563eb !important;
    }
    .h5p-quiz-answer.h5p-correct {
        background: rgba(220, 252, 231, 0.9) !important;
        border-color: #22c55e !important;
    }
    .h5p-quiz-answer.h5p-incorrect {
        background: rgba(254, 226, 226, 0.9) !important;
        border-color: #ef4444 !important;
    }
    
    /* Marker checkbox */
    .h5p-quiz-marker {
        width: 20px !important;
        height: 20px !important;
        min-width: 20px !important;
        min-height: 20px !important;
        border: 2px solid #9ca3af !important;
        border-radius: 4px !important;
        flex-shrink: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        transition: all 0.2s !important;
        background: white !important;
    }
    .h5p-quiz-answer.selected .h5p-quiz-marker {
        background: #2563eb !important;
        border-color: #2563eb !important;
    }
    .h5p-quiz-answer.selected .h5p-quiz-marker::after {
        content: '✓' !important;
        color: white !important;
        font-size: 14px !important;
        font-weight: bold !important;
    }
    .h5p-quiz-answer.h5p-correct .h5p-quiz-marker {
        background: #22c55e !important;
        border-color: #22c55e !important;
    }
    .h5p-quiz-answer.h5p-correct .h5p-quiz-marker::after {
        content: '✓' !important;
        color: white !important;
        font-size: 14px !important;
        font-weight: bold !important;
    }
    .h5p-quiz-answer.h5p-incorrect .h5p-quiz-marker {
        background: #ef4444 !important;
        border-color: #ef4444 !important;
    }
    .h5p-quiz-answer.h5p-incorrect .h5p-quiz-marker::after {
        content: '✗' !important;
        color: white !important;
        font-size: 14px !important;
        font-weight: bold !important;
    }
    
    .h5p-quiz-answer-text {
        font-size: 0.95rem !important;
        color: #374151 !important;
        flex: 1 !important;
    }
    
    /* Réponses Vrai/Faux verticales */
    .h5p-quiz-tf-answers {
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
        margin-top: 12px !important;
        width: 100% !important;
    }
    .h5p-quiz-tf-answer {
        display: block !important;
        padding: 8px 14px !important;
        border-radius: 6px !important;
        font-size: 0.95rem !important;
        text-align: left !important;
        background: rgba(255, 255, 255, 0.9) !important;
        border: 1px solid #d1d5db !important;
        color: #374151 !important;
        cursor: pointer !important;
        transition: all 0.2s !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .h5p-quiz-tf-answer:hover {
        background: #f3f4f6 !important;
        border-color: #9ca3af !important;
    }
    .h5p-quiz-tf-answer.selected {
        background: #dbeafe !important;
        border-color: #2563eb !important;
        font-weight: 600 !important;
    }
    .h5p-quiz-tf-answer.h5p-correct {
        background: rgba(220, 252, 231, 0.9) !important;
        border-color: #22c55e !important;
        color: #166534 !important;
        font-weight: 600 !important;
    }
    .h5p-quiz-tf-answer.h5p-incorrect {
        background: rgba(254, 226, 226, 0.9) !important;
        border-color: #ef4444 !important;
        color: #991b1b !important;
    }
    
    /* Titre Texte à trous */
    .h5p-quiz-blanks-title {
        font-size: 1.1rem !important;
        font-weight: 500 !important;
        color: #1f2937 !important;
        margin-bottom: 12px !important;
        padding-bottom: 8px !important;
        border-bottom: 2px solid #e5e7eb !important;
        display: block !important;
    }
    
    /* Texte à trous */
    .h5p-quiz-blanks-text {
        font-size: 1rem !important;
        line-height: 2 !important;
        color: #1f2937 !important;
        flex: 1 !important;
    }
    .h5p-quiz-blanks-line {
        margin-bottom: 12px !important;
        display: block !important;
    }
    .h5p-quiz-blank-input {
        display: inline-block !important;
        background: #fef3c7 !important;
        border: 2px dashed #f59e0b !important;
        border-radius: 6px !important;
        padding: 4px 12px !important;
        min-width: 80px !important;
        text-align: center !important;
        font-weight: 600 !important;
        color: #92400e !important;
        font-size: 0.9rem !important;
        margin: 0 4px !important;
        vertical-align: middle !important;
    }
    .h5p-quiz-blank-input:focus {
        outline: none !important;
        border-color: #2563eb !important;
        background: #dbeafe !important;
        color: #1e40af !important;
    }
    .h5p-quiz-blank-input.h5p-correct {
        background: #dcfce7 !important;
        border-color: #22c55e !important;
        border-style: solid !important;
        color: #166534 !important;
    }
    .h5p-quiz-blank-input.h5p-incorrect {
        background: #fee2e2 !important;
        border-color: #ef4444 !important;
        border-style: solid !important;
        color: #991b1b !important;
    }
    
    /* Bouton Vérifier */
    .h5p-quiz-btn-container {
        margin-top: 12px !important;
        padding-top: 8px !important;
        display: block !important;
    }
    .h5p-quiz-verify-btn {
        background: #2563eb !important;
        color: white !important;
        border: none !important;
        padding: 8px 20px !important;
        border-radius: 6px !important;
        font-size: 0.95rem !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: all 0.2s !important;
    }
    .h5p-quiz-verify-btn:hover {
        background: #1d4ed8 !important;
    }
    .h5p-quiz-verify-btn:disabled {
        background: #9ca3af !important;
        cursor: not-allowed !important;
    }
    
    /* Spacer anti-scroll */
    .h5p-quiz-spacer {
        height: 20px !important;
        flex-shrink: 0 !important;
    }
    
    /* Indicateur de navigation (Vrai/Faux multi-questions) */
    .h5p-quiz-nav-indicator {
        margin-top: 16px !important;
        padding: 10px 16px !important;
        background: rgba(37, 99, 235, 0.1) !important;
        border-radius: 8px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 12px !important;
    }
    .h5p-quiz-nav-info {
        font-size: 1rem !important;
        font-weight: 600 !important;
        color: #2563eb !important;
    }
    
    /* ========== FIN STYLES QUIZ MODERNES ========== */
    
    
    /* Barre de progression avec indicateurs - Style Éléa */
    .h5p-cp-progressbar-container {
        position: relative;
        background: #546e7a;
        padding: 0;
        height: 18px;
        display: flex;
    }
    .h5p-cp-progressbar { 
        height: 100%; 
        background: #546e7a; 
        position: relative; 
        cursor: pointer;
        flex: 1;
        display: flex;
    }
    /* Segments de la barre */
    .h5p-cp-segment {
        height: 100%;
        flex: 1;
        background: #546e7a;
        border-right: 1px solid #455a64;
        transition: background 0.3s;
    }
    .h5p-cp-segment:last-child {
        border-right: none;
    }
    .h5p-cp-segment.viewed {
        background: #1a73e8;
    }
    .h5p-cp-segment.current {
        box-shadow: inset 0 0 0 1px #fff, 0 0 4px rgba(255, 255, 255, 0.45);
        position: relative;
        z-index: 1;
    }
    .h5p-cp-segment.current::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 0;
        border-left: 5px solid transparent;
        border-right: 5px solid transparent;
        border-bottom: 6px solid #fff;
        pointer-events: none;
    }

    /* Indicateurs de slides (ronds) - positionnés uniquement sur les slides avec activité */
    .h5p-cp-indicators {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 100%;
        pointer-events: none;
        z-index: 2;
    }
    .h5p-cp-indicator {
        position: absolute;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid white;
        background: transparent;
        cursor: pointer;
        pointer-events: auto;
        transition: all 0.2s;
    }
    .h5p-cp-indicator:hover {
        transform: translate(-50%, -50%) scale(1.3);
    }
    .h5p-cp-indicator.completed {
        background: white;
    }
    
    /* Navigation */
    .h5p-cp-nav { display: flex; align-items: center; padding: 0.75rem 1rem; background: white; border-top: 1px solid #e0e0e0; }
    .h5p-cp-nav-left, .h5p-cp-nav-right { flex: 1; }
    .h5p-cp-nav-right { display: flex; justify-content: flex-end; }
    .h5p-cp-nav-center { display: flex; align-items: center; gap: 1rem; }
    .h5p-cp-nav-btn { width: 36px; height: 36px; border-radius: 50%; border: none; background: #f0f0f0; cursor: pointer; font-size: 1rem; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .h5p-cp-nav-btn:hover { background: #e0e0e0; transform: scale(1.05); }
    .h5p-cp-fullscreen { width: 32px; height: 32px; background: transparent; }
    .h5p-cp-fullscreen:hover { background: #f0f0f0; }
    .h5p-cp-progress { font-size: 1rem; color: #666; font-weight: 500; }
    
    /* CoursePresentation Fullscreen — le cqi s'adapte automatiquement */
    .h5p-coursepresentation:fullscreen,
    .h5p-coursepresentation:-webkit-full-screen {
        background: #222;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-slides-wrapper,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-slides-wrapper {
        background: #222;
        width: 100%;
        max-width: calc(100vh * 2); /* max width pour garder le ratio 2:1 */
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-progressbar-container,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-progressbar-container {
        background: #333;
        border-bottom: none;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-nav,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-nav {
        background: #333;
        border-top: none;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-nav-btn,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-nav-btn {
        background: #555;
        color: #fff;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-nav-btn:hover,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-nav-btn:hover {
        background: #666;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-progress,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-progress {
        color: #ccc;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-fullscreen,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-fullscreen {
        background: transparent;
        color: #ccc;
    }
    .h5p-coursepresentation:fullscreen .h5p-cp-fullscreen:hover,
    .h5p-coursepresentation:-webkit-full-screen .h5p-cp-fullscreen:hover {
        background: #555;
    }
    
    /* H5P Blanks (Fill in the blanks) */
    .h5p-blanks { padding: 1rem; }
    .h5p-blanks-text { font-size: 1.1rem; line-height: 2; }
    .h5p-blank-input {
        display: inline-block;
        width: 120px;
        padding: 0.3rem 0.5rem;
        border: 2px solid #ccc;
        border-radius: 4px;
        font-size: 1rem;
        text-align: center;
        margin: 0 0.25rem;
        transition: border-color 0.2s;
    }
    .h5p-blank-input:focus { border-color: #1a73e8; outline: none; }
    .h5p-blank-input.h5p-correct { border-color: #4caf50; background: #e8f5e9; }
    .h5p-blank-input.h5p-incorrect { border-color: #f44336; background: #ffebee; }
    
    /* H5P Dialog Cards avec animation flip */
    .h5p-dialogcards {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        perspective: 1000px;
        /* Les deux faces sont en position:absolute : la carte n'a aucune hauteur
           intrinsèque. Dans un parcours CP le parent est dimensionné, mais en
           activité autonome il ne l'est pas → sans ce minimum, la carte est aplatie. */
        min-height: 300px;
    }
    .h5p-dc-card {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .h5p-dc-card-inner {
        position: relative;
        width: 100%;
        height: 100%;
        flex: 1;
        transition: transform 0.6s;
        transform-style: preserve-3d;
    }
    .h5p-dc-card.flipped .h5p-dc-card-inner {
        transform: rotateY(180deg);
    }
    .h5p-dc-front, .h5p-dc-back { 
        position: absolute;
        top: 0;
        left: 0;
        width: 100%; 
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 0.75rem;
        box-sizing: border-box;
        background: #ffffff;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        border: 1px solid #e0e0e0;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
    }
    .h5p-dc-back {
        transform: rotateY(180deg);
    }
    .h5p-dc-front img, .h5p-dc-back img { 
        max-width: 90%; 
        max-height: 45%; 
        object-fit: contain;
        border-radius: 4px; 
        margin-bottom: 0.5rem;
        flex-shrink: 0;
    }
    .h5p-dc-text { 
        flex: 1;
        width: 100%;
        overflow: auto;
        text-align: center;
        font-size: 0.9rem; 
        line-height: 1.4;
        color: #333333;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .h5p-dc-text p { margin: 0.2rem 0; }
    .h5p-dc-btn {
        flex-shrink: 0;
        margin-top: auto;
        padding: 0.5rem 1.25rem;
        background: #1a73e8;
        color: #ffffff;
        border: none;
        border-radius: 4px;
        font-size: 0.85rem;
        cursor: pointer;
    }
    .h5p-dc-btn:hover { background: #1557b0; }
    .h5p-dc-nav {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        padding-top: 0.5rem;
    }
    .h5p-dc-nav-btn {
        border: 1px solid #d0d5dd;
        background: #ffffff;
        color: #1a73e8;
        border-radius: 4px;
        padding: 0.15rem 0.5rem;
        font-size: 0.8rem;
        line-height: 1.4;
        cursor: pointer;
    }
    .h5p-dc-nav-btn:hover:not(:disabled) { background: #e8f0fe; }
    .h5p-dc-nav-btn:disabled { opacity: 0.35; cursor: default; }
    .h5p-dc-progress { font-size: 0.75rem; font-weight: 600; color: #666666; white-space: nowrap; }
    
    /* H5P Flashcards */
    .h5p-flashcards { padding: 1rem; }
    .h5p-fc-card {
        background: #f5f5f5;
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
    }
    .h5p-fc-front img { max-width: 100%; max-height: 200px; border-radius: 8px; margin-bottom: 1rem; }
    .h5p-fc-text { font-size: 1.1rem; line-height: 1.6; margin-bottom: 1rem; }
    .h5p-fc-answer { display: flex; gap: 0.5rem; justify-content: center; align-items: center; flex-wrap: wrap; }
    .h5p-fc-answer input { max-width: 200px; }
    .h5p-fc-nav { display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1rem; }
    .h5p-fc-progress { font-weight: 600; color: #666; }
    
    /* H5P Memory Game */
    .h5p-memorygame { padding: 1rem; }
    .h5p-memory-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1rem; }
    .h5p-memory-card {
        aspect-ratio: 1;
        background: #e0e0e0;
        border-radius: 8px;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        transition: transform 0.3s;
    }
    .h5p-memory-card:hover { transform: scale(1.05); }
    .h5p-memory-front, .h5p-memory-back {
        position: absolute;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        backface-visibility: hidden;
        transition: opacity 0.3s;
    }
    .h5p-memory-front { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-size: 2rem; }
    .h5p-memory-back { background: white; opacity: 0; }
    .h5p-memory-back img { max-width: 90%; max-height: 90%; object-fit: contain; }
    .h5p-memory-card.flipped .h5p-memory-front { opacity: 0; }
    .h5p-memory-card.flipped .h5p-memory-back { opacity: 1; }
    .h5p-memory-card.matched { background: #c8e6c9; }
    .h5p-memory-card.matched .h5p-memory-front { opacity: 0; }
    .h5p-memory-card.matched .h5p-memory-back { opacity: 1; }
    .h5p-memory-info { display: flex; justify-content: space-between; align-items: center; }
    
    /* H5P Game Map */
    .h5p-gamemap { padding: 1rem; }
    .h5p-gamemap-container { position: relative; }
    .h5p-gamemap-bg { width: 100%; border-radius: 12px; display: block; }
    
    /* SVG pour les chemins */
    .h5p-gamemap-paths {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        pointer-events: none;
        z-index: 5;
    }
    .h5p-gamemap-path {
        stroke: white;
        stroke-width: 0.5;
        stroke-dasharray: 1.5, 1.5;
        stroke-linecap: round;
        fill: none;
        filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5));
    }
    .h5p-gamemap-path.active {
        stroke: #4caf50;
        stroke-width: 0.7;
    }
    
    .h5p-gamemap-steps { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
    .h5p-gamemap-step {
        position: absolute;
        transform: translate(-50%, -50%);
        background: #ffc107;
        color: #333;
        border-radius: 50%;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 3px 10px rgba(0,0,0,0.4);
        transition: transform 0.2s, box-shadow 0.2s, background 0.3s;
        z-index: 10;
        border: 3px solid white;
    }
    .h5p-gamemap-step:hover:not(.locked) { 
        transform: translate(-50%, -50%) scale(1.15); 
        box-shadow: 0 5px 15px rgba(0,0,0,0.5); 
    }
    .h5p-gamemap-step.completed { 
        background: #4caf50;
        color: white;
    }
    .h5p-gamemap-step.locked {
        background: #b71c1c;
        color: white;
        cursor: not-allowed;
        opacity: 0.9;
    }
    .h5p-gamemap-step.locked:hover {
        transform: translate(-50%, -50%);
    }
    .h5p-gamemap-step.final {
        background: #ff9800;
    }
    .h5p-gamemap-step.final.completed {
        background: #4caf50;
    }
    .h5p-gamemap-step-icon { font-weight: bold; font-size: 0.9rem; }
    .h5p-gamemap-step-label { 
        display: none;
        position: absolute;
        bottom: -20px;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
        font-size: 0.7rem;
        background: rgba(0,0,0,0.7);
        color: white;
        padding: 2px 6px;
        border-radius: 3px;
    }
    .h5p-gamemap-step:hover .h5p-gamemap-step-label {
        display: block;
    }
    
    .h5p-gamemap-modal {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.6);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .h5p-gamemap-modal-content {
        background: white;
        border-radius: 12px;
        max-width: 600px;
        width: 90%;
        max-height: 80vh;
        overflow: auto;
    }
    .h5p-gamemap-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #eee;
    }
    .h5p-gamemap-modal-header h4 { margin: 0; }
    .h5p-gamemap-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #666;
    }
    .h5p-gamemap-modal-body { padding: 1.5rem; }
    .h5p-gamemap-progress { margin-top: 1rem; text-align: center; color: #666; }
    
    /* Message de fin */
    .h5p-gamemap-finish {
        text-align: center;
        padding: 2rem;
    }
    .h5p-gamemap-finish-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
    }
    .h5p-gamemap-finish h3 {
        color: #4caf50;
        margin: 0 0 0.5rem 0;
    }
    .h5p-gamemap-finish p {
        color: #666;
        margin: 0;
    }
    
    /* H5P Virtual Tour 360 */
    .h5p-virtualtour { padding: 1rem; }
    .h5p-vt-viewer { position: relative; border-radius: 12px; overflow: hidden; background: #111; }
    
    /* Pannellum viewer */
    .h5p-vt-pannellum {
        width: 100%;
        height: 400px;
        border-radius: 12px;
        overflow: hidden;
        background: #000;
    }
    
    /* Hotspots personnalisés "+" avec texte */
    .h5p-vt-pannellum .pnlm-hotspot-base {
        cursor: pointer;
    }
    .h5p-vt-pannellum .pnlm-hotspot {
        background: none !important;
        border: none !important;
        width: auto !important;
        height: auto !important;
    }
    .vt-custom-hotspot {
        display: flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }
    .vt-hotspot-plus {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        background: rgba(30, 144, 255, 0.9);
        color: white;
        border-radius: 50%;
        font-size: 18px;
        font-weight: bold;
        line-height: 1;
        box-shadow: 0 2px 8px rgba(0,0,0,0.4);
        transition: transform 0.2s, background 0.2s;
        flex-shrink: 0;
    }
    .vt-custom-hotspot:hover .vt-hotspot-plus {
        transform: scale(1.15);
        background: rgba(30, 144, 255, 1);
    }
    .vt-hotspot-label {
        background: rgba(0,0,0,0.75);
        color: white;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    }
    /* Cacher les tooltips par défaut de Pannellum */
    .h5p-vt-pannellum .pnlm-tooltip {
        display: none !important;
    }
    
    .h5p-vt-nav {
        display: flex;
        gap: 0.5rem;
        padding: 1rem;
        flex-wrap: wrap;
        justify-content: center;
    }
    .h5p-vt-nav-btn {
        padding: 0.5rem 1rem;
        border: 2px solid #667eea;
        background: white;
        color: #667eea;
        border-radius: 20px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
    }
    .h5p-vt-nav-btn:hover { background: #667eea; color: white; }
    .h5p-vt-nav-btn.active { background: #667eea; color: white; }
    .h5p-vt-modal {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.6);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .h5p-vt-modal-content {
        background: white;
        border-radius: 12px;
        max-width: 500px;
        width: 90%;
        max-height: 80vh;
        overflow: auto;
    }
    .h5p-vt-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #eee;
    }
    .h5p-vt-modal-header h4 { margin: 0; }
    .h5p-vt-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #666;
    }
    .h5p-vt-modal-body { padding: 1.5rem; }
    
    /* Mapmodules */
    .mapmodules-map img { max-width: 100%; height: auto; }
    
    /* Interactive Video - Style sobre */
    .h5p-interactivevideo { 
        padding: 0; 
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .iv-container {
        position: relative;
        width: 100%;
        height: 100%;
        background: #000;
        border-radius: 8px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .iv-video-wrapper {
        position: relative;
        flex: 1;
        min-height: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .iv-video {
        width: 100%;
        max-height: 100%;
        display: block;
        object-fit: contain;
    }
    .iv-loading {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(0,0,0,0.7);
        color: white;
        font-size: 1.2rem;
    }
    .iv-error {
        padding: 1rem;
        background: #fee2e2;
        color: #dc2626;
        border-radius: 8px;
        margin-top: 0.5rem;
        text-align: center;
    }
    .iv-error a {
        color: #dc2626;
        text-decoration: underline;
    }
    .iv-overlay {
        position: absolute;
        pointer-events: none;
        /* Position ajustée par JS pour correspondre à la vidéo */
    }
    
    /* Barre de contrôles en dessous */
    .iv-controls-wrapper {
        background: #333;
        flex-shrink: 0;
    }
    
    /* Barre de progression fine */
    .iv-progress-container {
        padding: 0;
    }
    .iv-progress-bar {
        position: relative;
        height: 4px;
        background: #555;
        cursor: pointer;
        transition: height 0.15s;
    }
    .iv-progress-bar:hover {
        height: 6px;
    }
    .iv-progress-played {
        position: absolute;
        top: 0;
        left: 0;
        height: 100%;
        background: #e53935;
        pointer-events: none;
    }
    .iv-progress-markers {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        pointer-events: none;
    }
    .iv-marker {
        position: absolute;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 8px;
        height: 8px;
        background: #fbbf24;
        border-radius: 50%;
        box-shadow: 0 0 0 1px rgba(0,0,0,0.2);
    }
    
    /* Contrôles en ligne */
    .iv-controls {
        display: flex;
        align-items: center;
        padding: 4px 8px;
        gap: 2px;
    }
    .iv-btn {
        background: none;
        border: none;
        color: #ccc;
        cursor: pointer;
        padding: 4px 6px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s, color 0.2s;
    }
    .iv-btn:hover {
        background: rgba(255,255,255,0.1);
        color: #fff;
    }
    .iv-btn svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }
    .iv-time {
        color: #bbb;
        font-size: 0.75rem;
        margin: 0 6px;
        font-family: system-ui, sans-serif;
    }
    .iv-spacer {
        flex: 1;
    }
    .iv-speed-btn {
        background: none;
        border: none;
        color: #bbb;
        cursor: pointer;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 500;
        transition: background 0.2s;
    }
    .iv-speed-btn:hover {
        background: rgba(255,255,255,0.1);
        color: #fff;
    }
    
    /* Fullscreen */
    .iv-container:fullscreen,
    .iv-container:-webkit-full-screen {
        background: #000;
        display: flex;
        flex-direction: column;
    }
    .iv-container:fullscreen .iv-video-wrapper,
    .iv-container:-webkit-full-screen .iv-video-wrapper {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .iv-container:fullscreen .iv-video,
    .iv-container:-webkit-full-screen .iv-video {
        max-height: calc(100vh - 36px);
        width: auto;
        max-width: 100%;
    }
    
    .iv-interaction {
        position: absolute;
        pointer-events: auto;
        transform: translate(-50%, -50%); /* Centrer sur le point x,y */
        max-width: 45%;
        animation: ivFadeIn 0.3s ease;
        z-index: 10;
    }
    @keyframes ivFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    /* Mode bouton */
    .iv-display-button {
        max-width: none;
    }
    .iv-inter-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(255, 255, 255, 0.95);
        border: none;
        border-radius: 20px;
        padding: 6px 12px;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        font-size: 0.85rem;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .iv-inter-btn:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }
    .iv-inter-btn-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        background: #667eea;
        color: white;
        border-radius: 50%;
        font-weight: bold;
        font-size: 14px;
    }
    .iv-inter-btn-label {
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    /* Popup au clic */
    .iv-inter-popup {
        position: absolute;
        top: 100%;
        left: 0;
        margin-top: 8px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        min-width: 200px;
        max-width: 350px;
        z-index: 100;
        animation: ivFadeIn 0.2s ease;
    }
    .iv-inter-popup-close {
        position: absolute;
        top: 4px;
        right: 4px;
        background: none;
        border: none;
        font-size: 1.2rem;
        cursor: pointer;
        color: #666;
        padding: 4px 8px;
        line-height: 1;
    }
    .iv-inter-popup-close:hover {
        color: #333;
    }
    .iv-inter-popup-content {
        padding: 12px 16px;
        font-size: 0.9rem;
        line-height: 1.5;
    }
    .iv-inter-popup-content p {
        margin: 0 0 0.5rem;
    }
    .iv-inter-popup-content p:last-child {
        margin-bottom: 0;
    }
    
    /* Mode poster (affichage direct) */
    .iv-interaction-content {
        background: rgba(255,255,255,0.95);
        padding: 12px 16px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        font-size: 0.9rem;
        line-height: 1.5;
    }
    .iv-interaction-content p { margin: 0 0 0.5rem; }
    .iv-interaction-content p:last-child { margin-bottom: 0; }
    .iv-type-nil .iv-label {
        background: rgba(102, 126, 234, 0.9);
        color: white;
        padding: 8px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .iv-question {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 6px;
        margin-top: 8px;
    }
    .iv-question ul {
        margin: 0.5rem 0 0;
        padding-left: 1.5rem;
    }
    
    /* Interactions interactives dans la vidéo */
    .iv-multichoice, .iv-truefalse, .iv-blanks {
        background: rgba(255,255,255,0.98);
        padding: 16px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        min-width: 280px;
        max-width: 400px;
    }
    .iv-mc-question, .iv-tf-question {
        font-weight: 600;
        margin-bottom: 12px;
        color: #333;
        line-height: 1.4;
    }
    .iv-mc-answers {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 12px;
    }
    .iv-mc-option {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        background: #f5f5f5;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .iv-mc-option:hover {
        background: #eef2ff;
        border-color: #667eea;
    }
    .iv-mc-option input {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    .iv-mc-option.correct {
        background: #d1fae5 !important;
        border-color: #10b981 !important;
    }
    .iv-mc-option.incorrect {
        background: #fee2e2 !important;
        border-color: #ef4444 !important;
    }
    .iv-mc-option.was-correct {
        background: #fef3c7;
        border-color: #f59e0b;
    }
    .iv-tf-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 12px;
    }
    .iv-tf-btn {
        flex: 1;
        padding: 12px 16px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        background: #f5f5f5;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 500;
        transition: all 0.2s;
    }
    .iv-tf-btn:hover {
        transform: translateY(-2px);
    }
    .iv-tf-true:hover {
        background: #d1fae5;
        border-color: #10b981;
    }
    .iv-tf-false:hover {
        background: #fee2e2;
        border-color: #ef4444;
    }
    .iv-tf-btn.selected-correct {
        background: #d1fae5 !important;
        border-color: #10b981 !important;
        color: #065f46;
    }
    .iv-tf-btn.selected-incorrect {
        background: #fee2e2 !important;
        border-color: #ef4444 !important;
        color: #991b1b;
    }
    .iv-tf-btn.was-correct {
        background: #fef3c7;
        border-color: #f59e0b;
    }
    .iv-blanks-text {
        font-size: 1rem;
        line-height: 2;
        margin-bottom: 12px;
    }
    .iv-blank-input {
        display: inline-block;
        width: 100px;
        padding: 4px 8px;
        border: 2px solid #667eea;
        border-radius: 4px;
        font-size: 1rem;
        text-align: center;
        background: #f8f9ff;
        margin: 0 4px;
    }
    .iv-blank-input:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
    }
    .iv-blank-input.correct {
        background: #d1fae5 !important;
        border-color: #10b981 !important;
    }
    .iv-blank-input.incorrect {
        background: #fee2e2 !important;
        border-color: #ef4444 !important;
    }
    .iv-check-btn {
        width: 100%;
        padding: 10px 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .iv-check-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    .iv-check-btn:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
    }
    .iv-feedback {
        margin-top: 10px;
        padding: 10px;
        border-radius: 6px;
        text-align: center;
        font-weight: 500;
        display: none;
    }
    .iv-feedback.show {
        display: block;
    }
    .iv-feedback.correct {
        background: #d1fae5;
        color: #065f46;
    }
    .iv-feedback.incorrect {
        background: #fee2e2;
        color: #991b1b;
    }
    .iv-text-content, .iv-label-content, .iv-generic {
        font-size: 0.95rem;
        line-height: 1.5;
    }
    .iv-text-content p { margin: 0 0 0.5rem; }
    .iv-text-content p:last-child { margin-bottom: 0; }
    
    /* SingleChoiceSet */
    .iv-singlechoice {
        background: rgba(255,255,255,0.98);
        padding: 16px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        min-width: 280px;
        max-width: 400px;
    }
    .iv-sc-question {
        font-weight: 600;
        margin-bottom: 12px;
        color: #333;
        line-height: 1.4;
    }
    .iv-sc-answers {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .iv-sc-option {
        display: block;
        width: 100%;
        padding: 12px 16px;
        background: #f5f5f5;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.95rem;
        text-align: left;
        transition: all 0.2s;
    }
    .iv-sc-option:hover:not(:disabled) {
        background: #eef2ff;
        border-color: #667eea;
        transform: translateX(4px);
    }
    .iv-sc-option:disabled {
        cursor: default;
    }
    .iv-sc-option.correct {
        background: #d1fae5 !important;
        border-color: #10b981 !important;
    }
    .iv-sc-option.incorrect {
        background: #fee2e2 !important;
        border-color: #ef4444 !important;
    }
    .iv-sc-option.was-correct {
        background: #fef3c7 !important;
        border-color: #f59e0b !important;
    }
    
    /* Question Set */
    .h5p-questionset {
        padding: 1rem;
        max-width: 800px;
        margin: 0 auto;
    }
    .h5p-qs-intro {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        text-align: center;
    }
    .h5p-qs-question {
        background: #fff;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .h5p-qs-progressbar {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin: 1.5rem 0;
    }
    .h5p-qs-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #e0e0e0;
        cursor: pointer;
        transition: all 0.2s;
    }
    .h5p-qs-dot:hover {
        background: #bbb;
        transform: scale(1.1);
    }
    .h5p-qs-dot.active {
        background: #667eea;
        transform: scale(1.2);
    }
    .h5p-qs-dot.done {
        background: #22c55e;
    }
    .h5p-qs-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 10px;
        margin-top: 1rem;
    }
    .h5p-qs-btn {
        padding: 0.6rem 1.2rem;
        border: none;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        background: #667eea;
        color: white;
    }
    .h5p-qs-btn:hover {
        background: #5a6fd6;
        transform: translateY(-1px);
    }
    .h5p-qs-prev {
        background: #6c757d;
    }
    .h5p-qs-prev:hover {
        background: #5a6268;
    }
    .h5p-qs-progress {
        font-weight: 600;
        color: #444;
    }
    .h5p-qs-result {
        text-align: center;
        padding: 2rem;
        background: #f8f9fa;
        border-radius: 12px;
    }
    .h5p-qs-result.passed {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    }
    .h5p-qs-result.failed {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    }
    .h5p-qs-score {
        display: flex;
        justify-content: center;
        align-items: baseline;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .h5p-qs-score .score-value {
        font-size: 2.5rem;
        font-weight: 700;
        color: #333;
    }
    .h5p-qs-score .score-percent {
        font-size: 1.5rem;
        color: #666;
    }
    .h5p-qs-message {
        font-size: 1.25rem;
        margin-bottom: 1.5rem;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .course-sidebar { transform: translateX(-100%); }
        .course-sidebar.open { transform: translateX(0); }
        .sidebar-close { display: block; }
        .sidebar-toggle { display: none; }
        .menu-toggle { display: block; }
        .course-main { margin-left: 0; }
        .nav-bar-content { padding-left: 1rem; padding-right: 1rem; }
        .course-content { padding: 1rem; padding-bottom: 100px; }
        .btn-student-link { padding: 0.4rem 0.6rem; font-size: 0.75rem; }
        .btn-student-link span { display: none; }
        .btn-header-action { padding: 0.4rem 0.6rem; font-size: 0.75rem; }
        .mode-badge { display: none; }
        .course-header-content { flex-wrap: wrap; gap: 0.5rem; }
    }
    </style>
    
    <script>
    // ===== ZOOM DU VIEWER =====
    // Auto-fit à chaque activité (par défaut), zoom manuel possible via la barre flottante
    // (boutons − / +, slider 30-200%, label %, bouton ⊞ pour revenir au fit).
    var _viewerZoomLevel  = 100;     // % courant
    var _viewerZoomManual = false;   // l'élève a-t-il zoomé manuellement depuis le dernier changement d'activité ?

    function viewerZoom(delta) {
        viewerZoomTo(_viewerZoomLevel + delta * 100, true);
    }

    function viewerZoomTo(val, isManual) {
        _viewerZoomLevel = Math.max(30, Math.min(400, parseInt(val, 10) || 100));
        if (isManual) {
            _viewerZoomManual = true;
            // Activer la transition CSS uniquement pendant ce zoom manuel
            var w = document.querySelector('.activity-wrapper.active');
            if (w) {
                w.classList.add('zooming');
                clearTimeout(w._zoomTimer);
                w._zoomTimer = setTimeout(function() { w.classList.remove('zooming'); }, 200);
            }
        }
        _applyViewerZoom();
    }

    // Auto-fit : l'activité remplit la place dispo entre les deux bandeaux violets,
    // sans marge superflue. Calcule la hauteur dispo à partir de la position réelle
    // du wrapper (top après header + padding) jusqu'au top de la nav-bar fixe.
    function viewerZoomFit() {
        var wrapper = document.querySelector('.activity-wrapper.active');
        if (!wrapper) return;

        // Mesurer la taille naturelle et la position du wrapper (sans transform)
        wrapper.style.transform = '';
        var natRect = wrapper.getBoundingClientRect();
        var natW = natRect.width  || 1;
        var natH = natRect.height || 1;

        var navbar = document.querySelector('.course-nav-bar');
        var nH = navbar ? navbar.offsetHeight : 70;
        // Espace réellement disponible : entre la position du wrapper et le haut de la nav-bar
        var availH = (window.innerHeight - nH - 4) - natRect.top;

        // Largeur dispo : on prend .course-main (vraie zone à droite de la sidebar),
        // pas .course-content qui est plafonné à 1100px par max-width et bloquerait le scale > 1.
        var courseMain = document.querySelector('.course-main');
        var availW = (courseMain ? courseMain.clientWidth : window.innerWidth) - 16;

        var scale = Math.min(availW / natW, availH / natH);
        scale = Math.max(0.3, Math.min(4.0, scale));

        _viewerZoomManual = false;
        _viewerZoomLevel  = Math.round(scale * 100);
        _applyViewerZoom();
    }

    function _applyViewerZoom() {
        var wrapper = document.querySelector('.activity-wrapper.active');
        if (!wrapper) return;
        var scale = _viewerZoomLevel / 100;
        wrapper.style.transform = 'scale(' + scale + ')';
        wrapper.style.transformOrigin = 'top center';
        var slider = document.getElementById('viewerZoomSlider');
        var label  = document.getElementById('viewerZoomLabel');
        if (slider) slider.value = _viewerZoomLevel;
        if (label)  label.textContent = _viewerZoomLevel + '%';
    }

    // Rétrocompat : updateViewerScale (utilisé sur resize et ailleurs)
    // Re-fit seulement si l'élève n'a pas zoomé manuellement.
    function updateViewerScale() {
        if (!_viewerZoomManual) viewerZoomFit();
    }

    window.addEventListener('resize', updateViewerScale);
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('has-active-activity');
        viewerZoomFit();
    });
    </script>
    
    <script>
    var activityIds = <?= json_encode(array_column($allActivities, 'id')) ?>;
    var currentIndex = 0;
    var isStudentMode = <?= $source === 'token' ? 'true' : 'false' ?>;
    
    // Au chargement, vérifier les quiz déjà complétés (mode élève)
    document.addEventListener('DOMContentLoaded', function() {
        if (isStudentMode) {
            checkCompletedQuizzes();
        }
    });
    
    function checkCompletedQuizzes() {
        try {
            var completedQuizzes = JSON.parse(localStorage.getItem('elea_completed_quizzes') || '{}');
            for (var quizId in completedQuizzes) {
                var container = document.getElementById(quizId);
                if (container && window.quizState && window.quizState[quizId]) {
                    // Marquer comme complété
                    window.quizState[quizId].completed = true;
                    
                    // Afficher un message
                    var quizData = completedQuizzes[quizId];
                    var questions = container.querySelectorAll('.quiz-question');
                    for (var i = 0; i < questions.length; i++) {
                        questions[i].style.display = 'block';
                    }
                    
                    // Cacher la navigation et le récap
                    var nav = container.querySelector('.quiz-navigation');
                    var progress = container.querySelector('.quiz-progress');
                    var recap = container.querySelector('.quiz-recap');
                    if (nav) nav.style.display = 'none';
                    if (progress) progress.style.display = 'none';
                    if (recap) recap.style.display = 'none';
                    
                    // Afficher le message de quiz déjà fait
                    var results = container.querySelector('.quiz-results');
                    if (results) {
                        results.querySelector('.quiz-score').innerHTML = '✅ Vous avez déjà validé ce test (Score: ' + quizData.score + '%)';
                        results.style.display = 'block';
                    }
                    
                    // Désactiver les inputs
                    var inputs = container.querySelectorAll('input, select');
                    for (var j = 0; j < inputs.length; j++) {
                        inputs[j].disabled = true;
                    }
                }
            }
        } catch(e) { /* localStorage non disponible */ }
    }
    
    // Système de tracking de complétion des CoursePresentation
    var cpCompletionState = {}; // { cpId: { totalSlides, viewedSlides: Set, totalActivities, completedActivities: Set } }
    
    function initCpCompletion(cpId, totalSlides, activitySlides) {
        if (!cpCompletionState[cpId]) {
            cpCompletionState[cpId] = {
                totalSlides: totalSlides,
                viewedSlides: new Set([0]), // La première slide est vue par défaut
                activitySlides: activitySlides, // Array des indices de slides avec activité
                completedActivities: new Set()
            };
        }
    }
    
    function markSlideViewed(cpId, slideIdx) {
        if (cpCompletionState[cpId]) {
            cpCompletionState[cpId].viewedSlides.add(slideIdx);
            checkCpCompletion(cpId);
        }
    }
    
    function markCpActivityCompleted(cpId, slideIdx) {
        if (cpCompletionState[cpId]) {
            cpCompletionState[cpId].completedActivities.add(slideIdx);
            checkCpCompletion(cpId);
        }
    }
    
    function checkCpCompletion(cpId) {
        var state = cpCompletionState[cpId];
        if (!state) return;
        
        // Vérifier si toutes les slides sont vues
        var allSlidesViewed = state.viewedSlides.size >= state.totalSlides;
        
        // Vérifier si toutes les activités sont complétées
        // Si pas d'activités, c'est automatiquement complété
        var allActivitiesCompleted = true;
        if (state.activitySlides.length > 0) {
            for (var i = 0; i < state.activitySlides.length; i++) {
                if (!state.completedActivities.has(state.activitySlides[i])) {
                    allActivitiesCompleted = false;
                    break;
                }
            }
        }
        
        // Si tout est fait, marquer l'activité comme complétée dans la sidebar
        if (allSlidesViewed && allActivitiesCompleted) {
            var cp = document.getElementById(cpId);
            if (cp) {
                var activityWrapper = cp.closest('.activity-wrapper');
                if (activityWrapper) {
                    var activityId = activityWrapper.id;
                    var navItem = document.querySelector('.nav-item[data-id="' + activityId + '"]');
                    if (navItem) {
                        navItem.classList.add('completed');
                    }
                }
            }
        }
    }

    function showActivity(id) {
        var wrappers = document.querySelectorAll('.activity-wrapper');
        for (var i = 0; i < wrappers.length; i++) {
            wrappers[i].classList.remove('active');
            // Reset le scale
            wrappers[i].style.transform = '';
            wrappers[i].style.width = '';
            wrappers[i].style.marginLeft = '';
        }
        
        var activity = document.getElementById(id);
        if (activity) activity.classList.add('active');
        
        var navItems = document.querySelectorAll('.nav-item');
        for (var i = 0; i < navItems.length; i++) navItems[i].classList.remove('active');
        var navItem = document.querySelector('.nav-item[data-id="' + id + '"]');
        if (navItem) {
            navItem.classList.add('active');
            var section = navItem.closest('.nav-section');
            if (section && section.classList.contains('collapsed')) section.classList.remove('collapsed');
        }
        
        for (var i = 0; i < activityIds.length; i++) {
            if (activityIds[i] === id) { currentIndex = i; break; }
        }
        
        updateNavButtons();
        var sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.remove('open');
        window.scrollTo(0, 0);

        // Auto-fit synchrone : on applique le scale dans la même tâche JS, avant que le
        // browser ne paint. Sans setTimeout, pas de frame intermédiaire à 100% visible.
        document.body.classList.add('has-active-activity');
        _viewerZoomManual = false;
        viewerZoomFit();
    }

    function navigateActivity(dir) {
        // En mode élève, sauter les activités cachées
        var newIndex = currentIndex + dir;
        while (newIndex >= 0 && newIndex < activityIds.length) {
            if (!hiddenActivities.has(activityIds[newIndex])) {
                showActivity(activityIds[newIndex]);
                return;
            }
            newIndex += dir;
        }
    }

    function updateNavButtons() {
        var prevBtn = document.getElementById('prevBtn');
        var nextBtn = document.getElementById('nextBtn');
        
        // Trouver la première activité visible avant et après
        var hasPrev = false;
        var hasNext = false;
        
        for (var i = currentIndex - 1; i >= 0; i--) {
            if (!hiddenActivities.has(activityIds[i])) {
                hasPrev = true;
                break;
            }
        }
        
        for (var i = currentIndex + 1; i < activityIds.length; i++) {
            if (!hiddenActivities.has(activityIds[i])) {
                hasNext = true;
                break;
            }
        }
        
        if (prevBtn) prevBtn.style.visibility = hasPrev ? 'visible' : 'hidden';
        if (nextBtn) {
            if (!hasNext) {
                nextBtn.innerHTML = '✓ Terminé';
                nextBtn.disabled = true;
            } else {
                nextBtn.innerHTML = 'SUIVANT →';
                nextBtn.disabled = false;
            }
        }
    }

    function toggleSection(el) {
        var section = el.closest('.nav-section');
        if (section) section.classList.toggle('collapsed');
    }

    function toggleSidebar() {
        var sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.toggle('open');
    }
    
    function toggleSidebarCollapse() {
        var sidebar = document.getElementById('sidebar');
        var toggle = document.getElementById('sidebarToggle');
        var main = document.getElementById('courseMain');
        var navBarContent = document.querySelector('.nav-bar-content');
        
        if (sidebar) sidebar.classList.toggle('collapsed');
        if (toggle) toggle.classList.toggle('collapsed');
        if (main) main.classList.toggle('sidebar-collapsed');
        if (navBarContent) navBarContent.classList.toggle('sidebar-collapsed');
        
        // Mettre à jour le scale après la transition
        setTimeout(updateViewerScale, 350);
    }
    
    // === Système de visibilité des sections/activités ===
    var hiddenSections = new Set();
    var hiddenActivities = new Set();
    
    function toggleSectionVisibility(sectionIndex, event) {
        event.stopPropagation();
        var section = document.querySelector('.nav-section[data-section="' + sectionIndex + '"]');
        var btn = section.querySelector('.nav-section-header .visibility-toggle');
        
        if (hiddenSections.has(sectionIndex)) {
            // Rendre visible
            hiddenSections.delete(sectionIndex);
            section.classList.remove('visibility-hidden');
            btn.classList.remove('is-hidden');
            btn.title = 'Masquer cette section';
            
            // Rendre visible tous les items de cette section
            var items = section.querySelectorAll('.nav-item');
            items.forEach(function(item) {
                var activityId = item.getAttribute('data-id');
                hiddenActivities.delete(activityId);
                item.classList.remove('visibility-hidden');
                var itemBtn = item.querySelector('.visibility-toggle');
                if (itemBtn) {
                    itemBtn.classList.remove('is-hidden');
                    itemBtn.title = 'Masquer';
                }
                // Rendre visible le contenu
                var content = document.getElementById(activityId);
                if (content) content.classList.remove('visibility-hidden');
            });
        } else {
            // Cacher
            hiddenSections.add(sectionIndex);
            section.classList.add('visibility-hidden');
            btn.classList.add('is-hidden');
            btn.title = 'Afficher cette section';
            
            // Cacher tous les items de cette section
            var items = section.querySelectorAll('.nav-item');
            items.forEach(function(item) {
                var activityId = item.getAttribute('data-id');
                hiddenActivities.add(activityId);
                item.classList.add('visibility-hidden');
                var itemBtn = item.querySelector('.visibility-toggle');
                if (itemBtn) {
                    itemBtn.classList.add('is-hidden');
                    itemBtn.title = 'Afficher';
                }
                // Cacher le contenu
                var content = document.getElementById(activityId);
                if (content) content.classList.add('visibility-hidden');
            });
        }
    }
    
    function toggleActivityVisibility(activityId, event) {
        event.stopPropagation();
        var item = document.querySelector('.nav-item[data-id="' + activityId + '"]');
        var content = document.getElementById(activityId);
        var btn = item.querySelector('.visibility-toggle');
        
        if (hiddenActivities.has(activityId)) {
            // Rendre visible
            hiddenActivities.delete(activityId);
            item.classList.remove('visibility-hidden');
            if (content) content.classList.remove('visibility-hidden');
            btn.classList.remove('is-hidden');
            btn.title = 'Masquer';
        } else {
            // Cacher
            hiddenActivities.add(activityId);
            item.classList.add('visibility-hidden');
            if (content) content.classList.add('visibility-hidden');
            btn.classList.add('is-hidden');
            btn.title = 'Afficher';
        }
    }
    
    function getHiddenActivitiesString() {
        return Array.from(hiddenActivities).join(',');
    }

    // H5P CoursePresentation
    function navigateCpSlide(id, total, dir) {
        var container = document.getElementById(id);
        if (!container) return;
        var slides = container.querySelectorAll('.h5p-cp-slide');
        var current = 0;
        for (var i = 0; i < slides.length; i++) {
            if (slides[i].style.display !== 'none') current = i;
        }
        var next = Math.max(0, Math.min(total - 1, current + dir));
        goToCpSlide(id, next, total);
    }
    
    function toggleCpFullscreen(id) {
        var container = document.getElementById(id);
        if (!container) return;
        
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            }
        } else {
            if (container.requestFullscreen) {
                container.requestFullscreen();
            } else if (container.webkitRequestFullscreen) {
                container.webkitRequestFullscreen();
            }
        }
    }

    function goToCpSlide(id, slideIndex, total) {
        var container = document.getElementById(id);
        if (!container) return;
        
        // Initialiser le tracking si pas encore fait
        if (!cpCompletionState[id]) {
            var activitySlides = [];
            var indicators = container.querySelectorAll('.h5p-cp-indicator');
            for (var i = 0; i < indicators.length; i++) {
                activitySlides.push(parseInt(indicators[i].dataset.slide));
            }
            initCpCompletion(id, total, activitySlides);
        }
        
        var slides = container.querySelectorAll('.h5p-cp-slide');
        for (var i = 0; i < slides.length; i++) {
            slides[i].style.display = (i === slideIndex) ? 'block' : 'none';
        }
        
        // Marquer la slide comme vue
        markSlideViewed(id, slideIndex);
        
        // Mise à jour des segments (bleu pour vus, gris pour non vus, surbrillance pour la courante)
        var segments = container.querySelectorAll('.h5p-cp-segment');
        for (var i = 0; i < segments.length; i++) {
            if (cpCompletionState[id] && cpCompletionState[id].viewedSlides.has(i)) {
                segments[i].classList.add('viewed');
            }
            segments[i].classList.toggle('current', i === slideIndex);
        }
        
        var progress = container.querySelector('.h5p-cp-progress');
        if (progress) progress.textContent = (slideIndex + 1) + ' / ' + total;
        
        var prevBtn = container.querySelector('.h5p-cp-prev');
        var nextBtn = container.querySelector('.h5p-cp-next');
        if (prevBtn) prevBtn.style.visibility = slideIndex > 0 ? 'visible' : 'hidden';
        if (nextBtn) nextBtn.style.visibility = slideIndex < total - 1 ? 'visible' : 'hidden';
    }

    function onCpProgressClick(event, id, total) {
        var container = document.getElementById(id);
        var progressBar = container.querySelector('.h5p-cp-progressbar');
        var rect = progressBar.getBoundingClientRect();
        var clickX = event.clientX - rect.left;
        var percentage = clickX / rect.width;
        var slideIndex = Math.floor(percentage * total);
        slideIndex = Math.max(0, Math.min(total - 1, slideIndex));
        goToCpSlide(id, slideIndex, total);
    }

    // H5P Blanks
    function checkH5pBlanks(id, answers) {
        var container = document.getElementById(id);
        if (!container) return;
        var correct = 0;
        // Support des deux formats de classes
        var inputs = container.querySelectorAll('.h5p-quiz-blank-input, .h5p-blank-input');
        
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var idx = parseInt(input.dataset.idx);
            var userAnswer = input.value.trim().toLowerCase();
            var validAnswers = answers[idx] || [];
            var isCorrect = false;
            
            for (var j = 0; j < validAnswers.length; j++) {
                if (validAnswers[j].toLowerCase() === userAnswer) {
                    isCorrect = true;
                    break;
                }
            }
            
            input.classList.remove('h5p-correct', 'h5p-incorrect');
            if (isCorrect) {
                input.classList.add('h5p-correct');
                correct++;
            } else {
                input.classList.add('h5p-incorrect');
                input.title = 'Réponse attendue : ' + validAnswers[0];
            }
        }
        
        showH5pFeedback(container, correct, answers.length);
    }

    function resetH5pBlanks(id) {
        var container = document.getElementById(id);
        if (!container) return;
        // Support des deux formats de classes
        var inputs = container.querySelectorAll('.h5p-quiz-blank-input, .h5p-blank-input');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].value = '';
            inputs[i].classList.remove('h5p-correct', 'h5p-incorrect');
            inputs[i].title = '';
        }
        var fb = container.querySelector('.h5p-feedback');
        if (fb) fb.style.display = 'none';
    }

    // H5P MultiChoice - supporte les deux formats (ancien et moderne)
    function checkH5pMultiChoice(id) {
        var container = document.getElementById(id);
        if (!container) return;
        var correct = 0, total = 0;
        
        // Format moderne (labels avec classe h5p-quiz-answer)
        var modernAnswers = container.querySelectorAll('.h5p-quiz-answer');
        if (modernAnswers.length > 0) {
            for (var i = 0; i < modernAnswers.length; i++) {
                var ans = modernAnswers[i];
                var isCorrect = ans.dataset.correct === '1';
                var input = ans.querySelector('input');
                if (isCorrect) total++;
                if (input && input.checked) {
                    ans.classList.add(isCorrect ? 'h5p-correct' : 'h5p-incorrect');
                    if (isCorrect) correct++;
                }
            }
        } else {
            // Format ancien (labels avec classe h5p-answer-option)
            var opts = container.querySelectorAll('.h5p-answer-option');
            for (var i = 0; i < opts.length; i++) {
                var opt = opts[i];
                var isCorrect = opt.dataset.correct === '1';
                var input = opt.querySelector('input');
                if (isCorrect) total++;
                if (input && input.checked) {
                    opt.classList.add(isCorrect ? 'h5p-correct' : 'h5p-incorrect');
                    if (isCorrect) correct++;
                }
            }
        }
        
        showH5pFeedback(container, correct, total);
    }

    function resetH5pMultiChoice(id) {
        var container = document.getElementById(id);
        var inputs = container.querySelectorAll('input');
        for (var i = 0; i < inputs.length; i++) inputs[i].checked = false;
        var opts = container.querySelectorAll('.h5p-answer-option, .h5p-quiz-answer');
        for (var i = 0; i < opts.length; i++) {
            opts[i].classList.remove('h5p-correct', 'h5p-incorrect', 'selected');
        }
        var fb = container.querySelector('.h5p-feedback');
        if (fb) fb.style.display = 'none';
    }
    
    // H5P MultiMediaChoice - toggle option selection
    function toggleMmcOption(el) {
        if (el.classList.contains('h5p-checked')) return;
        var container = el.closest('.h5p-multimediachoice');
        var inputType = container ? container.dataset.inputType : 'checkbox';
        var input = el.querySelector('input');
        if (!input) return;
        
        if (inputType === 'radio') {
            // Déselectionner toutes les autres
            container.querySelectorAll('.h5p-mmc-option').forEach(function(o) {
                o.classList.remove('selected');
                var inp = o.querySelector('input');
                if (inp) inp.checked = false;
            });
            el.classList.add('selected');
            input.checked = true;
        } else {
            el.classList.toggle('selected');
            input.checked = !input.checked;
        }
    }
    
    // H5P MultiMediaChoice - check answers
    function checkH5pMultiMediaChoice(id) {
        var container = document.getElementById(id);
        if (!container) return;
        var correct = 0, total = 0, wrongSelected = 0;
        var options = container.querySelectorAll('.h5p-mmc-option');
        
        for (var i = 0; i < options.length; i++) {
            var opt = options[i];
            var isCorrect = opt.dataset.correct === '1';
            var input = opt.querySelector('input');
            var isSelected = input && input.checked;
            opt.classList.add('h5p-checked');
            
            if (isCorrect) {
                total++;
                if (isSelected) {
                    opt.classList.add('h5p-correct');
                    correct++;
                } else {
                    opt.classList.add('h5p-missed');
                }
            } else if (isSelected) {
                opt.classList.add('h5p-incorrect');
                wrongSelected++;
            }
        }
        
        var score = Math.max(0, correct - wrongSelected);
        showH5pFeedback(container, score, total);
    }
    document.addEventListener('click', function(e) {
        var answer = e.target.closest('.h5p-quiz-answer');
        if (answer) {
            var input = answer.querySelector('input');
            if (input) {
                if (input.type === 'radio') {
                    // Déselectionner les autres
                    var container = answer.closest('.h5p-quiz-modern');
                    if (container) {
                        container.querySelectorAll('.h5p-quiz-answer').forEach(function(a) {
                            a.classList.remove('selected');
                        });
                    }
                }
                input.checked = !input.checked || input.type === 'radio';
                answer.classList.toggle('selected', input.checked);
            }
        }
        
        // Réponses Vrai/Faux
        var tfAnswer = e.target.closest('.h5p-quiz-tf-answer');
        if (tfAnswer && !tfAnswer.classList.contains('h5p-correct') && !tfAnswer.classList.contains('h5p-incorrect')) {
            var tfContainer = tfAnswer.closest('.h5p-quiz-modern');
            if (tfContainer) {
                // Pour SingleChoiceSet, gérer la sélection
                var allAnswers = tfAnswer.parentElement.querySelectorAll('.h5p-quiz-tf-answer');
                allAnswers.forEach(function(a) {
                    a.classList.remove('selected');
                });
                tfAnswer.classList.add('selected');
                
                var input = tfAnswer.querySelector('input');
                if (input) {
                    input.checked = true;
                }
            }
        }
    });
    
    // SingleChoiceSet (Vrai/Faux) - sélection d'une réponse
    function selectScsAnswer(btn, id, questionIdx, totalQuestions) {
        var container = document.getElementById(id);
        if (!container) return;
        
        var isCorrect = btn.dataset.correct === '1';
        
        // Marquer la réponse
        var answers = btn.parentElement.querySelectorAll('.h5p-quiz-tf-answer');
        answers.forEach(function(a) {
            a.classList.remove('selected', 'h5p-correct', 'h5p-incorrect');
        });
        
        btn.classList.add(isCorrect ? 'h5p-correct' : 'h5p-incorrect');
        
        // Attendre un peu puis passer à la question suivante
        setTimeout(function() {
            var nextIdx = questionIdx + 1;
            
            if (nextIdx < totalQuestions) {
                // Masquer question actuelle, afficher la suivante
                var questions = container.querySelectorAll('.h5p-scs-question');
                questions.forEach(function(q, idx) {
                    q.style.display = idx === nextIdx ? 'block' : 'none';
                });
                
                // Mettre à jour l'indicateur de navigation
                var navInfo = document.getElementById(id + '-nav');
                if (navInfo) {
                    navInfo.textContent = 'Question ' + (nextIdx + 1) + ' / ' + totalQuestions;
                }
            } else {
                // Fin du quiz - calculer le score
                var correct = 0;
                var questions = container.querySelectorAll('.h5p-scs-question');
                questions.forEach(function(q) {
                    var correctAnswer = q.querySelector('.h5p-quiz-tf-answer.h5p-correct');
                    if (correctAnswer) correct++;
                });
                
                // Afficher les résultats
                var results = container.querySelector('.h5p-scs-results');
                if (results) {
                    var score = results.querySelector('.h5p-scs-score');
                    var pct = Math.round((correct / totalQuestions) * 100);
                    var emoji = pct === 100 ? '🎉' : (pct >= 50 ? '👍' : '💪');
                    score.innerHTML = emoji + ' Score : <strong>' + correct + '/' + totalQuestions + '</strong> (' + pct + '%)';
                    results.style.display = 'block';
                    
                    // Masquer les questions
                    questions.forEach(function(q) {
                        q.style.display = 'none';
                    });
                    
                    // Masquer l'indicateur de navigation
                    var navIndicator = container.querySelector('.h5p-quiz-nav-indicator');
                    if (navIndicator) navIndicator.style.display = 'none';
                    
                    // Marquer comme complété si 100%
                    if (pct === 100) {
                        var cp = container.closest('.h5p-coursepresentation');
                        if (cp) {
                            markSlideActivityCompleted(container);
                        } else {
                            markActivityCompleted(container);
                        }
                    }
                }
            }
        }, isCorrect ? 800 : 1500);
    }
    
    // Réinitialiser le SingleChoiceSet
    function resetScs(id) {
        var container = document.getElementById(id);
        if (!container) return;
        
        // Réinitialiser toutes les réponses
        var answers = container.querySelectorAll('.h5p-quiz-tf-answer');
        answers.forEach(function(a) {
            a.classList.remove('selected', 'h5p-correct', 'h5p-incorrect');
        });
        
        // Afficher la première question, masquer les autres
        var questions = container.querySelectorAll('.h5p-scs-question');
        questions.forEach(function(q, idx) {
            q.style.display = idx === 0 ? 'block' : 'none';
        });
        
        // Masquer les résultats
        var results = container.querySelector('.h5p-scs-results');
        if (results) results.style.display = 'none';
        
        // Réafficher l'indicateur de navigation
        var navIndicator = container.querySelector('.h5p-quiz-nav-indicator');
        if (navIndicator) navIndicator.style.display = 'flex';
        
        // Réinitialiser le texte de navigation
        var navInfo = container.querySelector('.h5p-quiz-nav-info');
        if (navInfo) {
            var total = container.dataset.total || questions.length;
            navInfo.textContent = 'Question 1 / ' + total;
        }
    }

    function showH5pFeedback(container, correct, total) {
        var feedback = container.querySelector('.h5p-feedback');
        if (!feedback) return;
        var pct = total > 0 ? Math.round((correct / total) * 100) : 0;
        var emoji = pct === 100 ? '🎉' : (pct >= 50 ? '👍' : '💪');
        feedback.innerHTML = emoji + ' Score : <strong>' + correct + '/' + total + '</strong> (' + pct + '%)';
        feedback.style.display = 'block';
        
        // Marquer l'indicateur comme completed si score = 100%
        if (pct === 100) {
            // Vérifier si c'est dans un CoursePresentation
            var cp = container.closest('.h5p-coursepresentation');
            if (cp) {
                markSlideActivityCompleted(container);
            } else {
                // Activité standalone - marquer directement dans la sidebar
                markActivityCompleted(container);
            }
        }
    }
    
    // Marque une activité standalone comme complétée dans la sidebar
    function markActivityCompleted(element) {
        var activityWrapper = element.closest('.activity-wrapper');
        if (activityWrapper) {
            var activityId = activityWrapper.id;
            var navItem = document.querySelector('.nav-item[data-id="' + activityId + '"]');
            if (navItem) {
                navItem.classList.add('completed');
            }
        }
    }
    
    // Marque l'indicateur de la slide courante comme completed
    function markSlideActivityCompleted(element) {
        // Trouver le CoursePresentation parent
        var cp = element.closest('.h5p-coursepresentation');
        if (!cp) return;
        
        // Trouver la slide active
        var activeSlide = null;
        var slides = cp.querySelectorAll('.h5p-cp-slide');
        for (var i = 0; i < slides.length; i++) {
            if (slides[i].style.display !== 'none') {
                activeSlide = slides[i];
                break;
            }
        }
        if (!activeSlide) return;
        
        var slideIdx = activeSlide.dataset.idx;
        
        // Trouver l'indicateur correspondant à cette slide (via data-slide)
        var indicator = cp.querySelector('.h5p-cp-indicator[data-slide="' + slideIdx + '"]');
        if (indicator) {
            indicator.classList.add('completed');
        }
        
        // Tracker la complétion de l'activité
        var cpId = cp.id;
        markCpActivityCompleted(cpId, parseInt(slideIdx));
    }

    // Quiz Moodle - Navigation par question
    function quizNextQuestion(quizId) {
        var container = document.getElementById(quizId);
        var state = window.quizState[quizId];
        var totalQuestions = parseInt(container.dataset.totalQuestions);
        
        if (state.currentQuestion < totalQuestions - 1) {
            showQuizQuestion(quizId, state.currentQuestion + 1);
        }
    }
    
    function quizPrevQuestion(quizId) {
        var state = window.quizState[quizId];
        
        if (state.currentQuestion > 0) {
            showQuizQuestion(quizId, state.currentQuestion - 1);
        }
    }
    
    function showQuizQuestion(quizId, index) {
        var container = document.getElementById(quizId);
        var questions = container.querySelectorAll('.quiz-question');
        var state = window.quizState[quizId];
        var totalQuestions = parseInt(container.dataset.totalQuestions);
        
        // Cacher toutes les questions
        for (var i = 0; i < questions.length; i++) {
            questions[i].style.display = 'none';
            questions[i].classList.remove('active');
        }
        
        // Afficher la question courante
        questions[index].style.display = 'block';
        questions[index].classList.add('active');
        state.currentQuestion = index;
        
        // Mettre à jour la barre de progression
        var progressText = container.querySelector('.quiz-current-q');
        var progressFill = container.querySelector('.quiz-progress-fill');
        if (progressText) progressText.textContent = index + 1;
        if (progressFill) progressFill.style.width = ((index + 1) / totalQuestions * 100) + '%';
        
        // Mettre à jour les boutons
        var prevBtn = container.querySelector('.quiz-prev-btn');
        var nextBtn = container.querySelector('.quiz-next-btn');
        var submitBtn = container.querySelector('.quiz-submit-btn');
        
        if (prevBtn) prevBtn.style.display = index > 0 ? 'inline-block' : 'none';
        if (nextBtn) nextBtn.style.display = index < totalQuestions - 1 ? 'inline-block' : 'none';
        if (submitBtn) submitBtn.style.display = index === totalQuestions - 1 ? 'inline-block' : 'none';
    }

    // Quiz Moodle - Récapitulatif avant validation
    function showQuizRecap(quizId) {
        var container = document.getElementById(quizId);
        var questions = container.querySelectorAll('.quiz-question');
        var quizData = window.quizData ? window.quizData[quizId] : [];
        var recapDiv = container.querySelector('.quiz-recap');
        var recapQuestions = container.querySelector('.quiz-recap-questions');
        
        // Construire le récapitulatif
        var html = '';
        for (var i = 0; i < questions.length; i++) {
            var qEl = questions[i];
            var qData = quizData[i];
            if (!qData) continue;
            
            var qtype = qEl.dataset.qtype;
            var questionText = qEl.querySelector('.question-text').innerHTML;
            var selectedAnswers = [];
            
            if (qtype === 'multichoice' || qtype === 'truefalse') {
                var inputs = qEl.querySelectorAll('.answer-option input');
                for (var j = 0; j < inputs.length; j++) {
                    if (inputs[j].checked) {
                        var answerText = inputs[j].closest('.answer-option').querySelector('.answer-text').textContent;
                        selectedAnswers.push(answerText);
                    }
                }
            } else if (qtype === 'shortanswer') {
                var input = qEl.querySelector('.answer-input');
                if (input && input.value) {
                    selectedAnswers.push(input.value);
                }
            } else if (qtype === 'match') {
                var selects = qEl.querySelectorAll('select');
                for (var j = 0; j < selects.length; j++) {
                    if (selects[j].value) {
                        var leftText = selects[j].closest('tr').querySelector('td:first-child').textContent;
                        selectedAnswers.push(leftText + ' → ' + selects[j].value);
                    }
                }
            }
            
            var answerDisplay = selectedAnswers.length > 0 
                ? selectedAnswers.join('<br>') 
                : '<em style="color:#999;">Pas de réponse</em>';
            
            html += '<div class="quiz-recap-item" data-qindex="' + i + '">' +
                '<div class="quiz-recap-question">' +
                '<span class="quiz-recap-num">' + (i + 1) + '</span>' +
                '<div class="quiz-recap-text">' + questionText + '</div>' +
                '</div>' +
                '<div class="quiz-recap-answer">' +
                '<strong>Votre réponse :</strong><br>' + answerDisplay +
                '</div>' +
                '<button class="btn btn-sm btn-secondary quiz-recap-edit" onclick="editQuizQuestion(\'' + quizId + '\', ' + i + ')">✏️ Modifier</button>' +
                '</div>';
        }
        
        recapQuestions.innerHTML = html;
        
        // Cacher les questions et la navigation, afficher le récap
        container.querySelector('.quiz-questions').style.display = 'none';
        container.querySelector('.quiz-navigation').style.display = 'none';
        container.querySelector('.quiz-progress').style.display = 'none';
        recapDiv.style.display = 'block';
    }
    
    function editQuizQuestion(quizId, questionIndex) {
        backToQuizQuestions(quizId);
        showQuizQuestion(quizId, questionIndex);
    }
    
    function backToQuizQuestions(quizId) {
        var container = document.getElementById(quizId);
        var state = window.quizState[quizId];
        var totalQuestions = parseInt(container.dataset.totalQuestions);
        
        // Cacher le récap
        container.querySelector('.quiz-recap').style.display = 'none';
        
        // Réafficher les questions et la navigation
        container.querySelector('.quiz-questions').style.display = 'block';
        container.querySelector('.quiz-navigation').style.display = 'flex';
        container.querySelector('.quiz-progress').style.display = 'block';
        
        // Afficher la dernière question
        showQuizQuestion(quizId, totalQuestions - 1);
    }
    
    function finalSubmitQuiz(quizId) {
        // Confirmation
        if (!confirm('Êtes-vous sûr de vouloir valider définitivement ce test ? Vous ne pourrez plus modifier vos réponses.')) {
            return;
        }
        
        // Appeler la fonction de correction
        submitQuiz(quizId);
        
        // Cacher le récap
        var container = document.getElementById(quizId);
        container.querySelector('.quiz-recap').style.display = 'none';
    }

    // Quiz Moodle - Validation (correction)
    function submitQuiz(quizId) {
        var container = document.getElementById(quizId);
        var questions = container.querySelectorAll('.quiz-question');
        var quizData = window.quizData ? window.quizData[quizId] : [];
        var state = window.quizState[quizId];
        var totalScore = 0, maxScore = 0;
        
        // Réafficher le conteneur de questions (peut être caché par le récap)
        container.querySelector('.quiz-questions').style.display = 'block';
        
        // Afficher toutes les questions pour la correction
        for (var i = 0; i < questions.length; i++) {
            questions[i].style.display = 'block';
        }
        
        for (var i = 0; i < questions.length; i++) {
            var qEl = questions[i];
            var qData = quizData[i];
            if (!qData) continue;
            var maxMark = parseFloat(qData.maxmark) || 1;
            maxScore += maxMark;
            var score = 0;
            var qtype = qEl.dataset.qtype;
            
            if (qtype === 'multichoice' || qtype === 'truefalse') {
                var inputs = qEl.querySelectorAll('.answer-option input');
                for (var j = 0; j < inputs.length; j++) {
                    var input = inputs[j];
                    var option = input.closest('.answer-option');
                    var fraction = parseFloat(input.dataset.fraction);
                    if (input.checked) {
                        score += fraction;
                        option.classList.add(fraction > 0 ? 'correct-answer' : 'wrong-answer');
                    }
                    // Ne pas montrer les réponses manquées
                }
            }
            
            totalScore += Math.max(0, Math.min(1, score)) * maxMark;
            qEl.classList.remove('correct', 'incorrect', 'partial');
            if (score >= 1) qEl.classList.add('correct');
            else if (score > 0) qEl.classList.add('partial');
            else qEl.classList.add('incorrect');
        }
        
        var pct = maxScore > 0 ? Math.round((totalScore / maxScore) * 100) : 0;
        var emoji = pct === 100 ? '🎉' : (pct >= 60 ? '👍' : (pct >= 40 ? '💪' : '📚'));
        var results = container.querySelector('.quiz-results');
        if (results) {
            results.querySelector('.quiz-score').innerHTML = emoji + ' Score : <strong>' + totalScore.toFixed(1) + ' / ' + maxScore.toFixed(1) + '</strong> (' + pct + '%)';
            results.style.display = 'block';
        }
        
        // Cacher la navigation et le récap, afficher les actions
        container.querySelector('.quiz-navigation').style.display = 'none';
        container.querySelector('.quiz-progress').style.display = 'none';
        var recapEl = container.querySelector('.quiz-recap');
        if (recapEl) recapEl.style.display = 'none';
        
        // Afficher le bouton recommencer seulement si pas en mode élève
        var actionsDiv = container.querySelector('.quiz-actions');
        if (actionsDiv && !isStudentMode) {
            actionsDiv.style.display = 'block';
        }
        
        // Marquer le quiz comme complété
        state.completed = true;
        
        // En mode élève, sauvegarder dans localStorage pour empêcher de recommencer après rechargement
        if (isStudentMode) {
            try {
                var completedQuizzes = JSON.parse(localStorage.getItem('elea_completed_quizzes') || '{}');
                completedQuizzes[quizId] = { score: pct, completedAt: Date.now() };
                localStorage.setItem('elea_completed_quizzes', JSON.stringify(completedQuizzes));
            } catch(e) { /* localStorage non disponible */ }
        }
    }

    function resetQuiz(quizId) {
        var container = document.getElementById(quizId);
        var state = window.quizState[quizId];
        
        // Si en mode élève, vérifier aussi localStorage
        if (isStudentMode) {
            try {
                var completedQuizzes = JSON.parse(localStorage.getItem('elea_completed_quizzes') || '{}');
                if (completedQuizzes[quizId]) {
                    alert('Vous avez déjà validé ce test. Vous ne pouvez pas le recommencer.');
                    return;
                }
            } catch(e) { /* localStorage non disponible */ }
        }
        
        // Si en mode élève et quiz complété, ne pas permettre de recommencer
        if (isStudentMode && state && state.completed) {
            alert('Vous ne pouvez pas recommencer ce test.');
            return;
        }
        
        var inputs = container.querySelectorAll('input');
        for (var i = 0; i < inputs.length; i++) { inputs[i].checked = false; inputs[i].value = ''; }
        var selects = container.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) { selects[i].selectedIndex = 0; }
        var opts = container.querySelectorAll('.answer-option');
        for (var i = 0; i < opts.length; i++) opts[i].classList.remove('correct-answer', 'wrong-answer');
        var qs = container.querySelectorAll('.quiz-question');
        for (var i = 0; i < qs.length; i++) qs[i].classList.remove('correct', 'incorrect', 'partial');
        var results = container.querySelector('.quiz-results');
        if (results) results.style.display = 'none';
        
        // Cacher le récap s'il est affiché
        var recap = container.querySelector('.quiz-recap');
        if (recap) recap.style.display = 'none';
        
        // Réinitialiser l'état et afficher la première question
        if (state) {
            state.currentQuestion = 0;
            state.completed = false;
        }
        
        // Réafficher les questions
        container.querySelector('.quiz-questions').style.display = 'block';
        showQuizQuestion(quizId, 0);
        
        // Réafficher la navigation
        container.querySelector('.quiz-navigation').style.display = 'flex';
        container.querySelector('.quiz-progress').style.display = 'block';
        container.querySelector('.quiz-actions').style.display = 'none';
    }

    // H5P Dialog Cards
    function flipDialogCard(element) {
        var card = element.closest('.h5p-dc-card');
        card.classList.toggle('flipped');
    }
    
    function goToDialogCard(containerId, total, target) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var cards = container.querySelectorAll('.h5p-dc-card');
        if (target < 0 || target >= cards.length) return;
        for (var i = 0; i < cards.length; i++) {
            cards[i].style.display = (i === target) ? 'flex' : 'none';
            if (i !== target) cards[i].classList.remove('flipped');
        }
        var progress = container.querySelector('.h5p-dc-progress');
        if (progress) progress.textContent = 'Carte ' + (target + 1) + ' sur ' + total;
        var btns = container.querySelectorAll('.h5p-dc-nav-btn');
        if (btns.length === 2) {
            btns[0].disabled = (target === 0);
            btns[1].disabled = (target === cards.length - 1);
        }
    }

    function currentDialogCard(container) {
        var cards = container.querySelectorAll('.h5p-dc-card');
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].style.display !== 'none') return i;
        }
        return 0;
    }

    function nextDialogCard(containerId, total) {
        var container = document.getElementById(containerId);
        if (!container) return;
        goToDialogCard(containerId, total, currentDialogCard(container) + 1);
    }

    function prevDialogCard(containerId, total) {
        var container = document.getElementById(containerId);
        if (!container) return;
        goToDialogCard(containerId, total, currentDialogCard(container) - 1);
    }

    // H5P Flashcards
    function checkFlashcard(btn, correctAnswer) {
        var card = btn.closest('.h5p-fc-card');
        var input = card.querySelector('input');
        var userAnswer = input.value.trim().toLowerCase();
        var correct = correctAnswer.toLowerCase();
        
        if (userAnswer === correct) {
            input.style.borderColor = '#4caf50';
            input.style.background = '#e8f5e9';
            btn.textContent = '✓ Correct !';
            btn.style.background = '#4caf50';
        } else {
            input.style.borderColor = '#f44336';
            input.style.background = '#ffebee';
            btn.textContent = 'Réponse : ' + correctAnswer;
            btn.style.background = '#f44336';
        }
        btn.disabled = true;
    }
    
    function nextFlashcard(containerId, total) {
        var container = document.getElementById(containerId);
        var cards = container.querySelectorAll('.h5p-fc-card');
        var progress = container.querySelector('.h5p-fc-progress');
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].style.display !== 'none') {
                cards[i].style.display = 'none';
                var next = (i + 1) % total;
                cards[next].style.display = 'block';
                progress.textContent = (next + 1) + ' / ' + total;
                break;
            }
        }
    }
    
    function prevFlashcard(containerId, total) {
        var container = document.getElementById(containerId);
        var cards = container.querySelectorAll('.h5p-fc-card');
        var progress = container.querySelector('.h5p-fc-progress');
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].style.display !== 'none') {
                cards[i].style.display = 'none';
                var prev = (i - 1 + total) % total;
                cards[prev].style.display = 'block';
                progress.textContent = (prev + 1) + ' / ' + total;
                break;
            }
        }
    }

    // H5P Memory Game
    var memoryFlipped = [];
    var memoryLocked = false;
    
    function flipMemoryCard(card, containerId) {
        if (memoryLocked || card.classList.contains('flipped') || card.classList.contains('matched')) return;
        
        card.classList.add('flipped');
        memoryFlipped.push(card);
        
        if (memoryFlipped.length === 2) {
            memoryLocked = true;
            var c1 = memoryFlipped[0];
            var c2 = memoryFlipped[1];
            
            if (c1.dataset.match === c2.dataset.match) {
                // Match!
                c1.classList.add('matched');
                c2.classList.add('matched');
                memoryFlipped = [];
                memoryLocked = false;
                
                // Update score
                var container = document.getElementById(containerId);
                var score = container.querySelector('.h5p-memory-score');
                score.textContent = parseInt(score.textContent) + 1;
            } else {
                // No match
                setTimeout(function() {
                    c1.classList.remove('flipped');
                    c2.classList.remove('flipped');
                    memoryFlipped = [];
                    memoryLocked = false;
                }, 1000);
            }
        }
    }
    
    function resetMemoryGame(containerId) {
        var container = document.getElementById(containerId);
        var cards = container.querySelectorAll('.h5p-memory-card');
        for (var i = 0; i < cards.length; i++) {
            cards[i].classList.remove('flipped', 'matched');
        }
        container.querySelector('.h5p-memory-score').textContent = '0';
        memoryFlipped = [];
        memoryLocked = false;
    }

    // H5P Game Map
    function openGameMapStep(containerId, stepIdx) {
        var container = document.getElementById(containerId);
        var stepEl = container.querySelector('.h5p-gamemap-step[data-step="' + stepIdx + '"]');
        
        // Vérifier si le point est verrouillé
        if (stepEl && stepEl.dataset.locked === 'true') {
            // Afficher un message
            stepEl.style.animation = 'shake 0.3s';
            setTimeout(function() { stepEl.style.animation = ''; }, 300);
            return;
        }
        
        var modal = document.getElementById(containerId + '-step-' + stepIdx);
        if (modal) {
            modal.style.display = 'flex';
            
            // Marquer l'étape comme complétée
            var state = window.gameMapState[containerId];
            if (state && !state.completed.has(stepIdx)) {
                state.completed.add(stepIdx);
                
                // Mettre à jour le compteur
                var completedEl = container.querySelector('.h5p-gamemap-completed');
                if (completedEl) {
                    completedEl.textContent = state.completed.size;
                }
                
                // Marquer visuellement l'étape
                if (stepEl) {
                    stepEl.classList.add('completed');
                    stepEl.classList.remove('locked');
                    stepEl.dataset.locked = 'false';
                    // Mettre à jour l'icône
                    var iconEl = stepEl.querySelector('.h5p-gamemap-step-icon');
                    if (iconEl) {
                        if (stepEl.classList.contains('final')) {
                            iconEl.textContent = '✓';
                        } else {
                            iconEl.textContent = '✓';
                        }
                    }
                }
                
                // Débloquer les voisins
                var neighbors = JSON.parse(stepEl.dataset.neighbors || '[]');
                neighbors.forEach(function(neighborIdx) {
                    var neighborEl = container.querySelector('.h5p-gamemap-step[data-step="' + neighborIdx + '"]');
                    if (neighborEl && neighborEl.dataset.locked === 'true') {
                        neighborEl.classList.remove('locked');
                        neighborEl.dataset.locked = 'false';
                        // Mettre à jour l'icône
                        var neighborIcon = neighborEl.querySelector('.h5p-gamemap-step-icon');
                        if (neighborIcon) {
                            if (neighborEl.classList.contains('final')) {
                                neighborIcon.textContent = '🏁';
                            } else {
                                neighborIcon.textContent = parseInt(neighborIdx) + 1;
                            }
                        }
                    }
                });
                
                // Activer les chemins connectés
                var paths = container.querySelectorAll('.h5p-gamemap-path');
                paths.forEach(function(path) {
                    var from = parseInt(path.dataset.from);
                    var to = parseInt(path.dataset.to);
                    if (state.completed.has(from) || state.completed.has(to)) {
                        path.classList.add('active');
                    }
                });
            }
        }
    }
    
    function closeGameMapStep(containerId, stepIdx) {
        var modal = document.getElementById(containerId + '-step-' + stepIdx);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // H5P Virtual Tour 360 (avec Pannellum)
    function vtLoadScene(containerId, sceneKey, btn) {
        var viewer = window['vtViewer_' + containerId];
        if (viewer) {
            viewer.loadScene(sceneKey);
        }
        // Mettre à jour les boutons
        var container = document.getElementById(containerId);
        var buttons = container.querySelectorAll('.h5p-vt-nav-btn');
        buttons.forEach(function(b) { b.classList.remove('active'); });
        if (btn) btn.classList.add('active');
    }
    
    function closeVtInteraction(containerId, sceneId, intIdx) {
        var modal = document.getElementById(containerId + '-int-' + sceneId + '-' + intIdx);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // H5P DragQuestion (glisser-déposer sur image)
    // Variable globale pour tracker le drag en cours
    var dqDragData = null;
    
    function startDqDrag(event, element, containerId) {
        var idx = element.dataset.idx;
        event.dataTransfer.setData('text/plain', idx);
        event.dataTransfer.effectAllowed = 'move';
        element.classList.add('dragging');
        
        // Stocker les infos du drag
        dqDragData = {
            containerId: containerId,
            elementIdx: idx,
            fromZone: element.closest('.h5p-dq-dropzone') // null si depuis position d'origine
        };
        
        // Afficher les zones de dépôt
        var container = document.getElementById(containerId);
        if (container) {
            container.classList.add('dq-dragging');
            // Rendre les zones visibles avec bordure en tirets
            var zones = container.querySelectorAll('.h5p-dq-dropzone');
            zones.forEach(function(zone) {
                zone.style.borderColor = 'rgba(0,0,0,0.4)';
            });
        }
    }
    
    function endDqDrag(event, element, containerId) {
        element.classList.remove('dragging');
        
        // Cacher les zones de dépôt
        var container = document.getElementById(containerId);
        if (container) {
            container.classList.remove('dq-dragging');
            var zones = container.querySelectorAll('.h5p-dq-dropzone');
            zones.forEach(function(zone) {
                zone.style.borderColor = 'transparent';
                zone.style.background = 'transparent';
            });
        }
        
        // Si le drop n'a pas été géré (drop en dehors), remettre l'étiquette à sa place
        setTimeout(function() {
            if (dqDragData && dqDragData.elementIdx === element.dataset.idx) {
                // Le drop n'a pas eu lieu sur une zone valide
                if (dqDragData.fromZone) {
                    // L'élément était dans une zone, on le remet dedans
                    // (rien à faire, le clone est toujours là)
                } else {
                    // L'élément était à sa position d'origine, on le restaure
                    element.style.opacity = '1';
                    element.style.pointerEvents = '';
                }
                dqDragData = null;
            }
        }, 50);
    }
    
    function dropDqElement(event, containerId) {
        event.preventDefault();
        event.stopPropagation();

        if (!dqDragData || dqDragData.containerId !== containerId) return;

        var elementIdx = dqDragData.elementIdx;
        var container = document.getElementById(containerId);
        var dqContainer = container.querySelector('.h5p-dq-container');
        if (!dqContainer) return;

        // Trouver l'élément original (jamais un clone)
        var original = dqContainer.querySelector('.h5p-dq-draggable[data-idx="' + elementIdx + '"]:not(.placed-clone)');
        if (!original) { hideDropZones(containerId); dqDragData = null; return; }

        // Position du curseur, normalisée en % du container parent
        var contRect = dqContainer.getBoundingClientRect();
        var elemRect = original.getBoundingClientRect();
        var elemWPct = (elemRect.width  / contRect.width)  * 100;
        var elemHPct = (elemRect.height / contRect.height) * 100;
        var xPct = ((event.clientX - contRect.left) / contRect.width)  * 100 - elemWPct / 2;
        var yPct = ((event.clientY - contRect.top)  / contRect.height) * 100 - elemHPct / 2;
        // Clamper pour rester dans le container
        if (xPct < 0) xPct = 0;
        if (yPct < 0) yPct = 0;
        if (xPct > 100 - elemWPct) xPct = 100 - elemWPct;
        if (yPct > 100 - elemHPct) yPct = 100 - elemHPct;

        // Retirer un éventuel clone précédent de cette même étiquette (re-dépôt)
        var oldClone = dqContainer.querySelector('.h5p-dq-draggable.placed-clone[data-idx="' + elementIdx + '"]');
        if (oldClone && oldClone.parentNode) oldClone.parentNode.removeChild(oldClone);

        // Créer le nouveau clone en position absolue dans le container
        var clone = original.cloneNode(true);
        clone.classList.add('placed-clone');
        clone.style.position = 'absolute';
        clone.style.left   = xPct + '%';
        clone.style.top    = yPct + '%';
        clone.style.width  = elemWPct + '%';
        clone.style.height = elemHPct + '%';
        clone.style.margin = '0';
        clone.style.transform = 'none';
        clone.style.cursor = 'grab';
        clone.style.opacity = '1';
        clone.style.pointerEvents = 'auto';
        clone.style.zIndex = '15'; // au-dessus des dropzones
        clone.dataset.placedX = xPct.toFixed(2);
        clone.dataset.placedY = yPct.toFixed(2);

        clone.setAttribute('draggable', 'true');
        clone.ondragstart = function(e) { startDqDrag(e, clone, containerId); };
        clone.ondragend   = function(e) { endDqDrag(e, clone, containerId); };
        // Clic = remettre l'étiquette à sa pioche d'origine
        clone.onclick = function(e) {
            e.stopPropagation();
            if (clone.parentNode) clone.parentNode.removeChild(clone);
            original.style.opacity = '1';
            original.style.pointerEvents = '';
        };

        dqContainer.appendChild(clone);

        // Masquer l'original (qui est maintenant placé)
        original.style.opacity = '0.3';
        original.style.pointerEvents = 'none';

        hideDropZones(containerId);
        dqDragData = null;
    }
    
    // Fonction pour cacher les zones de dépôt
    function hideDropZones(containerId) {
        var container = document.getElementById(containerId);
        if (container) {
            container.classList.remove('dq-dragging');
            var zones = container.querySelectorAll('.h5p-dq-dropzone');
            zones.forEach(function(zone) {
                zone.style.borderColor = 'transparent';
                zone.style.background = 'transparent';
            });
        }
    }
    
    // Drop sur le container (hors zones) : place l'étiquette à la position du curseur,
    // exactement comme un drop sur une zone (mêmes règles, validation par overlap géométrique).
    function handleDqDropOutside(event, containerId) {
        return dropDqElement(event, containerId);
    }

    // Surface d'intersection entre deux DOMRect (0 si disjoints)
    function _dqRectOverlapArea(a, b) {
        var x1 = Math.max(a.left, b.left), y1 = Math.max(a.top,    b.top);
        var x2 = Math.min(a.right, b.right), y2 = Math.min(a.bottom, b.bottom);
        if (x2 <= x1 || y2 <= y1) return 0;
        return (x2 - x1) * (y2 - y1);
    }

    // Validation par overlap : pour chaque étiquette placée, on cherche la zone
    // avec laquelle elle partage le plus de surface (best-match). Tolérance : 30%
    // de la surface de l'étiquette doit être sur la zone gagnante. Cela permet :
    //   - de garder la position exacte du dépôt (pas de recentrage),
    //   - d'accepter un dépôt "à cheval" tant qu'il est majoritairement sur la bonne zone,
    //   - de gérer les zones superposées (best-match départage).
    function checkH5pDragQuestion(id, correctMapping) {
        var container = document.getElementById(id);
        if (!container) return;
        var dqContainer = container.querySelector('.h5p-dq-container');
        if (!dqContainer) return;
        var dropZones = container.querySelectorAll('.h5p-dq-dropzone');
        var clones = dqContainer.querySelectorAll('.h5p-dq-draggable.placed-clone');

        var OVERLAP_THRESHOLD = 0.30; // 30% de la surface de l'étiquette

        // Compte des éléments à placer (pour le score affiché)
        var elementsToPlace = new Set();
        for (var key in correctMapping) {
            var elems = correctMapping[key];
            if (elems && elems.length > 0) elems.forEach(function(e) { elementsToPlace.add(String(e)); });
        }
        var total = elementsToPlace.size || dropZones.length;
        var correct = 0;

        // Reset style des zones
        for (var i = 0; i < dropZones.length; i++) {
            var dz0 = dropZones[i];
            dz0.style.borderColor = 'transparent';
            dz0.style.borderStyle = 'dashed';
            dz0.style.background = 'transparent';
            // Marqueur interne pour stocker le verdict pendant la passe
            delete dz0.dataset._verdict;
        }

        // Pour chaque étiquette placée, déterminer la zone gagnante par overlap
        clones.forEach(function(clone) {
            var elIdx = String(clone.dataset.idx);
            var cRect = clone.getBoundingClientRect();
            var cArea = cRect.width * cRect.height;
            if (cArea <= 0) return;

            var bestZone = null;
            var bestRatio = 0;
            for (var j = 0; j < dropZones.length; j++) {
                var dz = dropZones[j];
                var inter = _dqRectOverlapArea(cRect, dz.getBoundingClientRect());
                var ratio = inter / cArea;
                if (ratio > bestRatio) { bestRatio = ratio; bestZone = dz; }
            }

            if (!bestZone || bestRatio < OVERLAP_THRESHOLD) {
                // Étiquette pas suffisamment sur une zone
                return;
            }

            var zoneIdx = bestZone.dataset.idx;
            var correctElements = (correctMapping[zoneIdx] || []).map(String);
            var isCorrect = correctElements.indexOf(elIdx) !== -1;
            if (isCorrect) correct++;

            // Stocker le verdict de la zone : si plusieurs étiquettes partagent une zone,
            // priorité au verdict positif (correct écrase incorrect), sinon incorrect par défaut.
            var prev = bestZone.dataset._verdict;
            if (prev !== 'correct') bestZone.dataset._verdict = isCorrect ? 'correct' : 'incorrect';
        });

        // Appliquer le style final à chaque zone selon son verdict
        for (var k = 0; k < dropZones.length; k++) {
            var dz1 = dropZones[k];
            if (dz1.dataset._verdict === 'correct') {
                dz1.style.borderColor = '#4CAF50';
                dz1.style.borderStyle = 'solid';
                dz1.style.background  = 'rgba(76, 175, 80, 0.3)';
            } else if (dz1.dataset._verdict === 'incorrect') {
                dz1.style.borderColor = '#f44336';
                dz1.style.borderStyle = 'solid';
                dz1.style.background  = 'rgba(244, 67, 54, 0.3)';
            }
        }

        showH5pFeedback(container, correct, total);
    }

    function resetH5pDragQuestion(id) {
        var container = document.getElementById(id);
        if (!container) return;
        var dqContainer = container.querySelector('.h5p-dq-container');
        if (!dqContainer) return;

        // Retirer tous les clones placés (ils vivent désormais dans le container, pas dans les zones)
        var clones = dqContainer.querySelectorAll('.h5p-dq-draggable.placed-clone');
        clones.forEach(function(c) { if (c.parentNode) c.parentNode.removeChild(c); });

        // Réinitialiser le style des zones
        var dropZones = container.querySelectorAll('.h5p-dq-dropzone');
        for (var i = 0; i < dropZones.length; i++) {
            var dz = dropZones[i];
            dz.style.borderColor = 'transparent';
            dz.style.borderStyle = 'dashed';
            dz.style.background = 'transparent';
            delete dz.dataset._verdict;
        }

        // Réafficher tous les éléments draggables originaux
        var draggables = dqContainer.querySelectorAll('.h5p-dq-draggable:not(.placed-clone)');
        for (var j = 0; j < draggables.length; j++) {
            draggables[j].style.opacity = '1';
            draggables[j].style.pointerEvents = '';
            draggables[j].style.visibility = 'visible';
        }

        var fb = container.querySelector('.h5p-feedback');
        if (fb) fb.style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', function() { updateNavButtons(); });
    </script>
</head>
<body class="<?= $source === 'token' ? 'student-mode' : '' ?>">
    <?php if ($isDriveSource): ?>
    <div id="driveLoadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.95);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
        <div style="text-align:center;max-width:320px;">
            <div style="font-size:2rem;margin-bottom:1rem;">📚</div>
            <div style="font-size:1.1rem;color:#334155;font-weight:600;margin-bottom:0.75rem;">Chargement du cours</div>
            <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:0.5rem;">
                <div id="driveLoadingBar" style="width:0%;height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:4px;transition:width 0.3s ease;"></div>
            </div>
            <div id="driveLoadingText" style="font-size:0.8rem;color:#94a3b8;">Chargement des images...</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="course-layout">
        <aside class="course-sidebar" id="sidebar">
            <div class="sidebar-header">
                <button class="sidebar-close" onclick="toggleSidebar()">✕</button>
                <span class="sidebar-title">SOMMAIRE</span>
            </div>
            
            <?php foreach ($sections as $sIndex => $section): 
                $sectionName = !empty($section['name']) ? $section['name'] : 'Section ' . ($section['number'] + 1);
                $activities = $section['activities'] ?? [];
            ?>
            <div class="nav-section" data-section="<?= $sIndex ?>">
                <div class="nav-section-header">
                    <span class="nav-section-icon" onclick="toggleSection(this.parentElement)">▼</span>
                    <span class="section-title" onclick="toggleSection(this.parentElement)"><?= htmlspecialchars($sectionName) ?></span>
                    <button class="visibility-toggle" onclick="toggleSectionVisibility(<?= $sIndex ?>, event)" title="Afficher/Masquer cette section">👁</button>
                </div>
                <ul class="nav-section-list">
                    <?php foreach ($activities as $aIndex => $activity): 
                        $itemId = 'activity-' . $sIndex . '-' . $aIndex;
                        $isFirst = ($sIndex === 0 && $aIndex === 0);
                    ?>
                    <li class="nav-item <?= $isFirst ? 'active' : '' ?>" data-id="<?= $itemId ?>">
                        <span class="nav-completion-indicator"></span>
                        <span class="nav-link" onclick="showActivity('<?= $itemId ?>')">
                            <?= htmlspecialchars($activity['name'] ?? 'Activité') ?>
                        </span>
                        <button class="visibility-toggle" onclick="toggleActivityVisibility('<?= $itemId ?>', event)" title="Afficher/Masquer">👁</button>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </aside>
        
        <!-- Bouton toggle sidebar -->
        <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebarCollapse()">
            <span class="toggle-icon">◀</span>
        </button>
        
        <main class="course-main" id="courseMain">
            <header class="course-header">
                <div class="course-header-content">
                    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                    <?php if ($source !== 'token'): ?>
                    <a href="index.php" class="back-btn">← Retour</a>
                    <?php endif; ?>
                    <h1 class="course-title"><?= htmlspecialchars($course['course_fullname'] ?? 'Cours') ?></h1>
                    <?php if ($source !== 'token'): ?>
                    <span class="mode-badge">👨‍🏫 Mode professeur</span>
                    <button class="btn-header-action btn-edit" onclick="editCourse()">
                        ✏️ Éditer le cours
                    </button>
                    <button class="btn-header-action" onclick="openStudentLinkModal()">
                        🔑 Code élève
                    </button>
                    <button class="btn-header-action" onclick="generatePDF()">
                        📄 Générer PDF
                    </button>
                    <?php endif; ?>
                </div>
            </header>
            
            <div class="course-content">
                <?php foreach ($sections as $sIndex => $section): ?>
                    <?php foreach ($section['activities'] ?? [] as $aIndex => $activity): 
                        $itemId = 'activity-' . $sIndex . '-' . $aIndex;
                        $isFirst = ($sIndex === 0 && $aIndex === 0);
                    ?>
                    <div class="activity-wrapper <?= $isFirst ? 'active' : '' ?>" id="<?= $itemId ?>">
                        <?= $renderer->renderSingleActivity($activity) ?>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            
            <nav class="course-nav-bar">
                <div class="nav-bar-content">
                    <button class="nav-btn nav-btn-prev" id="prevBtn" onclick="navigateActivity(-1)">← Précédent</button>
                    <button class="nav-btn nav-btn-next" id="nextBtn" onclick="navigateActivity(1)">SUIVANT →</button>
                </div>
                <!-- Barre de zoom : élément flex de la nav-bar, placée à droite après les boutons (ne chevauche jamais "Suivant") -->
                <div class="viewer-zoom-bar" id="viewerZoomBar" aria-label="Niveau de zoom">
                    <button onclick="viewerZoom(-0.1)" title="Réduire">−</button>
                    <input type="range" min="30" max="400" value="100" id="viewerZoomSlider" oninput="viewerZoomTo(this.value, true)">
                    <button onclick="viewerZoom(0.1)" title="Agrandir">+</button>
                    <span id="viewerZoomLabel">100%</span>
                    <button onclick="viewerZoomFit()" title="Adapter à l'écran">⊞</button>
                </div>
            </nav>
        </main>
    </div>
    
    <?php if ($source !== 'token'): ?>
    <!-- Modal Code élève -->
    <div class="modal-overlay" id="studentLinkModal">
        <div class="modal-student-link">
            <div class="modal-header">
                <h3>🔑 Générer un code élève</h3>
                <button class="modal-close" onclick="closeStudentLinkModal()">✕</button>
            </div>
            <div class="modal-body">
                <p>Ce code permet aux élèves d'accéder au cours depuis la page d'accueil.</p>
                <p style="font-size: 0.9rem; color: var(--text-secondary, #666);">Valable 2 mois. Les activités masquées seront invisibles pour les élèves.</p>

                <div id="hiddenCountInfo" style="display: none; background: var(--warn-bg, #fff3cd); color: var(--warn-text, inherit); border-radius: 6px; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.9rem;">
                    ⚠️ <span id="hiddenCountText"></span>
                </div>
                
                <div id="studentLinkLoading" style="text-align: center; padding: 2rem;">
                    <div class="spinner-small"></div>
                    <p>Génération en cours...</p>
                </div>
                
                <div id="studentLinkResult" style="display: none; text-align: center;">
                    <p style="font-size: 0.9rem; color: var(--text-secondary, #666); margin-bottom: 0.75rem;">Dictez ce code à vos élèves :</p>
                    <div id="studentCodeDisplay" style="font-size: 2.5rem; font-weight: 700; letter-spacing: 0.3em; color: var(--brand-soft-text, #5b21b6); font-family: 'DM Sans', monospace; padding: 1rem; background: var(--brand-soft-bg, #f3f0ff); border-radius: 10px; margin-bottom: 1rem; user-select: all;"></div>
                    <button class="btn-copy" onclick="copyStudentCode()">📋 Copier le code</button>
                    <p id="copyConfirm" style="color: #4caf50; font-size: 0.9rem; display: none; margin-top: 0.5rem;">✓ Code copié !</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-modal-close" onclick="closeStudentLinkModal()">Fermer</button>
            </div>
        </div>
    </div>
    
    <!-- Modal génération PDF -->
    <div class="modal-overlay" id="pdfModal">
        <div class="pdf-modal">
            <h3>📄 Génération du PDF</h3>
            <div class="pdf-progress-container">
                <div class="pdf-progress-bar" id="pdfProgressBar"></div>
            </div>
            <div class="pdf-status" id="pdfStatus">Préparation...</div>
            <button class="pdf-cancel-btn" id="pdfCancelBtn" onclick="cancelPdfGeneration()">Annuler</button>
        </div>
    </div>
    
    <style>
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    .modal-overlay.active { display: flex; }
    
    .modal-student-link {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        overflow: hidden;
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #eee;
    }
    .modal-header h3 { margin: 0; font-size: 1.1rem; }
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #888;
    }
    .modal-close:hover { color: #333; }
    .modal-body { padding: 1.5rem; }
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #eee;
        text-align: right;
    }
    
    .student-url-box {
        display: flex;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    .student-url-box input {
        flex: 1;
        padding: 0.75rem;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 0.9rem;
        background: #f9f9f9;
    }
    .btn-copy {
        padding: 0.75rem 1rem;
        background: #5b21b6;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    .btn-copy:hover { background: #7c3aed; }
    
    .btn-modal-close {
        padding: 0.5rem 1.5rem;
        background: #f0f0f0;
        border: none;
        border-radius: 6px;
        cursor: pointer;
    }
    .btn-modal-close:hover { background: #e0e0e0; }
    
    .spinner-small {
        width: 30px;
        height: 30px;
        border: 3px solid #f0f0f0;
        border-top-color: #5b21b6;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    /* Modal génération PDF */
    .pdf-modal {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 450px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        overflow: hidden;
        text-align: center;
        padding: 2rem;
    }
    .pdf-modal h3 {
        margin: 0 0 1.5rem;
        font-size: 1.2rem;
        color: #333;
    }
    .pdf-progress-container {
        background: #f0f0f0;
        border-radius: 10px;
        height: 20px;
        overflow: hidden;
        margin: 1rem 0;
    }
    .pdf-progress-bar {
        background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
        height: 100%;
        width: 0%;
        transition: width 0.3s;
        border-radius: 10px;
    }
    .pdf-status {
        color: #666;
        font-size: 0.9rem;
        margin-top: 1rem;
    }
    .pdf-cancel-btn {
        margin-top: 1.5rem;
        padding: 0.6rem 1.5rem;
        background: #f0f0f0;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.9rem;
    }
    .pdf-cancel-btn:hover { background: #e0e0e0; }
    </style>
    
    <script>
    var courseIdentifier = '<?= htmlspecialchars($courseIdentifier ?? '') ?>';
    var courseType = '<?= htmlspecialchars($courseType ?? 'gdrive') ?>';
    
    function openStudentLinkModal() {
        document.getElementById('studentLinkModal').classList.add('active');
        document.getElementById('studentLinkLoading').style.display = 'block';
        document.getElementById('studentLinkResult').style.display = 'none';
        document.getElementById('copyConfirm').style.display = 'none';
        
        // Afficher le nombre d'activités masquées
        var hiddenCount = hiddenActivities.size;
        var hiddenInfo = document.getElementById('hiddenCountInfo');
        if (hiddenCount > 0) {
            document.getElementById('hiddenCountText').textContent = hiddenCount + ' activité(s) masquée(s) pour les élèves';
            hiddenInfo.style.display = 'block';
        } else {
            hiddenInfo.style.display = 'none';
        }
        
        // Générer le code avec les activités cachées
        var formData = new FormData();
        formData.append('action', 'generate_student_code');
        formData.append('course_id', courseIdentifier);
        formData.append('type', courseType);
        formData.append('hidden', getHiddenActivitiesString());
        
        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            document.getElementById('studentLinkLoading').style.display = 'none';
            if (data.success) {
                document.getElementById('studentCodeDisplay').textContent = data.code;
                document.getElementById('studentLinkResult').style.display = 'block';
            } else {
                alert('Erreur: ' + (data.error || 'Impossible de générer le code'));
                closeStudentLinkModal();
            }
        })
        .catch(function(err) {
            document.getElementById('studentLinkLoading').style.display = 'none';
            alert('Erreur réseau');
            console.error(err);
            closeStudentLinkModal();
        });
    }
    
    function closeStudentLinkModal() {
        document.getElementById('studentLinkModal').classList.remove('active');
    }
    
    function copyStudentCode() {
        var code = document.getElementById('studentCodeDisplay').textContent;
        navigator.clipboard.writeText(code).then(function() {
            document.getElementById('copyConfirm').style.display = 'block';
            setTimeout(function() {
                document.getElementById('copyConfirm').style.display = 'none';
            }, 3000);
        }).catch(function() {
            // Fallback
            var textarea = document.createElement('textarea');
            textarea.value = code;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            document.getElementById('copyConfirm').style.display = 'block';
            setTimeout(function() {
                document.getElementById('copyConfirm').style.display = 'none';
            }, 3000);
        });
    }
    
    // === Génération PDF avec html2canvas + jsPDF ===
    var pdfGenerationCancelled = false;
    
    async function generatePDF() {
        pdfGenerationCancelled = false;
        
        // Afficher le modal de progression
        document.getElementById('pdfModal').classList.add('active');
        document.getElementById('pdfProgressBar').style.width = '0%';
        document.getElementById('pdfStatus').textContent = 'Préparation...';
        document.getElementById('pdfCancelBtn').textContent = 'Annuler';
        
        try {
            var _pdfOriginalSrcs = [];
            var _pdfInlinedStyles = [];
            
            function _pdfRestoreAll() {
                // Restaurer les images
                _pdfOriginalSrcs.forEach(function(o) { o.el.src = o.src; });
                // Restaurer les CSS externes
                _pdfInlinedStyles.forEach(function(o) {
                    o.link.disabled = false;
                    if (o.style.parentNode) o.style.parentNode.removeChild(o.style);
                });
                _pdfInlinedStyles = [];
                // Retirer le style fix PDF
                _removePdfFixStyle();
            }
            
            // Convertir les images déjà chargées en data URLs (in-memory, pas de requête réseau)
            // Inclut les images des activités cachées pour éviter les requêtes lors de la capture
            document.getElementById('pdfStatus').textContent = 'Préparation des images...';
            
            // D'abord afficher brièvement toutes les activités pour charger les images
            var allWrappers = document.querySelectorAll('.activity-wrapper');
            allWrappers.forEach(function(w) { w.style.display = 'block'; });
            await new Promise(function(r) { setTimeout(r, 500); });
            
            // Pixel transparent pour remplacer les images cassées
            var BLANK_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            
            // Convertir toutes les images en data URL pour éliminer les requêtes réseau
            var imgList = Array.from(document.querySelectorAll('img[src]'));
            
            // Phase 1: Attendre le chargement de toutes les images EN PARALLÈLE (max 3s)
            document.getElementById('pdfStatus').textContent = 'Chargement des images...';
            await Promise.all(imgList.map(function(img) {
                if (img.src.startsWith('data:') || img.complete) return Promise.resolve();
                return new Promise(function(r) { img.onload = r; img.onerror = r; setTimeout(r, 3000); });
            }));
            
            // Phase 2: Convertir chaque image en data URL (instantané, pas de réseau)
            for (var imgI = 0; imgI < imgList.length; imgI++) {
                var img = imgList[imgI];
                if (img.src.startsWith('data:')) continue;
                
                if (img.naturalWidth === 0) {
                    _pdfOriginalSrcs.push({ el: img, src: img.src });
                    img.src = BLANK_PIXEL;
                    continue;
                }
                
                // Essayer la conversion canvas directe (same-origin)
                var converted = false;
                try {
                    var c = document.createElement('canvas');
                    c.width = img.naturalWidth;
                    c.height = img.naturalHeight;
                    c.getContext('2d').drawImage(img, 0, 0);
                    var dataUrl = c.toDataURL('image/jpeg', 0.85);
                    _pdfOriginalSrcs.push({ el: img, src: img.src });
                    img.src = dataUrl;
                    converted = true;
                } catch(e) { /* CORS taint */ }
                
                if (!converted) {
                    // Re-fetch en blob pour contourner le taint CORS
                    try {
                        var resp = await fetch(img.src);
                        if (resp.ok) {
                            var blob = await resp.blob();
                            var reader = new FileReader();
                            var blobDataUrl = await new Promise(function(resolve) {
                                reader.onload = function() { resolve(reader.result); };
                                reader.onerror = function() { resolve(null); };
                                reader.readAsDataURL(blob);
                            });
                            if (blobDataUrl) {
                                _pdfOriginalSrcs.push({ el: img, src: img.src });
                                img.src = blobDataUrl;
                                converted = true;
                            }
                        }
                    } catch(e2) { /* fetch failed */ }
                }
                
                if (!converted) {
                    _pdfOriginalSrcs.push({ el: img, src: img.src });
                    img.src = BLANK_PIXEL;
                }
                
                if (imgI % 20 === 0) {
                    document.getElementById('pdfStatus').textContent = 'Préparation des images (' + Math.round((imgI / imgList.length) * 100) + '%)...';
                }
            }
            
            // Recacher les activités
            allWrappers.forEach(function(w) { w.style.display = ''; });
            
            // Inliner toutes les feuilles de style externes dans le DOM
            // pour que html2canvas n'ait pas à les re-fetch à chaque capture
            document.getElementById('pdfStatus').textContent = 'Optimisation des styles...';
            var stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
            for (var si = 0; si < stylesheets.length; si++) {
                var link = stylesheets[si];
                try {
                    var sheet = link.sheet;
                    if (sheet && sheet.cssRules) {
                        var cssText = '';
                        for (var ri = 0; ri < sheet.cssRules.length; ri++) {
                            cssText += sheet.cssRules[ri].cssText + '\n';
                        }
                        if (cssText) {
                            var inlineStyle = document.createElement('style');
                            inlineStyle.textContent = cssText;
                            inlineStyle.setAttribute('data-pdf-inline', 'true');
                            link.parentNode.insertBefore(inlineStyle, link.nextSibling);
                            link.disabled = true;
                            _pdfInlinedStyles.push({ link: link, style: inlineStyle });
                        }
                    }
                } catch(e) { /* CORS stylesheet - skip */ }
            }
            
            const { jsPDF } = window.jspdf;
            // Format paysage A4 avec compression activée
            const pdf = new jsPDF({
                orientation: 'l',
                unit: 'mm',
                format: 'a4',
                compress: true
            });
            const pageWidth = 297;
            const pageHeight = 210;
            const margin = 3;
            
            // Compter le nombre total de captures à faire
            let totalCaptures = 0;
            const activityWrappers = document.querySelectorAll('.activity-wrapper:not(.visibility-hidden)');
            activityWrappers.forEach(wrapper => {
                const cpContainer = wrapper.querySelector('.h5p-coursepresentation');
                if (cpContainer) {
                    const total = parseInt(cpContainer.getAttribute('data-total')) || cpContainer.querySelectorAll('.h5p-cp-slide').length;
                    totalCaptures += total;
                } else {
                    totalCaptures += 1;
                }
            });
            
            let currentCapture = 0;
            
            // Sauvegarder l'état actuel
            const originalActivity = document.querySelector('.activity-wrapper.active');
            const originalActiveItem = document.querySelector('.nav-item.active');
            const navBar = document.querySelector('.course-nav-bar');
            const header = document.querySelector('.course-header');
            const sidebar = document.getElementById('sidebar');
            const courseMain = document.querySelector('.course-main');
            const courseContent = document.querySelector('.course-content');
            
            // Cacher la barre de nav en bas et le header (mais garder le sidebar!)
            if (navBar) navBar.style.display = 'none';
            if (header) header.style.display = 'none';
            
            // S'assurer que le sidebar est visible et non collapsed
            if (sidebar) {
                sidebar.classList.remove('collapsed');
                sidebar.style.position = 'relative';
                sidebar.style.transform = 'none';
                sidebar.style.width = '250px';
                sidebar.style.flexShrink = '0';
            }
            if (courseMain) {
                courseMain.classList.remove('sidebar-collapsed');
                courseMain.style.marginLeft = '0';
                courseMain.style.flex = '1';
            }
            
            // Ajuster le course-content pour qu'il prenne toute la largeur disponible
            if (courseContent) {
                courseContent.style.maxWidth = 'none';
                courseContent.style.padding = '1rem';
                courseContent.style.margin = '0';
            }
            
            // Page de titre
            pdf.setFillColor(91, 33, 182);
            pdf.rect(0, 0, pageWidth, pageHeight, 'F');
            pdf.setTextColor(255, 255, 255);
            pdf.setFontSize(32);
            pdf.setFont('helvetica', 'bold');
            const courseTitle = '<?= addslashes($course['course_fullname'] ?? 'Cours') ?>';
            const titleLines = pdf.splitTextToSize(courseTitle, pageWidth - 40);
            pdf.text(titleLines, pageWidth / 2, pageHeight / 2 - 20, { align: 'center' });
            pdf.setFontSize(14);
            pdf.setFont('helvetica', 'normal');
            pdf.text('Généré le ' + new Date().toLocaleDateString('fr-FR') + ' à ' + new Date().toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'}), pageWidth / 2, pageHeight / 2 + 20, { align: 'center' });
            pdf.setFontSize(12);
            pdf.text(totalCaptures + ' pages', pageWidth / 2, pageHeight / 2 + 35, { align: 'center' });
            
            // Parcourir chaque activité
            for (let i = 0; i < activityWrappers.length; i++) {
                if (pdfGenerationCancelled) break;
                
                const wrapper = activityWrappers[i];
                const activityId = wrapper.id;
                const activityName = document.querySelector('.nav-item[data-id="' + activityId + '"] .nav-link')?.textContent?.trim() || 'Activité';
                
                // Afficher cette activité
                document.querySelectorAll('.activity-wrapper').forEach(w => {
                    w.classList.remove('active');
                    w.style.display = 'none';
                });
                wrapper.classList.add('active');
                wrapper.style.display = 'block';
                wrapper.style.transform = '';
                wrapper.style.width = '';
                wrapper.style.marginLeft = '';
                
                // Mettre à jour l'item actif dans le sidebar
                document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
                const navItem = document.querySelector('.nav-item[data-id="' + activityId + '"]');
                if (navItem) {
                    navItem.classList.add('active');
                    // Ouvrir la section si elle est fermée
                    const section = navItem.closest('.nav-section');
                    if (section && section.classList.contains('collapsed')) {
                        section.classList.remove('collapsed');
                    }
                    // Scroll pour que l'item soit visible
                    navItem.scrollIntoView({ block: 'center' });
                }
                
                // Vérifier si c'est un CoursePresentation
                const cpContainer = wrapper.querySelector('.h5p-coursepresentation');
                
                if (cpContainer) {
                    // C'est un CoursePresentation - capturer chaque slide
                    const cpId = cpContainer.id;
                    const totalSlides = parseInt(cpContainer.getAttribute('data-total')) || cpContainer.querySelectorAll('.h5p-cp-slide').length;
                    
                    for (let s = 0; s < totalSlides; s++) {
                        if (pdfGenerationCancelled) break;
                        
                        currentCapture++;
                        const progress = Math.round((currentCapture / totalCaptures) * 100);
                        document.getElementById('pdfProgressBar').style.width = progress + '%';
                        document.getElementById('pdfStatus').textContent = activityName + ' - Slide ' + (s + 1) + '/' + totalSlides + ' (' + currentCapture + '/' + totalCaptures + ')';
                        
                        // Utiliser la fonction goToCpSlide pour naviguer
                        goToCpSlide(cpId, s, totalSlides);
                        
                        // Attendre le rendu complet (images, etc.)
                        await new Promise(r => setTimeout(r, 100));
                        
                        // Capturer la page entière (sidebar + contenu) - format paysage
                        pdf.addPage('l');
                        await capturePageToPdf(pdf, pageWidth, pageHeight, margin, 'landscape');
                    }
                } else {
                    // Activité normale (non CoursePresentation)
                    currentCapture++;
                    const progress = Math.round((currentCapture / totalCaptures) * 100);
                    document.getElementById('pdfProgressBar').style.width = progress + '%';
                    document.getElementById('pdfStatus').textContent = activityName + ' (' + currentCapture + '/' + totalCaptures + ')';
                    
                    // Délai pour le rendu des contenus dynamiques
                    await new Promise(r => setTimeout(r, 100));
                    
                    // Toujours format paysage pour la cohérence
                    pdf.addPage('l');
                    await capturePageToPdf(pdf, pageWidth, pageHeight, margin, 'landscape');
                }
            }
            
            if (!pdfGenerationCancelled) {
                // Restaurer l'interface
                if (navBar) navBar.style.display = '';
                if (header) header.style.display = '';
                if (sidebar) {
                    sidebar.style.position = '';
                    sidebar.style.transform = '';
                    sidebar.style.width = '';
                    sidebar.style.flexShrink = '';
                }
                if (courseMain) {
                    courseMain.style.marginLeft = '';
                    courseMain.style.flex = '';
                }
                if (courseContent) {
                    courseContent.style.maxWidth = '';
                    courseContent.style.padding = '';
                    courseContent.style.margin = '';
                }
                
                // Restaurer l'activité originale
                document.querySelectorAll('.activity-wrapper').forEach(w => {
                    w.classList.remove('active');
                    w.style.display = '';
                });
                if (originalActivity) {
                    originalActivity.classList.add('active');
                }
                
                // Restaurer l'item actif dans le sidebar
                document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
                if (originalActiveItem) {
                    originalActiveItem.classList.add('active');
                }
                
                // Télécharger le PDF
                document.getElementById('pdfStatus').textContent = 'Téléchargement...';
                const fileName = courseTitle.replace(/[^a-zA-Z0-9àâäéèêëïîôùûüç\s]/gi, '').replace(/\s+/g, '_').substring(0, 50) + '.pdf';
                pdf.save(fileName);
                
                document.getElementById('pdfStatus').textContent = '✓ PDF généré avec succès !';
                document.getElementById('pdfCancelBtn').textContent = 'Fermer';
                
                // Restaurer les sources originales des images
                _pdfRestoreAll();
                
                // Supprimer le dossier pdf-preview sur le serveur
                _cleanupPdfPreview();
                
                // Si ouvert depuis l'éditeur (?pdf=1), fermer automatiquement l'onglet
                if (new URLSearchParams(window.location.search).get('pdf') === '1') {
                    setTimeout(function() { window.close(); }, 1500);
                }
            } else {
                // Restaurer l'interface après annulation
                if (navBar) navBar.style.display = '';
                if (header) header.style.display = '';
                if (sidebar) {
                    sidebar.style.position = '';
                    sidebar.style.transform = '';
                    sidebar.style.width = '';
                    sidebar.style.flexShrink = '';
                }
                if (courseMain) {
                    courseMain.style.marginLeft = '';
                    courseMain.style.flex = '';
                }
                if (courseContent) {
                    courseContent.style.maxWidth = '';
                    courseContent.style.padding = '';
                    courseContent.style.margin = '';
                }
                
                document.querySelectorAll('.activity-wrapper').forEach(w => {
                    w.classList.remove('active');
                    w.style.display = '';
                });
                if (originalActivity) {
                    originalActivity.classList.add('active');
                }
                document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
                if (originalActiveItem) {
                    originalActiveItem.classList.add('active');
                }
                
                // Restaurer les sources originales des images
                _pdfRestoreAll();
                
                closePdfModal();
            }
            
        } catch (error) {
            console.error('Erreur génération PDF:', error);
            document.getElementById('pdfStatus').textContent = '❌ Erreur: ' + error.message;
            document.getElementById('pdfCancelBtn').textContent = 'Fermer';
            // Restaurer les sources originales des images
            _pdfRestoreAll();
        }
    }
    
    // Fonction pour attendre que toutes les images soient chargées
    async function waitForImages(element) {
        const images = element.querySelectorAll('img');
        const promises = Array.from(images).map(img => {
            if (img.complete) return Promise.resolve();
            return new Promise((resolve) => {
                img.onload = resolve;
                img.onerror = resolve;
                // Timeout de sécurité
                setTimeout(resolve, 2000);
            });
        });
        await Promise.all(promises);
    }
    
    // Style fix injecté UNE SEULE FOIS avant la boucle de capture
    var _pdfFixStyleInjected = false;
    function _ensurePdfFixStyle() {
        if (!_pdfFixStyleInjected) {
            var s = document.createElement('style');
            s.id = 'pdfCaptureFixStyle';
            s.textContent = '.h5p-cp-element { overflow: visible !important; } .h5p-cp-text { overflow: visible !important; height: auto !important; }';
            document.head.appendChild(s);
            _pdfFixStyleInjected = true;
        }
    }
    function _removePdfFixStyle() {
        var s = document.getElementById('pdfCaptureFixStyle');
        if (s) s.remove();
        _pdfFixStyleInjected = false;
    }
    
    async function capturePageToPdf(pdf, pageWidth, pageHeight, margin, orientation) {
        try {
            var t0 = performance.now();
            const captureElement = document.querySelector('.course-layout');
            
            _ensurePdfFixStyle();
            
            // Format paysage A4 : 297x210mm  
            const captureWidth = 1100;
            const captureHeight = Math.round(captureWidth * (210/297));
            
            // Forcer le scroll en haut
            window.scrollTo(0, 0);
            if (captureElement) captureElement.scrollTop = 0;
            
            var h2cOpts = {
                scale: 1,
                useCORS: false,
                allowTaint: true,
                backgroundColor: '#f5f5f5',
                logging: false,
                width: captureWidth,
                height: captureHeight,
                windowWidth: captureWidth,
                windowHeight: captureHeight,
                scrollX: 0,
                scrollY: 0,
                x: 0,
                y: 0,
                imageTimeout: 0,       // Toutes les images sont déjà en data URL
                removeContainer: true,
                // Ignorer les wrappers d'activités cachés (réduit le DOM à parser)
                ignoreElements: function(el) {
                    return el.classList && el.classList.contains('activity-wrapper') && el.style.display === 'none';
                }
            };
            
            var t1 = performance.now();
            var canvas = await Promise.race([
                html2canvas(captureElement, h2cOpts),
                new Promise(function(_, reject) {
                    setTimeout(function() { reject(new Error('capture timeout')); }, 10000);
                })
            ]);
            var t2 = performance.now();
            
            var imgData;
            try {
                imgData = canvas.toDataURL('image/jpeg', 0.75);
            } catch(taintErr) {
                console.warn('[PDF] Canvas tainted, retry sans allowTaint');
                h2cOpts.allowTaint = false;
                canvas = await html2canvas(captureElement, h2cOpts);
                imgData = canvas.toDataURL('image/jpeg', 0.75);
                t2 = performance.now();
            }
            
            var t3 = performance.now();
            const imgWidth = pageWidth - margin * 2;
            const imgHeight = pageHeight - margin * 2;
            pdf.addImage(imgData, 'JPEG', margin, margin, imgWidth, imgHeight);
            var t4 = performance.now();
            
            console.log('[PDF-TIMING] prep=' + Math.round(t1-t0) + 'ms  html2canvas=' + Math.round(t2-t1) + 'ms  toDataURL=' + Math.round(t3-t2) + 'ms  addImage=' + Math.round(t4-t3) + 'ms  TOTAL=' + Math.round(t4-t0) + 'ms');
            
        } catch (err) {
            console.error('Erreur capture:', err);
        }
    }
    
    function cancelPdfGeneration() {
        if (document.getElementById('pdfCancelBtn').textContent === 'Fermer') {
            closePdfModal();
        } else {
            pdfGenerationCancelled = true;
            document.getElementById('pdfStatus').textContent = 'Annulation...';
        }
    }
    
    function closePdfModal() {
        document.getElementById('pdfModal').classList.remove('active');
    }
    
    // Nettoyer le dossier pdf-preview sur le serveur après génération
    var _pdfCleanupDone = false;
    function _cleanupPdfPreview() {
        if (_pdfCleanupDone) return;
        _pdfCleanupDone = true;
        var previewId = '<?= htmlspecialchars($courseIdentifier ?? '') ?>';
        if (!previewId || previewId.indexOf('pdf-preview-') !== 0) return;
        navigator.sendBeacon('api/editor_api.php', JSON.stringify({ action: 'cleanup_pdf_preview', previewId: previewId }));
    }
    window.addEventListener('beforeunload', _cleanupPdfPreview);
    
    // Informations du cours pour l'édition
    var courseEditInfo = {
        type: '<?= $courseType ?>',
        gdriveId: '<?= htmlspecialchars($gdriveId) ?>',
        localId: '<?= htmlspecialchars($courseIdentifier) ?>',
        name: '<?= htmlspecialchars(addslashes($course['course_fullname'] ?? 'Cours')) ?>'
    };
    
    // Fonction pour ouvrir l'éditeur avec ce cours
    function editCourse() {
        // Stocker les infos dans sessionStorage pour l'éditeur
        try {
            sessionStorage.setItem('courseToLoad', JSON.stringify(courseEditInfo));
            // Ouvrir l'éditeur
            window.location.href = 'editor.php?load=course';
        } catch (e) {
            console.error('Erreur:', e);
            alert('Erreur: impossible de préparer le cours pour l\'édition.');
        }
    }
    </script>
    <?php endif; ?>
    
    <?php if ($source === 'token' && !empty($hiddenActivities)): ?>
    <script>
    // Initialise les activités cachées pour le mode élève
    (function() {
        var hiddenList = '<?= htmlspecialchars($hiddenActivities) ?>'.split(',');
        hiddenList.forEach(function(activityId) {
            if (!activityId) return;
            
            // Ajouter à la liste des activités cachées
            hiddenActivities.add(activityId);
            
            // Masquer dans le sidebar
            var item = document.querySelector('.nav-item[data-id="' + activityId + '"]');
            if (item) {
                item.classList.add('visibility-hidden');
            }
            
            // Masquer le contenu
            var content = document.getElementById(activityId);
            if (content) {
                content.classList.add('visibility-hidden');
            }
            
            // Vérifier si toute la section doit être masquée
            var section = item ? item.closest('.nav-section') : null;
            if (section) {
                var visibleItems = section.querySelectorAll('.nav-item:not(.visibility-hidden)');
                if (visibleItems.length === 0) {
                    section.classList.add('visibility-hidden');
                    var sectionIndex = section.getAttribute('data-section');
                    if (sectionIndex) hiddenSections.add(parseInt(sectionIndex));
                }
            }
        });
        
        // Naviguer vers la première activité visible
        setTimeout(function() {
            var firstVisible = document.querySelector('.nav-item:not(.visibility-hidden)');
            if (firstVisible) {
                var activityId = firstVisible.getAttribute('data-id');
                if (activityId) showActivity(activityId);
            }
        }, 100);
    })();
    </script>
    <?php endif; ?>
    
    <?php if (isset($_GET['pdf']) && $_GET['pdf'] == '1'): ?>
    <script>
    // Afficher la modal immédiatement
    (function() {
        var modal = document.getElementById('pdfModal');
        if (modal) {
            modal.classList.add('active');
            document.getElementById('pdfStatus').textContent = 'Chargement du cours...';
        }
    })();
    // Auto-déclencher la génération PDF dès que le DOM est prêt
    document.addEventListener('DOMContentLoaded', function() {
        // Attendre que les images visibles soient chargées
        var imgs = document.querySelectorAll('img[src]');
        var promises = Array.from(imgs).map(function(img) {
            if (img.complete) return Promise.resolve();
            return new Promise(function(r) {
                img.onload = r;
                img.onerror = r;
                setTimeout(r, 3000);
            });
        });
        Promise.all(promises).then(function() {
            if (typeof generatePDF === 'function') generatePDF();
        });
    });
    </script>
    <?php endif; ?>
    

    
    <?php if ($isDriveSource): ?>
    <script>
    (function() {
        var overlay = document.getElementById('driveLoadingOverlay');
        if (!overlay) return;
        
        var bar = document.getElementById('driveLoadingBar');
        var text = document.getElementById('driveLoadingText');
        var images = document.querySelectorAll('img[src*="file_drive.php"]');
        var total = images.length;
        
        if (total === 0) {
            overlay.remove();
            return;
        }
        
        var loaded = 0;
        var failed = 0;
        
        function updateProgress() {
            var done = loaded + failed;
            var pct = Math.round((done / total) * 100);
            bar.style.width = pct + '%';
            text.textContent = done + ' / ' + total + ' images chargées';
            
            if (done >= total) {
                bar.style.width = '100%';
                text.textContent = 'Prêt !';
                setTimeout(function() {
                    overlay.style.transition = 'opacity 0.3s';
                    overlay.style.opacity = '0';
                    setTimeout(function() { overlay.remove(); }, 300);
                }, 200);
            }
        }
        
        images.forEach(function(img) {
            if (img.complete && img.naturalWidth > 0) {
                loaded++;
                updateProgress();
            } else {
                img.addEventListener('load', function() { loaded++; updateProgress(); });
                img.addEventListener('error', function() { failed++; updateProgress(); });
            }
        });
        
        // Sécurité : retirer l'overlay après 15 secondes max
        setTimeout(function() {
            if (overlay.parentNode) {
                overlay.style.transition = 'opacity 0.3s';
                overlay.style.opacity = '0';
                setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
            }
        }, 15000);
    })();
    </script>
    <?php endif; ?>
<?php include __DIR__ . '/includes/drive_upload_widget.php'; ?>
<?php if ($needsDriveUpload && !empty($gdriveId)): ?>
<script>
// Declencher l'upload Drive pour ce cours permanent (affiche depuis fichiers locaux)
if (typeof DriveUploadWidget !== 'undefined') {
    DriveUploadWidget.enqueue('<?= addslashes($gdriveId) ?>', '<?= addslashes($course['course_fullname'] ?? $course['course_shortname'] ?? $gdriveId) ?>');
}
</script>
<?php endif; ?>
<?php if ($needsDriveUpload && !empty($tempCourseId)): ?>
<script>
// Declencher l'upload Drive pour ce cours temporaire
if (typeof DriveUploadWidget !== 'undefined') {
    DriveUploadWidget.enqueue('<?= addslashes($tempCourseId) ?>', '<?= addslashes($tempCourseName ?: $course['course_fullname'] ?? $course['course_shortname'] ?? $tempCourseId) ?>', 'temp');
}
</script>
<?php endif; ?>
</body>
</html>
