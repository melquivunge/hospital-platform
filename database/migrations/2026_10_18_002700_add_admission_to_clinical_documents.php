<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 4.4b (ADR-0004): documents are attached during a visit or an admission (exactly one). The guard of
     * step 3.7 compares the whole row, so the new column is immutable too.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE clinical_documents ALTER COLUMN visit_id DROP NOT NULL');
        DB::statement('ALTER TABLE clinical_documents ADD COLUMN admission_id bigint');
        DB::statement('ALTER TABLE clinical_documents ADD CONSTRAINT clinical_documents_admission_foreign FOREIGN KEY (tenant_id, admission_id) REFERENCES admissions (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_documents ADD CONSTRAINT clinical_documents_origin_check CHECK (num_nonnulls(visit_id, admission_id) = 1)');
    }

    public function down(): void
    {
        // Documents are never deleted: with admission documents, there is no way back.
        if (DB::table('clinical_documents')->whereNotNull('admission_id')->exists()) {
            throw new RuntimeException('Admission documents exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_documents DROP CONSTRAINT clinical_documents_origin_check');
        DB::statement('ALTER TABLE clinical_documents DROP CONSTRAINT clinical_documents_admission_foreign');
        DB::statement('ALTER TABLE clinical_documents DROP COLUMN admission_id');
        DB::statement('ALTER TABLE clinical_documents ALTER COLUMN visit_id SET NOT NULL');
    }
};
