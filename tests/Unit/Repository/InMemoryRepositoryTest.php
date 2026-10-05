<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Unit\Repository;

use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('repository')]
final class InMemoryRepositoryTest extends TestCase
{
    #[Test]
    public function defaultsToInactiveWhenNoInitialStateIsGiven(): void
    {
        static::assertEquals(MaintenanceState::inactive(), (new InMemoryRepository())->get());
    }

    #[Test]
    public function returnsTheInitialState(): void
    {
        $initial = MaintenanceState::active('initial');

        static::assertSame($initial, (new InMemoryRepository($initial))->get());
    }

    #[Test]
    public function savedStateReplacesThePreviousOne(): void
    {
        $repository = new InMemoryRepository(MaintenanceState::active('before'));
        $next       = MaintenanceState::active('now down');

        $repository->save($next);

        static::assertSame($next, $repository->get());
    }
}
