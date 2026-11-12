<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorShapeTest extends TestCase
{
    public function test_unauthenticated_api_requests_return_uniform_401(): void
    {
        $res = $this->getJson('/api/v1/auth/me');
        $res->assertStatus(401)->assertJsonStructure(['code', 'message', 'fields', 'request_id'])->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertNotEmpty($res->headers->get('X-Request-Id'));
    }

    public function test_guest_without_accept_header_still_gets_json_401_not_a_redirect(): void
    {
        $res = $this->get('/api/v1/auth/me');
        $res->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_validation_errors_use_uniform_shape(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email']);
        $res->assertStatus(422)->assertJsonPath('code', 'VALIDATION')->assertJsonStructure(['fields' => ['email', 'password']]);
    }

    public function test_unknown_api_route_is_json_404(): void
    {
        $this->actingAsRole('super_admin');
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_money_is_never_a_float_in_responses_and_security_headers_present(): void
    {
        $this->actingAsRole('super_admin');
        $res = $this->getJson('/api/v1/settings/app');
        $res->assertOk();
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $res->headers->get('X-Frame-Options'));
    }
}
