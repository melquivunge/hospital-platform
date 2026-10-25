<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.1a (ADR-0010): the Billing role, one unit each; the network's billable items (each tied to what it
     * charges for: a consultation, a day in a bed type, an exam, a procedure, a material or a medication) and its price
     * tables, with prices in cents and periods of validity that never overlap. A price is never changed nor deleted;
     * only its end date is set, once. No patient data, so no RLS.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement(self::scopeCheck(true));

        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->unsignedBigInteger('exam_id')->nullable();
            $table->unsignedBigInteger('surgical_procedure_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedBigInteger('medication_id')->nullable();
            $table->string('bed_type', 20)->nullable();
            $table->string('name', 255);
            // TUSS for an insurer, or the network's own code.
            $table->string('code', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'exam_id'])->references(['tenant_id', 'id'])->on('exams')->restrictOnDelete();
            $table->foreign(['tenant_id', 'surgical_procedure_id'])->references(['tenant_id', 'id'])->on('surgical_procedures')->restrictOnDelete();
            $table->foreign(['tenant_id', 'material_id'])->references(['tenant_id', 'id'])->on('materials')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });
        // Each kind carries exactly its own reference; one item per thing charged for.
        DB::statement("ALTER TABLE billing_items ADD CONSTRAINT billing_items_values_check CHECK (
            length(btrim(name)) > 0 AND (code IS NULL OR code ~ '^[0-9A-Za-z.-]+$')
            AND ((kind IN ('consultation', 'emergency_consultation') AND num_nonnulls(exam_id, surgical_procedure_id, material_id, medication_id, bed_type) = 0)
                OR (kind = 'daily' AND bed_type IN ('ward', 'private', 'icu') AND num_nonnulls(exam_id, surgical_procedure_id, material_id, medication_id) = 0)
                OR (kind = 'exam' AND exam_id IS NOT NULL AND num_nonnulls(surgical_procedure_id, material_id, medication_id, bed_type) = 0)
                OR (kind = 'procedure' AND surgical_procedure_id IS NOT NULL AND num_nonnulls(exam_id, material_id, medication_id, bed_type) = 0)
                OR (kind = 'material' AND material_id IS NOT NULL AND num_nonnulls(exam_id, surgical_procedure_id, medication_id, bed_type) = 0)
                OR (kind = 'medication' AND medication_id IS NOT NULL AND num_nonnulls(exam_id, surgical_procedure_id, material_id, bed_type) = 0))
        )");
        DB::statement("CREATE UNIQUE INDEX billing_items_one_reference ON billing_items (tenant_id, kind, coalesce(exam_id, surgical_procedure_id, material_id, medication_id, 0), coalesce(bed_type, ''))");

        Schema::create('price_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX price_tables_name_unique ON price_tables (tenant_id, lower(name))');
        DB::statement('ALTER TABLE price_tables ADD CONSTRAINT price_tables_values_check CHECK (length(btrim(name)) > 0)');

        Schema::create('price_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price_table_id');
            $table->unsignedBigInteger('billing_item_id');
            $table->bigInteger('price_cents');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['price_table_id', 'billing_item_id', 'valid_from']);
            $table->foreign(['tenant_id', 'price_table_id'])->references(['tenant_id', 'id'])->on('price_tables')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_item_id'])->references(['tenant_id', 'id'])->on('billing_items')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE price_entries ADD CONSTRAINT price_entries_values_check CHECK (
            price_cents BETWEEN 0 AND 100000000000 AND (valid_until IS NULL OR valid_until >= valid_from)
        )');
        // Two prices of the same item in the same table never hold on the same day.
        DB::statement("ALTER TABLE price_entries ADD CONSTRAINT price_entries_no_overlap EXCLUDE USING gist (
            price_table_id WITH =, billing_item_id WITH =, daterange(valid_from, valid_until, '[]') WITH &&
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION price_entries_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.valid_until IS NULL AND NEW.valid_until IS NOT NULL
                    AND (to_jsonb(NEW) - 'valid_until') = (to_jsonb(OLD) - 'valid_until') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'a price is never changed: only its end date is set, once' USING ERRCODE = '23514';
            END $$;
            CREATE TRIGGER price_entries_guard BEFORE UPDATE OR DELETE ON price_entries
                FOR EACH ROW EXECUTE FUNCTION price_entries_guard();
            CREATE TRIGGER price_entries_no_truncate BEFORE TRUNCATE ON price_entries
                FOR EACH STATEMENT EXECUTE FUNCTION price_entries_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('role_assignments')->where('role', 'billing')->exists() || DB::table('price_entries')->exists()) {
            throw new RuntimeException('Billing roles or prices exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('price_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS price_entries_guard()');
        Schema::dropIfExists('price_tables');
        Schema::dropIfExists('billing_items');
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement(self::scopeCheck(false));
    }

    private static function scopeCheck(bool $billing): string
    {
        $roles = "'unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping', 'pharmacy', 'laboratory', 'imaging', 'surgical_center'".($billing ? ", 'billing'" : '');

        return "ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ({$roles}) AND unit_id IS NOT NULL)
        )";
    }
};
