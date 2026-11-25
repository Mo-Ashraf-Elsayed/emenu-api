<?php

use App\Modules\Media\ReconcileMedia;
use App\Shared\Http\ApiErrors;
use App\Shared\Http\ApiProblem;
use App\Shared\Http\ApiResponseHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php')
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(ReconcileMedia::class)->name('reconcile-menu-media')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/auth/login');
        $middleware->append(ApiResponseHeaders::class);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/v1/restaurants/*')]);
        $middleware->trimStrings(except: ['currentPassword', 'passwordConfirmation', 'token', 'hash', 'signature']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontReport(ApiProblem::class);
        $exceptions->dontFlash(['currentPassword', 'passwordConfirmation', 'password', 'token', 'signature']);
        $exceptions->render(ApiErrors::render(...));
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request): Response => ApiErrors::headers($response, $request));
    })->create();
