<?php
require_once 'config/db.php';
$page_title = 'Szállítók – Raktárkészlet';
$db = getDB();

// Törlés
if (isset($_GET['torles']) && is_numeric($_GET['torles'])) {
    $db->prepare("DELETE FROM szallitok WHERE id = ?")->execute([$_GET['torles']]);
    header('Location: szallitok.php?uzenet=torolve');
    exit;
}

$hibak = [];
$szerkesztett = null;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $szerkesztett = $db->prepare("SELECT * FROM szallitok WHERE id = ?");
    $szerkesztett->execute([$_GET['id']]);
    $szerkesztett = $szerkesztett->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adat = [
        'nev'             => trim($_POST['nev'] ?? ''),
        'kapcsolattarto'  => trim($_POST['kapcsolattarto'] ?? ''),
        'email'           => trim($_POST['email'] ?? ''),
        'telefon'         => trim($_POST['telefon'] ?? ''),
        'cim'             => trim($_POST['cim'] ?? ''),
        'megjegyzes'      => trim($_POST['megjegyzes'] ?? ''),
    ];
    $edit_id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : null;

    if ($adat['nev'] === '') $hibak[] = 'A szállító neve kötelező.';

    if (empty($hibak)) {
        if ($edit_id) {
            $db->prepare("UPDATE szallitok SET nev=?, kapcsolattarto=?, email=?, telefon=?, cim=?, megjegyzes=? WHERE id=?")
               ->execute([$adat['nev'], $adat['kapcsolattarto'], $adat['email'], $adat['telefon'], $adat['cim'], $adat['megjegyzes'], $edit_id]);
        } else {
            $db->prepare("INSERT INTO szallitok (nev, kapcsolattarto, email, telefon, cim, megjegyzes) VALUES (?,?,?,?,?,?)")
               ->execute([$adat['nev'], $adat['kapcsolattarto'], $adat['email'], $adat['telefon'], $adat['cim'], $adat['megjegyzes']]);
        }
        header('Location: szallitok.php?uzenet=mentve');
        exit;
    }
    $szerkesztett = array_merge($adat, ['id' => $edit_id]);
}

$szallitok = $db->query("
    SELECT s.*, COUNT(t.id) AS termek_szam
    FROM szallitok s
    LEFT JOIN termekek t ON t.szallito_id = s.id
    GROUP BY s.id
    ORDER BY s.nev
")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#9951; Szállítók</h1>
</div>

<?php if (isset($_GET['uzenet'])): ?>
<div class="flash flash-<?= $_GET['uzenet'] === 'torolve' ? 'error' : 'success' ?>">
    <?= $_GET['uzenet'] === 'mentve' ? 'Szállító sikeresen mentve.' : 'Szállító törölve.' ?>
</div>
<?php endif; ?>

<div class="two-col">
    <!-- Lista -->
    <div class="card">
        <?php if (empty($szallitok)): ?>
            <p class="empty-state">Még nincs szállító rögzítve.</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>Név</th><th>Kapcsolat</th><th>Termékek</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($szallitok as $s): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($s['nev']) ?></strong>
                        <?php if ($s['cim']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($s['cim']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['kapcsolattarto']): ?><div><?= htmlspecialchars($s['kapcsolattarto']) ?></div><?php endif; ?>
                        <?php if ($s['email']): ?><div><a href="mailto:<?= htmlspecialchars($s['email']) ?>"><?= htmlspecialchars($s['email']) ?></a></div><?php endif; ?>
                        <?php if ($s['telefon']): ?><div><?= htmlspecialchars($s['telefon']) ?></div><?php endif; ?>
                    </td>
                    <td><?= $s['termek_szam'] ?> db</td>
                    <td class="actions">
                        <a href="?id=<?= $s['id'] ?>" class="btn btn-sm btn-secondary">Szerk.</a>
                        <?php if ($s['termek_szam'] == 0): ?>
                        <a href="?torles=<?= $s['id'] ?>" class="btn btn-sm btn-danger btn-delete">Törlés</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Űrlap -->
    <div class="card form-card">
        <h2><?= $szerkesztett ? 'Szállító szerkesztése' : 'Új szállító' ?></h2>
        <?php if ($hibak): ?>
        <div class="flash flash-error">
            <?php foreach ($hibak as $h): ?><div><?= htmlspecialchars($h) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <?php if ($szerkesztett && isset($szerkesztett['id'])): ?>
            <input type="hidden" name="id" value="<?= $szerkesztett['id'] ?>">
            <?php endif; ?>
            <div class="form-group">
                <label>Cégnév *</label>
                <input type="text" name="nev" class="input" required value="<?= htmlspecialchars($szerkesztett['nev'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Kapcsolattartó</label>
                <input type="text" name="kapcsolattarto" class="input" value="<?= htmlspecialchars($szerkesztett['kapcsolattarto'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" class="input" value="<?= htmlspecialchars($szerkesztett['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Telefon</label>
                <input type="text" name="telefon" class="input" value="<?= htmlspecialchars($szerkesztett['telefon'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Cím</label>
                <input type="text" name="cim" class="input" value="<?= htmlspecialchars($szerkesztett['cim'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Megjegyzés</label>
                <textarea name="megjegyzes" class="input" rows="2"><?= htmlspecialchars($szerkesztett['megjegyzes'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Mentés</button>
                <?php if ($szerkesztett): ?>
                <a href="szallitok.php" class="btn btn-ghost">Mégse</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
