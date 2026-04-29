<?php
require_once 'config/db.php';
$page_title = 'Új mozgás – Raktárkészlet';
$db = getDB();

$hibak = [];
$elovalasztott_termek = isset($_GET['termek']) && is_numeric($_GET['termek']) ? (int)$_GET['termek'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $termek_id  = isset($_POST['termek_id']) && is_numeric($_POST['termek_id']) ? (int)$_POST['termek_id'] : null;
    $tipus      = in_array($_POST['tipus'] ?? '', ['be', 'ki']) ? $_POST['tipus'] : null;
    $mennyiseg  = (int)($_POST['mennyiseg'] ?? 0);
    $egysegar   = str_replace(',', '.', $_POST['egysegar'] ?? 0);
    $megjegyzes = trim($_POST['megjegyzes'] ?? '');

    if (!$termek_id) $hibak[] = 'Termék megadása kötelező.';
    if (!$tipus) $hibak[] = 'Mozgás típusának megadása kötelező.';
    if ($mennyiseg <= 0) $hibak[] = 'A mennyiség pozitív szám kell legyen.';

    // Kivétnél ellenőrizzük a készletet
    if (empty($hibak) && $tipus === 'ki') {
        $keszlet = $db->prepare("SELECT aktualis_keszlet FROM termekek WHERE id = ?");
        $keszlet->execute([$termek_id]);
        $keszlet = $keszlet->fetchColumn();
        if ($keszlet < $mennyiseg) {
            $hibak[] = "Nincs elegendő készlet! (jelenlegi: $keszlet)";
        }
    }

    if (empty($hibak)) {
        $db->prepare("INSERT INTO mozgasok (termek_id, tipus, mennyiseg, egysegar, megjegyzes) VALUES (?,?,?,?,?)")
           ->execute([$termek_id, $tipus, $mennyiseg, $egysegar, $megjegyzes]);
        header('Location: mozgasok.php?uzenet=mentve');
        exit;
    }
}

$termekek = $db->query("SELECT id, cikkszam, nev, egyseg, aktualis_keszlet, ar FROM termekek ORDER BY nev")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#8644; Új raktármozgás</h1>
    <a href="mozgasok.php" class="btn btn-ghost">&larr; Vissza</a>
</div>

<?php if ($hibak): ?>
<div class="flash flash-error">
    <?php foreach ($hibak as $h): ?><div><?= htmlspecialchars($h) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card form-card form-card-narrow">
<form method="post" id="mozgasForm">
    <div class="form-group">
        <label>Termék *</label>
        <select name="termek_id" class="input" id="termekSelect" required>
            <option value="">– Válassz terméket –</option>
            <?php foreach ($termekek as $t): ?>
            <option value="<?= $t['id'] ?>"
                data-keszlet="<?= $t['aktualis_keszlet'] ?>"
                data-egyseg="<?= htmlspecialchars($t['egyseg']) ?>"
                data-ar="<?= $t['ar'] ?>"
                <?= $elovalasztott_termek == $t['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['nev']) ?> (<?= htmlspecialchars($t['cikkszam']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <div id="keszletInfo" class="form-hint"></div>
    </div>

    <div class="form-group">
        <label>Mozgás típusa *</label>
        <div class="radio-group">
            <label class="radio-label">
                <input type="radio" name="tipus" value="be" checked> Bevét (raktárba)
            </label>
            <label class="radio-label">
                <input type="radio" name="tipus" value="ki"> Kivét (raktárból)
            </label>
        </div>
    </div>

    <div class="form-group">
        <label>Mennyiség *</label>
        <div class="input-with-suffix">
            <input type="number" name="mennyiseg" id="mennyiseg" class="input" min="1" required value="1">
            <span class="input-suffix" id="egysegLabel">db</span>
        </div>
    </div>

    <div class="form-group">
        <label>Egységár (Ft) <small class="text-muted">– opcionális</small></label>
        <input type="number" name="egysegar" id="egysegar" class="input" min="0" step="0.01" value="">
        <div class="form-hint" id="osszegInfo"></div>
    </div>

    <div class="form-group">
        <label>Megjegyzés</label>
        <textarea name="megjegyzes" class="input" rows="2" placeholder="Pl. rendelés száma, hivatkozás..."></textarea>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Rögzítés</button>
        <a href="mozgasok.php" class="btn btn-ghost">Mégse</a>
    </div>
</form>
</div>

<script>
const termekSelect = document.getElementById('termekSelect');
const keszletInfo = document.getElementById('keszletInfo');
const egysegLabel = document.getElementById('egysegLabel');
const mennyisegInput = document.getElementById('mennyiseg');
const egysegarInput = document.getElementById('egysegar');
const osszegInfo = document.getElementById('osszegInfo');

function frissitInfo() {
    const opt = termekSelect.selectedOptions[0];
    if (!opt || !opt.value) {
        keszletInfo.textContent = '';
        return;
    }
    const keszlet = opt.dataset.keszlet;
    const egyseg = opt.dataset.egyseg;
    const ar = opt.dataset.ar;
    egysegLabel.textContent = egyseg;
    keszletInfo.textContent = `Jelenlegi készlet: ${keszlet} ${egyseg}`;
    if (!egysegarInput.value && ar > 0) egysegarInput.value = ar;
    frissitOsszeg();
}

function frissitOsszeg() {
    const m = parseInt(mennyisegInput.value) || 0;
    const e = parseFloat(egysegarInput.value) || 0;
    if (m > 0 && e > 0) {
        osszegInfo.textContent = `Összeg: ${(m * e).toLocaleString('hu-HU')} Ft`;
    } else {
        osszegInfo.textContent = '';
    }
}

termekSelect.addEventListener('change', frissitInfo);
mennyisegInput.addEventListener('input', frissitOsszeg);
egysegarInput.addEventListener('input', frissitOsszeg);
frissitInfo();
</script>

<?php include 'includes/footer.php'; ?>
