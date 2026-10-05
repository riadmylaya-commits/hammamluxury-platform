<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_ip', fn () => response()->json(['ip' => request()->ip(), 'secure' => request()->secure()]));
    }

    public function test_forwarded_headers_are_ignored_from_untrusted_sources(): void
    {
        TrustProxies::at([]);

        $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_ip');

        $r->assertJson(['ip' => '203.0.113.9', 'secure' => false]);
    }

    public function test_forwarded_headers_are_honoured_from_a_trusted_cloudflare_range(): void
    {
        TrustProxies::at(['173.245.48.0/20']);

        $r = $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_ip');

        $r->assertJson(['ip' => '198.51.100.7', 'secure' => true]);

        TrustProxies::at(config('hl.trusted_proxies'));
    }
}
