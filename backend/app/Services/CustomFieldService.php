<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CustomFieldDef;
use App\Models\LookupType;
use Illuminate\Support\Facades\Validator;

/**
 * Admin-defined extra fields (FR-203). Values live in the entity's `custom` JSON column.
 * Unknown keys are dropped, types are checked, and required fields are enforced when asked.
 */
class CustomFieldService
{
    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    public function normalize(string $entity, ?array $input, bool $enforceRequired = false): array
    {
        $defs = CustomFieldDef::query()->where('entity', $entity)->where('is_active', true)->orderBy('sort_order')->get();
        $input ??= [];
        $clean = [];
        $errors = [];
        foreach ($defs as $def) {
            $has = array_key_exists($def->key, $input) && $input[$def->key] !== null && $input[$def->key] !== '' && $input[$def->key] !== [];
            if (! $has) {
                if ($enforceRequired && $def->is_required) {
                    $errors["custom.{$def->key}"] = ['errors.REQUIRED'];
                }

                continue;
            }
            $value = $input[$def->key];
            $rules = ['v' => $this->rule($def)];
            if ($def->field_type === 'multi_select') {
                $rules['v.*'] = ['string', 'in:'.implode(',', $this->allowedOptions($def))];
            }
            $v = Validator::make(['v' => $value], $rules);
            if ($v->fails()) {
                $errors["custom.{$def->key}"] = ['errors.VALIDATION'];

                continue;
            }
            $clean[$def->key] = $def->field_type === 'number' ? $value + 0 : $value;
        }
        if ($errors !== []) {
            throw new ApiException('VALIDATION', 422, '', $errors);
        }

        return $clean;
    }

    /** @return array<int, string> allowed option values for select fields */
    public function allowedOptions(CustomFieldDef $def): array
    {
        $opts = $def->options ?? [];
        if (isset($opts['lookup'])) {
            $type = LookupType::query()->where('key', $opts['lookup'])->first();

            return $type ? $type->values()->where('is_active', true)->pluck('code')->all() : [];
        }

        return array_values(array_map(fn ($o) => is_array($o) ? (string) ($o['value'] ?? '') : (string) $o, $opts));
    }

    /** @return array<int, mixed> */
    private function rule(CustomFieldDef $def): array
    {
        return match ($def->field_type) {
            'text' => ['string', 'max:255'],
            'long_text' => ['string', 'max:5000'],
            'number' => ['numeric'],
            'date' => ['date_format:Y-m-d'],
            'boolean' => ['boolean'],
            'url' => ['url', 'max:500'],
            'select' => ['string', 'in:'.implode(',', $this->allowedOptions($def))],
            'multi_select' => ['array', 'max:50'], // each entry is checked below
            default => ['string'],
        };
    }
}
