<?php

namespace App\Domain\Booking;

use App\Domain\Catalogue\Presentation;
use App\Domain\Policy\CancellationPolicy;
use App\Models\Spa;
use App\Models\Treatment;

/**
 * Devis serveur : prix par formule (solo / couple / groupe), extras (par personne ou non),
 * durée par participant, total et empreinte stable.
 */
class QuoteBuilder
{
    public function __construct(private CapacityEngine $engine) {}

    /**
     * Normalise une demande client en participants.
     *
     * Mode simple  : {treatment, party, extras}
     * Mode avancé  : {participants: [{treatment, extras}, …]} (une personne par participant, party optionnel)
     *
     * @return array<int, array{participant_no:int, treatment_id:int, party:int, extras:array<int,int>}>
     */
    public function participantsFromRequest(Spa $spa, array $req): array
    {
        $out = [];
        if (! empty($req['participants']) && is_array($req['participants'])) {
            $no = 0;
            foreach ($req['participants'] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $out[] = [
                    'participant_no' => ++$no,
                    'treatment' => $p['treatment_id'] ?? $p['treatment'] ?? null,
                    'party' => max(1, (int) ($p['party'] ?? 1)),
                    'extras' => $p['extras'] ?? [],
                ];
            }
        } elseif (! empty($req['treatment']) || ! empty($req['treatment_id'])) {
            $out[] = [
                'participant_no' => 1,
                'treatment' => $req['treatment_id'] ?? $req['treatment'],
                'party' => max(1, (int) ($req['party'] ?? 1)),
                'extras' => $req['extras'] ?? [],
            ];
        }

        if (! $out) {
            throw BookingException::make('empty', 'choose_treatment');
        }
        if (count($out) > (int) config('hl.max_participants')) {
            throw BookingException::make('too_many', 'too_many_participants');
        }

        foreach ($out as &$p) {
            $t = $this->engine->resolveTreatment($spa, $p['treatment']);
            if (! $t || ! $t->isActive()) {
                throw BookingException::make('treatment', 'treatment_not_found');
            }
            $p['treatment_id'] = $t->id;
            $p['extras'] = CapacityEngine::normalizeExtras($t->extras->where('status', 'active')->keyBy('id'), $p['extras']);
            unset($p['treatment']);
        }

        return $out;
    }

    /** Type de tarif demandé (standard par défaut ; « nr » / « non_refundable » acceptés). */
    public static function rateFromRequest(array $req): string
    {
        $r = (string) ($req['rate'] ?? 'standard');

        return in_array($r, ['nr', 'non_refundable'], true) ? 'non_refundable' : 'standard';
    }

    /**
     * Prix d'une prestation pour `party` personnes.
     *
     * @return array{total: float, formula: string, unit: float}
     */
    public static function treatmentPrice(Treatment $t, int $party): array
    {
        $solo = $t->price_solo;
        $couple = $t->price_couple;
        $group = $t->price_group;

        if ($party === 1) {
            $unit = $solo ?? $group ?? ($couple !== null ? $couple / 2 : 0.0);

            return ['total' => $unit, 'formula' => 'solo', 'unit' => $unit];
        }
        if ($party === 2) {
            if ($couple !== null) {
                return ['total' => $couple, 'formula' => 'couple', 'unit' => $couple / 2];
            }
            $unit = $group ?? $solo ?? 0.0;

            return ['total' => 2 * $unit, 'formula' => $group !== null ? 'group' : 'solo', 'unit' => $unit];
        }
        $unit = $group ?? $solo ?? ($couple !== null ? $couple / 2 : 0.0);

        return ['total' => $party * $unit, 'formula' => $group !== null ? 'group' : 'solo', 'unit' => $unit];
    }

    /** Devis sans les informations de commission (réponse publique / API). */
    public static function publicView(array $quote): array
    {
        unset($quote['commissionable']);
        foreach ($quote['lines'] as &$l) {
            unset($l['commissionable']);
            foreach ($l['extras'] as &$e) {
                unset($e['commissionable']);
            }
        }

        return $quote;
    }

