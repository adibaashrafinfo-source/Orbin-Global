<?php

namespace App\Reports;

use App\Models\User;
use App\Services\PermissionService;

/** Who is asking: decides the columns they may see (cost needs products.view_cost) and whether they see everyone's sales or only their own. */
final class ReportContext
{
    public function __construct(
        public readonly User $user,
        public readonly bool $canCost,
        public readonly bool $ownOnly,
    ) {}

    public static function for(User $user): self
    {
        $p = app(PermissionService::class);

        return new self($user, $p->userHas($user, 'products.view_cost'), ! $p->userHas($user, 'reports.view_all') && $p->userHas($user, 'reports.view_own'));
    }
}
