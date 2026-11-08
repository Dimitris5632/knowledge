<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

function load_account($pdo)
{
    $stmt = $pdo->prepare(
        'SELECT id, username, email, full_name, photo, password_hash, security_question
         FROM users WHERE id = ?'
    );
    $stmt->execute([current_user_id()]);
    return $stmt->fetch();
}

$account   = load_account($pdo);
$questions = security_questions();
$errors    = [];
$section   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Αν η φωτογραφία είναι τεράστια, η PHP αδειάζει όλη τη φόρμα
    if (empty($_POST) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Η φωτογραφία πρέπει να είναι έως 2 MB.';
        $section  = 'photo';
    } else {
        check_csrf();
        $section = $_POST['action'] ?? '';
        $current = $_POST['current_password'] ?? '';

        // Η φωτογραφία δεν ζητάει κωδικό. Όλες οι άλλες αλλαγές ζητάνε τον τωρινό κωδικό.
        $needs_password = in_array($section, ['password', 'email', 'question', 'delete', 'pin_enable'], true);

        if ($needs_password && too_many_login_attempts($pdo, $account['username'])) {
            $errors[] = 'Πάρα πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.';
        } elseif ($needs_password && !password_verify($current, $account['password_hash'])) {
            log_login_attempt($pdo, $account['username'], false);
            log_event($pdo, 'login_failed', $account['id'], $account['username'], 'ρυθμίσεις: λάθος τωρινός κωδικός');
            $errors[] = 'Ο τωρινός κωδικός δεν είναι σωστός.';
        }

        // ---------- 1. Αλλαγή κωδικού ----------
        if (!$errors && $section === 'password') {
            $password  = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            $errors = password_errors($password, $account['username']);
            if ($password !== $password2) {
                $errors[] = 'Οι δύο νέοι κωδικοί δεν ταιριάζουν.';
            }
            if (!$errors && password_verify($password, $account['password_hash'])) {
                $errors[] = 'Ο νέος κωδικός είναι ίδιος με τον παλιό.';
            }
            if (!$errors) {
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
                session_regenerate_id(true);
                log_event($pdo, 'password_changed', $account['id'], $account['username']);
                // Νέος κωδικός: αποσύνδεση από όλες τις ΑΛΛΕΣ συσκευές και άκυρα όλα τα PIN.
                // Αυτή η συσκευή μένει συνδεδεμένη (παίρνει τον νέο αριθμό).
                sign_out_everywhere($pdo, $account['id']);
                $_SESSION['sv'] = session_version($pdo, $account['id']);
                $_SESSION['flash'] = 'Ο κωδικός σου άλλαξε. Αποσυνδέθηκες από όλες τις άλλες συσκευές και τα PIN ακυρώθηκαν.';
                header('Location: settings.php');
                exit;
            }
        }

        // ---------- 2. Φωτογραφία ----------
        if (!$errors && $section === 'photo') {
            $remove = isset($_POST['remove_photo']);
            $photo  = ['name' => null, 'error' => ''];

            if (!$remove) {
                $photo = save_photo($_FILES['photo'] ?? null);
                if ($photo['error'] !== '') {
                    $errors[] = $photo['error'];
                    log_event($pdo, 'upload_rejected', $account['id'], $account['username'], 'φωτογραφία: ' . basename($_FILES['photo']['name'] ?? ''));
                } elseif ($photo['name'] === null) {
                    $errors[] = 'Διάλεξε μια φωτογραφία.';
                }
            }

            if (!$errors) {
                // Σβήνουμε την παλιά φωτογραφία από τον φάκελο (μόνο αν έχει το δικό μας τυχαίο όνομα)
                if ($account['photo']) {
                    media_delete('photos', $account['photo']);   // μόνο αρχεία με το δικό μας τυχαίο όνομα
                }
                $stmt = $pdo->prepare('UPDATE users SET photo = ? WHERE id = ?');
                $stmt->execute([$photo['name'], $account['id']]);
                log_event($pdo, 'photo_changed', $account['id'], $account['username'], $remove ? 'αφαίρεση' : '');
                $_SESSION['flash'] = $remove ? 'Η φωτογραφία αφαιρέθηκε.' : 'Η φωτογραφία σου άλλαξε.';
                header('Location: settings.php');
                exit;
            }
        }

        // ---------- 3. Email ----------
        if (!$errors && $section === 'email') {
            $email = trim($_POST['email'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                $errors[] = 'Το email δεν είναι έγκυρο.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $stmt->execute([$email, $account['id']]);
                if ($stmt->fetch()) {
                    $errors[] = 'Αυτό το email χρησιμοποιείται ήδη.';
                }
            }
            if (!$errors) {
                // Το νέο email αλλάζει μόνο αφού γραφτεί ο κωδικός που στάλθηκε σε αυτό
                $sent = send_email_code($pdo, 'change_email', $email, $account['id']);
                if ($sent['ok']) {
                    $_SESSION['email_change'] = ['email' => $email, 'time' => time()];
                    $_SESSION['flash'] = $sent['message'];
                    header('Location: settings.php#email');
                    exit;
                }
                $errors[] = $sent['message'];
            }
        }

        // ---------- 3β. Επιβεβαίωση νέου email με κωδικό ----------
        if (!$errors && $section === 'email_confirm' && isset($_SESSION['email_change'])) {
            $new_email = $_SESSION['email_change']['email'];
            if (($_POST['cancel'] ?? '') === '1') {
                unset($_SESSION['email_change']);
                header('Location: settings.php#email');
                exit;
            }
            $result = check_email_code($pdo, 'change_email', $new_email, $_POST['email_code'] ?? '');
            if ($result !== 'ok') {
                log_event($pdo, 'email_code_wrong', $account['id'], $account['username'], 'αλλαγή email');
                $errors[] = email_code_error($result);
                $section = 'email';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $stmt->execute([$new_email, $account['id']]);
                if ($stmt->fetch()) {
                    $errors[] = 'Αυτό το email χρησιμοποιείται ήδη.';
                    $section = 'email';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NOW() WHERE id = ?');
                    $stmt->execute([$new_email, $account['id']]);
                    log_event($pdo, 'email_changed', $account['id'], $account['username']);
                    unset($_SESSION['email_change']);
                    $_SESSION['flash'] = 'Το email σου άλλαξε σε ' . $new_email . '.';
                    header('Location: settings.php#email');
                    exit;
                }
            }
        }

        // ---------- 3γ. PIN γρήγορης σύνδεσης ----------
        if (!$errors && $section === 'pin_enable') {
            $pin  = $_POST['pin'] ?? '';
            $pin2 = $_POST['pin2'] ?? '';
            $problem = pin_problem($pin);
            if ($problem !== '') {
                $errors[] = $problem;
            } elseif ($pin !== $pin2) {
                $errors[] = 'Τα δύο PIN δεν ταιριάζουν.';
            } else {
                enable_device_pin($pdo, $account['id'], $pin);
                log_event($pdo, 'pin_enabled', $account['id'], $account['username'], device_label());
                $_SESSION['flash'] = 'Το PIN ενεργοποιήθηκε σε αυτή τη συσκευή (' . device_label() . ').';
                header('Location: settings.php#pin');
                exit;
            }
        }
        if (!$errors && $section === 'pin_remove') {
            $device_id = (int) ($_POST['device_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM device_pins WHERE id = ? AND user_id = ?');   // μόνο δικές του συσκευές
            $stmt->execute([$device_id, $account['id']]);
            if ($stmt->rowCount()) {
                $here = current_device_pin($pdo);
                if (!$here) {
                    pin_cookie_write('', 0);
                }
                log_event($pdo, 'pin_removed', $account['id'], $account['username']);
                $_SESSION['flash'] = 'Το PIN αφαιρέθηκε από τη συσκευή.';
            }
            header('Location: settings.php#pin');
            exit;
        }

        // ---------- 4. Ερώτηση ασφαλείας ----------
        if (!$errors && $section === 'question') {
            [$errors, $question_set] = read_question_set($_POST, $account['username'], $_POST['current_password'] ?? '');
            if (!$errors) {
                save_user_questions($pdo, $account['id'], $question_set);
                log_event($pdo, 'question_changed', $account['id'], $account['username']);
                $_SESSION['flash'] = 'Οι ερωτήσεις ασφαλείας σου αποθηκεύτηκαν.';
                header('Location: settings.php');
                exit;
            }
        }

        // ---------- 5. Διαγραφή λογαριασμού ----------
        if (!$errors && $section === 'delete') {
            if (!in_array($_POST['confirm_text'] ?? '', ['ΔΙΑΓΡΑΦΗ', 'DELETE'], true)) {   // DELETE για την αγγλική έκδοση
                $errors[] = 'Για επιβεβαίωση γράψε τη λέξη ΔΙΑΓΡΑΦΗ με κεφαλαία.';
            }
            if (!$errors) {
                // Μαζεύουμε τα αρχεία του χρήστη για να τα σβήσουμε και από τους φακέλους
                $files = [];
                if ($account['photo']) {
                    $files[] = media_file('photos', $account['photo']);
                }
                $stmt = $pdo->prepare('SELECT stored_name FROM documents WHERE user_id = ?');
                $stmt->execute([$account['id']]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $files[] = upload_path($name);
                }
                $stmt = $pdo->prepare("SELECT stored_name FROM videos WHERE user_id = ? AND source = 'file'");
                $stmt->execute([$account['id']]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $files[] = media_file('videos', $name);
                }

                // Σβήνουμε τον χρήστη. Το ON DELETE CASCADE σβήνει αυτόματα
                // βιογραφικό, έγγραφα, βίντεο, μηνύματα, debates και επιχειρήματά του.
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$account['id']]);

                foreach ($files as $path) {
                    // Σβήνουμε μόνο αρχεία με το δικό μας τυχαίο όνομα
                    if ($path !== null && preg_match('~^[a-f0-9]{32}\.[a-z0-9]+$~', basename($path)) && is_file($path)) {
                        unlink($path);
                    }
                }

                log_event($pdo, 'account_deleted', null, $account['username']);

                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['flash'] = 'Ο λογαριασμός σου διαγράφηκε οριστικά. Σε ευχαριστούμε που ήσουν μέλος του Knowledge.';
                header('Location: index.php');
                exit;
            }
        }
    }
}

