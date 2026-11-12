<?php

namespace App\Http\Resources;

use App\Models\CompanyProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyProfile */
class CompanyProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'tagline' => $this->tagline,
            'logo_url' => $this->logo_url,
            'signature_url' => $this->signature_url,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'district' => $this->district,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phones' => $this->phones ?? [],
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'website' => $this->website,
            'bin_no' => $this->bin_no,
            'tin_no' => $this->tin_no,
            'trade_license_no' => $this->trade_license_no,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_no' => $this->bank_account_no,
            'invoice_terms' => $this->invoice_terms,
            'return_policy_text' => $this->return_policy_text,
            'warranty_policy_text' => $this->warranty_policy_text,
            'footer_note' => $this->footer_note,
            'invoice_ready' => $this->invoiceReady(),
            'missing_invoice_fields' => $this->missingInvoiceFields(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
