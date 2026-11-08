<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: library.php');
    exit;
}
check_csrf();

$id = (int) ($_POST['id'] ?? 0);

// Βρίσκουμε το έγγραφο ΜΟΝΟ αν το ανέβασε ο συνδεδεμένος χρήστης (όχι IDOR)
$stmt = $pdo->prepare('SELECT stored_name FROM documents WHERE id = ? AND user_id = ?');
$stmt->execute([$id, current_user_id()]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Το έγγραφο δεν βρέθηκε.');
}

// Σβήνουμε και το αρχείο από τον δίσκο και τη γραμμή από τη βάση
$path = upload_path($doc['stored_name']);
if (is_file($path)) {
    unlink($path);
}
$pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);

header('Location: library.php');
exit;
