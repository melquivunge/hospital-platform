<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.6: transfer between units of the network. requested → accepted (bed reserved at the destination)
     * → departed (the admission is in transit, without a bed) → completed (the same admission now belongs to
     * the destination). Refusal and cancellation keep the reason and the author. Clinical (RLS).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT admissions_values_check');
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_values_check CHECK (
            status IN ('requested', 'admitted', 'in_transit')
            AND (status IN ('requested', 'in_transit')) = (bed_id IS NULL)
            AND (status = 'requested') = (admitted_at IS NULL)
            AND (admitted_at IS NULL) = (admitted_by_user_id IS NULL)
            AND (compatibility_justification IS NULL OR length(btrim(compatibility_justification)) > 0)
        )");
        DB::statement('DROP INDEX admissions_one_active_per_patient');
        DB::statement("CREATE UNIQUE INDEX admissions_one_active_per_patient ON admissions (patient_id) WHERE status IN ('requested', 'admitted', 'in_transit')");

        Schema::create('admission_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('admission_id');
            $table->unsignedBigInteger('from_unit_id');
            $table->unsignedBigInteger('from_bed_id');
            $table->unsignedBigInteger('to_unit_id');
            $table->unsignedBigInteger('requested_sector_id');
            $table->unsignedBigInteger('responsible_professional_id');
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('reserved_bed_id')->nullable();
            $table->string('compatibility_justification', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('departed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('departed_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['to_unit_id', 'status']);
            $table->index(['from_unit_id', 'status']);
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_bed_id'])->references(['tenant_id', 'id'])->on('beds')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reserved_bed_id'])->references(['tenant_id', 'id'])->on('beds')->restrictOnDelete();
            $table->foreign(['to_unit_id', 'requested_sector_id'])->references(['unit_id', 'id'])->on('sectors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'responsible_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE admission_transfers ADD CONSTRAINT admission_transfers_values_check CHECK (
            status IN ('requested', 'accepted', 'departed', 'completed', 'refused', 'cancelled')
            AND from_unit_id <> to_unit_id
            AND (status IN ('accepted', 'departed', 'completed')) = (reserved_bed_id IS NOT NULL)
            AND (status IN ('refused', 'cancelled')) = (reason IS NOT NULL AND length(btrim(reason)) > 0)
            AND (decided_at IS NULL) = (decided_by_user_id IS NULL)
            AND (status IN ('accepted', 'departed', 'completed', 'refused')) <= (decided_at IS NOT NULL)
            AND (status IN ('departed', 'completed')) = (departed_at IS NOT NULL)
            AND (departed_at IS NULL) = (departed_by_user_id IS NULL)
            AND (status = 'completed') = (received_at IS NOT NULL)
            AND (received_at IS NULL) = (received_by_user_id IS NULL)
        )");
        // One open transfer per admission; a reserved bed serves one transfer.
        DB::statement("CREATE UNIQUE INDEX admission_transfers_one_open ON admission_transfers (admission_id) WHERE status IN ('requested', 'accepted', 'departed')");
        DB::statement("CREATE UNIQUE INDEX admission_transfers_one_reservation ON admission_transfers (reserved_bed_id) WHERE status IN ('accepted', 'departed')");
        ClinicalSchema::protect('admission_transfers');
    }

    public function down(): void
    {
        if (DB::table('admission_transfers')->whereIn('status', ['requested', 'accepted', 'departed'])->exists()) {
            throw new RuntimeException('Open transfers between units (reserved beds, patients in transit): finish or cancel them before rolling back.');
        }
        Schema::dropIfExists('admission_transfers');
        DB::statement('DROP INDEX admissions_one_active_per_patient');
        DB::statement("CREATE UNIQUE INDEX admissions_one_active_per_patient ON admissions (patient_id) WHERE status IN ('requested', 'admitted')");
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT admissions_values_check');
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_values_check CHECK (
            status IN ('requested', 'admitted')
            AND (status = 'requested') = (bed_id IS NULL)
            AND (bed_id IS NULL) = (admitted_at IS NULL)
            AND (admitted_at IS NULL) = (admitted_by_user_id IS NULL)
            AND (compatibility_justification IS NULL OR length(btrim(compatibility_justification)) > 0)
        )");
    }
};
