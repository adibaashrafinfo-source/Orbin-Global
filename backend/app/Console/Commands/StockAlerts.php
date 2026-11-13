<?php

namespace App\Console\Commands;

use App\Services\Inventory\AlertService;
use Illuminate\Console\Command;

class StockAlerts extends Command
{
    protected $signature = 'stock:alerts';

    protected $description = 'Raise low-stock, out-of-stock and negative-stock bell entries.';

    public function handle(AlertService $alerts): int
    {
        $counts = $alerts->run();
        $this->line('low '.$counts['low'].', out '.$counts['out'].', dead '.$counts['dead'].', negative '.$counts['negative']);

        return self::SUCCESS;
    }
}
