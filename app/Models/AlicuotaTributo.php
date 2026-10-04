<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tributo_id
 * @property string $alicuota
 * @property Carbon $vigencia_desde
 * @property Carbon|null $vigencia_hasta
 */
class AlicuotaTributo extends Model
{
    protected $table = 'alicuotas_tributo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['alicuota' => 'decimal:5', 'vigencia_desde' => 'date', 'vigencia_hasta' => 'date'];
    }

    /** @return BelongsTo<Tributo, $this> */
    public function tributo(): BelongsTo
    {
        return $this->belongsTo(Tributo::class);
    }
}
