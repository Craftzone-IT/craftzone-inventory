<?php
/**
 * Test data seeder — uploads 50 randomised products and can delete them again.
 *
 * This tool is intentionally standalone (no dependency on config/config.php or
 * the main auth system) so it can be used before or independently of the
 * application setup. It asks for database credentials on its own form and
 * stores the connection parameters in the session for the duration of the
 * browser visit.
 *
 * Test records are identified by two markers so they can be cleanly removed
 * without touching real data:
 *   - raktari_szam prefix:  TST-XXXX
 *   - megjegyzes field:     [TESZT_ADAT]
 *
 * DELETE THIS FILE before going to production.
 */
session_start();

// Handle disconnect first — before any HTML output — so the Location header
// can be sent without "headers already sent" errors.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
    unset($_SESSION['teszt_db']);
    header('Location: teszt.php');
    exit;
}

$pdo    = null;
$hibak  = [];
$uzenet = [];

/**
 * Creates a new PDO connection with exception-mode error handling.
 *
 * @param string $host MySQL host.
 * @param string $name Database name.
 * @param string $user MySQL username.
 * @param string $pass MySQL password.
 * @return PDO
 */
function connectDB(string $host, string $name, string $user, string $pass): PDO {
    return new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

// Save new credentials to session when the connection form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_host'])) {
    $_SESSION['teszt_db'] = [
        'host' => trim($_POST['db_host']),
        'name' => trim($_POST['db_name']),
        'user' => trim($_POST['db_user']),
        'pass' => $_POST['db_pass'],
    ];
}

// Restore connection from session (persists across page reloads within the visit).
if (isset($_SESSION['teszt_db'])) {
    try {
        $d   = $_SESSION['teszt_db'];
        $pdo = connectDB($d['host'], $d['name'], $d['user'], $d['pass']);
    } catch (PDOException $e) {
        // If the stored credentials no longer work (e.g. DB restarted), clear
        // them so the connection form is shown again.
        $hibak[] = 'Kapcsolati hiba: ' . $e->getMessage();
        unset($_SESSION['teszt_db']);
        $pdo = null;
    }
}

// ── Sample data pools for random product generation ──────────────────────────
$megnevezesek = [
    'Dell Latitude 5540 laptop', 'HP EliteBook 840 G10', 'Lenovo ThinkPad X1 Carbon',
    'Apple MacBook Pro 14"', 'ASUS VivoBook 15', 'Acer Aspire 5',
    'Samsung 27" monitor', 'LG 24" IPS monitor', 'Dell UltraSharp 32"',
    'HP LaserJet Pro M404dn', 'Canon PIXMA G3570', 'Epson EcoTank L3250',
    'Logitech MX Keys billentyűzet', 'Microsoft Wireless Desktop 900',
    'Logitech MX Master 3 egér', 'HP USB optikai egér',
    'Cisco SG350-28 switch', 'TP-Link TL-SG108 switch',
    'APC Back-UPS 650VA', 'Eaton 5E 650VA UPS',
    'Kingston 16GB DDR4 RAM', 'Corsair 32GB DDR5 RAM',
    'Samsung 970 EVO 1TB SSD', 'WD Blue 500GB SSD',
    'Seagate Barracuda 2TB HDD', 'Western Digital 4TB HDD',
    'Intel Core i7-13700 CPU', 'AMD Ryzen 9 7900X CPU',
    'NVIDIA RTX 4060 videókártya', 'AMD Radeon RX 7600',
    'ASUS ROG Strix B650-E alaplap', 'MSI PRO Z790-A WiFi',
    'Cooler Master MasterBox 500 ház', 'Fractal Design Define 7',
    'be quiet! Dark Power 13 750W tápegység', 'Corsair RM850x tápegység',
    'Noctua NH-D15 CPU hűtő', 'Arctic Liquid Freezer II 360',
    'TP-Link Archer AX73 WiFi router', 'ASUS RT-AX86U router',
    'Ubiquiti UniFi AP access point', 'Netgear WAX630 access point',
    'Raspberry Pi 4 Model B 8GB', 'NVIDIA Jetson Nano',
    'StarTech USB-C dokkoló', 'Anker PowerExpand 13-in-1',
    'AVerMedia Live Streamer CAM 513', 'Logitech Brio 4K webcam',
    'Jabra Evolve2 85 headset', 'Plantronics Voyager Focus 2',
];

