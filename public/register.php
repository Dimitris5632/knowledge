<?php
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$role = $_POST['role'] ?? $_GET['role'] ?? '';
if (!array_key_exists($role, roles())) {
    header('Location: choose_role.php');
    exit;
}

$questions = security_questions();
$errors = [];
$old = ['username' => '', 'email' => '', 'full_name' => '', 'q' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $old = compact('username', 'email', 'full_name');
    $old['q'] = array_map('intval', (array) ($_POST['q'] ?? []));

    $username_state = username_status($pdo, $username);
    if ($username_state === 'invalid') {
        $errors[] = 'Το username πρέπει να έχει 3-30 λατινικούς χαρακτήρες, αριθμούς ή _.';
    } elseif ($username_state === 'reserved') {
        $errors[] = username_message('reserved', $username);
    } elseif ($username_state === 'taken') {
        $ideas = username_suggestions($pdo, $username, $role);
        $errors[] = username_message('taken', $username) . ($ideas ? ' Δοκίμασε π.χ.: ' . implode(', ', $ideas) . '.' : ' Διάλεξε άλλο.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Το email δεν είναι έγκυρο.';
    }
    if ($full_name === '' || mb_strlen($full_name) > 100) {
        $errors[] = 'Γράψε το ονοματεπώνυμό σου (έως 100 χαρακτήρες).';
    }
    $errors = array_merge($errors, password_errors($password, $username));
    if ($password !== $password2) {
        $errors[] = 'Τα δύο password δεν ταιριάζουν.';
    }
    if ($bot = turnstile_error($pdo, 'εγγραφή')) {          // Cloudflare: όχι ψεύτικοι λογαριασμοί από bots
        $errors[] = $bot;
    }
    [$question_errors, $question_set] = read_question_set($_POST, $username, $password);
    $errors = array_merge($errors, $question_errors);
    if (!isset($_POST['accept_terms'])) {
        $errors[] = 'Πρέπει να αποδεχτείς τους Όρους Χρήσης και την Πολιτική απορρήτου.';
    }

    if (!$errors) {
        cleanup_unverified_accounts($pdo);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            // Το email υπάρχει ήδη: δείχνουμε την ΙΔΙΑ οθόνη «σου στείλαμε κωδικό», για να μη μαθαίνει
            // κανείς ποια email είναι μέλη. Στον πραγματικό κάτοχο πάει ειδοποίηση.
            notify_existing_email($pdo, $email);
            session_regenerate_id(true);
            $_SESSION['verify_pending'] = ['user_id' => null, 'username' => $username, 'email' => $email,
                                           'time' => time(), 'next' => 'edit_profile.php', 'fake' => true];
            $_SESSION['verify_notice'] = ['ok' => true, 'message' => 'Σου στείλαμε 6ψήφιο κωδικό στο ' . mask_email($email) . '. Κοίτα και στα ανεπιθύμητα (spam).'];
            header('Location: verify_email.php');
            exit;
        }
    }

    if (!$errors) {
        $photo = save_photo($_FILES['photo'] ?? null);
        if ($photo['error'] !== '') {
            $errors[] = $photo['error'];
            log_event($pdo, 'upload_rejected', null, $username, 'φωτογραφία: ' . basename($_FILES['photo']['name'] ?? ''));
        }
    }

    if (!$errors) {
        $fields = role_fields($role);
        $table  = $fields['table'];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (username, email, password_hash, full_name, role, security_question, security_answer_hash, photo, terms_accepted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $full_name,
                $role,
                $question_set[0][0],
                password_hash($question_set[0][1], PASSWORD_DEFAULT),
                $photo['name'],
            ]);
            $user_id = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO $table (user_id) VALUES (?)")->execute([$user_id]);
            save_user_questions($pdo, $user_id, $question_set);

            $pdo->commit();
        } catch (PDOException $ex) {
            // Το UNIQUE της βάσης: κάποιος άλλος πήρε το ίδιο username ή email την ίδια στιγμή.
            $pdo->rollBack();
            if (!empty($photo['name'])) {
                media_delete('photos', $photo['name']);
            }
            if ($ex->getCode() !== '23000') {
                throw $ex;
            }
            $errors[] = 'Μόλις το πήρε άλλο μέλος: το username ή το email χρησιμοποιείται ήδη. Διάλεξε άλλο.';
        }
    }

    if (!$errors && isset($user_id)) {
        log_event($pdo, 'register', $user_id, $username);

        // Πριν μπει, επιβεβαιώνει ότι το email είναι δικό του
        session_regenerate_id(true);
        $_SESSION['verify_pending'] = ['user_id' => $user_id, 'username' => $username, 'email' => $email,
                                       'time' => time(), 'next' => 'edit_profile.php'];
        $sent = send_email_code($pdo, 'register', $email, $user_id);
        $_SESSION['verify_notice'] = $sent;
        header('Location: verify_email.php');
        exit;
    }
}

