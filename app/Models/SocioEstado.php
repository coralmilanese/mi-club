<?php

namespace App\Models;

use App\Enums\EstadoSocio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property EstadoSocio $estado
 * @property Carbon|null $desde
 * @property Carbon|null $hasta
 * @property string|null $motivo
 */
class SocioEstado extends Model
{
    protected $table = 'socio_estados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoSocio::class,
            'desde' => 'date',
            'hasta' => 'date',
        ];
    }

    /** @return BelongsTo<Socio, $this> */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
    }
}
