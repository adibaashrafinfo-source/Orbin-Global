<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * app_settings access with the defaults from spec appSettingsDefaults.
 * Validation schema lives here so the API and seeder agree on types and options (FR-107).
 */
class SettingsService
{
    private const CACHE_KEY = 'orbin.app_settings';

    /** @var array<string, array{default: mixed, type: string, group: string, options?: array<int, string|int|null>, min?: int, max?: int}> */
    public const SCHEMA = [
        'vat_mode' => ['default' => 'exclusive', 'type' => 'enum', 'group' => 'sales', 'options' => ['exclusive', 'inclusive']],
        'default_tax_rate_code' => ['default' => 'VAT0', 'type' => 'string', 'group' => 'sales'],
        'rounding_mode' => ['default' => 'none', 'type' => 'enum', 'group' => 'sales', 'options' => ['none', 'nearest_1']],
        'cashier_max_discount_percent' => ['default' => 5, 'type' => 'int', 'group' => 'sales', 'min' => 0, 'max' => 100],
        'allow_negative_stock' => ['default' => false, 'type' => 'bool', 'group' => 'stock'],
        'require_customer_for_due' => ['default' => true, 'type' => 'bool', 'group' => 'sales'],
        'credit_limit_mode' => ['default' => 'warn', 'type' => 'enum', 'group' => 'sales', 'options' => ['warn', 'block']],
        'require_open_shift' => ['default' => true, 'type' => 'bool', 'group' => 'sales'],
        'reserve_on_hold' => ['default' => false, 'type' => 'bool', 'group' => 'stock'],
        'default_return_window_days' => ['default' => 7, 'type' => 'int', 'group' => 'sales', 'min' => 0, 'max' => 365],
        'driver_side' => ['default' => 'right', 'type' => 'enum', 'group' => 'sales', 'options' => ['left', 'right']],
        'week_start' => ['default' => 'saturday', 'type' => 'enum', 'group' => 'session', 'options' => ['saturday', 'sunday', 'monday']],
        'session_timeout_minutes' => ['default' => 480, 'type' => 'int', 'group' => 'session', 'min' => 5, 'max' => 1440],
        'idle_lock_minutes' => ['default' => 10, 'type' => 'int', 'group' => 'session', 'min' => 0, 'max' => 240],
        'adjustment_approval_threshold_qty' => ['default' => 5, 'type' => 'int', 'group' => 'stock', 'min' => 0, 'max' => 1000000],
        'adjustment_approval_threshold_value' => ['default' => 10000, 'type' => 'int', 'group' => 'stock', 'min' => 0, 'max' => 1000000000],
        'pin_attempt_limit' => ['default' => 5, 'type' => 'int', 'group' => 'session', 'min' => 1, 'max' => 20],
        'period_lock_date' => ['default' => null, 'type' => 'date', 'group' => 'session'],
        'invoice_default_language' => ['default' => 'en', 'type' => 'enum', 'group' => 'invoice', 'options' => ['en', 'bn']],
        'show_bangla_labels' => ['default' => false, 'type' => 'bool', 'group' => 'invoice'],
        'invoice_paper' => ['default' => 'a4', 'type' => 'enum', 'group' => 'invoice', 'options' => ['a4']],
        'receipt_width_mm' => ['default' => 80, 'type' => 'int', 'group' => 'invoice', 'min' => 58, 'max' => 80],
        'numeral_system' => ['default' => 'latn', 'type' => 'enum', 'group' => 'invoice', 'options' => ['latn', 'beng']],
        'pos_auto_download_invoice' => ['default' => true, 'type' => 'bool', 'group' => 'invoice'],
        'pos_auto_reset_seconds' => ['default' => 0, 'type' => 'int', 'group' => 'invoice', 'min' => 0, 'max' => 600],
        'require_verification_for_mfs' => ['default' => false, 'type' => 'bool', 'group' => 'sales'],
        'duplicate_trx_days' => ['default' => 30, 'type' => 'int', 'group' => 'sales', 'min' => 1, 'max' => 365],
        'dead_stock_days' => ['default' => 90, 'type' => 'int', 'group' => 'alerts', 'min' => 1, 'max' => 3650],
        'low_stock_notify' => ['default' => true, 'type' => 'bool', 'group' => 'alerts'],
        'void_same_day_manager_only' => ['default' => true, 'type' => 'bool', 'group' => 'sales'],
    ];

    /** @return array<string, mixed> every key with its current (or default) value */
    public function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => AppSetting::query()->pluck('value', 'key')->all());
        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = array_key_exists($key, $stored) ? $stored[$key] : $def['default'];
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? (self::SCHEMA[$key]['default'] ?? null);
    }

    /** @param  array<string, mixed>  $values  already validated */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;
            }
            $setting = AppSetting::query()->firstOrNew(['key' => $key]);
            $setting->fill([
                'value' => $value,
                'group_name' => self::SCHEMA[$key]['group'],
            ]);
            $setting->save();
        }
        Cache::forget(self::CACHE_KEY);
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Laravel validation rules derived from the schema (used by UpdateAppSettingsRequest). */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::SCHEMA as $key => $def) {
            $rule = ['sometimes'];
            $rule[] = match ($def['type']) {
                'bool' => 'boolean',
                'int' => 'integer',
                'date' => 'nullable',
                default => 'string',
            };
            if ($def['type'] === 'int') {
                $rule[] = 'min:'.($def['min'] ?? 0);
                $rule[] = 'max:'.($def['max'] ?? PHP_INT_MAX);
            }
            if ($def['type'] === 'date') {
                $rule[] = 'date_format:Y-m-d';
            }
            if ($def['type'] === 'enum') {
                $rule[] = 'in:'.implode(',', $def['options'] ?? []);
            }
            if ($def['type'] === 'string') {
                $rule[] = 'max:50';
            }
            $rules[$key] = $rule;
        }

        return $rules;
    }
}
