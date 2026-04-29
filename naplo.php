<?php
/**
 * Audit log viewer — shows all recorded user actions with optional filters.
 *
 * Every login, product change, config update, and user management event is
 * stored in the naplo table by logActivity(). This page lets admins and users
 * review who did what and when. Results are hard-capped at 500 rows; use the
 * date-range filters to narrow down large result sets.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Audit napló';
$db = getDB();

// Collect filter values from the query string (all optional).
$szuro_user   = $_GET['user']      ?? '';
$szuro_muv    = $_GET['muv']       ?? '';
$datum_tol    = $_GET['datum_tol'] ?? '';
$datum_ig     = $_GET['datum_ig']  ?? '';

// Build the WHERE clause dynamically — only add conditions for active filters.
$where  = [];
$params = [];

if ($szuro_user !== '') {
    $where[]  = "n.felhasznalo_id = ?";
    $params[] = $szuro_user;
}
if ($szuro_muv !== '') {
    $where[]  = "n.muvelet = ?";
    $params[] = $szuro_muv;
}
if ($datum_tol !== '') {
    $where[]  = "DATE(n.datum) >= ?";
    $params[] = $datum_tol;
}
if ($datum_ig !== '') {
    $where[]  = "DATE(n.datum) <= ?";
    $params[] = $datum_ig;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch log entries with the user's display name and the affected product's
// stock number. LIMIT 500 prevents accidental full-table dumps.
$naplo = $db->prepare("
    SELECT n.*, f.nev AS felhasznalo_nev, f.felhasznalonev,
           t.raktari_szam, t.megnevezes AS termek_nev
    FROM naplo n
    LEFT JOIN felhasznalok f ON n.felhasznalo_id = f.id
    LEFT JOIN termekek     t ON n.termek_id      = t.id
    $where_sql
    ORDER BY n.datum DESC
    LIMIT 500
");
$naplo->execute($params);
$naplo = $naplo->fetchAll();

// Populate the user and action-type filter dropdowns.
$felhasznalok = $db->query("SELECT id, nev FROM felhasznalok ORDER BY nev")->fetchAll();
// Distinct action types present in the log — dynamically built so the list
// stays accurate even when new action types are introduced.
$muveletek    = $db->query("SELECT DISTINCT muvelet FROM naplo ORDER BY muvelet")->fetchAll(PDO::FETCH_COLUMN);

// Maps action type strings to badge CSS classes for colour-coded display.
// Unknown types fall back to badge-gray via the null-coalescing operator below.
$muv_badge = [
    'belepes'                 => 'badge-blue',
    'kilepes'                 => 'badge-gray',
    'termek_felvetel'         => 'badge-green',
    'termek_modositas'        => 'badge-orange',
    'termek_torles'           => 'badge-red',
    'felhasznalo_letrehozas'  => 'badge-green',
    'felhasznalo_mod'         => 'badge-orange',
    'felhasznalo_torles'      => 'badge-red',
    'config_modositas'        => 'badge-blue',
    'telepites'               => 'badge-blue',
];

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#128203; Audit napló</h1>
</div>

<!-- Filter bar — all filters are optional and can be combined freely. -->
<form method="get" class="filter-bar filter-bar-wide" style="margin-bottom:16px">
    <select name="user" class="input" style="width:auto">
        <option value="">Minden felhasználó</option>
        <?php foreach ($felhasznalok as $f): ?>
        <option value="<?= $f['id'] ?>" <?= $szuro_user == $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nev']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="muv" class="input" style="width:auto">
        <option value="">Minden művelet</option>
        <?php foreach ($muveletek as $m): ?>
        <option value="<?= htmlspecialchars($m) ?>" <?= $szuro_muv === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="datum_tol" class="input" value="<?= htmlspecialchars($datum_tol) ?>" title="Dátumtól">
    <input type="date" name="datum_ig"  class="input" value="<?= htmlspecialchars($datum_ig) ?>"  title="Dátumig">
    <button type="submit" class="btn btn-secondary">Szűrés</button>
    <?php if ($szuro_user || $szuro_muv || $datum_tol || $datum_ig): ?>
    <a href="naplo.php" class="btn btn-ghost">Törlés</a>
    <?php endif; ?>
</form>

<div class="card">
    <?php if (empty($naplo)): ?>
        <p class="empty-state">Nincs napló bejegyzés.</p>
    <?php else: ?>
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Dátum</th>
                <th>Felhasználó</th>
                <th>Művelet</th>
                <th>Érintett tétel</th>
                <th>Részletek</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($naplo as $n): ?>
        <tr>
            <td class="text-muted" style="white-space:nowrap;font-size:12px"><?= date('Y.m.d H:i:s', strtotime($n['datum'])) ?></td>
            <td>
                <?= htmlspecialchars($n['felhasznalo_nev'] ?? '–') ?>
                <?php if ($n['felhasznalonev']): ?>
                <!-- Show the username below the display name for unambiguous identification. -->
                <br><small class="text-muted"><?= htmlspecialchars($n['felhasznalonev']) ?></small>
                <?php endif; ?>
            </td>
            <td>
                <!-- Colour-coded badge: green=create, orange=update, red=delete,
                     blue=auth/config, gray=other. -->
                <span class="badge <?= $muv_badge[$n['muvelet']] ?? 'badge-gray' ?>">
                    <?= htmlspecialchars($n['muvelet']) ?>
                </span>
            </td>
            <td>
                <?php if ($n['termek_id']): ?>
                <!-- Link to the product form so the reviewer can inspect the
                     current state of the affected item. -->
                <a href="termek_form.php?id=<?= $n['termek_id'] ?>">
                    <code><?= htmlspecialchars($n['raktari_szam'] ?? '#' . $n['termek_id']) ?></code>
                </a>
                <?php if ($n['termek_nev']): ?>
                <br><small class="text-muted"><?= htmlspecialchars(mb_substr($n['termek_nev'],0,30)) ?></small>
                <?php endif; ?>
                <?php else: ?>
                –
                <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:12px;max-width:300px"><?= htmlspecialchars($n['reszletek'] ?? '') ?></td>
            <td class="text-muted" style="font-size:11px"><?= htmlspecialchars($n['ip'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <!-- Warn the user if the 500-row cap was hit — they may need to narrow
         the date range to see all entries for a given period. -->
    <p class="table-count"><?= count($naplo) ?> bejegyzés <?= count($naplo) >= 500 ? '(limit: 500)' : '' ?></p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
