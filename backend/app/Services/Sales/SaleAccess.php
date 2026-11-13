<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Sale;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Who may see which invoice: everyone with sales.view_all, otherwise only the cashier's own bills (spec sales access). */
class SaleAccess
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function canViewAll(User $user): bool
    {
        return $this->permissions->userHas($user, 'sales.view_all');
    }

    public function assertCanView(User $user, Sale $sale): void
    {
        if ($this->canViewAll($user)) {
            return;
        }
        if ($this->permissions->userHas($user, 'sales.view_own') && $sale->cashier_id === $user->id) {
            return;
        }
        throw ApiException::forbidden();
    }

    /** @param  Builder<Sale>|QueryBuilder  $query */
    public function scope(User $user, $query, string $column = 'cashier_id'): void
    {
        if (! $this->canViewAll($user)) {
            $query->where($column, $user->id);
        }
    }
}
