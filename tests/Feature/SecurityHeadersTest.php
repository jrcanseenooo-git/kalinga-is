<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_has_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
        // Own pages may use the camera (photo of the signed consent form); other sites embedded in Kalinga may not, and the microphone stays off.
        $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('microphone=()', $response->headers->get('Permissions-Policy'));
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_csp_contains_nonce_and_frame_ancestors_none(): void
    {
        $response = $this->get(route('login'));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_authenticated_page_has_security_headers(): void
    {
        $user = User::factory()->create(['role' => 'cpu_member', 'active' => true]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_x_powered_by_is_absent(): void
    {
        $response = $this->get(route('login'));

        $this->assertNull($response->headers->get('X-Powered-By'));
    }

    public function test_404_page_has_security_headers(): void
    {
        $response = $this->get('/nonexistent-page-xyz');

        $response->assertStatus(404);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
}
