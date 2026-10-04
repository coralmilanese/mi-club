<?php

namespace App\Services\Importacion;

use App\Enums\CategoriaSocio;
use App\Enums\EstadoSocio;
use App\Enums\SedeSocio;
use App\Models\Socio;
use App\Models\TipoDocumento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importa la hoja `Socios` del Libro de Caja AASR: activos + bloques "Bajas AAAA".
 * Un mismo socio que aparece varias veces (reingresos) se unifica por DNI.
 */
class ImportadorSocios
{
    /** @var list<string> */
    private array $advertencias = [];

    /** @var list<string> */
    private array $reingresos = [];

    private int $limpiados = 0;

    /** @return array{creados: int, omitidos: int, reingresos: list<string>, advertencias: list<string>, limpiados: int, por_estado: array<string, int>} */
    public function importar(string $archivo, bool $dryRun = false): array
    {
        $this->advertencias = $this->reingresos = [];
        $this->limpiados = 0;

        $filas = $this->leerFilas($archivo);
        $grupos = $this->agrupar($filas);

        $creados = $omitidos = 0;
        $porEstado = [];

        DB::beginTransaction();
        try {
            foreach ($grupos as $registros) {
                $socio = $this->persistir($registros);
                if ($socio === null) {
                    $omitidos++;

                    continue;
                }
                $creados++;
                $porEstado[$socio->estado->value] = ($porEstado[$socio->estado->value] ?? 0) + 1;
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'creados' => $creados,
            'omitidos' => $omitidos,
            'reingresos' => $this->reingresos,
            'advertencias' => $this->advertencias,
            'limpiados' => $this->limpiados,
            'por_estado' => $porEstado,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function leerFilas(string $archivo): array
    {
        /** @var Xlsx $reader */
        $reader = IOFactory::createReader('Xlsx');
        $reader->setLoadSheetsOnly(['Socios']);
        $ws = $reader->load($archivo)->getSheetByName('Socios')
            ?? throw new \RuntimeException("El archivo no tiene una hoja 'Socios'.");

        $comentarios = [];
        foreach ($ws->getComments() as $coord => $comentario) {
            $comentarios[$coord] = trim($comentario->getText()->getPlainText());
        }

        $filas = [];
        $seccion = ['tipo' => 'activo', 'anio' => null];

        foreach ($ws->toArray(null, true, false, false) as $i => $c) {
            $fila = $i + 1;
            if ($fila === 1) {
                continue;
            }

            if (is_string($c[0] ?? null) && preg_match('/^\s*Bajas\s+(\d{4})/i', $c[0], $m) && blank($c[1] ?? null)) {
                $seccion = ['tipo' => 'baja', 'anio' => (int) $m[1]];

                continue;
            }

            if (blank($c[1] ?? null) && blank($c[2] ?? null)) {
                continue;
            }

            $filas[] = ['fila' => $fila, 'seccion' => $seccion, 'celdas' => $c, 'nota' => $comentarios["B$fila"] ?? null];
        }

        return $filas;
    }

    /**
     * Agrupa por DNI (o nombre si no hay DNI) y ordena cronológicamente: bajas 2023 → 2024 → 2025 → activos.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, list<array<string, mixed>>>
     */
    private function agrupar(array $filas): array
    {
        $registros = array_map(fn ($f) => $this->normalizar($f), $filas);

        usort($registros, fn ($a, $b) => [$a['orden'], $a['fila']] <=> [$b['orden'], $b['fila']]);

        $grupos = [];
        foreach ($registros as $r) {
            $clave = $r['dni'] ?? 'n:'.mb_strtolower($r['apellido'].' '.$r['nombre']);
            $grupos[$clave][] = $r;
        }

        return $grupos;
    }

    /** @return array<string, mixed> */
    private function normalizar(array $f): array
    {
        $c = $f['celdas'];
        $fila = $f['fila'];
        $esBaja = $f['seccion']['tipo'] === 'baja';

        $apellido = $this->nombrePropio($c[1]);
        $nombre = $this->nombrePropio($c[2]);
        $rotulo = "fila {$fila} ({$apellido} {$nombre})";

        $status = mb_strtolower(trim((string) ($c[12] ?? '')));
        $categoria = match (true) {
            str_starts_with($status, 'vitalicio') => CategoriaSocio::Vitalicio,
            str_starts_with($status, 'honorario') => CategoriaSocio::Honorario,
            default => CategoriaSocio::Activo,
        };

        $observaciones = [];
        if ($f['nota']) {
            $observaciones[] = "Nota del Excel: {$f['nota']}";
        }

        return [
            'fila' => $fila,
            'orden' => $esBaja ? $f['seccion']['anio'] : 9999,
            'es_baja' => $esBaja,
            'anio_baja' => $f['seccion']['anio'],
            'es_alta_reciente' => $status === 'alta',
            'nro_socio' => is_numeric($c[0] ?? null) ? (int) $c[0] : null,
            'apellido' => $apellido,
            'nombre' => $nombre,
            'genero' => in_array($g = mb_strtoupper(trim((string) ($c[3] ?? ''))), ['M', 'F'], true) ? $g : null,
            'fecha_nacimiento' => $this->fecha($c[4] ?? null, "Fecha de nacimiento, {$rotulo}"),
            'nacionalidad' => $this->limpiarTexto($c[5] ?? null, titulo: true),
            'dni' => $this->dni($c[6] ?? null),
            'direccion' => $this->limpiarTexto($c[7] ?? null),
            'ciudad' => $this->limpiarTexto($c[8] ?? null, titulo: true),
            'provincia' => $this->limpiarTexto($c[9] ?? null, titulo: true),
            'email' => $this->email($c[10] ?? null, $rotulo),
            'profesion' => $this->limpiarTexto($c[11] ?? null),
            'categoria' => $categoria,
            'telefono' => $this->telefono($c[13] ?? null),
            'tiene_foto' => $this->esSi($c[14] ?? null),
            'sede' => mb_strtoupper(trim((string) ($c[15] ?? ''))) === 'PLANEADORES' ? SedeSocio::Planeadores : SedeSocio::Aasr,
            'fecha_inicio_actividad' => $this->fecha($c[19] ?? null, "Fecha inicio actividad, {$rotulo}"),
            'fecha_asociacion' => $this->fecha($c[20] ?? null, "Fecha de asociación, {$rotulo}"),
            'tiene_planilla' => $this->esSi($c[21] ?? null),
            'observaciones' => $observaciones,
        ];
    }

    /** @param list<array<string, mixed>> $registros */
    private function persistir(array $registros): ?Socio
    {
        $ultimo = end($registros);
        $existente = $ultimo['dni'] !== null
            ? Socio::where('dni', $ultimo['dni'])->exists()
            : Socio::where('apellido', $ultimo['apellido'])->where('nombre', $ultimo['nombre'])->exists();

        if ($existente) {
            $this->advertencias[] = "Ya existe en el sistema, se omite: {$ultimo['apellido']} {$ultimo['nombre']}";

            return null;
        }

        // Datos del registro más reciente, completando huecos con los anteriores.
        $datos = [];
        foreach (array_reverse($registros) as $r) {
            foreach ($r as $k => $v) {
                if (! array_key_exists($k, $datos) || ($datos[$k] === null && $v !== null)) {
                    $datos[$k] = $v;
                }
            }
        }
        $observaciones = array_values(array_unique(array_merge(...array_column($registros, 'observaciones'))));

        [$estados, $estadoFinal, $fechaBaja, $motivoBaja] = $this->construirEstados($registros);

        $socio = Socio::create([
            'nro_socio' => $ultimo['nro_socio'] ?? $datos['nro_socio'],
            'apellido' => $datos['apellido'],
            'nombre' => $datos['nombre'],
            'genero' => $datos['genero'],
            'fecha_nacimiento' => $datos['fecha_nacimiento'],
            'nacionalidad' => $datos['nacionalidad'],
            'dni' => $datos['dni'],
            'direccion' => $datos['direccion'],
            'ciudad' => $datos['ciudad'],
            'provincia' => $datos['provincia'],
            'email' => $datos['email'],
            'telefono' => $datos['telefono'],
            'profesion' => $datos['profesion'],
            'categoria' => $datos['categoria'],
            'sede' => $ultimo['sede'],
            'estado' => $estadoFinal,
            'fecha_asociacion' => $datos['fecha_asociacion'],
            'fecha_inicio_actividad' => $datos['fecha_inicio_actividad'],
            'fecha_baja' => $fechaBaja,
            'motivo_baja' => $motivoBaja,
            'observaciones' => $observaciones ? implode("\n", $observaciones) : null,
        ]);

        $socio->estados()->createMany($estados);

        foreach ([['foto', $ultimo['tiene_foto']], ['planilla_datos', $ultimo['tiene_planilla']]] as [$codigo, $tiene]) {
            if ($tiene && $tipo = TipoDocumento::where('codigo', $codigo)->first()) {
                $socio->documentos()->create([
                    'tipo_documento_id' => $tipo->id,
                    'observaciones' => 'Marcado como entregado en el Excel (sin archivo digital).',
                ]);
            }
        }

        return $socio;
    }

    /**
     * @param  list<array<string, mixed>>  $registros
     * @return array{0: list<array<string, mixed>>, 1: EstadoSocio, 2: ?string, 3: ?string}
     */
    private function construirEstados(array $registros): array
    {
        $tramos = [];
        $fechaBaja = $motivoBaja = null;
        $estadoFinal = EstadoSocio::Activo;
        $bajaAnterior = null;

        foreach ($registros as $i => $r) {
            if ($i > 0 && $bajaAnterior) {
                $desde = CarbonImmutable::parse($bajaAnterior)->addDay()->toDateString();
                $tramos[] = ['estado' => EstadoSocio::Activo, 'desde' => $desde, 'motivo' => 'Reingreso (fecha aproximada, detectado al importar)'];
                $this->reingresos[] = "{$r['apellido']} {$r['nombre']} (DNI ".($r['dni'] ?? 's/d').')';
            } elseif ($r['es_baja']) {
                $tramos[] = ['estado' => EstadoSocio::Activo, 'desde' => $r['fecha_asociacion']?->toDateString(), 'motivo' => 'Alta previa a la baja'];
            }

            if ($r['es_baja']) {
                $bajaAnterior = "{$r['anio_baja']}-12-31";
                $motivoBaja = "Baja {$r['anio_baja']} según la planilla Socios (fecha exacta no registrada).";
                $tramos[] = ['estado' => EstadoSocio::Baja, 'desde' => $bajaAnterior, 'motivo' => $motivoBaja];
                $estadoFinal = EstadoSocio::Baja;
                $fechaBaja = $bajaAnterior;
            } else {
                $bajaAnterior = null;
                $estadoFinal = $r['es_alta_reciente'] ? EstadoSocio::Alta : EstadoSocio::Activo;
                $tramos[] = ['estado' => $estadoFinal, 'desde' => $r['fecha_asociacion']?->toDateString(), 'motivo' => 'Importado del Excel'];
                $fechaBaja = $motivoBaja = null;
            }
        }

        // Cada tramo termina donde empieza el siguiente; el último queda abierto.
        foreach ($tramos as $k => &$t) {
            $t['hasta'] = $tramos[$k + 1]['desde'] ?? null;
        }
        unset($t);

        return [$tramos, $estadoFinal, $fechaBaja, $motivoBaja];
    }

    // ---- Limpieza de datos sucios ----------------------------------------------------------

    private function limpiarTexto(mixed $v, bool $titulo = false): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $v));
        if ($t === '' || preg_match('/^[-–—._\s]+$/u', $t)) {
            $this->limpiados += $t === '' ? 0 : 1;

            return null;
        }

        return $titulo ? mb_convert_case(mb_strtolower($t), MB_CASE_TITLE) : $t;
    }

