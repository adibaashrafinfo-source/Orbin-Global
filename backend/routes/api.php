<?php

use App\Http\Controllers\Api\V1\Admin\HealthController;
use App\Http\Controllers\Api\V1\Admin\IntegrityController;
use App\Http\Controllers\Api\V1\AppSettingsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\FitmentController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\ImportExportController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LabelController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NumberingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\SupplierController;
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

// Purchase order PDF for the supplier: a signed link that expires, no login needed (FR-502).
Route::get('shared/purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'sharedPdf'])
    ->middleware(['signed', 'throttle:30,1'])->whereNumber('purchaseOrder')->name('shared.purchase-orders.pdf');

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
        Route::post('variants/{variant}/bundle-items', [VariantController::class, 'addBundleItem'])->name('bundle-items.store');
        Route::delete('bundle-items/{item}', [VariantController::class, 'removeBundleItem'])->name('bundle-items.destroy');

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
    Route::middleware('permission:products.import|masterdata.manage|inventory.adjust.approve')->group(function (): void {
        Route::get('imports/templates/{kind}', [ImportExportController::class, 'template'])->name('imports.template');
        Route::post('imports/dry-run', [ImportExportController::class, 'dryRun'])->name('imports.dry-run');
        Route::post('imports/commit', [ImportExportController::class, 'commit'])->name('imports.commit');
    });
    Route::get('products-export', [ImportExportController::class, 'exportProducts'])->middleware('permission:products.import|reports.export')->name('products.export');
    Route::post('variants/bulk-price', [VariantController::class, 'bulkPrice'])->middleware('permission:pricing.manage')->name('variants.bulk-price');

    // Purchasing (Phase 2)
    Route::middleware('permission:purchases.view')->group(function (): void {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->name('purchase-orders.show');
        Route::get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf'])->whereNumber('purchaseOrder')->name('purchase-orders.pdf');
        Route::get('variants/{variant}/purchase-history', [PurchaseOrderController::class, 'priceHistory'])->whereNumber('variant')->name('variants.purchase-history');
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
        Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->whereNumber('goodsReceipt')->name('goods-receipts.show');
        Route::get('purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
        Route::get('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])->whereNumber('purchaseReturn')->name('purchase-returns.show');
    });
    Route::middleware('permission:suppliers.manage')->group(function (): void {
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('suppliers.destroy');
    });
    Route::middleware('permission:purchases.manage')->group(function (): void {
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->name('purchase-orders.update');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->whereNumber('purchaseOrder')->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('purchaseOrder')->name('purchase-orders.cancel');
        Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->whereNumber('purchaseOrder')->name('purchase-orders.close');
        Route::post('purchase-orders/{purchaseOrder}/share-link', [PurchaseOrderController::class, 'shareLink'])->whereNumber('purchaseOrder')->name('purchase-orders.share-link');
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->whereNumber('purchaseOrder')->name('purchase-orders.destroy');
        Route::post('purchase-returns', [PurchaseReturnController::class, 'store'])->name('purchase-returns.store');
        Route::put('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'update'])->whereNumber('purchaseReturn')->name('purchase-returns.update');
        Route::post('purchase-returns/{purchaseReturn}/post', [PurchaseReturnController::class, 'post'])->whereNumber('purchaseReturn')->name('purchase-returns.post');
        Route::post('purchase-returns/{purchaseReturn}/cancel', [PurchaseReturnController::class, 'cancel'])->whereNumber('purchaseReturn')->name('purchase-returns.cancel');
    });
    Route::middleware('permission:purchases.grn|purchases.manage')->group(function (): void {
        Route::get('purchase-orders/{purchaseOrder}/receipt-draft', [GoodsReceiptController::class, 'fromOrder'])->whereNumber('purchaseOrder')->name('goods-receipts.from-order');
        Route::post('goods-receipts/preview', [GoodsReceiptController::class, 'preview'])->name('goods-receipts.preview');
        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
        Route::put('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->whereNumber('goodsReceipt')->name('goods-receipts.update');
        Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->whereNumber('goodsReceipt')->name('goods-receipts.post');
        Route::post('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->whereNumber('goodsReceipt')->name('goods-receipts.cancel');
        Route::delete('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'destroy'])->whereNumber('goodsReceipt')->name('goods-receipts.destroy');
    });

    // Inventory (Phase 2)
    Route::middleware('permission:inventory.view')->group(function (): void {
        Route::get('inventory/overview', [InventoryController::class, 'overview'])->name('inventory.overview');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
        Route::get('inventory/stock-card/{variant}', [InventoryController::class, 'card'])->whereNumber('variant')->name('inventory.card');
        Route::get('inventory/breakage', [InventoryController::class, 'breakage'])->name('inventory.breakage');
        Route::get('inventory/reorder', [InventoryController::class, 'reorder'])->name('inventory.reorder');
        Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::get('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->whereNumber('stockAdjustment')->name('stock-adjustments.show');
        Route::get('adjustment-photos/{attachment}', [StockAdjustmentController::class, 'attachment'])->whereNumber('attachment')->name('stock-adjustments.photo.show');
        Route::get('stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index');
        Route::get('stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->whereNumber('stockTransfer')->name('stock-transfers.show');
        Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
        Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])->whereNumber('stockCount')->name('stock-counts.show');
    });
    Route::middleware('permission:inventory.reports')->group(function (): void {
        Route::get('inventory/valuation', [InventoryController::class, 'valuation'])->name('inventory.valuation');
        Route::get('inventory/aging', [InventoryController::class, 'aging'])->name('inventory.aging');
    });
    Route::post('inventory/reorder/purchase-orders', [InventoryController::class, 'createPurchaseOrders'])->middleware('permission:purchases.manage')->name('inventory.reorder.purchase-orders');
    Route::middleware('permission:inventory.adjust')->group(function (): void {
        Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
        Route::put('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->whereNumber('stockAdjustment')->name('stock-adjustments.update');
        Route::post('stock-adjustments/{stockAdjustment}/photos', [StockAdjustmentController::class, 'photo'])->whereNumber('stockAdjustment')->name('stock-adjustments.photo');
        Route::post('stock-adjustments/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])->whereNumber('stockAdjustment')->name('stock-adjustments.submit');
        Route::post('stock-adjustments/{stockAdjustment}/post', [StockAdjustmentController::class, 'post'])->whereNumber('stockAdjustment')->name('stock-adjustments.post');
        Route::delete('stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'destroy'])->whereNumber('stockAdjustment')->name('stock-adjustments.destroy');
    });
    Route::post('stock-adjustments/{stockAdjustment}/reject', [StockAdjustmentController::class, 'reject'])->middleware('permission:inventory.adjust.approve')->whereNumber('stockAdjustment')->name('stock-adjustments.reject');
    Route::middleware('permission:inventory.transfer')->group(function (): void {
        Route::post('stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
        Route::put('stock-transfers/{stockTransfer}', [StockTransferController::class, 'update'])->whereNumber('stockTransfer')->name('stock-transfers.update');
        Route::post('stock-transfers/{stockTransfer}/send', [StockTransferController::class, 'send'])->whereNumber('stockTransfer')->name('stock-transfers.send');
        Route::post('stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->whereNumber('stockTransfer')->name('stock-transfers.receive');
        Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->whereNumber('stockTransfer')->name('stock-transfers.cancel');
        Route::delete('stock-transfers/{stockTransfer}', [StockTransferController::class, 'destroy'])->whereNumber('stockTransfer')->name('stock-transfers.destroy');
    });
    Route::middleware('permission:inventory.count')->group(function (): void {
        Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
        Route::post('stock-counts/{stockCount}/start', [StockCountController::class, 'start'])->whereNumber('stockCount')->name('stock-counts.start');
        Route::post('stock-counts/{stockCount}/items', [StockCountController::class, 'addItem'])->whereNumber('stockCount')->name('stock-counts.add-item');
        Route::put('stock-counts/{stockCount}/items', [StockCountController::class, 'enter'])->whereNumber('stockCount')->name('stock-counts.enter');
        Route::post('stock-counts/{stockCount}/review', [StockCountController::class, 'review'])->whereNumber('stockCount')->name('stock-counts.review');
        Route::post('stock-counts/{stockCount}/post', [StockCountController::class, 'post'])->whereNumber('stockCount')->name('stock-counts.post');
        Route::post('stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel'])->whereNumber('stockCount')->name('stock-counts.cancel');
        Route::delete('stock-counts/{stockCount}', [StockCountController::class, 'destroy'])->whereNumber('stockCount')->name('stock-counts.destroy');
    });

    // Admin → Health (super_admin only via settings.system.manage)
    Route::prefix('admin/health')->middleware('permission:settings.system.manage')->group(function (): void {
        Route::get('/', [HealthController::class, 'show'])->name('admin.health.show');
        Route::get('integrity', [IntegrityController::class, 'show'])->name('admin.integrity.show');
        Route::post('integrity/run', [IntegrityController::class, 'run'])->name('admin.integrity.run');
        Route::post('clear-caches', [HealthController::class, 'clearCaches'])->name('admin.health.clear-caches');
        Route::post('maintenance', [HealthController::class, 'maintenance'])->name('admin.health.maintenance');
        Route::post('run-migrations', [HealthController::class, 'runMigrations'])->name('admin.health.run-migrations');
    });
});
