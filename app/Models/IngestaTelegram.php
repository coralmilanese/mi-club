<?php

namespace App\Models;

use App\Enums\EstadoIngesta;
use App\Enums\TipoIngesta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $telegram_chat_id
 * @property string $message_id
 * @property TipoIngesta $tipo
 * @property string|null $texto_original
 * @property int|null $comprobante_id
 * @property EstadoIngesta $estado
 * @property array<string, mixed>|null $extraccion_json
 * @property array<int, mixed>|null $candidatos_json
 * @property int|null $socio_sugerido_id
 * @property string|null $confianza
 * @property int|null $pago_id
 * @property string|null $error_mensaje
 * @property TelegramChat $chat
 */
class IngestaTelegram extends Model
{
    protected $table = 'ingestas_telegram';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIngesta::class,
            'estado' => EstadoIngesta::class,
            'extraccion_json' => 'array',
            'candidatos_json' => 'array',
            'confianza' => 'decimal:3',
            'costo_usd' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<TelegramChat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }

    /** @return BelongsTo<Comprobante, $this> */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /** @return BelongsTo<Socio, $this> */
    public function socioSugerido(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_sugerido_id');
    }

    /** @return BelongsTo<Pago, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    public function pendiente(): bool
    {
        return in_array($this->estado, [EstadoIngesta::EsperandoConfirmacion, EstadoIngesta::EsperandoSocio, EstadoIngesta::EsperandoFecha, EstadoIngesta::Error], true);
    }
}
