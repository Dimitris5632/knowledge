<?php
// Ξέχασα το username: 1) email  2) κωδικός από το email + 2 ερωτήσεις ασφαλείας
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error      = '';
$notice     = '';
$found_name = '';
$user       = null;
$fake       = !empty($_SESSION['recover_fake']);

if (isset($_SESSION['recover_user_id'])) {
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['recover_user_id']]);
    $user = $stmt->fetch() ?: null;
}

function forget_recovery()
{
    unset($_SESSION['recover_user_id'], $_SESSION['recover_pick'], $_SESSION['recover_fake'], $_SESSION['recover_fake_q']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $step = $_POST['step'] ?? '';
    // Cloudflare: πριν στείλουμε κωδικό στο email, βεβαιωνόμαστε ότι δεν είναι bot
    if ($step === 'email' && ($bot = turnstile_error($pdo, 'ξέχασα username')) !== '') {
        $error = $bot;
        $step  = '';
    }

    if ($step === 'email') {
        forget_recovery();
        $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE email = ?');
        $stmt->execute([trim($_POST['email'] ?? '')]);
        $user = $stmt->fetch() ?: null;
        $fake = false;

        if ($user && user_questions($pdo, (int) $user['id'])) {
            $_SESSION['recover_user_id'] = (int) $user['id'];
            $sent = send_email_code($pdo, 'username', $user['email'], (int) $user['id']);
            $sent['ok'] ? $notice = $sent['message'] : $error = $sent['message'];
        } else {
            // Δεν λέμε αν υπάρχει λογαριασμός με αυτό το email
            $user = null;
            $fake = true;
            $_SESSION['recover_fake'] = true;
        }
        if ($notice === '' && $error === '') {
            $notice = 'Αν υπάρχει λογαριασμός με αυτό το email, του στείλαμε 6ψήφιο κωδικό.';
        }
    } elseif ($step === 'resend' && $user) {
        $sent = send_email_code($pdo, 'username', $user['email'], (int) $user['id']);
        $sent['ok'] ? $notice = $sent['message'] : $error = $sent['message'];
    } elseif ($step === 'resend' && $fake) {
        $notice = 'Αν υπάρχει λογαριασμός με αυτό το email, του στείλαμε νέο κωδικό.';
    } elseif ($step === 'answer' && ($user || $fake)) {
        if (too_many_recovery_attempts($pdo)) {
            $error = 'Πάρα πολλές λάθος απαντήσεις. Δοκίμασε ξανά σε 15 λεπτά.';
        } elseif (!$user) {
            log_recovery_attempt($pdo, false);
            $error = 'Λάθος κωδικός από το email ή λάθος απαντήσεις.';
        } else {
            $list        = user_questions($pdo, (int) $user['id']);
            $picked      = recovery_pick('recover_pick', count($list));
            $code_result = check_email_code($pdo, 'username', $user['email'], $_POST['email_code'] ?? '');
            $answers_ok  = recovery_answers_ok($list, $picked, $_POST['answers'] ?? []);

            if ($code_result !== 'ok' || !$answers_ok) {
                log_recovery_attempt($pdo, false);
                log_event($pdo, 'recovery_failed', (int) $user['id'], $user['username'],
                          'ανάκτηση username: ' . ($code_result !== 'ok' ? 'κωδικός email' : 'απαντήσεις'));
                $error = $code_result === 'expired' || $code_result === 'locked'
                    ? email_code_error($code_result)
                    : 'Λάθος κωδικός από το email ή λάθος απαντήσεις.';
            } else {
                log_recovery_attempt($pdo, true);
                $found_name = $user['username'];
                log_event($pdo, 'username_recovered', (int) $user['id'], $user['username']);
                forget_recovery();
            }
        }
    } elseif ($step === 'cancel') {
        forget_recovery();
        $user = null;
        $fake = false;
    }
}

$shown_questions = [];
if ($found_name === '' && $user) {
    $list = user_questions($pdo, (int) $user['id']);
    foreach (recovery_pick('recover_pick', count($list)) as $index) {
        $shown_questions[] = $list[$index]['question'];
    }
} elseif ($found_name === '' && $fake) {
    $all = security_questions();
    $_SESSION['recover_fake_q'] = $_SESSION['recover_fake_q'] ?? array_rand($all, 2);
    foreach ($_SESSION['recover_fake_q'] as $index) {
        $shown_questions[] = $all[$index];
    }
}

$title = 'Ξέχασα το username';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel">
    <h1>Ξέχασα το username</h1>

    <?php if ($error): ?><p class="error"><?= e(t($error)) ?></p><?php endif; ?>
    <?php if ($notice): ?><p class="success"><?= e(t($notice)) ?></p><?php endif; ?>

    <?php if ($found_name !== ''): ?>
        <p class="success">Το username σου είναι: <strong><?= e($found_name) ?></strong></p>
        <p><a class="button" href="choose_role.php">Σύνδεση</a></p>

    <?php elseif (!$user && !$fake): ?>
        <p class="muted">Θα σου στείλουμε 6ψήφιο κωδικό στο email σου και θα σε ρωτήσουμε 2 από τις ερωτήσεις ασφαλείας σου.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="email">
            <label>Το email σου
                <input type="email" name="email" required autocomplete="email">
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

            <button type="submit">Εμφάνιση username</button>
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
