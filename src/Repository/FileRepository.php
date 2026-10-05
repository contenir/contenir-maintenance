<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Repository;

use Contenir\Config\Reader\PhpArray as ConfigReader;
use Contenir\Config\Writer\PhpArray as ConfigWriter;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use Throwable;

use function is_array;
use function is_scalar;
use function is_string;

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
    private const string NAMESPACE_KEY = 'maintenance';
    private const string STATE_KEY     = 'state';
    private const string WRITE_LABEL   = 'maintenance state';

    public function __construct(
        private readonly string $filePath,
    ) {}

    private static function parseSince(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @mago-expect analysis:mixed-assignment,mixed-operand The file holds untyped operator-editable config; narrowed here.
     */
    #[Override]
    public function get(): MaintenanceState
    {
        $config    = ConfigReader::fromFile($this->filePath);
        $namespace = $config[self::NAMESPACE_KEY] ?? null;
        $stateData = is_array($namespace) ? $namespace[self::STATE_KEY] ?? null : null;

        if (! is_array($stateData) || ! (bool) ($stateData['active'] ?? false)) {
            return MaintenanceState::inactive();
        }

        $message = $stateData['message'] ?? '';

        return new MaintenanceState(
            active: true,
            message: is_scalar($message) ? (string) $message : '',
            since: self::parseSince($stateData['since'] ?? null),
        );
    }

    /**
     * @mago-expect analysis:mixed-assignment The file holds untyped operator-editable config; narrowed here.
     */
    #[Override]
    public function save(MaintenanceState $state): void
    {
        $config    = ConfigReader::fromFile($this->filePath);
        $namespace = $config[self::NAMESPACE_KEY] ?? null;
        $namespace = is_array($namespace) ? $namespace : [];

        $namespace[self::STATE_KEY] = [
            'active'  => $state->active,
            'message' => $state->message,
            'since'   => $state->since?->format(DateTimeInterface::ATOM),
        ];
        $config[self::NAMESPACE_KEY] = $namespace;

        ConfigWriter::toFile($this->filePath, $config, self::WRITE_LABEL);
    }
}
