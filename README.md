# 🕐 PresenteYa

Sistema de control de asistencia por RFID para equipos pequeños: un
lector ESP32 + MFRC522 en la puerta marca la entrada/salida de cada
empleado con su tarjeta, y un panel web en PHP + MySQL calcula
tardanzas, genera reportes y da a cada rol (administrador, RRHH,
empleado) sólo la vista que le corresponde.

## Características

Verificado leyendo el código de `app/`, `api/` y `esp32/`:

- 🏷️ **Marcación por RFID**: el ESP32 lee el UID de la tarjeta (MFRC522)
  y lo envía al backend, que registra la entrada/salida y calcula si
  fue con tardanza según el horario del empleado
  (`RegistroAsistencia::registrarMarcacion`, `App\Utils\AsistenciaCalculator`).
- 📡 **Firmware ESP32 con modo offline**: si no hay WiFi o el servidor no
  responde, la marcación se guarda en memoria (`Preferences`) y se
  reintenta automáticamente en el siguiente ping (`enviarAsistenciasOffline()`).
- 👥 **Tres roles con su propio panel**: Administrador (usuarios,
  dispositivos, tarjetas RFID, configuración), RRHH (dashboard del día,
  alertas de tardanzas/ausencias, reportes) y Empleado (su propio
  historial y estadísticas), cada uno con su propio controlador que
  verifica el rol en sesión antes de mostrar nada.
- 🔐 **Autenticación con sesiones PHP**: login con `password_hash`/
  `password_verify` (bcrypt), protección CSRF en los formularios que
  modifican datos, y limitación de intentos vía rate limiting básico.
- 🔑 **Autenticación de dispositivos por token**: cada lector ESP32 tiene
  su propio token (`dispositivos.token_dispositivo`), generado por el
  panel de administración y enviado por el firmware en cada request.
- 📊 **Reportes de asistencia**: resumen y detalle por empleado,
  llegadas tardías, ausentes, horas trabajadas, filtrables por fecha y
  empleado (`App\Models\Reporte`, panel de RRHH).
- 🚨 **Alertas automáticas**: empleados con varias tardanzas seguidas,
  ausencias sin justificar después de cierta hora, dispositivos
  desconectados, y detección de marcaciones "sospechosas" (la misma
  tarjeta usada en dos lectores distintos en pocos minutos).

## Cómo usar

1. El administrador registra empleados, dispositivos ESP32 y tarjetas
   RFID desde el panel de administración, y asigna cada tarjeta a un
   empleado.
2. Cada lector ESP32, ya configurado con su token, queda pegado junto a
   una puerta. El empleado acerca su tarjeta; el lector confirma con
   LED/buzzer y envía la marcación al servidor.
3. RRHH ve en tiempo real quién llegó, quién llegó tarde y quién falta,
   y genera reportes por rango de fechas.
4. Cada empleado entra a su propio panel para ver su historial y su
   porcentaje de puntualidad.

## Instalación y uso local

### Backend (PHP + MySQL)

```bash
git clone https://github.com/Luiss2080/PresenteYa.git
cd PresenteYa
composer install

cp .env.example .env
# Editar .env: credenciales de MySQL y JWT_SECRET/SESSION_SECRET propios

# Crear la base de datos e importar el volcado de ejemplo
mysql -u root -e "CREATE DATABASE control_asistencia CHARACTER SET utf8mb4;"
mysql -u root control_asistencia < database/backup_completo.sql

php -S localhost:8000
```

Abre `http://localhost:8000/`. El volcado de ejemplo incluye tres
usuarios de prueba (admin, RRHH y un empleado); sus credenciales sólo
se muestran en la propia pantalla de login cuando `APP_DEBUG=true` en
`.env` — en producción, cámbialas o crea usuarios nuevos y no actives
ese modo.

### Firmware ESP32

```bash
cd esp32
cp config.h.example config.h
# Editar config.h: WIFI_SSID, WIFI_PASSWORD, SERVER_URL y DEVICE_TOKEN
# (el token se genera en el panel: Admin -> Dispositivos -> Registrar Nuevo Dispositivo)
```

Abre `lector_asistencia.ino` en el Arduino IDE (placa "ESP32 Dev
Module"), instala las librerías listadas en `esp32/README.md`
(ArduinoJson, MFRC522, NTPClient) y compílalo/súbelo. `config.h` está
en `.gitignore`: nunca se sube al repositorio, a diferencia del `.ino`
original de este proyecto, que sí tenía el WiFi y el token
hardcodeados.

## Tecnologías

- **Firmware**: ESP32 + MFRC522 (Arduino/C++), `ArduinoJson`, `NTPClient`,
  `Preferences` para almacenamiento offline.
- **Backend**: PHP 8+ sin framework (MVC propio: `App\Controllers`,
  `App\Models`, `App\Views`), PDO con sentencias preparadas para MySQL.
- **Autenticación**: sesiones PHP nativas + `password_hash`/
  `password_verify`. El `composer.json` lista `firebase/php-jwt` como
  dependencia, pero **no se usa en ningún lugar del código actual**: no
  hay JWT real en este sistema hoy, todo el login es por sesión. Lo
  mismo pasa con `mpdf`, `phpoffice/phpspreadsheet` y `phpmailer/phpmailer`:
  están declarados pero los reportes se exportan hoy como HTML plano
  con cabecera `Content-Type` de Excel/PDF (`RRHHController::exportarExcel/exportarPDF`),
  no como archivos `.xlsx`/`.pdf` reales, y no hay envío de correo
  implementado en ningún controlador.
- **Base de datos**: MySQL/MariaDB.

## Tests

```bash
composer install
composer test
# o directamente:
vendor/bin/phpunit
```

La suite actual (`tests/Unit/AsistenciaCalculatorTest.php`) cubre la
lógica de asistencia extraída a `App\Utils\AsistenciaCalculator`
(siguiente tipo de marcación, horario laboral con tolerancia, horas
trabajadas, porcentaje de puntualidad) sin necesitar una base de datos.
`tests/SystemTest.php` es un script manual de humo contra una base de
datos real (no PHPUnit); sólo se ejecuta si `APP_DEBUG=true`.

## Limitaciones conocidas

- **Clonado/replay de tarjetas RFID**: el sistema sólo valida el UID de
  la tarjeta; no hay reto criptográfico contra la tarjeta ni forma de
  distinguir una tarjeta clonada de la original con este hardware. La
  única mitigación es que el servidor rechaza una nueva marcación del
  mismo usuario si la anterior fue hace menos de 5 minutos, lo que
  reduce el replay inmediato pero no evita el clonado. Ver
  `esp32/README.md` para el detalle.
- Los reportes "Excel"/"PDF" son HTML servido con el `Content-Type`
  correspondiente, no archivos binarios reales.

## Licencia

MIT — ver [`LICENSE`](LICENSE).
