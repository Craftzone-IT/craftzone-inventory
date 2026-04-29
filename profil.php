<?php
/**
 * User profile page — lets the currently logged-in user change their password.
 *
 * Changing the username or role is intentionally not possible here; those
 * operations are admin-only and handled in felhasznalok.php.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
requireLogin();
$page_title = 'Profil';
$db   = getDB();
$me   = currentUser();
$hibak = [];
$siker = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $regi   = $_POST['regi_jelszo'] ?? '';
    $uj     = $_POST['uj_jelszo']   ?? '';
    $uj2    = $_POST['uj_jelszo2']  ?? '';

    // Require the current password before accepting a change — this prevents
    // someone who finds an unlocked screen from silently setting a new password.
    if (!$regi) {
        $hibak[] = 'A régi jelszó megadása kötelező.';
    } else {
        $stmt = $db->prepare("SELECT jelszo_hash FROM felhasznalok WHERE id=?");
        $stmt->execute([$me['id']]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($regi, $hash)) {
            $hibak[] = 'A régi jelszó helytelen.';
        }
    }

    // Enforce a minimum length and require the confirmation to match before
    // touching the database. All three conditions are checked independently so
    // the user sees every problem at once rather than one at a time.
    if (strlen($uj) < 6)    $hibak[] = 'Az új jelszó legalább 6 karakter legyen.';
    if ($uj !== $uj2)        $hibak[] = 'A két új jelszó nem egyezik.';

    if (empty($hibak)) {
        // password_hash() uses bcrypt by default, producing a new random salt
        // every call, so the same plaintext never produces the same hash twice.
        $db->prepare("UPDATE felhasznalok SET jelszo_hash=? WHERE id=?")
           ->execute([password_hash($uj, PASSWORD_DEFAULT), $me['id']]);
        logActivity($db, 'jelszo_csere', 'Jelszó megváltoztatva.');
        $siker = 'Jelszó sikeresen megváltoztatva.';
    }
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>&#9881; Profil</h1>
</div>

<div class="two-col">
    <!-- Read-only account info panel — gives the user context about who they
         are logged in as and what permissions they hold. -->
    <div class="card form-card">
        <h2 style="margin-bottom:16px">Fiók adatok</h2>
        <table class="table">
            <tr><th style="width:140px">Felhasználónév</th><td><code><?= htmlspecialchars($me['felhasznalonev']) ?></code></td></tr>
            <tr><th>Teljes név</th><td><?= htmlspecialchars($me['nev']) ?></td></tr>
            <tr><th>Szerepkör</th><td><span class="badge <?= $me['szerepkor'] === 'admin' ? 'badge-blue' : 'badge-gray' ?>"><?= $me['szerepkor'] ?></span></td></tr>
        </table>
    </div>

    <!-- Password-change form. Errors stay visible (no flash-auto class) so the
         user has time to read all validation messages before re-submitting. -->
    <div class="card form-card">
        <h2 style="margin-bottom:16px">Jelszó megváltoztatása</h2>
        <?php if ($siker): ?>
        <div class="flash flash-success flash-auto"><?= htmlspecialchars($siker) ?></div>
        <?php endif; ?>
        <?php if ($hibak): ?>
        <div class="flash flash-error">
            <?php foreach ($hibak as $h): ?><div><?= htmlspecialchars($h) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label>Jelenlegi jelszó *</label>
                <input type="password" name="regi_jelszo" class="input" required>
            </div>
            <div class="form-group">
                <label>Új jelszó * (min. 6 karakter)</label>
                <input type="password" name="uj_jelszo" class="input" required>
            </div>
            <div class="form-group">
                <label>Új jelszó megerősítése *</label>
                <input type="password" name="uj_jelszo2" class="input" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Jelszó megváltoztatása</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
