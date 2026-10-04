<?php

namespace App\Services\Socios;

use App\Enums\TipoAlias;
use App\Models\Socio;
use App\Models\SocioAlias;
use Illuminate\Support\Collection;

/**
 * Matching del socio a partir de los datos extraídos de un comprobante o de un mensaje de texto.
 * Orden: a) CUIT/CBU/alias bancario exacto en SocioAlias (confianza 1.0) → b) similitud de nombre (pg_trgm).
 * Determinístico y auditable: la IA sólo extrae texto, nunca elige al socio (ver plan B.7/B.8).
 */
class MatcherSocios
{
    public function __construct(private BuscadorPorNombre $buscador) {}

    /**
     * @return Collection<int, array{socio: Socio, score: float, motivo: string}>
     */
    public function candidatos(?string $nombre, ?string $cuit = null, ?string $cbu = null, ?string $alias = null, int $limite = 5): Collection
    {
        foreach ([[$cuit, TipoAlias::Cuit, 'CUIT'], [$cbu, TipoAlias::Cbu, 'CBU'], [$alias, TipoAlias::AliasBancario, 'alias bancario']] as [$valor, $tipo, $etiqueta]) {
            if (! $valor) {
                continue;
            }
            $match = SocioAlias::where('tipo', $tipo->value)->where('valor', $this->normalizar($valor))->first();
            $socio = $match ? Socio::find($match->socio_id) : null;
            if ($socio) {
                return collect([['socio' => $socio, 'score' => 1.0, 'motivo' => "{$etiqueta} verificado"]]);
            }
        }

        if (! $nombre) {
            return collect();
        }

        return $this->buscador->candidatos($nombre, $limite)->map(fn ($c) => [...$c, 'motivo' => 'similitud de nombre']);
    }

    private function normalizar(string $v): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));
    }
}
