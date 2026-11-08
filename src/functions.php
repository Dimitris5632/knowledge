<?php
function e($text)
{
    // Στα αγγλικά: οι ετικέτες του site μεταφράζονται, τα κείμενα των μελών μένουν ως έχουν (i18n.php)
    if (function_exists('i18n_protect')) {
        return i18n_protect($text ?? '');
    }
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function e_multiline($text)
{
    return nl2br(e($text));
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

function require_login()
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function check_csrf()
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $sent)) {
        global $pdo;
        if (isset($pdo)) {
            log_event($pdo, 'csrf_failed', current_user_id(), '', basename($_SERVER['SCRIPT_NAME']));
        }
        http_response_code(403);
        die('Μη έγκυρο αίτημα (CSRF). Γύρνα πίσω και ξαναδοκίμασε.');
    }
}

function roles()
{
    return ['philosopher' => 'Φιλόσοφος', 'historian' => 'Ιστορικός'];
}

function role_label($role)
{
    $roles = roles();
    return $roles[$role] ?? '';
}

function categories()
{
    return ['philosophy' => 'Φιλοσοφία', 'history' => 'Ιστορία', 'other' => 'Άλλο'];
}

function category_label($category)
{
    $categories = categories();
    return $categories[$category] ?? '';
}

function security_questions()
{
    return [
        'Ποιο ήταν το όνομα του πρώτου σου κατοικίδιου;',
        'Σε ποια πόλη γεννήθηκε η μητέρα σου;',
        'Ποιος είναι ο αγαπημένος σου φιλόσοφος ή ιστορικός;',
        'Ποιο ήταν το πρώτο βιβλίο που σε συγκίνησε;',
        'Πώς λεγόταν το πρώτο σου σχολείο;',
        'Ποια αρχαία πόλη θα ήθελες να επισκεφτείς;',
        'Ποιο ήταν το παρατσούκλι σου στο σχολείο;',
        'Σε ποιο χωριό ή πόλη περνούσες τα καλοκαίρια σου;',
        'Πώς λεγόταν ο αγαπημένος σου δάσκαλος ή καθηγητής;',
        'Ποια ιστορική μάχη ή γεγονός σε εντυπωσιάζει περισσότερο;',
        'Ποιο ήταν το πρώτο σου κινητό ή το πρώτο σου αυτοκίνητο;',
        'Ποιο είναι το αγαπημένο σου φαγητό από τα παιδικά σου χρόνια;',
        'Ποιο μουσείο σού έμεινε αξέχαστο;',
        'Πώς λέγεται ο καλύτερος φίλος σου από το δημοτικό;',
    ];
}

function normalize_answer($text)
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $accents = ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
                'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ς' => 'σ'];
    return strtr($text, $accents);
}

function too_many_recovery_attempts($pdo)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(id) FROM recovery_attempts
         WHERE ip_address = ? AND success = 0
           AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $stmt->execute([client_ip()]);
    return $stmt->fetchColumn() >= 5;
}

function log_recovery_attempt($pdo, $success)
{
    $stmt = $pdo->prepare('INSERT INTO recovery_attempts (ip_address, success) VALUES (?, ?)');
    $stmt->execute([client_ip(), $success ? 1 : 0]);
}

function stance_label($stance)
{
    $labels = ['for' => 'Υπέρ', 'against' => 'Κατά', 'neutral' => 'Ουδέτερος'];
    return $labels[$stance] ?? '';
}

// Τα κοινά πεδία βιογραφικού: στήλη => [τίτλος, βοήθεια, μέγιστο μήκος]
function cv_fields()
{
    return [
        'biography' => ['Η ιστορία μου', 'Ποιος είσαι, πώς ξεκίνησες, τι σε οδήγησε εδώ', 5000],
        'education' => ['Σπουδές', 'Π.χ. Πτυχίο Ιστορίας & Αρχαιολογίας, ΕΚΠΑ (2018)', 5000],
        'works'     => ['Έργα & δημοσιεύσεις', 'Βιβλία, άρθρα, εργασίες, ομιλίες', 5000],
    ];
}

// Τα πεδία που έχει ΜΟΝΟ ο φιλόσοφος ή ΜΟΝΟ ο ιστορικός, και σε ποιον πίνακα είναι
function role_fields($role)
{
    if ($role === 'philosopher') {
        return [
            'table'  => 'philosophers',
            'fields' => [
                'school'         => ['Σχολή ή φιλοσοφικό ρεύμα', 'Π.χ. Υπαρξισμός, Στωικισμός', 150],
                'branches'       => ['Κλάδοι', 'Π.χ. Ηθική, Μεταφυσική, Λογική', 255],
                'influences'     => ['Φιλόσοφοι που με επηρέασαν', 'Π.χ. Αριστοτέλης, Καντ', 5000],
                'main_questions' => ['Ερωτήματα που με απασχολούν', 'Π.χ. Τι είναι η δικαιοσύνη;', 5000],
            ],
        ];
    }
    return [
        'table'  => 'historians',
        'fields' => [
            'period'          => ['Ιστορική περίοδος', 'Π.χ. Βυζαντινή περίοδος', 150],
            'region'          => ['Γεωγραφική περιοχή', 'Π.χ. Μικρά Ασία', 150],
            'methodology'     => ['Μέθοδος έρευνας', 'Π.χ. αρχειακή έρευνα, προφορική ιστορία', 255],
            'research_topics' => ['Θέματα έρευνας', 'Τι ερευνάς αυτή την περίοδο', 5000],
        ],
    ];
}

function format_size($bytes)
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return max(1, round($bytes / 1024)) . ' KB';
}

function unread_count($pdo)
{
    $stmt = $pdo->prepare('SELECT COUNT(id) FROM messages WHERE receiver_id = ? AND is_read = 0');
    $stmt->execute([current_user_id()]);
    return (int) $stmt->fetchColumn();
}

function password_rules()
{
    return [
        'length'  => 'Τουλάχιστον 8 χαρακτήρες',
        'upper'   => 'Ένα κεφαλαίο γράμμα (Α-Ω ή A-Z)',
        'lower'   => 'Ένα πεζό γράμμα (α-ω ή a-z)',
        'digit'   => 'Έναν αριθμό (0-9)',
        'special' => 'Ένα σύμβολο, π.χ. ! @ # $ % & ?',
        'common'  => 'Να μην είναι συνηθισμένος κωδικός (π.χ. Password1!)',
    ];
}

// Λέξεις που χρησιμοποιούνται συχνά σε κωδικούς (χωρίς τόνους, πεζά).
// Ο κωδικός απορρίπτεται αν, αφού βγάλουμε αριθμούς και σύμβολα, μένει μία από αυτές.
function common_password_words()
{
    return [
        'password', 'passw', 'pass', 'qwerty', 'qwertyuiop', 'asdfgh', 'zxcvbnm', 'qazwsx', 'abc', 'abcd',
        'admin', 'administrator', 'root', 'user', 'login', 'welcome', 'letmein', 'iloveyou', 'love',
        'monkey', 'dragon', 'football', 'baseball', 'soccer', 'sunshine', 'princess', 'superman',
        'batman', 'master', 'freedom', 'whatever', 'starwars', 'trustno', 'secret', 'changeme', 'test',
        'hello', 'summer', 'winter', 'spring', 'autumn', 'january', 'october', 'december',
        'knowledge', 'plato', 'platon', 'socrates', 'aristotle', 'history', 'philosophy',
        'kodikos', 'agapi', 'ellada', 'hellas', 'kalimera', 'olympiakos', 'panathinaikos', 'aek', 'paok',
        'κωδικος', 'αγαπη', 'ελλαδα', 'καλημερα', 'ολυμπιακος', 'παναθηναικος', 'πλατων',
        'σωκρατης', 'αριστοτελης', 'ιστορια', 'φιλοσοφια', 'γεια', 'γειασου',
    ];
}

