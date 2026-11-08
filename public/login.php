<?php
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$role = $_GET['role'] ?? '';
if (!array_key_exists($role, roles())) {
    header('Location: choose_role.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $bot = turnstile_error($pdo, 'σύνδεση');               // Cloudflare: όχι bots που δοκιμάζουν κωδικούς
    if ($bot !== '') {
        $error = $bot;
    } elseif (too_many_login_attempts($pdo, $username)) {
        log_event($pdo, 'login_locked', null, $username);
        $error = 'Πάρα πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, email, password_hash, totp_enabled, email_verified_at FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $success = $user && password_verify($password, $user['password_hash']);
        log_login_attempt($pdo, $username, $success);

        if ($success && $user['email_verified_at'] === null) {
            // Σωστός κωδικός, αλλά δεν έχει επιβεβαιώσει ακόμα το email του
            session_regenerate_id(true);
            $_SESSION['verify_pending'] = ['user_id' => (int) $user['id'], 'username' => $user['username'], 'email' => $user['email'],
                                           'time' => time(), 'next' => 'index.php'];
            $sent = send_email_code($pdo, 'register', $user['email'], (int) $user['id']);
            $_SESSION['verify_notice'] = $sent;
            header('Location: verify_email.php');
            exit;
        }

        if ($success && $user['totp_enabled']) {
            // Σωστός κωδικός, αλλά έχει 2FA: πάμε στο δεύτερο βήμα
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = ['user_id' => (int) $user['id'], 'username' => $username, 'time' => time()];
            header('Location: login_2fa.php');
            exit;
        }

        if ($success) {
            finish_login($pdo, (int) $user['id'], $user['username']);
        }

        log_event($pdo, 'login_failed', $user ? (int) $user['id'] : null, $username);
        $error = 'Λάθος username ή password.';
    }
}

$title = 'Σύνδεση';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel">
    <h1>Σύνδεση ως <?= role_label($role) ?></h1>

    <?php if ($error): ?>
        <p class="error"><?= e(t($error)) ?></p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <label>Username
            <input type="text" name="username" value="<?= e($username) ?>" required>
        </label>

        <label>Password
            <input type="password" name="password" required>
        </label>

        <?= turnstile_widget() ?>
        <div class="panel-buttons">
            <button type="submit">Σύνδεση</button>
            <a class="button secondary" href="register.php?role=<?= e($role) ?>">Εγγραφή</a>
        </div>
    </form>

    <p class="panel-links">
        <a href="forgot_username.php">Ξέχασα το username</a> ·
        <a href="forgot_password.php">Ξέχασα τον κωδικό</a>
    </p>

    <p class="panel-links"><a href="choose_role.php">&larr; Αλλαγή ρόλου</a></p>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
