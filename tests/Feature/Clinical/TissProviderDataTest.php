<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\PriceTable;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 6.5a (TISS 4.03.00): what the guides need about who performed — the unit's CNES, the hospital's code at each
 * insurer and the occupation's CBO. All optional here; the lot (step 6.5b) says what is missing.
 */
class TissProviderDataTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
    }

    public function test_the_unit_keeps_a_cnes_of_seven_digits(): void
    {
        $unit = Unit::factory()->for($this->admin->tenant)->create();
        $this->actingAs($this->admin->user);

        foreach (['123456', '12345678', '12345a7'] as $wrong) {
            $this->put(route('units.update', $unit), ['name' => $unit->name, 'cnes' => $wrong])->assertSessionHasErrors('cnes');
        }
        $this->put(route('units.update', $unit), ['name' => $unit->name, 'cnes' => '1234567'])->assertSessionHasNoErrors();
        $this->assertSame('1234567', Unit::withoutTenantScope()->find($unit->id)?->cnes);
        $this->assertContains('cnes', AuditEvent::where('action', 'unit.updated')->latest('id')->first()?->context['fields']);
        $this->get(route('units.edit', $unit))->assertInertia(fn (Assert $page) => $page->where('unit.cnes', '1234567'));

        $this->put(route('units.update', $unit), ['name' => $unit->name, 'cnes' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Unit::withoutTenantScope()->find($unit->id)?->cnes);
    }

    public function test_the_contract_keeps_the_provider_code_and_audits_it(): void
    {
        $tenant = $this->admin->tenant;
        $insurer = Insurer::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Saúde Mais']);
        $table = PriceTable::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'CBHPM']);
        $this->actingAs($this->admin->user);

        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $table->id, 'provider_code' => str_repeat('9', 15)])
            ->assertSessionHasErrorsIn("contract-{$insurer->id}", ['provider_code']);
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $table->id, 'provider_code' => ' 00012345 '])->assertSessionHasNoErrors();
        $this->assertSame('00012345', InsurerContract::query()->sole()->provider_code);
        $this->assertSame(['insurer_id', 'price_table_id', 'adjustment_bp', 'provider_code'], AuditEvent::where('action', 'insurer_contract.saved')->sole()->context['fields']);
        $this->get(route('billing-prices.index'))->assertInertia(fn (Assert $page) => $page->where('insurers.0.contract.providerCode', '00012345'));

        // Blank clears it, and the change is audited.
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $table->id, 'provider_code' => '  '])->assertSessionHasNoErrors();
        $this->assertNull(InsurerContract::query()->sole()->provider_code);
        $this->assertSame(['provider_code'], AuditEvent::where('action', 'insurer_contract.saved')->latest('id')->first()?->context['fields']);
    }

    public function test_the_job_function_keeps_a_cbo_of_six_digits(): void
    {
        $this->actingAs($this->admin->user);
        $this->post(route('job-functions.store'), ['name' => 'Médico clínico', 'cbo' => '22512'])->assertSessionHasErrorsIn('newFunction', 'cbo');
        $this->post(route('job-functions.store'), ['name' => 'Médico clínico', 'cbo' => '225125'])->assertSessionHasNoErrors();
        $function = JobFunction::query()->sole();
        $this->assertSame('225125', $function->cbo);

        $this->put(route('job-functions.update', $function), ['name' => 'Médico clínico', 'cbo' => '22512x'])->assertSessionHasErrorsIn("function-{$function->id}", 'cbo');
        $this->put(route('job-functions.update', $function), ['name' => 'Médico clínico', 'cbo' => '225142'])->assertSessionHasNoErrors();
        $this->assertSame('225142', $function->fresh()?->cbo);
        $this->assertSame(['cbo'], AuditEvent::where('action', 'job_function.updated')->sole()->context['fields']);
        $this->get(route('job-functions.index'))->assertInertia(fn (Assert $page) => $page->where('functions.0.cbo', '225142'));
    }

    public function test_the_database_refuses_malformed_codes(): void
    {
        $tenant = $this->admin->tenant;
        $unit = Unit::factory()->for($tenant)->create();
        $function = JobFunction::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Enfermeiro']);
        $insurer = Insurer::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Saúde Mais']);
        $table = PriceTable::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'CBHPM']);
        $contract = InsurerContract::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'insurer_id' => $insurer->id, 'price_table_id' => $table->id, 'adjustment_bp' => 0]);

        foreach ([['units', $unit->id, 'cnes', 'abc1234'], ['job_functions', $function->id, 'cbo', '12345x'], ['insurer_contracts', $contract->id, 'provider_code', '   ']] as [$table, $id, $column, $value]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->update([$column => $value]));
                $this->fail("{$table}.{$column} accepted '{$value}'.");
            } catch (QueryException) {
            }
        }
        $this->addToAssertionCount(3);
    }
}
