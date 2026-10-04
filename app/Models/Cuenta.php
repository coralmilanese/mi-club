<?php

namespace App\Models;

use App\Enums\TipoCuenta;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nombre
 * @property TipoCuenta $tipo
 * @property string|null $titular
 * @property bool $aplica_tributos
 * @property string $saldo_inicial
 * @property Carbon|null $fecha_saldo_inicial
 * @property bool $activa
 */
class Cuenta extends Model
{
    protected $table = 'cuentas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCuenta::class,
            'aplica_tributos' => 'boolean',
            'activa' => 'boolean',
            'saldo_inicial' => 'decimal:2',
            'fecha_saldo_inicial' => 'date',
        ];
    }

    /** @return HasMany<Movimiento, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    /** Saldo calculado = Σ ingresos − Σ egresos (la apertura es un ingreso más), hasta una fecha inclusive. */
    public function saldoAl(?CarbonInterface $fecha = null): string
    {
        $q = $this->movimientos()->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN importe ELSE -importe END), 0) AS saldo");
        if ($fecha) {
            $q->whereDate('fecha', '<=', $fecha->toDateString());
        }

        return number_format((float) $q->value('saldo'), 2, '.', '');
    }
}
