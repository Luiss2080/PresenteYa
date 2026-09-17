/*
 * Sistema de Control de Asistencia con ESP32 y RFID RC522
 * Versión: 2.1
 *
 * Funcionalidades:
 * - Lee tarjetas RFID y envía datos al servidor
 * - Indicadores LED y buzzer para feedback
 * - Conexión WiFi con reconexión automática
 * - Almacenamiento offline para casos sin internet
 * - Sincronización de tiempo con NTP
 * - Ping periódico al servidor
 *
 * Conexiones ESP32 - RC522:
 * - SDA/SS: GPIO 5
 * - SCK: GPIO 18
 * - MOSI: GPIO 23
 * - MISO: GPIO 19
 * - IRQ: No conectado
 * - GND: GND
 * - RST: GPIO 22
 * - 3.3V: 3.3V
 *
 * Conexiones adicionales:
 * - LED Verde: GPIO 2 (220Ω a GND)
 * - LED Rojo: GPIO 4 (220Ω a GND)
 * - LED Azul: GPIO 16 (220Ω a GND)
 * - Buzzer: GPIO 17 (Activo directo)
 *
 * === LIMITACIÓN CONOCIDA: clonado/replay de UID ===
 * Este lector, como cualquier lector RFID de bajo costo que sólo lee el
 * UID de la tarjeta (sin autenticación challenge-response contra la
 * tarjeta), NO puede distinguir una tarjeta original de un clon con el
 * mismo UID. No hay reto criptográfico, nonce por lectura, ni verificación
 * de las claves internas del sector de la tarjeta (eso requeriría tarjetas
 * MIFARE DESFire/con cifrado y lógica adicional en el MFRC522 que este
 * proyecto no implementa). El único control existente es del lado del
 * servidor: RegistroAsistencia::registrarMarcacion() rechaza una nueva
 * marcación del mismo usuario si la anterior fue hace menos de 5 minutos,
 * lo que mitiga (no elimina) el replay inmediato pero no el clonado.
 * Esto se documenta también en el README como limitación conocida en vez
 * de aparentar que está resuelto.
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <NTPClient.h>
#include <WiFiUdp.h>
#include <Preferences.h>

// SSID/password de WiFi y token del dispositivo viven en config.h, que NO
// se sube al repositorio (ver .gitignore). Copia config.h.example a
// config.h y pon ahí tus valores reales antes de compilar.
#include "config.h"

// ===== CONFIGURACIÓN DE HARDWARE =====
#define SS_PIN 5
#define RST_PIN 22
#define LED_VERDE 2
#define LED_ROJO 4
#define LED_AZUL 16
#define BUZZER 17

// ===== CONFIGURACIÓN DE TIEMPO =====
WiFiUDP ntpUDP;
NTPClient timeClient(ntpUDP, "pool.ntp.org", -21600, 60000); // GMT-6 México

// ===== OBJETOS GLOBALES =====
MFRC522 mfrc522(SS_PIN, RST_PIN);
HTTPClient http;
Preferences preferences;

// ===== VARIABLES GLOBALES =====
bool wifiConnected = false;
unsigned long lastCardRead = 0;
unsigned long lastPing = 0;
String lastUID = "";

// ===== CONSTANTES =====
const unsigned long CARD_DEBOUNCE = 3000;  // 3 segundos entre lecturas de la misma tarjeta
const unsigned long WIFI_TIMEOUT = 20000;  // 20 segundos timeout para WiFi
const unsigned long PING_INTERVAL = 60000; // 1 minuto entre pings

void setup() {
  Serial.begin(115200);
  Serial.println("\n=== Sistema de Control de Asistencia ===");
  Serial.println("Versión: 2.1");
  Serial.println("Iniciando ESP32...");

  // Inicializar preferencias (EEPROM)
  preferences.begin("asistencia", false);

  // Configurar pines
  pinMode(LED_VERDE, OUTPUT);
  pinMode(LED_ROJO, OUTPUT);
  pinMode(LED_AZUL, OUTPUT);
  pinMode(BUZZER, OUTPUT);

  // LEDs de inicio
  indicarInicio();

  // Inicializar SPI y MFRC522
  SPI.begin();
  mfrc522.PCD_Init();

  // Verificar lector RFID
  if (!verificarLectorRFID()) {
    Serial.println("ERROR: No se pudo inicializar el lector RFID");
    indicarError();
    while (1) delay(1000);
  }

  Serial.println("Lector RFID inicializado correctamente");

  // Conectar a WiFi
  conectarWiFi();

  // Inicializar cliente NTP
  timeClient.begin();
  sincronizarTiempo();

  // Obtener configuración del servidor
  obtenerConfiguracion();

  // Ping inicial
  enviarPing();

  Serial.println("Sistema listo para leer tarjetas");
  indicarListo();
}

void loop() {
  // Verificar conexión WiFi
  if (WiFi.status() != WL_CONNECTED) {
    wifiConnected = false;
    Serial.println("WiFi desconectado. Reintentando...");
    conectarWiFi();
  } else {
    wifiConnected = true;
  }

  // Actualizar tiempo
  timeClient.update();

  // Enviar ping periódico
  if (millis() - lastPing > PING_INTERVAL) {
    enviarPing();
    lastPing = millis();
  }

  // Leer tarjetas RFID
  if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
    procesarTarjeta();
  }

  // Indicar estado con LED azul
  if (wifiConnected) {
    digitalWrite(LED_AZUL, (millis() / 1000) % 2); // Parpadeo lento
  } else {
    digitalWrite(LED_AZUL, (millis() / 200) % 2);  // Parpadeo rápido
  }

  delay(100);
}

void conectarWiFi() {
  Serial.println("Conectando a WiFi...");
  Serial.println("Red: " + String(WIFI_SSID));

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  unsigned long startTime = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startTime < WIFI_TIMEOUT) {
    delay(500);
    Serial.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    wifiConnected = true;
    Serial.println();
    Serial.println("WiFi conectado");
    Serial.print("IP: ");
    Serial.println(WiFi.localIP());
    Serial.print("Senal: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");

    sonidoExito();
  } else {
    wifiConnected = false;
    Serial.println("\nError al conectar WiFi");
    sonidoError();
  }
}

void sincronizarTiempo() {
  Serial.println("Sincronizando tiempo...");

  int retries = 0;
  while (!timeClient.update() && retries < 5) {
    timeClient.forceUpdate();
    retries++;
    delay(1000);
  }

  if (retries < 5) {
    Serial.println("Tiempo sincronizado");
    Serial.println("Hora actual: " + timeClient.getFormattedTime());
  } else {
    Serial.println("No se pudo sincronizar el tiempo");
  }
}

bool verificarLectorRFID() {
  byte version = mfrc522.PCD_ReadRegister(MFRC522::VersionReg);
  return (version == 0x91 || version == 0x92);
}

void procesarTarjeta() {
  // Evitar lecturas duplicadas muy rápidas
  if (millis() - lastCardRead < CARD_DEBOUNCE) {
    return;
  }

  // Obtener UID de la tarjeta
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();

  // Evitar procesar la misma tarjeta muy seguido
  if (uid == lastUID && millis() - lastCardRead < CARD_DEBOUNCE * 2) {
    return;
  }

  lastUID = uid;
  lastCardRead = millis();

  Serial.println("\n=== TARJETA DETECTADA ===");
  Serial.println("UID: " + uid);
  Serial.println("Timestamp: " + obtenerTimestamp());

  // Indicar lectura
  digitalWrite(LED_AZUL, HIGH);
  sonidoLectura();

  // El backend valida el UID y registra la marcación en un solo paso
  // (ver /asistencia en api/index.php), así que basta una llamada.
  if (registrarAsistencia(uid)) {
    indicarExito();
    Serial.println("Asistencia registrada");
  } else {
    indicarError();
    Serial.println("Error al registrar asistencia");
  }

  digitalWrite(LED_AZUL, LOW);

  // Detener la tarjeta
  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();

  Serial.println("========================\n");
}

bool registrarAsistencia(String uid) {
  if (!wifiConnected) {
    Serial.println("Sin conexion WiFi - guardando offline");
    return guardarAsistenciaOffline(uid);
  }

  // Endpoint real expuesto por api/index.php: POST /api/asistencia
  http.begin(String(SERVER_URL) + "/asistencia");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + String(DEVICE_TOKEN));

  DynamicJsonDocument doc(1024);
  doc["uid_tarjeta"] = uid;
  doc["fecha_hora"] = obtenerTimestamp();
  doc["ip_dispositivo"] = WiFi.localIP().toString();

  String jsonString;
  serializeJson(doc, jsonString);

  Serial.println("Enviando: " + jsonString);

  int httpCode = http.POST(jsonString);

  if (httpCode == 200) {
    String response = http.getString();
    Serial.println("Respuesta: " + response);

    // api/index.php envuelve la respuesta en {"success":true,"data":{...}}
    DynamicJsonDocument responseDoc(2048);
    deserializeJson(responseDoc, response);

    if (responseDoc["success"] == true) {
      JsonObject data = responseDoc["data"];
      String usuario = data["usuario"] | "";
      String tipo = data["tipo"] | "";
      bool fueraHorario = data["fuera_horario"] | false;

      Serial.println("Usuario: " + usuario);
      Serial.println("Tipo: " + tipo);

      if (fueraHorario) {
        Serial.println("FUERA DE HORARIO");
        sonidoTardanza();
      }

      http.end();
      return true;
    }

    String error = responseDoc["error"] | "desconocido";
    Serial.println("Error del servidor: " + error);
  } else {
    Serial.println("Error HTTP: " + String(httpCode));
  }

  http.end();

  // Si falla, guardar offline
  return guardarAsistenciaOffline(uid);
}

bool guardarAsistenciaOffline(String uid) {
  Serial.println("Guardando asistencia offline...");

  int contador = preferences.getInt("offline_count", 0);
  String key = "offline_" + String(contador);

  DynamicJsonDocument doc(512);
  doc["uid_tarjeta"] = uid;
  doc["fecha_hora"] = obtenerTimestamp();
  doc["ip_dispositivo"] = WiFi.localIP().toString();

  String jsonString;
  serializeJson(doc, jsonString);

  preferences.putString(key.c_str(), jsonString);
  preferences.putInt("offline_count", contador + 1);

  Serial.println("Guardado offline (#" + String(contador + 1) + ")");
  return true;
}

void enviarAsistenciasOffline() {
  int contador = preferences.getInt("offline_count", 0);

  if (contador == 0) return;

  Serial.println("Enviando " + String(contador) + " asistencias offline...");

  int enviadas = 0;
  for (int i = 0; i < contador; i++) {
    String key = "offline_" + String(i);
    String data = preferences.getString(key.c_str(), "");

    if (data.length() > 0) {
      http.begin(String(SERVER_URL) + "/asistencia");
      http.addHeader("Content-Type", "application/json");
      http.addHeader("Authorization", "Bearer " + String(DEVICE_TOKEN));

      int httpCode = http.POST(data);

      if (httpCode == 200) {
        preferences.remove(key.c_str());
        enviadas++;
        Serial.println("Asistencia offline " + String(i + 1) + " enviada");
      } else {
        Serial.println("Error enviando asistencia " + String(i + 1));
        break; // Parar si hay error, se reintentará en el próximo ping
      }

      http.end();
      delay(500); // Esperar entre envíos
    }
  }

  if (enviadas > 0) {
    // Reorganizar índices de las que quedaron pendientes
    int restantes = contador - enviadas;
    for (int i = 0; i < restantes; i++) {
      String keyOld = "offline_" + String(i + enviadas);
      String keyNew = "offline_" + String(i);
      String data = preferences.getString(keyOld.c_str(), "");
      preferences.putString(keyNew.c_str(), data);
      preferences.remove(keyOld.c_str());
    }

    preferences.putInt("offline_count", restantes);
    Serial.println("Enviadas " + String(enviadas) + " asistencias offline");
  }
}

void enviarPing() {
  if (!wifiConnected) return;

  // Endpoint real: GET /api/ping (no requiere autenticación, ver
  // ApiController::validarAutenticacion en api/index.php)
  http.begin(String(SERVER_URL) + "/ping");
  http.addHeader("Authorization", "Bearer " + String(DEVICE_TOKEN));

  int httpCode = http.GET();

  if (httpCode == 200) {
    Serial.println("Ping enviado");
    enviarAsistenciasOffline();
  } else {
    Serial.println("Error en ping: " + String(httpCode));
  }

  http.end();

  // Reportar estado/diagnóstico del dispositivo (POST /api/estado)
  http.begin(String(SERVER_URL) + "/estado");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + String(DEVICE_TOKEN));

  DynamicJsonDocument doc(512);
  doc["estado"] = "online";
  doc["version_firmware"] = "2.1";
  doc["memoria_libre"] = ESP.getFreeHeap();
  doc["uptime"] = millis();

  String jsonString;
  serializeJson(doc, jsonString);
  http.POST(jsonString);
  http.end();
}

void obtenerConfiguracion() {
  if (!wifiConnected) return;

  Serial.println("Obteniendo configuracion del servidor...");

  // Endpoint real: GET /api/configuracion
  http.begin(String(SERVER_URL) + "/configuracion");
  http.addHeader("Authorization", "Bearer " + String(DEVICE_TOKEN));

  int httpCode = http.GET();

  if (httpCode == 200) {
    String response = http.getString();

    DynamicJsonDocument doc(2048);
    deserializeJson(doc, response);

    if (doc["success"] == true) {
      Serial.println("Configuracion obtenida");

      JsonObject validaciones = doc["data"]["validaciones"];
      if (!validaciones.isNull() && validaciones.containsKey("min_tiempo_entre_marcaciones")) {
        long minTiempo = validaciones["min_tiempo_entre_marcaciones"];
        Serial.println("Minimo entre marcaciones: " + String(minTiempo) + " s");
      }
    }
  } else {
    Serial.println("No se pudo obtener configuracion");
  }

  http.end();
}

String obtenerTimestamp() {
  // Formato: YYYY-MM-DD HH:MM:SS
  time_t epochTime = timeClient.getEpochTime();
  struct tm *ptm = gmtime(&epochTime);

  char timestamp[20];
  sprintf(timestamp, "%04d-%02d-%02d %02d:%02d:%02d",
          ptm->tm_year + 1900,
          ptm->tm_mon + 1,
          ptm->tm_mday,
          ptm->tm_hour,
          ptm->tm_min,
          ptm->tm_sec);

  return String(timestamp);
}

// ===== FUNCIONES DE INDICACIÓN =====

void indicarInicio() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_VERDE, HIGH);
    digitalWrite(LED_ROJO, HIGH);
    digitalWrite(LED_AZUL, HIGH);
    delay(200);
    digitalWrite(LED_VERDE, LOW);
    digitalWrite(LED_ROJO, LOW);
    digitalWrite(LED_AZUL, LOW);
    delay(200);
  }
}

void indicarListo() {
  digitalWrite(LED_VERDE, HIGH);
  delay(1000);
  digitalWrite(LED_VERDE, LOW);
  sonidoListo();
}

void indicarExito() {
  for (int i = 0; i < 2; i++) {
    digitalWrite(LED_VERDE, HIGH);
    delay(300);
    digitalWrite(LED_VERDE, LOW);
    delay(200);
  }
}

void indicarError() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_ROJO, HIGH);
    delay(200);
    digitalWrite(LED_ROJO, LOW);
    delay(200);
  }
}

void sonidoExito() {
  tone(BUZZER, 1000, 200);
  delay(250);
  tone(BUZZER, 1500, 200);
}

void sonidoError() {
  for (int i = 0; i < 3; i++) {
    tone(BUZZER, 300, 200);
    delay(300);
  }
}

void sonidoLectura() {
  tone(BUZZER, 800, 100);
}

void sonidoListo() {
  tone(BUZZER, 600, 200);
  delay(250);
  tone(BUZZER, 800, 200);
  delay(250);
  tone(BUZZER, 1000, 200);
}

void sonidoTardanza() {
  for (int i = 0; i < 5; i++) {
    tone(BUZZER, 400, 100);
    delay(150);
  }
}
