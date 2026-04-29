<?php
/**
 * One-time migration script: adds the statuszok table and statusz_id column
 * to an existing installation.
 *
 * Safe to run multiple times — uses IF NOT EXISTS / IF NOT EXISTS checks.
 * After running, existing products with a vevo (buyer) are set to "eladva" (id=4),
 * all others default to "raktáron" (id=1).
 *
 * DELETE THIS FILE after successful migration.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';

// Admin-only access.
if (!isLoggedIn() || !isAdmin()) {
    die('Csak admin futtathatja.');
}

$db = getDB();

echo "<pre>\n";
echo "=== Státusz migráció ===\n\n";

// 1. Create statuszok table.
echo "1. statuszok tábla létrehozása...\n";
$db->exec("CREATE TABLE IF NOT EXISTS statuszok (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nev       VARCHAR(100) NOT NULL,
    szin      VARCHAR(30)  NOT NULL DEFAULT 'gray',
    sorrend   INT          NOT NULL DEFAULT 0,
    torolheto TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. Seed default statuses (IGNORE skips if already present).
echo "2. Alapértelmezett státuszok beszúrása...\n";
$db->exec("INSERT IGNORE INTO statuszok (id, nev, szin, sorrend, torolheto) VALUES
    (1, 'raktáron',   'green',  1, 0),
    (2, 'privát',     'blue',   2, 1),
    (3, 'kölcsön',    'orange', 3, 1),
    (4, 'eladva',     'red',    4, 0),
    (5, 'elveszett',  'gray',   5, 1),
    (6, 'selejtezve', 'gray',   6, 1)");

// 3. Add statusz_id column to termekek if missing.
$cols = $db->query("SHOW COLUMNS FROM termekek LIKE 'statusz_id'")->fetch();
if (!$cols) {
    echo "3. statusz_id oszlop hozzáadása a termekek táblához...\n";
    $db->exec("ALTER TABLE termekek ADD COLUMN statusz_id INT NOT NULL DEFAULT 1 AFTER spec");
    $db->exec("ALTER TABLE termekek ADD FOREIGN KEY (statusz_id) REFERENCES statuszok(id)");

    // 4. Migrate existing data: if vevo is filled → eladva (4), else raktáron (1).
    echo "4. Meglévő sorok migrálása...\n";
    $updated = $db->exec("UPDATE termekek SET statusz_id = 4 WHERE vevo IS NOT NULL AND vevo != ''");
    echo "   - $updated sor átállítva 'eladva' státuszra (volt vevője).\n";
    $remaining = $db->query("SELECT COUNT(*) FROM termekek WHERE statusz_id = 1")->fetchColumn();
    echo "   - $remaining sor maradt 'raktáron' státuszon.\n";
} else {
    echo "3. statusz_id oszlop már létezik — kihagyva.\n";
}

logActivity($db, 'migráció', 'Státusz rendszer migrálva');
echo "\n=== Migráció kész! ===\n";
echo "Töröld ezt a fájlt (migrate_statusz.php) a befejezés után.\n";
echo "</pre>";