$title = 'Ρυθμίσεις λογαριασμού';
require TEMPLATES_DIR . '/header.php';

// Τα λάθη εμφανίζονται μέσα στην ενότητα που τα προκάλεσε
function show_errors($errors, $section, $here)
{
    if ($section !== $here) {
        return;
    }
    foreach ($errors as $error) {
        echo '<p class="error">' . e(t($error)) . '</p>';
    }
}
?>

<h1>Ρυθμίσεις λογαριασμού</h1>
<p class="muted">Συνδεδεμένος ως <strong>@<?= e($account['username']) ?></strong>. Για τις αλλαγές κωδικού, email, ερωτήσεων ασφαλείας και τη διαγραφή σου ζητάμε τον τωρινό κωδικό σου.</p>

<div class="box" id="photo">
    <h2>Φωτογραφία προφίλ</h2>
    <?php show_errors($errors, $section, 'photo'); ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="photo">
        <div class="photo-picker">
            <?= avatar($account, 'large') ?>
            <label>Νέα φωτογραφία
                <small class="muted">JPG, PNG ή WEBP, έως 2 MB</small>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            </label>
        </div>
        <div class="actions">
            <button type="submit">Αλλαγή φωτογραφίας</button>
            <?php if ($account['photo']): ?>
                <button type="submit" name="remove_photo" value="1" class="button secondary" formnovalidate>Αφαίρεση φωτογραφίας</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="box" id="password">
    <h2>Αλλαγή κωδικού</h2>
    <?php show_errors($errors, $section, 'password'); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Τωρινός κωδικός
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>Νέος κωδικός <small class="muted">(τουλάχιστον 8 χαρακτήρες)</small>
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
            <small class="pw-help">Τουλάχιστον 8 χαρακτήρες, με κεφαλαίο, πεζό, αριθμό και σύμβολο. <span class="pw-count" id="pw-count">0 / 8</span></small>
        </label>
        <label>Επανάληψη νέου κωδικού
            <input type="password" name="password2" required minlength="8" autocomplete="new-password">
        </label>
        <?php require TEMPLATES_DIR . '/password_hint.php'; ?>
        <button type="submit">Αλλαγή κωδικού</button>
    </form>
