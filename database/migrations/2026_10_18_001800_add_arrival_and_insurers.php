<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3.5: the network's insurers (kept by the network admin; no patient data, so no RLS) and the
     * reception confirming arrival with the visit's modality: private, insurer (card number and validity)
     * or SUS. No billing (delivery 3 contract).
     */
    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('ans_code', 6)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX insurers_tenant_id_lower_name_unique ON insurers (tenant_id, lower(name))');
        DB::statement("ALTER TABLE insurers ADD CONSTRAINT insurers_ans_code_check CHECK (ans_code ~ '^[0-9]{6}$')");

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestampTz('arrived_at')->nullable();
            $table->foreignId('arrived_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('modality', 20)->nullable();
            $table->unsignedBigInteger('insurer_id')->nullable();
            $table->string('insurance_card_number', 40)->nullable();
            $table->date('insurance_card_valid_until')->nullable();

            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_values_check');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_values_check CHECK (
            ends_at > starts_at
            AND status IN ('scheduled', 'arrived', 'cancelled', 'no_show')
            AND (status IN ('scheduled', 'arrived')) = (closed_at IS NULL)
            AND (closed_at IS NULL) = (closed_by_user_id IS NULL)
            AND (status = 'cancelled') = (cancel_reason IS NOT NULL)
            AND rescheduled_from_id IS DISTINCT FROM id
            AND (status = 'arrived') = (arrived_at IS NOT NULL)
            AND (arrived_at IS NULL) = (arrived_by_user_id IS NULL)
            AND (arrived_at IS NULL) = (modality IS NULL)
            AND (modality IS NULL OR modality IN ('private', 'insurance', 'sus'))
            AND (modality IS NOT DISTINCT FROM 'insurance') = (insurer_id IS NOT NULL)
            AND (insurer_id IS NULL) = (insurance_card_number IS NULL)
            AND (insurer_id IS NULL) = (insurance_card_valid_until IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_values_check');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_values_check CHECK (
            ends_at > starts_at
            AND status IN ('scheduled', 'cancelled', 'no_show')
            AND (status = 'scheduled') = (closed_at IS NULL)
            AND (closed_at IS NULL) = (closed_by_user_id IS NULL)
            AND (status = 'cancelled') = (cancel_reason IS NOT NULL)
            AND rescheduled_from_id IS DISTINCT FROM id
        )");
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'insurer_id']);
            $table->dropColumn(['arrived_at', 'arrived_by_user_id', 'modality', 'insurer_id', 'insurance_card_number', 'insurance_card_valid_until']);
        });
        Schema::dropIfExists('insurers');
    }
};
