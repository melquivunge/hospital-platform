<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Professionals of a tenant (step 3a). Independent from login accounts: imports and schedules
     * work before the person is invited. The base unit decides the UF for vacancies and transfers.
     */
    public function up(): void
    {
        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('base_unit_id');
            $table->string('name');
            $table->string('council', 10)->nullable();
            $table->string('council_number', 20)->nullable();
            $table->char('council_uf', 2)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'external_id']);
            $table->unique(['tenant_id', 'council', 'council_number', 'council_uf']);
            $table->index(['tenant_id', 'base_unit_id']);
            $table->foreign(['tenant_id', 'base_unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE professionals ADD CONSTRAINT professionals_council_check CHECK (
            (council IS NULL AND council_number IS NULL AND council_uf IS NULL)
            OR (council IS NOT NULL AND council_number IS NOT NULL AND council_uf IN ('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'))
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('professionals');
    }
};
