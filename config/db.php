<?php
/**
 * Database bootstrap and shared utility functions.
 *
 * This file is included by every page. It redirects to the installer when
 * the application has not been set up yet, then provides three globally
 * available functions: a singleton PDO factory, a cached config reader, and
 * a stock-number generator.
 */

// If config.php does not exist the application has not been installed yet —
// redirect immediately so the user is guided through the setup wizard.
if (!file_exists(__DIR__ . '/config.php')) {
    $install = str_replace('\\', '/', dirname(__DIR__)) . '/install.php';
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/config.php';

/**
 * Returns a shared (singleton) PDO connection to the application database.
 *
 * The connection is created on first call and reused on every subsequent call
 * within the same request, avoiding the overhead of opening multiple connections.
 * PDO is configured to:
 *   - throw exceptions on errors (avoids silent failures)
 *   - return rows as associative arrays by default
 *   - use real prepared statements (disabling emulation prevents certain
 *     SQL-injection edge cases and gives accurate parameter type handling)
 *
 * @return PDO The shared database connection instance.
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Reads a key-value setting from the app_config table.
 *
 * Results are cached in a static array so repeated calls for the same key
 * within a request do not hit the database more than once.
 *
 * @param PDO    $db      The database connection.
 * @param string $key     The configuration key to look up (e.g. 'app_nev').
 * @param string $default Value to return when the key is not found in the DB.
 * @return string The stored setting value, or $default if absent.
 */
function getConfig(PDO $db, string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = $db->prepare("SELECT ertek FROM app_config WHERE kulcs = ?");
        $stmt->execute([$key]);
        $cache[$key] = $stmt->fetchColumn() ?: $default;
    }
    return $cache[$key];
}

/**
 * Generates the next sequential stock reference number.
 *
 * The format is: <PREFIX>-<zero-padded 4-digit number>, e.g. "RAK-0042".
 * The prefix is read from app_config; if missing, "RAK" is used as fallback.
 * The numeric part is derived by finding the current maximum suffix in the
 * termekek table for the active prefix and incrementing it by one, so
 * changing the prefix in settings will start a fresh sequence from 0001
 * without touching existing records.
 *
 * @param PDO $db The database connection.
 * @return string The next available stock number, e.g. "RAK-0005".
 */
function generateRaktariSzam(PDO $db): string {
    $prefix = getConfig($db, 'raktari_prefix', 'RAK');
    // Extract the numeric suffix from existing stock numbers that share
    // the current prefix, then take the maximum to find the last used value.
    $stmt = $db->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(raktari_szam, '-', -1) AS UNSIGNED))
         FROM termekek WHERE raktari_szam LIKE ?"
    );
    $stmt->execute([$prefix . '-%']);
    $max = (int)($stmt->fetchColumn() ?? 0);
    return $prefix . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}
