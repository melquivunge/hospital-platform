<?php

namespace Tests\Feature\Tenancy;

use App\Models\Sector;
use App\Models\Tenant;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantContextViolation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_only_see_records_of_the_current_tenant(): void
    {
        $aurora = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $auroraUnit = Unit::factory()->for($aurora)->create();
        $otherUnit = Unit::factory()->for($other)->create();

        app(CurrentTenant::class)->set($aurora);

        $this->assertSame([$auroraUnit->id], Unit::pluck('id')->all());
        $this->assertNull(Unit::find($otherUnit->id));
    }

    public function test_queries_without_tenant_context_fail_closed(): void
    {
        Unit::factory()->create();

        $this->expectException(TenantContextViolation::class);
        Unit::count();
    }

    public function test_new_records_take_the_current_tenant(): void
    {
        $aurora = Tenant::factory()->create();
        app(CurrentTenant::class)->set($aurora);

        $unit = Unit::create(['name' => 'Hospital Horizonte', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);

        $this->assertSame($aurora->id, $unit->tenant_id);
    }

    public function test_records_cannot_be_created_for_another_tenant(): void
    {
        $aurora = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        app(CurrentTenant::class)->set($aurora);

        $this->expectException(TenantContextViolation::class);
        Unit::create(['tenant_id' => $other->id, 'name' => 'Intruso']);
    }

    public function test_database_rejects_sector_in_unit_of_another_tenant(): void
    {
        $aurora = Tenant::factory()->create();
        $otherUnit = Unit::factory()->create();

        $this->expectException(QueryException::class);
        Sector::withoutTenantScope()->insert([
            'tenant_id' => $aurora->id,
            'unit_id' => $otherUnit->id,
            'name' => 'UTI',
        ]);
    }

    public function test_database_rejects_invalid_uf(): void
    {
        $this->expectException(QueryException::class);
        Unit::factory()->create(['uf' => 'XX']);
    }
}
