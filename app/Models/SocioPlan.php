<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $socio_id
 * @property int $plan_id
 * @property Carbon $desde
 * @property Carbon|null $hasta
 * @property string|null $motivo
 * @property Plan $plan
 */
class SocioPlan extends Model
{
    protected $table = 'socio_planes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date'];
    }

    /** @return BelongsTo<Socio, $this> */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
