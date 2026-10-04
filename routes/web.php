<?php

use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\ConciliacionController;
use App\Http\Controllers\Configuracion\CategoriaGastoController;
use App\Http\Controllers\Configuracion\CuentaController;
use App\Http\Controllers\Configuracion\TelegramChatController;
use App\Http\Controllers\Configuracion\TipoDocumentoController;
use App\Http\Controllers\Configuracion\TributoController;
use App\Http\Controllers\CuotaController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\GrupoFamiliarController;
use App\Http\Controllers\LibroCajaController;
use App\Http\Controllers\LiquidacionDiariaController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SocioBajaController;
use App\Http\Controllers\SocioController;
use App\Http\Controllers\SocioDocumentoController;
use App\Http\Controllers\SocioPlanController;
use App\Http\Controllers\Telegram\IngestaTelegramController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

// Telegram llama esto directo, sin sesión ni CSRF.
Route::post('telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Lectura: tesorero y solo-lectura
    Route::get('socios', [SocioController::class, 'index'])->name('socios.index');
    Route::get('grupos-familiares', [GrupoFamiliarController::class, 'index'])->name('grupos-familiares.index');
    Route::get('configuracion/tipos-documento', [TipoDocumentoController::class, 'index'])->name('tipos-documento.index');
    Route::get('planes', [PlanController::class, 'index'])->name('planes.index');
    Route::get('cuotas', [CuotaController::class, 'grilla'])->name('cuotas.grilla');
    Route::get('pagos', [PagoController::class, 'index'])->name('pagos.index');
    Route::get('gastos', [GastoController::class, 'index'])->name('gastos.index');
    Route::get('comprobantes/{comprobante}', [ComprobanteController::class, 'show'])->name('comprobantes.show');
    Route::get('telegram/pendientes', [IngestaTelegramController::class, 'index'])->name('telegram.pendientes.index');
    Route::get('configuracion/telegram', [TelegramChatController::class, 'index'])->name('telegram-chats.index');
    Route::get('configuracion/categorias-gasto', [CategoriaGastoController::class, 'index'])->name('categorias-gasto.index');
    Route::get('libro-caja', [LibroCajaController::class, 'index'])->name('libro-caja.index');
    Route::get('libro-caja/exportar', [LibroCajaController::class, 'exportar'])->name('libro-caja.exportar');
    Route::get('libro-caja/liquidaciones', [LiquidacionDiariaController::class, 'index'])->name('liquidaciones.index');
    Route::get('conciliacion', [ConciliacionController::class, 'index'])->name('conciliacion.index');
    Route::get('configuracion/cuentas', [CuentaController::class, 'index'])->name('cuentas.index');
    Route::get('configuracion/tributos', [TributoController::class, 'index'])->name('tributos.index');

    // Escritura: solo tesorero. `socios/create` va antes que `socios/{socio}`.
    Route::middleware('tesorero')->group(function () {
        Route::get('socios/create', [SocioController::class, 'create'])->name('socios.create');
        Route::post('socios', [SocioController::class, 'store'])->name('socios.store');
        Route::get('socios/{socio}/edit', [SocioController::class, 'edit'])->name('socios.edit');
        Route::put('socios/{socio}', [SocioController::class, 'update'])->name('socios.update');
        Route::post('socios/{socio}/baja', [SocioBajaController::class, 'baja'])->name('socios.baja');
        Route::post('socios/{socio}/reingreso', [SocioBajaController::class, 'reingreso'])->name('socios.reingreso');

        Route::post('socios/{socio}/documentos', [SocioDocumentoController::class, 'store'])->name('socios.documentos.store');
        Route::delete('socios/{socio}/documentos/{documento}', [SocioDocumentoController::class, 'destroy'])->name('socios.documentos.destroy');

        Route::post('grupos-familiares', [GrupoFamiliarController::class, 'store'])->name('grupos-familiares.store');
        Route::post('grupos-familiares/{grupo}/miembros', [GrupoFamiliarController::class, 'agregarMiembro'])->name('grupos-familiares.miembros.store');
        Route::delete('grupos-familiares/{grupo}/miembros/{socio}', [GrupoFamiliarController::class, 'quitarMiembro'])->name('grupos-familiares.miembros.destroy');
        Route::delete('grupos-familiares/{grupo}', [GrupoFamiliarController::class, 'destroy'])->name('grupos-familiares.destroy');

        Route::post('planes', [PlanController::class, 'store'])->name('planes.store');
        Route::get('planes/asignacion-masiva', [SocioPlanController::class, 'masiva'])->name('planes.masiva');
        Route::post('planes/asignacion-masiva', [SocioPlanController::class, 'masivaStore'])->name('planes.masiva.store');
        Route::put('planes/{plan}', [PlanController::class, 'update'])->name('planes.update');
        Route::post('planes/{plan}/tarifas', [PlanController::class, 'storeTarifa'])->name('planes.tarifas.store');
        Route::post('socios/{socio}/plan', [SocioPlanController::class, 'store'])->name('socios.plan.store');
        Route::post('cuotas/devengar', [CuotaController::class, 'devengar'])->name('cuotas.devengar');

        Route::get('pagos/create', [PagoController::class, 'create'])->name('pagos.create');
        Route::post('pagos', [PagoController::class, 'store'])->name('pagos.store');
        Route::post('pagos/{pago}/anular', [PagoController::class, 'anular'])->name('pagos.anular');
        Route::post('gastos', [GastoController::class, 'store'])->name('gastos.store');
        Route::post('gastos/{gasto}/anular', [GastoController::class, 'anular'])->name('gastos.anular');
        Route::post('telegram/pendientes/{ingesta}/confirmar', [IngestaTelegramController::class, 'confirmar'])->name('telegram.pendientes.confirmar');
        Route::post('telegram/pendientes/{ingesta}/descartar', [IngestaTelegramController::class, 'descartar'])->name('telegram.pendientes.descartar');
        Route::put('configuracion/telegram/{chat}', [TelegramChatController::class, 'update'])->name('telegram-chats.update');

        Route::post('configuracion/categorias-gasto', [CategoriaGastoController::class, 'store'])->name('categorias-gasto.store');
        Route::put('configuracion/categorias-gasto/{categoria}', [CategoriaGastoController::class, 'update'])->name('categorias-gasto.update');

        Route::post('libro-caja/movimientos', [LibroCajaController::class, 'store'])->name('libro-caja.movimientos.store');
        Route::post('libro-caja/movimientos/{movimiento}/anular', [LibroCajaController::class, 'anular'])->name('libro-caja.movimientos.anular');
        Route::post('libro-caja/liquidaciones/ajustar', [LiquidacionDiariaController::class, 'ajustar'])->name('liquidaciones.ajustar');
        Route::post('libro-caja/liquidaciones/restablecer', [LiquidacionDiariaController::class, 'restablecer'])->name('liquidaciones.restablecer');
        Route::post('conciliacion', [ConciliacionController::class, 'store'])->name('conciliacion.store');

        Route::post('configuracion/cuentas', [CuentaController::class, 'store'])->name('cuentas.store');
        Route::put('configuracion/cuentas/{cuenta}', [CuentaController::class, 'update'])->name('cuentas.update');
        Route::post('configuracion/cuentas/{cuenta}/apertura', [CuentaController::class, 'apertura'])->name('cuentas.apertura');
        Route::put('configuracion/medios-pago/{medio}', [CuentaController::class, 'medio'])->name('medios-pago.update');
        Route::put('configuracion/tributos/{tributo}', [TributoController::class, 'update'])->name('tributos.update');
        Route::post('configuracion/tributos/{tributo}/alicuotas', [TributoController::class, 'storeAlicuota'])->name('tributos.alicuotas.store');

        Route::post('configuracion/tipos-documento', [TipoDocumentoController::class, 'store'])->name('tipos-documento.store');
        Route::put('configuracion/tipos-documento/{tipo}', [TipoDocumentoController::class, 'update'])->name('tipos-documento.update');
        Route::delete('configuracion/tipos-documento/{tipo}', [TipoDocumentoController::class, 'destroy'])->name('tipos-documento.destroy');
    });

    Route::get('socios/{socio}', [SocioController::class, 'show'])->name('socios.show');
    Route::get('socios/{socio}/estado-cuenta', [SocioController::class, 'estadoCuenta'])->name('socios.estado-cuenta');
    Route::get('socios/{socio}/documentos/{documento}', [SocioDocumentoController::class, 'show'])->name('socios.documentos.show');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
