<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.5a (ADR-0011): the AIH request report of a SUS admission, signed with the password by the responsible
     * doctor: the requested SIGTAP procedure (code and name as in the table), the CID-10 principal and secondary and
     * the clinical justification. A correction is a new version; nothing is changed or deleted. Clinical: RLS.
     */
    public function up(): void
    {
        Schema::create('aih_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('admission_id');
            $table->unsignedSmallInteger('version');
            $table->char('procedure_code', 10);
            $table->string('procedure_name', 250);
            $table->string('cid_code', 4);
            $table->string('cid_secondary', 4)->nullable();
            $table->text('justification');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->timestampTz('signed_at');

            $table->unique(['admission_id', 'version']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE aih_requests ADD CONSTRAINT aih_requests_values_check CHECK (
            version >= 1 AND procedure_code ~ '^[0-9]{10}$' AND cid_code ~ '^[A-Z][0-9]{2}[0-9]?$'
            AND (cid_secondary IS NULL OR cid_secondary ~ '^[A-Z][0-9]{2}[0-9]?$') AND length(btrim(justification)) > 0
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aih_requests_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'an AIH request is never changed nor deleted' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER aih_requests_guard BEFORE UPDATE OR DELETE ON aih_requests FOR EACH ROW EXECUTE FUNCTION aih_requests_guard();
            CREATE TRIGGER aih_requests_no_truncate BEFORE TRUNCATE ON aih_requests FOR EACH STATEMENT EXECUTE FUNCTION aih_requests_guard();
        SQL);
        ClinicalSchema::protect('aih_requests');
    }

    public function down(): void
    {
        if (DB::table('aih_requests')->exists()) {
            throw new RuntimeException('AIH requests exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('aih_requests');
        DB::unprepared('DROP FUNCTION IF EXISTS aih_requests_guard()');
    }
};
