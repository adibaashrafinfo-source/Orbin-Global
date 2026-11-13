<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuotationItem extends Model
{
    use HasBlame, SoftDeletes;

    protected $guarded = [];
}
