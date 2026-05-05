<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Unit;

use Contenir\Maintenance\MaintenanceState;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('maintenance')]
final class MaintenanceStateTest extends TestCase
{
    public function testActiveFactoryDefaultsSinceToNow(): void
    {
        $before = new DateTimeImmutable();
        $state  = MaintenanceState::active('Down for upgrade');
        $after  = new DateTimeImmutable();

        self::assertTrue($state->active);
        self::assertSame('Down for upgrade', $state->message);
        self::assertNotNull($state->since);
        self::assertGreaterThanOrEqual($before, $state->since);
        self::assertLessThanOrEqual($after, $state->since);
    }

    public function testActiveFactoryAcceptsExplicitSince(): void
    {
        $when  = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $state = MaintenanceState::active('msg', $when);

        self::assertSame($when, $state->since);
    }

    public function testInactiveFactoryHasNoMessageOrSince(): void
    {
        $state = MaintenanceState::inactive();

        self::assertFalse($state->active);
        self::assertSame('', $state->message);
        self::assertNull($state->since);
    }

    public function testStateIsImmutable(): void
    {
        $state = MaintenanceState::active('hi');
        $reflection = new \ReflectionClass($state);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), sprintf('Property %s should be readonly', $property->getName()));
        }
    }
}
