<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON cast that stores PHP null as the JSON literal `null` (the column is NOT NULL per spec).
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class JsonValue implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : json_decode((string) $value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
