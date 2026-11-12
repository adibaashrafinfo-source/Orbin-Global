<?php

namespace App\Models;

use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasBlame, SoftDeletes;

    protected $fillable = [
        'entity_table', 'entity_id', 'storage_bucket', 'storage_path', 'file_name', 'mime_type', 'size_bytes', 'label',
    ];
}
