<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6c: the responsible professional announces their own shift for replacement (V1-07). Announcing
     * never releases the responsible: the shift changes hands only when the coordinator selects a candidate.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestampTz('announced_at')->nullable();
        });

        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_announced_check CHECK (
            announced_at IS NULL OR (professional_id IS NOT NULL AND status = 'published')
        )");

        // A winner replaced through an announcement leaves the single "selected" slot of the shift.
        DB::statement('ALTER TABLE shift_applications DROP CONSTRAINT shift_applications_status_check');
        DB::statement("ALTER TABLE shift_applications ADD CONSTRAINT shift_applications_status_check CHECK (status IN ('active', 'withdrawn', 'selected', 'not_selected', 'replaced'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE shift_applications SET status = 'not_selected' WHERE status = 'replaced'");
        DB::statement('ALTER TABLE shift_applications DROP CONSTRAINT shift_applications_status_check');
        DB::statement("ALTER TABLE shift_applications ADD CONSTRAINT shift_applications_status_check CHECK (status IN ('active', 'withdrawn', 'selected', 'not_selected'))");
        DB::statement('ALTER TABLE shifts DROP CONSTRAINT shifts_announced_check');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('announced_at');
        });
    }
};
