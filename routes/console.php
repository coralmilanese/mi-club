<?php

use Illuminate\Support\Facades\Schedule;

// Devengamiento automático: el día 1 de cada mes (idempotente, se puede volver a correr).
Schedule::command('cuotas:devengar')->monthlyOn(1, '00:10')->withoutOverlapping()->onOneServer();

Schedule::command('telegram:expirar-pendientes')->daily()->onOneServer();
