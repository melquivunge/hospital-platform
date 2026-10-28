<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6b.5b1 (ADR-0011): the number of the AIH the manager authorized, recorded by billing on the SUS admission's
     * account (13 digits, once in the network). The account's guard keeps it once the account is closed.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE billing_accounts ADD COLUMN aih_number char(13)');
        DB::statement("ALTER TABLE billing_accounts ADD CONSTRAINT billing_accounts_aih_number_check CHECK (
            aih_number IS NULL OR (aih_number ~ '^[0-9]{13}$' AND modality = 'sus' AND complement_of_id IS NULL)
        )");
        DB::statement('CREATE UNIQUE INDEX billing_accounts_aih_number_unique ON billing_accounts (tenant_id, aih_number) WHERE aih_number IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX billing_accounts_aih_number_unique');
        DB::statement('ALTER TABLE billing_accounts DROP CONSTRAINT billing_accounts_aih_number_check');
        DB::statement('ALTER TABLE billing_accounts DROP COLUMN aih_number');
    }
};
