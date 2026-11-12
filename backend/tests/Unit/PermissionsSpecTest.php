<?php

namespace Tests\Unit;

use App\Support\Permissions;
use PHPUnit\Framework\TestCase;

/** App\Support\Permissions must mirror roles.permissionKeys / roles.defaultMatrix in orbin-pos-spec.json. */
class PermissionsSpecTest extends TestCase
{
    private function spec(): array
    {
        $path = dirname(__DIR__, 3).'/orbin-pos-spec.json';
        $this->assertFileExists($path, 'orbin-pos-spec.json must sit in the repository root');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_permission_keys_match_spec(): void
    {
        $spec = $this->spec();
        $this->assertSame($spec['roles']['permissionKeys'], Permissions::KEYS);
        $this->assertSame($spec['roles']['enum'], Permissions::ROLES);
    }

    public function test_default_matrix_matches_spec(): void
    {
        $spec = $this->spec();
        foreach ($spec['roles']['defaultMatrix'] as $role => $keys) {
            $this->assertEqualsCanonicalizing($keys, Permissions::DEFAULT_MATRIX[$role], "matrix for {$role}");
        }
    }

    public function test_defaults_for_expands_wildcard(): void
    {
        $admin = Permissions::defaultsFor('super_admin');
        $this->assertCount(count(Permissions::KEYS), $admin);
        $this->assertNotContains(false, $admin);
        $cashier = Permissions::defaultsFor('cashier');
        $this->assertTrue($cashier['pos.sell']);
        $this->assertFalse($cashier['products.view_cost']);
    }
}
