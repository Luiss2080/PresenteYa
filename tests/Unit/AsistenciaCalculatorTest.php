<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utils\AsistenciaCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de la lógica de asistencia (sin base de datos).
 *
 * Sistema de Control de Asistencia
 */
final class AsistenciaCalculatorTest extends TestCase
{
    // ----- determinarTipoMarcacion() -----

    public function testPrimeraMarcacionDelDiaEsEntrada(): void
    {
        $this->assertSame('entrada', AsistenciaCalculator::determinarTipoMarcacion(null));
    }

    public function testDespuesDeUnaEntradaLaSiguienteEsSalida(): void
    {
        $this->assertSame('salida', AsistenciaCalculator::determinarTipoMarcacion('entrada'));
    }

    public function testDespuesDeUnaSalidaLaSiguienteEsEntrada(): void
    {
        $this->assertSame('entrada', AsistenciaCalculator::determinarTipoMarcacion('salida'));
    }

    // ----- esDiaLaboral() -----

    public function testDiaLaboralConfiguradoEsLaboral(): void
    {
        // Lunes a viernes
        $diasLaborales = [1, 2, 3, 4, 5];

        $this->assertTrue(AsistenciaCalculator::esDiaLaboral(3, $diasLaborales)); // miércoles
    }

    public function testFinDeSemanaNoConfiguradoNoEsLaboral(): void
    {
        $diasLaborales = [1, 2, 3, 4, 5];

        $this->assertFalse(AsistenciaCalculator::esDiaLaboral(6, $diasLaborales)); // sábado
        $this->assertFalse(AsistenciaCalculator::esDiaLaboral(7, $diasLaborales)); // domingo
    }

    // ----- estaEnHorarioLaboral() -----

    public function testMarcacionDentroDelHorarioSinTolerancia(): void
    {
        $this->assertTrue(
            AsistenciaCalculator::estaEnHorarioLaboral('09:00:00', '08:00:00', '17:00:00', 15)
        );
    }

    public function testEntradaDentroDeLaToleranciaEsValida(): void
    {
        // Horario 08:00, tolerancia 15 min => válido desde 07:45
        $this->assertTrue(
            AsistenciaCalculator::estaEnHorarioLaboral('07:50:00', '08:00:00', '17:00:00', 15)
        );
    }

    public function testEntradaAntesDeLaToleranciaNoEsValida(): void
    {
        // 07:30 es más de 15 min antes de las 08:00
        $this->assertFalse(
            AsistenciaCalculator::estaEnHorarioLaboral('07:30:00', '08:00:00', '17:00:00', 15)
        );
    }

    public function testSalidaDespuesDeLaToleranciaNoEsValida(): void
    {
        // 17:30 es más de 15 min después de las 17:00
        $this->assertFalse(
            AsistenciaCalculator::estaEnHorarioLaboral('17:30:00', '08:00:00', '17:00:00', 15)
        );
    }

    public function testSalidaDentroDeLaToleranciaEsValida(): void
    {
        $this->assertTrue(
            AsistenciaCalculator::estaEnHorarioLaboral('17:10:00', '08:00:00', '17:00:00', 15)
        );
    }

    // ----- calcularHorasTrabajadas() -----

    public function testCalcularHorasTrabajadasJornadaCompleta(): void
    {
        $horas = AsistenciaCalculator::calcularHorasTrabajadas('08:00:00', '17:00:00');

        $this->assertEqualsWithDelta(9.0, $horas, 0.001);
    }

    public function testCalcularHorasTrabajadasConMinutos(): void
    {
        $horas = AsistenciaCalculator::calcularHorasTrabajadas('08:00:00', '12:30:00');

        $this->assertEqualsWithDelta(4.5, $horas, 0.001);
    }

    // ----- calcularPorcentajePuntualidad() -----

    public function testPuntualidadCienPorCientoSinTardanzas(): void
    {
        $this->assertSame(100.0, AsistenciaCalculator::calcularPorcentajePuntualidad(20, 0));
    }

    public function testPuntualidadSeReduceConTardanzas(): void
    {
        // 18 de 20 días puntuales = 90%
        $this->assertSame(90.0, AsistenciaCalculator::calcularPorcentajePuntualidad(20, 2));
    }

    public function testPuntualidadCienPorCientoSinDiasTrabajados(): void
    {
        // Sin días trabajados no hay nada que penalizar; evita división por cero.
        $this->assertSame(100.0, AsistenciaCalculator::calcularPorcentajePuntualidad(0, 0));
    }

    public function testPuntualidadNuncaEsNegativaConDatosInconsistentes(): void
    {
        // Más tardanzas que días trabajados no debería producir un
        // porcentaje negativo.
        $this->assertSame(0.0, AsistenciaCalculator::calcularPorcentajePuntualidad(3, 5));
    }
}
