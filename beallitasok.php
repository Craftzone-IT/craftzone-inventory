<?php
/**
 * System settings — admin-only page for managing application configuration,
 * dropdown option lists, and suppliers.
 *
 * All mutations are handled via POST with an `action` discriminator so the
 * page uses a single URL for every operation. After every POST the page
 * redirects back to itself (POST → Redirect → GET pattern) to prevent double
 * submissions on browser refresh.
 *
 * Sections:
 *   1. General config  — application name and stock-number prefix.
 *   2. Type options    — the admin-managed list for the Típus dropdown.
 *   3. Spec options    — the admin-managed list for the Spec. dropdown.
 *   4. Suppliers       — supplier CRUD with active/inactive toggle.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/smtp_mailer.php';
require_once 'includes/api_auth.php';
requireAdmin();
$page_title = 'Beállítások';
$db  = getDB();

$uzenet = '';
$hiba   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = $_POST['action'];

    // ── Action: save general configuration ──────────────────────────────────
    if ($action === 'config') {
        $prefix  = strtoupper(trim($_POST['raktari_prefix'] ?? ''));
        $app_nev = trim($_POST['app_nev'] ?? '');
        // Validate the prefix format: uppercase letters, digits, and hyphens,
        // between 2 and 8 characters. This keeps generated stock numbers readable.
        if (!preg_match('/^[A-Z0-9\-]{2,8}$/', $prefix)) {
            $hiba = 'Érvénytelen prefix (A-Z, 0-9, -, 2-8 karakter).';
        } else {
            // ON DUPLICATE KEY UPDATE handles both initial insert and subsequent updates.
            $db->prepare("INSERT INTO app_config (kulcs,ertek) VALUES ('raktari_prefix',?) ON DUPLICATE KEY UPDATE ertek=?")->execute([$prefix,$prefix]);
            $db->prepare("INSERT INTO app_config (kulcs,ertek) VALUES ('app_nev',?) ON DUPLICATE KEY UPDATE ertek=?")->execute([$app_nev,$app_nev]);
            logActivity($db, 'config_modositas', "Prefix=$prefix, App_nev=$app_nev");
            $uzenet = 'Konfiguráció mentve.';
        }
    }

    // ── Action: add a new Típus or Spec. option ──────────────────────────────
    elseif ($action === 'opcio_add') {
        // Whitelist the key to prevent injecting arbitrary option groups.
        $kulcs  = in_array($_POST['kulcs'] ?? '', ['tipus','spec']) ? $_POST['kulcs'] : null;
        $ertek  = trim($_POST['ertek'] ?? '');
        if ($kulcs && $ertek) {
            // Append at the end by using max(sorrend) + 10, leaving room for
            // future reordering without touching other rows.
            $max = $db->prepare("SELECT MAX(sorrend) FROM opcio_csoportok WHERE kulcs=?");
            $max->execute([$kulcs]);
            $sorrend = ((int)$max->fetchColumn()) + 10;
            $db->prepare("INSERT INTO opcio_csoportok (kulcs,ertek,sorrend) VALUES (?,?,?)")->execute([$kulcs,$ertek,$sorrend]);
            logActivity($db, 'opcio_hozzaadas', "$kulcs: $ertek");
            $uzenet = "Opció hozzáadva: $ertek";
        }
    }

    // ── Action: delete a Típus or Spec. option ───────────────────────────────
    elseif ($action === 'opcio_delete') {
        $oid = (int)($_POST['opcio_id'] ?? 0);
        if ($oid) {
            // Read name before deleting so the audit log entry is meaningful.
            $row = $db->prepare("SELECT kulcs,ertek FROM opcio_csoportok WHERE id=?");
            $row->execute([$oid]);
            $row = $row->fetch();
            $db->prepare("DELETE FROM opcio_csoportok WHERE id=?")->execute([$oid]);
            logActivity($db, 'opcio_torles', ($row ? "{$row['kulcs']}: {$row['ertek']}" : "#$oid"));
            $uzenet = 'Opció törölve.';
        }
    }

    // ── Action: reorder an option up or down ────────────────────────────────
    elseif ($action === 'opcio_sorrend') {
        $oid  = (int)($_POST['opcio_id'] ?? 0);
        $dir  = $_POST['dir'] ?? '';
        if ($oid && in_array($dir, ['fel','le'])) {
            $cur = $db->prepare("SELECT kulcs,sorrend FROM opcio_csoportok WHERE id=?");
            $cur->execute([$oid]);
            $cur = $cur->fetch();
            if ($cur) {
                // Find the nearest neighbour in the requested direction and swap
                // their sorrend values (two UPDATEs in sequence).
                if ($dir === 'fel') {
                    $other = $db->prepare("SELECT id,sorrend FROM opcio_csoportok WHERE kulcs=? AND sorrend < ? ORDER BY sorrend DESC LIMIT 1");
                } else {
                    $other = $db->prepare("SELECT id,sorrend FROM opcio_csoportok WHERE kulcs=? AND sorrend > ? ORDER BY sorrend ASC LIMIT 1");
                }
                $other->execute([$cur['kulcs'], $cur['sorrend']]);
                $other = $other->fetch();
                if ($other) {
                    $db->prepare("UPDATE opcio_csoportok SET sorrend=? WHERE id=?")->execute([$other['sorrend'], $oid]);
                    $db->prepare("UPDATE opcio_csoportok SET sorrend=? WHERE id=?")->execute([$cur['sorrend'], $other['id']]);
                }
            }
        }
    }

    // ── Action: add a new supplier ───────────────────────────────────────────
    elseif ($action === 'szallito_add') {
        $nev = trim($_POST['szallito_nev'] ?? '');
        if ($nev) {
            $db->prepare("INSERT INTO szallitok (nev) VALUES (?)")->execute([$nev]);
            logActivity($db, 'szallito_hozzaadas', $nev);
            $uzenet = "Szállító hozzáadva: $nev";
        }
    }

    // ── Action: delete a supplier ────────────────────────────────────────────
    elseif ($action === 'szallito_delete') {
        $sid = (int)($_POST['szallito_id'] ?? 0);
        if ($sid) {
            // Block deletion when products reference this supplier to avoid
            // breaking foreign-key integrity and losing traceability.
            $hasRef = $db->prepare("SELECT COUNT(*) FROM termekek WHERE szallito_id=?");
            $hasRef->execute([$sid]);
            if ($hasRef->fetchColumn() > 0) {
                $hiba = 'Nem törölhető: termékek hivatkoznak erre a szállítóra.';
            } else {
                $r = $db->prepare("SELECT nev FROM szallitok WHERE id=?");
                $r->execute([$sid]);
                $rnev = $r->fetchColumn();
                $db->prepare("DELETE FROM szallitok WHERE id=?")->execute([$sid]);
                logActivity($db, 'szallito_torles', $rnev);
                $uzenet = 'Szállító törölve.';
            }
        }
    }

    // ── Action: toggle a supplier active / inactive ──────────────────────────
    elseif ($action === 'szallito_toggle') {
        $sid = (int)($_POST['szallito_id'] ?? 0);
        if ($sid) {
            // Flip the aktiv bit atomically — no need to read the current value first.
            $db->prepare("UPDATE szallitok SET aktiv = 1-aktiv WHERE id=?")->execute([$sid]);
            $uzenet = 'Szállító állapota frissítve.';
        }
    }

    // ── Action: save SMTP / email notification settings ──────────────────────
    elseif ($action === 'smtp_config') {
        $smtp_enabled     = isset($_POST['smtp_enabled'])     ? '1' : '0';
        $smtp_notify_new  = isset($_POST['smtp_notify_new'])  ? '1' : '0';
        $smtp_notify_sold = isset($_POST['smtp_notify_sold']) ? '1' : '0';
        $smtp_host        = trim($_POST['smtp_host']          ?? '');
        $smtp_port        = (int)($_POST['smtp_port']         ?? 587);
        $smtp_secure      = trim($_POST['smtp_secure']        ?? 'tls');
        $smtp_user        = trim($_POST['smtp_user']          ?? '');
        $smtp_pass_raw    = $_POST['smtp_pass']               ?? '';
        $smtp_from        = trim($_POST['smtp_from']          ?? '');
        $smtp_from_name   = trim($_POST['smtp_from_name']     ?? '');
        $smtp_to          = trim($_POST['smtp_to']            ?? '');

        // Whitelist encryption mode.
        if (!in_array($smtp_secure, ['ssl', 'tls', 'none'])) $smtp_secure = 'tls';

        // Only re-encrypt and overwrite the stored password when the admin
        // typed a new one. Leaving the field blank means "keep current password".
        $fields = [
            'smtp_enabled'     => $smtp_enabled,
            'smtp_notify_new'  => $smtp_notify_new,
            'smtp_notify_sold' => $smtp_notify_sold,
            'smtp_host'        => $smtp_host,
            'smtp_port'        => (string)$smtp_port,
            'smtp_secure'      => $smtp_secure,
            'smtp_user'        => $smtp_user,
            'smtp_from'        => $smtp_from,
            'smtp_from_name'   => $smtp_from_name,
            'smtp_to'          => $smtp_to,
        ];
        if ($smtp_pass_raw !== '') {
            $fields['smtp_pass_enc'] = encryptSmtpPassword($smtp_pass_raw);
        }

        $stmt = $db->prepare("INSERT INTO app_config (kulcs,ertek) VALUES (?,?) ON DUPLICATE KEY UPDATE ertek=?");
        foreach ($fields as $k => $v) {
            $stmt->execute([$k, $v, $v]);
        }

        logActivity($db, 'config_modositas', 'SMTP beállítások mentve');
        $uzenet = 'SMTP beállítások mentve.';
    }

    // ── Action: add a new status ────────────────────────────────────────────
    elseif ($action === 'statusz_add') {
        $nev  = trim($_POST['statusz_nev']  ?? '');
        $szin = trim($_POST['statusz_szin'] ?? 'gray');
        if ($nev) {
            $max = $db->query("SELECT MAX(sorrend) FROM statuszok")->fetchColumn();
            $sorrend = ((int)$max) + 10;
            $db->prepare("INSERT INTO statuszok (nev, szin, sorrend) VALUES (?,?,?)")->execute([$nev, $szin, $sorrend]);
            logActivity($db, 'statusz_hozzaadas', $nev);
            $uzenet = "Státusz hozzáadva: $nev";
        }
    }

    // ── Action: update a status ─────────────────────────────────────────────
    elseif ($action === 'statusz_edit') {
        $sid  = (int)($_POST['statusz_id']  ?? 0);
        $nev  = trim($_POST['statusz_nev']  ?? '');
        $szin = trim($_POST['statusz_szin'] ?? 'gray');
        if ($sid && $nev) {
            $db->prepare("UPDATE statuszok SET nev=?, szin=? WHERE id=?")->execute([$nev, $szin, $sid]);
            logActivity($db, 'statusz_modositas', "$nev (szín: $szin)");
            $uzenet = "Státusz módosítva: $nev";
        }
    }

    // ── Action: delete a status ─────────────────────────────────────────────
    elseif ($action === 'statusz_delete') {
        $sid = (int)($_POST['statusz_id'] ?? 0);
        if ($sid) {
            // Check if this status is protected from deletion.
            $row = $db->prepare("SELECT nev, torolheto FROM statuszok WHERE id=?");
            $row->execute([$sid]);
            $row = $row->fetch();
            if (!$row) {
                $hiba = 'Státusz nem található.';
            } elseif (!$row['torolheto']) {
                $hiba = "A \"{$row['nev']}\" státusz nem törölhető (rendszer státusz).";
            } else {
                // Block deletion if products reference this status.
                $refCount = $db->prepare("SELECT COUNT(*) FROM termekek WHERE statusz_id=?");
                $refCount->execute([$sid]);
                if ($refCount->fetchColumn() > 0) {
                    $hiba = "Nem törölhető: termékek hivatkoznak a \"{$row['nev']}\" státuszra.";
                } else {
                    $db->prepare("DELETE FROM statuszok WHERE id=?")->execute([$sid]);
                    logActivity($db, 'statusz_torles', $row['nev']);
                    $uzenet = 'Státusz törölve.';
                }
            }
        }
    }

    // ── Action: reorder a status up or down ─────────────────────────────────
    elseif ($action === 'statusz_sorrend') {
        $sid = (int)($_POST['statusz_id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        if ($sid && in_array($dir, ['fel', 'le'])) {
            $cur = $db->prepare("SELECT sorrend FROM statuszok WHERE id=?");
            $cur->execute([$sid]);
            $cur = $cur->fetch();
            if ($cur) {
                if ($dir === 'fel') {
                    $other = $db->prepare("SELECT id, sorrend FROM statuszok WHERE sorrend < ? ORDER BY sorrend DESC LIMIT 1");
                } else {
                    $other = $db->prepare("SELECT id, sorrend FROM statuszok WHERE sorrend > ? ORDER BY sorrend ASC LIMIT 1");
                }
                $other->execute([$cur['sorrend']]);
                $other = $other->fetch();
                if ($other) {
                    $db->prepare("UPDATE statuszok SET sorrend=? WHERE id=?")->execute([$other['sorrend'], $sid]);
                    $db->prepare("UPDATE statuszok SET sorrend=? WHERE id=?")->execute([$cur['sorrend'], $other['id']]);
                }
            }
        }
    }

    // ── Action: generate a new API token ──────────────────────────────────────
    elseif ($action === 'api_token_generate') {
        $tokenNev = trim($_POST['token_nev'] ?? '');
        $tokenFor = (int)($_POST['token_for'] ?? currentUser()['id']);
        // Non-admin users can only create tokens for themselves.
        if (!isAdmin()) $tokenFor = currentUser()['id'];
        if ($tokenNev === '') {
            $hiba = 'A token nevének megadása kötelező.';
        } else {
            $result = generateApiToken($db, $tokenFor, $tokenNev);
            logActivity($db, 'api_token_letrehozas', "Token: $tokenNev (user #$tokenFor)");
            // Store raw token in session flash — displayed ONCE after redirect.
            $_SESSION['new_api_token'] = $result['raw_token'];
            $_SESSION['new_api_token_nev'] = $tokenNev;
            $uzenet = 'API token létrehozva.';
        }
    }

    // ── Action: toggle an API token active / inactive ───────────────────────
    elseif ($action === 'api_token_toggle') {
        $tid = (int)($_POST['token_id'] ?? 0);
        if ($tid) {
            // Non-admin users can only toggle their own tokens.
            $owner = $db->prepare("SELECT felhasznalo_id, nev FROM api_tokenek WHERE id = ?");
            $owner->execute([$tid]);
            $owner = $owner->fetch();
            if ($owner && (isAdmin() || (int)$owner['felhasznalo_id'] === currentUser()['id'])) {
                $db->prepare("UPDATE api_tokenek SET aktiv = 1 - aktiv WHERE id = ?")->execute([$tid]);
                $uzenet = 'API token állapota frissítve.';
            }
        }
    }

    // ── Action: delete an API token ─────────────────────────────────────────
    elseif ($action === 'api_token_delete') {
        $tid = (int)($_POST['token_id'] ?? 0);
        if ($tid) {
            $owner = $db->prepare("SELECT felhasznalo_id, nev FROM api_tokenek WHERE id = ?");
            $owner->execute([$tid]);
            $owner = $owner->fetch();
            if ($owner && (isAdmin() || (int)$owner['felhasznalo_id'] === currentUser()['id'])) {
                $db->prepare("DELETE FROM api_tokenek WHERE id = ?")->execute([$tid]);
                logActivity($db, 'api_token_torles', "Token: {$owner['nev']} (user #{$owner['felhasznalo_id']})");
                $uzenet = 'API token törölve.';
            }
        }
    }

    // POST → Redirect → GET: prevents re-submission on browser refresh.
    header('Location: beallitasok.php?' . ($hiba ? 'hiba=1' : 'uzenet=' . urlencode($uzenet)));
    exit;
}

// Load current values for display.
$prefix  = getConfig($db, 'raktari_prefix', 'RAK');
$app_nev = getConfig($db, 'app_nev', 'Raktárkészlet kezelő');

// Load SMTP settings for the settings form.
$smtp = [
    'enabled'      => getConfig($db, 'smtp_enabled',     '0'),
    'notify_new'   => getConfig($db, 'smtp_notify_new',  '1'),
    'notify_sold'  => getConfig($db, 'smtp_notify_sold', '1'),
    'host'         => getConfig($db, 'smtp_host'),
    'port'         => getConfig($db, 'smtp_port',         '587'),
    'secure'       => getConfig($db, 'smtp_secure',       'tls'),
    'user'         => getConfig($db, 'smtp_user'),
    'has_pass'     => getConfig($db, 'smtp_pass_enc') !== '',  // true = password already saved
    'from'         => getConfig($db, 'smtp_from'),
    'from_name'    => getConfig($db, 'smtp_from_name',    'Raktárkészlet kezelő'),
    'to'           => getConfig($db, 'smtp_to'),
];

$tipus_opcio = $db->query("SELECT * FROM opcio_csoportok WHERE kulcs='tipus' ORDER BY sorrend")->fetchAll();
$spec_opcio  = $db->query("SELECT * FROM opcio_csoportok WHERE kulcs='spec'  ORDER BY sorrend")->fetchAll();
// Include product count per supplier so the UI can disable delete for referenced ones.
$szallitok   = $db->query("SELECT s.*, (SELECT COUNT(*) FROM termekek WHERE szallito_id=s.id) AS ref_szam FROM szallitok s ORDER BY nev")->fetchAll();
// Load status list with product reference counts for the status CRUD section.
$statuszok   = $db->query("SELECT st.*, (SELECT COUNT(*) FROM termekek WHERE statusz_id=st.id) AS ref_szam FROM statuszok st ORDER BY sorrend")->fetchAll();

// Load API tokens: admin sees all, regular user sees only their own.
// Wrapped in try/catch so the page still loads if the api_tokenek table
// has not been created yet (migration not run).
$api_tokenek        = [];
$api_tabla_letezik  = true;
$newApiToken        = null;
$newApiTokenNev     = null;
$felhasznalok_lista = [];

try {
    if (isAdmin()) {
        $api_tokenek = $db->query("
            SELECT t.*, f.felhasznalonev, f.nev AS felhasznalo_nev
            FROM api_tokenek t
            JOIN felhasznalok f ON f.id = t.felhasznalo_id
            ORDER BY t.letrehozva DESC
        ")->fetchAll();
    } else {
        $stmt = $db->prepare("
            SELECT t.*, f.felhasznalonev, f.nev AS felhasznalo_nev
            FROM api_tokenek t
            JOIN felhasznalok f ON f.id = t.felhasznalo_id
            WHERE t.felhasznalo_id = ?
            ORDER BY t.letrehozva DESC
        ");
        $stmt->execute([currentUser()['id']]);
        $api_tokenek = $stmt->fetchAll();
    }

    // Flash: newly generated raw token (shown once, then cleared).
    $newApiToken    = $_SESSION['new_api_token']     ?? null;
    $newApiTokenNev = $_SESSION['new_api_token_nev'] ?? null;
    unset($_SESSION['new_api_token'], $_SESSION['new_api_token_nev']);

    // Load user list for admin token assignment dropdown.
    $felhasznalok_lista = isAdmin()
        ? $db->query("SELECT id, felhasznalonev, nev FROM felhasznalok WHERE aktiv = 1 ORDER BY nev")->fetchAll()
        : [];
} catch (PDOException $e) {
    // Table does not exist yet — show a migration notice instead of the token UI.
    $api_tabla_letezik = false;
}

include 'includes/header.php';

/**
 * Renders the option management table for a given option group (típus or spec).
 *
 * Outputs a table of current options with up/down reorder buttons and a delete
 * button per row, followed by an inline add form. All actions POST back to the
 * page with the appropriate action discriminator.
 *
 * @param array  $opcio Array of option rows from opcio_csoportok.
 * @param string $kulcs Option group key: 'tipus' or 'spec'.
 * @return void
 */
