<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // MySQL only: bail out early if not MySQL/MariaDB
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'mariadb'])) {
            // You can add a Postgres/SQLite path here if needed.
            return;
        }

        // Turn off FK checks so we can alter referenced columns safely.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        // Gather all candidate columns across all tables in the current schema
        // Match: column_name = 'uuid' OR column_name LIKE '%\_uuid'
        // Skip columns already char(36).
        $columns = DB::select("
            SELECT
                TABLE_NAME      AS table_name,
                COLUMN_NAME     AS column_name,
                COLUMN_TYPE     AS column_type,
                IS_NULLABLE     AS is_nullable,
                COLUMN_DEFAULT  AS column_default,
                CHARACTER_SET_NAME AS character_set_name,
                COLLATION_NAME  AS collation_name
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND (
                    COLUMN_NAME = 'uuid'
                 OR COLUMN_NAME LIKE '%\\_uuid'
              )
        ");

        foreach ($columns as $col) {
            $table   = $col->table_name;
            $column  = $col->column_name;
            $ctype   = strtolower($col->column_type);

            // Skip if already char(36)
            if ($ctype === 'char(36)') {
                continue;
            }

            // Build NULL / NOT NULL clause
            $nullClause = strtoupper($col->is_nullable) === 'YES' ? 'NULL' : 'NOT NULL';

            // Preserve default if it is a scalar literal (ignore expressions)
            $defaultClause = '';
            if (!is_null($col->column_default)) {
                // MySQL returns defaults like 'some string' already unquoted in INFORMATION_SCHEMA
                // We need to quote strings; numeric stays numeric.
                $default = $col->column_default;
                if (is_numeric($default)) {
                    $defaultClause = " DEFAULT {$default}";
                } elseif (in_array(strtolower($default), ['current_timestamp'])) {
                    $defaultClause = " DEFAULT {$default}";
                } else {
                    // Quote single quotes inside the default
                    $default       = str_replace("'", "''", $default);
                    $defaultClause = " DEFAULT '{$default}'";
                }
            }

            // Keep collation only if this column previously had a character set (it will on varchar/char)
            // (Optional) If you want to force a specific collation, set it here.
            $collationClause = '';
            if (!empty($col->collation_name)) {
                $collationClause = " COLLATE {$col->collation_name}";
            }

            // Compose ALTER TABLE ... MODIFY
            $sql = sprintf(
                'ALTER TABLE `%s` MODIFY `%s` CHAR(36)%s %s%s',
                str_replace('`', '``', $table),
                str_replace('`', '``', $column),
                $collationClause,
                $nullClause,
                $defaultClause
            );

            DB::statement($sql);
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(): void
    {
        // Irreversible safely (we don't know original lengths/types).
        // If you need a reversible migration, capture the original COLUMN_TYPEs
        // into a side table before altering, then restore from there.
    }
};
