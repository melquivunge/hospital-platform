<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a.2 (ADR-0012): a guardian's portal account reaches a minor's record. The reception links and ends;
     * each act is a new row (insert only), and the link stops counting at 18, checked when read.
     */
    public function up(): void
    {
        Schema::create('patient_account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('account_id')->constrained('patient_accounts');
            $table->foreignId('patient_id')->constrained('patients');
            $table->string('event', 10);
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['account_id', 'patient_id', 'id']);
            $table->index(['patient_id', 'id']);
        });

        DB::statement("ALTER TABLE patient_account_links ADD CONSTRAINT patient_account_links_event_check CHECK (event IN ('linked', 'ended'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION patient_account_links_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'patient_account_links is append-only';
            END;
            $$;
            CREATE TRIGGER patient_account_links_no_update_delete BEFORE UPDATE OR DELETE ON patient_account_links
                FOR EACH ROW EXECUTE FUNCTION patient_account_links_append_only();
            CREATE TRIGGER patient_account_links_no_truncate BEFORE TRUNCATE ON patient_account_links
                FOR EACH STATEMENT EXECUTE FUNCTION patient_account_links_append_only();
        SQL);
        ClinicalSchema::protect('patient_account_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_account_links');
        DB::unprepared('DROP FUNCTION IF EXISTS patient_account_links_append_only()');
    }
};
