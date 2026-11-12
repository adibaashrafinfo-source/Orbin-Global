<?php

namespace App\Http\Resources;

use App\Models\NumberSequence;
use App\Services\NumberService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NumberSequence */
class NumberSequenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'branch_id' => $this->branch_id,
            'prefix' => $this->prefix,
            'date_token' => $this->date_token ?? '',
            'padding' => $this->padding,
            'reset_policy' => $this->reset_policy,
            'period_key' => $this->period_key,
            'next_value' => $this->next_value,
            'example' => app(NumberService::class)->preview($this->resource),
        ];
    }
}
