<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.4b (ADR-0010): the private account's payment, once its account closed: the total in cents and how it was
     * paid (informative, no integration); the receipt is printed from it. One per account, never changed nor deleted.
     * Clinical (it ties the patient to what was done): RLS.
     */
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_account_id')->unique();
            $table->string('method', 15);
            $table->bigInteger('amount_cents');
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at');

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'billing_account_id'])->references(['tenant_id', 'id'])->on('billing_accounts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE billing_payments ADD CONSTRAINT billing_payments_values_check CHECK (
            method IN ('cash', 'debit', 'credit', 'pix', 'transfer') AND amount_cents BETWEEN 1 AND 100000000000
        )");
        DB::unprepared(<<<'SQL'
            -- Insert-only, and only into a closed private account.
            CREATE OR REPLACE FUNCTION billing_payments_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'a payment is never changed nor deleted' USING ERRCODE = 'P0001';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM billing_accounts WHERE id = NEW.billing_account_id AND status = 'closed' AND modality = 'private') THEN
                    RAISE EXCEPTION 'a payment is recorded for a closed private account' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER billing_payments_guard BEFORE INSERT OR UPDATE OR DELETE ON billing_payments FOR EACH ROW EXECUTE FUNCTION billing_payments_guard();
            CREATE TRIGGER billing_payments_no_truncate BEFORE TRUNCATE ON billing_payments FOR EACH STATEMENT EXECUTE FUNCTION billing_payments_guard();
        SQL);
        ClinicalSchema::protect('billing_payments');
    }

    public function down(): void
    {
        if (DB::table('billing_payments')->exists()) {
            throw new RuntimeException('Payments exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('billing_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_payments_guard()');
    }
};
