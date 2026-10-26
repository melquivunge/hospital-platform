<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.3b (ADR-0010): the insurer's answer for an item that needs it, recorded by billing by item or by guide
     * (several items with one number): authorized (number and validity), denied (reason) or, without it, urgency
     * (reason). Insert-only: the latest record of an item is its state. Clinical (it ties the patient's items): RLS.
     */
    public function up(): void
    {
        Schema::create('billing_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_account_item_id');
            $table->string('status', 15);
            $table->string('number', 40)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['billing_account_item_id', 'id']);
            $table->foreign(['tenant_id', 'billing_account_item_id'])->references(['tenant_id', 'id'])->on('billing_account_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE billing_authorizations ADD CONSTRAINT billing_authorizations_values_check CHECK (
            (status = 'authorized' AND length(btrim(coalesce(number, ''))) > 0 AND valid_until IS NOT NULL AND reason IS NULL)
            OR (status IN ('denied', 'urgency') AND number IS NULL AND valid_until IS NULL AND length(btrim(coalesce(reason, ''))) > 0)
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_authorizations_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'authorizations are insert-only: the latest one is the item''s state' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER billing_authorizations_insert_only BEFORE UPDATE OR DELETE ON billing_authorizations
                FOR EACH ROW EXECUTE FUNCTION billing_authorizations_insert_only();
            CREATE TRIGGER billing_authorizations_no_truncate BEFORE TRUNCATE ON billing_authorizations
                FOR EACH STATEMENT EXECUTE FUNCTION billing_authorizations_insert_only();
        SQL);
        ClinicalSchema::protect('billing_authorizations');
    }

    public function down(): void
    {
        if (DB::table('billing_authorizations')->exists()) {
            throw new RuntimeException('Authorizations exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('billing_authorizations');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_authorizations_insert_only()');
    }
};
