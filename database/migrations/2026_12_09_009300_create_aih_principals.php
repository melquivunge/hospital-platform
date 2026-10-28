<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.5b2 (ADR-0011): the principal procedure of a SUS admission's AIH, insert-only per stay account: it starts
     * as the AIH request report's (and follows its new versions) until billing changes it with a reason. The current
     * one is billed as an item "aih_principal"; the previous one's item is compensated. Clinical: RLS.
     */
    public function up(): void
    {
        Schema::create('aih_principals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_account_id');
            $table->char('procedure_code', 10);
            $table->string('procedure_name', 250);
            $table->unsignedBigInteger('aih_request_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');

            $table->index('billing_account_id');
            $table->foreign(['tenant_id', 'billing_account_id'])->references(['tenant_id', 'id'])->on('billing_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aih_request_id'])->references(['tenant_id', 'id'])->on('aih_requests')->restrictOnDelete();
        });
        // From the report, or billing's change with its reason.
        DB::statement("ALTER TABLE aih_principals ADD CONSTRAINT aih_principals_values_check CHECK (
            procedure_code ~ '^[0-9]{10}$' AND (aih_request_id IS NULL) = (reason IS NOT NULL) AND (reason IS NULL OR length(btrim(reason)) > 0)
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aih_principals_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'an AIH principal procedure is never changed nor deleted' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER aih_principals_guard BEFORE UPDATE OR DELETE ON aih_principals FOR EACH ROW EXECUTE FUNCTION aih_principals_guard();
            CREATE TRIGGER aih_principals_no_truncate BEFORE TRUNCATE ON aih_principals FOR EACH STATEMENT EXECUTE FUNCTION aih_principals_guard();
        SQL);
        ClinicalSchema::protect('aih_principals');
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_values_check');
        DB::statement(self::valuesCheck(true));
    }

    public function down(): void
    {
        if (DB::table('billing_account_items')->where('origin_type', 'aih_principal')->exists()) {
            throw new RuntimeException('AIH principal items exist: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE billing_account_items DROP CONSTRAINT billing_account_items_values_check');
        DB::statement(self::valuesCheck(false));
        Schema::dropIfExists('aih_principals');
        DB::unprepared('DROP FUNCTION IF EXISTS aih_principals_guard()');
    }

    /** As in step 6.3a, with the AIH's principal procedure as an origin. */
    private static function valuesCheck(bool $principal): string
    {
        $types = "'visit', 'exam_order_item', 'surgery', 'admission_day', 'stock_movement', 'dispensing', 'dispensing_return', 'manual', 'compensation'".($principal ? ", 'aih_principal'" : '');

        return "ALTER TABLE billing_account_items ADD CONSTRAINT billing_account_items_values_check CHECK (
            origin_type IN ({$types})
            AND length(btrim(name)) > 0 AND quantity <> 0
            AND (unit_price_cents IS NULL OR unit_price_cents BETWEEN 0 AND 100000000000)
            AND (origin_type = 'manual') = (origin_id IS NULL)
            AND (origin_type IN ('manual', 'compensation')) = (reason IS NOT NULL)
            AND (reason IS NULL) = (author_user_id IS NULL)
            AND (reason IS NULL OR length(btrim(reason)) > 0)
        )";
    }
};
