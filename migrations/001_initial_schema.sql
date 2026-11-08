SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS knowledgebase
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE knowledgebase;

CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(30)  NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    role          ENUM('philosopher', 'historian') NOT NULL,
    position      VARCHAR(150),
    biography     TEXT,
    education     TEXT,
    works         TEXT,
    photo         VARCHAR(100) NULL,
    security_question    VARCHAR(150) NOT NULL DEFAULT '',
    security_answer_hash VARCHAR(255) NOT NULL DEFAULT '',
    terms_accepted_at    DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen     DATETIME NULL,
    is_admin       TINYINT(1)  NOT NULL DEFAULT 0,
    totp_secret    VARCHAR(64) NULL,
    totp_enabled   TINYINT(1)  NOT NULL DEFAULT 0,
    totp_last_step BIGINT      NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    session_version INT NOT NULL DEFAULT 0
);

CREATE TABLE philosophers (
    user_id        INT PRIMARY KEY,
    school         VARCHAR(150),
    branches       VARCHAR(255),
    influences     TEXT,
    main_questions TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE historians (
    user_id         INT PRIMARY KEY,
    period          VARCHAR(150),
    region          VARCHAR(150),
    methodology     VARCHAR(255),
    research_topics TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE documents (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    category      ENUM('philosophy', 'history', 'other') NOT NULL,
    title         VARCHAR(200) NOT NULL,
    author        VARCHAR(150),
    description   TEXT,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(100) NOT NULL UNIQUE,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT NOT NULL,
    rights        ENUM('own', 'notes', 'permission', 'public') NOT NULL DEFAULT 'own',
    source        VARCHAR(255) NULL,
    file_hash     CHAR(64) NULL,
    status        ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
    risk          ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'low',
    risk_reasons  TEXT NULL,
    author_death_year SMALLINT NULL,
    review_note   VARCHAR(255) NULL,
    reviewed_by   INT NULL,
    reviewed_at   DATETIME NULL,
    uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (status),
    INDEX (file_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE debates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status      ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE debate_posts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    debate_id   INT NOT NULL,
    user_id     INT NOT NULL,
    stance      ENUM('for', 'against', 'neutral') NOT NULL,
    body        TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (debate_id) REFERENCES debates(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
);

CREATE TABLE messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sender_id   INT  NOT NULL,
    receiver_id INT  NOT NULL,
    body        TEXT NOT NULL,
    sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE videos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    category    ENUM('philosophy', 'history', 'other') NOT NULL,
    title       VARCHAR(200) NOT NULL,
    creator     VARCHAR(150),
    year        SMALLINT NULL,
    description TEXT,
    source      ENUM('file', 'youtube') NOT NULL,
    stored_name VARCHAR(100) NULL,
    youtube_id  VARCHAR(11) NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(30) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    success      TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (ip_address, attempted_at),
    INDEX idx_login_user (username, attempted_at)
);

CREATE TABLE recovery_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    success      TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (ip_address, attempted_at)
);

CREATE TABLE contact_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    subject    VARCHAR(150) NOT NULL,
    message    TEXT NOT NULL,
    sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE chatbot_faq (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    keywords VARCHAR(255) NOT NULL,
    answer   TEXT NOT NULL
);

INSERT INTO chatbot_faq (keywords, answer) VALUES
('γεια,καλημερα,καλησπερα,hello,hi', 'Γεια σου! Είμαι ο βοηθός του Knowledge. Ρώτησέ με π.χ. πώς κάνω εγγραφή, πώς ανεβάζω έγγραφο ή τι είναι τα debates.'),
('εγγραφη,λογαριασμο,register', 'Πάτα «Έναρξη» στην αρχική, διάλεξε αν είσαι φιλόσοφος ή ιστορικός και μετά «Εγγραφή». Θα διαλέξεις και 3 ερωτήσεις ασφαλείας για την περίπτωση που ξεχάσεις τα στοιχεία σου.'),
('συνδεση,login,μπω', 'Πάτα «Έναρξη», διάλεξε τον ρόλο σου και γράψε username και κωδικό. Μετά από 5 λάθος προσπάθειες η σύνδεση κλειδώνει για 15 λεπτά.'),
('ξεχασα,κωδικο,password', 'Στη σελίδα σύνδεσης πάτα «Ξέχασα τον κωδικό». Γράφεις το username σου, τον κωδικό που σου στέλνουμε στο email, απαντάς σε 2 ερωτήσεις ασφαλείας και ορίζεις νέο κωδικό.'),
('username,ονομα χρηστη', 'Στη σελίδα σύνδεσης πάτα «Ξέχασα το username». Γράφεις το email σου, τον κωδικό που σου στέλνουμε και απαντάς σε 2 ερωτήσεις ασφαλείας.'),
('βιβλιοθηκη,εγγραφο,ανεβασω,pdf,αρχειο', 'Από το μενού πάτα «Βιβλιοθήκη» και μετά «Ανέβασε έγγραφο». Δεκτά είναι PDF, Word, ODT, EPUB, TXT και εικόνες JPG, PNG, WEBP, έως 10 MB. Μπορείς και να σκανάρεις με την κάμερα.'),
('debate,συζητηση,επιχειρημα', 'Στα «Debates» ανοίγεις ένα ερώτημα και τα μέλη γράφουν επιχειρήματα υπέρ, κατά ή ουδέτερα. Μόνο όποιος άνοιξε το debate μπορεί να το κλείσει.'),
('μηνυμα,στειλω,chat', 'Από τα «Μέλη» πάτα «Μήνυμα» δίπλα στο όνομα κάποιου. Τα μηνύματά σου τα βρίσκεις στα «Μηνύματα».'),
('βιογραφικο,προφιλ', 'Πάτα «Το προφίλ μου» και μετά «Επεξεργασία βιογραφικού» για να γράψεις την ιστορία σου, τις σπουδές και τα έργα σου.'),
('ενεργος,online', 'Η πράσινη τελεία δίπλα σε ένα μέλος σημαίνει ότι ήταν ενεργό τα τελευταία 5 λεπτά.'),
('επικοινωνια,contact', 'Μπορείς να μας γράψεις από τη σελίδα «Επικοινωνία», κάτω-κάτω σε κάθε σελίδα.'),
('απορρητο,δεδομενα,privacy', 'Δες την «Πολιτική απορρήτου» κάτω-κάτω σε κάθε σελίδα. Οι κωδικοί σου αποθηκεύονται κρυπτογραφημένοι (hash).'),
('ευχαριστω,thanks', 'Παρακαλώ! Ό,τι άλλο χρειαστείς, εδώ είμαι.'),
('ταινιοθηκη,ταινια,βιντεο,video,youtube', 'Από το μενού πάτα «Ταινιοθήκη» και μετά «Πρόσθεσε ταινία ή βίντεο». Μπορείς να ανεβάσεις αρχείο MP4, WEBM ή OGG έως 40 MB ή να βάλεις σύνδεσμο από το YouTube.');

CREATE TABLE IF NOT EXISTS security_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    username   VARCHAR(30) NOT NULL DEFAULT '',
    event      VARCHAR(40) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    details    VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, created_at),
    INDEX (event, created_at),
    INDEX (ip_address, created_at)
);

