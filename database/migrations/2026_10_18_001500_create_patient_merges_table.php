<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.3: a duplicate registration is never deleted. It is marked merged and points to the primary
     * one; each merge (and its undoing) is a decision with author and reason (user decision, 18/10/2026).
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->foreign(['tenant_id', 'merged_into_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE patients DROP CONSTRAINT patients_status_check');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_status_check CHECK (
            (status = 'merged') = (merged_into_id IS NOT NULL)
            AND (status <> 'complete' OR ((cpf IS NOT NULL OR cns IS NOT NULL) AND birth_date IS NOT NULL AND sex IS NOT NULL))
            AND merged_into_id IS DISTINCT FROM id
        )");

        Schema::create('patient_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('primary_patient_id');
            $table->unsignedBigInteger('duplicate_patient_id');
            $table->text('reason');
            $table->foreignId('merged_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('merged_at');
            $table->text('undo_reason')->nullable();
            $table->foreignId('undone_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('undone_at')->nullable();

            $table->index(['tenant_id', 'primary_patient_id']);
            $table->foreign(['tenant_id', 'primary_patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'duplicate_patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE patient_merges ADD CONSTRAINT patient_merges_undo_check CHECK ((undone_at IS NULL) = (undone_by_user_id IS NULL) AND (undone_at IS NULL) = (undo_reason IS NULL))');
        // A registration is merged into one primary at a time.
        DB::statement('CREATE UNIQUE INDEX patient_merges_one_active ON patient_merges (duplicate_patient_id) WHERE undone_at IS NULL');

        ClinicalSchema::protect('patient_merges');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merges');
        DB::statement('ALTER TABLE patients DROP CONSTRAINT patients_status_check');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_status_check CHECK (
            (status = 'complete' AND (cpf IS NOT NULL OR cns IS NOT NULL) AND birth_date IS NOT NULL AND sex IS NOT NULL)
            OR status = 'provisional'
        )");
        Schema::table('patients', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'merged_into_id']);
            $table->dropColumn('merged_into_id');
        });
    }
};
