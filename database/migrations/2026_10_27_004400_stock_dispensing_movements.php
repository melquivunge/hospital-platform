<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4c.2 (ADR-0007): the dispensing of step 4b takes from the lots (one movement per lot) and its return
     * puts back into them. The movement points only to the dispensing id; reaching the patient from it still goes
     * through the clinical access of ADR-0005 (the dispensing is clinical, RLS; foreign keys bypass it).
     */
    public function up(): void
    {
        Schema::table('prescription_dispensings', fn (Blueprint $table) => $table->unique(['tenant_id', 'id']));
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('prescription_dispensing_id')->nullable();
            // One dispensing and one return per lot of a dispensing.
            $table->unique(['prescription_dispensing_id', 'stock_lot_id', 'type']);
            $table->foreign(['tenant_id', 'prescription_dispensing_id'])->references(['tenant_id', 'id'])->on('prescription_dispensings')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            (type = 'entry' AND quantity BETWEEN 1 AND 100000 AND prescription_dispensing_id IS NULL)
            OR (type = 'dispensing' AND quantity BETWEEN -500 AND -1 AND prescription_dispensing_id IS NOT NULL)
            OR (type = 'return' AND quantity BETWEEN 1 AND 500 AND prescription_dispensing_id IS NOT NULL)
        )");
        // A return goes back only into a lot its dispensing took from, and never more than that lot gave (one return
        // per lot by the unique index above): the database keeps it, not only the application.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_return_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.type = 'return' AND NOT EXISTS (
                    SELECT 1 FROM stock_movements given WHERE given.prescription_dispensing_id = NEW.prescription_dispensing_id
                        AND given.stock_lot_id = NEW.stock_lot_id AND given.type = 'dispensing' AND -given.quantity >= NEW.quantity
                ) THEN
                    RAISE EXCEPTION 'a return goes back only into what its dispensing took' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER stock_movements_return_guard BEFORE INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_return_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->whereNotNull('prescription_dispensing_id')->exists()) {
            throw new RuntimeException('Há movimentos de dispensação registrados; a migração não volta.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_return_guard ON stock_movements; DROP FUNCTION IF EXISTS stock_movements_return_guard();');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            type = 'entry' AND quantity BETWEEN 1 AND 100000
        )");
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'prescription_dispensing_id']);
            $table->dropUnique(['prescription_dispensing_id', 'stock_lot_id', 'type']);
            $table->dropColumn('prescription_dispensing_id');
        });
        Schema::table('prescription_dispensings', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'id']));
    }
};