$title = 'Εγγραφή';
$page_style = 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="panel">
    <h1>Εγγραφή ως <?= role_label($role) ?></h1>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= e(t($error)) ?></p>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="<?= e($role) ?>">

        <div class="photo-picker">
            <img id="photo-preview" class="avatar avatar-large" src="" alt="" hidden>
            <span id="photo-empty" class="avatar avatar-large avatar-empty">+</span>
            <label>Φωτογραφία προφίλ (προαιρετικό)
                <small class="muted">JPG, PNG ή WEBP, έως 2 MB</small>
                <input type="file" name="photo" id="photo-input" accept="image/jpeg,image/png,image/webp">
            </label>
        </div>

        <label>Username
            <input type="text" name="username" id="username-input" value="<?= e($old['username']) ?>" required maxlength="30"
                   autocomplete="username" autocapitalize="none" spellcheck="false" aria-describedby="username-status">
            <small class="username-status" id="username-status" aria-live="polite">3-30 λατινικοί χαρακτήρες, αριθμοί ή _</small>
            <span class="username-ideas" id="username-ideas"></span>
        </label>

        <label>Email
            <input type="email" name="email" value="<?= e($old['email']) ?>" required>
        </label>

        <label>Ονοματεπώνυμο
            <input type="text" name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="100">
        </label>

        <label>Password <small class="muted">(τουλάχιστον 8 χαρακτήρες)</small>
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
            <small class="pw-help">Τουλάχιστον 8 χαρακτήρες, με κεφαλαίο, πεζό, αριθμό και σύμβολο. <span class="pw-count" id="pw-count">0 / 8</span></small>
        </label>

        <label>Επανάληψη password
            <input type="password" name="password2" required minlength="8">
        </label>

        <?php require TEMPLATES_DIR . '/password_hint.php'; ?>

        <fieldset class="question-set">
            <legend>Ερωτήσεις ασφαλείας</legend>
            <small class="muted">Διάλεξε 3 διαφορετικές ερωτήσεις. Αν ξεχάσεις το username ή τον κωδικό σου,
            θα σε ρωτήσουμε 2 από αυτές. Δεν μετράνε τόνοι και κεφαλαία.</small>
            <?= question_set_fields($old['q']) ?>
        </fieldset>

        <label class="terms-check">
            <input type="checkbox" name="accept_terms" value="1" required>
            <span>Διάβασα και αποδέχομαι τους <a href="terms.php" target="_blank">Όρους Χρήσης</a>
            και την <a href="privacy.php" target="_blank">Πολιτική απορρήτου</a></span>
        </label>

        <?= turnstile_widget() ?>
        <div class="panel-buttons">
            <button type="submit">Εγγραφή</button>
            <a class="button secondary" href="login.php?role=<?= e($role) ?>">Έχω ήδη λογαριασμό</a>
        </div>
    </form>
</div>

<script>
document.getElementById('photo-input').addEventListener('change', function () {
    const preview = document.getElementById('photo-preview');
    const empty   = document.getElementById('photo-empty');
    if (this.files.length > 0) {
        preview.src = URL.createObjectURL(this.files[0]);
        preview.hidden = false;
        empty.hidden = true;
    }
});
</script>

<script>
// Ζωντανός έλεγχος: μόλις σταματήσεις να γράφεις, ρωτάμε τη βάση αν το username είναι ελεύθερο.
(function () {
    const input  = document.getElementById('username-input');
    const status = document.getElementById('username-status');
    const ideas  = document.getElementById('username-ideas');
    const role   = <?= json_encode($role) ?>;
    let timer = null;
    let last  = '';

    function show(state, text) {
        status.className = 'username-status ' + state;
        status.textContent = text;
    }

    function check() {
        const value = input.value.trim();
        if (value === last) { return; }
        last = value;
        ideas.textContent = '';
        if (value === '') { show('', '3-30 λατινικοί χαρακτήρες, αριθμοί ή _'); return; }
        if (!/^[a-zA-Z0-9_]{3,30}$/.test(value)) { show('bad', '3-30 λατινικοί χαρακτήρες, αριθμοί ή _ (χωρίς κενά).'); return; }
        show('wait', 'Έλεγχος…');
        fetch('check_username.php?u=' + encodeURIComponent(value) + '&role=' + encodeURIComponent(role), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (input.value.trim() !== value) { return; }
                show(data.status === 'ok' ? 'good' : (data.status === 'slow' ? 'wait' : 'bad'),
                     (data.status === 'ok' ? '✓ ' : (data.status === 'slow' ? '' : '✗ ')) + data.message);
                (data.suggestions || []).forEach(function (name) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'username-idea';
                    b.textContent = name;
                    b.addEventListener('click', function () { input.value = name; check(); input.focus(); });
                    ideas.appendChild(b);
                });
            })
            .catch(function () { show('', ''); last = ''; });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 450); });
    input.addEventListener('blur', check);
    if (input.value.trim() !== '') { check(); }
})();
</script>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
