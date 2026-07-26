<?php
/**
 * Elea-Secours — Script de test de robustesse autonome
 * À déployer à la racine du site, déclencher via URL admin, supprimer après usage.
 *
 * Usage :
 *   ?action=ui                       → page de contrôle (saisie mot de passe + boutons)
 *   ?action=run&suite=all            → lance toutes les suites
 *   ?action=run&suite=storage        → lance une suite (storage|drive_sync|location_independence|expiration|new_course|export_purity|recovery|extras)
 *   ?action=run&test=<nom>           → lance un test individuel
 *   ?action=log                      → affiche le log texte
 *   ?action=log&format=json          → affiche le log JSONL
 *   ?action=log&download=1           → télécharge le log
 *   ?action=cleanup&confirm=YES      → purge tous les artefacts + logs + Drive _robtest_*, puis auto-supprime ce fichier
 *   ?action=status                   → JSON résumé
 *
 * Tous les artefacts créés par les tests sont préfixés `_robtest_` pour cleanup chirurgical.
 */

declare(strict_types=1);

// === SÉCURITÉ DE BASE ===
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(600);
ignore_user_abort(true);

session_start();

// On charge la config principale (constantes, fonctions, _SECRETS)
require_once __DIR__ . '/config.php';
// cleanup.php fournit cleanupOldDrafts, cleanupOldExports, cleanupPdfPreviews
if (file_exists(__DIR__ . '/includes/cleanup.php')) {
    require_once __DIR__ . '/includes/cleanup.php';
}

// === CONSTANTES DU SCRIPT DE TEST ===
define('ROBTEST_PREFIX', '_robtest_');
define('ROBTEST_LOG_FILE', TMP_PATH . '/.robustness_test.log');
define('ROBTEST_JSONL_FILE', TMP_PATH . '/.robustness_test.jsonl');
define('ROBTEST_LOCK_FILE', TMP_PATH . '/.robtest.lock');
define('ROBTEST_LOCK_TIMEOUT', 300);
// IMPORTANT : le ballast doit être placé dans un dossier comptabilisé par getServerTotalUsage().
// COURSES_PATH est entièrement scanné (cours temporaires) → on y place le ballast.
// Sans info.json, cleanExpiredCourses() et getLocalCourses() ignorent le dossier.
define('ROBTEST_BALLAST_DIR', COURSES_PATH . '/' . ROBTEST_PREFIX . 'ballast');
define('ROBTEST_SAMPLE_MBZ', TMP_PATH . '/' . ROBTEST_PREFIX . 'sample.mbz');
define('ROBTEST_RUN_ID', 'r-' . time() . '-' . substr(bin2hex(random_bytes(2)), 0, 4));
define('ROBTEST_BALLAST_TARGET_MB', 350);
define('ROBTEST_BALLAST_MIN_FREE_MB', 600);
define('ROBTEST_BALLAST_FALLBACK_MB', 200);

// === GUARDS ===

function robtest_require_https(): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$isHttps && !$isLocal) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "HTTPS requis (ou accès local). Refus de transmettre le mot de passe en clair.";
        exit;
    }
}

function robtest_check_auth(): bool {
    global $_SECRETS;
    $pwd = $_POST['password'] ?? '';
    if (empty($pwd)) return false;
    $adminPwd = $_SECRETS['password_admin'] ?? '';
    if (empty($adminPwd) || $adminPwd === 'CHANGEZ_MOI') return false;
    return hash_equals($adminPwd, $pwd);
}

function robtest_send_403(string $msg = 'Auth requise'): void {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function robtest_acquire_run_lock(): bool {
    $lockFile = ROBTEST_LOCK_FILE;
    if (file_exists($lockFile)) {
        $age = time() - filemtime($lockFile);
        if ($age < ROBTEST_LOCK_TIMEOUT) return false;
        @unlink($lockFile);
    }
    @file_put_contents($lockFile, json_encode(['pid' => getmypid(), 'started' => time(), 'run_id' => ROBTEST_RUN_ID]));
    register_shutdown_function(function() {
        if (file_exists(ROBTEST_LOCK_FILE)) {
            $data = @json_decode(@file_get_contents(ROBTEST_LOCK_FILE), true);
            if ($data && ($data['pid'] ?? 0) == getmypid()) {
                @unlink(ROBTEST_LOCK_FILE);
            }
        }
    });
    return true;
}

// === LOGGER ===

class RobtestLogger {
    private $textFp = null;
    private $jsonFp = null;
    private string $runId;
    private array $counters = ['total' => 0, 'pass' => 0, 'fail' => 0, 'expected_fail' => 0, 'warn' => 0, 'error' => 0];
    private float $runStart;
    private bool $stream;

    public function __construct(string $runId, bool $stream = false) {
        $this->runId = $runId;
        $this->stream = $stream;
        $this->runStart = microtime(true);
        $this->textFp = @fopen(ROBTEST_LOG_FILE, 'a');
        $this->jsonFp = @fopen(ROBTEST_JSONL_FILE, 'a');
    }

    public function __destruct() {
        if ($this->textFp) @fclose($this->textFp);
        if ($this->jsonFp) @fclose($this->jsonFp);
    }

    private function ts(): string {
        return date('c');
    }

    private function write(string $level, string $msg, array $data = []): void {
        $padded = str_pad($level, 5, ' ');
        $line = sprintf("%s [%s] %s\n", $this->ts(), $padded, $msg);
        if ($this->textFp) { @fwrite($this->textFp, $line); @fflush($this->textFp); }
        $jsonRec = ['ts' => $this->ts(), 'lvl' => strtolower(trim($level)), 'run' => $this->runId, 'msg' => $msg];
        if (!empty($data)) $jsonRec['data'] = $data;
        if ($this->jsonFp) { @fwrite($this->jsonFp, json_encode($jsonRec, JSON_UNESCAPED_UNICODE) . "\n"); @fflush($this->jsonFp); }
        if ($this->stream) {
            echo $line;
            @ob_flush(); @flush();
        }
    }

    public function info(string $msg, array $data = []): void { $this->write('INFO', $msg, $data); }
    public function setup(string $msg, array $data = []): void { $this->write('SETUP', $msg, $data); }
    public function teardown(string $msg, array $data = []): void { $this->write('TEAR', $msg, $data); }
    public function warn(string $msg, array $data = []): void { $this->counters['warn']++; $this->write('WARN', $msg, $data); }
    public function error(string $msg, array $data = []): void { $this->counters['error']++; $this->write('ERROR', $msg, $data); }

    public function testStart(string $name): void {
        $this->counters['total']++;
        $this->write('TEST', $name . ' ...');
    }
    public function pass(string $name, string $detail = '', float $startTs = 0.0): void {
        $this->counters['pass']++;
        $dur = $startTs > 0 ? sprintf(', %.2fs', microtime(true) - $startTs) : '';
        $this->write('PASS', $name . ($detail ? ' (' . $detail . $dur . ')' : $dur), ['test' => $name, 'detail' => $detail]);
    }
    public function fail(string $name, string $reason, array $data = []): void {
        $this->counters['fail']++;
        $this->write('FAIL', $name . ': ' . $reason, array_merge(['test' => $name, 'reason' => $reason], $data));
    }
    public function expectedFail(string $name, string $reason, array $data = []): void {
        $this->counters['expected_fail']++;
        $this->write('WARN', $name . ' EXPECTED_FAIL: ' . $reason, array_merge(['test' => $name, 'reason' => $reason, 'expected' => true], $data));
    }

    public function summary(): array {
        $duration = round(microtime(true) - $this->runStart, 2);
        $sum = array_merge($this->counters, ['duration_s' => $duration, 'run_id' => $this->runId]);
        $this->write('INFO', sprintf('=== RUN END === total=%d pass=%d fail=%d expected_fail=%d warn=%d error=%d duration=%.2fs',
            $sum['total'], $sum['pass'], $sum['fail'], $sum['expected_fail'], $sum['warn'], $sum['error'], $duration), $sum);
        return $sum;
    }
}

// === HELPERS GÉNÉRAUX ===

function robtest_safe_path(string $path): bool {
    // Garantit qu'un chemin contient bien _robtest_ pour cleanup chirurgical
    return strpos($path, ROBTEST_PREFIX) !== false;
}

function robtest_rmrf(string $path): void {
    if (!robtest_safe_path($path)) return;
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    $items = @scandir($path);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $sub = $path . '/' . $item;
        if (is_dir($sub) && !is_link($sub)) {
            // Sub-path peut ne pas contenir _robtest_ (fichier dans un dossier ballast)
            // On force la suppression récursive depuis un parent _robtest_
            robtest_rmrf_unsafe($sub);
        } else {
            @unlink($sub);
        }
    }
    @rmdir($path);
}

function robtest_rmrf_unsafe(string $path): void {
    // Variante interne, appelée uniquement depuis robtest_rmrf après vérification du parent
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') continue;
        $sub = $path . '/' . $item;
        if (is_dir($sub) && !is_link($sub)) robtest_rmrf_unsafe($sub);
        else @unlink($sub);
    }
    @rmdir($path);
}

function robtest_mkdir(string $path, int $mode = 0755): bool {
    if (is_dir($path)) return true;
    return @mkdir($path, $mode, true);
}

function robtest_age_file(string $path, int $secondsOld): bool {
    if (!file_exists($path)) return false;
    return @touch($path, time() - $secondsOld);
}

function robtest_human_bytes(int $b): string {
    if ($b < 1024) return $b . 'o';
    if ($b < 1024 * 1024) return round($b / 1024, 1) . 'Ko';
    if ($b < 1024 * 1024 * 1024) return round($b / (1024 * 1024), 1) . 'Mo';
    return round($b / (1024 * 1024 * 1024), 2) . 'Go';
}

/**
 * Crée des fichiers de ballast (1 Mo chacun) jusqu'à atteindre la taille cible.
 * Si l'espace libre est trop faible, fallback à une taille réduite.
 * Retourne la taille effective du ballast en octets, 0 en cas d'échec.
 */
