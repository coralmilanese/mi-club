<?php

namespace App\Models;

use App\Enums\EstadoPago;
use App\Enums\OrigenPago;
use Database\Factories\PagoFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $socio_id
 * @property Carbon $fecha
 * @property string $importe_bruto
 * @property int|null $medio_pago_id
 * @property int $cuenta_id
 * @property EstadoPago $estado
 * @property OrigenPago $origen
 * @property int|null $comprobante_id
 * @property string|null $referencia_externa
 * @property string|null $concepto
 * @property Carbon|null $anulado_at
 * @property string|null $motivo_anulacion
 * @property Socio $socio
 * @property Cuenta $cuenta
 * @property Collection<int, PagoCuota> $imputaciones
 */
class Pago extends Model
{
    /** @use HasFactory<PagoFactory> */
    use HasFactory;

    protected $table = 'pagos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_registro' => 'datetime',
            'importe_bruto' => 'decimal:2',
            'estado' => EstadoPago::class,
            'origen' => OrigenPago::class,
            'confirmado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Socio, $this> */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
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

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /** @return HasMany<PagoCuota, $this> */
    public function imputaciones(): HasMany
    {
        return $this->hasMany(PagoCuota::class);
    }

    /** @return MorphOne<Movimiento, $this> */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'origenable');
    }

    /** Lo que del pago todavía no se imputó a ninguna cuota: saldo a favor del socio. */
    public function sobrante(): string
    {
        if ($this->estado !== EstadoPago::Confirmado) {
            return '0.00';
        }

        $imputado = (float) $this->imputaciones()->sum('importe_imputado');

        return number_format(max(0, (float) $this->importe_bruto - $imputado), 2, '.', '');
    }
}
