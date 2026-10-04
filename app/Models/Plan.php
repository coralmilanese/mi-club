<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $tipo_plan
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $genera_cuota
 * @property bool $es_adicional_familiar
 * @property int $orden
 * @property bool $activo
 * @property Collection<int, TarifaPlan> $tarifas
 */
class Plan extends Model
{
    protected $table = 'planes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['genera_cuota' => 'boolean', 'es_adicional_familiar' => 'boolean', 'activo' => 'boolean'];
    }

    /** @return HasMany<TarifaPlan, $this> */
    public function tarifas(): HasMany
    {
        return $this->hasMany(TarifaPlan::class)->orderByDesc('vigencia_desde');
    }

    /** @return HasMany<SocioPlan, $this> */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(SocioPlan::class);
    }

    /** La tarifa vigente a una fecha: la de mayor vigencia_desde <= fecha que no esté vencida. */
    public function tarifaVigente(CarbonInterface $fecha): ?TarifaPlan
    {
        return $this->tarifas()
            ->whereDate('vigencia_desde', '<=', $fecha->toDateString())
            ->where(fn ($q) => $q->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $fecha->toDateString()))
            ->first();
    }
}
