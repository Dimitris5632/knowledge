<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

// messages.php          -> εισερχόμενα
// messages.php?box=sent -> απεσταλμένα
$box = ($_GET['box'] ?? '') === 'sent' ? 'sent' : 'inbox';

if ($box === 'inbox') {
    // Μηνύματα που ΕΛΑΒΑ, μαζί με το όνομα του αποστολέα
    $stmt = $pdo->prepare(
        'SELECT m.id, m.body, m.sent_at, m.is_read, u.full_name AS other_name
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.receiver_id = ?
         ORDER BY m.sent_at DESC'
    );
} else {
    // Μηνύματα που ΕΣΤΕΙΛΑ, μαζί με το όνομα του παραλήπτη
    $stmt = $pdo->prepare(
        'SELECT m.id, m.body, m.sent_at, 1 AS is_read, u.full_name AS other_name
         FROM messages m
         JOIN users u ON u.id = m.receiver_id
         WHERE m.sender_id = ?
         ORDER BY m.sent_at DESC'
    );
}
$stmt->execute([current_user_id()]);
$messages = $stmt->fetchAll();

$title = 'Μηνύματα';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Μηνύματα</h1>

<p class="tabs">
    <a href="messages.php" class="<?= $box === 'inbox' ? 'active' : '' ?>">Εισερχόμενα</a>
    <a href="messages.php?box=sent" class="<?= $box === 'sent' ? 'active' : '' ?>">Απεσταλμένα</a>
</p>

<?php if (!$messages): ?>
    <p class="muted">Δεν υπάρχουν μηνύματα.</p>
<?php endif; ?>

<ul class="messages">
    <?php foreach ($messages as $msg): ?>
        <li class="<?= $msg['is_read'] ? '' : 'unread' ?>">
            <a href="read_message.php?id=<?= (int) $msg['id'] ?>">
                <strong><?= $box === 'inbox' ? 'Από' : 'Προς' ?>: <?= e($msg['other_name']) ?></strong>
                <span class="muted"><?= date('d/m/Y H:i', strtotime($msg['sent_at'])) ?></span><br>
                <!-- Δείχνουμε μόνο τους πρώτους 80 χαρακτήρες ως προεπισκόπηση -->
                <?= e(mb_strimwidth($msg['body'], 0, 80, '...')) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
