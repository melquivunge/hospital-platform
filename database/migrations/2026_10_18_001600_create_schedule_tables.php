<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.4a: outpatient schedule grid per professional and unit (contract: fixed consultation length).
     * Rules are never edited, only deactivated and replaced, so past bookings keep their meaning. Times are
     * the unit's wall clock (minutes from midnight). No patient data here: not a clinical table.
     */
    public function up(): void
    {
        Schema::create('schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('professional_id');
            $table->unsignedSmallInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->unsignedSmallInteger('slot_minutes');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique(['tenant_id', 'id']);
            $table->index(['unit_id', 'professional_id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE schedule_rules ADD CONSTRAINT schedule_rules_values_check CHECK (
            weekday BETWEEN 1 AND 7 AND start_minute < end_minute AND end_minute <= 1440
            AND slot_minutes BETWEEN 5 AND 240 AND (deactivated_at IS NULL) = (deactivated_by_user_id IS NULL)
        )');
        // One professional is never in two places at once: active rules of the same weekday cannot overlap,
        // even in different units.
        DB::statement('ALTER TABLE schedule_rules ADD CONSTRAINT schedule_rules_no_overlap EXCLUDE USING gist (
            professional_id WITH =, weekday WITH =, int4range(start_minute, end_minute) WITH &&
        ) WHERE (deactivated_at IS NULL)');

        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('professional_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['professional_id', 'starts_at']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE schedule_blocks ADD CONSTRAINT schedule_blocks_values_check CHECK (
            ends_at > starts_at AND (cancelled_at IS NULL) = (cancelled_by_user_id IS NULL)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_blocks');
        Schema::dropIfExists('schedule_rules');
    }
};
