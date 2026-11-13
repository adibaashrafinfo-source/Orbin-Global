<?php

use App\Http\Controllers\Api\V1\Admin\HealthController;
use App\Http\Controllers\Api\V1\Admin\IntegrityController;
use App\Http\Controllers\Api\V1\AppSettingsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\DayBookController;
use App\Http\Controllers\Api\V1\DuesController;
use App\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinancialAccountController;
use App\Http\Controllers\Api\V1\FitmentController;
use App\Http\Controllers\Api\V1\FundTransferController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\ImportExportController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LabelController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NumberingController;
use App\Http\Controllers\Api\V1\PartyLedgerController;
use App\Http\Controllers\Api\V1\PartyPaymentController;
use App\Http\Controllers\Api\V1\PeriodLockController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\StorageLocationController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VariantController;
use App\Http\Controllers\Api\V1\VariantSearchController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarrantyClaimController;
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

    // Customers, POS and sales (Phase 3)
    Route::middleware('permission:customers.view')->group(function (): void {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
        Route::get('customers/{customer}/overview', [CustomerController::class, 'overview'])->whereNumber('customer')->name('customers.overview');
    });
    Route::middleware('permission:customers.manage')->group(function (): void {
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->name('customers.destroy');
        Route::post('customers/{customer}/vehicles', [CustomerController::class, 'storeVehicle'])->whereNumber('customer')->name('customer-vehicles.store');
        Route::put('customer-vehicles/{vehicle}', [CustomerController::class, 'updateVehicle'])->whereNumber('vehicle')->name('customer-vehicles.update');
        Route::delete('customer-vehicles/{vehicle}', [CustomerController::class, 'destroyVehicle'])->whereNumber('vehicle')->name('customer-vehicles.destroy');
    });
    Route::middleware('permission:pos.sell')->group(function (): void {
        Route::get('pos/config', [PosController::class, 'config'])->name('pos.config');
        Route::get('pos/variants', [PosController::class, 'variants'])->name('pos.variants');
        Route::get('pos/scan', [PosController::class, 'scan'])->name('pos.scan');
        Route::post('sales/checkout', [SaleController::class, 'checkout'])->middleware('throttle:60,1')->name('sales.checkout');
        Route::post('sales/hold', [SaleController::class, 'hold'])->name('sales.hold');
        Route::get('sales/held', [SaleController::class, 'held'])->name('sales.held');
        Route::post('sales/{sale}/resume', [SaleController::class, 'resume'])->whereNumber('sale')->name('sales.resume');
        Route::delete('sales/{sale}/held', [SaleController::class, 'discardHeld'])->whereNumber('sale')->name('sales.held.discard');
    });
    Route::middleware('permission:sales.view_all|sales.view_own')->group(function (): void {
        Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
        Route::get('sales/{sale}/pdf', [SaleController::class, 'pdf'])->whereNumber('sale')->name('sales.pdf');
        Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->whereNumber('sale')->name('sales.receipt');
        Route::get('sale-returns', [SaleReturnController::class, 'index'])->name('sale-returns.index');
        Route::get('sale-returns/{saleReturn}', [SaleReturnController::class, 'show'])->whereNumber('saleReturn')->name('sale-returns.show');
        Route::get('sale-returns/{saleReturn}/pdf', [SaleReturnController::class, 'pdf'])->whereNumber('saleReturn')->name('sale-returns.pdf');
    });
    Route::post('sales/{sale}/void', [SaleController::class, 'void'])->middleware('permission:sales.void')->whereNumber('sale')->name('sales.void');
    Route::post('sale-payments/{payment}/confirm', [SaleController::class, 'confirmPayment'])->middleware('permission:payments.verify')->whereNumber('payment')->name('sale-payments.confirm');
    Route::post('sale-returns', [SaleReturnController::class, 'store'])->middleware('permission:sales.return.create')->name('sale-returns.store');
    Route::post('sale-returns/{saleReturn}/cancel', [SaleReturnController::class, 'cancel'])->middleware('permission:sales.return.create|sales.return.approve')->whereNumber('saleReturn')->name('sale-returns.cancel');
    Route::post('sale-returns/{saleReturn}/approve', [SaleReturnController::class, 'approve'])->middleware('permission:sales.return.approve')->whereNumber('saleReturn')->name('sale-returns.approve');
    Route::middleware('permission:quotations.manage')->group(function (): void {
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::post('quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('quotations.show');
        Route::post('quotations/{quotation}/status', [QuotationController::class, 'status'])->whereNumber('quotation')->name('quotations.status');
        Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])->whereNumber('quotation')->name('quotations.convert');
        Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->whereNumber('quotation')->name('quotations.pdf');
    });
    Route::middleware('permission:shifts.open_close_own|shifts.close_any|shifts.view_all')->group(function (): void {
        Route::get('shifts/current', [ShiftController::class, 'current'])->name('shifts.current');
        Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::get('shifts/{shift}', [ShiftController::class, 'show'])->whereNumber('shift')->name('shifts.show');
        Route::get('shifts/{shift}/z-report', [ShiftController::class, 'zReport'])->whereNumber('shift')->name('shifts.z-report');
    });
    Route::middleware('permission:shifts.open_close_own|shifts.close_any')->group(function (): void {
        Route::post('shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
        Route::post('shifts/{shift}/cash', [ShiftController::class, 'cash'])->whereNumber('shift')->name('shifts.cash');
        Route::post('shifts/{shift}/close', [ShiftController::class, 'close'])->whereNumber('shift')->name('shifts.close');
    });
    // Ledger and finance (FR-1000)
    Route::get('financial-accounts', [FinancialAccountController::class, 'index'])->middleware('permission:ledger.view|accounts.manage|pos.sell|ledger.receive_payment|ledger.pay_supplier')->name('financial-accounts.index');
    Route::middleware('permission:ledger.view|accounts.manage')->group(function (): void {
        Route::get('financial-accounts/{account}', [FinancialAccountController::class, 'show'])->whereNumber('account')->name('financial-accounts.show');
        Route::get('financial-accounts/{account}/transactions', [FinancialAccountController::class, 'transactions'])->whereNumber('account')->name('financial-accounts.transactions');
    });
    Route::middleware('permission:accounts.manage')->group(function (): void {
        Route::post('financial-accounts', [FinancialAccountController::class, 'store'])->name('financial-accounts.store');
        Route::put('financial-accounts/{account}', [FinancialAccountController::class, 'update'])->whereNumber('account')->name('financial-accounts.update');
        Route::delete('financial-accounts/{account}', [FinancialAccountController::class, 'destroy'])->whereNumber('account')->name('financial-accounts.destroy');
        Route::post('financial-accounts/{account}/adjust', [FinancialAccountController::class, 'adjust'])->whereNumber('account')->name('financial-accounts.adjust');
    });
    Route::get('party-payments', [PartyPaymentController::class, 'index'])->middleware('permission:ledger.view')->name('party-payments.index');
    Route::middleware('permission:ledger.view|ledger.receive_payment|ledger.pay_supplier')->group(function (): void {
        Route::get('party-payments/open-documents', [PartyPaymentController::class, 'openDocuments'])->name('party-payments.open-documents');
        Route::post('party-payments', [PartyPaymentController::class, 'store'])->middleware('throttle:60,1')->name('party-payments.store');
        Route::get('party-payments/{payment}', [PartyPaymentController::class, 'show'])->whereNumber('payment')->name('party-payments.show');
        Route::get('party-payments/{payment}/receipt', [PartyPaymentController::class, 'receipt'])->whereNumber('payment')->name('party-payments.receipt');
    });
    Route::middleware('permission:payments.verify')->group(function (): void {
        Route::post('party-payments/{payment}/void', [PartyPaymentController::class, 'void'])->whereNumber('payment')->name('party-payments.void');
        Route::post('party-payments/{payment}/confirm', [PartyPaymentController::class, 'confirm'])->whereNumber('payment')->name('party-payments.confirm');
    });
    Route::middleware('permission:ledger.view')->group(function (): void {
        Route::get('party-ledger', [PartyLedgerController::class, 'show'])->name('party-ledger.show');
        Route::get('party-ledger/statement.pdf', [PartyLedgerController::class, 'statementPdf'])->name('party-ledger.statement');
        Route::get('day-book', [DayBookController::class, 'index'])->name('day-book.index');
        Route::get('dues', [DuesController::class, 'index'])->name('dues.index');
    });
    Route::middleware('permission:ledger.writeoff')->group(function (): void {
        Route::post('party-ledger/write-offs', [PartyLedgerController::class, 'writeOff'])->name('party-ledger.write-offs');
        Route::post('party-ledger/adjustments', [PartyLedgerController::class, 'adjust'])->name('party-ledger.adjustments');
    });
    Route::put('dues/promise', [DuesController::class, 'promise'])->middleware('permission:ledger.receive_payment|ledger.pay_supplier')->name('dues.promise');
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
    Route::middleware('permission:expenses.manage|masterdata.manage')->group(function (): void {
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->whereNumber('category')->name('expense-categories.update');
        Route::delete('expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->whereNumber('category')->name('expense-categories.destroy');
    });
    Route::middleware('permission:expenses.manage')->group(function (): void {
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->name('expenses.show');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->whereNumber('expense')->name('expenses.approve');
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->whereNumber('expense')->name('expenses.reject');
        Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->whereNumber('expense')->name('expenses.void');
    });
    Route::middleware('permission:fund_transfers.manage')->group(function (): void {
        Route::get('fund-transfers', [FundTransferController::class, 'index'])->name('fund-transfers.index');
        Route::post('fund-transfers', [FundTransferController::class, 'store'])->name('fund-transfers.store');
        Route::get('fund-transfers/{transfer}', [FundTransferController::class, 'show'])->whereNumber('transfer')->name('fund-transfers.show');
        Route::post('fund-transfers/{transfer}/void', [FundTransferController::class, 'void'])->whereNumber('transfer')->name('fund-transfers.void');
    });
    Route::get('settings/period-lock', [PeriodLockController::class, 'show'])->name('settings.period-lock.show');
    Route::post('settings/period-lock', [PeriodLockController::class, 'update'])->middleware('permission:periods.lock')->name('settings.period-lock.update');
    Route::get('warranty-claims', [WarrantyClaimController::class, 'index'])->middleware('permission:sales.view_all')->name('warranty-claims.index');
    Route::post('warranty-claims', [WarrantyClaimController::class, 'store'])->middleware('permission:sales.return.create')->name('warranty-claims.store');
    Route::put('warranty-claims/{warrantyClaim}', [WarrantyClaimController::class, 'update'])->middleware('permission:sales.return.approve')->whereNumber('warrantyClaim')->name('warranty-claims.update');

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
