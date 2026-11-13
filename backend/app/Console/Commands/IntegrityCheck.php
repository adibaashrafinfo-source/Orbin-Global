<?php

namespace App\Console\Commands;

use App\Services\Inventory\IntegrityService;
use Illuminate\Console\Command;

class IntegrityCheck extends Command
{
    protected $signature = 'integrity:check {--json : Print the result as JSON}';

    protected $description = 'Prove stock, costs, receipts and the supplier ledger against the append-only movements. Exits 1 when something drifted.';

    public function handle(IntegrityService $integrity): int
    {
        $result = $integrity->run();
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($result['checks'] as $c) {
                $this->line(sprintf('%-4s %-38s %s', strtoupper($c['status']), $c['key'], $c['count'] > 0 ? "({$c['count']})" : ''));
            }
            $this->line("Result: {$result['status']}");
        }

        return $result['status'] === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
