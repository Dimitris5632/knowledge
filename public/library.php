<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$search   = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';

// Τα φίλτρα τα φτιάχνουμε μία φορά και τα χρησιμοποιούμε και για το «πόσα είναι» και για τη λίστα
// Οι άλλοι βλέπουν μόνο τα εγκεκριμένα· ο καθένας βλέπει και τα δικά του σε έλεγχο· ο διαχειριστής όλα
$where  = ' WHERE ' . visible_documents_sql('d');
$params = [];
if ($search !== '') {
    // Prepared statement: το % μπαίνει στην παράμετρο, όχι στο SQL
    $where .= ' AND (d.title LIKE ? OR d.author LIKE ? OR d.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (array_key_exists($category, categories())) {
    $where .= ' AND d.category = ?';
    $params[] = $category;
}

$stmt = $pdo->prepare('SELECT COUNT(d.id) FROM documents d' . $where);
$stmt->execute($params);
[$page, $pages, $offset] = paginate((int) $stmt->fetchColumn(), 10);

$sql = "SELECT d.id, d.user_id, d.category, d.title, d.author, d.description,
               d.stored_name, d.mime_type, d.file_size, d.uploaded_at, d.rights, d.source, u.full_name AS uploader,
               d.status, d.risk, d.risk_reasons, d.review_note,
               (SELECT AVG(r.rating) FROM reviews r WHERE r.item_type = 'document' AND r.item_id = d.id) AS rating,
               (SELECT COUNT(r.id) FROM reviews r WHERE r.item_type = 'document' AND r.item_id = d.id) AS review_count,
               (SELECT COUNT(f.item_id) FROM favorites f WHERE f.item_type = 'document' AND f.item_id = d.id AND f.user_id = ?) AS is_fav
        FROM documents d
        JOIN users u ON u.id = d.user_id"
     . $where
     . ' ORDER BY d.uploaded_at DESC LIMIT 10 OFFSET ' . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([current_user_id()], $params));
$documents = $stmt->fetchAll();

$back = 'library.php' . ($_GET ? '?' . http_build_query($_GET) : '');

$rights_labels = [
    'own'        => 'Έργο του μέλους',
    'notes'      => 'Σημειώσεις μέλους',
    'permission' => 'Με άδεια του συγγραφέα',
    'public'     => 'Ελεύθερο κείμενο',
];

$upload_result = $_SESSION['upload_result'] ?? null;
unset($_SESSION['upload_result']);
$risk_labels = risk_labels();
$status_labels = status_labels();

$title = 'Βιβλιοθήκη';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Ψηφιακή βιβλιοθήκη</h1>

<?php if ($upload_result): ?>
    <?php [$icon, $label] = $risk_labels[$upload_result['level']]; ?>
    <div class="box risk-result risk-<?= e($upload_result['level']) ?>">
        <h2><?= $icon ?> «<?= e($upload_result['title']) ?>»: <?= e($label) ?></h2>
        <?php if ($upload_result['status'] === 'approved'): ?>
            <p><strong>Το έγγραφο δημοσιεύτηκε.</strong></p>
        <?php else: ?>
            <p><strong>Το έγγραφο περιμένει έγκριση από τον διαχειριστή.</strong> Μέχρι τότε το βλέπεις μόνο εσύ.</p>
        <?php endif; ?>
        <?php if ($upload_result['reasons']): ?>
            <p class="muted">Τι βρήκε ο έλεγχος:</p>
            <ul><?php foreach ($upload_result['reasons'] as $reason): ?><li><?= e(t($reason)) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p><a class="button" href="upload.php">+ Ανέβασε έγγραφο</a></p>

<form method="get" class="filters">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Αναζήτηση τίτλου, συγγραφέα...">
    <select name="category">
        <option value="">Όλες οι θεματικές</option>
        <?php foreach (categories() as $value => $label): ?>
            <option value="<?= $value ?>" <?= $category === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Αναζήτηση</button>
</form>

<?php if (!$documents): ?>
    <p class="muted">Δεν βρέθηκαν έγγραφα.</p>
<?php endif; ?>

