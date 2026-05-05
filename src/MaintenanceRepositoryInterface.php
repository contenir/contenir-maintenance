<?php

declare(strict_types=1);

namespace Contenir\Maintenance;

use RuntimeException;

/**
 * Persists and retrieves the current MaintenanceState.
 *
 * Implementations should treat a missing/unreadable backing store as inactive
 * state rather than throwing — first-run and permission edge cases are part of
 * normal operation. Errors writing the state, by contrast, must throw so the
 * admin UI can surface them.
 */
interface MaintenanceRepositoryInterface
{
    public function get(): MaintenanceState;

    /**
     * @throws RuntimeException If the state cannot be persisted.
     */
    public function save(MaintenanceState $state): void;
}
