<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4c.3a (ADR-0007): a discard has a reason code (expired, damage, loss or other) and, only for "other",
     * a short text. The database keeps the codes and the text together.
     */
    public function up(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->string('note', 200)->nullable());
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_discard_reason_check CHECK (
            (type <> 'discard' AND note IS NULL)
            OR (type = 'discard' AND (
                (reason IN ('expired', 'damage', 'loss') AND note IS NULL)
                OR (reason = 'other' AND coalesce(btrim(note), '') <> '')
            ))
        )");
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->whereNotNull('note')->exists()) {
            throw new RuntimeException('Há descartes com texto registrados; a migração não volta.');
        }
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_discard_reason_check');
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('note'));
    }
};
