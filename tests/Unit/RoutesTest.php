<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Las rutas que modifican o borran datos no pueden ser GET (CSRF), y las
 * rutas de borrado deben apuntar a un método que exista.
 */
final class RoutesTest extends TestCase
{
    private static function rutas(): array
    {
        require_once dirname(__DIR__, 2) . '/src/routes.php';
        $router = (new ReflectionClass(\Router::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($router);
        $def = $ref->getMethod('definirRutas');
        $def->setAccessible(true);
        $def->invoke($router);
        $prop = $ref->getProperty('routes');
        $prop->setAccessible(true);
        return $prop->getValue($router);
    }

    public function testNingunaRutaGetBorraOModificaDatos(): void
    {
        foreach (array_keys(self::rutas()['GET']) as $uri) {
            $this->assertDoesNotMatchRegularExpression(
                '#eliminar|borrar|delete|bloquear|activar|desactivar|desasignar#i',
                $uri,
                "La ruta GET $uri cambia estado y debe ser POST con CSRF"
            );
        }
    }

    public function testRutasDeBorradoSonPost(): void
    {
        $post = self::rutas()['POST'];
        $this->assertArrayHasKey('/admin/tarjetas/eliminar/{uid}', $post);
        $this->assertArrayHasKey('/admin/dispositivos/eliminar/{id}', $post);
    }

    public function testRutasDeBorradoApuntanAUnMetodoExistente(): void
    {
        foreach (self::rutas() as $metodo => $rutas) {
            foreach ($rutas as $uri => $destino) {
                if (!is_array($destino) || stripos($uri, 'eliminar') === false) {
                    continue;
                }
                $this->assertTrue(
                    method_exists($destino[0], $destino[1]),
                    "$metodo $uri -> {$destino[0]}::{$destino[1]} no existe"
                );
            }
        }
    }
}
