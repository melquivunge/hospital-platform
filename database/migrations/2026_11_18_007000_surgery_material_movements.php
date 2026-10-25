<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.6c (ADR-0009): the surgical center records the materials a surgery used (movement 'surgery') and gives
     * back what was not (movement 'surgery_return', up to what that surgery took from the lot). The movement keeps
     * only the surgery's id: reaching the patient from it still needs the clinical access (the surgery is RLS).
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('surgery_id')->nullable();
            $table->index(['surgery_id', 'stock_lot_id']);
            $table->foreign(['tenant_id', 'surgery_id'])->references(['tenant_id', 'id'])->on('surgeries')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement(self::valuesCheck(true));

        // A consumption takes only from a valid material lot of the surgery's unit, while the surgery is in progress or
        // completed; a return gives back at most what the surgery took from that lot and has not given back yet. The
        // surgery is clinical (RLS): read as the caller, so without its client nothing matches (fails closed).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_surgery_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.type = 'surgery' AND NOT EXISTS (
                    SELECT 1 FROM surgeries s
                        JOIN stock_lots l ON l.id = NEW.stock_lot_id
                        JOIN units u ON u.id = l.unit_id
                    WHERE s.id = NEW.surgery_id AND s.unit_id = l.unit_id AND l.material_id IS NOT NULL
                        AND s.status IN ('in_progress', 'completed')
                        AND l.expires_on >= (now() AT TIME ZONE coalesce(u.timezone, 'UTC'))::date
                ) THEN
                    RAISE EXCEPTION 'a surgery takes only from a valid material lot of its unit' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.type = 'surgery_return' AND (
                    SELECT -coalesce(sum(quantity), 0) FROM stock_movements
                    WHERE surgery_id = NEW.surgery_id AND stock_lot_id = NEW.stock_lot_id AND type IN ('surgery', 'surgery_return')
                ) < NEW.quantity THEN
                    RAISE EXCEPTION 'a surgery gives back only what it took from the lot' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER stock_movements_surgery_guard BEFORE INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_surgery_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->whereNotNull('surgery_id')->exists()) {
            throw new RuntimeException('Há movimentos de cirurgia registrados; a migração não volta.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_surgery_guard ON stock_movements; DROP FUNCTION IF EXISTS stock_movements_surgery_guard();');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement(self::valuesCheck(false));
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'surgery_id']);
            $table->dropIndex(['surgery_id', 'stock_lot_id']);
            $table->dropColumn('surgery_id');
        });
    }

    private static function valuesCheck(bool $surgery): string
    {
        // Up: the other types never carry a surgery. Down: as before step 5b.6c.
        $none = $surgery ? ' AND surgery_id IS NULL' : '';
        $types = $surgery ? "
            OR (type = 'surgery' AND quantity BETWEEN -100000 AND -1 AND surgery_id IS NOT NULL AND prescription_dispensing_id IS NULL AND stock_inventory_id IS NULL)
            OR (type = 'surgery_return' AND quantity BETWEEN 1 AND 100000 AND surgery_id IS NOT NULL AND prescription_dispensing_id IS NULL AND stock_inventory_id IS NULL)" : '';

        return "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            (type = 'entry' AND quantity BETWEEN 1 AND 100000 AND prescription_dispensing_id IS NULL AND stock_inventory_id IS NULL{$none})
            OR (type = 'dispensing' AND quantity BETWEEN -500 AND -1 AND prescription_dispensing_id IS NOT NULL AND stock_inventory_id IS NULL{$none})
            OR (type = 'return' AND quantity BETWEEN 1 AND 500 AND prescription_dispensing_id IS NOT NULL AND stock_inventory_id IS NULL{$none})
            OR (type = 'discard' AND quantity BETWEEN -100000 AND -1 AND reason IS NOT NULL AND stock_inventory_id IS NULL{$none})
            OR (type = 'adjustment' AND quantity BETWEEN -100000 AND 100000 AND quantity <> 0 AND stock_inventory_id IS NOT NULL AND prescription_dispensing_id IS NULL{$none}){$types}
        )";
    }
};
