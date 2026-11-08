<?php
require __DIR__ . '/../src/bootstrap.php';
$title = 'Εφαρμογή κινητού';
$page_style = is_logged_in() ? '' : 'landing';
require TEMPLATES_DIR . '/header.php';
?>

<div class="<?= is_logged_in() ? 'box' : 'panel' ?> app-page">
    <h1>📱 Το Knowledge στο κινητό σου</h1>
    <p>Βάλε το Knowledge στην αρχική οθόνη του κινητού σου και άνοιγέ το σαν κανονική εφαρμογή, με δικό του εικονίδιο,
       χωρίς Google Play ή App Store.</p>

    <p><button type="button" class="button" id="install-app-big" hidden>📱 Εγκατάσταση τώρα</button></p>

    <h2>Android (Chrome)</h2>
    <ol>
        <li>Άνοιξε το Knowledge στο <strong>Chrome</strong>.</li>
        <li>Πάτα τις <strong>⋮ τρεις τελείες</strong> πάνω δεξιά.</li>
        <li>Πάτα <strong>«Εγκατάσταση εφαρμογής»</strong> ή <strong>«Προσθήκη στην αρχική οθόνη»</strong>.</li>
    </ol>

    <h2>iPhone / iPad (Safari)</h2>
    <ol>
        <li>Άνοιξε το Knowledge στο <strong>Safari</strong>.</li>
        <li>Πάτα το κουμπί <strong>Κοινή χρήση</strong> (τετράγωνο με βελάκι ⬆) κάτω στη μέση.</li>
        <li>Πάτα <strong>«Προσθήκη στην οθόνη Αφετηρίας»</strong> → <strong>Προσθήκη</strong>.</li>
    </ol>

    <h2>Τι κερδίζεις</h2>
    <ul>
        <li>🏛️ Εικονίδιο Knowledge στην αρχική οθόνη, άνοιγμα σε πλήρη οθόνη.</li>
        <li>📷 <strong>Σκανάρισμα εγγράφων</strong> με την κάμερα: στο «Ανέβασμα εγγράφου» πάτα «Σκανάρισμα».</li>
        <li>🔢 <strong>Γρήγορη σύνδεση με PIN</strong>: ενεργοποίησέ το από τις Ρυθμίσεις.</li>
    </ul>
</div>

<script>
(function () {
    const button = document.getElementById('install-app-big');
    let prompt = null;
    window.addEventListener('beforeinstallprompt', function (event) { event.preventDefault(); prompt = event; button.hidden = false; });
    button.addEventListener('click', function () { if (prompt) { prompt.prompt(); } });
})();
</script>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
