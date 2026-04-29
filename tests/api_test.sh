#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
# Raktárkészlet kezelő — API tesztek
#
# Használat:
#   chmod +x tests/api_test.sh
#   ./tests/api_test.sh https://example.com <API_TOKEN>
#
# Előfeltételek:
#   - curl, jq telepítve
#   - Futó webszerver az adott URL-en
#   - Érvényes API token (beallitasok.php-ból generálva)
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

BASE_URL="${1:?'Használat: ./api_test.sh <BASE_URL> <API_TOKEN>'}"
API_TOKEN="${2:?'Használat: ./api_test.sh <BASE_URL> <API_TOKEN>'}"

# Strip trailing slash from base URL.
BASE_URL="${BASE_URL%/}"
API="${BASE_URL}/api/termek"

PASS=0
FAIL=0
CREATED_ID=""

# ── Helpers ───────────────────────────────────────────────────────────────────

green()  { printf '\033[32m%s\033[0m\n' "$*"; }
red()    { printf '\033[31m%s\033[0m\n' "$*"; }
bold()   { printf '\033[1m%s\033[0m\n' "$*"; }

assert_status() {
    local test_name="$1"
    local expected="$2"
    local actual="$3"

    if [ "$actual" -eq "$expected" ]; then
        green "  PASS  $test_name (HTTP $actual)"
        PASS=$((PASS + 1))
    else
        red "  FAIL  $test_name — várt: $expected, kapott: $actual"
        FAIL=$((FAIL + 1))
    fi
}

