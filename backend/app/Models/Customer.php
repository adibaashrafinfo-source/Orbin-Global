<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<int, string> $phones
 */
class Customer extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = [
        'code', 'customer_type_id', 'price_tier', 'name', 'company_name', 'phones', 'whatsapp', 'email', 'address_line1', 'address_line2',
        'area', 'district', 'postcode', 'nid_no', 'trade_license_no', 'bin_no', 'credit_limit', 'opening_balance', 'notes', 'custom', 'is_active',
    ];

    protected function casts(): array
    {
        return ['phones' => 'array', 'custom' => 'array', 'is_walk_in' => 'boolean', 'is_active' => 'boolean'];
    }

    /** phone_primary and search_text are system maintained so lookups and duplicate warnings never depend on callers. */
    protected static function booted(): void
    {
        static::saving(function (self $c): void {
            $phones = array_values(array_filter(array_map(fn ($p) => preg_replace('/\D+/', '', (string) $p), $c->phones ?? []), fn ($p) => $p !== ''));
            $c->phone_primary = $phones[0] ?? null;
            $c->search_text = mb_strtolower(trim(implode(' ', array_filter([$c->code, $c->name, $c->company_name, ...$phones]))));
        });
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class);
    }
}
