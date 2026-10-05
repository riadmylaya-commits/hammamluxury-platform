<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
