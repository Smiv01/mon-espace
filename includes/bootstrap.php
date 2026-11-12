<?php

// Point d'entrée commun : configuration, base de données, session, sécurité.

define('APP_ROOT', dirname(__DIR__));
// Toutes les dates sont écrites par PHP dans ce fuseau, pas par MySQL.
date_default_timezone_set('Africa/Abidjan');
$GLOBALS['config'] = require APP_ROOT . '/config/config.php';

// --- Compatibilité PHP 5.5 (WAMP ancien) : fonctions absentes avant PHP 7 -------------------
// Ces fonctions ne sont définies que si PHP ne les fournit pas déjà.
if (!function_exists('random_bytes')) {
    function random_bytes($length)
    {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes === false || !$strong) {
            throw new Exception('Source aléatoire sécurisée indisponible (extension openssl requise).');
        }
        return $bytes;
    }
}
if (!function_exists('random_int')) {
    function random_int($min, $max)
    {
        $range = $max - $min + 1;
        do {
            $n = hexdec(bin2hex(random_bytes(4)));
            $limit = 4294967296 - (4294967296 % $range); // évite le biais du modulo
        } while ($n >= $limit);
        return $min + ($n % $range);
    }
}
if (!function_exists('hash_equals')) {
    function hash_equals($known, $user)
    {
        if (strlen($known) !== strlen($user)) {
            return false;
        }
        $diff = 0;
        for ($i = 0, $n = strlen($known); $i < $n; $i++) {
            $diff |= ord($known[$i]) ^ ord($user[$i]);
        }
        return $diff === 0;
    }
}

/** Lit une valeur de tableau avec une valeur par défaut (remplace l'opérateur ?? de PHP 7). */
function opt($array, $key, $default = null)
{
    return (is_array($array) && isset($array[$key])) ? $array[$key] : $default;
}

function cfg($path, $default = null)
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

// --- En-têtes de sécurité ----------------------------------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

// --- Chemin de base (fonctionne dans un sous-dossier de WAMP) ------------------
function base_path()
{
    static $base = null;
    if ($base === null) {
        $docRoot = rtrim(str_replace('\\', '/', (string)(opt($_SERVER, 'DOCUMENT_ROOT', ''))), '/');
        $appRoot = str_replace('\\', '/', APP_ROOT);
        $base = ($docRoot !== '' && strpos($appRoot, $docRoot) === 0)
            ? substr($appRoot, strlen($docRoot))
            : '';
    }
    return $base;
}

function url($path = '')
{
    return base_path() . '/' . ltrim($path, '/');
}

// --- Échappement et utilitaires ---------------------------------------------------
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect($path)
{
    header('Location: ' . url($path));
    exit;
}

function now()
{
    return date('Y-m-d H:i:s');
}

function in_minutes($minutes)
{
    return date('Y-m-d H:i:s', time() + $minutes * 60);
}

function client_ip()
{
    return substr((string)(opt($_SERVER, 'REMOTE_ADDR', '0.0.0.0')), 0, 45);
}

// --- Base de données -------------------------------------------------------------
/** Affiche un message clair (au lieu d'une erreur technique) quand la base est inaccessible, puis s'arrête. */
function db_fail($ex)
{
    error_log('Connexion base de données : ' . $ex->getMessage());
    http_response_code(503);
    // PDO::getCode() renvoie un SQLSTATE (ex. HY000) : le code MySQL (ex. 1049) est dans le message.
    $code = preg_match('/\\[(\\d{4})\\]/', $ex->getMessage(), $m) ? (int)$m[1] : 0;
    $isInstallPage = basename(opt($_SERVER, 'SCRIPT_NAME', '')) === 'install.php';
    $dev = cfg('env') === 'dev';

    if ($code === 1049 && $dev && !$isInstallPage) {
        $msg = 'La base de données « ' . cfg('db.name') . ' » n\'existe pas encore.';
        $help = '<a href="' . htmlspecialchars(url('install.php'), ENT_QUOTES, 'UTF-8') . '">Clique ici pour l\'installer</a> (une seule fois).';
    } elseif ($dev) {
        $msg = 'Impossible de joindre MySQL.';
        $help = 'Vérifie que WAMP est démarré (icône verte) et que les réglages de config/config.php sont corrects.';
    } else {
        $msg = 'Service momentanément indisponible.';
        $help = 'Réessaie dans quelques instants.';
    }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Service indisponible</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(url('assets/style.css'), ENT_QUOTES, 'UTF-8') . '"></head><body>'
        . '<main class="auth-wrap"><div class="auth-card card"><h1>Un instant</h1>'
        . '<div class="form-error" role="alert">' . $msg . '</div><p class="muted">' . $help . '</p></div></main></body></html>';
    exit;
}

function db($withDatabase = true)
{
    static $pdo = null, $pdoNoDb = null;
    $c = cfg('db');
    try {
        if (!$withDatabase) {
            if ($pdoNoDb === null) {
                $pdoNoDb = new PDO(
                    "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
                    $c['user'], $c['pass'],
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
                );
            }
            return $pdoNoDb;
        }
        if ($pdo === null) {
            $pdo = new PDO(
                "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
                $c['user'], $c['pass'],
                array(
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                )
            );
        }
        return $pdo;
    } catch (PDOException $ex) {
        db_fail($ex);
    }
}

