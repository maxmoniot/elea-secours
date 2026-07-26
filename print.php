<?php
/**
 * MoodleSecours - Version imprimable du cours pour génération PDF
 * Affiche TOUT le cours avec toutes les slides pour impression
 */

// Afficher les erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'includes/MbzParser.php';
require_once 'includes/CourseRenderer.php';
require_once 'includes/GoogleDriveLoader.php';

$courseData = null;
$basePath = '';
$baseUrl = '';
$error = null;
$course = null;
$sections = [];

try {
    // Cours depuis Google Drive
    if (isset($_GET['gdrive'])) {
        $gdriveId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['gdrive']);
        
        if (empty($gdriveId)) {
            throw new Exception("ID Google Drive invalide");
        }
        
        $driveLoader = new GoogleDriveLoader();
        $courseData = $driveLoader->loadAndParseCourse($gdriveId);
        
        if (!$courseData) {
            throw new Exception("Impossible de charger le cours depuis Google Drive");
        }
        
        $basePath = $courseData['tmp_path'] ?? '';
        $baseUrl = 'file.php?path=' . urlencode($basePath) . '&file=';
        $course = $courseData['course'] ?? [];
        $sections = $courseData['sections'] ?? [];
        
    } else {
        throw new Exception("Paramètre gdrive manquant");
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

if ($error):
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Erreur - MoodleSecours</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
    <style>
        body { font-family: Arial, sans-serif; padding: 2rem; text-align: center; }
        .error { color: #c00; background: #fee; padding: 2rem; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="error">
        <h2>❌ Erreur</h2>
        <p><?= htmlspecialchars($error) ?></p>
        <p><a href="javascript:window.close()">Fermer cette fenêtre</a></p>
    </div>
</body>
</html>
<?php
exit;
endif;

// Créer le renderer en mode print
$renderer = new CourseRenderer($courseData, $basePath, $baseUrl);
$renderer->setPrintMode(true);

// Collecte de toutes les activités pour le sommaire
$allActivities = [];
$activityIndex = 1;
foreach ($sections as $sIndex => $section) {
    foreach ($section['activities'] ?? [] as $aIndex => $activity) {
        $allActivities[] = [
            'section_index' => $sIndex,
            'section_name' => !empty($section['name']) ? $section['name'] : 'Section ' . ($section['number'] + 1),
            'activity_index' => $aIndex,
            'activity_name' => $activity['name'] ?? 'Activité',
            'activity' => $activity,
            'number' => $activityIndex++
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($course['course_fullname'] ?? 'Cours') ?> - Version imprimable</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🆘</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
    /* === RESET & BASE === */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 11pt;
        line-height: 1.5;
        color: #333;
        background: white;
    }
    
    /* === ÉCRAN: Interface de préparation === */
    @media screen {
        body { background: #f5f5f5; padding: 2rem; }
        
        .print-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .print-controls h1 {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .print-controls-buttons {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .btn-print {
            padding: 0.6rem 1.5rem;
            background: white;
            color: #5b21b6;
            border: none;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-print:hover { background: #f0f0f0; }
        
        .btn-close {
            padding: 0.6rem 1rem;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .btn-close:hover { background: rgba(255,255,255,0.3); }
        
        .print-container {
            margin-top: 80px;
            max-width: 210mm;
            margin-left: auto;
            margin-right: auto;
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .print-hint {
            text-align: center;
            padding: 1rem;
            background: #fff3cd;
            border-radius: 8px;
            margin-bottom: 1rem;
            max-width: 210mm;
            margin-left: auto;
            margin-right: auto;
        }
    }
    
    /* === IMPRESSION: Pages A4 === */
    @media print {
        .print-controls, .print-hint { display: none !important; }
        
        body { background: white; padding: 0; }
        
        @page {
            size: A4;
            margin: 15mm 15mm 20mm 15mm;
        }
        
        .print-container {
            margin: 0;
            max-width: none;
            box-shadow: none;
        }
        
        .page-break { page-break-before: always; }
        .avoid-break { page-break-inside: avoid; }
    }
    
    /* === EN-TÊTE DU DOCUMENT === */
    .doc-header {
        background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
        color: white;
        padding: 2rem;
        text-align: center;
    }
    
    .doc-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    
    .doc-header .doc-meta {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    /* === SOMMAIRE === */
    .toc {
        padding: 2rem;
        border-bottom: 2px solid #e0e0e0;
    }
    
    .toc h2 {
        font-size: 1.2rem;
        color: #5b21b6;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #5b21b6;
    }
    
    .toc-section {
        margin-bottom: 1rem;
    }
    
    .toc-section-title {
        font-weight: 600;
        color: #333;
        margin-bottom: 0.5rem;
        padding: 0.5rem;
        background: #f5f5f5;
        border-radius: 4px;
    }
    
    .toc-items {
        list-style: none;
        padding-left: 1.5rem;
    }
    
    .toc-item {
        padding: 0.3rem 0;
        color: #555;
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
    }
    
    .toc-number {
        color: #5b21b6;
        font-weight: 500;
        min-width: 2rem;
    }
    
    /* === CONTENU DES ACTIVITÉS === */
    .activity-print {
        padding: 2rem;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .activity-print:last-child {
        border-bottom: none;
    }
    
    .activity-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #5b21b6;
    }
    
    .activity-number {
        background: #5b21b6;
        color: white;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }
    
    .activity-info h3 {
        font-size: 1.1rem;
        color: #333;
        margin-bottom: 0.25rem;
    }
    
    .activity-info .section-name {
        font-size: 0.85rem;
        color: #888;
    }
    
    .activity-content {
        line-height: 1.6;
    }
    
    /* === STYLES POUR LE CONTENU H5P === */
    .h5p-cp-container {
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 1rem;
    }
    
    /* CoursePresentation mode print */
    .h5p-coursepresentation-print {
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        overflow: hidden;
    }
    
    .h5p-cp-slide-print {
        padding: 1.5rem;
        border-bottom: 1px solid #e0e0e0;
        page-break-inside: avoid;
    }
    
    .h5p-cp-slide-print:last-child {
        border-bottom: none;
    }
    
    .slide-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .slide-number {
        background: #7c3aed;
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    .slide-content {
        line-height: 1.6;
    }
    
    .h5p-cp-element-print {
        margin-bottom: 1rem;
    }
    
    .h5p-cp-element-print:last-child {
        margin-bottom: 0;
    }
    
    .h5p-cp-text {
        font-size: 11pt;
        line-height: 1.6;
    }
    
    .h5p-cp-text p {
        margin-bottom: 0.5rem;
    }
    
    .h5p-cp-image-print {
        max-width: 100%;
        height: auto;
        border-radius: 4px;
        margin: 0.5rem 0;
    }
    
    /* Images */
    .h5p-cp-slide img, .activity-content img {
        max-width: 100%;
        height: auto;
        border-radius: 4px;
    }
    
    /* Texte */
    .h5p-text-content, .h5p-advancedtext {
        font-size: 11pt;
        line-height: 1.6;
    }
    
    .h5p-text-content p, .h5p-advancedtext p {
        margin-bottom: 0.75rem;
    }
    
    /* Questions interactives */
    .h5p-question-print {
        background: #f8f8ff;
        border: 1px solid #d0d0e0;
        border-radius: 8px;
        padding: 1rem;
        margin: 1rem 0;
    }
    
    .h5p-question-print h4 {
        color: #5b21b6;
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
    }
    
    .h5p-options-print {
        list-style: none;
        padding: 0;
    }
    
    .h5p-options-print li {
        padding: 0.5rem 0.75rem;
        margin-bottom: 0.25rem;
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
    }
    
    .h5p-options-print li.correct-answer {
        background: #e8f5e9;
        border-color: #4caf50;
        font-weight: 500;
    }
    
    .question-text {
        margin-bottom: 0.75rem;
        line-height: 1.5;
    }
    
    .blank-answer {
        background: #e3f2fd;
        padding: 0.1rem 0.4rem;
        border-radius: 3px;
        font-weight: 500;
        color: #1565c0;
    }
    
    /* Masquer les éléments interactifs non pertinents pour l'impression */
    .h5p-cp-nav, .h5p-cp-footer, .h5p-cp-progress, .check-button, 
    .h5p-dq-check-button, .h5p-blanks-check, .h5p-mc-check {
        display: none !important;
    }
    
    /* Indicateur de slide */
    .h5p-cp-indicator {
        display: inline-block;
        background: #ffc107;
        color: #333;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        margin-left: 0.5rem;
    }
    
    /* Labels et URLs */
    .label-content, .url-content {
        padding: 1rem;
        background: #f5f5f5;
        border-radius: 8px;
        margin: 0.5rem 0;
    }
    
    /* Pied de page document */
    .doc-footer {
        padding: 1.5rem 2rem;
        background: #f5f5f5;
        text-align: center;
        font-size: 0.85rem;
        color: #888;
    }
    </style>
</head>
<body>
    <!-- Contrôles d'impression (visible uniquement à l'écran) -->
    <div class="print-controls">
        <h1>📄 Aperçu avant impression</h1>
        <div class="print-controls-buttons">
            <button class="btn-print" onclick="window.print()">
                🖨️ Imprimer / Enregistrer PDF
            </button>
            <button class="btn-close" onclick="window.close()">
                ✕ Fermer
            </button>
        </div>
    </div>
    
    <div class="print-hint">
        💡 <strong>Astuce :</strong> Dans la boîte de dialogue d'impression, choisissez "Enregistrer au format PDF" comme destination pour créer un fichier PDF.
    </div>
    
    <div class="print-container">
        <!-- En-tête du document -->
        <div class="doc-header">
            <h1><?= htmlspecialchars($course['course_fullname'] ?? 'Cours') ?></h1>
            <div class="doc-meta">
                Généré le <?= date('d/m/Y à H:i') ?> • <?= count($allActivities) ?> activité(s)
            </div>
        </div>
        
        <!-- Sommaire -->
        <div class="toc">
            <h2>📚 Sommaire</h2>
            <?php 
            $currentSection = null;
            foreach ($allActivities as $item): 
                if ($currentSection !== $item['section_index']):
                    if ($currentSection !== null) echo '</ul></div>';
                    $currentSection = $item['section_index'];
            ?>
            <div class="toc-section">
                <div class="toc-section-title"><?= htmlspecialchars($item['section_name']) ?></div>
                <ul class="toc-items">
            <?php endif; ?>
                    <li class="toc-item">
                        <span class="toc-number"><?= $item['number'] ?>.</span>
                        <span><?= htmlspecialchars($item['activity_name']) ?></span>
                    </li>
            <?php endforeach; ?>
            <?php if ($currentSection !== null) echo '</ul></div>'; ?>
        </div>
        
        <!-- Contenu des activités -->
        <?php foreach ($allActivities as $item): ?>
        <div class="activity-print page-break" id="activity-<?= $item['number'] ?>">
            <div class="activity-header avoid-break">
                <div class="activity-number"><?= $item['number'] ?></div>
                <div class="activity-info">
                    <h3><?= htmlspecialchars($item['activity_name']) ?></h3>
                    <div class="section-name"><?= htmlspecialchars($item['section_name']) ?></div>
                </div>
            </div>
            <div class="activity-content">
                <?= $renderer->renderSingleActivity($item['activity']) ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Pied de page -->
        <div class="doc-footer">
            <?= htmlspecialchars($course['course_fullname'] ?? 'Cours') ?> • Généré par MoodleSecours
        </div>
    </div>
    
    <script>
    // Auto-scroll vers le haut au chargement
    window.onload = function() {
        window.scrollTo(0, 0);
    };
    </script>
</body>
</html>
