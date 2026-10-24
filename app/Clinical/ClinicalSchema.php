<?php

namespace App\Clinical;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Row-level security for clinical tables (ADR-0003). Every clinical table is listed here and protected
 * in its migration; a test fails if one is missing the forced policy.
 */
final class ClinicalSchema
{
    /** @var list<string> */
    public const TABLES = ['patients', 'patient_merges', 'appointments', 'visits', 'clinical_notes', 'clinical_reads', 'emergency_accesses', 'clinical_documents', 'emergency_episodes', 'triages', 'admissions', 'admission_movements', 'admission_transfers', 'patient_allergies', 'prescriptions', 'prescription_items', 'prescription_doses', 'prescription_reviews', 'prescription_dispensings', 'medication_administrations', 'prescription_signatures', 'exam_orders', 'exam_order_items', 'exam_results', 'exam_result_values', 'exam_critical_alerts'];

    /**
     * Only rows of the client in the session are visible or writable; without a client, none is.
     */
    public static function protect(string $table, ?string $connection = null): void
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }

        $tenant = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $db = DB::connection($connection);
        $db->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        $db->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        $db->statement("CREATE POLICY tenant_isolation ON {$table} USING (tenant_id = {$tenant}) WITH CHECK (tenant_id = {$tenant})");
    }
}
