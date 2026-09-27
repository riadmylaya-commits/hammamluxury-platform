<?php

namespace Tests\Engine;

use App\Domain\Privacy\ContactMasker;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    public function test_contact_details_are_masked_but_useful_text_kept(): void
    {
        $in = 'Appelez-moi au 06 12 34 56 78 ou +212 661-234567, écrivez à contact@spa-marrakech.ma, site www.spa-marrakech.com, insta @spa_marrakech, WhatsApp wa.me/212661234567. Rdv à 10h pour 2 personnes, 450 MAD.';
        $out = ContactMasker::redact($in, '[masqué]');
        $this->assertTrue(! str_contains($out, '06 12 34 56 78') && ! str_contains($out, '661'), 'Téléphones masqués : '.$out);
        $this->assertTrue(! str_contains($out, 'contact@') && ! str_contains($out, 'spa-marrakech.ma'), 'E-mail masqué');
        $this->assertStringNotContainsString('www.spa-marrakech.com', $out, 'URL masquée');
        $this->assertTrue(! str_contains($out, '@spa_marrakech') && ! str_contains($out, 'wa.me'), 'Réseaux sociaux / WhatsApp masqués');
        $this->assertTrue(str_contains($out, '10h pour 2 personnes') && str_contains($out, '450 MAD'), 'Texte utile conservé : '.$out);
        $this->assertTrue(ContactMasker::containsContact('mon insta: @riad_test') && ! ContactMasker::containsContact('Hammam 45 min puis massage 60 min, 2 personnes'), 'Détection de coordonnées');
    }
}
