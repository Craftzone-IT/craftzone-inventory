<?php
/**
 * Shared page header: HTML <head>, navigation bar, and inline JavaScript.
 *
 * Included by every authenticated page AFTER requireLogin() / requireAdmin()
 * is called, so $user is always populated. Pages may set $page_title before
 * including this file to override the browser tab title. Pages may also set
 * $base_path (e.g. '../') when included from a sub-directory.
 */

// Determine the active page filename so the correct nav link can be highlighted.
$current_page = basename($_SERVER['PHP_SELF']);
$user = currentUser();
// Read the application name from the database; fall back to a hard-coded
// default in case the config row is missing.
$app_nev = getConfig(getDB(), 'app_nev', 'Raktárkészlet kezelő');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? $app_nev) ?></title>
    <script>
    /* Inline theme initialiser — must run before <body> renders to prevent a
       flash of unstyled light content when the user has selected dark mode.
       Reads from localStorage first; falls back to the OS preference. */
    (function(){
        var saved = localStorage.getItem('theme');
        var theme = saved || (matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <link rel="stylesheet" href="<?= $base_path ?? '' ?>assets/css/style.css">
</head>
<body>
<nav class="navbar">
    <div class="navbar-brand">
        <span class="brand-icon">&#9632;</span>
        <span class="brand-text"><?= htmlspecialchars($app_nev) ?></span>
    </div>

    <!-- Hamburger button — hidden on desktop, shown on mobile via CSS -->
    <button class="navbar-hamburger" id="navHamburger" aria-label="Menü megnyitása">
        <span></span><span></span><span></span>
    </button>

    <ul class="nav-links" id="navLinks">
        <li><a href="<?= $base_path ?? '' ?>termekek.php"
            class="<?= in_array($current_page, ['termekek.php','termek_form.php']) ? 'active' : '' ?>">&#9723; Termékek</a></li>
        <li><a href="<?= $base_path ?? '' ?>kereses.php"
            class="<?= $current_page === 'kereses.php' ? 'active' : '' ?>">&#128269; Keresés</a></li>
        <li><a href="<?= $base_path ?? '' ?>naplo.php"
            class="<?= $current_page === 'naplo.php' ? 'active' : '' ?>">&#128203; Napló</a></li>
        <?php if (isAdmin()): ?>
        <!-- Admin-only navigation items — hidden from regular users -->
        <li><a href="<?= $base_path ?? '' ?>felhasznalok.php"
            class="<?= $current_page === 'felhasznalok.php' ? 'active' : '' ?>">&#128100; Felhasználók</a></li>
        <li><a href="<?= $base_path ?? '' ?>beallitasok.php"
            class="<?= $current_page === 'beallitasok.php' ? 'active' : '' ?>">&#9881; Beállítások</a></li>
        <?php endif; ?>
        <!-- On mobile the profile and logout links are placed inside the
             dropdown menu because the desktop user-info bar is hidden. -->
        <li class="nav-mobile-only">
            <a href="<?= $base_path ?? '' ?>profil.php">&#9881; Profil</a>
        </li>
        <li class="nav-mobile-only">
            <a href="<?= $base_path ?? '' ?>logout.php" class="nav-logout">&#10005; Kijelentkezés</a>
        </li>
    </ul>

    <button class="theme-toggle" id="themeToggle" title="Témaváltás" onclick="toggleTheme()">&#9788;</button>

    <!-- Desktop user info bar: shows the logged-in user's name, role badge,
         a profile link, and a logout link. Hidden on mobile — those actions
         appear in the hamburger menu instead. -->
    <div class="navbar-user">
        <span class="user-name">
            <?php if (isAdmin()): ?><span class="role-badge">Admin</span><?php endif; ?>
            <?= htmlspecialchars($user['nev']) ?>
        </span>
        <a href="<?= $base_path ?? '' ?>profil.php" class="btn-user-action" title="Profil / jelszó">&#9881;</a>
        <a href="<?= $base_path ?? '' ?>logout.php" class="btn-user-action btn-logout" title="Kijelentkezés">&#10005; Ki</a>
    </div>
</nav>

<?php if (isset($_GET['hiba']) && $_GET['hiba'] === 'jogosultsag'): ?>
<!-- Shown when requireAdmin() redirects back with ?hiba=jogosultsag -->
<div style="background:#fee2e2;color:#dc2626;padding:10px 24px;font-size:13px;">
    &#9888; Nincs jogosultságod ehhez a művelethez.
</div>
<?php endif; ?>

<main class="container">

<script>
/* ── Mobile hamburger menu ─────────────────────────────────────────────── */
(function() {
    var btn   = document.getElementById('navHamburger');
    var links = document.getElementById('navLinks');
    if (!btn) return;

    // Toggle the dropdown open/closed and update the aria-label for screen readers.
    btn.addEventListener('click', function () {
        var open = links.classList.toggle('nav-open');
        btn.classList.toggle('is-open', open);
        btn.setAttribute('aria-label', open ? 'Menü bezárása' : 'Menü megnyitása');
    });

    // Close the dropdown when the user clicks anywhere outside the nav.
    document.addEventListener('click', function(e) {
        if (!btn.contains(e.target) && !links.contains(e.target)) {
            links.classList.remove('nav-open');
            btn.classList.remove('is-open');
        }
    });
})();

/* ── Dark / light theme toggle ─────────────────────────────────────────── */
function toggleTheme() {
    var html  = document.documentElement;
    var isDark = html.getAttribute('data-theme') === 'dark';
    var next  = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    // Persist the manual choice so it survives page reloads and new tabs.
    localStorage.setItem('theme', next);
    updateThemeBtn();
}

/* Updates the toggle button icon and tooltip to reflect the current theme. */
function updateThemeBtn() {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    btn.innerHTML  = isDark ? '&#9728;' : '&#9790;';   /* ☀ / ☾ */
    btn.title      = isDark ? 'Váltás világos módra' : 'Váltás sötét módra';
}

/* Respond to OS-level dark mode changes in real time, but only when the user
   has NOT made a manual choice (localStorage is empty). */
matchMedia('(prefers-color-scheme:dark)').addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
        document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
        updateThemeBtn();
    }
});
updateThemeBtn();
</script>
