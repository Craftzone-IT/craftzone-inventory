<?php
declare(strict_types=1);

/**
 * API handler for the /api/termek resource.
 *
 * Receives $db (PDO), $method (string), $id (int|null) from the front controller.
 *
 * Endpoints:
 *   POST   /api/termek       — Create a new product
 *   GET    /api/termek       — List products (with filters)
 *   GET    /api/termek/{id}  — Get single product details
 *   PUT    /api/termek/{id}  — Update a product
 *   DELETE /api/termek/{id}  — Delete a product
 */

// ── Authenticate every request ───────────────────────────────────────────────
$apiUser = authenticateApiRequest($db);

// Populate the session user array so logActivity() (called by the service
// layer and directly below) can attribute actions to the correct user.
// This does NOT create a persistent session cookie — auth.php already called
// session_start(), we just fill the data for the duration of this request.
$_SESSION['user'] = [
    'id'             => $apiUser['id'],
    'felhasznalonev' => $apiUser['felhasznalonev'],
    'nev'            => $apiUser['nev'],
    'szerepkor'      => $apiUser['szerepkor'],
];

// ── Dispatch by HTTP method ──────────────────────────────────────────────────
switch ($method) {

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/termek — Create
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST':
        if ($id !== null) {
            jsonResponse(400, ['ok' => false, 'hiba' => 'POST kérésnél nem adható meg ID. Használj PUT-ot módosításhoz.']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            jsonResponse(400, ['ok' => false, 'hiba' => 'Érvénytelen JSON body.']);
        }

        $result = createTermek($db, $input, $apiUser['id']);

        if (!$result['ok']) {
            jsonResponse(400, [
                'ok'    => false,
                'hibak' => $result['hibak'],
            ]);
        }

        jsonResponse(201, [
            'ok'           => true,
            'id'           => $result['id'],
            'raktari_szam' => $result['raktari_szam'],
        ]);

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/termek — List  |  GET /api/termek/{id} — Detail
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET':
        if ($id !== null) {
            // ── Single product detail ────────────────────────────────────────
            $stmt = $db->prepare("
                SELECT t.*,
                       st.nev  AS statusz_nev,
                       st.szin AS statusz_szin,
                       s.nev   AS szallito_nev,
                       fc.nev  AS letrehozo_nev,
                       fm.nev  AS modosito_nev
                FROM termekek t
                LEFT JOIN statuszok   st ON t.statusz_id  = st.id
                LEFT JOIN szallitok    s ON t.szallito_id  = s.id
                LEFT JOIN felhasznalok fc ON t.letrehozta   = fc.id
                LEFT JOIN felhasznalok fm ON t.modositotta  = fm.id
                WHERE t.id = ?
            ");
            $stmt->execute([$id]);
            $termek = $stmt->fetch();

            if (!$termek) {
                jsonResponse(404, ['ok' => false, 'hiba' => 'A termék nem található.']);
            }

            jsonResponse(200, ['ok' => true, 'termek' => $termek]);
        }

        // ── Product list with optional filters ───────────────────────────────
        $limit  = max(1, min(200, (int)($_GET['limit']  ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where  = [];
        $params = [];

        // Free-text search across key columns.
        if (!empty($_GET['kereses'])) {
            $q = '%' . $_GET['kereses'] . '%';
            $where[] = "(t.raktari_szam LIKE ? OR t.megnevezes LIKE ? OR t.megjegyzes LIKE ?
                         OR t.be_szamlaszam LIKE ? OR t.ki_szamlaszam LIKE ? OR t.vevo LIKE ?)";
            array_push($params, $q, $q, $q, $q, $q, $q);
        }

        // Filter by tipus (exact match).
        if (!empty($_GET['tipus'])) {
            $where[]  = "t.tipus = ?";
            $params[] = $_GET['tipus'];
        }

        // Filter by status name.
        if (!empty($_GET['statusz'])) {
            $where[]  = "st.nev = ?";
            $params[] = $_GET['statusz'];
        }

        // Filter by status ID.
        if (!empty($_GET['statusz_id'])) {
            $where[]  = "t.statusz_id = ?";
            $params[] = (int)$_GET['statusz_id'];
        }

        // Filter by supplier name.
        if (!empty($_GET['szallito'])) {
            $where[]  = "s.nev = ?";
            $params[] = $_GET['szallito'];
        }

        // Date range filters.
        if (!empty($_GET['datum_tol'])) {
            $where[]  = "t.datum >= ?";
            $params[] = $_GET['datum_tol'];
        }
        if (!empty($_GET['datum_ig'])) {
            $where[]  = "t.datum <= ?";
            $params[] = $_GET['datum_ig'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count (for pagination metadata).
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM termekek t
            LEFT JOIN statuszok st ON t.statusz_id = st.id
            LEFT JOIN szallitok  s ON t.szallito_id = s.id
            $whereSql
        ");
        $countStmt->execute($params);
        $osszes = (int)$countStmt->fetchColumn();

        // Fetch the page. LIMIT and OFFSET are bound as parameters even though
        // they are already cast to int, for defence-in-depth.
        $dataStmt = $db->prepare("
            SELECT t.id, t.raktari_szam, t.datum, t.megnevezes, t.netto_ar,
                   t.tipus, t.spec, t.megjegyzes,
                   t.be_szamlaszam, t.ki_szamlaszam,
                   t.vevo, t.eladas_datum,
                   t.archivalható, t.ellenorzott, t.leltar,
                   t.statusz_id, st.nev AS statusz_nev, st.szin AS statusz_szin,
                   t.szallito_id, s.nev AS szallito_nev,
                   t.letrehozva, t.modositva
            FROM termekek t
            LEFT JOIN statuszok st ON t.statusz_id = st.id
            LEFT JOIN szallitok  s ON t.szallito_id = s.id
            $whereSql
            ORDER BY t.id DESC
            LIMIT ? OFFSET ?
        ");
        $dataStmt->execute(array_merge($params, [$limit, $offset]));
        $termekek = $dataStmt->fetchAll();

        jsonResponse(200, [
            'ok'       => true,
            'termekek' => $termekek,
            'osszes'   => $osszes,
            'limit'    => $limit,
            'offset'   => $offset,
        ]);

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/termek/{id} — Update
    // ─────────────────────────────────────────────────────────────────────────
    case 'PUT':
        if ($id === null) {
            jsonResponse(400, ['ok' => false, 'hiba' => 'Hiányzó termék ID. Használat: PUT /api/termek/{id}']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            jsonResponse(400, ['ok' => false, 'hiba' => 'Érvénytelen JSON body.']);
        }

        $result = updateTermek($db, $id, $input, $apiUser['id']);

        if (!$result['ok']) {
            jsonResponse(400, [
                'ok'    => false,
                'hibak' => $result['hibak'],
            ]);
        }

        jsonResponse(200, [
            'ok'           => true,
            'id'           => $result['id'],
            'raktari_szam' => $result['raktari_szam'],
        ]);

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/termek/{id} — Delete
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE':
        if ($id === null) {
            jsonResponse(400, ['ok' => false, 'hiba' => 'Hiányzó termék ID. Használat: DELETE /api/termek/{id}']);
        }

        // Fetch product details for the audit log (same pattern as termekek.php).
        $stmt = $db->prepare("SELECT raktari_szam, megnevezes FROM termekek WHERE id = ?");
        $stmt->execute([$id]);
        $termek = $stmt->fetch();

        if (!$termek) {
            jsonResponse(404, ['ok' => false, 'hiba' => 'A termék nem található.']);
        }

        $db->prepare("DELETE FROM termekek WHERE id = ?")->execute([$id]);
        logActivity(
            $db,
            'termek_torles',
            "Törölve (API): {$termek['raktari_szam']} – {$termek['megnevezes']}",
            $id
        );

        jsonResponse(200, ['ok' => true]);

    // ─────────────────────────────────────────────────────────────────────────
    // Anything else → 405
    // ─────────────────────────────────────────────────────────────────────────
    default:
        header('Allow: GET, POST, PUT, DELETE, OPTIONS');
        jsonResponse(405, [
            'ok'   => false,
            'hiba' => "A(z) $method HTTP metódus nem támogatott ezen a végponton.",
        ]);
}
