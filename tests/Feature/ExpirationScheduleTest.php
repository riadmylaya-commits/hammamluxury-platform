<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ExpirationScheduleTest extends TestCase
{
    public function test_expiration_runs_every_minute_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'hl:expire-waiting'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'Deux passages ne se chevauchent jamais');
    }
}
