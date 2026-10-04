<?php

namespace App\Models;

use App\Enums\TipoMovimiento;
use App\Observers\MovimientoObserver;
use Database\Factories\MovimientoFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $fecha
 * @property string $concepto
 * @property TipoMovimiento $tipo
 * @property string $importe
 * @property int $cuenta_id
 * @property int|null $medio_pago_id
 * @property string $categoria_libro
 * @property bool $es_tributo
 * @property int|null $asiento_id
 * @property Carbon|null $anulado_at
 * @property int|null $anulado_por_movimiento_id
 * @property Cuenta $cuenta
 */
#[ObservedBy(MovimientoObserver::class)]
class Movimiento extends Model
{
    /** @use HasFactory<MovimientoFactory> */
    use HasFactory;

    protected $table = 'movimientos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'tipo' => TipoMovimiento::class,
            'importe' => 'decimal:2',
            'es_tributo' => 'boolean',
            'anulado_at' => 'datetime',
        ];
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

    /** @return BelongsTo<Asiento, $this> */
    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    /** @return MorphTo<Model, $this> */
    public function origenable(): MorphTo
    {
        return $this->morphTo();
    }

    public function estaAnulado(): bool
    {
        return $this->anulado_at !== null;
    }
}
