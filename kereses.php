<?php
/**
 * Advanced search page — supports free-text and field-specific filtering.
 *
 * Unlike the quick-search on the product list (which is limited to the current
 * page), this page queries the full database with up to 12 combined criteria.
 * Results are capped at 300 rows; users are warned when the cap is reached so
 * they know to narrow their filters. Free-text matches are highlighted with
 * <mark> tags in the name column.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Keresés';
$db = getDB();

// Load dropdown option lists for the filter form (only active entries shown).
$tipus_opcio  = $db->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='tipus' AND aktiv=1 ORDER BY sorrend")->fetchAll(PDO::FETCH_COLUMN);
$spec_opcio   = $db->query("SELECT ertek FROM opcio_csoportok WHERE kulcs='spec'  AND aktiv=1 ORDER BY sorrend")->fetchAll(PDO::FETCH_COLUMN);
$szallitok    = $db->query("SELECT id, nev FROM szallitok WHERE aktiv=1 ORDER BY nev")->fetchAll();

// Read all filter values from the query string (all optional).
$kereses      = trim($_GET['q']            ?? '');
$tipus        = $_GET['tipus']             ?? '';
$spec         = $_GET['spec']              ?? '';
$szallito     = $_GET['szallito']          ?? '';
$datum_tol    = $_GET['datum_tol']         ?? '';
$datum_ig     = $_GET['datum_ig']          ?? '';
$el_datum_tol = $_GET['el_datum_tol']      ?? '';
$el_datum_ig  = $_GET['el_datum_ig']       ?? '';
$allapot      = $_GET['allapot']           ?? '';
$archivalható = $_GET['archivalható']      ?? '';
$ellenorzott  = $_GET['ellenorzott']       ?? '';
$leltar       = $_GET['leltar']            ?? '';

// null means the form was never submitted; empty array means submitted but
// no results found. The template uses this distinction to avoid showing the
// results table before any search has been run.
$eredmenyek = null;
$van_szuro  = $kereses || $tipus || $spec || $szallito || $datum_tol || $datum_ig
           || $el_datum_tol || $el_datum_ig || $allapot || $archivalható !== ''
           || $ellenorzott !== '' || $leltar !== '';

if ($van_szuro) {
    $where  = [];
    $params = [];

    // Free-text: searches the six most human-readable identifying fields.
    if ($kereses !== '') {
        $where[]  = "(t.raktari_szam LIKE ? OR t.megnevezes LIKE ? OR t.be_szamlaszam LIKE ?
                      OR t.ki_szamlaszam LIKE ? OR t.vevo LIKE ? OR t.megjegyzes LIKE ?)";
        $k = "%$kereses%";
        array_push($params, $k, $k, $k, $k, $k, $k);
    }

    // Exact-match dropdown filters.
    if ($tipus !== '')      { $where[] = "t.tipus = ?";           $params[] = $tipus; }
    if ($spec !== '')       { $where[] = "t.spec = ?";            $params[] = $spec; }
    if ($szallito !== '')   { $where[] = "t.szallito_id = ?";     $params[] = $szallito; }

    // Date-range filters use >= / <= so partial dates like "2024-01-01" work correctly.
    if ($datum_tol !== '')  { $where[] = "t.datum >= ?";          $params[] = $datum_tol; }
    if ($datum_ig !== '')   { $where[] = "t.datum <= ?";          $params[] = $datum_ig; }
    if ($el_datum_tol!=='') { $where[] = "t.eladas_datum >= ?";   $params[] = $el_datum_tol; }
    if ($el_datum_ig !=='') { $where[] = "t.eladas_datum <= ?";   $params[] = $el_datum_ig; }

    // Stock status is determined by statusz_id.
    if ($allapot !== '' && is_numeric($allapot)) {
        $where[] = "t.statusz_id = ?"; $params[] = (int)$allapot;
    }

    // Boolean flag filters — checkbox values arrive as '1'; absence means unchecked.
    if ($archivalható !== '') { $where[] = "t.archivalható = ?";  $params[] = (int)$archivalható; }
    if ($ellenorzott !== '')  { $where[] = "t.ellenorzott = ?";   $params[] = (int)$ellenorzott; }
    if ($leltar !== '')       { $where[] = "t.leltar = ?";        $params[] = (int)$leltar; }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Cap at 300 to prevent accidental full-table exports; the template warns
    // the user when this limit is hit.
    $stmt = $db->prepare("
        SELECT t.*, s.nev AS szallito_nev, f.nev AS felvitte,
               st.nev AS statusz_nev, st.szin AS statusz_szin
        FROM termekek t
        LEFT JOIN statuszok   st ON t.statusz_id  = st.id
        LEFT JOIN szallitok    s ON t.szallito_id = s.id
        LEFT JOIN felhasznalok f ON t.letrehozta  = f.id
        $where_sql
        ORDER BY t.letrehozva DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $eredmenyek = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#128269; Fejlett keresés</h1>
</div>

<div class="card form-card" style="margin-bottom:20px">
<form method="get" id="searchForm">
    <div class="form-grid-3">
        <div class="form-group form-span-full">
            <label>Szabad szavas keresés</label>
            <input type="text" name="q" class="input" autofocus
                placeholder="Raktári szám, megnevezés, számlaszám, vevő, megjegyzés..."
                value="<?= htmlspecialchars($kereses) ?>">
            <div class="form-hint">Keres a következő mezőkben: raktári szám, megnevezés, bejövő/kimenő számlaszám, vevő, megjegyzés.</div>
        </div>

        <div class="form-group">
            <label>Típus</label>
            <select name="tipus" class="input">
                <option value="">Minden</option>
                <?php foreach ($tipus_opcio as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= $tipus === $o ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Spec.</label>
            <select name="spec" class="input">
                <option value="">Minden</option>
                <?php foreach ($spec_opcio as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= $spec === $o ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        $keresSzallitoNev = '';
        if ($szallito !== '') {
            foreach ($szallitok as $s) {
                if ((int)$s['id'] === (int)$szallito) { $keresSzallitoNev = $s['nev']; break; }
            }
        }
        ?>
        <div class="form-group">
            <label>Szállító</label>
            <div class="combobox <?= $keresSzallitoNev ? 'has-value' : '' ?>" id="szallitoComboboxK">
                <input type="hidden" name="szallito" id="szallitoIdK" value="<?= htmlspecialchars($szallito) ?>">
                <div class="combobox-input-wrap">
                    <span class="combobox-icon">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    </span>
                    <input type="text" id="szallitoInputK" class="input combobox-input"
                        placeholder="Szállító keresése…" autocomplete="off"
                        value="<?= htmlspecialchars($keresSzallitoNev) ?>">
                    <button type="button" class="combobox-clear" id="szallitoClearK" title="Törlés">&times;</button>
                </div>
                <div class="combobox-dropdown" id="szallitoDropdownK"></div>
            </div>
        </div>

        <div class="form-group">
            <label>Bevételezés dátuma (tól)</label>
            <input type="date" name="datum_tol" class="input" value="<?= htmlspecialchars($datum_tol) ?>">
        </div>
        <div class="form-group">
            <label>Bevételezés dátuma (ig)</label>
            <input type="date" name="datum_ig" class="input" value="<?= htmlspecialchars($datum_ig) ?>">
        </div>
        <div class="form-group">
            <label>Státusz</label>
            <select name="allapot" class="input">
                <option value="">Minden</option>
                <?php
                $statusz_lista_k = $db->query("SELECT id, nev FROM statuszok ORDER BY sorrend")->fetchAll();
                foreach ($statusz_lista_k as $st): ?>
                <option value="<?= $st['id'] ?>" <?= $allapot == $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['nev']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Eladás dátuma (tól)</label>
            <input type="date" name="el_datum_tol" class="input" value="<?= htmlspecialchars($el_datum_tol) ?>">
        </div>
        <div class="form-group">
            <label>Eladás dátuma (ig)</label>
            <input type="date" name="el_datum_ig" class="input" value="<?= htmlspecialchars($el_datum_ig) ?>">
        </div>
        <div class="form-group">
            <label>Jelzők</label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;padding-top:6px">
                <label class="checkbox-label">
                    <input type="checkbox" name="archivalható" value="1" <?= $archivalható === '1' ? 'checked' : '' ?>> Archiválható
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="ellenorzott" value="1" <?= $ellenorzott === '1' ? 'checked' : '' ?>> Ellenőrzött
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="leltar" value="1" <?= $leltar === '1' ? 'checked' : '' ?>> Leltár
                </label>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">&#128269; Keresés</button>
        <a href="kereses.php" class="btn btn-ghost">Szűrők törlése</a>
        <?php if ($eredmenyek !== null): ?>
        <span class="text-muted" style="margin-left:auto;font-size:13px"><?= count($eredmenyek) ?> találat</span>
        <?php endif; ?>
    </div>
</form>
</div>

<?php if ($eredmenyek !== null): ?>
<div class="card">
    <?php if (empty($eredmenyek)): ?>
        <p class="empty-state">Nincs találat a megadott feltételekre.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table table-hover">
        <thead>
            <tr>
                <th>Raktári szám</th>
                <th>Dátum</th>
                <th>Bej. számlaszám</th>
                <th>Szállító</th>
                <th>Megnevezés</th>
                <th>Nettó ár</th>
                <th>Típus</th>
                <th>Spec.</th>
                <th>Státusz</th>
                <th>Vevő</th>
                <th>El. dátum</th>
                <th>Ki. számlaszám</th>
                <th>Arch.</th><th>Ell.</th><th>Lelt.</th>
                <th>Felvitte</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($eredmenyek as $t): ?>
        <tr class="<?= ($t['statusz_szin'] ?? '') === 'red' ? 'row-sold' : '' ?>">
            <td><code><?= htmlspecialchars($t['raktari_szam']) ?></code></td>
            <td><?= $t['datum'] ? date('Y.m.d', strtotime($t['datum'])) : '–' ?></td>
            <td><?= htmlspecialchars($t['be_szamlaszam'] ?? '–') ?></td>
            <td><?= htmlspecialchars($t['szallito_nev'] ?? '–') ?></td>
            <td>
                <strong><?php
                    $nev = htmlspecialchars($t['megnevezes']);
                    if ($kereses) {
                        $nev = preg_replace('/(' . preg_quote(htmlspecialchars($kereses), '/') . ')/i', '<mark>$1</mark>', $nev);
                    }
                    echo $nev;
                ?></strong>
            </td>
            <td><?= $t['netto_ar'] !== null ? number_format($t['netto_ar'], 0, ',', ' ') . ' Ft' : '–' ?></td>
            <td><?= htmlspecialchars($t['tipus'] ?? '–') ?></td>
            <td><?= htmlspecialchars($t['spec'] ?? '–') ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($t['statusz_szin'] ?? 'gray') ?>"><?= htmlspecialchars($t['statusz_nev'] ?? '–') ?></span></td>
            <td><?= htmlspecialchars($t['vevo'] ?? '–') ?></td>
            <td><?= $t['eladas_datum'] ? date('Y.m.d', strtotime($t['eladas_datum'])) : '–' ?></td>
            <td><?= htmlspecialchars($t['ki_szamlaszam'] ?? '–') ?></td>
            <td style="text-align:center"><?= $t['archivalható'] ? '<span class="badge badge-green">I</span>'  : '–' ?></td>
            <td style="text-align:center"><?= $t['ellenorzott']  ? '<span class="badge badge-blue">I</span>'   : '–' ?></td>
            <td style="text-align:center"><?= $t['leltar']       ? '<span class="badge badge-orange">I</span>' : '–' ?></td>
            <td class="text-muted" style="font-size:11px"><?= htmlspecialchars($t['felvitte'] ?? '–') ?></td>
            <td class="actions">
                <a href="termek_form.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-secondary">Szerk.</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($eredmenyek) >= 300): ?>
    <!-- Warn the user that results were truncated so they know to add more filters. -->
    <p class="table-count" style="color:var(--warning)">&#9888; Az eredmény limitálva lett 300 találatra. Pontosítsd a keresést!</p>
    <?php else: ?>
    <p class="table-count"><?= count($eredmenyek) ?> találat</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
/* ── Szállító combobox (keresés oldal — csak meglévő szállítók) ─────────── */
(function () {
    var suppliers = <?= json_encode(array_map(function($s) {
        return ['id' => (int)$s['id'], 'nev' => $s['nev']];
    }, $szallitok), JSON_UNESCAPED_UNICODE) ?>;

    var wrap     = document.getElementById('szallitoComboboxK');
    var input    = document.getElementById('szallitoInputK');
    var hiddenId = document.getElementById('szallitoIdK');
    var dropdown = document.getElementById('szallitoDropdownK');
    var clearBtn = document.getElementById('szallitoClearK');
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
        // Add a "Minden" option at the top for clearing the filter.
        filtered = [{id:'', nev:'Minden (nincs szűrés)'}].concat(
            suppliers.filter(function(s){ return !q || s.nev.toLowerCase().indexOf(q)!==-1; })
        );
        dropdown.innerHTML = filtered.map(function(s,i){
            var sel = (String(s.id)===hiddenId.value) ? ' selected':'';
            var hl  = (i===highlighted) ? ' highlighted':'';
            return '<div class="combobox-item'+sel+hl+'" data-id="'+s.id+'" data-name="'+esc(s.nev)+'" data-index="'+i+'">'
                 + (s.id==='' ? '<em>'+esc(s.nev)+'</em>' : markMatch(s.nev, query)) + '</div>';
        }).join('');
    }

    function openDropdown(){ highlighted=-1; render(input.value); wrap.classList.add('open'); }
    function closeDropdown(){ wrap.classList.remove('open'); highlighted=-1; }

    function selectItem(id, name) {
        hiddenId.value = id;
        input.value = (id==='') ? '' : name;
        wrap.classList.toggle('has-value', id!=='');
        closeDropdown();
    }

    input.addEventListener('focus', openDropdown);
    input.addEventListener('input', function(){ highlighted=-1; render(this.value); wrap.classList.add('open'); });

    input.addEventListener('keydown', function(e){
        if(e.key==='ArrowDown'){ e.preventDefault(); highlighted=Math.min(highlighted+1,filtered.length-1); upHL(); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); highlighted=Math.max(highlighted-1,-1); upHL(); }
        else if(e.key==='Enter'){
            e.preventDefault();
            if(highlighted>=0 && filtered[highlighted]){ selectItem(String(filtered[highlighted].id), filtered[highlighted].nev); }
        } else if(e.key==='Escape'){ closeDropdown(); input.blur(); }
        else if(e.key==='Tab') closeDropdown();
    });

    function upHL(){
        dropdown.querySelectorAll('.combobox-item').forEach(function(el,i){ el.classList.toggle('highlighted',i===highlighted); });
        var h=dropdown.querySelector('.highlighted'); if(h) h.scrollIntoView({block:'nearest'});
    }

    dropdown.addEventListener('mousedown', function(e){ e.preventDefault(); });
    dropdown.addEventListener('click', function(e){
        var item=e.target.closest('.combobox-item');
        if(item) selectItem(item.dataset.id, item.dataset.name);
    });

    document.addEventListener('click', function(e){ if(!wrap.contains(e.target)) closeDropdown(); });

    clearBtn.addEventListener('click', function(){ selectItem('',''); input.value=''; input.focus(); });

    // When blurring, if text doesn't match any supplier, clear the selection.
    input.addEventListener('blur', function(){
        var q = this.value.trim().toLowerCase();
        if (!q) { hiddenId.value=''; wrap.classList.remove('has-value'); return; }
        var match = suppliers.find(function(s){ return s.nev.toLowerCase()===q; });
        if (match) { hiddenId.value=match.id; }
        else { this.value=''; hiddenId.value=''; wrap.classList.remove('has-value'); }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