function opcioTable(array $opcio, string $kulcs): void {
    echo '<table class="table" style="margin-bottom:12px">';
    echo '<thead><tr><th>Érték</th><th>Sorrend</th><th></th></tr></thead><tbody>';
    if (empty($opcio)) {
        echo '<tr><td colspan="3" class="empty-state">Nincs opció.</td></tr>';
    }
    foreach ($opcio as $o) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($o['ertek']) . (!$o['aktiv'] ? ' <span class="badge badge-red">inaktív</span>' : '') . '</td>';
        echo '<td>';
        // Up button
        echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="opcio_sorrend"><input type="hidden" name="opcio_id" value="' . $o['id'] . '"><input type="hidden" name="dir" value="fel"><button type="submit" class="btn btn-sm btn-ghost" title="Fel">&#8593;</button></form>';
        // Down button
        echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="opcio_sorrend"><input type="hidden" name="opcio_id" value="' . $o['id'] . '"><input type="hidden" name="dir" value="le"><button type="submit" class="btn btn-sm btn-ghost" title="Le">&#8595;</button></form>';
        echo '</td>';
        echo '<td class="actions"><form method="post" onsubmit="return confirm(\'Biztosan törli?\')"><input type="hidden" name="action" value="opcio_delete"><input type="hidden" name="opcio_id" value="' . $o['id'] . '"><button type="submit" class="btn btn-sm btn-danger">Törlés</button></form></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    // Inline add form appended directly below the table.
    echo '<form method="post" class="inline-add-form"><input type="hidden" name="action" value="opcio_add"><input type="hidden" name="kulcs" value="' . $kulcs . '">';
    echo '<input type="text" name="ertek" class="input" placeholder="Új ' . $kulcs . ' opció..." required style="max-width:240px">';
    echo '<button type="submit" class="btn btn-primary btn-sm">+ Hozzáadás</button>';
    echo '</form>';
}
?>

