# 📦 Craftzone Inventory

> Önállóan hosztolható, nyílt forráskódú raktárkészlet-kezelő webalkalmazás kis- és középvállalkozásoknak.


---

## 📖 Tartalomjegyzék

- [Bemutatkozás](#-bemutatkozás)
- [Funkciók](#-funkciók)
- [Képernyőképek](#-képernyőképek)
- [Demo](#-demo)
- [Technológiák](#%EF%B8%8F-technológiák)
- [Telepítés](#-telepítés)
- [Rendszerkövetelmények](#-rendszerkövetelmények)
- [Biztonság](#-biztonság)
- [Közreműködés](#-közreműködés)
- [Licenc](#-licenc)
- [Szerző](#-szerző)

---

## 🎯 Bemutatkozás

A **Craftzone Inventory** egy teljes funkcionalitású, önállóan hosztolható raktárkészlet-kezelő rendszer kis- és középvállalkozásoknak. A termékeket beérkezéstől eladásig követi nyomon, kezeli a beszállítókat, felhasználókat és alkalmazásbeállításokat — mindezt egy letisztult, reszponzív webes felületen, amely asztali és mobil eszközökön egyaránt jól működik.

> 💡 Ízelítőül a projekt scene-style bemutatkozásáért nézd meg a [CRAFTZONE.nfo](CRAFTZONE.nfo) fájlt!

---

## ✨ Funkciók

- 📦 **Termékkatalógus** — raktári számokkal, beszállítóval, típussal és specifikációval
- 📊 **Készletmozgások** — bejövő/kimenő tételek számlaszámokkal és vevői adatokkal
- 🔍 **Speciális keresés** — dátumtartomány, ártartomány, kulcsszavas kiemelés
- 📋 **Lapozott terméklista** — élő AJAX gyorskereséssel
- 👥 **Szerepkör-alapú jogosultságkezelés** — admin / felhasználó
- ⚙️ **Konfigurálható listák** — terméktípusok, specifikációk, beszállítók
- 🛠️ **Alkalmazásbeállítások** — név, raktári szám prefix, aktív mezők
- 📜 **Teljes naplózás** — minden művelet user-rel, akcióval, időbélyeggel és IP-vel
- 🚀 **Egylépcsős webes telepítő** — nincs szükség parancssoros bütykölésre
- 🌓 **Világos / sötét téma** — rendszerbeállítás-érzékeléssel
- 📱 **Teljesen reszponzív** — desktop, tablet és mobil

---


## 🌐 Demo

> 🚧 *Élő demo hamarosan elérhető lesz a `demo.craftzone.hu` címen.*

---

## 🛠️ Technológiák

| Réteg | Technológia |
|-------|-------------|
| **Backend** | PHP 8+ · PDO / MySQL |
| **Frontend** | Vanilla HTML5 / CSS3 / JavaScript *(zero dependencies)* |
| **Adatbázis** | MySQL 5.7+ · InnoDB foreign-key kényszerekkel |
| **Autentikáció** | Session-alapú · bcrypt jelszó hashelés |
| **Biztonság** | CSRF tokenek · prepared statement-ek · szerepkör-alapú hozzáférés |

### Hogyan működik?

Minden kérés egy központi `db.php` bootstrap fájlon megy keresztül, amely kezeli a PDO singleton-t, és átirányít a telepítőre, ha a `config.php` hiányzik. Az oldalak a **POST → Redirect → GET** mintát követik a dupla beküldés elkerülése érdekében. Minden módosítást CSRF tokenek védenek, amelyek minden űrlap betöltésekor újragenerálódnak.

A termékek automatikusan generált raktári számokat kapnak (pl. `RAK-0042`) konfigurálható prefixszel. A teljes naplózó rendszer minden létrehozási/szerkesztési/törlési műveletet rögzít a végrehajtó felhasználó IP-címével és időbélyegével együtt.

A felhasználói felület világos/sötét téma váltóval érkezik. A választott téma `localStorage`-ban tárolódik, és első látogatáskor az operációs rendszer `prefers-color-scheme` beállítására esik vissza. A teljes frontend 320 px szélességig reszponzív, összecsukható hamburger menüvel és oszlop-elrejtéssel keskeny táblákon.

---

## 📥 Telepítés

### 1. Fájlok másolása

Másold a projekt fájljait a webszervered dokumentumgyökerébe:

```bash
git clone https://github.com/Craftzone-IT/craftzone-inventory.git
cd craftzone-inventory
```

### 2. Adatbázis létrehozása

Hozz létre egy MySQL / MariaDB adatbázist:

```sql
CREATE DATABASE craftzone_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 3. Telepítő futtatása

Nyisd meg böngészőben:

```
http://your-server/install.php
```

Töltsd ki a telepítő űrlapot:
- **1. lépés** — adatbázis host, név, felhasználónév, jelszó
- **2. lépés** — admin fiók felhasználónév és jelszó

A telepítő automatikusan létrehozza az összes táblát, beilleszti az alapértelmezett opciókat, és megírja a `config/config.php` fájlt.

### 4. Telepítő törlése ⚠️

```bash
rm install.php
```

> **FONTOS:** Ha a `install.php` elérhető marad, bárki visszaállíthatja az alkalmazást!

### 5. Virtual host beállítása *(opcionális)*

Mutass egy virtual host-ot vagy aldomain-t a projekt mappára, hogy az alkalmazás tiszta URL-en legyen elérhető alkönyvtár nélkül.

> 💡 **Tipp:** A telepítés alatt a webszervernek írási joggal kell rendelkeznie a `config/` könyvtárhoz. Telepítés után írásvédett lehet újra.

---

## 📋 Rendszerkövetelmények

| Komponens | Minimum verzió |
|-----------|----------------|
| **PHP** | 8.0+ *(PDO + pdo_mysql kiterjesztés szükséges)* |
| **MySQL** | 5.7 / 8.x *vagy* MariaDB 10.4+ |
| **Webszerver** | Apache / Nginx / PHP beépített szerver |

---

## 🔒 Biztonság

A projekt a következő biztonsági gyakorlatokat alkalmazza:

- ✅ **Bcrypt jelszó hashelés** — soha nem tárolunk plain text jelszót
- ✅ **Prepared statement-ek mindenhol** — SQL injection védelem
- ✅ **CSRF tokenek** — minden módosító űrlapnál
- ✅ **Session-alapú autentikáció** — biztonságos session beállításokkal
- ✅ **Szerepkör-alapú hozzáférés-szabályozás** — admin / user szétválasztása
- ✅ **SMTP jelszavak titkosítva tárolva** — adatbázis-szintű titkosítás
- ✅ **Konfiguráció kívül a verziókezelőn** — a `config/config.php` `.gitignore`-ban

Biztonsági sebezhetőség jelentéséhez kérjük, ne nyiss publikus issue-t. Ehelyett vedd fel velünk a kapcsolatot privátban: [info@craftzone.hu](mailto:info@craftzone.hu)

---

## 🤝 Közreműködés

A közreműködést szívesen fogadjuk! 🎉

Ha hibát találtál vagy fejlesztési javaslatod van:

1. Nyiss egy [Issue-t](https://github.com/Craftzone-IT/craftzone-inventory/issues) a hiba leírásával vagy a javaslattal
2. Fork-old a repót, csinálj egy feature branch-et: `git checkout -b feature/AmazingFeature`
3. Commitold a változásokat: `git commit -m 'Add some AmazingFeature'`
4. Push-old a branch-et: `git push origin feature/AmazingFeature`
5. Nyiss egy Pull Request-et

> 💬 Bár jelenleg nem aktívan keresünk közreműködőket, minden építő jellegű hozzájárulást szívesen veszünk!

---

## 📄 Licenc

Ez a projekt a **GNU General Public License v3.0** licenc alatt áll.

A részletekért lásd a [LICENSE](LICENSE) fájlt.

```
Copyright (C) 2026 Craftzone IT Solutions

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
```

---

## 👤 Szerző

**[Craftzone IT Solutions](https://craftzone.hu)**

- 🌐 Weboldal: [craftzone.hu](https://craftzone.hu)
- 📧 Email: [info@craftzone.hu](mailto:info@craftzone.hu)
- 🐙 GitHub: [@Craftzone-IT](https://github.com/Craftzone-IT)

---

<div align="center">

**Üdvözlet mindenkinek, aki Excel-táblázatokban tárolja a készletét!** 📊✨

⭐ Ha tetszik a projekt, adj egy csillagot! ⭐

</div>