</div>

<div class="box" id="email">
    <h2>Email</h2>
    <?php show_errors($errors, $section, 'email'); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email">
        <label>Νέο email
            <input type="email" name="email" value="<?= e($section === 'email' ? ($_POST['email'] ?? '') : $account['email']) ?>" required maxlength="255">
        </label>
        <label>Τωρινός κωδικός
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <button type="submit">Αλλαγή email</button>
    </form>

    <?php $email_change = $_SESSION['email_change'] ?? null; ?>
    <?php if ($email_change && time() - $email_change['time'] < 1800): ?>
        <div class="code-box">
            <p>Γράψε τον 6ψήφιο κωδικό που στείλαμε στο <strong><?= e($email_change['email']) ?></strong>.
               Το email σου θα αλλάξει μόλις τον γράψεις.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email_confirm">
                <label>Κωδικός από το email <?= code_input() ?></label>
                <button type="submit">Επιβεβαίωση νέου email</button>
            </form>
            <form method="post" class="panel-links">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="email_confirm">
                <input type="hidden" name="cancel" value="1">
                <button type="submit" class="link-button">Ακύρωση αλλαγής</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="box" id="pin">
    <h2>Γρήγορη σύνδεση με PIN</h2>
    <?php show_errors($errors, $section, 'pin_enable'); ?>
    <p class="muted">Σε αυτή τη συσκευή μπορείς να μπαίνεις με ένα 6ψήφιο PIN, χωρίς username και password.
       Το PIN δουλεύει <strong>μόνο</strong> στη συσκευή όπου το ενεργοποιείς. Μετά από 5 λάθη ακυρώνεται.</p>
    <?php $this_device = current_device_pin($pdo); ?>
    <?php if ($this_device && (int) $this_device['user_id'] === (int) $account['id']): ?>
        <p class="success">✓ Το PIN είναι ενεργό σε αυτή τη συσκευή (<?= e($this_device['device_label']) ?>).</p>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="pin_enable">
            <label>Νέο PIN (6 ψηφία)
                <input type="password" name="pin" class="code-input" required inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off">
            </label>
            <label>Επανάληψη PIN
                <input type="password" name="pin2" class="code-input" required inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off">
            </label>
            <small class="muted">Όχι εύκολα PIN όπως 123456, 111111, 121212 ή χρονιά γέννησης.</small>
            <label>Τωρινός κωδικός
                <input type="password" name="current_password" required autocomplete="current-password">
            </label>
            <button type="submit">Ενεργοποίηση PIN σε αυτή τη συσκευή</button>
        </form>
    <?php endif; ?>

    <?php $pin_devices = user_pin_devices($pdo, $account['id']); ?>
    <?php if ($pin_devices): ?>
        <h3>Συσκευές με PIN</h3>
        <ul class="device-list">
            <?php foreach ($pin_devices as $device): ?>
                <li>
                    <span><strong><?= e($device['device_label']) ?></strong>
                        <?php if ($this_device && (int) $this_device['id'] === (int) $device['id']): ?><em>(αυτή η συσκευή)</em><?php endif; ?><br>
                        <small class="muted">Τελευταία χρήση: <?= date('d/m/Y H:i', strtotime($device['last_used_at'] ?? $device['created_at'])) ?></small></span>
                    <form method="post" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="pin_remove">
                        <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                        <button type="submit" class="small-button">Αφαίρεση</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted">Έχασες το κινητό σου; Πάτα «Αφαίρεση» και το PIN του σταματά αμέσως να δουλεύει.</p>
    <?php endif; ?>
