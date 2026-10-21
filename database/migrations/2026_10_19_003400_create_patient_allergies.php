<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.1c: allergies recorded on the patient by whoever has clinical write access. The substance is
     * free text or a catalog active ingredient; substance_key (the catalog's ingredient key) is what the
     * prescription matches in step 4b.2. Insert-only: inactivating needs a reason and keeps the row. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->string('substance', 255);
            $table->string('substance_key', 255);
            $table->string('severity', 20);
            $table->string('reaction', 255)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->timestampTz('created_at');
            $table->timestampTz('inactivated_at')->nullable();
            $table->foreignId('inactivated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('inactivation_reason', 255)->nullable();

            $table->index(['tenant_id', 'patient_id']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_values_check CHECK (
            severity IN ('mild', 'moderate', 'severe', 'unknown')
            AND btrim(substance) <> '' AND substance_key <> ''
            AND (inactivated_at IS NULL) = (inactivated_by_user_id IS NULL)
            AND (inactivated_at IS NULL) = (inactivation_reason IS NULL)
        )");
        // One active allergy per substance and patient: two simultaneous records of the same one yield one.
        DB::statement('CREATE UNIQUE INDEX patient_allergies_active_unique ON patient_allergies (tenant_id, patient_id, substance_key) WHERE inactivated_at IS NULL');
        // Only the inactivation may be recorded, once; the row is never deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION patient_allergies_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'patient allergies are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.inactivated_at IS NOT NULL
                    OR (to_jsonb(NEW) - 'inactivated_at' - 'inactivated_by_user_id' - 'inactivation_reason')
                        <> (to_jsonb(OLD) - 'inactivated_at' - 'inactivated_by_user_id' - 'inactivation_reason') THEN
                    RAISE EXCEPTION 'a patient allergy only records its inactivation, once' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER patient_allergies_guard BEFORE UPDATE OR DELETE ON patient_allergies
                FOR EACH ROW EXECUTE FUNCTION patient_allergies_guard();
            CREATE TRIGGER patient_allergies_no_truncate BEFORE TRUNCATE ON patient_allergies
                FOR EACH STATEMENT EXECUTE FUNCTION patient_allergies_guard();
        SQL);

        ClinicalSchema::protect('patient_allergies');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_allergies');
        DB::unprepared('DROP FUNCTION IF EXISTS patient_allergies_guard()');
    }
};
