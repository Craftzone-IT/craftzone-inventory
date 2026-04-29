<?php
/**
 * Product add / edit form.
 *
 * When accessed without an ?id parameter, the form is in "new product" mode:
 * it pre-generates the next sequential stock number and inserts a new row on
 * submit. When ?id=<N> is present, the form loads the existing record and
 * updates it on submit.
 *
 * The edit sidebar also shows the last 20 audit log entries for the product so
 * the user can see who changed what and when without leaving the form.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/termek_service.php';
requireLogin();

$db  = getDB();
$id  = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
$termek = null;
$hibak  = [];

// Load the existing product record (edit mode). Redirect to the list if the
// requested ID does not exist, rather than showing a blank form.
if ($id) {
    $s = $db->prepare("SELECT * FROM termekek WHERE id=?");
    $s->execute([$id]);
    $termek = $s->fetch();
    if (!$termek) { header('Location: termekek.php'); exit; }
}

$page_title = $id ? 'Tétel szerkesztése' : 'Új tétel felvitele';

// Load dropdown option lists (only active entries are shown to users).
$tipus_opcio  = $db->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='tipus' AND aktiv=1 ORDER BY sorrend")->fetchAll(PDO::FETCH_COLUMN);
$spec_opcio   = $db->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='spec'  AND aktiv=1 ORDER BY sorrend")->fetchAll(PDO::FETCH_COLUMN);
$szallitok    = $db->query("SELECT id, nev FROM szallitok WHERE aktiv=1 ORDER BY nev")->fetchAll();
$statusz_lista = $db->query("SELECT id, nev, szin FROM statuszok ORDER BY sorrend")->fetchAll();

// Pre-generate the stock number for display in the read-only field. On edit
// we show the existing number; on create we reserve the next one.
$kovetkezo_szam = $id ? ($termek['raktari_szam'] ?? '') : generateRaktariSzam($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form fields into the format expected by the service layer.
    // The service handles normalisation, supplier resolution, and validation.
    $adat = [
        'datum'          => $_POST['datum']          ?? '',
        'be_szamlaszam'  => $_POST['be_szamlaszam']  ?? '',
        'szallito_id'    => $_POST['szallito_id']     ?? '',
        'szallito_nev'   => $_POST['szallito_nev_input'] ?? '',
        'megnevezes'     => $_POST['megnevezes']      ?? '',
        'netto_ar'       => $_POST['netto_ar']        ?? '',
        'tipus'          => $_POST['tipus']           ?? '',
        'spec'           => $_POST['spec']            ?? '',
        'megjegyzes'     => $_POST['megjegyzes']      ?? '',
        'statusz_id'     => $_POST['statusz_id']      ?? 1,
        'vevo'           => $_POST['vevo']            ?? '',
        'eladas_datum'   => $_POST['eladas_datum']    ?? '',
        'ki_szamlaszam'  => $_POST['ki_szamlaszam']   ?? '',
        'archivalható'   => isset($_POST['archivalható']) ? 1 : 0,
        'ellenorzott'    => isset($_POST['ellenorzott'])  ? 1 : 0,
        'leltar'         => isset($_POST['leltar'])       ? 1 : 0,
    ];

    $user = currentUser();
    if ($id) {
        $result = updateTermek($db, $id, $adat, $user['id']);
    } else {
        $result = createTermek($db, $adat, $user['id']);
    }

    if ($result['ok']) {
        header('Location: termekek.php?uzenet=mentve');
        exit;
    }

    $hibak = $result['hibak'];

    // On validation failure, merge POST values over the original record so the
    // form re-renders with the user's input instead of the database values.
    $normalized = normalizeTermekData($adat);
    $termek = array_merge($termek ?? [], $normalized);
}

// Fetch the last 20 audit log entries for this product to display in the
// edit sidebar. Only loaded in edit mode (not for new products).
$elozmenyek = [];
if ($id) {
    $elozmenyek = $db->prepare("
        SELECT n.datum, n.muvelet, n.reszletek, f.nev
        FROM naplo n LEFT JOIN felhasznalok f ON n.felhasznalo_id = f.id
        WHERE n.termek_id = ? ORDER BY n.datum DESC LIMIT 20
    ");
    $elozmenyek->execute([$id]);
    $elozmenyek = $elozmenyek->fetchAll();
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1><?= $id ? 'Tétel szerkesztése' : 'Új tétel felvitele' ?></h1>
    <a href="termekek.php" class="btn btn-ghost">&larr; Vissza</a>
</div>

<?php if ($hibak): ?>
<div class="flash flash-error">
    <?php foreach ($hibak as $h): ?><div><?= htmlspecialchars($h) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="two-col-form">
<div class="card form-card">
<form method="post">

    <!-- Identification section — stock number is read-only, generated server-side. -->
    <div class="form-section-title">Azonosítás</div>
    <div class="form-grid-3">
        <div class="form-group">
            <label>Raktári szám</label>
            <input type="text" class="input input-readonly" value="<?= htmlspecialchars($kovetkezo_szam) ?>" readonly>
            <?php if (!$id): ?><div class="form-hint">Automatikusan generált.</div><?php endif; ?>
        </div>
        <div class="form-group">
            <label>Dátum (bevételezés)</label>
            <input type="date" name="datum" class="input"
                value="<?= htmlspecialchars($termek['datum'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group">
            <label>Bejövő számlaszám</label>
            <input type="text" name="be_szamlaszam" class="input"
                value="<?= htmlspecialchars($termek['be_szamlaszam'] ?? '') ?>">
        </div>
    </div>

    <!-- Product details section. -->
    <div class="form-section-title">Termék adatok</div>
    <div class="form-grid-3">
        <div class="form-group form-span-2">
            <label>Megnevezés *</label>
            <input type="text" name="megnevezes" class="input" required
                value="<?= htmlspecialchars($termek['megnevezes'] ?? '') ?>">
        </div>
        <?php
        // Resolve the currently selected supplier name for the combobox display.
        $currentSzallitoNev = '';
        $currentSzallitoId  = $termek['szallito_id'] ?? '';
        if ($currentSzallitoId !== '' && $currentSzallitoId !== null) {
            foreach ($szallitok as $s) {
                if ((int)$s['id'] === (int)$currentSzallitoId) {
                    $currentSzallitoNev = $s['nev'];
                    break;
                }
            }
        }
        ?>
        <div class="form-group">
            <label>Szállító</label>
            <div class="combobox <?= $currentSzallitoNev ? 'has-value' : '' ?>" id="szallitoCombobox">
                <input type="hidden" name="szallito_id" id="szallitoId"
                    value="<?= htmlspecialchars($currentSzallitoId) ?>">
                <input type="hidden" name="szallito_nev_input" id="szallitoNevHidden"
                    value="<?= htmlspecialchars($currentSzallitoNev) ?>">
                <div class="combobox-input-wrap">
                    <span class="combobox-icon">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    </span>
                    <input type="text" id="szallitoInput" class="input combobox-input"
                        placeholder="Keresés vagy új szállító…" autocomplete="off"
                        value="<?= htmlspecialchars($currentSzallitoNev) ?>">
                    <button type="button" class="combobox-clear" id="szallitoClear" title="Törlés">&times;</button>
                </div>
                <div class="combobox-dropdown" id="szallitoDropdown"></div>
            </div>
        </div>
        <div class="form-group">
            <label>Típus</label>
            <select name="tipus" class="input">
                <option value="">– Nincs –</option>
                <?php foreach ($tipus_opcio as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= ($termek['tipus'] ?? '') === $o ? 'selected' : '' ?>>
                    <?= htmlspecialchars($o) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Spec.</label>
            <select name="spec" class="input">
                <option value="">– Nincs –</option>
                <?php foreach ($spec_opcio as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= ($termek['spec'] ?? '') === $o ? 'selected' : '' ?>>
                    <?= htmlspecialchars($o) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Nettó beszer. ár (Ft)</label>
            <input type="number" name="netto_ar" class="input" step="0.01" min="0"
                value="<?= htmlspecialchars($termek['netto_ar'] ?? '') ?>">
        </div>
        <div class="form-group form-span-full">
            <label>Megjegyzés</label>
            <textarea name="megjegyzes" class="input" rows="2"><?= htmlspecialchars($termek['megjegyzes'] ?? '') ?></textarea>
        </div>
    </div>

    <!-- Status section -->
    <div class="form-section-title">Státusz</div>
    <div class="form-grid-3">
        <div class="form-group">
            <label>Státusz</label>
            <select name="statusz_id" class="input">
                <?php foreach ($statusz_lista as $st): ?>
                <option value="<?= $st['id'] ?>"
                    <?= ($termek['statusz_id'] ?? 1) == $st['id'] ? 'selected' : '' ?>
                    data-szin="<?= htmlspecialchars($st['szin']) ?>">
                    <?= htmlspecialchars($st['nev']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Sale data section — filled when the item has been sold. -->
    <div class="form-section-title">Eladás adatok</div>
    <div class="form-grid-3">
        <div class="form-group form-span-2">
            <label>Vevő</label>
            <input type="text" name="vevo" class="input"
                value="<?= htmlspecialchars($termek['vevo'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Eladás dátuma</label>
            <input type="date" name="eladas_datum" class="input"
                value="<?= htmlspecialchars($termek['eladas_datum'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Kimenő számlaszám</label>
            <input type="text" name="ki_szamlaszam" class="input"
                value="<?= htmlspecialchars($termek['ki_szamlaszam'] ?? '') ?>">
        </div>
    </div>

    <!-- Boolean flags section — quick status markers used for filtering. -->
    <div class="form-section-title">Jelzők</div>
    <div class="checkbox-row">
        <label class="checkbox-label">
            <input type="checkbox" name="archivalható" value="1" <?= !empty($termek['archivalható']) ? 'checked' : '' ?>>
            Archiválható
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="ellenorzott" value="1" <?= !empty($termek['ellenorzott']) ? 'checked' : '' ?>>
            Ellenőrzött (Ell.)
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="leltar" value="1" <?= !empty($termek['leltar']) ? 'checked' : '' ?>>
            Leltár
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Mentés</button>
        <a href="termekek.php" class="btn btn-ghost">Mégse</a>
        <?php if ($id && $termek): ?>
        <!-- Show creation and last-modification metadata in small text at the
             right side of the action bar so it is visible but not prominent. -->
        <span class="text-muted" style="font-size:11px;margin-left:auto">
            Felvitte: <?php
                $f = $db->prepare("SELECT nev FROM felhasznalok WHERE id=?");
                $f->execute([$termek['letrehozta']]);
                echo htmlspecialchars($f->fetchColumn() ?: '–');
            ?> &mdash; <?= $termek['letrehozva'] ? date('Y.m.d H:i', strtotime($termek['letrehozva'])) : '' ?>
            <?php if ($termek['modositotta']): ?>
            <br>Módosította: <?php
                $m = $db->prepare("SELECT nev FROM felhasznalok WHERE id=?");
                $m->execute([$termek['modositotta']]);
                echo htmlspecialchars($m->fetchColumn() ?: '–');
            ?> &mdash; <?= $termek['modositva'] ? date('Y.m.d H:i', strtotime($termek['modositva'])) : '' ?>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
</form>
</div>

<?php if ($id && !empty($elozmenyek)): ?>
<!-- Change history sidebar — shows up to 20 audit log entries for this product. -->
<div>
    <div class="card">
        <div class="card-header"><h2>&#128203; Előzmények</h2></div>
        <table class="table">
            <thead><tr><th>Dátum</th><th>Művelet</th><th>Felhasználó</th><th>Részletek</th></tr></thead>
            <tbody>
            <?php foreach ($elozmenyek as $e): ?>
            <tr>
                <td class="text-muted" style="white-space:nowrap"><?= date('Y.m.d H:i', strtotime($e['datum'])) ?></td>
                <td><span class="badge badge-gray"><?= htmlspecialchars($e['muvelet']) ?></span></td>
                <td><?= htmlspecialchars($e['nev'] ?? '–') ?></td>
                <td class="text-muted"><?= htmlspecialchars($e['reszletek'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
</div>

<script>
/* ── Szállító combobox ─────────────────────────────────────────────────────
   Searchable dropdown with free-text entry for adding new suppliers inline.
   The supplier list is passed as JSON from PHP. When the user types a name
   that is not in the list, a "create new" option appears. The selected ID
   (or empty for new) is stored in a hidden input; the typed name goes into
   a second hidden input so PHP can create the supplier on save.            */
