<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComprobanteController extends Controller
{
    public function show(Comprobante $comprobante): StreamedResponse
    {
        return Storage::disk($comprobante->disco)->response($comprobante->archivo_path);
    }
}
