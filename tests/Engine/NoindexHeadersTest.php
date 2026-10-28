<?php

namespace Tests\Engine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoindexHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_panel_is_never_indexed(): void
    {
        $this->get('/partenaire/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('Disallow: /partenaire', file_get_contents(public_path('robots.txt')));
    }

    public function test_noindex_environment_marks_every_public_page(): void
    {
        $this->get('/fr')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertDontSee('name="robots"', false);
        config(['hl.noindex' => true]);
        $this->get('/fr')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}
