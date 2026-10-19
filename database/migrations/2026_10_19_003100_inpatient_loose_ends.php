<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.9 (decisions after the security review of 4.8):
     * - an admission still waiting for a bed, or in transit, can be discharged (no bed, maybe never admitted);
     * - a transfer cancelled by that discharge keeps its departure time;
     * - job functions say whether they attend patients (call from the emergency queue). True by default, so
     *   nothing changes until the network admin unmarks a function.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT admissions_values_check');
        DB::statement("ALTER TABLE admissions ADD CONSTRAINT admissions_values_check CHECK (
            status IN ('requested', 'admitted', 'in_transit', 'discharged')
            AND (status <> 'admitted' OR bed_id IS NOT NULL)
            AND (status NOT IN ('requested', 'in_transit') OR bed_id IS NULL)
            AND (status <> 'requested' OR admitted_at IS NULL)
            AND (status NOT IN ('admitted', 'in_transit') OR admitted_at IS NOT NULL)
            AND (admitted_at IS NULL) = (admitted_by_user_id IS NULL)
            AND (compatibility_justification IS NULL OR length(btrim(compatibility_justification)) > 0)
            AND (status = 'discharged') = (discharged_at IS NOT NULL)
            AND (discharged_at IS NULL) = (discharged_by_user_id IS NULL)
            AND (discharged_at IS NULL) = (discharge_type IS NULL)
            AND (discharge_type IS NULL OR discharge_type IN ('improved', 'at_request', 'transfer_out', 'death', 'evasion'))
            AND COALESCE(discharge_type IN ('improved', 'at_request', 'transfer_out'), false) = (discharge_summary_id IS NOT NULL)
            AND (discharged_at IS NULL OR discharged_at >= COALESCE(admitted_at, requested_at))
        )");

        DB::statement('ALTER TABLE admission_transfers DROP CONSTRAINT admission_transfers_values_check');
        DB::statement("ALTER TABLE admission_transfers ADD CONSTRAINT admission_transfers_values_check CHECK (
            status IN ('requested', 'accepted', 'departed', 'completed', 'refused', 'cancelled')
            AND from_unit_id <> to_unit_id
            AND (status IN ('accepted', 'departed', 'completed')) = (reserved_bed_id IS NOT NULL)
            AND (status IN ('refused', 'cancelled')) = (reason IS NOT NULL AND length(btrim(reason)) > 0)
            AND (decided_at IS NULL) = (decided_by_user_id IS NULL)
            AND (status IN ('accepted', 'departed', 'completed', 'refused')) <= (decided_at IS NOT NULL)
            AND (status IN ('departed', 'completed')) <= (departed_at IS NOT NULL)
            AND (status IN ('requested', 'accepted', 'refused')) <= (departed_at IS NULL)
            AND (departed_at IS NULL) = (departed_by_user_id IS NULL)
            AND (status = 'completed') = (received_at IS NOT NULL)
            AND (received_at IS NULL) = (received_by_user_id IS NULL)
        )");

        Schema::table('job_functions', function (Blueprint $table) {
            $table->boolean('attends_patients')->default(true);
        });
    }

    public function down(): void
    {
        if (DB::table('admissions')->where('status', 'discharged')->whereNull('bed_id')->exists()
            || DB::table('admission_transfers')->where('status', 'cancelled')->whereNotNull('departed_at')->exists()) {
            throw new RuntimeException('Discharges without a bed or transfers cancelled in transit exist: rolling back would break them.');
        }
        Schema::table('job_functions', function (Blueprint $table) {
            $table->dropColumn('attends_patients');
        });
        DB::statement('ALTER TABLE admission_transfers DROP CONSTRAINT admission_transfers_values_check');
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
};
