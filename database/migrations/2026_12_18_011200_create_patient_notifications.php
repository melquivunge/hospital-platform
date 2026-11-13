<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7b.3 (contract, decision 6): the portal's inbox, delivered from audit_events like the staff's
     * (user_notifications), with a patient account as recipient. Titles carry no clinical data. Re-runnable:
     * (account, event, kind) is unique; "you are next" is sent once per arrival (ref).
     */
    public function up(): void
    {
        Schema::create('patient_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('account_id')->constrained('patient_accounts');
            $table->foreignId('audit_event_id')->constrained();
            $table->string('kind', 20);
            $table->string('ref', 40)->nullable();
            $table->string('title');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['account_id', 'audit_event_id', 'kind']);
            $table->unique(['account_id', 'kind', 'ref']);
            $table->index(['account_id', 'read_at']);
        });

        ClinicalSchema::protect('patient_notifications');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_notifications');
    }
};
