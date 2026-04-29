<?php
/**
 * Authentication and authorisation helpers.
 *
 * Provides session management, role checks, access-guard functions, and the
 * centralised activity logger used throughout the application.
 */

// Start the session only if one has not already been started by the caller.
// Using PHP_SESSION_NONE check prevents a warning when session_start() is
// called twice in the same request (e.g. from install.php or teszt.php).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Returns the currently authenticated user's session data, or null if no
 * user is logged in.
 *
 * The array contains: id, felhasznalonev, nev, szerepkor.
 *
 * @return array|null User data array, or null when the session is anonymous.
 */
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Returns true when there is an active authenticated session.
 *
 * @return bool
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user']);
}

/**
 * Returns true when the logged-in user has the 'admin' role.
 *
 * @return bool
 */
function isAdmin(): bool {
    return ($_SESSION['user']['szerepkor'] ?? '') === 'admin';
}

/**
 * Enforces that the visitor is logged in.
 *
 * If not, the visitor is redirected to the login page. The current URL is
 * passed as the `back` parameter so the user lands on the originally
 * requested page after a successful login.
 *
 * @return void
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php?back=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/**
 * Enforces that the visitor is logged in AND has admin privileges.
 *
 * First calls requireLogin() (which may redirect to login). Then checks the
 * role; non-admin users are redirected to the dashboard with an error flag.
 *
 * @return void
 */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: termekek.php?hiba=jogosultsag');
        exit;
    }
}

/**
 * Writes a record to the audit log (naplo) table.
 *
 * Every significant user action (login, product create/edit/delete,
 * config change, etc.) should be logged here. The IP address is stored to
 * help trace misuse. The termek_id foreign key is nullable so the same
 * function can log non-product events (login, settings, user management).
 *
 * @param PDO         $db        The database connection.
 * @param string      $muvelet   Action identifier string (e.g. 'termek_felvetel').
 * @param string      $reszletek Optional human-readable description of what changed.
 * @param int|null    $termek_id The affected product's primary key, if applicable.
 * @return void
 */
function logActivity(PDO $db, string $muvelet, string $reszletek = '', ?int $termek_id = null): void {
    $user = currentUser();
    $db->prepare(
        "INSERT INTO naplo (felhasznalo_id, muvelet, termek_id, reszletek, ip) VALUES (?,?,?,?,?)"
    )->execute([
        $user['id'] ?? null,
        $muvelet,
        $termek_id,
        // Store null instead of empty string to keep the column semantically clean.
        $reszletek ?: null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
