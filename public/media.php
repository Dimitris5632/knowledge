<?php
// Φωτογραφίες προφίλ και βίντεο από τον μόνιμο φάκελο (μόνο για μέλη).
// Υποστηρίζει «Range», για να μπορείς να πηγαίνεις μπρος-πίσω στα βίντεο (και στο iPhone).
$passive_request = true;
require __DIR__ . '/../src/bootstrap.php';
require_login();

$kind = ($_GET['t'] ?? '') === 'v' ? 'videos' : 'photos';
$path = media_file($kind, $_GET['f'] ?? '');
if ($path === null) {
    http_response_code(404);
    exit;
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
          'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg', 'ogv' => 'video/ogg'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!isset($types[$ext]) || ($kind === 'photos') !== (strpos($types[$ext], 'image/') === 0)) {
    http_response_code(404);
    exit;
}

// Κατευθείαν στον browser, όχι από τον «μεταφραστή σελίδας»
while (ob_get_level()) {
    ob_end_clean();
}
session_write_close();          // να μην «κλειδώνει» το session όσο παίζει ένα βίντεο

$size  = filesize($path);
$start = 0;
$end   = $size - 1;
header_remove('Set-Cookie');
header('Content-Type: ' . $types[$ext]);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=86400');
header('Accept-Ranges: bytes');

if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {                     // «τα τελευταία Ν bytes»
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min($end, (int) $m[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

$file = fopen($path, 'rb');
fseek($file, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($file)) {
    $chunk = fread($file, min(65536, $left));
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($file);
