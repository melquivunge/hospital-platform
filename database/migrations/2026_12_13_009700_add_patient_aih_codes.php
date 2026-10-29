<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6b.6b2: the patient's nationality and street type, codes of the official SISAIH01 tables (NAC_PAC and
     * TP_LOGRADOURO, 3 digits each), required in a SUS admission's account.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE patients ADD COLUMN nationality char(3), ADD COLUMN address_street_type char(3)');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_aih_codes_check CHECK (
            (nationality IS NULL OR nationality ~ '^[0-9]{3}$') AND (address_street_type IS NULL OR address_street_type ~ '^[0-9]{3}$')
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patients DROP CONSTRAINT patients_aih_codes_check');
        DB::statement('ALTER TABLE patients DROP COLUMN nationality, DROP COLUMN address_street_type');
    }
};
