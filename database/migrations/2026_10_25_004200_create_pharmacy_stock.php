<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4c.1a (ADR-0007): the pharmacy stock of each unit, by lot and expiry, as a ledger. A movement is
     * insert-only; the lot's balance is kept by the database from the movements (never written by the
     * application) and never goes below zero. A lot never changes otherwise and is never deleted. No patient
     * data: not clinical.
     */
    public function up(): void
    {
        Schema::table('medications', fn (Blueprint $table) => $table->string('stock_unit', 30)->default('unidade'));

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('medication_id');
            $table->string('lot_code', 50);
            $table->date('expires_on');
            $table->string('manufacturer', 100)->nullable();
            $table->bigInteger('balance')->default(0);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'unit_id', 'medication_id', 'lot_code', 'expires_on']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_lots ADD CONSTRAINT stock_lots_values_check CHECK (balance >= 0 AND lot_code ~ '^[A-Z0-9][A-Z0-9./-]*$')");

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('stock_lot_id');
            $table->string('type', 20);
            $table->integer('quantity');
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['stock_lot_id', 'created_at']);
            $table->foreign(['tenant_id', 'stock_lot_id'])->references(['tenant_id', 'id'])->on('stock_lots')->restrictOnDelete();
        });
        // Step 4c.1a records entries only; dispensing, return, discard and adjustment join in 4c.2 and 4c.3.
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_values_check CHECK (
            type = 'entry' AND quantity BETWEEN 1 AND 100000
        )");

        Schema::create('stock_minimums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('medication_id');
            $table->unsignedInteger('minimum');
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'unit_id', 'medication_id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'medication_id'])->references(['tenant_id', 'id'])->on('medications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_minimums ADD CONSTRAINT stock_minimums_values_check CHECK (minimum BETWEEN 1 AND 100000)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a stock movement never changes' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER stock_movements_guard BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_guard();
            CREATE TRIGGER stock_movements_no_truncate BEFORE TRUNCATE ON stock_movements
                FOR EACH STATEMENT EXECUTE FUNCTION stock_movements_guard();

            -- The balance follows the movements; the CHECK on stock_lots refuses one that would go negative. The
            -- change being made is announced to the lot guard (transaction-local), which accepts exactly it.
            CREATE OR REPLACE FUNCTION stock_movements_balance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM set_config('app.stock_balance_change', NEW.stock_lot_id || ':' || NEW.quantity, true);
                UPDATE stock_lots SET balance = balance + NEW.quantity WHERE id = NEW.stock_lot_id;
                PERFORM set_config('app.stock_balance_change', '', true);
                RETURN NULL;
            END $$;
            CREATE TRIGGER stock_movements_balance AFTER INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_balance();

            -- A lot is born empty and changes only by the balance trigger above: from inside a trigger (depth 2, so
            -- not a statement of the application) and by exactly the change it announced.
            CREATE OR REPLACE FUNCTION stock_lots_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.balance <> 0 THEN
                        RAISE EXCEPTION 'a lot is born empty' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP <> 'UPDATE' OR pg_trigger_depth() < 2 OR (to_jsonb(NEW) - 'balance') <> (to_jsonb(OLD) - 'balance')
                    OR coalesce(current_setting('app.stock_balance_change', true), '') <> NEW.id || ':' || (NEW.balance - OLD.balance) THEN
                    RAISE EXCEPTION 'a lot changes only by its movements' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER stock_lots_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_lots
                FOR EACH ROW EXECUTE FUNCTION stock_lots_guard();
            CREATE TRIGGER stock_lots_no_truncate BEFORE TRUNCATE ON stock_lots
                FOR EACH STATEMENT EXECUTE FUNCTION stock_lots_guard();
        SQL);
    }

    public function down(): void
    {
        // The ledger is history: rolling back with movements recorded would lose it.
        if (DB::table('stock_movements')->exists()) {
            throw new RuntimeException('Há movimentos de estoque registrados; a migração não volta.');
        }
        Schema::dropIfExists('stock_minimums');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_movements_guard(); DROP FUNCTION IF EXISTS stock_movements_balance(); DROP FUNCTION IF EXISTS stock_lots_guard();');
        Schema::table('medications', fn (Blueprint $table) => $table->dropColumn('stock_unit'));
    }
};
