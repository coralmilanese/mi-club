<?php

namespace App\Services\Socios;

use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Matching difuso de un texto libre ("ALFAGEME MATIAS", "Kelo Nagore") contra el padrón,
 * con pg_trgm. Compara contra "apellido nombre", "nombre apellido" y los apodos del socio, sin acentos ni puntuación.
 */
class BuscadorPorNombre
{
    /**
     * @param  Builder<Socio>|null  $base  Restringe el universo (por ejemplo, solo socios vigentes).
     * @return Collection<int, array{socio: Socio, score: float}>
     */
    public function candidatos(string $texto, int $limite = 5, float $minimo = 0.2, ?Builder $base = null): Collection
    {
        $normalizado = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($this->sinAcentos($texto))));
        if ($normalizado === '') {
            return collect();
        }

        $norm = fn (string $expr) => "regexp_replace(unaccent(lower({$expr})), '[^a-z0-9]+', ' ', 'g')";
        $apellidoNombre = $norm("apellido || ' ' || nombre");
        $nombreApellido = $norm("nombre || ' ' || apellido");
        $alias = $norm('a.valor');
        // Los apodos verificados ("Kelo Nagore", "Dani Rosso") compiten con el nombre real: gana el más parecido.
        $score = "GREATEST(similarity({$apellidoNombre}, ?), similarity({$nombreApellido}, ?), COALESCE((SELECT MAX(similarity({$alias}, ?)) FROM socio_alias a WHERE a.socio_id = socios.id AND a.tipo = 'apodo'), 0))";

        $filas = ($base ?? Socio::query())
            ->select('socios.*')
            ->selectRaw("{$score} AS score", [$normalizado, $normalizado, $normalizado])
            ->whereRaw("{$score} >= ?", [$normalizado, $normalizado, $normalizado, $minimo])
            ->orderByDesc('score')
            ->orderBy('apellido')
            ->limit($limite)
            ->get();

        return $filas->map(fn (Socio $s) => ['socio' => $s, 'score' => round((float) $s->getAttribute('score'), 3)]);
    }

    private function sinAcentos(string $t): string
    {
        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }
}
