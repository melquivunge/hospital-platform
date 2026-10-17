<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite (tenant_id, id) keys make cross-tenant references impossible at the database level.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestampsTz();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('uf', 2)->nullable();
            $table->string('timezone')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE units ADD CONSTRAINT units_uf_check CHECK (uf IS NULL OR uf IN ('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'))");

        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->string('name');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['unit_id', 'name']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            // One row per tenant and user: rejoining after revocation reactivates this row (step 2b).
            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->string('role');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->timestampsTz();

            $table->unique(['membership_id', 'role', 'unit_id'])->nullsNotDistinct();
            $table->index(['tenant_id', 'unit_id']);
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer') AND unit_id IS NOT NULL)
        )");

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at', 6);

            $table->index(['tenant_id', 'occurred_at', 'id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
        });

        // Append-only at the database level too: bulk queries and psql bypass Eloquent hooks.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$;
            CREATE TRIGGER audit_events_no_update_delete BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();
            CREATE TRIGGER audit_events_no_truncate BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_append_only()');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('units');
        Schema::dropIfExists('tenants');
    }
};
