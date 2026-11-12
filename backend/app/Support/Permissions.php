<?php

namespace App\Support;

/**
 * Permission keys and the default role matrix (spec → roles.permissionKeys / roles.defaultMatrix).
 * A unit test asserts this file matches orbin-pos-spec.json.
 */
final class Permissions
{
    public const ROLES = ['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant'];

    public const WILDCARD = '*';

    public const KEYS = [
        'settings.company.manage', 'settings.system.manage', 'users.manage', 'roles.manage', 'audit.view', 'periods.lock',
        'masterdata.manage', 'masterdata.view',
        'products.view', 'products.manage', 'products.view_cost', 'pricing.manage', 'products.import',
        'pos.sell', 'pos.discount_over_limit', 'pos.price_override', 'pos.sell_below_floor',
        'sales.view_all', 'sales.view_own', 'sales.void', 'sales.return.create', 'sales.return.approve',
        'quotations.manage',
        'shifts.open_close_own', 'shifts.close_any', 'shifts.view_all',
        'customers.view', 'customers.manage', 'suppliers.manage',
        'purchases.manage', 'purchases.grn', 'purchases.view',
        'inventory.view', 'inventory.adjust', 'inventory.adjust.approve', 'inventory.transfer', 'inventory.count', 'inventory.reports',
        'ledger.view', 'ledger.receive_payment', 'ledger.pay_supplier', 'ledger.writeoff',
        'payments.verify', 'expenses.manage', 'accounts.manage', 'fund_transfers.manage',
        'reports.view_all', 'reports.view_own', 'reports.view_stock', 'reports.view_profit', 'reports.export',
    ];

    public const DEFAULT_MATRIX = [
        'super_admin' => [self::WILDCARD],
        'manager' => [
            'masterdata.manage', 'masterdata.view', 'products.view', 'products.manage', 'products.view_cost', 'pricing.manage',
            'products.import', 'pos.sell', 'pos.discount_over_limit', 'pos.price_override', 'pos.sell_below_floor', 'sales.view_all',
            'sales.void', 'sales.return.create', 'sales.return.approve', 'quotations.manage', 'shifts.open_close_own', 'shifts.close_any',
            'shifts.view_all', 'customers.view', 'customers.manage', 'suppliers.manage', 'purchases.manage', 'purchases.grn',
            'purchases.view', 'inventory.view', 'inventory.adjust', 'inventory.adjust.approve', 'inventory.transfer', 'inventory.count',
            'inventory.reports', 'ledger.view', 'ledger.receive_payment', 'ledger.pay_supplier', 'payments.verify', 'expenses.manage',
            'accounts.manage', 'fund_transfers.manage', 'reports.view_all', 'reports.view_stock', 'reports.view_profit', 'reports.export',
            'audit.view',
        ],
        'cashier' => [
            'products.view', 'pos.sell', 'pos.price_override', 'sales.view_own', 'sales.return.create', 'quotations.manage',
            'shifts.open_close_own', 'customers.view', 'customers.manage', 'ledger.receive_payment', 'inventory.view', 'reports.view_own',
        ],
        'storekeeper' => [
            'masterdata.view', 'products.view', 'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.count',
            'purchases.grn', 'purchases.view', 'reports.view_stock', 'inventory.reports',
        ],
        'accountant' => [
            'products.view', 'products.view_cost', 'customers.view', 'purchases.view', 'inventory.view', 'ledger.view',
            'ledger.receive_payment', 'ledger.pay_supplier', 'ledger.writeoff', 'payments.verify', 'expenses.manage', 'accounts.manage',
            'fund_transfers.manage', 'reports.view_all', 'reports.view_stock', 'reports.view_profit', 'reports.export', 'sales.view_all',
        ],
    ];

    /** Module prefix of a key, e.g. "sales.return.create" → "sales". */
    public static function module(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /** @return array<string, bool> key => allowed for a role using the default matrix. */
    public static function defaultsFor(string $role): array
    {
        $allowed = self::DEFAULT_MATRIX[$role] ?? [];
        $all = in_array(self::WILDCARD, $allowed, true);
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $all || in_array($key, $allowed, true);
        }

        return $out;
    }
}
