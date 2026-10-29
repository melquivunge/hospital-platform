<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6b.6a2 (ADR-0011): the AIH file of a unit and processing competence, generated once (text in the official
     * layout, SHA-256, private disk), and the closed SUS accounts it presents, each in one file only. Both carry patient
     * data: RLS, insert-only.
     */
    public function up(): void
    {
        Schema::create('sus_aih_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->date('competence');
            $table->string('path', 200);
            $table->char('sha256', 64);
            $table->unsignedInteger('account_count');
            $table->foreignId('generated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');

            $table->unique(['unit_id', 'competence']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE sus_aih_files ADD CONSTRAINT sus_aih_files_check CHECK (extract(day FROM competence) = 1 AND sha256 ~ '^[0-9a-f]{64}$' AND account_count > 0)");
        Schema::create('sus_aih_file_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sus_aih_file_id');
            $table->unsignedBigInteger('billing_account_id')->unique();

            $table->index('sus_aih_file_id');
            $table->foreign(['tenant_id', 'sus_aih_file_id'])->references(['tenant_id', 'id'])->on('sus_aih_files')->restrictOnDelete();
            $table->foreign(['tenant_id', 'billing_account_id'])->references(['tenant_id', 'id'])->on('billing_accounts')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sus_aih_files_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'a AIH file and what it presents are never changed nor deleted' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER sus_aih_files_guard BEFORE UPDATE OR DELETE ON sus_aih_files FOR EACH ROW EXECUTE FUNCTION sus_aih_files_guard();
            CREATE TRIGGER sus_aih_files_no_truncate BEFORE TRUNCATE ON sus_aih_files FOR EACH STATEMENT EXECUTE FUNCTION sus_aih_files_guard();
            CREATE TRIGGER sus_aih_file_accounts_guard BEFORE UPDATE OR DELETE ON sus_aih_file_accounts FOR EACH ROW EXECUTE FUNCTION sus_aih_files_guard();
            CREATE TRIGGER sus_aih_file_accounts_no_truncate BEFORE TRUNCATE ON sus_aih_file_accounts FOR EACH STATEMENT EXECUTE FUNCTION sus_aih_files_guard();
        SQL);
        ClinicalSchema::protect('sus_aih_files');
        ClinicalSchema::protect('sus_aih_file_accounts');
    }

    public function down(): void
    {
        if (DB::table('sus_aih_files')->exists()) {
            throw new RuntimeException('AIH files exist: this migration cannot be rolled back.');
        }
        Schema::dropIfExists('sus_aih_file_accounts');
        Schema::dropIfExists('sus_aih_files');
        DB::unprepared('DROP FUNCTION IF EXISTS sus_aih_files_guard()');
    }
};
