<?php
// Αποσύνδεση: μόνο με κουμπί (POST + CSRF), ώστε ένας ξένος σύνδεσμος να μη μπορεί να σε αποσυνδέσει.
require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');           // απλός σύνδεσμος: δεν αποσυνδέει
    exit;
}
if (is_logged_in()) {
    check_csrf();
    log_event($pdo, 'logout', current_user_id());
}

// (Χωρίς σύνδεση, π.χ. «Ακύρωση» στην επιβεβαίωση email ή στο 2FA: απλώς καθαρίζουμε τα μισά βήματα.)
$_SESSION = [];
session_destroy();

header('Location: index.php');
exit;
