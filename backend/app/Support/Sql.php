<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The few SQL fragments that differ between PostgreSQL (primary) and MySQL/MariaDB (supported).
 * Everything else goes through the query builder.
 */
final class Sql
{
    public static function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** Case-insensitive LIKE: ILIKE on PostgreSQL; MySQL's default *_ci collations already ignore case. */
    public static function ilike(): string
    {
        return self::isPgsql() ? 'ilike' : 'like';
    }

    /** UTC calendar day of a timestamp column as 'YYYY-MM-DD'. */
    public static function day(string $column): string
    {
        return self::isPgsql() ? "to_char(date_trunc('day', {$column}), 'YYYY-MM-DD')" : "DATE_FORMAT({$column}, '%Y-%m-%d')";
    }

    /** Monday of the ISO week of a timestamp column as 'YYYY-MM-DD'. */
    public static function weekStart(string $column): string
    {
        return self::isPgsql()
            ? "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')"
            : "DATE_FORMAT(DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')";
    }

    /**
     * Atomic "insert or add to counters" keyed by a unique index.
     *
     * @param  array<string, mixed>  $keys  unique-key columns => values
     * @param  array<string, int>  $increments  counter columns => amount to add
     */
    public static function upsertIncrement(string $table, array $keys, array $increments): void
    {
        $columns = [...array_keys($keys), ...array_keys($increments)];
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $bindings = [...array_values($keys), ...array_values($increments)];
        $insert = "INSERT INTO {$table} (".implode(', ', $columns).', created_at, updated_at) VALUES ('.$placeholders.', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';

        if (self::isPgsql()) {
            $set = implode(', ', array_map(fn ($c) => "{$c} = {$table}.{$c} + EXCLUDED.{$c}", array_keys($increments)));
            DB::statement("{$insert} ON CONFLICT (".implode(', ', array_keys($keys)).") DO UPDATE SET {$set}, updated_at = CURRENT_TIMESTAMP", $bindings);

            return;
        }
        $set = implode(', ', array_map(fn ($c) => "{$c} = {$c} + VALUES({$c})", array_keys($increments)));
        DB::statement("{$insert} ON DUPLICATE KEY UPDATE {$set}, updated_at = CURRENT_TIMESTAMP", $bindings);
    }
}
