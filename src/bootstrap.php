<?php
/**
 * Bootstrap: loaded at the top of every page in public/.
 * Sets paths, reads .env, secure session + security headers, DB connection, helpers, i18n.
 */
define('APP_ROOT', dirname(__DIR__));
define('TEMPLATES_DIR', APP_ROOT . '/templates');
define('STORAGE_DIR', APP_ROOT . '/storage');

// Local settings from .env (XAMPP / Docker). On Azure the same names come from "Environment variables".
// Values already set by the server always win, and .env is never inside public/.
if (is_file(APP_ROOT . '/.env')) {
    foreach (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m) && $line[0] !== '#' && getenv($m[1]) === false) {
            putenv($m[1] . '=' . trim($m[2], "\"'"));
        }
    }
}
// Στο Azure (APP_ENV=production) τα τεχνικά λάθη ΔΕΝ εμφανίζονται στους επισκέπτες
if (getenv('APP_ENV') !== 'development' && getenv('APP_ENV') !== false || getenv('WEBSITE_SITE_NAME') !== false) {
    ini_set('display_errors', '0');      // online (Azure): ποτέ τεχνικά λάθη στην οθόνη
}

// Είμαστε σε https; (Στο Azure το https το «κρατάει» ένας ενδιάμεσος server και μας το λέει με header)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (getenv('TRUST_PROXY') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $is_https,   // στο https το cookie δεν στέλνεται ποτέ χωρίς κρυπτογράφηση
]);
session_start();

// Security headers: οδηγίες προς τον browser για να προστατεύει τη σελίδα
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($is_https) {
    header('Strict-Transport-Security: max-age=31536000');   // ο browser να μπαίνει ΠΑΝΤΑ με https
}
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://challenges.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; "
    . "media-src 'self'; "
    . "frame-src https://www.youtube-nocookie.com https://challenges.cloudflare.com; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'");

require __DIR__ . '/database.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/i18n.php';

// Ελληνικά / English: η σελίδα μεταφράζεται λίγο πριν σταλεί (βλ. i18n.php)
ob_start('i18n_page');
current_lang();

// Αυτόματη αποσύνδεση μετά από 30 λεπτά χωρίς κίνηση
$session_timeout = 1800;

// Αλλαγή κωδικού = αποσύνδεση από ΟΛΕΣ τις άλλες συσκευές.
// Κάθε μέλος έχει έναν αριθμό «έκδοσης» (session_version) που μεγαλώνει όταν αλλάζει ο κωδικός.
// Αν το session μιας συσκευής έχει παλιό αριθμό, η συσκευή αποσυνδέεται.
if (is_logged_in()) {
    $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id = ?');
    $stmt->execute([current_user_id()]);
    $current_version = $stmt->fetchColumn();
    if ($current_version === false) {
        $_SESSION = [];                                   // ο λογαριασμός διαγράφηκε
        session_regenerate_id(true);
    } elseif (!isset($_SESSION['sv'])) {
        $_SESSION['sv'] = (int) $current_version;
    } elseif ((int) $_SESSION['sv'] !== (int) $current_version) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Ο κωδικός του λογαριασμού άλλαξε, γι\'αυτό αποσυνδεθήκαμε από αυτή τη συσκευή. Συνδέσου ξανά.';
    }
}

if (is_logged_in()) {
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $session_timeout) {
        log_event($pdo, 'session_timeout', current_user_id());
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Αποσυνδέθηκες αυτόματα μετά από 30 λεπτά αδράνειας.';
    } elseif (empty($passive_request)) {
        // Οι αυτόματοι έλεγχοι (π.χ. notifications.php) ΔΕΝ μετράνε ως κίνηση,
        // αλλιώς ένα ανοιχτό tab δεν θα αποσυνδεόταν ποτέ.
        $_SESSION['last_activity'] = time();
        $stmt = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
        $stmt->execute([current_user_id()]);
    }
}
