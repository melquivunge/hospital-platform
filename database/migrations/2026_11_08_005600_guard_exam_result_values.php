<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5.4 review: a released result takes no new value either. Each result states how many values it has, and
     * the database refuses one more; a result also names the patient and the order of its item.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE exam_results ADD COLUMN value_count smallint NOT NULL DEFAULT 0 CHECK (value_count >= 0)');
        // Results released before this column (development data only): counted once, with the guard off.
        DB::unprepared(<<<'SQL'
            ALTER TABLE exam_results DISABLE TRIGGER exam_results_guard;
            UPDATE exam_results SET value_count = (SELECT count(*) FROM exam_result_values WHERE exam_result_id = exam_results.id);
            ALTER TABLE exam_results ENABLE TRIGGER exam_results_guard;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_results_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                expected smallint;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'released exam results never change' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'exam_results' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM exam_order_items i JOIN exam_orders o ON o.id = i.exam_order_id
                        WHERE i.id = NEW.exam_order_item_id AND o.id = NEW.exam_order_id AND o.patient_id = NEW.patient_id
                            AND i.collected_at IS NOT NULL
                    ) THEN
                        RAISE EXCEPTION 'a result needs its item collected or performed' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    -- The lock serializes two inserts on the same result.
                    SELECT value_count INTO expected FROM exam_results WHERE id = NEW.exam_result_id FOR UPDATE;
                    IF (SELECT count(*) FROM exam_result_values WHERE exam_result_id = NEW.exam_result_id) >= expected THEN
                        RAISE EXCEPTION 'a released result takes no new value' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER exam_result_values_insert_guard BEFORE INSERT ON exam_result_values
                FOR EACH ROW EXECUTE FUNCTION exam_results_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS exam_result_values_insert_guard ON exam_result_values;
            CREATE OR REPLACE FUNCTION exam_results_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'released exam results never change' USING ERRCODE = '23514';
                END IF;
                IF TG_TABLE_NAME = 'exam_results' AND NOT EXISTS (
                    SELECT 1 FROM exam_order_items
                    WHERE id = NEW.exam_order_item_id AND exam_order_id = NEW.exam_order_id AND collected_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'a result needs its item collected or performed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
        SQL);
        DB::statement('ALTER TABLE exam_results DROP COLUMN value_count');
    }
};
