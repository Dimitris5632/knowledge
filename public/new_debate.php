<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$errors = [];
$debate_title = '';
$description  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $debate_title = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');

    if (mb_strlen($debate_title) < 5 || mb_strlen($debate_title) > 200) {
        $errors[] = 'Ο τίτλος πρέπει να έχει από 5 έως 200 χαρακτήρες.';
    }
    if ($description === '' || mb_strlen($description) > 5000) {
        $errors[] = 'Γράψε την αρχική σου θέση (έως 5000 χαρακτήρες).';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO debates (user_id, title, description) VALUES (?, ?, ?)');
        $stmt->execute([current_user_id(), $debate_title, $description]);

        // Πάμε κατευθείαν στη σελίδα του νέου debate
        header('Location: debate.php?id=' . $pdo->lastInsertId());
        exit;
    }
}

$title = 'Νέο debate';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Νέο debate</h1>

<?php foreach ($errors as $error): ?>
    <p class="error"><?= e(t($error)) ?></p>
<?php endforeach; ?>

<form method="post">
    <?= csrf_field() ?>

    <label>Το ερώτημα
        <input type="text" name="title" value="<?= e($debate_title) ?>" required maxlength="200"
               placeholder="Π.χ. Είναι η ιστορία επιστήμη ή τέχνη;">
    </label>

    <label>Η αρχική σου θέση
        <textarea name="description" rows="8" maxlength="5000" required><?= e($description) ?></textarea>
    </label>

    <button type="submit">Άνοιγμα debate</button>
</form>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
