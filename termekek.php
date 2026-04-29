<?php
/**
 * Product list page with server-side pagination, filtering, sorting, and a
 * live AJAX quick-search that bypasses pagination for instant results.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/termek_service.php';
requireLogin();
$page_title = 'Termékek';
$db = getDB();

/* ── AJAX quick-search endpoint ────────────────────────────────────────────
   When ?ajax=1&q=<term> is requested (triggered by the search input on this
   page), the response is JSON and the script exits early. The query searches
   across all relevant text columns. Results are capped at 200 rows; for more
   precise results the user is directed to the advanced search page.         */
if (isset($_GET['ajax']) && isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = '%' . trim($_GET['q']) . '%';

    // Build additional WHERE clauses from active toggle filters so AJAX search
    // respects them (AND logic: search term + active filters).
    $ajaxWhere  = [];
    $ajaxParams = [];

    // Multi-value statusz filter (comma-separated IDs).
    $fStatusz = array_filter(explode(',', $_GET['f_statusz'] ?? ''), 'is_numeric');
    if (!empty($fStatusz)) {
        $ph = implode(',', array_fill(0, count($fStatusz), '?'));
        $ajaxWhere[] = "t.statusz_id IN ($ph)";
        $ajaxParams  = array_merge($ajaxParams, array_map('intval', $fStatusz));
    }
    // Multi-value tipus filter.
    $fTipus = array_filter(explode(',', $_GET['f_tipus'] ?? ''), 'strlen');
    if (!empty($fTipus)) {
        $ph = implode(',', array_fill(0, count($fTipus), '?'));
        $ajaxWhere[] = "t.tipus IN ($ph)";
        $ajaxParams  = array_merge($ajaxParams, $fTipus);
    }
    // Date range filter.
    if (!empty($_GET['f_datum_tol'])) { $ajaxWhere[] = "t.datum >= ?"; $ajaxParams[] = $_GET['f_datum_tol']; }
    if (!empty($_GET['f_datum_ig']))  { $ajaxWhere[] = "t.datum <= ?"; $ajaxParams[] = $_GET['f_datum_ig']; }

    // Combine: text search (OR across columns) AND toggle filters.
    $searchCond = "(t.raktari_szam LIKE ? OR t.megnevezes LIKE ?
           OR t.be_szamlaszam LIKE ? OR t.ki_szamlaszam LIKE ?
           OR t.vevo LIKE ? OR t.megjegyzes LIKE ?
           OR t.tipus LIKE ? OR t.spec LIKE ?
           OR t.datum LIKE ? OR t.eladas_datum LIKE ?
           OR t.netto_ar LIKE ? OR s.nev LIKE ?
           OR f1.nev LIKE ? OR st.nev LIKE ?)";
    $allConds = array_merge([$searchCond], $ajaxWhere);
    $whereSql = 'WHERE ' . implode(' AND ', $allConds);
    $allParams = array_merge([$q,$q,$q,$q,$q,$q,$q,$q,$q,$q,$q,$q,$q,$q], $ajaxParams);

    $rows = $db->prepare("
        SELECT t.id, t.raktari_szam, t.datum, t.megnevezes, t.megjegyzes,
               t.netto_ar, t.tipus, t.spec, t.vevo, t.eladas_datum,
               t.be_szamlaszam, t.ki_szamlaszam,
               t.archivalható, t.ellenorzott, t.leltar,
               t.statusz_id, st.nev AS statusz_nev, st.szin AS statusz_szin,
               t.szallito_id, s.nev AS szallito_nev, f1.nev AS felvitte
        FROM termekek t
        LEFT JOIN statuszok   st ON t.statusz_id  = st.id
        LEFT JOIN szallitok    s  ON t.szallito_id = s.id
        LEFT JOIN felhasznalok f1 ON t.letrehozta  = f1.id
        $whereSql
        ORDER BY t.letrehozva DESC
        LIMIT 200
    ");
    $rows->execute($allParams);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

/* ── AJAX inline-add endpoint ──────────────────────────────────────────────
   POST with ajax_save=1 creates a new product from the inline row and returns
   JSON with the saved row data (or error).                                  */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json; charset=utf-8');
    $user = currentUser();

    $adat = [
        'datum'          => $_POST['datum']          ?? '',
        'be_szamlaszam'  => $_POST['be_szamlaszam']  ?? '',
        'szallito_id'    => $_POST['szallito_id']     ?? '',
        'szallito_nev'   => $_POST['szallito_nev']    ?? '',
        'megnevezes'     => $_POST['megnevezes']      ?? '',
        'netto_ar'       => $_POST['netto_ar']        ?? '',
        'tipus'          => $_POST['tipus']           ?? '',
        'spec'           => '',
        'megjegyzes'     => $_POST['megjegyzes']      ?? '',
        'statusz_id'     => $_POST['statusz_id']      ?? 1,
        'vevo'           => $_POST['vevo']            ?? '',
        'eladas_datum'   => $_POST['eladas_datum']    ?? '',
        'ki_szamlaszam'  => $_POST['ki_szamlaszam']   ?? '',
    ];

    $result = createTermek($db, $adat, $user['id']);

    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'msg' => implode(' ', $result['hibak'])]);
        exit;
    }

    // Return the saved row with joined data for immediate table insertion.
    $row = $db->prepare("
        SELECT t.id, t.raktari_szam, t.datum, t.megnevezes, t.megjegyzes,
               t.netto_ar, t.tipus, t.vevo, t.eladas_datum,
               t.be_szamlaszam, t.ki_szamlaszam,
               t.statusz_id, st.nev AS statusz_nev, st.szin AS statusz_szin,
               s.nev AS szallito_nev
        FROM termekek t
        LEFT JOIN statuszok st ON t.statusz_id = st.id
        LEFT JOIN szallitok  s ON t.szallito_id = s.id
        WHERE t.id = ?
    ");
    $row->execute([$result['id']]);
    echo json_encode(['ok' => true, 'row' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

/* ── AJAX inline-update endpoint ───────────────────────────────────────────
   POST with ajax_update=1 updates an existing product and returns JSON.     */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json; charset=utf-8');
    $user = currentUser();
    $editId = (int)($_POST['id'] ?? 0);

    if (!$editId) {
        echo json_encode(['ok' => false, 'msg' => 'Hiányzó azonosító.']);
        exit;
    }

    $adat = [
        'datum'          => $_POST['datum']          ?? '',
        'be_szamlaszam'  => $_POST['be_szamlaszam']  ?? '',
        'szallito_id'    => $_POST['szallito_id']     ?? '',
        'szallito_nev'   => $_POST['szallito_nev']    ?? '',
        'megnevezes'     => $_POST['megnevezes']      ?? '',
        'netto_ar'       => $_POST['netto_ar']        ?? '',
        'tipus'          => $_POST['tipus']           ?? '',
        'megjegyzes'     => $_POST['megjegyzes']      ?? '',
        'statusz_id'     => $_POST['statusz_id']      ?? 1,
        'vevo'           => $_POST['vevo']            ?? '',
        'eladas_datum'   => $_POST['eladas_datum']    ?? '',
        'ki_szamlaszam'  => $_POST['ki_szamlaszam']   ?? '',
    ];

    $result = updateTermek($db, $editId, $adat, $user['id']);

    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'msg' => implode(' ', $result['hibak'])]);
        exit;
    }

    // Return updated row.
    $row = $db->prepare("
        SELECT t.id, t.raktari_szam, t.datum, t.megnevezes, t.megjegyzes,
               t.netto_ar, t.tipus, t.vevo, t.eladas_datum,
               t.be_szamlaszam, t.ki_szamlaszam,
               t.statusz_id, st.nev AS statusz_nev, st.szin AS statusz_szin,
               t.szallito_id, s.nev AS szallito_nev
        FROM termekek t
        LEFT JOIN statuszok st ON t.statusz_id = st.id
        LEFT JOIN szallitok  s ON t.szallito_id = s.id
        WHERE t.id = ?
    ");
    $row->execute([$editId]);
    echo json_encode(['ok' => true, 'row' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

/* ── Inline delete via GET ?torles=<id> ────────────────────────────────────
   The product details are fetched first so the stock number and name can be
   included in the audit log entry for traceability after deletion.          */
if (isset($_GET['torles']) && is_numeric($_GET['torles'])) {
    $t = $db->prepare("SELECT raktari_szam, megnevezes FROM termekek WHERE id=?");
    $t->execute([$_GET['torles']]);
    $t = $t->fetch();
    if ($t) {
        $db->prepare("DELETE FROM termekek WHERE id=?")->execute([$_GET['torles']]);
        logActivity($db, 'termek_torles', "Törölve: {$t['raktari_szam']} – {$t['megnevezes']}", (int)$_GET['torles']);
    }
    header('Location: termekek.php?uzenet=torolve');
    exit;
}

/* ── Filter and sort parameters ───────────────────────────────────────────
   Toggle filters support multi-value (comma-separated). When no filter GET
   params are present, values are restored from cookies for persistence.
   The sort column is whitelisted to prevent SQL injection via ORDER BY.     */

// Determine whether the URL carries explicit filter params. If ANY filter
// key is present (even empty), we use URL values; otherwise fall back to
// cookies so saved filters survive page reloads and navigation.
$hasExplicitFilters = isset($_GET['statusz']) || isset($_GET['tipus'])
                   || isset($_GET['datum_tol']) || isset($_GET['datum_ig']);

if ($hasExplicitFilters) {
    $szuro_statusz_raw = $_GET['statusz']  ?? '';
    $szuro_tipus_raw   = $_GET['tipus']    ?? '';
    $szuro_datum_tol   = $_GET['datum_tol'] ?? '';
    $szuro_datum_ig    = $_GET['datum_ig']  ?? '';
} else {
    $szuro_statusz_raw = $_COOKIE['f_statusz']  ?? '';
    $szuro_tipus_raw   = $_COOKIE['f_tipus']    ?? '';
    $szuro_datum_tol   = $_COOKIE['f_datum_tol'] ?? '';
    $szuro_datum_ig    = $_COOKIE['f_datum_ig']  ?? '';
}

// Parse comma-separated values into arrays for multi-select toggle filters.
$statusz_ids  = array_filter(explode(',', $szuro_statusz_raw), 'is_numeric');
$tipus_values = array_filter(explode(',', $szuro_tipus_raw), 'strlen');

$rendez = in_array($_GET['r'] ?? '', ['raktari_szam','datum','megnevezes','letrehozva']) ? $_GET['r'] : 'letrehozva';
$irany  = ($_GET['i'] ?? '') === 'asc' ? 'ASC' : 'DESC';

// Pagination settings — page size is validated against a whitelist.
$per_page_opcio = [25, 50, 100, 250];
$per_page = in_array((int)($_GET['pp'] ?? 0), $per_page_opcio) ? (int)$_GET['pp'] : 25;
$oldal    = max(1, (int)($_GET['oldal'] ?? 1));

$where  = [];
$params = [];

// Build the WHERE clause dynamically based on which filters are active.
if (!empty($statusz_ids)) {
    $ph = implode(',', array_fill(0, count($statusz_ids), '?'));
    $where[] = "t.statusz_id IN ($ph)";
    $params  = array_merge($params, array_map('intval', $statusz_ids));
}
if (!empty($tipus_values)) {
    $ph = implode(',', array_fill(0, count($tipus_values), '?'));
    $where[] = "t.tipus IN ($ph)";
    $params  = array_merge($params, $tipus_values);
}
if ($szuro_datum_tol !== '') { $where[] = "t.datum >= ?"; $params[] = $szuro_datum_tol; }
if ($szuro_datum_ig  !== '') { $where[] = "t.datum <= ?"; $params[] = $szuro_datum_ig; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Run the COUNT query first so we can clamp the requested page number to
   the actual maximum before running the data query.                         */
$count_stmt = $db->prepare("SELECT COUNT(*) FROM termekek t $where_sql");
$count_stmt->execute($params);
$osszes_talalat = (int)$count_stmt->fetchColumn();
$osszes_oldal   = max(1, (int)ceil($osszes_talalat / $per_page));
$oldal          = min($oldal, $osszes_oldal);  // Clamp to valid range.
$offset         = ($oldal - 1) * $per_page;

/* Main data query for the current page. LIMIT and OFFSET are interpolated
   as integers (already cast above), so no injection risk.                   */
$termekek = $db->prepare("
    SELECT t.*, s.nev AS szallito_nev,
           st.nev AS statusz_nev, st.szin AS statusz_szin,
           f1.nev AS felvitte, f2.nev AS modositotta_nev
    FROM termekek t
    LEFT JOIN statuszok   st ON t.statusz_id  = st.id
    LEFT JOIN szallitok    s  ON t.szallito_id = s.id
    LEFT JOIN felhasznalok f1 ON t.letrehozta  = f1.id
    LEFT JOIN felhasznalok f2 ON t.modositotta = f2.id
    $where_sql
    ORDER BY t.`$rendez` $irany
    LIMIT $per_page OFFSET $offset
");
$termekek->execute($params);
$termekek = $termekek->fetchAll();

// Load dropdown option lists for the filter bar and inline-add form.
$tipus_opcio   = $db->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='tipus' AND aktiv=1 ORDER BY sorrend")->fetchAll(PDO::FETCH_COLUMN);
$statusz_lista = $db->query("SELECT id, nev, szin FROM statuszok ORDER BY sorrend")->fetchAll();
$szallitok     = $db->query("SELECT id, nev FROM szallitok WHERE aktiv=1 ORDER BY nev")->fetchAll();

include 'includes/header.php';

/**
 * Renders a sortable column header link.
 *
 * Preserves all existing GET parameters and toggles the sort direction when
 * the same column is clicked again. Appends a directional arrow indicator.
 *
 * @param string $col   Column name used in ORDER BY.
 * @param string $label Human-readable header text.
 * @param string $cur   Currently active sort column.
 * @param string $irany Current sort direction ('ASC' or 'DESC').
 * @return string HTML anchor tag.
 */
function sortLink(string $col, string $label, string $cur, string $irany): string {
    $p = array_merge($_GET, ['r' => $col, 'i' => ($cur === $col && $irany === 'DESC') ? 'asc' : 'desc', 'oldal' => 1]);
    $arrow = $cur === $col ? ($irany === 'DESC' ? ' &#8595;' : ' &#8593;') : '';
    return '<a href="?' . http_build_query($p) . '" style="color:inherit;text-decoration:none">' . htmlspecialchars($label) . $arrow . '</a>';
}

/**
 * Builds a pagination link URL, merging the target page number into the
 * current query string so all active filters are preserved.
 *
 * @param int   $p   Target page number.
 * @param array $get Current $_GET parameters.
 * @return string URL string starting with '?'.
 */
function pageLink(int $p, array $get): string {
    return '?' . http_build_query(array_merge($get, ['oldal' => $p]));
}
?>

<style>
/* ── Toggle filter buttons ────────────────────────────────────────────── */
.filter-toggles { margin-bottom: 12px; }
.filter-group {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    margin-bottom: 8px;
}
.filter-group-label {
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    min-width: 70px; flex-shrink: 0;
}
.toggle-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px; border: 2px solid var(--border);
    border-radius: 6px; background: var(--surface);
    cursor: pointer; font-size: 12px; font-weight: 500;
    color: var(--text); transition: all .15s; user-select: none;
    font-family: inherit;
}
.toggle-btn:hover { border-color: var(--primary); }
.toggle-btn.active {
    background: var(--primary); color: #fff;
    border-color: var(--primary); box-shadow: 0 1px 3px rgba(59,130,246,.3);
}
.toggle-btn.active .badge { background: rgba(255,255,255,.25); color: #fff; }
.filter-actions {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 8px;
}
.datum-input { width: 140px; font-size: 12px; padding: 5px 8px; }
.datum-sep { color: var(--text-muted); font-size: 12px; }
</style>

<div class="page-header">
    <h1>&#9723; Termékek</h1>
    <div style="display:flex;align-items:center;gap:8px;flex:1;max-width:340px;margin:0 16px;">
        <input type="search" id="quickSearch" class="input" placeholder="&#128269; Gyors keresés…"
               autocomplete="off" style="width:100%" autofocus>
    </div>
</div>

<?php if (isset($_GET['uzenet'])): ?>
<div class="flash flash-<?= $_GET['uzenet'] === 'torolve' ? 'error' : 'success' ?> flash-auto">
    <?= match($_GET['uzenet']) { 'mentve' => 'Tétel sikeresen mentve.', 'torolve' => 'Tétel törölve.', default => '' } ?>
</div>
<?php endif; ?>

<?php $hasActiveFilter = !empty($statusz_ids) || !empty($tipus_values) || $szuro_datum_tol !== '' || $szuro_datum_ig !== ''; ?>

<!-- Toggle filter bar -->
<div class="card" style="padding:14px 16px;margin-bottom:12px" id="filterPanel">
    <div class="filter-toggles">
        <!-- Státusz toggle buttons -->
        <div class="filter-group">
            <span class="filter-group-label">Státusz:</span>
            <?php foreach ($statusz_lista as $st): ?>
            <button type="button"
                class="toggle-btn <?= in_array((int)$st['id'], $statusz_ids) ? 'active' : '' ?>"
                data-group="statusz" data-value="<?= $st['id'] ?>">
                <span class="badge badge-<?= htmlspecialchars($st['szin']) ?>" style="pointer-events:none;font-size:11px">
                    <?= htmlspecialchars($st['nev']) ?>
                </span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Típus toggle buttons -->
        <div class="filter-group">
            <span class="filter-group-label">Típus:</span>
            <?php foreach ($tipus_opcio as $tip): ?>
            <button type="button"
                class="toggle-btn <?= in_array($tip, $tipus_values) ? 'active' : '' ?>"
                data-group="tipus" data-value="<?= htmlspecialchars($tip) ?>">
                <?= htmlspecialchars($tip) ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Dátum szűrő: preset gombok + tól-ig -->
        <div class="filter-group">
            <span class="filter-group-label">Beszerzés:</span>
            <button type="button" class="toggle-btn datum-preset" data-preset="targyho">Tárgyhó</button>
            <button type="button" class="toggle-btn datum-preset" data-preset="elozo_ho">Előző hó</button>
            <button type="button" class="toggle-btn datum-preset" data-preset="targyev">Tárgyév</button>
            <button type="button" class="toggle-btn datum-preset" data-preset="elozo_ev">Előző év</button>
            <input type="date" id="datumTol" class="input datum-input"
                value="<?= htmlspecialchars($szuro_datum_tol) ?>" title="Dátum tól">
            <span class="datum-sep">–</span>
            <input type="date" id="datumIg" class="input datum-input"
                value="<?= htmlspecialchars($szuro_datum_ig) ?>" title="Dátum ig">
        </div>
    </div>

    <!-- Alsó sor: oldalméret + szűrők törlése + fejlett keresés -->
    <div class="filter-actions">
        <label class="filter-label" style="font-size:12px;color:var(--text-muted)">Oldal méret:</label>
        <select id="ppSelect" class="input" style="width:auto;font-size:12px">
            <?php foreach ($per_page_opcio as $pp): ?>
            <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> / oldal</option>
            <?php endforeach; ?>
        </select>
        <?php if ($hasActiveFilter): ?>
        <button type="button" class="btn btn-ghost btn-sm" id="clearFiltersBtn">&#10005; Szűrők törlése</button>
        <?php endif; ?>
        <a href="kereses.php" class="btn btn-ghost btn-sm" style="margin-left:auto">&#128269; Fejlett keresés</a>
    </div>
</div>

<div style="display:flex;justify-content:flex-end;margin-bottom:10px">
    <button type="button" class="btn btn-primary" id="inlineAddBtn">+ Új termék rögzítése</button>
</div>

<div class="card card-table">
    <?php if ($osszes_talalat === 0): ?>
        <p class="empty-state">Nincs találat.</p>
    <?php else: ?>
    <table class="table table-hover table-compact" id="productTable">
        <thead>
            <tr>
                <th><?= sortLink('raktari_szam', 'Rakt. szám', $rendez, $irany) ?></th>
                <th><?= sortLink('datum', 'Dátum', $rendez, $irany) ?></th>
                <th>Bej. számlaszám</th>
                <th>Szállító</th>
                <th><?= sortLink('megnevezes', 'Megnevezés', $rendez, $irany) ?></th>
                <th>Nettó ár</th>
                <th>Típus</th>
                <th>Státusz</th>
                <th>Vevő</th>
                <th>El. dátum</th>
                <th>Ki. számlaszám</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="productBody">
        <!-- Inline add row (hidden by default) -->
        <tr id="inlineAddRow" style="display:none;background:rgba(59,130,246,.04)">
            <td><code style="color:var(--text-muted);font-size:11px">auto</code></td>
            <td><input type="date" name="i_datum" class="input" style="width:120px;font-size:12px;padding:4px 6px" value="<?= date('Y-m-d') ?>"></td>
            <td><input type="text" name="i_be_szamlaszam" class="input" style="width:100px;font-size:12px;padding:4px 6px" placeholder="Számlasz."></td>
            <td>
                <div class="combobox" id="inlineSzCombo" style="min-width:130px">
                    <input type="hidden" name="i_szallito_id" id="iSzallitoId">
                    <input type="hidden" name="i_szallito_nev" id="iSzallitoNev">
                    <div class="combobox-input-wrap">
                        <span class="combobox-icon"><svg viewBox="0 0 20 20" fill="currentColor" style="width:12px;height:12px"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg></span>
                        <input type="text" id="iSzallitoInput" class="input combobox-input" style="font-size:12px;padding:4px 6px 4px 28px" placeholder="Szállító…" autocomplete="off">
                    </div>
                    <div class="combobox-dropdown" id="iSzallitoDropdown"></div>
                </div>
            </td>
            <td>
                <input type="text" name="i_megnevezes" class="input" style="width:100%;font-size:12px;padding:4px 6px;min-width:150px" placeholder="Megnevezés *" required>
                <input type="text" name="i_megjegyzes" class="input" style="width:100%;font-size:11px;padding:3px 6px;margin-top:3px;color:var(--text-muted)" placeholder="Megjegyzés…">
            </td>
            <td><input type="number" name="i_netto_ar" class="input" style="width:90px;font-size:12px;padding:4px 6px" step="0.01" min="0" placeholder="Ft"></td>
            <td>
                <select name="i_tipus" class="input" style="font-size:12px;padding:4px 6px;width:auto">
                    <option value="">–</option>
                    <?php foreach ($tipus_opcio as $o): ?>
                    <option value="<?= htmlspecialchars($o) ?>"><?= htmlspecialchars($o) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="i_statusz_id" class="input" style="font-size:12px;padding:4px 6px;width:auto">
                    <?php foreach ($statusz_lista as $st): ?>
                    <option value="<?= $st['id'] ?>" <?= (int)$st['id'] === 1 ? 'selected' : '' ?>><?= htmlspecialchars($st['nev']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="i_vevo" class="input" style="width:90px;font-size:12px;padding:4px 6px" placeholder="Vevő"></td>
            <td><input type="date" name="i_eladas_datum" class="input" style="width:120px;font-size:12px;padding:4px 6px"></td>
            <td><input type="text" name="i_ki_szamlaszam" class="input" style="width:100px;font-size:12px;padding:4px 6px" placeholder="Ki. szám"></td>
            <td class="actions" style="white-space:nowrap">
                <button type="button" id="inlineSaveBtn" class="btn btn-sm" style="background:var(--success);color:#fff;border:none;font-size:16px;padding:4px 10px" title="Mentés">&#10003;</button>
                <button type="button" id="inlineCancelBtn" class="btn btn-sm btn-ghost" style="font-size:14px;padding:4px 8px" title="Mégse">&#10005;</button>
            </td>
        </tr>
        <?php foreach ($termekek as $t): ?>
        <?php $eladott = ($t['statusz_szin'] ?? '') === 'red'; ?>
        <tr class="<?= $eladott ? 'row-sold' : '' ?>" data-id="<?= $t['id'] ?>"
            data-raktari_szam="<?= htmlspecialchars($t['raktari_szam']) ?>"
            data-datum="<?= htmlspecialchars($t['datum'] ?? '') ?>"
            data-be_szamlaszam="<?= htmlspecialchars($t['be_szamlaszam'] ?? '') ?>"
            data-szallito_id="<?= htmlspecialchars($t['szallito_id'] ?? '') ?>"
            data-szallito_nev="<?= htmlspecialchars($t['szallito_nev'] ?? '') ?>"
            data-megnevezes="<?= htmlspecialchars($t['megnevezes'] ?? '') ?>"
            data-megjegyzes="<?= htmlspecialchars($t['megjegyzes'] ?? '') ?>"
            data-netto_ar="<?= htmlspecialchars($t['netto_ar'] ?? '') ?>"
            data-tipus="<?= htmlspecialchars($t['tipus'] ?? '') ?>"
            data-statusz_id="<?= $t['statusz_id'] ?? 1 ?>"
            data-statusz_nev="<?= htmlspecialchars($t['statusz_nev'] ?? '') ?>"
            data-statusz_szin="<?= htmlspecialchars($t['statusz_szin'] ?? 'gray') ?>"
            data-vevo="<?= htmlspecialchars($t['vevo'] ?? '') ?>"
            data-eladas_datum="<?= htmlspecialchars($t['eladas_datum'] ?? '') ?>"
            data-ki_szamlaszam="<?= htmlspecialchars($t['ki_szamlaszam'] ?? '') ?>">
            <td><code><?= htmlspecialchars($t['raktari_szam']) ?></code></td>
            <td><?= $t['datum'] ? date('Y.m.d', strtotime($t['datum'])) : '–' ?></td>
            <td><?= htmlspecialchars($t['be_szamlaszam'] ?? '–') ?></td>
            <td><?= htmlspecialchars($t['szallito_nev'] ?? '–') ?></td>
            <td><strong><?= htmlspecialchars($t['megnevezes']) ?></strong>
                <?php if ($t['megjegyzes']): ?>
                <br><small class="text-muted"><?= htmlspecialchars(mb_substr($t['megjegyzes'],0,40)) ?><?= mb_strlen($t['megjegyzes'])>40?'…':'' ?></small>
                <?php endif; ?></td>
            <td><?= $t['netto_ar'] !== null ? number_format($t['netto_ar'], 0, ',', ' ') . ' Ft' : '–' ?></td>
            <td><?= htmlspecialchars($t['tipus'] ?? '–') ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($t['statusz_szin'] ?? 'gray') ?>"><?= htmlspecialchars($t['statusz_nev'] ?? '–') ?></span></td>
            <td><?= htmlspecialchars($t['vevo'] ?? '–') ?></td>
            <td><?= $t['eladas_datum'] ? date('Y.m.d', strtotime($t['eladas_datum'])) : '–' ?></td>
            <td><?= htmlspecialchars($t['ki_szamlaszam'] ?? '–') ?></td>
            <td class="actions">
                <button type="button" class="btn btn-sm btn-secondary btn-edit" data-id="<?= $t['id'] ?>">Szerk.</button>
                <a href="termekek.php?torles=<?= $t['id'] ?>&<?= http_build_query(array_diff_key($_GET, ['torles'=>'','uzenet'=>''])) ?>" class="btn btn-sm btn-danger btn-delete">Törlés</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Alsó sáv: találatok + lapozó -->
    <div class="pagination-bar">
        <span class="table-count" style="border:none;padding:0">
            <?= number_format($osszes_talalat, 0, ',', ' ') ?> találat &mdash;
            <?= $offset + 1 ?>–<?= min($offset + $per_page, $osszes_talalat) ?>. tétel
        </span>

        <?php if ($osszes_oldal > 1): ?>
        <nav class="pagination">
            <?php
            // First / previous navigation buttons.
            if ($oldal > 1): ?>
            <a href="<?= pageLink(1, $_GET) ?>"       class="page-btn" title="Első">&laquo;</a>
            <a href="<?= pageLink($oldal-1, $_GET) ?>" class="page-btn" title="Előző">&lsaquo;</a>
            <?php endif; ?>

            <?php
            /* Sliding window pagination — shows at most 7 numbered buttons
               (current ± 3 neighbours). Pages outside the window are replaced
               by ellipsis markers so the bar stays compact on large result sets. */
            $ablak = 3; // Number of neighbour pages to show on each side.
            $tol = max(1, $oldal - $ablak);
            $ig  = min($osszes_oldal, $oldal + $ablak);

            if ($tol > 1): ?>
            <a href="<?= pageLink(1, $_GET) ?>" class="page-btn">1</a>
            <?php if ($tol > 2): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $tol; $p <= $ig; $p++): ?>
            <a href="<?= pageLink($p, $_GET) ?>"
               class="page-btn <?= $p === $oldal ? 'page-btn-active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($ig < $osszes_oldal): ?>
            <?php if ($ig < $osszes_oldal - 1): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
            <a href="<?= pageLink($osszes_oldal, $_GET) ?>" class="page-btn"><?= $osszes_oldal ?></a>
            <?php endif; ?>

            <?php if ($oldal < $osszes_oldal): ?>
            <a href="<?= pageLink($oldal+1, $_GET) ?>"       class="page-btn" title="Következő">&rsaquo;</a>
            <a href="<?= pageLink($osszes_oldal, $_GET) ?>"  class="page-btn" title="Utolsó">&raquo;</a>
            <?php endif; ?>

            <!-- Direct page-jump input for navigating large result sets quickly. -->
            <span class="page-jump">
                <input type="number" id="pageJumpInput" class="input page-jump-input"
                    min="1" max="<?= $osszes_oldal ?>" placeholder="…" title="Ugrás oldalra">
                <button class="page-btn" onclick="
                    var v=parseInt(document.getElementById('pageJumpInput').value);
                    if(v>=1&&v<=<?= $osszes_oldal ?>){
                        var p=new URLSearchParams(location.search);
                        p.set('oldal',v);location.search=p.toString();
                    }" title="Ugrás">&#10148;</button>
            </span>
        </nav>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<script>
(function () {
    // ── Cookie helpers ──────────────────────────────────────────────────────
    function setCookie(name, value, days) {
        var d = new Date(); d.setTime(d.getTime() + (days||30)*24*60*60*1000);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }
    function getCookie(name) {
        var m = document.cookie.match('(^|;)\\s*' + name + '=([^;]*)');
        return m ? decodeURIComponent(m[2]) : '';
    }
    function deleteCookie(name) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
    }

    // ── Gather current filter state from toggle buttons & date inputs ───────
    function getActiveValues(group) {
        var vals = [];
        document.querySelectorAll('.toggle-btn.active[data-group="'+group+'"]').forEach(function(b) {
            vals.push(b.getAttribute('data-value'));
        });
        return vals;
    }

    // ── Date preset calculations ────────────────────────────────────────────
    function datePreset(preset) {
        var now = new Date(), y = now.getFullYear(), m = now.getMonth();
        var tol, ig;
        switch (preset) {
            case 'targyho':
                tol = new Date(y, m, 1);
                ig  = new Date(y, m + 1, 0);
                break;
            case 'elozo_ho':
                tol = new Date(y, m - 1, 1);
                ig  = new Date(y, m, 0);
                break;
            case 'targyev':
                tol = new Date(y, 0, 1);
                ig  = new Date(y, 11, 31);
                break;
            case 'elozo_ev':
                tol = new Date(y - 1, 0, 1);
                ig  = new Date(y - 1, 11, 31);
                break;
            default: return null;
        }
        return {
            tol: tol.toISOString().substring(0, 10),
            ig:  ig.toISOString().substring(0, 10)
        };
    }

    // ── Apply filters: save cookies + navigate ──────────────────────────────
    function applyFilters() {
        var statuszVals = getActiveValues('statusz');
        var tipusVals   = getActiveValues('tipus');
        var datumTol    = document.getElementById('datumTol').value;
        var datumIg     = document.getElementById('datumIg').value;

        // Save to cookies (30 days)
        setCookie('f_statusz',  statuszVals.join(','), 30);
        setCookie('f_tipus',    tipusVals.join(','), 30);
        setCookie('f_datum_tol', datumTol, 30);
        setCookie('f_datum_ig',  datumIg, 30);

        // Build URL params — include all filter keys so PHP detects explicit filters
        var p = new URLSearchParams();
        p.set('statusz',  statuszVals.join(','));
        p.set('tipus',    tipusVals.join(','));
        p.set('datum_tol', datumTol);
        p.set('datum_ig',  datumIg);

        // Preserve sort and page size
        var cur = new URLSearchParams(location.search);
        if (cur.has('r'))  p.set('r', cur.get('r'));
        if (cur.has('i'))  p.set('i', cur.get('i'));
        if (cur.has('pp')) p.set('pp', cur.get('pp'));
        // Reset to page 1 when filters change
        p.set('oldal', '1');

        location.search = p.toString();
    }

    // ── Toggle button click handlers ────────────────────────────────────────
    // Multi-select: clicking toggles .active on/off; then applyFilters navigates.
    document.querySelectorAll('.toggle-btn[data-group]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            this.classList.toggle('active');
            applyFilters();
        });
    });

    // ── Date preset buttons (toggle: click again to deselect) ─────────────
    document.querySelectorAll('.datum-preset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var wasActive = this.classList.contains('active');

            // Deactivate all presets first.
            document.querySelectorAll('.datum-preset').forEach(function(b) { b.classList.remove('active'); });

            if (wasActive) {
                // Toggle off: clear date range.
                document.getElementById('datumTol').value = '';
                document.getElementById('datumIg').value  = '';
            } else {
                // Activate this preset.
                var range = datePreset(this.getAttribute('data-preset'));
                if (!range) return;
                this.classList.add('active');
                document.getElementById('datumTol').value = range.tol;
                document.getElementById('datumIg').value  = range.ig;
            }
            applyFilters();
        });
    });

    // Highlight active date preset on page load (if dates match a preset)
    (function highlightPreset() {
        var tol = document.getElementById('datumTol').value;
        var ig  = document.getElementById('datumIg').value;
        if (!tol && !ig) return;
        ['targyho','elozo_ho','targyev','elozo_ev'].forEach(function(key) {
            var r = datePreset(key);
            if (r && r.tol === tol && r.ig === ig) {
                var btn = document.querySelector('.datum-preset[data-preset="'+key+'"]');
                if (btn) btn.classList.add('active');
            }
        });
    })();

    // ── Date input change: apply on manual date entry ───────────────────────
    document.getElementById('datumTol').addEventListener('change', function() {
        document.querySelectorAll('.datum-preset').forEach(function(b) { b.classList.remove('active'); });
        applyFilters();
    });
    document.getElementById('datumIg').addEventListener('change', function() {
        document.querySelectorAll('.datum-preset').forEach(function(b) { b.classList.remove('active'); });
        applyFilters();
    });

    // ── Clear all filters ───────────────────────────────────────────────────
    var clearBtn = document.getElementById('clearFiltersBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            deleteCookie('f_statusz');
            deleteCookie('f_tipus');
            deleteCookie('f_datum_tol');
            deleteCookie('f_datum_ig');
            // Navigate with empty filter keys so PHP sees explicit (empty) filters
            var p = new URLSearchParams();
            p.set('statusz', '');
            p.set('tipus', '');
            p.set('datum_tol', '');
            p.set('datum_ig', '');
            var cur = new URLSearchParams(location.search);
            if (cur.has('r'))  p.set('r', cur.get('r'));
            if (cur.has('i'))  p.set('i', cur.get('i'));
            if (cur.has('pp')) p.set('pp', cur.get('pp'));
            location.search = p.toString();
        });
    }

    // ── Page size selector ──────────────────────────────────────────────────
    var ppSelect = document.getElementById('ppSelect');
    if (ppSelect) {
        ppSelect.addEventListener('change', function() {
            var p = new URLSearchParams(location.search);
            p.set('pp', this.value);
            p.set('oldal', '1');
            location.search = p.toString();
        });
    }

    // ── Live AJAX quick-search ──────────────────────────────────────────────
    // Searches within the currently active filter context (AND logic).
    var input   = document.getElementById('quickSearch');
    if (!input) return;
    var card    = document.querySelector('.card.card-table');
    var origCard = card ? card.innerHTML : null;
    var timer;

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function highlight(s, q) {
        var e = esc(s);
        if (!q) return e;
        var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return e.replace(re, '<mark>$1</mark>');
    }
    function fmtDate(s) { return s ? s.substring(0,10).replace(/-/g,'.') : '–'; }
    function fmtAr(v)   { return v !== null && v !== '' ? Number(v).toLocaleString('hu-HU') + ' Ft' : '–'; }
    function badge(v, cls) { return v == 1 ? '<span class="badge badge-'+cls+'">I</span>' : '–'; }

    function buildRows(data, q) {
        if (!data.length) return '<p class="empty-state">Nincs találat.</p>';
        var rows = data.map(function(t) {
            var sold = t.statusz_szin === 'red';
            var note = t.megjegyzes
                ? '<br><small class="text-muted">'
                    + highlight(t.megjegyzes.length > 40 ? t.megjegyzes.substring(0,40)+'…' : t.megjegyzes, q)
                    + '</small>'
                : '';
            var statuszBadge = '<span class="badge badge-'+esc(t.statusz_szin||'gray')+'">'+esc(t.statusz_nev||'–')+'</span>';
            var dataAttrs = ' data-id="'+esc(t.id)+'"'
                + ' data-raktari_szam="'+esc(t.raktari_szam)+'"'
                + ' data-datum="'+esc((t.datum||'').substring(0,10))+'"'
                + ' data-be_szamlaszam="'+esc(t.be_szamlaszam||'')+'"'
                + ' data-szallito_id="'+esc(t.szallito_id||'')+'"'
                + ' data-szallito_nev="'+esc(t.szallito_nev||'')+'"'
                + ' data-megnevezes="'+esc(t.megnevezes||'')+'"'
                + ' data-megjegyzes="'+esc(t.megjegyzes||'')+'"'
                + ' data-netto_ar="'+esc(t.netto_ar||'')+'"'
                + ' data-tipus="'+esc(t.tipus||'')+'"'
                + ' data-statusz_id="'+esc(t.statusz_id||'1')+'"'
                + ' data-statusz_nev="'+esc(t.statusz_nev||'')+'"'
                + ' data-statusz_szin="'+esc(t.statusz_szin||'gray')+'"'
                + ' data-vevo="'+esc(t.vevo||'')+'"'
                + ' data-eladas_datum="'+esc((t.eladas_datum||'').substring(0,10))+'"'
                + ' data-ki_szamlaszam="'+esc(t.ki_szamlaszam||'')+'"';
            return '<tr class="'+(sold?'row-sold':'')+'"'+dataAttrs+'>'
                + '<td><code>'+highlight(t.raktari_szam, q)+'</code></td>'
                + '<td>'+highlight(fmtDate(t.datum), q)+'</td>'
                + '<td>'+highlight(t.be_szamlaszam||'–', q)+'</td>'
                + '<td>'+highlight(t.szallito_nev||'–', q)+'</td>'
                + '<td><strong>'+highlight(t.megnevezes, q)+'</strong>'+note+'</td>'
                + '<td>'+highlight(fmtAr(t.netto_ar), q)+'</td>'
                + '<td>'+highlight(t.tipus||'–', q)+'</td>'
                + '<td>'+statuszBadge+'</td>'
                + '<td>'+highlight(t.vevo||'–', q)+'</td>'
                + '<td>'+highlight(fmtDate(t.eladas_datum), q)+'</td>'
                + '<td>'+highlight(t.ki_szamlaszam||'–', q)+'</td>'
                + '<td class="actions">'
                    + '<button type="button" class="btn btn-sm btn-secondary btn-edit" data-id="'+esc(t.id)+'">Szerk.</button>'
                    + '<a href="termekek.php?torles='+esc(t.id)+'" class="btn btn-sm btn-danger btn-delete">Törlés</a>'
                + '</td></tr>';
        });
        return '<table class="table table-hover table-compact"><thead><tr>'
            + '<th>Rakt. szám</th><th>Dátum</th><th>Bej. számlaszám</th><th>Szállító</th>'
            + '<th>Megnevezés</th><th>Nettó ár</th><th>Típus</th>'
            + '<th>Státusz</th><th>Vevő</th><th>El. dátum</th><th>Ki. számlaszám</th><th></th>'
            + '</tr></thead><tbody>'+rows.join('')+'</tbody></table>'
            + '<div class="pagination-bar"><span class="table-count" style="border:none;padding:0">'
            + data.length + ' találat (max 200 jelenik meg – pontosításhoz használd a fejlett keresőt)'
            + '</span></div>';
    }

    function restore() {
        if (card && origCard !== null) card.innerHTML = origCard;
        bindDelete();
    }

    function bindDelete() {
        document.querySelectorAll('.btn-delete').forEach(function(a) {
            a.addEventListener('click', function(e) {
                if (!confirm('Biztosan törlöd ezt a tételt?')) e.preventDefault();
            });
        });
    }

    // Build AJAX URL with current filter params so search respects active toggles.
    function buildAjaxUrl(q) {
        var url = 'termekek.php?ajax=1&q=' + encodeURIComponent(q);
        var statuszVals = getActiveValues('statusz');
        var tipusVals   = getActiveValues('tipus');
        var datumTol    = document.getElementById('datumTol').value;
        var datumIg     = document.getElementById('datumIg').value;
        if (statuszVals.length) url += '&f_statusz=' + encodeURIComponent(statuszVals.join(','));
        if (tipusVals.length)   url += '&f_tipus='   + encodeURIComponent(tipusVals.join(','));
        if (datumTol) url += '&f_datum_tol=' + encodeURIComponent(datumTol);
        if (datumIg)  url += '&f_datum_ig='  + encodeURIComponent(datumIg);
        return url;
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { restore(); return; }
        timer = setTimeout(function() {
            fetch(buildAjaxUrl(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (card) { card.innerHTML = buildRows(data, q); bindDelete(); }
                });
        }, 280);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { this.value = ''; restore(); }
    });

    bindDelete();
})();

