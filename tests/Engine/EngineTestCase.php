<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Booking\IntentService;
use App\Domain\Booking\QuoteBuilder;
use App\Models\Allocation;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Extra;
use App\Models\Partner;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Spa;
use App\Models\SpaHour;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Socle des scénarios du moteur : un établissement de test avec horaires
 * lun–sam 09:00–23:00 (mercredi 09:00–14:00 / 16:00–23:00), dimanche fermé.
 * `$this->day` est un mercredi à venir.
 */
abstract class EngineTestCase extends TestCase
{
    use RefreshDatabase;

    protected Spa $spa;

    protected string $day;

    protected CapacityEngine $engine;

    protected QuoteBuilder $quotes;

    protected BookingService $bookings;

    protected IntentService $intents;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->engine = app(CapacityEngine::class);
        $this->quotes = app(QuoteBuilder::class);
        $this->bookings = app(BookingService::class);
        $this->intents = app(IntentService::class);
        $this->day = CarbonImmutable::now()->addDays(7)->next(CarbonImmutable::WEDNESDAY)->format('Y-m-d');
    }

    /* ------------------------------------------------------------------ Données */

    protected function spa(string $slug = 'hl-test-spa', string $name = 'HL TEST Spa'): Spa
    {
        $user = User::create(['name' => 'Partenaire test', 'email' => $slug.'@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $partner = Partner::create(['user_id' => $user->id, 'company_name' => $name.' SARL', 'status' => 'approved']);
        $spa = Spa::create(['partner_id' => $partner->id, 'slug' => $slug, 'name' => $name, 'city' => 'Marrakech', 'status' => 'published', 'published_at' => now(), 'min_lead_minutes' => 0]);
        foreach ([0, 1, 3, 4, 5] as $wd) {
            $spa->hours()->create(['weekday' => $wd, 'opens_min' => 9 * 60, 'closes_min' => 23 * 60]);
        }
        $spa->hours()->create(['weekday' => 2, 'opens_min' => 9 * 60, 'closes_min' => 14 * 60]);
        $spa->hours()->create(['weekday' => 2, 'opens_min' => 16 * 60, 'closes_min' => 23 * 60]);

        return $this->spa = $spa->fresh();
    }

    /** Recharge les relations mises en cache sur le modèle (ressources, horaires…). */
    protected function reload(): Spa
    {
        return $this->spa = $this->spa->fresh();
    }

    protected function type(string $slug, string $name, string $mode = 'pool'): ResourceType
    {
        $t = $this->spa->resourceTypes()->create(['slug' => $slug, 'name_fr' => $name, 'name_en' => $name, 'allocation_mode' => $mode]);
        $this->reload();

        return $t;
    }

    protected function resource(ResourceType $type, string $name, int $cap, int $min = 1, int $max = 0): Resource
    {
        $r = $this->spa->resources()->create(['resource_type_id' => $type->id, 'name' => $name, 'capacity' => $cap, 'min_party' => $min, 'max_party' => $max ?: $cap, 'sort_order' => $this->spa->resources()->count()]);
        $this->reload();

        return $r;
    }

    /**
     * @param  array<int, array{type: string, duration: int, offset?: int}>  $steps
     * @param  array{price_solo?: float, price_couple?: float, price_group?: float}  $prices
     */
    protected function treatment(string $slug, string $name, array $steps, int $partyMax = 10, array $prices = []): Treatment
    {
        $category = count($steps) > 1 ? 'ritual' : (str_contains($slug, 'massage') ? 'massage' : (str_contains($slug, 'soin') ? 'face' : 'hammam'));
        $t = $this->spa->treatments()->create($prices + ['slug' => $slug, 'name_fr' => $name, 'name_en' => $name, 'category' => $category, 'price_solo' => 300, 'party_min' => 1, 'party_max' => $partyMax, 'duration_min' => 0]);
        foreach ($steps as $i => $s) {
            $type = $this->spa->resourceTypes->firstWhere('slug', $s['type']);
            $t->steps()->create(['resource_type_id' => $type->id, 'duration_min' => $s['duration'], 'offset_min' => $s['offset'] ?? 0, 'position' => $i]);
        }
        $t->duration_min = $t->fresh('steps')->computedDuration();
        $t->save();
        $this->reload();

        return $t->fresh();
    }

    protected function extra(Treatment $t, string $name, float $price, int $min = 0, bool $perPerson = true, int $maxQty = 1): Extra
    {
        return $t->extras()->create(['spa_id' => $this->spa->id, 'name_fr' => $name, 'name_en' => $name, 'price' => $price, 'extra_min' => $min, 'per_person' => $perPerson, 'max_qty' => $maxQty]);
    }

    protected function block(string $scope, string $start, string $end, string $kind = 'closed', ?ResourceType $type = null, ?Resource $resource = null): Block
    {
        return $this->spa->blocks()->create(['scope' => $scope, 'resource_type_id' => $type?->id, 'resource_id' => $resource?->id, 'start_at' => $start, 'end_at' => $end, 'kind' => $kind]);
    }

    /* ------------------------------------------------------------------ Actions */

    protected function at(string $time, ?string $day = null): CarbonImmutable
    {
        return CarbonImmutable::parse(($day ?? $this->day).' '.$time.':00');
    }

    /**
     * Tente une réservation et vérifie le résultat attendu.
     *
     * @param  array<int, array{0: string, 1: int, 2?: array}>  $services  [[slug, party, extras?], …]
     */
    protected function book(string $label, string $time, array $services, bool $expectOk, string $status = 'confirmed', ?string $day = null): ?Booking
    {
        $items = [];
        foreach ($services as $i => [$slug, $party]) {
            $items[] = ['treatment' => $slug, 'party' => $party, 'extras' => $services[$i][2] ?? [], 'participant_no' => $i + 1];
        }
        try {
            $b = $this->bookings->book($this->spa, $this->at($time, $day), $items, ['first_name' => 'Test', 'last_name' => 'Client', 'email' => 'client@example.test', 'phone' => '0600000000'], $status);
            $this->assertTrue($expectOk, "$label $time → ACCEPTÉ alors qu'un refus était attendu");

            return $b;
        } catch (BookingException $e) {
            $this->assertFalse($expectOk, "$label $time → REFUSÉ ({$e->getMessage()})");

            return null;
        }
    }

    protected function cancel(Booking $b): void
    {
        $this->bookings->cancel($b, 'client');
    }

    protected function activeAllocations(Booking $b): array
    {
        return Allocation::where('booking_id', $b->id)->where('status', 'active')->orderBy('start_at')->get()->all();
    }

    protected function fmt(iterable $allocs): string
    {
        $out = [];
        foreach ($allocs as $a) {
            $out[] = ($a['resource_name'] ?? Resource::find($a['resource_id'])?->name).' '.CarbonImmutable::parse($a['start_at'])->format('H:i').'–'.CarbonImmutable::parse($a['end_at'])->format('H:i').' ×'.$a['party'];
        }

        return '['.implode(' | ', $out).']';
    }

    protected function availability(array $sel, ?string $day = null): array
    {
        $participants = $this->quotes->participantsFromRequest($this->spa, $sel);
        $quote = $this->quotes->build($this->spa, $participants);

        return $this->engine->availability($this->spa, $day ?? $this->day, $quote['items'], null, $this->at('00:00', $day));
    }

    protected function minutes(string $hhmm): int
    {
        return SpaHour::toMinutes($hhmm);
    }
}
