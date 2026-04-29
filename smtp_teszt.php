<?php
/**
 * SMTP test endpoint — admin-only AJAX handler.
 *
 * Accepts a POST request with the current SMTP form values, attempts to send
 * a test email using those settings (without saving them to the database), and
 * returns a JSON response indicating success or failure.
 *
 * Called from beallitasok.php via fetch(). Always responds with JSON.
 */
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/smtp_mailer.php';

header('Content-Type: application/json; charset=UTF-8');

// Reject non-admin or unauthenticated requests.
if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['ok' => false, 'msg' => 'Nincs jogosultság.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Csak POST kérés fogadható el.']);
    exit;
}

$db = getDB();

// Read parameters from POST. If the password field is blank, fall back to the
// currently saved (encrypted) password from the database.
$host     = trim($_POST['smtp_host']    ?? '');
$port     = (int)($_POST['smtp_port']   ?? 587);
$secure   = trim($_POST['smtp_secure']  ?? 'tls');
$user     = trim($_POST['smtp_user']    ?? '');
$passRaw  = $_POST['smtp_pass']         ?? '';   // empty = "unchanged"
$from     = trim($_POST['smtp_from']    ?? '');
$fromName = trim($_POST['smtp_from_name'] ?? 'Raktárkészlet kezelő');
$to       = trim($_POST['smtp_to']      ?? '');

// Validate minimum required fields.
if (!$host || !$from || !$to) {
    echo json_encode(['ok' => false, 'msg' => 'SMTP szerver, Feladó email és Értesítési email mezők megadása kötelező.']);
    exit;
}

// Resolve password: if the form field is blank use the saved encrypted password.
if ($passRaw === '') {
    $savedEnc = getConfig($db, 'smtp_pass_enc');
    $pass     = decryptSmtpPassword($savedEnc);
} else {
    $pass = $passRaw;
}

// Whitelist the secure / encryption mode value.
if (!in_array($secure, ['ssl', 'tls', 'none', ''])) {
    $secure = 'tls';
}

$appNev = getConfig($db, 'app_nev', 'Raktárkészlet kezelő');
$user_info = currentUser();

$subject = "[$appNev] SMTP teszt email";
$html    = buildEmailHtml(
    $appNev,
    'Ez egy teszt értesítő',
    [
        'Küldési idő'   => date('Y-m-d H:i:s'),
        'Tesztelő'      => $user_info['nev'] ?? $user_info['felhasznalonev'] ?? '–',
        'SMTP szerver'  => $host . ':' . $port . ' (' . strtoupper($secure ?: 'PLAIN') . ')',
    ],
    'Teszt email',
    'badge-blue'
);

try {
    $mailer = new SmtpMailer($host, $port, $secure === 'none' ? '' : $secure, $user, $pass);
    $mailer->send($from, $fromName, $to, $subject, $html);

    logActivity($db, 'smtp_teszt', "Teszt email sikeresen elküldve → $to");
    echo json_encode(['ok' => true, 'msg' => "Teszt email sikeresen elküldve: $to"]);

} catch (Exception $e) {
    logActivity($db, 'smtp_teszt', 'Teszt email sikertelen: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
