<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$allowed = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'ogg'  => 'video/ogg',
    'ogv'  => 'video/ogg',
];
$max_size = 41943040;

$errors = [];
$old = ['title' => '', 'creator' => '', 'year' => '', 'description' => '', 'category' => '', 'source' => 'file', 'youtube_url' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Το βίντεο είναι πολύ μεγάλο (έως 40 MB).';
    } else {
        check_csrf();

        $old = [
            'title'       => trim($_POST['title'] ?? ''),
            'creator'     => trim($_POST['creator'] ?? ''),
            'year'        => trim($_POST['year'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'category'    => $_POST['category'] ?? '',
            'source'      => ($_POST['source'] ?? '') === 'youtube' ? 'youtube' : 'file',
            'youtube_url' => trim($_POST['youtube_url'] ?? ''),
        ];

        if ($old['title'] === '' || mb_strlen($old['title']) > 200) {
            $errors[] = 'Γράψε τίτλο (έως 200 χαρακτήρες).';
        }
        if (mb_strlen($old['creator']) > 150) {
            $errors[] = 'Ο σκηνοθέτης/δημιουργός μπορεί να έχει έως 150 χαρακτήρες.';
        }
        if ($old['year'] !== '' && (!ctype_digit($old['year']) || $old['year'] < 1880 || $old['year'] > (int) date('Y'))) {
            $errors[] = 'Το έτος πρέπει να είναι αριθμός από 1880 έως ' . date('Y') . '.';
        }
        if (mb_strlen($old['description']) > 2000) {
            $errors[] = 'Η περιγραφή μπορεί να έχει έως 2000 χαρακτήρες.';
        }
        if (!array_key_exists($old['category'], categories())) {
            $errors[] = 'Διάλεξε θεματική.';
        }

        $stored_name = null;
        $youtube     = null;

        if ($old['source'] === 'youtube') {
            $youtube = youtube_id($old['youtube_url']);
            if ($youtube === null) {
                $errors[] = 'Ο σύνδεσμος δεν είναι έγκυρος σύνδεσμος βίντεο YouTube.';
            }
        } else {
            $file = $_FILES['video'] ?? null;
            $errors_before_file = count($errors);

            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Διάλεξε ένα αρχείο βίντεο.';
            } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $max_size) {
                $errors[] = 'Το βίντεο είναι πολύ μεγάλο (έως 40 MB).';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Κάτι πήγε στραβά με το ανέβασμα. Ξαναδοκίμασε.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $real_type = $finfo->file($file['tmp_name']);

                if (!array_key_exists($ext, $allowed)) {
                    $errors[] = 'Επιτρέπονται μόνο βίντεο MP4, WEBM ή OGG.';
                } elseif ($real_type !== $allowed[$ext]) {
                    $errors[] = 'Το περιεχόμενο του αρχείου δεν ταιριάζει με την κατάληξη .' . $ext . '.';
                }
                if (count($errors) > $errors_before_file) {
                    log_event($pdo, 'upload_rejected', current_user_id(), '', 'ταινιοθήκη: ' . basename($file['name']));
                }
            }

            if (!$errors) {
                $stored_name = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], media_path('videos', $stored_name))) {
                    $errors[] = 'Δεν ήταν δυνατή η αποθήκευση του βίντεο.';
                }
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO videos (user_id, category, title, creator, year, description, source, stored_name, youtube_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                current_user_id(), $old['category'], $old['title'], $old['creator'],
                $old['year'] === '' ? null : (int) $old['year'], $old['description'],
                $old['source'], $stored_name, $youtube,
            ]);

            header('Location: videos.php');
            exit;
        }
    }
}

$title = 'Πρόσθεσε στην ταινιοθήκη';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Πρόσθεσε ταινία ή βίντεο</h1>

<?php foreach ($errors as $error): ?>
    <p class="error"><?= e(t($error)) ?></p>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <p>
        <label class="inline"><input type="radio" name="source" value="file" <?= $old['source'] === 'file' ? 'checked' : '' ?>> Αρχείο από τον υπολογιστή</label>
        <label class="inline"><input type="radio" name="source" value="youtube" <?= $old['source'] === 'youtube' ? 'checked' : '' ?>> Σύνδεσμος YouTube</label>
    </p>

    <label id="field-file">Αρχείο βίντεο (MP4, WEBM ή OGG · έως 40 MB)
        <input type="file" name="video" accept="video/mp4,video/webm,video/ogg">
    </label>

    <label id="field-youtube">Σύνδεσμος YouTube
        <input type="text" name="youtube_url" value="<?= e($old['youtube_url']) ?>"
               placeholder="Π.χ. https://www.youtube.com/watch?v=...">
    </label>

    <label>Τίτλος
        <input type="text" name="title" value="<?= e($old['title']) ?>" required maxlength="200"
               placeholder="Π.χ. Η δίκη του Σωκράτη">
    </label>

    <label>Σκηνοθέτης ή δημιουργός
        <input type="text" name="creator" value="<?= e($old['creator']) ?>" maxlength="150"
               placeholder="Π.χ. Ρομπέρτο Ροσελίνι">
    </label>

    <label>Έτος
        <input type="text" name="year" value="<?= e($old['year']) ?>" maxlength="4" inputmode="numeric"
               placeholder="Π.χ. 1971">
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

    <button type="submit">Προσθήκη</button>
</form>

<script>
(function () {
    const radios  = document.querySelectorAll('input[name="source"]');
    const file    = document.getElementById('field-file');
    const youtube = document.getElementById('field-youtube');

    function update() {
        const choice = document.querySelector('input[name="source"]:checked').value;
        file.hidden    = choice !== 'file';
        youtube.hidden = choice !== 'youtube';
    }

    radios.forEach(function (radio) { radio.addEventListener('change', update); });
    update();
})();
</script>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
