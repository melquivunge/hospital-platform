<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ADR-0003: the role the application assumes after connecting. Without superuser or BYPASSRLS, row-level
     * security applies to it. Runs as the table owner (pgsql_owner), which also grants every future table.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    CREATE ROLE app_runtime NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
                END IF;
            END
            $$;
            GRANT app_runtime TO CURRENT_USER;
            GRANT USAGE ON SCHEMA public TO app_runtime;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_runtime;
            GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO app_runtime;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_runtime;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO app_runtime;
            SQL);
    }

    public function down(): void
    {
        // The role is shared by every database in the cluster: only this database's grants are removed.
        DB::unprepared(<<<'SQL'
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM app_runtime;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM app_runtime;
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM app_runtime;
            REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM app_runtime;
            REVOKE USAGE ON SCHEMA public FROM app_runtime;
            SQL);
    }
};
