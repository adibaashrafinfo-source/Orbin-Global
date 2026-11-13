<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerVehicle extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = ['customer_id', 'reg_no', 'make_id', 'model_id', 'year', 'color', 'note'];

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }
}
