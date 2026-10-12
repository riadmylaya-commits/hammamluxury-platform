<?php

namespace App\Domain\Booking;

use App\Models\Allocation;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Spa;
use App\Models\SpaHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Catalogue mutations share the booking lock: sold capacity cannot disappear between check and write. */
class ResourceProtection
{
    public function __construct(private BookingMutation $mutations) {}

    public function saveResource(Resource $resource, callable $persist): bool
    {
        return $this->mutations->forSpa($this->ownerId($resource), function (Spa $spa) use ($resource, $persist): bool {
            $current = $resource->exists ? Resource::query()->lockForUpdate()->findOrFail($resource->id) : null;
            $data = array_replace($current?->getAttributes() ?? [], $resource->getDirty());
            $this->assertOwner($spa, $data);
            $type = ResourceType::where('spa_id', $spa->id)->find($data['resource_type_id'] ?? null);
            if (! $type) {
                $this->fail('resource_type_id', 'resource_wrong_spa');
            }
            $capacity = (int) ($data['capacity'] ?? 1);
            $minimum = (int) ($data['min_party'] ?? 1);
            $maximum = (int) ($data['max_party'] ?? 1);
            if ($capacity < 1 || $minimum < 1 || $maximum < $minimum || $maximum > $capacity) {
                $this->fail('capacity', 'resource_invalid_capacity');
            }
            if (! in_array($data['status'] ?? 'active', ['active', 'inactive'], true)) {
                $this->fail('status', 'resource_invalid_status');
            }
            if ($current) {
                if ($current->archived_at && ($data['status'] ?? $current->status) === 'active') {
                    $this->fail('status', 'resource_archived');
                }
                if ((int) $data['resource_type_id'] !== (int) $current->resource_type_id && $current->allocations()->exists()) {
                    $this->fail('resource_type_id', 'resource_type_history');
                }
                $restrictsCapacity = $capacity < (int) $current->capacity
                    || $minimum > (int) $current->min_party || $maximum < (int) $current->max_party
                    || ($data['status'] ?? $current->status) !== 'active'
                    || ! empty($data['archived_at'])
                    || (int) $data['resource_type_id'] !== (int) $current->resource_type_id;
                if ($restrictsCapacity) {
                    $this->assertNoCommitments($this->commitments($spa)->where('resource_id', $current->id), 'capacity');
                }
            }

            return $persist();
        });
    }

    /** Deletion deliberately archives even an unused resource; historical allocations keep their references. */
    public function archiveResource(Resource $resource): ?bool
    {
        if (! $resource->exists) {
            return null;
        }

        return $this->mutations->forSpa($this->ownerId($resource), function (Spa $spa) use ($resource): bool {
            $current = Resource::query()->lockForUpdate()->findOrFail($resource->id);
            $this->assertNoCommitments($this->commitments($spa)->where('resource_id', $current->id), 'status');
            $resource->setRawAttributes($current->getAttributes(), true);
            $resource->fill(['status' => 'inactive', 'archived_at' => $current->archived_at ?? now()]);

            return $resource->save();
        });
    }

    public function saveType(ResourceType $type, callable $persist): bool
    {
        return $this->mutations->forSpa($this->ownerId($type), function (Spa $spa) use ($type, $persist): bool {
            $current = $type->exists ? ResourceType::query()->lockForUpdate()->findOrFail($type->id) : null;
            $data = array_replace($current?->getAttributes() ?? [], $type->getDirty());
            $this->assertOwner($spa, $data);
            if (! in_array($data['allocation_mode'] ?? 'pool', ResourceType::MODES, true)
                || ! in_array($data['kind'] ?? 'room', ResourceType::KINDS, true)
                || (int) ($data['buffer_min'] ?? 0) < 0) {
                $this->fail('allocation_mode', 'resource_invalid_type');
            }
            if ($current && (($data['allocation_mode'] ?? $current->allocation_mode) !== $current->allocation_mode
                || ($data['kind'] ?? $current->kind) !== $current->kind
                || (int) ($data['buffer_min'] ?? 0) !== (int) $current->buffer_min)) {
                $this->assertNoCommitments($this->commitments($spa)->where('resource_type_id', $current->id), 'allocation_mode');
            }

            return $persist();
        });
    }

    public function deleteType(ResourceType $type, callable $persist): ?bool
    {
        if (! $type->exists) {
            return null;
        }

        return $this->mutations->forSpa($this->ownerId($type), function () use ($type, $persist): ?bool {
            $current = ResourceType::query()->lockForUpdate()->findOrFail($type->id);
            if ($current->resources()->exists() || $current->steps()->exists()
                || Allocation::where('resource_type_id', $current->id)->exists()
                || Block::where('resource_type_id', $current->id)->exists()) {
                $this->fail('resource_type_id', 'resource_type_referenced');
            }

            return $persist();
        });
    }

