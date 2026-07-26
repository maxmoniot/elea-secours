<?php
/**
 * Script de téléchargement sécurisé
 * Sert le fichier puis le supprime automatiquement
 */

require_once __DIR__ . '/config.php';

// Récupérer le nom du fichier
$filename = $_GET['file'] ?? '';

// Validation stricte du nom de fichier (sécurité)
if (empty($filename) || preg_match('/[\/\\\\]/', $filename) || strpos($filename, '..') !== false) {
    http_response_code(400);
    die('Fichier invalide');
}

// Chemin complet du fichier
$filepath = CACHE_DIR . '/exports/' . $filename;

// Vérifier que le fichier existe et est dans le bon dossier
$realpath = realpath($filepath);
$exportsDir = realpath(CACHE_DIR . '/exports');

if (!$realpath || strpos($realpath, $exportsDir) !== 0 || !is_file($realpath)) {
    http_response_code(404);
    die('Fichier non trouvé');
}

// Déterminer le type MIME
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mimeTypes = [
    'mbz' => 'application/octet-stream',
    'zip' => 'application/zip',
    'tar' => 'application/x-tar',
    'gz' => 'application/gzip',
];
$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

// Envoyer les headers pour le téléchargement
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realpath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Désactiver la mise en tampon pour envoyer directement
if (ob_get_level()) {
    ob_end_clean();
}

// Envoyer le fichier
readfile($realpath);

// Supprimer le fichier après l'envoi
@unlink($realpath);

exit;
