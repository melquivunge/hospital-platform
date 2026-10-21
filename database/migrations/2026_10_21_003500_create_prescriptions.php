<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.2a: the prescription of an admission or an emergency visit. A draft belongs to its author; signing
     * (password, ADR-0006 until the cloud certificate) freezes it for 24 hours. Items keep the catalog label and
     * dose unit as signed, the safety alerts found and the prescriber's justification. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('admission_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->string('status', 10)->default('draft');
            $table->timestampTz('created_at');
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->string('signature_method', 20)->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_values_check CHECK (
            (admission_id IS NULL) <> (visit_id IS NULL)
            AND status IN ('draft', 'signed')
            AND (status = 'draft') = (signed_at IS NULL)
            AND (status = 'draft') = (signature_method IS NULL)
            AND (signature_method IS NULL OR signature_method IN ('password'))
            AND ((status = 'draft' AND valid_until IS NULL) OR valid_until = signed_at + interval '24 hours')
        )");
        // One draft per author and encounter.
        DB::statement("CREATE UNIQUE INDEX prescriptions_admission_draft_unique ON prescriptions (author_user_id, admission_id) WHERE status = 'draft' AND admission_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX prescriptions_visit_draft_unique ON prescriptions (author_user_id, visit_id) WHERE status = 'draft' AND visit_id IS NOT NULL");
        DB::statement('CREATE INDEX prescriptions_admission_signed ON prescriptions (admission_id, signed_at) WHERE signed_at IS NOT NULL');
        DB::statement('CREATE INDEX prescriptions_visit_signed ON prescriptions (visit_id, signed_at) WHERE signed_at IS NOT NULL');

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_id');
            $table->unsignedBigInteger('medication_id');
            $table->string('medication_label', 500);
            $table->decimal('dose', 12, 3);
            $table->string('dose_unit', 20);
            $table->string('route', 20);
            $table->unsignedSmallInteger('frequency_hours')->nullable();
            $table->jsonb('times')->nullable();
            $table->boolean('prn')->default(false);
            $table->string('prn_condition', 255)->nullable();
            $table->timestampTz('starts_at');
            $table->string('notes', 500)->nullable();
            $table->jsonb('alerts')->default('[]');
            $table->string('justification', 500)->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'prescription_id']);
            $table->foreign(['tenant_id', 'prescription_id'])->references(['tenant_id', 'id'])->on('prescriptions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_values_check CHECK (
            dose > 0
            AND (frequency_hours IS NULL) <> (times IS NULL)
            AND (frequency_hours IS NULL OR frequency_hours IN (1, 2, 3, 4, 6, 8, 12, 24))
            AND (times IS NULL OR (jsonb_typeof(times) = 'array' AND jsonb_array_length(times) BETWEEN 1 AND 24))
            AND (prn = (prn_condition IS NOT NULL))
            AND (NOT prn OR frequency_hours IS NOT NULL)
            AND jsonb_typeof(alerts) = 'array'
        )");

        // A signed prescription and its items never change; a draft may be edited or discarded by its author.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescriptions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE' OR OLD.status = 'signed' THEN
                    RAISE EXCEPTION 'a signed prescription never changes' USING ERRCODE = 'P0001';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER prescriptions_guard BEFORE UPDATE OR DELETE ON prescriptions
                FOR EACH ROW EXECUTE FUNCTION prescriptions_guard();
            CREATE TRIGGER prescriptions_no_truncate BEFORE TRUNCATE ON prescriptions
                FOR EACH STATEMENT EXECUTE FUNCTION prescriptions_guard();

            CREATE OR REPLACE FUNCTION prescription_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE'
                    OR (SELECT status FROM prescriptions WHERE id = COALESCE(NEW.prescription_id, OLD.prescription_id)) = 'signed'
                    OR (TG_OP = 'UPDATE' AND NEW.prescription_id <> OLD.prescription_id) THEN
                    RAISE EXCEPTION 'the items of a signed prescription never change' USING ERRCODE = 'P0001';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER prescription_items_guard BEFORE INSERT OR UPDATE OR DELETE ON prescription_items
                FOR EACH ROW EXECUTE FUNCTION prescription_items_guard();
            CREATE TRIGGER prescription_items_no_truncate BEFORE TRUNCATE ON prescription_items
                FOR EACH STATEMENT EXECUTE FUNCTION prescription_items_guard();
        SQL);

        ClinicalSchema::protect('prescriptions');
        ClinicalSchema::protect('prescription_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        DB::unprepared('DROP FUNCTION IF EXISTS prescription_items_guard(); DROP FUNCTION IF EXISTS prescriptions_guard();');
    }
};
