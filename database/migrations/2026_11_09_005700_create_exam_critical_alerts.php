<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5.5 (ADR-0008): a result released as critical opens an alert. Whoever ordered it, or attends the patient,
     * acknowledges it once; without that in 1 hour the unit manager hears it, once. Never deleted; each mark is set
     * once and never undone. Clinical (RLS). Notifications gain an urgent flag, shown highlighted.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE user_notifications ADD COLUMN urgent boolean NOT NULL DEFAULT false');
        // A column with a default: no row is rewritten, so the immutability trigger is not involved.
        DB::statement('ALTER TABLE exam_results ADD COLUMN critical boolean NOT NULL DEFAULT false');

        Schema::create('exam_critical_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('exam_result_id');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('escalated_at')->nullable();

            $table->unique('exam_result_id');
            $table->index(['unit_id', 'acknowledged_at']);
            $table->foreign(['tenant_id', 'exam_result_id'])->references(['tenant_id', 'id'])->on('exam_results')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE exam_critical_alerts ADD CONSTRAINT exam_critical_alerts_values_check CHECK ((acknowledged_at IS NULL) = (acknowledged_by_user_id IS NULL))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_critical_alerts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE'
                    OR (NEW.id, NEW.tenant_id, NEW.exam_result_id, NEW.unit_id, NEW.created_at)
                        IS DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.exam_result_id, OLD.unit_id, OLD.created_at)
                    OR (OLD.acknowledged_at IS NOT NULL AND (NEW.acknowledged_at, NEW.acknowledged_by_user_id) IS DISTINCT FROM (OLD.acknowledged_at, OLD.acknowledged_by_user_id))
                    OR (OLD.escalated_at IS NOT NULL AND NEW.escalated_at IS DISTINCT FROM OLD.escalated_at) THEN
                    RAISE EXCEPTION 'a critical alert is acknowledged and escalated once, never undone' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER exam_critical_alerts_guard BEFORE UPDATE OR DELETE ON exam_critical_alerts
                FOR EACH ROW EXECUTE FUNCTION exam_critical_alerts_guard();
            CREATE TRIGGER exam_critical_alerts_no_truncate BEFORE TRUNCATE ON exam_critical_alerts
                FOR EACH STATEMENT EXECUTE FUNCTION exam_critical_alerts_guard();
        SQL);
        ClinicalSchema::protect('exam_critical_alerts');
    }

    public function down(): void
    {
        if (DB::table('exam_critical_alerts')->exists() || DB::table('exam_results')->where('critical', true)->exists()) {
            throw new RuntimeException('Critical results exist and are never deleted: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('exam_critical_alerts');
        DB::unprepared('DROP FUNCTION IF EXISTS exam_critical_alerts_guard()');
        DB::statement('ALTER TABLE exam_results DROP COLUMN critical');
        DB::statement('ALTER TABLE user_notifications DROP COLUMN urgent');
    }
};
