<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

if (!class_exists('finfo')) {
    die('Λείπει το extension "fileinfo". Στο XAMPP: Config (Apache) -> php.ini -> βγάλε το ; από τη γραμμή extension=fileinfo και κάνε restart το Apache.');
}

// Ποια αρχεία δεχόμαστε: κατάληξη => [τύπος για αποθήκευση, τύποι που επιτρέπουμε κατά τον έλεγχο]
// (τα docx/odt/epub είναι εσωτερικά zip, γι' αυτό κάποιοι υπολογιστές τα αναγνωρίζουν ως application/zip)
$allowed = [
    'pdf'  => ['application/pdf', ['application/pdf']],
    'doc'  => ['application/msword', ['application/msword']],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
               ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
    'odt'  => ['application/vnd.oasis.opendocument.text', ['application/vnd.oasis.opendocument.text', 'application/zip']],
    'epub' => ['application/epub+zip', ['application/epub+zip', 'application/zip']],
    'txt'  => ['text/plain', ['text/plain']],
    'jpg'  => ['image/jpeg', ['image/jpeg']],
    'jpeg' => ['image/jpeg', ['image/jpeg']],
    'png'  => ['image/png', ['image/png']],
    'webp' => ['image/webp', ['image/webp']],
];
$max_size = 10485760;

// Με ποιο δικαίωμα ανεβαίνει το έγγραφο (πνευματικά δικαιώματα)
$rights_options = [
    'own'        => 'Δικό μου έργο (το έγραψα εγώ)',
    'notes'      => 'Δικές μου σημειώσεις (με δικά μου λόγια, από μάθημα ή βιβλιογραφία)',
    'permission' => 'Με γραπτή άδεια του συγγραφέα',
    'public'     => 'Ελεύθερο κείμενο (π.χ. αρχαίοι συγγραφείς, παλιές εκδόσεις)',
];