// Empty string in the buyer pool means the product stays in stock (not sold).
$vevok = [
    'Kovács Péter', 'Nagy Mária', 'Szabó János', 'Tóth Anna',
    'Horváth Gábor', 'Varga Éva', 'Kiss László', 'Molnár Zsuzsanna',
    'Fekete Tamás', 'Balogh Katalin', 'Tech Solutions Kft.',
    'Digitál Bt.', 'Innováció Zrt.', 'SmartOffice Kft.', '',
];

// These two constants identify test records and are used both during insert
// and during the targeted delete operation.
$TESZT_PREFIX    = 'TST';
$TESZT_MEGJEGYZES = '[TESZT_ADAT]';

// ── Seed action: insert N random products ────────────────────────────────────
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'feltoltes') {

    // Read and clamp the requested count (1–500) from the form.
    $feltoltendoDb = max(1, min(500, (int)($_POST['feltoltes_db'] ?? 50)));

    // Find the highest existing TST- suffix so new numbers do not collide with
    // previously seeded (and not yet deleted) test records.
    $maxStmt = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(raktari_szam,'-',-1) AS UNSIGNED)) FROM termekek WHERE raktari_szam LIKE 'TST-%'");
    $maxNum  = (int)($maxStmt->fetchColumn() ?? 0);

    // Pull live reference data from the DB so seeded products use real IDs.
    $userIds   = $pdo->query("SELECT id FROM felhasznalok")->fetchAll(PDO::FETCH_COLUMN) ?: [null];
    $szallIds  = $pdo->query("SELECT id FROM szallitok WHERE aktiv=1")->fetchAll(PDO::FETCH_COLUMN) ?: [null];
    $tipusok   = $pdo->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='tipus' AND aktiv=1")->fetchAll(PDO::FETCH_COLUMN) ?: ['Laptop','Monitor'];
    $specek    = $pdo->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='spec'  AND aktiv=1")->fetchAll(PDO::FETCH_COLUMN) ?: ['16GB RAM'];

    $stmt = $pdo->prepare("
        INSERT INTO termekek
            (raktari_szam, datum, be_szamlaszam, szallito_id, megnevezes, netto_ar,
             tipus, spec, megjegyzes, vevo, eladas_datum, ki_szamlaszam,
             archivalható, ellenorzott, leltar, letrehozta)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $feltoltve = 0;
    $hibas     = 0;

    shuffle($megnevezesek);

    for ($i = 1; $i <= $feltoltendoDb; $i++) {
        $szam      = $TESZT_PREFIX . '-' . str_pad($maxNum + $i, 4, '0', STR_PAD_LEFT);
        $bevDatum  = date('Y-m-d', strtotime('-' . rand(1, 730) . ' days'));
        $beSzamla  = 'SZ-' . rand(2022, 2025) . '/' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $szallito  = $szallIds[array_rand($szallIds)];
        $nev       = $megnevezesek[($i - 1) % count($megnevezesek)];
        $ar        = rand(30, 800) * 1000 + rand(0, 9) * 100;
        $tipus     = $tipusok[array_rand($tipusok)];
        $spec      = $specek[array_rand($specek)];
        $vevo      = $vevok[array_rand($vevok)];
        $eladasDatum = null;
        $kiSzamla  = null;

        // If a buyer was selected, generate a sale date after the intake date.
        if ($vevo !== '') {
            $eladasDatum = date('Y-m-d', strtotime($bevDatum . ' +' . rand(7, 180) . ' days'));
            // Clamp future sale dates to today so the DB does not contain dates
            // in the future.
            if (strtotime($eladasDatum) > time()) { $eladasDatum = date('Y-m-d'); }
            $kiSzamla = 'KI-' . rand(2023, 2025) . '/' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        } else {
            $vevo = null;
        }

        $userId = $userIds[array_rand($userIds)];

        try {
            $stmt->execute([
                $szam, $bevDatum, $beSzamla, $szallito, $nev, $ar,
                $tipus, $spec, $TESZT_MEGJEGYZES, $vevo, $eladasDatum, $kiSzamla,
                rand(0,1), rand(0,1), rand(0,1), $userId
            ]);
            $uzenet[] = ['ok', "$szam – $nev ($ar Ft)"];
            $feltoltve++;
        } catch (PDOException $e) {
            $uzenet[] = ['err', "#$i hiba: " . $e->getMessage()];
            $hibas++;
        }
    }

    $uzenet[] = ['summary', "Feltöltve: $feltoltve db | Hibás: $hibas db"];
}

