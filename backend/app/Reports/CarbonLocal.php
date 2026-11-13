<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/** A UTC timestamp as the Dhaka clock shows it. */
final class CarbonLocal
{
    public static function at(string $utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s');
    }
}
