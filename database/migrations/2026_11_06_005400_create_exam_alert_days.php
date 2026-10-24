<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 5.3b (ADR-0008): the daily alert of orders without collection for 7 days is sent once per day of the
     * unit; this row, written in the same transaction as the alert, keeps a second run of the same day from
     * repeating it.
     */
    public function up(): void
    {
        Schema::create('exam_alert_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->date('alert_date');
            $table->timestampTz('created_at');

            $table->unique(['unit_id', 'alert_date']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_alert_days');
    }
};
