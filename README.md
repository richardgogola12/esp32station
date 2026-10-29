# ESP32 DHT Station

Kompletný systém na meranie teploty a vlhkosti: **ESP32 + DHT11 → PHP/MySQL web (PWA) → Android widgety**.

```
esp32/     firmvér pre ESP32 (Arduino IDE)
web/       webová aplikácia (PHP 8, MySQL, Bootstrap 5, PWA, prihlásenie odtlačkom)
android/   Android aplikácia s 10 widgetmi (Android Studio, Kotlin, bez knižníc)
```

---

## 1. Web – inštalácia

1. Nahraj obsah priečinka `web/` na server, napr. do `/www_root_rgogola-wms_eu/esp32dth/`
   → aplikácia bude na `https://rgogola-wms.eu/esp32dth/`.
2. Skopíruj `config.example.php` ako `config.php` a doplň údaje k databáze.
   (`config.php` je v `.gitignore`, aby sa heslo nedostalo do verejného repozitára.)
3. Otvor v prehliadači `https://…/esp32dth/install.php` – vytvoria sa tabuľky s prefixom **`esp32dth_`**
   (alebo naimportuj `install.sql` cez phpMyAdmin). Potom **`install.php` zmaž**.
4. Zaregistruj sa. Ak nechceš ďalších používateľov, v `config.php` nastav `'allow_register' => false`.
5. Uisti sa, že adresár `storage/sessions/` je zapisovateľný (súbory session tejto aplikácie).

Požiadavky: PHP 8.0+ (pdo_mysql, openssl, mbstring), MySQL 5.7+/MariaDB 10.3+, Apache s `.htaccess`.
**HTTPS je nutné** pre prihlásenie odtlačkom prsta a pre PWA.

### Tabuľky
| Tabuľka | Obsah |
|---|---|
| `esp32dth_users` | používatelia, token pre widgety, interval obnovy widgetov |
| `esp32dth_devices` | zariadenia (identifikátor, názov, popis, umiestnenie, interval, korekcie, hash API kľúča) |
| `esp32dth_readings` | merania |
| `esp32dth_webauthn` | uložené odtlačky (passkeys) |
| `esp32dth_login_attempts` | ochrana proti hádaniu hesla |

### Bezpečnosť
- Heslá cez `password_hash` (bcrypt/argon), API kľúče a tokeny uložené iba ako SHA-256 hash (zobrazia sa len raz).
- CSRF tokeny na všetkých formulároch a POST požiadavkách, PDO prepared statements, výstup cez `htmlspecialchars`.
- Obmedzenie pokusov o prihlásenie (5 / 15 min na IP), rovnaký čas odozvy pre neexistujúce účty.
- Hlavičky CSP, X-Frame-Options, nosniff, HSTS (na HTTPS), `.htaccess` blokuje `config.php`, `includes/`, `storage/`, `install.sql`.
- **Session je izolovaná od ostatných aplikácií na serveri**: vlastný názov cookie `ESP32DTH_SID`, cookie platí len pre cestu aplikácie,
  vlastný adresár na session súbory, všetky dáta v `$_SESSION['esp32dth']`, regenerácia ID po prihlásení, timeout nečinnosti.
- API pre ESP32: overenie identifikátora + kľúča (`hash_equals`), validácia rozsahov, ochrana pred zahltením.

### Prihlásenie odtlačkom prsta
WebAuthn / passkeys bez externých knižníc. Aplikácia sama zistí, či zariadenie má snímač (alebo zámok obrazovky),
automaticky pomenuje zariadenie (model, systém, prehliadač) a doménu (RP ID). Na prihlasovacej stránke ponúkne uložený
odtlačok aj priamo v poli „meno“. Zapína sa v **Nastavenia → Prihlásenie odtlačkom prsta**.

### PWA
Manifest, ikony, service worker (offline stránka, cache statických súborov). V Chrome: menu → *Inštalovať aplikáciu*
(alebo tlačidlo ⬇ v hornej lište).

---

## 2. ESP32

1. Arduino IDE → doska **ESP32 Dev Module**, knižnice **DHT sensor library** + **Adafruit Unified Sensor**.
2. Zapojenie: DHT11 `VCC → 3V3`, `GND → GND`, `DATA → GPIO 4`.
3. Na webe pridaj zariadenie (Zariadenia → Pridať). Kľúč a hotové riadky kódu nájdeš kedykoľvek
   na karte zariadenia cez tlačidlo **„API kľúč a kód“** (kľúč je v databáze uložený šifrovane – AES-256-GCM,
   tajný kľúč sa vytvorí v `storage/secret.key`, alebo ho nastav ako `app_secret` v `config.php`).
4. V `esp32/esp32_dht_station/esp32_dht_station.ino` vyplň `WIFI_SSID`, `WIFI_PASSWORD`, `API_URL`, `DEVICE_NAME`, `API_KEY` a nahraj.

Interval merania sa nastavuje na webe – ESP32 si ho prevezme z odpovede servera pri ďalšom odoslaní.

API: `POST /api/ingest.php`, hlavičky `X-Device-Name`, `X-Api-Key`, telo `{"temperature":21.5,"humidity":45}`.

---

## 3. Android widgety

1. Hotové APK sa po každom pushi zostaví cez GitHub Actions – stiahni **esp32dth-widgety.apk**
   z [Releases → latest](../../releases/latest). (Alebo otvor `android/` v Android Studiu → *Build → Build APK(s)*.)
2. Nainštaluj APK do telefónu (povoľ inštaláciu z neznámych zdrojov) a raz otvor aplikáciu **DHT Widgety**.
3. Na webe: **Nastavenia → Android widgety → Vygenerovať token** → v telefóne ťukni na
   **„Nastaviť aplikáciu v tomto telefóne“** (alebo skopíruj adresu a token ručne).
4. Na ploche pridaj widgety (podrž prst → Widgety → DHT Widgety).

**10 widgetov:** Teplota · Vlhkosť · Teplota + vlhkosť · Karta zariadenia s minigrafom · Kruhové budíky ·
Graf 24 h · Dnešné min/max · Minimalistický priehľadný · Všetky zariadenia · Komfort a rosný bod.

- Interval obnovy sa nastavuje na webe (Nastavenia → Android widgety) a aplikácia ho prevezme pri každej aktualizácii.
- **Štart po zapnutí telefónu**: `BOOT_COMPLETED` receiver hneď vykreslí posledné hodnoty, stiahne nové a naplánuje ďalšie obnovy.
  Ak po štarte ešte nebeží sieť, skúša to znova o minútu.
- Na telefónoch Xiaomi / Huawei / Oppo / Vivo v aplikácii povoľ **Automatické spustenie** a **Batéria bez obmedzení** (tlačidlá sú priamo v aplikácii).
- Android v režime šetrenia (Doze) môže obnovy zoskupiť – pri intervaloch pod ~10 minút je to normálne.