<style>
    .settings-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 20px;
        background: var(--surface);
        border: 2px solid var(--primary);
        border-radius: var(--radius);
        cursor: pointer;
        font-size: 15px;
        font-weight: 600;
        color: var(--text);
        box-shadow: var(--shadow);
        transition: background .15s, border-color .15s;
    }
    .settings-toggle:hover { background: var(--bg); }
    .settings-toggle .toggle-left { display: flex; align-items: center; gap: 10px; }
    .settings-toggle .toggle-icon { font-size: 20px; line-height: 1; }
    .settings-toggle .toggle-arrow {
        font-size: 18px;
        transition: transform .25s;
    }
    .settings-panel {
        max-height: 0;
        overflow: hidden;
        transition: max-height .35s ease, opacity .25s ease;
        opacity: 0;
    }
    .settings-panel.open {
        opacity: 1;
        overflow: visible;
    }
    .settings-panel > .card {
        margin-top: 4px;
        border-top: 2px solid var(--primary);
    }
    .settings-section { margin-bottom: 16px; }
</style>

<div class="page-header">
    <h1>&#9881; Rendszer beállítások</h1>
</div>

<?php if (isset($_GET['uzenet'])): ?>
<div class="flash flash-success flash-auto"><?= htmlspecialchars(urldecode($_GET['uzenet'])) ?></div>
<?php endif; ?>
<?php if ($hiba || isset($_GET['hiba'])): ?>
<div class="flash flash-error"><?= htmlspecialchars($hiba ?: 'Hiba történt.') ?></div>
<?php endif; ?>

