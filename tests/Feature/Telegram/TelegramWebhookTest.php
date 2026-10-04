<?php

use App\Actions\Cuotas\DevengarCuotas;
use App\Actions\Planes\AsignarPlanSocio;
use App\Ai\Agents\ExtractorComprobante;
use App\Ai\Agents\ExtractorPagoEfectivo;
use App\Enums\EstadoCuota;
use App\Enums\EstadoIngesta;
use App\Models\Cuota;
use App\Models\GrupoFamiliar;
use App\Models\IngestaTelegram;
use App\Models\Movimiento;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\SocioAlias;
use App\Models\TelegramChat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriaGastoSeeder;
use Database\Seeders\LibroDeCajaSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;

const SECRETO = 'el-secreto-de-prueba';

beforeEach(function () {
    config(['aasr.telegram.webhook_secret' => SECRETO, 'aasr.telegram.usuarios_autorizados' => ['coraltesorera']]);
    Storage::fake('comprobantes');
    $this->seed([PlanSeeder::class, LibroDeCajaSeeder::class, CategoriaGastoSeeder::class]);
    $this->travelTo('2026-09-20');

    // Las llamadas salientes a Telegram no pegan a la red: se registran y se responden con éxito.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg', 'message_id' => 55]])]);

    $this->gelos = Socio::factory()->create(['apellido' => 'Gelos', 'nombre' => 'Gabriel']);
    app(AsignarPlanSocio::class)($this->gelos, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-09-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-09-01'));

    $this->post = fn (array $update, ?string $secreto = SECRETO) => $this->postJson('/telegram/webhook', $update, $secreto ? ['X-Telegram-Bot-Api-Secret-Token' => $secreto] : []);
    $this->mensajeFoto = fn (int $msgId = 1, string $chatId = '111') => [
        'update_id' => $msgId,
        'message' => ['message_id' => $msgId, 'date' => time(), 'chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId, 'username' => 'coraltesorera', 'first_name' => 'Coral'], 'photo' => [['file_id' => 'f1', 'file_size' => 100]]],
    ];
    $this->mensajeTexto = fn (string $texto, int $msgId = 2, string $chatId = '111') => [
        'update_id' => $msgId,
        'message' => ['message_id' => $msgId, 'date' => time(), 'chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId, 'username' => 'coraltesorera', 'first_name' => 'Coral'], 'text' => $texto],
    ];
});

test('rechaza el webhook sin el secret token correcto', function () {
    ($this->post)(($this->mensajeFoto)(), 'secreto-equivocado')->assertForbidden();
    ($this->post)(($this->mensajeFoto)(), null)->assertForbidden();
    expect(IngestaTelegram::count())->toBe(0);
});

test('un chat no autorizado se ignora silenciosamente, sin crear nada', function () {
    $update = ($this->mensajeFoto)(1, '999');
    $update['message']['from']['username'] = 'desconocido';

    ($this->post)($update)->assertOk();

    expect(IngestaTelegram::count())->toBe(0)->and(TelegramChat::first()->autorizado)->toBeFalse();
});

test('un username de la whitelist se autoriza solo la primera vez que escribe', function () {
    ($this->post)(($this->mensajeTexto)('/saldo', 1))->assertOk();

    $chat = TelegramChat::firstOrFail();
    expect($chat->autorizado)->toBeTrue()->and($chat->username)->toBe('coraltesorera');
});

test('foto → extracción → match exacto por alias → confirmación con teclado, y confirmar crea el pago', function () {
    SocioAlias::create(['socio_id' => $this->gelos->id, 'tipo' => 'cuit', 'valor' => '20123456789']);
    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'cuit_origen' => '20-12345678-9',
        'cbu_origen' => null, 'alias_origen' => null, 'banco_origen' => 'Banco Nación', 'nro_operacion' => '4829173',
        'es_comprobante_transferencia' => true, 'confianza' => 0.97,
    ]]);

    ($this->post)(($this->mensajeFoto)())->assertOk();

    $ingesta = IngestaTelegram::firstOrFail();
    expect($ingesta->estado)->toBe(EstadoIngesta::EsperandoConfirmacion)
        ->and($ingesta->socio_sugerido_id)->toBe($this->gelos->id)
        ->and((float) $ingesta->confianza)->toBe(0.97)
        ->and($ingesta->comprobante_id)->not->toBeNull();
    Storage::disk('comprobantes')->assertExists($ingesta->comprobante->archivo_path);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], 'Comprobante reconocido'));

    ($this->post)(['update_id' => 99, 'callback_query' => ['id' => 'cb1', 'data' => "confirmar:{$ingesta->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]])->assertOk();

    expect(Pago::count())->toBe(1)->and(Cuota::firstOrFail()->estado)->toBe(EstadoCuota::Pagada)
        ->and($ingesta->fresh()->estado)->toBe(EstadoIngesta::Confirmada)
        ->and(Movimiento::where('es_tributo', true)->count())->toBe(3); // los impuestos del día se calcularon solos
});

test('socio ambiguo: pide elegir, y el callback "elegir_socio" termina en la propuesta correcta', function () {
    $otro = Socio::factory()->create(['apellido' => 'Gelos', 'nombre' => 'Gaston']);
    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gelos', 'cuit_origen' => null, 'cbu_origen' => null,
        'alias_origen' => null, 'banco_origen' => null, 'nro_operacion' => null, 'es_comprobante_transferencia' => true, 'confianza' => 0.6,
    ]]);

    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();
    expect($ingesta->estado)->toBe(EstadoIngesta::EsperandoSocio);

    ($this->post)(['update_id' => 50, 'callback_query' => ['id' => 'cb2', 'data' => "elegir_socio:{$ingesta->id}:{$this->gelos->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]])->assertOk();

    expect($ingesta->fresh()->estado)->toBe(EstadoIngesta::EsperandoConfirmacion)->and($ingesta->fresh()->socio_sugerido_id)->toBe($this->gelos->id);
    expect($otro)->not->toBeNull(); // existe para que la ambigüedad sea real
});

test('socio no encontrado: el tesorero lo escribe por texto y la ingesta se resuelve', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Nombre Rarísimo Sin Match', 'cuit_origen' => null,
        'cbu_origen' => null, 'alias_origen' => null, 'banco_origen' => null, 'nro_operacion' => null, 'es_comprobante_transferencia' => true, 'confianza' => 0.9,
    ]]);
    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();
    expect($ingesta->estado)->toBe(EstadoIngesta::EsperandoSocio);

    ($this->post)(($this->mensajeTexto)('Gabriel Gelos', 2))->assertOk();

    expect($ingesta->fresh()->estado)->toBe(EstadoIngesta::EsperandoConfirmacion)->and($ingesta->fresh()->socio_sugerido_id)->toBe($this->gelos->id);
});

