<?php
require_once 'config/db.php';
$page_title = 'Raktármozgások – Raktárkészlet';
$db = getDB();

// Szűrés
$tipus_szuro = $_GET['tipus'] ?? '';
$termek_szuro = $_GET['termek'] ?? '';
$datum_tol = $_GET['datum_tol'] ?? '';
$datum_ig = $_GET['datum_ig'] ?? '';

$where = [];
$params = [];

if ($tipus_szuro !== '') {
    $where[] = "m.tipus = ?";
    $params[] = $tipus_szuro;
}
if ($termek_szuro !== '') {
    $where[] = "m.termek_id = ?";
    $params[] = $termek_szuro;
}
if ($datum_tol !== '') {
    $where[] = "DATE(m.datum) >= ?";
    $params[] = $datum_tol;
}
if ($datum_ig !== '') {
    $where[] = "DATE(m.datum) <= ?";
    $params[] = $datum_ig;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$mozgasok = $db->prepare("
    SELECT m.*, t.nev AS termek_nev, t.cikkszam, t.egyseg
    FROM mozgasok m
    JOIN termekek t ON m.termek_id = t.id
    $where_sql
    ORDER BY m.datum DESC
    LIMIT 200
");
$mozgasok->execute($params);
$mozgasok = $mozgasok->fetchAll();

$termekek = $db->query("SELECT id, nev, cikkszam FROM termekek ORDER BY nev")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#8644; Raktármozgások</h1>
    <a href="mozgas_form.php" class="btn btn-primary">+ Új mozgás</a>
</div>

<?php if (isset($_GET['uzenet'])): ?>
<div class="flash flash-success">Mozgás sikeresen rögzítve.</div>
<?php endif; ?>

<!-- Szűrő -->
<form method="get" class="filter-bar filter-bar-wide">
    <select name="tipus" class="input">
        <option value="">Minden típus</option>
        <option value="be" <?= $tipus_szuro === 'be' ? 'selected' : '' ?>>Bevét</option>
        <option value="ki" <?= $tipus_szuro === 'ki' ? 'selected' : '' ?>>Kivét</option>
    </select>
    <select name="termek" class="input">
        <option value="">Minden termék</option>
        <?php foreach ($termekek as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $termek_szuro == $t['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['nev']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="datum_tol" class="input" value="<?= htmlspecialchars($datum_tol) ?>" title="Dátumtól">
    <input type="date" name="datum_ig" class="input" value="<?= htmlspecialchars($datum_ig) ?>" title="Dátumig">
    <button type="submit" class="btn btn-secondary">Szűrés</button>
    <?php if ($tipus_szuro || $termek_szuro || $datum_tol || $datum_ig): ?>
    <a href="mozgasok.php" class="btn btn-ghost">Törlés</a>
    <?php endif; ?>
</form>

<div class="card">
    <?php if (empty($mozgasok)): ?>
        <p class="empty-state">Nincs találat a szűrési feltételekre.</p>
    <?php else: ?>
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Dátum</th>
                <th>Termék</th>
                <th>Típus</th>
                <th>Mennyiség</th>
                <th>Egységár</th>
                <th>Összeg</th>
                <th>Megjegyzés</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($mozgasok as $m): ?>
            <tr>
                <td class="text-muted"><?= date('Y.m.d H:i', strtotime($m['datum'])) ?></td>
                <td>
                    <strong><?= htmlspecialchars($m['termek_nev']) ?></strong><br>
                    <small class="text-muted"><?= htmlspecialchars($m['cikkszam']) ?></small>
                </td>
                <td>
                    <span class="badge <?= $m['tipus'] === 'be' ? 'badge-green' : 'badge-red' ?>">
                        <?= $m['tipus'] === 'be' ? '&#8599; Bevét' : '&#8600; Kivét' ?>
                    </span>
                </td>
                <td><strong><?= $m['mennyiseg'] ?></strong> <?= htmlspecialchars($m['egyseg']) ?></td>
                <td><?= $m['egysegar'] > 0 ? number_format($m['egysegar'], 0, ',', ' ') . ' Ft' : '–' ?></td>
                <td><?= $m['egysegar'] > 0 ? number_format($m['egysegar'] * $m['mennyiseg'], 0, ',', ' ') . ' Ft' : '–' ?></td>
                <td class="text-muted"><?= htmlspecialchars($m['megjegyzes'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="table-count"><?= count($mozgasok) ?> bejegyzés</p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
