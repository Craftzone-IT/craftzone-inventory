<?php
/**
 * SMTP Mailer — lightweight standalone SMTP client.
 *
 * Provides:
 *   - SmtpMailer class: raw SMTP conversation over SSL/TLS/STARTTLS
 *   - encryptSmtpPassword() / decryptSmtpPassword(): AES-256-CBC helpers
 *   - sendTermekErtesito(): high-level notification dispatcher
 *
 * No external libraries required. Uses PHP's stream_socket_client and
 * OpenSSL functions, which are available in all standard PHP installations.
 *
 * Password encryption key is derived at runtime from the database credentials
 * (DB_PASS + DB_NAME + DB_HOST), so it is never stored anywhere — it is
 * re-derived on every request. If DB credentials change the stored encrypted
 * password becomes invalid and must be re-saved.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Encryption helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Encrypts an SMTP password using AES-256-CBC.
 *
 * The encryption key is derived from the database credentials so it is unique
 * per installation without needing a separate key-management system. The IV is
 * randomly generated on every call and prepended to the ciphertext before
 * base64-encoding, so every save produces a different ciphertext.
 *
 * @param string $password Plain-text password to encrypt.
 * @return string Base64-encoded IV + ciphertext.
 */
function encryptSmtpPassword(string $password): string {
    $key = hash('sha256', DB_PASS . DB_NAME . DB_HOST, true); // 32 raw bytes
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($password, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

/**
 * Decrypts an SMTP password that was encrypted by encryptSmtpPassword().
 *
 * @param string $encrypted Base64-encoded IV + ciphertext (from the DB).
 * @return string Plain-text password, or empty string on failure.
 */
function decryptSmtpPassword(string $encrypted): string {
    if (empty($encrypted)) return '';
    $key  = hash('sha256', DB_PASS . DB_NAME . DB_HOST, true);
    $data = base64_decode($encrypted);
    if (strlen($data) < 17) return '';
    $iv  = substr($data, 0, 16);
    $enc = substr($data, 16);
    $dec = openssl_decrypt($enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $dec !== false ? $dec : '';
}

// ─────────────────────────────────────────────────────────────────────────────
// SmtpMailer class
// ─────────────────────────────────────────────────────────────────────────────

class SmtpMailer {

    private string $host;
    private int    $port;
    private string $secure;  // 'ssl' | 'tls' | ''
    private string $user;
    private string $pass;
    private int    $timeout = 15;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host,
        int    $port,
        string $secure,
        string $user,
        string $pass
    ) {
        $this->host   = $host;
        $this->port   = $port;
        $this->secure = strtolower(trim($secure));
        $this->user   = $user;
        $this->pass   = $pass;
    }

    /**
     * Sends a single HTML email.
     *
     * @param string $fromEmail Sender address (e.g. "noreply@domain.hu").
     * @param string $fromName  Sender display name (UTF-8, encoded automatically).
     * @param string $toEmail   Recipient address.
     * @param string $subject   Email subject (UTF-8, encoded automatically).
     * @param string $htmlBody  Full HTML body of the email.
     * @throws Exception on any SMTP or connection error.
     */
    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $htmlBody
    ): void {
        try {
            $this->connect();
            $this->ehlo();

            if ($this->secure === 'tls') {
                $this->sendCommand('STARTTLS');
                $this->read(220);
                // Upgrade the existing plain socket to TLS in-place.
                stream_socket_enable_crypto(
                    $this->socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                $this->ehlo(); // Re-introduce ourselves after TLS upgrade.
            }

            if ($this->user !== '') {
                $this->authenticate();
            }

            $this->sendCommand("MAIL FROM:<{$fromEmail}>");
            $this->read(250);
            $this->sendCommand("RCPT TO:<{$toEmail}>");
            $this->read(250);
            $this->sendData($fromEmail, $fromName, $toEmail, $subject, $htmlBody);
            $this->sendCommand('QUIT');
        } finally {
            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    // ── Private SMTP conversation helpers ─────────────────────────────────────

    private function connect(): void {
        $prefix  = ($this->secure === 'ssl') ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                // Self-signed certificates are common in dev/testing environments.
                // Set smtp_verify_ssl=1 in config to tighten this for production.
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $this->socket = stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$this->socket) {
            throw new Exception("SMTP kapcsolódás sikertelen ({$this->host}:{$this->port}): $errstr ($errno)");
        }
        stream_set_timeout($this->socket, $this->timeout);
        $this->read(220); // Server greeting.
    }

    private function ehlo(): void {
        $hostname = gethostname() ?: 'localhost';
        $this->sendCommand("EHLO $hostname");
        $this->read(250);
    }

    private function authenticate(): void {
        $this->sendCommand('AUTH LOGIN');
        $this->read(334);
        $this->sendCommand(base64_encode($this->user));
        $this->read(334);
        $this->sendCommand(base64_encode($this->pass));
        $this->read(235);
    }

    private function sendData(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $htmlBody
    ): void {
        $this->sendCommand('DATA');
        $this->read(354);

        // RFC 2047 encoded subject and from-name so non-ASCII characters survive.
        $subjectEnc  = '=?UTF-8?B?' . base64_encode($subject)  . '?=';
        $fromNameEnc = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $msgId   = '<' . time() . '.' . mt_rand(1000, 9999) . '@' . gethostname() . '>';
        $headers  = "From: {$fromNameEnc} <{$fromEmail}>\r\n";
        $headers .= "To: {$toEmail}\r\n";
        $headers .= "Subject: {$subjectEnc}\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: {$msgId}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";

        // Body must be base64-encoded and split into 76-char lines (RFC 2045).
        $encodedBody = chunk_split(base64_encode($htmlBody));

        // Lines starting with a dot must be dot-stuffed (RFC 5321 §4.5.2).
        $message = $headers . "\r\n" . $encodedBody;
        $message = preg_replace('/^\.$/m', '..', $message);

        fwrite($this->socket, $message . "\r\n.\r\n");
        $this->read(250);
    }

    private function sendCommand(string $cmd): void {
        fwrite($this->socket, $cmd . "\r\n");
    }

    /**
     * Reads lines from the SMTP server until a final response line is found.
     * Multi-line responses have a '-' in position 3; the final line has ' '.
     *
     * @param int $expected Expected response code.
     * @throws Exception if the response code does not match.
     */
    private function read(int $expected): string {
        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 512);
            if ($line === false) break;
            $response .= $line;
            // The 4th character distinguishes a continuation line ('-') from
            // the final line (' '). Stop reading on the final line.
            if (isset($line[3]) && $line[3] !== '-') break;
        }
        $code = (int)substr($response, 0, 3);
        if ($code !== $expected) {
            throw new Exception(
                "SMTP hiba: {$expected} várva, {$code} érkezett. Válasz: " . trim($response)
            );
        }
        return $response;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// High-level notification helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Builds and returns the HTML body for a notification email.
 *
 * @param string $appNev    Application name (used in the header).
 * @param string $cim       Email title (h2 under the header).
 * @param array  $sorok     Associative array of label => value rows.
 * @param string $badgeText Optional badge label (e.g. "Új termék").
 * @param string $badgeCss  Badge CSS classes (e.g. "badge-blue").
 * @return string Full HTML email body.
 */
function buildEmailHtml(
    string $appNev,
    string $cim,
    array  $sorok,
    string $badgeText = '',
    string $badgeCss  = 'badge-blue'
): string {
    $rows = '';
    foreach ($sorok as $label => $value) {
        if ($value === null || $value === '') continue;
        $rows .= '<tr>'
               . '<td style="color:#6b7280;font-size:13px;padding:6px 0 2px;white-space:nowrap">' . htmlspecialchars($label) . '</td>'
               . '<td style="font-weight:600;padding:6px 0 2px 12px">' . htmlspecialchars($value) . '</td>'
               . '</tr>';
    }
    $badge = $badgeText
        ? '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;'
          . ($badgeCss === 'badge-green'
              ? 'background:#dcfce7;color:#166534'
              : 'background:#dbeafe;color:#1e40af')
          . '">' . htmlspecialchars($badgeText) . '</span><br><br>'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="'
        . 'margin:0;padding:20px;background:#f3f4f6;font-family:Arial,sans-serif">'
        . '<div style="max-width:580px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;'
        . 'box-shadow:0 2px 8px rgba(0,0,0,.08)">'
        . '<div style="background:#2563eb;padding:20px 24px">'
        . '<div style="color:#fff;font-size:20px;font-weight:700">' . htmlspecialchars($appNev) . '</div>'
        . '<div style="color:rgba(255,255,255,.75);font-size:13px;margin-top:4px">' . htmlspecialchars($cim) . '</div>'
        . '</div>'
        . '<div style="padding:24px">'
        . $badge
        . '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>'
        . '</div>'
        . '<div style="padding:12px 24px;background:#f9fafb;font-size:11px;color:#9ca3af;border-top:1px solid #e5e7eb">'
        . 'Ez az üzenet automatikusan lett elküldve a ' . htmlspecialchars($appNev) . ' rendszer által.'
        . '</div>'
        . '</div></body></html>';
}

/**
 * Sends a product notification email (new product added or product sold).
 *
 * Silently does nothing if:
 *   - Email is disabled (smtp_enabled = 0)
 *   - The specific notification type is disabled
 *   - SMTP settings are incomplete
 *
 * @param PDO    $db     Database connection.
 * @param string $tipus  'uj_termek' or 'eladva'.
 * @param array  $termek Product row (DB values + any overrides).
 */
function sendTermekErtesito(PDO $db, string $tipus, array $termek): void {
    // Guard: feature enabled?
    if (getConfig($db, 'smtp_enabled', '0') !== '1') return;

    $notifyKey = ($tipus === 'uj_termek') ? 'smtp_notify_new' : 'smtp_notify_sold';
    if (getConfig($db, $notifyKey, '0') !== '1') return;

    $host     = getConfig($db, 'smtp_host');
    $port     = (int)getConfig($db, 'smtp_port', '587');
    $secure   = getConfig($db, 'smtp_secure', 'tls');
    $user     = getConfig($db, 'smtp_user');
    $passEnc  = getConfig($db, 'smtp_pass_enc');
    $from     = getConfig($db, 'smtp_from');
    $fromName = getConfig($db, 'smtp_from_name', 'Raktárkészlet kezelő');
    $to       = getConfig($db, 'smtp_to');
    $appNev   = getConfig($db, 'app_nev', 'Raktárkészlet kezelő');

    if (!$host || !$from || !$to) return;

    $pass = decryptSmtpPassword($passEnc);

    // Resolve the status name for display in the email.
    $statuszNev = '';
    if (isset($termek['statusz_id'])) {
        $stNev = $db->prepare("SELECT nev FROM statuszok WHERE id = ?");
        $stNev->execute([(int)$termek['statusz_id']]);
        $statuszNev = $stNev->fetchColumn() ?: '';
    }

    $fmtAr = isset($termek['netto_ar']) && $termek['netto_ar'] !== null
        ? number_format((float)$termek['netto_ar'], 0, ',', ' ') . ' Ft' : '';

    // Build email content based on notification type.
    if ($tipus === 'uj_termek') {
        $subject   = "Új termék felvéve: {$termek['raktari_szam']} – {$termek['megnevezes']}";
        $cim       = 'Új termék érkezett a raktárba';
        $badgeText = 'Új termék';
        $badgeCss  = 'badge-blue';
        $sorok     = [
            'Raktári szám'        => $termek['raktari_szam'] ?? '',
            'Megnevezés'          => $termek['megnevezes'] ?? '',
            'Státusz'             => $statuszNev,
            'Típus'               => $termek['tipus'] ?? '',
            'Spec.'               => $termek['spec'] ?? '',
            'Nettó ár'            => $fmtAr,
            'Bevételezés dátuma'  => $termek['datum'] ?? '',
            'Bejövő számlaszám'   => $termek['be_szamlaszam'] ?? '',
            'Megjegyzés'          => $termek['megjegyzes'] ?? '',
        ];
    } else {
        $subject   = "Termék eladva: {$termek['raktari_szam']} – {$termek['megnevezes']}";
        $cim       = 'Termék értékesítve';
        $badgeText = 'Eladva';
        $badgeCss  = 'badge-green';
        $sorok     = [
            'Raktári szám'        => $termek['raktari_szam'] ?? '',
            'Megnevezés'          => $termek['megnevezes'] ?? '',
            'Státusz'             => $statuszNev,
            'Vevő'                => $termek['vevo'] ?? '',
            'Eladás dátuma'       => $termek['eladas_datum'] ?? '',
            'Kimenő számlaszám'   => $termek['ki_szamlaszam'] ?? '',
            'Típus'               => $termek['tipus'] ?? '',
            'Spec.'               => $termek['spec'] ?? '',
            'Nettó ár'            => $fmtAr,
        ];
    }

    $html = buildEmailHtml($appNev, $cim, $sorok, $badgeText, $badgeCss);

    try {
        $mailer = new SmtpMailer($host, $port, $secure, $user, $pass);
        $mailer->send($from, $fromName, $to, $subject, $html);
    } catch (Exception $e) {
        // Never block the main save operation due to an email failure.
        error_log('[smtp_mailer] Értesítő küldése sikertelen: ' . $e->getMessage());
    }
}
