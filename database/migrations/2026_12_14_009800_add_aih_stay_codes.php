<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6b.6b2b1 (user's decisions, 28/10/2026): the sector's SUS bed specialty (ESPEC_AIH), set by the bed
     * management; billing's correction of the AIH's admission character (CAR_INTERN) and exit reason (MOT_SAÍDA), which
     * otherwise come from the stay (null = derived); the three are frozen in the account when it closes.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE sectors ADD COLUMN sus_bed_specialty char(2)');
        DB::statement("ALTER TABLE sectors ADD CONSTRAINT sectors_sus_bed_specialty_check CHECK (sus_bed_specialty IS NULL OR sus_bed_specialty ~ '^[0-9]{2}$')");
        DB::statement('ALTER TABLE billing_accounts ADD COLUMN aih_character char(2), ADD COLUMN aih_exit_reason char(2), ADD COLUMN aih_bed_specialty char(2)');
        DB::statement("ALTER TABLE billing_accounts ADD CONSTRAINT billing_accounts_aih_codes_check CHECK (
            (aih_character IS NULL OR aih_character ~ '^[0-9]{2}$') AND (aih_exit_reason IS NULL OR aih_exit_reason ~ '^[0-9]{2}$')
            AND (aih_bed_specialty IS NULL OR aih_bed_specialty ~ '^[0-9]{2}$')
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE billing_accounts DROP CONSTRAINT billing_accounts_aih_codes_check');
        DB::statement('ALTER TABLE billing_accounts DROP COLUMN aih_character, DROP COLUMN aih_exit_reason, DROP COLUMN aih_bed_specialty');
        DB::statement('ALTER TABLE sectors DROP CONSTRAINT sectors_sus_bed_specialty_check');
        DB::statement('ALTER TABLE sectors DROP COLUMN sus_bed_specialty');
    }
};
