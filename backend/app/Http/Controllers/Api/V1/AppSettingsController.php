<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAppSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/** FR-107 App settings (key → value map validated against SettingsService::SCHEMA). */
class AppSettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all(), 'meta' => ['schema' => $this->schema()]]);
    }

    public function update(UpdateAppSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request->validated());

        return response()->json(['data' => $this->settings->all(), 'meta' => ['schema' => $this->schema()]]);
    }

    /** @return array<string, array{type: string, group: string, options?: array<int, string|int|null>, min?: int, max?: int}> */
    private function schema(): array
    {
        $out = [];
        foreach (SettingsService::SCHEMA as $key => $def) {
            if ($key === 'period_lock_date') {
                continue;
            }
            unset($def['default']);
            $out[$key] = $def;
        }

        return $out;
    }
}
