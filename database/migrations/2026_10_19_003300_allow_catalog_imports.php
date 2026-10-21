<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 4b.1b: the medication catalog and its interactions are imported by CSV too.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions'))");
    }

    public function down(): void
    {
        if (DB::table('import_batches')->whereIn('type', ['medications', 'medication_interactions'])->exists()) {
            throw new RuntimeException('Catalog imports exist: rolling back would leave batches of an unknown type.');
        }
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts'))");
    }
};
