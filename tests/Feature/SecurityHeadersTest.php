<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_present_in_report_mode(): void
    {
        config(['hl.csp_mode' => 'report']);

        $r = $this->get('https://localhost/fr/recherche');

        $r->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeaderMissing('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $r->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('report-uri /csp-report', $r->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('geolocation=()', $r->headers->get('Permissions-Policy'));
    }

    public function test_csp_enforce_mode_and_no_hsts_over_http(): void
    {
        config(['hl.csp_mode' => 'enforce']);

        $r = $this->get('http://localhost/fr/recherche');

        $r->assertOk()->assertHeaderMissing('Strict-Transport-Security')->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("object-src 'none'", $r->headers->get('Content-Security-Policy'));
    }

    public function test_csp_report_endpoint_accepts_reports_without_csrf(): void
    {
        $r = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => ['document-uri' => 'https://x/', 'blocked-uri' => 'https://evil', 'violated-directive' => 'img-src']]));

        $r->assertNoContent();
    }

    public function test_csp_reports_in_excess_are_dropped_without_error_so_visitors_are_never_banned(): void
    {
        Log::shouldReceive('channel')->with('csp')->times(30)->andReturnSelf();
        Log::shouldReceive('warning')->times(30);
        $body = json_encode(['csp-report' => ['document-uri' => 'https://x/', 'blocked-uri' => 'https://evil', 'violated-directive' => 'font-src']]);
        for ($i = 0; $i < 100; $i++) {
            $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();
        }
    }

    public function test_panel_font_is_served_locally_and_policy_allows_no_external_font(): void
    {
        $policy = SecurityHeaders::policy();
        $this->assertStringContainsString("font-src 'self' data:;", $policy.';');
        $this->assertStringNotContainsString('fonts.bunny.net', $policy);
        $this->assertStringNotContainsString('jsdelivr', $policy);
        $this->assertFileExists(public_path('fonts/inter/inter-latin-wght-normal.woff2'));
        $this->get('/partenaire/login')->assertOk()->assertSee('fonts/inter/inter.css', false)->assertDontSee('fonts.bunny.net');
    }
}
