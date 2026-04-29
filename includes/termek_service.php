<?php
declare(strict_types=1);

/**
 * Product service — centralised business logic for product CRUD.
 *
 * This module contains validation, creation, and update logic extracted from
 * termek_form.php and termekek.php. It does NOT handle sessions, HTTP
 * responses, or any presentation concern — it is pure business logic that
 * can be called from web forms, AJAX endpoints, or a future API layer.
 *
 * Dependencies (must be loaded before including this file):
 *   - config/db.php      (getDB, getConfig, generateRaktariSzam)
 *   - includes/auth.php   (logActivity)
 *   - includes/smtp_mailer.php (sendTermekErtesito)
 */

// ─────────────────────────────────────────────────────────────────────────────
// Validation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validates product input data.
 *
 * Returns an array of human-readable error strings. An empty array means the
 * data is valid. Does NOT touch the database — purely structural checks.
 *
 * @param  array $data Associative array with product field values.
 * @return string[] Validation error messages (empty = OK).
 */
function validateTermek(array $data): array
{
    $hibak = [];

    // ── Required fields ──────────────────────────────────────────────────
    $megnevezes = trim($data['megnevezes'] ?? '');
    if ($megnevezes === '') {
        $hibak[] = 'A megnevezés megadása kötelező.';
    } elseif (mb_strlen($megnevezes) > 300) {
        $hibak[] = 'A megnevezés maximum 300 karakter lehet.';
    }

    // ── Optional field length checks ─────────────────────────────────────
    if (mb_strlen(trim($data['be_szamlaszam'] ?? '')) > 100) {
        $hibak[] = 'A bejövő számlaszám maximum 100 karakter lehet.';
    }
    if (mb_strlen(trim($data['ki_szamlaszam'] ?? '')) > 100) {
        $hibak[] = 'A kimenő számlaszám maximum 100 karakter lehet.';
    }
    if (mb_strlen(trim($data['vevo'] ?? '')) > 200) {
        $hibak[] = 'A vevő neve maximum 200 karakter lehet.';
    }
    if (mb_strlen(trim($data['tipus'] ?? '')) > 100) {
        $hibak[] = 'A típus maximum 100 karakter lehet.';
    }
    if (mb_strlen(trim($data['spec'] ?? '')) > 100) {
        $hibak[] = 'A specifikáció maximum 100 karakter lehet.';
    }

    // ── Price validation ─────────────────────────────────────────────────
    $nettoAr = $data['netto_ar'] ?? null;
    if ($nettoAr !== null && $nettoAr !== '') {
        $nettoAr = str_replace(',', '.', (string)$nettoAr);
        if (!is_numeric($nettoAr)) {
            $hibak[] = 'A nettó ár csak szám lehet.';
        } elseif ((float)$nettoAr < 0) {
            $hibak[] = 'A nettó ár nem lehet negatív.';
        }
    }

    // ── Date validation ──────────────────────────────────────────────────
    $datum = $data['datum'] ?? null;
    if ($datum !== null && $datum !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$datum)) {
        $hibak[] = 'Érvénytelen dátum formátum (ÉÉÉÉ-HH-NN).';
    }
    $eladasDatum = $data['eladas_datum'] ?? null;
    if ($eladasDatum !== null && $eladasDatum !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$eladasDatum)) {
        $hibak[] = 'Érvénytelen eladási dátum formátum (ÉÉÉÉ-HH-NN).';
    }

    // ── Status ID ────────────────────────────────────────────────────────
    $statuszId = $data['statusz_id'] ?? null;
    if ($statuszId !== null && $statuszId !== '' && (!is_numeric($statuszId) || (int)$statuszId < 1)) {
        $hibak[] = 'Érvénytelen státusz azonosító.';
    }

    return $hibak;
}

// ─────────────────────────────────────────────────────────────────────────────
// Supplier resolution
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolves a supplier reference to a szallitok.id.
 *
 * Accepts either a numeric ID (existing supplier) or a free-text name. When a
 * name is given that does not exist yet, a new supplier row is created
 * automatically and the action is logged.
 *
 * @param  PDO         $db           Database connection.
 * @param  int|null    $szallitoId   Existing supplier ID (from dropdown), or null.
 * @param  string|null $szallitoNev  Free-text supplier name (from combobox), or null.
 * @return int|null    Resolved supplier ID, or null when neither input was provided.
 */
