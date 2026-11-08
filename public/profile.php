<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : current_user_id();

$stmt = $pdo->prepare(
    'SELECT id, username, full_name, role, position, biography, education, works, photo, created_at,
            (last_seen >= NOW() - INTERVAL 5 MINUTE) AS is_active
     FROM users
     WHERE id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    die('Ο χρήστης δεν βρέθηκε.');
}

$extra = role_fields($user['role']);
$columns = implode(', ', array_keys($extra['fields']));
$stmt = $pdo->prepare("SELECT $columns FROM {$extra['table']} WHERE user_id = ?");
$stmt->execute([$id]);
$details = $stmt->fetch() ?: [];

$stmt = $pdo->prepare('SELECT id, title, author FROM documents d WHERE user_id = ? AND ' . visible_documents_sql('d') . ' ORDER BY uploaded_at DESC');
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, title, status FROM debates WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$id]);
$debates = $stmt->fetchAll();

$is_me = ($user['id'] == current_user_id());

$title = $user['full_name'];
require TEMPLATES_DIR . '/header.php';
?>

<div class="box profile-head">
    <?= avatar($user, 'large') ?>
    <div>
    <h1>
        <?= e($user['full_name']) ?>
        <?php if ($user['is_active']): ?>
            <span class="online-label">● ενεργός τώρα</span>
        <?php endif; ?>
    </h1>
    <p class="muted">
        @<?= e($user['username']) ?> · <?= role_label($user['role']) ?> ·
        μέλος από <?= date('d/m/Y', strtotime($user['created_at'])) ?>
    </p>
    <?php if ($user['position']): ?>
        <p><strong><?= e($user['position']) ?></strong></p>
    <?php endif; ?>

    <div class="actions no-print">
        <?php if ($is_me): ?>
            <a class="button" href="edit_profile.php">Επεξεργασία βιογραφικού</a>
        <?php else: ?>
            <a class="button" href="send_message.php?to=<?= (int) $user['id'] ?>">Στείλε μήνυμα</a>
        <?php endif; ?>
        <button type="button" class="button secondary" onclick="window.print()">Εκτύπωση / PDF</button>
    </div>
    </div>
</div>

<?php if (array_filter(array_intersect_key($details, $extra['fields']))): ?>
    <div class="box">
        <h2>Ως <?= role_label($user['role']) ?></h2>
        <?php foreach ($extra['fields'] as $column => [$label]): ?>
            <?php if (!empty($details[$column])): ?>
                <p><strong><?= $label ?>:</strong><br><?= e_multiline($details[$column]) ?></p>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php foreach (cv_fields() as $column => [$label]): ?>
    <?php if ($user[$column]): ?>
        <div class="box">
            <h2><?= $label ?></h2>
            <p><?= e_multiline($user[$column]) ?></p>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($documents): ?>
    <div class="box">
        <h2>Έγγραφα στη βιβλιοθήκη</h2>
        <ul>
            <?php foreach ($documents as $doc): ?>
                <li>
                    <a href="download.php?id=<?= (int) $doc['id'] ?>"><?= e($doc['title']) ?></a>
                    <?php if ($doc['author']): ?><span class="muted">· <?= e($doc['author']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($debates): ?>
    <div class="box">
        <h2>Debates που άνοιξε</h2>
        <ul>
            <?php foreach ($debates as $d): ?>
                <li>
                    <a href="debate.php?id=<?= (int) $d['id'] ?>"><?= e($d['title']) ?></a>
                    <?php if ($d['status'] === 'closed'): ?><span class="muted">(κλειστό)</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