DELETE FROM chatbot_faq WHERE keywords IN ('2fa,δυο βηματων,authenticator', 'δραστηριοτητα,ιστορικο,συνδεσεις', 'ασφαλεια,ασφαλης,προστασια,hack');

INSERT INTO chatbot_faq (keywords, answer) VALUES
('2fa,δυο βηματων,authenticator', 'Πάτα το όνομά σου πάνω δεξιά, μετά «Ασφάλεια» και «Έλεγχος δύο βημάτων». Θα χρειαστείς την εφαρμογή Google Authenticator ή Microsoft Authenticator στο κινητό σου.'),
('δραστηριοτητα,ιστορικο,συνδεσεις', 'Πάτα το όνομά σου πάνω δεξιά, μετά «Ασφάλεια» και «Η δραστηριότητά μου» για να δεις πότε και από πού συνδέθηκες και αν κάποιος προσπάθησε να μπει στον λογαριασμό σου.'),
('ασφαλεια,ασφαλης,προστασια,hack', 'Πάτα το όνομά σου πάνω δεξιά και μετά «Ασφάλεια». Εκεί βλέπεις πόσο ασφαλής είναι ο λογαριασμός σου, τις τελευταίες συνδέσεις σου και βρίσκεις τον έλεγχο δύο βημάτων, την αλλαγή κωδικού και τη δραστηριότητά σου.');

