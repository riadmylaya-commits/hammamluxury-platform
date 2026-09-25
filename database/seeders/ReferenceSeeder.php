<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Category;
use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Référentiels gérables en admin (villes, expériences, équipements).
 * Idempotent : ne modifie que les lignes absentes, les libellés édités en admin sont conservés.
 */
class ReferenceSeeder extends Seeder
{
    /** @var list<array{string,string,string,bool}> slug, fr, en, populaire */
    public const CITIES = [
        ['marrakech', 'Marrakech', 'Marrakech', true],
        ['agadir', 'Agadir', 'Agadir', true],
        ['casablanca', 'Casablanca', 'Casablanca', true],
        ['essaouira', 'Essaouira', 'Essaouira', true],
        ['rabat', 'Rabat', 'Rabat', true],
        ['tanger', 'Tanger', 'Tangier', true],
        ['fes', 'Fès', 'Fez', false],
        ['ouarzazate', 'Ouarzazate', 'Ouarzazate', false],
    ];

    /** @var list<array{string,string,string,bool}> slug, fr, en, populaire — types d'expérience (futurs filtres et URL /ville/experience/) */
    public const CATEGORIES = [
        ['hammam', 'Hammam traditionnel', 'Traditional hammam', true],
        ['hammam-massage', 'Hammam & massage', 'Hammam & massage', true],
        ['massage', 'Massage', 'Massage', true],
        ['spa', 'Spa', 'Spa', true],
        ['couple', 'Expérience couple', 'Couples experience', true],
        ['spa-luxe', 'Spa de luxe', 'Luxury spa', true],
        ['spa-hotel', 'Spa d’hôtel', 'Hotel spa', false],
        ['hammam-prive', 'Hammam privatif', 'Private hammam', false],
        ['soins-visage', 'Soins du visage', 'Facial treatments', false],
        ['soins-corps', 'Soins du corps', 'Body treatments', false],
        ['bien-etre', 'Centre de bien-être', 'Wellness centre', false],
    ];

    /** @var list<array{string,string,string}> slug, fr, en — équipements et services */
    public const AMENITIES = [
        ['hammam', 'Hammam', 'Hammam'],
        ['sauna', 'Sauna', 'Sauna'],
        ['jacuzzi', 'Jacuzzi', 'Jacuzzi'],
        ['piscine', 'Piscine', 'Pool'],
        ['cabines-privees', 'Cabines privées', 'Private cabins'],
        ['salle-couple', 'Salle duo / couple', 'Couples room'],
        ['femmes-seulement', 'Créneaux réservés aux femmes', 'Women-only slots'],
        ['terrasse', 'Terrasse / rooftop', 'Terrace / rooftop'],
        ['the-offert', 'Thé offert', 'Complimentary tea'],
        ['parking', 'Parking', 'Parking'],
        ['navette-hotel', 'Navette hôtel', 'Hotel pickup'],
        ['vestiaires', 'Vestiaires & douches', 'Changing rooms & showers'],
        ['wifi', 'Wi-Fi', 'Wi-Fi'],
        ['climatisation', 'Climatisation', 'Air conditioning'],
    ];

    public function run(): void
    {
        foreach (self::CITIES as $i => [$slug, $fr, $en, $popular]) {
            City::firstOrCreate(['slug' => $slug], ['name_fr' => $fr, 'name_en' => $en, 'is_popular' => $popular, 'sort_order' => $i]);
        }
        foreach (self::CATEGORIES as $i => [$slug, $fr, $en, $popular]) {
            Category::firstOrCreate(['slug' => $slug], ['name_fr' => $fr, 'name_en' => $en, 'is_popular' => $popular, 'sort_order' => $i]);
        }
        foreach (self::AMENITIES as $i => [$slug, $fr, $en]) {
            Amenity::firstOrCreate(['slug' => $slug], ['name_fr' => $fr, 'name_en' => $en, 'sort_order' => $i]);
        }
    }
}
