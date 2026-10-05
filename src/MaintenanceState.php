<?php

declare(strict_types=1);

namespace Contenir\Maintenance;

use DateTimeImmutable;

/**
 * Immutable maintenance-mode state.
 *
 * `since` records when the state was last toggled to active; for inactive
 * states it is always null. Persistence layers should round-trip the timestamp
 * unchanged so consumers can render "down for X minutes" UIs without keeping
 * their own clocks.
 */
final class MaintenanceState
{
    public function __construct(
        public readonly bool $active,
        public readonly string $message,
        public readonly ?DateTimeImmutable $since,
    ) {}

    public static function active(string $message, ?DateTimeImmutable $since = null): self
    {
        return new self(true, $message, $since ?? new DateTimeImmutable());
    }

    public static function inactive(): self
    {
        return new self(false, '', null);
    }
}
