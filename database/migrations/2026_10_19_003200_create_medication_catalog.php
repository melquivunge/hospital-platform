<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.1a: the network's medication catalog and its safety rules (maximum doses on each
     * medication; interactions between active ingredients, so they hold for every presentation). Kept by the
     * network admin; no patient data, so no RLS. Deactivated, never deleted: prescriptions will refer to them.
     */
    public function up(): void
    {
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('active_ingredient', 255);
            $table->string('ingredient_key', 255);
            $table->string('form', 100);
            $table->string('strength', 100);
            $table->string('dose_unit', 20);
            $table->jsonb('routes');
            $table->boolean('controlled')->default(false);
            $table->boolean('high_alert')->default(false);
            $table->decimal('max_single_dose', 12, 3)->nullable();
            $table->decimal('max_daily_dose', 12, 3)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'ingredient_key']);
        });
        DB::statement('CREATE UNIQUE INDEX medications_presentation_unique ON medications (tenant_id, ingredient_key, lower(form), lower(strength))');
        DB::statement("ALTER TABLE medications ADD CONSTRAINT medications_values_check CHECK (
            length(btrim(active_ingredient)) > 0 AND length(btrim(form)) > 0 AND length(btrim(strength)) > 0
            AND dose_unit IN ('mg', 'mcg', 'g', 'mL', 'UI', 'gota', 'unidade', 'aplicação', 'jato')
            AND jsonb_typeof(routes) = 'array' AND jsonb_array_length(routes) > 0
            AND (max_single_dose IS NULL OR max_single_dose > 0)
            AND (max_daily_dose IS NULL OR max_daily_dose > 0)
            AND (max_single_dose IS NULL OR max_daily_dose IS NULL OR max_single_dose <= max_daily_dose)
        )");

        Schema::create('medication_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('ingredient_a', 255);
            $table->string('ingredient_b', 255);
            $table->string('severity', 20);
            $table->string('note', 500);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'ingredient_a', 'ingredient_b']);
            $table->index(['tenant_id', 'ingredient_b']);
        });
        // One row per pair, stored in order (a < b), so "x with y" and "y with x" are the same rule.
        DB::statement("ALTER TABLE medication_interactions ADD CONSTRAINT medication_interactions_values_check CHECK (
            ingredient_a COLLATE \"C\" < ingredient_b COLLATE \"C\"
            AND severity IN ('mild', 'moderate', 'severe', 'contraindicated')
            AND length(btrim(note)) > 0
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_interactions');
        Schema::dropIfExists('medications');
    }
};
