<?php

use App\Clinical\ClinicalSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 4.5: every bed allocation, bed or sector change and change of responsible doctor is a new row;
     * nothing is rewritten. Insert-only (trigger), clinical (RLS). The allocations of existing admissions
     * are copied so the bed history is complete.
     */
    public function up(): void
    {
        Schema::create('admission_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('admission_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('from_bed_id')->nullable();
            $table->unsignedBigInteger('to_bed_id')->nullable();
            $table->unsignedBigInteger('from_professional_id')->nullable();
            $table->unsignedBigInteger('to_professional_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('occurred_at');

            $table->index(['admission_id', 'id']);
            $table->foreign(['tenant_id', 'admission_id'])->references(['tenant_id', 'id'])->on('admissions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_bed_id'])->references(['tenant_id', 'id'])->on('beds')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_bed_id'])->references(['tenant_id', 'id'])->on('beds')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_professional_id'])->references(['tenant_id', 'id'])->on('professionals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE admission_movements ADD CONSTRAINT admission_movements_values_check CHECK (
            (kind = 'bed' AND to_bed_id IS NOT NULL AND from_bed_id IS DISTINCT FROM to_bed_id AND from_professional_id IS NULL AND to_professional_id IS NULL)
            OR (kind = 'responsible' AND from_professional_id IS NOT NULL AND to_professional_id IS NOT NULL AND from_professional_id <> to_professional_id
                AND from_bed_id IS NULL AND to_bed_id IS NULL)
        )");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION admission_movements_insert_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'admission movements are insert-only' USING ERRCODE = 'P0001';
            END $$;
            CREATE TRIGGER admission_movements_insert_only BEFORE UPDATE OR DELETE ON admission_movements
                FOR EACH ROW EXECUTE FUNCTION admission_movements_insert_only();
            CREATE TRIGGER admission_movements_no_truncate BEFORE TRUNCATE ON admission_movements
                FOR EACH STATEMENT EXECUTE FUNCTION admission_movements_insert_only();
        SQL);
        ClinicalSchema::protect('admission_movements');

        // Row-level security is forced even for the owner: copy client by client, with the client in session.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::select("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
            DB::statement("INSERT INTO admission_movements (tenant_id, admission_id, kind, to_bed_id, reason, user_id, occurred_at)
                SELECT tenant_id, id, 'bed', bed_id, compatibility_justification, admitted_by_user_id, admitted_at FROM admissions
                WHERE status = 'admitted' AND tenant_id = ?", [$tenantId]);
        }
        DB::select("SELECT set_config('app.tenant_id', '', true)");
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS admission_movements_insert_only()');
    }
};
