<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\HealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/** FR-112 Hosting health page: checks, cron heartbeat and audited admin actions. */
class HealthController extends Controller
{
    public function __construct(
        private readonly HealthService $health,
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->health->report($request->isSecure())]);
    }

    public function clearCaches(): JsonResponse
    {
        Artisan::call('optimize:clear');
        if (app()->isProduction()) {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');
        }
        $this->audit->log('admin_action', 'system', null, null, ['action' => 'clear_caches']);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function maintenance(Request $request): JsonResponse
    {
        $enable = $request->boolean('enabled');
        if ($enable) {
            $secret = Str::lower(Str::random(32));
            Artisan::call('down', ['--secret' => $secret, '--retry' => 60]);
            $this->audit->log('admin_action', 'system', null, null, ['action' => 'maintenance_on']);

            // The bypass link lets the admin keep working while staff see the maintenance page.
            return response()->json(['data' => ['enabled' => true, 'bypass_url' => rtrim((string) config('app.url'), '/').'/'.$secret]]);
        }
        Artisan::call('up');
        $this->audit->log('admin_action', 'system', null, null, ['action' => 'maintenance_off']);

        return response()->json(['data' => ['enabled' => false]]);
    }

    public function runMigrations(): JsonResponse
    {
        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());
        $this->audit->log('admin_action', 'system', null, null, ['action' => 'run_migrations', 'output' => Str::limit($output, 2000)]);

        return response()->json(['data' => ['ok' => true, 'output' => $output]]);
    }
}
