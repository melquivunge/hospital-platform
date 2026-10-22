<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.4 (ADR-0005): the ward team records each scheduled dose once, as administered (real time), refused
     * by the patient or not done (with a reason), and each dose of an "if needed" item as administered. An item
     * not dispensed is administered only as an exception with a reason; controlled and high-alert ones need a
     * second person (witness). Insert-only. Clinical (RLS).
     */
    public function up(): void
    {
        // The dose and its item travel together (the record cannot name a dose of another item).
        Schema::table('prescription_doses', fn (Blueprint $table) => $table->unique(['tenant_id', 'id', 'prescription_item_id']));
        Schema::create('medication_administrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('prescription_item_id');
            $table->unsignedBigInteger('prescription_dose_id')->nullable()->unique();
            $table->string('outcome', 20);
            $table->timestampTz('occurred_at');
            $table->string('reason', 255)->nullable();
            $table->boolean('exception')->default(false);
            $table->foreignId('witness_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['prescription_item_id', 'occurred_at']);
            $table->foreign(['tenant_id', 'prescription_item_id'])->references(['tenant_id', 'id'])->on('prescription_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'prescription_dose_id', 'prescription_item_id'])->references(['tenant_id', 'id', 'prescription_item_id'])->on('prescription_doses')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE medication_administrations ADD CONSTRAINT medication_administrations_values_check CHECK (
            outcome IN ('administered', 'refused', 'not_done')
            AND (outcome = 'administered' OR (reason IS NOT NULL AND NOT exception AND witness_user_id IS NULL))
            AND (NOT exception OR reason IS NOT NULL)
            AND (prescription_dose_id IS NOT NULL OR outcome = 'administered')
            AND witness_user_id IS DISTINCT FROM recorded_by_user_id
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION medication_administrations_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'an administration record never changes' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER medication_administrations_guard BEFORE UPDATE OR DELETE ON medication_administrations
                FOR EACH ROW EXECUTE FUNCTION medication_administrations_guard();
            CREATE TRIGGER medication_administrations_no_truncate BEFORE TRUNCATE ON medication_administrations
                FOR EACH STATEMENT EXECUTE FUNCTION medication_administrations_guard();
        SQL);

        ClinicalSchema::protect('medication_administrations');
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_administrations');
        DB::unprepared('DROP FUNCTION IF EXISTS medication_administrations_guard()');
        Schema::table('prescription_doses', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'id', 'prescription_item_id']));
    }
};
