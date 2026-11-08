<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccentInsensitiveSearch
{
    /**
     * @var array<int, true>
     */
    private static array $configuredSqliteConnections = [];

    /**
     * Return a SQL expression that lowercases and removes common Slovak and
     * Czech diacritics from a trusted column expression.
     */
    public static function expression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return "public.immutable_unaccent(lower(cast({$column} as text)))";
        }

        if ($driver === 'mysql') {
            return "lower(cast({$column} as char)) collate utf8mb4_unicode_ci";
        }

        if ($driver === 'sqlite') {
            self::configureSqliteConnection();

            return "immutable_unaccent(lower(coalesce({$column}, '')))";
        }

        return "lower(coalesce({$column}, ''))";
    }

    public static function normalizeTerm(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }

    private static function configureSqliteConnection(): void
    {
        $pdo = DB::connection()->getPdo();
        $connectionId = spl_object_id($pdo);

        if (isset(self::$configuredSqliteConnections[$connectionId])) {
            return;
        }

        $pdo->sqliteCreateFunction(
            'immutable_unaccent',
            static fn (?string $value): string => Str::ascii($value ?? ''),
            1,
        );
        self::$configuredSqliteConnections[$connectionId] = true;
    }
}