function is_common_password($password)
{
    $letters = normalize_answer(preg_replace('/[^\p{L}]/u', '', $password));
    return $letters === '' || in_array($letters, array_map('normalize_answer', common_password_words()), true);
}

function password_errors($password, $username = '')
{
    $checks = [
        'common'  => !is_common_password($password),
        'length'  => mb_strlen($password) >= 8,
        'upper'   => preg_match('/\p{Lu}/u', $password) === 1,
        'lower'   => preg_match('/\p{Ll}/u', $password) === 1,
        'digit'   => preg_match('/[0-9]/', $password) === 1,
        'special' => preg_match('/[^\p{L}0-9\s]/u', $password) === 1,
    ];

    $errors = [];
    foreach (password_rules() as $key => $label) {
        if (!$checks[$key]) {
            $errors[] = 'Λείπει από τον κωδικό: ' . $label . '.';
        }
    }
    if (mb_strlen($username) >= 3 && mb_stripos($password, $username) !== false) {
        $errors[] = 'Ο κωδικός δεν πρέπει να περιέχει το username σου.';
    }
    return $errors;
}

function save_photo($file)
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['name' => null, 'error' => ''];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2097152) {
        return ['name' => null, 'error' => 'Η φωτογραφία πρέπει να είναι έως 2 MB.'];
    }

    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type  = $finfo->file($file['tmp_name']);

    if (!array_key_exists($type, $types) || getimagesize($file['tmp_name']) === false) {
        return ['name' => null, 'error' => 'Η φωτογραφία πρέπει να είναι εικόνα JPG, PNG ή WEBP.'];
    }

    $name = bin2hex(random_bytes(16)) . '.' . $types[$type];
    if (!move_uploaded_file($file['tmp_name'], media_path('photos', $name))) {
        return ['name' => null, 'error' => 'Δεν ήταν δυνατή η αποθήκευση της φωτογραφίας.'];
    }
    return ['name' => $name, 'error' => ''];
}

function avatar($user, $size = 'small')
{
    if (!empty($user['photo'])) {
        return '<img class="avatar avatar-' . $size . '" src="' . e(media_url('photos', $user['photo'])) . '" alt="">';
    }
    $initial = mb_strtoupper(mb_substr($user['full_name'] ?? '?', 0, 1));
    return '<span class="avatar avatar-' . $size . ' avatar-empty">' . e($initial) . '</span>';
}

function current_user($pdo)
{
    if (!is_logged_in()) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, username, full_name, photo, is_admin, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([current_user_id()]);
    return $stmt->fetch() ?: null;
}

function nav_link($href, $label)
{
    $current = basename($_SERVER['SCRIPT_NAME']);
    $active  = ($current === $href) ? ' class="active"' : '';
    return '<a href="' . $href . '"' . $active . '>' . $label . '</a>';
}

function youtube_id($url)
{
    $pattern = '~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~';
    if (preg_match($pattern, $url, $match)) {
        return $match[1];
    }
    return null;
}

// Η IP του επισκέπτη. Στο Azure όλοι φαίνονται να έρχονται από την IP του Azure,
// και η πραγματική IP έρχεται σε header. Τον εμπιστευόμαστε ΜΟΝΟ αν TRUST_PROXY=1,
// αλλιώς ένας επιτιθέμενος θα έστελνε ψεύτικο header για να γλιτώσει το κλείδωμα.
function client_ip()
{
    if (getenv('TRUST_PROXY') === '1') {
        $forwarded  = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $candidates = [$_SERVER['HTTP_X_CLIENT_IP'] ?? '', $forwarded[0]];
        foreach ($candidates as $ip) {
            $ip = trim($ip);
            // Το Azure μερικές φορές γράφει και τη θύρα: 1.2.3.4:56789 ή [2a02::1]:56789
            if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $match) || preg_match('/^\[(.+)\]:\d+$/', $ip, $match)) {
                $ip = $match[1];
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Πού αποθηκεύονται τα έγγραφα της βιβλιοθήκης.
// XAMPP: ο φάκελος uploads (κλειδωμένος με .htaccess).
// Azure: φάκελος ΕΞΩ από το site (UPLOAD_DIR=/home/data/uploads), γιατί εκεί το .htaccess δεν δουλεύει.
function upload_path($name = '')
{
    $dir = rtrim(getenv('UPLOAD_DIR') ?: STORAGE_DIR . '/uploads', '/\\');
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir . '/' . $name;
}

// ---------- Ιστορικό ασφαλείας ----------

function log_event($pdo, $event, $user_id = null, $username = '', $details = '')
{
    $stmt = $pdo->prepare(
        'INSERT INTO security_log (user_id, username, event, ip_address, user_agent, details)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user_id,
        mb_substr($username, 0, 30),
        $event,
        client_ip(),
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        mb_substr($details, 0, 255),
    ]);
}

function event_labels()
{
    return [
        'register'           => 'Εγγραφή',
        'login_success'      => 'Επιτυχής σύνδεση',
        'login_failed'       => 'Αποτυχημένη σύνδεση',
        'login_locked'       => 'Μπλοκαρίστηκε σύνδεση (πολλά λάθη)',
        'logout'             => 'Αποσύνδεση',
        'session_timeout'    => 'Αυτόματη αποσύνδεση (αδράνεια)',
        'password_reset'     => 'Αλλαγή κωδικού με ερώτηση ασφαλείας',
        'recovery_failed'    => 'Λάθος απάντηση στην ερώτηση ασφαλείας',
        'username_recovered' => 'Ανάκτηση username',
        'upload_rejected'    => 'Απορρίφθηκε ύποπτο αρχείο',
        'csrf_failed'        => 'Μπλοκαρίστηκε αίτημα CSRF',
        '2fa_enabled'        => 'Ενεργοποίηση 2FA',
        '2fa_disabled'       => 'Απενεργοποίηση 2FA',
        '2fa_failed'         => 'Λάθος κωδικός 2FA',
        'admin_unlock'       => 'Ξεκλείδωμα IP από διαχειριστή',
        'password_changed'   => 'Αλλαγή κωδικού από τις ρυθμίσεις',
        'email_changed'      => 'Αλλαγή email',
        'photo_changed'      => 'Αλλαγή φωτογραφίας',
        'question_changed'   => 'Αλλαγή ερώτησης ασφαλείας',
        'account_deleted'    => 'Διαγραφή λογαριασμού',
        'link_rejected'      => 'Μπλοκαρίστηκε ύποπτος σύνδεσμος',
        'email_code_sent'    => 'Στάλθηκε κωδικός στο email',
        'email_code_failed'  => 'Αποτυχία αποστολής email',
        'email_code_limit'   => 'Πάρα πολλά αιτήματα κωδικού email',
        'email_code_wrong'   => 'Λάθος κωδικός email',
        'email_verified'     => 'Επιβεβαίωση email',
        'pin_enabled'        => 'Ενεργοποίηση PIN σε συσκευή',
        'pin_removed'        => 'Αφαίρεση PIN από συσκευή',
        'pin_failed'         => 'Λάθος PIN',
        'pin_locked'         => 'Ακυρώθηκε PIN (πολλά λάθη)',
        'doc_flagged'        => 'Έγγραφο σε έλεγχο νομιμότητας',
        'doc_approved'       => 'Έγκριση εγγράφου',
        'doc_rejected'       => 'Απόρριψη εγγράφου',
        'doc_blocked'        => 'Μπλοκαρίστηκε αρχείο (μαύρη λίστα)',
        'doc_reported'       => 'Αναφορά παραβίασης εγγράφου',
        'bot_blocked'        => 'Μπλοκαρίστηκε bot (Cloudflare)',
        'register_existing'  => 'Εγγραφή με email που υπάρχει ήδη',
    ];
}

function event_label($event)
{
    $labels = event_labels();
    return $labels[$event] ?? $event;
}

function is_bad_event($event)
{
    return in_array($event, ['login_failed', 'login_locked', 'recovery_failed', 'upload_rejected', 'csrf_failed', '2fa_failed', 'link_rejected',
                             'email_code_wrong', 'email_code_limit', 'pin_failed', 'pin_locked', 'doc_blocked', 'bot_blocked'], true);
}

function short_browser($agent)
{
    $browsers = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox',
                 'Safari/' => 'Safari', 'curl' => 'curl (πρόγραμμα)', 'python' => 'Python (πρόγραμμα)'];
    foreach ($browsers as $needle => $name) {
        if (stripos($agent, $needle) !== false) {
            return $name;
        }
    }
    return 'Άγνωστο';
}

