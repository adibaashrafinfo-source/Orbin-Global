<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    public function test_seeded_profile_blocks_invoices_until_required_fields_exist(): void
    {
        $this->actingAsRole('cashier');
        $this->getJson('/api/v1/settings/company')->assertOk()
            ->assertJsonPath('data.legal_name', 'Orbin Global')
            ->assertJsonPath('data.invoice_ready', false)
            ->assertJsonPath('data.missing_invoice_fields', ['address_line1', 'phones']);
    }

    public function test_update_persists_and_is_audited(): void
    {
        $this->actingAsRole('super_admin');
        $payload = [
            'legal_name' => 'Orbin Global Ltd.', 'trade_name' => 'Orbin', 'tagline' => 'Trust. Growth. Excellence.',
            'address_line1' => '12 Mirpur Road', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postcode' => '1207', 'country' => 'Bangladesh',
            'phones' => ['01711000000', ' 01911000000 ', ''], 'email' => 'hello@orbin.example', 'bin_no' => '0012345', 'bank_name' => 'City Bank',
            'invoice_terms' => 'Goods once sold are not returned after 7 days.',
        ];
        $res = $this->putJson('/api/v1/settings/company', $payload);
        $res->assertOk()
            ->assertJsonPath('data.legal_name', 'Orbin Global Ltd.')
            ->assertJsonPath('data.phones', ['01711000000', '01911000000'])
            ->assertJsonPath('data.invoice_ready', true)
            ->assertJsonPath('data.missing_invoice_fields', []);
        $this->assertDatabaseHas('company_profile', ['singleton' => 1, 'legal_name' => 'Orbin Global Ltd.', 'city' => 'Dhaka']);
        $this->assertSame(1, CompanyProfile::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_table' => 'company_profile']);
    }

    public function test_update_validation(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson('/api/v1/settings/company', ['legal_name' => '', 'phones' => ['abc']])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION')->assertJsonStructure(['fields' => ['legal_name', 'phones.0']]);
    }

    public function test_logo_upload_stores_original_bytes_and_updates_url(): void
    {
        $this->actingAsRole('super_admin');
        $file = UploadedFile::fake()->image('logo.png', 600, 400);
        $original = md5_file($file->getRealPath());
        $res = $this->post('/api/v1/settings/company/logo', ['file' => $file], ['Accept' => 'application/json']);
        $res->assertOk();
        $url = $res->json('data.logo_url');
        $this->assertStringStartsWith('/uploads/brand/logo-', $url);
        $this->assertStringEndsWith('.png', $url);
        $stored = public_path(ltrim($url, '/'));
        $this->assertFileExists($stored);
        $this->assertSame($original, md5_file($stored), 'logo must be stored byte-for-byte');
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'entity_table' => 'company_profile']);
        @unlink($stored);
    }

    public function test_signature_upload_and_rejection_of_fake_images(): void
    {
        $this->actingAsRole('super_admin');
        $sig = UploadedFile::fake()->image('sig.jpg', 300, 100);
        $res = $this->post('/api/v1/settings/company/signature', ['file' => $sig], ['Accept' => 'application/json']);
        $res->assertOk();
        @unlink(public_path(ltrim($res->json('data.signature_url'), '/')));

        // PHP code disguised as a PNG is rejected by MIME sniffing.
        $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php echo "x";');
        $before = count(glob(public_path('uploads/brand/logo-*')) ?: []);
        $this->post('/api/v1/settings/company/logo', ['file' => $fake], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertCount($before, glob(public_path('uploads/brand/logo-*')) ?: [], 'nothing may be written for a rejected upload');
        $this->post('/api/v1/settings/company/logo', [], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
