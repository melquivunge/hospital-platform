<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "UTI" and "uti" are the same sector for people: names are unique ignoring case.
     * Fails if case-insensitive duplicates already exist (only synthetic data at this point);
     * the new index is created before the old one is dropped, so a failure keeps the old constraint.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX units_tenant_id_lower_name_unique ON units (tenant_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX sectors_unit_id_lower_name_unique ON sectors (unit_id, lower(name))');

        Schema::table('sectors', function (Blueprint $table) {
            $table->dropUnique(['unit_id', 'name']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX sectors_unit_id_lower_name_unique');

        Schema::table('sectors', function (Blueprint $table) {
            $table->unique(['unit_id', 'name']);
        });

        DB::statement('DROP INDEX units_tenant_id_lower_name_unique');
    }
};
