<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 9a: internal notifications (V1-12), derived from audit_events (written in the same transaction
     * as each change). Delivery advances a cursor and is re-runnable: (user, event) is unique.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('audit_event_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('url')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['user_id', 'audit_event_id']);
            $table->index(['user_id', 'tenant_id', 'read_at']);
        });

        // Delivery re-reads recently occurred events to catch transactions that committed out of id order.
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index('occurred_at');
        });

        Schema::create('notification_cursors', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('last_audit_event_id');
        });

        // Start after the existing history: past events would flood every inbox with stale items.
        DB::table('notification_cursors')->insert(['name' => 'inbox', 'last_audit_event_id' => (int) DB::table('audit_events')->max('id')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_cursors');
        Schema::dropIfExists('user_notifications');
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['occurred_at']);
        });
    }
};
