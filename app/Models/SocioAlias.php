<?php

namespace App\Models;

use App\Enums\TipoAlias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocioAlias extends Model
{
    protected $table = 'socio_alias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tipo' => TipoAlias::class, 'verificado_at' => 'datetime'];
    }

    /** @return BelongsTo<Socio, $this> */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
    }
}
