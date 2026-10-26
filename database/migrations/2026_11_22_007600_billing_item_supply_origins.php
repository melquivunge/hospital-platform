<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.2b (ADR-0010): more origins for an account's items. A day of an admission (one per day, by the bed type;
     * the origin is the admission and its day), a surgery's consumption or return of material (the stock movement),
     * and a medication's dispensing or return (the dispensing; the return is its own origin type). A return is a
     * negative quantity. Each origin, and each admission day, generates at most one item.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_values_check');
        DB::statement("ALTER TABLE billing_account_items ADD CONSTRAINT billing_account_items_values_check CHECK (
            origin_type IN ('visit', 'exam_order_item', 'surgery', 'admission_day', 'stock_movement', 'dispensing', 'dispensing_return')
            AND length(btrim(name)) > 0 AND quantity <> 0
            AND (unit_price_cents IS NULL OR unit_price_cents BETWEEN 0 AND 100000000000)
        )");
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_origin_type_origin_id_unique');
        DB::statement("CREATE UNIQUE INDEX billing_account_items_one_per_origin ON billing_account_items (origin_type, origin_id) WHERE origin_type <> 'admission_day'");
        DB::statement("CREATE UNIQUE INDEX billing_account_items_one_per_day ON billing_account_items (origin_id, performed_on) WHERE origin_type = 'admission_day'");
    }

    public function down(): void
    {
        if (DB::table('billing_account_items')->whereNotIn('origin_type', ['visit', 'exam_order_item', 'surgery'])->exists()) {
            throw new RuntimeException('Items of the new origins exist: this migration cannot be rolled back.');
        }
        DB::statement('DROP INDEX billing_account_items_one_per_day');
        DB::statement('DROP INDEX billing_account_items_one_per_origin');
        DB::statement('ALTER TABLE billing_account_items ADD CONSTRAINT billing_account_items_origin_type_origin_id_unique UNIQUE (origin_type, origin_id)');
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_values_check');
        DB::statement("ALTER TABLE billing_account_items ADD CONSTRAINT billing_account_items_values_check CHECK (
            origin_type IN ('visit', 'exam_order_item', 'surgery')
            AND length(btrim(name)) > 0 AND quantity <> 0
            AND (unit_price_cents IS NULL OR unit_price_cents BETWEEN 0 AND 100000000000)
        )");
    }
};
