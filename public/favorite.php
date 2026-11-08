<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: favorites.php');
    exit;
}
check_csrf();

$type = $_POST['type'] ?? '';
$id   = (int) ($_POST['id'] ?? 0);

// Πού γυρνάμε μετά: ΜΟΝΟ σε δικές μας σελίδες (όχι σε ό,τι γράψει κάποιος στη φόρμα)
$back = $_POST['back'] ?? '';
if (!preg_match('/^(library|videos|favorites)\.php(\?[A-Za-z0-9_=&%.+-]*)?$/', $back)) {
    $back = 'favorites.php';
}

if (find_item($pdo, $type, $id)) {
    // Αν είναι ήδη στα αγαπημένα το βγάζουμε, αλλιώς το προσθέτουμε
    $stmt = $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND item_type = ? AND item_id = ?');
    $stmt->execute([current_user_id(), $type, $id]);
    if ($stmt->rowCount() === 0) {
        $pdo->prepare('INSERT INTO favorites (user_id, item_type, item_id) VALUES (?, ?, ?)')
            ->execute([current_user_id(), $type, $id]);
    }
}

header('Location: ' . $back . '#' . $type . '-' . $id);
exit;
