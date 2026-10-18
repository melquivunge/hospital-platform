<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional shift value in BRL cents (PRODUCT.md: optional, explicit currency, not payroll).
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedInteger('value_cents')->nullable();
        });

        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_value_check CHECK (value_cents IS NULL OR value_cents >= 0)');
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('value_cents');
        });
    }
};