<?php
// Determine which section to auto-open based on the redirect message.
$autoOpen = '';
if (isset($_GET['uzenet'])) {
    $msg = urldecode($_GET['uzenet']);
    if (stripos($msg, 'Konfiguráció') !== false)    $autoOpen = 'config';
    elseif (stripos($msg, 'Opció') !== false)        $autoOpen = 'opciok';
    elseif (stripos($msg, 'Szállító') !== false)     $autoOpen = 'szallitok';
    elseif (stripos($msg, 'Státusz') !== false)      $autoOpen = 'statuszok';
    elseif (stripos($msg, 'SMTP') !== false)         $autoOpen = 'smtp';
    elseif (stripos($msg, 'API') !== false)          $autoOpen = 'api';
}
if ($newApiToken) $autoOpen = 'api';
if (isset($_GET['hiba'])) $autoOpen = $autoOpen ?: 'config';
?>

<!-- ═══ Section 1: Általános beállítások ═══ -->
<div class="settings-section">
    <button type="button" class="settings-toggle" data-target="config-panel">
        <span class="toggle-left">
            <span class="toggle-icon">&#9881;</span>
            Általános beállítások
            <span class="badge badge-blue" style="font-size:11px"><?= htmlspecialchars($prefix) ?>-****</span>
        </span>
        <span class="toggle-arrow">&#9660;</span>
    </button>
    <div class="settings-panel" id="config-panel">
        <div class="card form-card">
            <form method="post">
                <input type="hidden" name="action" value="config">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Alkalmazás neve</label>
                        <input type="text" name="app_nev" class="input" value="<?= htmlspecialchars($app_nev) ?>">
                    </div>
                    <div class="form-group">
                        <label>Raktári szám prefix</label>
                        <input type="text" name="raktari_prefix" class="input" value="<?= htmlspecialchars($prefix) ?>"
                            maxlength="8" style="text-transform:uppercase">
                        <div class="form-hint">
                            Jelenlegi: <code><?= htmlspecialchars($prefix) ?>-0001</code>, <code><?= htmlspecialchars($prefix) ?>-0002</code> ...
                            &mdash; Módosítás csak az ÚJ tételek számozásán változtat.
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Mentés</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ Section 2: Típus & Spec. opciók ═══ -->
<div class="settings-section">
    <button type="button" class="settings-toggle" data-target="opciok-panel">
        <span class="toggle-left">
            <span class="toggle-icon">&#9776;</span>
            Típus &amp; Spec. opciók
            <span class="badge badge-blue" style="font-size:11px"><?= count($tipus_opcio) ?> típus</span>
            <span class="badge badge-blue" style="font-size:11px"><?= count($spec_opcio) ?> spec</span>
        </span>
        <span class="toggle-arrow">&#9660;</span>
    </button>
    <div class="settings-panel" id="opciok-panel">
        <div class="card form-card">
            <div class="two-col" style="margin:0">
                <div>
                    <h3 style="margin-bottom:12px;font-size:14px;font-weight:600">Típus opciók</h3>
                    <?php opcioTable($tipus_opcio, 'tipus') ?>
                </div>
                <div>
                    <h3 style="margin-bottom:12px;font-size:14px;font-weight:600">Spec. opciók</h3>
                    <?php opcioTable($spec_opcio, 'spec') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Section 3: Szállítók ═══ -->
