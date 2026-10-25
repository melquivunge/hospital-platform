<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5b.5: a surgery is completed only with its signed description, and a completed surgery never changes or
     * goes away (the description and its addenda point at it as it was).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION surgery_completion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' AND OLD.status = 'completed' THEN
                    RAISE EXCEPTION 'a completed surgery never changes' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                IF NEW.status = 'completed' AND NOT EXISTS (SELECT 1 FROM surgery_descriptions WHERE surgery_id = NEW.id AND addendum_of_id IS NULL) THEN
                    RAISE EXCEPTION 'a surgery is completed by its signed description' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER surgeries_completion_guard BEFORE INSERT OR UPDATE OR DELETE ON surgeries
                FOR EACH ROW EXECUTE FUNCTION surgery_completion_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS surgeries_completion_guard ON surgeries; DROP FUNCTION IF EXISTS surgery_completion_guard()');
    }
};
