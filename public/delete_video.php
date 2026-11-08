<?php
require __DIR__ . '/../src/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: videos.php');
    exit;
}
check_csrf();

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT stored_name FROM videos WHERE id = ? AND user_id = ?');
$stmt->execute([$id, current_user_id()]);
$video = $stmt->fetch();

if (!$video) {
    http_response_code(404);
    die('Το βίντεο δεν βρέθηκε.');
}

if ($video['stored_name']) {
    media_delete('videos', $video['stored_name']);   // μόνιμος φάκελος (ή ο παλιός)
}
$pdo->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);

header('Location: videos.php');
exit;