</div>

<div class="box" id="question">
    <h2>Ερωτήσεις ασφαλείας</h2>
    <?php show_errors($errors, $section, 'question'); ?>
    <?php $my_questions = user_questions($pdo, $account['id']); ?>
    <?php if (count($my_questions) < QUESTIONS_PER_USER): ?>
        <p class="warning">Έχεις <?= count($my_questions) ?> από <?= QUESTIONS_PER_USER ?> ερωτήσεις. Συμπλήρωσε και τις 3 για καλύτερη προστασία.</p>
    <?php endif; ?>
    <?php if ($my_questions): ?>
        <p class="muted">Τωρινές ερωτήσεις:</p>
        <ol class="muted">
            <?php foreach ($my_questions as $row): ?><li><?= e($row['question']) ?></li><?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="question">
        <?= question_set_fields() ?>
        <label>Τωρινός κωδικός
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <button type="submit">Αποθήκευση ερωτήσεων</button>
    </form>
</div>

<div class="box danger-zone" id="delete">
    <h2>Διαγραφή λογαριασμού</h2>
    <?php show_errors($errors, $section, 'delete'); ?>
    <p>Η διαγραφή είναι <strong>οριστική</strong>. Θα σβηστούν το προφίλ και το βιογραφικό σου, η φωτογραφία σου,
       τα έγγραφα και τα βίντεο που ανέβασες, τα μηνύματά σου, τα debates που άνοιξες (μαζί με τα επιχειρήματα
       των άλλων μέσα σε αυτά) και τα επιχειρήματά σου.</p>
    <form method="post" onsubmit="return confirm('Σίγουρα; Ο λογαριασμός σου θα διαγραφεί οριστικά.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <label>Γράψε <strong>ΔΙΑΓΡΑΦΗ</strong> για επιβεβαίωση
            <input type="text" name="confirm_text" required autocomplete="off">
        </label>
        <label>Τωρινός κωδικός
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <button type="submit" class="danger">Οριστική διαγραφή λογαριασμού</button>
    </form>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
