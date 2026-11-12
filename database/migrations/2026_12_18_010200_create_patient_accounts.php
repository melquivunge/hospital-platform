<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 7a.1a (ADR-0012): the patient's own portal account, one per network and patient, apart from
     * the staff's users. The single-use link (invitation or forgotten password) is kept as a hash.
     */
    public function up(): void
    {
        Schema::create('patient_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('patient_id');
            $table->string('email');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->char('setup_token_hash', 64)->nullable();
            $table->timestampTz('setup_token_expires_at')->nullable();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'patient_id']);
            $table->unique('setup_token_hash');
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
        });

        // One account per e-mail in a network, whatever the case.
        DB::statement('CREATE UNIQUE INDEX patient_accounts_tenant_email_unique ON patient_accounts (tenant_id, lower(email))');
        DB::statement('ALTER TABLE patient_accounts ADD CONSTRAINT patient_accounts_token_check CHECK ((setup_token_hash IS NULL) = (setup_token_expires_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_accounts');
    }
};
