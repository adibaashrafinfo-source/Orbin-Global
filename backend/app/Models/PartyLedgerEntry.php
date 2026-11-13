<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\StampsCreator;
use Illuminate\Database\Eloquent\Model;

class PartyLedgerEntry extends Model
{
    use AppendOnly, StampsCreator;

    public const UPDATED_AT = null;

    protected $fillable = ['created_by', 'branch_id', 'party_type', 'party_id', 'entry_date', 'entry_type', 'debit', 'credit', 'ref_table', 'ref_id', 'ref_no', 'description'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d'];
    }
}
