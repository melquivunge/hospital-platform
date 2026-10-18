<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6a: a professional may be linked to one access (membership) of the same tenant, created by
     * invitation from the professional's page (user decision, 17/10/2026).
     */
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_id')->nullable();
            $table->unique('membership_id');
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'membership_id']);
            $table->dropUnique(['membership_id']);
            $table->dropColumn('membership_id');
        });
    }
};
