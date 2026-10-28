<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6b.6b1 (user's decisions, 28/10/2026): the patient's address (sizes of the SISAIH01 layout; the municipality
     * by its IBGE code), required in a SUS admission's account; the unit's municipality, AIH issuing body and clinical
     * director; the CNS of the manager's doctor who authorized the AIH, with its number.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE patients ADD COLUMN address_street varchar(50), ADD COLUMN address_number varchar(7), ADD COLUMN address_complement varchar(15),
            ADD COLUMN address_district varchar(30), ADD COLUMN address_municipality char(7), ADD COLUMN address_zip char(8)');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_address_check CHECK (
            (address_municipality IS NULL OR address_municipality ~ '^[0-9]{7}$') AND (address_zip IS NULL OR address_zip ~ '^[0-9]{8}$')
        )");
        DB::statement('ALTER TABLE units ADD COLUMN municipality char(7), ADD COLUMN aih_issuer varchar(10), ADD COLUMN clinical_director_id bigint');
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_aih_check CHECK (municipality IS NULL OR municipality ~ '^[0-9]{7}$')");
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_clinical_director_foreign FOREIGN KEY (tenant_id, clinical_director_id) REFERENCES professionals (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE billing_accounts ADD COLUMN aih_authorizer_cns char(15)');
        DB::statement("ALTER TABLE billing_accounts ADD CONSTRAINT billing_accounts_aih_authorizer_check CHECK (
            aih_authorizer_cns IS NULL OR (aih_authorizer_cns ~ '^[0-9]{15}$' AND aih_number IS NOT NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE billing_accounts DROP CONSTRAINT billing_accounts_aih_authorizer_check');
        DB::statement('ALTER TABLE billing_accounts DROP COLUMN aih_authorizer_cns');
        DB::statement('ALTER TABLE units DROP CONSTRAINT units_clinical_director_foreign');
        DB::statement('ALTER TABLE units DROP CONSTRAINT units_aih_check');
        DB::statement('ALTER TABLE units DROP COLUMN municipality, DROP COLUMN aih_issuer, DROP COLUMN clinical_director_id');
        DB::statement('ALTER TABLE patients DROP CONSTRAINT patients_address_check');
        DB::statement('ALTER TABLE patients DROP COLUMN address_street, DROP COLUMN address_number, DROP COLUMN address_complement,
            DROP COLUMN address_district, DROP COLUMN address_municipality, DROP COLUMN address_zip');
    }
};
