<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.5c2 (ADR-0010, TISS 4.03.00): the glosa appeal of a returned lot. An appeal is numbered in its lot, carries
     * the insurer's protocol of the lot and its TISS file (written with the row, never changed); each appealed item is a
     * glossed return, appealed once, up to its glosa, with a justification. The insurer's answer is recorded once per
     * item: what it recovered, from nothing (denied) to the whole appeal. Clinical: RLS.
     */
    public function up(): void
    {
        Schema::create('billing_glosa_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_lot_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('protocol_number', 12);
            $table->bigInteger('appealed_cents');
            $table->string('file_path', 255);
            $table->char('file_sha256', 64);
            $table->char('file_hash', 32);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['billing_lot_id', 'sequence']);
            $table->foreign(['tenant_id', 'billing_lot_id'])->references(['tenant_id', 'id'])->on('billing_lots')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE billing_glosa_appeals ADD CONSTRAINT billing_glosa_appeals_check CHECK (
            sequence >= 1 AND protocol_number ~ '^[0-9]{1,12}$' AND appealed_cents > 0
            AND file_sha256 ~ '^[0-9a-f]{64}$' AND file_hash ~ '^[0-9a-f]{32}$'
        )");

        Schema::create('billing_glosa_appeal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_glosa_appeal_id');
            $table->unsignedBigInteger('billing_item_return_id')->unique();
            $table->bigInteger('appealed_cents');
            $table->string('justification', 500);
            $table->bigInteger('recovered_cents')->nullable();
            $table->timestampTz('result_recorded_at')->nullable();
            $table->foreignId('result_recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->index('billing_glosa_appeal_id');
            $table->foreign(['tenant_id', 'billing_glosa_appeal_id'])->references(['tenant_id', 'id'])->on('billing_glosa_appeals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_item_return_id'])->references(['tenant_id', 'id'])->on('billing_item_returns')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE billing_glosa_appeal_items ADD CONSTRAINT billing_glosa_appeal_items_check CHECK (
            appealed_cents > 0 AND length(btrim(justification)) > 0
            AND num_nonnulls(recovered_cents, result_recorded_at, result_recorded_by_user_id) IN (0, 3)
            AND (recovered_cents IS NULL OR recovered_cents BETWEEN 0 AND appealed_cents)
        )');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_glosa_appeals_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'an appeal is never changed nor deleted' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER billing_glosa_appeals_guard BEFORE UPDATE OR DELETE ON billing_glosa_appeals FOR EACH ROW EXECUTE FUNCTION billing_glosa_appeals_guard();
            CREATE TRIGGER billing_glosa_appeals_no_truncate BEFORE TRUNCATE ON billing_glosa_appeals FOR EACH STATEMENT EXECUTE FUNCTION billing_glosa_appeals_guard();

            -- An appealed item is a glossed return of the appeal's lot, up to its glosa; its answer is written once.
            CREATE OR REPLACE FUNCTION billing_glosa_appeal_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.recovered_cents IS NOT NULL OR NOT EXISTS (
                        SELECT 1 FROM billing_item_returns r JOIN billing_glosa_appeals a ON a.billing_lot_id = r.billing_lot_id
                        WHERE r.id = NEW.billing_item_return_id AND a.id = NEW.billing_glosa_appeal_id AND NEW.appealed_cents <= r.glossed_cents
                    ) THEN
                        RAISE EXCEPTION 'an appealed item is a glossed return of the lot, up to its glosa' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.recovered_cents IS NULL AND NEW.recovered_cents IS NOT NULL
                    AND (NEW.id, NEW.tenant_id, NEW.billing_glosa_appeal_id, NEW.billing_item_return_id, NEW.appealed_cents, NEW.justification)
                        IS NOT DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.billing_glosa_appeal_id, OLD.billing_item_return_id, OLD.appealed_cents, OLD.justification) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'an appealed item only changes by its answer, written once' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER billing_glosa_appeal_items_guard BEFORE INSERT OR UPDATE OR DELETE ON billing_glosa_appeal_items FOR EACH ROW EXECUTE FUNCTION billing_glosa_appeal_items_guard();
            CREATE TRIGGER billing_glosa_appeal_items_no_truncate BEFORE TRUNCATE ON billing_glosa_appeal_items FOR EACH STATEMENT EXECUTE FUNCTION billing_glosa_appeal_items_guard();
        SQL);
        ClinicalSchema::protect('billing_glosa_appeals');
        ClinicalSchema::protect('billing_glosa_appeal_items');
    }

    public function down(): void
    {
        if (DB::table('billing_glosa_appeals')->exists()) {
            throw new RuntimeException('Glosa appeals exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('billing_glosa_appeal_items');
        Schema::dropIfExists('billing_glosa_appeals');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_glosa_appeal_items_guard(); DROP FUNCTION IF EXISTS billing_glosa_appeals_guard();');
    }
};
