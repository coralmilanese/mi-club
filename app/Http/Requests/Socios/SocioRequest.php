<?php

namespace App\Http\Requests\Socios;

use App\Enums\CategoriaSocio;
use App\Enums\SedeSocio;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->esTesorero() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Los inputs vacíos del formulario llegan como '' → null.
        $this->merge(collect($this->all())->map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v))->all());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Socio|null $socio */
        $socio = $this->route('socio');

        return [
            'nro_socio' => ['nullable', 'integer', 'min:1'],
            'apellido' => ['required', 'string', 'max:255'],
            'nombre' => ['required', 'string', 'max:255'],
            'genero' => ['nullable', Rule::in(['M', 'F'])],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'nacionalidad' => ['nullable', 'string', 'max:100'],
            'dni' => ['nullable', 'regex:/^\d{6,10}$/', Rule::unique('socios', 'dni')->ignore($socio?->id)],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'profesion' => ['nullable', 'string', 'max:255'],
            'categoria' => ['required', Rule::enum(CategoriaSocio::class)],
            'sede' => ['required', Rule::enum(SedeSocio::class)],
            'fecha_asociacion' => ['nullable', 'date'],
            'fecha_inicio_actividad' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['dni' => 'DNI', 'nro_socio' => 'número de socio', 'fecha_nacimiento' => 'fecha de nacimiento'];
    }
}