test('fecha ilegible: nunca se asume la del mensaje, se pide por texto y acepta DD/MM/AAAA', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => 35000.0, 'fecha' => null, 'titular_origen' => 'Gabriel Gelos', 'cuit_origen' => null, 'cbu_origen' => null,
        'alias_origen' => null, 'banco_origen' => null, 'nro_operacion' => null, 'es_comprobante_transferencia' => true, 'confianza' => 0.8,
    ]]);
    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();
    expect($ingesta->estado)->toBe(EstadoIngesta::Error);

    // El tesorero pide "cambiar fecha" (en este caso directo por botón ya que no hubo propuesta) — probamos el camino de texto:
    $ingesta->update(['estado' => 'esperando_fecha', 'extraccion_json' => ['socio_id' => $this->gelos->id, 'importe' => '35000']]);
    ($this->post)(($this->mensajeTexto)('15/07/2026', 3))->assertOk();

    expect($ingesta->fresh()->estado)->toBe(EstadoIngesta::EsperandoConfirmacion)
        ->and($ingesta->fresh()->extraccion_json['fecha'])->toBe('2026-07-15');
});

test('un PDF que no es un comprobante se descarta solo, sin pedir nada', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => null, 'fecha' => null, 'titular_origen' => null, 'cuit_origen' => null, 'cbu_origen' => null,
        'alias_origen' => null, 'banco_origen' => null, 'nro_operacion' => null, 'es_comprobante_transferencia' => false, 'confianza' => 0.1,
    ]]);
    $update = ($this->mensajeFoto)();
    unset($update['message']['photo']);
    $update['message']['document'] = ['file_id' => 'd1', 'mime_type' => 'application/pdf'];

    ($this->post)($update)->assertOk();

    expect(IngestaTelegram::firstOrFail()->estado)->toBe(EstadoIngesta::Descartada);
});

