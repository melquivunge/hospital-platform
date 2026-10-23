<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * After step 4c.2 (ADR-0007): the database, not only the application, keeps a dispensing movement to a lot of the
     * prescription's unit and of the item's medication, not expired in the unit's time zone. A return into a lot that
     * expired in the meantime is discarded at once (type 'discard', reason 'expired'), so an expired balance never
     * waits on the shelf; the discard screen itself comes in step 4c.3.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            (type = 'entry' AND quantity BETWEEN 1 AND 100000 AND prescription_dispensing_id IS NULL)
            OR (type = 'dispensing' AND quantity BETWEEN -500 AND -1 AND prescription_dispensing_id IS NOT NULL)
            OR (type = 'return' AND quantity BETWEEN 1 AND 500 AND prescription_dispensing_id IS NOT NULL)
            OR (type = 'discard' AND quantity BETWEEN -100000 AND -1 AND reason IS NOT NULL)
        )");
        // The dispensing is clinical (RLS): read as the caller, so without its clinical context nothing matches and
        // the movement is refused (fails closed).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_dispensing_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.type = 'dispensing' AND NOT EXISTS (
                    SELECT 1 FROM prescription_dispensings d
                        JOIN prescription_items i ON i.id = d.prescription_item_id
                        JOIN prescriptions p ON p.id = i.prescription_id
                        JOIN stock_lots l ON l.id = NEW.stock_lot_id
                        JOIN units u ON u.id = l.unit_id
                    WHERE d.id = NEW.prescription_dispensing_id AND l.unit_id = p.unit_id AND l.medication_id = i.medication_id
                        AND l.expires_on >= (now() AT TIME ZONE coalesce(u.timezone, 'UTC'))::date
                ) THEN
                    RAISE EXCEPTION 'a dispensing takes only from a valid lot of its unit and medication' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER stock_movements_dispensing_guard BEFORE INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_dispensing_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->where('type', 'discard')->exists()) {
            throw new RuntimeException('Há descartes registrados; a migração não volta.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_dispensing_guard ON stock_movements; DROP FUNCTION IF EXISTS stock_movements_dispensing_guard();');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            (type = 'entry' AND quantity BETWEEN 1 AND 100000 AND prescription_dispensing_id IS NULL)
            OR (type = 'dispensing' AND quantity BETWEEN -500 AND -1 AND prescription_dispensing_id IS NOT NULL)
            OR (type = 'return' AND quantity BETWEEN 1 AND 500 AND prescription_dispensing_id IS NOT NULL)
        )");
    }
};
