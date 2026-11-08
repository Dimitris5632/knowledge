<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$stmt = $pdo->prepare('SELECT username, password_hash, totp_enabled, totp_secret, totp_last_step FROM users WHERE id = ?');
$stmt->execute([current_user_id()]);
$user = $stmt->fetch();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'enable' && !$user['totp_enabled'] && isset($_SESSION['totp_setup'])) {
        $step = totp_verify($_SESSION['totp_setup'], $_POST['code'] ?? '');
        if ($step === false) {
            $error = 'Λάθος κωδικός. Γράψε τον κωδικό που δείχνει τώρα η εφαρμογή.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?');
            $stmt->execute([$_SESSION['totp_setup'], $step, current_user_id()]);
            unset($_SESSION['totp_setup']);
            log_event($pdo, '2fa_enabled', current_user_id(), $user['username']);
            $user['totp_enabled'] = 1;
            $success = 'Ο έλεγχος δύο βημάτων ενεργοποιήθηκε! Από την επόμενη σύνδεση θα σου ζητάει και κωδικό από το κινητό.';
        }
    } elseif ($action === 'disable' && $user['totp_enabled']) {
        $step = totp_verify($user['totp_secret'], $_POST['code'] ?? '', (int) $user['totp_last_step']);
        if (!password_verify($_POST['password'] ?? '', $user['password_hash']) || $step === false) {
            log_event($pdo, '2fa_failed', current_user_id(), $user['username'], 'απόπειρα απενεργοποίησης');
            $error = 'Λάθος κωδικός πρόσβασης ή κωδικός 2FA.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?');
            $stmt->execute([current_user_id()]);
            log_event($pdo, '2fa_disabled', current_user_id(), $user['username']);
            $user['totp_enabled'] = 0;
            $success = 'Ο έλεγχος δύο βημάτων απενεργοποιήθηκε.';
        }
    }
}

// Νέο μυστικό κλειδί: κρατιέται στο session μέχρι να επιβεβαιωθεί με σωστό κωδικό
if (!$user['totp_enabled'] && empty($_SESSION['totp_setup'])) {
    $_SESSION['totp_setup'] = totp_new_secret();
}

$title = 'Έλεγχος δύο βημάτων';
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="security.php">&larr; Ασφάλεια</a></p>
<h1>Έλεγχος δύο βημάτων (2FA)</h1>

<?php if ($error): ?><p class="error"><?= e(t($error)) ?></p><?php endif; ?>
<?php if ($success): ?><p class="success"><?= e(t($success)) ?></p><?php endif; ?>

<div class="box">
    <p>Με τον έλεγχο δύο βημάτων, για να μπει κάποιος στον λογαριασμό σου χρειάζεται <strong>δύο</strong> πράγματα:
       τον κωδικό σου (κάτι που <em>ξέρεις</em>) και το κινητό σου (κάτι που <em>έχεις</em>).
       Έτσι, ακόμα κι αν κλέψει τον κωδικό σου, δεν μπορεί να μπει.</p>
</div>

<?php if ($user['totp_enabled']): ?>

    <div class="box">
        <h2><span class="status-on">● Ενεργό</span></h2>
        <p>Σε κάθε σύνδεση σού ζητάμε και τον 6ψήφιο κωδικό της εφαρμογής Authenticator.</p>

        <h2>Απενεργοποίηση</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="disable">
            <label>Κωδικός πρόσβασης
                <input type="password" name="password" required>
            </label>
            <label>Κωδικός 2FA από την εφαρμογή
                <input type="text" name="code" required maxlength="7" inputmode="numeric" autocomplete="one-time-code" class="code-input">
            </label>
            <button type="submit" class="danger">Απενεργοποίηση 2FA</button>
        </form>
    </div>

<?php else: ?>

    <div class="box">
        <h2><span class="status-off">● Ανενεργό</span></h2>

        <h2>1. Κατέβασε μια εφαρμογή Authenticator</h2>
        <p>Στο κινητό σου: <strong>Google Authenticator</strong> ή <strong>Microsoft Authenticator</strong> (δωρεάν).</p>

        <h2>2. Σκάναρε τον κωδικό QR</h2>
        <div class="qr-wrap">
            <div id="qrcode" class="qr-box"></div>
            <div>
                <p>Αν δεν μπορείς να σκανάρεις, πάτα «Εισαγωγή κλειδιού» στην εφαρμογή και γράψε:</p>
                <p class="secret-key"><?= e(trim(chunk_split($_SESSION['totp_setup'], 4, ' '))) ?></p>
                <p class="muted">Όνομα λογαριασμού: Knowledge · Τύπος: με βάση τον χρόνο</p>
            </div>
        </div>

        <h2>3. Γράψε τον κωδικό που βλέπεις</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="enable">
            <label>Κωδικός 6 ψηφίων
                <input type="text" name="code" required maxlength="7" inputmode="numeric" autocomplete="one-time-code" class="code-input">
            </label>
            <button type="submit">Ενεργοποίηση 2FA</button>
        </form>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    if (window.QRCode) {
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode(totp_uri($_SESSION['totp_setup'], $user['username'])) ?>,
            width: 180,
            height: 180
        });
    } else {
        document.getElementById('qrcode').textContent = 'Το QR χρειάζεται internet. Χρησιμοποίησε το κλειδί δίπλα.';
    }
    </script>

<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
