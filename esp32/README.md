# Instrucciones de Instalación - ESP32
# Sistema de Control de Asistencia

## Componentes Necesarios

### Hardware
- ESP32 DevKit v1
- Módulo RFID MFRC522
- LEDs: Verde, Rojo, Azul
- Buzzer piezo eléctrico
- Resistencias 220Ω (3 unidades)
- Protoboard
- Cables jumper
- Fuente de alimentación 5V/1A

### Software
- Arduino IDE 1.8.x o 2.x
- Librerías de Arduino (ver lista abajo)

## Instalación del Software

### 1. Configurar Arduino IDE para ESP32

1. Abrir Arduino IDE
2. Ir a `Archivo` > `Preferencias`
3. En "URLs adicionales del Gestor de Tarjetas", agregar:
   ```
   https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json
   ```
4. Ir a `Herramientas` > `Placa` > `Gestor de tarjetas`
5. Buscar "esp32" e instalar "ESP32 by Espressif Systems"

### 2. Instalar Librerías Requeridas

Ir a `Herramientas` > `Administrar librerías` e instalar:

- **WiFi** (incluida con ESP32)
- **HTTPClient** (incluida con ESP32)
- **ArduinoJson** por Benoit Blanchon (versión 6.x)
- **MFRC522** por GithubCommunity
- **NTPClient** por Fabrice Weinberg

### 3. Configurar las librerías

En el menú `Herramientas`, seleccionar:
- Placa: "ESP32 Dev Module"
- Puerto: [Puerto correspondiente a tu ESP32]
- Upload Speed: "921600"
- CPU Frequency: "240MHz (WiFi/BT)"
- Flash Frequency: "80MHz"
- Flash Mode: "QIO"
- Flash Size: "4MB (32Mb)"
- Partition Scheme: "Default 4MB with spiffs"

## Conexiones del Circuito

### MFRC522 ↔ ESP32
```
MFRC522    ESP32
------------------------
SDA     →  GPIO 5
SCK     →  GPIO 18
MOSI    →  GPIO 23
MISO    →  GPIO 19
IRQ     →  No conectar
GND     →  GND
RST     →  GPIO 22
3.3V    →  3.3V
```

### LEDs y Buzzer ↔ ESP32
```
Componente    ESP32    Resistencia
---------------------------------
LED Verde  →  GPIO 2  →  220Ω → GND
LED Rojo   →  GPIO 4  →  220Ω → GND
LED Azul   →  GPIO 16 →  220Ω → GND
Buzzer +   →  GPIO 17
Buzzer -   →  GND
```

## Configuración del Código

### 1. Credenciales WiFi y token del dispositivo

Las credenciales de WiFi y el token del dispositivo YA NO se escriben
dentro de `lector_asistencia.ino` (antes estaban ahí en texto plano, lo
que significa que subir el `.ino` a un repositorio público exponía la
contraseña real de la red WiFi). Ahora viven en `config.h`, que está en
`.gitignore` y nunca se sube al repositorio.

1. Copia `esp32/config.h.example` a `esp32/config.h`.
2. Edita `esp32/config.h` con tus valores reales:

```cpp
#define WIFI_SSID     "TU_WIFI_SSID"
#define WIFI_PASSWORD "TU_WIFI_PASSWORD"
#define SERVER_URL    "http://tu-servidor.com/ControlDeAsistencia/api"
#define DEVICE_TOKEN  "el_token_que_generó_el_panel_al_crear_el_dispositivo"
```

El `DEVICE_TOKEN` se obtiene del panel de administración al registrar el
dispositivo (Admin → Dispositivos → Registrar Nuevo Dispositivo). Cada
lector debe usar su propio token.

### 2. Zona Horaria
Ajustar según tu ubicación:
```cpp
NTPClient timeClient(ntpUDP, "pool.ntp.org", -21600, 60000); // GMT-6 México
```

Para otras zonas:
- GMT-5 (Colombia, Perú): -18000
- GMT-3 (Argentina): -10800
- GMT+1 (España): 3600

## Proceso de Instalación