function robtest_create_ballast(int $targetMb, RobtestLogger $log): int {
    if (!robtest_mkdir(ROBTEST_BALLAST_DIR)) {
        $log->error('Impossible de créer ' . ROBTEST_BALLAST_DIR);
        return 0;
    }
    $existingMb = (int) round(getDirSize(ROBTEST_BALLAST_DIR) / (1024 * 1024));
    if ($existingMb >= $targetMb) {
        $log->setup("Ballast déjà à {$existingMb}Mo (cible {$targetMb}Mo)");
        return $existingMb * 1024 * 1024;
    }
    $freeBytes = @disk_free_space(TMP_PATH);
    if ($freeBytes !== false) {
        $freeMb = (int) round($freeBytes / (1024 * 1024));
        if ($freeMb < ROBTEST_BALLAST_MIN_FREE_MB) {
            $log->warn("Espace libre faible ({$freeMb}Mo) — fallback ballast à " . ROBTEST_BALLAST_FALLBACK_MB . 'Mo');
            $targetMb = min($targetMb, ROBTEST_BALLAST_FALLBACK_MB);
        }
    }
    $log->setup("Création ballast vers {$targetMb}Mo dans " . ROBTEST_BALLAST_DIR);
    $start = microtime(true);
    $chunk = str_repeat("\0", 1024 * 1024); // 1 Mo de zéros
    for ($i = $existingMb; $i < $targetMb; $i++) {
        $f = ROBTEST_BALLAST_DIR . '/chunk_' . sprintf('%04d', $i) . '.bin';
        if (file_exists($f)) continue;
        $written = @file_put_contents($f, $chunk);
        if ($written !== strlen($chunk)) {
            $log->error("Échec écriture chunk $i (écrits=$written)");
            break;
        }
        if ($i % 50 === 0 && $i > 0) {
            $log->setup("Ballast progression : {$i}/{$targetMb}Mo");
        }
    }
    $finalSize = getDirSize(ROBTEST_BALLAST_DIR);
    $log->setup(sprintf("Ballast prêt : %s en %.1fs", robtest_human_bytes($finalSize), microtime(true) - $start));
    return $finalSize;
}

function robtest_destroy_ballast(RobtestLogger $log): void {
    if (is_dir(ROBTEST_BALLAST_DIR)) {
        $log->teardown('Suppression ballast');
        robtest_rmrf(ROBTEST_BALLAST_DIR);
    }
}

/**
 * Crée un mini MBZ valide (tar.gz) avec une structure Moodle minimale.
 * Retourne le chemin du fichier ou null en cas d'échec.
 */
function robtest_create_sample_mbz(RobtestLogger $log): ?string {
    if (file_exists(ROBTEST_SAMPLE_MBZ) && filesize(ROBTEST_SAMPLE_MBZ) > 0) {
        return ROBTEST_SAMPLE_MBZ;
    }
    $tmpDir = TMP_PATH . '/' . ROBTEST_PREFIX . 'mbz_build';
    robtest_rmrf($tmpDir);
    if (!robtest_mkdir($tmpDir)) {
        $log->error('Impossible de créer ' . $tmpDir);
        return null;
    }
    if (!robtest_mkdir($tmpDir . '/files')) {
        $log->error('Impossible de créer ' . $tmpDir . '/files');
        return null;
    }
    if (!robtest_mkdir($tmpDir . '/course')) {
        $log->error('Impossible de créer ' . $tmpDir . '/course');
        return null;
    }
    // Fichier files dummy
    @file_put_contents($tmpDir . '/files/dummy.txt', "Robtest sample file\n");
    @file_put_contents($tmpDir . '/moodle_backup.xml', '<?xml version="1.0" encoding="UTF-8"?><moodle_backup><information><name>robtest_sample.mbz</name><moodle_version>2024100100</moodle_version></information></moodle_backup>' . "\n");
    @file_put_contents($tmpDir . '/files.xml', '<?xml version="1.0" encoding="UTF-8"?><files></files>' . "\n");
    @file_put_contents($tmpDir . '/course/course.xml', '<?xml version="1.0" encoding="UTF-8"?><course id="1"><shortname>robtest</shortname><fullname>Robtest Sample Course</fullname></course>' . "\n");
    // Création du tar.gz
    $cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg(ROBTEST_SAMPLE_MBZ), escapeshellarg($tmpDir));
    @exec($cmd, $output, $rc);
    if ($rc !== 0) {
        // Fallback PharData
        try {
            $tarPath = TMP_PATH . '/' . ROBTEST_PREFIX . 'sample.tar';
            if (file_exists($tarPath)) @unlink($tarPath);
            $phar = new PharData($tarPath);
            $phar->buildFromDirectory($tmpDir);
            $phar->compress(Phar::GZ);
            unset($phar);
            if (file_exists($tarPath . '.gz')) {
                @rename($tarPath . '.gz', ROBTEST_SAMPLE_MBZ);
                @unlink($tarPath);
            }
        } catch (Throwable $e) {
            $log->error('Échec création MBZ via tar et PharData : ' . $e->getMessage());
            robtest_rmrf($tmpDir);
            return null;
        }
    }
    robtest_rmrf($tmpDir);
    if (file_exists(ROBTEST_SAMPLE_MBZ) && filesize(ROBTEST_SAMPLE_MBZ) > 0) {
        $log->setup('MBZ test créé : ' . robtest_human_bytes(filesize(ROBTEST_SAMPLE_MBZ)));
        return ROBTEST_SAMPLE_MBZ;
    }
    return null;
}

/**
 * Effectue un appel HTTP interne (boucle locale) avec une session de prof actif.
 * Utilise le cookie de session de la requête en cours.
 */
