<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cuenta_id
 * @property Carbon $fecha
 * @property string $saldo_declarado
 * @property string $saldo_calculado
 * @property string $diferencia
 * @property string|null $observaciones
 */
class ArqueoCuenta extends Model
{
    protected $table = 'arqueos_cuenta';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'saldo_declarado' => 'decimal:2', 'saldo_calculado' => 'decimal:2', 'diferencia' => 'decimal:2'];
    }

    /** @return BelongsTo<Cuenta, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }
}
