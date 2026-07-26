<?php
/**
 * EleaSecours - Page d'accueil
 * Solution de secours pour afficher les parcours Éléa (.mbz)
 */

ini_set('session.gc_maxlifetime', 28800); // 8 heures (doit être défini AVANT session_start)
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/GoogleDriveLoader.php';
require_once __DIR__ . '/includes/cleanup.php';
require_once __DIR__ . '/includes/session_check.php';

// Vérification de l'expiration custom de session (8h, contournement bridage OVH)
enforceSessionExpiry();

// Nettoyage automatique des brouillons de plus de 24h
cleanupOldDrafts();

// Nettoyage automatique des dossiers PDF de plus de 10 minutes
cleanupPdfPreviews();



// === MOTS DE PASSE ===
// Chargés depuis le fichier secrets (credentials/elea_secrets.php)
$_profPwd = $_SECRETS['password_prof'] ?? 'CHANGEZ_MOI';
$_adminPwd = $_SECRETS['password_admin'] ?? 'CHANGEZ_MOI';
$VALID_PASSWORDS = [
    'normal' => password_hash($_profPwd, PASSWORD_DEFAULT),
    'admin' => password_hash($_adminPwd, PASSWORD_DEFAULT),
];

// === GESTION DE LA DÉCONNEXION ===
if (isset($_GET['logout'])) {
    unset($_SESSION['elea_access']);
    unset($_SESSION['elea_admin']);
    header('Location: index.php?loggedout=1');
    exit;
}

// === VÉRIFICATION DU MOT DE PASSE ===
$loginError = '';
$codeError = '';

// Vérification du code élève
if (isset($_POST['student_code'])) {
    $code = strtoupper(trim($_POST['student_code']));
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    
    $codesFile = COURSES_PATH . '/student_codes.json';
    $found = false;
    
    if (file_exists($codesFile)) {
        $codes = json_decode(file_get_contents($codesFile), true) ?: [];
        
        // Nettoyer les codes expirés (> 2 mois)
        $cleaned = false;
        foreach ($codes as $k => $v) {
            if (($v['expires'] ?? 0) < time()) {
                unset($codes[$k]);
                $cleaned = true;
            }
        }
        if ($cleaned) {
            file_put_contents($codesFile, json_encode($codes));
        }
        
        if (isset($codes[$code]) && $codes[$code]['expires'] > time()) {
            // Code valide → rediriger vers view.php
            header('Location: view.php?code=' . urlencode($code));
            exit;
        }
    }
    
    $codeError = 'Code invalide ou expiré';
}

if (isset($_POST['password'])) {
    $password = $_POST['password'];

    if (password_verify($password, $VALID_PASSWORDS['admin'])) {
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $_SESSION['elea_login_at'] = time();
        header('Location: index.php');
        exit;
    } elseif (password_verify($password, $VALID_PASSWORDS['normal'])) {
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = false;
        $_SESSION['elea_login_at'] = time();
        header('Location: index.php');
        exit;
    } else {
        $loginError = 'Mot de passe incorrect';
    }
}

// API AJAX pour vérifier le mot de passe prof (auto-validation sans Entrée)
if (isset($_POST['action']) && $_POST['action'] === 'check_password') {
    header('Content-Type: application/json');
    $password = $_POST['pwd'] ?? '';

    if (password_verify($password, $VALID_PASSWORDS['admin'])) {
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $_SESSION['elea_login_at'] = time();
        echo json_encode(['success' => true]);
    } elseif (password_verify($password, $VALID_PASSWORDS['normal'])) {
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = false;
        $_SESSION['elea_login_at'] = time();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// === API AJAX (avant vérification de session pour répondre en JSON) ===

// API pour générer un code élève (nécessite session)
if (isset($_POST['action']) && $_POST['action'] === 'generate_student_code') {
    header('Content-Type: application/json');
    
    // Vérifier la session
    if (!isset($_SESSION['elea_access']) || $_SESSION['elea_access'] !== true) {
        echo json_encode(['error' => 'Session expirée. Veuillez vous reconnecter.']);
        exit;
    }
    
    $courseId = $_POST['course_id'] ?? $_POST['gdrive_id'] ?? '';
    $hidden = $_POST['hidden'] ?? '';
    $type = $_POST['type'] ?? 'gdrive';
    
    if (empty($courseId)) {
        echo json_encode(['error' => 'ID manquant']);
        exit;
    }
    
    $code = generateStudentCode($courseId, $hidden, $type);
    echo json_encode(['success' => true, 'code' => $code]);
    exit;
}

// === PAGE DE CONNEXION ===
if (!isset($_SESSION['elea_access']) || $_SESSION['elea_access'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= SITE_NAME ?></title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
        <?php include __DIR__ . '/includes/theme_assets.php'; ?>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'DM Sans', sans-serif;
                background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #a855f7 100%);
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }
            .login-card {
                background: white;
                border-radius: 16px;
                padding: 2.5rem;
                width: 100%;
                max-width: 400px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                text-align: center;
            }
            .login-logo { font-size: 3rem; margin-bottom: 0.5rem; }
            .login-title { font-size: 1.8rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; }
            .login-subtitle { color: #666; margin-bottom: 1.5rem; font-size: 0.95rem; }
            .login-form { display: flex; flex-direction: column; gap: 1rem; }
            .login-input {
                padding: 1rem;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-size: 1.4rem;
                font-family: 'DM Sans', monospace;
                font-weight: 700;
                transition: border-color 0.2s;
                text-align: center;
                letter-spacing: 0.3em;
                text-transform: uppercase;
            }
            .login-input:focus { outline: none; border-color: #7c3aed; }
            .login-input::placeholder { letter-spacing: normal; text-transform: none; font-size: 0.95rem; font-weight: 400; }
            .login-btn {
                padding: 1rem;
                background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .login-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4); }
            .login-error {
                background: #fef2f2;
                color: #dc2626;
                padding: 0.75rem;
                border-radius: 8px;
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }
            .login-hint { margin-top: 1rem; color: #888; font-size: 0.85rem; }
            
            /* Lien prof discret sous la carte */
            .prof-link {
                margin-top: 1.5rem;
                color: rgba(255,255,255,0.45);
                font-size: 0.8rem;
                cursor: pointer;
                transition: color 0.2s;
                user-select: none;
            }
            .prof-link:hover { color: rgba(255,255,255,0.7); }
            
            /* Popup prof */
            .prof-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 100;
                align-items: center;
                justify-content: center;
            }
            .prof-overlay.active { display: flex; }
            .prof-popup {
                background: white;
                border-radius: 12px;
                padding: 2rem;
                width: 90%;
                max-width: 360px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                text-align: center;
            }
            .prof-popup h3 { font-size: 1.1rem; margin-bottom: 1rem; color: #333; }
            .prof-popup .login-input { letter-spacing: normal; text-transform: none; font-size: 1rem; font-weight: 400; }
            .prof-popup .login-form { gap: 0.75rem; }
            .prof-popup-close {
                margin-top: 0.75rem;
                background: none;
                border: none;
                color: #888;
                font-size: 0.85rem;
                cursor: pointer;
            }
            .prof-popup-close:hover { color: #333; }
        </style>
    </head>
    <body>
        <!-- Carte principale : code élève -->
        <div class="login-card">
            <div class="login-logo">🆘</div>
            <h1 class="login-title"><?= SITE_NAME ?></h1>
            <p class="login-subtitle">Entrez le code donné par votre professeur</p>
            
            <?php if ($codeError): ?>
            <div class="login-error"><?= htmlspecialchars($codeError) ?></div>
            <?php endif; ?>
            
            <form class="login-form" method="POST" autocomplete="off" id="codeForm">
                <input type="text" name="student_code" class="login-input" id="codeInput"
                       placeholder="Code d'accès"
                       autocomplete="off" autocorrect="off" autocapitalize="characters"
                       spellcheck="false" data-form-type="other" data-1p-ignore
                       maxlength="6" autofocus>
                <button type="submit" class="login-btn">Rejoindre</button>
            </form>
            
            <p class="login-hint">💡 Votre professeur vous communiquera ce code</p>
        </div>
        
        <!-- Lien prof discret -->
        <div class="prof-link" onclick="openProfPopup()">éléa-secours</div>
        
        <!-- Popup mot de passe prof -->
        <div class="prof-overlay" id="profPopup">
            <div class="prof-popup">
                <h3>🔑 Accès enseignant</h3>
                
                <form method="POST" autocomplete="off" id="profForm">
                    <input type="password" name="password" class="login-input" id="profInput"
                           placeholder="Mot de passe"
                           autocomplete="off" autocorrect="off" autocapitalize="off"
                           spellcheck="false" data-form-type="other" data-1p-ignore>
                </form>
            </div>
        </div>
        
        <script>
        // Détecter si on vient de se déconnecter
        var _justLoggedOut = window.location.search.indexOf('loggedout=1') !== -1;
        
        // Vider le champ mot de passe au chargement (contre l'auto-remplissage navigateur)
        var _profInput = document.getElementById('profInput');
        if (_profInput) { _profInput.value = ''; }
        // Certains navigateurs auto-remplissent après le DOMContentLoaded
        setTimeout(function() { if (_profInput && _justLoggedOut) _profInput.value = ''; }, 100);
        setTimeout(function() { if (_profInput && _justLoggedOut) _profInput.value = ''; }, 500);
        
        // Popup prof : ouvrir avec focus
        function openProfPopup() {
            document.getElementById('profPopup').classList.add('active');
            // Vider le champ et désactiver le flag loggedout dès que le prof ouvre manuellement la popup
            _justLoggedOut = false;
            var inp = document.getElementById('profInput');
            if (inp) { inp.value = ''; }
            setTimeout(function() { document.getElementById('profInput').focus(); }, 50);
        }
        
        // Fermer la popup en cliquant à l'extérieur
        document.getElementById('profPopup').addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('active');
        });
        
        // Prof : vérification automatique à chaque frappe (AJAX)
        // + fallback par Entrée (form POST classique)
        var profCheckTimeout = null;
        var profChecking = false;
        document.getElementById('profInput').addEventListener('input', function() {
            var pwd = this.value;
            if (pwd.length < 3 || profChecking || _justLoggedOut) return;
            
            clearTimeout(profCheckTimeout);
            profCheckTimeout = setTimeout(function() {
                profChecking = true;
                var fd = new FormData();
                fd.append('action', 'check_password');
                fd.append('pwd', pwd);
                
                fetch('index.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    profChecking = false;
                    if (data.success) {
                        window.location.href = 'index.php';
                    }
                })
                .catch(function() { profChecking = false; });
            }, 150);
        });
        
        // Si erreur de login, ouvrir automatiquement la popup prof
        <?php if ($loginError): ?>
        openProfPopup();
        <?php endif; ?>
        </script>
    </body>
    </html>
    <?php
    exit;
}

// === ACCÈS AUTORISÉ - SUITE DU CODE ===
$isAdmin = isset($_SESSION['elea_admin']) && $_SESSION['elea_admin'] === true;

// Nettoie les fichiers temporaires anciens (> 1h)
GoogleDriveLoader::cleanTempFiles(3600);

// Nettoie les cours expirés
cleanExpiredCourses();

// Nettoie les sessions éditeur expirées (local + Drive)
cleanExpiredEditorSessions();

// Filet de sécurité : nettoie les fichiers drive_downloads > 1h
cleanDriveDownloads();

// Keep-alive du token OAuth Drive (toutes les 6h)
driveTokenKeepAlive();

// Sessions éditeur actives (pour affichage admin)
$editorSessions = [];
if ($isAdmin && is_dir(EDITOR_SESSIONS_DIR)) {
    require_once ROOT_PATH . '/includes/EditorDriveSync.php';
    $editorSessions = EditorDriveSync::listActiveSessions();
}

// Récupère les cours disponibles
$localCourses = getLocalCourses();
$driveLoader = new GoogleDriveLoader();
$driveCoursesByFolder = $driveLoader->listCoursesByFolder();

// Liste des cours locaux
$localCourses = getAllCoursesIncludingDrive($localCourses);

// Récupère la liste des cours Google Drive déjà en cache (décompressés)
$cachedGdriveIds = [];
$driveCacheFile = TMP_PATH . '/gdrive_courses_cache.json';
if (file_exists($driveCacheFile)) {
    $driveCache = json_decode(file_get_contents($driveCacheFile), true) ?? [];
    foreach ($driveCache as $fileId => $cacheInfo) {
        $extractPath = $cacheInfo['extract_path'] ?? null;
        if ($extractPath && is_dir($extractPath)) {
            $cachedGdriveIds[$fileId] = true;
        }
    }
}

// Statut Drive pour chaque cours permanent
$driveIndexStatus = [];
if (!empty($driveCoursesByFolder)) {
    foreach ($driveCoursesByFolder as $courses) {
        foreach ($courses as $c) {
            $fid = $c['gdrive_id'];
            $indexPath = DRIVE_INDEX_DIR . '/' . $fid . '.json';
            if (file_exists($indexPath)) {
                // Le .mbz a-t-il été remplacé par une version plus récente (même fileId) ?
                // On compare la date de modif du listing Drive à celle stockée à la décompression.
                $idx = json_decode(@file_get_contents($indexPath), true) ?: [];
                $storedMod  = $idx['source_modified'] ?? '';
                $currentMod = $c['modified'] ?? '';
                if ($storedMod !== '' && $currentMod !== '' && $storedMod !== $currentMod) {
                    // Cache obsolète → purge (dossier Drive décompressé + index local).
                    // Le cours sera re-décompressé proprement à la prochaine ouverture.
                    purgePermanentDriveCache($fid);
                    $driveIndexStatus[$fid] = ['status' => 'pending'];
                } else {
                    $driveIndexStatus[$fid] = ['status' => 'ready'];
                }
            } else {
                $driveIndexStatus[$fid] = ['status' => 'pending'];
            }
        }
    }
}

// Nettoyage automatique des index Drive orphelins
// Construire les listes d'IDs valides depuis les données déjà chargées
$_validGdriveIds = [];
if (!empty($driveCoursesByFolder)) {
    foreach ($driveCoursesByFolder as $courses) {
        foreach ($courses as $c) {
            $_validGdriveIds[$c['gdrive_id']] = true;
        }
    }
}
$_validTempIds = [];
foreach ($localCourses as $lc) {
    $_validTempIds[$lc['prof_id']] = true;
}
// Vérificateur d'existence RÉELLE (garde-fou : ne purge que sur 404 confirmé,
// jamais sur une panne API — sinon un hoquet effacerait tout le cache Drive).
$_existenceCheck = function (string $fid) use ($driveLoader): string {
    return $driveLoader->checkFileExistence($fid);
};
cleanOrphanedDriveIndexes($_validGdriveIds, $_validTempIds, $_existenceCheck);
unset($_validGdriveIds, $_validTempIds, $_existenceCheck);

// Statut Drive pour chaque cours temporaire
$tempDriveStatus = [];
foreach ($localCourses as $lc) {
    $pid = $lc['prof_id'];
    $tempIndexExists = file_exists(DRIVE_INDEX_DIR . '/temp_' . $pid . '.json');
    $tempDriveStatus[$pid] = $tempIndexExists ? 'drive' : 'local';
}
$driveReadyCount = count(array_filter($driveIndexStatus, function($s) { return $s['status'] === 'ready'; }));

// Construire la liste d'activite uploads (queue + state files) — source unique de verite
$driveUploadActive = null;  // cours en train d'uploader (un seul a la fois)
$driveUploadQueue = [];      // cours en file d'attente
// Index : gdrive_id -> nom du cours dans $driveCoursesByFolder
$driveNameIndex = [];
if (!empty($driveCoursesByFolder)) {
    foreach ($driveCoursesByFolder as $courses) {
        foreach ($courses as $c) {
            $driveNameIndex[$c['gdrive_id']] = $c['name'];
        }
    }
    // Sauvegarder l'index pour que l'API queue_status puisse resoudre les noms
    @file_put_contents(TMP_PATH . '/.drive_names_index.json', json_encode($driveNameIndex), LOCK_EX);
}
// 1. Chercher un upload en cours via state files
$statePattern = TMP_PATH . '/.drive_prep_*.json';
foreach (glob($statePattern) ?: [] as $sf) {
    $stData = json_decode(file_get_contents($sf), true);
    if (!$stData || ($stData['status'] ?? '') !== 'uploading') continue;
    $sfid = preg_replace('/^\\.drive_prep_|\\.json$/', '', basename($sf));
    // Detecter le type
    $sfType = $stData['type'] ?? 'permanent';
    // Toujours stripper le prefixe temp_ du fid
    if (strpos($sfid, 'temp_') === 0) {
        $sfType = 'temp';
        $sfid = substr($sfid, 5);
    }
    // Supprimer les state files de preview stale
    if (strpos($sfid, 'preview-') === 0 || strpos($sfid, 'pdf-preview-') === 0) {
        @unlink($sf);
        continue;
    }
    $aName = $stData['course_name'] ?? '';
    if (empty($aName)) $aName = $driveNameIndex[$sfid] ?? '';
    if (empty($aName)) $aName = $sfid;
    $driveUploadActive = [
        'gdrive_id' => $sfid,
        'name' => $aName,
        'type' => $sfType,
        'uploaded' => $stData['uploaded_count'] ?? 0,
        'total' => $stData['total_files'] ?? 0,
    ];
    break; // un seul a la fois
}
// 2. Lire la file d'attente serveur + purger les preview-* stale
$queueFile = TMP_PATH . '/.drive_upload_queue.json';
if (file_exists($queueFile)) {
    $queueData = json_decode(file_get_contents($queueFile), true) ?: [];
    // Purger les preview-* de la queue
    $cleanQueue = array_filter($queueData, function($qi) {
        $fid = $qi['gdrive_id'] ?? '';
        return strpos($fid, 'preview-') !== 0 && strpos($fid, 'pdf-preview-') !== 0;
    });
    if (count($cleanQueue) !== count($queueData)) {
        file_put_contents($queueFile, json_encode(array_values($cleanQueue), JSON_PRETTY_PRINT), LOCK_EX);
        $queueData = $cleanQueue;
    }
    foreach ($queueData as $qi) {
        $qfid = $qi['gdrive_id'] ?? '';
        if (empty($qfid)) continue;
        $qType = $qi['type'] ?? 'permanent';
        // Deja pret sur Drive ? ignorer (checker le bon index selon le type)
        if ($qType === 'temp') {
            if (file_exists(DRIVE_INDEX_DIR . '/temp_' . $qfid . '.json')) continue;
        } else {
            if (file_exists(DRIVE_INDEX_DIR . '/' . $qfid . '.json')) continue;
        }
        // C'est le cours en cours d'upload ? ignorer
        if ($driveUploadActive && $driveUploadActive['gdrive_id'] === $qfid && ($driveUploadActive['type'] ?? '') === $qType) continue;
        // Resoudre le nom
        $qName = $qi['name'] ?? '';
        if (empty($qName)) {
            $qStateFile = ($qType === 'temp')
                ? TMP_PATH . '/.drive_prep_temp_' . $qfid . '.json'
                : TMP_PATH . '/.drive_prep_' . $qfid . '.json';
            if (file_exists($qStateFile)) {
                $qState = json_decode(file_get_contents($qStateFile), true);
                $qName = $qState['course_name'] ?? '';
            }
        }
        if (empty($qName)) $qName = $driveNameIndex[$qfid] ?? '';
        if (empty($qName)) $qName = $qfid;
        // Total fichiers
        $qTotal = 0;
        $qStateFile = ($qType === 'temp')
            ? TMP_PATH . '/.drive_prep_temp_' . $qfid . '.json'
            : TMP_PATH . '/.drive_prep_' . $qfid . '.json';
        if (file_exists($qStateFile)) {
            $qState = json_decode(file_get_contents($qStateFile), true);
            $qTotal = $qState['total_files'] ?? 0;
        }
        $driveUploadQueue[] = [
            'gdrive_id' => $qfid,
            'name' => $qName,
            'type' => $qType,
            'total' => $qTotal,
        ];
    }
}
$hasUploadActivity = ($driveUploadActive !== null || count($driveUploadQueue) > 0);

$usedStorage = getUsedStorage();
$usedStorageMB = round($usedStorage / (1024 * 1024), 1);
$availableStorageMB = MAX_STORAGE_MB - $usedStorageMB;

// Génère un code élève court pour accéder à un cours
function generateStudentCode($courseId, $hidden = '', $type = 'gdrive') {
    // Code de 6 caractères alphanumériques majuscules (facile à dicter)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sans I/O/0/1 pour éviter confusion
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    $codesFile = COURSES_PATH . '/student_codes.json';
    $codes = [];
    if (file_exists($codesFile)) {
        $codes = json_decode(file_get_contents($codesFile), true) ?: [];
    }
    
    // Nettoyer les codes expirés (> 2 mois)
    $codes = array_filter($codes, function($c) {
        return ($c['expires'] ?? 0) > time();
    });
    
    // Éviter les doublons de code
    while (isset($codes[$code])) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
    }
    
    // Stocker le code (expire dans 2 mois)
    $codeData = [
        'expires' => time() + (60 * 86400), // 2 mois
        'created' => time(),
        'hidden' => $hidden,
        'type' => $type
    ];
    
    if ($type === 'local') {
        $codeData['local_id'] = $courseId;
    } else {
        $codeData['gdrive_id'] = $courseId;
    }
    
    $codes[$code] = $codeData;
    file_put_contents($codesFile, json_encode($codes));
    return $code;
}

// API pour supprimer un cours temporaire (admin only)
if (isset($_POST['action']) && $_POST['action'] === 'delete_temp_course' && $isAdmin) {
    header('Content-Type: application/json');
    $courseId = $_POST['course_id'] ?? '';
    if (empty($courseId)) {
        echo json_encode(['error' => 'ID manquant']);
        exit;
    }
    $courseId = basename($courseId);
    $coursePath = COURSES_PATH . '/' . $courseId;
    $deleted = false;
    $driveResult = 'skipped';
    
    // 1. Supprimer le dossier local s'il existe
    if (is_dir($coursePath)) {
        $it = new RecursiveDirectoryIterator($coursePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($coursePath);
        $deleted = true;
    }
    
    // 2. Supprimer l'index Drive local
    $tempIndex = DRIVE_INDEX_DIR . '/temp_' . $courseId . '.json';
    $tempData = DRIVE_INDEX_DIR . '/temp_' . $courseId . '_data.json';
    if (file_exists($tempIndex)) { @unlink($tempIndex); $deleted = true; }
    if (file_exists($tempData)) { @unlink($tempData); }
    
    // 3. Supprimer le state file
    $stateFile = TMP_PATH . '/.drive_prep_temp_' . $courseId . '.json';
    if (file_exists($stateFile)) {
        @unlink($stateFile);
    }
    
    // 4. Retirer de la file d'upload
    $queueFile = TMP_PATH . '/.drive_upload_queue.json';
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true) ?? [];
        $newQueue = array_filter($queue, function($item) use ($courseId) {
            return $item['gdrive_id'] !== $courseId;
        });
        if (count($newQueue) !== count($queue)) {
            file_put_contents($queueFile, json_encode(array_values($newQueue), JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
    
    // 5. Supprimer le dossier Drive CoursTemporaires/{courseId}/
    try {
        require_once ROOT_PATH . '/DriveManager.php';
        $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
        $folderId = $dm->findSubfolder(DRIVE_COURSETEMP_FOLDER_ID, $courseId);
        if ($folderId) {
            $dm->delete($folderId);
            $driveResult = 'deleted:' . $folderId;
        } else {
            $driveResult = 'no_folder_found';
        }
    } catch (\Throwable $e) {
        $driveResult = 'error:' . $e->getMessage();
    }
    
    echo json_encode($deleted ? ['success' => true, 'drive' => $driveResult] : ['error' => 'Cours non trouvé']);
    exit;
}

// API pour supprimer un cours permanent du cache (admin only)
if (isset($_POST['action']) && $_POST['action'] === 'delete_cache_course') {
    header('Content-Type: application/json');
    $gdriveId = $_POST['gdrive_id'] ?? '';
    if (empty($gdriveId)) {
        echo json_encode(['error' => 'ID manquant']);
        exit;
    }
    
    $deleted = false;
    
    // 1. Supprimer le cache local (tmp/course_MD5/ et entree dans gdrive_courses_cache.json)
    $driveCacheFile = TMP_PATH . '/gdrive_courses_cache.json';
    if (file_exists($driveCacheFile)) {
        $driveCache = json_decode(file_get_contents($driveCacheFile), true) ?? [];
        if (isset($driveCache[$gdriveId])) {
            $extractPath = $driveCache[$gdriveId]['extract_path'] ?? null;
            if ($extractPath && is_dir($extractPath)) {
                rrmdir($extractPath);
            }
            unset($driveCache[$gdriveId]);
            file_put_contents($driveCacheFile, json_encode($driveCache, JSON_PRETTY_PRINT));
            $deleted = true;
        }
    }
    
    // 2. Supprimer le dossier local tmp/course_MD5 meme si pas dans le JSON cache
    $localPath = TMP_PATH . '/course_' . md5($gdriveId);
    if (is_dir($localPath)) {
        rrmdir($localPath);
        $deleted = true;
    }
    
    // 3. Supprimer l'index Drive local (cache/drive_index/{id}.json)
    $driveIndexFile = DRIVE_INDEX_DIR . '/' . $gdriveId . '.json';
    if (file_exists($driveIndexFile)) {
        @unlink($driveIndexFile);
        $deleted = true;
    }
    
    // 4. Supprimer le state file d'upload (tmp/.drive_prep_{id}.json)
    $stateFile = TMP_PATH . '/.drive_prep_' . $gdriveId . '.json';
    if (file_exists($stateFile)) {
        // Recuperer le extract_path du state file pour supprimer les fichiers extraits
        $stateData = json_decode(file_get_contents($stateFile), true);
        $stateExtractPath = $stateData['extract_path'] ?? '';
        if (!empty($stateExtractPath) && is_dir($stateExtractPath)) {
            rrmdir($stateExtractPath);
        }
        @unlink($stateFile);
        $deleted = true;
    }
    
    // 5. Retirer de la file d'attente d'upload
    $queueFile = TMP_PATH . '/.drive_upload_queue.json';
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true) ?? [];
        $newQueue = array_filter($queue, function($item) use ($gdriveId) {
            return ($item['gdrive_id'] ?? '') !== $gdriveId;
        });
        if (count($newQueue) !== count($queue)) {
            file_put_contents($queueFile, json_encode(array_values($newQueue)), LOCK_EX);
        }
    }
    
    // 6. Supprimer le sous-dossier sur Google Drive
    $driveResult = 'skipped';
    if ($isAdmin && defined('DRIVE_OAUTH_CLIENT_JSON') && defined('DRIVE_COURSEPERMANENTS_FOLDER_ID')) {
        try {
            require_once ROOT_PATH . '/DriveManager.php';
            $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
            $folderId = $dm->findSubfolder(DRIVE_COURSEPERMANENTS_FOLDER_ID, $gdriveId);
            if ($folderId) {
                $dm->delete($folderId);
                $driveResult = 'deleted:' . $folderId;
                $deleted = true;
            } else {
                $driveResult = 'no_folder_found';
            }
        } catch (\Throwable $e) {
            $driveResult = 'error:' . $e->getMessage();
        }
    }
    
    $resp = $deleted ? ['success' => true, 'drive' => $driveResult] : ['error' => 'Cours non trouve', 'drive' => $driveResult];
    echo json_encode($resp);
    exit;
}

// Fonction utilitaire : suppression récursive d'un dossier
function rrmdir($dir) {
    if (!is_dir($dir)) return 0;
    $count = 0;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            if (@unlink($file->getRealPath())) $count++;
        }
    }
    @rmdir($dir);
    return $count;
}

