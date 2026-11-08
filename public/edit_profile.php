<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$stmt = $pdo->prepare('SELECT full_name, role, position, biography, education, works FROM users WHERE id = ?');
$stmt->execute([current_user_id()]);
$user = $stmt->fetch();

$cv_fields = cv_fields();
$extra     = role_fields($user['role']);
$table     = $extra['table'];

$columns = implode(', ', array_keys($extra['fields']));
$stmt = $pdo->prepare("SELECT $columns FROM $table WHERE user_id = ?");
$stmt->execute([current_user_id()]);
$details = $stmt->fetch() ?: [];

$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $full_name = trim($_POST['full_name'] ?? '');
    $position  = trim($_POST['position'] ?? '');

    if ($full_name === '' || mb_strlen($full_name) > 100) {
        $errors[] = 'Γράψε το ονοματεπώνυμό σου (έως 100 χαρακτήρες).';
    }
    if (mb_strlen($position) > 150) {
        $errors[] = 'Η θέση μπορεί να έχει έως 150 χαρακτήρες.';
    }

    $cv = [];
    foreach ($cv_fields as $column => [$label, $help, $max]) {
        $cv[$column] = trim($_POST[$column] ?? '');
        if (mb_strlen($cv[$column]) > $max) {
            $errors[] = "Το πεδίο \"$label\" μπορεί να έχει έως $max χαρακτήρες.";
        }
    }

    $new_details = [];
    foreach ($extra['fields'] as $column => [$label, $help, $max]) {
        $new_details[$column] = trim($_POST[$column] ?? '');
        if (mb_strlen($new_details[$column]) > $max) {
            $errors[] = "Το πεδίο \"$label\" μπορεί να έχει έως $max χαρακτήρες.";
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE users
             SET full_name = ?, position = ?, biography = ?, education = ?, works = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $full_name, $position, $cv['biography'], $cv['education'], $cv['works'],
            current_user_id(),
        ]);

        $set = implode(', ', array_map(fn($column) => "$column = ?", array_keys($new_details)));
        $stmt = $pdo->prepare("UPDATE $table SET $set WHERE user_id = ?");
        $stmt->execute([...array_values($new_details), current_user_id()]);

        $pdo->commit();
        $saved = true;
    }

    $user    = array_merge($user, ['full_name' => $full_name, 'position' => $position], $cv);
    $details = $new_details;
}

$title = 'Επεξεργασία βιογραφικού';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Το βιογραφικό μου</h1>

<?php if ($saved): ?>
    <p class="success">Οι αλλαγές αποθηκεύτηκαν. <a href="profile.php">Δες το προφίλ σου</a></p>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <p class="error"><?= e(t($error)) ?></p>
<?php endforeach; ?>

<form method="post">
    <?= csrf_field() ?>

    <label>Ονοματεπώνυμο
        <input type="text" name="full_name" value="<?= e($user['full_name']) ?>" required maxlength="100">
    </label>

    <label>Τωρινή θέση
        <input type="text" name="position" value="<?= e($user['position']) ?>" maxlength="150"
               placeholder="Π.χ. Υποψήφιος διδάκτορας, ΕΚΠΑ">
    </label>

    <?php foreach ($cv_fields as $column => [$label, $help, $max]): ?>
        <label><?= $label ?>
            <small class="muted"><?= $help ?></small>
            <textarea name="<?= $column ?>" rows="5" maxlength="<?= $max ?>"><?= e($user[$column]) ?></textarea>
        </label>
    <?php endforeach; ?>

    <h2>Ως <?= role_label($user['role']) ?></h2>

    <?php foreach ($extra['fields'] as $column => [$label, $help, $max]): ?>
        <label><?= $label ?>
            <small class="muted"><?= $help ?></small>
            <?php if ($max <= 255): ?>
                <input type="text" name="<?= $column ?>" value="<?= e($details[$column] ?? '') ?>" maxlength="<?= $max ?>">
            <?php else: ?>
                <textarea name="<?= $column ?>" rows="4" maxlength="<?= $max ?>"><?= e($details[$column] ?? '') ?></textarea>
            <?php endif; ?>
        </label>
    <?php endforeach; ?>

    <button type="submit">Αποθήκευση</button>
</form>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
