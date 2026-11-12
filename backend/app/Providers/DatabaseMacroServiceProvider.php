<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

/**
 * Blueprint macros implementing meta.commonColumns and meta.appendOnlyColumns from the spec.
 */
class DatabaseMacroServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // id | created_at | created_by | updated_at | updated_by | deleted_at
        Blueprint::macro('orbinCommon', function (): void {
            /** @var Blueprint $this */
            $this->id();
            $this->dateTime('created_at', 3)->useCurrent();
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $this->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $this->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $this->dateTime('deleted_at', 3)->nullable()->index();
        });

        // id | created_at | created_by  (append-only tables are never updated or deleted)
        Blueprint::macro('orbinAppendOnly', function (): void {
            /** @var Blueprint $this */
            $this->id();
            $this->dateTime('created_at', 3)->useCurrent();
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // branchScoped tables: branch_id | bigint | fk:branches.id | req idx
        Blueprint::macro('orbinBranchScoped', function (): void {
            /** @var Blueprint $this */
            $this->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
        });
    }
}
