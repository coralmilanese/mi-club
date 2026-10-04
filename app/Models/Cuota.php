<?php

namespace App\Models;

use App\Enums\EstadoCuota;
use Database\Factories\CuotaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $socio_id
 * @property Carbon $periodo
 * @property int $plan_id
 * @property string $importe_devengado
 * @property string|null $importe_cobrado
 * @property string $importe_imputado
 * @property EstadoCuota $estado
 * @property int|null $socio_pagador_id
 * @property Plan $plan
 * @property Socio $socio
 */
class Cuota extends Model
{
    /** @use HasFactory<CuotaFactory> */
    use HasFactory;

    protected $table = 'cuotas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'periodo' => 'date',
            'fecha_cancelacion' => 'date',
            'importe_devengado' => 'decimal:2',
            'importe_cobrado' => 'decimal:2',
            'importe_imputado' => 'decimal:2',
            'estado' => EstadoCuota::class,
        ];
    }

    /** @return HasMany<PagoCuota, $this> */
    public function imputaciones(): HasMany
    {
        return $this->hasMany(PagoCuota::class);
    }

    /** @return BelongsTo<Socio, $this> */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
    }

    /** @return BelongsTo<Socio, $this> */
    public function pagador(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_pagador_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