// ---------- Κλείδωμα μετά από πολλά λάθη ----------

// Κλείδωμα για 15 λεπτά:
//  - 5 λάθη από την ίδια IP (όπως πριν), ή
//  - 10 λάθη στον ίδιο λογαριασμό από ΟΠΟΙΕΣΔΗΠΟΤΕ IP (για επιθέσεις από πολλούς υπολογιστές).
//    Το όριο είναι μεγαλύτερο, ώστε ένας «πειραχτήρι» να μην μπορεί εύκολα να κλειδώσει τον λογαριασμό κάποιου άλλου.
function too_many_login_attempts($pdo, $username = '')
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(id) FROM login_attempts
         WHERE ip_address = ? AND success = 0
           AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $stmt->execute([client_ip()]);
    if ($stmt->fetchColumn() >= 5) {
        return true;
    }
    if ($username !== '') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(id) FROM login_attempts
             WHERE username = ? AND success = 0
               AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
        );
        $stmt->execute([mb_substr($username, 0, 30)]);
        if ($stmt->fetchColumn() >= 10) {
            return true;
        }
    }
    return false;
}

function log_login_attempt($pdo, $username, $success)
{
    $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)');
    $stmt->execute([mb_substr($username, 0, 30), client_ip(), $success ? 1 : 0]);
}

// ---------- Διαχειριστής ----------

function require_admin($pdo)
{
    require_login();
    $me = current_user($pdo);
    if (!$me || !$me['is_admin']) {
        http_response_code(403);
        die('Αυτή η σελίδα είναι μόνο για διαχειριστές.');
    }
    if (logged_in_with_pin()) {
        // Μπήκε με PIN: για τη διαχείριση ζητάμε ξανά τον κωδικό
        header('Location: confirm_password.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME'])));
        exit;
    }
}

// ---------- Έλεγχος δύο βημάτων (TOTP, όπως το Google Authenticator) ----------

function base32_encode($data)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $output .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $output;
}

function base32_decode($text)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($text)) as $char) {
        $position = strpos($alphabet, $char);
        if ($position !== false) {
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $output .= chr(bindec($byte));
        }
    }
    return $output;
}

function totp_new_secret()
{
    return base32_encode(random_bytes(20));
}

// Ο 6ψήφιος κωδικός για ένα «χρονικό βήμα» 30 δευτερολέπτων (RFC 6238)
function totp_code($secret, $step)
{
    $hash   = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secret), true);
    $offset = ord($hash[19]) & 15;
    $number = ((ord($hash[$offset]) & 127) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
    return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
}

// Επιστρέφει το βήμα αν ο κωδικός είναι σωστός, αλλιώς false.
// Δεχόμαστε και το προηγούμενο/επόμενο βήμα (αν το ρολόι του κινητού πάει λίγο μπροστά ή πίσω),
// αλλά ποτέ βήμα που έχει ήδη χρησιμοποιηθεί (προστασία από επανάληψη του ίδιου κωδικού).
function totp_verify($secret, $code, $last_step = 0)
{
    $code = preg_replace('/\s/', '', $code);
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $step = $now + $i;
        if ($step > $last_step && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return false;
}

function totp_uri($secret, $username)
{
    return 'otpauth://totp/' . rawurlencode('Knowledge:' . $username)
         . '?secret=' . $secret . '&issuer=Knowledge';
}

// ---------- Ρητό της ημέρας ----------

// Διαλέγει ένα ρητό που μένει ΙΔΙΟ όλη τη μέρα και αλλάζει την επόμενη.
// Το crc32 μετατρέπει την ημερομηνία (π.χ. "2026-11-01") σε έναν αριθμό.
function quote_of_the_day($pdo)
{
    $total = (int) $pdo->query('SELECT COUNT(id) FROM quotes')->fetchColumn();
    if ($total === 0) {
        return null;
    }
    $position = crc32(date('Y-m-d')) % $total;
    $stmt = $pdo->prepare('SELECT text, author FROM quotes ORDER BY id LIMIT 1 OFFSET ?');
    $stmt->bindValue(1, $position, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch();
}

// ---------- Σελίδες (1, 2, 3 ...) ----------

// Επιστρέφει [τρέχουσα σελίδα, πόσες σελίδες, από ποια γραμμή ξεκινάμε]
function paginate($total, $per_page = 10)
{
    $pages = max(1, (int) ceil($total / $per_page));
    $page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    return [$page, $pages, ($page - 1) * $per_page];
}

// Τα κουμπιά «← Προηγούμενη 1 2 3 Επόμενη →». Κρατάνε την αναζήτηση και τα φίλτρα.
function pagination_links($page, $pages)
{
    if ($pages <= 1) {
        return '';
    }
    $params = $_GET;
    $link = function ($number, $label) use ($params) {
        $params['page'] = $number;
        return '<a href="?' . e(http_build_query($params)) . '">' . $label . '</a>';
    };

    $html = '<div class="pagination">';
    if ($page > 1) {
        $html .= $link($page - 1, '&larr; Προηγούμενη');
    }
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === $page) {
            $html .= '<span class="current">' . $i . '</span>';
        } elseif ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
            $html .= $link($i, (string) $i);
        } elseif (abs($i - $page) === 3) {
            $html .= '<span class="dots">…</span>';
        }
    }
    if ($page < $pages) {
        $html .= $link($page + 1, 'Επόμενη &rarr;');
    }
    return $html . '</div>';
}

// ---------- Αστέρια (κριτικές) ----------

function stars($rating)
{
    $full = (int) round($rating);
    return '<span class="stars" title="' . number_format((float) $rating, 1, ',', '') . ' / 5">'
         . str_repeat('★', $full) . '<span class="stars-empty">' . str_repeat('★', 5 - $full) . '</span></span>';
}

// Ένα έγγραφο ή μια ταινία, για αγαπημένα και κριτικές. Επιστρέφει null αν δεν υπάρχει.
function find_item($pdo, $type, $id)
{
    if ($type === 'document') {
        $stmt = $pdo->prepare('SELECT id, user_id, title, author AS subtitle, mime_type FROM documents d WHERE id = ? AND ' . visible_documents_sql('d'));
    } elseif ($type === 'video') {
        $stmt = $pdo->prepare('SELECT id, user_id, title, creator AS subtitle, NULL AS mime_type FROM videos WHERE id = ?');
    } else {
        return null;
    }
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}

// ---------- Εικόνες στη βιβλιοθήκη ----------

function is_image_type($mime)
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
}

// Ό,τι μπορεί να ανοίξει μέσα στον browser (αντί να κατέβει): PDF και εικόνες
function is_viewable_type($mime)
{
    return $mime === 'application/pdf' || is_image_type($mime);
}

// ---------- Σύνδεσμοι μέσα σε κείμενο ----------

const LINK_PATTERN = '~((?:https?://|www\.)[^\s<>"\']+)~iu';

// Όλοι οι σύνδεσμοι ενός κειμένου
function find_links($text)
{
    preg_match_all(LINK_PATTERN, $text, $matches);
    return array_map('trim_link', $matches[1]);
}

// Τελεία, κόμμα, παρένθεση κ.λπ. στο τέλος δεν ανήκουν στον σύνδεσμο
function trim_link($url)
{
    while ($url !== '' && mb_strpos('.,;:!?)»', mb_substr($url, -1)) !== false) {
        $url = mb_substr($url, 0, -1);
    }
    return $url;
}

