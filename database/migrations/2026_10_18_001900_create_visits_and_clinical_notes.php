<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.6a: the professional opens the visit from an arrival and closes it (no reopening); clinical notes
     * are drafts only the author sees until signed, then immutable; corrections are signed addenda. Every read
     * of the record is kept in an insert-only trail. All clinical: forced RLS (ADR-0003).
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('appointment_id')->unique();
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('patient_id');
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['professional_id', 'closed_at']);
            $table->index(['tenant_id', 'patient_id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_close_check CHECK (closed_at IS NULL OR closed_at >= opened_at)');
        // No reopening, and a visit keeps its patient, professional and appointment (enforced in the database).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION visits_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'visits are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.closed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a closed visit is never reopened nor changed' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.unit_id <> OLD.unit_id OR NEW.appointment_id <> OLD.appointment_id
                    OR NEW.professional_id <> OLD.professional_id OR NEW.patient_id <> OLD.patient_id
                    OR NEW.opened_by_user_id <> OLD.opened_by_user_id OR NEW.opened_at <> OLD.opened_at THEN
                    RAISE EXCEPTION 'a visit keeps its patient, professional and appointment' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER visits_guard BEFORE UPDATE OR DELETE ON visits FOR EACH ROW EXECUTE FUNCTION visits_guard();
            CREATE TRIGGER visits_no_truncate BEFORE TRUNCATE ON visits FOR EACH STATEMENT EXECUTE FUNCTION visits_guard();
        SQL);

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->unsignedBigInteger('addendum_of_id')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('author_professional_id');
            $table->text('body');
            $table->string('status', 10)->default('draft');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->timestampTz('signed_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'signed_at']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'addendum_of_id'])->references(['tenant_id', 'id'])->on('clinical_notes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'author_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        // A note belongs to a visit; an addendum belongs to the note it corrects.
        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_values_check CHECK (
            status IN ('draft', 'signed') AND (status = 'signed') = (signed_at IS NOT NULL)
            AND (visit_id IS NULL) <> (addendum_of_id IS NULL) AND length(btrim(body)) > 0
        )");
        // One draft of the visit's note per author: two tabs never produce two notes.
        DB::statement("CREATE UNIQUE INDEX clinical_notes_one_visit_draft ON clinical_notes (visit_id, author_user_id) WHERE status = 'draft' AND visit_id IS NOT NULL");
        // Signed is final, and nothing clinical is deleted: enforced by the database, not only the app.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinical_notes_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'clinical notes are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' OR NEW.signed_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a clinical note starts as a draft' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status = 'signed' THEN
                    RAISE EXCEPTION 'a signed clinical note is immutable' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.patient_id <> OLD.patient_id
                    OR NEW.author_user_id <> OLD.author_user_id OR NEW.author_professional_id <> OLD.author_professional_id
                    OR NEW.created_at <> OLD.created_at OR NEW.visit_id IS DISTINCT FROM OLD.visit_id
                    OR NEW.addendum_of_id IS DISTINCT FROM OLD.addendum_of_id THEN
                    RAISE EXCEPTION 'a clinical note keeps its patient, author and origin' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER clinical_notes_guard BEFORE INSERT OR UPDATE OR DELETE ON clinical_notes
                FOR EACH ROW EXECUTE FUNCTION clinical_notes_guard();
            CREATE TRIGGER clinical_notes_no_truncate BEFORE TRUNCATE ON clinical_notes
                FOR EACH STATEMENT EXECUTE FUNCTION clinical_notes_guard();
        SQL);

        Schema::create('clinical_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->string('reason', 20);
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->timestampTz('read_at');

            $table->index(['tenant_id', 'patient_id', 'read_at']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency') AND (reason = 'visit') = (visit_id IS NOT NULL)
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinical_reads_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'the record read trail is insert-only' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER clinical_reads_insert_only BEFORE UPDATE OR DELETE ON clinical_reads
                FOR EACH ROW EXECUTE FUNCTION clinical_reads_insert_only();
            CREATE TRIGGER clinical_reads_no_truncate BEFORE TRUNCATE ON clinical_reads
                FOR EACH STATEMENT EXECUTE FUNCTION clinical_reads_insert_only();
        SQL);

        // Appointment: arrived → in progress (visit open) → completed (visit closed).
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_values_check');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_values_check CHECK (
            ends_at > starts_at
            AND status IN ('scheduled', 'arrived', 'in_progress', 'completed', 'cancelled', 'no_show')
            AND (status IN ('cancelled', 'no_show')) = (closed_at IS NOT NULL)
            AND (closed_at IS NULL) = (closed_by_user_id IS NULL)
            AND (status = 'cancelled') = (cancel_reason IS NOT NULL)
            AND rescheduled_from_id IS DISTINCT FROM id
            AND (status IN ('arrived', 'in_progress', 'completed')) = (arrived_at IS NOT NULL)
            AND (arrived_at IS NULL) = (arrived_by_user_id IS NULL)
            AND (arrived_at IS NULL) = (modality IS NULL)
            AND (modality IS NULL OR modality IN ('private', 'insurance', 'sus'))
            AND (modality IS NOT DISTINCT FROM 'insurance') = (insurer_id IS NOT NULL)
            AND (insurer_id IS NULL) = (insurance_card_number IS NULL)
            AND (insurer_id IS NULL) = (insurance_card_valid_until IS NULL)
        )");

        foreach (['visits', 'clinical_notes', 'clinical_reads'] as $table) {
            ClinicalSchema::protect($table);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_values_check');
        // Without visits, a visit in progress or done goes back to "arrived".
        DB::table('appointments')->whereIn('status', ['in_progress', 'completed'])->update(['status' => 'arrived']);
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_values_check CHECK (
            ends_at > starts_at
            AND status IN ('scheduled', 'arrived', 'cancelled', 'no_show')
            AND (status IN ('scheduled', 'arrived')) = (closed_at IS NULL)
            AND (closed_at IS NULL) = (closed_by_user_id IS NULL)
            AND (status = 'cancelled') = (cancel_reason IS NOT NULL)
            AND rescheduled_from_id IS DISTINCT FROM id
            AND (status = 'arrived') = (arrived_at IS NOT NULL)
            AND (arrived_at IS NULL) = (arrived_by_user_id IS NULL)
            AND (arrived_at IS NULL) = (modality IS NULL)
            AND (modality IS NULL OR modality IN ('private', 'insurance', 'sus'))
            AND (modality IS NOT DISTINCT FROM 'insurance') = (insurer_id IS NOT NULL)
            AND (insurer_id IS NULL) = (insurance_card_number IS NULL)
            AND (insurer_id IS NULL) = (insurance_card_valid_until IS NULL)
        )");
        Schema::dropIfExists('clinical_reads');
        DB::unprepared('DROP FUNCTION IF EXISTS clinical_reads_insert_only()');
        Schema::dropIfExists('clinical_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS clinical_notes_guard()');
        Schema::dropIfExists('visits');
        DB::unprepared('DROP FUNCTION IF EXISTS visits_guard()');
    }
};
