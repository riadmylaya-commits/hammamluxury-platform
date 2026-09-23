<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\Review;
use App\Models\Spa;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration : reprise de la fiche « Hammam Démo Marrakech » (ex-Listeo #32) + 3 établissements fictifs.
 * Mots de passe de démo à changer dès le déploiement (voir README) — ne jamais exécuter en production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('HL_DEMO_PASSWORD', 'ChangeMe-Demo-2026!');

        User::updateOrCreate(['email' => 'admin@hammamluxury.test'], ['name' => 'Admin HL', 'role' => 'admin', 'password' => Hash::make($password), 'email_verified_at' => now()]);

        $owner = User::updateOrCreate(['email' => 'owner@hammamluxury.test'], ['name' => 'Propriétaire Démo', 'role' => 'partner', 'phone' => '+212600000000', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        $partner = Partner::updateOrCreate(['user_id' => $owner->id], ['company_name' => 'Hammam Démo SARL', 'legal_form' => 'SARL', 'contact_phone' => '+212600000000', 'status' => 'approved']);

        $demo = $this->spa($partner, [
            'slug' => 'hammam-demo-marrakech', 'name' => 'Hammam Démo Marrakech', 'category' => 'hammam', 'city' => 'Marrakech', 'area' => 'Médina',
            'address' => 'Derb Demo 12, Médina, Marrakech', 'phone' => '+212 5 24 00 00 00', 'email' => 'contact@hammam-demo.test',
            'description_fr' => "Hammam traditionnel au cœur de la médina : salle chaude collective, gommage au savon noir, massages à l'huile d'argan dans nos deux cabines privées et soins du visage.\nThé à la menthe offert, patio ombragé pour se reposer.",
            'description_en' => "Traditional hammam in the heart of the medina: shared hot room, black-soap scrub, argan-oil massages in our two private cabins and facials.\nComplimentary mint tea, shaded patio to relax.",
            'features' => ['couples', 'tea', 'rooftop'], 'rating' => 4.7, 'reviews_count' => 128, 'lat' => 31.6295, 'lng' => -7.9811,
        ], [
            [0, 540, 1320], [1, 540, 1320], [2, 540, 840], [2, 960, 1380], [3, 540, 1320], [4, 540, 1320], [5, 540, 1320],
        ], [
            ['hammam', 'Hammam', 'Hammam', 'pool', 'room', [['Hammam', 6, 1, 6]]],
            ['massage', 'Cabine de massage', 'Massage cabin', 'unit', 'room', [['Cabine 1', 1, 1, 1], ['Cabine 2', 1, 1, 1]]],
            ['soin', 'Salle de soin', 'Treatment room', 'unit', 'room', [['Salle soin', 2, 1, 2]]],
            ['jacuzzi', 'Jacuzzi', 'Jacuzzi', 'pool', 'equipment', [['Jacuzzi', 4, 1, 4]]],
        ], [
            ['hammam-traditionnel', 'hammam', 'Hammam traditionnel', 'Traditional hammam', 'Salle chaude, gommage au savon noir et rinçage. 45 minutes de détente.', 'Hot room, black-soap scrub and rinse. 45 minutes of relaxation.', 150, 280, 130, 1, 6, [['hammam', 45]], []],
            ['massage-argan', 'massage', 'Massage à l’huile d’argan', 'Argan oil massage', 'Massage relaxant du corps entier, 60 minutes, en cabine privée.', 'Full-body relaxing massage, 60 minutes, in a private cabin.', 400, 750, null, 1, 2, [['massage', 60]], [['Massage crânien', 'Head massage', 125, 15, true, 1]]],
            ['hammam-massage', 'ritual', 'Hammam + Massage', 'Hammam + Massage', 'Notre rituel signature : hammam traditionnel de 45 min puis massage à l’argan de 60 min en cabine privée.', 'Our signature ritual: 45-min traditional hammam followed by a 60-min argan massage in a private cabin.', 650, 1200, 600, 1, 2, [['hammam', 45], ['massage', 60]], [['Massage crânien', 'Head massage', 125, 15, true, 1], ['Thé & pâtisseries', 'Tea & pastries', 30, 0, false, 3]]],
            ['hammam-soin-visage', 'ritual', 'Hammam + Soin visage', 'Hammam + Facial', 'Hammam de 45 min suivi d’un soin du visage purifiant à l’argile du Rif (50 min).', '45-min hammam followed by a purifying Rif clay facial (50 min).', 550, 1000, null, 1, 2, [['hammam', 45], ['soin', 50]], []],
            ['soin-visage', 'face', 'Soin du visage à l’argile', 'Clay facial', 'Nettoyage, gommage doux, masque à l’argile et hydratation. 50 minutes.', 'Cleansing, gentle scrub, clay mask and moisturising. 50 minutes.', 350, null, null, 1, 2, [['soin', 50]], []],
            ['jacuzzi-hammam', 'ritual', 'Hammam + Jacuzzi', 'Hammam + Jacuzzi', 'Hammam de 45 min puis 30 min de jacuzzi sur la terrasse.', '45-min hammam then 30 min in the rooftop jacuzzi.', 300, 550, 260, 1, 4, [['hammam', 45], ['jacuzzi', 30]], []],
        ]);
        $this->reviews($demo, [['Sophie L.', 5, 'Un moment magique, le gommage est parfait et le massage très professionnel.', 'fr'], ['James W.', 5, 'Great experience, very clean and the staff was lovely.', 'en'], ['Nadia B.', 4, 'Très bien mais un peu d’attente à l’accueil.', 'fr']]);

        // Établissements fictifs (autres partenaires) pour peupler la recherche.
        $others = [
            ['sara@hammamluxury.test', 'Sara Spa SARL', 'riad-sara-spa', 'Riad Sara Spa', 'spa', 'Marrakech', 'Guéliz', ['private_hammam', 'couples', 'pool'], 4.9, 64, 'Spa intimiste dans un riad rénové : hammam privatif pour deux, massages en duo et piscine intérieure chauffée.', 'Intimate spa in a renovated riad: private hammam for two, couples massages and heated indoor pool.'],
            ['atlas@hammamluxury.test', 'Atlas Wellness SA', 'atlas-wellness-agadir', 'Atlas Wellness Agadir', 'wellness', 'Agadir', 'Front de mer', ['parking', 'pool', 'hotel_pickup'], 4.5, 210, 'Grand centre de bien-être face à l’océan : hammam, sauna, 6 cabines de massage et espace thalasso.', 'Large seafront wellness centre: hammam, sauna, 6 massage cabins and thalasso area.'],
            ['fes@hammamluxury.test', 'Dar Fès Hammam', 'dar-fes-hammam', 'Dar Fès Hammam', 'hammam', 'Fès', 'Fès el-Bali', ['women_only', 'tea'], 4.6, 38, 'Hammam authentique de la vieille ville, créneaux réservés aux femmes le matin.', 'Authentic old-town hammam, women-only slots in the morning.'],
        ];
        foreach ($others as [$email, $company, $slug, $name, $cat, $city, $area, $features, $rating, $nrev, $fr, $en]) {
            $u = User::updateOrCreate(['email' => $email], ['name' => $name, 'role' => 'partner', 'password' => Hash::make($password), 'email_verified_at' => now()]);
            $p = Partner::updateOrCreate(['user_id' => $u->id], ['company_name' => $company, 'status' => 'approved']);
            $spa = $this->spa($p, [
                'slug' => $slug, 'name' => $name, 'category' => $cat, 'city' => $city, 'area' => $area, 'address' => "Adresse démo, $area, $city", 'phone' => '+212 5 00 00 00 00',
                'description_fr' => $fr, 'description_en' => $en, 'features' => $features, 'rating' => $rating, 'reviews_count' => $nrev,
            ], [[0, 600, 1260], [1, 600, 1260], [2, 600, 1260], [3, 600, 1260], [4, 600, 1260], [5, 600, 1320], [6, 600, 1200]], [
                ['hammam', 'Hammam', 'Hammam', $slug === 'riad-sara-spa' ? 'unit' : 'pool', 'room', $slug === 'riad-sara-spa' ? [['Hammam privé', 2, 1, 2]] : [['Hammam', 8, 1, 8]]],
                ['massage', 'Cabine de massage', 'Massage cabin', 'unit', 'room', $slug === 'atlas-wellness-agadir' ? array_map(fn ($i) => ["Cabine $i", 1, 1, 1], range(1, 6)) : [['Cabine 1', 1, 1, 1], ['Cabine duo', 2, 1, 2]]],
            ], [
                ['hammam', 'hammam', 'Hammam', 'Hammam', null, null, $slug === 'riad-sara-spa' ? 350 : 120, $slug === 'riad-sara-spa' ? 600 : 220, null, 1, $slug === 'riad-sara-spa' ? 2 : 6, [['hammam', 45]], []],
                ['massage', 'massage', 'Massage relaxant', 'Relaxing massage', null, null, 450, 850, null, 1, 2, [['massage', 60]], [['Huiles chaudes', 'Hot oils', 80, 0, true, 1]]],
                ['hammam-massage', 'ritual', 'Hammam + Massage', 'Hammam + Massage', null, null, 600, 1100, null, 1, 2, [['hammam', 45], ['massage', 60]], []],
            ]);
            $this->reviews($spa, [['Client démo', 5, 'Parfait.', 'fr']]);
        }
    }

    private function spa(Partner $partner, array $attrs, array $hours, array $types, array $treatments): Spa
    {
        $spa = Spa::updateOrCreate(['slug' => $attrs['slug']], $attrs + ['partner_id' => $partner->id, 'status' => 'published', 'published_at' => now()]);
        $spa->hours()->delete();
        foreach ($hours as [$wd, $o, $c]) {
            $spa->hours()->create(['weekday' => $wd, 'opens_min' => $o, 'closes_min' => $c]);
        }
        $spa->photos()->delete();
        foreach (range(1, 4) as $i) {
            $spa->photos()->create(['path' => "https://picsum.photos/seed/{$spa->slug}-$i/1200/800", 'sort_order' => $i, 'is_cover' => $i === 1, 'caption_fr' => $spa->name, 'caption_en' => $spa->name]);
        }
        $typeIds = [];
        foreach ($types as $i => [$slug, $fr, $en, $mode, $kind, $resources]) {
            $t = $spa->resourceTypes()->updateOrCreate(['slug' => $slug], ['name_fr' => $fr, 'name_en' => $en, 'allocation_mode' => $mode, 'kind' => $kind, 'sort_order' => $i]);
            $typeIds[$slug] = $t->id;
            $t->resources()->delete();
            foreach ($resources as $j => [$name, $cap, $min, $max]) {
                $t->resources()->create(['spa_id' => $spa->id, 'name' => $name, 'capacity' => $cap, 'min_party' => $min, 'max_party' => $max, 'sort_order' => $j]);
            }
        }
        $priceFrom = null;
        foreach ($treatments as $i => [$slug, $cat, $fr, $en, $dfr, $den, $solo, $couple, $group, $pmin, $pmax, $steps, $extras]) {
            $t = $spa->treatments()->updateOrCreate(['slug' => $slug], [
                'category' => $cat, 'name_fr' => $fr, 'name_en' => $en, 'description_fr' => $dfr, 'description_en' => $den,
                'duration_min' => array_sum(array_column($steps, 1)), 'price_solo' => $solo, 'price_couple' => $couple, 'price_group' => $group,
                'party_min' => $pmin, 'party_max' => $pmax, 'sort_order' => $i, 'status' => 'active',
            ]);
            $t->steps()->delete();
            $offset = 0;
            foreach ($steps as $k => [$type, $dur]) {
                $t->steps()->create(['resource_type_id' => $typeIds[$type], 'duration_min' => $dur, 'offset_min' => $offset, 'position' => $k]);
                $offset += $dur;
            }
            $t->extras()->delete();
            foreach ($extras as [$efr, $een, $price, $min, $pp, $max]) {
                $t->extras()->create(['spa_id' => $spa->id, 'name_fr' => $efr, 'name_en' => $een, 'price' => $price, 'extra_min' => $min, 'per_person' => $pp, 'max_qty' => $max]);
            }
            $priceFrom = $priceFrom === null ? (float) $solo : min($priceFrom, (float) $solo);
        }
        $spa->update(['price_from' => $priceFrom]);

        return $spa;
    }

    private function reviews(Spa $spa, array $rows): void
    {
        $spa->reviews()->delete();
        foreach ($rows as [$author, $rating, $body, $locale]) {
            $spa->reviews()->create(['author_name' => $author, 'rating' => $rating, 'body' => $body, 'locale' => $locale, 'status' => 'published']);
        }
    }
}