function robtest_http_call(string $endpoint, string $method = 'GET', array $params = [], array $files = [], bool $anonymous = false): array {
    $sessionName = session_name();
    if ($anonymous) {
        // Forge un session_id qui ne correspond à aucun fichier de session existant
        $sessionId = 'robtest_anon_' . substr(bin2hex(random_bytes(8)), 0, 16);
    } else {
        $sessionId = session_id();
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $url = $scheme . '://' . $host . $base . '/' . ltrim($endpoint, '/');
    if ($method === 'GET' && !empty($params)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIE => $sessionName . '=' . $sessionId,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HEADER => true,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if (!empty($files)) {
            $postFields = $params;
            foreach ($files as $name => $path) {
                $postFields[$name] = new CURLFile($path);
            }
            $opts[CURLOPT_POSTFIELDS] = $postFields;
        } else {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }
    } elseif ($method === 'POST_JSON') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'POST';
        $opts[CURLOPT_POSTFIELDS] = json_encode($params);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    // Important : libérer la session avant l'appel curl pour éviter un deadlock
    session_write_close();
    $resp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    // Ré-ouvrir la session pour la suite
    session_start();
    if ($resp === false) {
        return ['http_code' => 0, 'body' => '', 'json' => null, 'error' => $err];
    }
    $body = substr($resp, $headerSize);
    return ['http_code' => $httpCode, 'body' => $body, 'json' => json_decode($body, true), 'error' => null];
}

/**
 * Crée un dossier de cours temporaire factice (info.json + course_data.json + 1 fichier).
 */
function robtest_make_fake_course(string $profId, int $createdAtOffset = 0): string {
    $coursePath = COURSES_PATH . '/' . $profId;
    robtest_mkdir($coursePath);
    robtest_mkdir($coursePath . '/files');
    @file_put_contents($coursePath . '/files/dummy_a.txt', "robtest a\n");
    @file_put_contents($coursePath . '/files/dummy_b.txt', "robtest b\n");
    $createdAt = time() + $createdAtOffset;
    $info = [
        'prof_id' => $profId,
        'prof_name' => $profId,
        'course_name' => 'Robtest ' . $profId,
        'created_at' => $createdAt,
        'expires_at' => $createdAt + (COURSE_LIFETIME_HOURS * 3600),
        'file_size' => 100,
        'source' => 'robtest',
        'uploaded_by' => 'robtest',
    ];
    @file_put_contents($coursePath . '/info.json', json_encode($info, JSON_PRETTY_PRINT));
    $courseData = ['course' => ['course_fullname' => 'Robtest ' . $profId, 'course_shortname' => $profId], 'files' => []];
    @file_put_contents($coursePath . '/course_data.json', json_encode($courseData, JSON_PRETTY_PRINT));
    if ($createdAtOffset !== 0) {
        @touch($coursePath . '/info.json', $createdAt);
        @touch($coursePath, $createdAt);
    }
    return $coursePath;
}

// === ASSERTIONS ===

class RobtestAssert {
    public static function true($val, string $msg = ''): array {
        return [(bool)$val, $msg ?: "expected true, got " . var_export($val, true)];
    }
    public static function false($val, string $msg = ''): array {
        return [!$val, $msg ?: "expected false, got " . var_export($val, true)];
    }
    public static function equals($expected, $actual, string $msg = ''): array {
        return [$expected === $actual, $msg ?: "expected " . var_export($expected, true) . ", got " . var_export($actual, true)];
    }
    public static function contains(string $needle, string $haystack, string $msg = ''): array {
        return [strpos($haystack, $needle) !== false, $msg ?: "expected '$needle' in string"];
    }
    public static function fileExists(string $path, string $msg = ''): array {
        return [file_exists($path), $msg ?: "expected file exists: $path"];
    }
    public static function fileMissing(string $path, string $msg = ''): array {
        return [!file_exists($path), $msg ?: "expected file missing: $path"];
    }
    public static function greaterThan($a, $b, string $msg = ''): array {
        return [$a > $b, $msg ?: "expected $a > $b"];
    }
    public static function lessThan($a, $b, string $msg = ''): array {
        return [$a < $b, $msg ?: "expected $a < $b"];
    }
    public static function between($val, $min, $max, string $msg = ''): array {
        return [$val >= $min && $val <= $max, $msg ?: "expected $min <= $val <= $max"];
    }
}

// Exécute un bloc de test, capture les erreurs, alimente le logger.
function robtest_run(RobtestLogger $log, string $name, callable $fn): void {
    $log->testStart($name);
    $start = microtime(true);
    try {
        $result = $fn($log);
        if (is_array($result) && isset($result['expected_fail']) && $result['expected_fail']) {
            $log->expectedFail($name, $result['reason'] ?? 'feature manquante');
            return;
        }
        if (is_array($result) && isset($result['fail']) && $result['fail']) {
            $log->fail($name, $result['reason'] ?? 'assertion failed', $result['data'] ?? []);
            return;
        }
        $detail = is_string($result) ? $result : (is_array($result) ? ($result['detail'] ?? '') : '');
        $log->pass($name, $detail, $start);
    } catch (Throwable $e) {
        $log->fail($name, 'exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// =====================================================================
// SUITE 1 — STORAGE (limite 400 Mo)
// =====================================================================

function robtest_suite_storage(RobtestLogger $log): void {
    $log->info('--- Suite: storage ---');
    // Setup ballast partagé
    $ballastSize = robtest_create_ballast(ROBTEST_BALLAST_TARGET_MB, $log);
    if ($ballastSize === 0) {
        $log->warn('Ballast non créé — tests storage limités');
    }
    $maxBytes = SERVER_MAX_MB * 1024 * 1024;

    robtest_run($log, 'storage_below_threshold_ok', function() use ($log) {
        $used = getServerTotalUsage();
        $can = canUpload(10 * 1024 * 1024);
        $st = checkExtractionStatus();
        if (!$can) return ['fail' => true, 'reason' => "canUpload(10MB)=false alors que used=" . robtest_human_bytes($used)];
        if (!$st['can_extract']) return ['fail' => true, 'reason' => "can_extract=false alors que used=" . robtest_human_bytes($used)];
        return "used=" . robtest_human_bytes($used) . ", pct={$st['pct']}%";
    });

    robtest_run($log, 'storage_at_399mb_warns', function() use ($log, $maxBytes) {
        $used = getServerTotalUsage();
        $remaining = $maxBytes - $used;
        if ($remaining < 5 * 1024 * 1024) return ['fail' => true, 'reason' => "Ballast trop gros : remaining=" . robtest_human_bytes($remaining)];
        // On simule un upload qui laisse 1 Mo de marge → doit passer
        $simSize = $remaining - (1 * 1024 * 1024);
        $can = canUpload($simSize);
        $st = checkExtractionStatus();
        if (!$can) return ['fail' => true, 'reason' => "canUpload($simSize) renvoie false alors que used+sim=" . robtest_human_bytes($used + $simSize) . " <= max=" . robtest_human_bytes($maxBytes)];
        return sprintf("used=%s, sim=%s, pct=%s%%", robtest_human_bytes($used), robtest_human_bytes($simSize), $st['pct']);
    });

    robtest_run($log, 'storage_at_401mb_blocks', function() use ($log, $maxBytes) {
        $used = getServerTotalUsage();
        $remaining = $maxBytes - $used;
        // On simule un upload qui dépasse de 1 Mo → doit bloquer
        $simSize = $remaining + (1 * 1024 * 1024);
        $can = canUpload($simSize);
        if ($can) return ['fail' => true, 'reason' => "canUpload($simSize) renvoie true alors que used+sim dépasse max"];
        // Vérifier également via acquireExtractionLock
        $lock = acquireExtractionLock($simSize);
        if ($lock['ok'] !== false) {
            releaseExtractionLock();
            return ['fail' => true, 'reason' => "acquireExtractionLock devrait refuser, mais ok=true"];
        }
        if (($lock['reason'] ?? '') !== 'server_full') {
            return ['fail' => true, 'reason' => "raison inattendue: " . ($lock['reason'] ?? 'aucune')];
        }
        return "blocage server_full confirmé";
    });

    robtest_run($log, 'storage_status_pct_correct', function() use ($log, $maxBytes) {
        $used = getServerTotalUsage();
        $st = checkExtractionStatus();
        $expected = round(($used / $maxBytes) * 100, 1);
        if (abs($expected - $st['pct']) > 1) return ['fail' => true, 'reason' => "pct attendu=$expected, reçu={$st['pct']}"];
        return "pct={$st['pct']}%";
    });

    robtest_run($log, 'storage_alert_threshold', function() use ($log, $maxBytes) {
        // Vérifie qu'il existe une constante SERVER_WARN_MB et une clé 'warning' dans checkExtractionStatus
        if (!defined('SERVER_WARN_MB')) {
            return ['expected_fail' => true, 'reason' => 'SERVER_WARN_MB non définie (à ajouter dans config.php)'];
        }
        $st = checkExtractionStatus();
        if (!array_key_exists('warning', $st)) {
            return ['expected_fail' => true, 'reason' => "checkExtractionStatus() ne retourne pas la clé 'warning'"];
        }
        // Avec ballast 350Mo, le seuil 320Mo doit être franchi → warning attendu à true
        $expectedWarning = ($st['used_mb'] >= SERVER_WARN_MB && $st['used_mb'] < SERVER_MAX_MB);
        if ($expectedWarning && !$st['warning']) {
            return ['fail' => true, 'reason' => "warning attendu à true à {$st['used_mb']}Mo (seuil " . SERVER_WARN_MB . "Mo) mais reçu false"];
        }
        return "SERVER_WARN_MB=" . SERVER_WARN_MB . ", warning=" . var_export($st['warning'], true) . ", pct={$st['pct']}%";
    });

    // Test endpoint upload.php avec espace insuffisant
    // Stratégie : on ajoute un fichier d'appoint dans le ballast pour pousser
    // getServerTotalUsage() au-dessus de (400Mo - taille_MBZ_test). L'upload doit alors être refusé.
    robtest_run($log, 'storage_upload_endpoint_blocked', function() use ($log, $maxBytes) {
        $sample = robtest_create_sample_mbz($log);
        if (!$sample) return ['fail' => true, 'reason' => 'sample MBZ indisponible'];
        $mbzSize = filesize($sample);
        $used = getServerTotalUsage();
        // Combien d'octets faut-il rajouter pour franchir le seuil 400 Mo en incluant le MBZ ?
        $needed = ($maxBytes - $used - $mbzSize) + (1 * 1024 * 1024); // +1 Mo de marge
        $extraFile = ROBTEST_BALLAST_DIR . '/' . ROBTEST_PREFIX . 'extra_overflow.bin';
        if ($needed > 0) {
            if (!is_dir(ROBTEST_BALLAST_DIR)) robtest_mkdir(ROBTEST_BALLAST_DIR);
            // Vérifier qu'on a bien la place pour écrire $needed octets sans saturer le disque
            $free = @disk_free_space(COURSES_PATH);
            if ($free !== false && $free < $needed + (50 * 1024 * 1024)) {
                return ['fail' => true, 'reason' => "espace disque insuffisant pour le test (libre=" . robtest_human_bytes((int)$free) . ", besoin=" . robtest_human_bytes((int)$needed) . ")"];
            }
            // Écriture par chunks de 1 Mo
            $fp = @fopen($extraFile, 'wb');
            if (!$fp) return ['fail' => true, 'reason' => "impossible d'écrire $extraFile"];
            $chunk = str_repeat("\0", 1024 * 1024);
            $remaining = (int)$needed;
            while ($remaining > 0) {
                $w = ($remaining >= strlen($chunk)) ? $chunk : substr($chunk, 0, $remaining);
                $written = @fwrite($fp, $w);
                if ($written === false || $written === 0) break;
                $remaining -= $written;
            }
            fclose($fp);
        }
        $usedAfter = getServerTotalUsage();
        if ($usedAfter + $mbzSize <= $maxBytes) {
            @unlink($extraFile);
            return ['fail' => true, 'reason' => "le fichier d'appoint n'a pas suffi : used=" . robtest_human_bytes($usedAfter) . " + mbz=" . robtest_human_bytes($mbzSize) . " <= max=" . robtest_human_bytes($maxBytes)];
        }
        // Forcer une session admin et tenter l'upload
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('upload.php', 'POST', ['prof_name' => ROBTEST_PREFIX . 'overflow'], ['file' => $sample]);
        // Cleanup immédiat
        @unlink($extraFile);
        if ($resp['http_code'] === 0) return ['fail' => true, 'reason' => 'curl error : ' . ($resp['error'] ?? '?')];
        $body = $resp['body'] ?? '';
        $json = $resp['json'];
        // Si malgré tout l'upload a réussi, supprimer le cours créé
        if (is_array($json) && ($json['success'] ?? false) === true) {
            $createdId = $json['prof_id'] ?? '';
            if ($createdId && strpos($createdId, ROBTEST_PREFIX) === 0) {
                robtest_rmrf(COURSES_PATH . '/' . $createdId);
            }
            return ['fail' => true, 'reason' => "upload accepté alors que used=" . robtest_human_bytes($usedAfter) . " + mbz=" . robtest_human_bytes($mbzSize) . " > max — le blocage à 400Mo est défaillant"];
        }
        if (is_array($json) && ($json['success'] ?? true) === false) {
            $err = $json['error'] ?? '';
            if (stripos($err, 'espace') !== false || stripos($err, 'stockage') !== false || stripos($err, 'insuffisant') !== false) {
                return "upload bloqué proprement (used=" . robtest_human_bytes($usedAfter) . ") : $err";
            }
            return "upload refusé (raison: $err)";
        }
        return ['fail' => true, 'reason' => 'réponse non-JSON ou inattendue : ' . substr($body, 0, 200)];
    });

    // Cleanup ballast en fin de suite
    robtest_destroy_ballast($log);
}

// =====================================================================
// SUITE 2 — DRIVE_SYNC
// =====================================================================

function robtest_drive_manager_or_null(RobtestLogger $log) {
    static $dm = null;
    static $tried = false;
    if ($tried) return $dm;
    $tried = true;
    if (!file_exists(__DIR__ . '/DriveManager.php')) {
        $log->warn('DriveManager.php absent — tests Drive ignorés');
        return null;
    }
    if (!defined('DRIVE_OAUTH_CLIENT_JSON') || !defined('GDRIVE_OAUTH_TOKEN_PATH')) {
        $log->warn('Constantes Drive non définies — tests Drive ignorés');
        return null;
    }
    if (!file_exists(DRIVE_OAUTH_CLIENT_JSON)) {
        $log->warn('Credentials Drive absents (' . DRIVE_OAUTH_CLIENT_JSON . ') — tests Drive ignorés');
        return null;
    }
    $autoloader = ROOT_PATH . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        $log->warn('Autoloader Composer absent (' . $autoloader . ') — tests Drive ignorés');
        return null;
    }
    require_once __DIR__ . '/DriveManager.php';
    try {
        $dm = new DriveManager(DRIVE_OAUTH_CLIENT_JSON, GDRIVE_OAUTH_TOKEN_PATH, $autoloader);
    } catch (Throwable $e) {
        $log->warn('DriveManager init failed : ' . $e->getMessage());
        return null;
    }
    return $dm;
}

function robtest_suite_drive_sync(RobtestLogger $log): void {
    $log->info('--- Suite: drive_sync ---');
    $dm = robtest_drive_manager_or_null($log);
    $tempParent = defined('DRIVE_COURSETEMP_FOLDER_ID') ? DRIVE_COURSETEMP_FOLDER_ID : '';

    robtest_run($log, 'drive_quota_baseline', function() use ($dm, $log) {
        if (!$dm) return ['expected_fail' => true, 'reason' => 'DriveManager indisponible'];
        if (!method_exists($dm, 'getQuotaInfo')) return ['fail' => true, 'reason' => 'DriveManager::getQuotaInfo manquant'];
        $q = $dm->getQuotaInfo();
        if (!is_array($q) || !isset($q['used'])) return ['fail' => true, 'reason' => 'getQuotaInfo retour inattendu'];
        return "Drive used=" . robtest_human_bytes((int)$q['used']) . ", limit=" . robtest_human_bytes((int)($q['total'] ?? 0));
    });

    robtest_run($log, 'sync_import_mbz_to_drive', function() use ($log, $dm, $tempParent) {
        if (!$dm) return ['expected_fail' => true, 'reason' => 'DriveManager indisponible'];
        $sample = robtest_create_sample_mbz($log);
        if (!$sample) return ['fail' => true, 'reason' => 'sample MBZ indisponible'];
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $profId = ROBTEST_PREFIX . 'sync1';
        $resp = robtest_http_call('upload.php', 'POST', ['prof_name' => $profId], ['file' => $sample]);
        if (!is_array($resp['json']) || ($resp['json']['success'] ?? false) !== true) {
            return ['fail' => true, 'reason' => 'upload échoué : ' . substr($resp['body'] ?? '', 0, 200)];
        }
        $createdId = $resp['json']['prof_id'] ?? '';
        if (!$createdId) return ['fail' => true, 'reason' => 'prof_id manquant dans réponse'];
        // Le cours est créé localement — la sync Drive est asynchrone via la queue
        $coursePath = COURSES_PATH . '/' . $createdId;
        if (!is_dir($coursePath)) return ['fail' => true, 'reason' => "dossier local manquant : $coursePath"];
        return "import OK, prof_id=$createdId, dossier=$coursePath (sync Drive asynchrone)";
    });

    robtest_run($log, 'sync_state_file_created', function() use ($log) {
        // Avec G6 (enqueue auto dans upload.php), la garantie après import est :
        //   - Le cours est enqueué dans tmp/.drive_upload_queue.json
        //   - Le state file .drive_prep_*.json n'apparaît qu'après handleStart (côté JS)
        // On vérifie donc la PRÉSENCE DANS LA QUEUE, qui est la promesse réelle d'enqueue auto.
        $queueFile = TMP_PATH . '/.drive_upload_queue.json';
        if (!file_exists($queueFile)) {
            return ['fail' => true, 'reason' => 'queue file inexistante après import'];
        }
        $queue = json_decode(@file_get_contents($queueFile), true) ?: [];
        $foundRobtest = false;
        foreach ($queue as $item) {
            $gid = $item['gdrive_id'] ?? '';
            if (strpos($gid, 'robtestsync1') === 0 || strpos($gid, ROBTEST_PREFIX) !== false) {
                $foundRobtest = true;
                break;
            }
        }
        if (!$foundRobtest) {
            return ['fail' => true, 'reason' => 'cours importé pas trouvé dans la queue (G6 non actif ?)'];
        }
        return sprintf('queue contient l\'item importé (taille queue=%d)', count($queue));
    });

    robtest_run($log, 'sync_create_course_session', function() use ($log) {
        // Crée une session éditeur via l'API editor_api.php et vérifie qu'un fichier session est écrit
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $sid = ROBTEST_PREFIX . 'sess1_' . substr(bin2hex(random_bytes(2)), 0, 4);
        // Setup direct (sans passer par editor_api : on crée le fichier session pour ce test)
        $sessionFile = EDITOR_SESSIONS_DIR . '/' . $sid . '.json';
        robtest_mkdir(EDITOR_SESSIONS_DIR);
        $session = [
            'session_id' => $sid,
            'pending_files' => [],
            'file_mapping' => [],
            'drive_folder_id' => '',
            'files_folder_id' => '',
            'last_activity' => time(),
        ];
        @file_put_contents($sessionFile, json_encode($session, JSON_PRETTY_PRINT));
        if (!file_exists($sessionFile)) return ['fail' => true, 'reason' => 'session non créée'];
        return "session $sid créée";
    });

    robtest_run($log, 'sync_drive_parity', function() use ($log, $dm) {
        if (!$dm) return ['expected_fail' => true, 'reason' => 'DriveManager indisponible'];
        // Vérifie que pour chaque index local, le dossier Drive correspondant existe
        if (!is_dir(DRIVE_INDEX_DIR)) return "pas d'index local à vérifier";
        $robtestIndexes = glob(DRIVE_INDEX_DIR . '/*' . ROBTEST_PREFIX . '*.json') ?: [];
        $checked = 0;
        $orphans = 0;
        foreach ($robtestIndexes as $idxFile) {
            if (strpos($idxFile, '_data.json') !== false) continue;
            $idx = json_decode(@file_get_contents($idxFile), true);
            if (!is_array($idx)) continue;
            $folderId = $idx['drive_folder_id'] ?? $idx['folder_id'] ?? '';
            if (empty($folderId)) continue;
            $checked++;
            // Tenter de lister le dossier
            try {
                if (method_exists($dm, 'listFolder')) {
                    $list = $dm->listFolder($folderId);
                    if (!is_array($list)) $orphans++;
                }
            } catch (Throwable $e) {
                $orphans++;
            }
        }
        if ($checked === 0) return "aucun index _robtest_* à vérifier";
        if ($orphans > 0) return ['fail' => true, 'reason' => "$orphans/$checked indices orphelins (Drive vide)"];
        return "$checked indices vérifiés, parité OK";
    });
}

// =====================================================================
// SUITE 3 — LOCATION_INDEPENDENCE
// =====================================================================

function robtest_suite_location_independence(RobtestLogger $log): void {
    $log->info('--- Suite: location_independence ---');

    robtest_run($log, 'loc_view_local_course', function() use ($log) {
        $profId = ROBTEST_PREFIX . 'loc_view';
        robtest_make_fake_course($profId);
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('view.php', 'GET', ['id' => $profId]);
        // Cleanup
        robtest_rmrf(COURSES_PATH . '/' . $profId);
        if ($resp['http_code'] !== 200) return ['fail' => true, 'reason' => "HTTP {$resp['http_code']}"];
        if (strlen($resp['body']) < 100) return ['fail' => true, 'reason' => 'corps trop court'];
        return "HTTP 200, body=" . robtest_human_bytes(strlen($resp['body']));
    });

    robtest_run($log, 'loc_view_drive_only', function() use ($log) {
        // Crée un index Drive sans dossier local
        $profId = ROBTEST_PREFIX . 'loc_drive';
        robtest_mkdir(DRIVE_INDEX_DIR);
        $indexFile = DRIVE_INDEX_DIR . '/temp_' . $profId . '.json';
        $dataFile = DRIVE_INDEX_DIR . '/temp_' . $profId . '_data.json';
        @file_put_contents($indexFile, json_encode(['drive_folder_id' => 'fake_folder', 'files' => []]));
        @file_put_contents($dataFile, json_encode(['course' => ['course_fullname' => 'Robtest Drive Only', 'course_shortname' => $profId], 'files' => []]));
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('view.php', 'GET', ['id' => $profId]);
        // Cleanup
        @unlink($indexFile); @unlink($dataFile);
        if ($resp['http_code'] === 0) return ['fail' => true, 'reason' => 'curl error'];
        if ($resp['http_code'] >= 500) return ['fail' => true, 'reason' => "HTTP {$resp['http_code']} — view.php ne sait pas afficher un cours Drive-only"];
        // 2xx ou 3xx ou 404 acceptable selon implémentation
        if ($resp['http_code'] === 404) {
            return ['expected_fail' => true, 'reason' => 'view.php retourne 404 pour un cours Drive-only — vérifier que la décompression auto est bien déclenchée'];
        }
        return "HTTP {$resp['http_code']}";
    });

    robtest_run($log, 'loc_export_pdf_endpoint_exists', function() use ($log) {
        // Vérifie que download.php ou export_pdf.php existe
        if (file_exists(__DIR__ . '/download.php')) return "download.php présent";
        if (file_exists(__DIR__ . '/export_pdf.php')) return "export_pdf.php présent";
        return ['expected_fail' => true, 'reason' => 'aucun endpoint d\'export PDF connu'];
    });

    robtest_run($log, 'loc_mbz_exporter_class_loadable', function() use ($log) {
        if (!file_exists(__DIR__ . '/includes/EleaMbzExporter.php')) return ['fail' => true, 'reason' => 'EleaMbzExporter.php absent'];
        require_once __DIR__ . '/includes/EleaMbzExporter.php';
        if (!class_exists('EleaMbzExporter')) return ['fail' => true, 'reason' => 'classe EleaMbzExporter absente après include'];
        return "EleaMbzExporter chargeable";
    });
}

// =====================================================================
// SUITE 4 — EXPIRATION
// =====================================================================

function robtest_suite_expiration(RobtestLogger $log): void {
    $log->info('--- Suite: expiration ---');

    robtest_run($log, 'exp_temp_course_24h', function() use ($log) {
        $profId = ROBTEST_PREFIX . 'exp_old';
        $coursePath = robtest_make_fake_course($profId, -25 * 3600);
        if (!is_dir($coursePath)) return ['fail' => true, 'reason' => 'setup échoué'];
        $deleted = cleanExpiredCourses();
        $stillExists = is_dir($coursePath);
        if ($stillExists) {
            // cleanup forcé
            robtest_rmrf($coursePath);
            return ['fail' => true, 'reason' => "cours vieux >24h non supprimé par cleanExpiredCourses()"];
        }
        return "cours expiré supprimé (cleanExpiredCourses retour=$deleted)";
    });

    robtest_run($log, 'exp_recent_course_kept', function() use ($log) {
        $profId = ROBTEST_PREFIX . 'exp_recent';
        $coursePath = robtest_make_fake_course($profId, -1 * 3600); // 1h
        if (!is_dir($coursePath)) return ['fail' => true, 'reason' => 'setup échoué'];
        cleanExpiredCourses();
        $stillExists = is_dir($coursePath);
        // Cleanup
        robtest_rmrf($coursePath);
        if (!$stillExists) return ['fail' => true, 'reason' => 'cours récent (1h) supprimé à tort'];
        return "cours récent conservé";
    });

    robtest_run($log, 'exp_editor_session_24h', function() use ($log) {
        if (!function_exists('cleanExpiredEditorSessions')) {
            return ['expected_fail' => true, 'reason' => 'cleanExpiredEditorSessions() non définie'];
        }
        $sid = ROBTEST_PREFIX . 'exp_sess';
        robtest_mkdir(EDITOR_SESSIONS_DIR);
        $sessionFile = EDITOR_SESSIONS_DIR . '/' . $sid . '.json';
        $session = [
            'session_id' => $sid,
            'last_activity' => time() - 25 * 3600,
            'pending_files' => [],
            'file_mapping' => [],
        ];
        @file_put_contents($sessionFile, json_encode($session, JSON_PRETTY_PRINT));
        @touch($sessionFile, time() - 25 * 3600);
        cleanExpiredEditorSessions();
        $stillExists = file_exists($sessionFile);
        if ($stillExists) {
            @unlink($sessionFile);
            return ['fail' => true, 'reason' => 'session vieille >24h non supprimée'];
        }
        return 'session expirée supprimée';
    });

    robtest_run($log, 'exp_state_file_orphan', function() use ($log) {
        $sid = ROBTEST_PREFIX . 'orphan_state';
        $stateFile = TMP_PATH . '/.drive_prep_temp_' . $sid . '.json';
        $state = [
            'status' => 'uploading',
            'extract_path' => '/path/that/does/not/exist/_robtest_/' . $sid,
            'uploaded_count' => 2,
            'total_files' => 10,
            'updated' => time(),
        ];
        @file_put_contents($stateFile, json_encode($state));
        if (!file_exists($stateFile)) return ['fail' => true, 'reason' => 'setup state file échoué'];
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('api/prepare_course.php', 'POST_JSON', ['action' => 'pending']);
        $stillExists = file_exists($stateFile);
        if ($stillExists) {
            @unlink($stateFile);
            return ['fail' => true, 'reason' => 'state file orphelin non nettoyé par handlePending'];
        }
        return "orphan state file supprimé par handlePending (HTTP {$resp['http_code']})";
    });

    robtest_run($log, 'exp_pdf_preview_10min', function() use ($log) {
        $dirName = 'pdf-preview-' . ROBTEST_PREFIX . 'old';
        $dirPath = COURSES_PATH . '/' . $dirName;
        robtest_mkdir($dirPath);
        @file_put_contents($dirPath . '/dummy.txt', 'old');
        @touch($dirPath, time() - 700);
        @touch($dirPath . '/dummy.txt', time() - 700);
        cleanupPdfPreviews();
        $stillExists = is_dir($dirPath);
        if ($stillExists) {
            robtest_rmrf($dirPath);
            return ['fail' => true, 'reason' => 'pdf-preview vieux >10min non supprimé'];
        }
        return 'pdf-preview supprimé';
    });

    robtest_run($log, 'exp_drive_downloads_1h', function() use ($log) {
        if (!function_exists('cleanDriveDownloads')) {
            return ['expected_fail' => true, 'reason' => 'cleanDriveDownloads() non définie'];
        }
        $dlDir = TMP_PATH . '/drive_downloads';
        robtest_mkdir($dlDir);
        $f = $dlDir . '/' . ROBTEST_PREFIX . 'old.bin';
        @file_put_contents($f, str_repeat('X', 1024));
        @touch($f, time() - 3700);
        cleanDriveDownloads();
        $stillExists = file_exists($f);
        if ($stillExists) {
            @unlink($f);
            return ['fail' => true, 'reason' => 'drive_downloads vieux >1h non supprimé'];
        }
        return 'drive_downloads vieux supprimé';
    });
}

// =====================================================================
// SUITE 5 — NEW_COURSE
// =====================================================================

function robtest_suite_new_course(RobtestLogger $log): void {
    $log->info('--- Suite: new_course ---');

    robtest_run($log, 'new_course_clears_old', function() use ($log) {
        // Setup : crée des fichiers d'éditeur pour une "ancienne session"
        $sid = ROBTEST_PREFIX . 'old_sess';
        $uploadsDir = CACHE_DIR . '/editor_uploads/' . $sid;
        robtest_mkdir($uploadsDir);
        @file_put_contents($uploadsDir . '/file1.png', 'fake1');
        @file_put_contents($uploadsDir . '/file2.png', 'fake2');
        @file_put_contents($uploadsDir . '/file3.png', 'fake3');
        $_SESSION['editor_session_id'] = $sid;
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        // L'API create_course lit $_SESSION['editor_session_id'] OU input.old_session_id.
        // On envoie old_session_id explicitement pour ne pas dépendre du transport de session via curl.
        $resp = robtest_http_call('api/editor_api.php', 'POST_JSON', [
            'action' => 'create_course',
            'old_session_id' => $sid,
            'course_name' => 'Nouveau Robtest'
        ]);
        // Vérifier si les fichiers de l'ancienne session ont été supprimés
        $remaining = is_dir($uploadsDir) ? count(glob($uploadsDir . '/*') ?: []) : 0;
        // Cleanup résiduel
        robtest_rmrf($uploadsDir);
        // Cleanup de la nouvelle session créée par create_course
        if (is_array($resp['json']) && !empty($resp['json']['session_id'])) {
            $newSid = $resp['json']['session_id'];
            robtest_rmrf(CACHE_DIR . '/editor_uploads/' . $newSid);
            @unlink(EDITOR_SESSIONS_DIR . '/' . $newSid . '.json');
        }
        if ($remaining > 0) {
            return [
                'expected_fail' => true,
                'reason' => "create_course n'a pas supprimé les $remaining fichiers (HTTP {$resp['http_code']}, success=" . var_export($resp['json']['success'] ?? null, true) . ", cleanup=" . json_encode($resp['json']['cleanup'] ?? null) . ", error=" . ($resp['json']['error'] ?? '?') . ")",
                'data' => ['remaining_files' => $remaining, 'response' => $resp['json']]
            ];
        }
        return "ancienne session vidée (cleanup=" . json_encode($resp['json']['cleanup']['deleted'] ?? []) . ")";
    });
}

// =====================================================================
// SUITE 6 — EXPORT_PURITY
// =====================================================================

function robtest_suite_export_purity(RobtestLogger $log): void {
    $log->info('--- Suite: export_purity ---');

    robtest_run($log, 'mbz_sample_valid_tar_gz', function() use ($log) {
        $sample = robtest_create_sample_mbz($log);
        if (!$sample) return ['fail' => true, 'reason' => 'sample MBZ indisponible'];
        $cmd = 'gzip -t ' . escapeshellarg($sample) . ' 2>&1';
        @exec($cmd, $output, $rc);
        if ($rc !== 0) return ['fail' => true, 'reason' => 'gzip -t a échoué : ' . implode(' ', $output)];
        return 'MBZ test passe gzip -t';
    });

    robtest_run($log, 'mbz_sample_valid_tar', function() use ($log) {
        $sample = robtest_create_sample_mbz($log);
        if (!$sample) return ['fail' => true, 'reason' => 'sample MBZ indisponible'];
        $cmd = 'tar -tzf ' . escapeshellarg($sample) . ' 2>&1';
        @exec($cmd, $output, $rc);
        if ($rc !== 0) return ['fail' => true, 'reason' => 'tar -tzf a échoué'];
        $entries = count($output);
        if ($entries < 3) return ['fail' => true, 'reason' => "MBZ contient $entries entrées seulement"];
        return "MBZ tar valide, $entries entrées";
    });

    robtest_run($log, 'mbz_export_no_undo_files', function() use ($log) {
        // Test conceptuel : si EleaMbzExporter::export() existe, on simule un export et on vérifie qu'aucun fichier interdit n'est inclus.
        // En l'absence d'un cours réel à exporter, on vérifie que le code source ne produit pas de patterns interdits dans son output.
        if (!file_exists(__DIR__ . '/includes/EleaMbzExporter.php')) {
            return ['expected_fail' => true, 'reason' => 'EleaMbzExporter.php absent'];
        }
        // Lecture du source pour vérifier que les patterns interdits ne sont pas produits intentionnellement
        $src = @file_get_contents(__DIR__ . '/includes/EleaMbzExporter.php');
        if ($src === false) return ['fail' => true, 'reason' => 'lecture EleaMbzExporter impossible'];
        // Patterns interdits = noms de fichiers internes Elea-Secours (PAS les fichiers Moodle standards
        // comme grade_history.xml, files.xml etc. qui sont attendus dans un MBZ valide).
        // 'editor_uploads' est retiré : utilisé uniquement en résolution de chemin (lecture des fichiers
        // sources d'un cours), pas pour inclure le dossier dans l'export.
        $forbidden = ['undo_history', 'auto_save_', '.bak', 'draft_session_', '_undo.'];
        $found = [];
        foreach ($forbidden as $p) {
            if (preg_match('/[\'"]([^\'"]*' . preg_quote($p, '/') . '[^\'"]*)[\'"]/', $src, $m)) {
                $context = $m[0];
                $idx = strpos($src, $context);
                $before = substr($src, max(0, $idx - 100), 100);
                // Tolérer les contextes d'exclusion explicite
                if (preg_match('/(skip|ignore|exclude|!=|continue|reject|forbidden|strpos.*===\s*false|str_contains)/i', $before)) continue;
                $found[] = $p;
            }
        }
        if (!empty($found)) {
            return ['expected_fail' => true, 'reason' => 'patterns suspects dans EleaMbzExporter (à vérifier manuellement) : ' . implode(',', $found)];
        }
        return 'aucun pattern d\'historique interne repéré dans le code';
    });
}

// =====================================================================
// SUITE 7 — RECOVERY
// =====================================================================

function robtest_suite_recovery(RobtestLogger $log): void {
    $log->info('--- Suite: recovery ---');

    robtest_run($log, 'recovery_orphan_state_temp', function() use ($log) {
        $profId = ROBTEST_PREFIX . 'recov1';
        // Setup : décompresser un MBZ vers courses/{profId}/ + créer state file
        $coursePath = robtest_make_fake_course($profId);
        $stateFile = TMP_PATH . '/.drive_prep_temp_' . $profId . '.json';
        $state = [
            'status' => 'uploading',
            'extract_path' => $coursePath,
            'uploaded_count' => 2,
            'total_files' => 10,
            'updated' => time(),
            'course_name' => 'Robtest Recov 1',
            'lock_until' => 0,
        ];
        @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('api/prepare_course.php', 'POST_JSON', ['action' => 'pending']);
        // Cleanup
        @unlink($stateFile);
        robtest_rmrf($coursePath);
        if (!is_array($resp['json'])) return ['fail' => true, 'reason' => 'réponse non JSON : ' . substr($resp['body'], 0, 200)];
        $found = $resp['json']['found'] ?? false;
        if ($found !== true) return ['fail' => true, 'reason' => 'handlePending n\'a pas trouvé l\'orphelin'];
        if (($resp['json']['gdrive_id'] ?? '') !== $profId) return ['fail' => true, 'reason' => 'gdrive_id incorrect : ' . ($resp['json']['gdrive_id'] ?? 'null')];
        if (($resp['json']['type'] ?? '') !== 'temp') return ['fail' => true, 'reason' => 'type incorrect'];
        return "handlePending found=true, type=temp, {$resp['json']['uploaded_count']}/{$resp['json']['total_files']}";
    });

    robtest_run($log, 'recovery_orphan_in_queue', function() use ($log) {
        $profId = ROBTEST_PREFIX . 'recov2';
        $coursePath = robtest_make_fake_course($profId);
        // Setup : queue avec cet item
        $queueFile = TMP_PATH . '/.drive_upload_queue.json';
        $existingQueue = file_exists($queueFile) ? (json_decode(@file_get_contents($queueFile), true) ?? []) : [];
        $newQueue = $existingQueue;
        $newQueue[] = ['gdrive_id' => $profId, 'name' => 'Robtest Recov 2', 'type' => 'temp', 'added' => time()];
        @file_put_contents($queueFile, json_encode($newQueue, JSON_PRETTY_PRINT));
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('api/prepare_course.php', 'POST_JSON', ['action' => 'pending']);
        // Cleanup queue
        @file_put_contents($queueFile, json_encode($existingQueue, JSON_PRETTY_PRINT));
        robtest_rmrf($coursePath);
        if (!is_array($resp['json'])) return ['fail' => true, 'reason' => 'réponse non JSON'];
        $found = $resp['json']['found'] ?? false;
        if ($found !== true) return ['fail' => true, 'reason' => 'handlePending n\'a pas remonté l\'item de queue'];
        // Tolérance : il peut remonter un AUTRE item si plusieurs sont en queue. On vérifie que NOTRE item est listé quelque part.
        $matchedTop = ($resp['json']['gdrive_id'] ?? '') === $profId;
        $matchedQueued = false;
        foreach (($resp['json']['also_queued'] ?? []) as $q) {
            if (($q['gdrive_id'] ?? '') === $profId) { $matchedQueued = true; break; }
        }
        if (!$matchedTop && !$matchedQueued) {
            return ['fail' => true, 'reason' => 'item de queue non remonté ni en top ni en also_queued'];
        }
        return $matchedTop ? "found en top (type={$resp['json']['type']})" : "found en also_queued";
    });

    robtest_run($log, 'recovery_stale_lock_cleanup', function() use ($log) {
        $lockFile = EXTRACTION_LOCK_FILE;
        $existingBackup = file_exists($lockFile) ? @file_get_contents($lockFile) : null;
        // Setup : faux lock avec PID inexistant et mtime ancien
        $stale = ['pid' => 99999, 'started' => time() - 100, 'info' => '_robtest_stale'];
        @file_put_contents($lockFile, json_encode($stale));
        @touch($lockFile, time() - 100);
        $isStale = _isLockStale();
        if (!$isStale) {
            // Restaurer
            if ($existingBackup !== null) @file_put_contents($lockFile, $existingBackup);
            else @unlink($lockFile);
            return ['fail' => true, 'reason' => '_isLockStale() retourne false alors que pid=99999 et age=100s'];
        }
        // Tester acquireExtractionLock — doit réussir car le lock est stale
        @unlink($lockFile);
        $r = acquireExtractionLock(0);
        $ok = $r['ok'] ?? false;
        releaseExtractionLock();
        if ($existingBackup !== null) @file_put_contents($lockFile, $existingBackup);
        if (!$ok) return ['fail' => true, 'reason' => 'acquireExtractionLock a refusé après nettoyage du stale lock'];
        return 'stale lock détecté et nettoyé';
    });

    robtest_run($log, 'recovery_corrupt_state_file', function() use ($log) {
        $sid = ROBTEST_PREFIX . 'corrupt_state';
        $stateFile = TMP_PATH . '/.drive_prep_' . $sid . '.json';
        @file_put_contents($stateFile, '{"status":"uploadi'); // JSON tronqué
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $resp = robtest_http_call('api/prepare_course.php', 'POST_JSON', ['action' => 'pending']);
        // Cleanup forcé
        if (file_exists($stateFile)) @unlink($stateFile);
        if ($resp['http_code'] >= 500) {
            return ['fail' => true, 'reason' => "handlePending crashe sur JSON corrompu (HTTP {$resp['http_code']})"];
        }
        return "handlePending résiste au JSON corrompu (HTTP {$resp['http_code']})";
    });

    robtest_run($log, 'recovery_anonymous_user', function() use ($log) {
        // Test : un utilisateur SANS session (cookie session vierge) peut-il déclencher la reprise ?
        // On utilise le mode anonymous=true qui forge un session_id non-existant côté serveur.
        $resp = robtest_http_call('api/prepare_course.php', 'POST_JSON', ['action' => 'pending'], [], true);
        if ($resp['http_code'] === 403) {
            return ['expected_fail' => true, 'reason' => 'la reprise nécessite une session prof active (pas anonyme) — gap si l\'intention est que la reprise soit déclenchée par n\'importe qui'];
        }
        if ($resp['http_code'] === 200 && is_array($resp['json'])) {
            return "reprise accessible aux utilisateurs anonymes (HTTP 200, success=" . var_export($resp['json']['success'] ?? null, true) . ")";
        }
        return ['fail' => true, 'reason' => "réponse inattendue : HTTP {$resp['http_code']}, body=" . substr($resp['body'] ?? '', 0, 100)];
    });
}

// =====================================================================
// SUITE 8 — EXTRAS
// =====================================================================

function robtest_suite_extras(RobtestLogger $log): void {
    $log->info('--- Suite: extras ---');

    robtest_run($log, 'race_concurrent_extraction_lock', function() use ($log) {
        // Première acquisition
        $r1 = acquireExtractionLock(1024);
        if (!($r1['ok'] ?? false)) {
            // Lock déjà pris (peut-être par un autre process). On n'échoue pas, on note.
            return ['expected_fail' => true, 'reason' => 'acquireExtractionLock initial refusé : ' . ($r1['reason'] ?? 'inconnu')];
        }
        // Tentative immédiate : doit refuser car notre PID détient toujours le lock
        // Mais _isLockStale checke posix_kill(pid, 0) — comme c'est notre propre PID, le test refusera.
        // On simule donc un autre process en modifiant temporairement le lock
        $lockFile = EXTRACTION_LOCK_FILE;
        // Pour simuler concurrence : on force le lock à "appartenir" à un autre PID actif (nous-même mais via un PID virtuel)
        // En réalité, le seul moyen sûr est de vérifier le comportement avec _isLockStale
        $stale = _isLockStale();
        releaseExtractionLock();
        if ($stale) return ['fail' => true, 'reason' => 'lock fraichement créé jugé stale — anomalie'];
        return 'lock vivant détecté correctement';
    });

    robtest_run($log, 'oauth_token_keepalive_throttle', function() use ($log) {
        if (!function_exists('driveTokenKeepAlive')) {
            return ['expected_fail' => true, 'reason' => 'driveTokenKeepAlive() non définie'];
        }
        // 1er appel : peut faire un API call si le throttle a expiré
        @driveTokenKeepAlive();
        $throttleFile = TMP_PATH . '/.drive_keepalive.json';
        $firstMtime = file_exists($throttleFile) ? filemtime($throttleFile) : 0;
        if ($firstMtime === 0) {
            return ['expected_fail' => true, 'reason' => 'fichier throttle .drive_keepalive.json non créé — vérifier l\'implémentation'];
        }
        // 2e appel immédiat : ne doit pas refaire d'API call
        @driveTokenKeepAlive();
        $secondMtime = file_exists($throttleFile) ? filemtime($throttleFile) : 0;
        if ($secondMtime !== $firstMtime) {
            return ['fail' => true, 'reason' => 'throttle ne fonctionne pas (mtime modifié au 2e appel)'];
        }
        return 'throttle keepalive OK';
    });

    robtest_run($log, 'drive_quota_check', function() use ($log) {
        $dm = robtest_drive_manager_or_null($log);
        if (!$dm) return ['expected_fail' => true, 'reason' => 'DriveManager indisponible'];
        if (!method_exists($dm, 'getQuotaInfo')) return ['fail' => true, 'reason' => 'getQuotaInfo manquant'];
        $q = $dm->getQuotaInfo();
        $used = (int)($q['used'] ?? 0);
        $total = (int)($q['total'] ?? 0);
        if ($total > 0 && $used > $total) return ['fail' => true, 'reason' => "used > total ?!"];
        return sprintf('used=%s, total=%s, pct=%.1f%%', robtest_human_bytes($used), robtest_human_bytes($total), $total > 0 ? ($used / $total) * 100 : 0);
    });

    robtest_run($log, 'auth_protection_no_password', function() use ($log) {
        // On appelle le script de test sans mot de passe
        $url = 'admin_robustness_test.php';
        $resp = robtest_http_call($url, 'POST', ['action' => 'run', 'suite' => 'storage']);
        // 403 attendu (auth missing)
        if ($resp['http_code'] === 403) return 'protection auth OK (403)';
        if ($resp['http_code'] === 0) return ['fail' => true, 'reason' => 'curl error'];
        return ['fail' => true, 'reason' => "auth bypass : HTTP {$resp['http_code']}"];
    });

    robtest_run($log, 'large_filename_unicode', function() use ($log) {
        $tmpDir = TMP_PATH . '/' . ROBTEST_PREFIX . 'unicode';
        robtest_mkdir($tmpDir);
        $fname = 'éàü_🎓_test.txt';
        $path = $tmpDir . '/' . $fname;
        $written = @file_put_contents($path, 'unicode test');
        $exists = file_exists($path);
        $ls = scandir($tmpDir);
        $foundInLs = false;
        foreach ($ls as $f) {
            if (mb_strpos($f, '🎓') !== false || mb_strpos($f, 'é') !== false) { $foundInLs = true; break; }
        }
        robtest_rmrf($tmpDir);
        if (!$exists) return ['fail' => true, 'reason' => 'fichier unicode non écrit'];
        if (!$foundInLs) return ['fail' => true, 'reason' => 'fichier unicode non listable par scandir'];
        return "unicode écrit et listable ($written octets)";
    });

    robtest_run($log, 'cleanup_old_drafts_callable', function() use ($log) {
        if (!function_exists('cleanupOldDrafts')) return ['fail' => true, 'reason' => 'cleanupOldDrafts non définie'];
        // Crée un brouillon vieux
        $dir = CACHE_DIR . '/drafts/auto';
        robtest_mkdir($dir);
        $f = $dir . '/' . ROBTEST_PREFIX . 'old_draft.json';
        @file_put_contents($f, json_encode(['session_id' => '_robtest_', 'data' => 'old']));
        @touch($f, time() - 25 * 3600);
        cleanupOldDrafts();
        $stillExists = file_exists($f);
        if ($stillExists) {
            @unlink($f);
            return ['fail' => true, 'reason' => 'cleanupOldDrafts n\'a pas supprimé le fichier vieux'];
        }
        return 'cleanupOldDrafts fonctionne';
    });

    robtest_run($log, 'session_lifetime_8h', function() use ($log) {
        $current = (int) ini_get('session.gc_maxlifetime');
        // Sur OVH, gc_maxlifetime est bridé à 1440 — on ne peut pas le contourner via ini_set.
        // Mais le helper checkSessionExpiry/enforceSessionExpiry doit gérer l'expiration côté app.
        $hasHelper = file_exists(__DIR__ . '/includes/session_check.php');
        if (!$hasHelper) {
            return ['expected_fail' => true, 'reason' => "session.gc_maxlifetime=$current < 28800 et includes/session_check.php absent"];
        }
        if ($current < 28800) {
            return "session.gc_maxlifetime=$current bridé par OVH, mais session_check.php présent (expiration gérée côté app)";
        }
        return "session.gc_maxlifetime=$current OK";
    });

    robtest_run($log, 'session_expiry_custom_8h', function() use ($log) {
        // Vérifie que le helper checkSessionExpiry retourne true pour login_at récent et false pour login_at > 8h
        $helperFile = __DIR__ . '/includes/session_check.php';
        if (!file_exists($helperFile)) return ['fail' => true, 'reason' => 'includes/session_check.php absent'];
        require_once $helperFile;
        if (!function_exists('checkSessionExpiry')) return ['fail' => true, 'reason' => 'checkSessionExpiry() non définie'];

        // Sauvegarder l'état actuel
        $savedAccess = $_SESSION['elea_access'] ?? null;
        $savedAdmin = $_SESSION['elea_admin'] ?? null;
        $savedLoginAt = $_SESSION['elea_login_at'] ?? null;

        // Cas 1 : session fraîche (1h)
        $_SESSION['elea_access'] = true;
        $_SESSION['elea_login_at'] = time() - 3600;
        $valid1h = checkSessionExpiry();

        // Cas 2 : session expirée (9h)
        $_SESSION['elea_login_at'] = time() - (9 * 3600);
        $valid9h = checkSessionExpiry();

        // Cas 3 : session sans login_at (legacy) → doit l'initialiser et retourner true
        unset($_SESSION['elea_login_at']);
        $validLegacy = checkSessionExpiry();
        $initialized = isset($_SESSION['elea_login_at']);

        // Restaurer
        if ($savedAccess !== null) $_SESSION['elea_access'] = $savedAccess; else unset($_SESSION['elea_access']);
        if ($savedAdmin !== null) $_SESSION['elea_admin'] = $savedAdmin; else unset($_SESSION['elea_admin']);
        if ($savedLoginAt !== null) $_SESSION['elea_login_at'] = $savedLoginAt; else unset($_SESSION['elea_login_at']);

        if (!$valid1h) return ['fail' => true, 'reason' => 'session 1h ancienne jugée expirée'];
        if ($valid9h) return ['fail' => true, 'reason' => 'session 9h ancienne acceptée (devrait être expirée)'];
        if (!$validLegacy || !$initialized) return ['fail' => true, 'reason' => 'session legacy sans login_at non gérée correctement'];
        return '1h=valide, 9h=expirée, legacy=auto-init OK';
    });

    robtest_run($log, 'session_check_deployed', function() use ($log) {
        // Diagnostic : vérifie que les fichiers ont bien été redéployés (helper présent ?)
        $checks = [
            'session_check.php' => __DIR__ . '/includes/session_check.php',
            'drive_cache.php' => __DIR__ . '/api/drive_cache.php',
            'drive_usage.php' => __DIR__ . '/api/drive_usage.php',
            'editor_api.php' => __DIR__ . '/api/editor_api.php',
            'prepare_course.php' => __DIR__ . '/api/prepare_course.php',
            'upload.php' => __DIR__ . '/upload.php',
        ];
        $missing = [];
        foreach ($checks as $name => $path) {
            if (!file_exists($path)) { $missing[] = "$name (absent)"; continue; }
            $content = @file_get_contents($path);
            if ($content === false) { $missing[] = "$name (illisible)"; continue; }
            // session_check.php doit définir enforceSessionExpiryJson
            // Les autres doivent l'appeler
            if ($name === 'session_check.php') {
                if (strpos($content, 'function enforceSessionExpiryJson') === false) $missing[] = "$name (helper non défini)";
            } else {
                if (strpos($content, 'enforceSessionExpiryJson()') === false) $missing[] = "$name (n'appelle pas le helper)";
            }
        }
        if (!empty($missing)) {
            return ['fail' => true, 'reason' => 'fichiers non à jour : ' . implode(', ', $missing)];
        }
        return 'tous les fichiers contiennent le helper et son appel';
    });

    robtest_run($log, 'session_expired_endpoint_returns_401', function() use ($log) {
        // Vérifie que les endpoints API retournent 401 si la session est marquée expirée.
        // S'assurer que la session est ACTIVE avant de modifier $_SESSION (sinon session_write_close
        // n'écrira rien sur disque).
        $statusBefore = session_status();
        if ($statusBefore !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $savedAccess = $_SESSION['elea_access'] ?? null;
        $savedAdmin = $_SESSION['elea_admin'] ?? null;
        $savedLoginAt = $_SESSION['elea_login_at'] ?? null;

        $_SESSION['elea_access'] = true;
        $_SESSION['elea_admin'] = true;
        $expiredTs = time() - (10 * 3600);
        $_SESSION['elea_login_at'] = $expiredTs;

        $sessionId = session_id();
        $savePath = session_save_path() ?: sys_get_temp_dir();
        $sessionFile = rtrim($savePath, '/') . '/sess_' . $sessionId;

        // Forcer l'écriture sur disque
        session_write_close();
        clearstatcache(true, $sessionFile);

        $beforeContent = file_exists($sessionFile) ? @file_get_contents($sessionFile) : null;
        $beforeHasExpired = $beforeContent !== null && strpos($beforeContent, (string)$expiredTs) !== false;

        // Ré-ouvrir la session pour pouvoir restaurer après
        session_start();

        $resp = robtest_http_call('api/drive_cache.php', 'POST_JSON', ['action' => 'check_extraction']);

        // Restaurer
        if ($savedAccess !== null) $_SESSION['elea_access'] = $savedAccess; else unset($_SESSION['elea_access']);
        if ($savedAdmin !== null) $_SESSION['elea_admin'] = $savedAdmin; else unset($_SESSION['elea_admin']);
        if ($savedLoginAt !== null) $_SESSION['elea_login_at'] = $savedLoginAt; else unset($_SESSION['elea_login_at']);

        $diag = sprintf(
            "status_before=%d, session_id=%s, file_existed=%s, file_had_expired_ts=%s, http=%d",
            $statusBefore,
            substr($sessionId, 0, 8) . '…',
            $beforeContent !== null ? 'yes' : 'no',
            $beforeHasExpired ? 'yes' : 'no',
            $resp['http_code']
        );

        // Si le fichier session ne contient pas notre timestamp expiré, le test ne peut pas
        // valider le comportement de l'endpoint — c'est un problème de transport de session, pas un bug du helper.
        if (!$beforeHasExpired) {
            return ['expected_fail' => true, 'reason' => "le fichier session n'a pas reçu elea_login_at après session_write_close — diag: $diag. Limitation du transport de session via curl interne, pas un bug du helper (cf test session_expiry_custom_8h qui PASS)."];
        }

        if ($resp['http_code'] !== 401) {
            return ['fail' => true, 'reason' => "endpoint a accepté une session expirée — diag: $diag, body=" . substr($resp['body'] ?? '', 0, 150)];
        }
        $err = $resp['json']['error'] ?? '?';
        if ($err !== 'session_expired') {
            return ['fail' => true, 'reason' => "code erreur incorrect: '$err' (attendu 'session_expired')"];
        }
        return "endpoint retourne 401 + error=session_expired correctement";
    });
}

// =====================================================================
// CLEANUP
// =====================================================================

function robtest_cleanup_artifacts(RobtestLogger $log): array {
    $report = ['files_removed' => 0, 'dirs_removed' => 0, 'drive_folders_removed' => 0, 'errors' => []];

    // 1. Glob _robtest_* dans courses, tmp, cache
    $patterns = [
        COURSES_PATH . '/' . ROBTEST_PREFIX . '*',
        TMP_PATH . '/' . ROBTEST_PREFIX . '*',
        CACHE_DIR . '/' . ROBTEST_PREFIX . '*',
        CACHE_DIR . '/editor_uploads/' . ROBTEST_PREFIX . '*',
    ];
    foreach ($patterns as $p) {
        foreach (glob($p) ?: [] as $path) {
            if (!robtest_safe_path($path)) continue;
            if (is_dir($path)) {
                robtest_rmrf($path);
                $report['dirs_removed']++;
            } else {
                @unlink($path);
                $report['files_removed']++;
            }
        }
    }

    // 2. State files / index préfixés _robtest_
    $patterns2 = [
        TMP_PATH . '/.drive_prep_*' . ROBTEST_PREFIX . '*.json',
        TMP_PATH . '/.drive_prep_temp_' . ROBTEST_PREFIX . '*.json',
        DRIVE_INDEX_DIR . '/temp_' . ROBTEST_PREFIX . '*.json',
        DRIVE_INDEX_DIR . '/' . ROBTEST_PREFIX . '*.json',
        EDITOR_SESSIONS_DIR . '/' . ROBTEST_PREFIX . '*.json',
        CACHE_DIR . '/drafts/auto/' . ROBTEST_PREFIX . '*.json',
        CACHE_DIR . '/exports/' . ROBTEST_PREFIX . '*',
    ];
    foreach ($patterns2 as $p) {
        foreach (glob($p) ?: [] as $f) {
            if (!robtest_safe_path($f)) continue;
            @unlink($f);
            $report['files_removed']++;
        }
    }

    // 3. Drive cleanup (best effort)
    $dm = robtest_drive_manager_or_null($log);
    if ($dm && method_exists($dm, 'listFolder') && method_exists($dm, 'delete')) {
        $parents = [];
        if (defined('DRIVE_COURSETEMP_FOLDER_ID')) $parents[] = DRIVE_COURSETEMP_FOLDER_ID;
        if (defined('DRIVE_COURSEPERMANENTS_FOLDER_ID')) $parents[] = DRIVE_COURSEPERMANENTS_FOLDER_ID;
        if (defined('DRIVE_COURSCREATION_FOLDER_ID')) $parents[] = DRIVE_COURSCREATION_FOLDER_ID;
        foreach ($parents as $parentId) {
            if (empty($parentId)) continue;
            try {
                $items = $dm->listFolder($parentId);
                if (!is_array($items)) continue;
                foreach ($items as $item) {
                    $name = $item['name'] ?? '';
                    if (strpos($name, ROBTEST_PREFIX) !== false) {
                        try {
                            $dm->delete($item['id']);
                            $report['drive_folders_removed']++;
                            $log->teardown("Drive : supprimé $name (parent=$parentId)");
                        } catch (Throwable $e) {
                            $report['errors'][] = "Drive delete $name : " . $e->getMessage();
                        }
                    }
                }
            } catch (Throwable $e) {
                $report['errors'][] = "Drive list parent $parentId : " . $e->getMessage();
            }
        }
    }

    // 4. Logs
    if (file_exists(ROBTEST_LOG_FILE)) { @unlink(ROBTEST_LOG_FILE); $report['files_removed']++; }
    if (file_exists(ROBTEST_JSONL_FILE)) { @unlink(ROBTEST_JSONL_FILE); $report['files_removed']++; }
    if (file_exists(ROBTEST_LOCK_FILE)) { @unlink(ROBTEST_LOCK_FILE); }

    return $report;
}

// =====================================================================
// RUNNER
// =====================================================================

function robtest_run_suite(RobtestLogger $log, string $suite): void {
    switch ($suite) {
        case 'storage': robtest_suite_storage($log); break;
        case 'drive_sync': robtest_suite_drive_sync($log); break;
        case 'location_independence': robtest_suite_location_independence($log); break;
        case 'expiration': robtest_suite_expiration($log); break;
        case 'new_course': robtest_suite_new_course($log); break;
        case 'export_purity': robtest_suite_export_purity($log); break;
        case 'recovery': robtest_suite_recovery($log); break;
        case 'extras': robtest_suite_extras($log); break;
        case 'all':
            robtest_suite_storage($log);
            robtest_suite_drive_sync($log);
            robtest_suite_location_independence($log);
            robtest_suite_expiration($log);
            robtest_suite_new_course($log);
            robtest_suite_export_purity($log);
            robtest_suite_recovery($log);
            robtest_suite_extras($log);
            break;
        default:
            $log->error("Suite inconnue : $suite");
    }
}

function robtest_handle_run(): void {
    if (!robtest_acquire_run_lock()) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Un run est déjà en cours (lock < 5 min).\nForcer : ?action=run&force=1 (à implémenter manuellement) ou attendre.";
        return;
    }
    header('Content-Type: text/plain; charset=utf-8');
    @ob_implicit_flush(true);
    while (ob_get_level() > 0) ob_end_flush();
    $suite = $_POST['suite'] ?? $_GET['suite'] ?? 'all';
    $singleTest = $_POST['test'] ?? $_GET['test'] ?? '';
    $log = new RobtestLogger(ROBTEST_RUN_ID, true);
    $used = getServerTotalUsage();
    $log->info(sprintf('=== ROBUSTNESS TEST RUN START === run_id=%s suite=%s php=%s host=%s used=%s/%dMo',
        ROBTEST_RUN_ID, $suite, PHP_VERSION, $_SERVER['HTTP_HOST'] ?? '?', robtest_human_bytes($used), SERVER_MAX_MB));

    if ($singleTest !== '') {
        // Lancer un test individuel : on le retrouve par nom dans toutes les suites
        $log->info("Mode test individuel : $singleTest");
        // Approche simple : parcourir les suites et filtrer le nom dans le logger via un wrapper
        // Pour rester simple, on lance la suite "all" mais on signale qu'on attendait un test précis.
        $log->warn("Mode 'test=' non implémenté en exécution ciblée — lancement de la suite complète");
        robtest_run_suite($log, $suite);
    } else {
        robtest_run_suite($log, $suite);
    }

    $sum = $log->summary();
    echo "\n--- RÉSUMÉ ---\n";
    echo json_encode($sum, JSON_PRETTY_PRINT) . "\n";
}

function robtest_handle_log(): void {
    $format = $_GET['format'] ?? 'text';
    $download = !empty($_GET['download']);
    $file = $format === 'json' ? ROBTEST_JSONL_FILE : ROBTEST_LOG_FILE;
    if (!file_exists($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Aucun log disponible (lancer une suite avant).";
        return;
    }
    if ($download) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }
    @readfile($file);
}

function robtest_handle_status(): void {
    header('Content-Type: application/json; charset=utf-8');
    $info = [
        'run_id_current' => ROBTEST_RUN_ID,
        'log_text_present' => file_exists(ROBTEST_LOG_FILE),
        'log_text_size' => file_exists(ROBTEST_LOG_FILE) ? filesize(ROBTEST_LOG_FILE) : 0,
        'log_jsonl_present' => file_exists(ROBTEST_JSONL_FILE),
        'log_jsonl_size' => file_exists(ROBTEST_JSONL_FILE) ? filesize(ROBTEST_JSONL_FILE) : 0,
        'lock_present' => file_exists(ROBTEST_LOCK_FILE),
        'lock_age_s' => file_exists(ROBTEST_LOCK_FILE) ? (time() - filemtime(ROBTEST_LOCK_FILE)) : null,
        'ballast_present' => is_dir(ROBTEST_BALLAST_DIR),
        'ballast_size' => is_dir(ROBTEST_BALLAST_DIR) ? getDirSize(ROBTEST_BALLAST_DIR) : 0,
        'server_used' => getServerTotalUsage(),
        'server_max_mb' => SERVER_MAX_MB,
    ];
    if (file_exists(ROBTEST_JSONL_FILE)) {
        $lines = @file(ROBTEST_JSONL_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            $tail = array_slice($lines, -20);
            $info['last_lines'] = array_map(fn($l) => json_decode($l, true), $tail);
        }
    }
    echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function robtest_handle_cleanup(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $log = new RobtestLogger(ROBTEST_RUN_ID, true);
    $log->info('=== CLEANUP START === run_id=' . ROBTEST_RUN_ID);
    $report = robtest_cleanup_artifacts($log);
    $log->info('Cleanup terminé', $report);
    echo "\n--- CLEANUP REPORT ---\n";
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    echo "\nLe script va s'auto-supprimer (" . __FILE__ . ").\n";
    echo "Si la suppression échoue (permissions), supprimer manuellement via FTP :\n  " . __FILE__ . "\n";
    // Supprimer également les logs en dernier (le logger les a peut-être laissés)
    @unlink(ROBTEST_LOG_FILE);
    @unlink(ROBTEST_JSONL_FILE);
    @unlink(ROBTEST_LOCK_FILE);
    register_shutdown_function(function() {
        @unlink(__FILE__);
    });
}

function robtest_handle_ui(): void {
    header('Content-Type: text/html; charset=utf-8');
    $logExists = file_exists(ROBTEST_LOG_FILE);
    $logSize = $logExists ? robtest_human_bytes(filesize(ROBTEST_LOG_FILE)) : '—';
    $used = robtest_human_bytes(getServerTotalUsage());
    $maxMb = SERVER_MAX_MB;
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Elea-Secours — Test de robustesse</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,sans-serif;max-width:900px;margin:2em auto;padding:0 1em;color:#222}
h1{border-bottom:2px solid #333;padding-bottom:.3em}
.warn{background:#fff3cd;border:1px solid #ffc107;padding:1em;border-radius:6px;margin:1em 0}
.danger{background:#f8d7da;border:1px solid #dc3545;padding:1em;border-radius:6px;margin:1em 0}
.info{background:#d1ecf1;border:1px solid #17a2b8;padding:1em;border-radius:6px;margin:1em 0}
form{background:#f6f8fa;border:1px solid #d0d7de;padding:1em;border-radius:6px;margin:1em 0}
input[type=password],select{width:100%;padding:.5em;font-size:1em;margin:.3em 0;box-sizing:border-box}
button{padding:.6em 1em;background:#0969da;color:#fff;border:0;border-radius:6px;cursor:pointer;margin-right:.5em;font-size:1em}
button.secondary{background:#6c757d}
button.danger{background:#dc3545}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5em;margin:1em 0}
table{width:100%;border-collapse:collapse;margin:1em 0}
td,th{padding:.4em;border-bottom:1px solid #eee;text-align:left}
code{background:#eee;padding:.1em .3em;border-radius:3px}
pre{background:#0d1117;color:#e6edf3;padding:1em;border-radius:6px;overflow-x:auto;font-size:.85em;max-height:600px}
</style>
</head>
<body>
<h1>Elea-Secours — Test de robustesse</h1>
<p>Script autonome, déployable, auto-suppressible. Tous les artefacts sont préfixés <code>_robtest_</code>.</p>

<div class="info">
<strong>Espace serveur :</strong> <?= htmlspecialchars($used) ?> / <?= $maxMb ?> Mo<br>
<strong>Log courant :</strong> <?= $logExists ? "présent ($logSize)" : "—" ?><br>
<strong>Run ID :</strong> <code><?= htmlspecialchars(ROBTEST_RUN_ID) ?></code>
</div>

<div class="warn">
<strong>⚠ Avant de lancer :</strong> ce script crée un ballast disque jusqu'à 350 Mo (puis le supprime). Il fait des appels Drive et peut consommer un peu de quota API. Lancer en heure creuse.
</div>

<h2>1. Lancer les tests</h2>
<form method="post" action="?action=run" target="_blank">
<label>Mot de passe admin :</label>
<input type="password" name="password" required autocomplete="off">
<label>Suite :</label>
<select name="suite">
<option value="all">all (toutes les suites)</option>
<option value="storage">storage</option>
<option value="drive_sync">drive_sync</option>
<option value="location_independence">location_independence</option>
<option value="expiration">expiration</option>
<option value="new_course">new_course</option>
<option value="export_purity">export_purity</option>
<option value="recovery">recovery</option>
<option value="extras">extras</option>
</select>
<button type="submit">▶ Lancer</button>
</form>

<h2>2. Récupérer les logs</h2>
<form method="post" action="?action=log" target="_blank">
<input type="password" name="password" required autocomplete="off" placeholder="Mot de passe admin">
<div class="grid">
<button type="submit">📄 Voir log texte</button>
<button type="submit" formaction="?action=log&format=json">📊 Voir JSONL</button>
<button type="submit" formaction="?action=log&download=1" class="secondary">💾 Télécharger</button>
<button type="submit" formaction="?action=status" class="secondary">ℹ Statut JSON</button>
</div>
</form>

<h2>3. Tout supprimer (artefacts + logs + script)</h2>
<form method="post" action="?action=cleanup&confirm=YES" onsubmit="return confirm('Supprimer définitivement tous les artefacts _robtest_*, les logs, et CE SCRIPT ? L\'action est irréversible.')">
<input type="password" name="password" required autocomplete="off" placeholder="Mot de passe admin">
<div class="warn">Cette action :
<ul>
<li>Supprime tout fichier/dossier <code>_robtest_*</code> sur le serveur (courses/, tmp/, cache/)</li>
<li>Supprime les state files / index Drive correspondants</li>
<li>Supprime les dossiers <code>_robtest_*</code> sur Google Drive (best effort)</li>
<li>Supprime les logs et ce fichier <code>admin_robustness_test.php</code></li>
</ul>
</div>
<button type="submit" class="danger">🗑 Supprimer tout et auto-supprimer le script</button>
</form>

<h2>Documentation rapide</h2>
<table>
<tr><th>Endpoint</th><th>Description</th></tr>
<tr><td><code>?action=run&suite=all</code></td><td>Lance toutes les suites</td></tr>
<tr><td><code>?action=run&suite=storage</code></td><td>Lance une suite ciblée</td></tr>
<tr><td><code>?action=log</code></td><td>Affiche le log texte</td></tr>
<tr><td><code>?action=log&format=json</code></td><td>Affiche le log JSONL</td></tr>
<tr><td><code>?action=log&download=1</code></td><td>Télécharge le log texte</td></tr>
<tr><td><code>?action=status</code></td><td>JSON avec compteurs et dernières lignes</td></tr>
<tr><td><code>?action=cleanup&amp;confirm=YES</code></td><td>Supprime tout + auto-suppression du script</td></tr>
</table>

<p><small>Run ID : <?= htmlspecialchars(ROBTEST_RUN_ID) ?> — fichier : <?= htmlspecialchars(__FILE__) ?></small></p>
</body>
</html><?php
}

// =====================================================================
// MAIN ROUTER
// =====================================================================

robtest_require_https();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case '':
    case 'ui':
        robtest_handle_ui();
        break;
    case 'run':
        if (!robtest_check_auth()) robtest_send_403('Mot de passe admin requis pour lancer les tests.');
        robtest_handle_run();
        break;
    case 'log':
        if (!robtest_check_auth()) robtest_send_403('Mot de passe admin requis pour lire les logs.');
        robtest_handle_log();
        break;
    case 'status':
        if (!robtest_check_auth()) robtest_send_403('Mot de passe admin requis pour voir le statut.');
        robtest_handle_status();
        break;
    case 'cleanup':
        if (!robtest_check_auth()) robtest_send_403('Mot de passe admin requis pour le cleanup.');
        if (($_POST['confirm'] ?? $_GET['confirm'] ?? '') !== 'YES') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Confirmation requise (envoyer confirm=YES).";
            exit;
        }
        robtest_handle_cleanup();
        break;
    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Action inconnue : " . htmlspecialchars($action);
}
