<?php
// Ζωντανός έλεγχος username στη φόρμα εγγραφής (απαντά σε JSON).
$passive_request = true;
require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Όριο: 40 έλεγχοι ανά 5 λεπτά ανά επισκέπτη, για να μη «σαρώνει» κάποιος όλα τα usernames.
$now = time();
$_SESSION['uname_checks'] = array_filter($_SESSION['uname_checks'] ?? [], function ($t) use ($now) {
    return $t > $now - 300;
});
if (count($_SESSION['uname_checks']) >= 40) {
    echo json_encode(['status' => 'slow', 'message' => t('Πολλοί έλεγχοι. Περίμενε λίγο και δοκίμασε ξανά.')]);
    exit;
}
$_SESSION['uname_checks'][] = $now;

$username = trim($_GET['u'] ?? '');
$role     = $_GET['role'] ?? '';
$status   = username_status($pdo, $username);

echo json_encode([
    'status'      => $status,
    'message'     => t(username_message($status, $username)),
    'suggestions' => $status === 'taken' ? username_suggestions($pdo, $username, $role) : [],
], JSON_UNESCAPED_UNICODE);
