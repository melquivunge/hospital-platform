<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5.2 (delivery 5): the exam order of an open visit or an active admission, by a professional who attends
     * and is qualified in the unit, signed with the password when created. Items keep the exam's name and type as
     * ordered. Cancelled by its author, with a reason, before anything is collected. Clinical (RLS).
     */
    public function up(): void
    {
        Schema::create('exam_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('admission_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->string('priority', 10);
            $table->string('indication', 1000);
            $table->string('status', 10)->default('requested');
            $table->string('signature_method', 20);
            $table->timestampTz('created_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['unit_id', 'status']);
            $table->index(['tenant_id', 'visit_id']);
            $table->index(['tenant_id', 'admission_id']);
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'visit_id'])->references(['tenant_id', 'id'])->on('visits')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE exam_orders ADD CONSTRAINT exam_orders_values_check CHECK (
            (admission_id IS NULL) <> (visit_id IS NULL)
            AND priority IN ('routine', 'urgent')
            AND length(btrim(indication)) > 0
            AND signature_method IN ('password')
            AND ((status = 'requested' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND length(btrim(coalesce(cancellation_reason, ''))) > 0))
        )");

        Schema::create('exam_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('exam_order_id');
            $table->unsignedBigInteger('exam_id');
            // As ordered: a later change of the catalog does not rewrite the order.
            $table->string('exam_name', 255);
            $table->string('exam_type', 20);

            $table->unique(['tenant_id', 'id']);
            $table->unique(['exam_order_id', 'exam_id']);
            $table->foreign(['tenant_id', 'exam_order_id'])->references(['tenant_id', 'id'])->on('exam_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'exam_id'])->references(['tenant_id', 'id'])->on('exams')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE exam_order_items ADD CONSTRAINT exam_order_items_values_check CHECK (exam_type IN ('laboratory', 'imaging'))");

        ClinicalSchema::protect('exam_orders');
        ClinicalSchema::protect('exam_order_items');
    }

    public function down(): void
    {
        if (DB::table('exam_orders')->exists()) {
            throw new RuntimeException('Exam orders exist and are never deleted: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('exam_order_items');
        Schema::dropIfExists('exam_orders');
    }
};
