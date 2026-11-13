<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'vehicle_snapshot' => 'array', 'quote_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('id');
    }
}
