<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6.2a0 (user's decision, 25/10/2026): the reception records the modality and the insurance card on the
     * emergency arrival, as on the consultation's; an admission keeps the one of the episode it came from. Episodes
     * from before keep none (the account shows it as pending).
     */
    public function up(): void
    {
        Schema::table('emergency_episodes', function (Blueprint $table) {
            $table->string('modality', 20)->nullable();
            $table->unsignedBigInteger('insurer_id')->nullable();
            $table->string('insurance_card_number', 40)->nullable();
            $table->date('insurance_card_valid_until')->nullable();

            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE emergency_episodes ADD CONSTRAINT emergency_episodes_modality_check CHECK (
            (modality IS NULL OR modality IN ('private', 'insurance', 'sus'))
            AND (modality IS NOT DISTINCT FROM 'insurance') = (insurer_id IS NOT NULL)
            AND (insurer_id IS NULL) = (insurance_card_number IS NULL)
            AND (insurer_id IS NULL) = (insurance_card_valid_until IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emergency_episodes DROP CONSTRAINT emergency_episodes_modality_check');
        Schema::table('emergency_episodes', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'insurer_id']);
            $table->dropColumn(['modality', 'insurer_id', 'insurance_card_number', 'insurance_card_valid_until']);
        });
    }
};
