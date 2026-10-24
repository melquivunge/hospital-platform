<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5.1a (delivery 5, ADR-0008): the network's exam catalog (laboratory and imaging) and the Laboratory and
     * Imaging roles, one unit each. A laboratory exam lists its analytes with unit and reference range. Kept by the
     * network admin; no patient data, so no RLS. Deactivated, never deleted: orders will refer to them.
     */
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('name', 255);
            $table->string('name_key', 255);
            // Material (laboratory) or body region (imaging).
            $table->string('specimen', 100);
            $table->string('preparation', 1000)->nullable();
            // TUSS or SIGTAP, for billing later (delivery 6).
            $table->string('code', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'type', 'name_key']);
        });
        DB::statement("ALTER TABLE exams ADD CONSTRAINT exams_values_check CHECK (
            type IN ('laboratory', 'imaging') AND length(btrim(name)) > 0 AND length(btrim(specimen)) > 0
            AND (code IS NULL OR code ~ '^[0-9A-Za-z.-]+$')
        )");

        Schema::create('exam_analytes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('exam_id');
            $table->string('name', 100);
            $table->string('unit', 30)->nullable();
            $table->decimal('reference_min', 14, 4)->nullable();
            $table->decimal('reference_max', 14, 4)->nullable();
            $table->string('reference_text', 255)->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'exam_id'])->references(['tenant_id', 'id'])->on('exams')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX exam_analytes_name_unique ON exam_analytes (exam_id, lower(name))');
        DB::statement('ALTER TABLE exam_analytes ADD CONSTRAINT exam_analytes_values_check CHECK (
            length(btrim(name)) > 0
            AND (reference_min IS NULL OR reference_max IS NULL OR reference_min <= reference_max)
        )');

        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping', 'pharmacy', 'laboratory', 'imaging') AND unit_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        if (DB::table('role_assignments')->whereIn('role', ['laboratory', 'imaging'])->exists()) {
            throw new RuntimeException('Laboratory or Imaging roles exist: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping', 'pharmacy') AND unit_id IS NOT NULL)
        )");
        Schema::dropIfExists('exam_analytes');
        Schema::dropIfExists('exams');
    }
};
