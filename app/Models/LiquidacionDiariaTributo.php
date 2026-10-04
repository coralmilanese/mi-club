<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $liquidacion_diaria_id
 * @property int $tributo_id
 * @property string|null $alicuota_aplicada
 * @property string|null $base_imponible
 * @property string $importe
 * @property int|null $movimiento_id
 * @property Tributo $tributo
 */
class LiquidacionDiariaTributo extends Model
{
    protected $table = 'liquidacion_diaria_tributos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['alicuota_aplicada' => 'decimal:5', 'base_imponible' => 'decimal:2', 'importe' => 'decimal:2'];
    }

    /** @return BelongsTo<LiquidacionDiaria, $this> */
    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionDiaria::class, 'liquidacion_diaria_id');
    }

    /** @return BelongsTo<Tributo, $this> */
    public function tributo(): BelongsTo
    {
        return $this->belongsTo(Tributo::class);
    }

    /** @return BelongsTo<Movimiento, $this> */
    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }
}
