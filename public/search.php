<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$q = trim($_GET['q'] ?? '');
$results = ['members' => [], 'documents' => [], 'videos' => [], 'debates' => []];

if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';

    $stmt = $pdo->prepare(
        'SELECT id, full_name, username, role, photo, position FROM users
         WHERE full_name LIKE ? OR username LIKE ? OR position LIKE ?
         ORDER BY full_name LIMIT 10'
    );
    $stmt->execute([$like, $like, $like]);
    $results['members'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, title, author, category FROM documents d
         WHERE (title LIKE ? OR author LIKE ? OR description LIKE ?) AND ' . visible_documents_sql('d') . '
         ORDER BY uploaded_at DESC LIMIT 10'
    );
    $stmt->execute([$like, $like, $like]);
    $results['documents'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, title, creator, year, category FROM videos
         WHERE title LIKE ? OR creator LIKE ? OR description LIKE ?
         ORDER BY uploaded_at DESC LIMIT 10'
    );
    $stmt->execute([$like, $like, $like]);
    $results['videos'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, title, status FROM debates
         WHERE title LIKE ? OR description LIKE ?
         ORDER BY created_at DESC LIMIT 10'
    );
    $stmt->execute([$like, $like]);
    $results['debates'] = $stmt->fetchAll();
}

$total = array_sum(array_map('count', $results));

$title = 'Αναζήτηση';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Αναζήτηση</h1>

<form method="get" class="filters">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Όνομα, τίτλος, συγγραφέας, σκηνοθέτης..." autofocus>
    <button type="submit">Αναζήτηση</button>
</form>

<?php if ($q === ''): ?>
    <p class="muted">Ψάχνει μαζί σε μέλη, έγγραφα της βιβλιοθήκης, ταινίες και debates.</p>
<?php elseif (mb_strlen($q) < 2): ?>
    <p class="error">Γράψε τουλάχιστον 2 χαρακτήρες.</p>
<?php elseif ($total === 0): ?>
    <p class="muted">Δεν βρέθηκε τίποτα για «<?= e($q) ?>».</p>
<?php else: ?>
    <p class="muted"><?= $total ?> αποτελέσματα για «<?= e($q) ?>».</p>

    <?php if ($results['members']): ?>
        <div class="box">
            <h2>Μέλη (<?= count($results['members']) ?>)</h2>
            <ul class="search-list">
                <?php foreach ($results['members'] as $m): ?>
                    <li>
                        <?= avatar($m) ?>
                        <a href="profile.php?id=<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></a>
                        <small class="muted">@<?= e($m['username']) ?> · <?= role_label($m['role']) ?><?= $m['position'] ? ' · ' . e($m['position']) : '' ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($results['documents']): ?>
        <div class="box">
            <h2>Βιβλιοθήκη (<?= count($results['documents']) ?>)</h2>
            <ul class="search-list">
                <?php foreach ($results['documents'] as $d): ?>
                    <li>
                        <a href="reviews.php?type=document&amp;id=<?= (int) $d['id'] ?>"><?= e($d['title']) ?></a>
                        <?php if ($d['author']): ?><small class="muted">· <?= e($d['author']) ?></small><?php endif; ?>
                        <span class="tag"><?= category_label($d['category']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($results['videos']): ?>
        <div class="box">
            <h2>Ταινιοθήκη (<?= count($results['videos']) ?>)</h2>
            <ul class="search-list">
                <?php foreach ($results['videos'] as $v): ?>
                    <li>
                        <a href="reviews.php?type=video&amp;id=<?= (int) $v['id'] ?>"><?= e($v['title']) ?></a>
                        <small class="muted"><?= $v['creator'] ? '· ' . e($v['creator']) : '' ?><?= $v['year'] ? ' · ' . (int) $v['year'] : '' ?></small>
                        <span class="tag"><?= category_label($v['category']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($results['debates']): ?>
        <div class="box">
            <h2>Debates (<?= count($results['debates']) ?>)</h2>
            <ul class="search-list">
                <?php foreach ($results['debates'] as $d): ?>
                    <li>
                        <a href="debate.php?id=<?= (int) $d['id'] ?>"><?= e($d['title']) ?></a>
                        <?php if ($d['status'] === 'closed'): ?><span class="tag">κλειστό</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