    /**
     * Devis complet.
     *
     * @param  array  $participants  sortie de participantsFromRequest()
     * @return array{ok:bool, errors:string[], spa_id:int, party:int, duration_min:int, total:float, currency:string, lines:array, items:array, fingerprint:string}
     */
    public function build(Spa $spa, array $participants, string $rate = 'standard'): array
    {
        $nr = $rate === 'non_refundable';
        $quote = [
            'ok' => true, 'errors' => [], 'spa_id' => $spa->id, 'party' => 0, 'duration_min' => 0,
            'total' => 0.0, 'commissionable' => 0.0, 'currency' => config('hl.currency'), 'lines' => [], 'items' => [],
            'rate' => $nr ? 'non_refundable' : 'standard', 'standard_total' => 0.0, 'nr_discount_pct' => null, 'nr_available' => true,
            'cancellation_hours' => (int) $spa->cancellation_hours,
        ];
        $pcts = [];

        foreach ($participants as $p) {
            $t = $this->engine->resolveTreatment($spa, (int) $p['treatment_id']);
            if (! $t) {
                $quote['ok'] = false;
                $quote['errors'][] = __('booking.treatment_not_found');

                continue;
            }
            $party = (int) $p['party'];
            if ($party < $t->party_min || $party > $t->party_max) {
                $quote['ok'] = false;
                $quote['errors'][] = __('booking.party_range', ['name' => $t->tr('name'), 'min' => $t->party_min, 'max' => $t->party_max]);

                continue;
            }

            $price = self::treatmentPrice($t, $party);
            $standardBase = round($price['total'], 2);
            if (! $t->hasNonRefundable()) {
                $quote['nr_available'] = false;
            }
            if ($nr) {
                if (! $t->hasNonRefundable()) {
                    $quote['ok'] = false;
                    $quote['errors'][] = __('booking.nr_unavailable', ['name' => $t->tr('name')]);

                    continue;
                }
                $price['total'] = CancellationPolicy::discounted($standardBase, $t->nr_discount_pct);
                $price['unit'] = $party > 0 ? $price['total'] / $party : $price['total'];
                $pcts[] = (int) $t->nr_discount_pct;
            }
            $quote['cancellation_hours'] = max($quote['cancellation_hours'], $t->cancellationHours());
            $extras = $t->extras->keyBy('id');
            $exLines = [];
            $exTotal = 0.0;
            $exNonCommissionable = 0.0;
            $extraMin = 0;
            foreach ($p['extras'] as $eid => $qty) {
                $e = $extras[$eid];
                $mult = $e->per_person ? $party : 1;
                $sub = round($e->price * $qty * $mult, 2);
                $exTotal += $sub;
                if (! $e->commissionable) {
                    $exNonCommissionable += $sub;
                }
                $extraMin += $e->extra_min * $qty;
                $exLines[] = [
                    'id' => $eid, 'name' => $e->tr('name'), 'qty' => $qty, 'per_person' => (int) $e->per_person,
                    'unit_price' => $e->price, 'price' => $sub, 'extra_min' => $e->extra_min * $qty,
                    'commissionable' => (int) $e->commissionable,
                ];
            }

            $duration = $t->computedDuration() + $extraMin;
            $line = [
                'participant_no' => (int) $p['participant_no'],
                'treatment_id' => $t->id,
                'treatment_name' => $t->tr('name'),
                'party' => $party,
                'formula' => $price['formula'],
                'unit_price' => round($price['unit'], 2),
                'base_price' => round($price['total'], 2),
                'standard_base_price' => $standardBase,
                'nr_discount_pct' => $nr ? (int) $t->nr_discount_pct : null,
                'cancellation_hours' => $t->cancellationHours(),
                'extras' => $exLines,
                'extras_price' => round($exTotal, 2),
                'price' => round($price['total'] + $exTotal, 2),
                'commissionable' => round($price['total'] + $exTotal - $exNonCommissionable, 2),
                'duration_min' => $duration,
                'treatment_duration_min' => $t->computedDuration(),
                'steps' => $t->steps->map(fn ($s) => ['label' => $s->displayLabel(), 'duration_min' => (int) $s->duration_min])->values()->all(),
                'included' => array_map(fn ($k) => __('ui.included.'.$k), Presentation::cleanIncluded($t->included)),
            ];
            $quote['lines'][] = $line;
            $quote['items'][] = ['treatment' => $t->id, 'party' => $party, 'extras' => $p['extras'], 'participant_no' => (int) $p['participant_no']];
            $quote['party'] += $party;
            $quote['total'] += $line['price'];
            $quote['standard_total'] += $standardBase + $exTotal;
            $quote['commissionable'] += $line['commissionable'];
            $quote['duration_min'] = max($quote['duration_min'], $duration);
        }

        $quote['total'] = round($quote['total'], 2);
        $quote['standard_total'] = round($quote['standard_total'], 2);
        $quote['commissionable'] = round($quote['commissionable'], 2);
        $quote['nr_discount_pct'] = $nr && $pcts ? min($pcts) : null;
        $quote['fingerprint'] = self::fingerprint($quote);

        return $quote;
    }

    public function fromRequest(Spa $spa, array $req): array
    {
        return $this->build($spa, $this->participantsFromRequest($spa, $req), self::rateFromRequest($req));
    }

    /** Empreinte stable d'un devis : mêmes lignes, extras, total et durée → même empreinte. */
    public static function fingerprint(array $quote): string
    {
        $flat = [];
        foreach ($quote['lines'] as $l) {
            $ex = [];
            foreach ($l['extras'] as $e) {
                $ex[] = $e['id'].'x'.$e['qty'];
            }
            sort($ex);
            $flat[] = $l['participant_no'].':'.$l['treatment_id'].':'.$l['party'].':'.implode(',', $ex);
        }

        return md5(implode('|', $flat).'|'.$quote['total'].'|'.$quote['duration_min'].'|'.($quote['rate'] ?? 'standard').'|'.($quote['cancellation_hours'] ?? ''));
    }

    /** Libellé court d'un devis (« 2 × Hammam + Massage (+ Massage crânien) »). */
    public static function summaryText(array $quote): string
    {
        $parts = [];
        foreach ($quote['lines'] as $l) {
            $s = $l['party'].' × '.$l['treatment_name'];
            if ($l['extras']) {
                $s .= ' (+ '.implode(', ', array_map(fn ($e) => $e['name'].($e['qty'] > 1 ? ' ×'.$e['qty'] : ''), $l['extras'])).')';
            }
            $parts[] = $s;
        }

        return implode(' ; ', $parts);
    }
}
