-- =====================================================
-- Migráció: API tokenek tábla létrehozása
-- Futtatás: USE raktar; SOURCE db/migrate_api_tokenek.sql;
-- =====================================================

CREATE TABLE IF NOT EXISTS api_tokenek (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    felhasznalo_id   INT NOT NULL,
    token            VARCHAR(64)  NOT NULL COMMENT 'SHA-256 hash of the raw token',
    nev              VARCHAR(100) NOT NULL COMMENT 'Token description (e.g. Home Assistant)',
    letrehozva       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utolso_hasznalat TIMESTAMP    NULL,
    aktiv            TINYINT(1)   NOT NULL DEFAULT 1,
    req_count        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Request count in current window',
    req_count_reset  TIMESTAMP    NULL     COMMENT 'When the current rate-limit window expires',
    UNIQUE KEY uk_token (token),
    KEY idx_felhasznalo (felhasznalo_id),
    FOREIGN KEY (felhasznalo_id) REFERENCES felhasznalok(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