function resolveSzallito(PDO $db, ?int $szallitoId, ?string $szallitoNev): ?int
{
    if ($szallitoId !== null && $szallitoId > 0) {
        return $szallitoId;
    }

    $nev = $szallitoNev !== null ? trim($szallitoNev) : '';
    if ($nev === '') {
        return null;
    }

    // Check if a supplier with this exact name already exists.
    $stmt = $db->prepare("SELECT id FROM szallitok WHERE nev = ? LIMIT 1");
    $stmt->execute([$nev]);
    $existId = $stmt->fetchColumn();

    if ($existId) {
        return (int)$existId;
    }

    // Create a new supplier on the fly.
    $db->prepare("INSERT INTO szallitok (nev, aktiv) VALUES (?, 1)")->execute([$nev]);
    $newId = (int)$db->lastInsertId();
    logActivity($db, 'szallito_hozzaadas', "Auto: $nev");

    return $newId;
}

// ─────────────────────────────────────────────────────────────────────────────
// Data normalisation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Normalises raw input data into the internal format expected by the DB layer.
 *
 * Converts empty strings to null, normalises decimal separators, and casts
 * boolean flag fields. The caller should pass the raw (trimmed) user input.
 *
 * @param  array $data Raw input data.
 * @return array Normalised data ready for DB binding.
 */
