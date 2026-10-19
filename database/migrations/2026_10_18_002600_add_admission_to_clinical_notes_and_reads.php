<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 4.4b (ADR-0004): a note can belong to an admission, written by any member of the ward team (one
     * draft per author and admission), and a read can be based on the admission (reason 'admission').
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_notes ADD COLUMN admission_id bigint');
        DB::statement('ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_admission_foreign FOREIGN KEY (tenant_id, admission_id) REFERENCES admissions (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_notes DROP CONSTRAINT clinical_notes_values_check');
        // A note belongs to a visit or an admission; an addendum belongs to the note it corrects.
        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_values_check CHECK (
            status IN ('draft', 'signed') AND (status = 'signed') = (signed_at IS NOT NULL)
            AND num_nonnulls(visit_id, admission_id, addendum_of_id) = 1 AND length(btrim(body)) > 0
        )");
        DB::statement("CREATE UNIQUE INDEX clinical_notes_one_admission_draft ON clinical_notes (admission_id, author_user_id) WHERE status = 'draft' AND admission_id IS NOT NULL");
        DB::unprepared(self::guard(true));

        DB::statement('ALTER TABLE clinical_reads ADD COLUMN admission_id bigint');
        DB::statement('ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_admission_foreign FOREIGN KEY (tenant_id, admission_id) REFERENCES admissions (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
        ) NOT VALID");
    }

    public function down(): void
    {
        // The trail is insert-only and notes are never deleted: with admission data, there is no way back.
        if (DB::table('clinical_notes')->whereNotNull('admission_id')->exists() || DB::table('clinical_reads')->whereNotNull('admission_id')->exists()) {
            throw new RuntimeException('Admission notes or reads exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_admission_foreign');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN admission_id');
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
        ) NOT VALID");

        DB::unprepared(self::guard(false));
        DB::statement('DROP INDEX clinical_notes_one_admission_draft');
        DB::statement('ALTER TABLE clinical_notes DROP CONSTRAINT clinical_notes_values_check');
        DB::statement('ALTER TABLE clinical_notes DROP CONSTRAINT clinical_notes_admission_foreign');
        DB::statement('ALTER TABLE clinical_notes DROP COLUMN admission_id');
        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_values_check CHECK (
            status IN ('draft', 'signed') AND (status = 'signed') = (signed_at IS NOT NULL)
            AND (visit_id IS NULL) <> (addendum_of_id IS NULL) AND length(btrim(body)) > 0
        )");
    }

    /**
     * The note guard of step 3.6a, with the admission among the origin columns it keeps.
     *
     * @return literal-string
     */
    private static function guard(bool $withAdmission): string
    {
        $admission = $withAdmission ? 'NEW.admission_id IS DISTINCT FROM OLD.admission_id OR' : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION clinical_notes_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
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
                    OR NEW.created_at <> OLD.created_at OR NEW.visit_id IS DISTINCT FROM OLD.visit_id OR {$admission}
                    NEW.addendum_of_id IS DISTINCT FROM OLD.addendum_of_id THEN
                    RAISE EXCEPTION 'a clinical note keeps its patient, author and origin' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END \$\$;
        SQL;
    }
};
