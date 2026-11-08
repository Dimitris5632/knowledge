<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);

// Το debate και ποιος το άνοιξε
$stmt = $pdo->prepare(
    'SELECT d.id, d.user_id, d.title, d.description, d.status, d.created_at, u.full_name
     FROM debates d
     JOIN users u ON u.id = d.user_id
     WHERE d.id = ?'
);
$stmt->execute([$id]);
$debate = $stmt->fetch();

if (!$debate) {
    http_response_code(404);
    die('Το debate δεν βρέθηκε.');
}

$is_owner = ($debate['user_id'] == current_user_id());
$error  = '';
$body   = '';
$stance = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'post';

    if ($action === 'close') {
        // Μόνο όποιος το άνοιξε μπορεί να το κλείσει. Ο έλεγχος γίνεται ΕΔΩ,
        // όχι μόνο με το να κρύβουμε το κουμπί από τους άλλους.
        if (!$is_owner) {
            http_response_code(403);
            die('Μόνο όποιος άνοιξε το debate μπορεί να το κλείσει.');
        }
        $pdo->prepare("UPDATE debates SET status = 'closed' WHERE id = ?")->execute([$id]);
        header('Location: debate.php?id=' . $id);
        exit;
    }

    if ($action === 'like') {
        // «Συμφωνώ»: αν δεν έχω ψηφίσει το προσθέτει, αν έχω ψηφίσει το αφαιρεί
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT user_id FROM debate_posts WHERE id = ? AND debate_id = ?');
        $stmt->execute([$post_id, $id]);
        $post = $stmt->fetch();

        if ($post && $debate['status'] === 'open' && $post['user_id'] != current_user_id()) {
            $stmt = $pdo->prepare('DELETE FROM post_likes WHERE post_id = ? AND user_id = ?');
            $stmt->execute([$post_id, current_user_id()]);
            if ($stmt->rowCount() === 0) {
                $pdo->prepare('INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)')->execute([$post_id, current_user_id()]);
            }
        }
        header('Location: debate.php?id=' . $id . '#post-' . $post_id);
        exit;
    }

    // Αλλιώς: νέο επιχείρημα
    $body   = trim($_POST['body'] ?? '');
    $stance = $_POST['stance'] ?? '';

    if ($debate['status'] !== 'open') {
        $error = 'Το debate έχει κλείσει.';
    } elseif (!in_array($stance, ['for', 'against', 'neutral'], true)) {
        $error = 'Διάλεξε αν είσαι υπέρ, κατά ή ουδέτερος.';
    } elseif ($body === '' || mb_strlen($body) > 5000) {
        $error = 'Γράψε το επιχείρημά σου (έως 5000 χαρακτήρες).';
    } else {
        $stmt = $pdo->prepare('INSERT INTO debate_posts (debate_id, user_id, stance, body) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, current_user_id(), $stance, $body]);

        // Redirect μετά το POST: αν πατήσει F5 δεν ξαναστέλνεται το ίδιο επιχείρημα
        header('Location: debate.php?id=' . $id . '#post-' . $pdo->lastInsertId());
        exit;
    }
}

// Όλα τα επιχειρήματα, με τη σειρά που γράφτηκαν
$stmt = $pdo->prepare(
    'SELECT p.id, p.user_id, p.stance, p.body, p.created_at, u.full_name, u.role,
            (SELECT COUNT(l.post_id) FROM post_likes l WHERE l.post_id = p.id) AS likes,
            (SELECT COUNT(l.post_id) FROM post_likes l WHERE l.post_id = p.id AND l.user_id = ?) AS i_liked
     FROM debate_posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.debate_id = ?
     ORDER BY p.created_at, p.id'
);
$stmt->execute([current_user_id(), $id]);
$posts = $stmt->fetchAll();

// Το πιο πειστικό επιχείρημα: αυτό με τα περισσότερα «Συμφωνώ» (αν έχει τουλάχιστον 1)
$top_id = 0;
$top_likes = 0;
foreach ($posts as $p) {
    if ($p['likes'] > $top_likes) {
        $top_likes = (int) $p['likes'];
        $top_id = (int) $p['id'];
    }
}

