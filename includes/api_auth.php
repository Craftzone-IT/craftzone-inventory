<?php
declare(strict_types=1);

/**
 * API authentication middleware — stateless Bearer-token authentication.
 *
 * This module provides token-based authentication for the REST API layer.
 * It does NOT start a PHP session or touch $_SESSION — every request is
 * authenticated independently via the Authorization header.
 *
 * Token lifecycle:
 *   1. Admin generates a token (bin2hex(random_bytes(32)) → 64 hex chars)
 *   2. The SHA-256 hash of that token is stored in api_tokenek
 *   3. The raw token is shown to the user ONCE (never stored)
 *   4. On each API request the client sends: Authorization: Bearer <raw-token>
 *   5. This module hashes the incoming token and looks it up in the DB
 *
 * Dependencies (must be loaded before this file):
 *   - config/db.php (getDB)
 */

// ─────────────────────────────────────────────────────────────────────────────
// JSON response helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Sends a JSON response with the given HTTP status code and exits.
 *
 * @param  int   $status HTTP status code (e.g. 200, 401, 429).
 * @param  array $data   Associative array to be JSON-encoded.
 * @return never
 */
function jsonResponse(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Rate limiter
// ─────────────────────────────────────────────────────────────────────────────

/** Maximum requests per token per minute. */
define('API_RATE_LIMIT', 60);

/**
 * Checks and increments the rate-limit counter for the given token row.
 *
 * Uses a sliding 60-second window stored directly in the api_tokenek table
 * (req_count + req_count_reset columns). When the window has expired the
 * counter is reset; when the limit is exceeded a 429 response is sent.
 *
 * @param  PDO $db      Database connection.
 * @param  int $tokenId The api_tokenek.id of the authenticated token.
 * @return void
 */
function checkRateLimit(PDO $db, int $tokenId): void
{
    $now = date('Y-m-d H:i:s');

    // Read current counter state.
    $stmt = $db->prepare(
        "SELECT req_count, req_count_reset FROM api_tokenek WHERE id = ?"
    );
    $stmt->execute([$tokenId]);
    $row = $stmt->fetch();

    if (!$row) {
        return; // Defensive — should never happen after successful auth.
    }

    $resetAt = $row['req_count_reset'];
    $count   = (int)$row['req_count'];

    // If no window exists or the window has expired, start a new one.
    if ($resetAt === null || $now >= $resetAt) {
        $newReset = date('Y-m-d H:i:s', time() + 60);
        $db->prepare(
            "UPDATE api_tokenek SET req_count = 1, req_count_reset = ? WHERE id = ?"
        )->execute([$newReset, $tokenId]);
        return;
    }

    // Window is still active — check the count.
    if ($count >= API_RATE_LIMIT) {
        $retryAfter = max(1, strtotime($resetAt) - time());
        header("Retry-After: $retryAfter");
        jsonResponse(429, [
            'ok'    => false,
            'hiba'  => 'Túl sok kérés. Kérjük várjon ' . $retryAfter . ' másodpercet.',
        ]);
    }

    // Increment the counter.
    $db->prepare(
        "UPDATE api_tokenek SET req_count = req_count + 1 WHERE id = ?"
    )->execute([$tokenId]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Token authentication
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Authenticates an incoming API request via Bearer token.
 *
 * Reads the Authorization header, hashes the raw token with SHA-256, and
 * looks up the hash in api_tokenek. On success returns the associated user's
 * data; on failure sends a 401 JSON response and exits.
 *
 * Side effects:
 *   - Updates utolso_hasznalat on the token row.
 *   - Increments the rate-limit counter (may send 429 and exit).
 *
 * @param  PDO $db Database connection.
 * @return array{id: int, felhasznalonev: string, nev: string, szerepkor: string, token_id: int, token_nev: string}
 */
function authenticateApiRequest(PDO $db): array
{
    // ── Extract Bearer token from Authorization header ───────────────────
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';

    if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
        jsonResponse(401, [
            'ok'   => false,
            'hiba' => 'Hiányzó vagy érvénytelen Authorization header. Használat: Bearer <token>',
        ]);
    }

    $rawToken = substr($authHeader, 7); // Strip "Bearer " prefix.

    // Basic format check: must be exactly 64 hex characters.
    if (!preg_match('/^[0-9a-f]{64}$/i', $rawToken)) {
        jsonResponse(401, [
            'ok'   => false,
            'hiba' => 'Érvénytelen token formátum.',
        ]);
    }

    // ── Look up the hashed token ─────────────────────────────────────────
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $db->prepare("
        SELECT t.id       AS token_id,
               t.nev      AS token_nev,
               t.aktiv    AS token_aktiv,
               f.id       AS felhasznalo_id,
               f.felhasznalonev,
               f.nev      AS felhasznalo_nev,
               f.szerepkor,
               f.aktiv    AS felhasznalo_aktiv
        FROM api_tokenek t
        JOIN felhasznalok f ON f.id = t.felhasznalo_id
        WHERE t.token = ?
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(401, [
            'ok'   => false,
            'hiba' => 'Érvénytelen API token.',
        ]);
    }

    // ── Check active state ───────────────────────────────────────────────
    if (!(int)$row['token_aktiv']) {
        jsonResponse(401, [
            'ok'   => false,
            'hiba' => 'Ez az API token deaktiválva van.',
        ]);
    }

    if (!(int)$row['felhasznalo_aktiv']) {
        jsonResponse(401, [
            'ok'   => false,
            'hiba' => 'A tokenhez tartozó felhasználói fiók inaktív.',
        ]);
    }

    // ── Rate limiting ────────────────────────────────────────────────────
    checkRateLimit($db, (int)$row['token_id']);

    // ── Update last-used timestamp ───────────────────────────────────────
    $db->prepare(
        "UPDATE api_tokenek SET utolso_hasznalat = CURRENT_TIMESTAMP WHERE id = ?"
    )->execute([(int)$row['token_id']]);

    // ── Return user data (same shape as session-based currentUser()) ─────
    return [
        'id'             => (int)$row['felhasznalo_id'],
        'felhasznalonev' => $row['felhasznalonev'],
        'nev'            => $row['felhasznalo_nev'],
        'szerepkor'      => $row['szerepkor'],
        'token_id'       => (int)$row['token_id'],
        'token_nev'      => $row['token_nev'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Token generation helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generates a new API token for a user and stores its hash in the database.
 *
 * Returns the raw token string — this is the ONLY time the raw token is
 * available. The caller must display it to the user immediately.
 *
 * @param  PDO    $db     Database connection.
 * @param  int    $userId The felhasznalok.id to associate the token with.
 * @param  string $nev    Human-readable description of the token.
 * @return array{raw_token: string, token_id: int}
 */
function generateApiToken(PDO $db, int $userId, string $nev): array
{
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $db->prepare(
        "INSERT INTO api_tokenek (felhasznalo_id, token, nev) VALUES (?, ?, ?)"
    )->execute([$userId, $tokenHash, $nev]);

    return [
        'raw_token' => $rawToken,
        'token_id'  => (int)$db->lastInsertId(),
    ];
}
