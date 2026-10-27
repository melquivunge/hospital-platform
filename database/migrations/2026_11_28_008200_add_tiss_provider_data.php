<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.5a (TISS 4.03.00): what the insurer's guides need about who performed: the unit's CNES (7 digits), the
     * hospital's code at each insurer (codigoPrestadorNaOperadora, up to 14 characters, on the contract) and the
     * occupation's CBO (6 digits, on the job function). Optional until a lot is built (step 6.5b says what is missing).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE units ADD COLUMN cnes char(7)');
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_cnes_check CHECK (cnes ~ '^[0-9]{7}$')");
        DB::statement('ALTER TABLE insurer_contracts ADD COLUMN provider_code varchar(14)');
        DB::statement('ALTER TABLE insurer_contracts ADD CONSTRAINT insurer_contracts_provider_code_check CHECK (length(btrim(provider_code)) > 0)');
        DB::statement('ALTER TABLE job_functions ADD COLUMN cbo char(6)');
        DB::statement("ALTER TABLE job_functions ADD CONSTRAINT job_functions_cbo_check CHECK (cbo ~ '^[0-9]{6}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE job_functions DROP COLUMN cbo');
        DB::statement('ALTER TABLE insurer_contracts DROP COLUMN provider_code');
        DB::statement('ALTER TABLE units DROP COLUMN cnes');
    }
};
