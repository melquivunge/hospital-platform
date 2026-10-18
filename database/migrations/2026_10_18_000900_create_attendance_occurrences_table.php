<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 8b: presence occurrences (PRODUCT.md, "Falhas e ajuste administrativo"). The professional
     * reports; a coordinator grants or denies with a reason. Only a granted exit occurrence closes the
     * presence administratively, at the informed time; attendance events are never changed.
     */
    public function up(): void
    {
        Schema::create('attendance_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('professional_id');
            $table->string('kind');
            $table->text('description');
            $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->timestampTz('informed_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampsTz();

            $table->index(['shift_id', 'status']);
            $table->foreign(['tenant_id', 'shift_id'])->references(['tenant_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE attendance_occurrences ADD CONSTRAINT attendance_occurrences_kind_check CHECK (kind IN ('check_in', 'check_out'))");
        DB::statement("ALTER TABLE attendance_occurrences ADD CONSTRAINT attendance_occurrences_status_check CHECK (
            status IN ('open', 'granted', 'denied')
            AND (status = 'open') = (decided_at IS NULL)
            AND (status = 'open' OR (decided_by_user_id IS NOT NULL AND decision_reason IS NOT NULL))
            AND ((status = 'granted' AND kind = 'check_out') = (informed_at IS NOT NULL))
        )");
        // One open occurrence per shift and kind; one administrative exit per shift.
        DB::statement("CREATE UNIQUE INDEX attendance_occurrences_one_open ON attendance_occurrences (shift_id, kind) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX attendance_occurrences_one_exit ON attendance_occurrences (shift_id) WHERE status = 'granted' AND kind = 'check_out'");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_occurrences');
    }
};
