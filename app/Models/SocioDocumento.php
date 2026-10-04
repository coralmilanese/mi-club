<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $socio_id
 * @property int $tipo_documento_id
 * @property string|null $archivo_path
 * @property string|null $disco
 * @property string|null $nombre_original
 * @property Carbon|null $subido_at
 * @property string|null $observaciones
 * @property TipoDocumento $tipo
 */
class SocioDocumento extends Model
{
    protected $table = 'socio_documentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subido_at' => 'datetime'];
    }

    /** @return BelongsTo<Socio, $this> */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class);
    }

    /** @return BelongsTo<TipoDocumento, $this> */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    public function tieneArchivo(): bool
    {
        return $this->archivo_path !== null;
    }
}
