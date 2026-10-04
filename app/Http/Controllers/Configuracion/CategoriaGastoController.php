<?php

namespace App\Http\Controllers\Configuracion;

use App\Enums\TipoCategoriaGasto;
use App\Http\Controllers\Controller;
use App\Models\CategoriaGasto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoriaGastoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('configuracion/categorias-gasto', [
            'categorias' => CategoriaGasto::withCount('gastos')->orderBy('nombre')->get()->map(fn (CategoriaGasto $c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'tipo' => $c->tipo->value, 'tipo_label' => $c->tipo->label(), 'activa' => $c->activa, 'gastos_count' => $c->getAttribute('gastos_count'),
            ]),
            'tipos' => TipoCategoriaGasto::opciones(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:categorias_gasto,nombre'],
            'tipo' => ['required', Rule::enum(TipoCategoriaGasto::class)],
        ]);

        $base = Str::slug($d['nombre'], '_');
        $codigo = $base;
        for ($i = 2; CategoriaGasto::where('codigo', $codigo)->exists(); $i++) {
            $codigo = "{$base}_{$i}";
        }
        CategoriaGasto::create([...$d, 'codigo' => $codigo]);

        return back()->with('success', 'Categoría creada.');
    }

    public function update(Request $request, CategoriaGasto $categoria): RedirectResponse
    {
        $categoria->update($request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('categorias_gasto', 'nombre')->ignore($categoria->id)],
            'tipo' => ['required', Rule::enum(TipoCategoriaGasto::class)],
            'activa' => ['boolean'],
        ]));

        return back()->with('success', 'Categoría actualizada.');
    }
}
