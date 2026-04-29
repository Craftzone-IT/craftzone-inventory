<?php
declare(strict_types=1);

/**
 * API front controller.
 *
 * Every API request is routed here by api/.htaccess. The URI is parsed to
 * extract the resource name and optional ID, then dispatched to the
 * appropriate handler file.
 *
 * URI format:  /api/{resource}[/{id}]
 * Examples:    /api/termek        → resource: termek, id: null
 *              /api/termek/42     → resource: termek, id: 42
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
// Paths are relative to the project root (one level up from api/).
$baseDir = dirname(__DIR__);

require_once $baseDir . '/config/db.php';
require_once $baseDir . '/includes/auth.php';
require_once $baseDir . '/includes/smtp_mailer.php';
require_once $baseDir . '/includes/api_auth.php';
require_once $baseDir . '/includes/termek_service.php';

$db = getDB();

// ── Default JSON content type for all responses ──────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Parse the request URI into resource + id ─────────────────────────────────
// Strip the base path (everything up to and including /api/) and query string.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path       = parse_url($requestUri, PHP_URL_PATH);

// Remove trailing slash and extract the part after /api/.
// Handles both /api/termek and /subdir/api/termek deployments.
if (preg_match('#/api/([a-zA-Z_]+)(?:/(\d+))?#', $path, $matches)) {
    $resource = strtolower($matches[1]);
    $id       = isset($matches[2]) ? (int)$matches[2] : null;
} else {
    jsonResponse(404, [
        'ok'   => false,
        'hiba' => 'Ismeretlen végpont. Használat: /api/{resource}[/{id}]',
    ]);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Route to handler ─────────────────────────────────────────────────────────
$handlerFile = __DIR__ . '/handlers/' . $resource . '_handler.php';

if (!file_exists($handlerFile)) {
    jsonResponse(404, [
        'ok'   => false,
        'hiba' => "Ismeretlen resource: $resource",
    ]);
}

// The handler receives: $db (PDO), $method (string), $id (int|null).
// Authentication is done inside the handler (so public endpoints are possible
// in the future).
require $handlerFile;
