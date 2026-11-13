<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/** Starter expense categories; admin-editable (CLAUDE.md rule 9). The transfer-fee category is the one the system posts to itself. */
class ExpenseCategorySeeder extends Seeder
{
    /** @var array<int, array{0: string|null, 1: string, 2: string}> code, English, Bangla */
    private const ROWS = [
        [null, 'Shop rent', 'দোকান ভাড়া'],
        [null, 'Staff salary', 'কর্মচারীর বেতন'],
        [null, 'Electricity and utilities', 'বিদ্যুৎ ও ইউটিলিটি'],
        [null, 'Internet and phone', 'ইন্টারনেট ও ফোন'],
        [null, 'Transport and delivery', 'পরিবহন ও ডেলিভারি'],
        [null, 'Marketing', 'বিপণন'],
        [null, 'Repair and maintenance', 'মেরামত ও রক্ষণাবেক্ষণ'],
        [null, 'Office supplies', 'অফিস সামগ্রী'],
        [null, 'Packaging', 'প্যাকেজিং'],
        [ExpenseCategory::TRANSFER_FEE, 'Bank and transfer charges', 'ব্যাংক ও ট্রান্সফার চার্জ'],
        [null, 'Miscellaneous', 'বিবিধ'],
    ];

    public function run(): void
    {
        ExpenseCategory::withoutEvents(function (): void {
            foreach (self::ROWS as [$code, $en, $bn]) {
                $existing = $code !== null
                    ? ExpenseCategory::withTrashed()->where('code', $code)->first()
                    : ExpenseCategory::withTrashed()->where('name_en', $en)->whereNull('code')->first();
                if ($existing === null) {
                    $row = new ExpenseCategory(['name_en' => $en, 'name_bn' => $bn, 'is_active' => true]);
                    $row->code = $code;
                    $row->save();
                }
            }
        });
    }
}
