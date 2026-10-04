<?php

namespace App\Actions\Comprobantes;

use App\Models\Comprobante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Guarda el archivo en el disco `comprobantes` (local en desarrollo, S3 en producción) y deduplica por SHA-256. */
class GuardarComprobante
{
    public const DISCO = 'comprobantes';

    public function desdeUpload(UploadedFile $archivo, string $origen = 'web'): Comprobante
    {
        $hash = hash_file('sha256', $archivo->getRealPath());

        if ($existente = Comprobante::where('hash_sha256', $hash)->first()) {
            if ($existente->comprobantable_id !== null) {
                throw ValidationException::withMessages(['comprobante' => 'Este comprobante ya fue cargado antes.']);
            }

            return $existente; // subido pero nunca asociado a un pago/gasto: se reutiliza
        }

        $path = $archivo->store('comprobantes/'.now()->format('Y/m'), self::DISCO);

        return Comprobante::create([
            'archivo_path' => $path,
            'disco' => self::DISCO,
            'mime' => $archivo->getMimeType(),
            'hash_sha256' => $hash,
            'origen' => $origen,
        ]);
    }

    /** Para archivos que no llegan como UploadedFile (ej. descargados de Telegram). */
    public function desdeContenido(string $contenido, string $extension, string $mime, string $origen, ?string $telegramFileId = null, ?string $telegramMessageId = null): Comprobante
    {
        $hash = hash('sha256', $contenido);

        if ($existente = Comprobante::where('hash_sha256', $hash)->first()) {
            return $existente;
        }

        $path = 'comprobantes/'.now()->format('Y/m').'/'.$hash.'.'.$extension;
        Storage::disk(self::DISCO)->put($path, $contenido);

        return Comprobante::create([
            'archivo_path' => $path,
            'disco' => self::DISCO,
            'mime' => $mime,
            'hash_sha256' => $hash,
            'origen' => $origen,
            'telegram_file_id' => $telegramFileId,
            'telegram_message_id' => $telegramMessageId,
        ]);
    }

    public function url(Comprobante $comprobante): string
    {
        return route('comprobantes.show', $comprobante);
    }

    public function borrarArchivo(Comprobante $comprobante): void
    {
        Storage::disk($comprobante->disco)->delete($comprobante->archivo_path);
    }
}
