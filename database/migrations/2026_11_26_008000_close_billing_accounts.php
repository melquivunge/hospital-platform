<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.4a (ADR-0010): billing closes an account once the stay ended and nothing is pending; a closed account is
     * immutable in the database (no item, answer nor change). What the stay produces afterwards goes to a complementary
     * account (one open at a time per account), with the same patient, unit, modality and card.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE billing_accounts ADD COLUMN closed_at timestamptz');
        DB::statement('ALTER TABLE billing_accounts ADD COLUMN closed_by_user_id bigint REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE billing_accounts ADD COLUMN complement_of_id bigint');
        DB::statement('ALTER TABLE billing_accounts ADD CONSTRAINT billing_accounts_complement_foreign FOREIGN KEY (tenant_id, complement_of_id) REFERENCES billing_accounts (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE billing_accounts DROP CONSTRAINT billing_accounts_values_check');
        DB::statement(self::valuesCheck(true));
        DB::statement("CREATE UNIQUE INDEX billing_accounts_one_open_complement ON billing_accounts (complement_of_id) WHERE status = 'open'");

        DB::unprepared(<<<'SQL'
            -- A closed account never changes again (only later steps' own transitions will be added here); none is deleted.
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
            CREATE TRIGGER billing_accounts_guard BEFORE UPDATE OR DELETE ON billing_accounts FOR EACH ROW EXECUTE FUNCTION billing_accounts_guard();
            CREATE TRIGGER billing_accounts_no_truncate BEFORE TRUNCATE ON billing_accounts FOR EACH STATEMENT EXECUTE FUNCTION billing_accounts_guard();

            -- Items and answers go into an open account only.
            CREATE OR REPLACE FUNCTION billing_account_items_open_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- FOR SHARE: a close committing meanwhile waits, or this insert sees it closed.
                IF NOT EXISTS (SELECT 1 FROM billing_accounts WHERE id = NEW.billing_account_id AND status = 'open' FOR SHARE) THEN
                    RAISE EXCEPTION 'a closed account takes no item nor answer' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_account_items_open_guard BEFORE INSERT ON billing_account_items FOR EACH ROW EXECUTE FUNCTION billing_account_items_open_guard();
            CREATE OR REPLACE FUNCTION billing_authorizations_open_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM billing_account_items i JOIN billing_accounts a ON a.id = i.billing_account_id
                    WHERE i.id = NEW.billing_account_item_id AND a.status = 'open' FOR SHARE OF a) THEN
                    RAISE EXCEPTION 'a closed account takes no item nor answer' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_authorizations_open_guard BEFORE INSERT ON billing_authorizations FOR EACH ROW EXECUTE FUNCTION billing_authorizations_open_guard();

            -- A compensation may land in the complementary account of the compensated item's (same family).
            CREATE OR REPLACE FUNCTION billing_account_items_compensation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.origin_type = 'compensation' AND NOT EXISTS (
                    SELECT 1 FROM billing_account_items target
                        JOIN billing_accounts target_account ON target_account.id = target.billing_account_id
                        JOIN billing_accounts new_account ON new_account.id = NEW.billing_account_id
                    WHERE target.id = NEW.origin_id
                        AND coalesce(target_account.complement_of_id, target_account.id) = coalesce(new_account.complement_of_id, new_account.id)
                        AND target.origin_type <> 'compensation' AND target.quantity = -NEW.quantity
                        AND target.unit_price_cents IS NOT DISTINCT FROM NEW.unit_price_cents
                ) THEN
                    RAISE EXCEPTION 'a compensation undoes one item of its account, at its quantity and price' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::table('billing_accounts')->where('status', 'closed')->orWhereNotNull('complement_of_id')->exists()) {
            throw new RuntimeException('Closed or complementary accounts exist: this migration cannot be rolled back.');
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER billing_authorizations_open_guard ON billing_authorizations;
            DROP FUNCTION billing_authorizations_open_guard();
            DROP TRIGGER billing_account_items_open_guard ON billing_account_items;
            DROP FUNCTION billing_account_items_open_guard();
            DROP TRIGGER billing_accounts_no_truncate ON billing_accounts;
            DROP TRIGGER billing_accounts_guard ON billing_accounts;
            DROP FUNCTION billing_accounts_guard();
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
        SQL);
        DB::statement('DROP INDEX billing_accounts_one_open_complement');
        DB::statement('ALTER TABLE billing_accounts DROP CONSTRAINT billing_accounts_values_check');
        DB::statement(self::valuesCheck(false));
        DB::statement('ALTER TABLE billing_accounts DROP CONSTRAINT billing_accounts_complement_foreign');
        DB::statement('ALTER TABLE billing_accounts DROP COLUMN complement_of_id, DROP COLUMN closed_by_user_id, DROP COLUMN closed_at');
    }

    private static function valuesCheck(bool $closing): string
    {
        $stay = $closing
            // A complementary account points to its account and has no stay of its own.
            ? '(complement_of_id IS NULL) = (num_nonnulls(appointment_id, emergency_episode_id) = 1) AND num_nonnulls(appointment_id, emergency_episode_id) <= 1'
            : '(appointment_id IS NULL) <> (emergency_episode_id IS NULL)';
        $status = $closing
            ? "status IN ('open', 'closed') AND (status = 'closed') = (closed_at IS NOT NULL) AND (closed_at IS NULL) = (closed_by_user_id IS NULL)"
            : "status IN ('open')";

        return "ALTER TABLE billing_accounts ADD CONSTRAINT billing_accounts_values_check CHECK (
            {$stay}
            AND {$status}
            AND (modality IS NULL OR modality IN ('private', 'insurance', 'sus'))
            AND (modality IS NOT DISTINCT FROM 'insurance') = (insurer_id IS NOT NULL)
            AND (insurer_id IS NULL) = (insurance_card_number IS NULL)
            AND (insurer_id IS NULL) = (insurance_card_valid_until IS NULL)
        )";
    }
};
