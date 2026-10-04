<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nombre
 * @property int|null $titular_socio_id
 * @property Socio|null $titular
 * @property Collection<int, Socio> $miembros
 */
class GrupoFamiliar extends Model
{
    protected $table = 'grupos_familiares';

    protected $guarded = ['id'];

    /** @return BelongsTo<Socio, $this> */
    public function titular(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'titular_socio_id');
    }

    /** @return HasMany<Socio, $this> */
    public function miembros(): HasMany
    {
        return $this->hasMany(Socio::class);
    }

    /**
     * Miembros que no son el titular (los que generan "adicional familiar").
     *
     * @return HasMany<Socio, $this>
     */
    public function adherentes(): HasMany
    {
        return $this->miembros()->where('id', '!=', $this->titular_socio_id);
    }
}
