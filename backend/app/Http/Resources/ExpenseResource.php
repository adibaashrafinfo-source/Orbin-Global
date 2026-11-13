<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->only(['id', 'expense_no', 'category_id', 'amount', 'account_id', 'payee', 'note', 'attachment_id', 'status', 'approved_by', 'decision_note', 'created_by']),
            'expense_date' => $this->expense_date?->toDateString(),
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name_en' => $this->category->name_en, 'name_bn' => $this->category->name_bn]),
            'account' => $this->whenLoaded('account', fn () => ['id' => $this->account->id, 'name' => $this->account->name]),
        ];
    }
}
