<?php
/**
 * Expiration custom de session (contournement du bridage OVH).
 *
 * Sur OVH mutualisé, session.gc_maxlifetime est forcé à 1440s (24 min) malgré
 * ini_set('session.gc_maxlifetime', 28800). On gère l'expiration côté application
 * via un timestamp $_SESSION['elea_login_at'] vérifié à chaque requête authentifiée.
 *
 * Usage :
 *   - Pages HTML (index.php, editor.php, view.php, upload.php, etc.) :
 *       require_once .../includes/session_check.php;
 *       enforceSessionExpiry();
 *   - API JSON (api/*.php) :
 *       require_once .../includes/session_check.php;
 *       enforceSessionExpiryJson();
 *
 * Au login (dans index.php), penser à setter $_SESSION['elea_login_at'] = time().
 */

if (!defined('ELEA_SESSION_HOURS')) {
    define('ELEA_SESSION_HOURS', 8);
}

/**
 * Retourne true si la session est encore valide (login récent < 8h).
 * Si elea_login_at est absent (ancienne session avant déploiement), on l'initialise
 * à maintenant pour donner une marge de 8h aux sessions actives.
 */
function checkSessionExpiry(): bool {
    if (!isset($_SESSION['elea_access']) || $_SESSION['elea_access'] !== true) {
        return false;
    }
    if (!isset($_SESSION['elea_login_at'])) {
        $_SESSION['elea_login_at'] = time();
        return true;
    }
    return (time() - (int)$_SESSION['elea_login_at']) < (ELEA_SESSION_HOURS * 3600);
}

/**
 * Détruit la session expirée et redirige vers le login.
 * Pour les pages HTML uniquement. Si la session n'est pas authentifiée, ne fait rien.
 */
function enforceSessionExpiry(string $redirect = 'index.php?session_expired=1'): void {
    if (!isset($_SESSION['elea_access'])) return; // visiteur anonyme : laisser passer
    if (checkSessionExpiry()) return; // session valide

    // Session expirée : nettoyer et rediriger
    unset($_SESSION['elea_access'], $_SESSION['elea_admin'], $_SESSION['elea_login_at'], $_SESSION['editor_session_id']);
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Variante pour les endpoints JSON : retourne 401 + JSON {success:false, error:'session_expired'}
 * au lieu de rediriger.
 */
function enforceSessionExpiryJson(): void {
    if (!isset($_SESSION['elea_access'])) return; // visiteur anonyme : laisser passer
    if (checkSessionExpiry()) return; // session valide

    unset($_SESSION['elea_access'], $_SESSION['elea_admin'], $_SESSION['elea_login_at'], $_SESSION['editor_session_id']);
    if (!headers_sent()) {
        http_response_code(401);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'session_expired', 'message' => 'Session expirée, veuillez vous reconnecter.']);
    exit;
}
