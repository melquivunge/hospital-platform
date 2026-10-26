<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.2c (ADR-0010): opening an account is a read of the trail, with the reason "billing" and the account.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_reads ADD COLUMN billing_account_id bigint');
        DB::statement('ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_billing_account_foreign FOREIGN KEY (tenant_id, billing_account_id) REFERENCES billing_accounts (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only, as since step 3.6b: the insert-only trail may hold rows that older rules allowed.
        DB::statement(self::reasonCheck(true));
        DB::statement('CREATE INDEX clinical_reads_billing_account_index ON clinical_reads (tenant_id, billing_account_id) WHERE billing_account_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('clinical_reads')->whereNotNull('billing_account_id')->exists()) {
            throw new RuntimeException('Billing reads exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('DROP INDEX clinical_reads_billing_account_index');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_billing_account_foreign');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN billing_account_id');
        DB::statement(self::reasonCheck(false));
    }

    private static function reasonCheck(bool $billing): string
    {
        return "ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy', 'exam', 'surgery'".($billing ? ", 'billing'" : '').") AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
            AND (reason = 'exam') = (exam_order_id IS NOT NULL)
            AND (reason = 'surgery') = (surgery_id IS NOT NULL)
            ".($billing ? "AND (reason = 'billing') = (billing_account_id IS NOT NULL)" : '').'
        ) NOT VALID';
    }
};
