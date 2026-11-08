<?php
// Επιβεβαίωση email με 6ψήφιο κωδικό (μετά την εγγραφή ή σε σύνδεση με μη επιβεβαιωμένο email)
require __DIR__ . '/../src/bootstrap.php';

$pending = $_SESSION['verify_pending'] ?? null;
if (!$pending || time() - $pending['time'] > 1800) {
    unset($_SESSION['verify_pending']);
    header('Location: choose_role.php');
    exit;
}

$error   = '';
$notice  = '';
if (isset($_SESSION['verify_notice'])) {
    $_SESSION['verify_notice']['ok'] ? $notice = $_SESSION['verify_notice']['message'] : $error = $_SESSION['verify_notice']['message'];
    unset($_SESSION['verify_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $action = $_POST['action'] ?? '';
    $fake   = !empty($pending['fake']);       // εγγραφή με email που υπάρχει ήδη: κανένας κωδικός δεν είναι σωστός

    if ($action === 'resend') {
        if ($fake) {
            $notice = 'Σου στείλαμε 6ψήφιο κωδικό στο ' . mask_email($pending['email']) . '. Κοίτα και στα ανεπιθύμητα (spam).';
        } else {
            $sent = send_email_code($pdo, 'register', $pending['email'], $pending['user_id']);
            $sent['ok'] ? $notice = $sent['message'] : $error = $sent['message'];
        }
    } elseif ($action === 'change_email') {
        // Έγραψε λάθος email στην εγγραφή: το διορθώνει εδώ, χωρίς να ξαναγραφτεί
        $new_email = trim($_POST['new_email'] ?? '');
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$new_email, (int) $pending['user_id']]);
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || mb_strlen($new_email) > 255) {
            $error = 'Το email δεν είναι έγκυρο.';
        } elseif ($fake) {
            $error = 'Για να γραφτείς με άλλο email, ξεκίνα την εγγραφή από την αρχή.';
        } elseif ($stmt->fetch()) {
            $error = 'Αυτό το email δεν μπορεί να χρησιμοποιηθεί. Δοκίμασε άλλο.';
        } else {
            $sent = send_email_code($pdo, 'register', $new_email, $pending['user_id']);
            if ($sent['ok']) {
                $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND email_verified_at IS NULL')->execute([$new_email, $pending['user_id']]);
                $_SESSION['verify_pending']['email'] = $new_email;
                $pending['email'] = $new_email;
                log_event($pdo, 'email_changed', $pending['user_id'], $pending['username'], 'πριν την επιβεβαίωση');
                $notice = $sent['message'];
            } else {
                $error = $sent['message'];
            }
        }
    } elseif ($fake) {
        $error = email_code_error('wrong');
    } else {
        $result = check_email_code($pdo, 'register', $pending['email'], $_POST['email_code'] ?? '');
        if ($result === 'ok') {
            $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$pending['user_id']]);
            log_event($pdo, 'email_verified', $pending['user_id'], $pending['username']);
            unset($_SESSION['verify_pending']);

            $stmt = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
            $stmt->execute([$pending['user_id']]);
            if ((int) $stmt->fetchColumn() === 1) {
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['user_id' => $pending['user_id'], 'username' => $pending['username'], 'time' => time()];
                header('Location: login_2fa.php');
                exit;
            }
            $_SESSION['flash'] = 'Το email σου επιβεβαιώθηκε. Καλώς ήρθες!';
            finish_login($pdo, $pending['user_id'], $pending['username'], 'μετά από επιβεβαίωση email', $pending['next']);
        }
        log_event($pdo, 'email_code_wrong', $pending['user_id'], $pending['username'], 'επιβεβαίωση email');
        $error = email_code_error($result);
    }
}

$title = 'Επιβεβαίωση email';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel">
    <h1>Επιβεβαίωση email</h1>

    <?php if ($error): ?><p class="error"><?= e(t($error)) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="success"><?= e(t($notice)) ?></p><?php endif; ?>

    <p>Γράψε τον <strong>6ψήφιο κωδικό</strong> που στείλαμε στο <strong><?= e(mask_email($pending['email'])) ?></strong>.
       Ισχύει για 10 λεπτά.</p>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <label>Κωδικός από το email
            <?= code_input() ?>
        </label>
        <button type="submit">Επιβεβαίωση</button>
    </form>

    <form method="post" class="panel-links">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend">
        Δεν ήρθε; <button type="submit" class="link-button">Στείλε νέο κωδικό</button>
    </form>

    <details class="panel-links change-email">
        <summary>Έγραψες λάθος email;</summary>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_email">
            <label>Σωστό email
                <input type="email" name="new_email" required maxlength="255" autocomplete="email">
            </label>
            <button type="submit">Αλλαγή και νέος κωδικός</button>
        </form>
    </details>

    <p class="panel-links"><a href="logout.php">Ακύρωση</a></p>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
