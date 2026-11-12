<?php

namespace Database\Seeders;

use App\Models\NumberSequence;
use Illuminate\Database\Seeder;

/** spec seedData.number_sequences — global (branch-less) definitions. */
class NumberSequenceSeeder extends Seeder
{
    public const DEFAULTS = [
        ['key' => 'invoice', 'prefix' => 'ORB-INV', 'date_token' => 'YYMM', 'padding' => 6, 'reset_policy' => 'yearly'],
        ['key' => 'quotation', 'prefix' => 'ORB-QT', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'purchase_order', 'prefix' => 'ORB-PO', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'goods_receipt', 'prefix' => 'ORB-GRN', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'adjustment', 'prefix' => 'ORB-ADJ', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'transfer', 'prefix' => 'ORB-TRF', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'count', 'prefix' => 'ORB-CNT', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'sale_return', 'prefix' => 'ORB-SR', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'purchase_return', 'prefix' => 'ORB-PR', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'party_payment', 'prefix' => 'ORB-PAY', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'expense', 'prefix' => 'ORB-EXP', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'fund_transfer', 'prefix' => 'ORB-FT', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'shift', 'prefix' => 'ORB-SH', 'date_token' => 'YYMMDD', 'padding' => 3, 'reset_policy' => 'never'],
        ['key' => 'warranty_claim', 'prefix' => 'ORB-WC', 'date_token' => 'YYMM', 'padding' => 5, 'reset_policy' => 'yearly'],
        ['key' => 'customer', 'prefix' => 'CUS', 'date_token' => '', 'padding' => 6, 'reset_policy' => 'never'],
        ['key' => 'supplier', 'prefix' => 'SUP', 'date_token' => '', 'padding' => 5, 'reset_policy' => 'never'],
    ];

    public function run(): void
    {
        NumberSequence::withoutEvents(function (): void {
            foreach (self::DEFAULTS as $row) {
                NumberSequence::query()->firstOrCreate(
                    ['key' => $row['key'], 'branch_id' => null, 'period_key' => 'all'],
                    $row + ['next_value' => 1],
                );
            }
        });
    }
}
