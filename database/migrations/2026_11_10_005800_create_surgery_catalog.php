<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.1a (delivery 5b, ADR-0009): the network's surgical procedures, the operating rooms of each unit and
     * the Surgical center role, one unit each. Kept by the network admin; no patient data, so no RLS. Deactivated,
     * never deleted: surgeries will refer to them.
     */
    public function up(): void
    {
        Schema::create('surgical_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('name_key', 255);
            // TUSS or SIGTAP, for billing later (delivery 6).
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('size', 10);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name_key']);
        });
        DB::statement("ALTER TABLE surgical_procedures ADD CONSTRAINT surgical_procedures_values_check CHECK (
            length(btrim(name)) > 0 AND duration_minutes BETWEEN 5 AND 1440 AND size IN ('small', 'medium', 'large')
            AND (code IS NULL OR code ~ '^[0-9A-Za-z.-]+$')
        )");

        Schema::create('operating_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'unit_id', 'name_key']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE operating_rooms ADD CONSTRAINT operating_rooms_values_check CHECK (length(btrim(name)) > 0)');

        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping', 'pharmacy', 'laboratory', 'imaging', 'surgical_center') AND unit_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        if (DB::table('role_assignments')->where('role', 'surgical_center')->exists()) {
            throw new RuntimeException('Surgical center roles exist: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping', 'pharmacy', 'laboratory', 'imaging') AND unit_id IS NOT NULL)
        )");
        Schema::dropIfExists('operating_rooms');
        Schema::dropIfExists('surgical_procedures');
    }
};
