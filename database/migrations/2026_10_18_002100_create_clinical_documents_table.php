<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.7: documents attached to a visit (PDF, JPG or PNG up to 10 MB). The file lives on the private
     * disk under a per-client prefix with a random name; this row keeps its metadata. Nothing is deleted:
     * withdrawing needs a reason and keeps the file and the row. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('clinical_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('visit_id');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->string('original_name', 255);
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');
            $table->string('path', 255)->unique();
            $table->char('sha256', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('withdraw_reason', 255)->nullable();

            $table->index(['tenant_id', 'patient_id']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        // The path always sits under the row's own client prefix: a row can never point at another client's file.
        DB::statement("ALTER TABLE clinical_documents ADD CONSTRAINT clinical_documents_values_check CHECK (
            mime_type IN ('application/pdf', 'image/jpeg', 'image/png')
            AND size_bytes BETWEEN 1 AND 10485760
            AND path LIKE 'clinical/' || tenant_id || '/%'
            AND (withdrawn_at IS NULL) = (withdrawn_by_user_id IS NULL)
            AND (withdrawn_at IS NULL) = (withdraw_reason IS NULL)
        )");
        // Only the withdrawal may be recorded, once; the row is never deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinical_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'clinical documents are never deleted' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.withdrawn_at IS NOT NULL
                    OR (to_jsonb(NEW) - 'withdrawn_at' - 'withdrawn_by_user_id' - 'withdraw_reason')
                        <> (to_jsonb(OLD) - 'withdrawn_at' - 'withdrawn_by_user_id' - 'withdraw_reason') THEN
                    RAISE EXCEPTION 'a clinical document only records its withdrawal, once' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER clinical_documents_guard BEFORE UPDATE OR DELETE ON clinical_documents
                FOR EACH ROW EXECUTE FUNCTION clinical_documents_guard();
            CREATE TRIGGER clinical_documents_no_truncate BEFORE TRUNCATE ON clinical_documents
                FOR EACH STATEMENT EXECUTE FUNCTION clinical_documents_guard();
        SQL);

        ClinicalSchema::protect('clinical_documents');
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_documents');
        DB::unprepared('DROP FUNCTION IF EXISTS clinical_documents_guard()');
    }
};
