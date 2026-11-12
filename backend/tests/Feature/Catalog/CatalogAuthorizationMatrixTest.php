<?php

namespace Tests\Feature\Catalog;

use App\Models\CustomFieldDef;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\OemCrossReference;
use App\Models\ProductImage;
use App\Models\VehicleFitment;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Role × endpoint matrix for every Phase 1 endpoint (NFR-010, AT-019): guests get 401, allowed roles get anything but 401/403,
 * every other role gets 403 before any validation or data access happens.
 */
class CatalogAuthorizationMatrixTest extends CatalogTestCase
{
    protected function tearDown(): void
    {
        foreach (glob(public_path('uploads/products/*/*/*')) ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @return array<string, array{string, string, array<int, string>, array<string, mixed>}> */
    public static function endpoints(): array
    {
        $all = self::ROLES;
        $edit = ['super_admin', 'manager'];
        $m = ['name' => 'Matrix Make'];

        return [
            // reads: every signed-in role
            'GET vehicle-makes' => ['GET', 'vehicle-makes', $all, []],
            'GET vehicle-models' => ['GET', 'vehicle-models', $all, []],
            'GET product-brands' => ['GET', 'product-brands', $all, []],
            'GET categories' => ['GET', 'categories', $all, []],
            'GET units' => ['GET', 'units', $all, []],
            'GET tax-rates' => ['GET', 'tax-rates', $all, []],
            'GET lookups' => ['GET', 'lookups', $all, []],
            'GET custom-fields' => ['GET', 'custom-fields', $all, []],
            'GET products' => ['GET', 'products', $all, []],
            'GET products/{product}' => ['GET', 'products/{product}', $all, []],
            'GET variants/{variant}' => ['GET', 'variants/{variant}', $all, []],
            'GET variants/search' => ['GET', 'variants/search?q=axio', $all, []],
            'POST labels' => ['POST', 'labels', $all, ['template' => 'roll-50x25', 'items' => [['variant_id' => '{variant}', 'qty' => 1]]]],
            // master data writes: masterdata.manage
            'POST vehicle-makes' => ['POST', 'vehicle-makes', $edit, $m],
            'PUT vehicle-makes/{make}' => ['PUT', 'vehicle-makes/{make}', $edit, ['is_active' => true]],
            'DELETE vehicle-makes/{unused_make}' => ['DELETE', 'vehicle-makes/{unused_make}', $edit, []],
            'PUT vehicle-makes/reorder' => ['PUT', 'vehicle-makes/reorder', $edit, ['ids' => [1]]],
            'POST vehicle-models' => ['POST', 'vehicle-models', $edit, ['make_id' => '{make}', 'name' => 'Matrix Model']],
            'POST product-brands' => ['POST', 'product-brands', $edit, ['name' => 'Matrix Brand']],
            'POST categories' => ['POST', 'categories', $edit, ['name_en' => 'Matrix Cat']],
            'POST units' => ['POST', 'units', $edit, ['code' => 'mx', 'name_en' => 'Matrix']],
            'POST tax-rates' => ['POST', 'tax-rates', $edit, ['code' => 'MX1', 'name' => 'Matrix', 'rate_percent' => 1]],
            'POST lookups' => ['POST', 'lookups', $edit, ['key' => 'matrix_list', 'name_en' => 'Matrix']],
            'PUT lookups/{lookup_type}' => ['PUT', 'lookups/{lookup_type}', $edit, ['name_en' => 'Renamed']],
            'POST lookups/{lookup_type}/values' => ['POST', 'lookups/{lookup_type}/values', $edit, ['code' => 'mx', 'label_en' => 'Matrix']],
            'PUT lookup-values/{lookup_value}' => ['PUT', 'lookup-values/{lookup_value}', $edit, ['label_en' => 'Renamed']],
            'POST custom-fields' => ['POST', 'custom-fields', $edit, ['entity' => 'product', 'key' => 'mx_field', 'label_en' => 'Matrix', 'field_type' => 'text']],
            'PUT custom-fields/{custom_field}' => ['PUT', 'custom-fields/{custom_field}', $edit, ['label_en' => 'Renamed']],
            // product writes: products.manage
            'POST products' => ['POST', 'products', $edit, ['code' => 'MX-P1', 'name_en' => 'Matrix', 'mirror_type' => 'side_mirror', 'category_id' => 1, 'quality_grade_id' => 1, 'unit_id' => 1]],
            'PUT products/{product}' => ['PUT', 'products/{product}', $edit, ['name_en' => 'Renamed']],
            'DELETE products/{product}' => ['DELETE', 'products/{product}', $edit, []],
            'POST products/{product}/publish' => ['POST', 'products/{product}/publish', $edit, []],
            'POST products/{product}/deactivate' => ['POST', 'products/{product}/deactivate', $edit, []],
            'POST products/{product}/clone' => ['POST', 'products/{product}/clone', $edit, []],
            'POST products/{product}/variants' => ['POST', 'products/{product}/variants', $edit, ['position' => 'interior', 'retail_price' => '10']],
            'POST products/{product}/variants/matrix' => ['POST', 'products/{product}/variants/matrix', $edit, ['positions' => ['pair'], 'defaults' => ['retail_price' => '10'], 'dry_run' => true]],
            'PUT variants/{variant}' => ['PUT', 'variants/{variant}', $edit, ['internal_note' => 'x']],
            'DELETE variants/{variant}' => ['DELETE', 'variants/{variant}', $edit, []],
            'POST variants/{variant}/clone' => ['POST', 'variants/{variant}/clone', $edit, ['position' => 'universal']],
            'POST variants/{variant}/barcode' => ['POST', 'variants/{variant}/barcode', $edit, []],
            'POST products/{product}/images' => ['POST', 'products/{product}/images', $edit, ['__upload' => true]],
            'PUT products/{product}/images/reorder' => ['PUT', 'products/{product}/images/reorder', $edit, ['ids' => [1]]],
            'PUT images/{image}' => ['PUT', 'images/{image}', $edit, ['label' => 'front']],
            'POST images/{image}/primary' => ['POST', 'images/{image}/primary', $edit, []],
            'DELETE images/{image}' => ['DELETE', 'images/{image}', $edit, []],
            'POST variants/{variant}/fitments' => ['POST', 'variants/{variant}/fitments', $edit, ['vehicle_model_id' => '{model}', 'year_from' => 2001]],
            'POST variants/{variant}/fitments/copy' => ['POST', 'variants/{variant}/fitments/copy', $edit, ['target_variant_ids' => ['{variant}']]],
            'DELETE fitments/{fitment}' => ['DELETE', 'fitments/{fitment}', $edit, []],
            'POST variants/{variant}/cross-references' => ['POST', 'variants/{variant}/cross-references', $edit, ['number' => 'MX-1']],
            'DELETE cross-references/{xref}' => ['DELETE', 'cross-references/{xref}', $edit, []],
            // pricing, import and export
            'POST variants/bulk-price' => ['POST', 'variants/bulk-price', $edit, ['mode' => 'fixed', 'amount' => '1', 'columns' => ['retail_price'], 'filter' => ['product_ids' => ['{product}']], 'dry_run' => true]],
            'GET imports/templates/products' => ['GET', 'imports/templates/products', $edit, []],
            'GET imports/templates/vehicle-makes' => ['GET', 'imports/templates/vehicle-makes', $edit, []],
            'POST imports/dry-run' => ['POST', 'imports/dry-run', $edit, ['kind' => 'vehicle-makes', '__csv' => true]],
            'POST imports/commit' => ['POST', 'imports/commit', $edit, ['kind' => 'vehicle-makes', 'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
            'GET products-export' => ['GET', 'products-export', ['super_admin', 'manager', 'accountant'], []],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_endpoint_is_gated_by_permission(string $method, string $uri, array $allowedRoles, array $payload): void
    {
        $this->actingAsRole('manager');
        $pid = $this->makeProduct();
        $variant = $this->makeVariant($pid);
        $image = ProductImage::query()->create(['product_id' => $pid, 'storage_path' => 'uploads/products/x.jpg', 'url' => '/uploads/products/x.jpg', 'label' => 'other', 'sort_order' => 10]);
        $model = VehicleModel::query()->where('name', 'Axio')->value('id');
        $fitment = VehicleFitment::query()->create(['variant_id' => $variant['id'], 'vehicle_model_id' => $model, 'year_from' => 2010, 'signature' => 'fixture-sig']);
        $xref = OemCrossReference::query()->create(['variant_id' => $variant['id'], 'number' => 'FX-1', 'number_normalized' => 'FX1']);
        $lookupType = LookupType::query()->where('key', 'glass_type')->value('id');
        $lookupValue = LookupValue::query()->where('type_id', $lookupType)->value('id');
        $field = CustomFieldDef::query()->create(['entity' => 'variant', 'key' => 'fx', 'label_en' => 'Fx', 'field_type' => 'text']);
        $make = VehicleMake::query()->where('name', 'Toyota')->value('id');
        $unusedMake = VehicleMake::query()->create(['name' => 'Unused Make'])->id;
        $this->app['auth']->forgetGuards();

        $swap = fn (string $s): string => strtr($s, [
            '{product}' => (string) $pid, '{variant}' => (string) $variant['id'], '{image}' => (string) $image->id, '{fitment}' => (string) $fitment->id,
            '{xref}' => (string) $xref->id, '{lookup_type}' => (string) $lookupType, '{lookup_value}' => (string) $lookupValue,
            '{custom_field}' => (string) $field->id, '{make}' => (string) $make, '{unused_make}' => (string) $unusedMake, '{model}' => (string) $model,
        ]);
        $resolve = function ($v) use (&$resolve, $swap) {
            return is_array($v) ? array_map($resolve, $v) : (is_string($v) ? $swap($v) : $v);
        };
        $payload = $resolve($payload);
        $url = '/api/v1/'.$swap($uri);
        $send = function () use ($method, $url, $payload) {
            if (isset($payload['__upload'])) {
                return $this->post($url, ['file' => UploadedFile::fake()->image('a.jpg', 50, 50)], ['Accept' => 'application/json']);
            }
            if (isset($payload['__csv'])) {
                return $this->post($url, ['kind' => $payload['kind'], 'file' => UploadedFile::fake()->createWithContent('m.csv', "name,sort_order\nMatrix Make,1\n")], ['Accept' => 'application/json']);
            }

            return $this->json($method, $url, $payload);
        };

        $this->assertSame(401, $send()->getStatusCode(), 'guest must get 401');
        // forbidden roles first: allowed roles may delete the record, which would turn later 403s into 404s
        $ordered = array_merge(array_diff(self::ROLES, $allowedRoles), array_intersect(self::ROLES, $allowedRoles));
        foreach ($ordered as $role) {
            $this->actingAsRole($role);
            $status = $send()->getStatusCode();
            if (in_array($role, $allowedRoles, true)) {
                $this->assertNotContains($status, [401, 403], "{$role} should reach {$method} {$uri} but got {$status}");
            } else {
                $this->assertSame(403, $status, "{$role} must be forbidden on {$method} {$uri} but got {$status}");
            }
        }
    }
}