### 1. Armado del Circuito
1. Conectar el MFRC522 según el diagrama
2. Conectar los LEDs con sus resistencias
3. Conectar el buzzer
4. Verificar todas las conexiones

### 2. Programación
1. Conectar el ESP32 al PC vía USB
2. Copiar `config.h.example` a `config.h` y completar tus credenciales
   (ver sección "Configuración del Código" arriba)
3. Abrir `lector_asistencia.ino` en Arduino IDE
4. Seleccionar la placa y puerto correctos
5. Compilar y subir el código

### 3. Pruebas
1. Abrir el Monitor Serie (115200 baudios)
2. Verificar que se conecte a WiFi
3. Verificar que se inicialice el lector RFID
4. Probar con una tarjeta RFID

## Indicadores del Sistema

### LEDs
- **Verde**: Operación exitosa
- **Rojo**: Error o fallo
- **Azul**: Estado de conectividad
  - Parpadeo lento: WiFi conectado
  - Parpadeo rápido: Sin WiFi

### Sonidos
- 1 beep corto: Tarjeta leída
- 2 beeps ascendentes: Operación exitosa
- 3 beeps graves: Error
- 5 beeps rápidos: Tardanza detectada

## Solución de Problemas

### ESP32 no se conecta a WiFi
1. Verificar credenciales WiFi
2. Asegurar que la red sea 2.4GHz
3. Verificar alcance de señal
4. Revisar configuración del router

### MFRC522 no responde
1. Verificar conexiones SPI
2. Revisar alimentación (3.3V)
3. Verificar que el módulo no esté dañado
4. Comprobar soldaduras

### Error de compilación
1. Verificar que todas las librerías estén instaladas
2. Actualizar el core de ESP32
3. Revisar la versión de Arduino IDE

### Tarjetas no se leen
1. Verificar que sean tarjetas RFID 13.56MHz
2. Acercar más la tarjeta al lector
3. Revisar la antena del MFRC522

## Mantenimiento

### Limpieza
- Limpiar regularmente la superficie del lector RFID
- Verificar conexiones periódicamente
- Mantener el dispositivo libre de polvo

### Actualizaciones
- Actualizar firmware vía OTA (implementar según necesidades)
- Revisar logs del sistema periódicamente
- Verificar funcionamiento de todos los componentes

## Características Avanzadas

### Almacenamiento Offline
El sistema guarda asistencias en memoria local cuando no hay conexión y las envía cuando se restablece.

### Sincronización de Tiempo
El sistema sincroniza automáticamente con servidores NTP para mantener la hora exacta.

### Configuración Remota
Puede recibir configuraciones del servidor para ajustar parámetros sin reprogramar.

### Monitoreo
Envía pings periódicos al servidor con información de estado y diagnóstico.

## Limitaciones conocidas

### Clonado/replay de UID RFID

Este lector sólo lee el UID de la tarjeta MFRC522, sin ningún desafío
criptográfico contra la propia tarjeta. Eso significa que **una tarjeta
clonada con el mismo UID es indistinguible de la original** para este
sistema: no hay forma de detectar el clonado a nivel de lector con este
hardware. Resolverlo de verdad requiere tarjetas con cifrado (p. ej.
MIFARE DESFire) y lógica de autenticación adicional, que este proyecto
no implementa.

Lo único que existe hoy como mitigación parcial es del lado del
servidor: `RegistroAsistencia::registrarMarcacion()` rechaza una nueva
marcación del mismo usuario si la anterior fue hace menos de 5 minutos,
lo que reduce el impacto de un replay inmediato (alguien reenviando la
misma lectura una y otra vez) pero no evita que una tarjeta clonada
marque asistencia como si fuera la original. Si tu caso de uso requiere
protección real contra clonado, este sistema no es suficiente tal cual
está y necesitarías tarjetas con cifrado + firmware que las soporte.

## Contacto y Soporte

Para soporte técnico:
1. Revisar los logs en el Monitor Serie
2. Verificar la documentación del servidor
3. Comprobar la configuración de red
4. Consultar la documentación de las librerías utilizadas