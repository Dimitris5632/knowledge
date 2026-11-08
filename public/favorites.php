<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$stmt = $pdo->prepare(
    "SELECT d.id, d.title, d.author, d.category, d.mime_type
     FROM favorites f
     JOIN documents d ON d.id = f.item_id
     WHERE f.user_id = ? AND f.item_type = 'document' AND " . visible_documents_sql('d') . "
     ORDER BY f.created_at DESC"
);
$stmt->execute([current_user_id()]);
$documents = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT v.id, v.title, v.creator, v.year, v.category
     FROM favorites f
     JOIN videos v ON v.id = f.item_id
     WHERE f.user_id = ? AND f.item_type = 'video'
     ORDER BY f.created_at DESC"
);
$stmt->execute([current_user_id()]);
$videos = $stmt->fetchAll();

// Το κουμπί «Αφαίρεση»
function remove_button($type, $id)
{
    return '<form method="post" action="favorite.php" class="inline-form">' . csrf_field()
         . '<input type="hidden" name="type" value="' . $type . '">'
         . '<input type="hidden" name="id" value="' . (int) $id . '">'
         . '<input type="hidden" name="back" value="favorites.php">'
         . '<button type="submit" class="fav-button faved" title="Αφαίρεση από τα αγαπημένα">♥</button></form>';
}

$title = 'Τα αγαπημένα μου';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Τα αγαπημένα μου</h1>

<div class="box">
    <h2>Βιβλιοθήκη (<?= count($documents) ?>)</h2>
    <?php if (!$documents): ?>
        <p class="muted">Πάτα την καρδούλα ♡ σε ένα έγγραφο της <a href="library.php">βιβλιοθήκης</a> για να το βρίσκεις εδώ.</p>
    <?php endif; ?>
    <ul class="search-list">
        <?php foreach ($documents as $d): ?>
            <li id="document-<?= (int) $d['id'] ?>">
                <?= remove_button('document', $d['id']) ?>
                <a href="download.php?id=<?= (int) $d['id'] ?><?= is_viewable_type($d['mime_type']) ? '&amp;view=1' : '' ?>" target="_blank"><?= e($d['title']) ?></a>
                <?php if ($d['author']): ?><small class="muted">· <?= e($d['author']) ?></small><?php endif; ?>
                <span class="tag"><?= category_label($d['category']) ?></span>
                <a class="muted" href="reviews.php?type=document&amp;id=<?= (int) $d['id'] ?>">Κριτικές</a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="box">
    <h2>Ταινιοθήκη (<?= count($videos) ?>)</h2>
    <?php if (!$videos): ?>
        <p class="muted">Πάτα την καρδούλα ♡ σε μια ταινία της <a href="videos.php">ταινιοθήκης</a> για να τη βρίσκεις εδώ.</p>
    <?php endif; ?>
    <ul class="search-list">
        <?php foreach ($videos as $v): ?>
            <li id="video-<?= (int) $v['id'] ?>">
                <?= remove_button('video', $v['id']) ?>
                <a href="reviews.php?type=video&amp;id=<?= (int) $v['id'] ?>"><?= e($v['title']) ?></a>
                <small class="muted"><?= $v['creator'] ? '· ' . e($v['creator']) : '' ?><?= $v['year'] ? ' · ' . (int) $v['year'] : '' ?></small>
                <span class="tag"><?= category_label($v['category']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
