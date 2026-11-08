<?php
require __DIR__ . '/../src/bootstrap.php';

$errors = [];
$sent   = false;
$old    = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $old = [
        'name'    => trim($_POST['name'] ?? ''),
        'email'   => trim($_POST['email'] ?? ''),
        'subject' => trim($_POST['subject'] ?? ''),
        'message' => trim($_POST['message'] ?? ''),
    ];

    if ($old['name'] === '' || mb_strlen($old['name']) > 100) {
        $errors[] = 'Γράψε το όνομά σου (έως 100 χαρακτήρες).';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Το email δεν είναι έγκυρο.';
    }
    if ($old['subject'] === '' || mb_strlen($old['subject']) > 150) {
        $errors[] = 'Γράψε θέμα (έως 150 χαρακτήρες).';
    }
    if ($old['message'] === '' || mb_strlen($old['message']) > 3000) {
        $errors[] = 'Γράψε το μήνυμά σου (έως 3000 χαρακτήρες).';
    }
    if ($bot = turnstile_error($pdo, 'επικοινωνία')) {     // Cloudflare: όχι spam από bots
        $errors[] = $bot;
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$old['name'], $old['email'], $old['subject'], $old['message']]);
        $sent = true;
    }
}

$title = 'Επικοινωνία';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Επικοινωνία</h1>

<?php if ($sent): ?>
    <p class="success">Ευχαριστούμε! Το μήνυμά σου στάλθηκε και θα σου απαντήσουμε σύντομα.</p>
<?php else: ?>
    <p>Έχεις κάποια ερώτηση, πρόταση ή πρόβλημα; Γράψε μας.</p>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= e(t($error)) ?></p>
    <?php endforeach; ?>

    <form method="post">
        <?= csrf_field() ?>

        <label>Όνομα
            <input type="text" name="name" value="<?= e($old['name']) ?>" required maxlength="100">
        </label>

        <label>Email
            <input type="email" name="email" value="<?= e($old['email']) ?>" required>
        </label>

        <label>Θέμα
            <input type="text" name="subject" value="<?= e($old['subject']) ?>" required maxlength="150">
        </label>

        <label>Μήνυμα
            <textarea name="message" rows="6" maxlength="3000" required><?= e($old['message']) ?></textarea>
        </label>

        <?= turnstile_widget() ?>
        <button type="submit">Αποστολή</button>
    </form>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
