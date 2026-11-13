<?php

namespace App\Services\Inventory;

use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** FR-407: the nightly low-stock, out-of-stock and dead-stock bell entries. Each is raised once until someone reads it. */
class AlertService
{
    public function __construct(private readonly StockQueryService $query, private readonly NotifyService $notify, private readonly SettingsService $settings) {}

    /** @return array<string, int> */
    public function run(): array
    {
        $counts = ['low' => 0, 'out' => 0, 'dead' => 0, 'negative' => 0];
        if ($this->settings->get('low_stock_notify')) {
            $low = $this->query->overview(['status' => 'low'], false)->get();
            foreach ($low->take(50) as $r) {
                $this->notify->roles(['manager', 'storekeeper'], 'low_stock', 'warning', 'Low stock: '.$r->sku, $r->product_name.' has '.rtrim(rtrim((string) $r->on_hand, '0'), '.').' left (reorder at '.rtrim(rtrim((string) $r->reorder_level, '0'), '.').').', 'product_variants', (int) $r->variant_id);
            }
            $counts['low'] = $low->count();
            $out = $this->query->overview(['status' => 'out'], false)->where('reorder_level', '>', 0)->get();
            foreach ($out->take(50) as $r) {
                $this->notify->roles(['manager', 'storekeeper'], 'out_of_stock', 'critical', 'Out of stock: '.$r->sku, $r->product_name, 'product_variants', (int) $r->variant_id);
            }
            $counts['out'] = $out->count();
        }
        $dead = $this->query->overview(['status' => 'dead'], false)->get();
        $counts['dead'] = $dead->count();
        $negative = DB::table('stock_levels')->whereNull('deleted_at')->where('qty_on_hand', '<', 0)->get(['variant_id']);
        foreach ($negative as $n) {
            $this->notify->roles(['manager', 'super_admin'], 'negative_stock', 'critical', 'Negative stock on variant '.$n->variant_id, 'Stock is below zero. Check recent sales and receipts.', 'product_variants', (int) $n->variant_id);
        }
        $counts['negative'] = $negative->count();

        return $counts;
    }
}
