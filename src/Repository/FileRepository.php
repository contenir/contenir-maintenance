<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Repository;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * PHP-array file backing store.
 *
 * The file returns an associative array — opcache-cacheable, fast to read on
 * every request. A missing or unreadable file resolves to inactive state so
 * first-run consumers don't crash before the admin has ever toggled the
 * flag. Save errors throw; the caller (admin UI) is expected to surface them.
 */
final class FileRepository implements MaintenanceRepositoryInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function get(): MaintenanceState
    {
        if (! is_file($this->filePath) || ! is_readable($this->filePath)) {
            return MaintenanceState::inactive();
        }

        try {
            /** @psalm-suppress UnresolvableInclude */
            $data = include $this->filePath;
        } catch (Throwable) {
            return MaintenanceState::inactive();
        }

        if (! is_array($data)) {
            return MaintenanceState::inactive();
        }

        $active  = (bool) ($data['active'] ?? false);
        $message = (string) ($data['message'] ?? '');
        $since   = self::parseSince($data['since'] ?? null);

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

        $contents = "<?php\n\nreturn " . self::exportArray($payload) . ";\n";

        $dir = \dirname($this->filePath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create maintenance directory "%s".', $dir));
        }

        $tmp = $this->filePath . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Cannot write maintenance state to "%s".', $tmp));
        }

        // Atomic swap so a partial write is never visible to readers.
        if (! @rename($tmp, $this->filePath)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Cannot install maintenance state at "%s".', $this->filePath));
        }

        // Drop any cached opcode for the old contents — otherwise readers in
        // long-running PHP-FPM workers would see stale state.
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->filePath, true);
        }
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

    /**
     * @param array<string, mixed> $data
     */
    private static function exportArray(array $data): string
    {
        $lines = ['['];
        foreach ($data as $key => $value) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), var_export($value, true));
        }
        $lines[] = ']';
        return implode("\n", $lines);
    }
}
