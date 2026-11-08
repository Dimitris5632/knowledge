<?php
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$title = 'Ποιος είσαι;';
$page_style = 'vase-page';
require TEMPLATES_DIR . '/header.php';
?>

<?php $meander_id = 'top'; require TEMPLATES_DIR . '/meander.php'; ?>

<div class="vase-split">

    <a class="vase-side vase-philosophy" href="login.php?role=philosopher">
        <svg class="vase-icon" viewBox="0 0 100 100" aria-hidden="true">
            <g fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="20" y="12" width="60" height="8"/>
                <path d="M22 20 C22 34 38 34 38 26 C38 22 32 22 32 26 M78 20 C78 34 62 34 62 26 C62 22 68 22 68 26"/>
                <path d="M34 30 H66"/>
                <path d="M36 30 V86 M64 30 V86"/>
                <path d="M43 34 V82 M50 34 V82 M57 34 V82"/>
                <rect x="30" y="86" width="40" height="6"/>
                <rect x="24" y="92" width="52" height="4"/>
            </g>
        </svg>
        <span class="vase-label">ΦΙΛΟΣΟΦΙΑ</span>
        <strong class="vase-title">Είμαι Φιλόσοφος</strong>
        <em class="vase-quote">«Πάντα ῥεῖ»<br><small>Ηράκλειτος</small></em>
        <span class="vase-button">Συνέχεια &rarr;</span>
    </a>

    <a class="vase-side vase-history" href="login.php?role=historian">
        <svg class="vase-icon" viewBox="0 0 100 100" aria-hidden="true">
            <g fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M38 10 H62"/>
                <path d="M42 10 V22 C42 27 29 31 27 48 C25 66 37 82 50 86 C63 82 75 66 73 48 C71 31 58 27 58 22 V10"/>
                <path d="M42 18 C30 16 25 28 32 36"/>
                <path d="M58 18 C70 16 75 28 68 36"/>
                <path d="M44 86 L41 94 H59 L56 86"/>
                <path d="M28 46 H72 M27 56 H73"/>
                <path d="M30 56 V50 H36 V53 H33 M40 56 V50 H46 V53 H43 M50 56 V50 H56 V53 H53 M60 56 V50 H66 V53 H63"/>
                <path d="M38 66 C44 72 56 72 62 66"/>
            </g>
        </svg>
        <span class="vase-label">ΙΣΤΟΡΙΑ</span>
        <strong class="vase-title">Είμαι Ιστορικός</strong>
        <em class="vase-quote">«Κτῆμα ἐς αἰεί»<br><small>Θουκυδίδης</small></em>
        <span class="vase-button">Συνέχεια &rarr;</span>
    </a>

</div>

<?php $meander_id = 'bottom'; require TEMPLATES_DIR . '/meander.php'; ?>

<?php require TEMPLATES_DIR . '/footer.php'; ?>
