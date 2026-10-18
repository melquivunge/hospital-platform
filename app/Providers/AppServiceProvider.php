<?php

namespace App\Providers;

use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests and queue jobs, so a tenant context never leaks to the next one.
        $this->app->scoped(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureSafeProductionConfig();
        $this->configureDefaults();
        $this->configureDatabaseRole();
    }

    /**
     * ADR-0003: the application connection assumes `app_runtime` (config/database.php); migrations run with
     * `--database=pgsql_owner`. A worker keeps its connection between jobs, so each job starts with no client.
     */
    protected function configureDatabaseRole(): void
    {
        // A sync job runs inside the request that dispatched it and keeps that request's client.
        Queue::before(fn (JobProcessing $event) => $event->connectionName === 'sync' || app(CurrentTenant::class)->set(null));
    }

    /**
     * Refuse to boot production with debug output or session cookies sent over plain HTTP.
     */
    protected function ensureSafeProductionConfig(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        if (config('app.debug') || ! config('session.secure')) {
            throw new RuntimeException('Produção exige APP_DEBUG=false e SESSION_SECURE_COOKIE=true.');
        }

        URL::forceScheme('https');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
