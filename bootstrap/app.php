<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Fortify não oferece limiter para o pedido de link de senha; anexado por IP aqui.
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->getByName('password.email')?->middleware('throttle:password-reset-link');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias(['tenant' => ResolveTenant::class]);
        // Tenant context must exist before route model binding resolves tenant-scoped models.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Step 4b.4: the second person's password never goes back to the session as old input.
        $exceptions->dontFlash(['witness_password']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Branded pt-BR error page; never shows exception messages (they can name models or internals).
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $branded = in_array($status, [403, 404, 419, 503], true) || ($status >= 500 && ! config('app.debug'));

            if (! $branded || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $page = Inertia::render('error', ['status' => $status])->toResponse($request)->setStatusCode($status);

            // Keep client-relevant headers only; copying all would clobber Inertia's own Content-Type.
            if ($response->headers->has('Retry-After')) {
                $page->headers->set('Retry-After', $response->headers->get('Retry-After'));
            }

            return $page;
        });
    })->create();
