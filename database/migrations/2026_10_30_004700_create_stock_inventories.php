<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4c.3b (ADR-0007): the inventory of a unit's stock. The pharmacy counts lot by lot (each count keeps the
     * balance read with the lot locked at that moment) and may add a lot found without a record; a unit manager
     * who neither opened nor counted it approves or refuses. Approving adds one adjustment movement per lot with a
     * difference (counted minus the balance at the count), so what moved after the count is not adjusted again.
     * Not clinical: no patient data.
     */
    public function up(): void
    {
        Schema::create('stock_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->string('status', 20)->default('counting');
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('refusal_reason', 200)->nullable();
            // Everyone who ever counted it (a recount by someone else does not erase who counted first).
            $table->jsonb('participant_user_ids')->default('[]');

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_inventories ADD CONSTRAINT stock_inventories_values_check CHECK (
            (status = 'counting' AND submitted_at IS NULL AND decided_at IS NULL)
            OR (status = 'submitted' AND submitted_at IS NOT NULL AND submitted_by_user_id IS NOT NULL AND decided_at IS NULL)
            OR (status = 'approved' AND submitted_at IS NOT NULL AND decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL AND refusal_reason IS NULL)
            OR (status = 'refused' AND decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL AND btrim(coalesce(refusal_reason, '')) <> '')
        )");
        // One inventory in progress per unit.
        DB::statement("CREATE UNIQUE INDEX stock_inventories_one_open ON stock_inventories (unit_id) WHERE status IN ('counting', 'submitted')");

        Schema::create('stock_inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('stock_inventory_id');
            $table->unsignedBigInteger('medication_id');
            // A recorded lot, or a lot found without a record (its code and expiry).
            $table->unsignedBigInteger('stock_lot_id')->nullable();
            $table->string('lot_code', 50)->nullable();
            $table->date('expires_on')->nullable();
            $table->integer('counted')->nullable();
            // The lot's balance read with the lot locked when it was counted.
            $table->integer('expected')->nullable();
            $table->foreignId('counted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('counted_at')->nullable();

            $table->unique(['stock_inventory_id', 'stock_lot_id']);
            $table->unique(['stock_inventory_id', 'medication_id', 'lot_code', 'expires_on']);
            $table->foreign(['tenant_id', 'stock_inventory_id'])->references(['tenant_id', 'id'])->on('stock_inventories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'stock_lot_id'])->references(['tenant_id', 'id'])->on('stock_lots')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_inventory_lines ADD CONSTRAINT stock_inventory_lines_values_check CHECK (
            ((stock_lot_id IS NOT NULL AND lot_code IS NULL AND expires_on IS NULL)
                OR (stock_lot_id IS NULL AND lot_code IS NOT NULL AND lot_code ~ '^[A-Z0-9][A-Z0-9./-]*$' AND expires_on IS NOT NULL))
            AND ((counted IS NULL AND expected IS NULL AND counted_by_user_id IS NULL AND counted_at IS NULL)
                OR (counted IS NOT NULL AND expected IS NOT NULL AND counted_by_user_id IS NOT NULL AND counted_at IS NOT NULL
                    AND counted BETWEEN 0 AND 100000 AND expected >= 0))
        )");

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_inventory_id')->nullable();
            // An inventory adjusts each lot at most once: approving twice cannot adjust twice.
            $table->unique(['stock_inventory_id', 'stock_lot_id']);
            $table->foreign(['tenant_id', 'stock_inventory_id'])->references(['tenant_id', 'id'])->on('stock_inventories')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            (type = 'entry' AND quantity BETWEEN 1 AND 100000 AND prescription_dispensing_id IS NULL AND stock_inventory_id IS NULL)
            OR (type = 'dispensing' AND quantity BETWEEN -500 AND -1 AND prescription_dispensing_id IS NOT NULL AND stock_inventory_id IS NULL)
            OR (type = 'return' AND quantity BETWEEN 1 AND 500 AND prescription_dispensing_id IS NOT NULL AND stock_inventory_id IS NULL)
            OR (type = 'discard' AND quantity BETWEEN -100000 AND -1 AND reason IS NOT NULL AND stock_inventory_id IS NULL)
            OR (type = 'adjustment' AND quantity BETWEEN -100000 AND 100000 AND quantity <> 0 AND stock_inventory_id IS NOT NULL AND prescription_dispensing_id IS NULL)
        )");
    }

    public function down(): void
    {
        if (DB::table('stock_inventories')->exists()) {
            throw new RuntimeException('Há inventários registrados; a migração não volta.');
        }
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_values_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            (type = 'entry' AND quantity BETWEEN 1 AND 100000 AND prescription_dispensing_id IS NULL)
            OR (type = 'dispensing' AND quantity BETWEEN -500 AND -1 AND prescription_dispensing_id IS NOT NULL)
            OR (type = 'return' AND quantity BETWEEN 1 AND 500 AND prescription_dispensing_id IS NOT NULL)
            OR (type = 'discard' AND quantity BETWEEN -100000 AND -1 AND reason IS NOT NULL)
        )");
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'stock_inventory_id']);
            $table->dropUnique(['stock_inventory_id', 'stock_lot_id']);
            $table->dropColumn('stock_inventory_id');
        });
        Schema::dropIfExists('stock_inventory_lines');
        Schema::dropIfExists('stock_inventories');
    }
};
