SET NAMES utf8mb4;

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
