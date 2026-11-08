<?php
// Διαχειριστής: έγγραφα σε έλεγχο νομιμότητας και αναφορές παραβίασης
require __DIR__ . '/../src/bootstrap.php';
require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);
    $note   = mb_substr(trim($_POST['note'] ?? ''), 0, 255);

    $stmt = $pdo->prepare('SELECT id, title, stored_name, file_hash, user_id FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if ($action === 'approve' && $doc) {
        $pdo->prepare("UPDATE documents SET status = 'approved', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$note ?: null, current_user_id(), $doc['id']]);
        $pdo->prepare("UPDATE document_reports SET status = 'closed' WHERE document_id = ? AND status = 'open'")->execute([$doc['id']]);
        log_event($pdo, 'doc_approved', current_user_id(), '', $doc['title']);
        $_SESSION['flash'] = 'Το «' . $doc['title'] . '» εγκρίθηκε και δημοσιεύτηκε.';
    } elseif ($action === 'reject' && $doc) {
        if ($note === '') {
            $_SESSION['flash'] = 'Γράψε τον λόγο της απόρριψης (τον βλέπει το μέλος).';
            header('Location: review_documents.php#doc-' . $doc['id']);
            exit;
        }
        // Το αρχείο σβήνεται από τον δίσκο: δεν κρατάμε περιεχόμενο που παραβιάζει δικαιώματα
        $path = upload_path($doc['stored_name']);
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/', $doc['stored_name']) && is_file($path)) {
            unlink($path);
        }
        $pdo->prepare("UPDATE documents SET status = 'rejected', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$note, current_user_id(), $doc['id']]);
        if (isset($_POST['block']) && $doc['file_hash']) {
            $pdo->prepare('INSERT IGNORE INTO blocked_files (file_hash, title, reason) VALUES (?, ?, ?)')
                ->execute([$doc['file_hash'], mb_substr($doc['title'], 0, 200), $note]);
        }
        $pdo->prepare("UPDATE document_reports SET status = 'closed' WHERE document_id = ? AND status = 'open'")->execute([$doc['id']]);
        log_event($pdo, 'doc_rejected', current_user_id(), '', $doc['title'] . (isset($_POST['block']) ? ' + μαύρη λίστα' : ''));
        $_SESSION['flash'] = 'Το «' . $doc['title'] . '» απορρίφθηκε' . (isset($_POST['block']) ? ' και μπήκε στη μαύρη λίστα.' : '.');
    } elseif ($action === 'close_report') {
        $pdo->prepare("UPDATE document_reports SET status = 'closed' WHERE id = ?")->execute([(int) ($_POST['report_id'] ?? 0)]);
        $_SESSION['flash'] = 'Η αναφορά έκλεισε.';
    }
    header('Location: review_documents.php');
    exit;
}

$pending = $pdo->query(
    "SELECT d.*, u.full_name, u.username,
            (SELECT COUNT(r.id) FROM document_reports r WHERE r.document_id = d.id AND r.status = 'open') AS reports
     FROM documents d JOIN users u ON u.id = d.user_id
     WHERE d.status = 'pending'
     ORDER BY FIELD(d.risk, 'high', 'medium', 'low'), d.uploaded_at"
)->fetchAll();

$reports = $pdo->query(
    "SELECT r.*, d.title, d.status AS doc_status, u.full_name AS reporter
     FROM document_reports r
     JOIN documents d ON d.id = r.document_id
     LEFT JOIN users u ON u.id = r.reporter_id
     WHERE r.status = 'open'
     ORDER BY r.created_at"
)->fetchAll();

$blocked_count = (int) $pdo->query('SELECT COUNT(file_hash) FROM blocked_files')->fetchColumn();
$risk_labels = risk_labels();
$rights_labels = ['own' => 'Δικό του έργο', 'notes' => 'Σημειώσεις', 'permission' => 'Με άδεια συγγραφέα', 'public' => 'Ελεύθερο κείμενο'];
$report_reasons = ['copyright' => 'Πνευματικά δικαιώματα', 'wrong_author' => 'Λάθος συγγραφέας/πηγή', 'offensive' => 'Προσβλητικό/παράνομο', 'other' => 'Άλλο'];

$title = 'Έλεγχος εγγράφων';
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="admin.php">&larr; Κέντρο ασφαλείας</a></p>
<h1>⚖️ Έλεγχος εγγράφων</h1>
<p class="muted">Ο αυτόματος έλεγχος δίνει <strong>ενδείξεις</strong>. Την τελική απόφαση την παίρνεις εσύ.
   Αρχεία στη μαύρη λίστα: <?= $blocked_count ?>.</p>

