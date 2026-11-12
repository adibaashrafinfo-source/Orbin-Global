<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNumberSequenceRequest;
use App\Http\Resources\NumberSequenceResource;
use App\Models\NumberSequence;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** FR-106 Numbering formats. Only the template rows (period_key = 'all') are editable. */
class NumberingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return NumberSequenceResource::collection(
            NumberSequence::query()->where('period_key', 'all')->orderBy('id')->get(),
        );
    }

    public function update(UpdateNumberSequenceRequest $request, NumberSequence $sequence): NumberSequenceResource
    {
        abort_if($sequence->period_key !== 'all', 404);
        $data = $request->validated();
        DB::transaction(function () use ($sequence, $data): void {
            $row = NumberSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
            // next_value may only move forward (gap-free: never reuse a number).
            if (isset($data['next_value']) && (int) $data['next_value'] < (int) $row->next_value) {
                unset($data['next_value']);
            }
            $row->fill($data)->save();
            // Keep per-period counters' format in sync; counters themselves are untouched.
            NumberSequence::query()
                ->where('key', $row->key)
                ->where(fn ($q) => $row->branch_id === null ? $q->whereNull('branch_id') : $q->where('branch_id', $row->branch_id))
                ->where('period_key', '!=', 'all')
                ->update(['prefix' => $row->prefix, 'date_token' => $row->date_token, 'padding' => $row->padding, 'reset_policy' => $row->reset_policy]);
        });

        return new NumberSequenceResource($sequence->refresh());
    }
}
