<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PromiseRequest;
use App\Services\Finance\DuesService;
use App\Services\Finance\PartyPaymentService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-1007 */
class DuesController extends Controller
{
    public function __construct(private readonly DuesService $dues, private readonly PermissionService $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'type' => ['required', 'in:customer,supplier'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bucket' => ['sometimes', 'nullable', 'in:'.implode(',', DuesService::BUCKETS)], 'sort' => ['sometimes', 'nullable', 'in:balance,oldest,name'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $out = $this->dues->list($v['type'], $v);

        return response()->json(['data' => $out['data'], 'summary' => $out['summary'], 'meta' => $out['meta']]);
    }

    /** Whoever may collect from customers sets their promise-to-pay; whoever may pay suppliers sets ours. */
    public function promise(PromiseRequest $request): JsonResponse
    {
        $v = $request->validated();
        if (! $this->permissions->userHas($request->user(), PartyPaymentService::permissionFor($v['party_type']))) {
            throw ApiException::forbidden();
        }
        $this->dues->setPromise($v['party_type'], (int) $v['party_id'], $v['promise_date'], $v['promise_note'] ?? null);

        return response()->json(['data' => ['party_type' => $v['party_type'], 'party_id' => (int) $v['party_id'], 'promise_date' => $v['promise_date'], 'promise_note' => $v['promise_date'] ? ($v['promise_note'] ?? null) : null]]);
    }
}
