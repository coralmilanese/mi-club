<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Extrae los datos de un comprobante de transferencia bancaria argentino (foto o PDF).
 * Sólo extrae texto: NO elige al socio. El matching contra el padrón es código determinístico (BuscadorPorNombre).
 */
class ExtractorComprobante implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
            Sos un extractor de datos de comprobantes de transferencia bancaria de bancos argentinos (home banking,
            Mercado Pago, billeteras virtuales). Te paso la imagen o el PDF de un comprobante.

            Extraé ÚNICAMENTE lo que está escrito en el comprobante. Si un dato no se lee con claridad, devolvé null
            en ese campo y bajá la confianza: NUNCA inventes ni completes un dato que no esté legible. No adivines
            el nombre del titular a partir de un alias de cuenta: copiá el alias tal cual si eso es lo único visible.

            La fecha va en formato YYYY-MM-DD. Si el comprobante trae fecha y hora de operación, usá la fecha de la
            operación (no la de impresión ni la de vencimiento). El importe es el monto transferido, en pesos
            argentinos, como número (sin separador de miles).
            TXT;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'importe' => $schema->number()->nullable()->description('Monto transferido, en pesos'),
            'fecha' => $schema->string()->nullable()->description('Fecha de la operación, YYYY-MM-DD'),
            'titular_origen' => $schema->string()->nullable()->description('Nombre de quien envía la transferencia'),
            'cuit_origen' => $schema->string()->nullable(),
            'cbu_origen' => $schema->string()->nullable(),
            'alias_origen' => $schema->string()->nullable(),
            'banco_origen' => $schema->string()->nullable(),
            'nro_operacion' => $schema->string()->nullable()->description('Número de operación / comprobante'),
            'es_comprobante_transferencia' => $schema->boolean()->required()->description('false si la imagen no parece un comprobante de transferencia'),
            'confianza' => $schema->number()->required()->description('0 a 1: qué tan seguro estás de los datos extraídos'),
        ];
    }
}
