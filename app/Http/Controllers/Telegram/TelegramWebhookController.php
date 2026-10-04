<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\ManejadorDeUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TelegramWebhookController extends Controller
{
    /** Sólo valida y encola: nunca llama a la IA en el request. Telegram reintenta si no respondemos rápido. */
    public function __invoke(Request $request, ManejadorDeUpdate $manejador): Response
    {
        $secreto = config('aasr.telegram.webhook_secret');
        if ($secreto && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secreto) {
            return response('', HttpResponse::HTTP_FORBIDDEN);
        }

        $manejador->procesar($request->all());

        return response('', HttpResponse::HTTP_OK);
    }
}
