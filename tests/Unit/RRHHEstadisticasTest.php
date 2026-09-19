<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\RRHHController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regresión: el dashboard de RRHH mostraba "Total Empleados: 0" porque el
 * controlador exponía la clave `empleados_total` y la vista leía
 * `total_empleados`.
 */
final class RRHHEstadisticasTest extends TestCase
{
    private function estadisticas(): array
    {
        $db = new class {
            public function fetch(string $sql, array $params = []): array
            {
                if (strpos($sql, "rol = 'empleado'") !== false) {
                    return ['total' => 12];
                }
                if (strpos($sql, "tipo = 'entrada'") !== false) {
                    return ['total' => 5];
                }
                return ['total' => 2];
            }
        };

        $ref = new ReflectionClass(RRHHController::class);
        $controller = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($controller, $db);

        $m = $ref->getMethod('obtenerEstadisticasHoy');
        $m->setAccessible(true);
        return $m->invoke($controller);
    }

    public function testExponeTotalEmpleadosConLaClaveQueLeeLaVista(): void
    {
        $stats = $this->estadisticas();
        $this->assertArrayHasKey('total_empleados', $stats);
        $this->assertSame(12, (int) $stats['total_empleados']);
    }

    public function testAusentesSeCalculaContraElTotal(): void
    {
        $stats = $this->estadisticas();
        $this->assertSame(7, (int) $stats['ausentes_hoy']);
    }
}
