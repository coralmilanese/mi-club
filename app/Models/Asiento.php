<?php

namespace App\Models;

use App\Enums\TipoAsiento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $fecha
 * @property string $descripcion
 * @property TipoAsiento $tipo
 */
class Asiento extends Model
{
    protected $table = 'asientos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'tipo' => TipoAsiento::class];
    }

    /** @return HasMany<Movimiento, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }
}
