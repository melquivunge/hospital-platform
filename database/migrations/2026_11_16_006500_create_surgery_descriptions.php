<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.5 (ADR-0009): the surgical description, signed with the password by a surgeon of the team once the
     * three checklist steps are confirmed; signing it completes the surgery. Addenda come later, the original stays.
     * Nothing signed changes or goes away (trigger). Clinical (RLS).
     */
    public function up(): void
    {
        Schema::table('surgeries', fn (Blueprint $table) => $table->timestampTz('completed_at')->nullable());
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_values_check');
        DB::statement(self::valuesCheck(true));

        Schema::create('surgery_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('surgery_id');
            $table->unsignedBigInteger('addendum_of_id')->nullable();
            $table->text('findings')->nullable();
            $table->text('technique')->nullable();
            $table->text('complications')->nullable();
            $table->string('specimens', 1000)->nullable();
            $table->text('body')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->string('signature_method', 20);
            $table->timestampTz('signed_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'surgery_id']);
            $table->foreign(['tenant_id', 'surgery_id'])->references(['tenant_id', 'id'])->on('surgeries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'addendum_of_id'])->references(['tenant_id', 'id'])->on('surgery_descriptions')->restrictOnDelete();
        });
        // One description per surgery; the rest are addenda.
        DB::statement('CREATE UNIQUE INDEX surgery_descriptions_one_per_surgery ON surgery_descriptions (surgery_id) WHERE addendum_of_id IS NULL');
        DB::statement("ALTER TABLE surgery_descriptions ADD CONSTRAINT surgery_descriptions_values_check CHECK (
            signature_method IN ('password')
            AND ((addendum_of_id IS NULL AND length(btrim(coalesce(findings, ''))) > 0 AND length(btrim(coalesce(technique, ''))) > 0
                    AND length(btrim(coalesce(complications, ''))) > 0 AND body IS NULL)
                OR (addendum_of_id IS NOT NULL AND findings IS NULL AND technique IS NULL AND complications IS NULL AND specimens IS NULL
                    AND length(btrim(coalesce(body, ''))) > 0))
        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION surgery_description_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'signed surgical descriptions never change' USING ERRCODE = '23514';
                END IF;
                IF NEW.addendum_of_id IS NULL THEN
                    IF NOT EXISTS (SELECT 1 FROM surgeries WHERE id = NEW.surgery_id AND status = 'in_progress')
                        OR NOT EXISTS (SELECT 1 FROM surgery_checklist_steps WHERE surgery_id = NEW.surgery_id AND step = 'sign_out') THEN
                        RAISE EXCEPTION 'the description needs the surgery in progress with the three checklist steps' USING ERRCODE = '23514';
                    END IF;
                ELSIF NOT EXISTS (SELECT 1 FROM surgery_descriptions WHERE id = NEW.addendum_of_id AND surgery_id = NEW.surgery_id AND addendum_of_id IS NULL)
                    OR NOT EXISTS (SELECT 1 FROM surgeries WHERE id = NEW.surgery_id AND status = 'completed') THEN
                    RAISE EXCEPTION 'an addendum corrects the description of a completed surgery' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER surgery_descriptions_guard BEFORE INSERT OR UPDATE OR DELETE ON surgery_descriptions
                FOR EACH ROW EXECUTE FUNCTION surgery_description_guard();
            CREATE TRIGGER surgery_descriptions_no_truncate BEFORE TRUNCATE ON surgery_descriptions
                FOR EACH STATEMENT EXECUTE FUNCTION surgery_description_guard();
        SQL);

        ClinicalSchema::protect('surgery_descriptions');
    }

    public function down(): void
    {
        // As in step 5b.4: with row security off the check sees every row or fails, never an empty table by mistake.
        DB::transaction(function () {
            DB::statement('SET LOCAL row_security = off');
            if (DB::table('surgery_descriptions')->exists() || DB::table('surgeries')->where('status', 'completed')->exists()) {
                throw new RuntimeException('Surgical descriptions exist and are never deleted: this migration cannot be rolled back.');
            }
        });
        Schema::dropIfExists('surgery_descriptions');
        DB::unprepared('DROP FUNCTION IF EXISTS surgery_description_guard()');
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_values_check');
        Schema::table('surgeries', fn (Blueprint $table) => $table->dropColumn('completed_at'));
        DB::statement(self::valuesCheck(false));
    }

    private static function valuesCheck(bool $completed): string
    {
        $statuses = $completed ? "'requested', 'scheduled', 'in_progress', 'completed', 'cancelled'" : "'requested', 'scheduled', 'in_progress', 'cancelled'";
        $booked = $completed ? "status NOT IN ('scheduled', 'in_progress', 'completed')" : "status NOT IN ('scheduled', 'in_progress')";
        $start = $completed
            ? "AND ((status IN ('in_progress', 'completed')) = (started_at IS NOT NULL)) AND ((status = 'completed') = (completed_at IS NOT NULL))"
            : "AND ((status = 'in_progress') = (started_at IS NOT NULL))";

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
