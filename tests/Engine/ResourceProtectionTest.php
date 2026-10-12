<?php

namespace Tests\Engine;

use App\Domain\Partner\OnboardingService;
use App\Models\Allocation;
use App\Models\Block;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class ResourceProtectionTest extends EngineTestCase
{
    private ResourceType $hammam;

    private Resource $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spa('resource-protection');
        $this->hammam = $this->type('hammam', 'Hammam');
        $this->pool = $this->resource($this->hammam, 'Hammam', 6);
        $this->treatment('hammam', 'Hammam', [['type' => 'hammam', 'duration' => 60]], 6);
    }

    public function test_waiting_and_confirmed_allocations_block_capacity_reduction_inactivation_and_archive(): void
    {
        foreach (['waiting', 'confirmed'] as $status) {
            $booking = $this->book($status, $status === 'waiting' ? '10:00' : '11:00', [['hammam', 2]], true, $status);
            foreach ([['capacity' => 1, 'max_party' => 1], ['status' => 'inactive']] as $change) {
                $this->assertRejected(fn () => $this->pool->fresh()->update($change));
            }
            $this->assertRejected(fn () => $this->pool->fresh()->delete());
            $this->assertSame('active', $this->pool->fresh()->status);
            $this->assertNull($this->pool->fresh()->archived_at);
            $this->assertSame(1, Allocation::where('booking_id', $booking->id)->where('status', 'active')->count());
        }
    }

    public function test_capacity_increase_and_rename_are_allowed_without_changing_allocations(): void
    {
        $booking = $this->book('existing', '10:00', [['hammam', 2]], true);
        $before = Allocation::where('booking_id', $booking->id)->get()->toArray();
        $this->pool->update(['capacity' => 8, 'max_party' => 8, 'name' => 'Grand hammam']);
        $this->assertSame(8, $this->pool->fresh()->capacity);
        $this->assertSame($before, Allocation::where('booking_id', $booking->id)->get()->toArray());
    }

    public function test_stale_resource_is_checked_against_current_capacity_under_lock(): void
    {
        $stale = $this->pool->fresh();
        $this->pool->update(['capacity' => 8, 'max_party' => 8]);
        $this->book('larger pool', '10:00', [['hammam', 2]], true);
        // The stale instance sees an increase from 6 to 7, but it is a reduction of current capacity 8.
        $this->assertRejected(fn () => $stale->update(['capacity' => 7, 'max_party' => 7]));
        $this->assertSame(8, $this->pool->fresh()->capacity);
    }

    public function test_archive_preserves_released_and_historical_allocations(): void
    {
        $booking = $this->book('cancel then archive', '10:00', [['hammam', 1]], true);
        $this->bookings->cancel($booking, 'client');
        $ids = Allocation::where('booking_id', $booking->id)->pluck('id')->all();
        $this->pool->delete();
        $this->assertDatabaseHas('resources', ['id' => $this->pool->id, 'status' => 'inactive']);
        $this->assertNotNull($this->pool->fresh()->archived_at);
        $this->assertSame($ids, Allocation::where('booking_id', $booking->id)->pluck('id')->all());
        $this->assertRejected(fn () => $this->pool->fresh()->update(['status' => 'active']));
        $this->assertRejected(fn () => $this->hammam->delete());
    }

    public function test_ongoing_commitment_cannot_be_archived_but_completed_history_is_preserved(): void
    {
        $booking = $this->book('ongoing protection', '10:00', [['hammam', 1]], true);
        $this->travelTo($this->at('10:30'));
        $this->assertRejected(fn () => $this->pool->delete());
        $this->travelTo($this->at('12:00'));
        $this->bookings->complete($booking);
        $this->pool->delete();
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertNotNull($this->pool->fresh()->archived_at);
        $this->assertSame(1, Allocation::where('booking_id', $booking->id)->count());
    }

    public function test_type_mode_kind_and_rotation_cannot_change_with_commitments(): void
    {
        $this->book('type protected', '10:00', [['hammam', 1]], true);
        foreach ([['allocation_mode' => 'unit'], ['kind' => 'therapist'], ['buffer_min' => 15]] as $change) {
            $this->assertRejected(fn () => $this->hammam->fresh()->update($change));
        }
        $this->assertSame('pool', $this->hammam->fresh()->allocation_mode);
        $this->assertSame(0, (int) $this->hammam->fresh()->buffer_min);
    }

    public function test_completion_keeps_rotation_protected_until_its_end(): void
    {
        $this->hammam->update(['buffer_min' => 15]);
        $booking = $this->book('completed rotation', '10:00', [['hammam', 1]], true);
        $this->travelTo($this->at('11:00'));
        $this->bookings->complete($booking);
        $this->assertRejected(fn () => $this->pool->delete());
        $this->assertRejected(fn () => $this->hammam->update(['buffer_min' => 0]));
        $this->assertRejected(fn () => $this->block('spa', $this->at('11:05'), $this->at('11:10')));
        $this->travelTo($this->at('11:15'));
        $this->pool->delete();
        $this->assertNotNull($this->pool->fresh()->archived_at);
    }

    public function test_opening_hours_must_cover_the_whole_ritual_including_gaps(): void
    {
        $this->treatment('ritual-gap', 'Rituel avec pause', [
            ['type' => 'hammam', 'duration' => 30],
            ['type' => 'hammam', 'duration' => 30, 'offset' => 60],
        ], 6);
        $this->book('whole visit', '10:00', [['ritual-gap', 1]], true);
        $hours = [
            ['weekday' => 2, 'opens_min' => 600, 'closes_min' => 630],
            ['weekday' => 2, 'opens_min' => 660, 'closes_min' => 690],
        ];
        $this->assertRejected(fn () => app(OnboardingService::class)->saveHours($this->spa, $hours, ['hammam_capacity' => 6]));
    }

    public function test_overlapping_blocks_are_refused_for_spa_type_and_resource(): void
    {
        $booking = $this->book('protected block', '10:00', [['hammam', 1]], true, 'waiting');
        foreach (['spa', 'type', 'resource'] as $scope) {
            $error = $this->assertRejected(fn () => $this->block($scope, $this->at('10:30'), $this->at('11:30'), 'closed', $this->hammam, $this->pool));
            $this->assertStringContainsString($booking->reference, $error->getMessage());
        }
        $this->assertSame(0, Block::count());
        $this->assertSame(1, Allocation::where('booking_id', $booking->id)->where('status', 'active')->count());
    }

    public function test_touching_boundaries_and_unrelated_resources_can_be_blocked(): void
    {
        $this->book('boundaries', '10:00', [['hammam', 1]], true);
        $other = $this->resource($this->hammam, 'Autre hammam', 4);
        $this->block('resource', $this->at('10:30'), $this->at('11:00'), 'maintenance', null, $other);
        $this->block('spa', $this->at('09:00'), $this->at('10:00'));
        $this->block('spa', $this->at('11:00'), $this->at('12:00'));
        $this->assertSame(3, Block::count());
    }

    public function test_rotation_time_is_protected_against_a_new_block(): void
    {
        $this->hammam->update(['buffer_min' => 15]);
        $this->book('rotation', '10:00', [['hammam', 1]], true);
        $this->assertRejected(fn () => $this->block('resource', $this->at('11:05'), $this->at('11:10'), 'maintenance', null, $this->pool));
        $this->block('resource', $this->at('11:15'), $this->at('11:30'), 'maintenance', null, $this->pool);
        $this->assertSame(1, Block::count());
    }

    public function test_editing_a_block_into_a_commitment_is_refused_without_mutating_the_record(): void
    {
        $block = $this->block('spa', $this->at('12:00'), $this->at('13:00'));
        $this->book('edit protected', '10:00', [['hammam', 1]], true);
        $this->assertRejected(fn () => $block->update(['start_at' => $this->at('10:30')]));
        $this->assertSame('12:00', $block->fresh()->start_at->format('H:i'));
    }

    public function test_resources_and_blocks_cannot_reference_another_spa(): void
    {
        $originalSpa = $this->spa;
        $this->spa('other-resource-spa');
        $foreignType = $this->type('hammam', 'Autre spa');
        $foreignResource = $this->resource($foreignType, 'Autre ressource', 4);
        $this->spa = $originalSpa;
        $this->assertRejected(fn () => $originalSpa->resources()->create(['resource_type_id' => $foreignType->id, 'name' => 'Falsifié']));
        $this->assertRejected(fn () => $this->pool->fresh()->update(['spa_id' => $foreignType->spa_id]));
        $this->assertRejected(fn () => $this->block('type', $this->at('09:00'), $this->at('10:00'), 'closed', $foreignType));
        $this->assertRejected(fn () => $this->block('resource', $this->at('09:00'), $this->at('10:00'), 'closed', null, $foreignResource));
    }

    public function test_onboarding_capacity_and_hours_update_rolls_back_as_one_operation(): void
    {
        $this->book('onboarding protected', '10:00', [['hammam', 2]], true);
        $hours = $this->spa->hours()->orderBy('id')->get()->toArray();
        $this->assertRejected(fn () => app(OnboardingService::class)->saveHours($this->spa,
            array_map(fn (array $h) => ['weekday' => $h['weekday'], 'opens_min' => $h['opens_min'], 'closes_min' => $h['closes_min']], $hours),
            ['hammam_capacity' => 1]
        ));
        $this->assertSame($hours, $this->spa->hours()->orderBy('id')->get()->toArray());
        $this->assertSame(6, $this->pool->fresh()->capacity);
        $this->assertRejected(fn () => app(OnboardingService::class)->saveHours($this->spa, [], ['hammam_capacity' => 6]));
        $this->assertSame($hours, $this->spa->hours()->orderBy('id')->get()->toArray());
    }

    public function test_database_rejects_bulk_deletion_that_bypasses_model_archive(): void
    {
        $this->book('bulk delete protected', '10:00', [['hammam', 1]], true);
        $this->expectException(QueryException::class);
        Resource::whereKey($this->pool->id)->delete();
    }

    private function assertRejected(callable $operation): ValidationException
    {
        try {
            $operation();
            $this->fail('La mutation devait être refusée sans modifier les réservations.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());

            return $e;
        }
    }
}
