<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagedResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CustomerRequest;
use App\Http\Requests\Sales\CustomerVehicleRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Services\Sales\CustomerService;
use App\Support\Dec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-601 to FR-605 */
class CustomerController extends Controller
{
    use PagedResponses;

    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Customer::query()->orderByDesc('is_walk_in')->orderBy('name');
        if ($q = mb_strtolower(trim((string) $request->input('search', '')))) {
            // A typed phone number matches the digits stored for it, however it was spaced or dashed.
            $digits = (string) preg_replace('/\D+/', '', $q);
            $needle = $digits !== '' && $digits === preg_replace('/[\s\-]+/', '', $q) ? $digits : $q;
            $query->where('search_text', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $needle).'%');
        }
        foreach (['price_tier', 'customer_type_id'] as $col) {
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }
        if ($request->has('filter.is_active') && $request->input('filter.is_active') !== '') {
            $query->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOL));
        }
        if ($request->boolean('filter.with_due')) {
            $query->whereIn('id', DB::table('party_ledger_entries')->where('party_type', 'customer')->groupBy('party_id')->havingRaw('SUM(debit) - SUM(credit) > 0')->select('party_id'));
        }
        $result = $this->paged($query, $request, CustomerResource::class);
        $ids = collect($result->resource)->pluck('id')->all();
        $balances = DB::table('party_ledger_entries')->where('party_type', 'customer')->whereIn('party_id', $ids)->groupBy('party_id')
            ->selectRaw('party_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->get()->keyBy('party_id');
        foreach ($result->resource as $c) {
            $b = $balances[$c->id] ?? null;
            $c->balance = Dec::fmt($b ? Dec::parse((string) $b->d, Dec::MONEY) - Dec::parse((string) $b->c, Dec::MONEY) : 0, Dec::MONEY);
        }

        return $result;
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load(['vehicles.make', 'vehicles.model']));
    }

    /** FR-604: the customer 360 page in one call. */
    public function overview(Customer $customer): JsonResponse
    {
        $sales = DB::table('sales')->where('customer_id', $customer->id)->whereIn('status', ['completed', 'partially_returned', 'returned'])->whereNull('deleted_at');
        $lifetime = Dec::parse((string) (clone $sales)->sum('grand_total'), Dec::MONEY);
        $balance = Dec::parse($this->customers->balance($customer->id), Dec::MONEY);

        return response()->json(['data' => [
            'customer' => new CustomerResource($customer->load(['vehicles.make', 'vehicles.model'])),
            'balance' => Dec::fmt($balance, Dec::MONEY),
            'due' => Dec::fmt(max($balance, 0), Dec::MONEY), 'advance' => Dec::fmt(max(-$balance, 0), Dec::MONEY),
            'lifetime_value' => Dec::fmt($lifetime, Dec::MONEY), 'invoice_count' => (clone $sales)->count(),
            'last_purchase_at' => (clone $sales)->max('sale_date'),
            'invoices' => (clone $sales)->orderByDesc('sale_date')->limit(20)->get(['id', 'invoice_no', 'sale_date', 'status', 'grand_total', 'paid_total', 'due_total', 'payment_status']),
            'ledger' => DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $customer->id])->orderByDesc('id')->limit(50)->get(['id', 'entry_date', 'entry_type', 'debit', 'credit', 'ref_no', 'description']),
            'top_products' => DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.customer_id', $customer->id)->whereIn('s.status', ['completed', 'partially_returned', 'returned'])
                ->whereNull('i.bundle_parent_line')->groupBy('i.product_name_snapshot')->orderByRaw('SUM(i.qty) DESC')->limit(5)->selectRaw('i.product_name_snapshot as name, SUM(i.qty) as qty')->get(),
        ]]);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ack = (bool) ($data['acknowledge_duplicate'] ?? false);
        unset($data['acknowledge_duplicate']);

        return (new CustomerResource($this->customers->create($data, $ack)))->response()->setStatusCode(201);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $data = $request->validated();
        unset($data['acknowledge_duplicate']);

        return new CustomerResource($this->customers->update($customer, $data));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customers->delete($customer);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function storeVehicle(CustomerVehicleRequest $request, Customer $customer): JsonResponse
    {
        $v = $this->customers->saveVehicle($customer, $request->validated());

        return response()->json(['data' => $v->load(['make', 'model'])->only(['id', 'reg_no', 'make_id', 'model_id', 'year', 'color', 'note'])], 201);
    }

    public function updateVehicle(CustomerVehicleRequest $request, CustomerVehicle $vehicle): JsonResponse
    {
        $v = $this->customers->saveVehicle(Customer::query()->findOrFail($vehicle->customer_id), $request->validated(), $vehicle);

        return response()->json(['data' => $v->only(['id', 'reg_no', 'make_id', 'model_id', 'year', 'color', 'note'])]);
    }

    public function destroyVehicle(CustomerVehicle $vehicle): JsonResponse
    {
        $vehicle->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