// Μετράμε πόσα υπέρ / κατά / ουδέτερα
$counts = ['for' => 0, 'against' => 0, 'neutral' => 0];
foreach ($posts as $p) {
    $counts[$p['stance']]++;
}

$title = $debate['title'];
require TEMPLATES_DIR . '/header.php';
?>

<p><a href="debates.php">&larr; Όλα τα debates</a></p>

<div class="box">
    <h1>
        <?= e($debate['title']) ?>
        <?php if ($debate['status'] === 'closed'): ?><span class="tag">κλειστό</span><?php endif; ?>
    </h1>
    <p class="muted">
        Το άνοιξε ο/η <a href="profile.php?id=<?= (int) $debate['user_id'] ?>"><?= e($debate['full_name']) ?></a>
        στις <?= date('d/m/Y H:i', strtotime($debate['created_at'])) ?>
    </p>
    <p><?= e_multiline($debate['description']) ?></p>

    <p class="score">
        <span class="stance for">Υπέρ: <?= $counts['for'] ?></span>
        <span class="stance against">Κατά: <?= $counts['against'] ?></span>
        <span class="stance neutral">Ουδέτεροι: <?= $counts['neutral'] ?></span>
    </p>

    <?php if ($is_owner && $debate['status'] === 'open'): ?>
        <form method="post" onsubmit="return confirm('Κλείσιμο του debate; Δεν θα μπορούν να γραφτούν άλλα επιχειρήματα.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close">
            <button type="submit" class="small-button danger">Κλείσιμο debate</button>
        </form>
    <?php endif; ?>
</div>

<h2>Επιχειρήματα</h2>

<?php if (!$posts): ?>
    <p class="muted">Κανένα επιχείρημα ακόμα. Γράψε το πρώτο!</p>
<?php endif; ?>

<?php foreach ($posts as $p): ?>
    <div class="post <?= e($p['stance']) ?> <?= $p['id'] == $top_id ? 'top-post' : '' ?>" id="post-<?= (int) $p['id'] ?>">
        <p>
            <span class="stance <?= e($p['stance']) ?>"><?= stance_label($p['stance']) ?></span>
            <a href="profile.php?id=<?= (int) $p['user_id'] ?>"><strong><?= e($p['full_name']) ?></strong></a>
            <small class="muted">· <?= role_label($p['role']) ?> · <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></small>
            <?php if ($p['id'] == $top_id): ?><span class="top-label">Το πιο πειστικό</span><?php endif; ?>
        </p>
        <p><?= e_multiline($p['body']) ?></p>

        <div class="like-row">
            <?php if ($debate['status'] === 'open' && $p['user_id'] != current_user_id()): ?>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="like">
                    <input type="hidden" name="post_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="like-button <?= $p['i_liked'] ? 'liked' : '' ?>">
                        <?= $p['i_liked'] ? '✓ Συμφωνώ' : '👍 Συμφωνώ' ?>
                    </button>
                </form>
            <?php endif; ?>
            <span class="like-count"><?= (int) $p['likes'] ?> <?= $p['likes'] == 1 ? 'μέλος συμφωνεί' : 'μέλη συμφωνούν' ?></span>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($debate['status'] === 'open'): ?>
    <h2>Το επιχείρημά σου</h2>

    <?php if ($error): ?>
        <p class="error"><?= e(t($error)) ?></p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="post">

        <p>
            <label class="inline"><input type="radio" name="stance" value="for"     <?= $stance === 'for' ? 'checked' : '' ?>> Υπέρ</label>
            <label class="inline"><input type="radio" name="stance" value="against" <?= $stance === 'against' ? 'checked' : '' ?>> Κατά</label>
            <label class="inline"><input type="radio" name="stance" value="neutral" <?= $stance === 'neutral' ? 'checked' : '' ?>> Ουδέτερος</label>
        </p>

        <label>Επιχείρημα
            <textarea name="body" rows="6" maxlength="5000" required><?= e($body) ?></textarea>
        </label>

        <button type="submit">Δημοσίευση</button>
    </form>
<?php else: ?>
    <p class="muted">Το debate έχει κλείσει και δεν δέχεται νέα επιχειρήματα.</p>
<?php endif; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
