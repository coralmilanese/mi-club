<?php

namespace App\Enums;

enum EstadoIngesta: string
{
    case Recibida = 'recibida';
    case Procesando = 'procesando';
    case EsperandoConfirmacion = 'esperando_confirmacion';
    case EsperandoSocio = 'esperando_socio';
    case EsperandoFecha = 'esperando_fecha';
    case Confirmada = 'confirmada';
    case Descartada = 'descartada';
    case Expirada = 'expirada';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Recibida => 'Recibida',
            self::Procesando => 'Procesando',
            self::EsperandoConfirmacion => 'Esperando confirmación',
            self::EsperandoSocio => 'Esperando socio',
            self::EsperandoFecha => 'Esperando fecha',
            self::Confirmada => 'Confirmada',
            self::Descartada => 'Descartada',
            self::Expirada => 'Expirada',
            self::Error => 'Error',
        };
    }
}
