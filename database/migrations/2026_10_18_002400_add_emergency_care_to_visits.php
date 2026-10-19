<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 4.3: a visit is born from an appointment or from an emergency episode (exactly one), and the
     * episode records its outcome: discharge from the emergency department, admission request or evasion,
     * each with time and author. The visit guard now compares with IS DISTINCT FROM: with a nullable
     * column, `<>` would let a change through.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE visits ALTER COLUMN appointment_id DROP NOT NULL');
        DB::statement('ALTER TABLE visits ADD COLUMN emergency_episode_id bigint');
        DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_emergency_episode_unique UNIQUE (emergency_episode_id)');
        DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_emergency_episode_foreign FOREIGN KEY (tenant_id, emergency_episode_id) REFERENCES emergency_episodes (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_origin_check CHECK ((appointment_id IS NULL) <> (emergency_episode_id IS NULL))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION visits_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'visits are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.closed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'a closed visit is never reopened nor changed' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.unit_id IS DISTINCT FROM OLD.unit_id
                    OR NEW.appointment_id IS DISTINCT FROM OLD.appointment_id OR NEW.emergency_episode_id IS DISTINCT FROM OLD.emergency_episode_id
                    OR NEW.professional_id IS DISTINCT FROM OLD.professional_id OR NEW.patient_id IS DISTINCT FROM OLD.patient_id
                    OR NEW.opened_by_user_id IS DISTINCT FROM OLD.opened_by_user_id OR NEW.opened_at IS DISTINCT FROM OLD.opened_at THEN
                    RAISE EXCEPTION 'a visit keeps its patient, professional and origin' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
        SQL);

        DB::statement('ALTER TABLE emergency_episodes ADD COLUMN ended_at timestamptz');
        DB::statement('ALTER TABLE emergency_episodes ADD COLUMN ended_by_user_id bigint REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE emergency_episodes DROP CONSTRAINT emergency_episodes_values_check');
        // Dropped before the conversion below: the old index ignored only 'closed'.
        DB::statement('DROP INDEX emergency_episodes_one_open');
        // 'closed' existed only in local test data before this step.
        DB::statement("UPDATE emergency_episodes SET status = 'discharged', ended_at = arrived_at, ended_by_user_id = arrived_by_user_id WHERE status = 'closed'");
        DB::statement("ALTER TABLE emergency_episodes ADD CONSTRAINT emergency_episodes_values_check CHECK (
            status IN ('waiting', 'in_care', 'admission_requested', 'discharged', 'evaded')
            AND (current_level IS NULL OR current_level BETWEEN 1 AND 5)
            AND (current_level IS NULL) = (triaged_at IS NULL)
            AND (status IN ('waiting', 'in_care')) = (ended_at IS NULL)
            AND (ended_at IS NULL) = (ended_by_user_id IS NULL)
        )");
        // One open episode per patient: still in the department, including while waiting for a bed.
        DB::statement("CREATE UNIQUE INDEX emergency_episodes_one_open ON emergency_episodes (patient_id) WHERE status IN ('waiting', 'in_care', 'admission_requested')");
    }

    public function down(): void
    {
        if (DB::table('visits')->whereNotNull('emergency_episode_id')->exists()) {
            throw new RuntimeException('Emergency visits exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('DROP INDEX emergency_episodes_one_open');
        DB::statement('ALTER TABLE emergency_episodes DROP CONSTRAINT emergency_episodes_values_check');
        DB::statement("UPDATE emergency_episodes SET status = 'closed' WHERE status <> 'waiting'");
        DB::statement('ALTER TABLE emergency_episodes DROP COLUMN ended_by_user_id, DROP COLUMN ended_at');
        DB::statement("ALTER TABLE emergency_episodes ADD CONSTRAINT emergency_episodes_values_check CHECK (
            status IN ('waiting', 'closed')
            AND (current_level IS NULL OR current_level BETWEEN 1 AND 5)
            AND (current_level IS NULL) = (triaged_at IS NULL)
        )");
        DB::statement("CREATE UNIQUE INDEX emergency_episodes_one_open ON emergency_episodes (patient_id) WHERE status <> 'closed'");

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
        SQL);
        DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_origin_check');
        DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_emergency_episode_foreign');
        DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_emergency_episode_unique');
        DB::statement('ALTER TABLE visits DROP COLUMN emergency_episode_id');
        DB::statement('ALTER TABLE visits ALTER COLUMN appointment_id SET NOT NULL');
    }
};
