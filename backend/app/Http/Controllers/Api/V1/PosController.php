<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftResource;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\PermissionService;
use App\Services\Sales\ShiftService;
use App\Services\Sales\VariantSearchService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-701 to FR-704: what the POS terminal needs to start, and its two search calls. */
class PosController extends Controller
{
    public function __construct(
        private readonly VariantSearchService $search,
        private readonly SettingsService $settings,
        private readonly ShiftService $shifts,
        private readonly PermissionService $permissions,
    ) {}

    public function config(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = CompanyProfile::current();
        $shift = $this->shifts->current($user);
        $s = $this->settings->all();

        return response()->json(['data' => [
            'settings' => array_intersect_key($s, array_flip(['vat_mode', 'rounding_mode', 'cashier_max_discount_percent', 'credit_limit_mode', 'require_open_shift', 'driver_side', 'pos_auto_download_invoice',
                'pos_auto_reset_seconds', 'require_verification_for_mfs', 'default_return_window_days', 'receipt_width_mm', 'duplicate_trx_days', 'numeral_system'])),
            'default_tax_rate' => (string) (TaxRate::query()->where('code', $s['default_tax_rate_code'])->value('rate_percent') ?? '0'),
            'warehouses' => Warehouse::query()->where(['is_active' => true, 'is_sellable' => true])->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'accounts' => FinancialAccount::query()->where('is_active', true)->orderBy('account_type')->orderBy('name')->get(['id', 'name', 'account_type', 'account_number', 'provider', 'is_default_for_method']),
            'walk_in_customer_id' => Customer::query()->where('is_walk_in', true)->value('id'),
            'shift' => $shift ? new ShiftResource($shift) : null,
            'company_ready' => $company->invoiceReady(), 'company_missing' => $company->missingInvoiceFields(),
            'can' => array_combine(['price_override', 'sell_below_floor', 'discount_over_limit', 'view_cost'], array_map(fn ($k) => $this->permissions->userHas($user, $k), ['pos.price_override', 'pos.sell_below_floor', 'pos.discount_over_limit', 'products.view_cost'])),
        ]]);
    }

    public function variants(Request $request): JsonResponse
    {
        $f = $request->validate([
            'make_id' => ['sometimes', 'nullable', 'integer'], 'model_id' => ['sometimes', 'nullable', 'integer'], 'year' => ['sometimes', 'nullable', 'integer', 'between:1950,2100'],
            'position' => ['sometimes', 'nullable', 'in:left,right,interior,pair,universal'], 'text' => ['sometimes', 'nullable', 'string', 'max:100'], 'only_in_stock' => ['sometimes', 'boolean'],
            'warehouse_id' => ['sometimes', 'nullable', 'integer'], 'mirror_type' => ['sometimes', 'nullable', 'string', 'max:40'], 'brand_id' => ['sometimes', 'nullable', 'integer'],
            'quality_grade_id' => ['sometimes', 'nullable', 'integer'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:60'], 'offset' => ['sometimes', 'integer', 'min:0'],
        ]);
        $result = $this->search->search($f, (int) ($f['limit'] ?? 24), (int) ($f['offset'] ?? 0));

        return response()->json(['data' => $result['data'], 'meta' => ['total' => $result['total'], 'warehouse_id' => $result['warehouse_id']]]);
    }

    /** A barcode scanner types the code and presses Enter; this resolves it to exactly one variant (FR-703). */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:191'], 'warehouse_id' => ['sometimes', 'nullable', 'integer']]);
        $hit = $this->search->scan($data['code'], $data['warehouse_id'] ?? null);

        return response()->json(['data' => $hit]);
    }
}
