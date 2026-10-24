<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5.4 (ADR-0008): the released result of a collected or performed item, signed with the password: text,
     * values per analyte (laboratory, with the reference as released) and a PDF. Never changed nor deleted; a
     * rectification is the next version, with its reason, and the previous one stays visible. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('exam_order_id');
            $table->unsignedBigInteger('exam_order_item_id');
            $table->unsignedSmallInteger('version');
            $table->string('rectification_reason', 500)->nullable();
            $table->text('report')->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('attachment_sha256', 64)->nullable();
            $table->foreignId('released_by_user_id')->constrained('users')->restrictOnDelete();
            // The signer's professional: required for imaging (CRM), recorded for laboratory when linked.
            $table->unsignedBigInteger('professional_id')->nullable();
            $table->string('signature_method', 20);
            $table->timestampTz('released_at');

            $table->unique(['tenant_id', 'id']);
            $table->unique(['exam_order_item_id', 'version']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'exam_order_id']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'exam_order_id'])->references(['tenant_id', 'id'])->on('exam_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'exam_order_item_id'])->references(['tenant_id', 'id'])->on('exam_order_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE exam_results ADD CONSTRAINT exam_results_values_check CHECK (
            version >= 1 AND (version = 1) = (rectification_reason IS NULL)
            AND (rectification_reason IS NULL OR length(btrim(rectification_reason)) > 0)
            AND (report IS NULL OR length(btrim(report)) > 0)
            AND (attachment_path IS NULL) = (attachment_name IS NULL)
            AND (attachment_path IS NULL) = (attachment_size IS NULL)
            AND (attachment_path IS NULL) = (attachment_sha256 IS NULL)
            AND signature_method IN ('password')
        )");

        Schema::create('exam_result_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('exam_result_id');
            $table->unsignedBigInteger('exam_analyte_id');
            // As released: a later change of the catalog does not rewrite the result.
            $table->string('analyte_name', 100);
            $table->string('unit', 30)->nullable();
            $table->decimal('reference_min', 14, 4)->nullable();
            $table->decimal('reference_max', 14, 4)->nullable();
            $table->string('reference_text', 255)->nullable();
            $table->decimal('value_numeric', 14, 4)->nullable();
            $table->string('value_text', 100)->nullable();
            // Below or above the reference range, decided by the server.
            $table->string('flag', 4)->nullable();

            $table->unique(['exam_result_id', 'exam_analyte_id']);
            $table->foreign(['tenant_id', 'exam_result_id'])->references(['tenant_id', 'id'])->on('exam_results')->restrictOnDelete();
            $table->foreign(['tenant_id', 'exam_analyte_id'])->references(['tenant_id', 'id'])->on('exam_analytes')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE exam_result_values ADD CONSTRAINT exam_result_values_check CHECK (
            (value_numeric IS NULL) <> (value_text IS NULL)
            AND (value_text IS NULL OR length(btrim(value_text)) > 0)
            AND (flag IS NULL OR (flag IN ('low', 'high') AND value_numeric IS NOT NULL))
        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_results_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'released exam results never change' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'exam_results' AND NOT EXISTS (
                    SELECT 1 FROM exam_order_items
                    WHERE id = NEW.exam_order_item_id AND exam_order_id = NEW.exam_order_id AND collected_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'a result needs its item collected or performed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER exam_results_guard BEFORE INSERT OR UPDATE OR DELETE ON exam_results
                FOR EACH ROW EXECUTE FUNCTION exam_results_guard();
            CREATE TRIGGER exam_results_no_truncate BEFORE TRUNCATE ON exam_results
                FOR EACH STATEMENT EXECUTE FUNCTION exam_results_guard();
            CREATE TRIGGER exam_result_values_guard BEFORE UPDATE OR DELETE ON exam_result_values
                FOR EACH ROW EXECUTE FUNCTION exam_results_guard();
            CREATE TRIGGER exam_result_values_no_truncate BEFORE TRUNCATE ON exam_result_values
                FOR EACH STATEMENT EXECUTE FUNCTION exam_results_guard();
        SQL);

        ClinicalSchema::protect('exam_results');
        ClinicalSchema::protect('exam_result_values');
    }

    public function down(): void
    {
        if (DB::table('exam_results')->exists()) {
            throw new RuntimeException('Exam results exist and are never deleted: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('exam_result_values');
        Schema::dropIfExists('exam_results');
        DB::unprepared('DROP FUNCTION IF EXISTS exam_results_guard()');
    }
};
