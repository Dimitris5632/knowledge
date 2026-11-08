<?php
require __DIR__ . '/../src/bootstrap.php';
require_admin($pdo);

// Ξεκλείδωμα μιας IP (σβήνουμε τις αποτυχημένες προσπάθειές της)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
    check_csrf();
    $ip = $_POST['ip'] ?? '';
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ? AND success = 0')->execute([$ip]);
        $pdo->prepare('DELETE FROM recovery_attempts WHERE ip_address = ? AND success = 0')->execute([$ip]);
        log_event($pdo, 'admin_unlock', current_user_id(), '', 'IP ' . $ip);
        $_SESSION['flash'] = 'Η IP ' . $ip . ' ξεκλειδώθηκε.';
    }
    header('Location: admin.php');
    exit;
}

// --- Αριθμοί τελευταίου 24ώρου ---
function count_events($pdo, $events)
{
    $marks = implode(', ', array_fill(0, count($events), '?'));
    $stmt  = $pdo->prepare("SELECT COUNT(id) FROM security_log WHERE event IN ($marks) AND created_at >= NOW() - INTERVAL 1 DAY");
    $stmt->execute($events);
    return (int) $stmt->fetchColumn();
}
$failed_logins = count_events($pdo, ['login_failed', '2fa_failed']);
$blocked       = count_events($pdo, ['login_locked']);
$bad_uploads   = count_events($pdo, ['upload_rejected']);
$csrf          = count_events($pdo, ['csrf_failed']);

$users_total = (int) $pdo->query('SELECT COUNT(id) FROM users')->fetchColumn();
$users_2fa   = (int) $pdo->query('SELECT COUNT(id) FROM users WHERE totp_enabled = 1')->fetchColumn();

// --- IP που είναι κλειδωμένες ΤΩΡΑ ---
$locked = $pdo->query(
    'SELECT ip_address, COUNT(id) AS fails, MAX(attempted_at) AS last_try, GROUP_CONCAT(DISTINCT username) AS usernames
     FROM login_attempts
     WHERE success = 0 AND attempted_at >= NOW() - INTERVAL 15 MINUTE
     GROUP BY ip_address
     HAVING COUNT(id) >= 5'
)->fetchAll();

$locked_recovery = $pdo->query(
    'SELECT ip_address, COUNT(id) AS fails, MAX(attempted_at) AS last_try
     FROM recovery_attempts
     WHERE success = 0 AND attempted_at >= NOW() - INTERVAL 15 MINUTE
     GROUP BY ip_address
     HAVING COUNT(id) >= 5'
)->fetchAll();

// --- Οι πιο «ύποπτες» IP της εβδομάδας ---
$top_ips = $pdo->query(
    "SELECT ip_address, COUNT(id) AS total, MAX(created_at) AS last_seen
     FROM security_log
     WHERE event IN ('login_failed', 'login_locked', 'recovery_failed', 'upload_rejected', 'csrf_failed', '2fa_failed')
       AND created_at >= NOW() - INTERVAL 7 DAY
     GROUP BY ip_address
     ORDER BY total DESC
     LIMIT 10"
)->fetchAll();

// --- Ημερολόγιο συμβάντων, με φίλτρο ---
$filter = $_GET['event'] ?? '';
$only_bad = isset($_GET['bad']);
$sql = 'SELECT l.event, l.username, l.ip_address, l.user_agent, l.details, l.created_at, u.full_name
        FROM security_log l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE 1 = 1';
$params = [];
if (array_key_exists($filter, event_labels())) {
    $sql .= ' AND l.event = ?';
    $params[] = $filter;
}
if ($only_bad) {
    $sql .= " AND l.event IN ('login_failed', 'login_locked', 'recovery_failed', 'upload_rejected', 'csrf_failed', '2fa_failed')";
}
$sql .= ' ORDER BY l.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$log = $stmt->fetchAll();

$title = 'Κέντρο ασφαλείας';
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="security.php">&larr; Ασφάλεια</a></p>
<h1>Κέντρο ασφαλείας</h1>
<?php $to_review = pending_review_count($pdo); ?>
<p><a class="button" href="review_documents.php">⚖️ Έλεγχος εγγράφων<?= $to_review ? ' (' . $to_review . ')' : '' ?></a></p>
<p class="muted">Τι συμβαίνει στο site από πλευράς ασφάλειας. Οι αριθμοί αφορούν το τελευταίο 24ωρο.</p>

<div class="stats">
    <div class="card <?= $failed_logins ? 'alert' : '' ?>"><strong><?= $failed_logins ?></strong><br>αποτυχημένες συνδέσεις</div>
    <div class="card <?= $blocked ? 'alert' : '' ?>"><strong><?= $blocked ?></strong><br>μπλοκαρίσματα</div>
    <div class="card <?= $bad_uploads ? 'alert' : '' ?>"><strong><?= $bad_uploads ?></strong><br>ύποπτα αρχεία</div>
    <div class="card <?= $csrf ? 'alert' : '' ?>"><strong><?= $csrf ?></strong><br>επιθέσεις CSRF</div>
    <div class="card"><strong><?= $users_2fa ?>/<?= $users_total ?></strong><br>μέλη με 2FA</div>
