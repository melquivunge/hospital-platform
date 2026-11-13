<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 7a.3 (ADR-0012): the patient (or the guardian) reading the record through the portal goes to the same
     * access trail, with the reason "portal" and the portal account instead of a staff user.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_reads ALTER COLUMN user_id DROP NOT NULL');
        DB::statement('ALTER TABLE clinical_reads ADD COLUMN patient_account_id bigint REFERENCES patient_accounts (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only, as since step 3.6b: the insert-only trail may hold rows that older rules allowed.
        DB::statement(self::reasonCheck(true));
    }

    public function down(): void
    {
        if (DB::table('clinical_reads')->where('reason', 'portal')->exists()) {
            throw new RuntimeException('Portal reads exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN patient_account_id');
        DB::statement('ALTER TABLE clinical_reads ALTER COLUMN user_id SET NOT NULL');
        DB::statement(self::reasonCheck(false));
    }

    private static function reasonCheck(bool $portal): string
    {
        return "ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy', 'exam', 'surgery', 'billing', 'audit'".($portal ? ", 'portal'" : '').") AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
            AND (reason = 'exam') = (exam_order_id IS NOT NULL)
            AND (reason = 'surgery') = (surgery_id IS NOT NULL)
            AND (reason = 'billing') = (billing_account_id IS NOT NULL)
            AND (reason = 'audit') = (aih_request_id IS NOT NULL)
            ".($portal ? "AND (reason = 'portal') = (patient_account_id IS NOT NULL) AND (reason = 'portal') = (user_id IS NULL)" : '').'
        ) NOT VALID';
    }
};
