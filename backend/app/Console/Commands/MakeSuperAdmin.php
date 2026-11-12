<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/** Creates (or resets) the first super admin during install (DEPLOY_CPANEL.md §6). */
class MakeSuperAdmin extends Command
{
    protected $signature = 'orbin:make-super-admin {--email=} {--name=} {--password=}';

    protected $description = 'Create the first super_admin user (interactive if options are omitted)';

    public function handle(AuditService $audit): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Full name');
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

        $v = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email', 'max:191'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $branch = Branch::query()->where('is_default', true)->first();
        $user = User::query()->withTrashed()->firstOrNew(['email' => $email]);
        $user->fill([
            'full_name' => $name,
            'role' => 'super_admin',
            'default_branch_id' => $branch?->id,
            'branch_ids' => $branch ? [$branch->id] : null,
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->password_hash = Hash::make($password);
        $user->deleted_at = null;
        $user->save();

        $audit->log('create', 'users', $user->id, null, ['email' => $email, 'role' => 'super_admin', 'via' => 'orbin:make-super-admin']);
        $this->info("Super admin {$email} ready. The user must change the password at first login.");

        return self::SUCCESS;
    }
}
