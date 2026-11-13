<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a.4 (ADR-0012): the professional who attached a document releases it to the patient's portal, or revokes
     * the release. Each act is a new row (insert only); the latest decides.
     */
    public function up(): void
    {
        Schema::create('clinical_document_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('clinical_document_id')->constrained('clinical_documents');
            $table->string('event', 10);
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['clinical_document_id', 'id']);
        });

        DB::statement("ALTER TABLE clinical_document_releases ADD CONSTRAINT clinical_document_releases_event_check CHECK (event IN ('released', 'revoked'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinical_document_releases_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'clinical_document_releases is append-only';
            END;
            $$;
            CREATE TRIGGER clinical_document_releases_no_update_delete BEFORE UPDATE OR DELETE ON clinical_document_releases
                FOR EACH ROW EXECUTE FUNCTION clinical_document_releases_append_only();
            CREATE TRIGGER clinical_document_releases_no_truncate BEFORE TRUNCATE ON clinical_document_releases
                FOR EACH STATEMENT EXECUTE FUNCTION clinical_document_releases_append_only();
        SQL);
        ClinicalSchema::protect('clinical_document_releases');
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_document_releases');
        DB::unprepared('DROP FUNCTION IF EXISTS clinical_document_releases_append_only()');
    }
};
