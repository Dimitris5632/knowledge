<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();   // μόνο τα μέλη κατεβάζουν έγγραφα

$id = (int) ($_GET['id'] ?? 0);

// Έγγραφα σε έλεγχο ή απορριμμένα: μόνο ο ιδιοκτήτης και ο διαχειριστής (IDOR)
$stmt = $pdo->prepare('SELECT original_name, stored_name, mime_type FROM documents d WHERE id = ? AND ' . visible_documents_sql('d'));
$stmt->execute([$id]);
$doc = $stmt->fetch();

// Το όνομα του αρχείου στον δίσκο το παίρνουμε από τη ΒΑΣΗ, ποτέ από το URL.
// Αν γράφαμε download.php?file=..., κάποιος θα έβαζε file=../config.php
// και θα κατέβαζε τον κώδικά μας (path traversal).
$path = $doc ? upload_path($doc['stored_name']) : '';

if (!$doc || !is_file($path)) {
    http_response_code(404);
    die('Το έγγραφο δεν βρέθηκε.');
}

// Τα PDF και οι εικόνες μπορούν να ανοίξουν μέσα στον browser (view=1), όλα τα άλλα κατεβαίνουν
$inline = isset($_GET['view']) && is_viewable_type($doc['mime_type']);
$ext    = pathinfo($doc['stored_name'], PATHINFO_EXTENSION);

// Το αρχείο πάει κατευθείαν στον browser (όχι από τον «μεταφραστή σελίδας»)
while (ob_get_level()) {
    ob_end_clean();
}
header_remove('Content-Security-Policy');
if ($doc['mime_type'] !== 'application/pdf') {
    // Εικόνες, κείμενα, Word κ.λπ.: «κλειδωμένο κουτί», ώστε ένα πονηρό αρχείο να μην εκτελεί τίποτα.
    // (Στα PDF δεν μπαίνει, γιατί ο Chrome τότε δεν τα ανοίγει· το PDF το ανοίγει ο δικός του ασφαλής viewer.)
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');   // ο browser να μη "μαντεύει" άλλο τύπο αρχείου
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="document.' . $ext . '"'
    . "; filename*=UTF-8''" . rawurlencode($doc['original_name']));

readfile($path);
exit;
