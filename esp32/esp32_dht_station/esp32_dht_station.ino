/*
 * ESP32 DHT Station – firmvér
 * ---------------------------------------------------------------
 * Meria teplotu a vlhkosť senzorom DHT11 a v intervale ich posiela
 * na API webovej aplikácie. Interval si zariadenie preberá zo servera
 * (nastavuje sa vo webovej aplikácii v sekcii Zariadenia).
 *
 * Potrebné knižnice (Arduino IDE → Správca knižníc):
 *   - "DHT sensor library" od Adafruit
 *   - "Adafruit Unified Sensor" od Adafruit
 *
 * Doska: "ESP32 Dev Module" (balík esp32 od Espressif, verzia 2.x alebo 3.x)
 *
 * Zapojenie DHT11 (modul s 3 pinmi):
 *   VCC  -> 3V3
 *   GND  -> GND
 *   DATA -> GPIO 4  (ak máš holý senzor so 4 pinmi, daj 10k rezistor medzi DATA a 3V3)
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <DHT.h>

// ======================= NASTAVENIA =======================
// WiFi sieť (pevne v kóde)
const char* WIFI_SSID     = "NAZOV_WIFI";
const char* WIFI_PASSWORD = "HESLO_WIFI";

// Adresa API – zmeň podľa toho, kam nahráš webovú aplikáciu
const char* API_URL       = "https://rgogola-wms.eu/esp32dth/api/ingest.php";

// Identifikátor zariadenia a API kľúč – skopíruj z webu (Zariadenia → Nastavenie ESP32)
const char* DEVICE_NAME   = "obyvacka";
const char* API_KEY       = "SEM_VLOZ_API_KLUC";

// Senzor
#define DHT_PIN   4
#define DHT_TYPE  DHT11

// Stavová LED (na väčšine ESP32 Dev dosiek je modrá LED na GPIO 2)
#define LED_PIN   2

// Predvolený interval (sekundy), kým ho zariadenie nedostane zo servera
#define DEFAULT_INTERVAL_SEC 60
// Ak sa dlhšie nepodarí odoslať dáta, ESP sa reštartuje (minúty)
#define RESTART_AFTER_FAIL_MIN 30
// ==========================================================

DHT dht(DHT_PIN, DHT_TYPE);

uint32_t intervalSec      = DEFAULT_INTERVAL_SEC;
uint32_t lastSendMs       = 0;
uint32_t lastSuccessMs    = 0;
bool     firstRun         = true;

void blink(int times, int ms = 80) {
  for (int i = 0; i < times; i++) {
    digitalWrite(LED_PIN, HIGH); delay(ms);
    digitalWrite(LED_PIN, LOW);  delay(ms);
  }
}

bool connectWiFi(uint32_t timeoutMs = 20000) {
  if (WiFi.status() == WL_CONNECTED) return true;

  Serial.printf("[WiFi] Pripájam sa k \"%s\"", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < timeoutMs) {
    delay(400);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[WiFi] Pripojené, IP: %s, signál: %d dBm\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
    blink(2);
    return true;
  }
  Serial.println("[WiFi] Pripojenie zlyhalo");
  WiFi.disconnect(true);
  return false;
}

// DHT11 občas vráti chybu – skúsime to niekoľkokrát
bool readSensor(float &t, float &h) {
  for (int attempt = 0; attempt < 4; attempt++) {
    h = dht.readHumidity();
    t = dht.readTemperature();
    if (!isnan(h) && !isnan(t)) return true;
    Serial.println("[DHT] Chyba čítania, skúšam znova...");
    delay(2100); // DHT11 potrebuje medzi čítaniami aspoň ~2 s
  }
  return false;
}

// Jednoduché vytiahnutie čísla z JSON odpovede ("interval":60)
long jsonNumber(const String &json, const char *key, long fallback) {
  String needle = String("\"") + key + "\":";
  int idx = json.indexOf(needle);
  if (idx < 0) return fallback;
  idx += needle.length();
  while (idx < (int)json.length() && json[idx] == ' ') idx++;
  long value = 0; bool any = false;
  while (idx < (int)json.length() && isDigit(json[idx])) {
    value = value * 10 + (json[idx] - '0');
    idx++; any = true;
  }
  return any ? value : fallback;
}

bool sendReading(float t, float h) {
  bool https = String(API_URL).startsWith("https");
  WiFiClientSecure secureClient;
  WiFiClient       plainClient;
  HTTPClient http;

  if (https) {
    // Pre jednoduchosť sa neoveruje certifikát servera.
    // Pre vyššiu bezpečnosť použi secureClient.setCACert(ROOT_CA_PEM);
    secureClient.setInsecure();
    http.begin(secureClient, API_URL);
  } else {
    http.begin(plainClient, API_URL);
  }

  http.setTimeout(10000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Name", DEVICE_NAME);
  http.addHeader("X-Api-Key", API_KEY);
  http.setUserAgent("ESP32-DHT-Station/1.0");

  char body[160];
  snprintf(body, sizeof(body),
           "{\"temperature\":%.2f,\"humidity\":%.2f,\"rssi\":%d,\"uptime\":%lu}",
           t, h, WiFi.RSSI(), (unsigned long)(millis() / 1000));

  int code = http.POST((uint8_t*)body, strlen(body));
  String resp = code > 0 ? http.getString() : "";
  http.end();

  Serial.printf("[API] HTTP %d %s\n", code, resp.c_str());

  if (code == 200 || code == 201 || code == 429) {
    long newInterval = jsonNumber(resp, "interval", intervalSec);
    if (newInterval >= 10 && newInterval <= 86400 && (uint32_t)newInterval != intervalSec) {
      Serial.printf("[API] Nový interval zo servera: %ld s\n", newInterval);
      intervalSec = newInterval;
    }
  }
  return code == 200 || code == 201;
}

void setup() {
  Serial.begin(115200);
  delay(300);
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  Serial.println();
  Serial.println("=== ESP32 DHT Station ===");
  Serial.printf("Zariadenie: %s\n", DEVICE_NAME);

  dht.begin();
  delay(2000); // stabilizácia senzora

  connectWiFi();
  lastSuccessMs = millis();
}

void loop() {
  uint32_t now = millis();

  if (firstRun || now - lastSendMs >= intervalSec * 1000UL) {
    firstRun = false;
    lastSendMs = now;

    float t, h;
    if (!readSensor(t, h)) {
      Serial.println("[DHT] Senzor neodpovedá – skontroluj zapojenie");
      blink(5, 50);
    } else {
      Serial.printf("[DHT] Teplota: %.1f °C, Vlhkosť: %.0f %%\n", t, h);
      if (connectWiFi()) {
        if (sendReading(t, h)) {
          lastSuccessMs = millis();
          blink(1, 40);
        } else {
          blink(3, 60);
        }
      }
    }
  }

  // Poistka – keď sa dlho nepodarí odoslať dáta, reštart
  if (millis() - lastSuccessMs > RESTART_AFTER_FAIL_MIN * 60UL * 1000UL) {
    Serial.println("[SYS] Dlho bez úspešného odoslania – reštartujem");
    delay(500);
    ESP.restart();
  }

  delay(200);
}
