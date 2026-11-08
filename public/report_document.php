<?php
// Αναφορά παραβίασης για ένα έγγραφο (για δημιουργούς / κατόχους δικαιωμάτων)
require __DIR__ . '/../src/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, title, author, user_id FROM documents WHERE id = ? AND status = 'approved'");
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    die('Το έγγραφο δεν βρέθηκε.');
}

$reasons = [
    'copyright'    => 'Παραβιάζει πνευματικά δικαιώματα (π.χ. είναι δικό μου έργο ή βιβλίο εκδότη)',
    'wrong_author' => 'Λάθος συγγραφέας ή πηγή',
    'offensive'    => 'Προσβλητικό ή παράνομο περιεχόμενο',
    'other'        => 'Άλλο',
];
$errors = [];
$sent = false;
$old = ['reason' => '', 'details' => '', 'contact' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $old = ['reason' => $_POST['reason'] ?? '', 'details' => trim($_POST['details'] ?? ''), 'contact' => trim($_POST['contact'] ?? '')];

    if (!array_key_exists($old['reason'], $reasons)) {
        $errors[] = 'Διάλεξε τον λόγο της αναφοράς.';
    }
    if (mb_strlen($old['details']) < 10 || mb_strlen($old['details']) > 3000) {
        $errors[] = 'Γράψε λίγα λόγια (10 έως 3000 χαρακτήρες): τι παραβιάζεται και γιατί.';
    }
    if ($old['contact'] !== '' && !filter_var($old['contact'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Το email επικοινωνίας δεν είναι έγκυρο.';
    }
    // Όριο: 5 αναφορές την ώρα ανά μέλος, και μία φορά για το ίδιο έγγραφο
    $stmt = $pdo->prepare('SELECT COUNT(id) FROM document_reports WHERE reporter_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
    $stmt->execute([current_user_id()]);
    if ((int) $stmt->fetchColumn() >= 5) {
        $errors[] = 'Έστειλες πολλές αναφορές. Δοκίμασε ξανά σε λίγο.';
    }
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM document_reports WHERE reporter_id = ? AND document_id = ? AND status = 'open'");
    $stmt->execute([current_user_id(), $doc['id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'Έχεις ήδη στείλει αναφορά για αυτό το έγγραφο. Θα την εξετάσουμε σύντομα.';
    }

    if (!$errors) {
        $pdo->prepare('INSERT INTO document_reports (document_id, reporter_id, reason, details, contact, ip_address)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$doc['id'], current_user_id(), $old['reason'], $old['details'], $old['contact'] ?: null, client_ip()]);
        log_event($pdo, 'doc_reported', current_user_id(), '', $doc['title'] . ' (' . $old['reason'] . ')');

        // 3 αναφορές από διαφορετικά μέλη για πνευματικά δικαιώματα: το έγγραφο κρύβεται μέχρι να το δει ο διαχειριστής
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT reporter_id) FROM document_reports
                               WHERE document_id = ? AND status = 'open' AND reason = 'copyright'");
        $stmt->execute([$doc['id']]);
        if ((int) $stmt->fetchColumn() >= 3) {
            $pdo->prepare("UPDATE documents SET status = 'pending', review_note = 'Κρύφτηκε προσωρινά μετά από αναφορές παραβίασης.' WHERE id = ?")
                ->execute([$doc['id']]);
            log_event($pdo, 'doc_flagged', null, '', $doc['title'] . ' (3 αναφορές)');
        }
        $sent = true;
    }
}

$title = 'Αναφορά παραβίασης';
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="library.php#document-<?= (int) $doc['id'] ?>">&larr; Βιβλιοθήκη</a></p>
<h1>Αναφορά για «<?= e($doc['title']) ?>»</h1>

<?php if ($sent): ?>
    <p class="success">Ευχαριστούμε! Η αναφορά στάλθηκε στον διαχειριστή, που θα την εξετάσει το συντομότερο.
       Αν το έγγραφο παραβιάζει δικαιώματα, θα αφαιρεθεί.</p>
<?php else: ?>
    <?php foreach ($errors as $error): ?><p class="error"><?= e(t($error)) ?></p><?php endforeach; ?>
    <div class="box">
        <p>Είσαι ο δημιουργός ή ο κάτοχος των δικαιωμάτων και το έγγραφο ανέβηκε χωρίς την άδειά σου;
           Ή βλέπεις κάτι παράνομο; Πες μας και θα το εξετάσουμε.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
            <label>Λόγος
                <select name="reason" required>
                    <option value="">-- Διάλεξε --</option>
                    <?php foreach ($reasons as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $old['reason'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Τι συμβαίνει;
                <small class="muted">Π.χ. «Είναι το βιβλίο μου, εκδόσεις Χ, 2019, ISBN …» ή «Είναι οι σημειώσεις του καθηγητή, όχι του μέλους».</small>
                <textarea name="details" rows="5" required maxlength="3000"><?= e($old['details']) ?></textarea>
            </label>
            <label>Email επικοινωνίας <small class="muted">(προαιρετικό, αν θέλεις να σου απαντήσουμε)</small>
                <input type="email" name="contact" value="<?= e($old['contact']) ?>" maxlength="255">
            </label>
            <button type="submit">Αποστολή αναφοράς</button>
        </form>
    </div>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
