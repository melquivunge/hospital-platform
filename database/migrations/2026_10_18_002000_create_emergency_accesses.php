<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.6b: emergency access to a record without an open visit. Justified, granted at once for 4 hours
     * (user decision, 18/10/2026), read-only; renewing is a new grant with a new justification. Insert-only,
     * clinical (RLS). Reads made under it point to the grant.
     */
    public function up(): void
    {
        Schema::create('emergency_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('unit_id');
            $table->text('justification');
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['user_id', 'patient_id', 'expires_at']);
            $table->index(['tenant_id', 'granted_at']);
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE emergency_accesses ADD CONSTRAINT emergency_accesses_values_check CHECK (
            expires_at = granted_at + interval '4 hours' AND length(btrim(justification)) >= 20
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION emergency_accesses_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'emergency accesses are insert-only' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER emergency_accesses_insert_only BEFORE UPDATE OR DELETE ON emergency_accesses
                FOR EACH ROW EXECUTE FUNCTION emergency_accesses_insert_only();
            CREATE TRIGGER emergency_accesses_no_truncate BEFORE TRUNCATE ON emergency_accesses
                FOR EACH STATEMENT EXECUTE FUNCTION emergency_accesses_insert_only();
        SQL);
        ClinicalSchema::protect('emergency_accesses');

        Schema::table('clinical_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('emergency_access_id')->nullable();
            $table->foreign(['tenant_id', 'emergency_access_id'])->references(['tenant_id', 'id'])->on('emergency_accesses')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only: emergency reads kept from before a rollback have no grant to point to, and the
        // insert-only trail cannot be fixed afterwards.
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
        ) NOT VALID");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        Schema::table('clinical_reads', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'emergency_access_id']);
            $table->dropColumn('emergency_access_id');
        });
        // Emergency reads stay (the trail is insert-only): the old rule applies to new rows only.
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency') AND (reason = 'visit') = (visit_id IS NOT NULL)
        ) NOT VALID");
        Schema::dropIfExists('emergency_accesses');
        DB::unprepared('DROP FUNCTION IF EXISTS emergency_accesses_insert_only()');
    }
};
