<?php

namespace App\Console\Commands;

use App\Models\CompanyProfile;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/** Adds sample products, customers and sales for training (see DemoDataSeeder). Never runs in production. */
class DemoSeed extends Command
{
    protected $signature = 'orbin:demo-seed';

    protected $description = 'Add sample products, customers and sales for training (not for production)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: this is a production environment.');

            return self::FAILURE;
        }
        // Invoices need a complete company profile; the demo never writes the owner's company details.
        if (! CompanyProfile::current()->invoiceReady()) {
            $this->error('Fill Settings → Company profile (name, address, phone) first. The demo sales need it for invoices.');

            return self::FAILURE;
        }
        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
