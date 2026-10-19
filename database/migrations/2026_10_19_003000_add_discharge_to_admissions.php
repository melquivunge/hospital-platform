<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.7: the discharge ends the admission. Improvement, at request and transfer out of the network
     * need a signed summary (a clinical note of the admission); death and evasion record the time and the
     * author. The admission keeps its last bed, so the census knows the sector of each stay.
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->string('discharge_type', 20)->nullable();
            $table->timestampTz('discharged_at')->nullable();
            $table->foreignId('discharged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('discharge_summary_id')->nullable()->unique();
            $table->foreign(['tenant_id', 'discharge_summary_id'])->references(['tenant_id', 'id'])->on('clinical_notes')->restrictOnDelete();
            $table->index(['unit_id', 'discharged_at']);
        });
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT admissions_values_check');
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_values_check CHECK (
            status IN ('requested', 'admitted', 'in_transit', 'discharged')
            AND (status IN ('requested', 'in_transit')) = (bed_id IS NULL)
            AND (status = 'requested') = (admitted_at IS NULL)
            AND (admitted_at IS NULL) = (admitted_by_user_id IS NULL)
            AND (compatibility_justification IS NULL OR length(btrim(compatibility_justification)) > 0)
            AND (status = 'discharged') = (discharged_at IS NOT NULL)
            AND (discharged_at IS NULL) = (discharged_by_user_id IS NULL)
            AND (discharged_at IS NULL) = (discharge_type IS NULL)
            AND (discharge_type IS NULL OR discharge_type IN ('improved', 'at_request', 'transfer_out', 'death', 'evasion'))
            AND COALESCE(discharge_type IN ('improved', 'at_request', 'transfer_out'), false) = (discharge_summary_id IS NOT NULL)
            AND (discharged_at IS NULL OR discharged_at >= admitted_at)
        )");
    }

    public function down(): void
    {
        if (DB::table('admissions')->where('status', 'discharged')->exists()) {
            throw new RuntimeException('Discharged admissions exist: rolling back would lose the discharge.');
        }
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT admissions_values_check');
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_values_check CHECK (
            status IN ('requested', 'admitted', 'in_transit')
            AND (status IN ('requested', 'in_transit')) = (bed_id IS NULL)
            AND (status = 'requested') = (admitted_at IS NULL)
            AND (admitted_at IS NULL) = (admitted_by_user_id IS NULL)
            AND (compatibility_justification IS NULL OR length(btrim(compatibility_justification)) > 0)
        )");
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'discharge_summary_id']);
            $table->dropColumn(['discharge_type', 'discharged_at', 'discharged_by_user_id', 'discharge_summary_id']);
        });
    }
};
