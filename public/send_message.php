<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

// Σε ποιον στέλνουμε; Από το URL στην αρχή (send_message.php?to=5),
// από το κρυφό πεδίο της φόρμας όταν πατηθεί "Αποστολή".
$to = (int) ($_POST['to'] ?? $_GET['to'] ?? 0);

// Υπάρχει αυτός ο παραλήπτης;
$stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = ?');
$stmt->execute([$to]);
$receiver = $stmt->fetch();

if (!$receiver) {
    http_response_code(404);
    die('Ο παραλήπτης δεν βρέθηκε.');
}
if ($receiver['id'] == current_user_id()) {
    die('Δεν μπορείς να στείλεις μήνυμα στον εαυτό σου.');
}

$error = '';
$body  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $body = trim($_POST['body'] ?? '');

    if ($body === '') {
        $error = 'Το μήνυμα είναι κενό.';
    } elseif (mb_strlen($body) > 2000) {
        $error = 'Το μήνυμα μπορεί να έχει έως 2000 χαρακτήρες.';
    }

    // Έλεγχος συνδέσμων: οι ύποπτοι δεν στέλνονται
    if ($error === '') {
        foreach (find_links($body) as $url) {
            $problem = link_problem($url);
            if ($problem !== '') {
                $error = 'Ο σύνδεσμος «' . $url . '» δεν επιτρέπεται: ' . $problem . '.';
                log_event($pdo, 'link_rejected', current_user_id(), '', mb_substr($url, 0, 200));
                break;
            }
        }
    }

    if ($error === '') {
        // Ο αποστολέας είναι ΠΑΝΤΑ ο συνδεδεμένος χρήστης (από το session).
        // Αν τον παίρναμε από τη φόρμα, κάποιος θα μπορούσε να στείλει
        // μήνυμα "με το όνομα" άλλου.
        $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)');
        $stmt->execute([current_user_id(), $receiver['id'], $body]);

        header('Location: messages.php?box=sent');
        exit;
    }
}

$title = 'Νέο μήνυμα';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Μήνυμα προς <?= e($receiver['full_name']) ?></h1>

<?php if ($error): ?>
    <p class="error"><?= e(t($error)) ?></p>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="to" value="<?= (int) $receiver['id'] ?>">

    <label>Μήνυμα
        <small class="muted">Μπορείς να βάλεις και συνδέσμους, π.χ. https://www.youtube.com/... ή www.ekpa.gr. Θα γίνουν αυτόματα «κλικ».
            Για την ασφάλεια όλων, οι ύποπτοι σύνδεσμοι (χωρίς https, «κοντοί» όπως bit.ly, με αριθμό IP ή προς αρχεία .exe) δεν στέλνονται.</small>
        <textarea name="body" rows="8" maxlength="2000" required><?= e($body) ?></textarea>
    </label>

    <button type="submit">Αποστολή</button>
</form>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
