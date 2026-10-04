<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequiereTesorero;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['tesorero' => RequiereTesorero::class]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // El webhook lo llama Telegram, no un navegador con sesión: no tiene token CSRF.
        $middleware->validateCsrfTokens(except: ['telegram/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
