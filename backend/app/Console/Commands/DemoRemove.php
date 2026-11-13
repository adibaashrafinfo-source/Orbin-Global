<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Console\Command;

/**
 * Removes the demo logins that `SEED_DEMO_USERS=true` created. Users are soft-deleted, so the audit trail that names them stays
 * readable. Refuses to run in production, and asks for confirmation unless --force is given.
 */
class DemoRemove extends Command
{
    protected $signature = 'orbin:demo-remove {--force : Do not ask for confirmation}';

    protected $description = 'Soft-delete the demo logins created by the demo seed (never in production)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: this is a production environment.');

            return self::FAILURE;
        }

        $emails = array_column(DemoUserSeeder::USERS, 'email');
        $users = User::withTrashed()->whereIn('email', $emails)->whereNull('deleted_at')->get();
        if ($users->isEmpty()) {
            $this->info('No demo logins to remove.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Remove '.$users->count().' demo login(s): '.implode(', ', $users->pluck('email')->all()).'?')) {
            $this->info('Nothing removed.');

            return self::SUCCESS;
        }

        User::withoutEvents(fn () => $users->each->delete());
        $this->info('Removed '.$users->count().' demo login(s). Their history stays in the audit log.');

        return self::SUCCESS;
    }
}
