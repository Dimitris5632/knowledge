SET NAMES utf8mb4;

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS rights ENUM('own', 'notes', 'permission', 'public') NOT NULL DEFAULT 'own',
    ADD COLUMN IF NOT EXISTS source VARCHAR(255) NULL;

ALTER TABLE documents
    MODIFY rights ENUM('own', 'notes', 'permission', 'public') NOT NULL DEFAULT 'own';
