<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomFieldDef extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $fillable = [
        'entity', 'key', 'label_en', 'label_bn', 'field_type', 'options', 'is_required', 'show_on_pos_card',
        'show_on_invoice', 'is_searchable', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array', 'is_required' => 'boolean', 'show_on_pos_card' => 'boolean',
            'show_on_invoice' => 'boolean', 'is_searchable' => 'boolean', 'sort_order' => 'integer',
        ];
    }
}