// Επιστρέφει ΓΙΑΤΙ ένας σύνδεσμος είναι ύποπτος, ή '' αν είναι εντάξει
function link_problem($url)
{
    $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
    $parts = parse_url($href);
    $host  = strtolower($parts['host'] ?? '');
    $path  = strtolower($parts['path'] ?? '');

    if ($host === '' || isset($parts['user'])) {
        return 'κρύβει το πραγματικό site (π.χ. έχει @ μέσα)';
    }
    if (stripos($href, 'http://') === 0) {
        return 'δεν είναι ασφαλής, γιατί ξεκινά με http αντί για https';
    }
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
        return 'οδηγεί σε αριθμό IP αντί για όνομα site';
    }
    if (strpos($host, 'xn--') !== false || preg_match('/[^a-z0-9.-]/', $host)) {
        return 'έχει γράμματα που μιμούνται άλλα (π.χ. ελληνικό «ο» αντί για λατινικό «o»)';
    }
    $shorteners = ['bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly', 'rb.gy', 'shorturl.at', 'tiny.cc'];
    if (in_array(preg_replace('/^www\./', '', $host), $shorteners, true)) {
        return 'είναι «κοντός» σύνδεσμος που κρύβει πού οδηγεί';
    }
    if (preg_match('/\.(exe|msi|bat|cmd|scr|com|js|vbs|ps1|jar|apk|dmg|iso)$/', $path)) {
        return 'κατεβάζει πρόγραμμα που μπορεί να είναι ιός';
    }
    return '';
}

// Κάνει τους ασφαλείς συνδέσμους (https://..., www....) να πατιούνται.
// Χωρίζουμε το κείμενο σε «συνδέσμους» και «υπόλοιπο κείμενο» και ασφαλίζουμε
// ΚΑΘΕ κομμάτι με e(). Έτσι κανένα <script> ή "javascript:" δεν μπορεί να περάσει.
// Οι ύποπτοι σύνδεσμοι ΔΕΝ πατιούνται: φαίνονται ως κείμενο με προειδοποίηση.
function linkify($text)
{
    $parts = preg_split(LINK_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html  = '';
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            $html .= e($part);             // απλό κείμενο
            continue;
        }
        $url  = trim_link($part);
        $tail = mb_substr($part, mb_strlen($url));
        $problem = link_problem($url);

        if ($problem !== '') {
            $html .= '<span class="bad-link" title="Ύποπτος σύνδεσμος: ' . e($problem) . '">⚠ ' . e($url) . '</span>' . e($tail);
            continue;
        }
        $href  = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
        $html .= '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer nofollow" class="ext-link">'
               . e($url) . '</a>' . e($tail);
    }
    return nl2br($html);
}

// ===== Έλεγχος username: διαθέσιμο ή πιασμένο =====
// Ονόματα που δεν δίνουμε σε κανέναν, για να μην παριστάνει κάποιος τον διαχειριστή.
function reserved_usernames()
{
    return ['admin', 'administrator', 'root', 'system', 'support', 'help', 'moderator', 'mod',
            'knowledge', 'staff', 'security', 'info', 'contact', 'webmaster', 'owner', 'test', 'null'];
}

// Επιστρέφει: 'ok', 'invalid', 'reserved' ή 'taken'.
// Η βάση συγκρίνει χωρίς κεφαλαία/πεζά: το «Nikos» θεωρείται ίδιο με το «nikos».
function username_status($pdo, $username)
{
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return 'invalid';
    }
    if (in_array(strtolower($username), reserved_usernames(), true)) {
        return 'reserved';
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetch() ? 'taken' : 'ok';
}

function username_message($status, $username)
{
    $messages = [
        'ok'       => 'Το username «' . $username . '» είναι διαθέσιμο.',
        'invalid'  => '3-30 λατινικοί χαρακτήρες, αριθμοί ή _ (χωρίς κενά).',
        'reserved' => 'Αυτό το username δεν επιτρέπεται. Διάλεξε άλλο.',
        'taken'    => 'Το username «' . $username . '» το έχει ήδη άλλο μέλος.',
    ];
    return $messages[$status] ?? '';
}

// Προτείνει έως 3 ελεύθερα usernames, π.χ. nikos_ist, nikos27
function username_suggestions($pdo, $username, $role = '')
{
    $base = strtolower(substr(preg_replace('/[^a-zA-Z0-9_]/', '', $username), 0, 24));
    if (strlen($base) < 3) {
        return [];
    }
    $ideas = [];
    if ($role === 'philosopher') { $ideas[] = $base . '_phil'; }
    if ($role === 'historian')   { $ideas[] = $base . '_hist'; }
    $ideas[] = $base . date('y');
    for ($i = 0; $i < 6; $i++) {
        $ideas[] = $base . random_int(10, 999);
    }
    $free = [];
    foreach (array_unique($ideas) as $idea) {
        if (username_status($pdo, $idea) === 'ok') {
            $free[] = $idea;
        }
        if (count($free) === 3) {
            break;
        }
    }
    return $free;
}


// ===== 3 ερωτήσεις ασφαλείας =====
// Κάθε μέλος έχει 3 διαφορετικές ερωτήσεις. Στην ανάκτηση ρωτάμε 2 τυχαίες.
// Οι απαντήσεις αποθηκεύονται μόνο με hash (όπως οι κωδικοί).

const QUESTIONS_PER_USER = 3;

// Οι ερωτήσεις ενός μέλους: [['question' => ..., 'answer_hash' => ...], ...]
// Τα παλιά μέλη (με 1 ερώτηση στον πίνακα users) συνεχίζουν να δουλεύουν.
function user_questions($pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT question, answer_hash FROM user_questions WHERE user_id = ? ORDER BY position');
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        return $rows;
    }
    $stmt = $pdo->prepare('SELECT security_question, security_answer_hash FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $old = $stmt->fetch();
    if ($old && $old['security_question'] !== '') {
        return [['question' => $old['security_question'], 'answer_hash' => $old['security_answer_hash']]];
    }
    return [];
}

// Ελέγχει τις 3 ερωτήσεις από τη φόρμα. Επιστρέφει [λάθη, [[ερώτηση, απάντηση], ...]]
function read_question_set($post, $username = '', $password = '')
{
    $questions = security_questions();
    $errors = [];
    $set = [];
    $used_q = [];
    $used_a = [];
    for ($i = 1; $i <= QUESTIONS_PER_USER; $i++) {
        $q = (int) ($post['q'][$i] ?? -1);
        $a = normalize_answer($post['a'][$i] ?? '');
        if (!array_key_exists($q, $questions)) {
            $errors[] = 'Διάλεξε την ερώτηση ασφαλείας ' . $i . '.';
            continue;
        }
        if (in_array($q, $used_q, true)) {
            $errors[] = 'Οι 3 ερωτήσεις πρέπει να είναι διαφορετικές μεταξύ τους.';
            continue;
        }
        if (mb_strlen($a) < 3) {
            $errors[] = 'Η απάντηση στην ερώτηση ' . $i . ' πρέπει να έχει τουλάχιστον 3 γράμματα.';
            continue;
        }
        if (($username !== '' && $a === normalize_answer($username)) || ($password !== '' && $a === normalize_answer($password))) {
            $errors[] = 'Η απάντηση στην ερώτηση ' . $i . ' δεν πρέπει να είναι ίδια με το username ή το password σου.';
            continue;
        }
        if (in_array($a, $used_a, true)) {
            $errors[] = 'Δώσε διαφορετική απάντηση σε κάθε ερώτηση.';
            continue;
        }
        $used_q[] = $q;
        $used_a[] = $a;
        $set[] = [$questions[$q], $a];
    }
    return [array_values(array_unique($errors)), $set];
}

