<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Knowledge') ?> | Knowledge</title>
    <link rel="stylesheet" href="style.css">
    <!-- Εφαρμογή κινητού (PWA) -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1a1410">
    <link rel="icon" type="image/png" href="icons/favicon-64.png">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Knowledge">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <script>window.KB_LANG = '<?= current_lang() ?>';</script>
</head>
<body class="<?= e($page_style ?? '') ?>">
<?php $me = current_user($pdo); ?>
<nav id="site-nav">
    <a href="index.php" class="logo">Knowledge</a>

    <button type="button" id="menu-toggle" aria-label="Μενού" aria-expanded="false">☰</button>

    <span class="lang-switch" aria-label="Αλλαγή γλώσσας">
        <a href="<?= htmlspecialchars(lang_switch_url('el')) ?>" class="<?= current_lang() === 'el' ? 'active' : '' ?>" lang="el">ΕΛ</a>
        <a href="<?= htmlspecialchars(lang_switch_url('en')) ?>" class="<?= current_lang() === 'en' ? 'active' : '' ?>" lang="en">EN</a>
    </span>

    <div class="nav-links">
        <?php if ($me): ?>
            <?= nav_link('index.php', 'Αρχική') ?>
            <?= nav_link('members.php', 'Μέλη') ?>
            <?= nav_link('library.php', 'Βιβλιοθήκη') ?>
            <?= nav_link('videos.php', 'Ταινιοθήκη') ?>
            <?= nav_link('debates.php', 'Debates') ?>
            <?php $unread = unread_count($pdo); ?>
            <?= nav_link('messages.php', 'Μηνύματα <span class="badge" id="unread-badge"' . ($unread > 0 ? '' : ' hidden') . '>' . $unread . '</span>') ?>

            <form class="nav-search" action="search.php" method="get" role="search">
                <input type="text" name="q" placeholder="Αναζήτηση..." aria-label="Αναζήτηση"
                       value="<?= basename($_SERVER['SCRIPT_NAME']) === 'search.php' ? e($_GET['q'] ?? '') : '' ?>">
            </form>

            <details class="user-menu">
                <summary>
                    <?= avatar($me, 'nav') ?>
                    <span><?= e(strtok($me['full_name'], ' ')) ?></span>
                </summary>
                <div class="user-menu-list">
                    <a href="profile.php">Το προφίλ μου</a>
                    <a href="edit_profile.php">Επεξεργασία βιογραφικού</a>
                    <a href="favorites.php">Τα αγαπημένα μου</a>
                    <a href="settings.php">Ρυθμίσεις λογαριασμού</a>
                    <a href="security.php">Ασφάλεια</a>
                    <?php if ($me['is_admin']): ?>
                        <?php $to_review = pending_review_count($pdo); ?>
                        <a href="review_documents.php">Έλεγχος εγγράφων<?= $to_review ? ' <span class="badge">' . $to_review . '</span>' : '' ?></a>
                    <?php endif; ?>
                    <form method="post" action="logout.php" class="logout-form">
                        <?= csrf_field() ?>
                        <button type="submit">Αποσύνδεση</button>
                    </form>
                </div>
            </details>
        <?php else: ?>
            <?= nav_link('index.php', 'Αρχική') ?>
            <?= nav_link('contact.php', 'Επικοινωνία') ?>
            <?php $pin_device = current_device_pin($pdo); ?>
            <?php if ($pin_device): ?>
                <a href="pin_login.php" class="nav-cta">🔢 Σύνδεση με PIN</a>
            <?php else: ?>
                <a href="choose_role.php" class="nav-cta">Σύνδεση / Εγγραφή</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</nav>
<script>
// Εφαρμογή κινητού: ο service worker κάνει το site εγκαταστάσιμο
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
}
document.getElementById('menu-toggle').addEventListener('click', function () {
    const nav = document.getElementById('site-nav');
    const open = nav.classList.toggle('open');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
});
</script>
<?php if ($me): ?>
<script>
// Ζωντανές ειδοποιήσεις: κάθε 20 δευτερόλεπτα ρωτάμε τον server πόσα αδιάβαστα μηνύματα έχουμε
(function () {
    const badge = document.getElementById('unread-badge');
    const baseTitle = document.title;
    let last = parseInt(badge.textContent, 10) || 0;

    function show(count) {
        badge.textContent = count;
        badge.hidden = count === 0;
        document.title = (count > 0 ? '(' + count + ') ' : '') + baseTitle;
        if (count > last) {
            badge.classList.remove('pulse');
            void badge.offsetWidth;
            badge.classList.add('pulse');
        }
        last = count;
    }

    function check() {
        if (document.hidden) {
            return;
        }
        fetch('notifications.php', { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.logged_in === false) {
                    window.location.reload();
                    return;
                }
                show(data.unread);
            })
            .catch(function () {});
    }

    show(last);
    setInterval(check, 20000);
    document.addEventListener('visibilitychange', check);
})();
</script>
<?php endif; ?>
<?php if (empty($page_style)) { $meander_id = 'nav'; require TEMPLATES_DIR . '/meander.php'; } ?>
<main>
<?php if (!empty($_SESSION['flash'])): ?>
    <p class="flash"><?= e(t($_SESSION['flash'])) ?></p>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
