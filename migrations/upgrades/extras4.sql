-- Αναβάθμιση 4: 3 ερωτήσεις ασφαλείας, κωδικοί email, PIN, έλεγχος νομιμότητας
-- Τρέχει με ασφάλεια και πάνω σε υπάρχουσα βάση (δεν σβήνει δεδομένα).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_questions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    position    TINYINT NOT NULL,
    question    VARCHAR(150) NOT NULL,
    answer_hash VARCHAR(255) NOT NULL,
    UNIQUE (user_id, position),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Επιβεβαίωση email με 6ψήφιο κωδικό
-- (γραμμένο έτσι ώστε να δουλεύει και σε MySQL 8 του Azure και σε MariaDB του XAMPP)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at');
SET @sql := IF(@has = 0, 'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
-- Τα μέλη που υπήρχαν ήδη θεωρούνται επιβεβαιωμένα
UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

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

-- PIN γρήγορης σύνδεσης (ένα ανά συσκευή)
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

-- Έλεγχος νομιμότητας εγγράφων: κατάσταση, κίνδυνος, έγκριση από διαχειριστή
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'status');
SET @sql := IF(@has = 0, 'ALTER TABLE documents
    ADD COLUMN file_hash CHAR(64) NULL,
    ADD COLUMN status ENUM(''approved'', ''pending'', ''rejected'') NOT NULL DEFAULT ''approved'',
    ADD COLUMN risk ENUM(''low'', ''medium'', ''high'') NOT NULL DEFAULT ''low'',
    ADD COLUMN risk_reasons TEXT NULL,
    ADD COLUMN author_death_year SMALLINT NULL,
    ADD COLUMN review_note VARCHAR(255) NULL,
    ADD COLUMN reviewed_by INT NULL,
    ADD COLUMN reviewed_at DATETIME NULL,
    ADD INDEX (status), ADD INDEX (file_hash)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Αρχεία που αφαιρέθηκαν για παραβίαση: δεν μπορούν να ξανανέβουν
CREATE TABLE IF NOT EXISTS blocked_files (
    file_hash  CHAR(64) PRIMARY KEY,
    title      VARCHAR(200) NOT NULL,
    reason     VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Αναφορές παραβίασης από τα μέλη (notice and takedown)
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

-- Ενημερωμένες απαντήσεις του βοηθού (3 ερωτήσεις, κωδικός email, σκανάρισμα)
UPDATE chatbot_faq SET answer = 'Πάτα «Έναρξη» στην αρχική, διάλεξε αν είσαι φιλόσοφος ή ιστορικός και μετά «Εγγραφή». Θα διαλέξεις και 3 ερωτήσεις ασφαλείας για την περίπτωση που ξεχάσεις τα στοιχεία σου.' WHERE keywords = 'εγγραφη,λογαριασμο,register';
UPDATE chatbot_faq SET answer = 'Στη σελίδα σύνδεσης πάτα «Ξέχασα τον κωδικό». Γράφεις το username σου, τον κωδικό που σου στέλνουμε στο email, απαντάς σε 2 ερωτήσεις ασφαλείας και ορίζεις νέο κωδικό.' WHERE keywords = 'ξεχασα,κωδικο,password';
UPDATE chatbot_faq SET answer = 'Στη σελίδα σύνδεσης πάτα «Ξέχασα το username». Γράφεις το email σου, τον κωδικό που σου στέλνουμε και απαντάς σε 2 ερωτήσεις ασφαλείας.' WHERE keywords = 'username,ονομα χρηστη';
UPDATE chatbot_faq SET answer = 'Από το μενού πάτα «Βιβλιοθήκη» και μετά «Ανέβασε έγγραφο». Δεκτά είναι PDF, Word, ODT, EPUB, TXT και εικόνες JPG, PNG, WEBP, έως 10 MB. Μπορείς και να σκανάρεις με την κάμερα.' WHERE keywords = 'βιβλιοθηκη,εγγραφο,ανεβασω,pdf,αρχειο';
