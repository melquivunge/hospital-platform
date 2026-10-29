<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.7a (user's decisions, 29/10/2026): billing records the manager's return of each AIH a file presented
     * (approved, or rejected with the reason; the latest wins until the AIH is presented again). A rejected AIH goes back
     * to the queue of a later competence, so an account may be presented more than once, but only again after a
     * rejection; to correct it first, billing reopens the closed account (the one transition a closed account allows).
     */
    public function up(): void
    {
        Schema::table('sus_aih_file_accounts', function (Blueprint $table) {
            $table->dropUnique(['billing_account_id']);
            $table->index('billing_account_id');
            $table->unique(['sus_aih_file_id', 'billing_account_id']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('sus_aih_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sus_aih_file_account_id');
            $table->boolean('approved');
            $table->string('reason', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->index('sus_aih_file_account_id');
            $table->foreign(['tenant_id', 'sus_aih_file_account_id'])->references(['tenant_id', 'id'])->on('sus_aih_file_accounts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE sus_aih_returns ADD CONSTRAINT sus_aih_returns_check CHECK (approved OR btrim(coalesce(reason, '')) <> '')");
        DB::unprepared(<<<'SQL'
            -- Whether the account's latest presentation came back rejected (its latest return).
            CREATE OR REPLACE FUNCTION sus_aih_rejected(account bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT coalesce((SELECT NOT r.approved FROM sus_aih_returns r
                    WHERE r.sus_aih_file_account_id = (SELECT max(id) FROM sus_aih_file_accounts WHERE billing_account_id = account)
                    ORDER BY r.id DESC LIMIT 1), false)
            $$;

            CREATE TRIGGER sus_aih_returns_guard BEFORE UPDATE OR DELETE ON sus_aih_returns FOR EACH ROW EXECUTE FUNCTION sus_aih_files_guard();
            CREATE TRIGGER sus_aih_returns_no_truncate BEFORE TRUNCATE ON sus_aih_returns FOR EACH STATEMENT EXECUTE FUNCTION sus_aih_files_guard();

            -- An account is presented again only after its latest presentation was rejected.
            CREATE OR REPLACE FUNCTION sus_aih_file_accounts_presented_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM sus_aih_file_accounts WHERE billing_account_id = NEW.billing_account_id) AND NOT sus_aih_rejected(NEW.billing_account_id) THEN
                    RAISE EXCEPTION 'an AIH is presented again only after a rejection' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sus_aih_file_accounts_presented_guard BEFORE INSERT ON sus_aih_file_accounts FOR EACH ROW EXECUTE FUNCTION sus_aih_file_accounts_presented_guard();

            -- A closed account never changes again, except billing reopening a rejected AIH: back to open, the frozen
            -- bed specialty cleared (the sector's again at the next close), nothing else changed.
            CREATE OR REPLACE FUNCTION billing_accounts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'accounts are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status = 'closed' THEN
                    IF NEW.status = 'open' AND NEW.closed_at IS NULL AND NEW.closed_by_user_id IS NULL
                        AND NEW.aih_bed_specialty IS NULL
                        AND to_jsonb(NEW) - ARRAY['status', 'closed_at', 'closed_by_user_id', 'aih_bed_specialty', 'updated_at']
                            = to_jsonb(OLD) - ARRAY['status', 'closed_at', 'closed_by_user_id', 'aih_bed_specialty', 'updated_at']
                        AND sus_aih_rejected(OLD.id) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'a closed account is immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
        SQL);
        ClinicalSchema::protect('sus_aih_returns');
    }

    public function down(): void
    {
        if (DB::table('sus_aih_returns')->exists() || DB::table('sus_aih_file_accounts')->groupBy('billing_account_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('AIH returns exist: this migration cannot be rolled back.');
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_accounts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'accounts are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'a closed account is immutable' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER sus_aih_file_accounts_presented_guard ON sus_aih_file_accounts;
            DROP FUNCTION sus_aih_file_accounts_presented_guard();
        SQL);
        Schema::dropIfExists('sus_aih_returns');
        DB::unprepared('DROP FUNCTION IF EXISTS sus_aih_rejected(bigint)');
        Schema::table('sus_aih_file_accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropUnique(['sus_aih_file_id', 'billing_account_id']);
            $table->dropIndex(['billing_account_id']);
            $table->unique('billing_account_id');
        });
    }
};
