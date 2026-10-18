<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a: direct transfer of a shift (V1-06/V1-08). requested → accepted by the recipient → approved
     * by the shift unit's coordinator; refusal, cancellation and expiration are terminal. The shift only
     * changes hands on approval.
     */
    public function up(): void
    {
        Schema::create('shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('requester_professional_id');
            $table->unsignedBigInteger('recipient_professional_id');
            $table->string('status');
            $table->text('note')->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recipient_decided_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampsTz();

            $table->index(['shift_id', 'status']);
            $table->index(['recipient_professional_id', 'status']);
            $table->index(['requester_professional_id', 'status']);
            $table->foreign(['tenant_id', 'shift_id'])->references(['tenant_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requester_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recipient_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE shift_requests ADD CONSTRAINT shift_requests_type_check CHECK (type IN ('transfer'))");
        DB::statement("ALTER TABLE shift_requests ADD CONSTRAINT shift_requests_status_check CHECK (status IN ('pending_recipient', 'pending_coordinator', 'completed', 'refused', 'cancelled', 'expired'))");
        DB::statement('ALTER TABLE shift_requests ADD CONSTRAINT shift_requests_parties_check CHECK (requester_professional_id <> recipient_professional_id)');
        // Resending the same intent never creates a second change: one pending request per shift.
        DB::statement("CREATE UNIQUE INDEX shift_requests_one_pending ON shift_requests (shift_id) WHERE status IN ('pending_recipient', 'pending_coordinator')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_requests');
    }
};
