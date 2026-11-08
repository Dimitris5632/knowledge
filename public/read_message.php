<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);

// ΤΟ ΠΙΟ ΣΗΜΑΝΤΙΚΟ ΣΗΜΕΙΟ ΤΗΣ ΣΕΛΙΔΑΣ:
// Δεν ψάχνουμε μόνο "WHERE m.id = ?". Ελέγχουμε ΚΑΙ ότι το μήνυμα
// ανήκει στον συνδεδεμένο χρήστη (είναι αποστολέας ή παραλήπτης).
// Χωρίς αυτό, αλλάζοντας το ?id=5 σε ?id=6 στο URL θα διάβαζες
// μηνύματα άλλων. Αυτό λέγεται IDOR (Insecure Direct Object Reference).
$stmt = $pdo->prepare(
    'SELECT m.id, m.sender_id, m.receiver_id, m.body, m.sent_at, m.is_read,
            s.full_name AS sender_name, r.full_name AS receiver_name
     FROM messages m
     JOIN users s ON s.id = m.sender_id
     JOIN users r ON r.id = m.receiver_id
     WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)'
);
$stmt->execute([$id, current_user_id(), current_user_id()]);
$msg = $stmt->fetch();

if (!$msg) {
    http_response_code(404);
    die('Το μήνυμα δεν βρέθηκε.');
}

// Αν είμαι ο παραλήπτης, το σημειώνουμε ως διαβασμένο
if ($msg['receiver_id'] == current_user_id() && !$msg['is_read']) {
    $pdo->prepare('UPDATE messages SET is_read = 1 WHERE id = ?')->execute([$id]);
}

$i_am_receiver = ($msg['receiver_id'] == current_user_id());

$title = 'Μήνυμα';
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="messages.php">&larr; Πίσω στα μηνύματα</a></p>

<div class="message-full">
    <p>
        <strong>Από:</strong>
        <a href="profile.php?id=<?= (int) $msg['sender_id'] ?>"><?= e($msg['sender_name']) ?></a><br>
        <strong>Προς:</strong>
        <a href="profile.php?id=<?= (int) $msg['receiver_id'] ?>"><?= e($msg['receiver_name']) ?></a><br>
        <span class="muted"><?= date('d/m/Y H:i', strtotime($msg['sent_at'])) ?></span>
    </p>
    <hr>
    <p><?= linkify($msg['body']) ?></p>
</div>

<?php if ($i_am_receiver): ?>
    <a class="button" href="send_message.php?to=<?= (int) $msg['sender_id'] ?>">Απάντηση</a>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