// --- Session ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_name('mon_espace_sid');
    // SameSite n'est géré nativement qu'à partir de PHP 7.3 ; sur PHP plus ancien on l'ajoute au chemin du cookie.
    $cookiePath = PHP_VERSION_ID >= 70300 ? '/' : '/; SameSite=Lax';
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => $cookiePath, 'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, $cookiePath, '', !empty($_SERVER['HTTPS']), true);
    }
    session_start();
}

// --- CSRF ---------------------------------------------------------------------
function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid()
{
    $sent = opt($_POST, 'csrf', '');
    return is_string($sent) && hash_equals(csrf_token(), $sent);
}

function is_post()
{
    return (opt($_SERVER, 'REQUEST_METHOD', '')) === 'POST';
}

// --- Messages éphémères -----------------------------------------------------------
function flash($type, $message)
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes()
{
    $f = opt($_SESSION, 'flash', []);
    unset($_SESSION['flash']);
    return $f;
}

// --- Authentification ------------------------------------------------------------
function session_fingerprint()
{
    return hash('sha256', session_id());
}

function login_user($userId)
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $days = (int)cfg('security.session_days', 30);
    $stmt = db()->prepare(
        'INSERT INTO user_sessions (user_id, session_hash, user_agent, ip_address, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        session_fingerprint(),
        substr((string)(opt($_SERVER, 'HTTP_USER_AGENT', '')), 0, 255),
        client_ip(),
        in_minutes($days * 24 * 60),
    ]);
}

/** Retourne l'utilisateur connecté (avec profil) ou null. Vérifie que la session n'a pas été révoquée. */
function current_user()
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $userId = opt($_SESSION, 'user_id', null);
    if (!$userId) {
        return $cache = null;
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.email, p.first_name, p.last_name, p.avatar_path, pr.timezone, pr.theme
           FROM user_sessions s
           JOIN users u ON u.id = s.user_id
           LEFT JOIN profiles p ON p.user_id = u.id
           LEFT JOIN user_preferences pr ON pr.user_id = u.id
          WHERE s.session_hash = ? AND s.user_id = ? AND s.expires_at > ?'
    );
    $stmt->execute([session_fingerprint(), $userId, now()]);
    $user = $stmt->fetch();

    if (!$user) {
        // Session révoquée, expirée ou inconnue.
        $_SESSION = [];
        return $cache = null;
    }

    db()->prepare('UPDATE user_sessions SET last_seen_at = ? WHERE session_hash = ?')
        ->execute([now(), session_fingerprint()]);

    return $cache = $user;
}

/** Page où aller après la connexion : uniquement un lien d'invitation valide mémorisé, sinon l'accueil. */
function after_login_target()
{
    $t = isset($_SESSION['after_login']) ? (string)$_SESSION['after_login'] : '';
    unset($_SESSION['after_login']);
    return preg_match('/^rejoindre\.php\?code=[0-9a-f]{32}$/', $t) ? $t : 'accueil.php';
}

function require_login()
{
    $user = current_user();
    if (!$user) {
        redirect('connexion.php');
    }
    return $user;
}

function logout_user()
{
    if (!empty($_SESSION['user_id'])) {
        db()->prepare('DELETE FROM user_sessions WHERE session_hash = ?')
            ->execute([session_fingerprint()]);
    }
    $_SESSION = [];
    session_regenerate_id(true);
}

// --- Validation --------------------------------------------------------------
function normalize_email($email)
{
    return mb_strtolower(trim($email));
}

function password_errors($password)
{
    $errors = [];
    $min = (int)cfg('security.password_min', 10);
    if (mb_strlen($password) < $min) {
        $errors[] = "Le mot de passe doit contenir au moins $min caractères.";
    }
    if (mb_strlen($password) > 128) {
        $errors[] = 'Le mot de passe est trop long.';
    }
    if (!preg_match('/\pL/u', $password)) {
        $errors[] = 'Le mot de passe doit contenir une lettre.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Le mot de passe doit contenir un chiffre.';
    }
    return $errors;
}

// --- Limitation des tentatives -----------------------------------------------------
function too_many_failures($email)
{
    $window = date('Y-m-d H:i:s', time() - (int)cfg('security.lockout_minutes', 15) * 60);
    $max = (int)cfg('security.max_login_failures', 5);
    $stmt = db()->prepare(
        'SELECT
            SUM(email = ?) AS by_email,
            SUM(ip_address = ?) AS by_ip
           FROM login_attempts WHERE created_at > ?'
    );
    $stmt->execute([$email, client_ip(), $window]);
    $row = $stmt->fetch();
    return (int)$row['by_email'] >= $max || (int)$row['by_ip'] >= $max * 4;
}

function record_failure($email)
{
    db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
        ->execute([$email, client_ip()]);
}

function clear_failures($email)
{
    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}

// --- E-mails (développement : stockés, jamais envoyés) -----------------------------------
function send_mail($to, $subject, $body)
{
    if (cfg('env') === 'dev') {
        db()->prepare('INSERT INTO dev_mails (to_email, subject, body) VALUES (?, ?, ?)')
            ->execute([$to, $subject, $body]);
        return;
    }
    // Production : brancher ici un vrai service d'envoi (SMTP, API...).
    error_log('send_mail() non configuré en production.');
}

function full_url($path)
{
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = (string)(opt($_SERVER, 'HTTP_HOST', 'localhost'));
    return $scheme . '://' . $host . url($path);
}

function hash_secret($value)
{
    return hash('sha256', $value);
}
