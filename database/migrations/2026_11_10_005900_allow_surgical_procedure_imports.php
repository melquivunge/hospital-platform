<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5b.1b: the surgical procedures imported by CSV, as the exam catalog.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures'))");
    }

    public function down(): void
    {
        if (DB::table('import_batches')->where('type', 'surgical_procedures')->exists()) {
            throw new RuntimeException('Surgical procedure imports exist: rolling back would leave batches of an unknown type.');
        }
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes'))");
    }
};
