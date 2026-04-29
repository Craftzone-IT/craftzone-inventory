<?php
require_once 'config/db.php';
$page_title = 'Kategóriák – Raktárkészlet';
$db = getDB();

// Törlés
if (isset($_GET['torles']) && is_numeric($_GET['torles'])) {
    $db->prepare("DELETE FROM kategoriak WHERE id = ?")->execute([$_GET['torles']]);
    header('Location: kategoriak.php?uzenet=torolve');
    exit;
}

// Mentés
$hibak = [];
$szerkesztett = null;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $szerkesztett = $db->prepare("SELECT * FROM kategoriak WHERE id = ?");
    $szerkesztett->execute([$_GET['id']]);
    $szerkesztett = $szerkesztett->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nev = trim($_POST['nev'] ?? '');
    $leiras = trim($_POST['leiras'] ?? '');
    $edit_id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : null;

    if ($nev === '') $hibak[] = 'A kategória neve kötelező.';

    if (empty($hibak)) {
        if ($edit_id) {
            $db->prepare("UPDATE kategoriak SET nev=?, leiras=? WHERE id=?")->execute([$nev, $leiras, $edit_id]);
        } else {
            $db->prepare("INSERT INTO kategoriak (nev, leiras) VALUES (?, ?)")->execute([$nev, $leiras]);
        }
        header('Location: kategoriak.php?uzenet=mentve');
        exit;
    }
}

$kategoriak = $db->query("
    SELECT k.*, COUNT(t.id) AS termek_szam
    FROM kategoriak k
    LEFT JOIN termekek t ON t.kategoria_id = k.id
    GROUP BY k.id
    ORDER BY k.nev
")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#9783; Kategóriák</h1>
</div>

<?php if (isset($_GET['uzenet'])): ?>
<div class="flash flash-<?= $_GET['uzenet'] === 'torolve' ? 'error' : 'success' ?>">
    <?= $_GET['uzenet'] === 'mentve' ? 'Kategória sikeresen mentve.' : 'Kategória törölve.' ?>
</div>
<?php endif; ?>

<div class="two-col">
    <!-- Lista -->
    <div class="card">
        <table class="table">
            <thead>
                <tr><th>Név</th><th>Termékek</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($kategoriak)): ?>
                <tr><td colspan="3" class="empty-state">Még nincs kategória.</td></tr>
            <?php else: ?>
            <?php foreach ($kategoriak as $k): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($k['nev']) ?></strong>
                        <?php if ($k['leiras']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($k['leiras']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= $k['termek_szam'] ?> db</td>
                    <td class="actions">
                        <a href="?id=<?= $k['id'] ?>" class="btn btn-sm btn-secondary">Szerk.</a>
                        <?php if ($k['termek_szam'] == 0): ?>
                        <a href="?torles=<?= $k['id'] ?>" class="btn btn-sm btn-danger btn-delete">Törlés</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Űrlap -->
    <div class="card form-card">
        <h2><?= $szerkesztett ? 'Kategória szerkesztése' : 'Új kategória' ?></h2>
        <?php if ($hibak): ?>
        <div class="flash flash-error">
            <?php foreach ($hibak as $h): ?><div><?= htmlspecialchars($h) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <?php if ($szerkesztett): ?>
            <input type="hidden" name="id" value="<?= $szerkesztett['id'] ?>">
            <?php endif; ?>
            <div class="form-group">
                <label>Kategória neve *</label>
                <input type="text" name="nev" class="input" required
                    value="<?= htmlspecialchars($szerkesztett['nev'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Leírás</label>
                <textarea name="leiras" class="input" rows="2"><?= htmlspecialchars($szerkesztett['leiras'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Mentés</button>
                <?php if ($szerkesztett): ?>
                <a href="kategoriak.php" class="btn btn-ghost">Mégse</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
