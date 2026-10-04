<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $categoria_gasto_id
 * @property Carbon $fecha
 * @property string $importe
 * @property string $descripcion
 * @property string|null $proveedor
 * @property int|null $medio_pago_id
 * @property int $cuenta_id
 * @property Carbon|null $periodo_cubierto_desde
 * @property Carbon|null $periodo_cubierto_hasta
 * @property Carbon|null $anulado_at
 * @property CategoriaGasto $categoria
 * @property Cuenta $cuenta
 */
class Gasto extends Model
{
    protected $table = 'gastos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'importe' => 'decimal:2',
            'periodo_cubierto_desde' => 'date',
            'periodo_cubierto_hasta' => 'date',
            'anulado_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CategoriaGasto, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }

    /** @return BelongsTo<Cuenta, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /** @return BelongsTo<MedioPago, $this> */
    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }

    /** @return MorphOne<Movimiento, $this> */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'origenable');
    }
}
