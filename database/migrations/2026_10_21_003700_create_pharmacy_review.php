<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.3a (ADR-0005): the unit role Pharmacy, its read of a prescription in the read trail (reason
     * "pharmacy", tied to the prescription) and the pharmacist's review of each item: approved, approved with a
     * note, or refused with a reason. One review per item, insert-only. Clinical (RLS).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping', 'pharmacy') AND unit_id IS NOT NULL)
        )");

        DB::statement('ALTER TABLE clinical_reads ADD COLUMN prescription_id bigint');
        DB::statement('ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_prescription_foreign FOREIGN KEY (tenant_id, prescription_id) REFERENCES prescriptions (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only, as since step 3.6b: the insert-only trail may hold rows that older rules allowed.
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
        ) NOT VALID");

        Schema::create('prescription_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_item_id')->unique();
            $table->string('decision', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->foreign(['tenant_id', 'prescription_item_id'])->references(['tenant_id', 'id'])->on('prescription_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE prescription_reviews ADD CONSTRAINT prescription_reviews_values_check CHECK (
            decision IN ('approved', 'approved_with_note', 'refused') AND (decision = 'approved') = (note IS NULL)
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_reviews_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a pharmacy review never changes' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER prescription_reviews_guard BEFORE UPDATE OR DELETE ON prescription_reviews
                FOR EACH ROW EXECUTE FUNCTION prescription_reviews_guard();
            CREATE TRIGGER prescription_reviews_no_truncate BEFORE TRUNCATE ON prescription_reviews
                FOR EACH STATEMENT EXECUTE FUNCTION prescription_reviews_guard();
        SQL);

        ClinicalSchema::protect('prescription_reviews');
    }

    public function down(): void
    {
        if (DB::table('clinical_reads')->whereNotNull('prescription_id')->exists() || DB::table('role_assignments')->where('role', 'pharmacy')->exists()) {
            throw new RuntimeException('Pharmacy reads or roles exist and are never deleted: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('prescription_reviews');
        DB::unprepared('DROP FUNCTION IF EXISTS prescription_reviews_guard()');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_prescription_foreign');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN prescription_id');
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
        ) NOT VALID");
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
            (role = 'network_admin' AND unit_id IS NULL)
            OR (role = 'auditor')
            OR (role IN ('unit_manager', 'shift_coordinator', 'professional', 'importer', 'reception', 'bed_management', 'housekeeping') AND unit_id IS NOT NULL)
        )");
    }
};
