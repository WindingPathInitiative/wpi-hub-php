<?php

use App\Exceptions\UserException;
use App\Http\Middleware\AuthenticateCognito;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\InternalOnly;
use App\Http\Middleware\PresetOffice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Node mounted the API at /v{major} (package.json major => v1).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // presetOffice ran as global middleware in Node (app.use). Apply it to
        // every API request so ?useOffice / body.useOffice is always available.
        $middleware->appendToGroup('api', PresetOffice::class);

        $middleware->alias([
            'cognito' => AuthenticateCognito::class, // token.parse/validate
            'member' => EnsureMember::class,          // token.expired
            'internal' => InternalOnly::class,        // network.internal (shared secret)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Headless API: always answer with JSON (the legacy { message, status } shape).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        // Non-UserException errors still render in the legacy { message, status }
        // shape. (UserException renders itself; ValidationException is shaped by
        // the Form Requests in Phase 5.)
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }
            if ($e instanceof UserException
                || $e instanceof ValidationException) {
                return null; // handled elsewhere
            }

            $status = match (true) {
                $e instanceof NotFoundHttpException => 404,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            // Node's catch-all 404 reported "Not Found"; otherwise it echoed the
            // error message (it leaked message but never the stack trace).
            $message = $e instanceof NotFoundHttpException
                ? 'Not Found'
                : ($e->getMessage() ?: 'Internal error');

            return response()->json(['message' => $message, 'status' => $status], $status);
        });
    })->create();