function save_user_questions($pdo, $user_id, $set)
{
    $pdo->prepare('DELETE FROM user_questions WHERE user_id = ?')->execute([$user_id]);
    $stmt = $pdo->prepare('INSERT INTO user_questions (user_id, position, question, answer_hash) VALUES (?, ?, ?, ?)');
    foreach ($set as $index => [$question, $answer]) {
        $stmt->execute([$user_id, $index + 1, $question, password_hash($answer, PASSWORD_DEFAULT)]);
    }
    // Κρατάμε την 1η και στον πίνακα users, για συμβατότητα με παλιές σελίδες.
    $pdo->prepare('UPDATE users SET security_question = ?, security_answer_hash = ? WHERE id = ?')
        ->execute([$set[0][0], password_hash($set[0][1], PASSWORD_DEFAULT), $user_id]);
}

// Η φόρμα με τις 3 ερωτήσεις (εγγραφή και ρυθμίσεις)
function question_set_fields($old = [])
{
    $questions = security_questions();
    $html = '';
    for ($i = 1; $i <= QUESTIONS_PER_USER; $i++) {
        $chosen = (int) ($old[$i] ?? -1);
        $html .= '<div class="question-pair"><label>' . e('Ερώτηση ασφαλείας') . ' ' . $i . '<select name="q[' . $i . ']" required>'
               . '<option value="">' . e('-- Διάλεξε --') . '</option>';
        foreach ($questions as $index => $text) {
            $html .= '<option value="' . $index . '"' . ($chosen === $index ? ' selected' : '') . '>' . e($text) . '</option>';
        }
        $html .= '</select></label><label>' . e('Απάντηση') . '<input type="text" name="a[' . $i . ']" required maxlength="100" autocomplete="off"></label></div>';
    }
    return $html;
}

// Ανάκτηση: διαλέγουμε 2 τυχαίες ερωτήσεις και τις κρατάμε στο session,
// ώστε ο επισκέπτης να μην μπορεί να «ξαναρίξει» μέχρι να του βγει εύκολη.
function recovery_pick($key, $count_available)
{
    if (!isset($_SESSION[$key]) || max($_SESSION[$key]) >= $count_available) {
        $all = range(0, $count_available - 1);
        shuffle($all);
        $_SESSION[$key] = array_slice($all, 0, min(2, $count_available));
        sort($_SESSION[$key]);
    }
    return $_SESSION[$key];
}

function recovery_answers_ok($list, $picked, $answers)
{
    $ok = true;
    foreach ($picked as $n => $index) {
        // ελέγχουμε όλες τις απαντήσεις (όχι «σταματάμε στο πρώτο λάθος»), για ίδιο χρόνο απόκρισης
        if (!password_verify(normalize_answer($answers[$n] ?? ''), $list[$index]['answer_hash'])) {
            $ok = false;
        }
    }
    return $ok;
}

// ===== Αποστολή email (Brevo) και 6ψήφιοι κωδικοί επιβεβαίωσης =====
// Ρυθμίσεις στο Azure (Environment variables):
//   MAIL_API_KEY   = το API key του Brevo (ΠΟΤΕ μέσα στον κώδικα)
//   MAIL_FROM      = το email αποστολέα που επιβεβαίωσες στο Brevo
//   MAIL_FROM_NAME = προαιρετικό, π.χ. Knowledge
// Στο XAMPP (χωρίς MAIL_API_KEY) το email δεν στέλνεται: ο κωδικός εμφανίζεται στην οθόνη για δοκιμή.

function mail_is_configured()
{
    return getenv('MAIL_API_KEY') && getenv('MAIL_FROM');
}

