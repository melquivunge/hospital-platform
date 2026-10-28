<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.2 (ADR-0011): the CID-10 diagnoses informed by who attends, at the close of a visit (consultation or
     * emergency) or at the discharge of an admission: one principal and up to five secondary codes per version. A
     * correction is a new version; nothing is changed or deleted. Clinical: RLS.
     */
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->unsignedBigInteger('admission_id')->nullable();
            $table->unsignedSmallInteger('version');
            $table->string('cid_code', 4);
            $table->boolean('principal');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->index('visit_id');
            $table->index('admission_id');
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE diagnoses ADD CONSTRAINT diagnoses_values_check CHECK (
            num_nonnulls(visit_id, admission_id) = 1 AND version >= 1 AND cid_code ~ '^[A-Z][0-9]{2}[0-9]?$'
        )");
        DB::statement('CREATE UNIQUE INDEX diagnoses_visit_code ON diagnoses (visit_id, version, cid_code) WHERE visit_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX diagnoses_admission_code ON diagnoses (admission_id, version, cid_code) WHERE admission_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX diagnoses_visit_principal ON diagnoses (visit_id, version) WHERE visit_id IS NOT NULL AND principal');
        DB::statement('CREATE UNIQUE INDEX diagnoses_admission_principal ON diagnoses (admission_id, version) WHERE admission_id IS NOT NULL AND principal');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION diagnoses_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a diagnosis is never changed nor deleted' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER diagnoses_guard BEFORE UPDATE OR DELETE ON diagnoses FOR EACH ROW EXECUTE FUNCTION diagnoses_guard();
            CREATE TRIGGER diagnoses_no_truncate BEFORE TRUNCATE ON diagnoses FOR EACH STATEMENT EXECUTE FUNCTION diagnoses_guard();
        SQL);
        ClinicalSchema::protect('diagnoses');
    }

    public function down(): void
    {
        if (DB::table('diagnoses')->exists()) {
            throw new RuntimeException('Diagnoses exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('diagnoses');
        DB::unprepared('DROP FUNCTION IF EXISTS diagnoses_guard()');
    }
};
