<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Repository;

use Contenir\Config\Reader\PhpArray as ConfigReader;
use Contenir\Config\Writer\PhpArray as ConfigWriter;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use DateTimeImmutable;
use Throwable;

/**
 * PHP-array file backing store.
 *
 * The file follows the Laminas/Mezzio config-namespacing convention:
 *
 *     return [
 *         'maintenance' => [
 *             'state' => [
 *                 'active'  => true,
 *                 'message' => 'Down for upgrade',
 *                 'since'   => '2026-01-02T03:04:05+00:00',
 *             ],
 *         ],
 *     ];
 *
 * The repository owns only the `maintenance.state` subkey. All other
 * top-level keys, and any sibling keys under `maintenance`, are preserved
 * on save — operators or other tooling can hand-edit the same file safely.
 *
 * A missing or unreadable file resolves to inactive state so first-run
 * consumers don't crash before the admin has ever toggled the flag.
 */
final class FileRepository implements MaintenanceRepositoryInterface
{
    private const NAMESPACE_KEY = 'maintenance';
    private const STATE_KEY     = 'state';
    private const WRITE_LABEL   = 'maintenance state';

    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function get(): MaintenanceState
    {
        $config    = ConfigReader::fromFile($this->filePath);
        $stateData = $config[self::NAMESPACE_KEY][self::STATE_KEY] ?? null;

        if (! is_array($stateData)) {
            return MaintenanceState::inactive();
        }

        $active  = (bool) ($stateData['active'] ?? false);
        $message = (string) ($stateData['message'] ?? '');
        $since   = self::parseSince($stateData['since'] ?? null);

        if (! $active) {
            return MaintenanceState::inactive();
        }

        return new MaintenanceState($active, $message, $since);
    }

    public function save(MaintenanceState $state): void
    {
        $payload = [
            'active'  => $state->active,
            'message' => $state->message,
            'since'   => $state->since?->format(\DateTimeInterface::ATOM),
        ];

        $config = ConfigReader::fromFile($this->filePath);
        if (! isset($config[self::NAMESPACE_KEY]) || ! is_array($config[self::NAMESPACE_KEY])) {
            $config[self::NAMESPACE_KEY] = [];
        }
        $config[self::NAMESPACE_KEY][self::STATE_KEY] = $payload;

        ConfigWriter::toFile($this->filePath, $config, self::WRITE_LABEL);
    }

    private static function parseSince(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
