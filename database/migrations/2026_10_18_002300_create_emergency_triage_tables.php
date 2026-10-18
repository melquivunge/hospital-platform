<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.2: arrival at the emergency department and risk classification on the network's own 5-level
     * scale (user decision, 18/10/2026: no licensed Manchester content). Unidentified patients are
     * registered with sex, apparent age and a description and merged later. Episodes and classifications
     * carry patient data: clinical (RLS). Classifications are insert-only; a reclassification links back.
     */
    public function up(): void
    {
        Schema::create('triage_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('name', 50);
            $table->string('color', 10);
            $table->unsignedSmallInteger('target_minutes');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'level']);
        });
        DB::statement("ALTER TABLE triage_levels ADD CONSTRAINT triage_levels_values_check CHECK (
            level BETWEEN 1 AND 5 AND color IN ('red', 'orange', 'yellow', 'green', 'blue') AND target_minutes <= 1440
        )");

        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('unidentified')->default(false);
            $table->unsignedSmallInteger('apparent_age')->nullable();
            $table->string('description', 500)->nullable();
        });
        DB::statement('ALTER TABLE patients ADD CONSTRAINT patients_unidentified_check CHECK (
            unidentified OR (apparent_age IS NULL AND description IS NULL)
        )');

        Schema::create('emergency_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('status', 20)->default('waiting');
            $table->unsignedSmallInteger('current_level')->nullable();
            $table->timestampTz('arrived_at');
            $table->foreignId('arrived_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('triaged_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'status']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE emergency_episodes ADD CONSTRAINT emergency_episodes_values_check CHECK (
            status IN ('waiting', 'closed')
            AND (current_level IS NULL OR current_level BETWEEN 1 AND 5)
            AND (current_level IS NULL) = (triaged_at IS NULL)
        )");
        // One open emergency episode per patient in the network.
        DB::statement("CREATE UNIQUE INDEX emergency_episodes_one_open ON emergency_episodes (patient_id) WHERE status <> 'closed'");

        Schema::create('triages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('episode_id');
            $table->unsignedBigInteger('previous_id')->nullable()->unique();
            $table->unsignedSmallInteger('level');
            $table->string('complaint', 500);
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedSmallInteger('spo2')->nullable();
            $table->unsignedSmallInteger('pain')->nullable();
            $table->unsignedSmallInteger('glucose')->nullable();
            $table->unsignedBigInteger('professional_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['episode_id', 'id']);
            $table->foreign(['tenant_id', 'episode_id'])->references(['tenant_id', 'id'])->on('emergency_episodes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'previous_id'])->references(['tenant_id', 'id'])->on('triages')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE triages ADD CONSTRAINT triages_values_check CHECK (
            level BETWEEN 1 AND 5 AND length(btrim(complaint)) > 0
            AND (systolic IS NULL OR systolic BETWEEN 30 AND 300) AND (diastolic IS NULL OR diastolic BETWEEN 10 AND 200)
            AND (heart_rate IS NULL OR heart_rate BETWEEN 10 AND 300) AND (respiratory_rate IS NULL OR respiratory_rate BETWEEN 2 AND 80)
            AND (temperature IS NULL OR temperature BETWEEN 25 AND 45) AND (spo2 IS NULL OR spo2 BETWEEN 30 AND 100)
            AND (pain IS NULL OR pain BETWEEN 0 AND 10) AND (glucose IS NULL OR glucose BETWEEN 10 AND 1500)
            AND previous_id IS DISTINCT FROM id
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION triages_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'risk classifications are insert-only' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER triages_insert_only BEFORE UPDATE OR DELETE ON triages
                FOR EACH ROW EXECUTE FUNCTION triages_insert_only();
            CREATE TRIGGER triages_no_truncate BEFORE TRUNCATE ON triages
                FOR EACH STATEMENT EXECUTE FUNCTION triages_insert_only();
        SQL);

        ClinicalSchema::protect('emergency_episodes');
        ClinicalSchema::protect('triages');
    }

    public function down(): void
    {
        Schema::dropIfExists('triages');
        DB::unprepared('DROP FUNCTION IF EXISTS triages_insert_only()');
        Schema::dropIfExists('emergency_episodes');
        DB::statement('ALTER TABLE patients DROP CONSTRAINT patients_unidentified_check');
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['unidentified', 'apparent_age', 'description']);
        });
        Schema::dropIfExists('triage_levels');
    }
};
