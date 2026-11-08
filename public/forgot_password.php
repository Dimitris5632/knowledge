<?php
// Ξέχασα τον κωδικό: 1) username  2) κωδικός από το email + 2 ερωτήσεις ασφαλείας + νέος κωδικός
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error  = '';
$notice = '';
$done   = false;
$user   = null;
$fake   = !empty($_SESSION['reset_fake']);   // username που δεν υπάρχει: δείχνουμε την ίδια οθόνη

if (isset($_SESSION['reset_user_id'])) {
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['reset_user_id']]);
    $user = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $step = $_POST['step'] ?? '';
    // Cloudflare: πριν στείλουμε κωδικό στο email, βεβαιωνόμαστε ότι δεν είναι bot
    if ($step === 'username' && ($bot = turnstile_error($pdo, 'ξέχασα κωδικό')) !== '') {
        $error = $bot;
        $step  = '';
    }

    if ($step === 'username') {
        unset($_SESSION['reset_user_id'], $_SESSION['reset_pick'], $_SESSION['reset_fake'], $_SESSION['reset_fake_q']);
        $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ?');
        $stmt->execute([trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch() ?: null;
        $fake = false;

        if ($user && user_questions($pdo, (int) $user['id'])) {
            $_SESSION['reset_user_id'] = (int) $user['id'];
            $sent = send_email_code($pdo, 'reset', $user['email'], (int) $user['id']);
            $sent['ok'] ? $notice = $sent['message'] : $error = $sent['message'];
        } else {
            // Δεν λέμε αν υπάρχει ο λογαριασμός, για να μη μαντεύει κανείς τα usernames
            $user = null;
            $fake = true;
            $_SESSION['reset_fake'] = true;
        }
        if ($notice === '' && $error === '') {
            $notice = 'Αν υπάρχει λογαριασμός με αυτό το username, στείλαμε 6ψήφιο κωδικό στο email του.';
        }
    } elseif ($step === 'resend' && $user) {
        $sent = send_email_code($pdo, 'reset', $user['email'], (int) $user['id']);
        $sent['ok'] ? $notice = $sent['message'] : $error = $sent['message'];
    } elseif ($step === 'resend' && $fake) {
        $notice = 'Αν υπάρχει λογαριασμός με αυτό το username, στείλαμε νέο κωδικό στο email του.';
    } elseif ($step === 'answer' && ($user || $fake)) {
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $list      = $user ? user_questions($pdo, (int) $user['id']) : [];
        $picked    = $user ? recovery_pick('reset_pick', count($list)) : [];

        if (too_many_recovery_attempts($pdo)) {
            $error = 'Πάρα πολλές λάθος απαντήσεις. Δοκίμασε ξανά σε 15 λεπτά.';
        } elseif (password_errors($password, $user['username'] ?? '')) {
            $error = implode(' ', password_errors($password, $user['username'] ?? ''));
        } elseif ($password !== $password2) {
            $error = 'Οι δύο κωδικοί δεν ταιριάζουν.';
        } elseif (!$user) {
            log_recovery_attempt($pdo, false);
            $error = 'Λάθος κωδικός από το email ή λάθος απαντήσεις.';
        } else {
            $code_result = check_email_code($pdo, 'reset', $user['email'], $_POST['email_code'] ?? '');
            $answers_ok  = recovery_answers_ok($list, $picked, $_POST['answers'] ?? []);

            if ($code_result !== 'ok' || !$answers_ok) {
                log_recovery_attempt($pdo, false);
                log_event($pdo, 'recovery_failed', (int) $user['id'], $user['username'],
                          'επαναφορά κωδικού: ' . ($code_result !== 'ok' ? 'κωδικός email' : 'απαντήσεις'));
                $error = $code_result === 'expired' || $code_result === 'locked'
                    ? email_code_error($code_result)
                    : 'Λάθος κωδικός από το email ή λάθος απαντήσεις.';
            } else {
                log_recovery_attempt($pdo, true);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                log_event($pdo, 'password_reset', (int) $user['id'], $user['username']);
                // Νέος κωδικός = αποσύνδεση από όλες τις συσκευές και άκυρα όλα τα PIN
                sign_out_everywhere($pdo, (int) $user['id']);
                unset($_SESSION['reset_user_id'], $_SESSION['reset_pick'], $_SESSION['reset_fake'], $_SESSION['reset_fake_q']);
                $done = true;
            }
        }
    } elseif ($step === 'cancel') {
        unset($_SESSION['reset_user_id'], $_SESSION['reset_pick'], $_SESSION['reset_fake'], $_SESSION['reset_fake_q']);
        $user = null;
        $fake = false;
    }
}

// Ποιες ερωτήσεις θα δείξουμε
$shown_questions = [];
if (!$done && $user) {
    $list = user_questions($pdo, (int) $user['id']);
    foreach (recovery_pick('reset_pick', count($list)) as $index) {
        $shown_questions[] = $list[$index]['question'];
    }
} elseif (!$done && $fake) {
    $all = security_questions();
    $_SESSION['reset_fake_q'] = $_SESSION['reset_fake_q'] ?? array_rand($all, 2);
    foreach ($_SESSION['reset_fake_q'] as $index) {
        $shown_questions[] = $all[$index];
    }
}

$title = 'Ξέχασα τον κωδικό';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel">
    <h1>Ξέχασα τον κωδικό</h1>

    <?php if ($error): ?><p class="error"><?= e(t($error)) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="success"><?= e(t($notice)) ?></p><?php endif; ?>

    <?php if ($done): ?>
        <p class="success">Ο κωδικός σου άλλαξε. Μπορείς τώρα να συνδεθείς.</p>
        <p><a class="button" href="choose_role.php">Σύνδεση</a></p>

    <?php elseif (!$user && !$fake): ?>
        <p class="muted">Θα σου στείλουμε 6ψήφιο κωδικό στο email σου και θα σε ρωτήσουμε 2 από τις ερωτήσεις ασφαλείας σου.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="username">
            <label>Το username σου
                <input type="text" name="username" required autocomplete="username">
            </label>
            <?= turnstile_widget() ?>
            <button type="submit">Συνέχεια</button>
        </form>

    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="answer">

            <label>1. Κωδικός από το email
                <?= code_input() ?>
            </label>

            <p class="muted">2. Απάντησε στις ερωτήσεις ασφαλείας σου.</p>
            <?php foreach ($shown_questions as $n => $text): ?>
                <label><?= e($text) ?>
                    <input type="text" name="answers[<?= $n ?>]" required autocomplete="off">
                </label>
            <?php endforeach; ?>

            <label>3. Νέος κωδικός <small class="muted">(τουλάχιστον 8 χαρακτήρες)</small>
                <input type="password" name="password" required minlength="8" autocomplete="new-password">
                <small class="pw-help">Τουλάχιστον 8 χαρακτήρες, με κεφαλαίο, πεζό, αριθμό και σύμβολο. <span class="pw-count" id="pw-count">0 / 8</span></small>
            </label>

            <label>Επανάληψη νέου κωδικού
                <input type="password" name="password2" required minlength="8">
            </label>

            <?php require TEMPLATES_DIR . '/password_hint.php'; ?>

            <button type="submit">Αλλαγή κωδικού</button>
        </form>

        <form method="post" class="panel-links">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="resend">
            Δεν ήρθε το email; <button type="submit" class="link-button">Στείλε νέο κωδικό</button>
        </form>

        <form method="post" class="panel-links">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="cancel">
            <button type="submit" class="link-button">Ακύρωση</button>
        </form>
    <?php endif; ?>

    <p class="panel-links"><a href="choose_role.php">&larr; Πίσω στη σύνδεση</a></p>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
