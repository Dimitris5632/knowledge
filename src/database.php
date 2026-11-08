<?php
// Ώρα Ελλάδας (στο Azure ο server είναι σε ώρα UTC)
date_default_timezone_set('Europe/Athens');

// Στο XAMPP χρησιμοποιούνται οι τιμές μετά το ?: (localhost, root, χωρίς κωδικό).
// Στο Azure τις δίνουμε από τις «Μεταβλητές περιβάλλοντος» της εφαρμογής,
// ώστε ο κωδικός της βάσης να ΜΗΝ είναι γραμμένος μέσα στον κώδικα.
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'knowledgebase';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';

$db_options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Η βάση του Azure θέλει κρυπτογραφημένη σύνδεση (SSL)
if (getenv('DB_SSL') === '1') {
    $db_options[PDO::MYSQL_ATTR_SSL_CA] = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
}

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, $db_options);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $ex) {
    die('Σφάλμα σύνδεσης με τη βάση. Τρέχει η MySQL;');
}