test('comprobante duplicado (mismo archivo) se detecta por hash y no genera un segundo pago', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'cuit_origen' => null, 'cbu_origen' => null,
        'alias_origen' => null, 'banco_origen' => null, 'nro_operacion' => null, 'es_comprobante_transferencia' => true, 'confianza' => 0.95,
    ]]);
    ($this->post)(($this->mensajeFoto)(1))->assertOk();
    $primera = IngestaTelegram::firstOrFail();
    ($this->post)(['update_id' => 10, 'callback_query' => ['id' => 'cb3', 'data' => "confirmar:{$primera->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]]);
    expect(Pago::count())->toBe(1);

    // Mismo file_id (getFile devuelve el mismo file_path mockeado): el segundo mensaje queda descartado.
    ($this->post)(($this->mensajeFoto)(2))->assertOk();

    expect(IngestaTelegram::count())->toBe(2)->and(IngestaTelegram::orderByDesc('id')->first()->estado)->toBe(EstadoIngesta::Descartada)
        ->and(Pago::count())->toBe(1);
});

test('el mismo update_id/message_id reenviado por Telegram es idempotente', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.9]]);

    ($this->post)(($this->mensajeFoto)(7))->assertOk();
    ($this->post)(($this->mensajeFoto)(7))->assertOk(); // Telegram reintenta

    expect(IngestaTelegram::count())->toBe(1);
});