// ── Delete action: remove all test records ───────────────────────────────────
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'torles') {
    // Delete only rows that carry the [TESZT_ADAT] marker, never touching real data.
    $stmt   = $pdo->prepare("DELETE FROM termekek WHERE megjegyzes = ?");
    $stmt->execute([$TESZT_MEGJEGYZES]);
    $torolve = $stmt->rowCount();
    $uzenet[] = ['summary', "Törölve: $torolve teszt termék."];
}

// ── Stats: count test vs. total products in the connected database ────────────
$tesztDb  = 0;
$osszes   = 0;
if ($pdo) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM termekek WHERE megjegyzes = ?");
        $s->execute([$TESZT_MEGJEGYZES]);
        $tesztDb = (int)$s->fetchColumn();
        $osszes  = (int)$pdo->query("SELECT COUNT(*) FROM termekek")->fetchColumn();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Teszt feltöltő – Raktárkészlet</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; color: #1a202c; padding: 32px 16px; }
        .wrap { max-width: 720px; margin: 0 auto; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .subtitle { color: #718096; font-size: 13px; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 24px; margin-bottom: 20px; }
        .card h2 { font-size: 15px; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; }
        .form-group { margin-bottom: 14px; }
        label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; }
        input[type=text], input[type=password] {
            width: 100%; padding: 8px 11px; border: 1px solid #e2e8f0;
            border-radius: 7px; font-size: 13px; outline: none; font-family: inherit;
        }
        input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: background .15s; text-decoration: none; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger  { background: #ef4444; color: #fff; }
        .btn-danger:hover  { background: #dc2626; }
        .btn-secondary { background: #e2e8f0; color: #1a202c; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; text-align: center; }
        .stat-val { font-size: 28px; font-weight: 700; }
        .stat-lab { font-size: 12px; color: #718096; }
        .stat-val.blue { color: #3b82f6; }
        .stat-val.orange { color: #f97316; }
        .log { max-height: 340px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 7px; font-size: 12px; font-family: monospace; }
        .log-row { padding: 5px 12px; border-bottom: 1px solid #f1f5f9; display: flex; gap: 10px; align-items: baseline; }
        .log-row:last-child { border-bottom: none; }
        .log-ok   { background: #f0fdf4; }
        .log-err  { background: #fef2f2; color: #dc2626; }
        .log-sum  { background: #eff6ff; font-weight: 700; font-size: 13px; color: #1d4ed8; padding: 10px 12px; }
        .badge-ok  { background: #dcfce7; color: #15803d; padding: 1px 7px; border-radius: 99px; font-size: 10px; flex-shrink: 0; }
        .badge-err { background: #fee2e2; color: #dc2626; padding: 1px 7px; border-radius: 99px; font-size: 10px; flex-shrink: 0; }
        .warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 7px; padding: 10px 14px; font-size: 12px; margin-bottom: 16px; }
        .error-box { background: #fee2e2; border: 1px solid #fecaca; color: #dc2626; border-radius: 7px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; }
        .hint { font-size: 11px; color: #718096; margin-top: 4px; }
        .sep { border: none; border-top: 1px solid #e2e8f0; margin: 16px 0; }
        .connected-badge { display:inline-flex; align-items:center; gap:5px; background:#dcfce7; color:#15803d; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; margin-left:12px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>&#127381; Raktárkészlet – Teszt adatfeltöltő</h1>
    <p class="subtitle">
        Tetszőleges számú random termék feltöltése / törlése az adatbázisból.
        <strong>Csak fejlesztési célra!</strong>
    </p>

    <?php if (!empty($hibak)): ?>
    <div class="error-box"><?php foreach ($hibak as $h) echo htmlspecialchars($h) . '<br>'; ?></div>
    <?php endif; ?>

    <!-- Database connection card — shown always; displays connected status when
         session credentials are active. -->
    <div class="card">
        <h2>
            Adatbázis kapcsolat
            <?php if ($pdo): ?>
            <span class="connected-badge">&#10003; Kapcsolódva: <?= htmlspecialchars($_SESSION['teszt_db']['name']) ?></span>
            <?php endif; ?>
        </h2>

        <?php if (!$pdo): ?>
        <form method="post">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label>MySQL host</label>
                    <input type="text" name="db_host" value="localhost">
                </div>
                <div class="form-group">
                    <label>Adatbázis neve</label>
                    <input type="text" name="db_name" value="raktar" required>
                </div>
                <div class="form-group">
                    <label>Felhasználónév</label>
                    <input type="text" name="db_user" value="root">
                </div>
                <div class="form-group">
                    <label>Jelszó</label>
                    <input type="password" name="db_pass" placeholder="(üres = nincs jelszó)">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Kapcsolódás</button>
        </form>
        <?php else: ?>
        <hr class="sep">
        <form method="post" onsubmit="return confirm('Kapcsolat bontása?')">
            <input type="hidden" name="disconnect" value="1">
            <button type="submit" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">
                &#8592; Másik adatbázis
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($pdo): ?>

    <!-- Live product counts for the connected database. -->
    <div class="stats">
        <div class="stat">
            <div class="stat-val blue"><?= $osszes ?></div>
            <div class="stat-lab">Összes termék az adatbázisban</div>
        </div>
        <div class="stat">
            <div class="stat-val orange"><?= $tesztDb ?></div>
            <div class="stat-lab">Teszt termékek (TST- prefix)</div>
        </div>
    </div>

    <!-- Remind the user how test records are marked so they understand what
         the delete operation targets. -->
    <div class="warn">
        &#9888; A feltöltött teszt termékek a <code>megjegyzes</code> mezőben <code>[TESZT_ADAT]</code> jelölést kapnak,
        és a raktári számuk <code>TST-XXXX</code> formátumú. A törlés csak ezeket érinti.
    </div>

    <!-- Action buttons — each in its own form to avoid mixing POST values. -->
    <div class="card">
        <h2>Műveletek</h2>
        <div class="btn-row">
            <!-- Upload form: the number input controls how many products are generated.
                 Clamped to 1–500 both here (min/max) and server-side. -->
            <form method="post" onsubmit="return confirm('Feltöltesz ' + this.feltoltes_db.value + ' teszt terméket?')" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <input type="hidden" name="action" value="feltoltes">
                <label for="feltoltes_db" style="font-size:13px;font-weight:600;white-space:nowrap">Darabszám:</label>
                <input type="number" id="feltoltes_db" name="feltoltes_db"
                       value="50" min="1" max="500"
                       style="width:80px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none"
                       title="1–500 termék tölthető fel egyszerre">
                <button type="submit" class="btn btn-primary">&#128640; Teszt termékek feltöltése</button>
            </form>

            <form method="post" onsubmit="return confirm('Biztosan törlöd az összes teszt terméket ([TESZT_ADAT] megjegyzéssel)?')">
                <input type="hidden" name="action" value="torles">
                <button type="submit" class="btn btn-danger" <?= $tesztDb === 0 ? 'disabled title="Nincs teszt termék"' : '' ?>>
                    &#128465; Teszt termékek törlése (<?= $tesztDb ?> db)
                </button>
            </form>

            <a href="index.php" class="btn btn-secondary">&#8594; Főoldal</a>
        </div>
    </div>

    <!-- Operation result log — displayed after seed or delete actions. -->
    <?php if (!empty($uzenet)): ?>
    <div class="card">
        <h2>Eredmény</h2>
        <div class="log">
            <?php foreach ($uzenet as [$tipus, $szoveg]): ?>
            <?php if ($tipus === 'summary'): ?>
                <div class="log-row log-sum">&#10003; <?= htmlspecialchars($szoveg) ?></div>
            <?php elseif ($tipus === 'ok'): ?>
                <div class="log-row log-ok"><span class="badge-ok">OK</span> <?= htmlspecialchars($szoveg) ?></div>
            <?php else: ?>
                <div class="log-row log-err"><span class="badge-err">HIBA</span> <?= htmlspecialchars($szoveg) ?></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // $pdo — nothing shown below this until connected ?>

</div>

</body>
</html>