<div class="settings-section">
    <button type="button" class="settings-toggle" data-target="szallitok-panel">
        <span class="toggle-left">
            <span class="toggle-icon">&#128666;</span>
            Szállítók kezelése
            <span class="badge badge-blue" style="font-size:11px"><?= count($szallitok) ?> szállító</span>
        </span>
        <span class="toggle-arrow">&#9660;</span>
    </button>
    <div class="settings-panel" id="szallitok-panel">
        <div class="card form-card">
            <table class="table" style="margin-bottom:12px">
                <thead><tr><th>Szállító neve</th><th>Státusz</th><th>Tételek száma</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($szallitok)): ?>
                    <tr><td colspan="4" class="empty-state">Nincs szállító.</td></tr>
                <?php endif; ?>
                <?php foreach ($szallitok as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['nev']) ?></td>
                    <td><span class="badge <?= $s['aktiv'] ? 'badge-green' : 'badge-red' ?>"><?= $s['aktiv'] ? 'Aktív' : 'Inaktív' ?></span></td>
                    <td><?= $s['ref_szam'] ?></td>
                    <td class="actions">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="szallito_toggle">
                            <input type="hidden" name="szallito_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-secondary" title="Aktiv váltás">&#8635;</button>
                        </form>
                        <?php if ($s['ref_szam'] == 0): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Biztosan törli?')">
                            <input type="hidden" name="action" value="szallito_delete">
                            <input type="hidden" name="szallito_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Törlés</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" class="inline-add-form">
                <input type="hidden" name="action" value="szallito_add">
                <input type="text" name="szallito_nev" class="input" placeholder="Új szállító neve..." required style="max-width:300px">
                <button type="submit" class="btn btn-primary btn-sm">+ Hozzáadás</button>
            </form>
        </div>
    </div>
</div>

<!-- ═══ Section 4: Státuszok kezelése ═══ -->
<?php
$szin_opcio = ['green','blue','orange','red','gray','purple'];
?>
<div class="settings-section">
    <button type="button" class="settings-toggle" data-target="statuszok-panel">
        <span class="toggle-left">
            <span class="toggle-icon">&#9899;</span>
            Státuszok kezelése
            <span class="badge badge-blue" style="font-size:11px"><?= count($statuszok) ?> státusz</span>
        </span>
        <span class="toggle-arrow">&#9660;</span>
    </button>
    <div class="settings-panel" id="statuszok-panel">
        <div class="card form-card">
            <table class="table" style="margin-bottom:12px">
                <thead><tr><th>Név</th><th>Szín</th><th>Tételek</th><th>Sorrend</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($statuszok)): ?>
                    <tr><td colspan="5" class="empty-state">Nincs státusz.</td></tr>
                <?php endif; ?>
                <?php foreach ($statuszok as $st): ?>
                <tr>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($st['szin']) ?>"><?= htmlspecialchars($st['nev']) ?></span>
                        <?php if (!$st['torolheto']): ?>
                        <span style="font-size:10px;color:var(--text-muted)" title="Rendszer státusz, nem törölhető">&#128274;</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="statusz_edit">
                            <input type="hidden" name="statusz_id" value="<?= $st['id'] ?>">
                            <input type="text" name="statusz_nev" value="<?= htmlspecialchars($st['nev']) ?>" class="input" style="width:120px;display:inline-block;font-size:12px" required>
                            <select name="statusz_szin" class="input" style="width:90px;display:inline-block;font-size:12px" onchange="this.form.submit()">
                                <?php foreach ($szin_opcio as $sz): ?>
                                <option value="<?= $sz ?>" <?= $st['szin'] === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-ghost" title="Mentés">&#10003;</button>
                        </form>
                    </td>
                    <td><?= $st['ref_szam'] ?></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="statusz_sorrend"><input type="hidden" name="statusz_id" value="<?= $st['id'] ?>"><input type="hidden" name="dir" value="fel"><button type="submit" class="btn btn-sm btn-ghost" title="Fel">&#8593;</button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="statusz_sorrend"><input type="hidden" name="statusz_id" value="<?= $st['id'] ?>"><input type="hidden" name="dir" value="le"><button type="submit" class="btn btn-sm btn-ghost" title="Le">&#8595;</button></form>
                    </td>
                    <td class="actions">
                        <?php if ($st['torolheto'] && $st['ref_szam'] == 0): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Biztosan törli?')">
                            <input type="hidden" name="action" value="statusz_delete">
                            <input type="hidden" name="statusz_id" value="<?= $st['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Törlés</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" class="inline-add-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <input type="hidden" name="action" value="statusz_add">
                <input type="text" name="statusz_nev" class="input" placeholder="Új státusz neve..." required style="max-width:200px">
                <select name="statusz_szin" class="input" style="width:auto">
                    <?php foreach ($szin_opcio as $sz): ?>
                    <option value="<?= $sz ?>"><?= $sz ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">+ Hozzáadás</button>
            </form>
        </div>
    </div>