/* ── Inline add row logic ──────────────────────────────────────────────────
   Shows/hides the input row at the top of the table. On save, sends an AJAX
   POST and inserts the returned row into the DOM without a page reload.     */
(function () {
    var addBtn    = document.getElementById('inlineAddBtn');
    var addRow    = document.getElementById('inlineAddRow');
    var saveBtn   = document.getElementById('inlineSaveBtn');
    var cancelBtn = document.getElementById('inlineCancelBtn');
    var tbody     = document.getElementById('productBody');
    if (!addBtn || !addRow) return;

    // Ensure the table exists even when result set is empty.
    var tableCard = document.querySelector('.card.card-table');
    function ensureTable() {
        var tbl = document.getElementById('productTable');
        if (tbl) return;
        // Replace "Nincs találat." with a table skeleton.
        var empty = tableCard.querySelector('.empty-state');
        if (empty) {
            var html = '<table class="table table-hover table-compact" id="productTable"><thead><tr>'
                + '<th>Rakt. szám</th><th>Dátum</th><th>Bej. számlaszám</th><th>Szállító</th>'
                + '<th>Megnevezés</th><th>Nettó ár</th><th>Típus</th>'
                + '<th>Státusz</th><th>Vevő</th><th>El. dátum</th><th>Ki. számlaszám</th><th></th>'
                + '</tr></thead><tbody id="productBody"></tbody></table>';
            empty.outerHTML = html;
            tbody = document.getElementById('productBody');
            // Move addRow into the new tbody
            tbody.insertBefore(addRow, tbody.firstChild);
        }
    }

    addBtn.addEventListener('click', function () {
        ensureTable();
        addRow.style.display = '';
        // Focus the megnevezés input.
        var mn = addRow.querySelector('[name="i_megnevezes"]');
        if (mn) mn.focus();
        addBtn.disabled = true;
    });

    cancelBtn.addEventListener('click', function () {
        addRow.style.display = 'none';
        clearInlineRow();
        addBtn.disabled = false;
    });

    function clearInlineRow() {
        addRow.querySelectorAll('input[type="text"], input[type="number"]').forEach(function(el) { el.value = ''; });
        addRow.querySelector('[name="i_datum"]').value = new Date().toISOString().substring(0,10);
        addRow.querySelector('[name="i_eladas_datum"]').value = '';
        addRow.querySelector('[name="i_statusz_id"]').value = '1';
        addRow.querySelector('[name="i_tipus"]').value = '';
        document.getElementById('iSzallitoId').value = '';
        document.getElementById('iSzallitoNev').value = '';
        document.getElementById('iSzallitoInput').value = '';
        var errEl = addRow.querySelector('.inline-error');
        if (errEl) errEl.remove();
        var combo = document.getElementById('inlineSzCombo');
        if (combo) combo.classList.remove('has-value');
    }

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    saveBtn.addEventListener('click', function () {
        var errEl = addRow.querySelector('.inline-error');
        if (errEl) errEl.remove();

        var fd = new FormData();
        fd.append('ajax_save', '1');
        fd.append('datum',          addRow.querySelector('[name="i_datum"]').value);
        fd.append('be_szamlaszam',  addRow.querySelector('[name="i_be_szamlaszam"]').value);
        fd.append('szallito_id',    document.getElementById('iSzallitoId').value);
        fd.append('szallito_nev',   document.getElementById('iSzallitoNev').value);
        fd.append('megnevezes',     addRow.querySelector('[name="i_megnevezes"]').value);
        fd.append('megjegyzes',     addRow.querySelector('[name="i_megjegyzes"]').value);
        fd.append('netto_ar',       addRow.querySelector('[name="i_netto_ar"]').value);
        fd.append('tipus',          addRow.querySelector('[name="i_tipus"]').value);
        fd.append('statusz_id',     addRow.querySelector('[name="i_statusz_id"]').value);
        fd.append('vevo',           addRow.querySelector('[name="i_vevo"]').value);
        fd.append('eladas_datum',   addRow.querySelector('[name="i_eladas_datum"]').value);
        fd.append('ki_szamlaszam',  addRow.querySelector('[name="i_ki_szamlaszam"]').value);

        saveBtn.disabled = true;
        saveBtn.innerHTML = '&#8987;';

        fetch('termekek.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j.ok) {
                    var e = document.createElement('div');
                    e.className = 'inline-error';
                    e.style.cssText = 'color:var(--danger);font-size:11px;padding:4px 8px;position:absolute;bottom:-18px;left:0;white-space:nowrap';
                    addRow.querySelector('[name="i_megnevezes"]').parentElement.style.position = 'relative';
                    addRow.querySelector('[name="i_megnevezes"]').parentElement.appendChild(e);
                    e.textContent = j.msg;
                    return;
                }
                // Insert the saved row into the table after the add row.
                var t = j.row;
                var sold = t.statusz_szin === 'red';
                var note = t.megjegyzes
                    ? '<br><small class="text-muted">' + esc(t.megjegyzes.length > 40 ? t.megjegyzes.substring(0,40)+'…' : t.megjegyzes) + '</small>'
                    : '';
                var fmtDate = function(s) { return s ? s.substring(0,10).replace(/-/g,'.') : '–'; };
                var fmtAr = function(v) { return v !== null && v !== '' ? Number(v).toLocaleString('hu-HU') + ' Ft' : '–'; };
                var tr = document.createElement('tr');
                tr.className = sold ? 'row-sold' : '';
                tr.style.animation = 'fadeIn .4s';
                tr.dataset.id = t.id;
                tr.dataset.raktari_szam = t.raktari_szam || '';
                tr.dataset.datum = (t.datum || '').substring(0,10);
                tr.dataset.be_szamlaszam = t.be_szamlaszam || '';
                tr.dataset.szallito_id = t.szallito_id || document.getElementById('iSzallitoId').value || '';
                tr.dataset.szallito_nev = t.szallito_nev || '';
                tr.dataset.megnevezes = t.megnevezes || '';
                tr.dataset.megjegyzes = t.megjegyzes || '';
                tr.dataset.netto_ar = t.netto_ar || '';
                tr.dataset.tipus = t.tipus || '';
                tr.dataset.statusz_id = t.statusz_id || '1';
                tr.dataset.statusz_nev = t.statusz_nev || '';
                tr.dataset.statusz_szin = t.statusz_szin || 'gray';
                tr.dataset.vevo = t.vevo || '';
                tr.dataset.eladas_datum = (t.eladas_datum || '').substring(0,10);
                tr.dataset.ki_szamlaszam = t.ki_szamlaszam || '';
                tr.innerHTML =
                    '<td><code>'+esc(t.raktari_szam)+'</code></td>'
                    + '<td>'+fmtDate(t.datum)+'</td>'
                    + '<td>'+esc(t.be_szamlaszam||'–')+'</td>'
                    + '<td>'+esc(t.szallito_nev||'–')+'</td>'
                    + '<td><strong>'+esc(t.megnevezes)+'</strong>'+note+'</td>'
                    + '<td>'+fmtAr(t.netto_ar)+'</td>'
                    + '<td>'+esc(t.tipus||'–')+'</td>'
                    + '<td><span class="badge badge-'+esc(t.statusz_szin||'gray')+'">'+esc(t.statusz_nev||'–')+'</span></td>'
                    + '<td>'+esc(t.vevo||'–')+'</td>'
                    + '<td>'+fmtDate(t.eladas_datum)+'</td>'
                    + '<td>'+esc(t.ki_szamlaszam||'–')+'</td>'
                    + '<td class="actions">'
                        + '<button type="button" class="btn btn-sm btn-secondary btn-edit" data-id="'+esc(t.id)+'">Szerk.</button>'
                        + '<a href="termekek.php?torles='+esc(t.id)+'" class="btn btn-sm btn-danger btn-delete">Törlés</a>'
                    + '</td>';
                addRow.after(tr);
                // Re-bind delete confirmation on the new row.
                tr.querySelector('.btn-delete').addEventListener('click', function(e) {
                    if (!confirm('Biztosan törlöd ezt a tételt?')) e.preventDefault();
                });
                // Hide and reset the add row.
                addRow.style.display = 'none';
                clearInlineRow();
                addBtn.disabled = false;
            })
            .catch(function() {
                var e = document.createElement('div');
                e.className = 'inline-error';
                e.style.cssText = 'color:var(--danger);font-size:11px;padding:4px';
                e.textContent = 'Hálózati hiba.';
                addRow.querySelector('[name="i_megnevezes"]').parentElement.appendChild(e);
            })
            .finally(function() {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '&#10003;';
            });
    });
})();

