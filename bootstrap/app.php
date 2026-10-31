<?php

use App\Console\Commands\CheckPatientStatuses;
use App\Console\Commands\RunPatientAudit;
use App\Console\Commands\SendCarMaintenanceNotifications;
use App\Console\Commands\SendSubscriptionNotifications;
use App\Console\Commands\SendTrialNotifications;
use App\Console\Commands\RunMonthlyVertexRetraining;
use App\Console\Commands\RunDocumentationAudit;
use App\Http\Responses\ApiResponseClass;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias(
            [
                'role' => \App\Http\Middleware\EnsureUserHasRole::class,
                // API auth that returns JSON 401 instead of redirecting
                'api.auth' => \App\Http\Middleware\EnsureApiAuthenticated::class,
                'subscription.active' => \App\Http\Middleware\EnsureCompanySubscriptionActive::class,
                'expires' => \App\Http\Middleware\ValidateExpiration::class,
                'signed.url' => \App\Http\Middleware\ValidateSignedUrl::class,
            ]
        );

        $middleware->api([
            App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })->withSchedule(function (Schedule $schedule): void {
        $schedule->command(CheckPatientStatuses::class)
            ->dailyAt('02:00')
            ->withoutOverlapping();
        $schedule->command(RunPatientAudit::class)
            ->dailyAt('02:45')
            ->withoutOverlapping();
        $schedule->command(SendCarMaintenanceNotifications::class)->dailyAt('6:00');
        $schedule->command(SendSubscriptionNotifications::class)->dailyAt('6:15');
        $schedule->command(SendTrialNotifications::class)->dailyAt('6:20');

        if (config('documentation_audit.enabled') && config('documentation_audit.schedule.enabled')) {
            $schedule->command(RunDocumentationAudit::class)
                ->dailyAt((string) config('documentation_audit.schedule.time', '03:00'))
                ->withoutOverlapping()
                ->onOneServer();
        }

        $schedule->command('backup:run --only-db')->dailyAt('01:00')->withoutOverlapping();
        $schedule->command('backup:clean')->dailyAt('01:30')->withoutOverlapping();

        $schedule
            ->command('vertex:monthly-retrain')
            ->monthlyOn(1, '02:30')
            ->withoutOverlapping(1440)
            ->onOneServer();

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, Throwable $e) {
            if ($request->is('api/*'))
                return true;

            return $request->expectsJson();
        });


        $exceptions->render(function (Throwable $e) {
            if (request()->is('api/*')) {
                $response = new ApiResponseClass();

                $status = 500;
                if (method_exists($e, 'getStatusCode')) {
                    $status = $e->getStatusCode();
                }

                $message = $e->getMessage() ?: 'Server Error';

                $errors = [];

                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $status = 422;
                    $errors = $e->errors();
                }

                return $response->error($message, $status, $errors);
            }

            // Render HTML errors through the universal Slovak error view
            $status = 500;
            if (method_exists($e, 'getStatusCode')) {
                $status = $e->getStatusCode();
            }

            $title = match ($status) {
                404 => 'Stránka sa nenašla',
                403 => 'Prístup zakázaný',
                401 => 'Bez autentifikácie',
                default => 'Serverová chyba',
            };

            $message = match ($status) {
                404 => 'Požadovanú stránku sa nám nepodarilo nájsť.',
                403 => 'Nemáte povolenie na prístup k tejto stránke.',
                401 => 'Musíte sa prihlásiť, aby ste mohli pokračovať.',
                default => 'Nastala neočakávaná chyba na strane servera.',
            };

            if (method_exists($e, 'getMessage') && $e->getMessage()) {
                $title .= ' (' . $e->getMessage() . ')';
            }

            return response()->view('errors.error', [
                'code' => $status,
                'title' => $title,
                'message' => $message,
                'exception' => $e,
            ], $status);
        });



    })->create();
