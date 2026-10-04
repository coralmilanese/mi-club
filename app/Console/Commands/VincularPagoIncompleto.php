<?php

namespace App\Console\Commands;

use App\Actions\Pagos\VincularIngresoComoPagoIncompleto;
use App\Models\Movimiento;
use App\Models\Socio;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class VincularPagoIncompleto extends Command
{
    protected $signature = 'pagos:vincular-incompleto {movimiento : ID del ingreso en el libro} {socio : ID del socio que pagó} {--periodos= : Períodos que debía cubrir, AAAA-MM separados por coma}';

    protected $description = 'Vincula un ingreso del libro a un socio dejando las cuotas como incompletas cuando el dinero ingresado fue menor al que da por cobrado la planilla';

    public function handle(VincularIngresoComoPagoIncompleto $vincular): int
    {
        $ingreso = Movimiento::find($this->argument('movimiento'));
        $socio = Socio::find($this->argument('socio'));
        $periodos = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('periodos')))));

        if (! $ingreso || ! $socio || ! $periodos) {
            $this->error('Indicá un movimiento existente, un socio existente y --periodos=AAAA-MM,AAAA-MM.');

            return self::FAILURE;
        }

        try {
            $pago = $vincular($ingreso, $socio, $periodos);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->join(' '));

            return self::FAILURE;
        }

        $this->info("Pago #{$pago->id} de \${$pago->importe_bruto} vinculado a {$socio->nombre_completo}.");
        foreach ($pago->imputaciones()->with('cuota.socio')->get() as $i) {
            $c = $i->cuota;
            $this->line(sprintf('  %s %s: imputado $%s → %s', $c->periodo->format('m/Y'), $c->socio->nombre_completo, $i->importe_imputado, $c->estado->label()));
        }

        return self::SUCCESS;
    }
}
