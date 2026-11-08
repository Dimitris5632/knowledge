<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

// debates.php -> ανοιχτά, debates.php?status=closed -> κλειστά
$status = ($_GET['status'] ?? '') === 'closed' ? 'closed' : 'open';

// Για κάθε debate μετράμε πόσα επιχειρήματα έχει, με ένα μικρό
// "υποερώτημα" (subquery) μέσα στο SELECT.
$stmt = $pdo->prepare(
    'SELECT d.id, d.title, d.created_at, d.user_id, u.full_name,
            (SELECT COUNT(p.id) FROM debate_posts p WHERE p.debate_id = d.id) AS posts
     FROM debates d
     JOIN users u ON u.id = d.user_id
     WHERE d.status = ?
     ORDER BY d.created_at DESC'
);
$stmt->execute([$status]);
$debates = $stmt->fetchAll();

$title = 'Debates';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Debates</h1>

<p><a class="button" href="new_debate.php">+ Άνοιξε νέο debate</a></p>

<p class="tabs">
    <a href="debates.php" class="<?= $status === 'open' ? 'active' : '' ?>">Ανοιχτά</a>
    <a href="debates.php?status=closed" class="<?= $status === 'closed' ? 'active' : '' ?>">Κλειστά</a>
</p>

<?php if (!$debates): ?>
    <p class="muted">Δεν υπάρχουν debates εδώ ακόμα.</p>
<?php endif; ?>

<ul class="list">
    <?php foreach ($debates as $d): ?>
        <li>
            <a href="debate.php?id=<?= (int) $d['id'] ?>"><strong><?= e($d['title']) ?></strong></a><br>
            <small class="muted">
                από <a href="profile.php?id=<?= (int) $d['user_id'] ?>"><?= e($d['full_name']) ?></a> ·
                <?= date('d/m/Y', strtotime($d['created_at'])) ?> ·
                <?= (int) $d['posts'] ?> επιχειρήματα
            </small>
        </li>
    <?php endforeach; ?>
</ul>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
