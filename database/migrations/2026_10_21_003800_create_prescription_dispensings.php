<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.3b (ADR-0005): the pharmacy dispenses an approved item once, for the 24 hours of the prescription,
     * and records the return of what came back, once. Nothing else changes. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('prescription_dispensings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_item_id')->unique();
            $table->unsignedSmallInteger('quantity');
            $table->foreignId('dispensed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unsignedSmallInteger('returned_quantity')->nullable();
            $table->string('return_reason', 255)->nullable();
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('returned_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['tenant_id', 'prescription_item_id'])->references(['tenant_id', 'id'])->on('prescription_items')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE prescription_dispensings ADD CONSTRAINT prescription_dispensings_values_check CHECK (
            quantity BETWEEN 1 AND 500
            AND (returned_at IS NULL) = (returned_quantity IS NULL) AND (returned_at IS NULL) = (return_reason IS NULL)
            AND (returned_at IS NULL) = (returned_by_user_id IS NULL)
            AND (returned_quantity IS NULL OR returned_quantity BETWEEN 1 AND quantity)
        )');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_dispensings_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Only the return, once.
                IF TG_OP <> 'UPDATE' OR OLD.returned_at IS NOT NULL OR NEW.returned_at IS NULL
                    OR (to_jsonb(NEW) - 'returned_quantity' - 'return_reason' - 'returned_by_user_id' - 'returned_at')
                        <> (to_jsonb(OLD) - 'returned_quantity' - 'return_reason' - 'returned_by_user_id' - 'returned_at') THEN
                    RAISE EXCEPTION 'a dispensing never changes but for its return, once' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER prescription_dispensings_guard BEFORE UPDATE OR DELETE ON prescription_dispensings
                FOR EACH ROW EXECUTE FUNCTION prescription_dispensings_guard();
            CREATE TRIGGER prescription_dispensings_no_truncate BEFORE TRUNCATE ON prescription_dispensings
                FOR EACH STATEMENT EXECUTE FUNCTION prescription_dispensings_guard();
        SQL);

        ClinicalSchema::protect('prescription_dispensings');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_dispensings');
        DB::unprepared('DROP FUNCTION IF EXISTS prescription_dispensings_guard()');
    }
};
