<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.5b1 (ADR-0010, TISS 4.03.00): a lot of guides for one insurer, sent from one unit (its CNES), for one
     * competence (the month the accounts closed, in the unit's time zone) and one guide type: SP/SADT for a
     * consultation or an emergency episode, the hospitalization summary for an episode that became an admission. Each
     * closed insurance account is one guide; a lot holds up to 100. An account is in at most one active lot (partial
     * unique index): cancelling a lot, with a reason, releases its accounts. Nothing is deleted. Clinical: RLS.
     */
    public function up(): void
    {
        Schema::create('billing_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('insurer_id');
            $table->date('competence');
            $table->string('guide_type', 20);
            $table->string('tiss_version', 10);
            $table->string('status', 20)->default('generated');
            $table->integer('guide_count');
            $table->bigInteger('total_cents');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'created_at']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE billing_lots ADD CONSTRAINT billing_lots_values_check CHECK (
            extract(day FROM competence) = 1
            AND guide_type IN ('sp_sadt', 'hospitalization')
            AND tiss_version = '4.03.00'
            AND status IN ('generated', 'cancelled')
            AND guide_count BETWEEN 1 AND 100
            AND total_cents BETWEEN 0 AND 10000000000000
            AND (status = 'cancelled') = (cancelled_at IS NOT NULL)
            AND (cancelled_at IS NULL) = (cancelled_by_user_id IS NULL)
            AND (cancelled_at IS NULL) = (cancel_reason IS NULL)
            AND (cancel_reason IS NULL OR length(btrim(cancel_reason)) > 0)
        )");

        Schema::create('billing_lot_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_lot_id');
            $table->unsignedBigInteger('billing_account_id');
            // Set when the lot is cancelled: the account may go into another lot.
            $table->timestampTz('released_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['billing_lot_id', 'billing_account_id']);
            $table->foreign(['tenant_id', 'billing_lot_id'])->references(['tenant_id', 'id'])->on('billing_lots')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_account_id'])->references(['tenant_id', 'id'])->on('billing_accounts')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX billing_lot_accounts_one_active ON billing_lot_accounts (billing_account_id) WHERE released_at IS NULL');

        DB::unprepared(<<<'SQL'
            -- A lot is never deleted; its only change is the cancellation (step 6.5b2 adds the file).
            CREATE OR REPLACE FUNCTION billing_lots_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'lots are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status <> 'generated' OR NEW.status <> 'cancelled'
                    OR (NEW.id, NEW.tenant_id, NEW.unit_id, NEW.insurer_id, NEW.competence, NEW.guide_type, NEW.tiss_version, NEW.guide_count, NEW.total_cents, NEW.created_by_user_id, NEW.created_at)
                        IS DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.unit_id, OLD.insurer_id, OLD.competence, OLD.guide_type, OLD.tiss_version, OLD.guide_count, OLD.total_cents, OLD.created_by_user_id, OLD.created_at) THEN
                    RAISE EXCEPTION 'a lot only changes by its cancellation' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_lots_guard BEFORE UPDATE OR DELETE ON billing_lots FOR EACH ROW EXECUTE FUNCTION billing_lots_guard();
            CREATE TRIGGER billing_lots_no_truncate BEFORE TRUNCATE ON billing_lots FOR EACH STATEMENT EXECUTE FUNCTION billing_lots_guard();

            -- An account goes into a generated lot of its own unit and insurer, closed; it leaves only when the lot is cancelled.
            CREATE OR REPLACE FUNCTION billing_lot_accounts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'an account leaves a lot only by its cancellation' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.released_at IS NOT NULL OR NOT EXISTS (
                        SELECT 1 FROM billing_lots l JOIN billing_accounts a ON a.id = NEW.billing_account_id
                        WHERE l.id = NEW.billing_lot_id AND l.status = 'generated' AND a.status = 'closed' AND a.modality = 'insurance'
                            AND a.unit_id = l.unit_id AND a.insurer_id = l.insurer_id
                    ) THEN
                        RAISE EXCEPTION 'a lot takes closed insurance accounts of its unit and insurer' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.released_at IS NOT NULL OR NEW.released_at IS NULL
                    OR (NEW.id, NEW.tenant_id, NEW.billing_lot_id, NEW.billing_account_id) IS DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.billing_lot_id, OLD.billing_account_id)
                    OR NOT EXISTS (SELECT 1 FROM billing_lots WHERE id = OLD.billing_lot_id AND status = 'cancelled') THEN
                    RAISE EXCEPTION 'an account leaves a lot only by its cancellation' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_lot_accounts_guard BEFORE INSERT OR UPDATE OR DELETE ON billing_lot_accounts FOR EACH ROW EXECUTE FUNCTION billing_lot_accounts_guard();
            CREATE TRIGGER billing_lot_accounts_no_truncate BEFORE TRUNCATE ON billing_lot_accounts FOR EACH STATEMENT EXECUTE FUNCTION billing_lot_accounts_guard();
        SQL);

        foreach (['billing_lots', 'billing_lot_accounts'] as $table) {
            ClinicalSchema::protect($table);
        }
    }

    public function down(): void
    {
        if (DB::table('billing_lots')->exists()) {
            throw new RuntimeException('Lots exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('billing_lot_accounts');
        Schema::dropIfExists('billing_lots');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_lot_accounts_guard(); DROP FUNCTION IF EXISTS billing_lots_guard();');
    }
};
