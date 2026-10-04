<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $archivo_path
 * @property string $disco
 * @property string|null $mime
 * @property string $hash_sha256
 */
class Comprobante extends Model
{
    protected $table = 'comprobantes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['extraccion_json' => 'array', 'procesado_at' => 'datetime', 'confianza' => 'decimal:3'];
    }

    /** @return MorphTo<Model, $this> */
    public function comprobantable(): MorphTo
    {
        return $this->morphTo();
    }
}
