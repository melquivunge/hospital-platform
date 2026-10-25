<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5b.6b2: the initial balance of materials is imported by CSV too, by the unit's pharmacy.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures', 'materials', 'material_stock'))");
    }

    public function down(): void
    {
        if (DB::table('import_batches')->where('type', 'material_stock')->exists()) {
            throw new RuntimeException('Material stock imports exist: rolling back would leave batches of an unknown type.');
        }
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures', 'materials'))");
    }
};
