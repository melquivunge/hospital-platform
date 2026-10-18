<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.4b: reception books patients into the schedule grid. Ties a patient to a time, so it is a
     * clinical table (RLS). Nothing is deleted: cancelling keeps the row with its reason, rescheduling
     * cancels and links the new booking to the old one, a no-show is marked after the time.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('patient_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('walk_in')->default(false);
            $table->string('status', 20)->default('scheduled');
            $table->unsignedBigInteger('rescheduled_from_id')->nullable()->unique();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'starts_at']);
            $table->index(['tenant_id', 'patient_id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rescheduled_from_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_values_check CHECK (
            ends_at > starts_at
            AND status IN ('scheduled', 'cancelled', 'no_show')
            AND (status = 'scheduled') = (closed_at IS NULL)
            AND (closed_at IS NULL) = (closed_by_user_id IS NULL)
            AND (status = 'cancelled') = (cancel_reason IS NOT NULL)
            AND rescheduled_from_id IS DISTINCT FROM id
        )");
        // A grid slot holds one booking: two live bookings of the same professional never overlap, unless
        // one of them is an explicit walk-in (delivery 3 contract).
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_no_double_booking EXCLUDE USING gist (
            professional_id WITH =, tstzrange(starts_at, ends_at) WITH &&
        ) WHERE (status <> 'cancelled' AND NOT walk_in)");

        ClinicalSchema::protect('appointments');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
