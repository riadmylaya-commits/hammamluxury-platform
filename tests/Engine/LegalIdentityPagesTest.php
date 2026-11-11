<?php

namespace Tests\Engine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Pages légales : identité de l'éditeur lue depuis la configuration, repères visibles tant qu'elle manque, aucune affirmation d'autorisation photo. */
class LegalIdentityPagesTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTITY = [
        'company_name' => 'Jaune et Vert',
        'legal_form' => 'SARL à associé unique',
        'capital' => '100 000',
        'rc_city' => 'Marrakech',
        'rc_number' => '123456',
        'ice' => '001234567000089',
        'tax_id' => '98765432',
        'patente' => '',
        'address' => '1 rue Exemple, Guéliz, 40000 Marrakech',
        'publication_director' => 'Mme Exemple, gérante',
        'email' => 'contact@example.test',
        'phone' => '',
        'cndp' => '',
        'court_city' => '',
        'retention_years' => 5,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
    }

    public function test_pages_show_placeholders_and_banner_while_identity_is_missing(): void
    {
        config(['hl.legal' => array_fill_keys(array_keys(self::IDENTITY), null) + ['retention_years' => 5]]);

        $this->get('/fr/mentions-legales')->assertOk()
            ->assertSee('à compléter avec les informations juridiques')
            ->assertSee('[dénomination sociale à compléter]')
            ->assertSee('Hetzner Online GmbH')
            ->assertDontSee('publiées avec leur autorisation')
            ->assertSee('supports de communication publics');
        $this->get('/en/mentions-legales')->assertOk()->assertSee('[company name to be completed]')->assertDontSee('published with their permission');
        $this->get('/fr/cgu')->assertOk()->assertSee('[n° RC à compléter]')->assertDontSee('qui autorisent leur diffusion');
        $this->get('/fr/confidentialite')->assertOk()->assertSee('[adresse du siège à compléter]')->assertSee('5 ans à compter de la date de la prestation');
    }

    public function test_pages_show_configured_identity_without_placeholders(): void
    {
        config(['hl.legal' => self::IDENTITY]);

        foreach (['/fr/mentions-legales', '/en/mentions-legales', '/fr/cgu', '/en/cgu', '/fr/confidentialite', '/en/confidentialite'] as $url) {
            $this->get($url)->assertOk()->assertSee('Jaune et Vert')->assertDontSee('à compléter')->assertDontSee('to be completed');
        }

        $this->get('/fr/mentions-legales')->assertOk()
            ->assertSee('SARL à associé unique')->assertSee('au capital de 100 000 MAD')->assertSee('Registre du commerce de Marrakech sous le n° 123456')
            ->assertSee('ICE 001234567000089')->assertSee('identifiant fiscal 98765432')->assertDontSee('taxe professionnelle')
            ->assertSee('Directeur de la publication : Mme Exemple, gérante')->assertSee('contact@example.test')->assertDontSee('téléphone')
            ->assertSee('loi n° 09-08.', false)->assertSee('tribunaux compétents de Marrakech');
    }

    public function test_configured_values_are_escaped(): void
    {
        config(['hl.legal' => ['company_name' => '<script>alert(1)</script>'] + self::IDENTITY]);

        $this->get('/fr/mentions-legales')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
