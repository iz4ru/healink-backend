<?php

use App\Http\Middleware\CheckIfActive;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckSingleSession;
use App\Http\Middleware\UpdateLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'check.role' => CheckRole::class,
            'check.active' => CheckIfActive::class,
            'check.single.session' => CheckSingleSession::class,
            'update.lastseen' => UpdateLastSeen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