<ul class="list">
    <?php foreach ($documents as $doc): ?>
        <li id="document-<?= (int) $doc['id'] ?>">
            <form method="post" action="favorite.php" class="inline-form fav-form">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="document">
                <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <button type="submit" class="fav-button <?= $doc['is_fav'] ? 'faved' : '' ?>"
                        title="<?= $doc['is_fav'] ? 'Αφαίρεση από τα αγαπημένα' : 'Προσθήκη στα αγαπημένα' ?>"><?= $doc['is_fav'] ? '♥' : '♡' ?></button>
            </form>
            <?php if (is_image_type($doc['mime_type'])): ?>
                <a href="download.php?id=<?= (int) $doc['id'] ?>&amp;view=1" target="_blank" class="doc-thumb-link">
                    <img class="doc-thumb" src="download.php?id=<?= (int) $doc['id'] ?>&amp;view=1" alt="<?= e($doc['title']) ?>" loading="lazy">
                </a>
            <?php endif; ?>
            <strong><?= e($doc['title']) ?></strong>
            <?php if ($doc['status'] !== 'approved'): ?>
                <span class="tag status-<?= e($doc['status']) ?>"><?= e($status_labels[$doc['status']]) ?></span>
            <?php endif; ?>
            <?php if ($doc['author']): ?> · <?= e($doc['author']) ?><?php endif; ?>
            <span class="tag"><?= category_label($doc['category']) ?></span>

            <?php if ($doc['description']): ?>
                <p><?= e_multiline($doc['description']) ?></p>
            <?php endif; ?>

            <?php if ($doc['status'] !== 'approved' && ($doc['risk_reasons'] || $doc['review_note'])): ?>
                <div class="risk-note">
                    <?php if ($doc['review_note']): ?><p><strong>Σημείωση διαχειριστή:</strong> <?= e($doc['review_note']) ?></p><?php endif; ?>
                    <?php if ($doc['risk_reasons'] && $doc['status'] === 'pending'): ?>
                        <small><?= $risk_labels[$doc['risk']][0] ?> <?= e($risk_labels[$doc['risk']][1]) ?>: <?= e(t(str_replace("\n", ' · ', $doc['risk_reasons']))) ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="doc-credit">
                <span class="tag"><?= e($rights_labels[$doc['rights']] ?? '') ?></span>
                <?php if ($doc['source']): ?><small class="muted">Πηγή: <?= e($doc['source']) ?></small><?php endif; ?>
            </p>

            <small class="muted">
                <?= e(strtoupper(pathinfo($doc['stored_name'], PATHINFO_EXTENSION))) ?>,
                <?= format_size($doc['file_size']) ?> ·
                ανέβηκε από <a href="profile.php?id=<?= (int) $doc['user_id'] ?>"><?= e($doc['uploader']) ?></a>
                στις <?= date('d/m/Y', strtotime($doc['uploaded_at'])) ?>
            </small>

            <div class="actions">
                <?php if (is_viewable_type($doc['mime_type'])): ?>
                    <a class="small-button" href="download.php?id=<?= (int) $doc['id'] ?>&amp;view=1" target="_blank"><?= is_image_type($doc['mime_type']) ? 'Προβολή' : 'Ανάγνωση' ?></a>
                <?php endif; ?>
                <a class="small-button" href="download.php?id=<?= (int) $doc['id'] ?>">Λήψη</a>
                <a class="small-button secondary-small" href="reviews.php?type=document&amp;id=<?= (int) $doc['id'] ?>">
                    <?php if ($doc['review_count'] > 0): ?><?= stars($doc['rating']) ?> (<?= (int) $doc['review_count'] ?>)<?php else: ?>Κριτικές<?php endif; ?>
                </a>

                <?php if ($doc['user_id'] != current_user_id()): ?>
                    <a class="small-button secondary-small" href="report_document.php?id=<?= (int) $doc['id'] ?>" title="Αναφορά παραβίασης">⚑ Αναφορά</a>
                <?php endif; ?>

                <?php if ($doc['user_id'] == current_user_id()): ?>
                    <!-- Η διαγραφή γίνεται με POST + CSRF, ποτέ με απλό link -->
                    <form method="post" action="delete_document.php" class="inline-form"
                          onsubmit="return confirm('Σίγουρα θέλεις να διαγράψεις αυτό το έγγραφο;');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                        <button type="submit" class="small-button danger">Διαγραφή</button>
                    </form>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<?= pagination_links($page, $pages) ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
