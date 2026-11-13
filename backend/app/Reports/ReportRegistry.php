<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Defs\FinanceReports;
use App\Reports\Defs\InventoryReports;
use App\Reports\Defs\ProfitReports;
use App\Reports\Defs\PurchaseReports;
use App\Reports\Defs\SalesReports;
use App\Reports\Defs\TaxAuditReports;
use App\Services\PermissionService;

/** The report catalog, in the order of the spec, and who may open what. */
class ReportRegistry
{
    /** @var array<string, ReportDef>|null */
    private ?array $defs = null;

    public function __construct(private readonly PermissionService $permissions) {}

    /** @return array<string, ReportDef> */
    public function all(): array
    {
        if ($this->defs === null) {
            $this->defs = [];
            foreach ([SalesReports::defs(), ProfitReports::defs(), InventoryReports::defs(), PurchaseReports::defs(), FinanceReports::defs(), TaxAuditReports::defs()] as $group) {
                foreach ($group as $def) {
                    $this->defs[$def->id] = $def;
                }
            }
        }

        return $this->defs;
    }

    public function find(string $id): ?ReportDef
    {
        return $this->all()[$id] ?? null;
    }

    public function canOpen(User $user, ReportDef $def): bool
    {
        if (! $this->permissions->userHasAny($user, $def->permissions)) {
            return false;
        }

        // Profit and margin come from the cost on each line, so they need the cost permission too (CLAUDE.md rule 8).
        return ! $def->needsCost || $this->permissions->userHas($user, 'products.view_cost');
    }

    /** @return array<int, ReportDef> */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (ReportDef $d) => $this->canOpen($user, $d)));
    }
}
