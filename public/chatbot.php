<?php
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$message = normalize_answer(mb_substr($_POST['message'] ?? '', 0, 300));

// Ερωτήσεις στα αγγλικά: αντιστοιχίζουμε αγγλικές λέξεις στις ελληνικές λέξεις-κλειδιά
if (current_lang() === 'en') {
    $english = [
        'register' => 'εγγραφη', 'sign up' => 'εγγραφη', 'account' => 'λογαριασμο', 'login' => 'συνδεση', 'log in' => 'συνδεση',
        'password' => 'κωδικο', 'forgot' => 'ξεχασα', 'username' => 'username', 'library' => 'βιβλιοθηκη', 'upload' => 'ανεβασω',
        'document' => 'εγγραφο', 'file' => 'αρχειο', 'debate' => 'debate', 'message' => 'μηνυμα', 'cv' => 'βιογραφικο',
        'profile' => 'βιογραφικο', 'film' => 'ταινια', 'video' => 'βιντεο', 'contact' => 'επικοινωνια', 'privacy' => 'απορρητο',
        'thank' => 'ευχαριστω', 'hello' => 'γεια', 'hi' => 'γεια', 'security' => 'ασφαλεια', 'activity' => 'δραστηριοτητα',
        'two-step' => 'δυο βηματων', '2fa' => '2fa', 'search' => 'αναζητηση', 'favourite' => 'αγαπημενα', 'favorite' => 'favorite',
        'review' => 'κριτικη', 'stars' => 'αστερια', 'print' => 'εκτυπωση', 'pdf' => 'pdf', 'quote' => 'ρητο', 'agree' => 'συμφωνω',
        'active' => 'ενεργος', 'online' => 'ενεργος', 'scan' => 'ανεβασω',
    ];
    foreach ($english as $word => $greek) {
        if (mb_strpos($message, $word) !== false) {
            $message .= ' ' . $greek;
        }
    }
}

$best_answer = 'Δεν είμαι σίγουρος ότι κατάλαβα. Δοκίμασε να ρωτήσεις για: εγγραφή, σύνδεση, κωδικό, βιβλιοθήκη, debates, μηνύματα ή βιογραφικό.';
$best_score  = 0;

$faqs = $pdo->query('SELECT keywords, answer FROM chatbot_faq')->fetchAll();

foreach ($faqs as $faq) {
    $score = 0;
    foreach (explode(',', $faq['keywords']) as $keyword) {
        $keyword = normalize_answer($keyword);
        if ($keyword !== '' && mb_strpos($message, $keyword) !== false) {
            $score++;
        }
    }
    if ($score > $best_score) {
        $best_score  = $score;
        $best_answer = $faq['answer'];
    }
}

echo json_encode(['answer' => t($best_answer)]);