</div>

<!-- ═══ Section 5: SMTP / Email értesítések ═══ -->
<div class="settings-section">
    <button type="button" class="settings-toggle" data-target="smtp-panel">
        <span class="toggle-left">
            <span class="toggle-icon">&#9993;</span>
            Email értesítések (SMTP)
            <?php if ($smtp['enabled'] === '1'): ?>
            <span class="badge badge-green" style="font-size:11px">Aktív</span>
            <?php else: ?>
            <span class="badge badge-gray" style="font-size:11px">Kikapcsolva</span>
            <?php endif; ?>
        </span>
        <span class="toggle-arrow">&#9660;</span>
    </button>
    <div class="settings-panel" id="smtp-panel">
        <div class="card form-card">
            <form method="post" id="smtp-form">
                <input type="hidden" name="action" value="smtp_config">

                <div class="form-group" style="margin-bottom:16px">
                    <label class="checkbox-label" style="font-weight:600;font-size:14px">
                        <input type="checkbox" name="smtp_enabled" value="1" id="smtp_enabled"
                            <?= $smtp['enabled'] === '1' ? 'checked' : '' ?>>
                        Email értesítések bekapcsolva
                    </label>
                </div>
                <div class="checkbox-row" style="margin-bottom:20px">
                    <label class="checkbox-label">
                        <input type="checkbox" name="smtp_notify_new" value="1"
                            <?= $smtp['notify_new'] !== '0' ? 'checked' : '' ?>>
                        Értesítés: új termék felvételekor
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="smtp_notify_sold" value="1"
                            <?= $smtp['notify_sold'] !== '0' ? 'checked' : '' ?>>
                        Értesítés: termék eladottá válásakor
                    </label>
                </div>

                <div class="form-section-title">SMTP szerver</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>SMTP szerver (host)</label>
                        <input type="text" name="smtp_host" class="input"
                            placeholder="pl. smtp.gmail.com"
                            value="<?= htmlspecialchars($smtp['host']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" name="smtp_port" class="input"
                            placeholder="587"
                            value="<?= htmlspecialchars($smtp['port']) ?>" min="1" max="65535">
                        <div class="form-hint">587 (TLS/STARTTLS) &bull; 465 (SSL) &bull; 25 (plain)</div>
                    </div>
                    <div class="form-group">
                        <label>Titkosítás</label>
                        <select name="smtp_secure" class="input">
                            <option value="tls"  <?= $smtp['secure'] === 'tls'  ? 'selected' : '' ?>>STARTTLS (ajánlott)</option>
                            <option value="ssl"  <?= $smtp['secure'] === 'ssl'  ? 'selected' : '' ?>>SSL/TLS</option>
                            <option value="none" <?= $smtp['secure'] === 'none' ? 'selected' : '' ?>>Nincs (plain)</option>
                        </select>
                    </div>
                </div>

                <div class="form-section-title">Hitelesítés</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>SMTP felhasználónév</label>
                        <input type="text" name="smtp_user" class="input" autocomplete="off"
                            placeholder="pl. felhasznalo@domain.hu"
                            value="<?= htmlspecialchars($smtp['user']) ?>">
                    </div>
                    <div class="form-group">
                        <label>SMTP jelszó <?= $smtp['has_pass'] ? '<span class="badge badge-green" style="font-size:11px">&#128274; mentve</span>' : '' ?></label>
                        <input type="password" name="smtp_pass" class="input" autocomplete="new-password"
                            placeholder="<?= $smtp['has_pass'] ? 'Üresen hagyva = jelenlegi jelszó marad' : 'Jelszó megadása...' ?>">
                        <?php if ($smtp['has_pass']): ?>
                        <div class="form-hint">A jelszó AES-256 titkosítással tárolva. Csak akkor add meg újra, ha változott.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-section-title">Feladó és Címzett</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Feladó email cím</label>
                        <input type="email" name="smtp_from" class="input"
                            placeholder="pl. noreply@domain.hu"
                            value="<?= htmlspecialchars($smtp['from']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Feladó neve</label>
                        <input type="text" name="smtp_from_name" class="input"
                            placeholder="pl. Raktárkezelő"
                            value="<?= htmlspecialchars($smtp['from_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Értesítési email cím (címzett)</label>
                        <input type="email" name="smtp_to" class="input"
                            placeholder="pl. raktar@ceg.hu"
                            value="<?= htmlspecialchars($smtp['to']) ?>">
                        <div class="form-hint">Erre a címre érkeznek az értesítők.</div>
                    </div>
                </div>

                <div class="form-actions" style="align-items:center;gap:12px">
                    <button type="submit" class="btn btn-primary">Mentés</button>
                    <button type="button" class="btn btn-secondary" id="smtp-test-btn">
                        &#9993; Teszt email küldése
                    </button>
                    <span id="smtp-test-result" style="font-size:13px"></span>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ Section 6: API hozzáférés ═══ -->
