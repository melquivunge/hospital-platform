<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 6.5b2a (TISS 4.03.00): a generated lot's XML file, validated against the official XSD, kept in private
     * storage with its SHA-256 and the epilogue hash. It is written once and never changes; the lot may still be
     * cancelled (before it is sent to the insurer).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE billing_lots ADD COLUMN file_path varchar(255)');
        DB::statement('ALTER TABLE billing_lots ADD COLUMN file_sha256 char(64)');
        DB::statement('ALTER TABLE billing_lots ADD COLUMN file_hash char(32)');
        DB::statement('ALTER TABLE billing_lots ADD COLUMN file_generated_at timestamptz');
        DB::statement('ALTER TABLE billing_lots ADD COLUMN file_generated_by_user_id bigint REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE billing_lots ADD CONSTRAINT billing_lots_file_check CHECK (
            num_nonnulls(file_path, file_sha256, file_hash, file_generated_at, file_generated_by_user_id) IN (0, 5)
            AND (file_sha256 IS NULL OR file_sha256 ~ '^[0-9a-f]{64}$')
            AND (file_hash IS NULL OR file_hash ~ '^[0-9a-f]{32}$')
        )");
        DB::unprepared(<<<'SQL'
            -- A lot changes only by writing its file once (while generated) or by its cancellation; never deleted.
            CREATE OR REPLACE FUNCTION billing_lots_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'lots are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF (NEW.id, NEW.tenant_id, NEW.unit_id, NEW.insurer_id, NEW.competence, NEW.guide_type, NEW.tiss_version, NEW.guide_count, NEW.total_cents, NEW.created_by_user_id, NEW.created_at)
                        IS DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.unit_id, OLD.insurer_id, OLD.competence, OLD.guide_type, OLD.tiss_version, OLD.guide_count, OLD.total_cents, OLD.created_by_user_id, OLD.created_at)
                    OR OLD.status <> 'generated'
                    OR (OLD.file_path IS NOT NULL AND (NEW.file_path, NEW.file_sha256, NEW.file_hash, NEW.file_generated_at, NEW.file_generated_by_user_id)
                        IS DISTINCT FROM (OLD.file_path, OLD.file_sha256, OLD.file_hash, OLD.file_generated_at, OLD.file_generated_by_user_id))
                    OR (NEW.status = 'generated' AND OLD.file_path IS NOT NULL)
                    OR (NEW.status = 'cancelled' AND NEW.file_path IS DISTINCT FROM OLD.file_path) THEN
                    RAISE EXCEPTION 'a lot only changes by its file, written once, or its cancellation' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::table('billing_lots')->whereNotNull('file_path')->exists()) {
            throw new RuntimeException('Lot files exist: this migration cannot be rolled back.');
        }
        DB::unprepared(<<<'SQL'
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
        SQL);
        DB::statement('ALTER TABLE billing_lots DROP CONSTRAINT billing_lots_file_check');
        DB::statement('ALTER TABLE billing_lots DROP COLUMN file_generated_by_user_id, DROP COLUMN file_generated_at, DROP COLUMN file_hash, DROP COLUMN file_sha256, DROP COLUMN file_path');
    }
};
