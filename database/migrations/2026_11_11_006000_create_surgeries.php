<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.2 (delivery 5b): the surgery, born from the order of the professional who attends an open visit or an
     * active admission and is qualified in the unit, signed with the password. Keeps the procedure's name as ordered.
     * Cancelled by its author, with a reason, before it starts. Scheduling, team and checklist come in the next
     * steps and widen the status. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('surgeries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('admission_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('surgical_procedure_id');
            // As ordered: a later change of the catalog does not rewrite the order.
            $table->string('procedure_name', 255);
            $table->string('laterality', 10);
            $table->string('urgency', 10);
            $table->date('desired_date');
            $table->string('indication', 1000);
            $table->string('status', 15)->default('requested');
            $table->string('signature_method', 20);
            $table->timestampTz('created_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['unit_id', 'status']);
            $table->index(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'admission_id']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'surgical_procedure_id'])->references(['tenant_id', 'id'])->on('surgical_procedures')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE surgeries ADD CONSTRAINT surgeries_values_check CHECK (
            (admission_id IS NULL) <> (visit_id IS NULL)
            AND laterality IN ('none', 'left', 'right', 'bilateral')
            AND urgency IN ('elective', 'urgent')
            AND length(btrim(procedure_name)) > 0
            AND length(btrim(indication)) > 0
            AND signature_method IN ('password')
            AND ((status = 'requested' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND length(btrim(coalesce(cancellation_reason, ''))) > 0))
        )");

        ClinicalSchema::protect('surgeries');
    }

    public function down(): void
    {
        if (DB::table('surgeries')->exists()) {
            throw new RuntimeException('Surgeries exist and are never deleted: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('surgeries');
    }
};
