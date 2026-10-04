<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $pago_id
 * @property int $cuota_id
 * @property string $importe_imputado
 * @property Cuota $cuota
 * @property Pago $pago
 */
class PagoCuota extends Model
{
    protected $table = 'pago_cuotas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importe_imputado' => 'decimal:2'];
    }

    /** @return BelongsTo<Pago, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    /** @return BelongsTo<Cuota, $this> */
    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class);
    }
}