$errors = [];
$old = ['title' => '', 'author' => '', 'description' => '', 'category' => '', 'rights' => '', 'source' => '', 'death_year' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Αν το αρχείο είναι τεράστιο, η PHP πετάει ΟΛΗ τη φόρμα και το $_POST έρχεται άδειο
    if (empty($_POST) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Το αρχείο είναι πολύ μεγάλο.';
    } else {
        check_csrf();

        $old = [
            'title'        => trim($_POST['title'] ?? ''),
            'author'       => trim($_POST['author'] ?? ''),
            'description'  => trim($_POST['description'] ?? ''),
            'category'     => $_POST['category'] ?? '',
            'rights'       => $_POST['rights'] ?? '',
            'source'       => trim($_POST['source'] ?? ''),
            'death_year'   => trim($_POST['death_year'] ?? ''),
        ];

        if ($old['title'] === '' || mb_strlen($old['title']) > 200) {
            $errors[] = 'Γράψε τίτλο (έως 200 χαρακτήρες).';
        }
        if ($old['author'] === '' || mb_strlen($old['author']) > 150) {
            $errors[] = 'Γράψε το όνομα του συγγραφέα (έως 150 χαρακτήρες). Κάθε κείμενο πρέπει να έχει το όνομα αυτού που το έγραψε.';
        }
        if (!array_key_exists($old['rights'], $rights_options)) {
            $errors[] = 'Διάλεξε με ποιο δικαίωμα ανεβάζεις το έγγραφο.';
        }
        if ($old['rights'] === 'notes' && $old['source'] === '') {
            $errors[] = 'Για σημειώσεις γράψε στην πηγή σε ποιο μάθημα ή ποια βιβλιογραφία βασίζονται.';
        } elseif (in_array($old['rights'], ['permission', 'public'], true) && $old['source'] === '') {
            $errors[] = 'Γράψε την πηγή: από ποια έκδοση είναι ή ποιος σου έδωσε την άδεια.';
        }
        $death_year = null;
        if ($old['rights'] === 'public') {
            if (!preg_match('/^-?\d{1,4}$/', $old['death_year']) || (int) $old['death_year'] > (int) date('Y')) {
                $errors[] = 'Για ελεύθερο κείμενο γράψε τη χρονιά θανάτου του συγγραφέα (π.Χ. με μείον, π.χ. Πλάτων: -347).';
            } else {
                $death_year = (int) $old['death_year'];
            }
        }
        if (mb_strlen($old['source']) > 255) {
            $errors[] = 'Η πηγή μπορεί να έχει έως 255 χαρακτήρες.';
        }
        if (!isset($_POST['rights_confirm'])) {
            $errors[] = 'Πρέπει να δηλώσεις ότι έχεις το δικαίωμα να ανεβάσεις το έγγραφο.';
        }
        if (mb_strlen($old['description']) > 2000) {
            $errors[] = 'Η περιγραφή μπορεί να έχει έως 2000 χαρακτήρες.';
        }
        if (!array_key_exists($old['category'], categories())) {
            $errors[] = 'Διάλεξε θεματική.';
        }

        // --- Έλεγχοι για το ίδιο το αρχείο ---
        $file = $_FILES['document'] ?? null;
        $errors_before_file = count($errors);

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Διάλεξε ένα αρχείο.';
        } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'Το αρχείο είναι πολύ μεγάλο (έως 10 MB).';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Κάτι πήγε στραβά με το ανέβασμα. Ξαναδοκίμασε.';
        } else {
            // 1. Μέγεθος
            if ($file['size'] > $max_size) {
                $errors[] = 'Το αρχείο είναι πολύ μεγάλο (έως 10 MB).';
            }

            // 2. Κατάληξη: μόνο όσες είναι στη λίστα (λευκή λίστα, όχι μαύρη)
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext])) {
                $errors[] = 'Επιτρέπονται μόνο αρχεία: ' . implode(', ', array_keys($allowed)) . '.';
            } else {
                // 3. Πραγματικό περιεχόμενο: ΔΕΝ εμπιστευόμαστε την κατάληξη ούτε τον τύπο
                //    που στέλνει ο browser. Κοιτάμε τα ίδια τα bytes του αρχείου.
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $real_type = $finfo->file($file['tmp_name']);
                if (!in_array($real_type, $allowed[$ext][1], true)) {
                    $errors[] = 'Το περιεχόμενο του αρχείου δεν ταιριάζει με την κατάληξη .' . $ext . '.';
                } elseif (is_image_type($real_type) && getimagesize($file['tmp_name']) === false) {
                    // 4. Για εικόνες: η PHP πρέπει να μπορεί να τη «διαβάσει» ως πραγματική εικόνα
                    $errors[] = 'Η εικόνα είναι χαλασμένη ή ψεύτικη.';
                }
            }
        }

        if (count($errors) > $errors_before_file && $file && $file['error'] === UPLOAD_ERR_OK) {
            log_event($pdo, 'upload_rejected', current_user_id(), '', 'βιβλιοθήκη: ' . basename($file['name']));
        }

        // 6. Έλεγχος νομιμότητας (ενδείξεις κινδύνου: ISBN, ©, εκδότες, 70 χρόνια, διπλότυπα, μαύρη λίστα)
        $risk = null;
        if (!$errors) {
            $risk = analyze_document($pdo, $file['tmp_name'], $ext, [
                'rights' => $old['rights'], 'death_year' => $death_year, 'source' => $old['source'],
                'description' => $old['description'], 'user_id' => current_user_id(),
            ]);
            if ($risk['blocked']) {
                log_event($pdo, 'doc_blocked', current_user_id(), '', basename($file['name']));
                $errors[] = $risk['reasons'][0] . ' Δεν μπορεί να ανέβει ξανά.';
            }
        }

        if (!$errors) {
            // 5. Νέο, τυχαίο όνομα: ο χρήστης δεν ελέγχει πού και με ποιο όνομα αποθηκεύεται
            $stored_name = bin2hex(random_bytes(16)) . '.' . $ext;

            if (!move_uploaded_file($file['tmp_name'], upload_path($stored_name))) {
                $errors[] = 'Δεν ήταν δυνατή η αποθήκευση του αρχείου.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO documents
                        (user_id, category, title, author, description,
                         original_name, stored_name, mime_type, file_size, rights, source,
                         file_hash, status, risk, risk_reasons, author_death_year)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                // Χαμηλός κίνδυνος (ή διαχειριστής): δημοσιεύεται. Αλλιώς περιμένει έγκριση.
                $me = current_user($pdo);
                $status = ($risk['level'] === 'low' || $me['is_admin']) ? 'approved' : 'pending';
                $stmt->execute([
                    current_user_id(), $old['category'], $old['title'], $old['author'], $old['description'],
                    mb_substr(basename($file['name']), 0, 255), $stored_name, $allowed[$ext][0], $file['size'],
                    $old['rights'], $old['source'] === '' ? null : $old['source'],
                    $risk['hash'], $status, $risk['level'], $risk['reasons'] ? implode("\n", $risk['reasons']) : null, $death_year,
                ]);
                if ($status === 'pending') {
                    log_event($pdo, 'doc_flagged', current_user_id(), '', $old['title'] . ' (' . $risk['level'] . ')');
                }
                $_SESSION['upload_result'] = ['title' => $old['title'], 'level' => $risk['level'],
                                              'reasons' => $risk['reasons'], 'status' => $status];
                header('Location: library.php');
                exit;
            }
        }
    }
}