<div class="box">
    <h2>Σε αναμονή έγκρισης (<?= count($pending) ?>)</h2>
    <?php if (!$pending): ?><p class="muted">Κανένα έγγραφο σε αναμονή. 🎉</p><?php endif; ?>
    <?php foreach ($pending as $doc): ?>
        <?php [$icon, $label] = $risk_labels[$doc['risk']]; ?>
        <div class="review-item risk-<?= e($doc['risk']) ?>" id="doc-<?= (int) $doc['id'] ?>">
            <h3><?= $icon ?> <?= e($doc['title']) ?> <small class="muted">· <?= e($doc['author']) ?></small></h3>
            <p><strong><?= e($label) ?></strong>
               <?php if ($doc['reports']): ?> · <span class="tag status-rejected"><?= (int) $doc['reports'] ?> αναφορές</span><?php endif; ?></p>
            <?php if ($doc['risk_reasons']): ?>
                <ul><?php foreach (explode("\n", $doc['risk_reasons']) as $reason): ?><li><?= e(t($reason)) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <p class="muted">
                Δικαίωμα: <strong><?= e($rights_labels[$doc['rights']] ?? '') ?></strong>
                <?php if ($doc['source']): ?> · Πηγή: <?= e($doc['source']) ?><?php endif; ?>
                <?php if ($doc['author_death_year'] !== null): ?> · Θάνατος συγγραφέα: <?= (int) $doc['author_death_year'] ?><?php endif; ?><br>
                Από: <a href="profile.php?id=<?= (int) $doc['user_id'] ?>"><?= e($doc['full_name']) ?></a> (<?= e($doc['username']) ?>)
                · <?= date('d/m/Y H:i', strtotime($doc['uploaded_at'])) ?> · <?= format_size($doc['file_size']) ?>
            </p>
            <?php if ($doc['description']): ?><p><?= e_multiline($doc['description']) ?></p><?php endif; ?>
            <p>
                <?php if (is_viewable_type($doc['mime_type'])): ?>
                    <a class="small-button" href="download.php?id=<?= (int) $doc['id'] ?>&amp;view=1" target="_blank">Άνοιγμα</a>
                <?php endif; ?>
                <a class="small-button" href="download.php?id=<?= (int) $doc['id'] ?>">Λήψη</a>
            </p>
            <form method="post" class="review-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                <label>Σημείωση προς το μέλος <small class="muted">(υποχρεωτική για απόρριψη)</small>
                    <input type="text" name="note" maxlength="255" placeholder="Π.χ. Είναι σελίδες από το βιβλίο των εκδόσεων Χ.">
                </label>
                <label class="inline"><input type="checkbox" name="block" value="1"> Μαύρη λίστα (να μην μπορεί να ξανανέβει)</label>
                <div class="actions">
                    <button type="submit" name="action" value="approve">✓ Έγκριση</button>
                    <button type="submit" name="action" value="reject" class="danger">✗ Απόρριψη</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="box">
    <h2>Αναφορές παραβίασης (<?= count($reports) ?>)</h2>
    <?php if (!$reports): ?><p class="muted">Καμία ανοιχτή αναφορά.</p><?php endif; ?>
    <?php foreach ($reports as $report): ?>
        <div class="review-item">
            <h3>⚑ <?= e($report['title']) ?> <small class="muted">· <?= e($report_reasons[$report['reason']]) ?></small></h3>
            <p><?= e_multiline($report['details']) ?></p>
            <p class="muted">Από: <?= e($report['reporter'] ?? 'διαγραμμένο μέλος') ?>
               <?php if ($report['contact']): ?> · Επικοινωνία: <a href="mailto:<?= e($report['contact']) ?>"><?= e($report['contact']) ?></a><?php endif; ?>
               · <?= date('d/m/Y H:i', strtotime($report['created_at'])) ?></p>
            <div class="actions">
                <a class="small-button" href="download.php?id=<?= (int) $report['document_id'] ?>&amp;view=1" target="_blank">Άνοιγμα εγγράφου</a>
                <?php if ($report['doc_status'] !== 'rejected'): ?>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $report['document_id'] ?>">
                        <input type="hidden" name="note" value="Αφαιρέθηκε μετά από αναφορά: <?= e($report_reasons[$report['reason']]) ?>">
                        <input type="hidden" name="block" value="1">
                        <button type="submit" name="action" value="reject" class="small-button danger"
                                onclick="return confirm('Αφαίρεση του εγγράφου και μαύρη λίστα;');">Αφαίρεση εγγράφου</button>
                    </form>
                <?php endif; ?>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                    <button type="submit" name="action" value="close_report" class="small-button">Κλείσιμο (δεν παραβιάζει)</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