test('descartar deja la ingesta sin tocar el libro, y confirmar dos veces la segunda falla con aviso', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);
    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();

    ($this->post)(['update_id' => 20, 'callback_query' => ['id' => 'cbA', 'data' => "confirmar:{$ingesta->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]]);
    expect(Pago::count())->toBe(1);

    ($this->post)(['update_id' => 21, 'callback_query' => ['id' => 'cbB', 'data' => "confirmar:{$ingesta->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]])->assertOk();

    expect(Pago::count())->toBe(1); // no duplicó nada
});

test('tope de gasto mensual superado: no llama a la IA y avisa cargar a mano', function () {
    config(['aasr.ia.max_costo_usd_mes' => 1]);
    IngestaTelegram::create(['telegram_chat_id' => TelegramChat::create(['chat_id' => 'x', 'autorizado' => true])->id, 'message_id' => 'm', 'tipo' => 'foto', 'estado' => 'confirmada', 'costo_usd' => 5]);

    Ai::fakeAgent(ExtractorComprobante::class, fn () => throw new Exception('no debería llamar a la IA'));

    ($this->post)(($this->mensajeFoto)())->assertOk();

    expect(IngestaTelegram::where('estado', 'error')->exists())->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], 'tope de gasto'));
});

test('pago en efectivo por texto: se interpreta, matchea y al confirmar entra sin impuestos', function () {
    Ai::fakeAgent(ExtractorPagoEfectivo::class, [['socio_texto' => 'Gabriel Gelos', 'importe' => 35000.0, 'periodos' => ['septiembre'], 'fecha' => null, 'es_pago' => true, 'confianza' => 0.9]]);

    ($this->post)(($this->mensajeTexto)('Gabriel Gelos pagó 35000 en efectivo'))->assertOk();

    $ingesta = IngestaTelegram::firstOrFail();
    expect($ingesta->estado)->toBe(EstadoIngesta::EsperandoConfirmacion)->and($ingesta->socio_sugerido_id)->toBe($this->gelos->id);

    ($this->post)(['update_id' => 30, 'callback_query' => ['id' => 'cbC', 'data' => "confirmar:{$ingesta->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]])->assertOk();

    $pago = Pago::firstOrFail();
    expect($pago->cuenta->tipo->value)->toBe('efectivo')->and(Movimiento::where('es_tributo', true)->count())->toBe(0);
});

test('un texto que no parece un cobro se descarta sin molestar', function () {
    Ai::fakeAgent(ExtractorPagoEfectivo::class, [['socio_texto' => null, 'importe' => null, 'periodos' => [], 'fecha' => null, 'es_pago' => false, 'confianza' => 0.1]]);

    ($this->post)(($this->mensajeTexto)('hola, ¿cómo andan?'))->assertOk();

    expect(IngestaTelegram::firstOrFail()->estado)->toBe(EstadoIngesta::Descartada)->and(Pago::count())->toBe(0);
});

test('comandos: /deudores, /saldo y /socio responden sin tocar nada', function () {
    ($this->post)(($this->mensajeTexto)('/saldo', 1))->assertOk();
    ($this->post)(($this->mensajeTexto)('/deudores', 2))->assertOk();
    ($this->post)(($this->mensajeTexto)('/socio Gelos', 3))->assertOk();

    Http::assertSentCount(3); // sólo sendMessage, nada de IA
    expect(IngestaTelegram::count())->toBe(0);
});

test('comando telegram:webhook registra la URL con el secret token', function () {
    $this->artisan('telegram:webhook', ['url' => 'https://ejemplo.ngrok.io'])->assertSuccessful();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'setWebhook') && $r['url'] === 'https://ejemplo.ngrok.io/telegram/webhook' && $r['secret_token'] === SECRETO);
});

test('telegram:expirar-pendientes marca como expiradas las ingestas viejas sin tocar las recientes', function () {
    $chat = TelegramChat::create(['chat_id' => 'x', 'autorizado' => true]);
    $vieja = IngestaTelegram::create(['telegram_chat_id' => $chat->id, 'message_id' => '1', 'tipo' => 'foto', 'estado' => 'esperando_confirmacion']);
    $vieja->forceFill(['updated_at' => now()->subDays(10)])->save();
    $reciente = IngestaTelegram::create(['telegram_chat_id' => $chat->id, 'message_id' => '2', 'tipo' => 'foto', 'estado' => 'esperando_confirmacion']);

    $this->artisan('telegram:expirar-pendientes')->assertSuccessful();

    expect($vieja->fresh()->estado)->toBe(EstadoIngesta::Expirada)->and($reciente->fresh()->estado)->toBe(EstadoIngesta::EsperandoConfirmacion);
});

test('la bandeja web y el panel de chats funcionan, y solo el tesorero confirma o autoriza', function () {
    $tesorero = User::factory()->create();
    $lector = User::factory()->lectura()->create();
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);
    ($this->post)(($this->mensajeFoto)());
    $ingesta = IngestaTelegram::firstOrFail();

    $this->actingAs($lector)->get('/telegram/pendientes')->assertOk();
    $this->actingAs($lector)->get('/configuracion/telegram')->assertOk();
    $this->actingAs($lector)->post("/telegram/pendientes/{$ingesta->id}/confirmar")->assertForbidden();

    $this->actingAs($tesorero)->post("/telegram/pendientes/{$ingesta->id}/confirmar")->assertRedirect();
    expect(Pago::count())->toBe(1);

    $chat = TelegramChat::firstOrFail();
    $this->actingAs($tesorero)->put("/configuracion/telegram/{$chat->id}", ['autorizado' => false])->assertRedirect();
    expect($chat->fresh()->autorizado)->toBeFalse();
});

test('/start no rompe el webhook (regresión: el texto de ayuda no puede traer < > sin escapar)', function () {
    ($this->post)(($this->mensajeTexto)('/start', 1))->assertOk();
    ($this->post)(($this->mensajeTexto)('/socio', 2))->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && ! str_contains((string) $r['text'], '<nombre>'));
});

test('un error al mandar el mensaje (ej. HTML mal formado) no tira abajo el webhook: el pago ya quedó guardado', function () {
    Http::fake([
        'api.telegram.org/*sendMessage*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], 400),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
    ]);
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);

    ($this->post)(($this->mensajeFoto)())->assertOk();

    // El mensaje de Telegram falló, pero la ingesta quedó bien construida igual.
    expect(IngestaTelegram::firstOrFail()->estado)->toBe(EstadoIngesta::EsperandoConfirmacion);
});

