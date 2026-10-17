<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shifts (step 4a). Instants in UTC; the unit's IANA timezone is used for input and display.
     * professional_id NULL is an open vacancy. Published shifts only change through cancellation here.
     */
    public function up(): void
    {
        // Trusted extension: needed for the overlap exclusion constraint below.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('sector_id');
            $table->unsignedBigInteger('job_function_id');
            $table->unsignedBigInteger('professional_id')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status');
            $table->unsignedInteger('eligibility_version')->nullable();
            $table->unsignedBigInteger('qualification_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'unit_id', 'starts_at']);
            $table->index(['professional_id', 'starts_at']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['unit_id', 'sector_id'])->references(['unit_id', 'id'])->on('sectors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'job_function_id'])->references(['tenant_id', 'id'])->on('job_functions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['unit_id', 'qualification_id'])->references(['unit_id', 'id'])->on('qualifications')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status_check CHECK (status IN ('draft', 'published', 'cancelled'))");
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_period_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_published_check CHECK (
            (published_at IS NULL) = (published_by_user_id IS NULL)
            AND (status <> 'published' OR published_at IS NOT NULL)
        )");
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_cancelled_check CHECK (
            (status = 'cancelled') = (cancelled_at IS NOT NULL)
            AND (status <> 'cancelled' OR (cancelled_by_user_id IS NOT NULL AND cancel_reason IS NOT NULL))
        )");
        // [start, end) intervals: one professional never holds two overlapping non-cancelled shifts, even
        // under concurrent publications. Ending exactly when the next starts is not an overlap (PRODUCT.md).
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_no_overlap EXCLUDE USING gist (
            professional_id WITH =,
            tstzrange(starts_at, ends_at, '[)') WITH &&
        ) WHERE (professional_id IS NOT NULL AND status <> 'cancelled')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
