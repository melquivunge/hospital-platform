<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase apaga o banco: abortar se config:cache desviou os testes para outro banco.
        if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'hospital_test') {
            throw new \RuntimeException('Testes só podem rodar no banco hospital_test com APP_ENV=testing.');
        }
    }

    /**
     * Schema commands run as the table owner (ADR-0003); RefreshDatabase reaches them through here.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function artisan($command, $parameters = [])
    {
        if (is_string($command) && str_starts_with($command, 'migrate')) {
            $parameters += ['--database' => 'pgsql_owner'];
        }

        return parent::artisan($command, $parameters);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
