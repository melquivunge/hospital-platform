<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 8a: presence by location (V1-09). Points are immutable (only deactivated), so the point id
     * recorded with a decision is the configuration version used. Events are append-only and keep both
     * the device measurement instant and the server receipt instant.
     */
    public function up(): void
    {
        Schema::create('attendance_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->string('name');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedInteger('radius_m');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'deactivated_at']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE attendance_points ADD CONSTRAINT attendance_points_coordinates_check CHECK (
            latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND radius_m > 0
            AND (deactivated_at IS NULL) = (deactivated_by_user_id IS NULL)
        )');

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('professional_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->string('result');
            $table->string('reason')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->decimal('accuracy_m', 8, 1)->nullable();
            $table->timestampTz('measured_at')->nullable();
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('point_id')->nullable();
            $table->decimal('distance_m', 10, 1)->nullable();
            $table->jsonb('policy_version_ids')->nullable();
            $table->uuid('idempotency_key');

            $table->index(['shift_id', 'kind']);
            $table->unique(['user_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'shift_id'])->references(['tenant_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'point_id'])->references(['tenant_id', 'id'])->on('attendance_points')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE attendance_events ADD CONSTRAINT attendance_events_kind_check CHECK (kind IN ('check_in', 'check_out'))");
        DB::statement("ALTER TABLE attendance_events ADD CONSTRAINT attendance_events_result_check CHECK (
            (result = 'accepted' AND reason IS NULL AND point_id IS NOT NULL AND distance_m IS NOT NULL)
            OR (result = 'rejected' AND reason IS NOT NULL)
        )");
        // One confirmed entry and one confirmed exit per shift.
        DB::statement("CREATE UNIQUE INDEX attendance_events_one_accepted ON attendance_events (shift_id, kind) WHERE result = 'accepted'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attendance_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'attendance_events is append-only';
            END;
            $$;
            CREATE TRIGGER attendance_events_no_update_delete BEFORE UPDATE OR DELETE ON attendance_events
                FOR EACH ROW EXECUTE FUNCTION attendance_events_append_only();
            CREATE TRIGGER attendance_events_no_truncate BEFORE TRUNCATE ON attendance_events
                FOR EACH STATEMENT EXECUTE FUNCTION attendance_events_append_only();

            -- A point is never edited: it can only be deactivated once.
            CREATE OR REPLACE FUNCTION attendance_points_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR OLD.deactivated_at IS NOT NULL
                    OR (NEW.tenant_id, NEW.unit_id, NEW.name, NEW.latitude, NEW.longitude, NEW.radius_m, NEW.created_by_user_id, NEW.created_at)
                        IS DISTINCT FROM (OLD.tenant_id, OLD.unit_id, OLD.name, OLD.latitude, OLD.longitude, OLD.radius_m, OLD.created_by_user_id, OLD.created_at)
                THEN
                    RAISE EXCEPTION 'attendance_points can only be deactivated';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER attendance_points_only_deactivate BEFORE UPDATE OR DELETE ON attendance_points
                FOR EACH ROW EXECUTE FUNCTION attendance_points_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_points');
        DB::unprepared('DROP FUNCTION IF EXISTS attendance_events_append_only(); DROP FUNCTION IF EXISTS attendance_points_immutable();');
    }
};
