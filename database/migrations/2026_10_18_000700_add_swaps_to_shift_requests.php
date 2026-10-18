<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7b: swap of two shifts (V1-06). The requester's shift is shift_id and the colleague's is
     * counterpart_shift_id. Each shift's unit approves; when both shifts are in the same unit, one approval
     * covers both. The swap applies both sides or none.
     */
    public function up(): void
    {
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('counterpart_shift_id')->nullable();
            $table->foreignId('origin_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('origin_approved_at')->nullable();
            $table->foreignId('counterpart_approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('counterpart_approved_at')->nullable();

            $table->index(['counterpart_shift_id', 'status']);
            $table->foreign(['tenant_id', 'counterpart_shift_id'])->references(['tenant_id', 'id'])->on('shifts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT shift_requests_type_check');
        DB::statement("ALTER TABLE shift_requests ADD CONSTRAINT shift_requests_type_check CHECK (type IN ('transfer', 'swap'))");
        DB::statement("ALTER TABLE shift_requests ADD CONSTRAINT shift_requests_counterpart_check CHECK (
            (type = 'swap') = (counterpart_shift_id IS NOT NULL) AND counterpart_shift_id IS DISTINCT FROM shift_id
        )");
        DB::statement("CREATE UNIQUE INDEX shift_requests_one_pending_counterpart ON shift_requests (counterpart_shift_id) WHERE status IN ('pending_recipient', 'pending_coordinator')");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM shift_requests WHERE type = 'swap'");
        DB::statement('DROP INDEX shift_requests_one_pending_counterpart');
        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT shift_requests_counterpart_check');
        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT shift_requests_type_check');
        DB::statement("ALTER TABLE shift_requests ADD CONSTRAINT shift_requests_type_check CHECK (type IN ('transfer'))");

        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'counterpart_shift_id']);
            $table->dropIndex(['counterpart_shift_id', 'status']);
            $table->dropConstrainedForeignId('origin_approved_by_user_id');
            $table->dropConstrainedForeignId('counterpart_approved_by_user_id');
            $table->dropColumn(['counterpart_shift_id', 'origin_approved_at', 'counterpart_approved_at']);
        });
    }
};
