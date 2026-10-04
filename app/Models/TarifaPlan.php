<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $importe
 * @property Carbon $vigencia_desde
 * @property Carbon|null $vigencia_hasta
 */
class TarifaPlan extends Model
{
    protected $table = 'tarifas_plan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'vigencia_desde' => 'date', 'vigencia_hasta' => 'date'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
