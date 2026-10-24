<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Step 5.3 (ADR-0008): the collection (laboratory) or the performance (imaging) of each item of an order, once,
     * with when and who; the item never changes after it. The exam team's reads of the record enter the trail with
     * the reason "exam" and the order.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE exam_order_items ADD COLUMN collected_at timestamptz');
        DB::statement('ALTER TABLE exam_order_items ADD COLUMN collected_by_user_id bigint REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE exam_order_items ADD CONSTRAINT exam_order_items_collected_check CHECK ((collected_at IS NULL) = (collected_by_user_id IS NULL))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_order_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' OR OLD.collected_at IS NOT NULL
                    OR (NEW.tenant_id, NEW.exam_order_id, NEW.exam_id, NEW.exam_name, NEW.exam_type)
                        IS DISTINCT FROM (OLD.tenant_id, OLD.exam_order_id, OLD.exam_id, OLD.exam_name, OLD.exam_type) THEN
                    RAISE EXCEPTION 'exam order items change only once, when collected' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER exam_order_items_guard BEFORE UPDATE OR DELETE ON exam_order_items
                FOR EACH ROW EXECUTE FUNCTION exam_order_items_guard();
            CREATE TRIGGER exam_order_items_no_truncate BEFORE TRUNCATE ON exam_order_items
                FOR EACH STATEMENT EXECUTE FUNCTION exam_order_items_guard();
        SQL);

        DB::statement('ALTER TABLE clinical_reads ADD COLUMN exam_order_id bigint');
        DB::statement('ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_exam_order_foreign FOREIGN KEY (tenant_id, exam_order_id) REFERENCES exam_orders (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        // New rows only, as since step 3.6b: the insert-only trail may hold rows that older rules allowed.
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy', 'exam') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
            AND (reason = 'exam') = (exam_order_id IS NOT NULL)
        ) NOT VALID");
    }

    public function down(): void
    {
        if (DB::table('clinical_reads')->whereNotNull('exam_order_id')->exists() || DB::table('exam_order_items')->whereNotNull('collected_at')->exists()) {
            throw new RuntimeException('Exam reads or collections exist and are never deleted: this migration cannot be rolled back.');
        }
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_reason_check');
        DB::statement('ALTER TABLE clinical_reads DROP CONSTRAINT clinical_reads_exam_order_foreign');
        DB::statement('ALTER TABLE clinical_reads DROP COLUMN exam_order_id');
        DB::statement("ALTER TABLE clinical_reads ADD CONSTRAINT clinical_reads_reason_check CHECK (
            reason IN ('visit', 'emergency', 'admission', 'pharmacy') AND (reason = 'visit') = (visit_id IS NOT NULL)
            AND (reason = 'emergency') = (emergency_access_id IS NOT NULL)
            AND (reason = 'admission') = (admission_id IS NOT NULL)
            AND (reason = 'pharmacy') = (prescription_id IS NOT NULL)
        ) NOT VALID");
        DB::unprepared('DROP TRIGGER IF EXISTS exam_order_items_no_truncate ON exam_order_items; DROP TRIGGER IF EXISTS exam_order_items_guard ON exam_order_items; DROP FUNCTION IF EXISTS exam_order_items_guard()');
        DB::statement('ALTER TABLE exam_order_items DROP CONSTRAINT exam_order_items_collected_check');
        DB::statement('ALTER TABLE exam_order_items DROP COLUMN collected_by_user_id');
        DB::statement('ALTER TABLE exam_order_items DROP COLUMN collected_at');
    }
};
