<?php
/**
 * Logout handler — records the audit log entry and destroys the session.
 *
 * The activity is logged before session_destroy() so currentUser() is still
 * available inside logActivity(). The ?kijelentkezve=1 flag on the redirect
 * tells login.php to display a "logged out successfully" confirmation message.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';

// Only log if a session exists; prevents a DB write if someone requests this
// URL without being logged in (e.g. a double-click on the logout link).
if (isLoggedIn()) {
    logActivity(getDB(), 'kilepes', 'Kijelentkezés.');
}
session_destroy();
header('Location: login.php?kijelentkezve=1');
exit;
