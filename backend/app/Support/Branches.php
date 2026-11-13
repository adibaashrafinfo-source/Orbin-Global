<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Support\Facades\Auth;

/** Which branch a new document belongs to when the caller does not say. */
final class Branches
{
    public static function currentId(): int
    {
        $user = Auth::user();
        if ($user && $user->default_branch_id) {
            return (int) $user->default_branch_id;
        }

        return (int) (Branch::query()->where('is_default', true)->value('id') ?? Branch::query()->orderBy('id')->value('id'));
    }

    /** Today in the shop's timezone (BR-090). */
    public static function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }
}
