<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $nombre
 * @property string $codigo
 * @property int|null $cuenta_default_id
 * @property bool $activo
 */
class MedioPago extends Model
{
    protected $table = 'medios_pago';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** @return BelongsTo<Cuenta, $this> */
    public function cuentaDefault(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'cuenta_default_id');
    }
}
