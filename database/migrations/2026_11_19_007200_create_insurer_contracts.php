<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.1b (ADR-0010): each insurer's contract points to a price table, with an optional adjustment in basis
     * points (1% = 100), and lists the billable items that need its authorization; one table of the network is the
     * private one. A contract change applies to items added from then on: an item keeps the price it entered with.
     */
    public function up(): void
    {
        Schema::table('price_tables', fn (Blueprint $table) => $table->boolean('private')->default(false));
        DB::statement('CREATE UNIQUE INDEX price_tables_one_private ON price_tables (tenant_id) WHERE private');

        Schema::create('insurer_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('insurer_id');
            $table->unsignedBigInteger('price_table_id');
            $table->integer('adjustment_bp')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->unique('insurer_id');
            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'price_table_id'])->references(['tenant_id', 'id'])->on('price_tables')->restrictOnDelete();
        });
        // From -100% (free) to +1000%.
        DB::statement('ALTER TABLE insurer_contracts ADD CONSTRAINT insurer_contracts_values_check CHECK (adjustment_bp BETWEEN -10000 AND 100000)');

        Schema::create('insurer_contract_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('insurer_contract_id');
            $table->unsignedBigInteger('billing_item_id');

            $table->unique(['insurer_contract_id', 'billing_item_id']);
            $table->foreign(['tenant_id', 'insurer_contract_id'])->references(['tenant_id', 'id'])->on('insurer_contracts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'billing_item_id'])->references(['tenant_id', 'id'])->on('billing_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurer_contract_authorizations');
        Schema::dropIfExists('insurer_contracts');
        DB::statement('DROP INDEX IF EXISTS price_tables_one_private');
        Schema::table('price_tables', fn (Blueprint $table) => $table->dropColumn('private'));
    }
};
