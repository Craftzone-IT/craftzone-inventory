# Raktárkészlet kezelő — REST API

A raktárkészlet kezelő rendszer REST API-t biztosít termékek programozott kezeléséhez.

## Token generálás

1. Bejelentkezés admin fiókkal
2. **Beállítások** > **API hozzáférés** szekció megnyitása
3. Token név megadása (pl. "Home Assistant", "Python script") > **Új token generálása**
4. A megjelenő tokent azonnal mentsd el — **többé nem jelenik meg**

## Autentikáció

Minden API kéréshez az `Authorization` header szükséges:

```
Authorization: Bearer <API_TOKEN>
```

## Endpoint-ok

| Metódus  | Végpont              | Leírás                  |
|----------|----------------------|-------------------------|
| `GET`    | `/api/termek`        | Terméklista (szűrőkkel) |
| `GET`    | `/api/termek/{id}`   | Egy termék részletei    |
| `POST`   | `/api/termek`        | Új termék felvétele     |
| `PUT`    | `/api/termek/{id}`   | Termék módosítása       |
| `DELETE` | `/api/termek/{id}`   | Termék törlése          |

## Request / Response példák

### POST /api/termek — Új termék

**Request:**
```bash
curl -X POST https://example.com/api/termek \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "megnevezes": "Dell Latitude 5540",
    "tipus": "Laptop",
    "spec": "16GB RAM",
    "szallito_nev": "Dell Hungary",
    "netto_ar": 285000,
    "datum": "2026-04-11",
    "statusz_id": 1
  }'
```

**Response (201 Created):**
```json
{
  "ok": true,
  "id": 127,
  "raktari_szam": "RAK-0127"
}
```

### GET /api/termek — Terméklista

**Request:**
```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "https://example.com/api/termek?kereses=Dell&tipus=Laptop&limit=20&offset=0"
```

**Response (200):**
```json
{
  "ok": true,
  "termekek": [
    {
      "id": 127,
      "raktari_szam": "RAK-0127",
      "megnevezes": "Dell Latitude 5540",
      "tipus": "Laptop",
      "statusz_nev": "raktáron",
      "szallito_nev": "Dell Hungary",
      ...
    }
  ],
  "osszes": 1,
  "limit": 20,
  "offset": 0
}
```

**Elérhető szűrők:**

| Paraméter    | Leírás                                   |
|--------------|------------------------------------------|
| `kereses`    | Szabad szöveg keresés több mezőben       |
| `tipus`      | Típus szűrő (pontos egyezés)             |
| `statusz`    | Státusz név szerinti szűrő               |
| `statusz_id` | Státusz ID szerinti szűrő                |
| `szallito`   | Szállító név szerinti szűrő              |
| `datum_tol`  | Bevételezés dátum (tól, YYYY-MM-DD)      |
| `datum_ig`   | Bevételezés dátum (ig, YYYY-MM-DD)       |
| `limit`      | Találatok száma (max 200, alapért.: 20)  |
| `offset`     | Eltolás (lapozáshoz, alapért.: 0)        |

### GET /api/termek/{id} — Egy termék

**Request:**
```bash
curl -H "Authorization: Bearer <TOKEN>" \
  https://example.com/api/termek/127
```

**Response (200):**
```json
{
  "ok": true,
  "termek": {
    "id": 127,
    "raktari_szam": "RAK-0127",
    "megnevezes": "Dell Latitude 5540",
    "tipus": "Laptop",
    "spec": "16GB RAM",
    "netto_ar": "285000.00",
    "statusz_nev": "raktáron",
    "szallito_nev": "Dell Hungary",
    "letrehozo_nev": "Admin",
    ...
  }
}
```

### PUT /api/termek/{id} — Módosítás

**Request:**
```bash
curl -X PUT https://example.com/api/termek/127 \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "megnevezes": "Dell Latitude 5540 (frissített)",
    "statusz_id": 4,
    "vevo": "Kovács Kft."
  }'
```

**Response (200):**
```json
{
  "ok": true,
  "id": 127,
  "raktari_szam": "RAK-0127"
}
```

### DELETE /api/termek/{id} — Törlés

**Request:**
```bash
curl -X DELETE https://example.com/api/termek/127 \
  -H "Authorization: Bearer <TOKEN>"
```

**Response (200):**
```json
{
  "ok": true
}
```

## Mezők (POST / PUT)

| Mező             | Típus   | Kötelező | Leírás                                             |
|------------------|---------|----------|----------------------------------------------------|
| `megnevezes`     | string  | Igen     | Termék neve (max 300 karakter)                     |
| `tipus`          | string  | Nem      | Típus (pl. Laptop, Monitor)                        |
| `spec`           | string  | Nem      | Specifikáció (pl. 16GB RAM)                        |
| `szallito_id`    | int     | Nem      | Szállító ID                                        |
| `szallito_nev`   | string  | Nem      | Szállító neve (ha nem létezik, automatikusan létrejön) |
| `netto_ar`       | number  | Nem      | Nettó beszerzési ár (Ft)                           |
| `datum`          | string  | Nem      | Bevételezés dátuma (YYYY-MM-DD)                    |
| `statusz_id`     | int     | Nem      | Státusz ID (alapértelmezett: 1 = raktáron)         |
| `be_szamlaszam`  | string  | Nem      | Bejövő számlaszám                                  |
| `ki_szamlaszam`  | string  | Nem      | Kimenő számlaszám                                  |
| `vevo`           | string  | Nem      | Vevő neve                                          |
| `eladas_datum`   | string  | Nem      | Eladás dátuma (YYYY-MM-DD)                         |
| `megjegyzes`     | string  | Nem      | Megjegyzés                                          |
| `archivalható`   | bool    | Nem      | Archiválható jelző                                  |
| `ellenorzott`    | bool    | Nem      | Ellenőrzött jelző                                   |
| `leltar`         | bool    | Nem      | Leltár jelző                                        |

## Hibakódok

| HTTP kód | Jelentés                | Példa                                        |
|----------|-------------------------|----------------------------------------------|
| `200`    | Sikeres művelet         | GET, PUT, DELETE                              |
| `201`    | Létrehozva              | POST sikeres termék felvétel                 |
| `400`    | Hibás kérés             | Hiányzó kötelező mező, érvénytelen JSON      |
| `401`    | Nem autentikált         | Hiányzó/érvénytelen/inaktív token            |
| `404`    | Nem található           | Nem létező termék ID / végpont               |
| `405`    | Nem engedélyezett metódus | PATCH, HEAD stb.                            |
| `429`    | Túl sok kérés           | Rate limit túllépés (60 req/perc/token)      |

Hibaválaszok formátuma:
```json
{
  "ok": false,
  "hiba": "Hibaüzenet szövege"
}
```

Validációs hibák (400) formátuma:
```json
{
  "ok": false,
  "hibak": ["Hiba 1", "Hiba 2"]
}
```

## Rate limit

- **60 kérés / perc / token**
- Túllépésnél: `429 Too Many Requests` + `Retry-After` header
- Az ablak 60 másodperc után automatikusan resetelődik

## Tesztelés

```bash
chmod +x tests/api_test.sh
./tests/api_test.sh https://example.com <API_TOKEN>
```

A teszt script lefedi: autentikáció, CRUD műveletek, validáció, rate limit.
