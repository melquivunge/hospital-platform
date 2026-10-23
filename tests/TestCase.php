<?php

namespace Tests;

use App\Http\Controllers\StockController;
use App\Models\Medication;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Step 4c.2: a dispensing takes from the stock; a lot of the medication in the unit with this balance.
     */
    protected function stockLot(int $unitId, Medication $medication, int $quantity = 1000, string $code = 'TESTE1', string $expiresOn = '2099-12-31'): StockLot
    {
        $tenant = app(CurrentTenant::class);
        $previous = $tenant->get();
        $tenant->set(Tenant::query()->findOrFail($medication->tenant_id));
        try {
            $movement = DB::transaction(fn () => StockController::enter($unitId, $medication, $code, $expiresOn, null, $quantity, User::factory()->create()));

            return StockLot::query()->findOrFail($movement->stock_lot_id);
        } finally {
            $tenant->set($previous);
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
