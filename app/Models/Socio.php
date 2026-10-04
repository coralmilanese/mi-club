<?php

namespace App\Models;

use App\Enums\CategoriaSocio;
use App\Enums\EstadoSocio;
use App\Enums\SedeSocio;
use Database\Factories\SocioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $nro_socio
 * @property string $apellido
 * @property string $nombre
 * @property string|null $dni
 * @property string|null $email
 * @property string|null $telefono
 * @property string|null $motivo_baja
 * @property int|null $grupo_familiar_id
 * @property CategoriaSocio $categoria
 * @property SedeSocio $sede
 * @property EstadoSocio $estado
 * @property Carbon|null $fecha_nacimiento
 * @property Carbon|null $fecha_asociacion
 * @property Carbon|null $fecha_inicio_actividad
 * @property Carbon|null $fecha_baja
 * @property-read string $nombre_completo
 * @property GrupoFamiliar|null $grupoFamiliar
 * @property Collection<int, SocioEstado> $estados
 * @property Collection<int, SocioDocumento> $documentos
 */
class Socio extends Model
{
    /** @use HasFactory<SocioFactory> */
    use HasFactory;

    protected $table = 'socios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaSocio::class,
            'sede' => SedeSocio::class,
            'estado' => EstadoSocio::class,
            'fecha_nacimiento' => 'date',
            'fecha_asociacion' => 'date',
            'fecha_inicio_actividad' => 'date',
            'fecha_baja' => 'date',
        ];
    }

    /** @return BelongsTo<GrupoFamiliar, $this> */
    public function grupoFamiliar(): BelongsTo
    {
        return $this->belongsTo(GrupoFamiliar::class);
    }

    /** @return HasMany<SocioEstado, $this> */
    public function estados(): HasMany
    {
        return $this->hasMany(SocioEstado::class)->orderByRaw('desde asc nulls first')->orderBy('id');
    }

    /** @return HasMany<SocioDocumento, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(SocioDocumento::class);
    }

    /** @return HasMany<SocioPlan, $this> */
    public function planes(): HasMany
    {
        return $this->hasMany(SocioPlan::class);
    }

    /** @return HasMany<SocioAlias, $this> */
    public function alias(): HasMany
    {
        return $this->hasMany(SocioAlias::class, 'socio_id');
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereIn('estado', [EstadoSocio::Alta->value, EstadoSocio::Activo->value, EstadoSocio::Suspendido->value]);
    }

    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $texto).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('apellido', 'ilike', $like)
                ->orWhere('nombre', 'ilike', $like)
                ->orWhereRaw("(apellido || ' ' || nombre) ilike ?", [$like])
                ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$like])
                ->orWhere('dni', 'like', $like)
                ->orWhere('email', 'ilike', $like);
        });
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->apellido} {$this->nombre}");
    }

    public function estaDeBaja(): bool
    {
        return $this->estado === EstadoSocio::Baja;
    }
}
