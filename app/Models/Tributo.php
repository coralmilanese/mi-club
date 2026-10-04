<?php

namespace App\Models;

use App\Enums\BaseTributo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string $codigo
 * @property BaseTributo $base
 * @property bool $es_retencion
 * @property bool $incluye_retenciones_en_base
 * @property string $categoria_libro
 * @property string|null $concepto_libro
 * @property int $orden
 * @property bool $activo
 * @property Collection<int, AlicuotaTributo> $alicuotas
 */
class Tributo extends Model
{
    protected $table = 'tributos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'base' => BaseTributo::class,
            'es_retencion' => 'boolean',
            'incluye_retenciones_en_base' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<AlicuotaTributo, $this> */
    public function alicuotas(): HasMany
    {
        return $this->hasMany(AlicuotaTributo::class)->orderByDesc('vigencia_desde');
    }

    /** La alícuota (en %) vigente en una fecha: siempre la de ESA fecha, nunca "la actual". */
    public function alicuotaVigente(CarbonInterface $fecha): ?AlicuotaTributo
    {
        return $this->alicuotas()
            ->whereDate('vigencia_desde', '<=', $fecha->toDateString())
            ->where(fn ($q) => $q->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $fecha->toDateString()))
            ->first();
    }
}
