<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $chat_id
 * @property string|null $username
 * @property string|null $nombre
 * @property bool $autorizado
 * @property string $rol
 */
class TelegramChat extends Model
{
    protected $table = 'telegram_chats';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['autorizado' => 'boolean', 'autorizado_at' => 'datetime'];
    }

    /** @return HasMany<IngestaTelegram, $this> */
    public function ingestas(): HasMany
    {
        return $this->hasMany(IngestaTelegram::class);
    }
}
