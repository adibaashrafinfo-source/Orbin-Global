<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Append-only tables are never updated or deleted (spec meta.appendOnlyRule).
 * Enforced here because shared cPanel hosting has no per-table DB users or triggers.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model): void {
            throw new LogicException(class_basename($model).' is append-only and cannot be updated.');
        });
        static::deleting(function ($model): void {
            throw new LogicException(class_basename($model).' is append-only and cannot be deleted.');
        });
    }
}
