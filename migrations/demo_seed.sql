-- Demo data for a local / Docker install. Password of every demo account: Demo#Knowledge2026
-- DO NOT run on a real site.
SET NAMES utf8mb4;

INSERT INTO users (username, email, password_hash, full_name, role, position, biography, is_admin, email_verified_at, terms_accepted_at) VALUES
('demo_admin', 'admin@knowledge.example', '$2y$10$pxo67DDq3hOBz28lUU/8nu/DT0yt.0vAJSpXOzx3Qjdw2YKdvTuIG', 'Ελένη Παπαδοπούλου', 'historian', 'Ιστορικός, διαχειρίστρια', 'Ερευνώ τη βυζαντινή ιστορία και τη διάδοση της γνώσης.', 1, NOW(), NOW()),
('sokratis', 'sokratis@knowledge.example', '$2y$10$pxo67DDq3hOBz28lUU/8nu/DT0yt.0vAJSpXOzx3Qjdw2YKdvTuIG', 'Σωκράτης Αθηναίος', 'philosopher', 'Υποψήφιος διδάκτορας Φιλοσοφίας', 'Με απασχολεί η ηθική και η πολιτική φιλοσοφία.', 0, NOW(), NOW()),
('irodotos', 'irodotos@knowledge.example', '$2y$10$pxo67DDq3hOBz28lUU/8nu/DT0yt.0vAJSpXOzx3Qjdw2YKdvTuIG', 'Ηρόδοτος Αλικαρνασσέας', 'historian', 'Ιστορικός', 'Ταξιδεύω και καταγράφω.', 0, NOW(), NOW()),
('ypatia', 'ypatia@knowledge.example', '$2y$10$pxo67DDq3hOBz28lUU/8nu/DT0yt.0vAJSpXOzx3Qjdw2YKdvTuIG', 'Υπατία Αλεξανδρινή', 'philosopher', 'Φιλόσοφος και μαθηματικός', 'Νεοπλατωνισμός και αστρονομία.', 0, NOW(), NOW());

INSERT INTO philosophers (user_id, school, branches) SELECT id, 'Στωικισμός', 'Ηθική, Πολιτική φιλοσοφία' FROM users WHERE username = 'sokratis';
INSERT INTO philosophers (user_id, school, branches) SELECT id, 'Νεοπλατωνισμός', 'Μεταφυσική, Λογική' FROM users WHERE username = 'ypatia';
INSERT INTO historians (user_id) SELECT id FROM users WHERE username IN ('demo_admin', 'irodotos');

INSERT INTO debates (user_id, title, description, status) SELECT id, 'Είναι η ιστορία επιστήμη ή τέχνη;', 'Ο Θουκυδίδης έγραψε «κτῆμα ἐς αἰεί». Μπορεί η ιστορία να είναι αντικειμενική;', 'open' FROM users WHERE username = 'irodotos';
INSERT INTO debates (user_id, title, description, status) SELECT id, 'Μπορεί μια τεχνητή νοημοσύνη να είναι ηθικά υπεύθυνη;', 'Από τον Αριστοτέλη στον Καντ: τι σημαίνει ευθύνη;', 'open' FROM users WHERE username = 'sokratis';
INSERT INTO debate_posts (debate_id, user_id, stance, body)
SELECT d.id, u.id, 'for', 'Επιστήμη: έχει μέθοδο, πηγές και κριτική των τεκμηρίων.' FROM debates d, users u WHERE d.title LIKE 'Είναι η ιστορία%' AND u.username = 'demo_admin';
INSERT INTO debate_posts (debate_id, user_id, stance, body)
SELECT d.id, u.id, 'against', 'Τέχνη: κάθε αφήγηση είναι επιλογή και ερμηνεία.' FROM debates d, users u WHERE d.title LIKE 'Είναι η ιστορία%' AND u.username = 'ypatia';
