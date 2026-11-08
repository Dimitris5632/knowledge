<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$type = ($_GET['type'] ?? '') === 'video' ? 'video' : 'document';
$id   = (int) ($_GET['id'] ?? 0);
$item = find_item($pdo, $type, $id);

if (!$item) {
    http_response_code(404);
    die('Δεν βρέθηκε.');
}

$is_mine = ($item['user_id'] == current_user_id());
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM reviews WHERE user_id = ? AND item_type = ? AND item_id = ?')
            ->execute([current_user_id(), $type, $id]);
        $_SESSION['flash'] = 'Η κριτική σου διαγράφηκε.';
        header('Location: reviews.php?type=' . $type . '&id=' . $id);
        exit;
    }

    $rating  = (int) ($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($is_mine) {
        $errors[] = 'Δεν μπορείς να βαθμολογήσεις κάτι που ανέβασες εσύ.';
    }
    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Διάλεξε από 1 έως 5 αστέρια.';
    }
    if (mb_strlen($comment) > 2000) {
        $errors[] = 'Το σχόλιο μπορεί να έχει έως 2000 χαρακτήρες.';
    }

    if (!$errors) {
        // Μία κριτική ανά μέλος: αν υπάρχει ήδη, την ενημερώνουμε (UNIQUE στη βάση)
        $stmt = $pdo->prepare(
            'INSERT INTO reviews (user_id, item_type, item_id, rating, comment) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), created_at = NOW()'
        );
        $stmt->execute([current_user_id(), $type, $id, $rating, $comment]);
        $_SESSION['flash'] = 'Η κριτική σου αποθηκεύτηκε.';
        header('Location: reviews.php?type=' . $type . '&id=' . $id);
        exit;
    }
}

$stmt = $pdo->prepare('SELECT AVG(rating) AS average, COUNT(id) AS total FROM reviews WHERE item_type = ? AND item_id = ?');
$stmt->execute([$type, $id]);
$summary = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT r.user_id, r.rating, r.comment, r.created_at, u.full_name, u.photo
     FROM reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.item_type = ? AND r.item_id = ?
     ORDER BY r.created_at DESC'
);
$stmt->execute([$type, $id]);
$reviews = $stmt->fetchAll();

$mine = null;
foreach ($reviews as $r) {
    if ($r['user_id'] == current_user_id()) {
        $mine = $r;
    }
}
$my_rating = (int) ($_POST['rating'] ?? ($mine['rating'] ?? 0));

$title = 'Κριτικές: ' . $item['title'];
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="<?= $type === 'video' ? 'videos.php' : 'library.php' ?>">&larr; <?= $type === 'video' ? 'Ταινιοθήκη' : 'Βιβλιοθήκη' ?></a></p>

<div class="box">
    <h1><?= e($item['title']) ?></h1>
    <?php if ($item['subtitle']): ?><p class="muted"><?= e($item['subtitle']) ?></p><?php endif; ?>
    <?php if ($type === 'document' && is_image_type($item['mime_type'])): ?>
        <a href="download.php?id=<?= (int) $id ?>&amp;view=1" target="_blank">
            <img class="doc-image" src="download.php?id=<?= (int) $id ?>&amp;view=1" alt="<?= e($item['title']) ?>">
        </a>
    <?php endif; ?>
    <?php if ($summary['total'] > 0): ?>
        <p class="rating-summary"><?= stars($summary['average']) ?>
            <strong><?= number_format((float) $summary['average'], 1, ',', '') ?></strong> / 5
            <span class="muted">· <?= (int) $summary['total'] ?> <?= $summary['total'] == 1 ? 'κριτική' : 'κριτικές' ?></span></p>
    <?php else: ?>
        <p class="muted">Καμία κριτική ακόμα.</p>
    <?php endif; ?>
    <?php if ($type === 'document'): ?>
        <a class="small-button" href="download.php?id=<?= (int) $id ?>">Λήψη</a>
    <?php endif; ?>
</div>

<?php if (!$is_mine): ?>
    <div class="box">
        <h2><?= $mine ? 'Η κριτική σου' : 'Γράψε κριτική' ?></h2>
        <?php foreach ($errors as $error): ?>
            <p class="error"><?= e(t($error)) ?></p>
        <?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="star-input">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" name="rating" value="<?= $i ?>" id="star<?= $i ?>" <?= $my_rating === $i ? 'checked' : '' ?>>
                    <label for="star<?= $i ?>" title="<?= $i ?> αστέρια">★</label>
                <?php endfor; ?>
            </div>
            <label>Σχόλιο (προαιρετικό)
                <textarea name="comment" rows="4" maxlength="2000"><?= e($_POST['comment'] ?? ($mine['comment'] ?? '')) ?></textarea>
            </label>
            <div class="actions">
                <button type="submit"><?= $mine ? 'Ενημέρωση κριτικής' : 'Δημοσίευση' ?></button>
            </div>
        </form>
        <?php if ($mine): ?>
            <form method="post" class="actions" onsubmit="return confirm('Διαγραφή της κριτικής σου;');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="small-button danger">Διαγραφή κριτικής</button>
            </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="muted">Αυτό το ανέβασες εσύ, οπότε δεν μπορείς να το βαθμολογήσεις. Εδώ βλέπεις τι λένε τα άλλα μέλη.</p>
<?php endif; ?>

<h2>Κριτικές</h2>
<?php foreach ($reviews as $r): ?>
    <div class="post">
        <p>
            <?= avatar($r) ?>
            <a href="profile.php?id=<?= (int) $r['user_id'] ?>"><strong><?= e($r['full_name']) ?></strong></a>
            <?= stars($r['rating']) ?>
            <small class="muted">· <?= date('d/m/Y', strtotime($r['created_at'])) ?></small>
        </p>
        <?php if ($r['comment'] !== null && $r['comment'] !== ''): ?>
            <p><?= e_multiline($r['comment']) ?></p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
