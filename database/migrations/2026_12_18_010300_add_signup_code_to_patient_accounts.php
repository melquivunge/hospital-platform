<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a.1b: self sign-up. A 6-digit code goes only to the e-mail on file (kept as an HMAC, 10 minutes,
     * 5 attempts); an account created this way has no inviting staff member.
     */
    public function up(): void
    {
        Schema::table('patient_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by_user_id')->nullable()->change();
            $table->char('signup_code_hash', 64)->nullable();
            $table->timestampTz('signup_code_expires_at')->nullable();
            $table->unsignedSmallInteger('signup_code_attempts')->default(0);
        });

        DB::statement('ALTER TABLE patient_accounts ADD CONSTRAINT patient_accounts_signup_code_check CHECK ((signup_code_hash IS NULL) = (signup_code_expires_at IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patient_accounts DROP CONSTRAINT patient_accounts_signup_code_check');
        Schema::table('patient_accounts', function (Blueprint $table) {
            $table->dropColumn(['signup_code_hash', 'signup_code_expires_at', 'signup_code_attempts']);
        });
    }
};
