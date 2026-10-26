<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.2a (ADR-0010): the patient's account, one per stay: a consultation (opened on its arrival) or an
     * emergency episode (opened on its arrival; when it becomes an admission, the same account is the admission's).
     * It copies the stay's modality and card. Its items come from the care records, each pointing to the one that
     * generated it: a record generates at most one item (unique origin), however often or concurrently the generation
     * runs. The price is frozen in cents, null while there is none (a pending item). Clinical: forced RLS.
     */
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('appointment_id')->nullable()->unique();
            $table->unsignedBigInteger('emergency_episode_id')->nullable()->unique();
            $table->string('status', 20)->default('open');
            $table->string('modality', 20)->nullable();
            $table->unsignedBigInteger('insurer_id')->nullable();
            $table->string('insurance_card_number', 40)->nullable();
            $table->date('insurance_card_valid_until')->nullable();
            $table->timestampTz('opened_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'status']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'emergency_episode_id'])->references(['tenant_id', 'id'])->on('emergency_episodes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->restrictOnDelete();
        });
        // ponytail: only 'open' until review and closing (steps 6.3 and 6.4) widen it.
        DB::statement("ALTER TABLE billing_accounts ADD CONSTRAINT billing_accounts_values_check CHECK (
            (appointment_id IS NULL) <> (emergency_episode_id IS NULL)
            AND status IN ('open')
            AND (modality IS NULL OR modality IN ('private', 'insurance', 'sus'))
            AND (modality IS NOT DISTINCT FROM 'insurance') = (insurer_id IS NOT NULL)
            AND (insurer_id IS NULL) = (insurance_card_number IS NULL)
            AND (insurer_id IS NULL) = (insurance_card_valid_until IS NULL)
        )");

        Schema::create('billing_account_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('billing_account_id');
            // The care record that generated it: a closed visit, a released exam of an order, a completed surgery.
            $table->string('origin_type', 30);
            $table->unsignedBigInteger('origin_id');
            $table->unsignedBigInteger('billing_item_id')->nullable();
            $table->string('code', 20)->nullable();
            $table->string('name', 255);
            $table->integer('quantity');
            $table->bigInteger('unit_price_cents')->nullable();
            $table->boolean('needs_authorization');
            $table->date('performed_on');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['origin_type', 'origin_id']);
            $table->index('billing_account_id');
            $table->foreign(['tenant_id', 'billing_account_id'])->references(['tenant_id', 'id'])->on('billing_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_item_id'])->references(['tenant_id', 'id'])->on('billing_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE billing_account_items ADD CONSTRAINT billing_account_items_values_check CHECK (
            origin_type IN ('visit', 'exam_order_item', 'surgery')
            AND length(btrim(name)) > 0 AND quantity <> 0
            AND (unit_price_cents IS NULL OR unit_price_cents BETWEEN 0 AND 100000000000)
        )");
        // An item is never changed nor deleted: a correction is a new, compensating item (step 6.3).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_account_items_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'an account item is never changed nor deleted' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER billing_account_items_insert_only BEFORE UPDATE OR DELETE ON billing_account_items
                FOR EACH ROW EXECUTE FUNCTION billing_account_items_insert_only();
            CREATE TRIGGER billing_account_items_no_truncate BEFORE TRUNCATE ON billing_account_items
                FOR EACH STATEMENT EXECUTE FUNCTION billing_account_items_insert_only();
        SQL);

        foreach (['billing_accounts', 'billing_account_items'] as $table) {
            ClinicalSchema::protect($table);
        }
    }

    public function down(): void
    {
        if (DB::table('billing_accounts')->exists()) {
            throw new RuntimeException('Billing accounts exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('billing_account_items');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_account_items_insert_only()');
        Schema::dropIfExists('billing_accounts');
    }
};
