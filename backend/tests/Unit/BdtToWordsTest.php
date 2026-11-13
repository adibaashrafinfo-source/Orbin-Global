<?php

namespace Tests\Unit;

use App\Support\BdtToWords;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRD §7.3 edge cases: 0, 1, 100, 1 000, 99 999, 1 00 000, 1 23 45 678.50. */
class BdtToWordsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        return [
            'zero' => ['0', 'Taka Zero Only'],
            'zero with paisa' => ['0.50', 'Taka Zero and Fifty Paisa Only'],
            'one' => ['1', 'Taka One Only'],
            'hundred' => ['100', 'Taka One Hundred Only'],
            'teen' => ['19', 'Taka Nineteen Only'],
            'round tens' => ['70', 'Taka Seventy Only'],
            'thousand' => ['1000', 'Taka One Thousand Only'],
            'invoice sized' => ['12500', 'Taka Twelve Thousand Five Hundred Only'],
            'just below a lakh' => ['99999', 'Taka Ninety Nine Thousand Nine Hundred Ninety Nine Only'],
            'one lakh' => ['100000', 'Taka One Lakh Only'],
            'lakh with thousands' => ['125000', 'Taka One Lakh Twenty Five Thousand Only'],
            'ten lakh' => ['1000000', 'Taka Ten Lakh Only'],
            'one crore' => ['10000000', 'Taka One Crore Only'],
            'crore, lakh, thousand' => ['12345678.50', 'Taka One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight and Fifty Paisa Only'],
            'hundred crore' => ['1000000000', 'Taka One Hundred Crore Only'],
            'paisa only unit' => ['5.05', 'Taka Five and Five Paisa Only'],
            'one decimal place is tenths' => ['7.5', 'Taka Seven and Fifty Paisa Only'],
            'leading zeros are ignored' => ['0012', 'Taka Twelve Only'],
        ];
    }

    #[DataProvider('cases')]
    public function test_words(string $amount, string $expected): void
    {
        $this->assertSame($expected, BdtToWords::convert($amount));
    }

    public function test_it_refuses_junk_and_negatives(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BdtToWords::convert('-5');
    }
}
