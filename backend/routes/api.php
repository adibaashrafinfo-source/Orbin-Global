<?php

use App\Http\Controllers\Api\V1\Admin\HealthController;
use App\Http\Controllers\Api\V1\AppSettingsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\FitmentController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\ImportExportController;
use App\Http\Controllers\Api\V1\LabelController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NumberingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VariantController;
use App\Http\Controllers\Api\V1\VariantSearchController;
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

    // ---- Phase 1: catalog -------------------------------------------------------------------------------------
    $master = 'vehicle-makes|vehicle-models|product-brands|categories|units|tax-rates';
    Route::get('{resource}', [MasterDataController::class, 'index'])->where('resource', $master)->name('master.index');
    Route::get('lookups', [LookupController::class, 'index'])->name('lookups.index');
    Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::middleware('permission:masterdata.manage')->group(function () use ($master): void {
        Route::put('{resource}/reorder', [MasterDataController::class, 'reorder'])->where('resource', $master)->name('master.reorder');
        Route::post('{resource}', [MasterDataController::class, 'store'])->where('resource', $master)->name('master.store');
        Route::put('{resource}/{id}', [MasterDataController::class, 'update'])->where('resource', $master)->whereNumber('id')->name('master.update');
        Route::delete('{resource}/{id}', [MasterDataController::class, 'destroy'])->where('resource', $master)->whereNumber('id')->name('master.destroy');

        Route::post('lookups', [LookupController::class, 'storeType'])->name('lookups.store');
        Route::put('lookups/{type}', [LookupController::class, 'updateType'])->name('lookups.update');
        Route::delete('lookups/{type}', [LookupController::class, 'destroyType'])->name('lookups.destroy');
        Route::post('lookups/{type}/values', [LookupController::class, 'storeValue'])->name('lookup-values.store');
        Route::put('lookups/{type}/values/reorder', [LookupController::class, 'reorderValues'])->name('lookup-values.reorder');
        Route::put('lookup-values/{value}', [LookupController::class, 'updateValue'])->name('lookup-values.update');
        Route::delete('lookup-values/{value}', [LookupController::class, 'destroyValue'])->name('lookup-values.destroy');

        Route::post('custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::put('custom-fields/reorder', [CustomFieldController::class, 'reorder'])->name('custom-fields.reorder');
        Route::put('custom-fields/{field}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('custom-fields/{field}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    });

    Route::middleware('permission:products.view')->group(function (): void {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
        Route::get('variants/search', VariantSearchController::class)->name('variants.search');
        Route::get('variants/{variant}', [VariantController::class, 'show'])->whereNumber('variant')->name('variants.show');
    });
    Route::middleware('permission:products.manage')->group(function (): void {
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('products/{product}/publish', [ProductController::class, 'publish'])->name('products.publish');
        Route::post('products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');
        Route::post('products/{product}/clone', [ProductController::class, 'clone'])->name('products.clone');

        Route::post('products/{product}/variants', [VariantController::class, 'store'])->name('variants.store');
        Route::post('products/{product}/variants/matrix', [VariantController::class, 'matrix'])->name('variants.matrix');
        Route::put('variants/{variant}', [VariantController::class, 'update'])->name('variants.update');
        Route::delete('variants/{variant}', [VariantController::class, 'destroy'])->name('variants.destroy');
        Route::post('variants/{variant}/clone', [VariantController::class, 'clone'])->name('variants.clone');
        Route::post('variants/{variant}/barcode', [VariantController::class, 'generateBarcode'])->name('variants.barcode');

        Route::post('products/{product}/images', [ImageController::class, 'store'])->name('images.store');
        Route::put('products/{product}/images/reorder', [ImageController::class, 'reorder'])->name('images.reorder');
        Route::put('images/{image}', [ImageController::class, 'update'])->name('images.update');
        Route::post('images/{image}/primary', [ImageController::class, 'setPrimary'])->name('images.primary');
        Route::delete('images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');

        Route::post('variants/{variant}/fitments', [FitmentController::class, 'storeFitment'])->name('fitments.store');
        Route::post('variants/{variant}/fitments/copy', [FitmentController::class, 'copyFitments'])->name('fitments.copy');
        Route::delete('fitments/{fitment}', [FitmentController::class, 'destroyFitment'])->name('fitments.destroy');
        Route::post('variants/{variant}/cross-references', [FitmentController::class, 'storeCrossReference'])->name('cross-references.store');
        Route::delete('cross-references/{reference}', [FitmentController::class, 'destroyCrossReference'])->name('cross-references.destroy');
    });
    Route::post('labels', LabelController::class)->middleware('permission:products.view')->name('labels.render');
    Route::middleware('permission:products.import|masterdata.manage')->group(function (): void {
        Route::get('imports/templates/{kind}', [ImportExportController::class, 'template'])->name('imports.template');
        Route::post('imports/dry-run', [ImportExportController::class, 'dryRun'])->name('imports.dry-run');
        Route::post('imports/commit', [ImportExportController::class, 'commit'])->name('imports.commit');
    });
    Route::get('products-export', [ImportExportController::class, 'exportProducts'])->middleware('permission:products.import|reports.export')->name('products.export');
    Route::post('variants/bulk-price', [VariantController::class, 'bulkPrice'])->middleware('permission:pricing.manage')->name('variants.bulk-price');

    // Admin → Health (super_admin only via settings.system.manage)
    Route::prefix('admin/health')->middleware('permission:settings.system.manage')->group(function (): void {
        Route::get('/', [HealthController::class, 'show'])->name('admin.health.show');
        Route::post('clear-caches', [HealthController::class, 'clearCaches'])->name('admin.health.clear-caches');
        Route::post('maintenance', [HealthController::class, 'maintenance'])->name('admin.health.maintenance');
        Route::post('run-migrations', [HealthController::class, 'runMigrations'])->name('admin.health.run-migrations');
    });
});
