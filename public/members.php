<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$role        = $_GET['role'] ?? '';
$only_active = isset($_GET['active']);

$where  = ' WHERE 1 = 1';
$params = [];
if (array_key_exists($role, roles())) {
    $where .= ' AND u.role = ?';
    $params[] = $role;
}
if ($only_active) {
    $where .= ' AND u.last_seen >= NOW() - INTERVAL 5 MINUTE';
}

$stmt = $pdo->prepare('SELECT COUNT(u.id) FROM users u' . $where);
$stmt->execute($params);
[$page, $pages, $offset] = paginate((int) $stmt->fetchColumn(), 10);

$sql = 'SELECT u.id, u.full_name, u.username, u.role, u.position, u.photo,
               COALESCE(p.school, h.period) AS detail,
               (u.last_seen >= NOW() - INTERVAL 5 MINUTE) AS is_active
        FROM users u
        LEFT JOIN philosophers p ON p.user_id = u.id
        LEFT JOIN historians h   ON h.user_id = u.id'
     . $where
     . ' ORDER BY is_active DESC, u.full_name LIMIT 10 OFFSET ' . (int) $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$title = 'Μέλη';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Μέλη</h1>

<form method="get" class="filters">
    <select name="role">
        <option value="">Φιλόσοφοι και ιστορικοί</option>
        <option value="philosopher" <?= $role === 'philosopher' ? 'selected' : '' ?>>Μόνο φιλόσοφοι</option>
        <option value="historian"   <?= $role === 'historian' ? 'selected' : '' ?>>Μόνο ιστορικοί</option>
    </select>
    <label class="inline">
        <input type="checkbox" name="active" value="1" <?= $only_active ? 'checked' : '' ?>> Μόνο ενεργοί τώρα
    </label>
    <button type="submit">Φιλτράρισμα</button>
</form>

<?php if (!$members): ?>
    <p>Δεν βρέθηκαν μέλη.</p>
<?php endif; ?>

<ul class="list">
    <?php foreach ($members as $m): ?>
        <li>
            <?php if ($m['id'] != current_user_id()): ?>
                <a class="small-button" href="send_message.php?to=<?= (int) $m['id'] ?>">Μήνυμα</a>
            <?php endif; ?>
            <?= avatar($m) ?>
            <span class="dot <?= $m['is_active'] ? 'online' : '' ?>"
                  title="<?= $m['is_active'] ? 'Ενεργός τώρα' : 'Εκτός σύνδεσης' ?>"></span>
            <a href="profile.php?id=<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></a>
            <small class="muted">@<?= e($m['username']) ?> · <?= role_label($m['role']) ?>
                <?php if ($m['detail']): ?> · <?= e($m['detail']) ?><?php endif; ?>
            </small>
            <?php if ($m['position']): ?>
                <br><small><?= e($m['position']) ?></small>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>

<?= pagination_links($page, $pages) ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
