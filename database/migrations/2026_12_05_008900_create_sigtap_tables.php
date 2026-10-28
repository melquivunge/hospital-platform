<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.3 (ADR-0011): the SIGTAP table of each competence, imported by the network admin from the official
     * DATASUS zip: each procedure with its values (SH, SA, SP in cents), sex and ages (months), the instruments it is
     * registered in (BPA-C 01, BPA-I 02, AIH 03-05...) and the compatible CID-10 and CBO codes. Network catalog, no
     * patient data, no RLS (like the price tables). A billable item may carry its SIGTAP code.
     */
    public function up(): void
    {
        Schema::create('sigtap_competences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->date('competence');
            $table->string('file_name', 100);
            $table->char('file_sha256', 64);
            $table->unsignedInteger('procedure_count');
            $table->foreignId('imported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('imported_at');

            $table->unique(['tenant_id', 'competence']);
        });
        DB::statement("ALTER TABLE sigtap_competences ADD CONSTRAINT sigtap_competences_check CHECK (
            extract(day FROM competence) = 1 AND file_sha256 ~ '^[0-9a-f]{64}$'
        )");

        Schema::create('sigtap_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('sigtap_competence_id')->constrained()->cascadeOnDelete();
            $table->char('code', 10);
            $table->string('name', 250);
            $table->char('sex', 1);
            $table->unsignedSmallInteger('min_age_months');
            $table->unsignedSmallInteger('max_age_months');
            $table->unsignedInteger('max_quantity');
            $table->bigInteger('sh_cents');
            $table->bigInteger('sa_cents');
            $table->bigInteger('sp_cents');

            $table->unique(['sigtap_competence_id', 'code']);
        });
        DB::statement('ALTER TABLE sigtap_procedures ADD COLUMN registers text[] NOT NULL, ADD COLUMN cids text[] NOT NULL, ADD COLUMN cbos text[] NOT NULL');
        DB::statement("ALTER TABLE sigtap_procedures ADD CONSTRAINT sigtap_procedures_check CHECK (
            code ~ '^[0-9]{10}$' AND sex IN ('I', 'F', 'M', 'N') AND sh_cents >= 0 AND sa_cents >= 0 AND sp_cents >= 0
        )");

        Schema::table('billing_items', fn (Blueprint $table) => $table->char('sigtap_code', 10)->nullable());
        DB::statement("ALTER TABLE billing_items ADD CONSTRAINT billing_items_sigtap_check CHECK (sigtap_code IS NULL OR sigtap_code ~ '^[0-9]{10}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE billing_items DROP CONSTRAINT billing_items_sigtap_check');
        Schema::table('billing_items', fn (Blueprint $table) => $table->dropColumn('sigtap_code'));
        Schema::dropIfExists('sigtap_procedures');
        Schema::dropIfExists('sigtap_competences');
    }
};
