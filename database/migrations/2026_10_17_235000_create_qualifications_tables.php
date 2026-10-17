<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3b: network job functions and qualifications (habilitações) per professional, unit and function,
     * with authorized sectors. Approval in one unit never implies another (V1-03).
     */
    public function up(): void
    {
        Schema::create('job_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX job_functions_tenant_id_lower_name_unique ON job_functions (tenant_id, lower(name))');

        // Changes to qualifications lock the professional row and bump this version (plans/web-v1.md, step 2/4).
        Schema::table('professionals', function (Blueprint $table) {
            $table->unsignedInteger('eligibility_version')->default(0);
        });

        // Lets qualification sectors reference (unit_id, sector_id): a sector of another unit is rejected by the database.
        Schema::table('sectors', function (Blueprint $table) {
            $table->unique(['unit_id', 'id']);
        });

        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('job_function_id');
            $table->string('status');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['unit_id', 'id']);
            $table->index(['tenant_id', 'unit_id']);
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'job_function_id'])->references(['tenant_id', 'id'])->on('job_functions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE qualifications ADD CONSTRAINT qualifications_status_check CHECK (status IN ('pending', 'approved', 'suspended', 'revoked'))");
        DB::statement('ALTER TABLE qualifications ADD CONSTRAINT qualifications_validity_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');
        // Revoked is terminal; a new qualification for the same unit and function starts a new record.
        DB::statement("CREATE UNIQUE INDEX qualifications_active_unique ON qualifications (professional_id, unit_id, job_function_id) WHERE status <> 'revoked'");

        Schema::create('qualification_sector', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('qualification_id');
            $table->unsignedBigInteger('sector_id');

            $table->primary(['qualification_id', 'sector_id']);
            $table->foreign(['unit_id', 'qualification_id'])->references(['unit_id', 'id'])->on('qualifications')->cascadeOnDelete();
            $table->foreign(['unit_id', 'sector_id'])->references(['unit_id', 'id'])->on('sectors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_sector');
        Schema::dropIfExists('qualifications');
        Schema::table('sectors', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'id']);
        });
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn('eligibility_version');
        });
        Schema::dropIfExists('job_functions');
    }
};
