<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7b.1 (ADR-0012): the QR the reception hands over on arrival. It opens only that arrival's journey, without
     * an account; only a hash of the token is kept, and it can be revoked.
     */
    public function up(): void
    {
        Schema::create('visit_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->foreignId('emergency_episode_id')->nullable()->constrained();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE visit_passes ADD CONSTRAINT visit_passes_origin_check CHECK ((appointment_id IS NULL) <> (emergency_episode_id IS NULL))');
        DB::statement('ALTER TABLE visit_passes ADD CONSTRAINT visit_passes_revoked_check CHECK ((revoked_at IS NULL) = (revoked_by_user_id IS NULL))');
        ClinicalSchema::protect('visit_passes');
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_passes');
    }
};
