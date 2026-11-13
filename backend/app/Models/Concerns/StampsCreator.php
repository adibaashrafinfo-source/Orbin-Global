<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/** created_by for append-only tables, which have no updated_by column (so HasBlame does not fit). */
trait StampsCreator
{
    public static function bootStampsCreator(): void
    {
        static::creating(function ($model): void {
            $model->created_by ??= Auth::id();
        });
    }
}
