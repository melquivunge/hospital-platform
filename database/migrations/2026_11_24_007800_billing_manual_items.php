<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.3a (ADR-0010): billing adds a manual item, with its reason and author, and compensates an item (automatic
     * or manual) with a negative one that points to it, also with reason and author; nothing is changed nor deleted.
     * An item is compensated at most once (the unique origin of step 6.2b covers the type "compensation").
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE billing_account_items ALTER COLUMN origin_id DROP NOT NULL');
        DB::statement('ALTER TABLE billing_account_items ADD COLUMN reason varchar(500)');
        DB::statement('ALTER TABLE billing_account_items ADD COLUMN author_user_id bigint REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_values_check');
        DB::statement(self::valuesCheck(true));
        // A compensation undoes exactly one item of the same account (never another compensation): the same quantity,
        // negated, at the same price.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_account_items_compensation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.origin_type = 'compensation' AND NOT EXISTS (
                    SELECT 1 FROM billing_account_items target
                    WHERE target.id = NEW.origin_id AND target.billing_account_id = NEW.billing_account_id
                        AND target.origin_type <> 'compensation' AND target.quantity = -NEW.quantity
                        AND target.unit_price_cents IS NOT DISTINCT FROM NEW.unit_price_cents
                ) THEN
                    RAISE EXCEPTION 'a compensation undoes one item of its account, at its quantity and price' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_account_items_compensation_guard BEFORE INSERT ON billing_account_items
                FOR EACH ROW EXECUTE FUNCTION billing_account_items_compensation_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::table('billing_account_items')->whereIn('origin_type', ['manual', 'compensation'])->exists()) {
            throw new RuntimeException('Manual or compensating items exist: this migration cannot be rolled back.');
        }
        DB::unprepared('DROP TRIGGER billing_account_items_compensation_guard ON billing_account_items; DROP FUNCTION billing_account_items_compensation_guard()');
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_values_check');
        DB::statement(self::valuesCheck(false));
        DB::statement('ALTER TABLE billing_account_items DROP COLUMN author_user_id');
        DB::statement('ALTER TABLE billing_account_items DROP COLUMN reason');
        DB::statement('ALTER TABLE billing_account_items ALTER COLUMN origin_id SET NOT NULL');
    }

    private static function valuesCheck(bool $manual): string
    {
        $types = "'visit', 'exam_order_item', 'surgery', 'admission_day', 'stock_movement', 'dispensing', 'dispensing_return'".($manual ? ", 'manual', 'compensation'" : '');
        // A manual item has no origin; a compensation points to the item it compensates; both carry reason and author.
        $manualRules = $manual ? "
            AND (origin_type = 'manual') = (origin_id IS NULL)
            AND (origin_type IN ('manual', 'compensation')) = (reason IS NOT NULL)
            AND (reason IS NULL) = (author_user_id IS NULL)
            AND (reason IS NULL OR length(btrim(reason)) > 0)" : '';

        return "ALTER TABLE billing_account_items ADD CONSTRAINT billing_account_items_values_check CHECK (
            origin_type IN ({$types})
            AND length(btrim(name)) > 0 AND quantity <> 0
            AND (unit_price_cents IS NULL OR unit_price_cents BETWEEN 0 AND 100000000000){$manualRules}
        )";
    }
};
