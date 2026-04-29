<?php
/**
 * Login page — handles the authentication form and creates the user session.
 *
 * This page is intentionally standalone (no header.php / footer.php) so it
 * can be displayed before a session exists without any layout dependencies.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';

// Skip the login form entirely when a valid session already exists.
if (isLoggedIn()) { header('Location: termekek.php'); exit; }

$hiba = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fnev = trim($_POST['felhasznalonev'] ?? '');
    $pass = $_POST['jelszo'] ?? '';

    if ($fnev && $pass) {
        $db   = getDB();
        // Only match active accounts — deactivated users cannot log in even
        // with valid credentials.
        $stmt = $db->prepare("SELECT * FROM felhasznalok WHERE felhasznalonev = ? AND aktiv = 1");
        $stmt->execute([$fnev]);
        $user = $stmt->fetch();

        // password_verify() handles bcrypt/argon2 comparison safely; it is
        // timing-safe and automatically handles legacy hash upgrades.
        if ($user && password_verify($pass, $user['jelszo_hash'])) {
            // Store only the fields needed across the app — never store the
            // password hash in the session.
            $_SESSION['user'] = [
                'id'             => $user['id'],
                'felhasznalonev' => $user['felhasznalonev'],
                'nev'            => $user['nev'],
                'szerepkor'      => $user['szerepkor'],
            ];
            // Record the timestamp for the "last login" display in the user list.
            $db->prepare("UPDATE felhasznalok SET utolso_belepes = NOW() WHERE id = ?")->execute([$user['id']]);
            logActivity($db, 'belepes', 'Sikeres bejelentkezés.');

            $back = $_GET['back'] ?? $_POST['back'] ?? 'termekek.php';
            // Security: only allow relative URLs in the redirect target to
            // prevent open-redirect attacks. Any URL containing characters
            // outside this whitelist (e.g. "//evil.com") is discarded.
            if (!preg_match('/^[a-zA-Z0-9_.\/\-?&=]+$/', $back)) $back = 'termekek.php';
            header('Location: ' . $back);
            exit;
        } else {
            // Use the same generic error for both "wrong username" and "wrong
            // password" — a distinction would leak whether the username exists.
            $hiba = 'Hibás felhasználónév vagy jelszó.';
        }
    } else {
        $hiba = 'Kérjük, töltsd ki mindkét mezőt.';
    }
}

// Try to read the app name from DB so the login page title matches the
// configured value; fall back gracefully if the DB is not yet accessible.
$app_nev = 'Raktárkészlet kezelő';
if (file_exists('config/config.php')) {
    try { $app_nev = getConfig(getDB(), 'app_nev', $app_nev); } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Bejelentkezés – <?= htmlspecialchars($app_nev) ?></title>
    <script>
    /* Early theme init — same pattern as header.php. Runs before render to
       avoid a flash of the wrong colour scheme on page load. */
    (function(){
        var saved = localStorage.getItem('theme');
        var theme = saved || (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 16px; }
        .login-box { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.10); width: 100%; max-width: 380px; overflow: hidden; }
        .login-header { background: #1e293b; color: #fff; padding: 28px 32px 24px; text-align: center; }
        .login-header h1 { font-size: 18px; margin-top: 8px; }
        .brand-icon { font-size: 32px; display: block; margin-bottom: 4px; }
        .login-body { padding: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
        @media (max-width: 767px) {
            .login-header { padding: 22px 20px 18px; }
            .login-body   { padding: 18px; }
        }
        /* Dark mode overrides for this page are handled by the global
           html[data-theme="dark"] rules in style.css */
        .login-theme-btn {
            position: fixed;
            top: 14px;
            right: 16px;
            background: rgba(255,255,255,.12);
            border: none;
            border-radius: 6px;
            color: #cbd5e1;
            font-size: 18px;
            line-height: 1;
            padding: 5px 8px;
            cursor: pointer;
            transition: background .15s;
            z-index: 10;
        }
        .login-theme-btn:hover { background: rgba(255,255,255,.22); }
    </style>
</head>
<body>
<button class="login-theme-btn" id="themeToggle" onclick="toggleTheme()" title="Témaváltás">&#9788;</button>
<div class="login-box">
    <div class="login-header">
        <span class="brand-icon">&#9632;</span>
        <h1><?= htmlspecialchars($app_nev) ?></h1>
    </div>
    <div class="login-body">
        <?php if ($hiba): ?>
        <div class="flash flash-error"><?= htmlspecialchars($hiba) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['kijelentkezve'])): ?>
        <div class="flash flash-success">Sikeresen kijelentkeztél.</div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="back" value="<?= htmlspecialchars($_GET['back'] ?? 'index.php') ?>">
            <div class="form-group">
                <label>Felhasználónév</label>
                <input type="text" name="felhasznalonev" class="input" autofocus required
                    value="<?= htmlspecialchars($_POST['felhasznalonev'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Jelszó</label>
                <input type="password" name="jelszo" class="input" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:8px;">Bejelentkezés</button>
        </form>
    </div>
</div>
<script>
/* Theme toggle logic — mirrors the implementation in header.php so the login
   page has the same behaviour before the user is authenticated. */
function toggleTheme() {
    var html  = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    var next  = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    updateThemeBtn();
}
function updateThemeBtn() {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    btn.innerHTML = isDark ? '&#9728;' : '&#9790;';
    btn.title     = isDark ? 'Váltás világos módra' : 'Váltás sötét módra';
}
/* Keep in sync with OS preference changes when no manual override is stored. */
matchMedia('(prefers-color-scheme:dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
        updateThemeBtn();
    }
});
updateThemeBtn();
</script>
</body>
</html>
