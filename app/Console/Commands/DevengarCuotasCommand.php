<?php

namespace App\Console\Commands;

use App\Actions\Cuotas\DevengarCuotas;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DevengarCuotasCommand extends Command
{
    protected $signature = 'cuotas:devengar
        {--periodo= : Mes a devengar (AAAA-MM). Por defecto, el mes actual}
        {--desde= : Devengamiento retroactivo: primer mes (AAAA-MM)}
        {--hasta= : Devengamiento retroactivo: último mes (AAAA-MM). Por defecto, el mes actual}
        {--dry-run : Simula sin escribir en la base}';

    protected $description = 'Devenga las cuotas mensuales de los socios (idempotente)';

    public function handle(DevengarCuotas $devengar): int
    {
        try {
            $meses = $this->meses();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($meses as $mes) {
            $r = $devengar($mes, $dry);
            $total += $r['creadas'];

            $this->info(($dry ? '[SIMULACIÓN] ' : '')."{$mes->format('m/Y')}: {$r['creadas']} cuotas nuevas · {$r['existentes']} ya existían");
            if ($dry && $this->getOutput()->isVerbose()) {
                foreach ($r['detalle'] as $d) {
                    $this->line("    {$d['socio']} — {$d['plan']} — \$ {$d['importe']}");
                }
            }
            foreach ($r['advertencias'] as $a) {
                $this->warn("    ⚠ {$a}");
            }
        }

        $this->line("Total: {$total}");

        return self::SUCCESS;
    }

    /** @return list<CarbonImmutable> */
    private function meses(): array
    {
        $parse = function (string $valor): CarbonImmutable {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $valor)) {
                throw new \InvalidArgumentException("Periodo inválido '{$valor}': usá el formato AAAA-MM.");
            }

            return CarbonImmutable::createFromFormat('!Y-m', $valor);
        };

        if ($this->option('desde')) {
            $desde = $parse($this->option('desde'));
            $hasta = $this->option('hasta') ? $parse($this->option('hasta')) : CarbonImmutable::now()->startOfMonth();
            if ($desde->gt($hasta)) {
                throw new \InvalidArgumentException('--desde no puede ser posterior a --hasta.');
            }

            $meses = [];
            for ($m = $desde; $m->lte($hasta); $m = $m->addMonth()) {
                $meses[] = $m;
            }

            return $meses;
        }

        return [$this->option('periodo') ? $parse($this->option('periodo')) : CarbonImmutable::now()->startOfMonth()];
    }
}
