<?php

use App\Enums\CategoriaSocio;
use App\Enums\EstadoSocio;
use App\Enums\SedeSocio;
use App\Models\Socio;
use App\Models\TipoDocumento;
use App\Services\Importacion\ImportadorSocios;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function excelSocios(array $filas): string
{
    $wb = new Spreadsheet;
    $ws = $wb->getActiveSheet()->setTitle('Socios');
    $ws->fromArray(['Nro', 'Apellido', 'Nombre', 'Genero', 'Fecha Nac', 'Nacionalidad', 'DNI', 'Dirección', 'Ciudad', 'Provincia', 'Email', 'Profesion', 'Status', 'Cel', 'FOTO', 'PLANEADORES - AASR', 'ALTA FED', 'F ALTA FED', 'BAJA FED', 'INIC ACT', 'F ASOC', 'PLANILLA'], null, 'A1');
    foreach ($filas as $i => $fila) {
        $ws->fromArray($fila, null, 'A'.($i + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'socios').'.xlsx';
    (new Xlsx($wb))->save($path);

    return $path;
}

/** Arma una fila de 22 columnas con los datos relevantes. */
function fila(array $c): array
{
    $base = array_fill(0, 22, null);
    foreach ($c as $i => $v) {
        $base[$i] = $v;
    }

    return $base;
}

beforeEach(function () {
    TipoDocumento::create(['codigo' => 'foto', 'nombre' => 'Foto', 'obligatorio' => true]);
    TipoDocumento::create(['codigo' => 'planilla_datos', 'nombre' => 'Planilla', 'obligatorio' => true]);
});

test('importa activos y bajas, limpia datos sucios y arma el historial', function () {
    $archivo = excelSocios([
        fila([0 => 1, 1 => 'ALFAJEME ', 2 => 'MATIAS', 3 => 'M', 4 => 31344, 5 => 'ARGENTINO', 6 => 31942095, 10 => '-----', 12 => 'Activo', 13 => '-', 14 => 'SI', 15 => 'PLANEADORES', 21 => 'si']),
        fila([0 => 12, 1 => 'GUTIERREZ', 2 => 'RICARDO', 12 => 'Vitalicio ', 15 => 'AASR']),
        fila([0 => 'Bajas 2025']),
        fila([0 => 5, 1 => 'BLANCO', 2 => 'RICARDO', 6 => 22772426, 10 => 'ricardoblanco72@hotmail.com', 12 => 'Activo', 15 => 'AASR', 20 => 'setiembre 23']),
    ]);

    $r = app(ImportadorSocios::class)->importar($archivo);

    expect($r['creados'])->toBe(3)
        ->and($r['por_estado'])->toEqual(['activo' => 2, 'baja' => 1]);

    $alfajeme = Socio::where('dni', '31942095')->firstOrFail();
    expect($alfajeme->apellido)->toBe('Alfajeme')
        ->and($alfajeme->nombre)->toBe('Matias')
        ->and($alfajeme->email)->toBeNull()
        ->and($alfajeme->telefono)->toBeNull()
        ->and($alfajeme->sede)->toBe(SedeSocio::Planeadores)
        ->and($alfajeme->fecha_nacimiento->toDateString())->toBe('1985-10-24')
        ->and($alfajeme->documentos)->toHaveCount(2);

    expect(Socio::where('apellido', 'Gutierrez')->firstOrFail()->categoria)->toBe(CategoriaSocio::Vitalicio);

    $blanco = Socio::where('dni', '22772426')->firstOrFail();
    expect($blanco->estado)->toBe(EstadoSocio::Baja)
        ->and($blanco->fecha_baja->toDateString())->toBe('2025-12-31')
        ->and($blanco->fecha_asociacion)->toBeNull()
        ->and($blanco->estados->pluck('estado')->all())->toBe([EstadoSocio::Activo, EstadoSocio::Baja]);

    expect(collect($r['advertencias'])->join(' '))->toContain('setiembre 23');
});

test('un mismo DNI en dos bajas se unifica como reingreso', function () {
    $archivo = excelSocios([
        fila([0 => 'Bajas 2024']),
        fila([0 => 34, 1 => 'MAZZAFERRO', 2 => 'FERNANDO', 6 => 34707112, 12 => 'Activo']),
        fila([0 => 'Bajas 2025']),
        fila([0 => 25, 1 => 'MAZZAFERRO', 2 => 'FERNANDO', 6 => 34707112, 12 => 'Activo']),
    ]);

    $r = app(ImportadorSocios::class)->importar($archivo);

    expect($r['creados'])->toBe(1)->and($r['reingresos'])->toHaveCount(1);
    $s = Socio::firstOrFail();
    expect($s->estado)->toBe(EstadoSocio::Baja)
        ->and($s->fecha_baja->toDateString())->toBe('2025-12-31')
        ->and($s->estados->pluck('estado')->all())->toBe([EstadoSocio::Activo, EstadoSocio::Baja, EstadoSocio::Activo, EstadoSocio::Baja]);
});

test('el dry-run no escribe y una segunda importación es idempotente', function () {
    $archivo = excelSocios([fila([1 => 'PEREZ', 2 => 'JUAN', 6 => 11111111, 12 => 'Activo'])]);

    $simulada = app(ImportadorSocios::class)->importar($archivo, dryRun: true);
    expect($simulada['creados'])->toBe(1)->and(Socio::count())->toBe(0);

    app(ImportadorSocios::class)->importar($archivo);
    $segunda = app(ImportadorSocios::class)->importar($archivo);

    expect(Socio::count())->toBe(1)->and($segunda['creados'])->toBe(0)->and($segunda['omitidos'])->toBe(1);
});

test('el comando falla con un archivo inexistente', function () {
    $this->artisan('socios:importar', ['archivo' => '/no/existe.xlsx'])->assertFailed();
});
