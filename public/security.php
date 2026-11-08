<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid = current_user_id();

$stmt = $pdo->prepare('SELECT username, security_question, totp_enabled, is_admin, created_at FROM users WHERE id = ?');
$stmt->execute([$uid]);
$account = $stmt->fetch();

// Οι 3 τελευταίες επιτυχημένες συνδέσεις
$stmt = $pdo->prepare(
    "SELECT ip_address, user_agent, details, created_at FROM security_log
     WHERE user_id = ? AND event = 'login_success'
     ORDER BY id DESC LIMIT 3"
);
$stmt->execute([$uid]);
$logins = $stmt->fetchAll();

// Ύποπτες προσπάθειες τις τελευταίες 7 ημέρες
$stmt = $pdo->prepare(
    "SELECT COUNT(id) FROM security_log
     WHERE user_id = ? AND event IN ('login_failed', 'login_locked', 'recovery_failed', '2fa_failed')
       AND created_at >= NOW() - INTERVAL 7 DAY"
);
$stmt->execute([$uid]);
$suspicious = (int) $stmt->fetchColumn();

// Πότε άλλαξε τελευταία φορά ο κωδικός (αλλιώς: πότε έγινε η εγγραφή)
$stmt = $pdo->prepare(
    "SELECT MAX(created_at) FROM security_log
     WHERE user_id = ? AND event IN ('password_changed', 'password_reset')"
);
$stmt->execute([$uid]);
$password_date = $stmt->fetchColumn() ?: $account['created_at'];
$password_days = (int) floor((time() - strtotime($password_date)) / 86400);

$question_count = count(user_questions($pdo, $uid));

// Η λίστα ελέγχου: [είναι εντάξει;, τίτλος, εξήγηση, σύνδεσμος για διόρθωση]
$checks = [
    [
        (bool) $account['totp_enabled'],
        'Έλεγχος δύο βημάτων (2FA)',
        $account['totp_enabled'] ? 'Ενεργός: χωρίς το κινητό σου κανείς δεν μπαίνει.' : 'Ανενεργός. Είναι η πιο δυνατή προστασία που μπορείς να έχεις.',
        ['twofa.php', $account['totp_enabled'] ? 'Διαχείριση' : 'Ενεργοποίηση'],
    ],
    [
        $question_count >= QUESTIONS_PER_USER,
        'Ερωτήσεις ασφαλείας (' . $question_count . '/' . QUESTIONS_PER_USER . ')',
        $question_count >= QUESTIONS_PER_USER ? 'Ορισμένες: μπορείς να ανακτήσεις τον λογαριασμό σου.'
            : ($question_count ? 'Πρόσθεσε κι άλλες, ώστε να σε ρωτάμε 2 στην ανάκτηση.' : 'Δεν έχεις ερωτήσεις ασφαλείας. Αν ξεχάσεις τον κωδικό, δεν θα μπορείς να μπεις.'),
        ['settings.php#question', $question_count >= QUESTIONS_PER_USER ? 'Αλλαγή' : 'Συμπλήρωση'],
    ],
    [
        $password_days <= 365,
        'Κωδικός πρόσβασης',
        $password_days <= 365 ? 'Τελευταία αλλαγή πριν από ' . $password_days . ' ημέρες.' : 'Έχεις να τον αλλάξεις πάνω από έναν χρόνο.',
        ['settings.php#password', 'Αλλαγή κωδικού'],
    ],
    [
        $suspicious === 0,
        'Ύποπτες προσπάθειες (7 ημέρες)',
        $suspicious === 0 ? 'Καμία.' : $suspicious . ' αποτυχημένες προσπάθειες πρόσβασης στον λογαριασμό σου.',
        ['activity.php', 'Δες τη δραστηριότητα'],
    ],
];
$score = count(array_filter(array_column($checks, 0)));

$title = 'Ασφάλεια';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Ασφάλεια</h1>

