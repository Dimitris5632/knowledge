<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$stmt = $pdo->prepare(
    'SELECT event, ip_address, user_agent, details, created_at
     FROM security_log
     WHERE user_id = ?
     ORDER BY id DESC
     LIMIT 50'
);
$stmt->execute([current_user_id()]);
$events = $stmt->fetchAll();

// Πόσες ύποπτες ενέργειες έγιναν στον λογαριασμό τις τελευταίες 7 ημέρες
$stmt = $pdo->prepare(
    "SELECT COUNT(id) FROM security_log
     WHERE user_id = ? AND event IN ('login_failed', 'login_locked', 'recovery_failed', '2fa_failed')
       AND created_at >= NOW() - INTERVAL 7 DAY"
);
$stmt->execute([current_user_id()]);
$suspicious = (int) $stmt->fetchColumn();

$me = current_user($pdo);

$title = 'Η δραστηριότητά μου';
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="security.php">&larr; Ασφάλεια</a></p>
<h1>Η δραστηριότητά μου</h1>

<?php if ($suspicious > 0): ?>
    <div class="warning">
        <strong>Προσοχή:</strong> τις τελευταίες 7 ημέρες έγιναν <strong><?= $suspicious ?></strong>
        αποτυχημένες προσπάθειες πρόσβασης στον λογαριασμό σου.
        Αν δεν ήσουν εσύ, άλλαξε τον κωδικό σου
        <?php if (!$me['totp_enabled']): ?>και <a href="twofa.php">ενεργοποίησε τον έλεγχο δύο βημάτων</a><?php endif; ?>.
    </div>
<?php else: ?>
    <p class="success">Καμία ύποπτη προσπάθεια πρόσβασης τις τελευταίες 7 ημέρες.</p>
<?php endif; ?>

<p class="muted">Οι τελευταίες 50 ενέργειες στον λογαριασμό σου. Αν δεις κάτι που δεν έκανες εσύ, άλλαξε αμέσως τον κωδικό σου.</p>

<div class="table-wrap">
    <table class="log-table">
        <thead>
            <tr><th>Πότε</th><th>Τι έγινε</th><th>Διεύθυνση IP</th><th>Browser</th></tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev): ?>
                <tr class="<?= is_bad_event($ev['event']) ? 'bad' : '' ?>">
                    <td><?= date('d/m/Y H:i', strtotime($ev['created_at'])) ?></td>
                    <td><?= e(event_label($ev['event'])) ?><?php if ($ev['details']): ?> <small class="muted">(<?= e($ev['details']) ?>)</small><?php endif; ?></td>
                    <td><?= e($ev['ip_address']) ?></td>
                    <td><?= e(short_browser($ev['user_agent'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!$events): ?>
    <p class="muted">Δεν υπάρχει καταγεγραμμένη δραστηριότητα ακόμα.</p>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
