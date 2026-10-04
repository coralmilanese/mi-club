<?php

namespace App\Enums;

enum TipoIngesta: string
{
    case Foto = 'foto';
    case Documento = 'documento';
    case Texto = 'texto';

    public function label(): string
    {
        return match ($this) {
            self::Foto => 'Foto',
            self::Documento => 'Documento',
            self::Texto => 'Texto',
        };
    }
}