<div class="box">
    <h2>Η ασφάλεια του λογαριασμού σου: <span class="<?= $score === count($checks) ? 'status-on' : 'status-off' ?>"><?= $score ?>/<?= count($checks) ?></span></h2>
    <ul class="sec-checks">
        <?php foreach ($checks as [$ok, $label, $text, $link]): ?>
            <li class="<?= $ok ? 'ok' : 'bad' ?>">
                <span class="sec-icon"><?= $ok ? '✓' : '!' ?></span>
                <div>
                    <strong><?= e(t($label)) ?></strong><br>
                    <small><?= e(t($text)) ?></small>
                </div>
                <a class="small-button" href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="box">
    <h2>Τελευταίες συνδέσεις</h2>
    <?php if (!$logins): ?>
        <p class="muted">Δεν υπάρχουν καταγεγραμμένες συνδέσεις ακόμα.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="log-table">
                <thead><tr><th>Πότε</th><th>Διεύθυνση IP</th><th>Browser</th></tr></thead>
                <tbody>
                    <?php foreach ($logins as $l): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?><?php if ($l['details']): ?> <small class="muted">(<?= e($l['details']) ?>)</small><?php endif; ?></td>
                            <td><?= e($l['ip_address']) ?></td>
                            <td><?= e(short_browser($l['user_agent'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Αν δεις σύνδεση που δεν έκανες εσύ, <a href="settings.php#password">άλλαξε αμέσως τον κωδικό σου</a>.</p>
    <?php endif; ?>
</div>

<div class="box">
    <h2>Εργαλεία ασφαλείας</h2>
    <div class="sec-links">
        <a href="activity.php"><strong>Η δραστηριότητά μου</strong><small>Όλες οι ενέργειες στον λογαριασμό σου, με IP και browser</small></a>
        <a href="twofa.php"><strong>Έλεγχος δύο βημάτων</strong><small>Κωδικός από το κινητό σε κάθε σύνδεση</small></a>
        <a href="settings.php#password"><strong>Αλλαγή κωδικού</strong><small>Και email, ερωτήσεις ασφαλείας, διαγραφή λογαριασμού</small></a>
        <?php if ($account['is_admin']): ?>
            <a href="admin.php"><strong>Κέντρο ασφαλείας</strong><small>Για διαχειριστές: επιθέσεις, κλειδωμένες IP, ημερολόγιο</small></a>
            <a href="review_documents.php"><strong>Έλεγχος εγγράφων</strong><small>Έγκριση εγγράφων και αναφορές παραβίασης</small></a>
        <?php endif; ?>
    </div>
</div>

<div class="box">
    <h2>Πώς σε προστατεύει το Knowledge</h2>
    <ul>
        <li><strong>Κωδικοί με hash:</strong> ο κωδικός σου δεν αποθηκεύεται πουθενά όπως τον γράφεις. Ούτε ο διαχειριστής δεν μπορεί να τον δει.</li>
        <li><strong>Κλείδωμα:</strong> μετά από 5 λάθος προσπάθειες, η σύνδεση κλειδώνει για 15 λεπτά.</li>
        <li><strong>Αυτόματη αποσύνδεση:</strong> μετά από 30 λεπτά χωρίς κίνηση.</li>
        <li><strong>Ισχυροί κωδικοί:</strong> δεν επιτρέπονται εύκολοι κωδικοί όπως «Password1!» ή κωδικοί με το username μέσα.</li>
        <li><strong>Έλεγχος αρχείων:</strong> κάθε έγγραφο, βίντεο και φωτογραφία ελέγχεται ότι είναι πραγματικά αυτό που λέει.</li>
        <li><strong>Προστασία από επιθέσεις:</strong> SQL Injection, XSS, CSRF και πρόσβαση σε ξένα δεδομένα (IDOR) μπλοκάρονται.</li>
        <li><strong>Ημερολόγιο ασφαλείας:</strong> κάθε σημαντική ενέργεια καταγράφεται, για να εντοπίζεται κάτι ύποπτο.</li>
    </ul>
</div>

<div class="box">
    <h2>Συμβουλές</h2>
    <ul>
        <li>Χρησιμοποίησε <strong>διαφορετικό κωδικό</strong> για κάθε site. Ένας «διαχειριστής κωδικών» (password manager) σε βοηθά να τους θυμάσαι.</li>
        <li>Ενεργοποίησε τον <strong>έλεγχο δύο βημάτων</strong> όπου μπορείς: email, τράπεζα, social media.</li>
        <li>Πρόσεχε το <strong>phishing</strong>: μην πατάς συνδέσμους σε ύποπτα email ή μηνύματα που σου ζητούν κωδικούς.</li>
        <li>Σε <strong>κοινόχρηστο υπολογιστή</strong> πάτα πάντα «Αποσύνδεση» όταν τελειώσεις.</li>
        <li>Μη λες σε κανέναν τον κωδικό σου ή τον κωδικό 2FA, ούτε σε κάποιον που λέει ότι είναι «από την υποστήριξη».</li>
    </ul>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
