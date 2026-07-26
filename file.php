<?php
/**
 * MoodleSecours - Serveur de fichiers
 * Sert les fichiers des cours (images, PDF, etc.)
 */

require_once __DIR__ . '/config.php';

// Paramètres
$basePath = $_GET['path'] ?? '';
$fileName = $_GET['file'] ?? '';

// Sécurité : vérifie que le chemin est dans les dossiers autorisés
$allowedPaths = [COURSES_PATH, TMP_PATH];
$realBase = realpath($basePath);

$isAllowed = false;
foreach ($allowedPaths as $allowed) {
    if ($realBase && strpos($realBase, realpath($allowed)) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    exit('Accès interdit');
}

// Construit le chemin du fichier
// Le fileName peut être au format "files/xx/hash" ou juste "hash"
if (strpos($fileName, 'files/') === 0) {
    $filePath = $basePath . '/' . $fileName;
} else {
    // C'est un hash, cherche le fichier
    $prefix = substr($fileName, 0, 2);
    $filePath = $basePath . '/files/' . $prefix . '/' . $fileName;
}

$realFile = realpath($filePath);

// Vérifie que le fichier existe et est dans le bon dossier
if (!$realFile || !file_exists($realFile) || strpos($realFile, $realBase) !== 0) {
    http_response_code(404);
    exit('Fichier non trouvé');
}

// Détermine le type MIME
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $realFile);
// finfo_close() supprimé - déprécié en PHP 8.5+

// Types MIME spéciaux
$extension = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$mimeTypes = [
    'svg' => 'image/svg+xml',
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'eot' => 'application/vnd.ms-fontobject',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mp3' => 'audio/mpeg',
    'ogg' => 'audio/ogg',
];

if (isset($mimeTypes[$extension])) {
    $mimeType = $mimeTypes[$extension];
}

// Headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($realFile));
header('Cache-Control: public, max-age=86400'); // Cache 24h
header('Accept-Ranges: bytes');

// Support des requêtes partielles (pour les vidéos)
if (isset($_SERVER['HTTP_RANGE'])) {
    $size = filesize($realFile);
    $start = 0;
    $end = $size - 1;
    
    preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
    $start = intval($matches[1]);
    if (!empty($matches[2])) {
        $end = intval($matches[2]);
    }
    
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
    header('Content-Length: ' . ($end - $start + 1));
    
    $fp = fopen($realFile, 'rb');
    fseek($fp, $start);
    echo fread($fp, $end - $start + 1);
    fclose($fp);
} else {
    // Envoie le fichier complet
    readfile($realFile);
}
