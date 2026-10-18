<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.2: patients belong to the client (network) and are registered at a unit. CPF and CNS are
     * optional and unique in the network when present (user decision, 18/10/2026); without them the
     * registration is provisional. Clinical table: forced row-level security (ADR-0003).
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('registered_unit_id');
            $table->string('status');
            $table->string('name');
            $table->string('name_key');
            $table->string('social_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('sex')->nullable();
            $table->char('cpf', 11)->nullable();
            $table->char('cns', 15)->nullable();
            $table->string('mother_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship', 60)->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'birth_date']);
            $table->index(['tenant_id', 'name_key']);
            $table->foreign(['tenant_id', 'registered_unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_status_check CHECK (
            (status = 'complete' AND (cpf IS NOT NULL OR cns IS NOT NULL) AND birth_date IS NOT NULL AND sex IS NOT NULL)
            OR status = 'provisional'
        )");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_sex_check CHECK (sex IN ('female', 'male', 'unknown'))");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_cpf_check CHECK (cpf IS NULL OR cpf ~ '^[0-9]{11}$')");
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_cns_check CHECK (cns IS NULL OR cns ~ '^[0-9]{15}$')");
        DB::statement('CREATE UNIQUE INDEX patients_cpf_unique ON patients (tenant_id, cpf) WHERE cpf IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX patients_cns_unique ON patients (tenant_id, cns) WHERE cns IS NOT NULL');

        ClinicalSchema::protect('patients');
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
