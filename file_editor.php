<?php
/**
 * MoodleSecours - Serveur de fichiers éditeur
 * Sert les images depuis cache/editor_uploads/ par nom de fichier.
 * Utilisé par la prévisualisation PDF depuis l'éditeur.
 */

require_once __DIR__ . '/config.php';

$filename = $_GET['file'] ?? '';
if (!$filename) {
    http_response_code(400);
    exit('Fichier non spécifié');
}

// Sécurité : nettoyer le nom de fichier (pas de traversée de répertoire)
$filename = basename($filename);

// Chercher dans editor_uploads
$filePath = CACHE_DIR . '/editor_uploads/' . $filename;

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    exit('Fichier non trouvé');
}

// Déterminer le type MIME
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'mp4' => 'video/mp4',
    'mp3' => 'audio/mpeg',
    'pdf' => 'application/pdf',
];

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=3600');
readfile($filePath);
