<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fault injection for integrity tests on SQLite and PostgreSQL.
 *
 * Tests describe the trigger once, in SQLite syntax:
 *   CREATE TRIGGER name BEFORE|AFTER INSERT|UPDATE ON table [WHEN condition]
 *   BEGIN statement; END
 * where the statement is either SELECT RAISE(ABORT, 'message') or an UPDATE.
 * On PostgreSQL the same trigger is created as a PL/pgSQL function, so the
 * suite can run against the production database engine.
 */
final class SyntheticTrigger
{
    private const PATTERN = '/^CREATE TRIGGER (\w+) (BEFORE|AFTER) (INSERT|UPDATE) ON (\w+)(?: WHEN (.+?))? BEGIN (.+?);\s*END;?$/is';

    public static function create(string $sqliteDefinition): void
    {
        $definition = trim($sqliteDefinition);
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared($definition);

            return;
        }
        if (!preg_match(self::PATTERN, $definition, $m)) {
            throw new InvalidArgumentException("Unsupported synthetic trigger: $definition");
        }
        [, $name, $timing, $event, $table, $when, $statement] = $m;
        $statement = preg_match("/^SELECT RAISE\\(ABORT,\\s*'(.+)'\\)$/i", trim($statement), $raise)
            ? "RAISE EXCEPTION '{$raise[1]}'"
            // SQLite stores booleans as 0/1 and truncates CURRENT_TIMESTAMP to the second;
            // PostgreSQL needs true/false and would round fractional seconds up.
            : str_replace('CURRENT_TIMESTAMP', "date_trunc('second', CURRENT_TIMESTAMP)", preg_replace('/\bactive = 0\b/', 'active = false', trim($statement)));
        $return = $timing === 'BEFORE' ? 'RETURN NEW;' : 'RETURN NULL;';
        $whenClause = $when ? " WHEN ({$when})" : '';

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION {$name}_fn() RETURNS trigger AS \$\$
BEGIN
    {$statement};
    {$return}
END;
\$\$ LANGUAGE plpgsql;
CREATE TRIGGER {$name} {$timing} {$event} ON {$table} FOR EACH ROW{$whenClause} EXECUTE FUNCTION {$name}_fn();
SQL);
    }
}