function normalizeTermekData(array $data): array
{
    $str = function ($key) use ($data): ?string {
        $v = trim((string)($data[$key] ?? ''));
        return $v !== '' ? $v : null;
    };

    return [
        'datum'          => $str('datum'),
        'be_szamlaszam'  => $str('be_szamlaszam'),
        'szallito_id'    => isset($data['szallito_id']) && $data['szallito_id'] !== null && $data['szallito_id'] !== ''
                            ? (int)$data['szallito_id']
                            : null,
        'megnevezes'     => trim((string)($data['megnevezes'] ?? '')),
        'netto_ar'       => (isset($data['netto_ar']) && $data['netto_ar'] !== null && $data['netto_ar'] !== '')
                            ? str_replace(',', '.', (string)$data['netto_ar'])
                            : null,
        'tipus'          => $str('tipus'),
        'spec'           => $str('spec'),
        'megjegyzes'     => $str('megjegyzes'),
        'statusz_id'     => (int)($data['statusz_id'] ?? 1),
        'vevo'           => $str('vevo'),
        'eladas_datum'   => $str('eladas_datum'),
        'ki_szamlaszam'  => $str('ki_szamlaszam'),
        'archivalható'   => !empty($data['archivalható']) ? 1 : 0,
        'ellenorzott'    => !empty($data['ellenorzott'])  ? 1 : 0,
        'leltar'         => !empty($data['leltar'])       ? 1 : 0,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Create
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Creates a new product.
 *
 * Validates the input, resolves the supplier, generates the stock number,
 * inserts the row, logs the action, and sends an email notification.
 *
 * @param  PDO   $db     Database connection.
 * @param  array $data   Raw product data (same keys as normalizeTermekData expects).
 *                        Additionally accepts 'szallito_nev' for auto-creation.
 * @param  int   $userId ID of the user performing the action.
 * @return array{ok: bool, hibak?: string[], id?: int, raktari_szam?: string}
 */
function createTermek(PDO $db, array $data, int $userId): array
{
    // Resolve supplier before normalisation so the ID is available.
    $szallitoId  = isset($data['szallito_id']) && is_numeric($data['szallito_id']) && (int)$data['szallito_id'] > 0
                   ? (int)$data['szallito_id']
                   : null;
    $szallitoNev = $data['szallito_nev'] ?? null;
    $data['szallito_id'] = resolveSzallito($db, $szallitoId, $szallitoNev);

    $adat  = normalizeTermekData($data);
    $hibak = validateTermek($adat);

    if (!empty($hibak)) {
        return ['ok' => false, 'hibak' => $hibak];
    }

    // Generate the stock number at insert time to prevent gaps.
    $raktariSzam = generateRaktariSzam($db);

    $db->prepare("
        INSERT INTO termekek
        (raktari_szam, datum, be_szamlaszam, szallito_id, megnevezes, netto_ar,
         tipus, spec, megjegyzes, statusz_id, vevo, eladas_datum, ki_szamlaszam,
         archivalható, ellenorzott, leltar, letrehozta)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $raktariSzam,
        $adat['datum'],
        $adat['be_szamlaszam'],
        $adat['szallito_id'],
        $adat['megnevezes'],
        $adat['netto_ar'],
        $adat['tipus'],
        $adat['spec'],
        $adat['megjegyzes'],
        $adat['statusz_id'],
        $adat['vevo'],
        $adat['eladas_datum'],
        $adat['ki_szamlaszam'],
        $adat['archivalható'],
        $adat['ellenorzott'],
        $adat['leltar'],
        $userId,
    ]);

    $newId = (int)$db->lastInsertId();
    logActivity($db, 'termek_felvetel', "Felvéve: $raktariSzam – {$adat['megnevezes']}", $newId);

    // Send "new product" email notification (silently skipped when disabled).
    $termekErtesito = array_merge($adat, ['raktari_szam' => $raktariSzam]);
    sendTermekErtesito($db, 'uj_termek', $termekErtesito);

    return [
        'ok'           => true,
        'id'           => $newId,
        'raktari_szam' => $raktariSzam,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Updates an existing product.
 *
 * Validates the input, resolves the supplier, updates the row, logs the
 * action, and sends an "eladva" notification when the status transitions to
 * "sold" for the first time.
 *
 * @param  PDO   $db       Database connection.
 * @param  int   $termekId The product's primary key.
 * @param  array $data     Raw product data (same keys as normalizeTermekData).
 *                          Additionally accepts 'szallito_nev' for auto-creation.
 * @param  int   $userId   ID of the user performing the action.
 * @return array{ok: bool, hibak?: string[], id?: int, raktari_szam?: string}
 */
function updateTermek(PDO $db, int $termekId, array $data, int $userId): array
{
    // Verify the product exists.
    $existing = $db->prepare("SELECT * FROM termekek WHERE id = ?");
    $existing->execute([$termekId]);
    $existing = $existing->fetch();

    if (!$existing) {
        return ['ok' => false, 'hibak' => ['A termék nem található.']];
    }

    // Resolve supplier before normalisation.
    $szallitoId  = isset($data['szallito_id']) && is_numeric($data['szallito_id']) && (int)$data['szallito_id'] > 0
                   ? (int)$data['szallito_id']
                   : null;
    $szallitoNev = $data['szallito_nev'] ?? null;
    $data['szallito_id'] = resolveSzallito($db, $szallitoId, $szallitoNev);

    $adat  = normalizeTermekData($data);
    $hibak = validateTermek($adat);

    if (!empty($hibak)) {
        return ['ok' => false, 'hibak' => $hibak];
    }

    // Detect "eladva" status transition for email notification.
    $eladvaStatuszId = $db->query("SELECT id FROM statuszok WHERE nev = 'eladva' LIMIT 1")->fetchColumn() ?: 4;
    $voltEladva = ((int)($existing['statusz_id'] ?? 0)) === (int)$eladvaStatuszId;

    $db->prepare("
        UPDATE termekek SET
            datum=?, be_szamlaszam=?, szallito_id=?, megnevezes=?,
            netto_ar=?, tipus=?, spec=?, megjegyzes=?, statusz_id=?,
            vevo=?, eladas_datum=?, ki_szamlaszam=?,
            archivalható=?, ellenorzott=?, leltar=?,
            modositotta=?, modositva=NOW()
        WHERE id=?
    ")->execute([
        $adat['datum'],
        $adat['be_szamlaszam'],
        $adat['szallito_id'],
        $adat['megnevezes'],
        $adat['netto_ar'],
        $adat['tipus'],
        $adat['spec'],
        $adat['megjegyzes'],
        $adat['statusz_id'],
        $adat['vevo'],
        $adat['eladas_datum'],
        $adat['ki_szamlaszam'],
        $adat['archivalható'],
        $adat['ellenorzott'],
        $adat['leltar'],
        $userId,
        $termekId,
    ]);

    logActivity($db, 'termek_modositas', "Módosítva: {$existing['raktari_szam']}", $termekId);

    // Send "sold" notification when status just changed to "eladva".
    $mostEladva = ((int)$adat['statusz_id']) === (int)$eladvaStatuszId;
    if (!$voltEladva && $mostEladva) {
        $termekErtesito = array_merge($existing, $adat);
        sendTermekErtesito($db, 'eladva', $termekErtesito);
    }

    return [
        'ok'           => true,
        'id'           => $termekId,
        'raktari_szam' => $existing['raktari_szam'],
    ];
}
