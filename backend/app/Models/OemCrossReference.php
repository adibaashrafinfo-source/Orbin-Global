<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OemCrossReference extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    public const TYPES = ['oem', 'aftermarket', 'competitor', 'supplier'];

    protected $fillable = ['variant_id', 'ref_type', 'number', 'number_normalized', 'brand', 'note'];

    /** Uppercase, without spaces, dashes or dots: "87910-02B30" and "8791002b30" match. */
    public static function normalize(string $number): string
    {
        return strtoupper((string) preg_replace('/[\s\-\.\/_]+/', '', $number));
    }
}
