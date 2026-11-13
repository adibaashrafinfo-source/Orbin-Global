<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Names for customers and suppliers, looked up once per request however many rows ask. */
class PartyDirectory
{
    /** @var array<string, array<int, object|null>> */
    private static array $memo = [];

    /** @return object{id: int, code: string, name: string}|null */
    public static function find(string $type, int $id): ?object
    {
        return self::$memo[$type][$id] ??= DB::table($type === 'customer' ? 'customers' : 'suppliers')->where('id', $id)->first(['id', 'code', 'name']);
    }

    public static function name(string $type, int $id): ?string
    {
        return self::find($type, $id)?->name;
    }
}
