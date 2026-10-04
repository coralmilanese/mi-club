<?php

namespace App\Models;

use App\Enums\TipoCategoriaGasto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string $codigo
 * @property TipoCategoriaGasto $tipo
 * @property bool $activa
 */
class CategoriaGasto extends Model
{
    protected $table = 'categorias_gasto';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tipo' => TipoCategoriaGasto::class, 'activa' => 'boolean'];
    }

    /** @return HasMany<Gasto, $this> */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }
}
