<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$search   = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';

$where  = ' WHERE 1 = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (v.title LIKE ? OR v.creator LIKE ? OR v.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (array_key_exists($category, categories())) {
    $where .= ' AND v.category = ?';
    $params[] = $category;
}

$stmt = $pdo->prepare('SELECT COUNT(v.id) FROM videos v' . $where);
$stmt->execute($params);
[$page, $pages, $offset] = paginate((int) $stmt->fetchColumn(), 5);

$sql = "SELECT v.id, v.user_id, v.category, v.title, v.creator, v.year, v.description, v.source,
               v.stored_name, v.youtube_id, v.uploaded_at, u.full_name AS uploader,
               (SELECT AVG(r.rating) FROM reviews r WHERE r.item_type = 'video' AND r.item_id = v.id) AS rating,
               (SELECT COUNT(r.id) FROM reviews r WHERE r.item_type = 'video' AND r.item_id = v.id) AS review_count,
               (SELECT COUNT(f.item_id) FROM favorites f WHERE f.item_type = 'video' AND f.item_id = v.id AND f.user_id = ?) AS is_fav
        FROM videos v
        JOIN users u ON u.id = v.user_id"
     . $where
     . ' ORDER BY v.uploaded_at DESC LIMIT 5 OFFSET ' . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([current_user_id()], $params));
$videos = $stmt->fetchAll();

$back = 'videos.php' . ($_GET ? '?' . http_build_query($_GET) : '');

$title = 'Ταινιοθήκη';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Ψηφιακή ταινιοθήκη</h1>

<p><a class="button" href="upload_video.php">+ Πρόσθεσε ταινία ή βίντεο</a></p>

<form method="get" class="filters">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Αναζήτηση τίτλου, σκηνοθέτη...">
    <select name="category">
        <option value="">Όλες οι θεματικές</option>
        <?php foreach (categories() as $value => $label): ?>
            <option value="<?= $value ?>" <?= $category === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Αναζήτηση</button>
</form>

<?php if (!$videos): ?>
    <p class="muted">Δεν βρέθηκαν ταινίες ή βίντεο.</p>
<?php endif; ?>

<div class="video-list">
    <?php foreach ($videos as $video): ?>
        <article class="box video-item" id="video-<?= (int) $video['id'] ?>">
            <div class="video-frame">
                <?php if ($video['source'] === 'youtube'): ?>
                    <iframe src="https://www.youtube-nocookie.com/embed/<?= e($video['youtube_id']) ?>"
                            title="<?= e($video['title']) ?>" loading="lazy" allowfullscreen
                            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"></iframe>
                <?php else: ?>
                    <video controls preload="metadata" src="<?= e(media_url('videos', $video['stored_name'])) ?>"></video>
                <?php endif; ?>
            </div>

            <h2>
                <form method="post" action="favorite.php" class="inline-form fav-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="type" value="video">
                    <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                    <input type="hidden" name="back" value="<?= e($back) ?>">
                    <button type="submit" class="fav-button <?= $video['is_fav'] ? 'faved' : '' ?>"
                            title="<?= $video['is_fav'] ? 'Αφαίρεση από τα αγαπημένα' : 'Προσθήκη στα αγαπημένα' ?>"><?= $video['is_fav'] ? '♥' : '♡' ?></button>
                </form>
                <?= e($video['title']) ?> <span class="tag"><?= category_label($video['category']) ?></span>
            </h2>
            <?php if ($video['creator'] || $video['year']): ?>
                <p class="muted">
                    <?= e($video['creator']) ?><?= ($video['creator'] && $video['year']) ? ' · ' : '' ?><?= $video['year'] ? (int) $video['year'] : '' ?>
                </p>
            <?php endif; ?>
            <?php if ($video['description']): ?>
                <p><?= e_multiline($video['description']) ?></p>
            <?php endif; ?>
            <small class="muted">
                <?= $video['source'] === 'youtube' ? 'YouTube' : 'Αρχείο' ?> ·
                από <a href="profile.php?id=<?= (int) $video['user_id'] ?>"><?= e($video['uploader']) ?></a>
                στις <?= date('d/m/Y', strtotime($video['uploaded_at'])) ?>
            </small>

            <div class="actions">
                <a class="small-button secondary-small" href="reviews.php?type=video&amp;id=<?= (int) $video['id'] ?>">
                    <?php if ($video['review_count'] > 0): ?><?= stars($video['rating']) ?> (<?= (int) $video['review_count'] ?>)<?php else: ?>Κριτικές<?php endif; ?>
                </a>
            </div>

            <?php if ($video['user_id'] == current_user_id()): ?>
                <form method="post" action="delete_video.php" class="actions"
                      onsubmit="return confirm('Σίγουρα θέλεις να το διαγράψεις από την ταινιοθήκη;');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                    <button type="submit" class="small-button danger">Διαγραφή</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>

<?= pagination_links($page, $pages) ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