// Τοπική δοκιμή: ΜΟΝΟ αν το γράφει ρητά APP_ENV=development, ή αν είμαστε στο XAMPP
// (browser στον ίδιο υπολογιστή, χωρίς Azure). Ποτέ σε online site, ακόμα κι αν ξεχαστεί το APP_ENV.
function is_local_dev()
{
    if (getenv('APP_ENV') === 'development') {
        return true;
    }
    return getenv('APP_ENV') === false && getenv('TRUST_PROXY') !== '1' && getenv('WEBSITE_SITE_NAME') === false
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function is_production()
{
    return getenv('APP_ENV') === 'production';
}

function send_mail($to, $subject, $text)
{
    if (!mail_is_configured()) {
        return false;
    }
    $html = '<div style="font-family:Georgia,serif;max-width:520px;margin:auto;padding:24px;background:#f6eee2;color:#2b2118;border-top:6px solid #c8622b">'
          . '<h2 style="margin-top:0;color:#1a1410">Knowledge</h2>'
          . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))
          . '<p style="color:#8a7a66;font-size:12px;margin-top:24px">' . t('Αν δεν το ζήτησες εσύ, αγνόησε αυτό το μήνυμα. Μη δώσεις ποτέ τον κωδικό σε κανέναν.') . '</p></div>';
    $payload = json_encode([
        'sender'      => ['email' => getenv('MAIL_FROM'), 'name' => getenv('MAIL_FROM_NAME') ?: 'Knowledge'],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'textContent' => $text,
        'htmlContent' => $html,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . getenv('MAIL_API_KEY')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status >= 200 && $status < 300;
}

function email_code_texts($purpose)
{
    $texts = [
        'register'     => ['Επιβεβαίωση email', 'Καλώς ήρθες στο Knowledge! Ο κωδικός επιβεβαίωσης του email σου είναι:'],
        'change_email' => ['Επιβεβαίωση νέου email', 'Ζήτησες να αλλάξεις το email του λογαριασμού σου. Ο κωδικός επιβεβαίωσης είναι:'],
        'reset'        => ['Επαναφορά κωδικού', 'Ζήτησες επαναφορά κωδικού. Ο κωδικός επιβεβαίωσης είναι:'],
        'username'     => ['Ανάκτηση username', 'Ζήτησες να βρεις το username σου. Ο κωδικός επιβεβαίωσης είναι:'],
    ];
    return $texts[$purpose];
}

// Φτιάχνει και στέλνει νέο κωδικό. Επιστρέφει ['ok' => bool, 'message' => ..., 'dev_code' => ...]
function send_email_code($pdo, $purpose, $email, $user_id = null)
{
    $ip = client_ip();

    // Όρια: 1 κωδικός ανά 60" για το ίδιο email, 5 την ώρα ανά email, 10 την ώρα ανά IP
    $stmt = $pdo->prepare('SELECT MAX(created_at) AS last_at, COUNT(id) AS n FROM email_codes
                           WHERE email = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row['last_at'] && strtotime($row['last_at']) > time() - 60) {
        return ['ok' => false, 'message' => 'Περίμενε 1 λεπτό πριν ζητήσεις νέο κωδικό.', 'dev_code' => null];
    }
    $stmt = $pdo->prepare('SELECT COUNT(id) FROM email_codes WHERE ip_address = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$ip]);
    if ((int) $row['n'] >= 5 || (int) $stmt->fetchColumn() >= 10) {
        log_event($pdo, 'email_code_limit', $user_id, '', $purpose);
        return ['ok' => false, 'message' => 'Ζητήθηκαν πάρα πολλοί κωδικοί. Δοκίμασε ξανά σε 1 ώρα.', 'dev_code' => null];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Ακυρώνουμε τους προηγούμενους κωδικούς για τον ίδιο σκοπό
    $pdo->prepare('UPDATE email_codes SET used_at = NOW() WHERE email = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$email, $purpose]);
    $pdo->prepare('INSERT INTO email_codes (user_id, email, purpose, code_hash, ip_address, expires_at)
                   VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL 10 MINUTE)')
        ->execute([$user_id, $email, $purpose, password_hash($code, PASSWORD_DEFAULT), $ip]);

    [$subject, $intro] = email_code_texts($purpose);
    $text = t($intro) . "\n\n" . substr($code, 0, 3) . ' ' . substr($code, 3) . "\n\n" . t('Ισχύει για 10 λεπτά.');

    if (send_mail($email, 'Knowledge: ' . t($subject), $text)) {
        log_event($pdo, 'email_code_sent', $user_id, '', $purpose);
        return ['ok' => true, 'message' => 'Σου στείλαμε 6ψήφιο κωδικό στο ' . mask_email($email) . '. Κοίτα και στα ανεπιθύμητα (spam).', 'dev_code' => null];
    }
    if (is_local_dev()) {
        // Τοπική δοκιμή (XAMPP): δεν υπάρχει υπηρεσία email, δείχνουμε τον κωδικό
        return ['ok' => true, 'message' => 'Δοκιμή χωρίς email: ο κωδικός είναι ' . $code, 'dev_code' => $code];
    }
    log_event($pdo, 'email_code_failed', $user_id, '', $purpose);
    return ['ok' => false, 'message' => 'Δεν ήταν δυνατή η αποστολή email. Δοκίμασε ξανά σε λίγο.', 'dev_code' => null];
}

// Ελέγχει τον κωδικό. Επιστρέφει 'ok', 'wrong', 'expired' ή 'locked'
function check_email_code($pdo, $purpose, $email, $code)
{
    $code = preg_replace('/\D/', '', (string) $code);
    $stmt = $pdo->prepare('SELECT id, code_hash, attempts, expires_at FROM email_codes
                           WHERE email = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();
    if (!$row || strtotime($row['expires_at']) < time()) {
        return 'expired';
    }
    if ((int) $row['attempts'] >= 5) {
        return 'locked';
    }
    if (strlen($code) === 6 && password_verify($code, $row['code_hash'])) {
        $pdo->prepare('UPDATE email_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return 'ok';
    }
    $pdo->prepare('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    return (int) $row['attempts'] + 1 >= 5 ? 'locked' : 'wrong';
}

function email_code_error($result)
{
    $messages = [
        'wrong'   => 'Λάθος κωδικός. Έλεγξε το email σου και δοκίμασε ξανά.',
        'expired' => 'Ο κωδικός έληξε ή δεν υπάρχει. Πάτα «Στείλε νέο κωδικό».',
        'locked'  => 'Πάρα πολλά λάθη. Πάτα «Στείλε νέο κωδικό».',
    ];
    return $messages[$result] ?? '';
}

// m***@gmail.com: δείχνουμε σε ποιο email πήγε, χωρίς να το αποκαλύπτουμε όλο
function mask_email($email)
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($name, 0, 1) . str_repeat('*', max(2, mb_strlen($name) - 1)) . '@' . $domain;
}

// Το κουτί για τον 6ψήφιο κωδικό
function code_input($name = 'email_code')
{
    return '<input type="text" name="' . $name . '" class="code-input" required inputmode="numeric" pattern="[0-9 ]{6,7}"'
         . ' maxlength="7" autocomplete="one-time-code" placeholder="000000">';
}

// Ολοκλήρωση σύνδεσης: κοινό για κανονική σύνδεση, μετά την επιβεβαίωση email, μετά το 2FA και με PIN
function finish_login($pdo, $user_id, $username, $note = '', $next = 'index.php')
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user_id;
    $_SESSION['last_activity'] = time();
    $_SESSION['login_method'] = $note === 'με PIN' ? 'pin' : 'password';
    $_SESSION['sv'] = session_version($pdo, $user_id);
    log_event($pdo, 'login_success', (int) $user_id, $username, $note);
    header('Location: ' . $next);
    exit;
}

// ===== PIN γρήγορης σύνδεσης (ανά συσκευή) =====
// Η συσκευή κρατάει ένα μυστικό cookie (selector:token). Το PIN δουλεύει ΜΟΝΟ μαζί με αυτό το cookie,
// οπότε όποιος μάθει το PIN δεν μπορεί να μπει από άλλη συσκευή. Στη βάση κρατάμε μόνο hashes.

const PIN_COOKIE = 'kpin';
const PIN_DAYS   = 30;

function pin_cookie_write($value, $days)
{
    setcookie(PIN_COOKIE, $value, [
        'expires'  => $days > 0 ? time() + $days * 86400 : time() - 3600,
        'path'     => '/',
        'secure'   => !empty($GLOBALS['is_https']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function device_label()
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $systems = ['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows',
                'Mac OS' => 'Mac', 'Linux' => 'Linux'];
    $system = 'συσκευή';
    foreach ($systems as $needle => $name) {
        if (stripos($agent, $needle) !== false) {
            $system = $name;
            break;
        }
    }
    return short_browser($agent) . ' σε ' . $system;
}

// Η εγγραφή PIN αυτής της συσκευής (μαζί με το όνομα του μέλους) ή null
function current_device_pin($pdo)
{
    $parts = explode(':', $_COOKIE[PIN_COOKIE] ?? '', 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT p.*, u.username, u.full_name, u.role, u.is_admin FROM device_pins p
                           JOIN users u ON u.id = p.user_id WHERE p.selector = ?');
    $stmt->execute([$parts[0]]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $parts[1]))) {
        return null;
    }
    $last = strtotime($row['last_used_at'] ?? $row['created_at']);
    if ($last < time() - PIN_DAYS * 86400) {
        $pdo->prepare('DELETE FROM device_pins WHERE id = ?')->execute([$row['id']]);
        return null;
    }
    return $row;
}

// 6 ψηφία, όχι εύκολα: 111111, 123456, 654321, 121212, 123123, 112233 ...
function pin_problem($pin)
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return 'Το PIN πρέπει να έχει ακριβώς 6 ψηφία.';
    }
    $digits = array_map('intval', str_split($pin));
    $steps = [];
    for ($i = 1; $i < 6; $i++) {
        $steps[] = $digits[$i] - $digits[$i - 1];
    }
    $easy = count(array_unique($digits)) <= 2                       // 111111, 121212, 112112
         || count(array_unique($steps)) === 1                        // 123456, 654321, 135791
         || substr($pin, 0, 3) === substr($pin, 3)                   // 123123
         || in_array($pin, ['112233', '102030', '147258', '159753', '010203', '696969', '202020', '199999'], true)
         || ((int) substr($pin, 0, 4) >= 1940 && (int) substr($pin, 0, 4) <= 2035);   // 1985.., 2026.. (χρονιά γέννησης)
    return $easy ? 'Αυτό το PIN είναι πολύ εύκολο να το μαντέψει κάποιος. Διάλεξε άλλο.' : '';
}

function enable_device_pin($pdo, $user_id, $pin)
{
    // Αν η συσκευή είχε ήδη PIN (ίσως άλλου μέλους), το αντικαθιστούμε
    $old = current_device_pin($pdo);
    if ($old) {
        $pdo->prepare('DELETE FROM device_pins WHERE id = ?')->execute([$old['id']]);
    }
    $selector = bin2hex(random_bytes(12));
    $token    = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO device_pins (user_id, selector, token_hash, pin_hash, device_label, last_used_at)
                   VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$user_id, $selector, hash('sha256', $token), password_hash($pin, PASSWORD_DEFAULT), device_label()]);
    pin_cookie_write($selector . ':' . $token, PIN_DAYS);
}

function forget_device_pin($pdo)
{
    $row = current_device_pin($pdo);
    if ($row) {
        $pdo->prepare('DELETE FROM device_pins WHERE id = ?')->execute([$row['id']]);
    }
    pin_cookie_write('', 0);
}

function session_version($pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

// Νέος κωδικός: όλες οι ΑΛΛΕΣ συσκευές αποσυνδέονται (και τα PIN ακυρώνονται)
function sign_out_everywhere($pdo, $user_id)
{
    $pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$user_id]);
    forget_all_pins($pdo, $user_id);
}

function forget_all_pins($pdo, $user_id)
{
    $pdo->prepare('DELETE FROM device_pins WHERE user_id = ?')->execute([$user_id]);
}

function user_pin_devices($pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT id, device_label, created_at, last_used_at FROM device_pins WHERE user_id = ? ORDER BY last_used_at DESC');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Οι σελίδες διαχείρισης θέλουν κωδικό, όχι μόνο PIN
function logged_in_with_pin()
{
    return ($_SESSION['login_method'] ?? '') === 'pin';
}

// ===== Έλεγχος νομιμότητας εγγράφων =====
// ΣΗΜΑΝΤΙΚΟ: κανένα πρόγραμμα δεν ξέρει με σιγουριά αν ένα αρχείο είναι νόμιμο.
// Εδώ βρίσκουμε ΕΝΔΕΙΞΕΙΣ κινδύνου. Ό,τι δεν είναι ξεκάθαρο το ελέγχει άνθρωπος (ο διαχειριστής).

// Παίρνουμε όσο κείμενο μπορούμε από το αρχείο (για να ψάξουμε ISBN, ©, εκδότες).
function document_text_sample($path, $ext)
{
    $text = '';
    if ($ext === 'txt') {
        $text = (string) file_get_contents($path, false, null, 0, 400000);
    } elseif (in_array($ext, ['docx', 'odt', 'epub'], true) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles && strlen($text) < 400000; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/(document\.xml|content\.xml|meta\.xml|core\.xml|\.x?html?|\.opf)$/i', $name)) {
                    $text .= ' ' . strip_tags((string) $zip->getFromIndex($i, 400000));
                }
            }
            $zip->close();
        }
    } elseif ($ext === 'pdf') {
        $raw = (string) file_get_contents($path, false, null, 0, 12000000);
        $text = $raw;                                       // μεταδεδομένα και ασυμπίεστο κείμενο
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
            foreach (array_slice($streams[1], 0, 400) as $stream) {
                $plain = @gzuncompress($stream);
                if ($plain !== false && strlen($text) < 3000000) {
                    $text .= ' ' . $plain;
                }
            }
        }
    }
    return $text;
}

