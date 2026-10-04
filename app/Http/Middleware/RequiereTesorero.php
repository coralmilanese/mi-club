<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequiereTesorero
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->esTesorero(), 403, 'Tu usuario es de solo lectura.');

        return $next($request);
    }
}
