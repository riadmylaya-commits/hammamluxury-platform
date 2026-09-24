<?php

namespace Tests\Engine;

use App\Domain\Phone\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_national_numbers_are_normalized_to_e164(): void
    {
        $this->assertSame('+212661351989', PhoneNumber::normalize('0661351989', 'MA'));
        $this->assertSame('+212661351989', PhoneNumber::normalize('06 61 35 19 89', 'MA'));
        $this->assertSame('+212661351989', PhoneNumber::normalize('661351989', 'MA'));
        $this->assertSame('+33612345678', PhoneNumber::normalize('06 12 34 56 78', 'FR'));
        $this->assertSame('+34612345678', PhoneNumber::normalize('612345678', 'ES'));
        $this->assertSame('+447911123456', PhoneNumber::normalize('07911 123456', 'GB'));
    }

    public function test_international_input_wins_over_selected_country(): void
    {
        $this->assertSame('+33612345678', PhoneNumber::normalize('+33 6 12 34 56 78', 'MA'));
        $this->assertSame('+212661351989', PhoneNumber::normalize('00212661351989', 'FR'));
        $this->assertSame('+212661351989', PhoneNumber::normalize('212661351989', 'MA'));
    }

    public function test_invalid_numbers_are_rejected(): void
    {
        $this->assertNull(PhoneNumber::normalize('', 'MA'));
        $this->assertNull(PhoneNumber::normalize('12345', 'MA'));
        $this->assertNull(PhoneNumber::normalize('066135198912', 'MA'));
        $this->assertNull(PhoneNumber::normalize('abc', 'FR'));
    }

    public function test_split_format_and_whatsapp(): void
    {
        $this->assertSame('MA', PhoneNumber::countryOf('+212661351989'));
        $this->assertSame('661351989', PhoneNumber::nationalPart('+212661351989'));
        $this->assertSame('+212 6 61 35 19 89', PhoneNumber::format('+212661351989'));
        $this->assertSame('https://wa.me/212661351989', PhoneNumber::whatsappUrl('+212661351989'));
        $this->assertArrayHasKey('MA', PhoneNumber::options('fr'));
        $this->assertSame(['MA', 'FR', 'ES', 'GB'], array_slice(array_keys(PhoneNumber::options('fr')), 0, 4));
    }
}
