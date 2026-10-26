<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.1c: prices imported by CSV, by the network admin.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures', 'materials', 'material_stock', 'prices'))");
    }

    public function down(): void
    {
        if (DB::table('import_batches')->where('type', 'prices')->exists()) {
            throw new RuntimeException('Price imports exist: rolling back would leave batches of an unknown type.');
        }
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures', 'materials', 'material_stock'))");
    }
};