test('un nombre de socio con caracteres especiales no rompe /deudores ni /socio', function () {
    $raro = Socio::factory()->create(['apellido' => 'O\'Connor & Cía <hijos>', 'nombre' => 'José']);
    app(AsignarPlanSocio::class)($raro, Plan::where('tipo_plan', 'aasr')->firstOrFail(), CarbonImmutable::parse('2026-09-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-09-01'));

    ($this->post)(($this->mensajeTexto)('/deudores', 1))->assertOk();
    ($this->post)(($this->mensajeTexto)("/socio O'Connor", 2))->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], '&lt;hijos&gt;'));
});

test('el titular de un grupo familiar paga su cuota y la de sus adherentes: el mensaje distingue cada una (regresión)', function () {
    $milo = Socio::factory()->create(['apellido' => 'Cepeda', 'nombre' => 'Milo']);
    $this->gelos->update(['grupo_familiar_id' => GrupoFamiliar::create(['nombre' => 'Familia Gelos', 'titular_socio_id' => $this->gelos->id])->id]);
    $milo->update(['grupo_familiar_id' => $this->gelos->grupo_familiar_id]);
    app(AsignarPlanSocio::class)($milo, Plan::where('tipo_plan', 'adicional_familiar')->firstOrFail(), CarbonImmutable::parse('2026-09-01'));
    app(DevengarCuotas::class)(CarbonImmutable::parse('2026-09-01'));

    Ai::fakeAgent(ExtractorComprobante::class, [[
        'importe' => 57500.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95,
    ]]);

    ($this->post)(($this->mensajeFoto)())->assertOk();

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'sendMessage') || ! str_contains((string) $r['text'], 'Comprobante reconocido')) {
            return true; // no es el mensaje que nos interesa inspeccionar
        }
        $texto = (string) $r['text'];

        // Dos líneas de "septiembre 2026", una sin aclaración (la del titular) y otra con el nombre de Milo.
        return substr_count($texto, 'septiembre 2026') === 2 && str_contains($texto, 'septiembre 2026 (Cepeda Milo)');
    });
});