CREATE TABLE IF NOT EXISTS quotes (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    text   VARCHAR(400) NOT NULL,
    author VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS post_likes (
    post_id    INT NOT NULL,
    user_id    INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, user_id),
    FOREIGN KEY (post_id) REFERENCES debate_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

DELETE FROM quotes;

INSERT INTO quotes (text, author) VALUES
('Ὁ ἀνεξέταστος βίος οὐ βιωτὸς ἀνθρώπῳ.', 'Σωκράτης (Πλάτων, Απολογία)'),
('Ἓν οἶδα ὅτι οὐδὲν οἶδα.', 'Σωκράτης'),
('Πάντες ἄνθρωποι τοῦ εἰδέναι ὀρέγονται φύσει.', 'Αριστοτέλης, Μετά τα Φυσικά'),
('Ἄνθρωπος φύσει πολιτικὸν ζῷον.', 'Αριστοτέλης, Πολιτικά'),
('Τὰ πάντα ῥεῖ.', 'Ηράκλειτος'),
('Πόλεμος πάντων μὲν πατήρ ἐστι.', 'Ηράκλειτος'),
('Πάντων χρημάτων μέτρον ἄνθρωπος.', 'Πρωταγόρας'),
('Ὁ θάνατος οὐδὲν πρὸς ἡμᾶς.', 'Επίκουρος'),
('Λάθε βιώσας.', 'Επίκουρος'),
('Δεν μας ταράζουν τα πράγματα, αλλά οι γνώμες μας για τα πράγματα.', 'Επίκτητος, Εγχειρίδιο'),
('Γνῶθι σαυτόν.', 'Δελφικό παράγγελμα'),
('Μέτρον ἄριστον.', 'Κλεόβουλος ο Λίνδιος'),
('Κτῆμα ἐς αἰεί.', 'Θουκυδίδης'),
('Ἡροδότου Ἁλικαρνησσέος ἱστορίης ἀπόδεξις ἥδε.', 'Ηρόδοτος, Ιστορίαι'),
('Η ιστορία είναι δασκάλα της ζωής.', 'Κικέρων'),
('Όποιος δεν θυμάται το παρελθόν είναι καταδικασμένος να το επαναλάβει.', 'Τζορτζ Σανταγιάνα'),
('Σκέφτομαι, άρα υπάρχω.', 'Ρενέ Ντεκάρτ'),
('Ο άνθρωπος είναι καταδικασμένος να είναι ελεύθερος.', 'Ζαν-Πολ Σαρτρ'),
('Τα όρια της γλώσσας μου σημαίνουν τα όρια του κόσμου μου.', 'Λούντβιχ Βιτγκενστάιν'),
('Η κουκουβάγια της Αθηνάς ανοίγει τα φτερά της μόνο όταν πέφτει το σούρουπο.', 'Χέγκελ');

DELETE FROM chatbot_faq WHERE keywords = 'συμφωνω,like,ψηφο,ρητο';

INSERT INTO chatbot_faq (keywords, answer) VALUES
('συμφωνω,like,ψηφο,ρητο', 'Στα debates πάτα «Συμφωνώ» κάτω από ένα επιχείρημα που σε έπεισε (όχι στα δικά σου). Στην αρχική βλέπεις κάθε μέρα ένα νέο ρητό φιλοσόφου ή ιστορικού.');

CREATE TABLE IF NOT EXISTS favorites (
    user_id    INT NOT NULL,
    item_type  ENUM('document', 'video') NOT NULL,
    item_id    INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_type, item_id),
    INDEX (item_type, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reviews (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    item_type  ENUM('document', 'video') NOT NULL,
    item_id    INT NOT NULL,
    rating     TINYINT NOT NULL,
    comment    TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, item_type, item_id),
    INDEX (item_type, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

DELETE FROM chatbot_faq WHERE keywords IN ('αναζητηση,ψαχνω,βρω', 'αγαπημενα,καρδια,favorite', 'κριτικη,αστερια,βαθμολογια', 'εκτυπωση,pdf,τυπωσω');

INSERT INTO chatbot_faq (keywords, answer) VALUES
('αναζητηση,ψαχνω,βρω', 'Γράψε αυτό που ψάχνεις στο κουτί «Αναζήτηση» πάνω στο μενού. Ψάχνει μαζί σε μέλη, έγγραφα, ταινίες και debates.'),
('αγαπημενα,καρδια,favorite', 'Πάτα την καρδούλα ♡ δίπλα σε ένα έγγραφο ή μια ταινία. Τα βρίσκεις όλα στο όνομά σου πάνω δεξιά → «Τα αγαπημένα μου».'),
('κριτικη,αστερια,βαθμολογια', 'Στη βιβλιοθήκη και την ταινιοθήκη πάτα «Κριτικές» κάτω από ένα έγγραφο ή μια ταινία, διάλεξε 1 έως 5 αστέρια και γράψε τη γνώμη σου.'),
('εκτυπωση,pdf,τυπωσω', 'Στο προφίλ πάτα «Εκτύπωση / PDF». Βγαίνει καθαρό βιογραφικό σε Α4· για PDF διάλεξε «Αποθήκευση ως PDF» στο παράθυρο εκτύπωσης.');

CREATE TABLE IF NOT EXISTS user_questions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    position    TINYINT NOT NULL,
    question    VARCHAR(150) NOT NULL,
    answer_hash VARCHAR(255) NOT NULL,
    UNIQUE (user_id, position),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    email       VARCHAR(255) NOT NULL,
    purpose     ENUM('register', 'change_email', 'reset', 'username') NOT NULL,
    code_hash   VARCHAR(255) NOT NULL,
    attempts    TINYINT NOT NULL DEFAULT 0,
    ip_address  VARCHAR(45) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (email, purpose),
    INDEX (ip_address, created_at)
);

CREATE TABLE IF NOT EXISTS device_pins (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    selector     CHAR(24) NOT NULL UNIQUE,
    token_hash   CHAR(64) NOT NULL,
    pin_hash     VARCHAR(255) NOT NULL,
    device_label VARCHAR(100) NOT NULL,
    attempts     TINYINT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS blocked_files (
    file_hash  CHAR(64) PRIMARY KEY,
    title      VARCHAR(200) NOT NULL,
    reason     VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS document_reports (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    reporter_id INT NULL,
    reason      ENUM('copyright', 'wrong_author', 'offensive', 'other') NOT NULL,
    details     TEXT NOT NULL,
    contact     VARCHAR(255) NULL,
    status      ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    ip_address  VARCHAR(45) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL
);
