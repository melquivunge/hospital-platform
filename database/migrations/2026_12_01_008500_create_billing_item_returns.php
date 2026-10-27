<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.5c1 (ADR-0010, TISS 4.03.00): the insurer's return of a sent lot (its file generated), per guide item:
     * what was billed (frozen), paid and glossed, with the glosa's code (TUSS table 38) and reason. Recorded once per
     * item, never changed nor deleted; a lot with a return is no longer cancelled. Clinical: RLS.
     */
    public function up(): void
    {
        Schema::create('billing_item_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_lot_id');
            $table->unsignedBigInteger('billing_account_item_id')->unique();
            $table->bigInteger('billed_cents');
            $table->bigInteger('paid_cents');
            $table->bigInteger('glossed_cents');
            $table->char('glosa_code', 4)->nullable();
            $table->string('glosa_reason', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->unique(['tenant_id', 'id']);
            $table->index('billing_lot_id');
            $table->foreign(['tenant_id', 'billing_lot_id'])->references(['tenant_id', 'id'])->on('billing_lots')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_account_item_id'])->references(['tenant_id', 'id'])->on('billing_account_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE billing_item_returns ADD CONSTRAINT billing_item_returns_values_check CHECK (
            billed_cents >= 0 AND paid_cents >= 0 AND glossed_cents >= 0 AND paid_cents + glossed_cents = billed_cents
            AND (glossed_cents > 0) = (glosa_code IS NOT NULL)
            AND (glosa_code IS NULL OR glosa_code ~ '^[0-9]{4}$')
            AND (glosa_reason IS NULL OR (glosa_code IS NOT NULL AND length(btrim(glosa_reason)) > 0))
        )");
        DB::unprepared(<<<'SQL'
            -- Insert-only, for an item of the guides of a generated lot whose file exists, at the item's value. The lot
            -- row is locked (FOR SHARE) so a concurrent cancellation waits and then sees the return.
            CREATE OR REPLACE FUNCTION billing_item_returns_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'a return is never changed nor deleted' USING ERRCODE = 'P0001';
                END IF;
                PERFORM 1 FROM billing_lots WHERE id = NEW.billing_lot_id AND status = 'generated' AND file_path IS NOT NULL FOR SHARE;
                IF NOT FOUND OR NOT EXISTS (
                    SELECT 1 FROM billing_account_items i JOIN billing_lot_accounts la ON la.billing_account_id = i.billing_account_id
                    WHERE i.id = NEW.billing_account_item_id AND la.billing_lot_id = NEW.billing_lot_id AND la.released_at IS NULL
                        AND i.origin_type <> 'compensation' AND i.quantity * coalesce(i.unit_price_cents, 0) = NEW.billed_cents
                        AND NOT EXISTS (SELECT 1 FROM billing_account_items c WHERE c.billing_account_id = i.billing_account_id AND c.origin_type = 'compensation' AND c.origin_id = i.id)
                ) THEN
                    RAISE EXCEPTION 'a return is of a guide item of a sent lot, at its value' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_item_returns_guard BEFORE INSERT OR UPDATE OR DELETE ON billing_item_returns FOR EACH ROW EXECUTE FUNCTION billing_item_returns_guard();
            CREATE TRIGGER billing_item_returns_no_truncate BEFORE TRUNCATE ON billing_item_returns FOR EACH STATEMENT EXECUTE FUNCTION billing_item_returns_guard();

            -- The insurer answered the lot: it is no longer cancelled.
            CREATE OR REPLACE FUNCTION billing_lots_returned_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM billing_item_returns WHERE billing_lot_id = OLD.id) THEN
                    RAISE EXCEPTION 'a lot with a return is not cancelled' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_lots_returned_guard BEFORE UPDATE ON billing_lots FOR EACH ROW
                WHEN (NEW.status = 'cancelled' AND OLD.status <> 'cancelled') EXECUTE FUNCTION billing_lots_returned_guard();
        SQL);
        ClinicalSchema::protect('billing_item_returns');
    }

    public function down(): void
    {
        if (DB::table('billing_item_returns')->exists()) {
            throw new RuntimeException('Returns exist: this migration cannot be rolled back.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS billing_lots_returned_guard ON billing_lots; DROP FUNCTION IF EXISTS billing_lots_returned_guard();');
        Schema::dropIfExists('billing_item_returns');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_item_returns_guard()');
    }
};
