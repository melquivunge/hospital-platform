<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a.3b (contract, LGPD art. 18 III): the patient asks the reception to correct the registration data;
     * the request stays open until the reception marks it resolved.
     */
    public function up(): void
    {
        Schema::create('patient_data_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('patient_id')->constrained('patients');
            $table->foreignId('account_id')->constrained('patient_accounts');
            $table->text('message');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'resolved_at', 'id']);
        });

        DB::statement('ALTER TABLE patient_data_requests ADD CONSTRAINT patient_data_requests_resolved_check CHECK ((resolved_at IS NULL) = (resolved_by_user_id IS NULL))');
        DB::statement('ALTER TABLE patient_data_requests ADD CONSTRAINT patient_data_requests_message_check CHECK (char_length(message) BETWEEN 1 AND 1000)');
        ClinicalSchema::protect('patient_data_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_data_requests');
    }
};