$title = 'Ανέβασμα εγγράφου';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Ανέβασμα εγγράφου στη βιβλιοθήκη</h1>

<?php foreach ($errors as $error): ?>
    <p class="error"><?= e(t($error)) ?></p>
<?php endforeach; ?>

<!-- enctype="multipart/form-data": ΑΠΑΡΑΙΤΗΤΟ για να στέλνει η φόρμα αρχεία -->
<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="scanner-launch">
        <button type="button" id="scan-open" class="button">📷 Σκανάρισμα εγγράφου με την κάμερα</button>
        <small class="muted">Φωτογράφισε τις σελίδες των σημειώσεών σου και θα γίνουν ένα PDF.</small>
        <p id="scan-ready" class="success" hidden></p>
    </div>

    <label>Αρχείο (PDF, Word, ODT, EPUB, TXT ή εικόνα JPG, PNG, WEBP · έως 10 MB)
        <input type="file" name="document" accept=".<?= implode(',.', array_keys($allowed)) ?>" required>
    </label>

    <label>Τίτλος
        <input type="text" name="title" value="<?= e($old['title']) ?>" required maxlength="200"
               placeholder="Π.χ. Πολιτεία">
    </label>

    <label>Συγγραφέας του έργου
        <small class="muted">Υποχρεωτικό: κάθε κείμενο έχει το όνομα αυτού που το έγραψε.</small>
        <input type="text" name="author" value="<?= e($old['author']) ?>" required maxlength="150"
               placeholder="Π.χ. Πλάτων">
    </label>

    <label>Με ποιο δικαίωμα το ανεβάζεις;
        <select name="rights" required>
            <option value="">-- Διάλεξε --</option>
            <?php foreach ($rights_options as $value => $label): ?>
                <option value="<?= $value ?>" <?= $old['rights'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label id="death-year-field">Χρονιά θανάτου του συγγραφέα <small class="muted">(μόνο για ελεύθερο κείμενο)</small>
        <small class="muted">Στην Ευρώπη ένα κείμενο γίνεται ελεύθερο 70 χρόνια μετά τον θάνατο του συγγραφέα.
            Για π.Χ. γράψε με μείον: Πλάτων -347, Θουκυδίδης -400, Καντ 1804.</small>
        <input type="number" name="death_year" value="<?= e($old['death_year']) ?>" min="-3000" max="<?= date('Y') ?>" step="1">
    </label>

    <details class="box help-box">
        <summary>Πώς ανεβάζω σημειώσεις σωστά</summary>
        <ul>
            <li>✅ Γράψε τις σημειώσεις <strong>με δικά σου λόγια</strong>, με δική σου σειρά και δικά σου παραδείγματα.</li>
            <li>✅ Ιδέες, γεγονότα, ημερομηνίες και θεωρίες είναι ελεύθερα· το <strong>κείμενο</strong> του καθηγητή ή του βιβλίου όχι.</li>
            <li>✅ Μικρά αποσπάσματα επιτρέπονται μέσα σε «εισαγωγικά» και με την πηγή.</li>
            <li>❌ Όχι ίδια σειρά και δομή με «άλλες λέξεις» (κοντινή παράφραση).</li>
            <li>❌ Όχι πίνακες, διαγράμματα, ασκήσεις ή παραδείγματα του καθηγητή.</li>
            <li>❌ Όχι τίτλος «Σημειώσεις του καθηγητή Χ»: είναι <strong>δικές σου</strong> σημειώσεις.</li>
            <li>🤝 Το πιο σίγουρο: ρώτα τον καθηγητή. Με γραπτή άδεια διάλεξε «Με γραπτή άδεια του συγγραφέα».</li>
        </ul>
        <p class="muted">Στον <strong>συγγραφέα</strong> γράψε το δικό σου όνομα και στην <strong>πηγή</strong> π.χ.
           «Βασισμένες στο μάθημα Φιλοσοφία του Νου, ΕΚΠΑ 2025, και στη βιβλιογραφία του μαθήματος».</p>
    </details>

    <label>Πηγή
        <small class="muted">Π.χ. «Εκδόσεις Κάκτος, 1992, μτφρ. Ν. Μ. Σκουτερόπουλος», «Άδεια από τον συγγραφέα, 3/2026»
            ή για σημειώσεις «Βασισμένες στο μάθημα …». Υποχρεωτικό αν δεν είναι δικό σου έργο.</small>
        <input type="text" name="source" value="<?= e($old['source']) ?>" maxlength="255">
    </label>

    <label>Θεματική
        <select name="category" required>
            <option value="">-- Διάλεξε --</option>
            <?php foreach (categories() as $value => $label): ?>
                <option value="<?= $value ?>" <?= $old['category'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Περιγραφή
        <textarea name="description" rows="4" maxlength="2000"><?= e($old['description']) ?></textarea>
    </label>

    <label class="terms-check">
        <input type="checkbox" name="rights_confirm" value="1" required>
        <span>Δηλώνω ότι έχω το δικαίωμα να ανεβάσω αυτό το έγγραφο και ότι ο συγγραφέας και η πηγή
              είναι σωστά. Καταλαβαίνω ότι έγγραφα χωρίς δικαίωμα αφαιρούνται.</span>
    </label>

    <div class="box legal-box">
        <strong>⚖️ Αυτόματος έλεγχος νομιμότητας</strong>
        <p>Κάθε έγγραφο ελέγχεται για ενδείξεις παραβίασης πνευματικών δικαιωμάτων (π.χ. ISBN, ©, εκδοτικός οίκος,
           70 χρόνια από τον θάνατο του συγγραφέα, ίδιο αρχείο που υπάρχει ήδη).</p>
        <p>🟢 Χαμηλός κίνδυνος: δημοσιεύεται αμέσως · 🟡 Χρειάζεται έλεγχο · 🔴 Πιθανή παραβίαση: περιμένουν έγκριση από τον διαχειριστή.</p>
        <p class="muted">Ο έλεγχος δίνει ενδείξεις, όχι νομική γνωμάτευση. Την ευθύνη για το έγγραφο την έχει αυτός που το ανεβάζει.</p>
    </div>

    <button type="submit">Ανέβασμα</button>
</form>

<!-- Scanner εγγράφων: όλη η επεξεργασία γίνεται μέσα στη συσκευή (scanner.js) -->
<div id="scanner" class="scanner" hidden role="dialog" aria-label="Σκανάρισμα εγγράφου">
    <div class="scanner-top">
        <strong>📷 Σκανάρισμα εγγράφου</strong>
        <button type="button" data-scan="close" aria-label="Κλείσιμο">✕</button>
    </div>
    <p class="scanner-warning">⚖️ Σκάναρε <strong>δικές σου</strong> σημειώσεις ή κείμενα ελεύθερα δικαιωμάτων.
        Το σκανάρισμα σελίδων από βιβλία εκδοτών δεν επιτρέπεται.</p>

    <div class="scanner-step" data-step="pages">
        <div class="scan-pages" id="scan-pages"></div>
        <div class="scan-buttons">
            <label class="button scan-add">➕ Φωτογραφία σελίδας
                <input type="file" id="scan-camera" accept="image/*" capture="environment" hidden>
            </label>
            <label class="small-button">🖼️ Από τη συλλογή
                <input type="file" id="scan-gallery" accept="image/*" multiple hidden>
            </label>
        </div>
        <button type="button" id="scan-make" class="button" disabled>📄 Δημιουργία PDF</button>
        <p class="muted">Συμβουλή: βάλε το χαρτί σε σκούρα επιφάνεια, με καλό φως και χωρίς σκιές.</p>
    </div>

    <div class="scanner-step" data-step="edit" hidden>
        <p class="muted">Σύρε τις 4 πορτοκαλί γωνίες πάνω στις άκρες του χαρτιού.</p>
        <div class="scan-stage"><canvas id="scan-canvas"></canvas></div>
        <div class="scan-tools">
            <button type="button" data-scan="rotate" class="small-button">↻ Περιστροφή</button>
            <button type="button" data-scan="reset" class="small-button">⤢ Γωνίες</button>
            <select id="scan-filter" aria-label="Φίλτρο">
                <option value="clean">Φωτοτυπία (καθαρό)</option>
                <option value="gray">Γκρι</option>
                <option value="color">Χρώμα</option>
                <option value="bw">Ασπρόμαυρο</option>
            </select>
        </div>
        <div class="scan-tools">
            <button type="button" data-scan="cancel-edit" class="small-button">Άκυρο</button>
            <button type="button" data-scan="save-page" class="button">✓ Προσθήκη σελίδας</button>
        </div>
    </div>

    <p id="scan-status" class="scan-status" aria-live="polite"></p>
</div>
<script src="scanner.js"></script>

<script>
// Η χρονιά θανάτου εμφανίζεται μόνο όταν διαλέξεις «Ελεύθερο κείμενο»
(function () {
    const rights = document.querySelector('select[name="rights"]');
    const field  = document.getElementById('death-year-field');
    function toggle() {
        const show = rights.value === 'public';
        field.hidden = !show;
        field.querySelector('input').required = show;
    }
    rights.addEventListener('change', toggle);
    toggle();
})();
</script>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
