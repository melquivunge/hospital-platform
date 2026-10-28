<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6b.6a1 (user's decisions, 28/10/2026): the patient's self-declared race/color (SUS codes 01 to 05) and, when
     * indigenous (05), the ethnicity (Portaria SAS 508/2010), for the BPA-I and the AIH; and the unit's data for the BPA
     * header: the provider's CNPJ, its acronym, and the SUS manager the file goes to (name, municipal or state).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE patients ADD COLUMN race_color char(2), ADD COLUMN ethnicity char(4)');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_race_color_check CHECK (
            (race_color IS NULL OR race_color IN ('01', '02', '03', '04', '05'))
            AND (ethnicity IS NULL OR (race_color = '05' AND ethnicity ~ '^[0-9]{4}$'))
        )");
        DB::statement('ALTER TABLE units ADD COLUMN cnpj char(14), ADD COLUMN sus_acronym varchar(6), ADD COLUMN sus_manager_name varchar(40), ADD COLUMN sus_manager_level char(1)');
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_sus_check CHECK (
            (cnpj IS NULL OR cnpj ~ '^[0-9]{14}$') AND (sus_manager_level IS NULL OR sus_manager_level IN ('M', 'E'))
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE units DROP CONSTRAINT units_sus_check');
        DB::statement('ALTER TABLE units DROP COLUMN cnpj, DROP COLUMN sus_acronym, DROP COLUMN sus_manager_name, DROP COLUMN sus_manager_level');
        DB::statement('ALTER TABLE patients DROP CONSTRAINT patients_race_color_check');
        DB::statement('ALTER TABLE patients DROP COLUMN race_color, DROP COLUMN ethnicity');
    }
};
