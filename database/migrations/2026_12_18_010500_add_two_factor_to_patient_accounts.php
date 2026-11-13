<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a.1c: an optional second factor (TOTP) on the patient's account, as on the staff accounts. The secret and
     * the recovery codes are stored encrypted; the factor counts only once confirmed with a code.
     */
    public function up(): void
    {
        Schema::table('patient_accounts', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            // The authenticator's time step last accepted: a code is good only from a later one (no replay).
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patient_accounts', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
