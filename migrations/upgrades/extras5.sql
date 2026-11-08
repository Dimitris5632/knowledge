-- Αναβάθμιση 5: αποσύνδεση όλων των συσκευών με αλλαγή κωδικού, κλείδωμα ανά username.
-- Τρέχει με ασφάλεια πάνω σε υπάρχουσα βάση (δεν σβήνει δεδομένα). Μπορεί να τρέξει και 2 φορές.
SET NAMES utf8mb4;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version');
SET @sql := IF(@has = 0, 'ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_user');
SET @sql := IF(@has = 0, 'CREATE INDEX idx_login_user ON login_attempts (username, attempted_at)', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
