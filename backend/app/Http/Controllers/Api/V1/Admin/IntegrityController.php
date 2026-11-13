<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Inventory\IntegrityService;
use Illuminate\Http\JsonResponse;

/** The integrity page: the last result, and a button to run it now. */
class IntegrityController extends Controller
{
    public function __construct(private readonly IntegrityService $integrity) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->integrity->last()]);
    }

    public function run(): JsonResponse
    {
        return response()->json(['data' => $this->integrity->run()]);
    }
}