// API pour vider tout le cache des cours permanents (admin only)
if (isset($_POST['action']) && $_POST['action'] === 'clear_all_cache' && $isAdmin) {
    header('Content-Type: application/json');
    ignore_user_abort(true);
    set_time_limit(120);
    $deleted = 0;
    $tempDeleted = 0;
    
    // Nettoyer TOUT le dossier TMP_PATH (cours extraits, cache JSON, MBZ temp)
    if (is_dir(TMP_PATH)) {
        $items = scandir(TMP_PATH);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $itemPath = TMP_PATH . '/' . $item;
            if (is_dir($itemPath)) {
                rrmdir($itemPath);
                $deleted++;
            } elseif (is_file($itemPath)) {
                @unlink($itemPath);
                $deleted++;
            }
        }
    }
    
    // Nettoyer TOUT le dossier COURSES_PATH (cours uploadés)
    if (is_dir(COURSES_PATH)) {
        $keep = ['tokens.json', 'student_codes.json', '.gitkeep', '.htaccess'];
        $items = scandir(COURSES_PATH);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $keep)) continue;
            $itemPath = COURSES_PATH . '/' . $item;
            if (is_dir($itemPath)) {
                rrmdir($itemPath);
                $tempDeleted++;
            } elseif (is_file($itemPath)) {
                @unlink($itemPath);
                $tempDeleted++;
            }
        }
    }
    

    
    echo json_encode(['success' => true, 'deleted' => $deleted, 'temp_deleted' => $tempDeleted]);
    exit;
}

// Vider le cache de création de cours (brouillons éditeur)
if (isset($_POST['action']) && $_POST['action'] === 'clear_editor_cache' && $isAdmin) {
    header('Content-Type: application/json');
    $totalDeleted = 0;
    
    // Supprimer le dossier drafts entier (brouillons manuels + auto)
    $draftsDir = CACHE_DIR . '/drafts';
    $totalDeleted += rrmdir($draftsDir);
    
    // Supprimer les fichiers uploadés (images, vidéos) - dossier entier
    $uploadsDir = CACHE_DIR . '/editor_uploads';
    $totalDeleted += rrmdir($uploadsDir);
    
    // Supprimer les exports générés
    $exportsDir = CACHE_DIR . '/exports';
    $totalDeleted += rrmdir($exportsDir);
    
    // Supprimer les dossiers d'import temporaires
    if (is_dir(CACHE_DIR)) {
        foreach (scandir(CACHE_DIR) as $item) {
            if ((strpos($item, 'import_') === 0 || strpos($item, 'tpl_') === 0) && is_dir(CACHE_DIR . '/' . $item)) {
                $totalDeleted += rrmdir(CACHE_DIR . '/' . $item);
            }
        }
    }
    
    // Aussi nettoyer l'ancien chemin editor_drafts (s'il existe)
    $oldDraftsDir = CACHE_DIR . '/editor_drafts';
    $totalDeleted += rrmdir($oldDraftsDir);
    
    echo json_encode([
        'success' => true, 
        'total_deleted' => $totalDeleted
    ]);
    exit;
}

// Vider le cache des cours temporaires
if (isset($_POST['action']) && $_POST['action'] === 'clear_temp_courses_cache' && $isAdmin) {
    header('Content-Type: application/json');
    ignore_user_abort(true);
    set_time_limit(120);
    $deleted = 0;
    
    if (is_dir(COURSES_PATH)) {
        $keep = ['tokens.json', 'student_codes.json', '.gitkeep', '.htaccess'];
        foreach (scandir(COURSES_PATH) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $keep)) continue;
            $itemPath = COURSES_PATH . '/' . $item;
            if (is_dir($itemPath)) {
                rrmdir($itemPath);
                $deleted++;
            }
        }
    }
    
    // Nettoyer aussi tous les index Drive temporaires
    if (is_dir(DRIVE_INDEX_DIR)) {
        foreach (glob(DRIVE_INDEX_DIR . '/temp_*.json') as $f) {
            @unlink($f);
        }
    }

    echo json_encode(['success' => true, 'deleted' => $deleted]);
    exit;
}

// Vider le cache local des cours permanents (tmp/course_*)
if (isset($_POST['action']) && $_POST['action'] === 'clear_permanent_courses_cache' && $isAdmin) {
    header('Content-Type: application/json');
    ignore_user_abort(true);
    set_time_limit(120);
    $deleted = 0;
    
    if (is_dir(TMP_PATH)) {
        foreach (scandir(TMP_PATH) as $item) {
            if (strpos($item, 'course_') === 0 && is_dir(TMP_PATH . '/' . $item)) {
                rrmdir(TMP_PATH . '/' . $item);
                $deleted++;
            }
        }
        // Aussi supprimer le fichier de cache JSON
        $cacheFile = TMP_PATH . '/gdrive_courses_cache.json';
        if (file_exists($cacheFile)) @unlink($cacheFile);
    }
    

    
    // Supprimer le lock d'extraction s'il existe
    if (file_exists(EXTRACTION_LOCK_FILE)) @unlink(EXTRACTION_LOCK_FILE);
    
    echo json_encode(['success' => true, 'deleted' => $deleted]);
    exit;
}

// API pour vider le cache PDF (admin only)
if (isset($_POST['action']) && $_POST['action'] === 'clear_pdf_cache' && $isAdmin) {
    header('Content-Type: application/json');
    $deleted = 0;
    
    if (is_dir(COURSES_PATH)) {
        foreach (scandir(COURSES_PATH) as $item) {
            if (strpos($item, 'pdf-preview-') === 0 && is_dir(COURSES_PATH . '/' . $item)) {
                rrmdir(COURSES_PATH . '/' . $item);
                $deleted++;
            }
        }
    }
    
    echo json_encode(['success' => true, 'deleted' => $deleted]);
    exit;
}

// API pour nettoyer les index Drive locaux (apres vidage du dossier CoursPermanents sur Drive)
if (isset($_POST['action']) && $_POST['action'] === 'clear_drive_indexes' && $isAdmin) {
    header('Content-Type: application/json');
    $deleted = 0;
    if (is_dir(DRIVE_INDEX_DIR)) {
        foreach (glob(DRIVE_INDEX_DIR . '/*.json') as $f) {
            $basename = basename($f);
            if (strpos($basename, '_') === 0) continue;
            if (strpos($basename, 'temp_') === 0) continue; // Ne pas toucher les temp
            @unlink($f);
            $deleted++;
        }
    }
    echo json_encode(['success' => true, 'deleted' => $deleted]);
    exit;
}

