<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashShift extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['branch_id', 'shift_no', 'cashier_id', 'cash_account_id', 'status', 'opened_at', 'opening_float', 'closed_at', 'expected_cash', 'counted_cash', 'variance', 'denomination_counts', 'totals', 'note', 'z_report_path', 'open_key'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'denomination_counts' => 'array', 'totals' => 'array'];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
