<?php
/**
 * Lógica pura de cálculo de asistencia.
 *
 * Sistema de Control de Asistencia
 *
 * Estas reglas (tipo de marcación siguiente, si una hora cae dentro del
 * horario laboral con tolerancia, horas trabajadas, porcentaje de
 * puntualidad) vivían repetidas y mezcladas con acceso a base de datos
 * dentro de App\Models\RegistroAsistencia, App\Controllers\RRHHController
 * y App\Controllers\EmpleadoController. Se extraen aquí como funciones
 * puras (sin PDO, sin $_SESSION, sin fecha "now" implícita) para poder
 * probarlas con PHPUnit sin necesitar una base de datos.
 */

namespace App\Utils;

class AsistenciaCalculator
{
    /**
     * Determina si la próxima marcación de un usuario debe ser 'entrada'
     * o 'salida', a partir del tipo de su última marcación válida del día.
     *
     * @param string|null $ultimoTipo 'entrada', 'salida' o null si no hay
     *                                marcación previa en el día.
     */
    public static function determinarTipoMarcacion(?string $ultimoTipo): string
    {
        if ($ultimoTipo === null) {
            return 'entrada';
        }

        return $ultimoTipo === 'entrada' ? 'salida' : 'entrada';
    }

    /**
     * Indica si una hora de marcación cae dentro del horario laboral,
     * incluyendo tolerancia antes de la entrada y después de la salida.
     *
     * Las tres horas se esperan en formato "H:i:s" (o "H:i"); se comparan
     * como texto tras normalizar con date()/strtotime(), igual que hacía
     * el código original.
     */
    public static function estaEnHorarioLaboral(
        string $horaMarcacion,
        string $horaEntrada,
        string $horaSalida,
        int $toleranciaMinutos = 15
    ): bool {
        $horaEntradaConTolerancia = date('H:i:s', strtotime($horaEntrada . " - {$toleranciaMinutos} minutes"));
        $horaSalidaConTolerancia = date('H:i:s', strtotime($horaSalida . " + {$toleranciaMinutos} minutes"));
        $hora = date('H:i:s', strtotime($horaMarcacion));

        return $hora >= $horaEntradaConTolerancia && $hora <= $horaSalidaConTolerancia;
    }

    /**
     * Indica si el día de la semana (formato ISO-8601: 1=lunes..7=domingo,
     * igual que date('N')) es uno de los días laborables configurados.
     *
     * @param int   $diaSemanaISO
     * @param int[] $diasLaborales
     */
    public static function esDiaLaboral(int $diaSemanaISO, array $diasLaborales): bool
    {
        return in_array($diaSemanaISO, $diasLaborales, true);
    }

    /**
     * Calcula las horas trabajadas (en horas decimales) entre una entrada
     * y una salida, ambas aceptadas por DateTime (p. ej. "H:i:s" o
     * "Y-m-d H:i:s").
     */
    public static function calcularHorasTrabajadas(string $entrada, string $salida): float
    {
        $inicio = new \DateTime($entrada);
        $fin = new \DateTime($salida);
        $diferencia = $fin->diff($inicio);

        return $diferencia->h + ($diferencia->i / 60);
    }

    /**
     * Calcula el porcentaje de puntualidad a partir de los días
     * trabajados y las tardanzas registradas en ese período.
     *
     * Devuelve 100 cuando no hay días trabajados (nada que penalizar), y
     * nunca un valor negativo aunque, por datos inconsistentes, hubiera
     * más tardanzas que días trabajados.
     */
    public static function calcularPorcentajePuntualidad(int $diasTrabajados, int $tardanzas): float
    {
        if ($diasTrabajados <= 0) {
            return 100.0;
        }

        $puntuales = max(0, $diasTrabajados - $tardanzas);

        return round(($puntuales / $diasTrabajados) * 100, 2);
    }
}
