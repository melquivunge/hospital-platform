<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5.3 (ADR-0008): the network admin sets the target time of the exam queues per priority, as the risk
     * scale does for the emergency; one minute to seven days, 1 hour and 24 hours until changed.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE tenants ADD COLUMN exam_target_urgent_minutes integer NOT NULL DEFAULT 60');
        DB::statement('ALTER TABLE tenants ADD COLUMN exam_target_routine_minutes integer NOT NULL DEFAULT 1440');
        DB::statement('ALTER TABLE tenants ADD CONSTRAINT tenants_exam_targets_check CHECK (
            exam_target_urgent_minutes BETWEEN 1 AND 10080 AND exam_target_routine_minutes BETWEEN 1 AND 10080
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT tenants_exam_targets_check');
        DB::statement('ALTER TABLE tenants DROP COLUMN exam_target_routine_minutes');
        DB::statement('ALTER TABLE tenants DROP COLUMN exam_target_urgent_minutes');
    }
};
