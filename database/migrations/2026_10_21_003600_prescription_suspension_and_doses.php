<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.2b: an item of a signed prescription may be suspended once, with a reason (the only change a signed
     * item accepts), and signing schedules the doses of each regular item in the unit's timezone. Doses are
     * insert-only; administration (4b.4) will refer to them. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->timestampTz('suspended_at')->nullable();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('suspension_reason', 255)->nullable();
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement('ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_suspension_check CHECK (
            (suspended_at IS NULL) = (suspended_by_user_id IS NULL) AND (suspended_at IS NULL) = (suspension_reason IS NULL)
        )');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                parent text;
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'the items of a signed prescription never change' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.prescription_id <> OLD.prescription_id THEN
                    RAISE EXCEPTION 'the items of a signed prescription never change' USING ERRCODE = 'P0001';
                END IF;
                parent := (SELECT status FROM prescriptions WHERE id = COALESCE(NEW.prescription_id, OLD.prescription_id));
                IF parent = 'signed' THEN
                    -- Signed: only the suspension, once.
                    IF TG_OP <> 'UPDATE' OR OLD.suspended_at IS NOT NULL OR NEW.suspended_at IS NULL
                        OR (to_jsonb(NEW) - 'suspended_at' - 'suspended_by_user_id' - 'suspension_reason')
                            <> (to_jsonb(OLD) - 'suspended_at' - 'suspended_by_user_id' - 'suspension_reason') THEN
                        RAISE EXCEPTION 'the items of a signed prescription never change' USING ERRCODE = 'P0001';
                    END IF;
                ELSIF TG_OP <> 'DELETE' AND NEW.suspended_at IS NOT NULL THEN
                    RAISE EXCEPTION 'only a signed item is suspended' USING ERRCODE = 'P0001';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
        SQL);

        Schema::create('prescription_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_item_id');
            $table->timestampTz('scheduled_at');

            $table->unique(['prescription_item_id', 'scheduled_at']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'prescription_item_id'])->references(['tenant_id', 'id'])->on('prescription_items')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_doses_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'scheduled doses never change' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER prescription_doses_guard BEFORE UPDATE OR DELETE ON prescription_doses
                FOR EACH ROW EXECUTE FUNCTION prescription_doses_guard();
            CREATE TRIGGER prescription_doses_no_truncate BEFORE TRUNCATE ON prescription_doses
                FOR EACH STATEMENT EXECUTE FUNCTION prescription_doses_guard();
        SQL);

        ClinicalSchema::protect('prescription_doses');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_doses');
        DB::unprepared('DROP FUNCTION IF EXISTS prescription_doses_guard()');
        DB::statement('ALTER TABLE prescription_items DROP CONSTRAINT prescription_items_suspension_check');
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropConstrainedForeignId('suspended_by_user_id');
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
        // The previous guard: signed items never change at all.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE'
                    OR (SELECT status FROM prescriptions WHERE id = COALESCE(NEW.prescription_id, OLD.prescription_id)) = 'signed'
                    OR (TG_OP = 'UPDATE' AND NEW.prescription_id <> OLD.prescription_id) THEN
                    RAISE EXCEPTION 'the items of a signed prescription never change' USING ERRCODE = 'P0001';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
        SQL);
    }
};
