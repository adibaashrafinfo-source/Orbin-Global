<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasBlame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Singleton row (singleton = 1). Drives invoice header/footer; snapshotted into every sale.
 *
 * @property array<int, string> $phones
 */
class CompanyProfile extends Model
{
    use Auditable, HasBlame, SoftDeletes;

    protected $table = 'company_profile';

    protected $fillable = [
        'legal_name', 'trade_name', 'tagline', 'address_line1', 'address_line2', 'city', 'district', 'postcode', 'country',
        'phones', 'whatsapp', 'email', 'website', 'bin_no', 'tin_no', 'trade_license_no', 'bank_name', 'bank_branch',
        'bank_account_name', 'bank_account_no', 'invoice_terms', 'return_policy_text', 'warranty_policy_text', 'footer_note',
    ];

    protected function casts(): array
    {
        return ['phones' => 'array', 'singleton' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['singleton' => 1],
            ['legal_name' => 'Orbin Global', 'address_line1' => '', 'phones' => []],
        );
    }

    /** Invoice generation is blocked until name, address and a phone exist (FR-104, CLAUDE.md §6). */
    public function invoiceReady(): bool
    {
        return trim((string) $this->legal_name) !== ''
            && trim((string) $this->address_line1) !== ''
            && count(array_filter($this->phones ?? [], fn ($p) => trim((string) $p) !== '')) > 0;
    }

    /** @return array<int, string> */
    public function missingInvoiceFields(): array
    {
        $missing = [];
        if (trim((string) $this->legal_name) === '') {
            $missing[] = 'legal_name';
        }
        if (trim((string) $this->address_line1) === '') {
            $missing[] = 'address_line1';
        }
        if (count(array_filter($this->phones ?? [], fn ($p) => trim((string) $p) !== '')) === 0) {
            $missing[] = 'phones';
        }

        return $missing;
    }
}
