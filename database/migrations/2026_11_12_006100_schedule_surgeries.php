<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5b.3a (ADR-0009): the surgical center books a room of the surgery's unit, the start and the end, and the
     * team. Two scheduled surgeries never overlap in the same room (exclusion constraint). A reschedule keeps the
     * previous booking and the reason; team members are removed, never deleted. Clinical (RLS).
     */
    public function up(): void
    {
        // The room must belong to the surgery's unit: composite key.
        Schema::table('operating_rooms', fn (Blueprint $table) => $table->unique(['unit_id', 'id']));

        Schema::table('surgeries', function (Blueprint $table) {
            $table->unsignedBigInteger('operating_room_id')->nullable();
            $table->timestampTz('scheduled_start')->nullable();
            $table->timestampTz('scheduled_end')->nullable();
            $table->foreignId('scheduled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('scheduled_at')->nullable();
            // An urgent surgery is booked at once, with the reason (plan 5b.3).
            $table->string('urgent_reason', 500)->nullable();
            $table->foreign(['unit_id', 'operating_room_id'])->references(['unit_id', 'id'])->on('operating_rooms')->restrictOnDelete();
            $table->index(['operating_room_id', 'scheduled_start']);
        });
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_values_check');
        DB::statement("ALTER TABLE surgeries ADD CONSTRAINT surgeries_values_check CHECK (
            (admission_id IS NULL) <> (visit_id IS NULL)
            AND laterality IN ('none', 'left', 'right', 'bilateral')
            AND urgency IN ('elective', 'urgent')
            AND length(btrim(procedure_name)) > 0
            AND length(btrim(indication)) > 0
            AND signature_method IN ('password')
            AND status IN ('requested', 'scheduled', 'cancelled')
            AND ((status = 'cancelled') = (cancelled_at IS NOT NULL))
            AND ((cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                OR (cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND length(btrim(coalesce(cancellation_reason, ''))) > 0))
            AND ((operating_room_id IS NULL AND scheduled_start IS NULL AND scheduled_end IS NULL AND scheduled_by_user_id IS NULL AND scheduled_at IS NULL)
                OR (operating_room_id IS NOT NULL AND scheduled_start IS NOT NULL AND scheduled_end IS NOT NULL AND scheduled_by_user_id IS NOT NULL AND scheduled_at IS NOT NULL
                    AND scheduled_end > scheduled_start AND scheduled_end - scheduled_start <= interval '24 hours'))
            AND (status <> 'requested' OR operating_room_id IS NULL)
            AND (status <> 'scheduled' OR operating_room_id IS NOT NULL)
            AND (urgent_reason IS NULL OR (urgency = 'urgent' AND length(btrim(urgent_reason)) > 0))
        )");
        DB::statement("ALTER TABLE surgeries ADD CONSTRAINT surgeries_no_room_overlap EXCLUDE USING gist (
            operating_room_id WITH =, tstzrange(scheduled_start, scheduled_end) WITH &&
        ) WHERE (status = 'scheduled')");

        Schema::create('surgery_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('surgery_id');
            $table->unsignedBigInteger('professional_id');
            $table->string('role', 15);
            $table->foreignId('added_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('added_at');
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('removed_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'professional_id']);
            $table->foreign(['tenant_id', 'surgery_id'])->references(['tenant_id', 'id'])->on('surgeries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE surgery_team_members ADD CONSTRAINT surgery_team_members_values_check CHECK (
            role IN ('surgeon', 'assistant', 'anesthetist', 'scrub', 'circulating')
            AND ((removed_at IS NULL) = (removed_by_user_id IS NULL))
            AND (removed_at IS NULL OR removed_at >= added_at)
        )");
        // One active place per professional in a surgery.
        DB::statement('CREATE UNIQUE INDEX surgery_team_members_active ON surgery_team_members (surgery_id, professional_id) WHERE removed_at IS NULL');

        Schema::create('surgery_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('surgery_id');
            $table->unsignedBigInteger('previous_room_id');
            $table->timestampTz('previous_start');
            $table->timestampTz('previous_end');
            $table->string('reason', 500);
            $table->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'surgery_id']);
            $table->foreign(['tenant_id', 'surgery_id'])->references(['tenant_id', 'id'])->on('surgeries')->restrictOnDelete();
            $table->foreign('previous_room_id')->references('id')->on('operating_rooms')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE surgery_reschedules ADD CONSTRAINT surgery_reschedules_values_check CHECK (length(btrim(reason)) > 0 AND previous_end > previous_start)');

        ClinicalSchema::protect('surgery_team_members');
        ClinicalSchema::protect('surgery_reschedules');
    }

    public function down(): void
    {
        if (DB::table('surgeries')->whereNotNull('operating_room_id')->exists() || DB::table('surgery_team_members')->exists()) {
            throw new RuntimeException('Surgeries were scheduled: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('surgery_reschedules');
        Schema::dropIfExists('surgery_team_members');
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_no_room_overlap');
        DB::statement('ALTER TABLE surgeries DROP CONSTRAINT surgeries_values_check');
        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropForeign(['unit_id', 'operating_room_id']);
            $table->dropColumn(['operating_room_id', 'scheduled_start', 'scheduled_end', 'scheduled_by_user_id', 'scheduled_at', 'urgent_reason']);
        });
        DB::statement("ALTER TABLE surgeries ADD CONSTRAINT surgeries_values_check CHECK (
            (admission_id IS NULL) <> (visit_id IS NULL)
            AND laterality IN ('none', 'left', 'right', 'bilateral')
            AND urgency IN ('elective', 'urgent')
            AND length(btrim(procedure_name)) > 0
            AND length(btrim(indication)) > 0
            AND signature_method IN ('password')
            AND ((status = 'requested' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND length(btrim(coalesce(cancellation_reason, ''))) > 0))
        )");
        Schema::table('operating_rooms', fn (Blueprint $table) => $table->dropUnique(['unit_id', 'id']));
    }
};
