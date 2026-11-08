<?php
// Αυτόματος έλεγχος από το header (κάθε 20''): ΔΕΝ μετράει ως κίνηση του χρήστη
$passive_request = true;
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    echo json_encode(['logged_in' => false]);
    exit;
}

echo json_encode(['logged_in' => true, 'unread' => unread_count($pdo)]);
