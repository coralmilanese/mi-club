<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string $codigo
 * @property bool $obligatorio
 * @property bool $activo
 */
class TipoDocumento extends Model
{
    protected $table = 'tipos_documento';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean', 'activo' => 'boolean'];
    }

    /** @return HasMany<SocioDocumento, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(SocioDocumento::class);
    }
}
