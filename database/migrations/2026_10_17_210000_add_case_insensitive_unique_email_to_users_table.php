<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One global identity per email regardless of case. Fails if case-insensitive duplicates exist.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX users_lower_email_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_lower_email_unique');
    }
};
