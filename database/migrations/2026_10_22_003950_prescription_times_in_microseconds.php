<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix found in the step 4b.4 browser check: a suspension and the next draft within the same second looked
     * simultaneous (columns rounded to the second), so the draft asked to justify a suspension made before it.
     * The two times the rule compares keep microseconds. Widening the precision rewrites nothing; old rows keep
     * their whole seconds.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN created_at TYPE timestamptz(6)');
        DB::statement('ALTER TABLE prescription_items ALTER COLUMN suspended_at TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN created_at TYPE timestamptz(0)');
        DB::statement('ALTER TABLE prescription_items ALTER COLUMN suspended_at TYPE timestamptz(0)');
    }
};
