<?php
require __DIR__ . '/../src/bootstrap.php';

$pending = $_SESSION['pending_2fa'] ?? null;

// Ο κωδικός πρέπει να δοθεί μέσα σε 5 λεπτά από τον σωστό κωδικό πρόσβασης
if (!$pending || time() - $pending['time'] > 300) {
    unset($_SESSION['pending_2fa']);
    header('Location: choose_role.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (too_many_login_attempts($pdo, $pending['username'])) {
        log_event($pdo, 'login_locked', $pending['user_id'], $pending['username']);
        $error = 'Πάρα πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.';
    } else {
        $stmt = $pdo->prepare('SELECT totp_secret, totp_last_step FROM users WHERE id = ?');
        $stmt->execute([$pending['user_id']]);
        $user = $stmt->fetch();

        $step = totp_verify($user['totp_secret'], $_POST['code'] ?? '', (int) $user['totp_last_step']);

        if ($step !== false) {
            $pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $pending['user_id']]);
            log_login_attempt($pdo, $pending['username'], true);

            unset($_SESSION['pending_2fa']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pending['user_id'];
            $_SESSION['last_activity'] = time();
            $_SESSION['login_method'] = 'password';
            $_SESSION['sv'] = session_version($pdo, $pending['user_id']);
            log_event($pdo, 'login_success', $pending['user_id'], $pending['username'], 'με 2FA');

            header('Location: index.php');
            exit;
        }

        log_login_attempt($pdo, $pending['username'], false);
        log_event($pdo, '2fa_failed', $pending['user_id'], $pending['username']);
        $error = 'Λάθος ή ληγμένος κωδικός. Δοκίμασε τον καινούργιο κωδικό της εφαρμογής.';
    }
}

$title = 'Έλεγχος δύο βημάτων';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel">
    <h1>Έλεγχος δύο βημάτων</h1>
    <p>Άνοιξε την εφαρμογή <strong>Authenticator</strong> στο κινητό σου και γράψε τον 6ψήφιο κωδικό για το <strong>Knowledge</strong>.</p>

    <?php if ($error): ?>
        <p class="error"><?= e(t($error)) ?></p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <label>Κωδικός 6 ψηφίων
            <input type="text" name="code" required maxlength="7" inputmode="numeric"
                   autocomplete="one-time-code" autofocus class="code-input" placeholder="123456">
        </label>

        <button type="submit">Επιβεβαίωση</button>
    </form>

    <p class="panel-links"><a href="logout.php">Ακύρωση</a></p>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
