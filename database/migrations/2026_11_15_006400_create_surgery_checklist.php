<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.4 (ADR-0009): the WHO safe surgery checklist (before anaesthesia, before incision, before leaving the
     * room) and the signed consent term. Each step is confirmed once by the unit's Surgical center or the team, in
     * order, never changed; the first needs a consent (or, for an urgent surgery, a waiver with a reason) and puts the
     * surgery in progress. Consents are kept: a new one supersedes, none is changed or deleted. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::table('surgeries', fn (Blueprint $table) => $table->timestampTz('started_at')->nullable());
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_values_check');
        DB::statement(self::valuesCheck(true));
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_no_room_overlap');
        DB::statement("ALTER TABLE surgeries ADD CONSTRAINT surgeries_no_room_overlap EXCLUDE USING gist (
            operating_room_id WITH =, tstzrange(scheduled_start, scheduled_end) WITH &&
        ) WHERE (status IN ('scheduled', 'in_progress'))");

        Schema::create('surgery_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('surgery_id');
            $table->string('kind', 10);
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('waiver_reason', 500)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'surgery_id']);
            $table->foreign(['tenant_id', 'surgery_id'])->references(['tenant_id', 'id'])->on('surgeries')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE surgery_consents ADD CONSTRAINT surgery_consents_values_check CHECK (
            (kind = 'document' AND path IS NOT NULL AND original_name IS NOT NULL AND mime_type IN ('application/pdf', 'image/jpeg', 'image/png') AND size_bytes IS NOT NULL AND sha256 IS NOT NULL AND waiver_reason IS NULL)
            OR (kind = 'waiver' AND path IS NULL AND original_name IS NULL AND mime_type IS NULL AND size_bytes IS NULL AND sha256 IS NULL AND length(btrim(coalesce(waiver_reason, ''))) > 0)
        )");

        Schema::create('surgery_checklist_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('surgery_id');
            $table->string('step', 10);
            $table->jsonb('answers');
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['surgery_id', 'step']);
            $table->foreign(['tenant_id', 'surgery_id'])->references(['tenant_id', 'id'])->on('surgeries')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE surgery_checklist_steps ADD CONSTRAINT surgery_checklist_steps_values_check CHECK (
            step IN ('sign_in', 'time_out', 'sign_out') AND jsonb_typeof(answers) = 'object'
        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION surgery_checklist_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'confirmed checklist steps and consents never change' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'surgery_consents' THEN
                    IF NOT EXISTS (SELECT 1 FROM surgeries WHERE id = NEW.surgery_id AND status IN ('requested', 'scheduled')) THEN
                        RAISE EXCEPTION 'the consent term changes only before the first step' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.kind = 'waiver' AND NOT EXISTS (SELECT 1 FROM surgeries WHERE id = NEW.surgery_id AND urgency = 'urgent') THEN
                        RAISE EXCEPTION 'only an urgent surgery goes without a consent term' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM surgeries WHERE id = NEW.surgery_id
                    AND status = CASE WHEN NEW.step = 'sign_in' THEN 'scheduled' ELSE 'in_progress' END) THEN
                    RAISE EXCEPTION 'this checklist step does not fit the surgery status' USING ERRCODE = '23514';
                END IF;
                IF NEW.step = 'sign_in' AND NOT EXISTS (SELECT 1 FROM surgery_consents WHERE surgery_id = NEW.surgery_id) THEN
                    RAISE EXCEPTION 'the first step needs the consent term' USING ERRCODE = '23514';
                END IF;
                IF NEW.step = 'time_out' AND NOT EXISTS (SELECT 1 FROM surgery_checklist_steps WHERE surgery_id = NEW.surgery_id AND step = 'sign_in') THEN
                    RAISE EXCEPTION 'checklist steps go in order' USING ERRCODE = '23514';
                END IF;
                IF NEW.step = 'sign_out' AND NOT EXISTS (SELECT 1 FROM surgery_checklist_steps WHERE surgery_id = NEW.surgery_id AND step = 'time_out') THEN
                    RAISE EXCEPTION 'checklist steps go in order' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER surgery_checklist_steps_guard BEFORE INSERT OR UPDATE OR DELETE ON surgery_checklist_steps
                FOR EACH ROW EXECUTE FUNCTION surgery_checklist_guard();
            CREATE TRIGGER surgery_checklist_steps_no_truncate BEFORE TRUNCATE ON surgery_checklist_steps
                FOR EACH STATEMENT EXECUTE FUNCTION surgery_checklist_guard();
            CREATE TRIGGER surgery_consents_guard BEFORE INSERT OR UPDATE OR DELETE ON surgery_consents
                FOR EACH ROW EXECUTE FUNCTION surgery_checklist_guard();
            CREATE TRIGGER surgery_consents_no_truncate BEFORE TRUNCATE ON surgery_consents
                FOR EACH STATEMENT EXECUTE FUNCTION surgery_checklist_guard();
        SQL);

        ClinicalSchema::protect('surgery_consents');
        ClinicalSchema::protect('surgery_checklist_steps');
    }

    public function down(): void
    {
        // Under forced RLS a check without a client sees nothing; with row security off, a role that cannot bypass it
        // gets an error instead, so the rollback is refused rather than dropping clinical rows it could not see.
        DB::transaction(function () {
            DB::statement('SET LOCAL row_security = off');
            if (DB::table('surgery_consents')->exists() || DB::table('surgery_checklist_steps')->exists() || DB::table('surgeries')->where('status', 'in_progress')->exists()) {
                throw new RuntimeException('Checklists or consents exist and are never deleted: this migration cannot be rolled back.');
            }
        });
        Schema::dropIfExists('surgery_checklist_steps');
        Schema::dropIfExists('surgery_consents');
        DB::unprepared('DROP FUNCTION IF EXISTS surgery_checklist_guard()');
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_no_room_overlap');
        DB::statement("ALTER TABLE surgeries ADD CONSTRAINT surgeries_no_room_overlap EXCLUDE USING gist (
            operating_room_id WITH =, tstzrange(scheduled_start, scheduled_end) WITH &&
        ) WHERE (status = 'scheduled')");
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_values_check');
        Schema::table('surgeries', fn (Blueprint $table) => $table->dropColumn('started_at'));
        DB::statement(self::valuesCheck(false));
    }

    private static function valuesCheck(bool $started): string
    {
        $statuses = $started ? "'requested', 'scheduled', 'in_progress', 'cancelled'" : "'requested', 'scheduled', 'cancelled'";
        $booked = $started ? "status NOT IN ('scheduled', 'in_progress')" : "status <> 'scheduled'";
        $start = $started ? "AND ((status = 'in_progress') = (started_at IS NOT NULL))" : '';

        return "ALTER TABLE surgeries ADD CONSTRAINT surgeries_values_check CHECK (
            (admission_id IS NULL) <> (visit_id IS NULL)
            AND laterality IN ('none', 'left', 'right', 'bilateral')
            AND urgency IN ('elective', 'urgent')
            AND length(btrim(procedure_name)) > 0
            AND length(btrim(indication)) > 0
            AND signature_method IN ('password')
            AND status IN ({$statuses})
            AND ((status = 'cancelled') = (cancelled_at IS NOT NULL))
            AND ((cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                OR (cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND length(btrim(coalesce(cancellation_reason, ''))) > 0))
            AND ((operating_room_id IS NULL AND scheduled_start IS NULL AND scheduled_end IS NULL AND scheduled_by_user_id IS NULL AND scheduled_at IS NULL)
                OR (operating_room_id IS NOT NULL AND scheduled_start IS NOT NULL AND scheduled_end IS NOT NULL AND scheduled_by_user_id IS NOT NULL AND scheduled_at IS NOT NULL
                    AND scheduled_end > scheduled_start AND scheduled_end - scheduled_start <= interval '24 hours'))
            AND (status <> 'requested' OR operating_room_id IS NULL)
            AND ({$booked} OR operating_room_id IS NOT NULL)
            {$start}
            AND (urgent_reason IS NULL OR (urgency = 'urgent' AND length(btrim(urgent_reason)) > 0))
        )";
    }
};
