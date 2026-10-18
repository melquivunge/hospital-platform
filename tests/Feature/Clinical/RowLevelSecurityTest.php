<?php

namespace Tests\Feature\Clinical;

use App\Clinical\ClinicalSchema;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-0003: the database itself keeps clinical rows inside their client, even if the application errs.
 */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_works_without_superuser_nor_bypassrls(): void
    {
        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertSame('app_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_every_clinical_table_forces_the_tenant_policy(): void
    {
        $withPolicy = DB::table('pg_policies')->where('schemaname', 'public')->where('policyname', 'tenant_isolation')->pluck('tablename')->all();
        $this->assertEqualsCanonicalizing(ClinicalSchema::TABLES, $withPolicy, 'Tabela com política fora da lista, ou listada sem política.');

        foreach (ClinicalSchema::TABLES as $table) {
            $state = DB::selectOne("select c.relrowsecurity, c.relforcerowsecurity, exists (select 1 from pg_policies p where p.tablename = c.relname and p.policyname = 'tenant_isolation') as policy
                from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relname = ?", [$table]);

            $this->assertNotNull($state, $table);
            $this->assertTrue($state->relrowsecurity && $state->relforcerowsecurity && $state->policy, "{$table} sem RLS forçado");
        }
    }

    public function test_the_database_hides_and_refuses_rows_of_another_client(): void
    {
        $owner = DB::connection('pgsql_owner');
        $owner->statement('create table clinical_rls_probe (id bigserial primary key, tenant_id bigint not null, note text not null)');
        ClinicalSchema::protect('clinical_rls_probe', 'pgsql_owner');
        // Dropped after RefreshDatabase rolls the test transaction back (callbacks run in order).
        $this->beforeApplicationDestroyed(fn () => DB::connection('pgsql_owner')->statement('drop table if exists clinical_rls_probe'));

        [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
        $context = app(CurrentTenant::class);

        $context->set($a);
        DB::table('clinical_rls_probe')->insert(['tenant_id' => $a->id, 'note' => 'de A']);
        $this->assertSame(1, DB::table('clinical_rls_probe')->count());

        // A raw query with no application scope still sees nothing of A from B, nor without a client.
        $context->set($b);
        $this->assertSame(0, DB::table('clinical_rls_probe')->count());
        $context->set(null);
        $this->assertSame(0, DB::table('clinical_rls_probe')->count());

        $context->set($b);
        $this->expectException(QueryException::class);
        DB::table('clinical_rls_probe')->insert(['tenant_id' => $a->id, 'note' => 'gravado em A a partir de B']);
    }
}
