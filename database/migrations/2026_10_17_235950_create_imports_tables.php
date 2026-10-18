<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traceable CSV imports (step 5, V1-10). Every received line becomes one item with a final result,
     * and an identity (tenant + source system + entity + original id) makes re-imports idempotent.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('source_system', 100);
            $table->string('filename');
            $table->string('path');
            $table->string('status');
            $table->unsignedInteger('received')->default(0);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_status_check CHECK (status IN ('previewed', 'applying', 'applied'))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals'))");

        Schema::create('import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('line');
            $table->jsonb('data');
            $table->string('status');
            $table->jsonb('messages')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestampsTz();

            $table->unique(['import_batch_id', 'line']);
            $table->index(['import_batch_id', 'status']);
            $table->foreign(['tenant_id', 'import_batch_id'])->references(['tenant_id', 'id'])->on('import_batches')->restrictOnDelete();
        });
        // ready = valid, waiting for confirmation; created/identical/conflict/rejected are final.
        DB::statement("ALTER TABLE import_items ADD CONSTRAINT import_items_status_check CHECK (status IN ('ready', 'created', 'identical', 'conflict', 'rejected'))");

        Schema::create('import_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('source_system', 100);
            $table->string('entity_type');
            $table->string('original_id', 100);
            $table->unsignedBigInteger('record_id');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'source_system', 'entity_type', 'original_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_identities');
        Schema::dropIfExists('import_items');
        Schema::dropIfExists('import_batches');
    }
};