// API pour nettoyer les index Drive temporaires (apres vidage du dossier CoursTemporaires sur Drive)
if (isset($_POST['action']) && $_POST['action'] === 'clear_temp_drive_indexes' && $isAdmin) {
    header('Content-Type: application/json');
    $deleted = 0;
    if (is_dir(DRIVE_INDEX_DIR)) {
        foreach (glob(DRIVE_INDEX_DIR . '/temp_*.json') as $f) {
            @unlink($f);
            $deleted++;
        }
    }
    echo json_encode(['success' => true, 'deleted' => $deleted]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - Accès de secours aux parcours Éléa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
    <?php include __DIR__ . '/includes/theme_assets.php'; ?>
    <style>
    .sections-grid {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 1.5rem;
        align-items: stretch;
    }
    
    /* Cartes upload et drive - hauteur fixe 250px */
    .section-card {
        display: flex;
        flex-direction: column;
    }
    .section-card .section-body {
        height: 250px;
        display: flex;
        flex-direction: column;
    }
    
    /* Zone upload prend tout l'espace disponible */
    .section-card .upload-zone {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    
    /* Carte des cours permanents */
    .section-card-drive {
        display: flex;
        flex-direction: column;
    }
    .section-card-drive .section-body {
        height: 250px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    
    /* Arborescence des dossiers */
    .drive-tree {
        flex: 1;
        overflow-y: auto;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        background: #fafafa;
    }
    .drive-tree-folder {
        border-bottom: 1px solid #eee;
    }
    .drive-tree-folder:last-child { border-bottom: none; }
    
    .folder-header {
        display: flex;
        align-items: center;
        padding: 0.75rem 1rem;
        cursor: pointer;
        font-weight: 500;
        color: #333;
        background: white;
        transition: background 0.2s;
        gap: 0.5rem;
    }
    .folder-header:hover { background: #f5f5f5; }
    .folder-icon { transition: transform 0.2s; }
    .drive-tree-folder.collapsed .folder-icon { transform: rotate(-90deg); }
    .drive-tree-folder.collapsed .folder-content { display: none; }
    
    .folder-content {
        background: #fafafa;
    }
    .course-tree-item {
        display: flex;
        align-items: center;
        padding: 0.6rem 1rem 0.6rem 2.5rem;
        cursor: pointer;
        color: #555;
        font-size: 0.9rem;
        transition: background 0.2s;
        gap: 0.5rem;
        border-top: 1px solid #eee;
    }
    .course-tree-item:hover { background: #e8f4ff; color: #1a73e8; }
    .course-tree-item .course-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }
    .course-tree-item .course-action {
        opacity: 0;
        font-size: 0.8rem;
        color: #1a73e8;
        padding: 0.25rem 0.5rem;
        border: 1px solid #1a73e8;
        border-radius: 4px;
        transition: opacity 0.2s;
    }
    .course-tree-item:hover .course-action { opacity: 1; }
    
    /* Cours en cache (décompressé) */
    .course-tree-item.is-cached { background: #f0fdf4; }
    .course-tree-item.is-cached:hover { background: #dcfce7; }
    .course-cached-badge {
        font-size: 0.75rem;
        color: #22c55e;
        margin-right: 0.3rem;
    }
    
    /* Bouton de suppression (admin) */
    .course-delete-btn {
        background: none;
        border: none;
        font-size: 0.9rem;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.2s, transform 0.2s;
        padding: 0.2rem 0.4rem;
        border-radius: 4px;
    }
    .course-delete-btn:hover {
        background: #fee2e2;
        transform: scale(1.1);
    }
    .course-tree-item:hover .course-delete-btn,
    .local-course-card:hover .course-delete-btn {
        opacity: 1;
    }
    
    /* Bouton Vider le cache (admin) */
    .clear-cache-btn {
        padding: 0.3rem 0.75rem;
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fca5a5;
        border-radius: 6px;
        font-size: 0.8rem;
        cursor: pointer;
        transition: background 0.2s;
    }
    .clear-cache-btn:hover {
        background: #fecaca;
    }
    
    /* Barre de stockage combinée */
    .storage-card {
        grid-column: 1 / -1;
        background: white;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .storage-header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    .storage-icon { font-size: 1.2rem; }
    .storage-title { font-weight: 600; color: #333; }
    
    .storage-bar-container {
        background: #e0e0e0;
        border-radius: 6px;
        height: 12px;
        overflow: hidden;
    }
    .storage-bar-combined {
        display: flex;
        height: 100%;
    }
    .storage-bar-local {
        background: linear-gradient(90deg, #5b21b6, #7c3aed);
        transition: width 0.3s;
    }
    .storage-bar-cache {
        background: linear-gradient(90deg, #1a73e8, #4dabf7);
        transition: width 0.3s;
    }
    
    .storage-legend {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        margin-top: 0.5rem;
        font-size: 0.85rem;
        color: #666;
        flex-wrap: wrap;
    }
    .legend-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 3px;
        margin-right: 0.4rem;
    }
    .legend-dot.local { background: #7c3aed; }
    .legend-dot.cache { background: #1a73e8; }
    .legend-dot.editor { background: #059669; }
    .storage-bar-editor {
        background: linear-gradient(90deg, #059669, #10b981);
        transition: width 0.3s;
    }
    .storage-card-editor {
        border-left: 3px solid #059669;
    }
    .storage-total {
        margin-left: auto;
        font-weight: 500;
        color: #333;
    }
    
    /* Cours temporaires sous les deux cartes */
    .section-card-local {
        grid-column: 1 / -1;
    }
    .section-card-local .section-body {
        height: 150px;
        overflow-y: auto;
    }
    .local-courses-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.4rem;
    }
    @media (max-width: 900px) {
        .local-courses-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 600px) {
        .local-courses-grid { grid-template-columns: repeat(2, 1fr); }
    }
    .local-course-card {
        display: flex;
        align-items: center;
        padding: 0.4rem 0.6rem;
        background: #f1f5f9;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s;
        gap: 0.4rem;
        min-width: 0;
    }
    .local-course-card:hover {
        background: #e0e7ff;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    }
    .local-course-icon { font-size: 0.9rem; flex-shrink: 0; }
    .temp-status-icon {
        width: 1.6rem; height: 1.6rem;
        display: flex; align-items: center; justify-content: center;
        border-radius: 4px;
        font-size: 0.75rem;
        background: #eef2ff; border: 1.5px solid #6366f1;
    }
    .temp-status-icon.is-drive {
        background: #fef3c7; border-color: #f59e0b;
    }
    .local-course-info { flex: 1; min-width: 0; }
    .local-course-name { font-weight: 500; color: #334155; font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .local-course-meta { font-size: 0.7rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .local-course-card .course-arrow { font-size: 0.75rem; color: #94a3b8; flex-shrink: 0; }
    
    /* Modal lien élève */
    .student-link-box {
        background: #f0f7ff;
        border: 1px solid #1a73e8;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
    }
    .student-link-box h4 { margin: 0 0 0.5rem; color: #1a73e8; }
    .student-link-input {
        display: flex;
        gap: 0.5rem;
    }
    .student-link-input input {
        flex: 1;
        padding: 0.5rem;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 0.9rem;
    }
    
    /* Boutons en haut à droite */
    .top-buttons {
        position: fixed;
        top: 1rem;
        right: calc(50% - 600px + 1rem);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        z-index: 100;
    }
    @media (max-width: 1200px) {
        .top-buttons { right: 1.5rem; }
    }
    @media (max-width: 768px) {
        .top-buttons { right: 1rem; gap: 0.3rem; }
    }
    
    .admin-badge {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
        padding: 0.4rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    }
    
    .create-course-btn {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    .create-course-btn:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
    }
    @media (max-width: 768px) {
        .create-course-btn { padding: 0.4rem 0.7rem; font-size: 0.75rem; }
    }
    
    .support-btn, .logout-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: none;
        background: white;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        font-size: 1.2rem;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    .support-btn:hover, .logout-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    @media (max-width: 768px) {
        .support-btn, .logout-btn { width: 36px; height: 36px; font-size: 1rem; }
        .admin-badge { font-size: 0.7rem; padding: 0.3rem 0.5rem; }
    }
    
    /* Modal Soutenir */
    .support-modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    .support-modal-overlay.active { display: flex; }
    
    .support-modal {
        background: white;
        border-radius: 16px;
        width: 90%;
        max-width: 400px;
        padding: 2rem;
        text-align: center;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    }
    .support-modal h3 {
        margin: 0 0 1rem;
        font-size: 1.3rem;
        color: #333;
    }
    .support-modal p {
        color: #555;
        margin: 0.5rem 0;
        line-height: 1.5;
    }
    .support-modal .support-link {
        display: inline-block;
        margin-top: 1rem;
        padding: 0.75rem 1.5rem;
        background: linear-gradient(135deg, #e91e63 0%, #f06292 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 500;
        transition: transform 0.2s;
    }
    .support-modal .support-link:hover {
        transform: translateY(-2px);
    }
    .support-modal .support-close {
        display: inline-block;
        margin-top: 1.5rem;
        padding: 0.6rem 2rem;
        background: #f0f0f0;
        border: none;
        border-radius: 8px;
        color: #555;
        cursor: pointer;
        font-size: 0.95rem;
        transition: background 0.2s;
    }
    .support-modal .support-close:hover { background: #e0e0e0; color: #333; }
    .support-modal .thanks { font-size: 1.5rem; margin-top: 0.5rem; }
    
    /* Dialog personnalisé (remplace confirm/alert) */
    .app-dialog-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.45);
        z-index: 10000;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.15s ease;
    }
    .app-dialog-overlay.active { display: flex; }
    .app-dialog {
        background: white;
        border-radius: 14px;
        width: 90%;
        max-width: 380px;
        padding: 1.75rem;
        text-align: center;
        box-shadow: 0 12px 40px rgba(0,0,0,0.2);
        animation: dialogPop 0.2s ease;
    }
    @keyframes dialogPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .app-dialog-icon { font-size: 2rem; margin-bottom: 0.75rem; }
    .app-dialog-title { font-size: 1.05rem; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; }
    .app-dialog-message { font-size: 0.9rem; color: #64748b; line-height: 1.5; margin-bottom: 1.25rem; }
    .app-dialog-buttons { display: flex; gap: 0.5rem; justify-content: center; }
    .app-dialog-btn {
        padding: 0.55rem 1.25rem;
        border: none;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
    }
    .app-dialog-btn.cancel { background: #f1f5f9; color: #475569; }
    .app-dialog-btn.cancel:hover { background: #e2e8f0; }
    .app-dialog-btn.danger { background: #ef4444; color: white; }
    .app-dialog-btn.danger:hover { background: #dc2626; }
    .app-dialog-btn.primary { background: #6366f1; color: white; }
    .app-dialog-btn.primary:hover { background: #4f46e5; }

    /* Toast notifications */
    .app-toast-container {
        position: fixed;
        top: 1.5rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10001;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        pointer-events: none;
    }
    .app-toast {
        background: #1e293b;
        color: white;
        padding: 0.7rem 1.25rem;
        border-radius: 10px;
        font-size: 0.9rem;
        box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        animation: toastIn 0.25s ease;
        pointer-events: auto;
    }
    .app-toast.success { background: #16a34a; }
    .app-toast.error { background: #ef4444; }
    .app-toast.fadeOut { animation: toastOut 0.3s ease forwards; }
    @keyframes toastIn { from { transform: translateY(-1rem); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes toastOut { from { opacity: 1; } to { opacity: 0; transform: translateY(-0.5rem); } }
    
    @media (max-width: 768px) {
        .sections-grid { grid-template-columns: 1fr; }
        .section-card .section-body { height: auto; min-height: 200px; }
        .section-card-drive .section-body { height: auto; max-height: 300px; }
        .section-card-local .section-body { height: auto; max-height: 200px; }
        .storage-legend { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
        .storage-total { margin-left: 0; }
    }
    
    /* Barre de progression pour le chargement */
    .loading-course-icon {
        font-size: 3rem;
        margin-bottom: 0.5rem;
        animation: bounce 1s ease infinite;
    }
    @keyframes bounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    
    .progress-bar-container {
        width: 100%;
        height: 12px;
        background: #e5e7eb;
        border-radius: 6px;
        overflow: hidden;
        margin-bottom: 0.5rem;
    }
    .progress-bar {
        height: 100%;
        width: 0%;
        background: linear-gradient(90deg, #3b82f6, #8b5cf6);
        border-radius: 6px;
        transition: width 0.3s ease;
        position: relative;
    }
    .progress-bar::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shimmer 1.5s infinite;
    }
    @keyframes shimmer {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
    
    .progress-percentage {
        font-size: 1.25rem;
        font-weight: 700;
        color: #3b82f6;
        margin-bottom: 1.5rem;
    }
    
    .loading-steps {
        display: flex;
        justify-content: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .loading-step {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.85rem;
        color: #9ca3af;
        padding: 0.4rem 0.8rem;
        background: #f3f4f6;
        border-radius: 20px;
        transition: all 0.3s;
    }
    .loading-step.active {
        color: #3b82f6;
        background: #dbeafe;
        font-weight: 500;
    }
    .loading-step.done {
        color: #22c55e;
        background: #dcfce7;
    }
    .loading-step.done .step-icon::before {
        content: '✓';
    }
    .loading-step .step-icon {
        font-size: 0.9rem;
    }
    /* Overlay chargement cours */
    .course-loading-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 10000;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(4px);
    }
    .course-loading-overlay.active { display: flex; }
    .course-loading-box {
        background: white;
        border-radius: 16px;
        padding: 2.5rem 2rem 2rem;
        text-align: center;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        min-width: 280px;
        max-width: 360px;
    }
    .course-loading-spinner {
        width: 44px;
        height: 44px;
        border: 4px solid #e2e8f0;
        border-top-color: #4f46e5;
        border-radius: 50%;
        animation: clspin 0.8s linear infinite;
        margin: 0 auto 1.2rem;
    }
    @keyframes clspin { to { transform: rotate(360deg); } }
    .course-loading-title {
        font-weight: 600;
        font-size: 1rem;
        color: #1e293b;
        margin-bottom: 0.3rem;
    }
    .course-loading-name {
        color: #64748b;
        font-size: 0.85rem;
        margin-bottom: 1.5rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .course-loading-cancel {
        background: none;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.5rem 1.5rem;
        font-size: 0.85rem;
        color: #64748b;
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
    }
    .course-loading-cancel:hover {
        background: #f1f5f9;
        color: #334155;
    }
    </style>
</head>
<body>
    <div class="home-container">
        <!-- Boutons en haut à droite -->
        <div class="top-buttons">
            <a href="editor.php" class="create-course-btn" title="Créer un nouveau cours">✏️ Créer un cours</a>
            <a href="?logout=1" class="logout-btn" title="Se déconnecter">🚪</a>
            <button class="support-btn" onclick="openSupportModal()" title="Soutenir ce projet">❤️</button>
        </div>
        
        <!-- Hero Section -->
        <header class="hero hero-compact">
            <div class="hero-content">
                <div class="hero-center">
                    <div class="hero-title-row">
                        <span class="hero-logo">🆘</span>
                        <h1><?= SITE_NAME ?></h1>
                        <?php if ($isAdmin): ?>
                        <span class="admin-badge" title="Mode administrateur">🔧 Admin</span>
                        <?php endif; ?>
                    </div>
                    <p class="hero-subtitle">Accédez à vos parcours Éléa même quand la plateforme est en panne</p>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="sections-grid">
                
                <!-- Section Upload -->
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-icon upload">📤</div>
                        <div>
                            <h2>Déposer un cours</h2>
                            <p>Upload temporaire (24h)</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="upload-zone" id="uploadZone">
                            <div class="upload-zone-icon">📁</div>
                            <p><strong>Glissez votre fichier .mbz</strong></p>
                            <p>ou cliquez pour sélectionner</p>
                            <small>Max. <?= round(MAX_UPLOAD_SIZE / (1024*1024)) ?> Mo • Supprimé après <?= COURSE_LIFETIME_HOURS ?>h</small>
                        </div>
                        <input type="file" id="fileInput" accept=".mbz" style="display: none;">
                    </div>
                </div>
                
                <!-- Section Cours Google Drive -->
                <?php if (!empty($driveCoursesByFolder)): ?>
                <div class="section-card section-card-drive">
                    <div class="section-header">
                        <div class="section-icon drive">☁️</div>
                        <div>
                            <h2>Cours permanents technologie</h2>
                            <p><?= array_sum(array_map('count', $driveCoursesByFolder)) ?> cours disponibles<?php
                                $totalAvailable = count($cachedGdriveIds) + $driveReadyCount;
                                // Eviter double-comptage si un cours est en cache serveur ET sur Drive
                                $uniqueAvailable = $cachedGdriveIds;
                                foreach ($driveIndexStatus as $fid => $st) {
                                    if ($st['status'] === 'ready') $uniqueAvailable[$fid] = true;
                                }
                                $totalAvailable = count($uniqueAvailable);
                                if ($totalAvailable > 0): ?> · <span style="color:#22c55e;"><?= $totalAvailable ?> disponibles</span><?php endif; ?></p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="drive-tree">
                            <?php foreach ($driveCoursesByFolder as $folderName => $courses): ?>
                            <?php 
                                // Compte les cours disponibles dans ce dossier
                                $cachedInFolder = 0;
                                foreach ($courses as $c) {
                                    $fid = $c['gdrive_id'];
                                    if (isset($cachedGdriveIds[$fid]) || (isset($driveIndexStatus[$fid]) && $driveIndexStatus[$fid]['status'] === 'ready')) $cachedInFolder++;
                                }
                            ?>
                            <div class="drive-tree-folder collapsed">
                                <div class="folder-header" onclick="toggleFolder(this)">
                                    <span class="folder-icon">▼</span>
                                    <span>📁 <?= htmlspecialchars($folderName) ?></span>
                                    <span style="color:#888;font-size:0.8rem;margin-left:auto;">
                                        <?= count($courses) ?>
                                        <?php if ($cachedInFolder > 0): ?>
                                        <span style="color:#22c55e;margin-left:0.3rem;" title="<?= $cachedInFolder ?> cours en cache">● <?= $cachedInFolder ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="folder-content">
                                    <?php foreach ($courses as $course): ?>
                                    <?php
                                    $fid = $course['gdrive_id'];
                                    $isCached = isset($cachedGdriveIds[$fid]);
                                    $isOnDrive = isset($driveIndexStatus[$fid]) && $driveIndexStatus[$fid]['status'] === 'ready';
                                    $isAvailable = $isCached || $isOnDrive;
                                    ?>
                                    <div class="course-tree-item <?= $isAvailable ? 'is-cached' : '' ?>" data-gdrive-id="<?= htmlspecialchars($fid) ?>">
                                        <span data-role="icon" onclick="openCourse('<?= htmlspecialchars($fid) ?>', '<?= htmlspecialchars(addslashes($course['name'])) ?>', <?= $isAvailable ? 'true' : 'false' ?>)" style="cursor:pointer;"><?= $isOnDrive ? '☁️' : ($isCached ? '💾' : '📚') ?></span>
                                        <span class="course-name" title="<?= htmlspecialchars($course['name']) ?>" onclick="openCourse('<?= htmlspecialchars($fid) ?>', '<?= htmlspecialchars(addslashes($course['name'])) ?>', <?= $isAvailable ? 'true' : 'false' ?>)" style="cursor:pointer;"><?= htmlspecialchars($course['name']) ?></span>
                                        <?php if ($isAdmin && $isAvailable): ?>
                                        <button class="course-delete-btn" onclick="event.stopPropagation(); deleteCacheGdrive('<?= htmlspecialchars($fid) ?>', '<?= htmlspecialchars(addslashes($course['name'])) ?>')" title="Supprimer du cache">🗑️</button>
                                        <?php endif; ?>
                                        <span class="course-action" onclick="editCourseFromHome('<?= htmlspecialchars($fid) ?>', '<?= htmlspecialchars(addslashes($course['name'])) ?>')">Éditer</span>
                                        <span class="course-action" onclick="openCourse('<?= htmlspecialchars($fid) ?>', '<?= htmlspecialchars(addslashes($course['name'])) ?>', <?= $isAvailable ? 'true' : 'false' ?>)">Ouvrir</span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-icon drive">☁️</div>
                        <div>
                            <h2>Cours permanents technologie</h2>
                            <p>Google Drive</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="empty-state">
                            <div class="empty-state-icon">☁️</div>
                            <p>Aucun cours Google Drive configuré</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($isAdmin): // Admin: pas de section-card cours locaux (la liste est dans les jauges)
                    // Calcul des tailles (utilisé dans la liste admin)
                    $totalTempSize = 0;
                    $tempSizes = [];
                    foreach ($localCourses as $course) {
                        $pid = $course['prof_id'];
                        $localPath = COURSES_PATH . '/' . $pid;
                        // Chercher la taille : local → index Drive → state file d'upload
                        $indexFile = DRIVE_INDEX_DIR . '/temp_' . $pid . '.json';
                        $stateFile = TMP_PATH . '/.drive_prep_temp_' . $pid . '.json';
                        $sz = getCourseTotalSize($localPath, $indexFile);
                        if ($sz === 0) $sz = getCourseTotalSize($localPath, $stateFile);
                        $tempSizes[$pid] = $sz;
                        $totalTempSize += $sz;
                    }
                ?>
                <?php else: // Prof: afficher la section-card cours locaux ?>
                
                <!-- Section Cours Locaux -->
                <div class="section-card section-card-local">
                    <div class="section-header">
                        <div class="section-icon local">💾</div>
                        <div>
                            <h2>Cours temporaires</h2>
                            <p><?= count($localCourses) ?> cours uploadé(s)</p>
                        </div>
                    </div>
                    <div class="section-body">
                        <?php if (count($localCourses) > 0): ?>
                        <div class="local-courses-grid">
                            <?php foreach ($localCourses as $course): 
                                $pid = $course['prof_id'];
                                $tStatus = $tempDriveStatus[$pid] ?? 'local';
                                $tIcon = ($tStatus === 'drive') ? '☁️' : '💾';
                            ?>
                            <div class="local-course-card" data-temp-id="<?= htmlspecialchars($pid) ?>">
                                <div class="local-course-icon temp-status-icon <?= $tStatus === 'drive' ? 'is-drive' : '' ?>" onclick="navigateToCourse('view.php?id=<?= urlencode($pid) ?>', '<?= htmlspecialchars(addslashes($course['course_name'] ?? $pid)) ?>')" style="cursor:pointer;"><?= $tIcon ?></div>
                                <div class="local-course-info" onclick="navigateToCourse('view.php?id=<?= urlencode($pid) ?>', '<?= htmlspecialchars(addslashes($course['course_name'] ?? $pid)) ?>')" style="cursor:pointer;">
                                    <div class="local-course-name"><?= htmlspecialchars($course['course_name'] ?? $pid) ?></div>
                                    <div class="local-course-meta">
                                        <?php if (!empty($course['is_drive_only'])): ?>
                                        <span style="color:#f59e0b;">☁️ Disponible via Drive</span>
                                        <?php elseif (isset($course['created_at'])): ?>
                                        Expire <?= date('d/m', $course['created_at'] + COURSE_LIFETIME_HOURS * 3600) ?> à <?= date('H:i', $course['created_at'] + COURSE_LIFETIME_HOURS * 3600) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="course-action" onclick="navigateToCourse('view.php?id=<?= urlencode($pid) ?>', '<?= htmlspecialchars(addslashes($course['course_name'] ?? $pid)) ?>')">Ouvrir</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <p>Aucun cours uploadé</p>
                            <small>Les cours uploadés apparaîtront ici</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Espace disque cache serveur -->
                <?php
                if (!function_exists('dirSize')) {
                    function dirSize($dir) {
                        $size = 0;
                        if (!is_dir($dir)) return 0;
                        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
                        foreach ($it as $file) {
                            if ($file->isFile()) $size += $file->getSize();
                        }
                        return $size;
                    }
                }
                
                // 1. Cours temporaires (courses/) - exclure pdf-preview-*
                $cacheTempSize = 0;
                $cachePdfSize = 0;
                $cacheTempCount = 0;
                $cachePdfCount = 0;
                if (is_dir(COURSES_PATH)) {
                    foreach (scandir(COURSES_PATH) as $item) {
                        if ($item === '.' || $item === '..') continue;
                        $itemPath = COURSES_PATH . '/' . $item;
                        if (is_dir($itemPath)) {
                            if (strpos($item, 'pdf-preview-') === 0) {
                                $cachePdfSize += dirSize($itemPath);
                                $cachePdfCount++;
                            } else {
                                $cacheTempSize += dirSize($itemPath);
                                $cacheTempCount++;
                            }
                        }
                    }
                }
                
                // 2. Cache cours permanents (tmp/course_*)
                $cachePermanentSize = 0;
                $cachePermanentCount = 0;
                if (is_dir(TMP_PATH)) {
                    foreach (scandir(TMP_PATH) as $item) {
                        if (strpos($item, 'course_') === 0 && is_dir(TMP_PATH . '/' . $item)) {
                            $cachePermanentSize += dirSize(TMP_PATH . '/' . $item);
                            $cachePermanentCount++;
                        }
                    }
                }
                
                // 3. Création de cours — compter depuis les sessions éditeur (source de vérité)
                $cacheCreationSize = 0;
                $cacheCreationCount = 0;
                
                // Taille : scanner tous les dossiers liés à l'éditeur
                $editorDirs = [
                    CACHE_DIR . '/drafts',
                    CACHE_DIR . '/editor_uploads',
                    CACHE_DIR . '/exports',
                    CACHE_DIR . '/editor_drafts',
                ];
                foreach ($editorDirs as $eDir) {
                    if (is_dir($eDir)) {
                        $cacheCreationSize += dirSize($eDir);
                    }
                }
                if (is_dir(CACHE_DIR)) {
                    foreach (scandir(CACHE_DIR) as $item) {
                        if ((strpos($item, 'import_') === 0 || strpos($item, 'tpl_') === 0) && is_dir(CACHE_DIR . '/' . $item)) {
                            $cacheCreationSize += dirSize(CACHE_DIR . '/' . $item);
                        }
                    }
                }
                
                // Comptage : nombre de sessions éditeur actives (pas les sous-dossiers)
                if (is_dir(EDITOR_SESSIONS_DIR)) {
                    $cacheCreationCount = count(glob(EDITOR_SESSIONS_DIR . '/*.json') ?: []);
                }
                
                $serverMaxMB = SERVER_MAX_MB;
                $cacheTempMB = round($cacheTempSize / (1024 * 1024), 1);
                $cachePermanentMB = round($cachePermanentSize / (1024 * 1024), 1);
                $cacheCreationMB = round($cacheCreationSize / (1024 * 1024), 1);
                $cachePdfMB = round($cachePdfSize / (1024 * 1024), 1);
                $serverTotalMB = $cacheTempMB + $cachePermanentMB + $cacheCreationMB + $cachePdfMB;
                $serverFull = ($serverTotalMB >= $serverMaxMB);
                $serverWarn = (!$serverFull && $serverTotalMB >= SERVER_WARN_MB);
                
                $pctTemp = min(100, ($cacheTempMB / $serverMaxMB) * 100);
                $pctPermanent = min(100 - $pctTemp, ($cachePermanentMB / $serverMaxMB) * 100);
                $pctCreation = min(100 - $pctTemp - $pctPermanent, ($cacheCreationMB / $serverMaxMB) * 100);
                $pctPdf = min(100 - $pctTemp - $pctPermanent - $pctCreation, ($cachePdfMB / $serverMaxMB) * 100);
                ?>
                
                <?php if ($isAdmin): ?>
                <!-- ADMIN : Section Cache data unifiée -->
                <div class="storage-card">
                    <div class="storage-header">
                        <span class="storage-icon">🗄️</span>
                        <span class="storage-title">Cache data</span>
                    </div>
                    
                    <!-- Cache serveur -->
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;align-items:center;">
                        <span style="font-weight:600;font-size:0.85rem;color:var(--text-primary);white-space:nowrap;">💾 Cache serveur</span>
                        <div id="server-cache-buttons" style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-left:auto;">
                            <?php if ($cacheTempMB > 0): ?>
                            <button class="clear-cache-btn" onclick="clearTempCoursesCache()" title="Vider le cache des cours temporaires">🗑️ <?= $cacheTempCount ?> Temporaire<?= $cacheTempCount > 1 ? 's' : '' ?> (<?= $cacheTempMB ?> Mo)</button>
                            <?php endif; ?>
                            <?php if ($cachePermanentMB > 0): ?>
                            <button class="clear-cache-btn" onclick="clearPermanentCoursesCache()" title="Vider le cache des cours permanents">🗑️ <?= $cachePermanentCount ?> Permanent<?= $cachePermanentCount > 1 ? 's' : '' ?> (<?= $cachePermanentMB ?> Mo)</button>
                            <?php endif; ?>
                            <?php if ($cacheCreationMB > 0): ?>
                            <button class="clear-cache-btn" onclick="clearEditorCache()" title="Vider le cache de création">🗑️ Création (<?= $cacheCreationMB ?> Mo)</button>
                            <?php endif; ?>
                            <?php if ($cachePdfMB > 0): ?>
                            <button class="clear-cache-btn" onclick="clearPdfCache()" title="Vider le cache PDF">🗑️ <?= $cachePdfCount ?> PDF (<?= $cachePdfMB ?> Mo)</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="storage-bar-container">
                        <div class="storage-bar-combined" id="server-cache-bar">
                            <div style="width:<?= $pctTemp ?>%;height:100%;background:#6366f1;float:left;border-radius:8px 0 0 8px;"></div>
                            <div style="width:<?= $pctPermanent ?>%;height:100%;background:#22c55e;float:left;"></div>
                            <div style="width:<?= $pctCreation ?>%;height:100%;background:#f59e0b;float:left;"></div>
                            <div style="width:<?= $pctPdf ?>%;height:100%;background:#ec4899;float:left;border-radius:0 8px 8px 0;"></div>
                        </div>
                    </div>
                    <div class="storage-legend" id="server-cache-legend">
                        <span><span class="legend-dot" style="background:#6366f1;"></span> Temporaires : <?= $cacheTempCount ?> (<?= $cacheTempMB ?> Mo)</span>
                        <span><span class="legend-dot" style="background:#22c55e;"></span> Permanents : <?= $cachePermanentCount ?> (<?= $cachePermanentMB ?> Mo)</span>
                        <span><span class="legend-dot" style="background:#f59e0b;"></span> Création : <?= $cacheCreationCount ?? 0 ?> (<?= $cacheCreationMB ?> Mo)</span>
                        <?php if ($cachePdfMB > 0): ?>
                        <span><span class="legend-dot" style="background:#ec4899;"></span> PDF : <?= $cachePdfCount ?> (<?= $cachePdfMB ?> Mo)</span>
                        <?php endif; ?>
                        <span class="storage-total"><?= $serverTotalMB ?> Mo / <?= $serverMaxMB ?> Mo</span>
                        <?php if ($serverFull): ?>
                        <span style="color:#ef4444;">⚠️ Espace plein</span>
                        <?php elseif ($serverWarn): ?>
                        <span style="color:#f59e0b;" title="Seuil d'alerte à <?= SERVER_WARN_MB ?> Mo (80%). Videz bientôt le cache pour éviter le blocage à <?= $serverMaxMB ?> Mo.">⚠️ Alerte 80% — videz bientôt</span>
                        <?php endif; ?>
                    </div>

                    <!-- Jauge Google Drive -->
                    <div style="margin-top:1rem;">
                        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;margin-bottom:0.5rem;">
                            <span style="font-weight:600;font-size:0.85rem;color:var(--text-primary);white-space:nowrap;">☁️ Google Drive</span>
                            <button onclick="loadDriveUsage(true)" style="background:none;border:1px solid #cbd5e1;color:#64748b;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:0.75rem;" title="Rafraîchir (recalcul complet)">⟳</button>
                            <div id="drive-buttons" style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-left:auto;"></div>
                        </div>
                        <div class="storage-bar-container">
                            <div class="storage-bar-combined" id="drive-bar" style="position:relative;">
                                <div id="drive-bar-loading" style="width:100%;height:100%;background:repeating-linear-gradient(90deg,var(--gray-200) 0%,var(--gray-100) 50%,var(--gray-200) 100%);background-size:200% 100%;animation:driveShimmer 1.5s ease infinite;border-radius:8px;"></div>
                            </div>
                        </div>
                        <div class="storage-legend" id="drive-legend" style="min-height:1.2rem;">
                            <span style="color:#94a3b8;font-size:0.75rem;">Chargement...</span>
                        </div>
                    </div>
                    <style>@keyframes driveShimmer { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }</style>
                    
                    <!-- Séparateur + Uploads Drive en cours (masqué si rien) -->
                    <div id="driveAdminSeparator" style="border-top:1px solid #e2e8f0;margin:1rem 0;<?= $hasUploadActivity ? '' : 'display:none;' ?>"></div>
                    <div id="driveAdminSection" style="<?= $hasUploadActivity ? '' : 'display:none;' ?>">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                            <span style="font-weight:600;font-size:0.85rem;color:var(--text-primary);">🚀 Uploads Drive</span>
                        </div>

                        <!-- Cours en cours d'upload (barre de progression live) -->
                        <?php if ($driveUploadActive): ?>
                        <?php
                            $uploadingPct = $driveUploadActive['total'] > 0 ? round($driveUploadActive['uploaded'] / $driveUploadActive['total'] * 100) : 0;
                        ?>
                        <div id="driveAdminCurrent" style="margin-bottom:6px;">
                            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:3px;">
                                <span id="driveAdminCurrentDot" class="legend-dot" style="background:<?= ($driveUploadActive['type'] ?? 'permanent') === 'temp' ? '#6366f1' : '#22c55e' ?>;flex-shrink:0;"></span>
                                <span id="driveAdminCurrentName" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.8rem;"><?= htmlspecialchars($driveUploadActive['name']) ?></span>
                                <span id="driveAdminCurrentCount" style="color:#3b82f6;font-size:0.75rem;white-space:nowrap;"><?= $driveUploadActive['uploaded'] ?>/<?= $driveUploadActive['total'] ?></span>
                            </div>
                            <div style="background:var(--gray-200);border-radius:6px;height:6px;overflow:hidden;margin-bottom:3px;">
                                <div id="driveAdminBar" style="height:100%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:6px;transition:width 0.3s;width:<?= $uploadingPct ?>%;"></div>
                            </div>
                            <div id="driveAdminStatus" style="font-size:0.75rem;color:#94a3b8;">
                                <?= $driveUploadActive['total'] > 0 ? $driveUploadActive['uploaded'] . '/' . $driveUploadActive['total'] . ' fichiers (' . $uploadingPct . '%)' : 'En attente...' ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div id="driveAdminCurrent" style="display:none;margin-bottom:6px;">
                            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:3px;">
                                <span id="driveAdminCurrentDot" class="legend-dot" style="background:#22c55e;flex-shrink:0;"></span>
                                <span id="driveAdminCurrentName" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.8rem;"></span>
                                <span id="driveAdminCurrentCount" style="color:#3b82f6;font-size:0.75rem;white-space:nowrap;"></span>
                            </div>
                            <div style="background:var(--gray-200);border-radius:6px;height:6px;overflow:hidden;margin-bottom:3px;">
                                <div id="driveAdminBar" style="height:100%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:6px;transition:width 0.3s;width:0%;"></div>
                            </div>
                            <div id="driveAdminStatus" style="font-size:0.75rem;color:#94a3b8;">En attente...</div>
                        </div>
                        <?php endif; ?>

                        <!-- File d'attente -->
                        <div id="driveAdminQueue" style="font-size:0.8rem;">
                            <?php foreach ($driveUploadQueue as $qi): 
                                $qiDotColor = ($qi['type'] ?? 'permanent') === 'temp' ? '#6366f1' : '#22c55e';
                            ?>
                            <div style="display:flex;align-items:center;gap:0.5rem;padding:0.2rem 0;color:#64748b;">
                                <span class="legend-dot" style="background:<?= $qiDotColor ?>;flex-shrink:0;"></span>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($qi['name']) ?></span>
                                <?php if ($qi['total'] > 0): ?>
                                <span style="color:#94a3b8;white-space:nowrap;font-size:0.75rem;"><?= $qi['total'] ?> fichiers</span>
                                <?php endif; ?>
                                <span style="color:#8b5cf6;white-space:nowrap;font-size:0.75rem;">en file</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php 
                    $hasEditorActivity = false;
                    if (!empty($editorSessions)) {
                        foreach ($editorSessions as $es) {
                            if (($es['pending_count'] ?? 0) > 0 || ($es['local_count'] ?? 0) > 0) {
                                $hasEditorActivity = true;
                                break;
                            }
                        }
                    }
                    $hasEditorSessions = !empty($editorSessions);
                    ?>
                    <div id="editorSessionsSeparator" style="border-top:1px solid #e2e8f0;margin:1rem 0;<?= $hasEditorSessions ? '' : 'display:none;' ?>"></div>
                    <div id="editorSessionsSection" style="<?= $hasEditorSessions ? '' : 'display:none;' ?>">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                            <span id="editorSessionsToggle" onclick="toggleEditorSessions()" style="cursor:pointer;font-size:0.7rem;color:#94a3b8;user-select:none;">▼</span>
                            <span id="editorSessionsTitle" onclick="toggleEditorSessions()" style="font-weight:600;font-size:0.85rem;color:var(--text-primary);cursor:pointer;">📝 Cours en création (<?= count($editorSessions) ?>)</span>
                            <button onclick="cleanAllEditorSessions()" style="margin-left:auto;background:none;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;font-size:0.7rem;color:#94a3b8;cursor:pointer;" title="Supprimer toutes les sessions">🗑️ Tout</button>
                        </div>
                        <div id="editorSessionsList">
                        <?php if ($hasEditorSessions): foreach ($editorSessions as $es): 
                            $esName = $es['course_name'] ?: ('Session ' . substr($es['session_id'], 0, 12));
                            $esUploading = ($es['pending_count'] ?? 0) > 0;
                            $esLastActivity = $es['last_activity'] ?: $es['created_at'];
                            $esExpireTs = $esLastActivity + 24 * 3600;
                            $esExpire = date('d/m H:i', $esExpireTs);
                            $esSize = $es['total_size'] ?? 0;
                            if ($esSize > 1048576) $esSizeStr = round($esSize / 1048576, 1) . ' Mo';
                            elseif ($esSize > 1024) $esSizeStr = round($esSize / 1024) . ' Ko';
                            else $esSizeStr = $esSize . ' o';
                        ?>
                        <div class="editor-session-row" data-session-id="<?= htmlspecialchars($es['session_id']) ?>" style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0;font-size:0.8rem;color:#64748b;">
                            <span class="legend-dot es-dot" style="background:<?= $es['has_drive'] ? '#f59e0b' : '#94a3b8' ?>;flex-shrink:0;"></span>
                            <span class="es-name" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;text-decoration:underline dotted;color:var(--accent-text);" title="Ouvrir dans le visualiseur" onclick="previewEditorSession('<?= htmlspecialchars($es['session_id']) ?>')"><?= htmlspecialchars($esName) ?><?php if ($esUploading): ?><span class="es-upload-status" style="color:#3b82f6;font-style:italic;text-decoration:none;"> - upload...</span><?php endif; ?></span>
                            <span class="es-size" style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"><?= $esSizeStr ?></span>
                            <span class="es-counters" style="display:flex;gap:0.3rem;align-items:center;"><?php
                                if ($es['drive_count'] > 0) echo '<span style="color:#f59e0b;font-size:0.7rem;white-space:nowrap;">☁️' . $es['drive_count'] . '</span>';
                                if ($es['local_count'] > 0) echo '<span style="color:#6366f1;font-size:0.7rem;white-space:nowrap;">💾' . $es['local_count'] . '</span>';
                                if ($es['pending_count'] > 0) echo '<span style="color:#3b82f6;font-size:0.7rem;white-space:nowrap;">⏳' . $es['pending_count'] . '</span>';
                            ?></span>
                            <span class="es-age" style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;">exp. <?= $esExpire ?></span>
                            <button onclick="editEditorSession('<?= htmlspecialchars($es['session_id']) ?>', '<?= htmlspecialchars(addslashes($esName)) ?>')" style="background:none;border:1px solid #c7d2fe;border-radius:4px;padding:1px 6px;font-size:0.65rem;color:var(--accent-text);cursor:pointer;white-space:nowrap;" title="Éditer ce cours">✏️</button>
                            <button onclick="deleteEditorSession('<?= htmlspecialchars($es['session_id']) ?>')" style="background:none;border:none;cursor:pointer;font-size:0.75rem;padding:0 2px;color:#94a3b8;" title="Supprimer cette session">🗑️</button>
                        </div>
                        <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <?php // ===== LISTE COURS TEMPORAIRES (même style que cours en création) =====
                    $hasLocalCourses = !empty($localCourses);
                    ?>
                    <div style="border-top:1px solid #e2e8f0;margin:1rem 0;<?= $hasLocalCourses ? '' : 'display:none;' ?>"></div>
                    <div id="tempCoursesListSection" style="<?= $hasLocalCourses ? '' : 'display:none;' ?>">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                            <span id="tempCoursesToggle" onclick="document.getElementById('tempCoursesList').style.display = document.getElementById('tempCoursesList').style.display === 'none' ? '' : 'none'; this.textContent = this.textContent === '▼' ? '▶' : '▼';" style="cursor:pointer;font-size:0.7rem;color:#94a3b8;user-select:none;">▼</span>
                            <span onclick="document.getElementById('tempCoursesToggle').click()" style="font-weight:600;font-size:0.85rem;color:var(--text-primary);cursor:pointer;">💾 Cours temporaires (<?= count($localCourses) ?>)<?php
                                if ($totalTempSize > 0) echo ' · <span style="color:#6366f1;font-weight:normal;">' . ($totalTempSize > 1048576 ? round($totalTempSize / 1048576, 1) . ' Mo' : round($totalTempSize / 1024) . ' Ko') . '</span>';
                            ?></span>
                            <button onclick="clearTempCoursesCache()" style="margin-left:auto;background:none;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;font-size:0.7rem;color:#94a3b8;cursor:pointer;" title="Supprimer tous les cours temporaires">🗑️ Tout</button>
                        </div>
                        <div id="tempCoursesList">
                        <?php foreach ($localCourses as $course):
                            $pid = $course['prof_id'];
                            $tStatus = $tempDriveStatus[$pid] ?? 'local';
                            $tSize = $tempSizes[$pid] ?? 0;
                            if ($tSize > 1048576) $tSizeStr = round($tSize / 1048576, 1) . ' Mo';
                            elseif ($tSize > 1024) $tSizeStr = round($tSize / 1024) . ' Ko';
                            else $tSizeStr = '';
                            $tExpire = isset($course['created_at']) ? date('d/m H:i', $course['created_at'] + COURSE_LIFETIME_HOURS * 3600) : '';
                        ?>
                        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0;font-size:0.8rem;color:#64748b;">
                            <span class="legend-dot" style="background:#6366f1;flex-shrink:0;"></span>
                            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;text-decoration:underline dotted;color:var(--accent-text);" onclick="navigateToCourse('view.php?id=<?= urlencode($pid) ?>', '<?= htmlspecialchars(addslashes($course['course_name'] ?? $pid)) ?>')" title="Ouvrir dans le visualiseur"><?= htmlspecialchars($course['course_name'] ?? $pid) ?></span>
                            <?php if ($tSizeStr): ?><span style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"><?= $tSizeStr ?></span><?php endif; ?>
                            <?php if ($tExpire): ?><span style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;">exp. <?= $tExpire ?></span><?php endif; ?>
                            <span style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"><?= $tStatus === 'drive' ? '☁️' : '💾' ?></span>
                            <button onclick="event.stopPropagation(); editCourseFromHome(null, '<?= htmlspecialchars(addslashes($course['course_name'] ?? $pid)) ?>', '<?= htmlspecialchars($pid) ?>')" style="background:none;border:1px solid #c7d2fe;border-radius:4px;padding:1px 6px;font-size:0.65rem;color:var(--accent-text);cursor:pointer;white-space:nowrap;" title="Éditer ce cours">✏️</button>
                            <button onclick="event.stopPropagation(); deleteTempCourse('<?= htmlspecialchars($pid) ?>', '<?= htmlspecialchars(addslashes($course['course_name'] ?? $pid)) ?>')" style="background:none;border:none;cursor:pointer;font-size:0.75rem;padding:0 2px;color:#94a3b8;" title="Supprimer">🗑️</button>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <?php // ===== LISTE COURS PERMANENTS (repliée par défaut) =====
                    // N'affiche que les cours décompressés (serveur ou Drive)
                    $permCoursesList = [];
                    $totalPermListSize = 0;
                    $permDecompCount = 0;
                    if (!empty($driveCoursesByFolder)) {
                        foreach ($driveCoursesByFolder as $folderName => $courses) {
                            foreach ($courses as $c) {
                                $fid = $c['gdrive_id'];
                                $permLocalPath = TMP_PATH . '/course_' . md5($fid);
                                $isDecomp = is_dir($permLocalPath);
                                $isOnDrive = isset($driveIndexStatus[$fid]) && $driveIndexStatus[$fid]['status'] === 'ready';
                                // Ne garder que les cours décompressés
                                if (!$isDecomp && !$isOnDrive) continue;
                                // Taille : locale si décompressé sur serveur, sinon lire depuis l'index Drive
                                $permSz = 0;
                                if ($isDecomp) {
                                    $permSz = dirSize($permLocalPath);
                                } elseif ($isOnDrive) {
                                    $driveIdxFile = DRIVE_INDEX_DIR . '/' . $fid . '.json';
                                    if (file_exists($driveIdxFile)) {
                                        $driveIdx = json_decode(file_get_contents($driveIdxFile), true);
                                        $permSz = $driveIdx['total_size'] ?? 0;
                                    }
                                }
                                $totalPermListSize += $permSz;
                                $permDecompCount++;
                                $permCoursesList[] = [
                                    'gdrive_id' => $fid,
                                    'name' => $c['name'],
                                    'folder' => $folderName,
                                    'size' => $permSz,
                                    'decomp' => $isDecomp,
                                    'on_drive' => $isOnDrive,
                                    'available' => true,
                                ];
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($permCoursesList)): ?>
                    <div style="border-top:1px solid #e2e8f0;margin:1rem 0;"></div>
                    <div id="permCacheListSection">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                            <span id="permCacheToggle" onclick="document.getElementById('permCacheList').style.display = document.getElementById('permCacheList').style.display === 'none' ? '' : 'none'; this.textContent = this.textContent === '▼' ? '▶' : '▼';" style="cursor:pointer;font-size:0.7rem;color:#94a3b8;user-select:none;">▶</span>
                            <span onclick="document.getElementById('permCacheToggle').click()" style="font-weight:600;font-size:0.85rem;color:var(--text-primary);cursor:pointer;">☁️ Cours permanents (<?= count($permCoursesList) ?>)<?php
                                if ($permDecompCount > 0) echo ' · <span style="color:#22c55e;font-weight:normal;">' . round($totalPermListSize / 1048576, 1) . ' Mo</span>';
                            ?></span>
                            <button onclick="clearPermanentCoursesCache()" style="margin-left:auto;background:none;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;font-size:0.7rem;color:#94a3b8;cursor:pointer;" title="Supprimer tous les cours permanents du cache">🗑️ Tout</button>
                        </div>
                        <div id="permCacheList" style="display:none;">
                        <?php foreach ($permCoursesList as $pc):
                            $pcSzStr = '';
                            $pcSz = $pc['size'];
                            if ($pcSz > 1048576) $pcSzStr = round($pcSz / 1048576, 1) . ' Mo';
                            elseif ($pcSz > 1024) $pcSzStr = round($pcSz / 1024) . ' Ko';
                            elseif ($pcSz > 0) $pcSzStr = '< 1 Ko';
                            $pcDotColor = '#22c55e';
                            $pcIcon = $pc['on_drive'] ? '☁️' : '💾';
                        ?>
                        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0;font-size:0.8rem;color:#64748b;">
                            <span class="legend-dot" style="background:<?= $pcDotColor ?>;flex-shrink:0;"></span>
                            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;<?= $pc['available'] ? 'cursor:pointer;text-decoration:underline dotted;color:var(--accent-text);' : '' ?>" <?php if ($pc['available']): ?>onclick="openCourse('<?= htmlspecialchars($pc['gdrive_id']) ?>', '<?= htmlspecialchars(addslashes($pc['name'])) ?>', true)"<?php endif; ?> title="<?= htmlspecialchars($pc['folder']) ?>"><?= htmlspecialchars($pc['name']) ?></span>
                            <?php if ($pcSzStr): ?><span style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"><?= $pcSzStr ?></span><?php endif; ?>
                            <span style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"><?= $pcIcon ?></span>
                            <?php if ($pc['available']): ?>
                            <button onclick="event.stopPropagation(); editCourseFromHome('<?= htmlspecialchars($pc['gdrive_id']) ?>', '<?= htmlspecialchars(addslashes($pc['name'])) ?>')" style="background:none;border:1px solid #c7d2fe;border-radius:4px;padding:1px 6px;font-size:0.65rem;color:var(--accent-text);cursor:pointer;white-space:nowrap;" title="Éditer">✏️</button>
                            <?php if ($pc['decomp']): ?>
                            <button onclick="event.stopPropagation(); deleteCacheGdrive('<?= htmlspecialchars($pc['gdrive_id']) ?>', '<?= htmlspecialchars(addslashes($pc['name'])) ?>')" style="background:none;border:none;cursor:pointer;font-size:0.75rem;padding:0 2px;color:#94a3b8;" title="Supprimer du cache">🗑️</button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php else: ?>
                <!-- PROF : Barre simple unifiée -->
                <div class="storage-card">
                    <div class="storage-header">
                        <span class="storage-icon">💾</span>
                        <span class="storage-title">Espace disque cache serveur</span>
                    </div>
                    <div class="storage-bar-container">
                        <div class="storage-bar-combined">
                            <div class="storage-bar-local" style="width: <?= min(100, ($serverTotalMB / $serverMaxMB) * 100) ?>%"></div>
                        </div>
                    </div>
                    <div class="storage-legend">
                        <span class="storage-total"><?= $serverTotalMB ?> Mo / <?= $serverMaxMB ?> Mo</span>
                        <?php if ($serverFull): ?>
                        <span style="color:#ef4444;">⚠️ Espace plein</span>
                        <?php elseif ($serverWarn): ?>
                        <span style="color:#f59e0b;" title="Seuil d'alerte à <?= SERVER_WARN_MB ?> Mo (80%). Videz bientôt le cache pour éviter le blocage à <?= $serverMaxMB ?> Mo.">⚠️ Alerte 80% — videz bientôt</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- Instructions -->
            <div class="instructions-card">
                <h3>💡 Comment ça marche ?</h3>
                <div class="instructions-grid">
                    <div class="instruction">
                        <span class="instruction-number">1</span>
                        <div>
                            <strong>Exportez votre cours</strong>
                            <p>Dans Éléa, faites une sauvegarde de votre cours (fichier .mbz)</p>
                        </div>
                    </div>
                    <div class="instruction">
                        <span class="instruction-number">2</span>
                        <div>
                            <strong>Uploadez-le ici</strong>
                            <p>Glissez le fichier .mbz dans la zone de dépôt</p>
                        </div>
                    </div>
                    <div class="instruction">
                        <span class="instruction-number">3</span>
                        <div>
                            <strong>Partagez le lien</strong>
                            <p>Donnez l'URL générée à vos élèves pour qu'ils accèdent au cours</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="footer">
            <p>
                <?= SITE_NAME ?> • Solution de secours pour parcours Éléa
                <br>
                <small>Les cours sont automatiquement supprimés après <?= COURSE_LIFETIME_HOURS ?> heures</small>
                <br>
                <small><a href="privacy.html" style="color:rgba(255,255,255,0.5);text-decoration:none;">Politique de confidentialité</a></small>
            </p>
        </footer>
    </div>
    
    <!-- Modal Upload -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal">
            <div class="modal-header">
                <h3>📤 Chargement du cours</h3>
                <button class="modal-close" onclick="closeUploadModal()">✕</button>
            </div>
            <div class="modal-body">
                <div id="uploadLoading">
                    <div class="loading"><div class="spinner"></div></div>
                    <p id="uploadStatusText" style="text-align: center; margin-top: 1rem;">Analyse du cours en cours...</p>
                </div>
                
                <div id="uploadError" style="display: none;">
                    <p style="color:#e53e3e;text-align:center;" id="uploadErrorText"></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Cours Permanent (legacy - pour lien élève depuis view.php) -->
    <div class="modal-overlay" id="courseModal">
        <div class="modal">
            <div class="modal-header">
                <h3>📚 <span id="courseModalTitle">Cours</span></h3>
                <button class="modal-close" onclick="closeCourseModal()">✕</button>
            </div>
            <div class="modal-body">
                <p>Chargement du cours en cours...</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCourseModal()">Fermer</button>
            </div>
        </div>
    </div>
    
    <!-- Modal Soutenir -->
    <div class="support-modal-overlay" id="supportModal">
        <div class="support-modal">
            <h3>❤️ Soutenir ce projet</h3>
            <p>Application créée par <strong>Max</strong>.</p>
            <p>Si cette application vous plaît, vous pouvez soutenir mon travail ici :</p>
            <a href="https://fr.tipeee.com/maxtechno/" target="_blank" class="support-link">🎁 Faire un don sur Tipeee</a>
            <div class="thanks">Merci ! 🙏</div>
            <button class="support-close" onclick="closeSupportModal()">Fermer</button>
        </div>
    </div>
    
    <!-- Modal Loading avec barre de progression -->
    <div class="modal-overlay" id="driveLoadingModal">
        <div class="modal">
            <div class="modal-body" style="padding: 2.5rem; text-align: center;">
                <div class="loading-course-icon">📚</div>
                <h4 id="loadingTitle" style="margin: 1rem 0 0.5rem;">Chargement du cours...</h4>
                <p id="loadingStatus" style="color: var(--text-secondary); margin-bottom: 1.5rem; min-height: 1.5em;">Connexion au serveur...</p>
                
                <div class="progress-bar-container">
                    <div class="progress-bar" id="loadingProgressBar"></div>
                </div>
                <div class="progress-percentage" id="loadingPercentage">0%</div>
                
                <div class="loading-steps">
                    <div class="loading-step" id="step1"><span class="step-icon">⏳</span> Connexion</div>
                    <div class="loading-step" id="step2"><span class="step-icon">⏳</span> Téléchargement</div>
                    <div class="loading-step" id="step3"><span class="step-icon">⏳</span> Extraction</div>
                    <div class="loading-step" id="step4"><span class="step-icon">⏳</span> Préparation</div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/js/app.js"></script>
    <script>
        // Toggle dossier
        function toggleFolder(header) {
            header.parentElement.classList.toggle('collapsed');
        }
        
        // Modal Soutenir
        function openSupportModal() {
            document.getElementById('supportModal').classList.add('active');
        }
        function closeSupportModal() {
            document.getElementById('supportModal').classList.remove('active');
        }
        
        // Variables pour le chargement
        var currentCourseId = '';
        var currentCourseName = '';
        
        // Ouvrir un cours directement (plus de popup intermédiaire)
        // Éditer un cours depuis la page d'accueil
        function editCourseFromHome(gdriveId, name, localId) {
            var info = { name: name };
            if (gdriveId) {
                info.type = 'gdrive';
                info.gdriveId = gdriveId;
            } else if (localId) {
                info.type = 'local';
                info.localId = localId;
            }
            try {
                sessionStorage.setItem('editor_needs_cleanup', '1');
                sessionStorage.setItem('courseToLoad', JSON.stringify(info));
                window.location.href = 'editor.php?load=course';
            } catch (e) {
                showAppToast('Erreur: impossible de préparer le cours pour l\'édition.', 'error');
            }
        }
        
        // --- Rafraichissement des icones de cours permanents ---
        function refreshCourseIcons() {
            var ids = [];
            // Cours permanents
            var items = document.querySelectorAll('.course-tree-item[data-gdrive-id]');
            items.forEach(function(el) { ids.push(el.getAttribute('data-gdrive-id')); });
            // Cours temporaires
            var tempItems = document.querySelectorAll('.local-course-card[data-temp-id]');
            tempItems.forEach(function(el) { ids.push('temp_' + el.getAttribute('data-temp-id')); });
            
            if (ids.length === 0) return;
            
            fetch('api/prepare_course.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'courses_status', ids: ids })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.statuses) return;
                // --- Mise à jour cours permanents ---
                items.forEach(function(el) {
                    var fid = el.getAttribute('data-gdrive-id');
                    var st = data.statuses[fid];
                    if (!st) return;
                    var iconEl = el.querySelector('[data-role="icon"]');
                    if (!iconEl) return;
                    var newIcon = st === 'drive' ? '☁️' : (st === 'cached' ? '💾' : '📚');
                    if (iconEl.textContent.trim() !== newIcon) {
                        iconEl.textContent = newIcon;
                    }
                    var wasCached = el.classList.contains('is-cached');
                    var nowAvailable = (st === 'drive' || st === 'cached');
                    var name = el.querySelector('.course-name') ? el.querySelector('.course-name').textContent : '';
                    if (nowAvailable && !wasCached) {
                        el.classList.add('is-cached');
                        iconEl.setAttribute('onclick', "openCourse('" + fid + "', '" + name.replace(/'/g, "\\'") + "', true)");
                        el.querySelector('.course-name').setAttribute('onclick', "openCourse('" + fid + "', '" + name.replace(/'/g, "\\'") + "', true)");
                        var openBtn = el.querySelector('.course-action:last-child');
                        if (openBtn) openBtn.setAttribute('onclick', "openCourse('" + fid + "', '" + name.replace(/'/g, "\\'") + "', true)");
                        if (document.getElementById('driveAdminSection') && !el.querySelector('.course-delete-btn')) {
                            var delBtn = document.createElement('button');
                            delBtn.className = 'course-delete-btn';
                            delBtn.title = 'Supprimer du cache';
                            delBtn.textContent = '🗑️';
                            delBtn.onclick = function(e) { e.stopPropagation(); deleteCacheGdrive(fid, name); };
                            el.querySelector('.course-name').after(delBtn);
                        }
                    } else if (!nowAvailable && wasCached) {
                        el.classList.remove('is-cached');
                        iconEl.setAttribute('onclick', "openCourse('" + fid + "', '" + name.replace(/'/g, "\\'") + "', false)");
                        el.querySelector('.course-name').setAttribute('onclick', "openCourse('" + fid + "', '" + name.replace(/'/g, "\\'") + "', false)");
                        var openBtn = el.querySelector('.course-action:last-child');
                        if (openBtn) openBtn.setAttribute('onclick', "openCourse('" + fid + "', '" + name.replace(/'/g, "\\'") + "', false)");
                        var delBtn = el.querySelector('.course-delete-btn');
                        if (delBtn) delBtn.remove();
                    }
                });
                // --- Mise à jour cours temporaires ---
                tempItems.forEach(function(el) {
                    var pid = el.getAttribute('data-temp-id');
                    var st = data.statuses['temp_' + pid];
                    if (!st) return;
                    var iconEl = el.querySelector('.temp-status-icon');
                    if (!iconEl) return;
                    var newIcon = st === 'drive' ? '☁️' : '💾';
                    if (iconEl.textContent.trim() !== newIcon) {
                        iconEl.textContent = newIcon;
                    }
                    if (st === 'drive') {
                        iconEl.classList.add('is-drive');
                    } else {
                        iconEl.classList.remove('is-drive');
                    }
                });
                // Mettre a jour les compteurs de dossier
                document.querySelectorAll('.drive-tree-folder').forEach(function(folder) {
                    var total = folder.querySelectorAll('.course-tree-item').length;
                    var cached = folder.querySelectorAll('.course-tree-item.is-cached').length;
                    var countEl = folder.querySelector('.folder-header span[style*="color:#888"]');
                    if (countEl) {
                        var html = total + '';
                        if (cached > 0) {
                            html += '<span style="color:#22c55e;margin-left:0.3rem;" title="' + cached + ' cours disponibles">● ' + cached + '</span>';
                        }
                        countEl.innerHTML = html;
                    }
                });
            })
            .catch(function() {});
        }
        // Rafraichir toutes les 60s
        setInterval(refreshCourseIcons, 60000);

        function openCourse(gdriveId, name, isCached) {
            currentCourseId = gdriveId;
            currentCourseName = name;
            
            // Si le cours est en cache, redirection immédiate
            if (isCached) {
                navigateToCourse('view.php?gdrive=' + encodeURIComponent(gdriveId), name);
                return;
            }
            
            // Vérifier l'espace serveur et le lock avant de décompresser
            checkBeforeExtraction(function() {
                openCourseWithProgress();
            });
        }
        
        // Garde-fou : vérifie espace + lock avant toute extraction
        function checkBeforeExtraction(callback) {
            fetch('api/drive_cache.php?action=check_extraction')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.status) {
                    callback(); // En cas d'erreur de vérif, on laisse passer
                    return;
                }
                if (!data.status.can_extract) {
                    showAppDialog({
                        icon: data.status.reason === 'server_full' ? '💾' : '⏳',
                        title: data.status.reason === 'server_full' ? 'Espace serveur plein' : 'Chargement en cours',
                        message: data.status.message
                    });
                } else {
                    callback();
                }
            })
            .catch(function() { callback(); });
        }
        
        function closeCourseModal() {
            document.getElementById('courseModal').classList.remove('active');
        }
        
        // Chargement avec barre de progression (cours non-cachés)
        function openCourseWithProgress() {
            
            // Afficher le modal
            document.getElementById('driveLoadingModal').classList.add('active');
            document.getElementById('loadingTitle').textContent = 'Chargement de "' + currentCourseName + '"';
            
            // Éléments de la barre de progression
            var progressBar = document.getElementById('loadingProgressBar');
            var percentage = document.getElementById('loadingPercentage');
            var status = document.getElementById('loadingStatus');
            var steps = ['step1', 'step2', 'step3', 'step4'];
            
            // Réinitialiser
            progressBar.style.width = '0%';
            percentage.textContent = '0%';
            steps.forEach(function(s) {
                var el = document.getElementById(s);
                el.classList.remove('active', 'done');
                el.querySelector('.step-icon').textContent = '⏳';
            });
            
            // Étapes de progression
            var loadingSteps = [
                { progress: 15, status: 'Connexion au serveur...', step: 0 },
                { progress: 35, status: 'Téléchargement du cours...', step: 1 },
                { progress: 60, status: 'Extraction des données...', step: 2 },
                { progress: 85, status: 'Préparation de l\'affichage...', step: 3 },
                { progress: 100, status: 'Redirection...', step: 3, final: true }
            ];
            
            var currentStep = 0;
            
            function updateProgress() {
                if (currentStep >= loadingSteps.length) return;
                
                var stepData = loadingSteps[currentStep];
                
                // Mettre à jour la barre
                progressBar.style.width = stepData.progress + '%';
                percentage.textContent = stepData.progress + '%';
                status.textContent = stepData.status;
                
                // Mettre à jour les étapes visuelles
                for (var i = 0; i <= stepData.step; i++) {
                    var stepEl = document.getElementById(steps[i]);
                    if (i < stepData.step) {
                        stepEl.classList.remove('active');
                        stepEl.classList.add('done');
                        stepEl.querySelector('.step-icon').textContent = '✓';
                    } else if (i === stepData.step) {
                        stepEl.classList.add('active');
                        stepEl.classList.remove('done');
                        stepEl.querySelector('.step-icon').textContent = '⏳';
                    }
                }
                
                currentStep++;
                
                if (stepData.final) {
                    // Marquer la dernière étape comme done
                    var lastStep = document.getElementById(steps[stepData.step]);
                    lastStep.classList.remove('active');
                    lastStep.classList.add('done');
                    lastStep.querySelector('.step-icon').textContent = '✓';
                    
                    // Rediriger après un court délai
                    setTimeout(function() {
                        window.location.href = 'view.php?gdrive=' + encodeURIComponent(currentCourseId);
                    }, 300);
                } else {
                    // Continuer la progression
                    var delay = 400 + Math.random() * 600; // Entre 400ms et 1000ms
                    setTimeout(updateProgress, delay);
                }
            }
            
            // Démarrer la progression
            setTimeout(updateProgress, 200);
        }
        
        // === FONCTIONS ADMIN ===
        <?php if ($isAdmin): ?>
        // Supprimer un cours temporaire
        function deleteTempCourse(courseId, name) {
            showAppDialog({
                icon: '🗑️', title: 'Supprimer ce cours ?',
                message: 'Le cours temporaire "' + name + '" sera supprimé.',
                confirm: true, confirmText: 'Supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var formData = new FormData();
                formData.append('action', 'delete_temp_course');
                formData.append('course_id', courseId);
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { location.reload(); }
                    else { showAppToast('Erreur: ' + (data.error || 'Impossible de supprimer'), 'error'); }
                })
                .catch(function() { showAppToast('Erreur réseau', 'error'); });
            });
        }
        
        // Supprimer un cours permanent du cache
        function deleteCacheGdrive(gdriveId, name) {
            showAppDialog({
                icon: '🗑️', title: 'Supprimer du cache ?',
                message: 'Le cache de "' + name + '" sera supprimé (serveur et Drive). Le cours pourra être re-décompressé à la prochaine ouverture.',
                confirm: true, confirmText: 'Supprimer'
            }).then(function(ok) {
                if (!ok) return;
                // Feedback visuel immediat
                var item = document.querySelector('.course-tree-item[data-gdrive-id="' + gdriveId + '"]');
                var deleteBtn = item ? item.querySelector('.course-delete-btn') : null;
                if (deleteBtn) {
                    deleteBtn.textContent = '⏳';
                    deleteBtn.disabled = true;
                    deleteBtn.title = 'Suppression en cours...';
                }
                var formData = new FormData();
                formData.append('action', 'delete_cache_course');
                formData.append('gdrive_id', gdriveId);
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    console.log("[DeleteCache] response:", JSON.stringify(data));
                    if (data.success) {
                        if (item) {
                            item.classList.remove('is-cached');
                            var iconEl = item.querySelector('[data-role="icon"]');
                            if (iconEl) iconEl.textContent = '📚';
                            if (deleteBtn) deleteBtn.remove();
                            var nameEl = item.querySelector('.course-name');
                            if (iconEl) iconEl.setAttribute('onclick', "openCourse('" + gdriveId + "', '" + name.replace(/'/g, "\\'") + "', false)");
                            if (nameEl) nameEl.setAttribute('onclick', "openCourse('" + gdriveId + "', '" + name.replace(/'/g, "\\'") + "', false)");
                            var openBtn = item.querySelectorAll('.course-action');
                            openBtn.forEach(function(btn) {
                                if (btn.textContent.trim() === 'Ouvrir') {
                                    btn.setAttribute('onclick', "openCourse('" + gdriveId + "', '" + name.replace(/'/g, "\\'") + "', false)");
                                }
                            });
                        }
                        if (typeof refreshServerGauge === 'function') refreshServerGauge();
                        if (typeof refreshCourseIcons === 'function') refreshCourseIcons();
                        showAppToast('Cache supprimé pour "' + name + '"', 'success');
                    }
                    else {
                        if (deleteBtn) { deleteBtn.textContent = '🗑️'; deleteBtn.disabled = false; deleteBtn.title = 'Supprimer du cache'; }
                        showAppToast('Erreur: ' + (data.error || 'Impossible de supprimer'), 'error');
                    }
                })
                .catch(function() {
                    if (deleteBtn) { deleteBtn.textContent = '🗑️'; deleteBtn.disabled = false; deleteBtn.title = 'Supprimer du cache'; }
                    showAppToast('Erreur réseau', 'error');
                });
            });
        }
        
        // Vider tout le cache
        function clearAllCache() {
            showAppDialog({
                icon: '⚠️', title: 'Supprimer TOUT le cache ?',
                message: 'Tous les cours en cache seront supprimés. Cette action est irréversible.',
                confirm: true, confirmText: 'Tout supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var formData = new FormData();
                formData.append('action', 'clear_all_cache');
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showAppToast(data.deleted + ' cours supprimé(s) du cache', 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else { showAppToast('Erreur: ' + (data.error || 'Impossible de vider le cache'), 'error'); }
                })
                .catch(function() { showAppToast('Erreur réseau', 'error'); });
            });
        }
        
        function clearEditorCache() {
            showAppDialog({
                icon: '⚠️', title: 'Supprimer les brouillons ?',
                message: 'Tous les brouillons de création de cours seront supprimés pour tous les utilisateurs. Cette action est irréversible.',
                confirm: true, confirmText: 'Supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var formData = new FormData();
                formData.append('action', 'clear_editor_cache');
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        try { localStorage.removeItem('elea_editor_session_id'); sessionStorage.removeItem('courseToLoad'); } catch(e) {}
                        showAppToast(data.total_deleted + ' fichier(s) supprimé(s)', 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else { showAppToast('Erreur: ' + (data.error || 'Impossible de vider le cache'), 'error'); }
                })
                .catch(function() { showAppToast('Erreur réseau', 'error'); });
            });
        }
        
        function clearTempCoursesCache() {
            showAppDialog({
                icon: '🗑️', title: 'Vider le cache temporaire ?',
                message: 'Tous les cours temporaires seront supprimés du serveur.',
                confirm: true, confirmText: 'Supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var formData = new FormData();
                formData.append('action', 'clear_temp_courses_cache');
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showAppToast(data.deleted + ' cours temporaire(s) supprimé(s)', 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else { showAppToast('Erreur: ' + (data.error || 'Impossible de vider le cache'), 'error'); }
                })
                .catch(function() { showAppToast('Erreur réseau', 'error'); });
            });
        }
        
        function clearPermanentCoursesCache() {
            showAppDialog({
                icon: '🗑️', title: 'Vider le cache permanent ?',
                message: 'Les cours seront re-téléchargés depuis Google Drive à la prochaine ouverture.',
                confirm: true, confirmText: 'Supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var formData = new FormData();
                formData.append('action', 'clear_permanent_courses_cache');
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showAppToast(data.deleted + ' cours permanent(s) supprimé(s)', 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else { showAppToast('Erreur: ' + (data.error || 'Impossible de vider le cache'), 'error'); }
                })
                .catch(function() { showAppToast('Erreur réseau', 'error'); });
            });
        }
        
        function clearPdfCache() {
            showAppDialog({
                icon: '🗑️', title: 'Vider le cache PDF ?',
                message: 'Tous les fichiers temporaires de génération PDF seront supprimés.',
                confirm: true, confirmText: 'Supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var formData = new FormData();
                formData.append('action', 'clear_pdf_cache');
                fetch('index.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        showAppToast(data.deleted + ' dossier(s) PDF supprimé(s)', 'success');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else { showAppToast('Erreur: ' + (data.error || 'Impossible de vider le cache'), 'error'); }
                })
                .catch(function() { showAppToast('Erreur réseau', 'error'); });
            });
        }
        
        // Rafraichissement automatique de la jauge serveur toutes les 60 secondes
        if (!window._gaugeIntervals) window._gaugeIntervals = [];
        
        function startServerGaugeInterval() {
            return setInterval(function() { refreshServerGauge(); }, 60000);
        }
        window._gaugeIntervals.push(startServerGaugeInterval());
        
        // Fonction globale pour relancer les jauges apres un upload
        window._restartGauges = function() {
            if (!window._gaugeIntervals) window._gaugeIntervals = [];
            // Relancer la jauge serveur
            window._gaugeIntervals.push(startServerGaugeInterval());
            // Rafraichir immediatement
            refreshServerGauge();
        };
        
        function refreshServerGauge() {
            fetch('api/drive_cache.php?action=server_usage')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) return;
                var u = data.usage;
                
                // Construire le HTML des boutons et comparer
                var btns = document.getElementById('server-cache-buttons');
                if (btns) {
                    var html = '';
                    if (u.tempMB > 0) html += '<button class="clear-cache-btn" onclick="clearTempCoursesCache()">🗑️ ' + (u.tempCount || '') + ' Temporaire' + ((u.tempCount || 0) > 1 ? 's' : '') + ' (' + u.tempMB + ' Mo)</button>';
                    if (u.permanentMB > 0) html += '<button class="clear-cache-btn" onclick="clearPermanentCoursesCache()">🗑️ ' + (u.permanentCount || '') + ' Permanent' + ((u.permanentCount || 0) > 1 ? 's' : '') + ' (' + u.permanentMB + ' Mo)</button>';
                    if (u.creationMB > 0) html += '<button class="clear-cache-btn" onclick="clearEditorCache()">🗑️ Création (' + u.creationMB + ' Mo)</button>';
                    if (u.pdfMB > 0) html += '<button class="clear-cache-btn" onclick="clearPdfCache()">🗑️ ' + (u.pdfCount || '') + ' PDF (' + u.pdfMB + ' Mo)</button>';
                    if (btns.innerHTML !== html) btns.innerHTML = html;
                }
                
                // Mettre à jour les segments de la barre (créer si absents, sinon juste width)
                var bar = document.getElementById('server-cache-bar');
                if (bar) {
                    var maxMB = u.maxMB;
                    var pctTemp = Math.min(100, (u.tempMB / maxMB) * 100);
                    var pctPerm = Math.min(100 - pctTemp, (u.permanentMB / maxMB) * 100);
                    var pctCrea = Math.min(100 - pctTemp - pctPerm, (u.creationMB / maxMB) * 100);
                    var pctPdf = Math.min(100 - pctTemp - pctPerm - pctCrea, (u.pdfMB / maxMB) * 100);
                    
                    bar.innerHTML = '<div style="width:' + pctTemp + '%;height:100%;background:#6366f1;float:left;border-radius:8px 0 0 8px;transition:width 0.5s ease;"></div>' +
                        '<div style="width:' + pctPerm + '%;height:100%;background:#22c55e;float:left;transition:width 0.5s ease;"></div>' +
                        '<div style="width:' + pctCrea + '%;height:100%;background:#f59e0b;float:left;transition:width 0.5s ease;"></div>' +
                        '<div style="width:' + pctPdf + '%;height:100%;background:#ec4899;float:left;border-radius:0 8px 8px 0;transition:width 0.5s ease;"></div>';
                }
                
                // Mettre à jour la légende
                var legend = document.getElementById('server-cache-legend');
                if (legend) {
                    var html = '<span><span class="legend-dot" style="background:#6366f1;"></span> Temporaires : ' + (u.tempCount || 0) + ' (' + u.tempMB + ' Mo)</span>' +
                        '<span><span class="legend-dot" style="background:#22c55e;"></span> Permanents : ' + (u.permanentCount || 0) + ' (' + u.permanentMB + ' Mo)</span>' +
                        '<span><span class="legend-dot" style="background:#f59e0b;"></span> Création : ' + (u.creationCount || 0) + ' (' + u.creationMB + ' Mo)</span>';
                    if (u.pdfMB > 0) html += '<span><span class="legend-dot" style="background:#ec4899;"></span> PDF : ' + (u.pdfCount || 0) + ' (' + u.pdfMB + ' Mo)</span>';
                    html += '<span class="storage-total">' + u.totalMB + ' Mo / ' + u.maxMB + ' Mo</span>';
                    if (u.totalMB >= u.maxMB) html += '<span style="color:#ef4444;">⚠️ Espace plein</span>';
                    if (legend.innerHTML !== html) legend.innerHTML = html;
                }
            })
            .catch(function() {});
        }
        <?php endif; ?>
        
        // === Jauge Google Drive ===
        <?php if ($isAdmin): ?>
        function formatDriveSize(bytes) {
            if (bytes === 0) return '0 o';
            var units = ['o', 'Ko', 'Mo', 'Go'];
            var i = Math.floor(Math.log(bytes) / Math.log(1024));
            if (i >= units.length) i = units.length - 1;
            return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i];
        }
        
        function loadDriveUsage(force) {
            var url = 'api/drive_usage.php?action=get_sizes' + (force ? '&force=1' : '');
            fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (!json.success) {
                    var errMsg = json.error || 'inconnue';
                    var isTokenError = errMsg.indexOf('invalid_grant') !== -1 || errMsg.indexOf('expired') !== -1 || errMsg.indexOf('revoked') !== -1 || errMsg.indexOf('refresh_token') !== -1;
                    if (isTokenError) {
                        document.getElementById('drive-legend').innerHTML = '<span style="color:#ef4444;font-size:0.75rem;">🔑 Token Drive expiré — <a href="authorize_drive.php" style="color:#4285f4;font-weight:600;">Renouveler l\'accès</a></span>';
                    } else {
                        document.getElementById('drive-legend').innerHTML = '<span style="color:#ef4444;font-size:0.75rem;">Erreur : ' + errMsg + '</span>';
                    }
                    document.getElementById('drive-bar-loading').style.display = 'none';
                    return;
                }
                
                var d = json.data;
                var quotaTotal = d.quota.total || (15 * 1024 * 1024 * 1024);
                var quotaUsed = d.quota.used || 0;
                
                // Barre : segments par dossier
                var bar = document.getElementById('drive-bar');
                var barHtml = '';
                d.folders.forEach(function(f, i) {
                    var pct = Math.min(100, (f.size / quotaTotal) * 100);
                    var radius = i === 0 ? '8px 0 0 8px' : (i === d.folders.length - 1 ? '0 8px 8px 0' : '0');
                    barHtml += '<div style="width:' + Math.max(0.2, pct).toFixed(2) + '%;height:100%;background:' + f.color + ';float:left;border-radius:' + radius + ';transition:width 0.8s ease;"></div>';
                });
                bar.innerHTML = barHtml;
                
                // Légende avec compteurs
                var legend = document.getElementById('drive-legend');
                var legendHtml = '';
                d.folders.forEach(function(f) {
                    var count = f.courses || 0;
                    legendHtml += '<span><span class="legend-dot" style="background:' + f.color + ';"></span> <a href="#" onclick="event.preventDefault(); verifyDriveFolder(\'' + f.id + '\', \'' + f.name + '\')" style="color:inherit;text-decoration:none;border-bottom:1px dashed #cbd5e1;" title="Cliquer pour vérifier">' + f.name + '</a> : ' + count + ' (' + formatDriveSize(f.size) + ')</span>';
                });
                legendHtml += '<span class="storage-total">' + formatDriveSize(quotaUsed) + ' / ' + formatDriveSize(quotaTotal) + '</span>';
                legend.innerHTML = legendHtml;

                // Boutons de vidage
                var btnsEl = document.getElementById('drive-buttons');
                if (btnsEl) {
                    var btnsHtml = '';
                    d.folders.forEach(function(f) {
                        var count = f.courses || 0;
                        if (count > 0 && f.name !== 'CoursElea') {
                            btnsHtml += '<button class="clear-cache-btn" onclick="clearDriveFolder(\'' + f.id + '\', \'' + f.name + '\')" title="Vider ' + f.name + ' sur Drive">🗑️ ' + count + ' ' + f.name + ' (' + formatDriveSize(f.size) + ')</button>';
                        }
                    });
                    btnsEl.innerHTML = btnsHtml;
                }
            })
            .catch(function(e) {
                document.getElementById('drive-legend').innerHTML = '<span style="color:#ef4444;font-size:0.75rem;">Erreur réseau</span>';
            });
        }
        
        // Charger au démarrage et rafraîchir toutes les 2 minutes
        setTimeout(function() { loadDriveUsage(true); }, 1500);
        function startDriveGaugeInterval() {
            return setInterval(function() { loadDriveUsage(false); }, 120000);
        }
        window._gaugeIntervals.push(startDriveGaugeInterval());
        
        // Completer _restartGauges avec la jauge Drive
        var _origRestart = window._restartGauges;
        window._restartGauges = function() {
            _origRestart();
            window._gaugeIntervals.push(startDriveGaugeInterval());
            loadDriveUsage(false);
        };

        <?php endif; ?>

        <?php if ($isAdmin): ?>
        function toggleEditorSessions() {
            var list = document.getElementById('editorSessionsList');
            var toggle = document.getElementById('editorSessionsToggle');
            if (!list) return;
            if (list.style.display === 'none') {
                list.style.display = '';
                if (toggle) toggle.textContent = '▼';
            } else {
                list.style.display = 'none';
                if (toggle) toggle.textContent = '▶';
            }
        }

        function deleteEditorSession(sessionId) {
            fetch('api/editor_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cleanup_editor_session', sessionId: sessionId })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var row = document.querySelector('.editor-session-row[data-session-id="' + sessionId + '"]');
                    if (row) row.remove();
                    var remaining = document.querySelectorAll('.editor-session-row');
                    if (remaining.length === 0) {
                        var section = document.getElementById('editorSessionsSection');
                        var sep = document.getElementById('editorSessionsSeparator');
                        if (section) section.style.display = 'none';
                        if (sep) sep.style.display = 'none';
                    } else {
                        var title = document.getElementById('editorSessionsTitle');
                        if (title) title.textContent = '📝 Cours en création (' + remaining.length + ')';
                    }
                    if (typeof refreshServerGauge === 'function') refreshServerGauge();
                    showAppToast('Session supprimée', 'success');
                }
            })
            .catch(function() { showAppToast('Erreur réseau', 'error'); });
        }

        function previewEditorSession(sessionId) {
            // Trouver le nom du cours depuis la ligne
            var row = document.querySelector('.editor-session-row[data-session-id="' + sessionId + '"]');
            var courseName = row ? (row.querySelector('.es-name') ? row.querySelector('.es-name').textContent.trim() : '') : '';
            document.getElementById('courseLoadingName').textContent = courseName;
            document.getElementById('courseLoadingOverlay').classList.add('active');
            _courseLoadingAbort = new AbortController();
            fetch('api/editor_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'preview_editor_session', sessionId: sessionId }),
                signal: _courseLoadingAbort.signal
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.viewUrl) {
                    window.location.href = data.viewUrl;
                } else {
                    document.getElementById('courseLoadingOverlay').classList.remove('active');
                    showAppToast('Erreur: ' + (data.error || 'Impossible d\'ouvrir'), 'error');
                }
            })
            .catch(function(e) {
                if (e.name === 'AbortError') return;
                document.getElementById('courseLoadingOverlay').classList.remove('active');
                showAppToast('Erreur réseau', 'error');
            });
        }

        function editEditorSession(sessionId, courseName) {
            try {
                var mySessionId = localStorage.getItem('elea_editor_session_id') || '';
                
                if (sessionId === mySessionId) {
                    // C'est NOTRE cours → ouvrir l'éditeur normalement (restauration du draft)
                    // Pas de cleanup, pas de courseToLoad
                    window.location.href = 'editor.php';
                } else {
                    // C'est le cours d'un AUTRE prof → cleanup notre session + charger son draft
                    var info = {
                        type: 'editor_session',
                        sessionId: sessionId,
                        name: courseName
                    };
                    sessionStorage.setItem('editor_needs_cleanup', '1');
                    sessionStorage.setItem('courseToLoad', JSON.stringify(info));
                    window.location.href = 'editor.php?load=course';
                }
            } catch (e) {
                showAppToast('Erreur: impossible de préparer le cours', 'error');
            }
        }

        function cleanAllEditorSessions() {
            var rows = document.querySelectorAll('.editor-session-row');
            if (rows.length === 0) return;
            showAppDialog({
                icon: '🗑️',
                title: 'Supprimer toutes les sessions ?',
                message: rows.length + ' session(s) en création seront supprimées (fichiers serveur + Drive).',
                confirm: true,
                confirmText: 'Tout supprimer'
            }).then(function(ok) {
                if (!ok) return;
                var promises = [];
                rows.forEach(function(row) {
                    var sid = row.getAttribute('data-session-id');
                    if (sid) {
                        promises.push(
                            fetch('api/editor_api.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'cleanup_editor_session', sessionId: sid })
                            }).then(function(r) { return r.json(); })
                        );
                    }
                });
                Promise.all(promises).then(function() {
                    var section = document.getElementById('editorSessionsSection');
                    var sep = document.getElementById('editorSessionsSeparator');
                    if (section) section.style.display = 'none';
                    if (sep) sep.style.display = 'none';
                    if (typeof refreshServerGauge === 'function') refreshServerGauge();
                    showAppToast('Toutes les sessions supprimées', 'success');
                });
            });
        }

        // Rafraîchissement périodique de la section cours en création
        (function() {
            function refreshEditorSessions() {
                fetch('api/editor_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_editor_sessions' })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) return;
                    var sessions = data.sessions || [];
                    var section = document.getElementById('editorSessionsSection');
                    var sep = document.getElementById('editorSessionsSeparator');
                    var list = document.getElementById('editorSessionsList');
                    var toggle = document.getElementById('editorSessionsToggle');
                    
                    // Pas de sessions → cacher tout
                    if (sessions.length === 0) {
                        if (section) section.style.display = 'none';
                        if (sep) sep.style.display = 'none';
                        return;
                    }
                    
                    // Il y a des sessions → montrer le titre (toujours)
                    if (section) section.style.display = '';
                    if (sep) sep.style.display = '';
                    
                    // Vérifier s'il y a de l'activité (upload en cours)
                    var hasActivity = false;
                    sessions.forEach(function(es) {
                        if ((es.pending_count || 0) > 0 || (es.local_count || 0) > 0) hasActivity = true;
                    });
                    
                    // Auto-déplier si activité
                    if (hasActivity && list && list.style.display === 'none') {
                        list.style.display = '';
                        if (toggle) toggle.textContent = '▼';
                    }
                    
                    // Mettre à jour les lignes existantes et ajouter les nouvelles
                    var existingIds = {};
                    document.querySelectorAll('.editor-session-row').forEach(function(r) {
                        existingIds[r.getAttribute('data-session-id')] = r;
                    });
                    
                    sessions.forEach(function(es) {
                        var row = existingIds[es.session_id];
                        
                        var esName = es.course_name || ('Session ' + es.session_id.substr(0, 12));
                        var expireTs = (es.last_activity || es.created_at || (Date.now()/1000)) + 24 * 3600;
                        var expireDate = new Date(expireTs * 1000);
                        var expireStr = 'exp. ' + ('0'+expireDate.getDate()).slice(-2) + '/' + ('0'+(expireDate.getMonth()+1)).slice(-2) + ' ' + ('0'+expireDate.getHours()).slice(-2) + ':' + ('0'+expireDate.getMinutes()).slice(-2);
                        
                        var sz = es.total_size || 0;
                        var sizeStr;
                        if (sz > 1048576) sizeStr = (sz / 1048576).toFixed(1) + ' Mo';
                        else if (sz > 1024) sizeStr = Math.round(sz / 1024) + ' Ko';
                        else sizeStr = sz + ' o';
                        
                        if (!row) {
                            // Nouvelle session : créer la ligne
                            row = document.createElement('div');
                            row.className = 'editor-session-row';
                            row.setAttribute('data-session-id', es.session_id);
                            row.style.cssText = 'display:flex;align-items:center;gap:0.5rem;padding:0.3rem 0;font-size:0.8rem;color:#64748b;';
                            row.innerHTML = '<span class="legend-dot es-dot" style="flex-shrink:0;"></span>' +
                                '<span class="es-name" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;text-decoration:underline dotted;color:var(--accent-text);" title="Ouvrir dans le visualiseur"></span>' +
                                '<span class="es-size" style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"></span>' +
                                '<span class="es-counters" style="display:flex;gap:0.3rem;align-items:center;"></span>' +
                                '<span class="es-age" style="color:#94a3b8;font-size:0.65rem;white-space:nowrap;"></span>' +
                                '<button class="es-edit-btn" style="background:none;border:1px solid #c7d2fe;border-radius:4px;padding:1px 6px;font-size:0.65rem;color:var(--accent-text);cursor:pointer;white-space:nowrap;" title="Éditer ce cours">✏️</button>' +
                                '<button class="es-del-btn" style="background:none;border:none;cursor:pointer;font-size:0.75rem;padding:0 2px;color:#94a3b8;" title="Supprimer">🗑️</button>';
                            row.querySelector('.es-name').onclick = function() { previewEditorSession(es.session_id); };
                            row.querySelector('.es-edit-btn').onclick = function() { editEditorSession(es.session_id, esName); };
                            row.querySelector('.es-del-btn').onclick = function() { deleteEditorSession(es.session_id); };
                            if (list) list.appendChild(row);
                        }
                        
                        var dot = row.querySelector('.es-dot');
                        if (dot) dot.style.background = es.has_drive ? '#f59e0b' : '#94a3b8';
                        
                        var nameEl = row.querySelector('.es-name');
                        if (nameEl) {
                            var uploadStatus = (es.pending_count > 0) ? '<span class="es-upload-status" style="color:#3b82f6;font-style:italic;text-decoration:none;"> - upload...</span>' : '';
                            nameEl.innerHTML = esName.replace(/</g, '&lt;') + uploadStatus;
                        }
                        
                        var sizeEl = row.querySelector('.es-size');
                        if (sizeEl) sizeEl.textContent = sizeStr;
                        
                        var counters = row.querySelector('.es-counters');
                        if (counters) {
                            var html = '';
                            if (es.drive_count > 0) html += '<span style="color:#f59e0b;font-size:0.7rem;white-space:nowrap;">☁️' + es.drive_count + '</span>';
                            if (es.local_count > 0) html += '<span style="color:#6366f1;font-size:0.7rem;white-space:nowrap;">💾' + es.local_count + '</span>';
                            if (es.pending_count > 0) html += '<span style="color:#3b82f6;font-size:0.7rem;white-space:nowrap;">⏳' + es.pending_count + '</span>';
                            counters.innerHTML = html;
                        }
                        
                        var ageEl = row.querySelector('.es-age');
                        if (ageEl) ageEl.textContent = expireStr;
                        
                        delete existingIds[es.session_id];
                    });
                    
                    // Supprimer les sessions qui n'existent plus
                    for (var oldId in existingIds) {
                        existingIds[oldId].remove();
                    }
                    
                    var title = document.getElementById('editorSessionsTitle');
                    if (title) title.textContent = '📝 Cours en création (' + sessions.length + ')';
                })
                .catch(function() {});
            }
            
            setInterval(refreshEditorSessions, 10000);
        })();

        function verifyDriveFolder(folderId, folderName) {
            showAppToast('Vérification de ' + folderName + '...', 'info');
            fetch('api/drive_usage.php?action=verify_folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ folder_id: folderId, folder_name: folderName })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    showAppToast('Erreur : ' + (data.error || '?'), 'error');
                    return;
                }
                var unit = (folderName === 'CoursElea') ? ' fichiers MBZ sur Drive' : ' cours sur Drive';
                var msg = folderName + ' : ' + data.drive_count + unit;
                var issues = [];
                if (data.orphaned_indexes && data.orphaned_indexes.length > 0) {
                    issues.push(data.orphaned_indexes.length + ' index locaux orphelins');
                }
                if (data.orphaned_drive && data.orphaned_drive.length > 0) {
                    issues.push(data.orphaned_drive.length + ' dossiers Drive sans index');
                }
                if (data.empty_drive && data.empty_drive.length > 0) {
                    issues.push(data.empty_drive.length + ' dossiers vides');
                }
                if (data.unknown_courses && data.unknown_courses.length > 0) {
                    issues.push(data.unknown_courses.length + ' cours inconnus (pas dans la liste source)');
                }
                if (data.duplicates && data.duplicates.length > 0) {
                    var totalDupes = 0;
                    data.duplicates.forEach(function(d) { totalDupes += d.count - 1; });
                    issues.push(totalDupes + ' doublon(s) Drive');
                }

                if (issues.length === 0) {
                    showAppDialog({
                        icon: '✅',
                        title: folderName + ' — OK',
                        message: msg + '\nTout est synchronisé.'
                    });
                } else {
                    var detail = msg + '\n\nProblèmes détectés :\n• ' + issues.join('\n• ');
                    if (data.orphaned_indexes && data.orphaned_indexes.length > 0) {
                        detail += '\n\nIndex orphelins : ' + data.orphaned_indexes.join(', ');
                    }
                    if (data.orphaned_drive && data.orphaned_drive.length > 0) {
                        detail += '\n\nDossiers Drive orphelins : ' + data.orphaned_drive.join(', ');
                    }
                    if (data.empty_drive && data.empty_drive.length > 0) {
                        detail += '\n\nDossiers vides : ' + data.empty_drive.map(function(d) { return d.name; }).join(', ');
                    }
                    if (data.unknown_courses && data.unknown_courses.length > 0) {
                        detail += '\n\nCours inconnus : ' + data.unknown_courses.map(function(d) { return d.name.substr(0, 16) + '...'; }).join(', ');
                    }
                    if (data.duplicates && data.duplicates.length > 0) {
                        detail += '\n\nDoublons : ' + data.duplicates.map(function(d) { return d.name.substr(0, 16) + '... (' + d.count + ' copies)'; }).join(', ');
                    }
                    showAppDialog({
                        icon: '⚠️',
                        title: folderName + ' — ' + issues.length + ' problème(s)',
                        message: detail,
                        confirm: true,
                        confirmText: 'Nettoyer',
                        cancelText: 'Fermer'
                    }).then(function(ok) {
                        if (!ok) return;
                        cleanupDriveOrphans(folderId, folderName, data);
                    });
                }
            })
            .catch(function(e) {
                showAppToast('Erreur réseau', 'error');
            });
        }

        function cleanupDriveOrphans(folderId, folderName, verifyData) {
            showAppToast('Nettoyage en cours...', 'info');
            var promises = [];

            // Supprimer les index locaux orphelins
            if (verifyData.orphaned_indexes && verifyData.orphaned_indexes.length > 0) {
                verifyData.orphaned_indexes.forEach(function(id) {
                    promises.push(
                        fetch('api/drive_usage.php?action=delete_index', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ index_id: id, folder_name: folderName })
                        }).then(function(r) { return r.json(); })
                    );
                });
            }

            // Supprimer les dossiers Drive vides
            if (verifyData.empty_drive && verifyData.empty_drive.length > 0) {
                verifyData.empty_drive.forEach(function(d) {
                    promises.push(
                        fetch('api/drive_usage.php?action=delete_drive_folder', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ folder_id: d.id })
                        }).then(function(r) { return r.json(); })
                    );
                });
            }

            // Supprimer les cours inconnus (dossier Drive + index local s'il existe)
            if (verifyData.unknown_courses && verifyData.unknown_courses.length > 0) {
                verifyData.unknown_courses.forEach(function(d) {
                    // Supprimer le dossier Drive
                    promises.push(
                        fetch('api/drive_usage.php?action=delete_drive_folder', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ folder_id: d.id })
                        }).then(function(r) { return r.json(); })
                    );
                    // Supprimer l'index local associé s'il existe
                    promises.push(
                        fetch('api/drive_usage.php?action=delete_index', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ index_id: d.name, folder_name: folderName })
                        }).then(function(r) { return r.json(); })
                    );
                });
            }

            // Supprimer les dossiers Drive orphelins (sans index local)
            if (verifyData.orphaned_drive && verifyData.orphaned_drive.length > 0) {
                // Trouver les driveFolders correspondants pour avoir les IDs
                // orphaned_drive ne contient que les noms, il faut retrouver les IDs
                // On passe par delete_drive_by_name
                verifyData.orphaned_drive.forEach(function(name) {
                    promises.push(
                        fetch('api/drive_usage.php?action=delete_drive_subfolder', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ parent_id: folderId, subfolder_name: name })
                        }).then(function(r) { return r.json(); })
                    );
                });
            }

            // Supprimer les doublons Drive (garder le 1er, supprimer les suivants)
            if (verifyData.duplicates && verifyData.duplicates.length > 0) {
                verifyData.duplicates.forEach(function(dup) {
                    for (var di = 1; di < dup.folders.length; di++) {
                        promises.push(
                            fetch('api/drive_usage.php?action=delete_drive_folder', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ folder_id: dup.folders[di].id })
                            }).then(function(r) { return r.json(); })
                        );
                    }
                });
            }

            Promise.all(promises).then(function() {
                showAppToast('Nettoyage terminé', 'success');
                loadDriveUsage(true);
                if (typeof refreshCourseIcons === 'function') refreshCourseIcons();
            }).catch(function() {
                showAppToast('Erreur durant le nettoyage', 'error');
            });
        }

        function clearDriveFolder(folderId, folderName) {
            if (!confirm('Vider le dossier "' + folderName + '" sur Google Drive ?\nTous les fichiers seront supprimés.')) return;
            var btnsEl = document.getElementById('drive-buttons');
            if (btnsEl) btnsEl.innerHTML = '<span style="font-size:0.75rem;color:#94a3b8;">Suppression en cours...</span>';
            fetch('api/drive_usage.php?action=empty_folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ folder_id: folderId })
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success && folderName === 'CoursPermanents') {
                    var fd = new FormData();
                    fd.append('action', 'clear_drive_indexes');
                    return fetch('index.php', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function() {
                            if (typeof refreshCourseIcons === 'function') refreshCourseIcons();
                            loadDriveUsage(true);
                        });
                }
                if (json.success && folderName === 'CoursTemporaires') {
                    var fd = new FormData();
                    fd.append('action', 'clear_temp_drive_indexes');
                    return fetch('index.php', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function() {
                            if (typeof refreshCourseIcons === 'function') refreshCourseIcons();
                            loadDriveUsage(true);
                        });
                }
                if (!json.success) {
                    alert('Erreur : ' + (json.error || 'inconnue'));
                }
                loadDriveUsage(true);
            })
            .catch(function(e) {
                alert('Erreur réseau');
                loadDriveUsage(true);
            });
        }

        // === Auto-vérification silencieuse des dossiers Drive au chargement ===
        function autoVerifyDriveFolders() {
            try { if (sessionStorage.getItem('drive_auto_verified')) return; } catch(e) {}
            
            var folders = [
                { id: '<?= DRIVE_COURSEPERMANENTS_FOLDER_ID ?>', name: 'CoursPermanents' },
                { id: '<?= DRIVE_COURSETEMP_FOLDER_ID ?>', name: 'CoursTemporaires' },
                { id: '<?= DRIVE_COURSCREATION_FOLDER_ID ?>', name: 'CoursCreation' }
            ];
            var totalCleaned = 0;
            var folderIndex = 0;
            
            function verifyNext() {
                if (folderIndex >= folders.length) {
                    try { sessionStorage.setItem('drive_auto_verified', '1'); } catch(e) {}
                    if (totalCleaned > 0) {
                        showAppToast(totalCleaned + ' élément(s) orphelin(s) nettoyé(s) sur Drive', 'success');
                        loadDriveUsage(true);
                    }
                    return;
                }
                var folder = folders[folderIndex++];
                fetch('api/drive_usage.php?action=verify_folder', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ folder_id: folder.id, folder_name: folder.name })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) { verifyNext(); return; }
                    var issues = (data.orphaned_indexes || []).length
                        + (data.orphaned_drive || []).length
                        + (data.empty_drive || []).length
                        + (data.duplicates || []).reduce(function(acc, d) { return acc + d.count - 1; }, 0);
                    if (issues === 0) { verifyNext(); return; }
                    totalCleaned += issues;
                    cleanupDriveOrphansSilent(folder.id, folder.name, data, verifyNext);
                })
                .catch(function() { verifyNext(); });
            }
            verifyNext();
        }
        
        function cleanupDriveOrphansSilent(folderId, folderName, verifyData, callback) {
            var promises = [];
            if (verifyData.orphaned_indexes) {
                verifyData.orphaned_indexes.forEach(function(id) {
                    promises.push(fetch('api/drive_usage.php?action=delete_index', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ index_id: id, folder_name: folderName })
                    }).then(function(r) { return r.json(); }));
                });
            }
            if (verifyData.empty_drive) {
                verifyData.empty_drive.forEach(function(d) {
                    promises.push(fetch('api/drive_usage.php?action=delete_drive_folder', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ folder_id: d.id })
                    }).then(function(r) { return r.json(); }));
                });
            }
            if (verifyData.orphaned_drive) {
                verifyData.orphaned_drive.forEach(function(name) {
                    promises.push(fetch('api/drive_usage.php?action=delete_drive_subfolder', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ parent_id: folderId, subfolder_name: name })
                    }).then(function(r) { return r.json(); }));
                });
            }
            if (verifyData.duplicates) {
                verifyData.duplicates.forEach(function(dup) {
                    for (var di = 1; di < dup.folders.length; di++) {
                        promises.push(fetch('api/drive_usage.php?action=delete_drive_folder', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ folder_id: dup.folders[di].id })
                        }).then(function(r) { return r.json(); }));
                    }
                });
            }
            Promise.all(promises).then(callback).catch(callback);
        }
        
        // Lancer 5s après le chargement (laisser la jauge se charger d'abord)
        setTimeout(autoVerifyDriveFolders, 5000);

        <?php endif; ?>
        (function() {
            fetch('api/editor_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'has_draft' })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    sessionStorage.setItem('editor_has_draft', data.has_draft ? '1' : '0');
                }
            })
            .catch(function() {});
        })();
    </script>
    
    <!-- Dialog personnalisé -->
    <div class="app-dialog-overlay" id="appDialog">
        <div class="app-dialog">
            <div class="app-dialog-icon" id="appDialogIcon">🗑️</div>
            <div class="app-dialog-title" id="appDialogTitle"></div>
            <div class="app-dialog-message" id="appDialogMessage"></div>
            <div class="app-dialog-buttons" id="appDialogButtons"></div>
        </div>
    </div>
    <div class="app-toast-container" id="appToastContainer"></div>
    
    <script>
    function showAppToast(message, type) {
        var container = document.getElementById('appToastContainer');
        var toast = document.createElement('div');
        toast.className = 'app-toast' + (type ? ' ' + type : '');
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function() {
            toast.classList.add('fadeOut');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }
    
    function showAppDialog(opts) {
        return new Promise(function(resolve) {
            var overlay = document.getElementById('appDialog');
            document.getElementById('appDialogIcon').textContent = opts.icon || '⚠️';
            document.getElementById('appDialogTitle').textContent = opts.title || '';
            document.getElementById('appDialogMessage').textContent = opts.message || '';
            
            var btns = document.getElementById('appDialogButtons');
            btns.innerHTML = '';
            
            if (opts.confirm) {
                var cancelBtn = document.createElement('button');
                cancelBtn.className = 'app-dialog-btn cancel';
                cancelBtn.textContent = 'Annuler';
                cancelBtn.onclick = function() { overlay.classList.remove('active'); resolve(false); };
                btns.appendChild(cancelBtn);
                
                var confirmBtn = document.createElement('button');
                confirmBtn.className = 'app-dialog-btn ' + (opts.btnClass || 'danger');
                confirmBtn.textContent = opts.confirmText || 'Supprimer';
                confirmBtn.onclick = function() { overlay.classList.remove('active'); resolve(true); };
                btns.appendChild(confirmBtn);
            } else {
                var okBtn = document.createElement('button');
                okBtn.className = 'app-dialog-btn primary';
                okBtn.textContent = 'OK';
                okBtn.onclick = function() { overlay.classList.remove('active'); resolve(true); };
                btns.appendChild(okBtn);
            }
            
            overlay.classList.add('active');
        });
    }
    </script>
<?php include __DIR__ . '/includes/drive_upload_widget.php'; ?>
<script>
<?php include __DIR__ . '/includes/editor/editor-drive-sync.js'; ?>

// Auto-flush des sessions éditeur actives depuis la page d'accueil.
// Fonctionne depuis n'importe quelle machine : scan côté serveur, pas de dépendance au localStorage.
(function() {
    fetch('api/editor_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_editor_sessions' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success || !Array.isArray(data.sessions)) return;

        var toResume = data.sessions.filter(function(s) {
            return s.pending_count > 0 || s.local_count > 0;
        });
        if (toResume.length === 0) return;

        // Priorité à la session du localStorage courant si elle figure dans la liste
        var localSessionId = '';
        try { localSessionId = localStorage.getItem('elea_editor_session_id') || ''; } catch(e) {}
        toResume.sort(function(a, b) {
            if (a.session_id === localSessionId) return -1;
            if (b.session_id === localSessionId) return 1;
            return b.last_activity - a.last_activity;
        });

        console.log('[DriveSync/index] ' + toResume.length + ' session(s) avec fichiers en attente');

        var idx = 0;
        function processNext() {
            if (idx >= toResume.length) return;
            var s = toResume[idx++];
            console.log('[DriveSync/index] Reprise ' + s.session_id
                + ' : ' + s.pending_count + ' pending, ' + s.local_count + ' local');

            EditorDriveSync.init(s.session_id);

            if (s.pending_count > 0) {
                EditorDriveSync.flush();
            } else {
                // Fichiers locaux sans pending → resync serveur pour remettre en pending
                fetch('api/editor_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'sync_editor_files',
                        sessionId: s.session_id,
                        files: [],
                        cleanMapped: true
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(d2) {
                    if (d2.cleaned > 0) console.log('[DriveSync/index] Nettoyé ' + d2.cleaned + ' fichier(s) déjà sur Drive');
                    if (d2.pending > 0) EditorDriveSync.flush();
                })
                .catch(function() {});
            }

            if (toResume.length <= 1) return; // Session unique : pas besoin de chaîner

            // Plusieurs sessions : attendre la fin du flush courant avant de passer à la suivante.
            // Délai initial 3 s (temps du 1er batch), puis poll avec gate "vu non-zéro puis revenu à zéro".
            setTimeout(function() {
                var seenNonZero = false;
                var deadline = Date.now() + 600000; // 10 min max par session
                var poller = setInterval(function() {
                    var cnt = EditorDriveSync.getPendingCount();
                    if (cnt > 0) seenNonZero = true;
                    if ((seenNonZero && cnt === 0) || Date.now() > deadline) {
                        clearInterval(poller);
                        processNext();
                    }
                }, 3000);
            }, 3000);
        }

        processNext();
    })
    .catch(function() {});
})();
</script>
<!-- Overlay chargement cours -->
<div class="course-loading-overlay" id="courseLoadingOverlay">
    <div class="course-loading-box">
        <div class="course-loading-spinner"></div>
        <div class="course-loading-title">Ouverture du cours...</div>
        <div class="course-loading-name" id="courseLoadingName"></div>
        <button class="course-loading-cancel" onclick="cancelCourseLoading()">Annuler</button>
    </div>
</div>
<script>
var _courseLoadingNavUrl = null;
var _courseLoadingAbort = null;
function navigateToCourse(url, courseName) {
    _courseLoadingNavUrl = url;
    document.getElementById('courseLoadingName').textContent = courseName || '';
    document.getElementById('courseLoadingOverlay').classList.add('active');
    window.location.href = url;
}
function cancelCourseLoading() {
    document.getElementById('courseLoadingOverlay').classList.remove('active');
    _courseLoadingNavUrl = null;
    if (_courseLoadingAbort) { _courseLoadingAbort.abort(); _courseLoadingAbort = null; }
    window.stop();
}
</script>
</body>
</html>
