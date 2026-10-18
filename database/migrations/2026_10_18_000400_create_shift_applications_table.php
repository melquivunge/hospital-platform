<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b: applications to open vacancies (V1-07). An application is never an assignment: the
     * coordinator selects one with a reason, and only then the shift gets its professional.
     */
    public function up(): void
    {
        Schema::create('shift_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('professional_id');
            $table->string('status');
            $table->unsignedInteger('eligibility_version');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampsTz();

            $table->index(['shift_id', 'status']);
            $table->index(['professional_id', 'status']);
            $table->foreign(['tenant_id', 'shift_id'])->references(['tenant_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE shift_applications ADD CONSTRAINT shift_applications_status_check CHECK (status IN ('active', 'withdrawn', 'selected', 'not_selected'))");
        DB::statement("ALTER TABLE shift_applications ADD CONSTRAINT shift_applications_selected_check CHECK (
            status <> 'selected' OR (decided_by_user_id IS NOT NULL AND decision_reason IS NOT NULL)
        )");
        // One active application per professional and vacancy; one winner per vacancy.
        DB::statement("CREATE UNIQUE INDEX shift_applications_one_active ON shift_applications (shift_id, professional_id) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX shift_applications_one_selected ON shift_applications (shift_id) WHERE status = 'selected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_applications');
    }
};
