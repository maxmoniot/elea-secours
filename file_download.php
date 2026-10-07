<?php
/**
 * Téléchargement d'un fichier de cours SOUS SON VRAI NOM (activités Fichier, Dossier, Devoir).
 *
 *   file_download.php?c=<identifiant du cours>&t=<permanent|temp>&h=<empreinte sha1>&n=<nom du fichier>
 *
 * Pourquoi un point d'entrée à part :
 *  - un cours servi depuis Google Drive y range ses fichiers sous leur EMPREINTE, sans
 *    extension. Un lien direct vers Drive livrait donc « 415317b1….txt » au lieu du .hex
 *    (Drive déduit l'extension du type text/plain), et le navigateur ignore l'attribut
 *    `download` d'un lien vers un autre domaine ;
 *  - un cours tout juste déposé est d'abord servi depuis le serveur, puis part sur Drive et
 *    son dossier local est supprimé : un lien construit sur la copie locale répondait 404
 *    dès la fin de l'envoi, sans que la page soit rechargée.
 * Ici le fichier est cherché AU MOMENT DU CLIC : copie locale si elle existe encore, sinon
 * Drive (index du cours), et toujours renvoyé avec son nom d'origine. Même domaine que la
 * page : le « tout télécharger » d'un Dossier peut en lire les octets pour faire le ZIP.
 *
 * Rien n'est écrit sur le serveur. Pas de session : ce point d'entrée ne la lit pas, il ne
 * doit donc pas la verrouiller (les élèves arrivent par un code, sans session professeur).
 * Seuls les fichiers DU cours désigné sont servis : empreinte présente dans son dossier
 * local ou dans son index Drive.
 */

require_once __DIR__ . '/config.php';

$cours = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['c'] ?? ''));
$type  = (($_GET['t'] ?? '') === 'temp') ? 'temp' : 'permanent';
$hash  = strtolower((string)($_GET['h'] ?? ''));
$nom   = nomDeTelechargement((string)($_GET['n'] ?? ''), $hash);

if ($cours === '' || !preg_match('/^[0-9a-f]{40}$/', $hash)) {
    http_response_code(400);
    exit('Paramètres invalides');
}

// 1. Copie locale : cours déposé pas encore (ou pas entièrement) parti sur Drive
$dossierLocal = ($type === 'temp')
    ? COURSES_PATH . '/' . $cours
    : TMP_PATH . '/course_' . md5($cours);
$local = $dossierLocal . '/files/' . substr($hash, 0, 2) . '/' . $hash;
if (is_file($local)) {
    envoyerEntetes($nom, $hash, filesize($local));
    readfile($local);
    exit;
}

// 2. Copie Drive : l'index du cours donne l'identifiant Drive de chaque empreinte
$index = ($type === 'temp')
    ? DRIVE_INDEX_DIR . '/temp_' . $cours . '.json'
    : DRIVE_INDEX_DIR . '/' . $cours . '.json';
$driveId = null;
if (is_file($index)) {
    $donnees = json_decode((string)file_get_contents($index), true);
    $driveId = $donnees['files'][$hash] ?? null;
}
if (!$driveId || !preg_match('/^[a-zA-Z0-9_-]+$/', (string)$driveId)) {
    http_response_code(404);
    error_log("file_download.php: $hash introuvable pour le cours $cours ($type)");
    exit('Fichier introuvable');
}

@set_time_limit(300);
$contenu = null;
try {
    require_once ROOT_PATH . '/DriveManager.php';
    $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, ROOT_PATH . '/vendor/autoload.php');
    $contenu = $dm->getFileContentById($driveId);
} catch (\Throwable $e) {
    error_log("file_download.php: Drive indisponible pour $driveId : " . $e->getMessage());
}
if ($contenu === null) {
    http_response_code(502);
    exit('Fichier momentanément indisponible, réessayez dans un instant.');
}
envoyerEntetes($nom, $hash, strlen($contenu));
echo $contenu;
exit;

/**
 * Nom proposé au navigateur : celui d'origine, sans chemin ni caractère de contrôle.
 */
function nomDeTelechargement(string $nom, string $hash): string {
    $nom = basename(str_replace('\\', '/', $nom));
    $nom = preg_replace('/[\x00-\x1F\x7F]/u', '', $nom) ?? '';
    $nom = str_replace(['<', '>', ':', '"', '|', '?', '*'], '_', $nom);   // interdits sous Windows
    $nom = trim($nom);
    return ($nom === '' || $nom === '.' || $nom === '..') ? ($hash !== '' ? $hash : 'fichier') : $nom;
}

function envoyerEntetes(string $nom, string $hash, int $taille): void {
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    $types = [
        'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];
    // Tout le reste (.hex, .sb3, .sh3d, .json de Filius, .stl…) en binaire : le navigateur
    // n'a rien à interpréter, il enregistre le fichier tel quel.
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $nom);
    $ascii = str_replace(['"', '\\'], '_', $ascii);
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
    header('Content-Length: ' . $taille);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('ETag: "' . $hash . '"');
}
