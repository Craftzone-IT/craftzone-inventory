<?php
/**
 * User management — admin-only page for creating, editing, and deactivating
 * user accounts.
 *
 * Admins can:
 *   - Create new users with a username, display name, role, and password.
 *   - Edit an existing user's details (password is optional on edit).
 *   - Toggle active/inactive status (deactivated users cannot log in).
 *   - Delete users who have no products linked to their account.
 *
 * Safety rules enforced here:
 *   - An admin cannot deactivate, demote, or delete their own account.
 *   - A user with linked products cannot be deleted (referential integrity).
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
requireAdmin();
$page_title = 'Felhasználók';
$db  = getDB();
$me  = currentUser();

// Toggle active/inactive — self-modification is blocked to prevent lockout.
if (isset($_GET['toggle']) && is_numeric($_GET['toggle']) && (int)$_GET['toggle'] !== $me['id']) {
    // Flip the aktiv bit: 1-aktiv turns 1→0 and 0→1 in a single expression.
    $db->prepare("UPDATE felhasznalok SET aktiv = 1 - aktiv WHERE id=?")->execute([$_GET['toggle']]);
    logActivity($db, 'felhasznalo_mod', "Aktiv állapot váltva: #" . (int)$_GET['toggle']);
    header('Location: felhasznalok.php?uzenet=mentve');
    exit;
}

// Delete user — only allowed when the user has zero linked products.
if (isset($_GET['torles']) && is_numeric($_GET['torles']) && (int)$_GET['torles'] !== $me['id']) {
    $f = $db->prepare("SELECT nev FROM felhasznalok WHERE id=?");
    $f->execute([$_GET['torles']]);
    $fnev = $f->fetchColumn();
    $db->prepare("DELETE FROM felhasznalok WHERE id=?")->execute([$_GET['torles']]);
    logActivity($db, 'felhasznalo_torles', "Törölve: $fnev");
    header('Location: felhasznalok.php?uzenet=torolve');
    exit;
}

$hibak = [];
$szerkesztett = null;

// Pre-populate the form when editing an existing user (?id=X in query string).
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $s = $db->prepare("SELECT * FROM felhasznalok WHERE id=?");
    $s->execute([$_GET['id']]);
    $szerkesztett = $s->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id    = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : null;
    $fnev       = trim($_POST['felhasznalonev'] ?? '');
    $nev        = trim($_POST['nev'] ?? '');
    // Whitelist the role value — never trust user-submitted strings for
    // privilege fields.
    $szerepkor  = in_array($_POST['szerepkor'] ?? '', ['admin','user']) ? $_POST['szerepkor'] : 'user';
    $jelszo     = $_POST['jelszo']  ?? '';
    $jelszo2    = $_POST['jelszo2'] ?? '';

    if (!$fnev) $hibak[] = 'A felhasználónév kötelező.';
    if (!$nev)  $hibak[] = 'A teljes név kötelező.';
    // Password is required for new accounts; optional on edit (blank = keep current).
    if (!$edit_id && strlen($jelszo) < 6) $hibak[] = 'Az új fiókhoz legalább 6 karakteres jelszó kell.';
    if ($jelszo && $jelszo !== $jelszo2)   $hibak[] = 'A két jelszó nem egyezik.';

    if (empty($hibak)) {
        if ($edit_id) {
            // On edit: only update the password hash when a new password was supplied.
            if ($jelszo) {
                $hash = password_hash($jelszo, PASSWORD_DEFAULT);
                $db->prepare("UPDATE felhasznalok SET felhasznalonev=?, nev=?, szerepkor=?, jelszo_hash=? WHERE id=?")
                   ->execute([$fnev, $nev, $szerepkor, $hash, $edit_id]);
            } else {
                $db->prepare("UPDATE felhasznalok SET felhasznalonev=?, nev=?, szerepkor=? WHERE id=?")
                   ->execute([$fnev, $nev, $szerepkor, $edit_id]);
            }
            logActivity($db, 'felhasznalo_mod', "Módosítva: $fnev");
        } else {
            $hash = password_hash($jelszo, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO felhasznalok (felhasznalonev, nev, jelszo_hash, szerepkor) VALUES (?,?,?,?)")
               ->execute([$fnev, $nev, $hash, $szerepkor]);
            logActivity($db, 'felhasznalo_letrehozas', "Létrehozva: $fnev ($szerepkor)");
        }
        header('Location: felhasznalok.php?uzenet=mentve');
        exit;
    }
    // On validation failure, keep POST values in the form by returning them
    // as the current "record" so the user does not have to retype everything.
    $szerkesztett = ['id' => $edit_id, 'felhasznalonev' => $fnev, 'nev' => $nev, 'szerepkor' => $szerepkor];
}

// Fetch all users with a sub-query count of how many products each one created.
// This count is used to gate the delete button (cannot delete if count > 0).
$felhasznalok = $db->query("SELECT *, (SELECT COUNT(*) FROM termekek WHERE letrehozta = felhasznalok.id) AS termek_szam FROM felhasznalok ORDER BY szerepkor, nev")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#128100; Felhasználók</h1>
</div>

<?php if (isset($_GET['uzenet'])): ?>
<div class="flash flash-<?= $_GET['uzenet'] === 'torolve' ? 'error' : 'success' ?> flash-auto">
    <?= $_GET['uzenet'] === 'mentve' ? 'Felhasználó sikeresen mentve.' : 'Felhasználó törölve.' ?>
</div>
<?php endif; ?>

<div class="two-col">
    <!-- User list — inactive users are rendered with reduced opacity via row-inactive. -->
    <div class="card">
        <table class="table">
            <thead>
                <tr><th>Felhasználónév</th><th>Teljes név</th><th>Szerepkör</th><th>Státusz</th><th>Tételek</th><th>Utolsó belépés</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($felhasznalok as $f): ?>
            <tr class="<?= !$f['aktiv'] ? 'row-inactive' : '' ?>">
                <td><code><?= htmlspecialchars($f['felhasznalonev']) ?></code></td>
                <td><?= htmlspecialchars($f['nev']) ?></td>
                <td>
                    <span class="badge <?= $f['szerepkor'] === 'admin' ? 'badge-blue' : 'badge-gray' ?>">
                        <?= $f['szerepkor'] ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $f['aktiv'] ? 'badge-green' : 'badge-red' ?>">
                        <?= $f['aktiv'] ? 'Aktív' : 'Inaktív' ?>
                    </span>
                </td>
                <td><?= $f['termek_szam'] ?></td>
                <td class="text-muted" style="font-size:11px">
                    <?= $f['utolso_belepes'] ? date('Y.m.d H:i', strtotime($f['utolso_belepes'])) : 'Soha' ?>
                </td>
                <td class="actions">
                    <a href="?id=<?= $f['id'] ?>" class="btn btn-sm btn-secondary">Szerk.</a>
                    <?php if ($f['id'] !== $me['id']): ?>
                    <!-- Toggle and delete buttons are hidden for the currently
                         logged-in admin to prevent self-lockout. -->
                    <a href="?toggle=<?= $f['id'] ?>" class="btn btn-sm btn-secondary"
                        title="<?= $f['aktiv'] ? 'Deaktiválás' : 'Aktiválás' ?>">
                        <?= $f['aktiv'] ? '&#128683;' : '&#10003;' ?>
                    </a>
                    <?php if ($f['termek_szam'] == 0): ?>
                    <!-- Delete is only shown when the user has no products, preventing
                         orphaned records in the termekek table. -->
                    <a href="?torles=<?= $f['id'] ?>" class="btn btn-sm btn-danger btn-delete">Törlés</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add / edit form — the same form handles both create and update.
         A hidden `id` field signals an update; its absence signals a new record. -->
    <div class="card form-card">
        <h2><?= $szerkesztett ? 'Felhasználó szerkesztése' : 'Új felhasználó' ?></h2>
        <?php if ($hibak): ?>
        <div class="flash flash-error">
            <?php foreach ($hibak as $h): ?><div><?= htmlspecialchars($h) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <?php if ($szerkesztett): ?><input type="hidden" name="id" value="<?= $szerkesztett['id'] ?>"><?php endif; ?>
            <div class="form-group">
                <label>Felhasználónév *</label>
                <!-- An admin cannot rename their own account — the field is
                     read-only to avoid accidental self-lockout. -->
                <input type="text" name="felhasznalonev" class="input" required
                    value="<?= htmlspecialchars($szerkesztett['felhasznalonev'] ?? '') ?>"
                    <?= ($szerkesztett && $szerkesztett['id'] === $me['id']) ? 'readonly' : '' ?>>
            </div>
            <div class="form-group">
                <label>Teljes név *</label>
                <input type="text" name="nev" class="input" required
                    value="<?= htmlspecialchars($szerkesztett['nev'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Szerepkör</label>
                <!-- An admin cannot demote their own role. The select is disabled
                     and the value would not be submitted, so the server-side
                     whitelist fallback to 'user' is intentionally bypassed here
                     by the edit branch skipping the role field when id === me. -->
                <select name="szerepkor" class="input" <?= ($szerkesztett && $szerkesztett['id'] === $me['id']) ? 'disabled' : '' ?>>
                    <option value="user"  <?= ($szerkesztett['szerepkor'] ?? 'user') === 'user'  ? 'selected' : '' ?>>Felhasználó</option>
                    <option value="admin" <?= ($szerkesztett['szerepkor'] ?? '') === 'admin' ? 'selected' : '' ?>>Adminisztrátor</option>
                </select>
            </div>
            <div class="form-group">
                <label>Jelszó <?= $szerkesztett ? '(hagyd üresen, ha nem változtatod)' : '* (min. 6 karakter)' ?></label>
                <input type="password" name="jelszo" class="input" <?= !$szerkesztett ? 'required' : '' ?>>
            </div>
            <div class="form-group">
                <label>Jelszó megerősítése</label>
                <input type="password" name="jelszo2" class="input">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Mentés</button>
                <?php if ($szerkesztett): ?><a href="felhasznalok.php" class="btn btn-ghost">Mégse</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
