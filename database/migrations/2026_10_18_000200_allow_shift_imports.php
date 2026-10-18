<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5b: batches may also import shifts (created as drafts).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals', 'shifts'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_type_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('professionals'))");
    }
};