assert_json_field() {
    local test_name="$1"
    local json="$2"
    local field="$3"
    local expected="$4"

    local actual
    actual=$(echo "$json" | jq -r "$field" 2>/dev/null || echo "__JQ_ERROR__")

    if [ "$actual" = "$expected" ]; then
        green "  PASS  $test_name ($field = $expected)"
        PASS=$((PASS + 1))
    else
        red "  FAIL  $test_name — $field várt: '$expected', kapott: '$actual'"
        FAIL=$((FAIL + 1))
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
bold "═══ Raktárkészlet kezelő — API teszt ═══"
bold "Cél: $API"
echo ""

# ── 1. Token nélküli hívás → 401 ─────────────────────────────────────────────
bold "1. Autentikáció nélküli hívás"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$API")
assert_status "GET /api/termek token nélkül" 401 "$HTTP_CODE"
echo ""

# ── 2. Érvénytelen token → 401 ───────────────────────────────────────────────
bold "2. Érvénytelen token"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer 0000000000000000000000000000000000000000000000000000000000000000" \
    "$API")
assert_status "GET /api/termek hamis tokennel" 401 "$HTTP_CODE"
echo ""

# ── 3. POST üres body → 400 ─���────────────────────────────────────────────────
bold "3. POST üres body-val (validációs hiba)"
RESPONSE=$(curl -s -w '\n%{http_code}' \
    -X POST \
    -H "Authorization: Bearer $API_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{}' \
    "$API")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status "POST /api/termek üres body" 400 "$HTTP_CODE"
assert_json_field "Válasz ok=false" "$BODY" ".ok" "false"
echo ""

# ── 4. POST helyes adatokkal → 201 ───────────────────────────────────────────
bold "4. POST helyes adatokkal"
TIMESTAMP=$(date +%s)
TEST_NAME="API-Teszt-$TIMESTAMP"

RESPONSE=$(curl -s -w '\n%{http_code}' \
    -X POST \
    -H "Authorization: Bearer $API_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"megnevezes\": \"$TEST_NAME\",
        \"tipus\": \"Laptop\",
        \"netto_ar\": 150000,
        \"datum\": \"$(date +%Y-%m-%d)\",
        \"szallito_nev\": \"Teszt Szállító Kft.\",
        \"megjegyzes\": \"Automatikus API teszt\"
    }" \
    "$API")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status "POST /api/termek létrehozás" 201 "$HTTP_CODE"
assert_json_field "Válasz ok=true" "$BODY" ".ok" "true"

CREATED_ID=$(echo "$BODY" | jq -r '.id // empty')
RAKTARI_SZAM=$(echo "$BODY" | jq -r '.raktari_szam // empty')

if [ -n "$CREATED_ID" ]; then
    green "  INFO  Létrehozott termék: id=$CREATED_ID, raktári szám=$RAKTARI_SZAM"
else
    red "  WARN  Nem sikerült kiolvasni a létrehozott ID-t"
fi
echo ""

# ── 5. GET lista → 200 + tartalmazza az új terméket ──────────────────────────
bold "5. GET terméklista"
RESPONSE=$(curl -s -w '\n%{http_code}' \
    -H "Authorization: Bearer $API_TOKEN" \
    "$API?kereses=$TEST_NAME")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status "GET /api/termek lista" 200 "$HTTP_CODE"
assert_json_field "Válasz ok=true" "$BODY" ".ok" "true"

FOUND=$(echo "$BODY" | jq -r ".termekek[]? | select(.id == $CREATED_ID) | .megnevezes" 2>/dev/null)
if [ "$FOUND" = "$TEST_NAME" ]; then
    green "  PASS  Létrehozott termék megtalálva a listában"
    PASS=$((PASS + 1))
else
    red "  FAIL  Létrehozott termék nem található a listában"
    FAIL=$((FAIL + 1))
fi
echo ""

# ── 6. GET egyedi → 200 ──────────────────────────────────────────────────────
bold "6. GET egyedi termék"
if [ -n "$CREATED_ID" ]; then
    RESPONSE=$(curl -s -w '\n%{http_code}' \
        -H "Authorization: Bearer $API_TOKEN" \
        "$API/$CREATED_ID")
    HTTP_CODE=$(echo "$RESPONSE" | tail -1)
    BODY=$(echo "$RESPONSE" | sed '$d')

    assert_status "GET /api/termek/$CREATED_ID" 200 "$HTTP_CODE"
    assert_json_field "Megnevezés egyezik" "$BODY" ".termek.megnevezes" "$TEST_NAME"
fi
echo ""

# ── 7. PUT módosítás → 200 ───────────────────────────────────────────────────
bold "7. PUT módosítás"
MODIFIED_NAME="$TEST_NAME-MODIFIED"
if [ -n "$CREATED_ID" ]; then
    RESPONSE=$(curl -s -w '\n%{http_code}' \
        -X PUT \
        -H "Authorization: Bearer $API_TOKEN" \
        -H "Content-Type: application/json" \
        -d "{\"megnevezes\": \"$MODIFIED_NAME\", \"tipus\": \"Monitor\"}" \
        "$API/$CREATED_ID")
    HTTP_CODE=$(echo "$RESPONSE" | tail -1)
    BODY=$(echo "$RESPONSE" | sed '$d')

    assert_status "PUT /api/termek/$CREATED_ID" 200 "$HTTP_CODE"
    assert_json_field "Válasz ok=true" "$BODY" ".ok" "true"

    # Verify the change stuck.
    VERIFY=$(curl -s -H "Authorization: Bearer $API_TOKEN" "$API/$CREATED_ID")
    VERIFY_NAME=$(echo "$VERIFY" | jq -r '.termek.megnevezes // empty')
    if [ "$VERIFY_NAME" = "$MODIFIED_NAME" ]; then
        green "  PASS  Módosítás ellenőrizve (megnevezes = $MODIFIED_NAME)"
        PASS=$((PASS + 1))
    else
        red "  FAIL  Módosítás nem érvényesült (kapott: '$VERIFY_NAME')"
        FAIL=$((FAIL + 1))
    fi
fi
echo ""

# ── 8. DELETE → 200 ──────────────────────────────────────────────────────────
bold "8. DELETE törlés"
if [ -n "$CREATED_ID" ]; then
    RESPONSE=$(curl -s -w '\n%{http_code}' \
        -X DELETE \
        -H "Authorization: Bearer $API_TOKEN" \
        "$API/$CREATED_ID")
    HTTP_CODE=$(echo "$RESPONSE" | tail -1)
    BODY=$(echo "$RESPONSE" | sed '$d')

    assert_status "DELETE /api/termek/$CREATED_ID" 200 "$HTTP_CODE"
    assert_json_field "Válasz ok=true" "$BODY" ".ok" "true"
fi
echo ""

# ── 9. GET törölt termék → 404 ���──────────────────────────────────────────────
bold "9. GET törölt termék (404)"
if [ -n "$CREATED_ID" ]; then
    HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
        -H "Authorization: Bearer $API_TOKEN" \
        "$API/$CREATED_ID")
    assert_status "GET /api/termek/$CREATED_ID törlés után" 404 "$HTTP_CODE"
fi
echo ""

# ─��� 10. Rate limit teszt → 429 ───────────────────────────────────────────────
bold "10. Rate limit teszt (61 gyors kérés)"
echo "  INFO  61 kérés küldése egymás után..."
GOT_429=false
for i in $(seq 1 61); do
    HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
        -H "Authorization: Bearer $API_TOKEN" \
        "$API?limit=1")
    if [ "$HTTP_CODE" -eq 429 ]; then
        green "  PASS  429 Too Many Requests a(z) $i. kérésnél"
        GOT_429=true
        PASS=$((PASS + 1))
        break
    fi
done
if [ "$GOT_429" = false ]; then
    red "  FAIL  Nem kaptunk 429-et 61 kérés után"
    FAIL=$((FAIL + 1))
fi
echo ""

# ═══════════════════════════════════════════════════════════════════════════════
bold "═══ Eredmény ═══"
green "  Sikeres: $PASS"
if [ "$FAIL" -gt 0 ]; then
    red "  Hibás:   $FAIL"
    exit 1
else
    green "  Minden teszt sikeres!"
    exit 0
fi
