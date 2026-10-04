<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Interpreta un mensaje de texto sobre un cobro en efectivo, tipo "Juan Pérez pagó 35 mil julio" o
 * "cobré 60000 a Gelos, agosto y septiembre". Sólo extrae texto: el matching del socio es código aparte.
 */
class ExtractorPagoEfectivo implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
            El tesorero de un club te escribe por Telegram avisando que cobró una cuota en efectivo. Extraé:

            - socio_texto: el nombre tal como lo escribió (puede ser un apodo).
            - importe: el monto en pesos como número. "35 mil" = 35000, "$35.000" = 35000, "30k" = 30000.
            - periodos: lista de meses mencionados, en minúscula sin acentos (ej. ["agosto", "septiembre"]). Si
              menciona un año explícito ponelo junto al mes como "julio 2025". Si no menciona ningún mes, lista vacía.
            - fecha: si menciona una fecha explícita del pago (no la de hoy), en YYYY-MM-DD; si no, null.

            Si el mensaje no parece hablar de un cobro (es un comando, un saludo, una pregunta), devolvé
            es_pago = false y confianza baja. Nunca inventes un nombre o un importe que no esté en el texto.
            TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'socio_texto' => $schema->string()->nullable(),
            'importe' => $schema->number()->nullable(),
            'periodos' => $schema->array()->nullable(),
            'fecha' => $schema->string()->nullable(),
            'es_pago' => $schema->boolean()->required(),
            'confianza' => $schema->number()->required(),
        ];
    }
}