/* ── Inline szállító combobox ──────────────────────────────────────────────
   Reusable combobox for the inline add row supplier field.                  */
(function () {
    var suppliers = <?= json_encode(array_map(function($s) {
        return ['id' => (int)$s['id'], 'nev' => $s['nev']];
    }, $szallitok), JSON_UNESCAPED_UNICODE) ?>;

    var wrap     = document.getElementById('inlineSzCombo');
    var input    = document.getElementById('iSzallitoInput');
    var hiddenId = document.getElementById('iSzallitoId');
    var hiddenNm = document.getElementById('iSzallitoNev');
    var dropdown = document.getElementById('iSzallitoDropdown');
    if (!wrap || !input) return;

    var highlighted = -1, filtered = [];

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function markMatch(t, q) {
        if (!q) return esc(t);
        var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi');
        return esc(t).replace(re,'<mark>$1</mark>');
    }

    function render(query) {
        var q = (query||'').trim().toLowerCase();
        filtered = suppliers.filter(function(s) { return !q || s.nev.toLowerCase().indexOf(q) !== -1; });
        var html = filtered.map(function(s, i) {
            var sel = (String(s.id) === hiddenId.value) ? ' selected' : '';
            var hl  = (i === highlighted) ? ' highlighted' : '';
            return '<div class="combobox-item'+sel+hl+'" data-id="'+s.id+'" data-name="'+esc(s.nev)+'">'+markMatch(s.nev, query)+'</div>';
        }).join('');
        var exact = suppliers.some(function(s) { return s.nev.toLowerCase() === q; });
        if (q && !exact) {
            html += '<div class="combobox-new" id="iComboNew">+ Új: <strong>'+esc(query.trim())+'</strong></div>';
        }
        dropdown.innerHTML = html;
    }

    function openDD() { highlighted = -1; render(input.value); wrap.classList.add('open'); }
    function closeDD() { wrap.classList.remove('open'); highlighted = -1; }

    function selectItem(id, name) {
        hiddenId.value = id; hiddenNm.value = name; input.value = name;
        wrap.classList.toggle('has-value', !!name); closeDD();
    }

    input.addEventListener('focus', openDD);
    input.addEventListener('input', function() {
        highlighted = -1; render(this.value); wrap.classList.add('open');
        var q = this.value.trim().toLowerCase();
        var m = suppliers.find(function(s) { return s.nev.toLowerCase() === q; });
        hiddenId.value = m ? m.id : '';
        hiddenNm.value = this.value.trim();
        wrap.classList.toggle('has-value', !!this.value.trim());
    });
    input.addEventListener('keydown', function(e) {
        var items = dropdown.querySelectorAll('.combobox-item');
        var newI  = document.getElementById('iComboNew');
        var total = items.length + (newI ? 1 : 0);
        if (e.key === 'ArrowDown') { e.preventDefault(); highlighted = Math.min(highlighted+1, total-1); upHL(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(highlighted-1, -1); upHL(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlighted >= 0 && highlighted < items.length) selectItem(items[highlighted].dataset.id, items[highlighted].dataset.name);
            else if (highlighted === items.length && newI) selectItem('', input.value.trim());
            else { var q=input.value.trim().toLowerCase(); var m=suppliers.find(function(s){return s.nev.toLowerCase()===q;}); if(m) selectItem(m.id,m.nev); else selectItem('',input.value.trim()); }
        } else if (e.key === 'Escape') { closeDD(); }
    });
    function upHL() {
        dropdown.querySelectorAll('.combobox-item').forEach(function(el,i) { el.classList.toggle('highlighted', i===highlighted); });
        var ni = document.getElementById('iComboNew');
        if (ni) ni.classList.toggle('highlighted', highlighted === dropdown.querySelectorAll('.combobox-item').length);
        var h = dropdown.querySelector('.highlighted'); if (h) h.scrollIntoView({block:'nearest'});
    }
    dropdown.addEventListener('mousedown', function(e) { e.preventDefault(); });
    dropdown.addEventListener('click', function(e) {
        var item = e.target.closest('.combobox-item');
        var newI = e.target.closest('.combobox-new');
        if (item) selectItem(item.dataset.id, item.dataset.name);
        else if (newI) selectItem('', input.value.trim());
    });
    document.addEventListener('click', function(e) { if (!wrap.contains(e.target)) closeDD(); });
})();

/* ── Inline edit logic ─────────────────────────────────────────────────────
   Click "Szerk." → row transforms into editable inputs (matching inline-add
   styling). Save → AJAX update + restore as display row. Cancel → restore
   original HTML. Only one row can be in edit mode at a time.                */
(function () {
    var suppliers = <?= json_encode(array_map(function($s) {
        return ['id' => (int)$s['id'], 'nev' => $s['nev']];
    }, $szallitok), JSON_UNESCAPED_UNICODE) ?>;
    var typusOpcio = <?= json_encode($tipus_opcio, JSON_UNESCAPED_UNICODE) ?>;
    var statuszLista = <?= json_encode(array_map(function($s) {
        return ['id' => (int)$s['id'], 'nev' => $s['nev'], 'szin' => $s['szin']];
    }, $statusz_lista), JSON_UNESCAPED_UNICODE) ?>;

    var currentEdit = null; // { tr, originalHtml, originalClass, comboCleanup }

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtDate(s) { return s ? s.substring(0,10).replace(/-/g,'.') : '–'; }
    function fmtAr(v)   { return v !== null && v !== '' ? Number(v).toLocaleString('hu-HU') + ' Ft' : '–'; }

    // Re-renders a tr as a display row from a fresh server payload, also
    // updating the data-* attributes so subsequent edits start from new state.
    function renderDisplayRow(tr, t) {
        tr.dataset.id            = t.id;
        tr.dataset.raktari_szam  = t.raktari_szam || '';
        tr.dataset.datum         = (t.datum || '').substring(0,10);
        tr.dataset.be_szamlaszam = t.be_szamlaszam || '';
        tr.dataset.szallito_id   = t.szallito_id || '';
        tr.dataset.szallito_nev  = t.szallito_nev || '';
        tr.dataset.megnevezes    = t.megnevezes || '';
        tr.dataset.megjegyzes    = t.megjegyzes || '';
        tr.dataset.netto_ar      = t.netto_ar || '';
        tr.dataset.tipus         = t.tipus || '';
        tr.dataset.statusz_id    = t.statusz_id || '1';
        tr.dataset.statusz_nev   = t.statusz_nev || '';
        tr.dataset.statusz_szin  = t.statusz_szin || 'gray';
        tr.dataset.vevo          = t.vevo || '';
        tr.dataset.eladas_datum  = (t.eladas_datum || '').substring(0,10);
        tr.dataset.ki_szamlaszam = t.ki_szamlaszam || '';

        var sold = t.statusz_szin === 'red';
        tr.className = sold ? 'row-sold' : '';
        tr.style.background = '';

        var note = t.megjegyzes
            ? '<br><small class="text-muted">' + esc(t.megjegyzes.length > 40 ? t.megjegyzes.substring(0,40)+'…' : t.megjegyzes) + '</small>'
            : '';
        tr.innerHTML =
            '<td><code>'+esc(t.raktari_szam)+'</code></td>'
            + '<td>'+fmtDate(t.datum)+'</td>'
            + '<td>'+esc(t.be_szamlaszam||'–')+'</td>'
            + '<td>'+esc(t.szallito_nev||'–')+'</td>'
            + '<td><strong>'+esc(t.megnevezes)+'</strong>'+note+'</td>'
            + '<td>'+fmtAr(t.netto_ar)+'</td>'
            + '<td>'+esc(t.tipus||'–')+'</td>'
            + '<td><span class="badge badge-'+esc(t.statusz_szin||'gray')+'">'+esc(t.statusz_nev||'–')+'</span></td>'
            + '<td>'+esc(t.vevo||'–')+'</td>'
            + '<td>'+fmtDate(t.eladas_datum)+'</td>'
            + '<td>'+esc(t.ki_szamlaszam||'–')+'</td>'
            + '<td class="actions">'
                + '<button type="button" class="btn btn-sm btn-secondary btn-edit" data-id="'+esc(t.id)+'">Szerk.</button>'
                + '<a href="termekek.php?torles='+esc(t.id)+'" class="btn btn-sm btn-danger btn-delete">Törlés</a>'
            + '</td>';
    }

    // Builds the editable cell HTML, pre-filled from the row's data-* attributes.
    function buildEditCells(tr) {
        var d = tr.dataset;
        var tipusOpts = '<option value="">–</option>' + typusOpcio.map(function(o) {
            var sel = (o === d.tipus) ? ' selected' : '';
            return '<option value="'+esc(o)+'"'+sel+'>'+esc(o)+'</option>';
        }).join('');
        var statuszOpts = statuszLista.map(function(s) {
            var sel = (String(s.id) === String(d.statusz_id)) ? ' selected' : '';
            return '<option value="'+s.id+'"'+sel+'>'+esc(s.nev)+'</option>';
        }).join('');

        return ''
            + '<td><code style="color:var(--text-muted);font-size:11px">'+esc(d.raktari_szam)+'</code></td>'
            + '<td><input type="date" class="input e_datum" style="width:120px;font-size:12px;padding:4px 6px" value="'+esc(d.datum)+'"></td>'
            + '<td><input type="text" class="input e_be_szamlaszam" style="width:100px;font-size:12px;padding:4px 6px" value="'+esc(d.be_szamlaszam)+'" placeholder="Számlasz."></td>'
            + '<td>'
                + '<div class="combobox" data-edit-combo style="min-width:130px">'
                    + '<input type="hidden" class="e_szallito_id" value="'+esc(d.szallito_id)+'">'
                    + '<input type="hidden" class="e_szallito_nev" value="'+esc(d.szallito_nev)+'">'
                    + '<div class="combobox-input-wrap">'
                        + '<span class="combobox-icon"><svg viewBox="0 0 20 20" fill="currentColor" style="width:12px;height:12px"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg></span>'
                        + '<input type="text" class="input combobox-input e_szallito_input" style="font-size:12px;padding:4px 6px 4px 28px" placeholder="Szállító…" autocomplete="off" value="'+esc(d.szallito_nev)+'">'
                    + '</div>'
                    + '<div class="combobox-dropdown e_szallito_dropdown"></div>'
                + '</div>'
            + '</td>'
            + '<td>'
                + '<input type="text" class="input e_megnevezes" style="width:100%;font-size:12px;padding:4px 6px;min-width:150px" value="'+esc(d.megnevezes)+'" placeholder="Megnevezés *" required>'
                + '<input type="text" class="input e_megjegyzes" style="width:100%;font-size:11px;padding:3px 6px;margin-top:3px;color:var(--text-muted)" value="'+esc(d.megjegyzes)+'" placeholder="Megjegyzés…">'
            + '</td>'
            + '<td><input type="number" class="input e_netto_ar" style="width:90px;font-size:12px;padding:4px 6px" step="0.01" min="0" value="'+esc(d.netto_ar)+'" placeholder="Ft"></td>'
            + '<td><select class="input e_tipus" style="font-size:12px;padding:4px 6px;width:auto">'+tipusOpts+'</select></td>'
            + '<td><select class="input e_statusz_id" style="font-size:12px;padding:4px 6px;width:auto">'+statuszOpts+'</select></td>'
            + '<td><input type="text" class="input e_vevo" style="width:90px;font-size:12px;padding:4px 6px" value="'+esc(d.vevo)+'" placeholder="Vevő"></td>'
            + '<td><input type="date" class="input e_eladas_datum" style="width:120px;font-size:12px;padding:4px 6px" value="'+esc(d.eladas_datum)+'"></td>'
            + '<td><input type="text" class="input e_ki_szamlaszam" style="width:100px;font-size:12px;padding:4px 6px" value="'+esc(d.ki_szamlaszam)+'" placeholder="Ki. szám"></td>'
            + '<td class="actions" style="white-space:nowrap">'
                + '<button type="button" class="btn btn-sm btn-edit-save" style="background:var(--success);color:#fff;border:none;font-size:16px;padding:4px 10px" title="Mentés">&#10003;</button>'
                + '<button type="button" class="btn btn-sm btn-ghost btn-edit-cancel" style="font-size:14px;padding:4px 8px" title="Mégse">&#10005;</button>'
            + '</td>';
    }

    // Wires up the supplier combobox inside an edit row. Returns a cleanup
    // function that detaches the document-level outside-click listener.
    function attachEditCombobox(tr) {
        var wrap     = tr.querySelector('[data-edit-combo]');
        var input    = tr.querySelector('.e_szallito_input');
        var hiddenId = tr.querySelector('.e_szallito_id');
        var hiddenNm = tr.querySelector('.e_szallito_nev');
        var dropdown = tr.querySelector('.e_szallito_dropdown');
        var highlighted = -1;

        function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
        function markMatch(t, q) {
            if (!q) return escH(t);
            var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi');
            return escH(t).replace(re,'<mark>$1</mark>');
        }
        function render(query) {
            var q = (query||'').trim().toLowerCase();
            var filtered = suppliers.filter(function(s) { return !q || s.nev.toLowerCase().indexOf(q) !== -1; });
            var html = filtered.map(function(s, i) {
                var hl = (i === highlighted) ? ' highlighted' : '';
                return '<div class="combobox-item'+hl+'" data-id="'+s.id+'" data-name="'+escH(s.nev)+'">'+markMatch(s.nev, query)+'</div>';
            }).join('');
            var exact = suppliers.some(function(s) { return s.nev.toLowerCase() === q; });
            if (q && !exact) {
                html += '<div class="combobox-new">+ Új: <strong>'+escH(query.trim())+'</strong></div>';
            }
            dropdown.innerHTML = html;
        }
        function selectItem(id, name) {
            hiddenId.value = id; hiddenNm.value = name; input.value = name;
            wrap.classList.toggle('has-value', !!name); wrap.classList.remove('open');
            highlighted = -1;
        }
        function upHL() {
            dropdown.querySelectorAll('.combobox-item').forEach(function(el,i) { el.classList.toggle('highlighted', i===highlighted); });
            var ni = dropdown.querySelector('.combobox-new');
            if (ni) ni.classList.toggle('highlighted', highlighted === dropdown.querySelectorAll('.combobox-item').length);
            var h = dropdown.querySelector('.highlighted'); if (h) h.scrollIntoView({block:'nearest'});
        }
        input.addEventListener('focus', function() { highlighted = -1; render(input.value); wrap.classList.add('open'); });
        input.addEventListener('input', function() {
            highlighted = -1; render(this.value); wrap.classList.add('open');
            var q = this.value.trim().toLowerCase();
            var m = suppliers.find(function(s) { return s.nev.toLowerCase() === q; });
            hiddenId.value = m ? m.id : '';
            hiddenNm.value = this.value.trim();
            wrap.classList.toggle('has-value', !!this.value.trim());
        });
        input.addEventListener('keydown', function(e) {
            var items = dropdown.querySelectorAll('.combobox-item');
            var newI  = dropdown.querySelector('.combobox-new');
            var total = items.length + (newI ? 1 : 0);
            if (e.key === 'ArrowDown') { e.preventDefault(); highlighted = Math.min(highlighted+1, total-1); upHL(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(highlighted-1, -1); upHL(); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlighted >= 0 && highlighted < items.length) selectItem(items[highlighted].dataset.id, items[highlighted].dataset.name);
                else if (highlighted === items.length && newI) selectItem('', input.value.trim());
                else { var q=input.value.trim().toLowerCase(); var m=suppliers.find(function(s){return s.nev.toLowerCase()===q;}); if(m) selectItem(m.id,m.nev); else selectItem('',input.value.trim()); }
            } else if (e.key === 'Escape') { wrap.classList.remove('open'); }
        });
        dropdown.addEventListener('mousedown', function(e) { e.preventDefault(); });
        dropdown.addEventListener('click', function(e) {
            var item = e.target.closest('.combobox-item');
            var newI = e.target.closest('.combobox-new');
            if (item) selectItem(item.dataset.id, item.dataset.name);
            else if (newI) selectItem('', input.value.trim());
        });
        var outside = function(e) { if (!wrap.contains(e.target)) wrap.classList.remove('open'); };
        document.addEventListener('click', outside);
        wrap.classList.toggle('has-value', !!input.value.trim());
        return function() { document.removeEventListener('click', outside); };
    }

    function cancelCurrentEdit() {
        if (!currentEdit) return;
        if (currentEdit.comboCleanup) currentEdit.comboCleanup();
        currentEdit.tr.innerHTML = currentEdit.originalHtml;
        currentEdit.tr.className = currentEdit.originalClass;
        currentEdit.tr.style.background = '';
        currentEdit = null;
    }

    function startEdit(tr) {
        if (!tr || !tr.dataset.id) return;
        if (currentEdit && currentEdit.tr === tr) return;
        cancelCurrentEdit();
        currentEdit = {
            tr: tr,
            originalHtml: tr.innerHTML,
            originalClass: tr.className,
            comboCleanup: null
        };
        tr.style.background = 'rgba(59,130,246,.04)';
        tr.innerHTML = buildEditCells(tr);
        currentEdit.comboCleanup = attachEditCombobox(tr);
        var mn = tr.querySelector('.e_megnevezes');
        if (mn) { mn.focus(); mn.select(); }
    }

    function saveEdit() {
        if (!currentEdit) return;
        var tr = currentEdit.tr;
        var saveBtn = tr.querySelector('.btn-edit-save');

        var fd = new FormData();
        fd.append('ajax_update', '1');
        fd.append('id',             tr.dataset.id);
        fd.append('datum',          tr.querySelector('.e_datum').value);
        fd.append('be_szamlaszam',  tr.querySelector('.e_be_szamlaszam').value);
        fd.append('szallito_id',    tr.querySelector('.e_szallito_id').value);
        fd.append('szallito_nev',   tr.querySelector('.e_szallito_nev').value);
        fd.append('megnevezes',     tr.querySelector('.e_megnevezes').value);
        fd.append('megjegyzes',     tr.querySelector('.e_megjegyzes').value);
        fd.append('netto_ar',       tr.querySelector('.e_netto_ar').value);
        fd.append('tipus',          tr.querySelector('.e_tipus').value);
        fd.append('statusz_id',     tr.querySelector('.e_statusz_id').value);
        fd.append('vevo',           tr.querySelector('.e_vevo').value);
        fd.append('eladas_datum',   tr.querySelector('.e_eladas_datum').value);
        fd.append('ki_szamlaszam',  tr.querySelector('.e_ki_szamlaszam').value);

        saveBtn.disabled = true; saveBtn.innerHTML = '&#8987;';

        fetch('termekek.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j.ok) {
                    alert(j.msg || 'Hiba mentés közben.');
                    saveBtn.disabled = false; saveBtn.innerHTML = '&#10003;';
                    return;
                }
                if (currentEdit && currentEdit.comboCleanup) currentEdit.comboCleanup();
                renderDisplayRow(tr, j.row);
                currentEdit = null;
                // Re-bind delete confirmation on the refreshed row.
                var del = tr.querySelector('.btn-delete');
                if (del) del.addEventListener('click', function(e) {
                    if (!confirm('Biztosan törlöd ezt a tételt?')) e.preventDefault();
                });
            })
            .catch(function() {
                alert('Hálózati hiba.');
                saveBtn.disabled = false; saveBtn.innerHTML = '&#10003;';
            });
    }

    // Delegated click handler — works for static, search, and inline-add rows.
    document.addEventListener('click', function(e) {
        var saveBtn = e.target.closest('.btn-edit-save');
        if (saveBtn) { e.preventDefault(); saveEdit(); return; }
        var cancelBtn = e.target.closest('.btn-edit-cancel');
        if (cancelBtn) { e.preventDefault(); cancelCurrentEdit(); return; }
        var editBtn = e.target.closest('.btn-edit');
        if (editBtn) { e.preventDefault(); startEdit(editBtn.closest('tr')); return; }
    });
})();
</script>

<style>
@keyframes fadeIn { from { opacity: 0; background: rgba(34,197,94,.08); } to { opacity: 1; background: transparent; } }
#inlineAddRow input:focus, #inlineAddRow select:focus { box-shadow: 0 0 0 2px rgba(59,130,246,.2); }
</style>

<?php include 'includes/footer.php'; ?>
