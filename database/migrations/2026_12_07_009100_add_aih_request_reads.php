<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6b.5a2 (ADR-0011): the auditor opens an AIH request report; the read goes to the trail with the reason
     * "audit" and the report.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE aih_requests ADD CONSTRAINT aih_requests_tenant_id_id_unique UNIQUE (tenant_id, id)');
        DB::statement('ALTER TABLE clinical_reads ADD COLUMN aih_request_id bigint');
        DB::statement('ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_aih_request_foreign FOREIGN KEY (tenant_id, aih_request_id) REFERENCES aih_requests (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only, as since step 3.6b: the insert-only trail may hold rows that older rules allowed.
        DB::statement(self::reasonCheck(true));
        DB::statement('CREATE INDEX clinical_reads_aih_request_index ON clinical_reads (tenant_id, aih_request_id) WHERE aih_request_id IS NOT NULL');
        // The signer's list and the menu entry (every page) look the reports up by who signed them.
        DB::statement('CREATE INDEX aih_requests_signer_index ON aih_requests (tenant_id, professional_id, signed_at DESC)');
    }

    public function down(): void
    {
        if (DB::table('clinical_reads')->whereNotNull('aih_request_id')->exists()) {
            throw new RuntimeException('Audit reads exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('DROP INDEX aih_requests_signer_index');
        DB::statement('DROP INDEX clinical_reads_aih_request_index');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_aih_request_foreign');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN aih_request_id');
        DB::statement('ALTER TABLE aih_requests DROP CONSTRAINT aih_requests_tenant_id_id_unique');
        DB::statement(self::reasonCheck(false));
    }

    private static function reasonCheck(bool $audit): string
    {
        return "ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy', 'exam', 'surgery', 'billing'".($audit ? ", 'audit'" : '').") AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
            AND (reason = 'exam') = (exam_order_id IS NOT NULL)
            AND (reason = 'surgery') = (surgery_id IS NOT NULL)
            AND (reason = 'billing') = (billing_account_id IS NOT NULL)
            ".($audit ? "AND (reason = 'audit') = (aih_request_id IS NOT NULL)" : '').'
        ) NOT VALID';
    }
};