function pdf_page_count($path)
{
    $raw = (string) file_get_contents($path, false, null, 0, 12000000);
    return preg_match_all('/\/Type\s*\/Page[^s]/', $raw);
}

// Επιστρέφει ['level' => low|medium|high, 'reasons' => [...], 'blocked' => bool, 'hash' => ...]
function analyze_document($pdo, $path, $ext, $info)
{
    $reasons = [];
    $level   = 'low';
    $raise = function ($to, $reason) use (&$level, &$reasons) {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];
        if ($order[$to] > $order[$level]) {
            $level = $to;
        }
        $reasons[] = $reason;
    };

    $hash = hash_file('sha256', $path);

    // 1. Μαύρη λίστα: αρχείο που έχει αφαιρεθεί για παραβίαση
    $stmt = $pdo->prepare('SELECT title FROM blocked_files WHERE file_hash = ?');
    $stmt->execute([$hash]);
    if ($blocked = $stmt->fetchColumn()) {
        return ['level' => 'high', 'blocked' => true, 'hash' => $hash,
                'reasons' => ['Αυτό ακριβώς το αρχείο έχει αφαιρεθεί στο παρελθόν για παραβίαση πνευματικών δικαιωμάτων.']];
    }

    // 2. Ίδιο αρχείο ήδη στη βιβλιοθήκη
    $stmt = $pdo->prepare("SELECT title FROM documents WHERE file_hash = ? AND status <> 'rejected' LIMIT 1");
    $stmt->execute([$hash]);
    if ($same = $stmt->fetchColumn()) {
        $raise('medium', 'Το ίδιο ακριβώς αρχείο υπάρχει ήδη στη βιβλιοθήκη («' . $same . '»).');
    }

    // 3. Σημάδια εκδότη μέσα στο αρχείο
    $text = mb_strtolower(document_text_sample($path, $ext), 'UTF-8');
    $strong = [
        'isbn'                              => 'Το αρχείο έχει αριθμό ISBN: μοιάζει με σελίδες από εκδομένο βιβλίο.',
        'issn'                              => 'Το αρχείο έχει αριθμό ISSN: μοιάζει με άρθρο από περιοδικό.',
        'all rights reserved'               => 'Γράφει «All rights reserved» (όλα τα δικαιώματα κατοχυρωμένα).',
        'με επιφύλαξη παντός δικαιώματος'   => 'Γράφει «Με επιφύλαξη παντός δικαιώματος».',
        'απαγορεύεται η αναδημοσίευση'      => 'Γράφει ότι απαγορεύεται η αναδημοσίευση.',
        'απαγορεύεται η αναπαραγωγή'        => 'Γράφει ότι απαγορεύεται η αναπαραγωγή.',
        'doi.org/'                          => 'Έχει αριθμό DOI: μοιάζει με δημοσιευμένο επιστημονικό άρθρο.',
    ];
    foreach ($strong as $needle => $reason) {
        if (mb_strpos($text, $needle) !== false) {
            $raise('high', $reason);
        }
    }
    if (preg_match('/(©|\(c\)|copyright)\s*(19|20)\d\d/u', $text)) {
        $raise('high', 'Έχει σημείωση πνευματικών δικαιωμάτων (© με χρονιά).');
    }
    $publishers = ['εκδόσεις', 'εκδοτικός οίκος', 'εκδοτικ', 'πανεπιστημιακές εκδόσεις', 'gutenberg', 'κέδρος', 'πατάκη',
                   'καστανιώτη', 'μεταίχμιο', 'πόλις', 'εστία', 'κριτική', 'αλεξάνδρεια', 'ζήτρος', 'κάκτος', 'ψυχογιός',
                   'university press', 'routledge', 'penguin', 'springer', 'elsevier', 'wiley', 'jstor', 'publisher'];
    foreach ($publishers as $needle) {
        if (mb_strpos($text, $needle) !== false) {
            $raise('medium', 'Αναφέρει εκδότη ή εκδοτικό οίκο («' . $needle . '»).');
            break;
        }
    }
    if (preg_match('/\/(producer|creator)\s*\((?:[^)]*)(indesign|quarkxpress|scribus|acrobat distiller)/i', $text)) {
        $raise('medium', 'Το PDF είναι φτιαγμένο με επαγγελματικό πρόγραμμα σελιδοποίησης βιβλίων.');
    }
    if ($ext === 'pdf' && in_array($info['rights'], ['own', 'notes'], true) && pdf_page_count($path) > 120) {
        $raise('medium', 'Πολύ μεγάλο για σημειώσεις ή δικό σου κείμενο (πάνω από 120 σελίδες).');
    }

    // 4. Δημόσιο κτήμα: 70 χρόνια μετά τον θάνατο του συγγραφέα (κανόνας ΕΕ)
    if ($info['rights'] === 'public') {
        $year = $info['death_year'];
        $limit = (int) date('Y') - 70;
        if ($year === null) {
            $raise('medium', 'Δεν δόθηκε πότε πέθανε ο συγγραφέας, για να ελεγχθεί αν το κείμενο είναι ελεύθερο.');
        } elseif ($year > $limit) {
            $raise('high', 'Ο συγγραφέας πέθανε το ' . $year . ': το έργο του είναι ελεύθερο μόνο αν πέθανε πριν από το ' . $limit . '.');
        }
        if (preg_match('/μετάφρ|μτφρ|translat|επιμέλ|σχόλι|εισαγωγή/u', mb_strtolower($info['source'] . ' ' . $info['description'], 'UTF-8'))) {
            $raise('medium', 'Αναφέρει μετάφραση, επιμέλεια ή σχόλια: ο σύγχρονος μεταφραστής ή επιμελητής έχει δικά του δικαιώματα.');
        }
    }

    // 5. Άδεια συγγραφέα: τη βλέπει ο διαχειριστής
    if ($info['rights'] === 'permission') {
        $raise('medium', 'Δηλώθηκε γραπτή άδεια του συγγραφέα: ο διαχειριστής την επιβεβαιώνει.');
    }

    // 6. Συμπεριφορά: πολλά αρχεία σε λίγη ώρα, ή το πρώτο αρχείο νέου μέλους
    $stmt = $pdo->prepare('SELECT COUNT(id) FROM documents WHERE user_id = ? AND uploaded_at >= NOW() - INTERVAL 1 DAY');
    $stmt->execute([$info['user_id']]);
    if ((int) $stmt->fetchColumn() >= 5) {
        $raise('medium', 'Πολλά αρχεία από τον ίδιο χρήστη μέσα σε 24 ώρες.');
    }
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM documents WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$info['user_id']]);
    if ((int) $stmt->fetchColumn() === 0) {
        $raise('medium', 'Πρώτο έγγραφο του μέλους: ελέγχεται μία φορά από τον διαχειριστή.');
    }

    return ['level' => $level, 'blocked' => false, 'hash' => $hash, 'reasons' => array_values(array_unique($reasons))];
}

