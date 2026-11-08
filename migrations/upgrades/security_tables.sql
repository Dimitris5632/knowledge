ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS totp_last_step BIGINT NOT NULL DEFAULT 0;

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
