<?php

namespace Tests\Unit;

use App\Services\NumberService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class NumberFormatTest extends TestCase
{
    public function test_formats_prefix_token_and_padding(): void
    {
        $svc = new NumberService;
        $date = CarbonImmutable::create(2025, 11, 7, 10, 0, 0, 'Asia/Dhaka');
        $this->assertSame('ORB-INV-2511-000001', $svc->format('ORB-INV', 'YYMM', 6, 1, $date));
        $this->assertSame('ORB-SH-251107-012', $svc->format('ORB-SH', 'YYMMDD', 3, 12, $date));
        $this->assertSame('CUS-000042', $svc->format('CUS', '', 6, 42, $date));
        $this->assertSame('CUS-000042', $svc->format('CUS', null, 6, 42, $date));
        $this->assertSame('X-2025-1234567', $svc->format('X', 'YYYY', 6, 1234567, $date));
    }
}
