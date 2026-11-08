<?php
// Γρήγορη σύνδεση με PIN (μόνο από τη συσκευή όπου ενεργοποιήθηκε)
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$device = current_device_pin($pdo);
if (!$device) {
    pin_cookie_write('', 0);
    header('Location: choose_role.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (($_POST['action'] ?? '') === 'forget') {
        // «Δεν είμαι εγώ»: σβήνουμε το PIN από αυτή τη συσκευή
        log_event($pdo, 'pin_removed', (int) $device['user_id'], $device['username'], 'από τη σελίδα σύνδεσης');
        forget_device_pin($pdo);
        header('Location: choose_role.php');
        exit;
    }

    $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');

    if (too_many_login_attempts($pdo, $device['username'])) {
        log_event($pdo, 'login_locked', (int) $device['user_id'], $device['username'], 'PIN');
        $error = 'Πάρα πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.';
    } elseif (password_verify($pin, $device['pin_hash'])) {
        log_login_attempt($pdo, $device['username'], true);
        $pdo->prepare('UPDATE device_pins SET attempts = 0, last_used_at = NOW() WHERE id = ?')->execute([$device['id']]);
        finish_login($pdo, (int) $device['user_id'], $device['username'], 'με PIN');
    } else {
        log_login_attempt($pdo, $device['username'], false);
        $attempts = (int) $device['attempts'] + 1;
        if ($attempts >= 5) {
            log_event($pdo, 'pin_locked', (int) $device['user_id'], $device['username'], $device['device_label']);
            forget_device_pin($pdo);
            $_SESSION['flash'] = 'Λάθος PIN 5 φορές: το PIN ακυρώθηκε σε αυτή τη συσκευή. Συνδέσου με username και password.';
            header('Location: login.php?role=' . urlencode($device['role']));
            exit;
        }
        $pdo->prepare('UPDATE device_pins SET attempts = ? WHERE id = ?')->execute([$attempts, $device['id']]);
        log_event($pdo, 'pin_failed', (int) $device['user_id'], $device['username'], $device['device_label']);
        $error = 'Λάθος PIN. Απομένουν ' . (5 - $attempts) . ' προσπάθειες.';
    }
}

$title = 'Σύνδεση με PIN';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel pin-panel">
    <h1>Καλώς ήρθες ξανά</h1>
    <p class="pin-user">👤 <?= e($device['full_name']) ?></p>
    <p class="muted">Γράψε το 6ψήφιο PIN σου.</p>

    <?php if ($error): ?><p class="error"><?= e(t($error)) ?></p><?php endif; ?>

    <form method="post" id="pin-form">
        <?= csrf_field() ?>
        <input type="password" name="pin" id="pin-input" class="code-input pin-input" required inputmode="numeric"
               pattern="\d{6}" maxlength="6" autocomplete="off" aria-label="PIN" autofocus>
        <div class="pin-dots" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        <div class="pin-keypad">
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit): ?>
                <button type="button" data-digit="<?= $digit ?>"><?= $digit ?></button>
            <?php endforeach; ?>
            <button type="button" data-action="clear" aria-label="Σβήσιμο">⌫</button>
            <button type="button" data-digit="0">0</button>
            <button type="submit" aria-label="Σύνδεση">✓</button>
        </div>
    </form>

    <p class="panel-links">
        <a href="login.php?role=<?= e($device['role']) ?>">Σύνδεση με password</a>
    </p>
    <form method="post" class="panel-links">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="forget">
        <button type="submit" class="link-button">Άλλος χρήστης; Αφαίρεση PIN από αυτή τη συσκευή</button>
    </form>
</div>

<script>
(function () {
    const input = document.getElementById('pin-input');
    const dots  = document.querySelectorAll('.pin-dots i');
    const form  = document.getElementById('pin-form');
    function paint() {
        input.value = input.value.replace(/\D/g, '').slice(0, 6);
        dots.forEach(function (dot, i) { dot.classList.toggle('on', i < input.value.length); });
        if (input.value.length === 6) { form.submit(); }
    }
    input.addEventListener('input', paint);
    document.querySelectorAll('.pin-keypad button[data-digit]').forEach(function (b) {
        b.addEventListener('click', function () { input.value += b.dataset.digit; paint(); });
    });
    document.querySelector('.pin-keypad [data-action="clear"]').addEventListener('click', function () {
        input.value = input.value.slice(0, -1); paint();
    });
})();
</script>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
