<?php
/**
 * Installation wizard — run once to set up the database and admin account.
 *
 * The wizard has three steps:
 *   Step 1 — Database credentials: tests the connection, creates the database
 *            if it does not exist, and creates all required tables.
 *   Step 2 — Admin account & app settings: creates the first admin user,
 *            seeds the app_config table, and writes config/config.php.
 *   Step 3 — Success screen with a reminder to delete this file.
 *
 * Database credentials are passed between steps via the PHP session (never in
 * a hidden form field) to avoid them appearing in page source.
 *
 * IMPORTANT: Delete or rename this file after installation is complete.
 * Leaving it accessible lets anyone reset the application.
 */

// Redirect to the main app if installation has already been completed.
if (file_exists(__DIR__ . '/config/config.php')) {
    header('Location: index.php');
    exit;
}

session_start();

// ── AJAX: test DB connection only (no table creation, no step advance) ────────
// Called by the "Kapcsolat tesztelése" button via fetch(). Returns JSON so the
// page can give instant feedback without a full form submission.
if (($_POST['action'] ?? '') === 'test_connection') {
    header('Content-Type: application/json');
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    if (!$name) {
        echo json_encode(['ok' => false, 'msg' => 'Az adatbázis neve kötelező.']);
        exit;
    }
    try {
        // Connect without selecting a database — we only verify that the
        // server is reachable and the credentials are accepted.
        $pdo = new PDO(
            "mysql:host=$host;charset=utf8mb4",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Fetch the server version so we can confirm the connection is live.
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo json_encode(['ok' => true, 'msg' => "Sikeres kapcsolat! MySQL szerver: $ver"]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Kapcsolat sikertelen: ' . $e->getMessage()]);
    }
    exit;
}
$step   = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$hibak  = [];
$siker  = '';

// ── Step 1 POST handler: test DB connection and create tables ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    if (!$name) $hibak[] = 'Az adatbázis neve kötelező.';

    if (empty($hibak)) {
        try {
            // Connect without specifying a database so we can create it if needed.
            $pdo = new PDO(
                "mysql:host=$host;charset=utf8mb4",
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            // CREATE DATABASE IF NOT EXISTS is safe to run even when the database
            // already exists — it becomes a no-op.
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci");
            $pdo->exec("USE `$name`");

            // Tables are defined as individual statements so PDO can execute them
            // one by one. PDO cannot process DELIMITER-based blocks (used in
            // standard MySQL dump files), so triggers are omitted here.
            $tables = [
                // felhasznalok: user accounts with bcrypt password hashes.
                "CREATE TABLE IF NOT EXISTS felhasznalok (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    felhasznalonev VARCHAR(50) UNIQUE NOT NULL,
                    jelszo_hash    VARCHAR(255) NOT NULL,
                    nev            VARCHAR(100) NOT NULL,
                    szerepkor      ENUM('admin','user') NOT NULL DEFAULT 'user',
                    aktiv          TINYINT(1) NOT NULL DEFAULT 1,
                    letrehozva     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    utolso_belepes TIMESTAMP NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // szallitok: supplier reference list.
                "CREATE TABLE IF NOT EXISTS szallitok (
                    id    INT AUTO_INCREMENT PRIMARY KEY,
                    nev   VARCHAR(200) NOT NULL,
                    aktiv TINYINT(1) NOT NULL DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // opcio_csoportok: admin-managed dropdown options for Típus and Spec.
                // The kulcs column acts as the group name ('tipus' or 'spec').
                "CREATE TABLE IF NOT EXISTS opcio_csoportok (
                    id      INT AUTO_INCREMENT PRIMARY KEY,
                    kulcs   VARCHAR(50)  NOT NULL,
                    ertek   VARCHAR(200) NOT NULL,
                    sorrend INT          NOT NULL DEFAULT 0,
                    aktiv   TINYINT(1)   NOT NULL DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // app_config: key-value store for runtime settings (e.g. app_nev,
                // raktari_prefix). Read by getConfig() on every page.
                "CREATE TABLE IF NOT EXISTS app_config (
                    kulcs VARCHAR(80) NOT NULL PRIMARY KEY,
                    ertek TEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // statuszok: product status reference table (raktáron, eladva, etc.).
                "CREATE TABLE IF NOT EXISTS statuszok (
                    id      INT AUTO_INCREMENT PRIMARY KEY,
                    nev     VARCHAR(100) NOT NULL,
                    szin    VARCHAR(30)  NOT NULL DEFAULT 'gray',
                    sorrend INT          NOT NULL DEFAULT 0,
                    torolheto TINYINT(1) NOT NULL DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Seed default status values. raktáron and eladva are protected
                // from deletion (torolheto=0) because the system relies on them.
                "INSERT IGNORE INTO statuszok (id, nev, szin, sorrend, torolheto) VALUES
                    (1, 'raktáron',   'green',  1, 0),
                    (2, 'privát',     'blue',   2, 1),
                    (3, 'kölcsön',    'orange',  3, 1),
                    (4, 'eladva',     'red',    4, 0),
                    (5, 'elveszett',  'gray',   5, 1),
                    (6, 'selejtezve', 'gray',   6, 1)",

                // termekek: the main product inventory table. Each row represents
                // one unique physical item identified by its raktari_szam.
                "CREATE TABLE IF NOT EXISTS termekek (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    raktari_szam   VARCHAR(20) UNIQUE NOT NULL,
                    datum          DATE         NULL,
                    be_szamlaszam  VARCHAR(100) NULL,
                    szallito_id    INT          NULL,
                    megnevezes     VARCHAR(300) NOT NULL,
                    netto_ar       DECIMAL(14,2) NULL,
                    tipus          VARCHAR(100) NULL,
                    spec           VARCHAR(100) NULL,
                    megjegyzes     TEXT         NULL,
                    statusz_id     INT          NOT NULL DEFAULT 1,
                    vevo           VARCHAR(200) NULL,
                    eladas_datum   DATE         NULL,
                    ki_szamlaszam  VARCHAR(100) NULL,
                    archivalható   TINYINT(1)   NOT NULL DEFAULT 0,
                    ellenorzott    TINYINT(1)   NOT NULL DEFAULT 0,
                    leltar         TINYINT(1)   NOT NULL DEFAULT 0,
                    letrehozta     INT          NULL,
                    letrehozva     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                    modositotta    INT          NULL,
                    modositva      DATETIME     NULL,
                    FOREIGN KEY (statusz_id)  REFERENCES statuszok(id),
                    FOREIGN KEY (szallito_id) REFERENCES szallitok(id) ON DELETE SET NULL,
                    FOREIGN KEY (letrehozta)  REFERENCES felhasznalok(id) ON DELETE SET NULL,
                    FOREIGN KEY (modositotta) REFERENCES felhasznalok(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // naplo: immutable audit log. ON DELETE SET NULL on felhasznalo_id
                // keeps log entries even after the user account is removed.
                "CREATE TABLE IF NOT EXISTS naplo (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    felhasznalo_id INT          NULL,
                    muvelet        VARCHAR(100) NOT NULL,
                    termek_id      INT          NULL,
                    reszletek      TEXT         NULL,
                    ip             VARCHAR(45)  NULL,
                    datum          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (felhasznalo_id) REFERENCES felhasznalok(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // api_tokenek: stateless Bearer-token authentication for the REST API.
                // Stores SHA-256 hashes of raw tokens (the raw token is shown once
                // at generation time and never stored). Rate-limit counters are
                // per-token with a 60-second sliding window.
                "CREATE TABLE IF NOT EXISTS api_tokenek (
                    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    felhasznalo_id   INT          NOT NULL,
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                // Seed default config values (INSERT IGNORE skips if already present).
                "INSERT IGNORE INTO app_config (kulcs, ertek) VALUES ('raktari_prefix','RAK'),('app_nev','Raktárkészlet kezelő')",

                // Seed a basic set of Típus and Spec. dropdown options.
                "INSERT IGNORE INTO opcio_csoportok (kulcs, ertek, sorrend) VALUES
                    ('tipus','Laptop',1),('tipus','Asztali PC',2),('tipus','Monitor',3),
                    ('tipus','Nyomtató',4),('tipus','Szerver',5),
                    ('spec','8GB RAM',1),('spec','16GB RAM',2),('spec','32GB RAM',3),
                    ('spec','SSD 256GB',4),('spec','SSD 512GB',5),('spec','SSD 1TB',6)",
            ];

            foreach ($tables as $sql) {
                $pdo->exec($sql);
            }

            // Store validated credentials in the session so Step 2 can reuse them
            // without embedding them in a hidden form field.
            $_SESSION['install_db'] = compact('host', 'name', 'user', 'pass');
            $step = 2;
        } catch (PDOException $e) {
            $hibak[] = 'Adatbázis kapcsolati hiba: ' . $e->getMessage();
        }
    }
}

// ── Step 2 POST handler: create admin account and write config file ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $db_data = $_SESSION['install_db'] ?? null;
    // If the session expired between steps, restart from Step 1.
    if (!$db_data) { $step = 1; goto render; }

    $admin_user  = trim($_POST['admin_user'] ?? '');
    $admin_nev   = trim($_POST['admin_nev'] ?? '');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    $app_nev     = trim($_POST['app_nev'] ?? 'Raktárkészlet kezelő');
    $prefix      = strtoupper(trim($_POST['raktari_prefix'] ?? 'RAK'));

    if (!$admin_user)                                    $hibak[] = 'A felhasználónév kötelező.';
    if (!$admin_nev)                                     $hibak[] = 'A teljes név kötelező.';
    if (strlen($admin_pass) < 6)                         $hibak[] = 'A jelszó legalább 6 karakter legyen.';
    if ($admin_pass !== $admin_pass2)                    $hibak[] = 'A két jelszó nem egyezik.';
    if (!preg_match('/^[A-Z0-9\-]{2,8}$/', $prefix))    $hibak[] = 'A raktári szám prefix csak A-Z, 0-9 és - karaktereket tartalmazhat (2-8 karakter).';

    if (empty($hibak)) {
        try {
            $pdo = new PDO(
                "mysql:host={$db_data['host']};dbname={$db_data['name']};charset=utf8mb4",
                $db_data['user'], $db_data['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            // Hash the password with bcrypt before storing — never store plaintext.
            $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO felhasznalok (felhasznalonev, jelszo_hash, nev, szerepkor) VALUES (?,?,?,'admin')")
                ->execute([$admin_user, $hash, $admin_nev]);
            $admin_id = $pdo->lastInsertId();

            // Upsert config rows in case Step 1 already inserted them with defaults.
            $pdo->prepare("INSERT INTO app_config (kulcs, ertek) VALUES ('app_nev',?) ON DUPLICATE KEY UPDATE ertek=?")->execute([$app_nev, $app_nev]);
            $pdo->prepare("INSERT INTO app_config (kulcs, ertek) VALUES ('raktari_prefix',?) ON DUPLICATE KEY UPDATE ertek=?")->execute([$prefix, $prefix]);

            // Write the installation event to the audit log.
            $pdo->prepare("INSERT INTO naplo (felhasznalo_id, muvelet, reszletek, ip) VALUES (?,?,?,?)")
                ->execute([$admin_id, 'telepites', 'Rendszer telepítve, admin fiók létrehozva.', $_SERVER['REMOTE_ADDR'] ?? '']);

            // Write config/config.php using var_export() so all values are
            // safely quoted as PHP string literals regardless of their content.
            $config_content = "<?php\n"
                . "// Automatikusan generálva: " . date('Y-m-d H:i:s') . "\n"
                . "define('DB_HOST',    " . var_export($db_data['host'], true) . ");\n"
                . "define('DB_NAME',    " . var_export($db_data['name'], true) . ");\n"
                . "define('DB_USER',    " . var_export($db_data['user'], true) . ");\n"
                . "define('DB_PASS',    " . var_export($db_data['pass'], true) . ");\n"
                . "define('DB_CHARSET', 'utf8mb4');\n"
                . "define('APP_INSTALLED', true);\n";

            if (!is_dir(__DIR__ . '/config')) mkdir(__DIR__ . '/config', 0755, true);
            file_put_contents(__DIR__ . '/config/config.php', $config_content);

            // Clear session credentials — no longer needed after writing the file.
            unset($_SESSION['install_db']);
            $step = 3;
        } catch (PDOException $e) {
            $hibak[] = 'Hiba az admin fiók létrehozásakor: ' . $e->getMessage();
        }
    }

    if (!empty($hibak)) $step = 2;
}

render:
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Telepítő – Raktárkészlet kezelő</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; color: #1a202c; display: flex; align-items: flex-start; justify-content: center; min-height: 100vh; padding: 40px 16px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.10); width: 100%; max-width: 520px; overflow: hidden; }
        .card-header { background: #1e293b; color: #fff; padding: 24px 32px; }
        .card-header h1 { font-size: 20px; }
        .card-header p  { color: #94a3b8; font-size: 13px; margin-top: 4px; }
        .steps { display: flex; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .step-item { flex: 1; text-align: center; padding: 12px 8px; font-size: 12px; color: #94a3b8; border-right: 1px solid #e2e8f0; }
        .step-item:last-child { border-right: none; }
        .step-item.active { color: #3b82f6; font-weight: 600; background: #eff6ff; }
        .step-item.done   { color: #22c55e; }
        .card-body { padding: 28px 32px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
        input[type=text], input[type=password], input[type=email] {
            width: 100%; padding: 9px 12px; border: 1px solid #e2e8f0; border-radius: 7px;
            font-size: 14px; outline: none; transition: border-color .15s; font-family: inherit;
        }
        input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
        .hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .btn { display: inline-block; padding: 10px 24px; background: #3b82f6; color: #fff; border: none; border-radius: 7px; font-size: 14px; font-weight: 600; cursor: pointer; width: 100%; transition: background .15s; }
        .btn:hover { background: #2563eb; }
        .errors { background: #fee2e2; border: 1px solid #fecaca; color: #dc2626; border-radius: 7px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; }
        .errors div + div { margin-top: 4px; }
        .success-icon { font-size: 48px; text-align: center; margin-bottom: 16px; }
        .success-title { font-size: 20px; font-weight: 700; text-align: center; color: #15803d; margin-bottom: 8px; }
        .success-text { text-align: center; color: #6b7280; font-size: 13px; margin-bottom: 24px; }
        .warning-box { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 7px; padding: 12px 16px; font-size: 12px; margin-bottom: 20px; }
        h2 { font-size: 16px; margin-bottom: 16px; color: #374151; }
        hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
        /* Test-connection button — secondary style, sits above the main submit */
        .btn-test { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s; font-family: inherit; margin-bottom: 10px; }
        .btn-test:hover { background: #e2e8f0; }
        .btn-test:disabled { opacity: .6; cursor: default; }
        /* Inline result badge shown after the test */
        #conn-result { font-size: 12px; font-weight: 600; padding: 8px 12px; border-radius: 6px; margin-bottom: 14px; display: none; }
        #conn-result.ok    { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        #conn-result.error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h1>&#9632; Raktárkészlet kezelő</h1>
        <p>Telepítő varázsló</p>
    </div>
    <!-- Step indicator — completed steps show green, the active step shows blue. -->
    <div class="steps">
        <div class="step-item <?= $step == 1 ? 'active' : ($step > 1 ? 'done' : '') ?>">1. Adatbázis</div>
        <div class="step-item <?= $step == 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">2. Admin fiók</div>
        <div class="step-item <?= $step == 3 ? 'active' : '' ?>">3. Kész</div>
    </div>
    <div class="card-body">

    <?php if (!empty($hibak)): ?>
    <div class="errors">
        <?php foreach ($hibak as $h): ?><div>&#9888; <?= htmlspecialchars($h) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <!-- Step 1: collect and validate database credentials. -->
        <h2>Adatbázis beállítások</h2>
        <form method="post">
            <input type="hidden" name="step" value="1">
            <div class="form-group">
                <label>MySQL szerver (host)</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
            </div>
            <div class="form-group">
                <label>Adatbázis neve *</label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'raktar') ?>" required>
                <div class="hint">Ha nem létezik, automatikusan létrehozzuk.</div>
            </div>
            <div class="form-group">
                <label>MySQL felhasználónév</label>
                <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>">
            </div>
            <div class="form-group">
                <label>MySQL jelszó</label>
                <input type="password" name="db_pass" value="">
                <div class="hint">Hagyd üresen, ha nincs jelszó beállítva (pl. XAMPP alapértelmezett).</div>
            </div>
            <!-- Test-connection button: sends an AJAX request without submitting
                 the form, then shows the server response inline. -->
            <button type="button" class="btn-test" id="btn-test-conn">
                &#128268; Kapcsolat tesztelése
            </button>
            <div id="conn-result"></div>
            <button type="submit" class="btn">Táblák létrehozása &amp; tovább &rarr;</button>
        </form>
        <script>
        document.getElementById('btn-test-conn').addEventListener('click', function () {
            const btn = this;
            const result = document.getElementById('conn-result');
            const form = btn.closest('form');

            // Collect the four DB credential fields from the form.
            const body = new FormData();
            body.append('action',  'test_connection');
            body.append('db_host', form.db_host.value);
            body.append('db_name', form.db_name.value);
            body.append('db_user', form.db_user.value);
            body.append('db_pass', form.db_pass.value);

            btn.disabled = true;
            btn.textContent = 'Tesztelés…';
            result.style.display = 'none';

            fetch('install.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    result.textContent = (data.ok ? '✓ ' : '✗ ') + data.msg;
                    result.className   = data.ok ? 'ok' : 'error';
                    result.style.display = 'block';
                })
                .catch(() => {
                    result.textContent = '✗ Váratlan hiba történt a tesztelés során.';
                    result.className   = 'error';
                    result.style.display = 'block';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '&#128268; Kapcsolat tesztelése';
                });
        });
        </script>

    <?php elseif ($step === 2): ?>
        <!-- Step 2: admin account details and initial app configuration. -->
        <h2>Admin fiók &amp; alapbeállítások</h2>
        <form method="post">
            <input type="hidden" name="step" value="2">
            <div class="form-group">
                <label>Alkalmazás neve</label>
                <input type="text" name="app_nev" value="<?= htmlspecialchars($_POST['app_nev'] ?? 'Raktárkészlet kezelő') ?>">
            </div>
            <div class="form-group">
                <label>Raktári szám prefix</label>
                <input type="text" name="raktari_prefix" value="<?= htmlspecialchars($_POST['raktari_prefix'] ?? 'RAK') ?>" maxlength="8" style="text-transform:uppercase">
                <div class="hint">Pl. "RAK" → RAK-0001, RAK-0002 ... (A-Z, 0-9, -, max 8 karakter)</div>
            </div>
            <hr>
            <div class="form-group">
                <label>Admin felhasználónév *</label>
                <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required>
            </div>
            <div class="form-group">
                <label>Admin teljes neve *</label>
                <input type="text" name="admin_nev" value="<?= htmlspecialchars($_POST['admin_nev'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Admin jelszó * (min. 6 karakter)</label>
                <input type="password" name="admin_pass" required>
            </div>
            <div class="form-group">
                <label>Jelszó megerősítése *</label>
                <input type="password" name="admin_pass2" required>
            </div>
            <button type="submit" class="btn">Fiók létrehozása &amp; telepítés befejezése &rarr;</button>
        </form>

    <?php elseif ($step === 3): ?>
        <!-- Step 3: success — remind the user to remove install.php. -->
        <div class="success-icon">&#10003;</div>
        <div class="success-title">Sikeres telepítés!</div>
        <div class="success-text">Az adatbázis és az admin fiók sikeresen létrejött.</div>
        <div class="warning-box">
            <strong>&#9888; Biztonsági figyelmeztetés:</strong><br>
            Töröld vagy nevezd át az <code>install.php</code> fájlt, hogy senki más ne futtathassa a telepítőt!
        </div>
        <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none;">Belépés az alkalmazásba &rarr;</a>
    <?php endif; ?>

    </div>
</div>
</body>
</html>
