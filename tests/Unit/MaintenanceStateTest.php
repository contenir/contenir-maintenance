<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Unit;

use Contenir\Maintenance\MaintenanceState;
use DateTimeImmutable;
use Error;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('maintenance')]
final class MaintenanceStateTest extends TestCase
{
    #[Test]
    public function activeStateCarriesItsMessage(): void
    {
        $state = MaintenanceState::active('Down for upgrade');

        static::assertTrue($state->active);
        static::assertSame('Down for upgrade', $state->message);
    }

    #[Test]
    public function activeStateDefaultsSinceToNow(): void
    {
        $before = new DateTimeImmutable();
        $state  = MaintenanceState::active('Down for upgrade');
        $after  = new DateTimeImmutable();

        static::assertNotNull($state->since);
        static::assertGreaterThanOrEqual($before, $state->since);
        static::assertLessThanOrEqual($after, $state->since);
    }

    #[Test]
    public function activeStateKeepsAnExplicitSince(): void
    {
        $when = new DateTimeImmutable('2026-01-02T03:04:05+00:00');

        static::assertSame($when, MaintenanceState::active('msg', $when)->since);
    }

    #[Test]
    public function inactiveStateHasNoMessageOrSince(): void
    {
        static::assertEquals(
            new MaintenanceState(
                active: false,
                message: '',
                since: null,
            ),
            MaintenanceState::inactive(),
        );
    }

    #[Test]
    public function stateCannotBeModified(): void
    {
        $state = MaintenanceState::active('hi');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $state->message = 'changed';
    }
}
