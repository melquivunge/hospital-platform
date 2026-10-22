<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4b.6 (integrated review): whether an item needs a second person at administration is fixed when it is
     * prescribed, so unticking "controlled" or "high alert" in the catalog does not drop the double check of
     * prescriptions already signed. Items prescribed before this have none (null) and read the catalog, failing
     * closed; they leave use within the 24 hours of their prescription.
     */
    public function up(): void
    {
        Schema::table('prescription_items', fn (Blueprint $table) => $table->boolean('double_check')->nullable());
    }

    public function down(): void
    {
        Schema::table('prescription_items', fn (Blueprint $table) => $table->dropColumn('double_check'));
    }
};
