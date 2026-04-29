-- =====================================================
-- Raktárkészlet kezelő v2 – Adatbázis séma
-- Futtatás: USE raktar; SOURCE db/schema.sql;
-- =====================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Felhasználók
CREATE TABLE IF NOT EXISTS felhasznalok (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    felhasznalonev VARCHAR(50) UNIQUE NOT NULL,
    jelszo_hash   VARCHAR(255) NOT NULL,
    nev           VARCHAR(100) NOT NULL,
    szerepkor     ENUM('admin','user') NOT NULL DEFAULT 'user',
    aktiv         TINYINT(1) NOT NULL DEFAULT 1,
    letrehozva    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    utolso_belepes TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Szállítók (admin által kezelt lista)
CREATE TABLE IF NOT EXISTS szallitok (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    nev   VARCHAR(200) NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dropdown opciók (tipus, spec – admin által kezelt)
CREATE TABLE IF NOT EXISTS opcio_csoportok (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    kulcs   VARCHAR(50)  NOT NULL COMMENT 'tipus | spec',
    ertek   VARCHAR(200) NOT NULL,
    sorrend INT          NOT NULL DEFAULT 0,
    aktiv   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alkalmazás konfiguráció (kulcs-érték)
CREATE TABLE IF NOT EXISTS app_config (
    kulcs VARCHAR(80)  NOT NULL PRIMARY KEY,
    ertek TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Termék státuszok
CREATE TABLE IF NOT EXISTS statuszok (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nev       VARCHAR(100) NOT NULL,
    szin      VARCHAR(30)  NOT NULL DEFAULT 'gray',
    sorrend   INT          NOT NULL DEFAULT 0,
    torolheto TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Termékek
CREATE TABLE IF NOT EXISTS termekek (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    raktari_szam    VARCHAR(20) UNIQUE NOT NULL,
    datum           DATE         NULL,
    be_szamlaszam   VARCHAR(100) NULL COMMENT 'Bejövő számlaszám',
    szallito_id     INT          NULL,
    megnevezes      VARCHAR(300) NOT NULL,
    netto_ar        DECIMAL(14,2) NULL,
    tipus           VARCHAR(100) NULL,
    spec            VARCHAR(100) NULL,
    megjegyzes      TEXT         NULL,
    statusz_id      INT          NOT NULL DEFAULT 1,
    vevo            VARCHAR(200) NULL,
    eladas_datum    DATE         NULL,
    ki_szamlaszam   VARCHAR(100) NULL COMMENT 'Kimenő számlaszám',
    archivalható    TINYINT(1)   NOT NULL DEFAULT 0,
    ellenorzott     TINYINT(1)   NOT NULL DEFAULT 0,
    leltar          TINYINT(1)   NOT NULL DEFAULT 0,
    letrehozta      INT          NULL,
    letrehozva      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    modositotta     INT          NULL,
    modositva       DATETIME     NULL,
    FOREIGN KEY (szallito_id)  REFERENCES szallitok(id)    ON DELETE SET NULL,
    FOREIGN KEY (statusz_id)   REFERENCES statuszok(id),
    FOREIGN KEY (letrehozta)   REFERENCES felhasznalok(id) ON DELETE SET NULL,
    FOREIGN KEY (modositotta)  REFERENCES felhasznalok(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit napló
CREATE TABLE IF NOT EXISTS naplo (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    felhasznalo_id INT          NULL,
    muvelet        VARCHAR(100) NOT NULL,
    termek_id      INT          NULL,
    reszletek      TEXT         NULL,
    ip             VARCHAR(45)  NULL,
    datum          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (felhasznalo_id) REFERENCES felhasznalok(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- API tokenek (stateless Bearer-token autentikáció)
CREATE TABLE IF NOT EXISTS api_tokenek (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    felhasznalo_id   INT NOT NULL,
    token            VARCHAR(64)  NOT NULL COMMENT 'SHA-256 hash of the raw token',
    nev              VARCHAR(100) NOT NULL COMMENT 'Token description',
    letrehozva       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    utolso_hasznalat TIMESTAMP    NULL,
    aktiv            TINYINT(1)   NOT NULL DEFAULT 1,
    req_count        INT UNSIGNED NOT NULL DEFAULT 0,
    req_count_reset  TIMESTAMP    NULL,
    UNIQUE KEY uk_token (token),
    KEY idx_felhasznalo (felhasznalo_id),
    FOREIGN KEY (felhasznalo_id) REFERENCES felhasznalok(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Alap konfiguráció
INSERT IGNORE INTO app_config (kulcs, ertek) VALUES
('raktari_prefix', 'RAK'),
('app_nev', 'Raktárkészlet kezelő');

-- Alap dropdown opciók
INSERT IGNORE INTO opcio_csoportok (kulcs, ertek, sorrend) VALUES
('tipus', 'Laptop',      1),
('tipus', 'Asztali PC',  2),
('tipus', 'Monitor',     3),
('tipus', 'Nyomtató',    4),
('tipus', 'Szerver',     5),
('spec',  '8GB RAM',     1),
('spec',  '16GB RAM',    2),
('spec',  '32GB RAM',    3),
('spec',  'SSD 256GB',   4),
('spec',  'SSD 512GB',   5),
('spec',  'SSD 1TB',     6);

-- Alapértelmezett státuszok
INSERT IGNORE INTO statuszok (id, nev, szin, sorrend, torolheto) VALUES
(1, 'raktáron',   'green',  1, 0),
(2, 'privát',     'blue',   2, 1),
(3, 'kölcsön',    'orange', 3, 1),
(4, 'eladva',     'red',    4, 0),
(5, 'elveszett',  'gray',   5, 1),
(6, 'selejtezve', 'gray',   6, 1);
