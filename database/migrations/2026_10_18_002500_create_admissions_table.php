<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.4a: the admission request (from the emergency visit: requested sector, responsible doctor,
     * isolation) and the bed allocated by bed management. The database refuses two patients in one bed and
     * two active admissions of one patient. Patient data: clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('emergency_episode_id')->nullable()->unique();
            $table->unsignedBigInteger('requested_sector_id');
            $table->unsignedBigInteger('responsible_professional_id');
            $table->boolean('isolation')->default(false);
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('bed_id')->nullable();
            $table->string('compatibility_justification', 500)->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignId('admitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('admitted_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'status']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'emergency_episode_id'])->references(['tenant_id', 'id'])->on('emergency_episodes')->restrictOnDelete();
            $table->foreign(['unit_id', 'requested_sector_id'])->references(['unit_id', 'id'])->on('sectors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'responsible_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'bed_id'])->references(['tenant_id', 'id'])->on('beds')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_values_check CHECK (
            status IN ('requested', 'admitted')
            AND (status = 'requested') = (bed_id IS NULL)
            AND (bed_id IS NULL) = (admitted_at IS NULL)
            AND (admitted_at IS NULL) = (admitted_by_user_id IS NULL)
            AND (compatibility_justification IS NULL OR length(btrim(compatibility_justification)) > 0)
        )");
        // One active admission per patient, one patient per bed.
        DB::statement("CREATE UNIQUE INDEX admissions_one_active_per_patient ON admissions (patient_id) WHERE status IN ('requested', 'admitted')");
        DB::statement("CREATE UNIQUE INDEX admissions_one_patient_per_bed ON admissions (bed_id) WHERE status = 'admitted'");
        ClinicalSchema::protect('admissions');

        // The episode leaves the emergency department when the patient gets the bed.
        DB::statement('ALTER TABLE emergency_episodes DROP CONSTRAINT emergency_episodes_values_check');
        DB::statement("ALTER TABLE emergency_episodes ADD CONSTRAINT emergency_episodes_values_check CHECK (
            status IN ('waiting', 'in_care', 'admission_requested', 'admitted', 'discharged', 'evaded')
            AND (current_level IS NULL OR current_level BETWEEN 1 AND 5)
            AND (current_level IS NULL) = (triaged_at IS NULL)
            AND (status IN ('waiting', 'in_care')) = (ended_at IS NULL)
            AND (ended_at IS NULL) = (ended_by_user_id IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement("UPDATE emergency_episodes SET status = 'admission_requested' WHERE status = 'admitted'");
        DB::statement('ALTER TABLE emergency_episodes DROP CONSTRAINT emergency_episodes_values_check');
        DB::statement("ALTER TABLE emergency_episodes ADD CONSTRAINT emergency_episodes_values_check CHECK (
            status IN ('waiting', 'in_care', 'admission_requested', 'discharged', 'evaded')
            AND (current_level IS NULL OR current_level BETWEEN 1 AND 5)
            AND (current_level IS NULL) = (triaged_at IS NULL)
            AND (status IN ('waiting', 'in_care')) = (ended_at IS NULL)
            AND (ended_at IS NULL) = (ended_by_user_id IS NULL)
        )");
        Schema::dropIfExists('admissions');
    }
};