    public function saveBlock(Block $block, callable $persist): bool
    {
        return $this->mutations->forSpa($this->ownerId($block), function (Spa $spa) use ($block, $persist): bool {
            $current = $block->exists ? Block::query()->lockForUpdate()->findOrFail($block->id) : null;
            $data = array_replace($current?->getAttributes() ?? [], $block->getDirty());
            $this->assertOwner($spa, $data);
            $scope = $data['scope'] ?? null;
            if (! in_array($scope, Block::SCOPES, true) || ! in_array($data['kind'] ?? 'closed', Block::KINDS, true)) {
                $this->fail('scope', 'resource_invalid_block');
            }
            if ($scope === 'type' && ! ResourceType::where('spa_id', $spa->id)->whereKey($data['resource_type_id'] ?? null)->exists()) {
                $this->fail('resource_type_id', 'resource_wrong_spa');
            }
            if ($scope === 'resource' && ! Resource::where('spa_id', $spa->id)->whereKey($data['resource_id'] ?? null)->exists()) {
                $this->fail('resource_id', 'resource_wrong_spa');
            }
            $block->resource_type_id = $scope === 'type' ? $data['resource_type_id'] : null;
            $block->resource_id = $scope === 'resource' ? $data['resource_id'] : null;
            try {
                if (empty($data['start_at']) || empty($data['end_at'])) {
                    $this->fail('end_at', 'resource_invalid_block');
                }
                $start = CarbonImmutable::parse($data['start_at']);
                $end = CarbonImmutable::parse($data['end_at']);
            } catch (\InvalidArgumentException $e) {
                $this->fail('end_at', 'resource_invalid_block');
            }
            if ($end <= $start) {
                $this->fail('end_at', 'resource_invalid_block');
            }
            $conflicts = $this->commitments($spa)->filter(function (Allocation $allocation) use ($scope, $data, $start, $end): bool {
                if ($scope === 'type' && (int) $allocation->resource_type_id !== (int) $data['resource_type_id']) {
                    return false;
                }
                if ($scope === 'resource' && (int) $allocation->resource_id !== (int) $data['resource_id']) {
                    return false;
                }

                return $allocation->start_at < $end && $this->occupiedUntil($allocation) > $start;
            });
            $this->assertNoCommitments($conflicts, 'start_at');

            return $persist();
        });
    }

    /** Called under the same lock before replacing the opening-hours collection. */
    public function assertOpeningHours(Spa $spa, array $hours): void
    {
        $proposed = clone $spa;
        $rows = collect();
        foreach ($hours as $hour) {
            if ((int) $hour['weekday'] < 0 || (int) $hour['weekday'] > 6 || (int) $hour['opens_min'] < 0
                || (int) $hour['closes_min'] <= (int) $hour['opens_min'] || (int) $hour['closes_min'] > 2880) {
                $this->fail('hours', 'resource_invalid_hours');
            }
            $rows->push(new SpaHour($hour));
        }
        $proposed->setRelation('hours', $rows);
        $engine = app(CapacityEngine::class);
        $conflicts = $this->commitments($spa)->filter(fn (Allocation $a) => ! $engine->isOpen(
            $proposed, CarbonImmutable::instance($a->booking->start_at), CarbonImmutable::instance($a->booking->end_at)
        ) || ! $engine->isOpen($proposed, CarbonImmutable::instance($a->start_at), CarbonImmutable::instance($a->end_at)));
        $this->assertNoCommitments($conflicts, 'hours');
    }

    private function commitments(Spa $spa): Collection
    {
        $maxBuffer = (int) $spa->resourceTypes()->max('buffer_min');

        return Allocation::where('spa_id', $spa->id)->where('status', 'active')
            ->where('end_at', '>', now()->subMinutes($maxBuffer))
            ->whereHas('booking', fn ($query) => $query->whereNotIn('status', Booking::INACTIVE_STATUSES))
            ->with(['booking', 'resourceType'])->get()
            ->filter(fn (Allocation $a) => $this->occupiedUntil($a) > now());
    }

    private function occupiedUntil(Allocation $allocation): CarbonImmutable
    {
        return CarbonImmutable::instance($allocation->end_at)->addMinutes((int) ($allocation->resourceType?->buffer_min ?? 0));
    }

    private function assertNoCommitments(Collection $allocations, string $field): void
    {
        if ($allocations->isNotEmpty()) {
            $references = $allocations->map(fn (Allocation $a) => $a->booking->reference)->unique()->implode(', ');
            $this->fail($field, 'resource_commitments', ['references' => $references]);
        }
    }

    private function ownerId(Resource|ResourceType|Block $model): int
    {
        return (int) ($model->exists ? $model->getRawOriginal('spa_id') : $model->spa_id);
    }

    private function assertOwner(Spa $spa, array $data): void
    {
        if ((int) ($data['spa_id'] ?? 0) !== (int) $spa->id) {
            $this->fail('spa_id', 'resource_wrong_spa');
        }
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('booking.'.$key, $replace)]);
    }
}
