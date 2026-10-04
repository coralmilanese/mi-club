<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\TelegramChat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramChatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('configuracion/telegram', [
            'chats' => TelegramChat::withCount('ingestas')->orderByDesc('autorizado')->orderBy('id')->get(),
            'usuarios_configurados' => config('aasr.telegram.usuarios_autorizados'),
            'bot_configurado' => (bool) config('aasr.telegram.bot_token'),
        ]);
    }

    public function update(Request $request, TelegramChat $chat): RedirectResponse
    {
        $d = $request->validate(['autorizado' => ['required', 'boolean']]);

        $chat->update(['autorizado' => $d['autorizado'], 'autorizado_at' => $d['autorizado'] ? now() : null]);

        return back()->with('success', $d['autorizado'] ? "{$chat->chat_id} autorizado." : "{$chat->chat_id} revocado.");
    }
}