<div class="settings-section">
    <button type="button" class="settings-toggle" data-target="api-panel">
        <span class="toggle-left">
            <span class="toggle-icon">&#128279;</span>
            API hozzáférés
            <?php if ($api_tabla_letezik): ?>
            <span class="badge badge-blue" style="font-size:11px"><?= count($api_tokenek) ?> token</span>
            <?php else: ?>
            <span class="badge badge-gray" style="font-size:11px">Nincs telepítve</span>
            <?php endif; ?>
        </span>
        <span class="toggle-arrow">&#9660;</span>
    </button>
    <div class="settings-panel" id="api-panel">
        <div class="card form-card">

            <?php if (!$api_tabla_letezik): ?>
            <!-- ── Migration needed ───────────────────────────────────── -->
            <div style="padding:16px;text-align:center">
                <p style="margin-bottom:12px">Az API tokenek tábla még nem létezik az adatbázisban.</p>
                <p style="margin-bottom:16px;font-size:13px;color:var(--text-muted)">
                    Futtasd a migrációt az adatbázisban:<br>
                    <code style="display:inline-block;margin-top:8px;padding:6px 12px;background:var(--bg);border-radius:6px">SOURCE db/migrate_api_tokenek.sql;</code>
                </p>
                <p style="font-size:12px;color:var(--text-muted)">
                    Vagy a parancssorból:<br>
                    <code style="display:inline-block;margin-top:4px;padding:6px 12px;background:var(--bg);border-radius:6px;font-size:11px">mysql -u USER -p DB_NAME &lt; db/migrate_api_tokenek.sql</code>
                </p>
            </div>
            <?php else: ?>

            <?php if ($newApiToken): ?>
            <!-- ── Newly generated token — shown once ─────────────────────── -->
            <div style="background:var(--success-bg, #dcfce7);border:2px solid var(--success, #16a34a);border-radius:var(--radius);padding:16px;margin-bottom:20px">
                <div style="font-weight:700;margin-bottom:8px;color:var(--success, #16a34a)">&#128273; Új API token: <?= htmlspecialchars($newApiTokenNev) ?></div>
                <div style="font-family:monospace;font-size:13px;background:var(--bg);padding:10px 14px;border-radius:6px;word-break:break-all;user-select:all;margin-bottom:10px" id="newTokenValue"><?= htmlspecialchars($newApiToken) ?></div>
                <button type="button" class="btn btn-sm btn-primary" id="copyTokenBtn">&#128203; Másolás</button>
                <span id="copyResult" style="font-size:12px;margin-left:8px"></span>
                <div style="margin-top:10px;font-size:12px;color:var(--danger, #dc2626);font-weight:600">
                    &#9888; Ez a token többé nem jelenik meg. Mentsd el biztonságos helyre!
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Token list ─────────────────────────────────────────────── -->
            <table class="table" style="margin-bottom:16px">
                <thead>
                    <tr>
                        <th>Név</th>
                        <?php if (isAdmin()): ?><th>Felhasználó</th><?php endif; ?>
                        <th>Létrehozva</th>
                        <th>Utolsó használat</th>
                        <th>Állapot</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($api_tokenek)): ?>
                    <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>" class="empty-state">Nincs API token.</td></tr>
                <?php endif; ?>
                <?php foreach ($api_tokenek as $tok): ?>
                <tr style="<?= $tok['aktiv'] ? '' : 'opacity:.55' ?>">
                    <td><?= htmlspecialchars($tok['nev']) ?></td>
                    <?php if (isAdmin()): ?>
                    <td><span class="text-muted"><?= htmlspecialchars($tok['felhasznalonev']) ?></span></td>
                    <?php endif; ?>
                    <td class="text-muted" style="white-space:nowrap"><?= date('Y.m.d H:i', strtotime($tok['letrehozva'])) ?></td>
                    <td class="text-muted" style="white-space:nowrap"><?= $tok['utolso_hasznalat'] ? date('Y.m.d H:i', strtotime($tok['utolso_hasznalat'])) : '<em>Még nem használt</em>' ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="api_token_toggle">
                            <input type="hidden" name="token_id" value="<?= $tok['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $tok['aktiv'] ? 'btn-success' : 'btn-secondary' ?>" style="min-width:70px">
                                <?= $tok['aktiv'] ? 'Aktív' : 'Inaktív' ?>
                            </button>
                        </form>
                    </td>
                    <td class="actions">
                        <form method="post" style="display:inline" onsubmit="return confirm('Biztosan törli ezt a tokent?')">
                            <input type="hidden" name="action" value="api_token_delete">
                            <input type="hidden" name="token_id" value="<?= $tok['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Törlés</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- ── Generate new token ─────────────────────────────────────── -->
            <form method="post" class="inline-add-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <input type="hidden" name="action" value="api_token_generate">
                <input type="text" name="token_nev" class="input" placeholder="Token neve (pl. Home Assistant)..." required style="max-width:260px">
                <?php if (isAdmin() && count($felhasznalok_lista) > 1): ?>
                <select name="token_for" class="input" style="width:auto">
                    <?php foreach ($felhasznalok_lista as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $f['id'] === currentUser()['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['nev']) ?> (<?= htmlspecialchars($f['felhasznalonev']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">+ Új token generálása</button>
            </form>

            <!-- ── API documentation ──────────────────────────────────────── -->
            <details style="margin-top:20px;border:1px solid var(--border);border-radius:var(--radius);padding:0;overflow:hidden">
                <summary style="padding:12px 16px;cursor:pointer;font-weight:600;font-size:13px;user-select:none">
                    &#128214; API használat
                </summary>
                <div style="padding:4px 16px 16px;font-size:13px;line-height:1.6;overflow:hidden">
                    <p>Minden API kéréshez az <code>Authorization</code> header szükséges:</p>
                    <pre style="background:var(--bg);padding:10px 14px;border-radius:6px;overflow-x:auto;font-size:12px">Authorization: Bearer &lt;API_TOKEN&gt;</pre>

                    <p style="margin-top:12px;font-weight:600">Elérhető végpontok:</p>
                    <div style="overflow-x:auto">
                    <table class="table" style="font-size:12px;margin-bottom:12px;min-width:0">
                        <thead><tr><th>Metódus</th><th>Végpont</th><th>Leírás</th></tr></thead>
                        <tbody>
                            <tr><td><code>GET</code></td><td><code>/api/termek</code></td><td>Terméklista</td></tr>
                            <tr><td><code>GET</code></td><td><code>/api/termek/{id}</code></td><td>Egy termék</td></tr>
                            <tr><td><code>POST</code></td><td><code>/api/termek</code></td><td>Új termék</td></tr>
                            <tr><td><code>PUT</code></td><td><code>/api/termek/{id}</code></td><td>Módosítás</td></tr>
                            <tr><td><code>DELETE</code></td><td><code>/api/termek/{id}</code></td><td>Törlés</td></tr>
                        </tbody>
                    </table>
                    </div>

                    <p style="font-weight:600">Példa: új termék (cURL)</p>
                    <pre style="background:var(--bg);padding:10px 14px;border-radius:6px;overflow-x:auto;font-size:11px;white-space:pre;word-break:break-all">curl -X POST <?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . dirname($_SERVER['SCRIPT_NAME']), '/')) ?>/api/termek \
  -H "Authorization: Bearer &lt;TOKEN&gt;" \
  -H "Content-Type: application/json" \
  -d '{"megnevezes":"Dell Latitude 5540","tipus":"Laptop","netto_ar":285000}'</pre>

                    <p style="margin-top:12px;font-weight:600">Mezők (POST / PUT)</p>
                    <div style="overflow-x:auto">
                    <table class="table" style="font-size:12px;min-width:0">
                        <thead><tr><th>Mező</th><th>Köt.</th><th>Leírás</th></tr></thead>
                        <tbody>
                            <tr><td><code>megnevezes</code></td><td>Igen</td><td>Termék neve (max 300 kar.)</td></tr>
                            <tr><td><code>tipus</code></td><td></td><td>Típus (pl. Laptop)</td></tr>
                            <tr><td><code>spec</code></td><td></td><td>Spec. (pl. 16GB RAM)</td></tr>
                            <tr><td><code>szallito_id</code></td><td></td><td>Szállító ID</td></tr>
                            <tr><td><code>szallito_nev</code></td><td></td><td>Szállító neve (auto létrehozás)</td></tr>
                            <tr><td><code>netto_ar</code></td><td></td><td>Nettó ár (Ft)</td></tr>
                            <tr><td><code>datum</code></td><td></td><td>Bevételezés (YYYY-MM-DD)</td></tr>
                            <tr><td><code>statusz_id</code></td><td></td><td>Státusz ID (alap: 1)</td></tr>
                            <tr><td><code>be_szamlaszam</code></td><td></td><td>Bejövő számlaszám</td></tr>
                            <tr><td><code>ki_szamlaszam</code></td><td></td><td>Kimenő számlaszám</td></tr>
                            <tr><td><code>vevo</code></td><td></td><td>Vevő neve</td></tr>
                            <tr><td><code>eladas_datum</code></td><td></td><td>Eladás dátuma (YYYY-MM-DD)</td></tr>
                            <tr><td><code>megjegyzes</code></td><td></td><td>Megjegyzés</td></tr>
                        </tbody>
                    </table>
                    </div>

                    <p style="margin-top:12px;font-weight:600">GET szűrők:</p>
                    <p style="font-size:12px"><code>?kereses=Dell&amp;tipus=Laptop&amp;statusz=raktáron&amp;limit=20&amp;offset=0</code></p>
                    <p style="margin-top:8px;font-weight:600">Rate limit: <span style="font-weight:400">60 kérés / perc / token</span></p>
                </div>
            </details>

            <?php endif; /* api_tabla_letezik */ ?>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Generic collapsible panels ──────────────────────────────────────────
    const autoOpen = '<?= $autoOpen ?>';

    document.querySelectorAll('.settings-toggle').forEach(function (btn) {
        const panelId = btn.getAttribute('data-target');
        const panel   = document.getElementById(panelId);
        const arrow   = btn.querySelector('.toggle-arrow');
        if (!panel) return;

        function open() {
            panel.style.maxHeight = panel.scrollHeight + 'px';
            panel.style.opacity  = '1';
            panel.classList.add('open');
            arrow.style.transform = 'rotate(180deg)';
            btn.setAttribute('data-open', '1');
            // After the CSS transition ends, switch to max-height:none so
            // child elements that expand later (e.g. <details>) are not clipped.
            panel.addEventListener('transitionend', function handler() {
                if (btn.hasAttribute('data-open')) {
                    panel.style.maxHeight = 'none';
                }
                panel.removeEventListener('transitionend', handler);
            });
        }
        function close() {
            // Restore a concrete max-height so the closing transition can animate.
            panel.style.maxHeight = panel.scrollHeight + 'px';
            // Force a reflow so the browser registers the starting value.
            panel.offsetHeight; // eslint-disable-line no-unused-expressions
            panel.style.maxHeight = '0';
            panel.style.opacity  = '0';
            panel.classList.remove('open');
            arrow.style.transform = 'rotate(0)';
            btn.removeAttribute('data-open');
        }

        btn.addEventListener('click', function () {
            btn.hasAttribute('data-open') ? close() : open();
        });

        // Auto-open the section that was just saved.
        if (panelId.replace('-panel', '') === autoOpen) {
            open();
        }
    });

    // ── API token clipboard copy ───────────────────────────────────────────
    var copyBtn = document.getElementById('copyTokenBtn');
    if (copyBtn) {
        var tokenEl = document.getElementById('newTokenValue');
        // Auto-copy on page load.
        if (tokenEl && navigator.clipboard) {
            navigator.clipboard.writeText(tokenEl.textContent.trim()).catch(function(){});
        }
        copyBtn.addEventListener('click', function () {
            var token  = tokenEl.textContent.trim();
            var result = document.getElementById('copyResult');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(token).then(function () {
                    result.textContent = '\u2713 Vágólapra másolva!';
                    result.style.color = 'var(--success)';
                }).catch(function () {
                    result.textContent = '\u2717 Nem sikerült másolni.';
                    result.style.color = 'var(--danger)';
                });
            } else {
                // Fallback for non-HTTPS environments.
                var range = document.createRange();
                range.selectNodeContents(tokenEl);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                result.textContent = 'Jelöld ki és Ctrl+C!';
                result.style.color = 'var(--text-muted)';
            }
        });
    }

    // ── SMTP test email ─────────────────────────────────────────────────────
    document.getElementById('smtp-test-btn').addEventListener('click', function () {
        const btn    = this;
        const result = document.getElementById('smtp-test-result');
        const form   = document.getElementById('smtp-form');

        btn.disabled    = true;
        btn.textContent = '\u23F3 Küldés...';
        result.textContent = '';
        result.style.color = '';

        fetch('smtp_teszt.php', { method: 'POST', body: new FormData(form) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                result.textContent = (j.ok ? '\u2713 ' : '\u2717 ') + j.msg;
                result.style.color = j.ok ? 'var(--success)' : 'var(--danger)';
            })
            .catch(function () {
                result.textContent = '\u2717 Hálózati hiba.';
                result.style.color = 'var(--danger)';
            })
            .finally(function () {
                btn.disabled  = false;
                btn.innerHTML = '&#9993; Teszt email küldése';
            });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
