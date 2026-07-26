<?php
/**
 * Nettoyage automatique des fichiers temporaires
 * Supprime les brouillons, fichiers uploadés et exports non utilisés après 24h
 */

function _deleteDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    rmdir($dir);
}

/**
 * Nettoie les brouillons de plus de 24h et leurs fichiers associés
 */
function cleanupOldDrafts() {
    $cacheDir = defined('CACHE_DIR') ? CACHE_DIR : __DIR__ . '/../cache';
    $autoDraftsDir = $cacheDir . '/drafts/auto';
    $uploadsDir = $cacheDir . '/editor_uploads';
    
    $maxAge = 24 * 60 * 60;
    $now = time();
    
    // 1. Nettoyer les brouillons auto de plus de 24h
    if (is_dir($autoDraftsDir)) {
        $files = glob($autoDraftsDir . '/*.json');
        foreach ($files as $file) {
            $fileAge = $now - filemtime($file);
            if ($fileAge > $maxAge) {
                $content = @file_get_contents($file);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data) {
                        cleanupDraftFiles($data, $uploadsDir);
                    }
                }
                @unlink($file);
            }
        }
    }
    
    // Aussi nettoyer l'ancien chemin editor_drafts
    $oldDraftsDir = $cacheDir . '/editor_drafts/auto';
    if (is_dir($oldDraftsDir)) {
        $files = glob($oldDraftsDir . '/*.json');
        foreach ($files as $file) {
            if ((time() - filemtime($file)) > $maxAge) @unlink($file);
        }
    }
    
    // 2. Nettoyer les fichiers uploadés orphelins de plus de 24h
    if (is_dir($uploadsDir)) {
        $referencedFiles = [];
        foreach ([$autoDraftsDir, $oldDraftsDir] as $dDir) {
            if (!is_dir($dDir)) continue;
            foreach (glob($dDir . '/*.json') as $draftFile) {
                $content = @file_get_contents($draftFile);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data) {
                        foreach (extractAllUrls($data) as $url) {
                            if (preg_match('/editor_uploads\/([^"\'?\s]+)/', $url, $m)) {
                                $referencedFiles[$m[1]] = true;
                            }
                        }
                    }
                }
            }
        }
        
        foreach (glob($uploadsDir . '/*') as $uploadedFile) {
            if (is_file($uploadedFile)) {
                $filename = basename($uploadedFile);
                if (!isset($referencedFiles[$filename]) && ($now - filemtime($uploadedFile)) > $maxAge) {
                    @unlink($uploadedFile);
                }
            }
        }
    }
    
    // 3. Nettoyer les fichiers d'export de plus de 24h
    cleanupOldExports();
}

/**
 * Nettoie les fichiers d'export (MBZ) de plus de 24h
 */
function cleanupOldExports() {
    $cacheDir = defined('CACHE_DIR') ? CACHE_DIR : __DIR__ . '/../cache';
    $exportsDir = $cacheDir . '/exports';
    $maxAge = 24 * 60 * 60;
    $now = time();
    
    if (!is_dir($exportsDir)) return;
    
    foreach (glob($exportsDir . '/*') as $file) {
        if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
            @unlink($file);
        }
    }
}

/**
 * Supprime les fichiers uploadés référencés dans un brouillon
 */
function cleanupDraftFiles($data, $uploadsDir) {
    foreach (extractAllUrls($data) as $url) {
        if (preg_match('/editor_uploads\/([^"\'?\s]+)/', $url, $m)) {
            $filepath = $uploadsDir . '/' . $m[1];
            if (file_exists($filepath)) @unlink($filepath);
        }
    }
}

/**
 * Extrait toutes les URLs d'un tableau récursivement
 */
function extractAllUrls($data) {
    $urls = [];
    if (is_array($data)) {
        foreach ($data as $value) {
            if (is_string($value)) {
                if (strpos($value, 'editor_uploads') !== false) $urls[] = $value;
                if (preg_match_all('/editor_uploads\/[^"\'?\s]+/', $value, $m)) {
                    $urls = array_merge($urls, $m[0]);
                }
            } elseif (is_array($value)) {
                $urls = array_merge($urls, extractAllUrls($value));
            }
        }
    }
    return array_unique($urls);
}

/**
 * Nettoie les dossiers PDF temporaires de plus de 10 minutes
 */
function cleanupPdfPreviews() {
    if (!defined('COURSES_PATH') || !is_dir(COURSES_PATH)) return;
    $maxAge = 600;
    $now = time();
    
    foreach (scandir(COURSES_PATH) as $item) {
        if (strpos($item, 'pdf-preview-') === 0 && is_dir(COURSES_PATH . '/' . $item)) {
            if (($now - filemtime(COURSES_PATH . '/' . $item)) > $maxAge) {
                _deleteDirectoryRecursive(COURSES_PATH . '/' . $item);
            }
        }
    }
}
