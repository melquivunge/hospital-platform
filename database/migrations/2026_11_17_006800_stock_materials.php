<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.6b (ADR-0009): the stock ledger of ADR-0007 holds materials too. A lot, a minimum and an inventory line
     * are of a medication or of a material, never both; the balance, its CHECK and the lot guards stay as they are.
     * A dispensing still takes only from a lot of the item's medication (its guard joins on medication_id).
     */
    public function up(): void
    {
        foreach (['stock_lots', 'stock_minimums', 'stock_inventory_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('medication_id')->nullable()->change();
                $blueprint->unsignedBigInteger('material_id')->nullable();
                $blueprint->foreign(['tenant_id', 'material_id'])->references(['tenant_id', 'id'])->on('materials')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_item_check CHECK (num_nonnulls(medication_id, material_id) = 1)");
        }
        Schema::table('stock_lots', fn (Blueprint $table) => $table->unique(['tenant_id', 'unit_id', 'material_id', 'lot_code', 'expires_on']));
        Schema::table('stock_minimums', fn (Blueprint $table) => $table->unique(['tenant_id', 'unit_id', 'material_id']));
        Schema::table('stock_inventory_lines', fn (Blueprint $table) => $table->unique(['stock_inventory_id', 'material_id', 'lot_code', 'expires_on']));
    }

    public function down(): void
    {
        if (collect(['stock_lots', 'stock_minimums', 'stock_inventory_lines'])->contains(fn (string $table) => DB::table($table)->whereNotNull('material_id')->exists())) {
            throw new RuntimeException('Há estoque de materiais registrado; a migração não volta.');
        }
        Schema::table('stock_inventory_lines', fn (Blueprint $table) => $table->dropUnique(['stock_inventory_id', 'material_id', 'lot_code', 'expires_on']));
        Schema::table('stock_minimums', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'unit_id', 'material_id']));
        Schema::table('stock_lots', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'unit_id', 'material_id', 'lot_code', 'expires_on']));
        foreach (['stock_lots', 'stock_minimums', 'stock_inventory_lines'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_item_check");
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['tenant_id', 'material_id']);
                $blueprint->dropColumn('material_id');
                $blueprint->unsignedBigInteger('medication_id')->nullable(false)->change();
            });
        }
    }
};
