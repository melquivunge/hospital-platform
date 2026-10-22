<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.5 (ADR-0006): a signed prescription also gets a digital signature from the doctor's cloud
     * certificate. The professional's CPF (registered by the administration) is what the certificate holder
     * must match. The signer account keeps only the credential the provider needs, encrypted. A signature row
     * exists only for a signature that passed validation; insert-only, clinical (RLS).
     */
    public function up(): void
    {
        Schema::table('professionals', fn (Blueprint $table) => $table->char('cpf', 11)->nullable());
        DB::statement("ALTER TABLE professionals ADD CONSTRAINT professionals_cpf_check CHECK (cpf IS NULL OR cpf ~ '^[0-9]{11}$')");
        DB::statement('CREATE UNIQUE INDEX professionals_cpf_unique ON professionals (tenant_id, cpf) WHERE cpf IS NOT NULL');

        Schema::create('signing_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->text('credential');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('prescription_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_id')->unique();
            $table->string('provider', 30);
            $table->boolean('qualified');
            $table->string('document_path', 255);
            $table->char('document_sha256', 64);
            $table->string('signature_path', 255);
            $table->string('signer_name', 255);
            $table->char('signer_cpf', 11);
            $table->string('certificate_serial', 100);
            $table->string('certificate_issuer', 255);
            $table->foreignId('signed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('signed_at');

            $table->foreign(['tenant_id', 'prescription_id'])->references(['tenant_id', 'id'])->on('prescriptions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE prescription_signatures ADD CONSTRAINT prescription_signatures_values_check CHECK (signer_cpf ~ '^[0-9]{11}$' AND document_sha256 ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_signatures_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a prescription signature never changes' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER prescription_signatures_guard BEFORE UPDATE OR DELETE ON prescription_signatures
                FOR EACH ROW EXECUTE FUNCTION prescription_signatures_guard();
            CREATE TRIGGER prescription_signatures_no_truncate BEFORE TRUNCATE ON prescription_signatures
                FOR EACH STATEMENT EXECUTE FUNCTION prescription_signatures_guard();
        SQL);

        ClinicalSchema::protect('prescription_signatures');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_signatures');
        DB::unprepared('DROP FUNCTION IF EXISTS prescription_signatures_guard()');
        Schema::dropIfExists('signing_accounts');
        DB::statement('DROP INDEX IF EXISTS professionals_cpf_unique');
        Schema::table('professionals', fn (Blueprint $table) => $table->dropColumn('cpf'));
    }
};
