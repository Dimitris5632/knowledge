<?php
// Όποιος μπήκε με PIN, για τις σελίδες διαχείρισης ξαναγράφει τον κωδικό του
require __DIR__ . '/../src/bootstrap.php';
require_login();

$allowed = ['admin.php', 'review_documents.php'];
$next = in_array($_GET['next'] ?? '', $allowed, true) ? $_GET['next'] : 'index.php';
$me = current_user($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([current_user_id()]);
    if (too_many_login_attempts($pdo, $me['username'] ?? '')) {
        $error = 'Πάρα πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.';
    } elseif (password_verify($_POST['password'] ?? '', (string) $stmt->fetchColumn())) {
        log_login_attempt($pdo, $me['username'], true);
        session_regenerate_id(true);
        $_SESSION['login_method'] = 'password';
        header('Location: ' . $next);
        exit;
    } else {
        log_login_attempt($pdo, $me['username'], false);
        log_event($pdo, 'login_failed', current_user_id(), $me['username'], 'επιβεβαίωση κωδικού για διαχείριση');
        $error = 'Λάθος κωδικός.';
    }
}

$title = 'Επιβεβαίωση κωδικού';
require TEMPLATES_DIR . '/header.php';
?>

<div class="box narrow">
    <h1>Επιβεβαίωση κωδικού</h1>
    <p>Μπήκες με PIN. Για τις σελίδες διαχείρισης χρειάζεται και ο κωδικός σου.</p>
    <?php if ($error): ?><p class="error"><?= e(t($error)) ?></p><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label>Κωδικός πρόσβασης
            <input type="password" name="password" required autocomplete="current-password" autofocus>
        </label>
        <button type="submit">Συνέχεια</button>
    </form>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
