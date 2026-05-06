<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Tests\Unit\Repository;

use Contenir\Config\Exception\WriteException;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\FileRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('maintenance')]
final class FileRepositoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/contenir-maintenance-' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->purge($this->tmpDir);
        }
        parent::tearDown();
    }

    private function purge(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $item) {
            if (is_dir($item)) {
                $this->purge($item);
                @rmdir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    private function path(string $name = 'maintenance.local.php'): string
    {
        return $this->tmpDir . '/' . $name;
    }

    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->path(),
            "<?php\n\nreturn " . var_export($config, true) . ";\n",
        );
    }

    public function testGetReturnsInactiveWhenFileMissing(): void
    {
        $repo  = new FileRepository($this->path());
        $state = $repo->get();

        self::assertFalse($state->active);
        self::assertSame('', $state->message);
        self::assertNull($state->since);
    }

    public function testGetReturnsInactiveWhenFileContentsAreNotAnArray(): void
    {
        file_put_contents($this->path(), "<?php\n\nreturn 'not an array';\n");

        $state = (new FileRepository($this->path()))->get();

        self::assertFalse($state->active);
    }

    public function testGetReturnsInactiveWhenNamespaceKeyMissing(): void
    {
        $this->writeConfig(['somethingelse' => ['stuff' => 'here']]);

        $state = (new FileRepository($this->path()))->get();

        self::assertFalse($state->active);
    }

    public function testGetReturnsInactiveWhenStateKeyMissing(): void
    {
        $this->writeConfig(['maintenance' => ['file' => '/tmp/x']]);

        $state = (new FileRepository($this->path()))->get();

        self::assertFalse($state->active);
    }

    public function testGetReturnsInactiveWhenActiveFlagIsFalseEvenIfMessagePresent(): void
    {
        $this->writeConfig([
            'maintenance' => [
                'state' => ['active' => false, 'message' => 'lingering message'],
            ],
        ]);

        $state = (new FileRepository($this->path()))->get();

        self::assertFalse($state->active);
        self::assertSame('', $state->message, 'Inactive states should not surface a stale message.');
    }

    public function testGetReturnsActiveStateWithMessageAndSince(): void
    {
        $this->writeConfig([
            'maintenance' => [
                'state' => [
                    'active'  => true,
                    'message' => 'Down for upgrade',
                    'since'   => '2026-01-02T03:04:05+00:00',
                ],
            ],
        ]);

        $state = (new FileRepository($this->path()))->get();

        self::assertTrue($state->active);
        self::assertSame('Down for upgrade', $state->message);
        self::assertEquals(new DateTimeImmutable('2026-01-02T03:04:05+00:00'), $state->since);
    }

    public function testGetTreatsUnparseableSinceAsNull(): void
    {
        $this->writeConfig([
            'maintenance' => [
                'state' => ['active' => true, 'message' => 'down', 'since' => 'not-a-date'],
            ],
        ]);

        $state = (new FileRepository($this->path()))->get();

        self::assertTrue($state->active);
        self::assertNull($state->since);
    }

    public function testSaveWritesActiveStateAndRoundTrips(): void
    {
        $repo  = new FileRepository($this->path());
        $when  = new DateTimeImmutable('2026-04-01T12:00:00+00:00');
        $state = MaintenanceState::active('Back at 5pm', $when);

        $repo->save($state);

        $loaded = (new FileRepository($this->path()))->get();
        self::assertTrue($loaded->active);
        self::assertSame('Back at 5pm', $loaded->message);
        self::assertEquals($when, $loaded->since);
    }

    public function testSaveWritesInactiveState(): void
    {
        $repo = new FileRepository($this->path());
        $repo->save(MaintenanceState::active('temp'));
        $repo->save(MaintenanceState::inactive());

        $loaded = (new FileRepository($this->path()))->get();
        self::assertFalse($loaded->active);
        self::assertSame('', $loaded->message);
        self::assertNull($loaded->since);
    }

    public function testSaveProducesNamespacedFileFormat(): void
    {
        $repo = new FileRepository($this->path());
        $when = new DateTimeImmutable('2026-04-01T12:00:00+00:00');
        $repo->save(MaintenanceState::active('Hi', $when));

        $loaded = include $this->path();

        self::assertSame([
            'maintenance' => [
                'state' => [
                    'active'  => true,
                    'message' => 'Hi',
                    'since'   => '2026-04-01T12:00:00+00:00',
                ],
            ],
        ], $loaded);
    }

    public function testSavePreservesUnmanagedTopLevelKeys(): void
    {
        // An operator (or another package) wrote sibling top-level keys to
        // the same file. Saving maintenance state must leave them untouched.
        $this->writeConfig([
            'pagecache' => ['options' => ['cache' => false]],
            'errors'    => ['pages' => [404 => ['title' => 'Lost']]],
        ]);

        $repo = new FileRepository($this->path());
        $repo->save(MaintenanceState::active('Down'));

        $reloaded = include $this->path();

        self::assertSame(['options' => ['cache' => false]], $reloaded['pagecache']);
        self::assertSame(404, array_key_first($reloaded['errors']['pages']));
        self::assertSame('Down', $reloaded['maintenance']['state']['message']);
    }

    public function testSavePreservesSiblingKeysWithinMaintenanceNamespace(): void
    {
        // The .global.php that wires the package may declare other keys
        // under 'maintenance' (e.g. file). Save must touch only the
        // 'state' subkey.
        $this->writeConfig([
            'maintenance' => [
                'file'        => '/some/path.php',
                'retry_after' => 600,
            ],
        ]);

        $repo = new FileRepository($this->path());
        $repo->save(MaintenanceState::active('Down'));

        $reloaded = include $this->path();

        self::assertSame('/some/path.php', $reloaded['maintenance']['file']);
        self::assertSame(600, $reloaded['maintenance']['retry_after']);
        self::assertSame('Down', $reloaded['maintenance']['state']['message']);
    }

    public function testSaveCreatesParentDirectoryIfMissing(): void
    {
        $nested = $this->tmpDir . '/nested/inner/maintenance.local.php';
        $repo   = new FileRepository($nested);

        $repo->save(MaintenanceState::active('hi'));

        self::assertFileExists($nested);
    }

    public function testSaveIsAtomicViaTempFileRename(): void
    {
        $repo = new FileRepository($this->path());
        $repo->save(MaintenanceState::active('first'));

        // After a successful save, no .tmp residue should be left over.
        self::assertFileDoesNotExist($this->path() . '.tmp');
    }

    public function testSaveThrowsWhenDestinationDirectoryUnwritable(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Running as root bypasses filesystem permission checks.');
        }

        $readOnly = $this->tmpDir . '/locked';
        mkdir($readOnly, 0o555, true);

        $repo = new FileRepository($readOnly . '/maintenance.local.php');

        try {
            $this->expectException(WriteException::class);
            $repo->save(MaintenanceState::active('x'));
        } finally {
            chmod($readOnly, 0o755);
            rmdir($readOnly);
        }
    }
}
