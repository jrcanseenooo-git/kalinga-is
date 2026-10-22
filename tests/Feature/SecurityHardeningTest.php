<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Deployment and transport hardening checks (vulnerability assessment). */
class SecurityHardeningTest extends TestCase
{
    public function test_every_page_and_error_page_sends_the_security_headers(): void
    {
        foreach (['/login', '/this-page-does-not-exist'] as $url) {
            $response = $this->get($url);
            foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Cross-Origin-Opener-Policy' => 'same-origin', 'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $header => $value) {
                $response->assertHeader($header, $value);
            }
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
            $this->assertFalse($response->headers->has('X-Powered-By'));
        }
    }

    public function test_hsts_is_sent_in_production_and_dev_server_is_not_allowed(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $response = SecurityHeaders::apply(new Response('ok'));
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
        $this->assertStringNotContainsString('5173', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('upgrade-insecure-requests', $response->headers->get('Content-Security-Policy'));
    }

    public function test_private_files_are_not_served_by_url_and_public_disk_is_refused(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->get('/storage/cases/1/quarantine/anything.pdf')->assertNotFound();
        config(['filesystems.default' => 'public']);
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame('local', config('filesystems.default'));
    }

    public function test_production_ignores_a_stray_hot_file(): void
    {
        $hot = public_path('hot');
        $existed = File::exists($hot);
        if (!$existed) {
            File::put($hot, 'http://localhost:5173');
        }
        try {
            $this->app->detectEnvironment(fn () => 'production');
            (new AppServiceProvider($this->app))->boot();
            $this->assertSame(storage_path('framework/vite.hot'), Vite::hotFile());
            $this->assertFalse(Vite::isRunningHot());
        } finally {
            if (!$existed) {
                File::delete($hot);
            }
        }
    }
}
