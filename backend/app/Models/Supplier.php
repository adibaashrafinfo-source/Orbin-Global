<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const TYPES = ['local', 'import'];

    protected $fillable = ['code', 'name', 'contact_person', 'phones', 'email', 'address', 'city', 'country', 'supplier_type', 'payment_terms_days', 'credit_limit', 'opening_balance', 'bank_details', 'notes', 'custom', 'is_active'];

    protected function casts(): array
    {
        return ['phones' => 'array', 'bank_details' => 'array', 'custom' => 'array', 'is_active' => 'boolean'];
    }
}
