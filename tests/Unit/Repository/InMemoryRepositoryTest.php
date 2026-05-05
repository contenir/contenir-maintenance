<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Unit\Repository;

use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('maintenance')]
final class InMemoryRepositoryTest extends TestCase
{
    public function testDefaultsToInactiveWhenNoInitialStateProvided(): void
    {
        $repo = new InMemoryRepository();

        self::assertFalse($repo->get()->active);
    }

    public function testReturnsInitialState(): void
    {
        $initial = MaintenanceState::active('initial');
        $repo    = new InMemoryRepository($initial);

        self::assertSame($initial, $repo->get());
    }

    public function testSaveReplacesState(): void
    {
        $repo = new InMemoryRepository();
        $next = MaintenanceState::active('now down');

        $repo->save($next);

        self::assertSame($next, $repo->get());
    }
}
