<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.7b (user's decisions, 29/10/2026): each line of a BPA file and the account items it carries (a BPA-C line
     * sums items of several accounts; a BPA-I line is one item), and the manager's return of each line (approved, or
     * rejected with the reason; the latest wins). The items of a rejected line go back to the queue of a later
     * competence, so an account may be presented again, and its closed account may be reopened to be corrected.
     * Files generated before this step have no lines: they take no return.
     */
    public function up(): void
    {
        Schema::table('sus_bpa_file_accounts', function (Blueprint $table) {
            $table->dropUnique(['billing_account_id']);
            $table->index('billing_account_id');
            $table->unique(['sus_bpa_file_id', 'billing_account_id']);
        });
        Schema::create('sus_bpa_file_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sus_bpa_file_id');
            $table->char('kind', 1);
            $table->unsignedSmallInteger('sheet');
            $table->unsignedTinyInteger('sequence');
            $table->char('procedure_code', 10);
            $table->char('done_competence', 6);
            $table->unsignedInteger('quantity');

            $table->unique(['sus_bpa_file_id', 'sheet', 'sequence']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'sus_bpa_file_id'])->references(['tenant_id', 'id'])->on('sus_bpa_files')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE sus_bpa_file_lines ADD CONSTRAINT sus_bpa_file_lines_check CHECK (kind IN ('C', 'I') AND procedure_code ~ '^[0-9]{10}$' AND done_competence ~ '^[0-9]{6}$' AND quantity > 0)");
        Schema::create('sus_bpa_file_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sus_bpa_file_line_id');
            $table->unsignedBigInteger('billing_account_item_id');

            $table->unique(['sus_bpa_file_line_id', 'billing_account_item_id']);
            $table->index('billing_account_item_id');
            $table->foreign(['tenant_id', 'sus_bpa_file_line_id'])->references(['tenant_id', 'id'])->on('sus_bpa_file_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_account_item_id'])->references(['tenant_id', 'id'])->on('billing_account_items')->restrictOnDelete();
        });
        Schema::create('sus_bpa_line_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sus_bpa_file_line_id');
            $table->boolean('approved');
            $table->string('reason', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->index('sus_bpa_file_line_id');
            $table->foreign(['tenant_id', 'sus_bpa_file_line_id'])->references(['tenant_id', 'id'])->on('sus_bpa_file_lines')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE sus_bpa_line_returns ADD CONSTRAINT sus_bpa_line_returns_check CHECK (approved OR btrim(coalesce(reason, '')) <> '')");
        DB::unprepared(<<<'SQL'
            -- Whether the item's latest line came back rejected (its latest return) and billing did not compensate it
            -- (the correction replaced it: it never goes again).
            CREATE OR REPLACE FUNCTION sus_bpa_item_rejected(item bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT coalesce((SELECT NOT r.approved FROM sus_bpa_line_returns r
                    WHERE r.sus_bpa_file_line_id = (SELECT max(sus_bpa_file_line_id) FROM sus_bpa_file_line_items WHERE billing_account_item_id = item)
                    ORDER BY r.id DESC LIMIT 1), false)
                    AND NOT EXISTS (SELECT 1 FROM billing_account_items c WHERE c.origin_type = 'compensation' AND c.origin_id = item)
            $$;
            -- Whether an item of the account is in a rejected line.
            CREATE OR REPLACE FUNCTION sus_bpa_rejected(account bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (SELECT 1 FROM billing_account_items i WHERE i.billing_account_id = account AND sus_bpa_item_rejected(i.id))
            $$;

            CREATE TRIGGER sus_bpa_file_lines_guard BEFORE UPDATE OR DELETE ON sus_bpa_file_lines FOR EACH ROW EXECUTE FUNCTION sus_bpa_files_guard();
            CREATE TRIGGER sus_bpa_file_lines_no_truncate BEFORE TRUNCATE ON sus_bpa_file_lines FOR EACH STATEMENT EXECUTE FUNCTION sus_bpa_files_guard();
            CREATE TRIGGER sus_bpa_file_line_items_guard BEFORE UPDATE OR DELETE ON sus_bpa_file_line_items FOR EACH ROW EXECUTE FUNCTION sus_bpa_files_guard();
            CREATE TRIGGER sus_bpa_file_line_items_no_truncate BEFORE TRUNCATE ON sus_bpa_file_line_items FOR EACH STATEMENT EXECUTE FUNCTION sus_bpa_files_guard();
            CREATE TRIGGER sus_bpa_line_returns_guard BEFORE UPDATE OR DELETE ON sus_bpa_line_returns FOR EACH ROW EXECUTE FUNCTION sus_bpa_files_guard();
            CREATE TRIGGER sus_bpa_line_returns_no_truncate BEFORE TRUNCATE ON sus_bpa_line_returns FOR EACH STATEMENT EXECUTE FUNCTION sus_bpa_files_guard();

            -- An item is presented again only after its latest line was rejected; an account, only with such an item.
            CREATE OR REPLACE FUNCTION sus_bpa_presented_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'sus_bpa_file_line_items' THEN
                    IF EXISTS (SELECT 1 FROM sus_bpa_file_line_items WHERE billing_account_item_id = NEW.billing_account_item_id) AND NOT sus_bpa_item_rejected(NEW.billing_account_item_id) THEN
                        RAISE EXCEPTION 'a BPA item is presented again only after a rejection' USING ERRCODE = 'P0001';
                    END IF;
                ELSIF EXISTS (SELECT 1 FROM sus_bpa_file_accounts WHERE billing_account_id = NEW.billing_account_id) AND NOT sus_bpa_rejected(NEW.billing_account_id) THEN
                    RAISE EXCEPTION 'a BPA account is presented again only after a rejection' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sus_bpa_file_line_items_presented_guard BEFORE INSERT ON sus_bpa_file_line_items FOR EACH ROW EXECUTE FUNCTION sus_bpa_presented_guard();
            CREATE TRIGGER sus_bpa_file_accounts_presented_guard BEFORE INSERT ON sus_bpa_file_accounts FOR EACH ROW EXECUTE FUNCTION sus_bpa_presented_guard();

            -- A closed account never changes again, except billing reopening a rejected AIH or a BPA account with a
            -- rejected line: back to open, the frozen bed specialty cleared, nothing else changed.
            CREATE OR REPLACE FUNCTION billing_accounts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'accounts are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status = 'closed' THEN
                    IF NEW.status = 'open' AND NEW.closed_at IS NULL AND NEW.closed_by_user_id IS NULL AND NEW.aih_bed_specialty IS NULL
                        AND to_jsonb(NEW) - ARRAY['status', 'closed_at', 'closed_by_user_id', 'aih_bed_specialty', 'updated_at']
                            = to_jsonb(OLD) - ARRAY['status', 'closed_at', 'closed_by_user_id', 'aih_bed_specialty', 'updated_at']
                        AND (sus_aih_rejected(OLD.id) OR sus_bpa_rejected(OLD.id)) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'a closed account is immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
        SQL);
        foreach (['sus_bpa_file_lines', 'sus_bpa_file_line_items', 'sus_bpa_line_returns'] as $table) {
            ClinicalSchema::protect($table);
        }
    }

    public function down(): void
    {
        if (DB::table('sus_bpa_file_lines')->exists()) {
            throw new RuntimeException('BPA lines exist: this migration cannot be rolled back.');
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_accounts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'accounts are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status = 'closed' THEN
                    IF NEW.status = 'open' AND NEW.closed_at IS NULL AND NEW.closed_by_user_id IS NULL AND NEW.aih_bed_specialty IS NULL
                        AND to_jsonb(NEW) - ARRAY['status', 'closed_at', 'closed_by_user_id', 'aih_bed_specialty', 'updated_at']
                            = to_jsonb(OLD) - ARRAY['status', 'closed_at', 'closed_by_user_id', 'aih_bed_specialty', 'updated_at']
                        AND sus_aih_rejected(OLD.id) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'a closed account is immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER sus_bpa_file_accounts_presented_guard ON sus_bpa_file_accounts;
        SQL);
        Schema::dropIfExists('sus_bpa_line_returns');
        Schema::dropIfExists('sus_bpa_file_line_items');
        Schema::dropIfExists('sus_bpa_file_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS sus_bpa_presented_guard(); DROP FUNCTION IF EXISTS sus_bpa_rejected(bigint); DROP FUNCTION IF EXISTS sus_bpa_item_rejected(bigint);');
        Schema::table('sus_bpa_file_accounts', function (Blueprint $table) {
            $table->dropUnique(['sus_bpa_file_id', 'billing_account_id']);
            $table->dropIndex(['billing_account_id']);
            $table->unique('billing_account_id');
        });
    }
};
