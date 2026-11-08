<?php
require __DIR__ . '/../src/bootstrap.php';

if (!is_logged_in()) {
    $title = 'Καλώς ήρθατε';
    $page_style = 'landing';
    require TEMPLATES_DIR . '/header.php';
    ?>

    <?php $meander_id = 'top'; require TEMPLATES_DIR . '/meander.php'; ?>

    <div class="hero">
        <h1>Καλώς ήρθατε στο <span>Knowledge</span></h1>
        <p>Ο τόπος συνάντησης φιλοσόφων και ιστορικών.<br>
           Βιογραφικά, ψηφιακή βιβλιοθήκη, debates και συζητήσεις.</p>
        <a class="button big" href="choose_role.php">Έναρξη</a>
        <?php $quote = quote_of_the_day($pdo); ?>
        <?php if ($quote): ?>
            <p class="hero-quote">«<?= e($quote['text']) ?>»<br><small><?= e($quote['author']) ?></small></p>
        <?php endif; ?>
    </div>

    <?php $meander_id = 'bottom'; require TEMPLATES_DIR . '/meander.php'; ?>

    <?php
    require TEMPLATES_DIR . '/footer.php';
    exit;
}

$members   = $pdo->query('SELECT COUNT(id) FROM users')->fetchColumn();
$active    = $pdo->query('SELECT COUNT(id) FROM users WHERE last_seen >= NOW() - INTERVAL 5 MINUTE')->fetchColumn();
$documents = $pdo->query("SELECT COUNT(id) FROM documents WHERE status = 'approved'")->fetchColumn();
$debates   = $pdo->query("SELECT COUNT(id) FROM debates WHERE status = 'open'")->fetchColumn();

$latest_debates = $pdo->query(
    "SELECT id, title FROM debates WHERE status = 'open' ORDER BY created_at DESC LIMIT 5"
)->fetchAll();
$latest_docs = $pdo->query(
    "SELECT id, title, author FROM documents WHERE status = 'approved' ORDER BY uploaded_at DESC LIMIT 5"
)->fetchAll();

$title = 'Αρχική';
require TEMPLATES_DIR . '/header.php';
?>

<h1>Καλώς ήρθες στο Knowledge</h1>

<?php $quote = quote_of_the_day($pdo); ?>
<?php if ($quote): ?>
    <blockquote class="quote-box">
        <span class="quote-label">Το ρητό της ημέρας</span>
        <p>«<?= e($quote['text']) ?>»</p>
        <cite><?= e($quote['author']) ?></cite>
    </blockquote>
<?php endif; ?>

<div class="stats">
    <div class="card"><strong><?= $members ?></strong><br>μέλη</div>
    <div class="card"><strong><?= $active ?></strong><br>ενεργοί τώρα</div>
    <div class="card"><strong><?= $documents ?></strong><br>έγγραφα</div>
    <div class="card"><strong><?= $debates ?></strong><br>ανοιχτά debates</div>
</div>

<div class="two-columns">
    <section>
        <h2>Πρόσφατα debates</h2>
        <?php if (!$latest_debates): ?><p class="muted">Κανένα ακόμα.</p><?php endif; ?>
        <ul>
            <?php foreach ($latest_debates as $d): ?>
                <li><a href="debate.php?id=<?= (int) $d['id'] ?>"><?= e($d['title']) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <a href="new_debate.php">+ Νέο debate</a>
    </section>
    <section>
        <h2>Νέα στη βιβλιοθήκη</h2>
        <?php if (!$latest_docs): ?><p class="muted">Κανένα ακόμα.</p><?php endif; ?>
        <ul>
            <?php foreach ($latest_docs as $doc): ?>
                <li>
                    <a href="download.php?id=<?= (int) $doc['id'] ?>"><?= e($doc['title']) ?></a>
                    <?php if ($doc['author']): ?><span class="muted">· <?= e($doc['author']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <a href="upload.php">+ Ανέβασε έγγραφο</a>
    </section>
</div>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