    private function nombrePropio(mixed $v): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $v));

        return ($t === mb_strtoupper($t) || $t === mb_strtolower($t)) ? mb_convert_case(mb_strtolower($t), MB_CASE_TITLE) : $t;
    }

    private function dni(mixed $v): ?string
    {
        $d = preg_replace('/\D/', '', is_float($v) ? (string) (int) $v : (string) $v);

        return $d === '' ? null : $d;
    }

    private function telefono(mixed $v): ?string
    {
        return $this->limpiarTexto($v);
    }

    private function email(mixed $v, string $rotulo): ?string
    {
        $e = mb_strtolower(trim((string) $v));
        if ($e === '' || preg_match('/^[-\s._]+$/', $e)) {
            $this->limpiados += $e === '' ? 0 : 1;

            return null;
        }
        if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $this->advertencias[] = "Email inválido descartado en {$rotulo}: '{$e}'";

            return null;
        }

        return $e;
    }

    private function esSi(mixed $v): bool
    {
        return mb_strtolower(trim((string) $v)) === 'si';
    }

    private function fecha(mixed $v, string $rotulo): ?CarbonImmutable
    {
        if ($v === null || $v === '' || $v === '-') {
            return null;
        }
        if (is_numeric($v)) {
            return CarbonImmutable::instance(Date::excelToDateTimeObject((float) $v))->startOfDay();
        }

        $this->advertencias[] = "Fecha ilegible (texto) descartada, {$rotulo}: '".trim((string) $v)."'";

        return null;
    }
}
