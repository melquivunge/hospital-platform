<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.1 (ADR-0011): the professional's Cartão Nacional de Saúde, which the SUS files (BPA-I and AIH) carry;
     * unique in the network, like the CPF. The check digit is validated by the application (PatientIdentity::validCns).
     */
    public function up(): void
    {
        Schema::table('professionals', fn (Blueprint $table) => $table->char('cns', 15)->nullable());
        DB::statement("ALTER TABLE professionals ADD CONSTRAINT professionals_cns_check CHECK (cns IS NULL OR cns ~ '^[0-9]{15}$')");
        DB::statement('CREATE UNIQUE INDEX professionals_cns_unique ON professionals (tenant_id, cns) WHERE cns IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS professionals_cns_unique');
        Schema::table('professionals', fn (Blueprint $table) => $table->dropColumn('cns'));
    }
};
