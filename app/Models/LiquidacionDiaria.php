<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cuenta_id
 * @property Carbon $fecha
 * @property string $base_credito
 * @property string $base_debito_operativo
 * @property bool $ajustada_manualmente
 * @property string|null $motivo_ajuste
 * @property Carbon|null $recalculada_at
 * @property Cuenta $cuenta
 * @property Collection<int, LiquidacionDiariaTributo> $tributos
 */
class LiquidacionDiaria extends Model
{
    protected $table = 'liquidaciones_diarias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'base_credito' => 'decimal:2',
            'base_debito_operativo' => 'decimal:2',
            'ajustada_manualmente' => 'boolean',
            'recalculada_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cuenta, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /** @return HasMany<LiquidacionDiariaTributo, $this> */
    public function tributos(): HasMany
    {
        return $this->hasMany(LiquidacionDiariaTributo::class);
    }
}
