<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleFitment extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = ['variant_id', 'vehicle_model_id', 'year_from', 'year_to', 'trim', 'note', 'signature'];

    protected function casts(): array
    {
        return ['year_from' => 'integer', 'year_to' => 'integer'];
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public static function signatureFor(int $variantId, int $modelId, int $yearFrom, ?int $yearTo, ?string $trim): string
    {
        return sha1(implode('|', [$variantId, $modelId, $yearFrom, $yearTo ?? 'open', mb_strtolower(trim((string) $trim))]));
    }
}