</div>

<?php
// --- Γράφημα: τι έγινε κάθε μέρα τις τελευταίες 7 ημέρες ---
$stmt = $pdo->query(
    "SELECT DATE(created_at) AS day,
            SUM(event = 'login_success') AS good,
            SUM(event IN ('login_failed', 'login_locked', '2fa_failed', 'recovery_failed')) AS bad,
            SUM(event IN ('upload_rejected', 'csrf_failed')) AS attacks
     FROM security_log
     WHERE created_at >= CURDATE() - INTERVAL 6 DAY
     GROUP BY DATE(created_at)"
);
$by_day = [];
foreach ($stmt->fetchAll() as $row) {
    $by_day[$row['day']] = $row;
}
$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chart[] = [
        'label'   => date('d/m', strtotime($day)),
        'good'    => (int) ($by_day[$day]['good'] ?? 0),
        'bad'     => (int) ($by_day[$day]['bad'] ?? 0),
        'attacks' => (int) ($by_day[$day]['attacks'] ?? 0),
    ];
}
$chart_max = max(1, max(array_map(fn($d) => max($d['good'], $d['bad'], $d['attacks']), $chart)));
?>

<div class="box">
    <h2>Η εβδομάδα σε γράφημα</h2>
    <div class="chart-legend">
        <span><i class="bar-good"></i> Επιτυχείς συνδέσεις</span>
        <span><i class="bar-bad"></i> Αποτυχίες / κλειδώματα</span>
        <span><i class="bar-attacks"></i> Ύποπτα αρχεία / CSRF</span>
    </div>
    <div class="chart">
        <?php foreach ($chart as $d): ?>
            <div class="chart-day">
                <div class="chart-bars">
                    <?php foreach (['good', 'bad', 'attacks'] as $kind): ?>
                        <div class="bar bar-<?= $kind ?>" style="height: <?= round($d[$kind] / $chart_max * 100) ?>%"
                             title="<?= $d['label'] ?>: <?= $d[$kind] ?>"><span><?= $d[$kind] ?: '' ?></span></div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-label"><?= $d['label'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="box">
    <h2>Κλειδωμένες IP τώρα</h2>
    <?php if (!$locked && !$locked_recovery): ?>
        <p class="muted">Καμία IP δεν είναι κλειδωμένη.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="log-table">
                <thead><tr><th>IP</th><th>Πού</th><th>Λάθη</th><th>Usernames που δοκίμασε</th><th>Τελευταία</th><th></th></tr></thead>
                <tbody>
                <?php foreach ([['Σύνδεση', $locked], ['Επαναφορά', $locked_recovery]] as [$where, $rows]): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr class="bad">
                            <td><?= e($row['ip_address']) ?></td>
                            <td><?= $where ?></td>
                            <td><?= (int) $row['fails'] ?></td>
                            <td><?= e($row['usernames'] ?? '') ?></td>
                            <td><?= date('H:i', strtotime($row['last_try'])) ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="unlock">
                                    <input type="hidden" name="ip" value="<?= e($row['ip_address']) ?>">
                                    <button type="submit" class="small-button">Ξεκλείδωμα</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="box">
    <h2>Οι πιο ύποπτες IP της εβδομάδας</h2>
    <?php if (!$top_ips): ?>
        <p class="muted">Τίποτα ύποπτο αυτή την εβδομάδα.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="log-table">
                <thead><tr><th>IP</th><th>Ύποπτες ενέργειες</th><th>Τελευταία φορά</th></tr></thead>
                <tbody>
                <?php foreach ($top_ips as $row): ?>
                    <tr>
                        <td><?= e($row['ip_address']) ?></td>
                        <td><?= (int) $row['total'] ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($row['last_seen'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="box">
    <h2>Ημερολόγιο συμβάντων</h2>
    <form method="get" class="filters">
        <select name="event">
            <option value="">Όλα τα συμβάντα</option>
            <?php foreach (event_labels() as $value => $label): ?>
                <option value="<?= $value ?>" <?= $filter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="inline"><input type="checkbox" name="bad" value="1" <?= $only_bad ? 'checked' : '' ?>> Μόνο ύποπτα</label>
        <button type="submit">Φιλτράρισμα</button>
    </form>

    <div class="table-wrap">
        <table class="log-table">
            <thead><tr><th>Πότε</th><th>Συμβάν</th><th>Χρήστης</th><th>IP</th><th>Browser</th></tr></thead>
            <tbody>
            <?php foreach ($log as $row): ?>
                <tr class="<?= is_bad_event($row['event']) ? 'bad' : '' ?>">
                    <td><?= date('d/m H:i:s', strtotime($row['created_at'])) ?></td>
                    <td><?= e(event_label($row['event'])) ?><?php if ($row['details']): ?> <small class="muted">(<?= e($row['details']) ?>)</small><?php endif; ?></td>
                    <td><?= e($row['full_name'] ?? $row['username']) ?></td>
                    <td><?= e($row['ip_address']) ?></td>
                    <td><?= e(short_browser($row['user_agent'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
