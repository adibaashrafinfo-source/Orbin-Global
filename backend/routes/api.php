<?php

use App\Http\Controllers\Api\V1\Admin\HealthController;
use App\Http\Controllers\Api\V1\AppSettingsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NumberingController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 is set in bootstrap/app.php. Every route below is authorized by auth + permission middleware
// (plus Gates/Policies inside controllers); UI hiding is never the security.

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('auth.forgot');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('auth.reset');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('pin/verify', [AuthController::class, 'verifyPin'])->middleware('throttle:10,1')->name('auth.pin');
        Route::put('profile', [AuthController::class, 'updateProfile'])->name('auth.profile');
        Route::put('password', [AuthController::class, 'changePassword'])->name('auth.password');
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    // Settings
    Route::get('settings/company', [CompanyProfileController::class, 'show'])->name('settings.company.show');
    Route::middleware('permission:settings.company.manage')->group(function (): void {
        Route::put('settings/company', [CompanyProfileController::class, 'update'])->name('settings.company.update');
        Route::post('settings/company/logo', [CompanyProfileController::class, 'uploadLogo'])->name('settings.company.logo');
        Route::post('settings/company/signature', [CompanyProfileController::class, 'uploadSignature'])->name('settings.company.signature');
    });
    Route::get('settings/app', [AppSettingsController::class, 'show'])->name('settings.app.show');
    Route::middleware('permission:settings.system.manage')->group(function (): void {
        Route::put('settings/app', [AppSettingsController::class, 'update'])->name('settings.app.update');
        Route::get('settings/numbering', [NumberingController::class, 'index'])->name('settings.numbering.index');
        Route::put('settings/numbering/{sequence}', [NumberingController::class, 'update'])->name('settings.numbering.update');
    });

    // Users and roles
    Route::middleware('permission:users.manage')->group(function (): void {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/pin', [UserController::class, 'setPin'])->name('users.pin');
    });
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show'); // self or users.manage (policy)
    Route::get('role-permissions', [RolePermissionController::class, 'index'])->name('roles.index');
    Route::middleware('permission:roles.manage')->group(function (): void {
        Route::put('role-permissions/{role}', [RolePermissionController::class, 'update'])->name('roles.update');
        Route::post('role-permissions/{role}/reset', [RolePermissionController::class, 'reset'])->name('roles.reset');
    });

    // Branches, warehouses, storage locations
    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::get('storage-locations', [StorageLocationController::class, 'index'])->name('storage-locations.index');
    Route::middleware('permission:settings.system.manage')->group(function (): void {
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    });
    Route::middleware('permission:settings.system.manage|inventory.adjust')->group(function (): void {
        Route::post('storage-locations', [StorageLocationController::class, 'store'])->name('storage-locations.store');
        Route::put('storage-locations/{storage_location}', [StorageLocationController::class, 'update'])->name('storage-locations.update');
    });

    // Audit log
    Route::middleware('permission:audit.view')->group(function (): void {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
    });

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::put('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::put('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // Admin → Health (super_admin only via settings.system.manage)
    Route::prefix('admin/health')->middleware('permission:settings.system.manage')->group(function (): void {
        Route::get('/', [HealthController::class, 'show'])->name('admin.health.show');
        Route::post('clear-caches', [HealthController::class, 'clearCaches'])->name('admin.health.clear-caches');
        Route::post('maintenance', [HealthController::class, 'maintenance'])->name('admin.health.maintenance');
        Route::post('run-migrations', [HealthController::class, 'runMigrations'])->name('admin.health.run-migrations');
    });
});
