<?php

return [

    /*
    | Usuario inicial que crea el DatabaseSeeder.
    */
    'tesorero' => [
        'email' => env('TESORERO_EMAIL', 'tesorero@aasr.test'),
        'nombre' => env('TESORERO_NOMBRE', 'Tesorería AASR'),
        'password' => env('TESORERO_PASSWORD', 'cambiar-esta-clave'),
    ],

    'importacion' => [
        'cuenta_efectivo' => env('AASR_IMPORT_CUENTA_EFECTIVO', 'Efectivo'),
        'fecha_apertura' => env('AASR_IMPORT_FECHA_APERTURA', '2026-01-01'),
    ],

    'ia' => [
        'modelo_vision' => env('AI_MODELO_VISION', 'anthropic/claude-sonnet-5'),
        'modelo_texto' => env('AI_MODELO_TEXTO', 'anthropic/claude-haiku-4.5'),
        'max_costo_usd_mes' => (float) env('AI_MAX_COSTO_USD_MES', 20),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Usuarios de Telegram (sin @) autorizados a usar el bot la primera vez que escriben.
        'usuarios_autorizados' => array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_USUARIOS_AUTORIZADOS', '')))),
        'dias_expiracion_pendientes' => (int) env('TELEGRAM_DIAS_EXPIRACION', 7),
    ],

    'tributos' => [
        /*
        | Fecha a partir de la cual el recálculo automático de impuestos toma el control (decisión D2).
        | Los días anteriores vienen del Excel, ya conciliados con el banco, y no se reescriben.
        | null = sin corte (todos los días se recalculan).
        */
        'corte_recalculo_automatico' => env('AASR_CORTE_TRIBUTOS', '2026-10-01'),
    ],

];
