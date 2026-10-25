<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5b.3b (ADR-0009): the Surgical center of the unit and the scheduled team read the record while the
     * surgery is requested or scheduled; each opening is a row of the trail with the reason "surgery".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_reads ADD COLUMN surgery_id bigint');
        DB::statement('ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_surgery_foreign FOREIGN KEY (tenant_id, surgery_id) REFERENCES surgeries (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only, as since step 3.6b: the insert-only trail may hold rows that older rules allowed.
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy', 'exam', 'surgery') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
            AND (reason = 'exam') = (exam_order_id IS NOT NULL)
            AND (reason = 'surgery') = (surgery_id IS NOT NULL)
        ) NOT VALID");
        DB::statement('CREATE INDEX clinical_reads_surgery_index ON clinical_reads (tenant_id, surgery_id) WHERE surgery_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('clinical_reads')->whereNotNull('surgery_id')->exists()) {
            throw new RuntimeException('Surgery reads exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('DROP INDEX clinical_reads_surgery_index');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_surgery_foreign');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN surgery_id');
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy', 'exam') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
            AND (reason = 'exam') = (exam_order_id IS NOT NULL)
        ) NOT VALID");
    }
};
