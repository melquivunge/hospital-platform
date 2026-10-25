<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.6a (ADR-0009): the network's catalog of surgical materials, separate from the medications, kept by the
     * network admin and imported by CSV. No patient data, so no RLS. Deactivated, never deleted: stock lots will
     * refer to them (step 5b.6b).
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('name_key', 255);
            // The supplier's or the hospital's reference, optional.
            $table->string('code', 30)->nullable();
            $table->string('stock_unit', 30);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name_key']);
        });
        DB::statement("ALTER TABLE materials ADD CONSTRAINT materials_values_check CHECK (
            length(btrim(name)) > 0 AND (code IS NULL OR code ~ '^[0-9A-Za-z./-]+$')
            AND stock_unit IN ('unidade', 'par', 'pacote', 'caixa', 'kit', 'rolo', 'frasco', 'envelope', 'metro')
        )");

        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures', 'materials'))");
    }

    public function down(): void
    {
        if (DB::table('import_batches')->where('type', 'materials')->exists()) {
            throw new RuntimeException('Material imports exist: rolling back would leave batches of an unknown type.');
        }
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts', 'medications', 'medication_interactions', 'stock', 'exams', 'exam_analytes', 'surgical_procedures'))");
        Schema::dropIfExists('materials');
    }
};