(function () {
    var suppliers = <?= json_encode(array_map(function($s) {
        return ['id' => (int)$s['id'], 'nev' => $s['nev']];
    }, $szallitok), JSON_UNESCAPED_UNICODE) ?>;

    var wrap       = document.getElementById('szallitoCombobox');
    var input      = document.getElementById('szallitoInput');
    var hiddenId   = document.getElementById('szallitoId');
    var hiddenName = document.getElementById('szallitoNevHidden');
    var dropdown   = document.getElementById('szallitoDropdown');
    var clearBtn   = document.getElementById('szallitoClear');
    if (!wrap || !input) return;

    var highlighted = -1; // index in the currently visible filtered list
    var filtered    = []; // current filtered results

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function markMatch(text, query) {
        if (!query) return esc(text);
        var re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
        return esc(text).replace(re, '<mark>$1</mark>');
    }

    function render(query) {
        var q = (query || '').trim().toLowerCase();
        filtered = suppliers.filter(function(s) {
            return !q || s.nev.toLowerCase().indexOf(q) !== -1;
        });

        var html = '';
        if (filtered.length === 0 && !q) {
            html = '<div class="combobox-empty">Nincs szállító a listában.</div>';
        } else {
            filtered.forEach(function(s, i) {
                var sel = (String(s.id) === hiddenId.value) ? ' selected' : '';
                var hl  = (i === highlighted) ? ' highlighted' : '';
                html += '<div class="combobox-item' + sel + hl + '" data-id="' + s.id + '" data-name="' + esc(s.nev) + '" data-index="' + i + '">'
                      + markMatch(s.nev, query) + '</div>';
            });
        }

        // Show "create new" option when typed text doesn't exactly match any supplier.
        var exactMatch = suppliers.some(function(s) { return s.nev.toLowerCase() === q; });
        if (q && !exactMatch) {
            html += '<div class="combobox-new" id="comboNewItem">+ Új szállító: <strong>' + esc(query.trim()) + '</strong></div>';
        }

        dropdown.innerHTML = html;
    }

    function openDropdown() {
        highlighted = -1;
        render(input.value);
        wrap.classList.add('open');
    }

    function closeDropdown() {
        wrap.classList.remove('open');
        highlighted = -1;
    }

    function selectSupplier(id, name) {
        hiddenId.value   = id;
        hiddenName.value = name;
        input.value      = name;
        wrap.classList.toggle('has-value', !!name);
        closeDropdown();
    }

    function selectNew(name) {
        hiddenId.value   = '';
        hiddenName.value = name;
        input.value      = name;
        wrap.classList.toggle('has-value', !!name);
        closeDropdown();
    }

    // ── Event listeners ──────────────────────────────────────────────────────

    input.addEventListener('focus', openDropdown);

    input.addEventListener('input', function() {
        highlighted = -1;
        render(this.value);
        wrap.classList.add('open');
        // Update hidden fields live: if typed text matches an existing supplier
        // exactly, set the ID; otherwise clear it for "new" mode.
        var q = this.value.trim().toLowerCase();
        var match = suppliers.find(function(s) { return s.nev.toLowerCase() === q; });
        if (match) {
            hiddenId.value   = match.id;
            hiddenName.value = match.nev;
        } else {
            hiddenId.value   = '';
            hiddenName.value = this.value.trim();
        }
        wrap.classList.toggle('has-value', !!this.value.trim());
    });

    input.addEventListener('keydown', function(e) {
        var items = dropdown.querySelectorAll('.combobox-item');
        var newItem = document.getElementById('comboNewItem');
        var totalItems = items.length + (newItem ? 1 : 0);

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlighted = Math.min(highlighted + 1, totalItems - 1);
            updateHighlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlighted = Math.max(highlighted - 1, -1);
            updateHighlight();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlighted >= 0 && highlighted < items.length) {
                var item = items[highlighted];
                selectSupplier(item.dataset.id, item.dataset.name);
            } else if (highlighted === items.length && newItem) {
                selectNew(input.value.trim());
            } else if (input.value.trim()) {
                // Enter without highlighting — accept typed text
                var q = input.value.trim().toLowerCase();
                var match = suppliers.find(function(s) { return s.nev.toLowerCase() === q; });
                if (match) selectSupplier(match.id, match.nev);
                else selectNew(input.value.trim());
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
            input.blur();
        } else if (e.key === 'Tab') {
            closeDropdown();
        }
    });

    function updateHighlight() {
        dropdown.querySelectorAll('.combobox-item').forEach(function(el, i) {
            el.classList.toggle('highlighted', i === highlighted);
        });
        var newItem = document.getElementById('comboNewItem');
        var items = dropdown.querySelectorAll('.combobox-item');
        if (newItem) {
            newItem.classList.toggle('highlighted', highlighted === items.length);
        }
        // Scroll highlighted item into view.
        var hlEl = dropdown.querySelector('.highlighted');
        if (hlEl) hlEl.scrollIntoView({ block: 'nearest' });
    }

    // Delegate click on dropdown items.
    dropdown.addEventListener('mousedown', function(e) {
        // Prevent blur from firing before click is registered.
        e.preventDefault();
    });
    dropdown.addEventListener('click', function(e) {
        var item = e.target.closest('.combobox-item');
        var newItem = e.target.closest('.combobox-new');
        if (item) {
            selectSupplier(item.dataset.id, item.dataset.name);
            input.focus();
        } else if (newItem) {
            selectNew(input.value.trim());
            input.focus();
        }
    });

    // Close when clicking outside the combobox.
    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) closeDropdown();
    });

    // Clear button.
    clearBtn.addEventListener('click', function() {
        selectSupplier('', '');
        input.value = '';
        input.focus();
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
