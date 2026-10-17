<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned rule catalog (step 2c). unit_id NULL is the network row: value, delegation and limits.
     * Unit rows carry only a local value (NULL = use the inherited one). Rows are never changed.
     */
    public function up(): void
    {
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('key');
            $table->unsignedInteger('version');
            $table->integer('value')->nullable();
            $table->boolean('delegable')->nullable();
            $table->integer('min_value')->nullable();
            $table->integer('max_value')->nullable();
            $table->text('reason');
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('created_at', 6);

            // Two publications over the same version: the second one hits this index.
            $table->unique(['tenant_id', 'unit_id', 'key', 'version'])->nullsNotDistinct();
            $table->index(['tenant_id', 'key', 'unit_id', 'effective_from', 'version'], 'policy_versions_current_index');
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE policy_versions ADD CONSTRAINT policy_versions_shape_check CHECK (
            version >= 1
            AND (
                (unit_id IS NULL AND value IS NOT NULL AND delegable IS NOT NULL
                    AND (min_value IS NULL OR min_value <= value)
                    AND (max_value IS NULL OR max_value >= value))
                OR (unit_id IS NOT NULL AND delegable IS NULL AND min_value IS NULL AND max_value IS NULL)
            )
        )');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION policy_versions_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'policy_versions is append-only';
            END;
            $$;
            CREATE TRIGGER policy_versions_no_update_delete BEFORE UPDATE OR DELETE ON policy_versions
                FOR EACH ROW EXECUTE FUNCTION policy_versions_append_only();
            CREATE TRIGGER policy_versions_no_truncate BEFORE TRUNCATE ON policy_versions
                FOR EACH STATEMENT EXECUTE FUNCTION policy_versions_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS policy_versions_append_only()');
    }
};