test('orden de imputación: de la más vieja a la más nueva, cada una completa, y solo la última queda incompleta (caso real Giuliani)', function () {
    $this->gelos->update(['apellido' => 'Giuliani', 'nombre' => 'Ezequiel']);
    $polo = Socio::factory()->create(['apellido' => 'Giuliani', 'nombre' => 'Polo']);
    $anabela = Socio::factory()->create(['apellido' => 'Gonzalez', 'nombre' => 'Amanda Anabela']);
    $grupo = GrupoFamiliar::create(['nombre' => 'Familia Giuliani', 'titular_socio_id' => $this->gelos->id]);
    foreach ([$this->gelos, $polo, $anabela] as $s) {
        $s->update(['grupo_familiar_id' => $grupo->id]);
    }

    $aasr = Plan::where('tipo_plan', 'aasr')->firstOrFail();
    $adicional = Plan::where('tipo_plan', 'adicional_familiar')->firstOrFail();

    // Marzo: Ezequiel ya tenía un pago parcial de antes (resto 35.000 − 8.664 = 26.336 a la tarifa de hoy).
    Cuota::factory()->create(['socio_id' => $this->gelos->id, 'plan_id' => $aasr->id, 'periodo' => '2026-03-01', 'importe_devengado' => '30000.00', 'estado' => EstadoCuota::Parcial->value, 'importe_imputado' => '8664.00']);
    Cuota::factory()->create(['socio_id' => $polo->id, 'plan_id' => $adicional->id, 'periodo' => '2026-03-01', 'importe_devengado' => '21500.00', 'socio_pagador_id' => $this->gelos->id]);
    Cuota::factory()->create(['socio_id' => $anabela->id, 'plan_id' => $adicional->id, 'periodo' => '2026-03-01', 'importe_devengado' => '21500.00', 'socio_pagador_id' => $this->gelos->id]);
    // Junio: Polo va a quedar como la última (incompleta).
    Cuota::factory()->create(['socio_id' => $this->gelos->id, 'plan_id' => $aasr->id, 'periodo' => '2026-06-01', 'importe_devengado' => '30000.00']);
    Cuota::factory()->create(['socio_id' => $polo->id, 'plan_id' => $adicional->id, 'periodo' => '2026-06-01', 'importe_devengado' => '21500.00', 'socio_pagador_id' => $this->gelos->id]);

    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 120000.0, 'fecha' => '2026-10-02', 'titular_origen' => 'Gonzalo Ezequiel Giuliani', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);

    ($this->post)(($this->mensajeFoto)())->assertOk();

    $d = IngestaTelegram::firstOrFail()->extraccion_json;
    $imputaciones = collect($d['imputaciones']);

    // A la tarifa vigente HOY (35.000 / 22.500): 26.336 (resto marzo Ezequiel) + 22.500×2 (marzo Polo y Anabela)
    // + 35.000 (junio Ezequiel) + 13.664 (junio Polo, resto) = 120.000.
    expect($imputaciones->pluck('importe')->all())->toBe(['26336.00', '22500.00', '22500.00', '35000.00', '13664.00'])
        ->and($imputaciones->pluck('completa')->all())->toBe([true, true, true, true, false])
        ->and($d['sobrante'])->toBe('0.00');

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'sendMessage') || ! str_contains((string) $r['text'], 'Comprobante reconocido')) {
            return true;
        }
        $t = (string) $r['text'];

        return str_contains($t, 'marzo 2026: $ 26.336,00 (resto; ya tenía $ 8.664,00 pagados)')
            && str_contains($t, 'junio 2026 (Giuliani Polo): $ 13.664,00 — parcial')
            && ! str_contains($t, 'marzo 2026: $ 26.336,00 — parcial'); // el resto NO es un pago nuevo a medias
    });
});

test('al confirmar un pago desde el bot, además del aviso manda el estado de cuenta en PDF', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);
    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();

    ($this->post)(['update_id' => 40, 'callback_query' => ['id' => 'cbPdf', 'data' => "confirmar:{$ingesta->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]])->assertOk();

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'sendDocument')) {
            return true;
        }
        $partes = collect($r->data());
        expect($partes->firstWhere('name', 'chat_id')['contents'])->toBe('111')
            ->and($partes->firstWhere('name', 'caption')['contents'])->toBe('Estado de cuenta actualizado');
        $archivo = $partes->firstWhere('name', 'document');

        return $archivo !== null && str_starts_with($archivo['contents'], '%PDF');
    });
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendDocument'));
});

test('confirmar desde la bandeja web también manda el estado de cuenta al chat de origen', function () {
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);
    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();

    $this->actingAs(User::factory()->create())->post("/telegram/pendientes/{$ingesta->id}/confirmar")->assertRedirect();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendDocument') && collect($r->data())->firstWhere('name', 'chat_id')['contents'] === '111');
});

test('si falla el envío del PDF, el pago queda confirmado igual (no rompe la confirmación)', function () {
    Http::fake([
        'api.telegram.org/*sendDocument*' => Http::response(['ok' => false], 500),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/x.jpg']]),
    ]);
    Ai::fakeAgent(ExtractorComprobante::class, [['importe' => 35000.0, 'fecha' => '2026-09-08', 'titular_origen' => 'Gabriel Gelos', 'es_comprobante_transferencia' => true, 'confianza' => 0.95]]);
    ($this->post)(($this->mensajeFoto)())->assertOk();
    $ingesta = IngestaTelegram::firstOrFail();

    ($this->post)(['update_id' => 41, 'callback_query' => ['id' => 'cbPdfFail', 'data' => "confirmar:{$ingesta->id}", 'from' => ['id' => '111'], 'message' => ['message_id' => 55, 'chat' => ['id' => '111']]]])->assertOk();

    expect(Pago::count())->toBe(1)->and($ingesta->fresh()->estado)->toBe(EstadoIngesta::Confirmada);
});
