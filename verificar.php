<?php
/**
 * Script de verificación del sistema
 * Para diagnosticar problemas de configuración
 */

// Cargar .env / APP_DEBUG antes de decidir si esta página puede ejecutarse.
require_once __DIR__ . '/config/bootstrap.php';

// Este script no requiere autenticación y, sin esta comprobación, cualquier
// visitante podía abrirlo directamente y ver la lista de correos/roles de
// usuarios activos además de si la conexión a la base de datos funciona
// (información útil para un atacante). Sólo se permite con APP_DEBUG=true,
// igual que el resto del manejo de errores/depuración del sistema.
if (($_ENV['APP_DEBUG'] ?? 'false') !== 'true') {
    http_response_code(404);
    exit('Not Found');
}

// Configuración de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Verificación del Sistema de Control de Asistencia</h1>";

// Verificar si existe la configuración
echo "<h2>1. Verificación de configuración</h2>";
$configFile = __DIR__ . '/config/database.php';
if (file_exists($configFile)) {
    echo "✅ Archivo de configuración existe<br>";
    $config = require $configFile;
    echo "✅ Configuración cargada correctamente<br>";
} else {
    echo "❌ Archivo de configuración no encontrado<br>";
    exit;
}

// Verificar conexión a la base de datos
echo "<h2>2. Verificación de conexión a base de datos</h2>";
try {
    $db = $config['database'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['username'], $db['password'], $db['options']);
    echo "✅ Conexión a base de datos exitosa<br>";
    
    // Verificar si existen las tablas principales
    echo "<h2>3. Verificación de tablas</h2>";
    $tablas = ['usuarios', 'dispositivos', 'tarjetas_rfid', 'asistencias'];
    
    foreach ($tablas as $tabla) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tabla]);
        if ($stmt->fetch()) {
            echo "✅ Tabla '{$tabla}' existe<br>";
        } else {
            echo "❌ Tabla '{$tabla}' no existe<br>";
        }
    }
    
    // Verificar datos de prueba
    echo "<h2>4. Verificación de datos de prueba</h2>";
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE activo = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "📊 Total de usuarios activos: {$result['total']}<br>";
    
    if ($result['total'] > 0) {
        $stmt = $pdo->prepare("SELECT email, rol FROM usuarios WHERE activo = 1 LIMIT 3");
        $stmt->execute();
        $usuarios = $stmt->fetchAll();
        echo "👥 Usuarios encontrados:<br>";
        foreach ($usuarios as $usuario) {
            echo "&nbsp;&nbsp;- {$usuario['email']} ({$usuario['rol']})<br>";
        }
    }
    
    echo "<h2>5. Verificación de archivos del sistema</h2>";
    $archivos = [
        'app/Controllers/AuthController.php',
        'app/Controllers/AdminController.php',
        'app/Models/Database.php',
        'app/Views/auth/login.php'
    ];
    
    foreach ($archivos as $archivo) {
        if (file_exists(__DIR__ . '/' . $archivo)) {
            echo "✅ {$archivo} existe<br>";
        } else {
            echo "❌ {$archivo} no encontrado<br>";
        }
    }
    
    echo "<h2>✅ Verificación completada</h2>";
    echo "<p>Si todo está en verde, el sistema debería funcionar correctamente.</p>";
    echo "<p><a href='/ControlDeAsistencia/'>← Volver al sistema</a></p>";
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "<br>";
    echo "<p>Verifica que:</p>";
    echo "<ul>";
    echo "<li>MySQL esté ejecutándose</li>";
    echo "<li>La base de datos 'control_asistencia' exista</li>";
    echo "<li>Las credenciales sean correctas</li>";
    echo "</ul>";
}
?>