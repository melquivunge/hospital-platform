<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FUNCTIONS = ['stock_movements_dispensing_guard', 'stock_movements_surgery_guard'];

    /**
     * The two stock guards decide "expired" with the database clock, while the tests run the application on a fixed
     * clock: fixtures valid on the test date failed once the calendar passed them. app_now() is now(); only on the
     * test database (hospital_test) it honours app.now, set by the suite from its fixed clock. The guards' bodies are
     * otherwise unchanged.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_now() RETURNS timestamptz LANGUAGE sql STABLE AS $$
                -- Only the test database honours app.now: elsewhere a session setting must never move the expiry clock.
                SELECT CASE WHEN current_database() = 'hospital_test'
                    THEN coalesce(nullif(current_setting('app.now', true), '')::timestamptz, now())
                    ELSE now() END
            $$;
        SQL);
        $this->rewrite('(now() AT TIME ZONE', '(app_now() AT TIME ZONE');
    }

    public function down(): void
    {
        $this->rewrite('(app_now() AT TIME ZONE', '(now() AT TIME ZONE');
        DB::unprepared('DROP FUNCTION IF EXISTS app_now()');
    }

    private function rewrite(string $from, string $to): void
    {
        foreach (self::FUNCTIONS as $function) {
            $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regproc) AS definition', [$function])->definition;

            if (substr_count($definition, $from) !== 1) {
                throw new RuntimeException("{$function}: expected one clock reading to replace.");
            }

            DB::statement(str_replace($from, $to, $definition));
        }
    }
};
