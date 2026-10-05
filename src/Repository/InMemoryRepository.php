<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Repository;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Override;

/**
 * Test-friendly repository that holds state in memory. Shipped in src/ so
 * consumers' tests can require contenir/contenir-maintenance and use this
 * directly without depending on autoload-dev.
 */
final class InMemoryRepository implements MaintenanceRepositoryInterface
{
    private MaintenanceState $state;

    public function __construct(?MaintenanceState $initial = null)
    {
        $this->state = $initial ?? MaintenanceState::inactive();
    }

    #[Override]
    public function get(): MaintenanceState
    {
        return $this->state;
    }

    #[Override]
    public function save(MaintenanceState $state): void
    {
        $this->state = $state;
    }
}