function risk_labels()
{
    return [
        'low'    => ['🟢', 'Χαμηλός κίνδυνος'],
        'medium' => ['🟡', 'Χρειάζεται έλεγχο'],
        'high'   => ['🔴', 'Πιθανή παραβίαση'],
    ];
}

function status_labels()
{
    return ['approved' => 'Δημοσιευμένο', 'pending' => 'Σε έλεγχο', 'rejected' => 'Απορρίφθηκε'];
}

// Ποια έγγραφα βλέπει κάποιος: όλοι τα εγκεκριμένα, ο καθένας τα δικά του, ο διαχειριστής όλα
function visible_documents_sql($alias = 'd')
{
    $me = isset($GLOBALS['pdo']) ? current_user($GLOBALS['pdo']) : null;
    if ($me && $me['is_admin']) {
        return '1 = 1';
    }
    $uid = (int) current_user_id();
    return "($alias.status = 'approved' OR $alias.user_id = $uid)";
}

function pending_review_count($pdo)
{
    return (int) $pdo->query("SELECT (SELECT COUNT(id) FROM documents WHERE status = 'pending')
                                   + (SELECT COUNT(id) FROM document_reports WHERE status = 'open')")->fetchColumn();
}

// ===== Cloudflare Turnstile: «Δεν είμαι ρομπότ» =====
// Ρυθμίσεις στο Azure (Environment variables): TURNSTILE_SITE_KEY και TURNSTILE_SECRET_KEY.
// Χωρίς αυτές (π.χ. στο XAMPP) ο έλεγχος απλώς παραλείπεται.

function turnstile_enabled()
{
    return getenv('TURNSTILE_SITE_KEY') && getenv('TURNSTILE_SECRET_KEY');
}

// Το κουτάκι μέσα στη φόρμα
function turnstile_widget()
{
    if (!turnstile_enabled()) {
        return '';
    }
    static $script = false;
    $html = '';
    if (!$script) {
        $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        $script = true;
    }
    $lang = function_exists('current_lang') ? current_lang() : 'el';
    return $html . '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars(getenv('TURNSTILE_SITE_KEY'), ENT_QUOTES)
         . '" data-language="' . $lang . '" data-theme="auto"></div>';
}

// Ρωτάμε το Cloudflare αν το κουτάκι λύθηκε από άνθρωπο. Επιστρέφει '' αν όλα καλά, αλλιώς μήνυμα λάθους.
function turnstile_error($pdo = null, $where = '')
{
    if (!turnstile_enabled()) {
        return '';
    }
    $token = $_POST['cf-turnstile-response'] ?? '';
    if ($token === '' || strlen($token) > 2048) {
        return 'Επιβεβαίωσε ότι δεν είσαι ρομπότ (το κουτάκι του Cloudflare).';
    }
    $ch = curl_init(getenv('TURNSTILE_VERIFY_URL') ?: 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => getenv('TURNSTILE_SECRET_KEY'), 'response' => $token, 'remoteip' => client_ip()]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $answer = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (!is_array($answer)) {
        return 'Ο έλεγχος του Cloudflare δεν απάντησε. Δοκίμασε ξανά σε λίγο.';
    }
    if (empty($answer['success'])) {
        if ($pdo) {
            log_event($pdo, 'bot_blocked', null, '', $where);
        }
        return 'Ο έλεγχος «δεν είμαι ρομπότ» απέτυχε. Δοκίμασε ξανά.';
    }
    return '';
}

// ===== Φωτογραφίες και βίντεο σε ΜΟΝΙΜΟ φάκελο =====
// Στο Azure κάθε νέο ανέβασμα του site (zip) σβήνει ό,τι υπάρχει στον φάκελο του site.
// Γι' αυτό οι φωτογραφίες και τα βίντεο των μελών μπαίνουν δίπλα στα έγγραφα:
//   UPLOAD_DIR=/home/data/uploads  →  /home/data/photos και /home/data/videos
// Στο XAMPP (χωρίς UPLOAD_DIR) μένουν στους φακέλους photos/ και videos/ όπως πριν.

function media_dir($kind)
{
    $base = getenv('UPLOAD_DIR') ? dirname(rtrim(getenv('UPLOAD_DIR'), '/\\')) . '/' . $kind : STORAGE_DIR . '/' . $kind;
    if (!is_dir($base)) {
        mkdir($base, 0770, true);
    }
    return $base;
}

// Πού ΑΠΟΘΗΚΕΥΟΥΜΕ ένα νέο αρχείο
function media_path($kind, $name)
{
    return media_dir($kind) . '/' . basename($name);
}

// Πού ΒΡΙΣΚΕΤΑΙ ένα αρχείο (νέος φάκελος, αλλιώς ο παλιός μέσα στο site). null αν δεν υπάρχει.
function media_file($kind, $name)
{
    $name = basename((string) $name);
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,4}$/', $name)) {
        return null;                                  // μόνο τα δικά μας τυχαία ονόματα
    }
    // new location first, then the old one inside the web folder (files from older versions)
    foreach ([media_dir($kind) . '/' . $name, APP_ROOT . '/public/' . $kind . '/' . $name, APP_ROOT . '/' . $kind . '/' . $name] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function media_delete($kind, $name)
{
    $path = media_file($kind, $name);
    if ($path !== null) {
        unlink($path);
    }
}

function media_url($kind, $name)
{
    return 'media.php?t=' . ($kind === 'videos' ? 'v' : 'p') . '&f=' . rawurlencode((string) $name);
}

// ===== Λογαριασμοί που δεν επιβεβαίωσαν ποτέ το email τους =====
// Σβήνονται μετά από 7 ημέρες, ώστε να ελευθερώνονται username και email (π.χ. αν κάποιος έγραψε λάθος email).
function cleanup_unverified_accounts($pdo)
{
    $stmt = $pdo->query('SELECT id, photo FROM users WHERE email_verified_at IS NULL AND created_at < NOW() - INTERVAL 7 DAY LIMIT 50');
    foreach ($stmt->fetchAll() as $row) {
        $pdo->prepare('DELETE FROM users WHERE id = ? AND email_verified_at IS NULL')->execute([$row['id']]);
        if ($row['photo']) {
            media_delete('photos', $row['photo']);
        }
    }
}

// Κάποιος προσπάθησε να γραφτεί με email που ΥΠΑΡΧΕΙ ήδη. Δεν του το λέμε (για να μη μαθαίνει
// ποια email είναι μέλη)· ενημερώνουμε όμως τον πραγματικό κάτοχο. Έως 3 τέτοια email την ώρα ανά διεύθυνση.
function notify_existing_email($pdo, $email)
{
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM security_log WHERE event = 'register_existing' AND details = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
    $stmt->execute([hash('sha256', strtolower($email))]);
    if ((int) $stmt->fetchColumn() >= 3) {
        return;
    }
    log_event($pdo, 'register_existing', null, '', hash('sha256', strtolower($email)));
    send_mail($email, 'Knowledge: ' . t('Προσπάθεια εγγραφής με το email σου'),
        t('Κάποιος προσπάθησε να κάνει νέα εγγραφή στο Knowledge με αυτό το email, αλλά έχεις ήδη λογαριασμό.') . "\n\n"
        . t('Αν ήσουν εσύ: στη σελίδα σύνδεσης πάτα «Ξέχασα το username» ή «Ξέχασα τον κωδικό».'));
}
