<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.1: beds of a unit, grouped by sector (a sector with beds is an inpatient sector), their state
     * and an insert-only history of every state change. New unit roles: bed management and housekeeping.
     * No patient data here (occupation comes in step 4.4): not clinical, no RLS.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping') AND unit_id IS NOT NULL)
        )");

        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('sector_id');
            $table->string('room', 50);
            $table->string('code', 50);
            $table->string('type', 20);
            $table->string('sex_restriction', 10)->nullable();
            $table->boolean('isolation')->default(false);
            $table->boolean('extra')->default(false);
            $table->string('status', 20)->default('free');
            $table->string('block_reason', 255)->nullable();
            $table->timestampTz('status_changed_at');
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'sector_id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['unit_id', 'sector_id'])->references(['unit_id', 'id'])->on('sectors')->restrictOnDelete();
        });
        // One code per sector among active beds, case-insensitive.
        DB::statement('CREATE UNIQUE INDEX beds_active_code_unique ON beds (sector_id, lower(code)) WHERE deactivated_at IS NULL');
        DB::statement("ALTER TABLE beds ADD CONSTRAINT beds_values_check CHECK (
            type IN ('ward', 'private', 'icu')
            AND (sex_restriction IS NULL OR sex_restriction IN ('female', 'male'))
            AND status IN ('free', 'reserved', 'occupied', 'cleaning', 'blocked')
            AND (status = 'blocked') = (block_reason IS NOT NULL)
            AND (deactivated_at IS NULL OR status IN ('free', 'blocked'))
        )");

        Schema::create('bed_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bed_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('occurred_at');

            $table->index(['bed_id', 'occurred_at']);
            $table->foreign(['tenant_id', 'bed_id'])->references(['tenant_id', 'id'])->on('beds')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bed_events_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'bed history is insert-only' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER bed_events_insert_only BEFORE UPDATE OR DELETE ON bed_events
                FOR EACH ROW EXECUTE FUNCTION bed_events_insert_only();
            CREATE TRIGGER bed_events_no_truncate BEFORE TRUNCATE ON bed_events
                FOR EACH STATEMENT EXECUTE FUNCTION bed_events_insert_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_events');
        DB::unprepared('DROP FUNCTION IF EXISTS bed_events_insert_only()');
        Schema::dropIfExists('beds');
        // The roles disappear with the beds: their assignments go first, or the old rule would refuse the rows.
        DB::table('role_assignments')->whereIn('role', ['bed_management', 'housekeeping'])->delete();
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception') AND unit_id IS NOT NULL)
        )");
    }
};
